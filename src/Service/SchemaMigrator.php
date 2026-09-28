<?php
// src/Service/SchemaMigrator.php

namespace App\Service;

use PDO;

/**
 * Class SchemaMigrator
 *
 * Hebt das Datenbank-Schema idempotent auf den aktuellen Stand (#230). Die
 * Migrationsschritte lagen früher als private Methode in App\Database
 * (runMigrations()) und waren damit nur implizit beim Verbindungsaufbau
 * erreichbar - Restore-/Import-Wege (z. B. ein Datenmigrations-Addon, das
 * den Dump einer ÄLTEREN Kern-Version einspielt) hätten für einen expliziten
 * Migrationslauf `php database/migrate.php` per shell_exec aufrufen müssen,
 * was auf klassischem Webhosting oft gesperrt ist (dieselbe Begründung wie
 * bei App\Service\DatabaseDumper). Deshalb jetzt als aufrufbare Klasse:
 *
 *     $schritte = SchemaMigrator::run($pdo);   // z. B. nach einem Restore
 *
 * Database::ensureSchemaUpToDate() delegiert hierher - es gibt weiterhin
 * genau EINE Quelle für die Migrationsschritte, nichts ist doppelt gepflegt.
 * database/migrate.php ist nur noch ein dünner CLI-Wrapper um diese Klasse.
 *
 * Idempotenz: Jeder Schritt prüft selbst, ob er nötig ist (SHOW COLUMNS /
 * SHOW TABLES / SHOW INDEX bzw. CREATE TABLE IF NOT EXISTS); ein wiederholter
 * Lauf ändert nichts und liefert eine leere Schritt-Liste. Zusätzlich gilt
 * der versionierte Kurzschluss aus #213: Ist der in settings.schema_version
 * persistierte Stand aktuell, kostet run() nur eine einzige Abfrage.
 */
final class SchemaMigrator {

    /**
     * Version des von run() hergestellten Schemas.
     *
     * DISZIPLIN (verbindlich): JEDE Schemaänderung in migrate() - neue
     * Spalte, neuer Index, neue Tabelle, geänderter Spaltentyp, neuer Seed -
     * erhöht diese Konstante um 1. Sonst sehen Bestandsinstallationen die
     * Änderung nie: run() überspringt die komplette Migration, sobald der in
     * settings.schema_version persistierte Stand aktuell ist (#213). Jeder
     * Migrationsschritt ist idempotent, ein Erhöhen der Version lässt also
     * gefahrlos alle Schritte erneut laufen.
     */
    public const SCHEMA_VERSION = 24; // 24: users.totp_secret auf VARCHAR(255), Klartext-TOTP-Secrets verschlüsseln (Audit N8)

    /**
     * Wie lange ein Lauf auf die Migrationssperre eines anderen Prozesses
     * wartet (GET_LOCK, Audit N75). Gilt für explizite Aufrufer und den
     * ERSTEN impliziten Lauf je Zielversion - also einmal je Update. Ein
     * Wiederholungslauf des Web-Wegs wartet gar nicht, siehe run().
     */
    private const SPERRE_WARTEN_SEKUNDEN = 30;

    /**
     * Frühester erneuter Versuch des IMPLIZITEN Wegs (Database::
     * ensureSchemaUpToDate()) je Zustand in settings.schema_migration_status,
     * in Sekunden (Audit N76). Ohne Drosselung liefe bei einem offenen
     * Schritt bei JEDEM Request ein voller migrate() mit rund 150
     * Metadatenabfragen plus Dateiscan. `php database/migrate.php` und andere
     * explizite Aufrufer laufen ungedrosselt.
     */
    public const WIEDERHOLEN_NACH = ['offen' => 900, 'fehler' => 60];

    /** settings-Schlüssel des Laufzustands (offen/fehler), siehe status(). */
    public const STATUS_SCHLUESSEL = 'schema_migration_status';

    /** Höchstens so viele Meldungen je Status, je höchstens so lang. */
    private const STATUS_MELDUNGEN_MAX = 10;
    private const STATUS_MELDUNG_LAENGE = 500;

    /**
     * Wiedereintrittsschutz (Audit N75). Nötig im CLI-Weg: migrate.php und
     * Restore-Werkzeuge nutzen eine eigene PDO, AuditLogger::log() baut über
     * Database::getInstance() eine zweite Verbindung auf - und deren
     * ensureSchemaUpToDate() wartete sonst auf die Sperre, die dieser Prozess
     * selbst hält. Im Web-Weg tritt der Fall nicht auf, weil
     * Database::$instance vor ensureSchemaUpToDate() gesetzt wird.
     */
    private static bool $laeuft = false;

    /** Nur für Tests verkürzbar, siehe setzeSperreWartezeitFuerTests(). */
    private static ?int $sperreWartezeit = null;

    /**
     * Der Stand, mit dem der Gast-Seed persons.view für die öffentliche
     * Personenseite kam (#293, v0.6.0; im v0.7.1-Migrator im Block
     * "Kontaktfelder für Personen (#293, SCHEMA_VERSION 6)"). Wer schon auf
     * diesem Stand war, hat den Seed erhalten - fehlt das Recht heute, hat
     * ein Admin es entzogen (Audit M22). Sollte es eine Zwischenfassung auf 6
     * ohne Seed gegeben haben, wirkt die Schwelle fail-closed: Der Gast
     * bekommt die Personenseite dann nicht, ein Admin schaltet sie frei.
     */
    private const VERSION_GASTRECHT_PERSONENSEITE = 6;

    /**
     * Der zuletzt vollständig migrierte, in settings.schema_version
     * persistierte Stand. 0 = unbekannt/nie migriert - auch im Setup- bzw.
     * Restore-Fall, wenn die settings-Tabelle (noch) nicht existiert.
     *
     * Öffentlich, damit Restore-Werkzeuge VOR einem Import entscheiden
     * können, ob ein eingespielter Dump hinter dem aktuellen Stand liegt
     * (siehe #230: versionsübergreifender Datenimport).
     */
    public static function storedVersion(PDO $pdo): int {
        try {
            return (int)$pdo->query(
                "SELECT setting_value FROM settings WHERE setting_key = 'schema_version'"
            )->fetchColumn();
        } catch (\Throwable $e) {
            // Setup-/Restore-Fall: settings existiert (noch) nicht.
            return 0;
        }
    }

    /**
     * true, wenn der persistierte Stand die aktuelle SCHEMA_VERSION erreicht -
     * dann ist ein run() ein garantierter No-Op (Kurzschluss, #213).
     */
    public static function isUpToDate(PDO $pdo): bool {
        return self::storedVersion($pdo) >= self::SCHEMA_VERSION;
    }

    /**
     * Führt den Schema-Migrationslauf aus und liefert die Liste der
     * tatsächlich durchgeführten Schritte (deutschsprachige Beschreibungen,
     * z. B. für ein Import-Protokoll). Leere Liste = es war nichts zu tun.
     *
     * Ist der persistierte Stand aktuell, wird die Migration komplett
     * übersprungen (Kurzschluss, #213). Andernfalls laufen alle - einzeln
     * idempotenten - Schritte, und der neue Stand wird erst NACH
     * vollständigem Durchlauf persistiert:
     *
     *  - Wirft ein Schritt, bleibt der alte Stand stehen, der Fehler landet
     *    in settings.schema_migration_status (zustand 'fehler', Dashboard)
     *    und wird weitergeworfen.
     *  - Meldet sich ein Datenschritt als offen ($offen, Audit N76), bleibt
     *    der alte Stand ebenfalls stehen, der Status sagt 'offen'. Früher
     *    stempelte run() trotzdem - und "wird erneut versucht" lief dann
     *    erst mit dem nächsten Release.
     *
     * Parallele Läufe serialisiert eine Datenbanksperre (GET_LOCK, Audit
     * N75): Mehrere gleichzeitige Requests konnten sonst nicht idempotente
     * Schritte doppelt ausführen (Deckstationen doppelt umgeschlüsselt).
     * Nach Erhalt der Sperre wird der Stand erneut geprüft - wer gewartet
     * hat, findet die Arbeit meist schon erledigt. Liefert der Server kein
     * Ergebnis (Galera, Proxy ohne GET_LOCK), läuft der Lauf bewusst ohne
     * Sperre weiter wie bisher.
     *
     * $drosseln (nur Database::ensureSchemaUpToDate()): Nach einem offenen
     * oder gescheiterten Lauf wird frühestens nach WIEDERHOLEN_NACH erneut
     * versucht, und ein solcher Wiederholungslauf wartet NICHT auf die
     * Sperre - das Schema ist dann benutzbar, und ein 15-Minuten-Versuch
     * soll nicht jeden parallelen Request bis zu 30 s aufhalten.
     *
     * Das Persistieren selbst wirft bei fehlender settings-Tabelle nach oben
     * (der Aufrufer soll erfahren, dass der Stand NICHT festgehalten wurde);
     * Database::ensureSchemaUpToDate() fängt das ab, weil die App im
     * Setup-Fall bewusst ohne persistierten Stand weiterlaufen können muss.
     *
     * @return string[] Durchgeführte Schritte in Ausführungsreihenfolge
     */
    public static function run(PDO $pdo, bool $drosseln = false): array {
        $current = self::storedVersion($pdo);
        if ($current >= self::SCHEMA_VERSION) {
            return []; // Normalfall: Schema aktuell, eine einzige Abfrage - fertig.
        }
        if (self::$laeuft) {
            return []; // Verschachtelter Aufruf aus einem laufenden Lauf heraus.
        }

        // Rückweg #336 (Audit N39): Der Betreiber hat bewusst zurückgerollt
        // und will die alte Version einspielen. Ein Lauf hier machte den
        // Rückweg sofort wieder rückgängig.
        if (Maintenance::sperrtMigration()) {
            throw new \RuntimeException(
                'Schema-Migration gesperrt: var/wartung.lock wurde vom Rückweg #336 gesetzt. '
                . 'Die Datei erst NACH dem Einspielen der alten Version entfernen; wer auf dieser '
                . 'Fassung bleiben will, entfernt sie jetzt (der nächste Lauf führt #336 erneut aus).'
            );
        }

        $status = $drosseln ? self::status($pdo) : null;
        if ($drosseln && self::wartetAufWiederholung($status)) {
            return [];
        }
        $wiederholung = $drosseln && $status !== null && $status['ziel'] === self::SCHEMA_VERSION;
        $wartezeit = $wiederholung ? 0 : (self::$sperreWartezeit ?? self::SPERRE_WARTEN_SEKUNDEN);

        $gesperrt = self::sperreHolen($pdo, $wartezeit);
        if ($gesperrt === false) {
            if ($wartezeit === 0) {
                return []; // Wiederholungslauf: ein anderer Prozess ist schon dran.
            }
            // Bewusst KEIN Status: Es ist nichts gescheitert, es läuft nur
            // gerade ein anderer Prozess. Database verschluckt die Ausnahme,
            // migrate.php zeigt sie an.
            throw new \RuntimeException(
                'Schema-Migration läuft bereits in einem anderen Prozess - bitte später erneut versuchen.'
            );
        }

        self::$laeuft = true;
        try {
            // Double-Check nach Erhalt der Sperre.
            $current = self::storedVersion($pdo);
            if ($current >= self::SCHEMA_VERSION) {
                return [];
            }
            if ($drosseln && self::wartetAufWiederholung(self::status($pdo))) {
                return [];
            }

            $performed = [];
            $offeneSchritte = [];
            try {
                self::migrate($pdo, $performed, $offeneSchritte, $current);
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                self::statusSchreiben($pdo, 'fehler', $current, [$e->getMessage()]);
                throw $e;
            }

            if ($offeneSchritte !== []) {
                $meldungen = [];
                foreach ($offeneSchritte as $schritt => $meldung) {
                    $meldungen[] = $schritt . ': ' . $meldung;
                }
                self::statusSchreiben($pdo, 'offen', $current, $meldungen);
                $performed[] = sprintf(
                    'settings.schema_version bleibt auf %d: %d Datenschritt(e) offen (%s)',
                    $current,
                    count($offeneSchritte),
                    implode(', ', array_keys($offeneSchritte))
                );
                return $performed;
            }

            $pdo->prepare(
                "INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            )->execute([(string)self::SCHEMA_VERSION]);
            $performed[] = sprintf('settings.schema_version auf %d gesetzt (vorher %d)', self::SCHEMA_VERSION, $current);
            try {
                $pdo->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([self::STATUS_SCHLUESSEL]);
            } catch (\Throwable $e) {
                // Ein liegengebliebener Status verschwindet spätestens, weil
                // ziel <= schema_version ihn für das Dashboard erledigt.
            }

            return $performed;
        } finally {
            if ($gesperrt === true) {
                try {
                    $pdo->query("DO RELEASE_LOCK(CONCAT('hv_schema_', MD5(DATABASE())))");
                } catch (\Throwable $e) {
                    // Verbindung weg - dann ist die Sperre es auch.
                }
            }
            self::$laeuft = false;
        }
    }

    /**
     * Der zuletzt geschriebene Laufzustand (offen/fehler) oder null. Siehe
     * statusAusEinstellungen() für die Form.
     */
    public static function status(PDO $pdo): ?array {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $stmt->execute([self::STATUS_SCHLUESSEL]);
            $roh = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }
        return $roh === false ? null : self::statusAusEinstellungen([self::STATUS_SCHLUESSEL => $roh]);
    }

    /**
     * Liest den Laufzustand aus bereits geladenen Einstellungen (Dashboard:
     * BaseController::$settings, keine zusätzliche Abfrage). Kaputtes oder
     * unvollständiges JSON zählt als "kein Status".
     *
     * @return array{zustand: string, von: int, ziel: int, zeit: string, meldungen: string[]}|null
     */
    public static function statusAusEinstellungen(array $settings): ?array {
        $roh = $settings[self::STATUS_SCHLUESSEL] ?? null;
        if (!is_string($roh) || $roh === '') {
            return null;
        }
        $daten = json_decode($roh, true);
        if (!is_array($daten)
            || !isset($daten['zustand'], $daten['von'], $daten['ziel'], $daten['zeit'])
            || !isset(self::WIEDERHOLEN_NACH[$daten['zustand']])
            || !is_numeric($daten['von']) || !is_numeric($daten['ziel'])
            || !is_string($daten['zeit']) || strtotime($daten['zeit']) === false
        ) {
            return null;
        }
        $meldungen = [];
        foreach ((array)($daten['meldungen'] ?? []) as $m) {
            if (is_scalar($m)) {
                $meldungen[] = (string)$m;
            }
        }
        return [
            'zustand' => (string)$daten['zustand'],
            'von' => (int)$daten['von'],
            'ziel' => (int)$daten['ziel'],
            'zeit' => $daten['zeit'],
            'meldungen' => $meldungen,
        ];
    }

    /**
     * Zeitpunkt (Unix-Zeit), ab dem der implizite Weg einen Status erneut
     * angeht - für den Dashboard-Hinweis.
     */
    public static function naechsterVersuch(array $status): int {
        return (int)strtotime($status['zeit']) + (self::WIEDERHOLEN_NACH[$status['zustand']] ?? 0);
    }

    /**
     * @internal Nur für Tests: Wartezeit auf die Migrationssperre in
     * Sekunden; ein negativer Wert stellt den Standard wieder her.
     */
    public static function setzeSperreWartezeitFuerTests(int $sekunden): void {
        self::$sperreWartezeit = $sekunden < 0 ? null : $sekunden;
    }

    /** Liegt für DIESES Ziel ein Status vor, dessen Wartefrist noch läuft? */
    private static function wartetAufWiederholung(?array $status): bool {
        return $status !== null
            && $status['ziel'] === self::SCHEMA_VERSION
            && self::naechsterVersuch($status) > time();
    }

    /**
     * GET_LOCK je Datenbank (serverweit eindeutig, 42 Zeichen < 64).
     *
     * @return bool|null true = gehalten, false = nicht erhalten,
     *                   null = Server kann es nicht (fail-open ohne Sperre)
     */
    private static function sperreHolen(PDO $pdo, int $wartezeit): ?bool {
        try {
            $stmt = $pdo->prepare("SELECT GET_LOCK(CONCAT('hv_schema_', MD5(DATABASE())), ?)");
            $stmt->execute([$wartezeit]);
            $ergebnis = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }
        if ($ergebnis === null || $ergebnis === false) {
            return null;
        }
        return (int)$ergebnis === 1;
    }

