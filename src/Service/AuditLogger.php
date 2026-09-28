<?php
// src/Service/AuditLogger.php

namespace App\Service;

use App\Database;
use App\Security\ClientIp;

/**
 * Class AuditLogger
 *
 * Revisionssicherer Audit-Protokoll-Dienst.
 * Zeichnet alle sicherheits- und datenrelevanten Systemereignisse (Pferde, Kontakte,
 * Einstellungen, Logins, 403-Sicherheitsverstöße, E-Mail-Versand)
 * in der Datenbank-Tabelle `audit_logs` auf.
 *
 * APPEND-ONLY MIT GENAU EINER AUSNAHME (Audit N45, Entscheidung D18): Die
 * DSGVO-Pseudonymisierung. Löscht oder anonymisiert ein Admin einen Kontakt
 * über /admin/gdpr, ersetzt kontaktPseudonymisieren() die Namens- und
 * Notizteile der Einträge, die über ihre KENNUNG an diesem Kontakt hängen,
 * durch ENTFERNT. Das Ereignis selbst - wer, wann, welche Kennung - bleibt
 * stehen. Dazu maskiert der einmalige Update-Schritt Einmalcodes und Adressen
 * in der Kategorie `email` (emailProtokollMaskieren()). Sonst ändert nichts
 * einen geschriebenen Eintrag. Bereits erstellte Sicherungen und extern
 * exportierte Protokolle erreicht das nicht.
 */
class AuditLogger {

    /** Ersatztext für pseudonymisierte Namens- und Notizteile (Audit N45). */
    public const ENTFERNT = '[DSGVO entfernt]';

    /** Ersatztext für E-Mail-Adressen im Protokoll (Audit N45/N17). */
    public const ADRESSE = '[Adresse]';

    /** Ersatztext für Einmalcodes im Mail-Protokoll (Audit N17). */
    public const CODE = '******';

    /**
     * Kategorien, deren Einträge Kontaktnamen tragen können. `persons`,
     * `breeding_stations` und `stations` sind Alteinträge aus der Zeit vor der
     * Kontaktliste (#336), `matches` die Dubletten-Entscheidungen (#355).
     */
    private const KONTAKT_KATEGORIEN = ['contacts', 'persons', 'breeding_stations', 'stations', 'matches'];