    /**
     * Schreibt settings.schema_migration_status. Scheitert das (Setup-Fall
     * ohne settings), wird es ignoriert - der Status ist Anzeige, kein Gate.
     */
    private static function statusSchreiben(PDO $pdo, string $zustand, int $von, array $meldungen): void {
        $gekuerzt = [];
        foreach (array_slice(array_values($meldungen), 0, self::STATUS_MELDUNGEN_MAX) as $m) {
            $gekuerzt[] = mb_substr((string)$m, 0, self::STATUS_MELDUNG_LAENGE);
        }
        try {
            $pdo->prepare(
                "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            )->execute([self::STATUS_SCHLUESSEL, json_encode([
                'zustand' => $zustand,
                'von' => $von,
                'ziel' => self::SCHEMA_VERSION,
                'zeit' => gmdate('c'),
                'meldungen' => $gekuerzt,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
        } catch (\Throwable $e) {
            // Siehe PHPDoc.
        }
    }

    /**
     * Sämtliche Schema-Migrationsschritte - der komplette frühere
     * Database::runMigrations()-Body, ergänzt um die Protokollierung
     * tatsächlich durchgeführter Änderungen in $performed. Läuft NUR, wenn
     * settings.schema_version hinter SCHEMA_VERSION zurückliegt (siehe
     * run()); auch die früher ungegateten ALTER TABLE (horse_persons.
     * person_id, users.must_change_password, horses.birth_year) laufen damit
     * ausschließlich innerhalb dieser versionierten Migration.
     *
     * Jeder Schritt ist für sich idempotent und einzeln per try/catch
     * abgesichert (Tabelle existiert ggf. noch nicht, z. B. im Setup-Fall).
     *
     * DISZIPLIN: Jede Schemaänderung hier erhöht zwingend SCHEMA_VERSION -
     * siehe den Kommentar an der Konstante.
     *
     * @param PDO      $pdo              Aktive Datenbankverbindung
     * @param string[] $performed        Sammelliste der durchgeführten Schritte
     * @param array<string,string> $offeneSchritte Datenschritte (bzw.
     *                                   'tabelle:<name>'), die sich als offen
     *                                   gemeldet haben - run() stempelt dann
     *                                   nicht (Audit N76)
     * @param int      $vorherigeVersion Stand VOR dieser Migration
     *                                   (settings.schema_version, siehe
     *                                   storedVersion()). 0 heißt: vor #213
     *                                   (v0.4.0), unbekannt, Setup-Fall ODER
     *                                   Restore/Settings-Import ohne
     *                                   schema_version. Deshalb 0 nie allein als
     *                                   Beweis für einen Altstand verwenden,
     *                                   sondern stets mit einem strukturellen
     *                                   Befund kombinieren (Audit M22/N18).
     */
    private static function migrate(PDO $pdo, array &$performed, array &$offeneSchritte, int $vorherigeVersion): void {
        // Steht das Kontaktschema (#336) bereits? EINMAL am Anfang bestimmt,
        // bevor irgendein Schritt läuft - der Wert muss den Stand VOR dieser
        // Migration beschreiben, nicht den, den Schritt 31a gleich herstellt.
        //
        // Wozu: Die Schritte 4, 22, 29 und 30 pflegen `persons` und
        // `breeding_stations`. Nach #336 gibt es beide nicht mehr (umbenannt
        // bzw. auf Neuinstallationen nie angelegt) - und `$createTable` legt
        // an, was fehlt. Ohne diese Sperre erschienen die Alttabellen bei
        // JEDEM künftigen Minor-Sprung leer wieder, und Code, der sie noch
        // abfragt, bekäme stillschweigend ein leeres Ergebnis statt eines
        // Fehlers. Genau das ist im Probelauf passiert.
        $kontaktschemaAktiv = (static function () use ($pdo): bool {
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'contacts'");
                return (bool)($stmt && $stmt->rowCount() > 0);
            } catch (\Throwable $e) {
                return false;
            }
        })();

        // Hat diese Instanz den Gast-Seed persons.view aus #293 schon
        // erhalten? Ebenfalls EINMAL vor allen Schritten bestimmt, denn
        // Schritt 30 legt persons.is_breeder im selben Lauf an. Zwei Signale,
        // die beide nur in Richtung "nicht seeden" wirken (fail-closed):
        //  - der gespeicherte Stand vor dieser Migration (#293 kam mit
        //    SCHEMA_VERSION 6), und
        //  - strukturell: persons.is_breeder kam mit demselben #293. Steht die
        //    Spalte schon, lief der Seed auch dann, wenn schema_version fehlt
        //    oder nicht stimmt (Restore, Settings-Import über ein Addon).
        // Warum nicht einfach im Rechtebestand nachsehen: Ein fehlendes
        // persons.view ist dort kein Beweis für "nie geseedet" - ein Admin
        // kann es bewusst entzogen haben (Audit M22).
        $gastrechtSeedBelegt = $vorherigeVersion >= self::VERSION_GASTRECHT_PERSONENSEITE
            || (static function () use ($pdo): bool {
                try {
                    $stmt = $pdo->query("SHOW COLUMNS FROM `persons` LIKE 'is_breeder'");
                    return (bool)($stmt && $stmt->rowCount() > 0);
                } catch (\Throwable $e) {
                    return false;
                }
            })();

        // Tabellen, die es nach #336 nicht mehr geben darf.
        $abgeloest = ['persons', 'breeding_stations'];

        // Und dasselbe je Spalte: horse_persons trug die Verweise früher als
        // person_id/breeding_station_id, seit #336 als
        // contact_id/station_contact_id. Die alten Spalten legt Schritt 31f
        // still - ohne diese Liste ergänzte Schritt 4 sie beim nächsten
        // Minor-Sprung leer wieder, und dann stünde neben jedem echten
        // Verweis eine leere Altspalte, die aussieht, als fehlte die Zuordnung.
        $abgeloesteSpalten = ['horse_persons.person_id', 'horse_persons.breeding_station_id'];

        // Einmal-Datenschritte (seit #336): $dataStep führt einen
        // Datenschritt genau einmal aus und hält das in settings fest.
        //
        // Vertrag (Audit N75/N76): $arbeit($vermerke, $offen) - Closures ohne
        // Parameter bleiben gültig.
        //  - Rückgabe array = erledigt: Marker migration_<key> wird gesetzt,
        //    die Zeilen landen in $performed.
        //  - Rückgabe null OHNE $offen = "war nicht zuständig" (z. B.
        //    Setup-Fall): nichts vermerkt, der nächste Lauf fragt erneut.
        //  - `return $offen('…')` = "zuständig, aber nicht fertig": kein
        //    Marker, die Meldung steht in $performed UND in $offeneSchritte -
        //    run() stempelt dann nicht, und der implizite Weg versucht es
        //    nach WIEDERHOLEN_NACH erneut. Früher hieß das nur "wird erneut
        //    versucht", der Stempel kam trotzdem, und der Schritt lief bis
        //    zum nächsten Release nie wieder.
        //  - $vermerke() schreibt den Marker SOFORT. Innerhalb der eigenen
        //    Transaktion vor commit() aufgerufen, sind Daten und Marker
        //    atomar - Pflicht für jeden nicht idempotenten Schritt (Audit
        //    M41: Daten festgeschrieben, Marker nicht, und jeder weitere Lauf
        //    hing).
        //  - Ein Schritt, der ein Teilergebnis still verschluckt (leeres
        //    catch), darf keinen Marker setzen, sondern meldet sich offen.
        //  - Ausnahmen gehen mit dem Präfix "Datenschritt <key>: " weiter.
        $dataStep = function (string $key, callable $arbeit) use ($pdo, &$performed, &$offeneSchritte): void {
            $markerKey = 'migration_' . $key;
            try {
                $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
                $stmt->execute([$markerKey]);
                if ($stmt->fetchColumn() !== false) {
                    return; // Schritt ist nachweislich schon gelaufen.
                }
            } catch (\Throwable $e) {
                return; // settings existiert noch nicht - dann gibt es auch keinen Altbestand.
            }

            $vermerkt = false;
            $vermerke = function () use ($pdo, $markerKey, &$vermerkt): void {
                $pdo->prepare(
                    "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
                )->execute([$markerKey, gmdate('c')]);
                $vermerkt = true;
            };
            $istOffen = false;
            $offen = function (string $meldung) use ($key, &$offeneSchritte, &$performed, &$istOffen): ?array {
                $offeneSchritte[$key] = $meldung;
                $performed[] = $meldung;
                $istOffen = true;
                return null;
            };

            try {
                $ergebnis = $arbeit($vermerke, $offen);
            } catch (\Throwable $e) {
                throw new \RuntimeException("Datenschritt {$key}: " . $e->getMessage(), 0, $e);
            }
            if ($istOffen || $ergebnis === null) {
                return; // Offen oder nicht zuständig - kein Marker, ein späterer Lauf versucht es erneut.
            }

            if (!$vermerkt) {
                $vermerke();
            }

            foreach ((array)$ergebnis as $zeile) {
                $performed[] = $zeile;
            }
        };

        // Gibt es diese Spalte? Bewusst neben $tabelleExistiert und mit
        // derselben Vorsicht: Scheitert SHOW COLUMNS, gibt es die Tabelle
        // nicht - und dann gibt es die Spalte erst recht nicht.
        $spalteExistiert = function (string $table, string $column) use ($pdo): bool {
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
                return $stmt !== false && $stmt->fetch() !== false;
            } catch (\Throwable) {
                return false;
            }
        };

        $tabelleExistiert = function (string $table) use ($pdo): bool {
            try {
                $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
                return $stmt && $stmt->rowCount() > 0;
            } catch (\Throwable $e) {
                return false;
            }
        };

        // Helper-Funktion zum schrittweisen Hinzufügen fehlender Spalten
        // Der try/catch umfasst BEWUSST nur die Existenzprüfung, nicht das
        // ALTER (#309): Scheitert schon SHOW COLUMNS, gibt es die Tabelle
        // hier noch nicht - das ist der reguläre Setup-/Restore-Fall und kein
        // Fehler. Ist die Tabelle dagegen nachweislich da und die Spalte
        // fehlt, dann ist ein Fehlschlag des ALTER ein echter Fehler und muss
        // nach oben durchschlagen: run() persistiert die neue
        // schema_version erst NACH vollständigem Durchlauf, ein geworfener
        // Schritt lässt den alten Stand stehen und wird beim nächsten Lauf
        // wiederholt. Genau das fehlte, als contact_public mit einer
        // AFTER-Klausel auf eine noch nicht angelegte Spalte verwies: Das
        // ALTER scheiterte still, die Version wurde trotzdem hochgesetzt, und
        // die Spalte fehlte danach dauerhaft.
        $addColumn = function ($table, $column, $definition) use ($pdo, &$performed, $kontaktschemaAktiv, $abgeloest, $abgeloesteSpalten) {
            if ($kontaktschemaAktiv && in_array($table, $abgeloest, true)) {
                return; // Abgelöst durch `contacts` (#336) - nicht wiederbeleben.
            }
            if ($kontaktschemaAktiv && in_array("{$table}.{$column}", $abgeloesteSpalten, true)) {
                return; // Abgelöste Spalte (#336) - nicht wiederbeleben.
            }
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            } catch (\Throwable $e) {
                return; // Tabelle existiert noch nicht (Setup-/Restore-Fall)
            }
            if (!$stmt || $stmt->rowCount() !== 0) {
                return; // Spalte ist schon da - idempotenter No-Op
            }
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            $performed[] = "Spalte {$table}.{$column} ergänzt";
        };

        // Helper für neue Tabellen: SHOW TABLES vor dem CREATE TABLE IF NOT
        // EXISTS dient nur der Protokollierung (hat die Tabelle gefehlt?) -
        // die Idempotenz garantiert weiterhin das IF NOT EXISTS selbst.
        $createTable = function (string $table, string $createSql) use ($pdo, &$performed, &$offeneSchritte, $abgeloest) {
            // BEDINGUNGSLOS, nicht nur wenn `contacts` schon steht.
            //
            // Der erste Anlauf machte das von $kontaktschemaAktiv abhängig -
            // und übersah den Weg, auf dem eine LEERE Datenbank durch die
            // Migration hochgezogen wird (Ersteinrichtung ohne schema.sql).
            // Dort gibt es `contacts` beim Start noch nicht, Schritt 4 legte
            // also brav ein leeres `breeding_stations` an, und Schritt 31b sah
            // danach "eine der beiden Alttabellen existiert" und versuchte,
            // aus dem nicht vorhandenen `persons` zu kopieren. Die Migration
            // warf, `run()` kam nie zum Schreiben der schema_version - und
            // lief damit bei JEDEM Request erneut. Aufgefallen ist das an
            // einer ganz anderen Stelle: Der Seed des offiziellen Addon-Repos
            // läuft in derselben Migration und hatte den AUTO_INCREMENT der
            // Tabelle auf über 2000 getrieben.
            //
            // Neu angelegt werden diese Tabellen ab v0.8 nirgends mehr. Wer
            // sie hat, hat sie aus einer älteren Fassung; wer nicht, braucht
            // sie nicht.
            if (in_array($table, $abgeloest, true)) {
                return;
            }
            try {
                $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
                $existed = $stmt && $stmt->rowCount() > 0;
                $pdo->exec($createSql);
                if (!$existed) {
                    $performed[] = "Tabelle {$table} angelegt";
                }
            } catch (\Throwable $e) {
                // Früher ein leeres catch - und nach dem Stempel wurde eine
                // gescheiterte Anlage nie wiederholt (dieselbe Lehre wie
                // #309, Audit N76). Jetzt hält sie den Stempel auf. Im
                // Setup-Fall ohne settings ist ein Stempel ohnehin nicht
                // möglich, dort ändert das nichts.
                $meldung = "Tabelle {$table} konnte nicht angelegt werden: " . $e->getMessage();
                $offeneSchritte['tabelle:' . $table] = $meldung;
                $performed[] = $meldung;
            }
        };

        // 1. Audit-Log für Revisionssicherheit (dauerhafte Speicherung, keine automatische Löschung)
        $createTable('audit_logs', "CREATE TABLE IF NOT EXISTS `audit_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NULL,
            `username` VARCHAR(50) NOT NULL DEFAULT 'SYSTEM',
            `action` VARCHAR(100) NOT NULL,
            `category` VARCHAR(50) NOT NULL DEFAULT 'general',
            `details` TEXT NULL,
            `ip_address` VARCHAR(45) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (`created_at`),
            INDEX (`category`),
            INDEX (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 2. 2-Faktor-Authentifizierung & Passkeys für Benutzer
        $addColumn('users', 'totp_secret', 'VARCHAR(255) NULL AFTER `role`');
        $addColumn('users', 'totp_enabled', 'TINYINT(1) DEFAULT 0 AFTER `totp_secret`');

        // 2a. users.totp_secret auf die Breite aus schema.sql (Audit N8,
        // SCHEMA_VERSION 24). Hier stand bis dahin VARCHAR(64), schema.sql
        // hatte längst 255 - eine Drift, die erst mit der Verschlüsselung
        // Folgen hat: Ein 16-Zeichen-Secret ergibt 60 Zeichen Chiffretext und
        // passt noch, ab 20 Zeichen Klartext nicht mehr. Deshalb VOR dem
        // Datenschritt totp_klartext_verschluesseln weiter unten, und als
        // eigener Schritt statt darin: Die Breite ist Schema, keine Daten.
        // Idempotent über die gemeldete Breite; scheitert das ALTER, bleibt
        // der Lauf offen statt still.
        try {
            $spalte = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'totp_secret'");
            $zeile = $spalte ? $spalte->fetch() : false;
            $typ = is_array($zeile) ? (string)($zeile['Type'] ?? '') : '';
            if (preg_match('/^varchar\((\d+)\)/i', $typ, $breite) === 1 && (int)$breite[1] < 255) {
                try {
                    $pdo->exec("ALTER TABLE `users` MODIFY `totp_secret` VARCHAR(255) NULL");
                    $performed[] = 'Spalte users.totp_secret auf VARCHAR(255) erweitert';
                } catch (\Throwable $e) {
                    $meldung = 'Spalte users.totp_secret konnte nicht auf VARCHAR(255) erweitert werden: ' . $e->getMessage();
                    $offeneSchritte['spalte:users.totp_secret'] = $meldung;
                    $performed[] = $meldung;
                }
            }
        } catch (\Throwable $e) {
            // Keine Tabelle users - dann gibt es auch keine Secrets.
        }
        $addColumn('users', 'backup_codes', 'TEXT NULL AFTER `totp_enabled`');
        // 39. Passkeys (#353). Die Spalte users.passkeys stand seit Langem im
        // Schema und wurde NIRGENDS gelesen oder geschrieben - sie versprach
        // eine Funktion, die es nicht gab. Jetzt gibt es sie, und sie braucht
        // eine eigene Tabelle: Ein JSON-Klumpen je Benutzer machte Widerruf,
        // Anzeige ("zuletzt benutzt") und die Eindeutigkeit der Credential-ID
        // umstaendlich, und genau die ist die Grundlage jeder Anmeldung.
        //
        // Die Reihenfolge ist wesentlich: erst die Tabelle, dann die Spalte
        // weg. Andersherum stuende zwischen beiden Schritten ein Zustand ohne
        // beides - und ein Abbruch dazwischen liesse eine Installation ohne
        // jeden Passkey-Speicher zurueck.
        $createTable('user_passkeys', "CREATE TABLE IF NOT EXISTS `user_passkeys` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `credential_id` VARCHAR(512) NOT NULL,
            `credential` TEXT NOT NULL,
            `label` VARCHAR(100) NOT NULL,
            `sign_count` BIGINT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `last_used_at` DATETIME NULL DEFAULT NULL,
            UNIQUE KEY `uq_user_passkeys_credential` (`credential_id`(255)),
            INDEX `idx_user_passkeys_user` (`user_id`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


        // 3. Erweiterungen für Pferdeprofile (Ausländische UELN, Abstammung, Deckstation)
        $addColumn('horses', 'foreign_ueln', 'VARCHAR(50) NULL DEFAULT NULL AFTER `ueln`');
        $addColumn('horses', 'sire_id', 'INT NULL AFTER `foreign_ueln`');
        $addColumn('horses', 'sire_name', 'VARCHAR(100) NULL AFTER `sire_id`');
        $addColumn('horses', 'sire_ueln', 'VARCHAR(15) NULL AFTER `sire_name`');
        $addColumn('horses', 'dam_id', 'INT NULL AFTER `sire_ueln`');
        $addColumn('horses', 'dam_name', 'VARCHAR(100) NULL AFTER `dam_id`');
        $addColumn('horses', 'dam_ueln', 'VARCHAR(15) NULL AFTER `dam_name`');

        // 4. Deckstationen-Tabelle anlegen
        $createTable('breeding_stations', "
            CREATE TABLE IF NOT EXISTS `breeding_stations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(150) NOT NULL,
                `contact_person` VARCHAR(100) NULL,
                `address` TEXT NULL,
                `phone` VARCHAR(50) NULL,
                `email` VARCHAR(100) NULL,
                `website` VARCHAR(255) NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $addColumn('horses', 'breeding_station_id', 'INT NULL AFTER `color`');
        $addColumn('horses', 'breeding_station', 'VARCHAR(255) NULL AFTER `breeding_station_id`');
        $addColumn('horses', 'image_url', 'VARCHAR(255) NULL AFTER `status`');

        // Öffentliche Sichtbarkeit (is_published) unabhängig vom Lebenszyklus-`status`.
        // Beim ERSTMALIGEN Hinzufügen die bisher öffentlich sichtbaren Pferde
        // (status='active') als veröffentlicht übernehmen, damit sich die
        // öffentliche Sichtbarkeit durch das Upgrade nicht ändert. Der Backfill
        // läuft bewusst nur einmal (an die SHOW COLUMNS-Prüfung gekoppelt) - sonst
        // würde eine spätere, bewusste Depublikation bei jedem Lauf rückgängig
        // gemacht (analog zum Editor-Rechte-Seed weiter unten).
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `horses` LIKE 'is_published'");
            if ($stmt && $stmt->rowCount() === 0) {
                $pdo->exec("ALTER TABLE `horses` ADD COLUMN `is_published` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`");
                $pdo->exec("UPDATE `horses` SET `is_published` = 1 WHERE `status` = 'active'");
                $performed[] = "Spalte horses.is_published ergänzt (Bestand mit status='active' als veröffentlicht übernommen)";
            }
        } catch (\Throwable $e) {}

        // Öffentliche Sichtbarkeit auch für Personen und Deckstationen (Massen-
        // Veröffentlichung, siehe Admin-Listen). Diese Datensätze waren vor dem
        // Upgrade uneingeschränkt öffentlich (Stations-Detailseite, Katalog-Filter),
        // daher der EINMALIGE Backfill auf is_published=1 für den Bestand - sonst
        // würden bestehende Stationen/Personen durch das Upgrade unsichtbar. Neu
        // angelegte Datensätze starten dagegen unveröffentlicht (DEFAULT 0) und
        // müssen bewusst veröffentlicht werden. Der Backfill ist an die
        // SHOW COLUMNS-Prüfung gekoppelt und läuft nur beim erstmaligen Hinzufügen
        // (analog zum Pferde-Block oben).
        foreach (['persons', 'breeding_stations'] as $table) {
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'is_published'");
                if ($stmt && $stmt->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `is_published` TINYINT(1) NOT NULL DEFAULT 0");
                    $pdo->exec("UPDATE `{$table}` SET `is_published` = 1");
                    $performed[] = "Spalte {$table}.is_published ergänzt (Bestand als veröffentlicht übernommen)";
                }
            } catch (\Throwable $e) {}
        }

        // 5. Zuordnungen zwischen Pferden & Personen/Besitzern anlegen
        $createTable('horse_persons', "
            CREATE TABLE IF NOT EXISTS `horse_persons` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `horse_id` INT NOT NULL,
                `person_id` INT NULL,
                `role` ENUM('breeder', 'owner', 'keeper') NOT NULL DEFAULT 'owner',
                `breeding_station_id` INT NULL,
                `breeding_station_text` VARCHAR(255) NULL,
                `origin_country` VARCHAR(100) NULL,
                `from_year` SMALLINT UNSIGNED NULL,
                `until_year` SMALLINT UNSIGNED NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`person_id`) REFERENCES `persons`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $addColumn('horse_persons', 'breeding_station_id', 'INT NULL AFTER `role`');
        $addColumn('horse_persons', 'breeding_station_text', 'VARCHAR(255) NULL AFTER `breeding_station_id`');

        // 31. Herkunftsland ohne bekannte Person (#294, SCHEMA_VERSION 7).
        // Das Altsystem kannte "Zuechter unbekannt, kam aus Norwegen". Ohne
        // dieses Feld muss dafuer eine Platzhalter-Person in der PII-Tabelle
        // angelegt werden - in der Dev-Instanz betrifft das 171 von 672
        // Zuechter-Zuordnungen, die im Katalog als Zuechtername erscheinen,
        // obwohl dahinter kein Mensch steht.
        //
        // Kein Backfill: Welche Platzhalter-Person ein Land meint und welche
        // eine echte Person mit ungluecklichem Namen ist, kann nur die
        // jeweilige Instanz entscheiden. Dieselbe Zurueckhaltung wie in
        // Schritt 22, 29 und 30.
        $addColumn('horse_persons', 'origin_country', 'VARCHAR(100) NULL DEFAULT NULL AFTER `breeding_station_text`');

        // 32. Ausdrueckliche Freigabe der Kontaktdaten (SCHEMA_VERSION 8).
        //
        // Die Vorgabewerte sind BEWUSST verschieden und das ist der Kern des
        // Schritts: Bei persons war die Veroeffentlichung bis #293 ein
        // Versehen, dort ist 0 richtig. Bei breeding_stations sind Telefon und
        // E-Mail seit jeher oeffentlich (Geschaeftsadresse) - eine 0 wuerde
        // bestehende Angaben stillschweigend verstecken, und eine Migration
        // darf nichts wegnehmen, was vorher da war.
        // persons.contact_public steht NICHT hier, sondern unten bei Schritt 30
        // direkt hinter persons.is_breeder - seine AFTER-Klausel verweist auf
        // genau diese Spalte, und migrate() läuft strikt von oben nach unten
        // (#309).
        $addColumn('breeding_stations', 'contact_public', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `website`');

        // person_id NULL-fähig machen (Zuordnung kann auch nur über eine
        // Deckstation erfolgen). Früher ein bei jedem Lauf wiederholtes
        // MODIFY; der SHOW-COLUMNS-Guard dient jetzt zugleich der ehrlichen
        // Protokollierung (nur melden, was sich wirklich geändert hat).
        try {
            $col = $pdo->query("SHOW COLUMNS FROM `horse_persons` LIKE 'person_id'")->fetch();
            if (($col['Null'] ?? '') === 'NO') {
                $pdo->exec("ALTER TABLE `horse_persons` MODIFY COLUMN `person_id` INT NULL DEFAULT NULL;");
                $performed[] = 'Spalte horse_persons.person_id NULL-fähig gemacht';
            }
        } catch (\Throwable $e) {}

        // 6. Tabelle für Passwort-Zurücksetzen-Tokens
        $createTable('password_resets', "
            CREATE TABLE IF NOT EXISTS `password_resets` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(100) NOT NULL,
                `token` VARCHAR(64) NOT NULL UNIQUE,
                `expires_at` DATETIME NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // 7. DSGVO-Anfragen-Tabelle
        $createTable('gdpr_requests', "
            CREATE TABLE IF NOT EXISTS `gdpr_requests` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NULL,
                `email` VARCHAR(100) NOT NULL,
                `request_type` ENUM('info', 'deletion') NOT NULL,
                `message` TEXT NULL,
                `status` ENUM('pending', 'processed', 'rejected') DEFAULT 'pending',
                `admin_notes` TEXT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $addColumn('gdpr_requests', 'name', 'VARCHAR(100) NULL AFTER `id`');
        $addColumn('gdpr_requests', 'message', 'TEXT NULL AFTER `request_type`');
        $addColumn('gdpr_requests', 'admin_notes', 'TEXT NULL AFTER `status`');

        // 8. Papierkorb-Unterstützung (Soft Delete)
        $addColumn('horses', 'deleted_at', 'DATETIME NULL DEFAULT NULL');
        $addColumn('persons', 'deleted_at', 'DATETIME NULL DEFAULT NULL');
        $addColumn('breeding_stations', 'deleted_at', 'DATETIME NULL DEFAULT NULL');
        $addColumn('users', 'deleted_at', 'DATETIME NULL DEFAULT NULL');

        // 34. Gesperrt ist nicht geloescht (#358, SCHEMA_VERSION 14).
        // Ohne AFTER-Klausel: Die Reihenfolge der Spalten haengt auf
        // Bestandsinstallationen davon ab, welche Migrationen sie schon
        // gesehen haben - ein AFTER auf eine Spalte, die dort noch weiter
        // hinten steht, scheitert.
        // 35. E-Mail-Adresse ist keine Pflichtangabe mehr (#348).
        // Idempotent ueber die Abfrage von IS_NULLABLE - ein MODIFY bei jedem
        // Lauf waere zwar folgenlos, aber auf grossen Tabellen unnoetig teuer.
        try {
            $stmt = $pdo->query(
                "SELECT IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email'"
            );
            $nullable = $stmt ? (string)$stmt->fetchColumn() : 'YES';
            if ($nullable === 'NO') {
                $pdo->exec("ALTER TABLE `users` MODIFY `email` VARCHAR(100) NULL DEFAULT NULL");
                $performed[] = 'users.email ist keine Pflichtangabe mehr (#348)';
            }
        } catch (\Throwable $e) {
            // Bestandsinstallation ohne information_schema-Zugriff: dann
            // bleibt die Spalte NOT NULL, und Konten ohne Adresse lassen sich
            // schlicht nicht anlegen. Fail-closed in die harmlose Richtung.
        }

        $addColumn('users', 'deactivated_at', 'DATETIME NULL DEFAULT NULL');
        $addColumn('users', 'deactivated_reason', 'VARCHAR(64) NULL DEFAULT NULL');
        $addColumn('users', 'unprotected_since', 'DATETIME NULL DEFAULT NULL');

        // 37. Zweiter Faktor per E-Mail (#354, SCHEMA_VERSION 16).
        // NOT NULL mit Default 0: Ein Konto, dessen Faktor-Zustand "unbekannt"
        // waere, muesste die Anmeldung fail-closed behandeln - einfacher ist
        // ein Wert, den es nicht gibt.
        $addColumn('users', 'email_2fa_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');

        // 36. Adressaenderung in der Selbstbedienung (#357, SCHEMA_VERSION 15).
        $addColumn('users', 'pending_email', 'VARCHAR(100) NULL DEFAULT NULL');
        $addColumn('users', 'pending_email_token', 'VARCHAR(64) NULL DEFAULT NULL');
        $addColumn('users', 'pending_email_expires_at', 'DATETIME NULL DEFAULT NULL');

        // 9. Passwortänderungs-Zwang für neue/zurückgesetzte Benutzer. Früher ein
        // ungegatetes ALTER TABLE, das bei jedem Lauf einen (verschluckten)
        // Duplicate-Column-Fehler warf - jetzt regulär über den SHOW-COLUMNS-Guard.
        $addColumn('users', 'must_change_password', 'TINYINT(1) NOT NULL DEFAULT 0');

        // 10. Historische Geburtsjahre vor 1901 unterstützen (SMALLINT statt YEAR).
        // Typ-Guard analog zum Status-Split unten: nur umstellen (und melden),
        // solange die Spalte tatsächlich noch den YEAR-Typ trägt.
        try {
            $col = $pdo->query("SHOW COLUMNS FROM `horses` LIKE 'birth_year'")->fetch();
            if (stripos((string)($col['Type'] ?? ''), 'year') !== false) {
                $pdo->exec("ALTER TABLE `horses` MODIFY COLUMN `birth_year` SMALLINT UNSIGNED NULL");
                $performed[] = 'Spalte horses.birth_year von YEAR auf SMALLINT UNSIGNED umgestellt';
            }
        } catch (\Throwable $e) {}

        // 11. Login-Versuche für Brute-Force-Schutz (Login, 2FA, Backup-Codes)
        $createTable('login_attempts', "CREATE TABLE IF NOT EXISTS `login_attempts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `identifier` VARCHAR(255) NOT NULL,
            `type` VARCHAR(20) NOT NULL DEFAULT 'login',
            `ip_address` VARCHAR(45) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (`identifier`, `type`),
            INDEX (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 11c. Medien je Pferd (#339, SCHEMA_VERSION 17). Siehe
        // database/schema.sql fuer die Begruendung von `is_main` und dafuer,
        // warum `horses.image_url` trotzdem bleibt.
        $createTable('horse_media', "CREATE TABLE IF NOT EXISTS `horse_media` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `horse_id` INT NOT NULL,
            `type` ENUM('image','video') NOT NULL,
            `file_name` VARCHAR(255) NULL DEFAULT NULL,
            `video_url` VARCHAR(255) NULL DEFAULT NULL,
            `caption` VARCHAR(255) NULL DEFAULT NULL,
            `is_main` TINYINT(1) NOT NULL DEFAULT 0,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_horse_media_horse` (`horse_id`, `sort_order`, `id`),
            FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 11b. Einmalcodes fuer den zweiten Faktor per E-Mail (#354,
        // SCHEMA_VERSION 16). Siehe database/schema.sql fuer die Begruendung
        // von Abdruck-Speicherung und Primaerschluessel.
        $createTable('email_2fa_codes', "CREATE TABLE IF NOT EXISTS `email_2fa_codes` (
            `user_id` INT NOT NULL,
            `purpose` VARCHAR(20) NOT NULL,
            `code_hash` VARCHAR(255) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`, `purpose`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 12. Plugin-System (siehe src/Plugin/PluginManager.php, #56): Aktivierungsstatus
        // pro Plugin, unabhängig vom Verzeichnis-Scan in plugins/ - ein deaktiviertes
        // Plugin bleibt so nach einem Deployment ohne DB-Zugriff sicher inaktiv.
        $createTable('plugins', "CREATE TABLE IF NOT EXISTS `plugins` (
            `slug` VARCHAR(100) NOT NULL PRIMARY KEY,
            `enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `installed_version` VARCHAR(20) NOT NULL DEFAULT '0.0.0',
            `content_hash` VARCHAR(64) NULL DEFAULT NULL,
            `activated_at` DATETIME NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // content_hash: eindeutiger Inhalts-Fingerabdruck (SHA-256 über alle Dateien des
        // Plugin-Verzeichnisses) der bei Aktivierung freigegebenen Version - verhindert,
        // dass ein nachträglich unter demselben Slug ausgetauschter Plugin-Code stillschweigend
        // unter der alten Freigabe weiterläuft (siehe PluginManager::loadEnabledPlugins()).
        // Für Bestandsinstallationen von vor Einführung dieser Spalte nachgerüstet.
        $addColumn('plugins', 'content_hash', "VARCHAR(64) NULL DEFAULT NULL AFTER `installed_version`");

        // 13. Gruppen-/Berechtigungssystem (#66, siehe docs/user-groups-plan.md und
        // BaseController::hasPermission()) - EINZIGES Rechtesystem der App. Drei
        // feste Gruppen admin/editor/public werden geseedet. Security-by-Design:
        // Mitgliedschaft ist für JEDE Gruppe (auch `admin`/`editor`) ausschließlich
        // explizit über `user_groups` - kein impliziter Standard (siehe
        // BaseController::userGroupIds() und die Migration weiter unten). `admin`
        // hat zusätzlich systemseitig immer implizit ALLE Rechte (siehe
        // hasPermission()), ihre eigene Berechtigungs-Matrix bleibt deshalb leer
        // und nicht editierbar. `public` repräsentiert nicht angemeldete Besucher;
        // über ihre Lese-Rechte steuert ein Admin die öffentliche Sichtbarkeit.
        $createTable('groups', "CREATE TABLE IF NOT EXISTS `groups` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `slug` VARCHAR(50) NOT NULL UNIQUE,
            `name` VARCHAR(100) NOT NULL,
            `description` VARCHAR(255) NULL,
            `is_builtin` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        try {
            $pdo->exec("INSERT IGNORE INTO `groups` (`slug`, `name`, `description`, `is_builtin`) VALUES
                ('admin', 'Administrator', 'Hat systemseitig immer uneingeschränkt alle Berechtigungen.', 1),
                ('editor', 'Editor', 'Vorlage für Bearbeiter mit Verwaltungszugriff - muss Benutzern wie jede andere Gruppe bewusst zugewiesen werden, kein automatischer Standard.', 1),
                ('public', 'Gast (Öffentlich)', 'Gilt automatisch für nicht angemeldete Besucher. Über ihre Lese-Rechte steuert ein Admin, welche Bereiche im öffentlichen Teil der Website sichtbar sind. Backend-Zugriff (/admin/...) bleibt stets ausgeschlossen (siehe BaseController::checkAuth()).', 1)");
        } catch (\Throwable $e) {}

        $createTable('user_groups', "CREATE TABLE IF NOT EXISTS `user_groups` (
            `user_id` INT NOT NULL,
            `group_id` INT NOT NULL,
            PRIMARY KEY (`user_id`, `group_id`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Erkennen, ob group_permissions gerade NEU angelegt wird (Bestandsinstallation
        // ohne dieses Feature) - nur dann die Editor-Standardrechte seeden, damit eine
        // spätere, bewusste Rechte-Entziehung durch einen Admin nicht bei jedem
        // Lauf erneut rückgängig gemacht wird (siehe docs/user-groups-plan.md, 3.4/8).
        $groupPermissionsExisted = true;
        try {
            $checkStmt = $pdo->query("SHOW TABLES LIKE 'group_permissions'");
            $groupPermissionsExisted = $checkStmt && $checkStmt->rowCount() > 0;
        } catch (\Throwable $e) {}

        $createTable('group_permissions', "CREATE TABLE IF NOT EXISTS `group_permissions` (
            `group_id` INT NOT NULL,
            `module` VARCHAR(50) NOT NULL,
            `action` VARCHAR(50) NOT NULL,
            PRIMARY KEY (`group_id`, `module`, `action`),
            FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Standardrechte für Editor (voller Verwaltungszugriff inkl. der Standard-Aktionen
        // 'view'/'publish') und Gast (`public`: nur Lese-Rechte für die öffentlich sichtbare
        // Fläche) seeden - genau EINMAL je Instanz, durchgesetzt über den Marker
        // migration_66_standardrechte_seed in settings (Audit N18). Geseedet wird nur:
        //  (a) wenn group_permissions in DIESEM Lauf neu angelegt wurde (Upgrade von
        //      vor #66), oder
        //  (b) beim Upgrade eines #66-Stands ohne Leserecht (v0.2.0-beta.1, vor #213
        //      also ohne gespeicherte schema_version), in dem es noch keine einzige
        //      'view'-Zeile gibt - damit Editoren die Backend-Listen und Gäste den
        //      öffentlichen Katalog nicht verlieren.
        //
        // Der Rechtebestand allein ist KEIN Beweis für "nie geseedet": Bis zum Fix lief
        // diese Prüfung bei jedem Voll-Lauf, und ein Admin, der bewusst alle Leserechte
        // entzogen hatte (rein interne Instanz), bekam sie mit dem nächsten Update
        // zurück. Deshalb gilt (b) nur, wenn der Stand vor der Migration 0 war UND das
        // Kontaktschema (#336, ab v0.8.0) noch nicht steht. Stand 0 allein reicht nicht:
        // Er entsteht auch bei einem Restore oder Settings-Import ohne schema_version.
        // Ab v0.4.0 lief dieser Seed in jedem versionierten Voll-Lauf mindestens
        // einmal; fehlt dort heute jedes Leserecht, war das Absicht.
        //
        // Bestand und frisches database/schema.sql werden nur still markiert. Ein
        // echter Fehler schlägt nach oben durch (#309): run() persistiert dann
        // weder Marker noch Version, der nächste Lauf versucht es erneut. Der Seed
        // ist per INSERT IGNORE gegen bereits vorhandene Zeilen idempotent.
        $dataStep('66_standardrechte_seed', function () use ($pdo, $groupPermissionsExisted, $vorherigeVersion, $kontaktschemaAktiv, $tabelleExistiert): ?array {
            if (!$tabelleExistiert('group_permissions')) {
                return null; // Setup-Fall - nichts vermerken, später erneut.
            }

            $seedNoetig = !$groupPermissionsExisted;
            if (!$seedNoetig && $vorherigeVersion === 0 && !$kontaktschemaAktiv) {
                $seedNoetig = (int)$pdo->query("SELECT COUNT(*) FROM `group_permissions` WHERE `action` = 'view'")->fetchColumn() === 0;
            }
            if (!$seedNoetig) {
                return []; // Bestand oder frisches schema.sql: nur vermerken, still.
            }

            $insertPermStmt = $pdo->prepare("INSERT IGNORE INTO `group_permissions` (`group_id`, `module`, `action`) VALUES (?, ?, ?)");

            // Parität zu database/schema.sql: Steht das Kontaktschema schon (nur im
            // Fall (a) denkbar), direkt contacts.* - sonst die Altmodule, die
            // Schritt 31e danach als Schnittmenge nach contacts.* umhängt.
            $kontaktModule = $kontaktschemaAktiv ? ['contacts'] : ['persons', 'breeding_stations'];

            $editorGroupId = $pdo->query("SELECT id FROM `groups` WHERE slug = 'editor'")->fetchColumn();
            if ($editorGroupId) {
                foreach (array_merge(['horses'], $kontaktModule) as $module) {
                    foreach (['view', 'create', 'edit', 'delete', 'publish'] as $action) {
                        $insertPermStmt->execute([$editorGroupId, $module, $action]);
                    }
                }
            }

            // Gast-Gruppe: ausschließlich die Lese-Rechte der heute öffentlich
            // sichtbaren Fläche. Bewusst nichts weiter - neue/Plugin-Bereiche
            // bleiben für Gäste fail-closed unsichtbar, bis ein Admin sie freischaltet.
            $publicGroupId = $pdo->query("SELECT id FROM `groups` WHERE slug = 'public'")->fetchColumn();
            if ($publicGroupId) {
                $gastModule = $kontaktschemaAktiv ? ['horses', 'contacts'] : ['horses', 'breeding_stations'];
                foreach ($gastModule as $module) {
                    $insertPermStmt->execute([$publicGroupId, $module, 'view']);
                }
            }

            return ['Standardrechte für die Gruppen editor/public geseedet (group_permissions)'];
        });

        // 13b. Rollensystem entfernt: Bestandsinstallationen hatten bislang
        // zusätzlich zum Gruppensystem eine users.role-Spalte (admin/editor), die
        // für Adminrechte (BaseController::requireAdmin()) und die automatische
        // Editor-Gruppenmitgliedschaft genutzt wurde. Einmalig (abgesichert durch
        // die SHOW COLUMNS-Prüfung selbst - läuft nie wieder, sobald die Spalte
        // weg ist) echte user_groups-Zeilen für alle role='admin'- und
        // role='editor'-Benutzer nachziehen, damit sich ihre Rechte durch dieses
        // Update nicht rückwirkend ändern, dann die Spalte entfernen. Ab hier ist
        // das Gruppensystem die EINZIGE Quelle für Berechtigungen (siehe
        // GroupMembership::isAdmin()).
        try {
            $roleColumnExists = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'role'")->rowCount() > 0;
        } catch (\Throwable $e) {
            $roleColumnExists = false;
        }

        if ($roleColumnExists) {
            try {
                $adminGroupId = $pdo->query("SELECT id FROM `groups` WHERE slug = 'admin'")->fetchColumn();
                if ($adminGroupId) {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO user_groups (user_id, group_id) SELECT id, ? FROM users WHERE role = 'admin'");
                    $stmt->execute([$adminGroupId]);
                }

                $editorGroupId = $pdo->query("SELECT id FROM `groups` WHERE slug = 'editor'")->fetchColumn();
                if ($editorGroupId) {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO user_groups (user_id, group_id) SELECT id, ? FROM users WHERE role = 'editor'");
                    $stmt->execute([$editorGroupId]);
                }

                $pdo->exec("ALTER TABLE `users` DROP COLUMN `role`");
                $performed[] = 'Spalte users.role in user_groups-Mitgliedschaften überführt und entfernt';
            } catch (\Throwable $e) {}
        }

        // 14. Addon-Store (Registry-Client, siehe docs/plugin-system-plan.md Phase 3
        // und App\Service\GithubAddonRepository): registrierte GitHub-Repos, aus denen
        // Admins Plugins direkt im Browser installieren können, statt sie manuell per
        // `cp -r` nach plugins/ zu kopieren. `is_official` markiert das mitgelieferte
        // Hengstverzeichnis_Addons-Repo - es ist immer vorhanden und kann nicht über die
        // UI entfernt werden (siehe AddonStoreController::removeRepo()), jedes weitere
        // Repo ist eine bewusste, von einem Admin per Link hinzugefügte Quelle. Der
        // Katalog eines Repos (gescannte plugins/*/plugin.json) wird kurzzeitig
        // gecacht (cached_catalog_json/cached_at), um nicht bei jedem Aufruf von
        // /admin/plugins/store erneut das komplette Tarball herunterzuladen.
        $createTable('addon_repos', "CREATE TABLE IF NOT EXISTS `addon_repos` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `owner` VARCHAR(100) NOT NULL,
            `repo` VARCHAR(100) NOT NULL,
            `ref` VARCHAR(100) NULL DEFAULT NULL,
            `is_official` TINYINT(1) NOT NULL DEFAULT 0,
            `added_by` INT NULL DEFAULT NULL,
            `cached_catalog_json` MEDIUMTEXT NULL DEFAULT NULL,
            `cached_at` DATETIME NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `owner_repo` (`owner`, `repo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        try {
            $pdo->exec("INSERT IGNORE INTO addon_repos (owner, repo, ref, is_official) VALUES ('Celestial0579', 'Hengstverzeichnis_Addons', NULL, 1)");
        } catch (\Throwable $e) {}

        // API-Schlüssel für die JSON-API (siehe App\Security\ApiKey und
        // docs/api.md). Muss auch hier angelegt werden - nicht nur in
        // database/schema.sql -, damit BESTEHENDE Installationen die Tabelle
        // beim ersten Migrationslauf nach dem Update automatisch erhalten. Ohne
        // sie wäre die (seit der Schlüsselpflicht auf diese Tabelle angewiesene)
        // API nach einem Update nicht mehr nutzbar.
        $createTable('api_keys', "CREATE TABLE IF NOT EXISTS `api_keys` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `label` VARCHAR(100) NOT NULL,
            `token_hash` CHAR(64) NOT NULL UNIQUE,
            `token_prefix` VARCHAR(20) NOT NULL,
            `scope_permissions` TEXT NULL DEFAULT NULL,
            `last_used_at` DATETIME NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `revoked_at` DATETIME NULL DEFAULT NULL,
            INDEX `idx_api_keys_user` (`user_id`, `revoked_at`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Herkunft eines installierten Plugins (z. B. 'Celestial0579/Hengstverzeichnis_Addons@main')
        // für die Anzeige unter /admin/plugins - rein informativ, NULL bei manuell
        // (per cp -r) installierten Plugins ohne Store-Herkunft.
        $addColumn('plugins', 'source', "VARCHAR(150) NULL DEFAULT NULL AFTER `content_hash`");

        // 15. Session-Invalidierung bei Passwortänderung (#113): Zähler wird bei
        // jeder Passwortänderung erhöht; BaseController::checkAuth() vergleicht
        // ihn mit dem beim Login in der Session abgelegten Wert und beendet
        // Sessions mit veraltetem Stand (siehe docs/security.md).
        $addColumn('users', 'session_version', 'INT NOT NULL DEFAULT 1');

        // 16. TOTP-Replay-Schutz (#111): zuletzt verbrauchter TOTP-Zeitschlitz -
        // Totp::verifyCodeReturnSlice() lehnt Schlitze <= diesem Wert ab, ein
        // Code ist damit single-use (siehe AuthController::process2faVerify()).
        $addColumn('users', 'last_totp_timeslice', 'BIGINT NULL DEFAULT NULL');

        // 17. 2FA-Pflicht pro Gruppe (#84): Default 1 = verpflichtend (Status
        // quo für Bestandsgruppen). Für die Gruppe `admin` fest verdrahtet
        // immer verpflichtend, unabhängig von dieser Spalte (siehe
        // AuthController::userRequires2fa() und GroupController).
        $addColumn('groups', 'require_2fa', 'TINYINT(1) NOT NULL DEFAULT 1');

        // 18. Selfservice-Registrierung (#83): E-Mail-Verifizierung vor der
        // Erstanmeldung. Ein gesetzter Token bedeutet "noch nicht verifiziert" -
        // der Login ist bis zur Bestätigung gesperrt (AuthController). Admin-
        // angelegte Konten erhalten nie einen Token und sind nicht betroffen.
        $addColumn('users', 'email_verification_token', 'VARCHAR(64) NULL DEFAULT NULL');
        $addColumn('users', 'email_verification_expires_at', 'DATETIME NULL DEFAULT NULL');

        // 19. Fehlende Indizes für Bestandsinstallationen nachrüsten (#120):
        // horses/persons/breeding_stations hatten außer PK/UNIQUE/FK-Indizes
        // keinerlei Indizes - jede öffentliche Abfrage (deleted_at IS NULL AND
        // is_published = 1, ORDER BY name) und der Papierkorb-Badge-Count liefen
        // als Full Table Scan. Spiegelbildlich zu database/schema.sql.
        $addIndex = function ($table, $indexName, $columns) use ($pdo, &$performed, $kontaktschemaAktiv, $abgeloest) {
            if ($kontaktschemaAktiv && in_array($table, $abgeloest, true)) {
                return; // Abgelöst durch `contacts` (#336) - nicht wiederbeleben.
            }
            try {
                $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
                $stmt->execute([$indexName]);
                if ($stmt->rowCount() === 0) {
                    $pdo->exec("CREATE INDEX `$indexName` ON `$table` ($columns)");
                    $performed[] = "Index {$table}.{$indexName} angelegt";
                }
            } catch (\Throwable $e) {
                // Tabelle existiert noch nicht oder Index-Prüfung fehlgeschlagen
            }
        };

        $addIndex('horses', 'idx_horses_published_name', '`is_published`, `deleted_at`, `name`');
        $addIndex('horses', 'idx_horses_deleted_name', '`deleted_at`, `name`');
        $addIndex('horses', 'idx_horses_name', '`name`');
        $addIndex('horses', 'idx_horses_foreign_ueln', '`foreign_ueln`');
        $addIndex('horse_persons', 'idx_horse_persons_horse_role', '`horse_id`, `role`');
        $addIndex('persons', 'idx_persons_deleted_name', '`deleted_at`, `name`');
        $addIndex('breeding_stations', 'idx_bs_deleted_name', '`deleted_at`, `name`');
        $addIndex('users', 'idx_users_deleted', '`deleted_at`');
        $addIndex('users', 'idx_users_deactivated', '`deactivated_at`');

        // 20. Geschlecht (#165) und Rasse (#163) für Pferde. NULL = unbekannt
        // (Altbestand); die Geschlechts-Validierung der Abstammung (#166/#167)
        // greift nur bei bekanntem Geschlecht. Spiegelbildlich zu database/schema.sql.
        $addColumn('horses', 'sex', "ENUM('stallion', 'mare', 'gelding') NULL DEFAULT NULL AFTER `color`");
        $addColumn('horses', 'breed', 'VARCHAR(100) NULL DEFAULT NULL AFTER `sex`');

        // 21. Stammdaten-Ausbau (#188): Geburtsdatum, Stockmaß und der
        // Status-Split. status wird zum reinen Zuchtstatus (active/inactive),
        // der Lebensstatus wandert nach is_deceased/death_year. Einmal-Gate
        // über den Spaltentyp: solange 'deceased' noch im Enum steht, ist die
        // Umstellung offen - erst Backfill (UPDATE), DANN das MODIFY, sonst
        // schneidet der Strict Mode die deceased-Werte ab. Spiegelbildlich zu
        // database/schema.sql.
        $addColumn('horses', 'birth_date', 'DATE NULL DEFAULT NULL AFTER `birth_year`');

        // 38. Genauigkeit des Geburtsdatums (#379, SCHEMA_VERSION 18).
        //
        // Steht hier und nicht bei den anderen hohen Nummern, weil die
        // Schrittnummern Etiketten sind, die AUSFUEHRUNG aber der
        // Dateireihenfolge folgt: `AFTER birth_date` braucht die Spalte
        // darueber. Weiter unten eingehaengt scheiterte die Migration eines
        // Altschemas mit "Unknown column 'birth_date'" - und riss die
        // uebrigen Schritte mit.
        //
        // AUSDRUECKLICH OHNE BACKFILL. Der 1. Januar ist in dieser Branche der
        // Platzhalter fuer ein bloss bekanntes Jahr - im Altbestand traf das
        // 887 von 1885 Pferden bei 11 Februargeburten. Es gibt aber auch
        // Pferde, die wirklich an dem Tag geboren sind, und welche Zeile
        // welche ist, weiss nur die jeweilige Instanz. Eine Migration, die das
        // raet, kennzeichnet echte Neujahrsgeburten falsch - unumkehrbar und
        // ohne dass es jemandem auffiele.
        //
        // Vorgabe 'day' heisst deshalb: Ein Bestand sieht nach dem Update aus
        // wie vorher. Die Pflege ist eine bewusste Entscheidung je Datensatz.
        $addColumn('horses', 'birth_date_precision', "ENUM('day', 'year') NOT NULL DEFAULT 'day' AFTER `birth_date`");
        $addColumn('horses', 'height_cm', 'SMALLINT UNSIGNED NULL DEFAULT NULL AFTER `breed`');
        $addColumn('horses', 'is_deceased', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`');
        $addColumn('horses', 'death_year', 'SMALLINT UNSIGNED NULL DEFAULT NULL AFTER `is_deceased`');
        try {
            $statusColumn = $pdo->query("SHOW COLUMNS FROM `horses` LIKE 'status'")->fetch();
            if (stripos((string)($statusColumn['Type'] ?? ''), 'deceased') !== false) {
                $pdo->exec("UPDATE `horses` SET `is_deceased` = 1, `status` = 'inactive' WHERE `status` = 'deceased'");
                $pdo->exec("ALTER TABLE `horses` MODIFY COLUMN `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active'");
                $performed[] = "Status-Split: horses.status-Bestand 'deceased' nach is_deceased/death_year überführt, Enum bereinigt";
            }
        } catch (\Throwable $e) {
            // Tabelle existiert noch nicht
        }

        // 22. Strukturierte Personendaten (#188): Adresse, E-Mail und
        // Mitgliedsstatus als eigene Spalten; contact_info bleibt als
        // Freitext-Restfeld. Kein Backfill - der bisherige Freitext lässt
        // sich nicht zuverlässig zerlegen. Spiegelbildlich zu database/schema.sql.
        $addColumn('persons', 'street', 'VARCHAR(150) NULL DEFAULT NULL AFTER `contact_info`');
        $addColumn('persons', 'house_number', 'VARCHAR(20) NULL DEFAULT NULL AFTER `street`');
        $addColumn('persons', 'postal_code', 'VARCHAR(20) NULL DEFAULT NULL AFTER `house_number`');
        $addColumn('persons', 'city', 'VARCHAR(100) NULL DEFAULT NULL AFTER `postal_code`');
        $addColumn('persons', 'country', 'VARCHAR(100) NULL DEFAULT NULL AFTER `city`');
        $addColumn('persons', 'email', 'VARCHAR(100) NULL DEFAULT NULL AFTER `country`');
        $addColumn('persons', 'membership_status', 'VARCHAR(100) NULL DEFAULT NULL AFTER `email`');

        // 23. Indizes für die Katalog-Filteroptionen (#221): SELECT DISTINCT
        // color/breed ... WHERE deleted_at IS NULL lief mangels Index als Full
        // Table Scan mit temporärer Tabelle + Filesort über die größte Tabelle.
        // Mit (color|breed, deleted_at) werden daraus Index-Only-Scans.
        $addIndex('horses', 'idx_horses_color', '`color`, `deleted_at`');
        $addIndex('horses', 'idx_horses_breed', '`breed`, `deleted_at`');

        // 24. Billiger Verzeichnis-Stempel je Plugin (#224, siehe
        // PluginManager::computeDirStamp()): max(filemtime), Dateianzahl und
        // Gesamtgröße des Plugin-Ordners zum Zeitpunkt der Freigabe. Stimmt der
        // gespeicherte Stempel beim Bootstrap überein, entfällt der teure
        // SHA-256-Fingerabdruck über alle Plugin-Dateien komplett; jede
        // Abweichung erzwingt weiterhin den vollen Hash-Vergleich (fail-closed).
        $addColumn('plugins', 'dir_stamp', "VARCHAR(64) NULL DEFAULT NULL AFTER `content_hash`");

        // 25. API-Schlüssel an die session_version ihres Besitzers koppeln
        // (#217): Beim Anlegen wird der aktuelle Stand mitgeschrieben; die
        // Authentifizierung akzeptiert nur Schlüssel mit übereinstimmendem
        // Stand. Ein Passwort-Reset (erhöht users.session_version) entzieht
        // damit auch allen zuvor ausgestellten API-Schlüsseln die Gültigkeit -
        // dieselbe Incident-Response-Kette wie bei Sessions (siehe
        // App\Security\ApiKey und BaseController::checkAuth()). DEFAULT 1
        // entspricht dem session_version-Startwert, Bestandsschlüssel von
        // Benutzern ohne zwischenzeitliche Passwortänderung bleiben gültig.
        $addColumn('api_keys', 'issued_session_version', 'INT NOT NULL DEFAULT 1');

        // 33. Pflicht-Ablaufdatum fuer API-Schluessel (#340, SCHEMA_VERSION 13).
        //
        // BESTANDSSCHLUESSEL LAUFEN MIT DEM UPDATE AB - ohne Uebergangsfrist.
        // Das ist eine bewusste Bruchstelle: Ein Schluessel unbekannten
        // Alters, der weiterlaeuft, ist genau der Zustand, den #340 beendet.
        // Der Spaltendefault CURRENT_TIMESTAMP setzt vorhandene Zeilen auf den
        // Zeitpunkt des Updates; ApiKey::authenticate() verlangt
        // `expires_at > NOW()`, sie sind damit ab dem Einspielen ungueltig.
        // Laufende Anbindungen brechen ab, bis ein neuer Schluessel eingetragen
        // ist - das gehoert auffaellig in die Release Notes.
        $addColumn('api_keys', 'expires_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

        // 26. Indizes für den Blutlinien-Vorfilter (#215): der MatchSuggestion-
        // Finder holt Kandidaten jetzt gezielt über (deleted_at, sire_id) bzw.
        // (deleted_at, dam_id) statt per Kreuzprodukt über den Gesamtbestand;
        // ohne diese Indizes fiele die Kandidatensuche auf einen Full Scan
        // der horses-Tabelle je Lauf zurück.
        $addIndex('horses', 'idx_horses_sire_unlinked', '`deleted_at`, `sire_id`');
        $addIndex('horses', 'idx_horses_dam_unlinked', '`deleted_at`, `dam_id`');

        // 27. Kastrationsdatum (#239, SCHEMA_VERSION 2): echtes Sachdatum -
        // die Deckeinsatz-Historie eines Wallachs endet dort. Fachlich nur bei
        // sex='gelding' sinnvoll (das Formular blendet das Feld entsprechend
        // ein/aus), serverseitig aber tolerant für jedes Geschlecht
        // gespeichert. NULL = nicht erfasst. Spiegelbildlich zu
        // database/schema.sql.
        $addColumn('horses', 'castration_date', 'DATE NULL DEFAULT NULL AFTER `sex`');

        // 28. Weitere Lebensnummern (#246, SCHEMA_VERSION 3): eigene Kindtabelle
        // horse_registrations statt der ' / '-Verkettung in horses.foreign_ueln
        // (varchar(50)), die real Nummern abgeschnitten hat. horses.ueln bleibt
        // die Primärnummer und wird NICHT dupliziert; horses.foreign_ueln bleibt
        // aus Abwärtskompatibilität bestehen (CSV-Import, API-Ausgabe,
        // Anzeige-Fallback), wird vom Admin-Formular aber nicht mehr befüllt.
        //
        // EINMAL-Backfill, an die SHOW TABLES-Prüfung gekoppelt (analog zum
        // is_published-Block oben): Bestehende foreign_ueln-Werte werden an
        // ' / ' bzw. '/' zerlegt und als Einzelzeilen übernommen. Nur beim
        // erstmaligen Anlegen der Tabelle - sonst würde ein späterer Lauf
        // (z. B. nach einer bewussten Korrektur der Nummern im Formular) die
        // Zeilen aus dem inzwischen veralteten foreign_ueln-Feld duplizieren.
        // foreign_ueln selbst bleibt unangetastet (Abwärtskompatibilität).
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'horse_registrations'");
            $registrationsExisted = $stmt && $stmt->rowCount() > 0;
            $pdo->exec("CREATE TABLE IF NOT EXISTS `horse_registrations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `horse_id` INT NOT NULL,
                `registration_number` VARCHAR(50) NOT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE,
                INDEX `idx_horse_registrations_horse` (`horse_id`, `sort_order`),
                INDEX `idx_horse_registrations_number` (`registration_number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            if (!$registrationsExisted) {
                $performed[] = 'Tabelle horse_registrations angelegt';

                $insert = $pdo->prepare(
                    "INSERT INTO horse_registrations (horse_id, registration_number, sort_order) VALUES (?, ?, ?)"
                );
                $rows = $pdo->query(
                    "SELECT id, ueln, foreign_ueln FROM horses WHERE foreign_ueln IS NOT NULL AND foreign_ueln != ''"
                )->fetchAll(PDO::FETCH_ASSOC);

                $migratedHorses = 0;
                foreach ($rows as $row) {
                    $sortOrder = 0;
                    $seen = [];
                    // Auch '/' ohne umgebende Leerzeichen zerlegen - durch das
                    // varchar(50)-Limit abgeschnittene Verkettungen enden teils
                    // mitten im Trennzeichen.
                    foreach (preg_split('~\s*/\s*~', (string)$row['foreign_ueln']) ?: [] as $number) {
                        $number = trim($number);
                        if ($number === '') {
                            continue;
                        }
                        // ueln bleibt Primärnummer im horses-Feld - nicht duplizieren.
                        if (mb_strtolower($number) === mb_strtolower(trim((string)($row['ueln'] ?? '')))) {
                            continue;
                        }
                        $key = mb_strtolower($number);
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                        $insert->execute([(int)$row['id'], $number, $sortOrder++]);
                    }
                    if ($sortOrder > 0) {
                        $migratedHorses++;
                    }
                }
                if ($migratedHorses > 0) {
                    $performed[] = sprintf(
                        'horse_registrations-Backfill: foreign_ueln von %d Pferd(en) in Einzelnummern zerlegt',
                        $migratedHorses
                    );
                }
            }
        } catch (\Throwable $e) {
            // Tabelle horses existiert ggf. noch nicht (Setup-Fall)
        }

        // 29. Bundesland/Kanton und strukturierte Stationsadresse (#256,
        // SCHEMA_VERSION 4). Bei DACH-weiten Zuchtdaten reichen Land und PLZ
        // oft nicht, um Herkunft oder Zuständigkeit (Landesverband) einzuordnen.
        //
        // persons: nur die eine fehlende Spalte, eingereiht zwischen city und
        // country. Freitext wie country - bewusst ohne ISO-3166-2-Validierung,
        // konsistent mit country/breed/membership_status.
        $addColumn('persons', 'state', 'VARCHAR(100) NULL DEFAULT NULL AFTER `city`');

        // breeding_stations hatte bisher überhaupt keine strukturierte Adresse,
        // nur das Freitextfeld address (und nicht einmal ein country). Deshalb
        // hier der volle Satz.
        //
        // Kein Backfill aus address: Der Bestand ist real mehrzeilig
        // ("Weideweg 1\n24000 Kiel"), eine Zerlegung wäre geraten. Dieselbe
        // Entscheidung wie bei den Personendaten in Schritt 22. address bleibt
        // deshalb bestehen, wird weiterhin angezeigt, solange die neuen Felder
        // leer sind, und bleibt als station_address Teil des dokumentierten
        // Plugin-Payloads - die Erweiterung ist damit rein additiv.
        $addColumn('breeding_stations', 'street', 'VARCHAR(150) NULL DEFAULT NULL AFTER `contact_person`');
        $addColumn('breeding_stations', 'house_number', 'VARCHAR(20) NULL DEFAULT NULL AFTER `street`');
        $addColumn('breeding_stations', 'postal_code', 'VARCHAR(20) NULL DEFAULT NULL AFTER `house_number`');
        $addColumn('breeding_stations', 'city', 'VARCHAR(100) NULL DEFAULT NULL AFTER `postal_code`');
        $addColumn('breeding_stations', 'state', 'VARCHAR(100) NULL DEFAULT NULL AFTER `city`');
        $addColumn('breeding_stations', 'country', 'VARCHAR(100) NULL DEFAULT NULL AFTER `state`');

        // 30. Kontaktfelder für Personen (#293, SCHEMA_VERSION 6). persons
        // hatte als einzige Kontaktmöglichkeit neben der E-Mail-Adresse das
        // Freitextfeld contact_info - und das Formular lud ausdrücklich zu
        // Telefonnummern darin ein, während dasselbe Feld öffentlich
        // gerendert wurde. Die Spalten spiegeln breeding_stations.
        //
        // Kein Backfill aus contact_info: Der Bestand ist beschrifteter
        // Freitext ("Mobil: 0170 ...", "Website: ..."), eine Zerlegung wäre
        // geraten - dieselbe Entscheidung wie in Schritt 22 und 29. Wer
        // Altdaten überführen will, tut das instanzspezifisch; die Zielspalten
        // gibt es ab hier.
        $addColumn('persons', 'phone', 'VARCHAR(50) NULL DEFAULT NULL AFTER `email`');
        $addColumn('persons', 'mobile', 'VARCHAR(50) NULL DEFAULT NULL AFTER `phone`');
        $addColumn('persons', 'website', 'VARCHAR(255) NULL DEFAULT NULL AFTER `mobile`');

        // Kennzeichen "diese Person züchtet": bewusst eine Eigenschaft der
        // Person und nicht aus horse_persons.role='breeder' abgeleitet - ein
        // Züchter soll auch dann auffindbar sein, wenn noch kein Pferd von ihm
        // im Verzeichnis steht. Grundlage für eine spätere Zucht-Suche über
        // Züchter und Deckstationen; der Index bedient genau diese Filterung
        // (Muster wie die Katalog-Indizes in Schritt 23).
        //
        // Kein Backfill aus horse_persons, und zwar bewusst: Eine
        // Züchter-Zuordnung ist Historie (sie trägt from_year/until_year), das
        // Kennzeichen dagegen sagt "züchtet heute". Wer früher gezüchtet hat,
        // wäre nach einem Backfill dauerhaft als aktiver Züchter markiert -
        // genau die Verwechslung, die das eigene Feld vermeiden soll. Der
        // Vorgabewert 0 lässt die Aussage offen, bis jemand sie trifft.
        $addColumn('persons', 'is_breeder', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `membership_status`');
        $addIndex('persons', 'idx_persons_is_breeder', '`is_breeder`, `is_published`, `deleted_at`');

        // Ausdrückliche Freigabe der Kontaktdaten einer Person (Schritt 32,
        // hierher gezogen). Der Vorgabewert 0 ist bewusst anders als bei den
        // Deckstationen: Bei persons war die Veröffentlichung bis #293 ein
        // Versehen, dort ist 0 richtig; bei breeding_stations sind Telefon und
        // E-Mail seit jeher öffentlich (Geschäftsadresse), und eine Migration
        // darf nichts wegnehmen, was vorher da war.
        //
        // Die Zeile MUSS hinter is_breeder stehen: Die AFTER-Klausel nennt
        // diese Spalte, und auf einer Installation mit schema_version < 6 gibt
        // es sie vorher nicht. Genau daran scheiterte #309.
        $addColumn('persons', 'contact_public', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_breeder`');

        // Leserecht der Gast-Gruppe für die neue öffentliche Personenseite
        // (/person). Ohne dieses Recht liefe der Verweis von der Pferdeseite
        // ins Leere - die Gast-Gruppe ist bewusst fail-closed und bekommt neue
        // Bereiche nicht automatisch (siehe database/schema.sql beim Seed).
        //
        // Es entstehen dadurch KEINE neuen öffentlichen Daten: Die Seite zeigt
        // ausschließlich Felder, die auf der Pferde-Detailseite ohnehin schon
        // öffentlich sind (Ort, Bundesland, Land) plus die dafür vorgesehene
        // Website. Wer die Seite nicht möchte, nimmt der Gruppe `public` das
        // Recht wieder weg.
        //
        // Dass es dann weg BLEIBT, sichern der Marker
        // migration_293_gastrecht_personenseite und $gastrechtSeedBelegt (Stand
        // vor der Migration >= 6 oder persons.is_breeder schon vorhanden) - NICHT
        // das INSERT IGNORE: Das verhindert nur Duplikate, eine gelöschte Zeile
        // legte es bei jedem Voll-Lauf neu an. Bis zum Fix geschah genau das, und
        // Schritt 31e machte aus dem wieder eingesetzten persons.view über die
        // Schnittmenge contacts.view für die Gast-Gruppe - samt verfälschtem
        // Vorzustand in migration_336_rechte_vorher (Audit M22).
        //
        // Ab #336 heißt das Recht `contacts`.`view` und wird vom Seed in
        // database/schema.sql bzw. von Schritt 31e vergeben. Der Seed hier
        // darf dann NICHT mehr laufen: Er trüge sonst ein `persons`.`view`
        // nach, das kein Modul mehr kennt - eine Zeile, die in der
        // Rechte-Matrix nirgends auftaucht und trotzdem in der Datenbank steht.
        if (!$kontaktschemaAktiv) {
            $dataStep('293_gastrecht_personenseite', function () use ($pdo, $gastrechtSeedBelegt, $tabelleExistiert): ?array {
                if (!$tabelleExistiert('group_permissions')) {
                    return null; // Setup-Fall - nichts vermerken, später erneut.
                }
                if ($gastrechtSeedBelegt) {
                    return []; // Seed schon erhalten - nur vermerken.
                }
                $neu = (int)$pdo->exec(
                    "INSERT IGNORE INTO `group_permissions` (`group_id`, `module`, `action`)
                     SELECT `id`, 'persons', 'view' FROM `groups` WHERE `slug` = 'public'"
                );
                return $neu > 0
                    ? ['Gast-Gruppe: Leserecht persons.view für die öffentliche Personenseite vergeben (#293)']
                    : [];
            });
        }

        // 31. Kontaktliste (#336, SCHEMA_VERSION 10): persons + breeding_stations
        // werden zu einer Tabelle `contacts`. Siehe database/schema.sql für das
        // Warum; hier steht nur, wie der Bestand hinüberkommt.
        //
        // Dieser Schritt ist der erste, der DATEN kopiert statt nur DDL
        // auszuführen - und dafür reichen $addColumn/$createTable nicht.
        // Beide sind idempotent, WEIL sie vorher nachsehen, ob es die Spalte
        // bzw. Tabelle schon gibt. Für "kopiere 467 Personen in eine andere
        // Tabelle" gibt es keine solche Frage: Ein zweiter Lauf sähe eine
        // gefüllte Zieltabelle und könnte daraus nicht schließen, ob sie von
        // IHM stammt. Ohne Wächter verdoppelte der nächste Minor-Sprung den
        // gesamten Kontaktbestand.
        //
        // Deshalb braucht es das Primitiv $dataStep: Es führt einen
        // Datenschritt genau einmal aus und hält das in settings fest. Es ist
        // (samt $spalteExistiert/$tabelleExistiert) an den Anfang von
        // migrate() gezogen, weil auch die Rechte-Seeds in Schritt 13 und vor
        // Schritt 31 es brauchen (Audit M22/N18).

        // Fremdschlüssel heißen auf Bestandsinstallationen, wie MariaDB sie
        // benannt hat (horses_ibfk_3 o. ä.) - der Name steht nirgends im Repo.
        // Deshalb über information_schema suchen statt raten.
        $dropForeignKey = function (string $table, string $column) use ($pdo): void {
            try {
                $stmt = $pdo->prepare(
                    "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
                       AND REFERENCED_TABLE_NAME IS NOT NULL"
                );
                $stmt->execute([$table, $column]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                    $pdo->exec("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
                }
            } catch (\Throwable $e) {
                // Tabelle/Spalte gibt es (noch) nicht - Setup-Fall.
            }
        };

        // 31a. Zieltabellen. CREATE TABLE IF NOT EXISTS ist für sich idempotent -
        // dieselbe Definition wie in database/schema.sql, dort steht die
        // Begründung je Spalte.
        $createTable('contacts', "CREATE TABLE IF NOT EXISTS `contacts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(150) NOT NULL,
            `contact_person` VARCHAR(100) NULL DEFAULT NULL,
            `contact_info` TEXT NULL,
            `street` VARCHAR(150) NULL DEFAULT NULL,
            `house_number` VARCHAR(20) NULL DEFAULT NULL,
            `postal_code` VARCHAR(20) NULL DEFAULT NULL,
            `city` VARCHAR(100) NULL DEFAULT NULL,
            `state` VARCHAR(100) NULL DEFAULT NULL,
            `country` VARCHAR(100) NULL DEFAULT NULL,
            `address` TEXT NULL,
            `email` VARCHAR(100) NULL DEFAULT NULL,
            `phone` VARCHAR(50) NULL DEFAULT NULL,
            `mobile` VARCHAR(50) NULL DEFAULT NULL,
            `website` VARCHAR(255) NULL DEFAULT NULL,
            `is_breeder` TINYINT(1) NOT NULL DEFAULT 0,
            `contact_public` TINYINT(1) NOT NULL DEFAULT 0,
            `is_published` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME NULL DEFAULT NULL,
            INDEX `idx_contacts_deleted_name` (`deleted_at`, `name`),
            INDEX `idx_contacts_is_breeder` (`is_breeder`, `is_published`, `deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $createTable('contact_id_map', "CREATE TABLE IF NOT EXISTS `contact_id_map` (
            `old_type` ENUM('person', 'station') NOT NULL,
            `old_id` INT NOT NULL,
            `contact_id` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`old_type`, `old_id`),
            INDEX `idx_contact_id_map_contact` (`contact_id`),
            FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Selbstheilung von `contacts`.
        //
        // WOZU, wo die Tabelle doch gerade angelegt wurde: CREATE TABLE IF NOT
        // EXISTS legt eine FEHLENDE Tabelle an - es ergänzt keine fehlende
        // SPALTE an einer vorhandenen. Vor #336 hatte jede Spalte von `persons`
        // und `breeding_stations` ihren eigenen $addColumn-Schritt (22, 29, 30),
        // und genau deshalb konnte eine Installation, der eine Spalte abhanden
        // gekommen war, sich beim nächsten Lauf wieder einfangen.
        //
        // Ohne die folgenden Zeilen verlöre `contacts` diese Eigenschaft: Die
        // Altspalten-Schritte laufen nach #336 nicht mehr (die Tabellen sind
        // stillgelegt), und für die neue Tabelle gäbe es kein Gegenstück. Eine
        // fehlende Spalte bliebe dauerhaft fehlend - aufgefallen ist das, weil
        // DatabaseTest genau das prüft (er entfernt eine contacts-Spalte und
        // erwartet, dass der nächste Lauf sie zurückbringt).
        //
        // Die Reihenfolge der AFTER-Klauseln entspricht der Spaltenfolge in
        // database/schema.sql; jede nennt nur eine Spalte, die davor in dieser
        // Liste steht - die Lehre aus #309.
        $addColumn('contacts', 'contact_person', 'VARCHAR(100) NULL DEFAULT NULL AFTER `name`');
        $addColumn('contacts', 'contact_info', 'TEXT NULL AFTER `contact_person`');
        $addColumn('contacts', 'street', 'VARCHAR(150) NULL DEFAULT NULL AFTER `contact_info`');
        $addColumn('contacts', 'house_number', 'VARCHAR(20) NULL DEFAULT NULL AFTER `street`');
        $addColumn('contacts', 'postal_code', 'VARCHAR(20) NULL DEFAULT NULL AFTER `house_number`');
        $addColumn('contacts', 'city', 'VARCHAR(100) NULL DEFAULT NULL AFTER `postal_code`');
        $addColumn('contacts', 'state', 'VARCHAR(100) NULL DEFAULT NULL AFTER `city`');
        $addColumn('contacts', 'country', 'VARCHAR(100) NULL DEFAULT NULL AFTER `state`');
        $addColumn('contacts', 'address', 'TEXT NULL AFTER `country`');
        $addColumn('contacts', 'email', 'VARCHAR(100) NULL DEFAULT NULL AFTER `address`');
        $addColumn('contacts', 'phone', 'VARCHAR(50) NULL DEFAULT NULL AFTER `email`');
        $addColumn('contacts', 'mobile', 'VARCHAR(50) NULL DEFAULT NULL AFTER `phone`');
        $addColumn('contacts', 'website', 'VARCHAR(255) NULL DEFAULT NULL AFTER `mobile`');
        $addColumn('contacts', 'is_breeder', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `website`');
        $addColumn('contacts', 'contact_public', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_breeder`');
        $addColumn('contacts', 'is_published', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `contact_public`');
        $addColumn('contacts', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        $addColumn('contacts', 'deleted_at', 'DATETIME NULL DEFAULT NULL');
        $addIndex('contacts', 'idx_contacts_deleted_name', '`deleted_at`, `name`');
        $addIndex('contacts', 'idx_contacts_is_breeder', '`is_breeder`, `is_published`, `deleted_at`');

        // 31b. Bestand übernehmen - genau einmal.
        //
        // Personen behalten ihre ID (INSERT mit explizitem id). Grund: /person?id=
        // steht in Suchmaschinen, und 467 Personen gegen 28 Stationen heißt, dass
        // so die weitaus meisten Adressen unverändert weiterzeigen. Zusätzlich
        // wird horse_persons.person_id -> contact_id damit zur Identitätskopie,
        // was eine ganze Fehlerklasse erspart. Stationen bekommen neue IDs
        // oberhalb des Personenbestands; /station?id= läuft über contact_id_map
        // als dauerhafte Weiterleitung.
        $dataStep('336_contacts_uebernahme', function (callable $vermerke) use ($pdo, $tabelleExistiert, $spalteExistiert): ?array {
            // JE TABELLE EINZELN prüfen, nicht als Paar.
            //
            // Die beiden Alttabellen kamen zwar praktisch immer zusammen vor -
            // aber eben nicht zwingend: `breeding_stations` gibt es erst seit
            // Schritt 4, eine Installation von davor hat nur `persons`. Und
            // eine leere Datenbank, die allein über die Migration hochgezogen
            // wird, kann kurzzeitig genau eine der beiden haben. Der erste
            // Anlauf verknüpfte die Prüfung mit UND und kopierte dann aus
            // einer Tabelle, die es nicht gab.
            $hatPersonen = $tabelleExistiert('persons');
            $hatStationen = $tabelleExistiert('breeding_stations');
            if (!$hatPersonen && !$hatStationen) {
                return null; // Neuinstallation: schema.sql hat contacts direkt angelegt.
            }
            $kontakte = (int)$pdo->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
            if ($kontakte > 0) {
                // Zieltabelle ist nicht leer, der Marker fehlt aber - und genau
                // hier wäre blindes Kopieren die Verdopplung.
                //
                // Selbstheilung (Audit M41): Bis zum Fix stand der Marker
                // NICHT in der Übernahmetransaktion, und die Dublettenabfrage
                // danach scheiterte unter ONLY_FULL_GROUP_BY (MySQL 8). Die
                // Daten waren festgeschrieben, der Marker nicht - und jeder
                // weitere Lauf warf hier. Solche Instanzen erkennt eine
                // gefüllte contact_id_map: Sie wird AUSSCHLIESSLICH in der
                // Übernahmetransaktion unten befüllt und nur zusammen mit
                // contacts geleert (SystemReset, Rückweg). Eine nicht leere
                // Map beweist also den vollständigen Commit. Kontakte aus der
                // Hängezeit stören nicht; einzelne per CASCADE verschwundene
                // Map-Zeilen (Kontakt inzwischen hart gelöscht) behandelt 31c.
                $zugeordnet = (int)$pdo->query("SELECT COUNT(*) FROM contact_id_map")->fetchColumn();
                $alt = ($hatPersonen ? (int)$pdo->query("SELECT COUNT(*) FROM persons")->fetchColumn() : 0)
                    + ($hatStationen ? (int)$pdo->query("SELECT COUNT(*) FROM breeding_stations")->fetchColumn() : 0);
                if ($zugeordnet > 0 || $alt === 0) {
                    return ['Kontaktliste (#336): Übernahme eines abgebrochenen Vorlaufs erkannt ('
                        . $zugeordnet . ' Zuordnungen in contact_id_map) - Marker nachgetragen, nichts erneut kopiert'];
                }
                // Kein Beleg für eine frühere Übernahme: lieber nichts tun und
                // es sagen.
                throw new \RuntimeException(sprintf(
                    'Migration #336: contacts enthält bereits %d Zeile(n), contact_id_map ist leer, '
                    . 'der Übernahme-Marker fehlt, und es gibt %d Altdatensätze. '
                    . 'Bitte den Stand von Hand prüfen (settings.migration_336_contacts_uebernahme).',
                    $kontakte,
                    $alt
                ));
            }

            $meldungen = [];
            $pdo->beginTransaction();
            try {
                $anzPersonen = 0;
                if ($hatPersonen) {
                // Personen: ID-treu.
                $pdo->exec("
                    INSERT INTO contacts
                        (id, name, contact_info, street, house_number, postal_code, city, state,
                         country, email, phone, mobile, website, is_breeder,
                         contact_public, is_published, created_at, updated_at, deleted_at)
                    SELECT id, name, contact_info, street, house_number, postal_code, city, state,
                           country, email, phone, mobile, website, is_breeder,
                           contact_public, is_published, created_at, created_at, deleted_at
                    FROM persons
                ");
                $anzPersonen = (int)$pdo->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
                $pdo->exec("
                    INSERT INTO contact_id_map (old_type, old_id, contact_id)
                    SELECT 'person', id, id FROM persons
                ");
                }

                // Stationen: neue IDs. contact_public kommt als BESTANDSWERT mit
                // (dort galt Vorgabe 1) - eine Migration darf nichts wegnehmen,
                // was vorher da war. Der Vorgabewert für NEUE Kontakte bleibt
                // trotzdem der sichere 0, siehe die Spaltendefinition.
                $stationen = !$hatStationen ? [] : $pdo->query("
                    SELECT id, name, contact_person, street, house_number, postal_code, city, state,
                           country, address, phone, email, website, contact_public, is_published,
                           created_at, updated_at, deleted_at
                    FROM breeding_stations ORDER BY id ASC
                ")->fetchAll(PDO::FETCH_ASSOC);

                $einfuegen = $pdo->prepare("
                    INSERT INTO contacts
                        (name, contact_person, street, house_number, postal_code, city, state,
                         country, address, phone, email, website, contact_public, is_published,
                         created_at, updated_at, deleted_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $merken = $pdo->prepare(
                    "INSERT INTO contact_id_map (old_type, old_id, contact_id) VALUES ('station', ?, ?)"
                );
                foreach ($stationen as $s) {
                    $einfuegen->execute([
                        $s['name'], $s['contact_person'], $s['street'], $s['house_number'],
                        $s['postal_code'], $s['city'], $s['state'], $s['country'], $s['address'],
                        $s['phone'], $s['email'], $s['website'], $s['contact_public'],
                        $s['is_published'], $s['created_at'], $s['updated_at'], $s['deleted_at'],
                    ]);
                    $merken->execute([(int)$s['id'], (int)$pdo->lastInsertId()]);
                }

                // Marker in DERSELBEN Transaktion (Audit M41): Kontakte,
                // contact_id_map und migration_336_contacts_uebernahme landen
                // gemeinsam - oder gar nicht.
                $vermerke();
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $meldungen[] = sprintf(
                'Kontaktliste (#336): %d Person(en) ID-treu und %d Deckstation(en) mit neuen IDs nach contacts übernommen',
                $anzPersonen,
                count($stationen)
            );

            // Mitgliedsstatus (Audit N78). Die Kopie oben nimmt
            // persons.membership_status bewusst NICHT mit - `contacts` führt
            // das Feld nicht mehr (#349/#395), die Angabe gehört dem Addon
            // `mitgliedsstatus`. Beim Sprung aus v0.7 bleiben die Werte in
            // persons_pre_contacts stehen (31f benennt nur um), und das Addon
            // ab 1.1.0 übernimmt sie von dort. Hier nur die Meldung, damit der
            // Betreiber es an der Konsole erfährt - keine Datenänderung. Zählt
            // nach derselben Leerraum-Regel wie Addon und Dashboard-Hinweis
            // (App\Service\MitgliedsstatusAltbestand). Nach dem Commit: darf
            // den Schritt nicht mehr scheitern lassen.
            if ($hatPersonen) {
                try {
                    if ($spalteExistiert('persons', 'membership_status')) {
                        $mitStatus = (int)$pdo->query(
                            "SELECT COUNT(*) FROM persons
                             WHERE membership_status IS NOT NULL
                               AND " . MitgliedsstatusAltbestand::normiert('membership_status') . " <> ''"
                        )->fetchColumn();
                        if ($mitStatus > 0) {
                            $meldungen[] = sprintf(
                                'Kontaktliste (#336): %d Person(en) führen einen Mitgliedsstatus - er bleibt in '
                                . 'persons_pre_contacts; Addon mitgliedsstatus ≥ 1.1.0 übernimmt ihn',
                                $mitStatus
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    // Nur eine Meldung - der Wert selbst bleibt ohnehin stehen.
                }
            }

            // Namensgleichheiten NICHT automatisch zusammenführen.
            //
            // #336 skizziert das ("bei den 3 Namensgleichheiten wird
            // zusammengeführt statt doppelt angelegt"), und genau das wird hier
            // bewusst nicht getan: Eine Zusammenführung ist nicht umkehrbar, sie
            // verschiebt Pferdezuordnungen, und sie setzt die Sichtbarkeit des
            // Ergebnisses auf den strengeren der beiden Werte - eine bisher
            // öffentliche Stationsanschrift verschwände also stillschweigend.
            // Gleicher Name heißt außerdem nicht gleicher Betrieb; im Bestand
            // stehen Platzhalter wie 'Nichtmitglied NO', die sich nur im
            // Länderkürzel unterscheiden.
            //
            // Stattdessen: melden. Der Deduplizierer (#355) kann Kontakte seit
            // demselben Release vorschlagen und zusammenführen - dort trifft ein
            // Mensch die Entscheidung, mit Vorschau und je Fall.
            //
            // Alles ab hier läuft NACH dem Commit und darf den Schritt nicht
            // mehr scheitern lassen - die Übernahme ist festgeschrieben.
            //
            // Die Abfrage ist standardkonform (nur Aggregate außerhalb des
            // GROUP BY): MySQL ab 5.7.5 hat ONLY_FULL_GROUP_BY standardmäßig
            // aktiv, und der Kern setzt keinen sql_mode. Die frühere Fassung
            // (`SELECT c.name … GROUP BY LOWER(TRIM(c.name))`) scheiterte dort
            // und ließ die Migration dauerhaft hängen (Audit M41).
            try {
                $doppelt = $pdo->query("
                    SELECT MIN(c.name) AS name, COUNT(*) AS anzahl
                    FROM contacts c
                    WHERE c.deleted_at IS NULL
                    GROUP BY LOWER(TRIM(c.name))
                    HAVING COUNT(*) > 1
                    ORDER BY MIN(c.name) ASC
                ")->fetchAll(PDO::FETCH_ASSOC);
                if ($doppelt) {
                    $namen = implode(', ', array_map(static fn($z) => $z['name'], array_slice($doppelt, 0, 10)));
                    $meldungen[] = sprintf(
                        'Kontaktliste (#336): %d namensgleiche Kontaktpaare NICHT automatisch zusammengeführt (%s%s) - '
                        . 'zu entscheiden im Deduplizierer unter /admin/contacts/merge',
                        count($doppelt),
                        $namen,
                        count($doppelt) > 10 ? ', …' : ''
                    );
                    try {
                        \App\Service\AuditLogger::log(
                            'Kontaktliste zusammengeführt (#336)',
                            'contacts',
                            sprintf('%d namensgleiche Paare offen gelassen: %s', count($doppelt), $namen)
                        );
                    } catch (\Throwable $e) {
                        // Protokoll darf die Migration nicht aufhalten.
                    }
                }
            } catch (\Throwable $e) {
                $meldungen[] = 'Kontaktliste (#336): Dublettenprüfung übersprungen (' . $e->getMessage()
                    . ') - namensgleiche Kontakte bitte im Deduplizierer (/admin/contacts/merge) prüfen';
            }

            return $meldungen;
        });

        // 31c. Verweise umhängen. Zwei Steckplätze, nicht einer - siehe den
        // Tabellenkommentar zu horse_persons in database/schema.sql.
        $addColumn('horse_persons', 'contact_id', 'INT NULL DEFAULT NULL AFTER `horse_id`');
        $addColumn('horse_persons', 'station_contact_id', 'INT NULL DEFAULT NULL AFTER `role`');

        $dataStep('336_horse_persons_umhaengen', function (callable $vermerke) use ($pdo, $tabelleExistiert): ?array {
            if (!$tabelleExistiert('horse_persons') || !$tabelleExistiert('contact_id_map')) {
                return null;
            }
            $spalten = $pdo->query("SHOW COLUMNS FROM `horse_persons`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('person_id', $spalten, true)) {
                return null; // Neuinstallation - die Altspalten gab es nie.
            }

            // Nicht zuordenbar ist ein Altverweis OHNE Eintrag in
            // contact_id_map - nicht einer, dessen Altdatensatz fehlt (Audit
            // M42). Zwei Ursachen, beide im Bestand real:
            //  (i)  Der Altdatensatz ist weg: In v0.7 hatte
            //       horse_persons.breeding_station_id keinen Fremdschlüssel,
            //       der Papierkorb löschte Stationen endgültig, ohne die
            //       Zuordnungen anzufassen.
            //  (ii) Der Altdatensatz ist da, der Kontakt aber inzwischen hart
            //       gelöscht: contact_id_map hängt per ON DELETE CASCADE an
            //       contacts. Papierkorb, die 30-Tage-Bereinigung und die
            //       DSGVO-Löschung konnten das zwischen einem abgebrochenen
            //       und dem abschließenden Lauf auslösen.
            // Früher warf der Schritt in beiden Fällen - bei jedem Lauf, für
            // immer. Jetzt werden die Verweise geleert (dieselbe Wirkung wie
            // der neue Fremdschlüssel station_contact_id ON DELETE SET NULL)
            // und mit Pferd und alter ID gemeldet.
            //
            // breeding_station_text bleibt bewusst unangetastet: Der Name
            // einer endgültig gelöschten Station existiert nicht mehr, ein
            // Platzhalter im öffentlichen Freitextfeld wäre erfunden.
            $ohneMap = [
                'station' => "hp.breeding_station_id IS NOT NULL AND hp.station_contact_id IS NULL
                              AND NOT EXISTS (SELECT 1 FROM contact_id_map m
                                              WHERE m.old_type = 'station' AND m.old_id = hp.breeding_station_id)",
                'person'  => "hp.person_id IS NOT NULL AND hp.contact_id IS NULL
                              AND NOT EXISTS (SELECT 1 FROM contact_id_map m
                                              WHERE m.old_type = 'person' AND m.old_id = hp.person_id)",
            ];
            $quelle = [
                'station' => ['breeding_station_id', 'breeding_stations'],
                'person'  => ['person_id', 'persons'],
            ];

            $geleert = ['station' => 0, 'person' => 0];
            $altWeg = 0;
            $kontaktWeg = 0;
            $beispiele = [];

            $pdo->beginTransaction();
            try {
                $pdo->exec("
                    UPDATE horse_persons hp
                    JOIN contact_id_map m ON m.old_type = 'person' AND m.old_id = hp.person_id
                    SET hp.contact_id = m.contact_id
                    WHERE hp.person_id IS NOT NULL
                ");
                $personen = $pdo->query("SELECT COUNT(*) FROM horse_persons WHERE contact_id IS NOT NULL")->fetchColumn();

                $pdo->exec("
                    UPDATE horse_persons hp
                    JOIN contact_id_map m ON m.old_type = 'station' AND m.old_id = hp.breeding_station_id
                    SET hp.station_contact_id = m.contact_id
                    WHERE hp.breeding_station_id IS NOT NULL
                ");
                $stationen = $pdo->query("SELECT COUNT(*) FROM horse_persons WHERE station_contact_id IS NOT NULL")->fetchColumn();

                foreach ($ohneMap as $art => $bedingung) {
                    [$spalte, $alttabelle] = $quelle[$art];
                    $anzahl = (int)$pdo->query("SELECT COUNT(*) FROM horse_persons hp WHERE {$bedingung}")->fetchColumn();
                    if ($anzahl === 0) {
                        continue;
                    }
                    $nochDa = $tabelleExistiert($alttabelle)
                        ? (int)$pdo->query(
                            "SELECT COUNT(*) FROM horse_persons hp WHERE {$bedingung}
                               AND EXISTS (SELECT 1 FROM `{$alttabelle}` a WHERE a.id = hp.{$spalte})"
                        )->fetchColumn()
                        : 0;
                    $kontaktWeg += $nochDa;
                    $altWeg += $anzahl - $nochDa;
                    $geleert[$art] = $anzahl;

                    foreach ($pdo->query(
                        "SELECT hp.id, hp.horse_id, hp.{$spalte} AS alt FROM horse_persons hp
                         WHERE {$bedingung} ORDER BY hp.id ASC LIMIT 20"
                    )->fetchAll(PDO::FETCH_ASSOC) as $z) {
                        $beispiele[] = sprintf(
                            'Zuordnung #%d (Pferd #%d, alte %s #%d)',
                            (int)$z['id'],
                            (int)$z['horse_id'],
                            $art === 'station' ? 'Station' : 'Person',
                            (int)$z['alt']
                        );
                    }
                    $pdo->exec("UPDATE horse_persons hp SET hp.{$spalte} = NULL WHERE {$bedingung}");
                }

                // Nachweis vor dem Löschen der Altspalten, jetzt als
                // Plausibilitätsprüfung: Ein Altverweis MIT Map-Eintrag muss
                // seinen neuen Steckplatz haben. Sonst bricht der Schritt ab,
                // der Marker bleibt ungesetzt - statt Daten zu verlieren.
                $ungeklaert = (int)$pdo->query("
                    SELECT COUNT(*) FROM horse_persons
                    WHERE (person_id IS NOT NULL AND contact_id IS NULL)
                       OR (breeding_station_id IS NOT NULL AND station_contact_id IS NULL)
                ")->fetchColumn();
                if ($ungeklaert > 0) {
                    throw new \RuntimeException(sprintf(
                        'Migration #336: %d horse_persons-Zeile(n) ohne Gegenstück in contact_id_map - '
                        . 'Altspalten werden NICHT entfernt.',
                        $ungeklaert
                    ));
                }

                $vermerke();
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $meldungen = [sprintf(
                'Kontaktliste (#336): %d Personen- und %d Stationsverweise in horse_persons umgehängt',
                $personen,
                $stationen
            )];
            if ($geleert['station'] + $geleert['person'] > 0) {
                $text = sprintf(
                    '%d Deckstations- und %d Personenverweise ohne Gegenstück geleert '
                    . '(%d Altdatensatz gelöscht, %d Kontakt seit dem Update gelöscht): %s%s',
                    $geleert['station'],
                    $geleert['person'],
                    $altWeg,
                    $kontaktWeg,
                    implode(', ', array_slice($beispiele, 0, 20)),
                    count($beispiele) > 20 || $geleert['station'] + $geleert['person'] > count($beispiele) ? ', …' : ''
                );
                $meldungen[] = 'Kontaktliste (#336): ' . $text;
                try {
                    \App\Service\AuditLogger::log('Verwaiste Verweise geleert (#336)', 'contacts', $text);
                } catch (\Throwable $e) {
                    // Protokoll darf die Migration nicht aufhalten.
                }
            }

            return $meldungen;
        });

        // 31d. horses.breeding_station_id zeigt jetzt auf contacts. Der
        // Spaltenname bleibt - die Aussage hat sich nicht geändert, nur die
        // Zieltabelle, und ein Umbenennen träfe jedes Addon, das den Spiegel
        // liest, ohne irgendetwas zu verbessern.
        $dataStep('336_horses_station_umhaengen', function (callable $vermerke) use ($pdo, $tabelleExistiert, $spalteExistiert, $dropForeignKey): ?array {
            if (!$tabelleExistiert('horses') || !$tabelleExistiert('contact_id_map')) {
                return null;
            }
            if (!$tabelleExistiert('breeding_stations')) {
                return null; // Neuinstallation.
            }
            // Erst den alten Fremdschlüssel lösen, sonst scheitert das UPDATE an
            // ihm (die neuen IDs gibt es in breeding_stations nicht). VOR der
            // Transaktion: DDL committet implizit.
            $dropForeignKey('horses', 'breeding_station_id');

            // Spiegel, die der NEUE Code geschrieben hat, nicht umschlüsseln
            // (Audit M42). Hing die Migration zwischen 31b und hier, hat
            // HorseController schon Kontakt-IDs in breeding_station_id
            // geschrieben - samt einer horse_persons-Zeile, die NUR den neuen
            // Steckplatz trägt. Solche Werte als alte Stations-ID zu lesen,
            // hieße eine fremde Deckstation einzutragen.
            $neuCode = $spalteExistiert('horse_persons', 'breeding_station_id')
                ? "NOT EXISTS (SELECT 1 FROM horse_persons hp
                               WHERE hp.horse_id = h.id AND hp.breeding_station_id IS NULL
                                 AND hp.station_contact_id = h.breeding_station_id)"
                : '1 = 1';

            // Die Heuristik erfasst nicht jeden Spiegel aus der Hängezeit:
            // HorseController::store/update schreibt breeding_station_id auch
            // direkt aus dem Formular, ohne horse_persons-Zeile. Deshalb
            // zusätzlich MELDEN, welche Pferde nach der Kontaktübernahme
            // bearbeitet wurden. Nicht automatisch auslassen: updated_at
            // ändert sich auch durch Bearbeitungen, die die Deckstation nicht
            // berühren. Beide Spalten sind TIMESTAMP derselben Datenbankuhr,
            // das ist zeitzonenfest. VOR dem Umschlüsseln gelesen, weil das
            // UPDATE unten updated_at selbst fortschreibt.
            $verdacht = [];
            try {
                $verdacht = $pdo->query(
                    "SELECT h.id FROM horses h
                     WHERE h.breeding_station_id IS NOT NULL
                       AND h.updated_at > (SELECT MIN(m.created_at) FROM contact_id_map m)
                     ORDER BY h.id ASC LIMIT 20"
                )->fetchAll(PDO::FETCH_COLUMN);
            } catch (\Throwable $e) {
                // Nur eine Meldung - sie darf den Schritt nicht aufhalten.
            }

            $pdo->beginTransaction();
            try {
                // (a) ZUERST Alt-IDs ohne Map-Eintrag nullen, DANN
                // umschlüsseln. Andersherum (die frühere Fassung prüfte erst
                // hinterher gegen contacts) blieb eine nicht zuordenbare
                // Stations-ID, die zufällig einer Personen-ID entspricht, als
                // falsche Deckstation stehen - Personen behalten ihre IDs.
                $ohneGegenstueck = (int)$pdo->exec("
                    UPDATE horses h
                    SET h.breeding_station_id = NULL
                    WHERE h.breeding_station_id IS NOT NULL
                      AND NOT EXISTS (SELECT 1 FROM contact_id_map m
                                      WHERE m.old_type = 'station' AND m.old_id = h.breeding_station_id)
                      AND {$neuCode}
                ");
                // (b) Umschlüsseln. Nicht idempotent - deshalb Marker in
                // derselben Transaktion und die Migrationssperre in run()
                // (Audit N75: zwei parallele Läufe schlüsselten doppelt um).
                $pdo->exec("
                    UPDATE horses h
                    JOIN contact_id_map m ON m.old_type = 'station' AND m.old_id = h.breeding_station_id
                    SET h.breeding_station_id = m.contact_id
                    WHERE h.breeding_station_id IS NOT NULL
                      AND {$neuCode}
                ");
                $anzahl = (int)$pdo->query(
                    "SELECT COUNT(*) FROM horses WHERE breeding_station_id IS NOT NULL"
                )->fetchColumn();

                // (c) Nachlauf gegen contacts: Verweise auf Kontakte, die in
                // der Hängezeit hart gelöscht wurden. Nicht abbrechen - ein
                // verwaister Spiegel darf den Fremdschlüssel nicht sprengen.
                $verwaist = (int)$pdo->exec("
                    UPDATE horses h
                    LEFT JOIN contacts c ON c.id = h.breeding_station_id
                    SET h.breeding_station_id = NULL
                    WHERE h.breeding_station_id IS NOT NULL AND c.id IS NULL
                ");
                $verwaist += $ohneGegenstueck;

                $vermerke();
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $meldungen = [sprintf(
                'Kontaktliste (#336): %d horses.breeding_station_id auf contacts umgehängt%s',
                $anzahl,
                $verwaist > 0 ? sprintf(' (%d verwaiste Verweise geleert)', $verwaist) : ''
            )];
            if ($verdacht !== []) {
                $text = sprintf(
                    'Deckstation bitte prüfen (in der Zeit zwischen abgebrochener und abgeschlossener Migration '
                    . 'bearbeitet): Pferd #%s',
                    implode(', #', array_map('intval', $verdacht))
                );
                $meldungen[] = 'Kontaktliste (#336): ' . $text;
                try {
                    \App\Service\AuditLogger::log('Deckstation prüfen (#336)', 'horses', $text);
                } catch (\Throwable $e) {
                    // Protokoll darf die Migration nicht aufhalten.
                }
            }

            return $meldungen;
        });

        // 31e. Rechte NUR als Schnittmenge.
        //
        // persons.* und breeding_stations.* wurden getrennt vergeben. Eine
        // Migration "persons.view ODER breeding_stations.view -> contacts.view"
        // gäbe Gruppen Zugriff auf personenbezogene Daten, den sie nie hatten -
        // die Gast-Gruppe etwa hatte breeding_stations.view seit jeher und
        // persons.view erst seit #293. Also UND, nicht ODER.
        $dataStep('336_rechte_schnittmenge', function () use ($pdo, $tabelleExistiert): ?array {
            if (!$tabelleExistiert('group_permissions')) {
                return null;
            }
            $vorher = (int)$pdo->query(
                "SELECT COUNT(*) FROM group_permissions WHERE module IN ('persons','breeding_stations')"
            )->fetchColumn();
            if ($vorher === 0) {
                return null; // Neuinstallation - der Seed vergibt contacts.* direkt.
            }

            $neu = $pdo->exec("
                INSERT IGNORE INTO group_permissions (group_id, module, action)
                SELECT p.group_id, 'contacts', p.action
                FROM group_permissions p
                JOIN group_permissions b
                  ON b.group_id = p.group_id
                 AND b.module = 'breeding_stations'
                 AND b.action = p.action
                WHERE p.module = 'persons'
            ");
            // Vor dem Löschen archivieren. Ohne das wäre der Rechte-Teil dieser
            // Migration der einzige unumkehrbare Schritt: Die Kontakte liegen
            // in persons_pre_contacts/breeding_stations_pre_contacts, die
            // Zuordnungen in contact_id_map - die Rechtezeilen aber nirgends.
            // Ein Rückweg, der die Rechte nicht mitnimmt, setzt die Instanz auf
            // "niemand darf mehr etwas", und das fällt erst auf, wenn jemand
            // arbeiten will. Siehe database/rollback-336.php.
            $altbestand = $pdo->query(
                "SELECT group_id, module, action FROM group_permissions
                 WHERE module IN ('persons','breeding_stations') ORDER BY group_id, module, action"
            )->fetchAll(PDO::FETCH_ASSOC);
            $pdo->prepare(
                "INSERT INTO settings (setting_key, setting_value) VALUES ('migration_336_rechte_vorher', ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            )->execute([json_encode($altbestand, JSON_UNESCAPED_UNICODE)]);

            $pdo->exec("DELETE FROM group_permissions WHERE module IN ('persons','breeding_stations')");

            return [sprintf(
                'Kontaktliste (#336): %d Recht(e) aus persons.*/breeding_stations.* als Schnittmenge nach contacts.* '
                . 'übernommen (%d alte Zeilen entfernt; wer nur EINEN der beiden Bereiche sehen durfte, '
                . 'sieht contacts jetzt NICHT - das ist Absicht)',
                $neu,
                $vorher
            )];
        });

        // 31f. Altspalten und Alttabellen. Erst jetzt, nachdem alle Schritte
        // oben ihren Nachweis erbracht haben.
        //
        // Die Tabellen werden UMBENANNT, nicht gelöscht: Der Umbau fasst jeden
        // Kontakt und jede Zuordnung an, und ein Rückweg muss existieren. Unter
        // dem alten Namen kann kein Code sie mehr versehentlich lesen, die Daten
        // sind aber noch da.
        //
        // SIE BLEIBEN BIS AUF WEITERES. Ursprünglich lautete die Zusage auf
        // 0.9.0, dann auf v0.10: Mit den Tabellen stirbt
        // `database/rollback-336.php`, der einzige Rückweg aus der
        // Kontakt-Zusammenlegung, und die 0.9.0 ist die erste suffixfreie
        // Fassung, die diese Umstellung an Bestände ausliefert.
        //
        // VERBINDLICH FÜR JEDE KÜNFTIGE BEREINIGUNG (Audit N78, abgestimmt mit
        // M23/fw-dsgvo): `persons_pre_contacts` ist beim direkten Sprung aus
        // v0.7 die EINZIGE Quelle des Mitgliedsstatus - `contacts` hatte die
        // Spalte nie, das Addon `mitgliedsstatus` ab 1.1.0 übernimmt von dort.
        //  - Ein Entfernen der Alttabellen darf NICHT laufen, solange
        //    App\Service\MitgliedsstatusAltbestand::offen() > 0 ist. Das
        //    braucht einen eigenen dataStep (mit SCHEMA_VERSION-Bump), der
        //    sich bis dahin offen meldet.
        //  - Eine Pseudonymisierung (M23) muss `persons_pre_contacts.id` und
        //    `membership_status` bis dahin stehen lassen.
        //  - DSGVO-Löschung und -Anonymisierung je Kontakt müssen dagegen
        //    `membership_status` dieser ID mit NULLen (siehe den Kommentar in
        //    GdprController::anonymizePerson()).
        // Wer das ändert, ändert auch die Meldung an den Betreiber weiter unten.
        $dataStep('336_altbestand_stilllegen', function (callable $vermerke, callable $offen) use ($pdo, $tabelleExistiert, $spalteExistiert, $dropForeignKey): ?array {
            if (!$tabelleExistiert('persons') && !$tabelleExistiert('breeding_stations')) {
                return null;
            }
            // Ohne erfolgreiche Übernahme wird nichts stillgelegt.
            $marker = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $marker->execute(['migration_336_horse_persons_umhaengen']);
            $umgehaengt = $marker->fetchColumn() !== false;
            $marker->execute(['migration_336_contacts_uebernahme']);
            $uebernommen = $marker->fetchColumn() !== false;
            if (!$uebernommen) {
                return $offen('Kontaktliste (#336): Altbestand nicht stillgelegt - die Übernahme nach contacts ist noch nicht abgeschlossen');
            }

            $meldungen = [];
            $fehler = [];
            if ($umgehaengt) {
                $dropForeignKey('horse_persons', 'person_id');
                $dropForeignKey('horse_persons', 'breeding_station_id');
                foreach (['person_id', 'breeding_station_id'] as $spalte) {
                    try {
                        $stmt = $pdo->query("SHOW COLUMNS FROM `horse_persons` LIKE '{$spalte}'");
                        if ($stmt && $stmt->rowCount() > 0) {
                            $pdo->exec("ALTER TABLE `horse_persons` DROP COLUMN `{$spalte}`");
                            $meldungen[] = "Spalte horse_persons.{$spalte} entfernt (ersetzt durch contact_id/station_contact_id)";
                        }
                    } catch (\Throwable $e) {
                        $fehler[] = $e->getMessage();
                    }
                }
            }

            foreach ([['persons', 'persons_pre_contacts'], ['breeding_stations', 'breeding_stations_pre_contacts']] as [$alt, $neu]) {
                if ($tabelleExistiert($alt) && !$tabelleExistiert($neu)) {
                    $dropForeignKey($alt, 'id');
                    $pdo->exec("RENAME TABLE `{$alt}` TO `{$neu}`");
                    $meldungen[] = "Tabelle {$alt} nach {$neu} umbenannt (Rückweg für #336; bleibt bis auf Weiteres erhalten)";
                }
            }

            // Das Entfernen der Altspalten schluckt Fehler oben bewusst (ein
            // fremder Fremdschlüssel o. ä. soll nicht die ganze Migration
            // aufhalten). Setzte der Schritt dann trotzdem seinen Marker,
            // bliebe 336_fremdschluessel für immer offen - 31f liefe nie
            // wieder (Audit N76). Deshalb: offen melden, nicht vermerken.
            $uebrig = [];
            if ($umgehaengt) {
                foreach (['person_id', 'breeding_station_id'] as $spalte) {
                    if ($spalteExistiert('horse_persons', $spalte)) {
                        $uebrig[] = "horse_persons.{$spalte}";
                    }
                }
            }
            foreach (['persons', 'breeding_stations'] as $alt) {
                if ($tabelleExistiert($alt)) {
                    $uebrig[] = "Tabelle {$alt}";
                }
            }
            if ($uebrig !== []) {
                return $offen(sprintf(
                    'Kontaktliste (#336): Altspalte/Alttabelle %s konnte nicht entfernt werden%s%s',
                    implode(', ', $uebrig),
                    $fehler !== [] ? ': ' . implode('; ', $fehler) : '',
                    $meldungen !== [] ? ' (bereits erledigt: ' . implode('; ', $meldungen) . ')' : ''
                ));
            }

            return $meldungen;
        });

        // 31g. Fremdschlüssel auf die neue Zieltabelle. Nach dem Umhängen und
        // erst, wenn keine verwaisten Verweise mehr übrig sind - sonst
        // scheitert das ALTER und der ganze Lauf bliebe stehen.
        $dataStep('336_fremdschluessel', function (callable $vermerke, callable $offen) use ($pdo, $tabelleExistiert): ?array {
            if (!$tabelleExistiert('contacts') || !$tabelleExistiert('horse_persons')) {
                return null;
            }
            $spalten = $pdo->query("SHOW COLUMNS FROM `horse_persons`")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('person_id', $spalten, true)) {
                // Stilllegung lief noch nicht - offen, nicht "nicht zuständig":
                // run() soll dann nicht stempeln.
                return $offen('Kontaktliste (#336): Fremdschlüssel auf contacts noch nicht gesetzt - die Altspalten in horse_persons stehen noch');
            }

            $meldungen = [];
            // Verwaiste Verweise vorher leeren, sonst wirft das ALTER.
            $pdo->exec("UPDATE horse_persons hp LEFT JOIN contacts c ON c.id = hp.contact_id
                        SET hp.contact_id = NULL WHERE hp.contact_id IS NOT NULL AND c.id IS NULL");
            $pdo->exec("UPDATE horse_persons hp LEFT JOIN contacts c ON c.id = hp.station_contact_id
                        SET hp.station_contact_id = NULL WHERE hp.station_contact_id IS NOT NULL AND c.id IS NULL");

            foreach ([
                ['horse_persons', 'contact_id', 'CASCADE'],
                ['horse_persons', 'station_contact_id', 'SET NULL'],
                ['horses', 'breeding_station_id', 'SET NULL'],
            ] as [$tabelle, $spalte, $verhalten]) {
                try {
                    $vorhanden = $pdo->prepare(
                        "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
                           AND REFERENCED_TABLE_NAME = 'contacts'"
                    );
                    $vorhanden->execute([$tabelle, $spalte]);
                    if ((int)$vorhanden->fetchColumn() > 0) {
                        continue;
                    }
                    $pdo->exec("ALTER TABLE `{$tabelle}` ADD FOREIGN KEY (`{$spalte}`) REFERENCES `contacts`(`id`) ON DELETE {$verhalten}");
                    $meldungen[] = "Fremdschlüssel {$tabelle}.{$spalte} -> contacts.id ergänzt";
                } catch (\Throwable $e) {}
            }

            $addIndexLokal = function (string $tabelle, string $name, string $spalten) use ($pdo, &$meldungen): void {
                try {
                    $stmt = $pdo->query("SHOW INDEX FROM `{$tabelle}` WHERE Key_name = " . $pdo->quote($name));
                    if ($stmt && $stmt->rowCount() === 0) {
                        $pdo->exec("ALTER TABLE `{$tabelle}` ADD INDEX `{$name}` ({$spalten})");
                        $meldungen[] = "Index {$name} ergänzt";
                    }
                } catch (\Throwable $e) {}
            };
            $addIndexLokal('horse_persons', 'idx_horse_persons_contact', '`contact_id`');
            $addIndexLokal('horse_persons', 'idx_horse_persons_station_contact', '`station_contact_id`');

            return $meldungen;
        });

        // 31h. Pferdefotos aus dem Webroot holen (#366, SCHEMA_VERSION 12).
        //
        // Bis v0.8.0 lagen sie unter public/uploads/horses/ und wurden vom
        // Webserver direkt ausgeliefert - an der Sichtbarkeitsprüfung des
        // MediaControllers vorbei. Ein depubliziertes Pferd blieb unter seinem
        // unveränderten Dateinamen abrufbar. Neuer Ort: storage/horses/.
        //
        // Kopieren, Inhalt vergleichen, erst dann die Quelle löschen. Ein
        // move/rename wäre kürzer, aber wenn es auf halbem Weg scheitert
        // (volle Platte, Rechte), ist das Foto weg - und Fotos gibt es nur
        // einmal. Bleibt etwas liegen, meldet sich der Schritt als offen:
        // kein Marker, kein Versionsstempel (Audit N76). Der Rückfall in
        // MediaController liefert die Datei weiter, das harte
        // public/uploads/horses/.htaccess hält den statischen Weg zu, und der
        // nächste Migrationslauf (implizit nach 15 Minuten) nimmt sich den
        // Rest vor.
        $dataStep('366_pferdefotos_aus_dem_webroot', function (callable $vermerke, callable $offen): ?array {
            $quelle = \App\Helper\HorseImagePath::legacyDir();
            $ziel   = \App\Helper\HorseImagePath::dir();

            // Leere Rückgabe statt einer Meldung: Der Schritt gilt als
            // erledigt (Marker wird gesetzt), sagt aber nichts. Auf einem
            // frisch importierten schema.sql darf run() ausschliesslich den
            // Versionsstempel melden - alles andere wäre Schema-Drift, und
            // genau darauf besteht SchemaMigratorTest.
            if (!is_dir($quelle)) {
                return [];
            }

            $eintraege = @scandir($quelle);
            if ($eintraege === false) {
                return $offen('Pferdefotos (#366): public/uploads/horses ist nicht lesbar - Verschiebung übersprungen - nächster Versuch automatisch in 15 Minuten oder sofort per php database/migrate.php');
            }

            $bilder = [];
            foreach ($eintraege as $eintrag) {
                if ($eintrag === '.' || $eintrag === '..') {
                    continue;
                }
                $pfad = $quelle . '/' . $eintrag;
                // Nur echte Bilddateien. .htaccess bleibt liegen - sie ist es,
                // die den statischen Weg sperrt.
                if (!is_file($pfad) || is_link($pfad)) {
                    continue;
                }
                $endung = strtolower(pathinfo($eintrag, PATHINFO_EXTENSION));
                if (!in_array($endung, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                    continue;
                }
                $bilder[] = $eintrag;
            }

            if ($bilder === []) {
                return [];
            }

            if (!is_dir($ziel) && !@mkdir($ziel, 0755, true) && !is_dir($ziel)) {
                return $offen('Pferdefotos (#366): storage/horses lässt sich nicht anlegen - Verschiebung übersprungen - nächster Versuch automatisch in 15 Minuten oder sofort per php database/migrate.php');
            }

            $verschoben = 0;
            $liegengeblieben = 0;
            foreach ($bilder as $datei) {
                $von = $quelle . '/' . $datei;
                $nach = $ziel . '/' . $datei;

                if (is_file($nach) && @filesize($nach) === @filesize($von) && @md5_file($nach) === @md5_file($von)) {
                    // Schon übertragen (abgebrochener Vorlauf) - nur die Quelle räumen.
                    if (@unlink($von)) {
                        $verschoben++;
                    } else {
                        $liegengeblieben++;
                    }
                    continue;
                }

                if (!@copy($von, $nach) || @md5_file($nach) !== @md5_file($von)) {
                    @unlink($nach);
                    $liegengeblieben++;
                    continue;
                }
                if (@unlink($von)) {
                    $verschoben++;
                } else {
                    // Kopie ist heil, Quelle nicht löschbar. Nicht als Erfolg
                    // zählen - die Datei liegt weiter im Webroot.
                    $liegengeblieben++;
                }
            }

            if ($liegengeblieben > 0) {
                return $offen(sprintf(
                    'Pferdefotos (#366): %d von %d Datei(en) nach storage/horses verschoben, %d liegen noch in '
                    . 'public/uploads/horses (Rechte prüfen). Sie werden weiter ausgeliefert und sind statisch '
                    . 'gesperrt - nächster Versuch automatisch in 15 Minuten oder sofort per php database/migrate.php',
                    $verschoben,
                    count($bilder),
                    $liegengeblieben
                ));
            }

            return [sprintf(
                'Pferdefotos (#366): %d Datei(en) aus dem Webroot nach storage/horses verschoben',
                $verschoben
            )];
        });

        // 34b. Fristanker fuer die 180-Tage-Regel setzen (#358).
        //
        // Bestandskonten, die heute schon ohne zweiten Faktor und ohne
        // E-Mail dastehen, bekommen created_at als Anker - das ist der
        // frueheste belegbare Zeitpunkt, seit dem der Zustand besteht.
        // Zusaetzlich wird der Karenzbeginn gesetzt: Der erste Lauf nach dem
        // Update darf nicht den kompletten Altbestand am selben Tag
        // abraeumen, ohne dass jemand die Vorwarnung gesehen hat.
        $dataStep('a358_fristanker_backfill', function (callable $vermerke, callable $offen) use ($pdo, $tabelleExistiert): ?array {
            if (!$tabelleExistiert('users')) {
                return null;
            }
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'unprotected_since'");
                if (!$stmt || $stmt->rowCount() === 0) {
                    return null;
                }
                $betroffen = (int)$pdo->exec(
                    "UPDATE `users`
                     SET `unprotected_since` = `created_at`
                     WHERE `deleted_at` IS NULL
                       AND `unprotected_since` IS NULL
                       -- Bewusst nur totp_enabled, ohne email_2fa_enabled aus
                       -- #354: Dieser Schritt laeuft einmalig beim Sprung auf
                       -- die Fassung, die die Spalte ueberhaupt erst anlegt -
                       -- sie steht dabei zwangslaeufig auf 0. Die laufende
                       -- Regel fragt dagegen SecondFactors (siehe
                       -- DormantAccountService::unprotectedPredicate()).
                       AND (`totp_enabled` = 0 OR `totp_enabled` IS NULL)
                       AND (`email` IS NULL OR `email` = '')"
                );
                $pdo->prepare(
                    "INSERT INTO settings (setting_key, setting_value) VALUES ('dormant_rule_active_since', ?)
                     ON DUPLICATE KEY UPDATE setting_value = setting_value"
                )->execute([gmdate('Y-m-d H:i:s')]);
            } catch (\Throwable $e) {
                return $offen('Ruhende Konten (#358): Fristanker nicht gesetzt (' . $e->getMessage() . ')');
            }

            if ($betroffen === 0) {
                return [];
            }

            return [sprintf(
                'Ruhende Konten (#358): Fristanker fuer %d Konto/Konten ohne zweiten Faktor und ohne '
                . 'E-Mail gesetzt; die 180-Tage-Frist laeuft ab deren Anlagedatum',
                $betroffen
            )];
        });

        // 37b. Galerie in den Kern uebernehmen (#339).
        //
        // WAS UEBERNOMMEN WIRD: die Zeilen aus `plugin_galerie_media` und die
        // zugehoerigen Dateien. Das Addon legte sie unter
        // storage/plugin_galerie ab (aeltere Staende unter
        // public/uploads/plugin_galerie); der Kern fuehrt alle Pferdefotos in
        // storage/horses.
        //
        // WARUM ERST DIE DATEI, DANN DIE ZEILE. Bricht der Lauf dazwischen ab,
        // findet ihn der naechste im selben Zustand wieder: Eine Datei, die
        // schon am Ziel liegt, wird nicht ueberschrieben, und eine Zeile ohne
        // Datei entsteht gar nicht erst. Umgekehrt haette ein Abbruch
        // Medieneintraege hinterlassen, die auf nichts zeigen.
        //
        // DAS HAUPTBILD: Ein vorhandenes `horses.image_url` bleibt das
        // Hauptbild und bekommt eine eigene Medienzeile - sonst kennte die
        // Medienliste ausgerechnet das wichtigste Bild nicht. Hat ein Pferd
        // kein Hauptbild, aber Galeriebilder, wird das erste dazu.
        $dataStep('339_galerie_uebernahme', function (callable $vermerke, callable $offen) use ($pdo, $tabelleExistiert): ?array {
            if (!$tabelleExistiert('horse_media') || !$tabelleExistiert('horses')) {
                return null;
            }
            // Ohne das Addon gibt es nichts zu uebernehmen - und das ist der
            // Normalfall, nicht ein Fehler.
            if (!$tabelleExistiert('plugin_galerie_media')) {
                return [];
            }

            // Ueber den Helfer und nicht ueber dirname(): Er ist die eine
            // Stelle, die die Verzeichnisse kennt - und nur so laesst sich
            // dieser Schritt pruefen, ohne in die echte Ablage zu schreiben.
            $ziel = \App\Helper\HorseImagePath::dir();
            $quellen = \App\Helper\HorseImagePath::galerieLegacyDirs();

            try {
                $zeilen = $pdo->query(
                    "SELECT g.id, g.horse_id, g.type, g.file_path, g.video_url, g.caption, g.sort_order
                     FROM `plugin_galerie_media` g
                     JOIN `horses` h ON h.id = g.horse_id
                     ORDER BY g.horse_id ASC, g.sort_order ASC, g.id ASC"
                )->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                return $offen('Galerie (#339): Addon-Medien nicht lesbar (' . $e->getMessage() . ') - nächster Versuch automatisch in 15 Minuten oder sofort per php database/migrate.php');
            }

            if (!is_dir($ziel) && !@mkdir($ziel, 0755, true) && !is_dir($ziel)) {
                return $offen('Galerie (#339): storage/horses lässt sich nicht anlegen - nächster Versuch automatisch in 15 Minuten oder sofort per php database/migrate.php');
            }

            $einfuegen = $pdo->prepare(
                'INSERT INTO `horse_media` (horse_id, type, file_name, video_url, caption, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $schonDa = $pdo->prepare(
                'SELECT COUNT(*) FROM `horse_media`
                 WHERE horse_id = ? AND type = ? AND COALESCE(file_name, video_url) = ?'
            );

            $uebernommen = 0;
            $ohneDatei = 0;

            foreach ($zeilen as $z) {
                $typ = (string)$z['type'] === 'video' ? 'video' : 'image';
                $wert = null;

                if ($typ === 'image') {
                    $name = basename((string)($z['file_path'] ?? ''));
                    if ($name === '' || $name === '.' || $name === '..') {
                        continue;
                    }
                    if (!is_file($ziel . '/' . $name)) {
                        $verschoben = false;
                        foreach ($quellen as $quelle) {
                            if (is_file($quelle . '/' . $name) && @rename($quelle . '/' . $name, $ziel . '/' . $name)) {
                                $verschoben = true;
                                break;
                            }
                        }
                        if (!$verschoben) {
                            // Zeile ohne Datei: nicht uebernehmen, sonst
                            // zeigte ein Medieneintrag ins Leere.
                            $ohneDatei++;
                            continue;
                        }
                    }
                    $wert = '/uploads/horses/' . $name;
                } else {
                    $wert = trim((string)($z['video_url'] ?? ''));
                    if ($wert === '') {
                        continue;
                    }
                }

                // Idempotent: Ein zweiter Lauf findet die Zeile wieder.
                $schonDa->execute([(int)$z['horse_id'], $typ, $wert]);
                if ((int)$schonDa->fetchColumn() > 0) {
                    continue;
                }

                $einfuegen->execute([
                    (int)$z['horse_id'],
                    $typ,
                    $typ === 'image' ? $wert : null,
                    $typ === 'video' ? $wert : null,
                    ($z['caption'] ?? '') === '' ? null : mb_substr((string)$z['caption'], 0, 255),
                    (int)($z['sort_order'] ?? 0),
                ]);
                $uebernommen++;
            }

            // Bestehende Hauptbilder bekommen ihre Medienzeile - vorne in der
            // Reihenfolge, damit sie beim Anzeigen zuerst kommen.
            $hauptbilder = (int)$pdo->exec(
                "INSERT INTO `horse_media` (horse_id, type, file_name, is_main, sort_order)
                 SELECT h.id, 'image', h.image_url, 1, 0
                 FROM `horses` h
                 WHERE h.image_url IS NOT NULL AND h.image_url <> ''
                   AND NOT EXISTS (SELECT 1 FROM `horse_media` m WHERE m.horse_id = h.id AND m.file_name = h.image_url)"
            );

            // Pferde ohne Hauptbild, aber mit Bildern: das erste wird es.
            $pdo->exec(
                "UPDATE `horse_media` m
                 JOIN (
                     SELECT horse_id, MIN(sort_order * 1000000 + id) AS ordnung
                     FROM `horse_media`
                     WHERE type = 'image' AND file_name IS NOT NULL
                     GROUP BY horse_id
                 ) erste ON erste.horse_id = m.horse_id
                     AND (m.sort_order * 1000000 + m.id) = erste.ordnung
                 SET m.is_main = 1
                 WHERE NOT EXISTS (
                     SELECT 1 FROM (SELECT * FROM `horse_media`) x
                     WHERE x.horse_id = m.horse_id AND x.is_main = 1
                 )"
            );

            // Und `horses.image_url` dem Hauptbild nachziehen.
            $pdo->exec(
                "UPDATE `horses` h
                 JOIN `horse_media` m ON m.horse_id = h.id AND m.is_main = 1 AND m.file_name IS NOT NULL
                 SET h.image_url = m.file_name
                 WHERE h.image_url IS NULL OR h.image_url <> m.file_name"
            );

            if ($uebernommen === 0 && $hauptbilder === 0 && $ohneDatei === 0) {
                return [];
            }

            $meldung = sprintf(
                'Galerie (#339): %d Medium/Medien aus dem Addon uebernommen, %d Hauptbild(er) eingereiht',
                $uebernommen,
                $hauptbilder
            );
            if ($ohneDatei > 0) {
                $meldung .= sprintf(
                    '; %d Eintrag/Eintraege uebersprungen, weil die Datei fehlte - das Addon bleibt fuer '
                    . 'die Nachschau vorerst installiert',
                    $ohneDatei
                );
            }

            return [$meldung];
        });

        // 35b. Mehrdeutige Anmeldekennungen melden (#348).
        //
        // Ab v0.9 meldet man sich mit dem Benutzernamen ODER der
        // E-Mail-Adresse an. Neue Benutzernamen duerfen deshalb kein `@`
        // enthalten (LoginIdentifier::usernameErrors()). Bestandsnamen aber
        // schon - und wenn einer davon die Adresse eines ANDEREN Kontos ist,
        // waere die Eingabe mehrdeutig. Die Anmeldung weist diesen Fall
        // fail-closed ab (AuthController::loginSubmit()); niemand wird also
        // faelschlich eingelassen, aber die Betroffenen kaemen ohne Zutun
        // nicht mehr hinein. Deshalb wird es hier gemeldet, statt still
        // umbenannt zu werden: Welcher Name weichen soll, kann diese
        // Migration nicht wissen.
        //
        // Gemeldet wird EINMAL, beim Sprung auf diese Fassung. Ein Marker
        // wird dabei gesetzt (Rueckgabe ist eine Liste, kein null). Nur wenn
        // die Abfrage selbst scheitert, meldet sich der Schritt als offen
        // ($offen, Audit N76) - dann wird er wiederholt, statt still zu
        // entfallen. Der Hinweis steht im Protokoll des Updates, und der
        // Laufzeitschutz (fail-closed bei der Anmeldung) traegt unabhaengig
        // davon weiter.
        $dataStep('348_mehrdeutige_kennungen', function (callable $vermerke, callable $offen) use ($pdo, $tabelleExistiert): ?array {
            if (!$tabelleExistiert('users')) {
                return null;
            }
            try {
                $treffer = $pdo->query(
                    "SELECT u.username
                     FROM `users` u
                     JOIN `users` a ON a.id <> u.id AND a.email = u.username
                     WHERE u.deleted_at IS NULL AND a.deleted_at IS NULL
                     ORDER BY u.username ASC"
                )->fetchAll(\PDO::FETCH_COLUMN);
            } catch (\Throwable $e) {
                return $offen('Anmeldung (#348): Pruefung auf mehrdeutige Kennungen gescheitert (' . $e->getMessage() . ')');
            }

            if (!$treffer) {
                return [];
            }

            return [sprintf(
                'Anmeldung (#348): %d Benutzername(n) entsprechen der E-Mail-Adresse eines anderen Kontos '
                . '(%s). Die Anmeldung weist eine solche Eingabe als mehrdeutig ab - bitte einen der beiden '
                . 'Werte aendern.',
                count($treffer),
                implode(', ', array_slice($treffer, 0, 10)) . (count($treffer) > 10 ? ' ...' : '')
            )];
        });

        // 33b. Bestandsschlüssel ausdrücklich ablaufen lassen (#340).
        //
        // Der Spaltendefault CURRENT_TIMESTAMP allein genügt NICHT. Er wird
        // von der Datenbank in DEREN Sitzungszeitzone ausgewertet, und
        // Database::alignSessionTimeZone() läuft nur für Verbindungen aus
        // Database::getInstance() - database/migrate.php baut ausdrücklich
        // eine eigene PDO, Restore-Werkzeuge dürfen SchemaMigrator::run() mit
        // beliebiger Verbindung aufrufen. Steht die Datenbank vor der
        // PHP-Zeitzone, läge der gesetzte Wert aus Sicht von
        // ApiKey::authenticate() in der ZUKUNFT - und die Bestandsschlüssel
        // liefen weiter. Das ist genau der Zustand, den #340 beendet.
        //
        // created_at ist dagegen beweisbar vergangen: Der Schlüssel wurde
        // ausgestellt, bevor diese Migration lief.
        //
        // NUR Schlüssel, deren Ablauf höchstens einen Tag in der Zukunft
        // liegt (Audit M42). Bestandsschlüssel tragen den Zeitpunkt des ALTER
        // - höchstens um den Zeitzonenversatz aus dem Absatz oben verschoben,
        // also weniger als einen Tag. Hing die Migration zwischen dem ALTER
        // und hier, hat der neue Code inzwischen Schlüssel mit ausdrücklichem
        // Ablauf von mindestens einem Tag ausgestellt (ApiKey::create,
        // max(1, …)); die sollen beim Nachholen nicht ablaufen. Ein solcher
        // Schlüssel mit weniger als 24 h Restlaufzeit läuft vorzeitig ab -
        // vernachlässigbar. Im normalen Lauf (ALTER und dieser Schritt in
        // derselben Sitzung) ändert die Bedingung nichts.
        $dataStep('340_bestandsschluessel_ablaufen', function (callable $vermerke, callable $offen) use ($pdo, $tabelleExistiert): ?array {
            if (!$tabelleExistiert('api_keys')) {
                return null;
            }
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM `api_keys` LIKE 'expires_at'");
                if (!$stmt || $stmt->rowCount() === 0) {
                    return null;
                }
                $betroffen = $pdo->exec(
                    'UPDATE `api_keys` SET `expires_at` = `created_at` WHERE `expires_at` <= NOW() + INTERVAL 1 DAY'
                );
            } catch (\Throwable $e) {
                return $offen('API-Schlüssel (#340): Bestandsschlüssel nicht abgelaufen (' . $e->getMessage() . ')');
            }

            if (!$betroffen) {
                return [];
            }

            return [sprintf(
                'API-Schlüssel (#340): %d Bestandsschlüssel auf ihren Ausstellungszeitpunkt datiert und damit '
                . 'abgelaufen - laufende Anbindungen brauchen einen neuen Schlüssel',
                $betroffen
            )];
        });

        // 39b. Die alte Spalte users.passkeys faellt (#353).
        //
        // Sie stand seit Langem im Schema und wurde NIRGENDS gelesen oder
        // geschrieben - eine leere Zusage. Der Ersatz (Tabelle user_passkeys)
        // wird weiter oben angelegt; erst danach darf sie weg, sonst stuende
        // zwischen beiden Schritten ein Zustand ohne beides.
        //
        // Dass nichts verloren geht, wird geprueft und nicht behauptet: Ist
        // die Spalte wider Erwarten belegt, bleibt sie stehen und der Lauf
        // meldet es. Ein Datenverlust beim Aufraeumen einer nie benutzten
        // Spalte waere die Ironie zu viel.
        $dataStep('353_passkeys_spalte_faellt', function () use ($pdo, $spalteExistiert): ?array {
            if (!$spalteExistiert('users', 'passkeys')) {
                return null;
            }
            $belegt = (int)$pdo->query(
                "SELECT COUNT(*) FROM `users` WHERE `passkeys` IS NOT NULL AND `passkeys` <> ''"
            )->fetchColumn();
            if ($belegt > 0) {
                return ['uebersprungen' => "users.passkeys ist in {$belegt} Zeile(n) belegt - Spalte bleibt stehen."];
            }
            $pdo->exec("ALTER TABLE `users` DROP COLUMN `passkeys`");
            return ['entfernt' => 'users.passkeys'];
        });

        // 39c. contacts.membership_status fällt (#395, SCHEMA_VERSION 21).
        //
        // Seit #349 (v0.9.0) zeigt der Kern das Feld nicht mehr an und nimmt
        // es nicht mehr entgegen; die Angabe führt das Addon
        // `mitgliedsstatus` mit fester Werteliste. Die Spalte stand ein
        // Release lang weiter da, damit die Übernahme ins Addon laufen
        // konnte. Die ist erledigt - jetzt geht die Spalte.
        $dataStep('395_membership_status_faellt', function () use ($pdo, $spalteExistiert): ?array {
            if (!$spalteExistiert('contacts', 'membership_status')) {
                return null;
            }
            $pdo->exec("ALTER TABLE `contacts` DROP COLUMN `membership_status`");
            return ['entfernt' => 'contacts.membership_status'];
        });

        // 40. Indexlage der Katalog-Vorschlagslisten (#412, SCHEMA_VERSION 20).
        //
        // Die beiden Filter-Vorschlagslisten des oeffentlichen Katalogs
        // (Deckstation, Person) laufen bei JEDEM vollen Seitenaufruf. Seit der
        // Zusammenlegung auf `contacts` (#336) sind daraus Mehr-Tabellen-
        // Abfragen mit DISTINCT geworden; die Auswahl der veroeffentlichten
        // Kontakte lief mangels Index als Full Table Scan mit Filesort.
        //
        // Gemessen auf einer Kopie mit 10.000 Kontakten / 50.000 Pferden /
        // 90.694 Rollenzuordnungen, je 30 Durchlaeufe gegen warmen Puffer:
        //
        //                       Deckstationen     Personen
        //   ohne die Indizes        8,39 ms       133,32 ms
        //   mit beiden              7,54 ms        89,85 ms
        //
        // Und auf einer kleinen Instanz (300 / 500 / 1.000, 200 Durchlaeufe):
        // 0,30 -> 0,27 ms bzw. 1,45 -> 0,47 ms. Beide Groessenordnungen
        // gewinnen, keine verliert.
        //
        // Die Abfragen selbst bleiben unangetastet. Eine Umschreibung auf
        // EXISTS war gemessen zweischneidig (auf der grossen Kopie 91 -> 73 ms,
        // auf der kleinen 0,47 -> 0,94 ms, also doppelt so teuer fuer die
        // Mehrheit der Instanzen); ein Zwischenspeicher schied aus, weil diese
        // Listen eine Sichtbarkeitsflaeche sind - ein zurueckgezogener Kontakt
        // muss sofort verschwinden, nicht nach Ablauf einer Frist.
        $addIndex('contacts', 'idx_contacts_published_name', '`is_published`, `deleted_at`, `name`');

        // `idx_horse_persons_contact` gibt es seit #336 - aber nur ueber
        // `contact_id`. Die Personenliste fragt je Kontakt lediglich, OB eine
        // Zuordnung auf ein veroeffentlichtes Pferd zeigt; mit `horse_id` als
        // zweiter Spalte beantwortet der Index das allein.
        //
        // $addIndex prueft nur den NAMEN und wuerde den vorhandenen, zu
        // schmalen Index fuer erledigt halten. Deshalb hier ausdruecklich ueber
        // die Spaltenzahl - und Neuanlage vor Loeschung, damit zwischen beiden
        // Schritten kein Zustand ganz ohne Index steht.
        //
        // WIEDERAUFSETZBAR (Audit N77): Jede der drei DDL-Anweisungen
        // committet implizit. Stirbt der Prozess dazwischen (FPM-Timeout,
        // Container-Neustart), fand der naechste Lauf frueher einen Zustand,
        // den er nicht kannte, scheiterte an "Duplicate key name" - bei jedem
        // Lauf, fuer immer. Deshalb eine Zustandsmaschine ueber beide Namen:
        //   Ziel zweispaltig, _deckend vorhanden -> _deckend aufraeumen
        //   Ziel zweispaltig, kein _deckend      -> nichts zu tun
        //   weder Ziel noch _deckend             -> Ziel zweispaltig anlegen
        //   Ziel einspaltig, kein _deckend       -> _deckend anlegen, weiter:
        //   Ziel einspaltig, _deckend vorhanden  -> Ziel loeschen, weiter:
        //   nur _deckend                         -> umbenennen
        // In jedem Zwischenzustand traegt ein Index den FK auf contact_id.
        $dataStep('412_horse_persons_contact_deckend', function () use ($pdo, $tabelleExistiert, $spalteExistiert): ?array {
            if (!$tabelleExistiert('horse_persons') || !$spalteExistiert('horse_persons', 'contact_id')) {
                return null;
            }
            $indexSpalten = function (string $name) use ($pdo): array {
                $zeilen = $pdo->query(
                    "SHOW INDEX FROM `horse_persons` WHERE Key_name = " . $pdo->quote($name)
                )->fetchAll(\PDO::FETCH_ASSOC);
                usort($zeilen, static fn($a, $b) => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
                return array_map(static fn($z) => (string)$z['Column_name'], $zeilen);
            };

            $ziel = $indexSpalten('idx_horse_persons_contact');
            $zwischen = $indexSpalten('idx_horse_persons_contact_deckend');

            if (count($ziel) >= 2) {
                if ($zwischen !== []) {
                    $pdo->exec("DROP INDEX `idx_horse_persons_contact_deckend` ON `horse_persons`");
                    return ['aufgeräumt' => 'horse_persons.idx_horse_persons_contact_deckend (Rest eines abgebrochenen Laufs, #412)'];
                }
                return null; // schon deckend (frisches schema.sql)
            }
            if ($ziel === [] && $zwischen === []) {
                $pdo->exec("CREATE INDEX `idx_horse_persons_contact` ON `horse_persons` (`contact_id`, `horse_id`)");
                return ['angelegt' => 'horse_persons.idx_horse_persons_contact (contact_id, horse_id) (#412)'];
            }
            if (count($ziel) === 1 && $zwischen === []) {
                $pdo->exec(
                    "CREATE INDEX `idx_horse_persons_contact_deckend` ON `horse_persons` (`contact_id`, `horse_id`)"
                );
            }
            if (count($ziel) === 1) {
                $pdo->exec("DROP INDEX `idx_horse_persons_contact` ON `horse_persons`");
            }
            $pdo->exec(
                "ALTER TABLE `horse_persons` RENAME INDEX `idx_horse_persons_contact_deckend` TO `idx_horse_persons_contact`"
            );

            return ['erweitert' => 'horse_persons.idx_horse_persons_contact um horse_id (#412)'];
        });

        // 32. Dauerhafte Entscheidungen über Dubletten-Vorschläge (#355,
        // SCHEMA_VERSION 11). Siehe database/schema.sql für die Begründung.
        $createTable('match_labels', "CREATE TABLE IF NOT EXISTS `match_labels` (
            `kind` ENUM('horse', 'contact') NOT NULL,
            `left_id` INT NOT NULL,
            `right_id` INT NOT NULL,
            `label` ENUM('merged', 'different', 'unclear') NOT NULL,
            `note` VARCHAR(255) NULL DEFAULT NULL,
            `user_id` INT NULL DEFAULT NULL,
            `username` VARCHAR(50) NOT NULL DEFAULT 'SYSTEM',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`kind`, `left_id`, `right_id`),
            INDEX `idx_match_labels_kind_label` (`kind`, `label`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Reset-Token liegen nur noch als SHA-256-Abdruck in der Tabelle
        // (siehe AuthController::hashResetToken(), #318, v0.7.1 =
        // SCHEMA_VERSION 9). Ältere Bestände enthalten noch Klartext-Token,
        // die gegen den Abdruck nie treffen würden - sie werden entfernt statt
        // umgerechnet: Aus dem Klartext ließe sich der Abdruck zwar bilden,
        // aber die Zeilen sind höchstens 15 Minuten gültig, und ein laufender
        // Reset ist mit einem Klick neu angefordert. Ein Vorrat gültiger
        // Klartext-Token soll die Migration nicht überleben.
        //
        // EINMALIG und nur für Bestände von vor #318 (Audit N76). Bis zum
        // Fix lief das DELETE ungegatet in jedem Voll-Lauf - seit offene
        // Schritte alle 15 Minuten wiederholt werden, hätte es gültige
        // Reset-Links (nur noch Abdrücke) laufend vernichtet. 0 = unbekannt
        // (etwa ein Restore ohne Stand) zählt fail-closed zu den Altbeständen.
        $dataStep('318_reset_token_klartext', function () use ($pdo, $tabelleExistiert, $vorherigeVersion): ?array {
            if (!$tabelleExistiert('password_resets')) {
                return null; // Setup-Fall - dann gibt es auch nichts zu bereinigen.
            }
            if ($vorherigeVersion >= 9) {
                return []; // Nur Abdrücke im Bestand - nur vermerken.
            }
            $anzahl = (int)$pdo->query("SELECT COUNT(*) FROM password_resets")->fetchColumn();
            if ($anzahl === 0) {
                return [];
            }
            $pdo->exec("DELETE FROM password_resets");
            return ["password_resets geleert ({$anzahl} offene Anforderung(en)) - Token liegen jetzt nur als Abdruck vor"];
        });

        // 2b. Alte Klartext-TOTP-Secrets verschlüsseln (Audit N8,
        // SCHEMA_VERSION 24). Läuft nach 2a (Spaltenbreite).
        //
        // Die Laufzeit liest users.totp_secret seit N8 fail-closed
        // (Totp::entschluesseleSecret()): Entschlüsselbar, oder Base32-
        // Klartext aus der Zeit vor der Verschlüsselung - sonst nichts. Dieser
        // Schritt räumt den Klartext-Bestand auf, damit der Rückfall im
        // nächsten Minor-Release entfallen kann, und MELDET Konten, deren
        // Secret mit dem aktuellen APP_KEY nicht lesbar ist (APP_KEY
        // gewechselt?) - sie kommen nur noch per Backup-Code herein.
        //
        // Wiederholbar: Jede Zeile wird per Compare-and-swap umgeschrieben;
        // bricht der Lauf mittendrin ab, sind die schon verschlüsselten
        // Zeilen beim nächsten Mal kein Klartext mehr. Ohne APP_KEY gibt es
        // nichts zu verschlüsseln - dann meldet sich der Schritt offen, statt
        // den Klartext mit einem Marker für erledigt zu erklären.
        $dataStep('totp_klartext_verschluesseln', function (callable $vermerke, callable $offen) use ($pdo, $spalteExistiert): ?array {
            if (!$spalteExistiert('users', 'totp_secret')) {
                return null;
            }
            $zeilen = $pdo->query(
                "SELECT id, totp_secret, totp_enabled FROM `users` WHERE totp_secret IS NOT NULL AND totp_secret <> ''"
            )->fetchAll(\PDO::FETCH_ASSOC);
            if ($zeilen === []) {
                return [];
            }

            $mitSchluessel = defined('APP_KEY') && (string)constant('APP_KEY') !== '';
            if (!$mitSchluessel) {
                $klartext = count(array_filter(
                    $zeilen,
                    static fn(array $z): bool => \App\Security\Totp::istKlartextSecret((string)$z['totp_secret'])
                ));
                if ($klartext > 0) {
                    return $offen(sprintf(
                        'TOTP (Audit N8): %d Klartext-Secret(s) nicht verschlüsselt - APP_KEY ist nicht gesetzt',
                        $klartext
                    ));
                }
                return null; // Ohne Schlüssel lässt sich der Rest nicht beurteilen.
            }

            $tausch = $pdo->prepare("UPDATE `users` SET totp_secret = ? WHERE id = ? AND totp_secret = ?");
            $verschluesselt = 0;
            $unlesbar = 0;
            foreach ($zeilen as $z) {
                $wert = (string)$z['totp_secret'];
                if (\App\Security\Crypto::decrypt($wert) !== null) {
                    continue;
                }
                if (\App\Security\Totp::istKlartextSecret($wert)) {
                    $tausch->execute([\App\Security\Crypto::encrypt($wert), (int)$z['id'], $wert]);
                    $verschluesselt += $tausch->rowCount();
                    continue;
                }
                if ((int)$z['totp_enabled'] === 1) {
                    $unlesbar++;
                }
            }

            $meldungen = [];
            if ($verschluesselt > 0) {
                $meldungen[] = sprintf('TOTP: %d Klartext-Secret(s) verschlüsselt', $verschluesselt);
            }
            if ($unlesbar > 0) {
                $meldungen[] = sprintf(
                    'TOTP: %d Konto/Konten haben ein mit dem aktuellen APP_KEY nicht lesbares Secret '
                    . '(APP_KEY gewechselt?) – bitte deren 2FA zurücksetzen',
                    $unlesbar
                );
            }
            return $meldungen;
        });
    }
}