    /**
     * Protokolliert ein Audit-Ereignis in der Datenbank.
     *
     * @param string $action Kurzbeschreibung der Aktion (z. B. "Pferd erstellt", "Systemeinstellungen aktualisiert")
     * @param string $category Kategorie ("horses", "contacts", "users", "settings", "email", "auth", "security", "trash").
     *                         Alteintraege tragen weiterhin "persons"/"stations" - das Protokoll wird
     *                         durch die Kontaktliste (#336) NICHT umgeschrieben, sonst behauptete es
     *                         rueckwirkend, es habe die Trennung nie gegeben. Der Filter auf
     *                         /admin/audit-log speist sich aus DISTINCT category, zeigt die alten
     *                         Werte also weiterhin an.
     * @param string|null $details Zusatzinformationen / Kontext zur Aktion
     * @param int|null $userId Optionale Überschreibung der Benutzer-ID (Standard: $_SESSION['user_id'] oder NULL)
     * @param string|null $username Optionale Überschreibung des Benutzernamens (Standard: $_SESSION['username'] oder 'SYSTEM')
     */
    public static function log($action, string $category = 'general', $details = null, ?int $userId = null, ?string $username = null): void {
        try {
            $db = Database::getInstance();

            // Fallback für Session-Start
            if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                @session_start();
            }

            // Benutzer-Kontext ermitteln (sofern nicht explizit übergeben)
            if ($userId === null && $username === null) {
                if (!empty($_SESSION['user_id'])) {
                    $userId = (int)$_SESSION['user_id'];
                    $username = $_SESSION['username'] ?? null;

                    // Benutzernamen aus der Datenbank nachladen, falls nicht in der Session vorhanden
                    if (empty($username)) {
                        $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
                        $stmt->execute([$userId]);
                        $username = $stmt->fetchColumn() ?: 'Unbekannt';
                        $_SESSION['username'] = $username;
                    }
                } else {
                    $userId = null;
                    $username = 'SYSTEM';
                }
            }

            if (empty($username)) {
                $username = 'SYSTEM';
            }

            // Parameter sicher formatieren (Arrays/Objekte in JSON wandeln)
            if (is_array($action) || is_object($action)) {
                $action = json_encode($action, JSON_UNESCAPED_UNICODE);
            } else {
                $action = (string)$action;
            }

            if (is_array($category) || is_object($category)) {
                $category = json_encode($category, JSON_UNESCAPED_UNICODE);
            } else {
                $category = (string)$category;
            }

            if (is_array($details) || is_object($details)) {
                $details = json_encode($details, JSON_UNESCAPED_UNICODE);
            } else if ($details !== null) {
                $details = (string)$details;
            }

            // Client-IP-Adresse ermitteln (berücksichtigt Reverse Proxies / Load Balancer
            // nur, wenn REMOTE_ADDR über TRUSTED_PROXIES als vertrauenswürdig gilt)
            $ipAddress = ClientIp::resolve();

            // Log-Eintrag in der Datenbank speichern
            $stmt = $db->prepare("INSERT INTO audit_logs (user_id, username, action, category, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $userId ?: null,
                $username,
                self::truncate($action, 100),
                self::truncate($category, 50),
                $details,
                self::truncate($ipAddress, 45)
            ]);
        } catch (\Throwable $e) {
            // Ausfallsicherheit: Audit-Logger Fehler stören den normalen
            // Anwendungsfluss nicht.
            //
            // Unterschieden wird dabei zwischen "konnte gar nicht" und
            // "hat nicht geklappt" - dieselbe Trennung wie beim nächtlichen
            // Testlauf, und aus demselben Grund. Ist überhaupt keine
            // Datenbank eingerichtet (Installationsassistent, isolierter
            // Unit-Test, CLI-Werkzeug ohne Konfiguration), dann ist das kein
            // Fehlschlag des Protokolls, sondern eine Lage, in der es nichts
            // zu protokollieren GIBT. Eine Meldung darüber im Fehlerprotokoll
            // ist Rauschen - und Rauschen im Fehlerprotokoll ist teuer, weil
            // es die echten Meldungen zudeckt.
            //
            // Steht dagegen eine Datenbank und der Eintrag geht trotzdem
            // schief, ist das ein echter Befund: Ein sicherheitsrelevantes
            // Ereignis ist dann nicht revisionssicher festgehalten. Das
            // gehört gemeldet.
            if (!self::datenbankEingerichtet()) {
                return;
            }

            error_log("AuditLogger Failure: " . $e->getMessage());
            $logDir = __DIR__ . '/../../storage/logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            @file_put_contents($logDir . '/audit_errors.log', date('[Y-m-d H:i:s] ') . $e->getMessage() . "\n", FILE_APPEND);

            // Automatische Dateibereinigung triggern
            self::cleanupLogs();
        }
    }

    /**
     * Ist ueberhaupt eine Datenbank eingerichtet?
     *
     * `DB_HOST` entsteht in config/config.php - aus einer Umgebungsvariablen
     * oder aus config/db_config.php. Fehlt die Konstante, wurde die
     * Konfiguration nie geladen: frische Installation vor dem Assistenten,
     * isolierter Unit-Test, CLI-Werkzeug ohne Konfiguration.
     *
     * Bewusst NICHT geprueft wird, ob die Verbindung steht. Eine eingerichtete,
     * aber nicht erreichbare Datenbank ist ein echter Fehlschlag und gehoert
     * gemeldet - genau die Unterscheidung, um die es hier geht.
     */
    private static function datenbankEingerichtet(): bool {
        if (self::$datenbankEingerichtetOverride !== null) {
            return self::$datenbankEingerichtetOverride;
        }
        return defined('DB_HOST');
    }

    /** @var bool|null Nur fuer Tests - siehe overrideDatenbankEingerichtetForTests(). */
    private static ?bool $datenbankEingerichtetOverride = null;

    /**
     * Nur fuer Tests: die Antwort auf "ist eine Datenbank eingerichtet?"
     * uebersteuern. `null` stellt den Normalzustand wieder her.
     *
     * WARUM DAS NOETIG IST. `DB_HOST` ist eine KONSTANTE - einmal definiert,
     * laesst sie sich nicht zuruecknehmen. Ein Test, der den Fall "keine
     * Datenbank" pruefen will, kann sie also nicht wegnehmen; er kann nur
     * feststellen, dass sie da ist, und sich ueberspringen.
     *
     * Genau das ist passiert: Die erste Fassung dieser Tests uebersprang sich
     * in jeder Umgebung mit Datenbankkonfiguration - also in der gesamten CI.
     * Zwei gruene Haken, die nie etwas geprueft hatten. Ein uebersprungener
     * Test ist kein bestandener, und wer das nicht bemerkt, haelt eine Luecke
     * fuer eine Zusicherung (analog BackupService::overrideUploadsDirForTests()).
     */
    public static function overrideDatenbankEingerichtetForTests(?bool $wert): void {
        self::$datenbankEingerichtetOverride = $wert;
    }

    /**
     * DSGVO-Pseudonymisierung der Protokolleinträge zu EINEM Kontakt (Audit
     * N45, Entscheidung D18) - die einzige Stelle, die einen geschriebenen
     * Eintrag nachträglich ändert, siehe den Klassenkommentar.
     *
     * Erfasst werden nur Einträge, die über ihre Kennung am Kontakt hängen,
     * in den Formaten, die der Kern seit v0.7 schreibt (geprüft gegen die
     * Tags v0.7.1 bis v0.9.0):
     *  - contacts: "Kontakt ID {k}: <Name>", Merge "Quelle ID {k} (<Name>) ->
     *    Ziel ID … (" bzw. "-> Ziel ID {k} (<Name>): N Zuordnung…" - bei
     *    Merge-Einträgen nur die Seite dieses Kontakts,
     *  - persons (vor #336): "Person ID {p}: <Name>" und die Merge-Formate
     *    mit der alten Personenkennung,
     *  - breeding_stations/stations (vor #336): "Deckstation ID {s}: <Name>"
     *    mit der alten Stationskennung aus contact_id_map,
     *  - matches: "contact {a}/{b}: <label> (<Notiz>)" - die Notiz.
     * In GENAU diesen Zeilen werden zusätzlich die übergebenen Namen (ab drei
     * Zeichen) ersetzt. Einträge anderer Kategorien bleiben unberührt, auch
     * wenn derselbe Name dort vorkommt (etwa als Pferdename) - ein
     * Namensabgleich über das ganze Protokoll träfe Unbeteiligte.
     *
     * $emails: Adressen des Kontakts, die in der Kategorie `email` (Mailer,
     * "Empfänger: <adresse>") durch ADRESSE ersetzt werden.
     *
     * Nutzt ausdrücklich die übergebene Verbindung: Der Aufruf läuft in der
     * Transaktion des Aufrufers (GdprController, SchemaMigrator,
     * database/rollback-336.php).
     *
     * @param int[] $altePersonenIds Kennungen aus persons (vor #336)
     * @param int[] $alteStationsIds Kennungen aus breeding_stations (vor #336)
     * @param string[] $namen bekannte Namen des Kontakts
     * @param string[] $emails bekannte E-Mail-Adressen des Kontakts
     * @return int Anzahl geänderter Einträge
     */
    public static function kontaktPseudonymisieren(
        \PDO $db,
        int $kontaktId,
        array $altePersonenIds = [],
        array $alteStationsIds = [],
        array $namen = [],
        array $emails = []
    ): int {
        $emails = array_values(array_unique(array_filter(
            array_map(static fn($e): string => trim((string)$e), $emails),
            static fn(string $e): bool => $e !== '' && str_contains($e, '@')
        )));

        $geaendert = 0;
        foreach (self::kontaktEintraege($db, $kontaktId, $altePersonenIds, $alteStationsIds) as $zeile) {
            $alt = (string)$zeile['details'];
            $neu = self::kennungsteilErsetzen($alt, (string)$zeile['category'], $kontaktId, $altePersonenIds, $alteStationsIds);
            $neu = self::namenErsetzen($neu, $namen);
            if ($emails !== []) {
                $neu = str_ireplace($emails, self::ADRESSE, $neu);
            }
            if ($neu !== $alt) {
                self::detailsSchreiben($db, (int)$zeile['id'], $neu);
                $geaendert++;
            }
        }

        if ($emails !== []) {
            $bedingung = implode(' OR ', array_fill(0, count($emails), 'details LIKE ?'));
            $stmt = $db->prepare("SELECT id, details FROM audit_logs WHERE category = 'email' AND ({$bedingung})");
            $stmt->execute(array_map(static fn(string $e): string => '%' . self::likeWoertlich($e) . '%', $emails));
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $zeile) {
                $alt = (string)$zeile['details'];
                $neu = str_ireplace($emails, self::ADRESSE, $alt);
                if ($neu !== $alt) {
                    self::detailsSchreiben($db, (int)$zeile['id'], $neu);
                    $geaendert++;
                }
            }
        }

        return $geaendert;
    }

    /**
     * Die Namen, die das Protokoll über die Kennungen eines Kontakts kennt
     * (dieselben Formate wie in kontaktPseudonymisieren()). Nötig, weil
     * ContactController::update() die Namenskopien am Pferd nicht
     * nachzieht: Nach einer Umbenennung steht dort der ALTE Name, und nur das
     * Protokoll weiß noch, dass er zu diesem Kontakt gehörte (Audit M11).
     *
     * @param int[] $altePersonenIds
     * @param int[] $alteStationsIds
     * @return string[]
     */
    public static function kontaktNamenAusProtokoll(\PDO $db, int $kontaktId, array $altePersonenIds = [], array $alteStationsIds = []): array {
        $namen = [];
        foreach (self::kontaktEintraege($db, $kontaktId, $altePersonenIds, $alteStationsIds) as $zeile) {
            foreach (self::kennungsMuster((string)$zeile['category'], $kontaktId, $altePersonenIds, $alteStationsIds) as $muster) {
                if (preg_match($muster['regex'], (string)$zeile['details'], $treffer) && $muster['name']) {
                    $namen[] = trim($treffer[2]);
                }
            }
        }
        return $namen;
    }

    /**
     * Maskiert Einmalcodes ("Anmeldecode 123456") und E-Mail-Adressen in
     * allen Einträgen der Kategorie `email` (Audit N17, Entscheidung D18) -
     * einmalig im Update-Schritt dsgvo_nachfuehrung. Idempotent: Maskierte
     * Einträge enthalten weder "@" noch eine Ziffernfolge nach "Anmeldecode".
     *
     * Ein Statement statt einer PHP-Schleife: Die Kategorie wächst mit jeder
     * versendeten Mail. REGEXP_REPLACE gibt es in MariaDB ab 10.0.5 und in
     * MySQL ab 8.0; die Muster nutzen nur POSIX-Klassen, die beide Dialekte
     * (PCRE bzw. ICU) gleich verstehen.
     *
     * @return int Anzahl geänderter Einträge
     */
    public static function emailProtokollMaskieren(\PDO $db): int {
        $stmt = $db->prepare(
            "UPDATE audit_logs
                SET details = REGEXP_REPLACE(
                        REGEXP_REPLACE(details, 'Anmeldecode [0-9]+', ?),
                        '[^[:space:]<>,;:()]+@[^[:space:]<>,;:()]+', ?),
                    created_at = created_at
              WHERE category = 'email'
                AND (details LIKE '%@%' OR details REGEXP 'Anmeldecode [0-9]')"
        );
        $stmt->execute(['Anmeldecode ' . self::CODE, self::ADRESSE]);
        return $stmt->rowCount();
    }

    /**
     * Kandidaten für kontaktPseudonymisieren()/kontaktNamenAusProtokoll():
     * Einträge der Kontakt-Kategorien, deren Details über eine der Kennungen
     * am Kontakt hängen. Die LIKE-Präfixe grenzen vor, die Regeln in
     * kennungsMuster() entscheiden (5 trifft nicht 55).
     *
     * @return array<int, array{id: mixed, category: mixed, details: mixed}>
     */
    private static function kontaktEintraege(\PDO $db, int $kontaktId, array $altePersonenIds, array $alteStationsIds): array {
        $teile = [];
        $werte = [];
        $mitKennung = static function (string $kategorien, array $muster) use (&$teile, &$werte): void {
            if ($muster === []) {
                return;
            }
            $teile[] = "(category IN ({$kategorien}) AND ("
                . implode(' OR ', array_fill(0, count($muster), 'details LIKE ?')) . '))';
            array_push($werte, ...$muster);
        };

        $k = $kontaktId;
        $mitKennung("'contacts'", ["Kontakt ID {$k}:%", "Quelle ID {$k} (%", "%-> Ziel ID {$k} (%"]);
        $mitKennung("'matches'", ["contact {$k}/%", "contact %/{$k}:%"]);
        $personen = [];
        foreach (self::kennungen($altePersonenIds) as $p) {
            array_push($personen, "Person ID {$p}:%", "Quelle ID {$p} (%", "%-> Ziel ID {$p} (%");
        }
        $mitKennung("'persons'", $personen);
        $stationen = [];
        foreach (self::kennungen($alteStationsIds) as $s) {
            $stationen[] = "Deckstation ID {$s}:%";
        }
        $mitKennung("'breeding_stations', 'stations'", $stationen);

        $stmt = $db->prepare('SELECT id, category, details FROM audit_logs WHERE ' . implode(' OR ', $teile) . ' ORDER BY id');
        $stmt->execute($werte);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Die ID-verankerten Formate je Kategorie. Gruppe 1 ist der Kennungsteil,
     * Gruppe 2 der Namens- bzw. Notizteil, Gruppe 3 der Rest dahinter.
     * 'name' sagt, ob Gruppe 2 ein Name ist (für kontaktNamenAusProtokoll)
     * oder Freitext (die Notiz einer Dubletten-Entscheidung).
     *
     * @return array<int, array{regex: string, name: bool}>
     */
    private static function kennungsMuster(string $kategorie, int $kontaktId, array $altePersonenIds, array $alteStationsIds): array {
        $merge = static fn(int $id): array => [
            // Greedy bis zum festen Anschluss: Namen dürfen Klammern enthalten.
            ['regex' => '/^(Quelle ID ' . $id . ' \()(.*)(\) -> Ziel ID \d+ \(.*)$/su', 'name' => true],
            ['regex' => '/^(.*-> Ziel ID ' . $id . ' \()(.*)(\): \d+ Zuordnung.*)$/su', 'name' => true],
        ];
        $muster = [];
        switch ($kategorie) {
            case 'contacts':
                $muster[] = ['regex' => '/^(Kontakt ID ' . $kontaktId . ': )(.+)()$/su', 'name' => true];
                array_push($muster, ...$merge($kontaktId));
                break;
            case 'persons':
                foreach (self::kennungen($altePersonenIds) as $p) {
                    $muster[] = ['regex' => '/^(Person ID ' . $p . ': )(.+)()$/su', 'name' => true];
                    array_push($muster, ...$merge($p));
                }
                break;
            case 'breeding_stations':
            case 'stations':
                foreach (self::kennungen($alteStationsIds) as $s) {
                    $muster[] = ['regex' => '/^(Deckstation ID ' . $s . ': )(.+)()$/su', 'name' => true];
                }
                break;
            case 'matches':
                $muster[] = [
                    'regex' => '/^(contact (?:' . $kontaktId . '\/\d+|\d+\/' . $kontaktId . '): [a-z]+ \()(.*)(\))$/su',
                    'name' => false,
                ];
                break;
        }
        return $muster;
    }

    private static function kennungsteilErsetzen(string $details, string $kategorie, int $kontaktId, array $altePersonenIds, array $alteStationsIds): string {
        foreach (self::kennungsMuster($kategorie, $kontaktId, $altePersonenIds, $alteStationsIds) as $muster) {
            $details = (string)preg_replace_callback(
                $muster['regex'],
                // Schon ersetzt bzw. der Platzhalter der neuen Einträge ("Notiz
                // hinterlegt", MatchLabel seit Audit N45): nichts zu tun.
                static fn(array $t): string => in_array($t[2], [self::ENTFERNT, 'Notiz hinterlegt'], true)
                    ? $t[0]
                    : $t[1] . self::ENTFERNT . $t[3],
                $details
            );
        }
        return $details;
    }

    /** @param string[] $namen */
    private static function namenErsetzen(string $details, array $namen): string {
        $namen = array_filter(
            array_map(static fn($n): string => trim((string)$n), $namen),
            static fn(string $n): bool => mb_strlen($n) >= 3 && $n !== self::ENTFERNT
        );
        // Längere zuerst: "Gestüt Anna Muster" vor "Anna Muster".
        usort($namen, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        return $namen === [] ? $details : str_replace($namen, self::ENTFERNT, $details);
    }

    /** @return int[] */
    private static function kennungen(array $ids): array {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
    }

    private static function likeWoertlich(string $s): string {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    private static function detailsSchreiben(\PDO $db, int $id, string $details): void {
        // created_at ausdrücklich auf sich selbst: Der Zeitpunkt des
        // Ereignisses bleibt, auch wo die Spalte ON UPDATE hätte.
        $db->prepare('UPDATE audit_logs SET details = ?, created_at = created_at WHERE id = ?')->execute([$details, $id]);
    }

    /**
     * Automatische Bereinigung alter Log-Dateien in storage/logs (> 5 MB oder > 30 Tage)
     */
    public static function cleanupLogs(): void {
        $logFile = __DIR__ . '/../../storage/logs/audit_errors.log';
        if (file_exists($logFile)) {
            $maxSizeBytes = 5 * 1024 * 1024; // 5 Megabyte
            $maxAgeSeconds = 30 * 86400;     // 30 Tage

            if (filesize($logFile) > $maxSizeBytes || (time() - filemtime($logFile) > $maxAgeSeconds)) {
                $lines = @file($logFile);
                if ($lines && count($lines) > 100) {
                    $recentLines = array_slice($lines, -100);
                    @file_put_contents($logFile, implode('', $recentLines));
                }
            }
        }
    }

    /**
     * Kürzt einen String sicher auf eine maximale Zeichenlänge.
     *
     * @param string $input Eingangstext
     * @param int $maxLength Maximale Zeichenanzahl
     * @return string Gekürzter Text
     */
    private static function truncate(string $input, int $maxLength): string {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($input) > $maxLength) {
                return mb_substr($input, 0, $maxLength);
            }
            return $input;
        }

        if (strlen($input) > $maxLength) {
            return substr($input, 0, $maxLength);
        }
        return $input;
    }
}
