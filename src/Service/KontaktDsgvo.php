<?php
// src/Service/KontaktDsgvo.php

namespace App\Service;

use PDO;

/**
 * DSGVO-Vollständigkeit am Kontakt (Audit M11, M23, N45).
 *
 * DAS PROBLEM. „Kontakt anonymisieren" und „Kontakt endgültig löschen"
 * behandelten nur die Zeile in `contacts`. Stehen blieben:
 *  - die Namenskopien der Deckstation am Pferd (`horses.breeding_station`,
 *    geschrieben von HorseController::saveHorsePersons()) und wörtliche
 *    Namenskopien in `horse_persons.breeding_station_text`. Nach dem DELETE
 *    ist `breeding_station_id` NULL, und die Sichtbarkeitsregeln aus #151
 *    behandeln die Kopie ab da als öffentlichen Freitext - auch bei nie
 *    veröffentlichten Kontakten (M11),
 *  - die Altkopien in `persons_pre_contacts`/`breeding_stations_pre_contacts`
 *    aus der Kontaktlisten-Umstellung (#336), mit Anschrift, E-Mail, Telefon
 *    und Mitgliedsstatus (M23),
 *  - Klarnamen und Dubletten-Notizen im Audit-Log (N45).
 *
 * Dieser Dienst erledigt alles zu einem Kontakt in der Transaktion des
 * Aufrufers. Reine PDO-Funktionen ohne Session, Hooks oder
 * Database::getInstance(): SchemaMigrator und database/rollback-336.php haben
 * eigene Verbindungen. Die Hooks (`contact.anonymized`, `contact.erased`)
 * feuern die Controller NACH dem Commit.
 *
 * BEKANNTE NAMEN. Die Freitextbereinigung ist ein wörtlicher Abgleich
 * (collation-insensitiv) gegen alle Namen, die der Bestand diesem Kontakt
 * zuordnen kann: den aktuellen, die der Altkopien und die aus ID-verankerten
 * Protokolleinträgen (Umbenennungen - ContactController::update() zieht die
 * Kopien am Pferd nicht nach). Namen unter drei Zeichen zählen nicht.
 * Schreibvarianten bleiben stehen; freier Text, der anders lautet (etwa
 * „Gestüt Musterstadt" neben einer verknüpften Station), ist Pferdehistorie
 * und bleibt ebenfalls.
 *
 * ALTKOPIEN UND MITGLIEDSSTATUS (Audit N78). `persons_pre_contacts` ist beim
 * Sprung aus v0.7 die Quelle, aus der das Addon mitgliedsstatus und
 * MitgliedsstatusAltbestand::offen() lesen - per JOIN auf `contacts` über
 * die ID. Dieser Dienst löscht eine Personen-Altzeile deshalb nur, wenn
 * KEIN lebender Kontakt mehr an ihr hängt (weder ID-treu noch über
 * contact_id_map) - dann zählt sie für beide ohnehin nicht mehr - oder wenn
 * sie einem DSGVO-Löschfall gehört. Anonymisiert wird nur die Zeile eines
 * DSGVO-Falls; `id` bleibt, `membership_status` wird wie jede andere Angabe
 * genullt. Alle übrigen Altzeilen behalten `id` und `membership_status`.
 */
final class KontaktDsgvo {

    /** Anlass einer Löschung: DSGVO-Verlangen über /admin/gdpr. */
    public const DSGVO = 'dsgvo';

    /** Anlass einer Löschung: endgültiges Löschen aus dem Papierkorb. */
    public const PAPIERKORB = 'papierkorb';

    /** Alttabellen der Kontaktlisten-Umstellung (#336) je contact_id_map.old_type. */
    public const ALTTABELLEN = [
        'person' => 'persons_pre_contacts',
        'station' => 'breeding_stations_pre_contacts',
    ];

    /**
     * Personenbezogene Spalten von `contacts`, die die Anonymisierung nullt.
     *
     * Diese Liste ist hartkodiert und muss bei JEDER neuen Spalte in
     * contacts mitgezogen werden. Ein vergessenes Feld fällt nicht auf: Die
     * Anonymisierung meldet weiterhin Erfolg, und die Lücke bleibt still
     * bestehen. Der Gegentest dazu steht in tests/Functional/GdprEraseTest.php
     * und leitet die Feldliste aus dem Schema ab.
     *
     * `contact_person` und `address` kommen aus der früheren Tabelle
     * `breeding_stations` (#336) und sind genauso personenbezogen - der
     * Ansprechpartner IST ein Mensch, und die alte Freitext-Anschrift ist oft
     * eine Privatadresse. Nicht genullt werden id/name (name wird ersetzt)
     * sowie is_published, is_breeder und contact_public: Sie sagen etwas über
     * den Datensatz, nicht über die Person, und sind NOT NULL.
     */
    public const PII_SPALTEN = [
        'contact_person', 'contact_info', 'street', 'house_number', 'postal_code', 'city', 'state',
        'country', 'address', 'email', 'phone', 'mobile', 'website',
    ];

    /** Spalten einer Altzeile, die ihre Anonymisierung überstehen. */
    private const ALT_BEHALTEN = ['id', 'is_published', 'is_breeder', 'contact_public', 'created_at', 'updated_at', 'deleted_at'];

    /** Kürzester Name, der in den Freitextabgleich eingeht. */
    public const MIN_NAMENSLAENGE = 3;

    /** Die Aktionen im gdpr-Protokoll, an denen der Bestand DSGVO-Fälle erkennt (#135). */
    private const GDPR_AKTIONEN = ['DSGVO: Person anonymisiert', 'DSGVO: Person endgültig gelöscht'];

    private function __construct() {}

    /**
     * Der Name eines anonymisierten Kontakts - die EINZIGE Quelle.
     *
     * DER WORTLAUT IST EIN VERTRAG (Audit N78): Das Addon mitgliedsstatus
     * (Uebernahme::altbestandZeilen()) und MitgliedsstatusAltbestand erkennen
     * anonymisierte Kontakte an genau diesem Text, der Bestand ebenso
     * (nachholen()). Wer ihn ändert, zieht alle drei mit.
     */
    public static function anonymName(int $id): string {
        return 'Anonymisierte Person (#' . $id . ')';
    }

    public static function istAnonymName(string $name): bool {
        return preg_match('/^Anonymisierte Person \(#\d+\)$/u', $name) === 1;
    }

    // ---- Anonymisieren und Löschen -------------------------------------

    /**
     * Anonymisiert einen Kontakt samt Kopien. Erwartet eine offene
     * Transaktion. null, wenn der Kontakt nicht (mehr) existiert.
     *
     * @return array{vorher: array<string, mixed>, bereinigt: array<string, int>}|null
     */
    public static function anonymisieren(PDO $db, int $id): ?array {
        $stmt = $db->prepare('SELECT * FROM contacts WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $vorher = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($vorher)) {
            return null;
        }

        $alt = self::altKennungen($db, [$id])[$id];
        $namen = self::bekannteNamen($db, $id, $alt, $vorher);
        $emails = self::bekannteEmails($db, $alt, $vorher);

        $anon = self::anonymName($id);
        $setzen = implode(', ', array_map(static fn(string $s): string => "`{$s}` = NULL", self::PII_SPALTEN));
        $db->prepare("UPDATE contacts SET name = ?, {$setzen} WHERE id = ?")->execute([$anon, $id]);

        $zaehler = self::zaehlerLeer();
        $zaehler['stationskopien'] = self::kopienBereinigen($db, [$id], $namen, $anon);
        $stmt = $db->prepare("UPDATE match_labels SET note = NULL WHERE kind = 'contact' AND (left_id = ? OR right_id = ?) AND note IS NOT NULL");
        $stmt->execute([$id, $id]);
        $zaehler['dubletten'] = $stmt->rowCount();
        $zaehler['altkopien'] = self::altzeilenAnonymisieren($db, [$id => $alt]);
        $zaehler['protokoll'] = AuditLogger::kontaktPseudonymisieren($db, $id, $alt['person'], $alt['station'], $namen, $emails);

        return ['vorher' => $vorher, 'bereinigt' => $zaehler];
    }

    /**
     * Löscht Kontakte endgültig samt Kopien. Erwartet eine offene
     * Transaktion.
     *
     * Der Dienst sperrt zuerst (SELECT … FOR UPDATE) und arbeitet danach NUR
     * mit den tatsächlich gesperrten Kennungen. Beim Anlass PAPIERKORB gilt
     * der Guard `deleted_at IS NOT NULL` im Sperr-Select UND im DELETE (Race
     * #222): Ein zwischen Auswahl und Charge wiederhergestellter Kontakt
     * bleibt erhalten. $mindestTage prüft zusätzlich die Aufbewahrungsfrist
     * des Editor-Pfads unter Sperre.
     *
     * Das Protokoll wird nur beim Anlass DSGVO pseudonymisiert - ein
     * gewöhnliches Löschen aus dem Papierkorb ist kein Löschverlangen, und
     * die Historie bleibt nachvollziehbar.
     *
     * @param int[] $ids
     * @return array{kontakte: array<int, array<string, mixed>>, bereinigt: array<string, int>}
     */
    public static function loeschen(PDO $db, array $ids, string $anlass, ?int $mindestTage = null): array {
        if (!in_array($anlass, [self::DSGVO, self::PAPIERKORB], true)) {
            throw new \InvalidArgumentException("Unbekannter Anlass: {$anlass}");
        }
        $ids = self::kennungen($ids);
        $zaehler = self::zaehlerLeer();
        if ($ids === []) {
            return ['kontakte' => [], 'bereinigt' => $zaehler];
        }

        $guard = '';
        $guardWerte = [];
        if ($anlass === self::PAPIERKORB) {
            $guard = ' AND deleted_at IS NOT NULL';
            if ($mindestTage !== null) {
                $guard .= ' AND deleted_at <= DATE_SUB(NOW(), INTERVAL ? DAY)';
                $guardWerte[] = $mindestTage;
            }
        }
        $stmt = $db->prepare('SELECT * FROM contacts WHERE id IN (' . self::platzhalter($ids) . ")" . $guard . ' ORDER BY id FOR UPDATE');
        $stmt->execute([...$ids, ...$guardWerte]);
        $kontakte = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
            $kontakte[(int)$zeile['id']] = $zeile;
        }
        if ($kontakte === []) {
            return ['kontakte' => [], 'bereinigt' => $zaehler];
        }
        $gesperrt = array_keys($kontakte);

        // Vor dem DELETE lesen: contact_id_map fällt per CASCADE mit.
        $alt = self::altKennungen($db, $gesperrt);
        $namenJe = [];
        $alleNamen = [];
        foreach ($gesperrt as $id) {
            $namenJe[$id] = self::bekannteNamen($db, $id, $alt[$id], $kontakte[$id]);
            array_push($alleNamen, ...$namenJe[$id]);
        }

        $zaehler['stationskopien'] = self::kopienBereinigen($db, $gesperrt, array_values(array_unique($alleNamen)), null);

        $in = self::platzhalter($gesperrt);
        $stmt = $db->prepare("DELETE FROM match_labels WHERE kind = 'contact' AND (left_id IN ({$in}) OR right_id IN ({$in}))");
        $stmt->execute([...$gesperrt, ...$gesperrt]);
        $zaehler['dubletten'] = $stmt->rowCount();

        $zaehler['altkopien'] = self::altzeilenLoeschen($db, $alt, $gesperrt, $anlass === self::DSGVO);

        if ($anlass === self::DSGVO) {
            foreach ($gesperrt as $id) {
                $zaehler['protokoll'] += AuditLogger::kontaktPseudonymisieren(
                    $db,
                    $id,
                    $alt[$id]['person'],
                    $alt[$id]['station'],
                    $namenJe[$id],
                    self::bekannteEmails($db, $alt[$id], $kontakte[$id])
                );
            }
        }

        $stmt = $db->prepare("DELETE FROM contacts WHERE id IN ({$in})" . ($anlass === self::PAPIERKORB ? ' AND deleted_at IS NOT NULL' : ''));
        $stmt->execute($gesperrt);

        return ['kontakte' => $kontakte, 'bereinigt' => $zaehler];
    }

    /**
     * Freitext für Vermerke und Protokoll - nur Zahlen, keine Namen.
     *
     * @param array<string, int> $zaehler
     */
    public static function zaehlerText(array $zaehler): string {
        return sprintf(
            '%d Deckstations-Kopie(n), %d Altkopie(n) (#336), %d Dubletten-Notiz(en), %d Protokolleintrag/-einträge',
            $zaehler['stationskopien'] ?? 0,
            $zaehler['altkopien'] ?? 0,
            $zaehler['dubletten'] ?? 0,
            $zaehler['protokoll'] ?? 0
        );
    }

    // ---- Namen und Kennungen -------------------------------------------

    /**
     * Welche alten Kennungen (#336) gehören zu diesen Kontakten?
     *
     * Aus contact_id_map (Stationen sind nur dort auffindbar; nach einem
     * Zusammenführen hängen die Kennungen der Quelle am Ziel, Audit M33),
     * dazu ID-treu die Personenzeile derselben Kennung, falls es sie gibt -
     * die zusammengeführte Quelle im Papierkorb findet ihre eigene Altzeile
     * sonst nicht mehr.
     *
     * @param int[] $ids
     * @return array<int, array{person: int[], station: int[]}>
     */
    public static function altKennungen(PDO $db, array $ids): array {
        $ids = self::kennungen($ids);
        $ergebnis = [];
        foreach ($ids as $id) {
            $ergebnis[$id] = ['person' => [], 'station' => []];
        }
        if ($ids === []) {
            return $ergebnis;
        }

        try {
            $stmt = $db->prepare('SELECT contact_id, old_type, old_id FROM contact_id_map WHERE contact_id IN (' . self::platzhalter($ids) . ')');
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
                $typ = (string)$zeile['old_type'];
                if (isset(self::ALTTABELLEN[$typ])) {
                    $ergebnis[(int)$zeile['contact_id']][$typ][] = (int)$zeile['old_id'];
                }
            }
        } catch (\PDOException $e) {
            // contact_id_map fehlt (Stand vor #336) - dann gibt es keine Altkopien.
            return $ergebnis;
        }

        if (isset(self::altTabellen($db)['person'])) {
            $stmt = $db->prepare('SELECT id FROM `persons_pre_contacts` WHERE id IN (' . self::platzhalter($ids) . ')');
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $ergebnis[(int)$pid]['person'][] = (int)$pid;
            }
        }

        foreach ($ergebnis as $id => $typen) {
            $ergebnis[$id] = ['person' => self::kennungen($typen['person']), 'station' => self::kennungen($typen['station'])];
        }
        return $ergebnis;
    }

    /**
     * Alle Namen, die der Bestand diesem Kontakt zuordnen kann (siehe
     * Klassenkommentar). Ohne Anonymnamen und ohne Namen unter
     * MIN_NAMENSLAENGE Zeichen.
     *
     * @param array{person: int[], station: int[]} $alt
     * @param array<string, mixed>|null $kontakt die Zeile aus contacts, falls schon gelesen
     * @return string[]
     */
    public static function bekannteNamen(PDO $db, int $id, array $alt, ?array $kontakt = null): array {
        $namen = [];
        if ($kontakt !== null) {
            $namen[] = (string)($kontakt['name'] ?? '');
        } else {
            $stmt = $db->prepare('SELECT name FROM contacts WHERE id = ?');
            $stmt->execute([$id]);
            $namen[] = (string)$stmt->fetchColumn();
        }
        foreach (self::altzeilen($db, $alt) as $zeile) {
            $namen[] = (string)($zeile['felder']['name'] ?? '');
        }
        array_push($namen, ...AuditLogger::kontaktNamenAusProtokoll($db, $id, $alt['person'], $alt['station']));

        $sauber = [];
        foreach ($namen as $name) {
            $name = trim($name);
            if (mb_strlen($name) < self::MIN_NAMENSLAENGE || self::istAnonymName($name) || str_contains($name, AuditLogger::ENTFERNT)) {
                continue;
            }
            $sauber[mb_strtolower($name)] = $name;
        }
        return array_values($sauber);
    }

    /**
     * @param array{person: int[], station: int[]} $alt
     * @param array<string, mixed> $kontakt
     * @return string[]
     */
    private static function bekannteEmails(PDO $db, array $alt, array $kontakt): array {
        $emails = [(string)($kontakt['email'] ?? '')];
        foreach (self::altzeilen($db, $alt) as $zeile) {
            $emails[] = (string)($zeile['felder']['email'] ?? '');
        }
        return array_values(array_unique(array_filter(array_map('trim', $emails), static fn(string $e): bool => str_contains($e, '@'))));
    }

    // ---- Altkopien (#336, Audit M23) -----------------------------------

    /**
     * Welche Alttabellen gibt es? Auf Neuinstallationen keine.
     *
     * @return array<string, string> old_type => Tabelle
     */
    public static function altTabellen(PDO $db): array {
        try {
            $vorhanden = $db->query(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME IN ('persons_pre_contacts', 'breeding_stations_pre_contacts')"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            return [];
        }
        return array_filter(self::ALTTABELLEN, static fn(string $t): bool => in_array($t, $vorhanden, true));
    }

    /**
     * Die Altkopien eines Kontakts für die Auskunft (Art. 15): je Zeile
     * Tabelle, alte Kennung und alle Spalten.
     *
     * @return array<int, array{tabelle: string, kennung: int, felder: array<string, mixed>}>
     */
    public static function altkopien(PDO $db, int $id): array {
        return self::altzeilen($db, self::altKennungen($db, [$id])[$id] ?? ['person' => [], 'station' => []]);
    }

    /**
     * Hat der Kontakt eine Altkopie? Batch für die DSGVO-Übersicht.
     *
     * @param int[] $ids
     * @return array<int, bool>
     */
    public static function hatAltkopie(PDO $db, array $ids): array {
        $ergebnis = [];
        $tabellen = self::altTabellen($db);
        foreach (self::altKennungen($db, $ids) as $id => $alt) {
            $hat = false;
            foreach ($tabellen as $typ => $tabelle) {
                if ($alt[$typ] === []) {
                    continue;
                }
                $stmt = $db->prepare("SELECT COUNT(*) FROM `{$tabelle}` WHERE id IN (" . self::platzhalter($alt[$typ]) . ')');
                $stmt->execute($alt[$typ]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $hat = true;
                    break;
                }
            }
            $ergebnis[$id] = $hat;
        }
        return $ergebnis;
    }

    /**
     * @param array{person: int[], station: int[]} $alt
     * @return array<int, array{tabelle: string, kennung: int, felder: array<string, mixed>}>
     */
    private static function altzeilen(PDO $db, array $alt): array {
        $zeilen = [];
        foreach (self::altTabellen($db) as $typ => $tabelle) {
            $kennungen = self::kennungen($alt[$typ] ?? []);
            if ($kennungen === []) {
                continue;
            }
            $stmt = $db->prepare("SELECT * FROM `{$tabelle}` WHERE id IN (" . self::platzhalter($kennungen) . ') ORDER BY id');
            $stmt->execute($kennungen);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
                $zeilen[] = ['tabelle' => $tabelle, 'kennung' => (int)$zeile['id'], 'felder' => $zeile];
            }
        }
        return $zeilen;
    }

    /**
     * Altzeilen der zu löschenden Kontakte entfernen.
     *
     * Eine Personen-Altzeile hängt ID-treu an ihrem Kontakt und über
     * contact_id_map womöglich an einem anderen (nach einem Zusammenführen).
     * Außer im DSGVO-Fall bleibt sie stehen, solange einer davon den Vorgang
     * überlebt - sonst verlöre der überlebende Kontakt seinen Altbestand
     * (Mitgliedsstatus, Rückweg #336). Stationszeilen hängen nur an der Karte.
     *
     * Rückfall bei SQLSTATE 23000 (ein Addon zeigt per Fremdschlüssel auf die
     * Alttabelle): anonymisieren statt löschen. Der Fehler betrifft nur das
     * einzelne Statement, die Transaktion bleibt intakt.
     *
     * @param array<int, array{person: int[], station: int[]}> $alt
     * @param int[] $geloescht die Kontakte, die gerade gelöscht werden
     */
    private static function altzeilenLoeschen(PDO $db, array $alt, array $geloescht, bool $dsgvo): int {
        $tabellen = self::altTabellen($db);
        if ($tabellen === []) {
            return 0;
        }

        $anzahl = 0;
        foreach ($tabellen as $typ => $tabelle) {
            $kennungNachKontakt = [];
            foreach ($alt as $kontaktId => $typen) {
                foreach ($typen[$typ] as $kennung) {
                    $kennungNachKontakt[$kennung] = $kontaktId;
                }
            }
            if ($typ === 'person' && !$dsgvo) {
                foreach (array_keys($kennungNachKontakt) as $kennung) {
                    if (self::personenzeileHatUeberlebende($db, $kennung, $geloescht)) {
                        unset($kennungNachKontakt[$kennung]);
                    }
                }
            }
            if ($kennungNachKontakt === []) {
                continue;
            }

            $kennungen = array_keys($kennungNachKontakt);
            try {
                $stmt = $db->prepare("DELETE FROM `{$tabelle}` WHERE id IN (" . self::platzhalter($kennungen) . ')');
                $stmt->execute($kennungen);
                $anzahl += $stmt->rowCount();
            } catch (\PDOException $e) {
                if ((string)$e->getCode() !== '23000') {
                    throw $e;
                }
                $anzahl += self::zeilenAnonymisieren($db, $tabelle, $kennungNachKontakt);
            }
        }
        return $anzahl;
    }

    /** Hängt an dieser Personen-Altzeile ein Kontakt, der nicht gerade gelöscht wird? */
    private static function personenzeileHatUeberlebende(PDO $db, int $kennung, array $geloescht): bool {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM contacts c
             WHERE (c.id = ? OR c.id IN (SELECT m.contact_id FROM contact_id_map m WHERE m.old_type = 'person' AND m.old_id = ?))
               AND c.id NOT IN (" . self::platzhalter($geloescht) . ')'
        );
        $stmt->execute([$kennung, $kennung, ...$geloescht]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Altzeilen DSGVO-anonymisierter Kontakte anonymisieren.
     *
     * @param array<int, array{person: int[], station: int[]}> $alt
     */
    private static function altzeilenAnonymisieren(PDO $db, array $alt): int {
        $anzahl = 0;
        foreach (self::altTabellen($db) as $typ => $tabelle) {
            $kennungNachKontakt = [];
            foreach ($alt as $kontaktId => $typen) {
                foreach ($typen[$typ] as $kennung) {
                    $kennungNachKontakt[$kennung] = $kontaktId;
                }
            }
            if ($kennungNachKontakt !== []) {
                $anzahl += self::zeilenAnonymisieren($db, $tabelle, $kennungNachKontakt);
            }
        }
        return $anzahl;
    }

    /**
     * Schemagetrieben aus SHOW COLUMNS - die Alt-Schemata variieren je
     * Version, eine feste Liste ließe dort Angaben stehen:
     *  - name wird der Anonymname des Kontakts,
     *  - ALT_BEHALTEN bleibt (Kennung und Kennzeichen des Datensatzes),
     *  - jede übrige NULL-fähige Spalte wird NULL - auch membership_status,
     *  - jede übrige NOT-NULL-Textspalte (CHAR/VARCHAR/TEXT) wird '',
     *  - übrige NOT-NULL-Spalten (Zahlen) bleiben.
     *
     * @param array<int, int> $kennungNachKontakt alte Kennung => Kontakt
     */
    private static function zeilenAnonymisieren(PDO $db, string $tabelle, array $kennungNachKontakt): int {
        $setzen = [];
        $hatName = false;
        foreach ($db->query("SHOW COLUMNS FROM `{$tabelle}`")->fetchAll(PDO::FETCH_ASSOC) as $spalte) {
            $feld = (string)$spalte['Field'];
            if (preg_match('/^[A-Za-z0-9_]{1,64}\z/', $feld) !== 1 || in_array($feld, self::ALT_BEHALTEN, true)) {
                continue;
            }
            if ($feld === 'name') {
                $hatName = true;
                continue;
            }
            if (strtoupper((string)$spalte['Null']) === 'YES') {
                $setzen[] = "`{$feld}` = NULL";
            } elseif (preg_match('/^(var)?char|^(tiny|medium|long)?text/i', (string)$spalte['Type']) === 1) {
                $setzen[] = "`{$feld}` = ''";
            }
        }

        $anzahl = 0;
        foreach ($kennungNachKontakt as $kennung => $kontaktId) {
            $teile = $setzen;
            $werte = [];
            if ($hatName) {
                array_unshift($teile, '`name` = ?');
                $werte[] = self::anonymName($kontaktId);
            }
            if ($teile === []) {
                continue;
            }
            $stmt = $db->prepare("UPDATE `{$tabelle}` SET " . implode(', ', $teile) . ' WHERE id = ?');
            $stmt->execute([...$werte, $kennung]);
            $anzahl += $stmt->rowCount();
        }
        return $anzahl;
    }

    // ---- Kopien am Pferd (Audit M11) -----------------------------------

    /**
     * Bereinigt die Namenskopien zu diesen Kontakten.
     *
     * a) Die Kopie am Pferd der eigenen Station: bei gesetzter
     *    breeding_station_id ist horses.breeding_station laut #151 immer nur
     *    der Spiegel. $ersatz = NULL beim Löschen, der Anonymname beim
     *    Anonymisieren.
     * b) Merge-Rest: ContactController::merge() hängt breeding_station_id auf
     *    das Ziel um und lässt die Kopie der Quelle stehen. Solche Kopien
     *    werden auf die tatsächlich verknüpfte Station synchronisiert - bei
     *    gesetzter Kennung ist der Text nur ein Spiegel, es geht nichts
     *    verloren. Ein Pferd, das den Namen als echten Freitext OHNE
     *    Verknüpfung trägt, bleibt unberührt.
     * c) Deckstations-Freitext einer Zuordnungszeile MIT Stationsverknüpfung,
     *    der wörtlich einem bekannten Namen entspricht - dort zeigt die
     *    Oberfläche ohnehin den Namen der verknüpften Station.
     *
     * @param int[] $ids
     * @param string[] $namen
     */
    private static function kopienBereinigen(PDO $db, array $ids, array $namen, ?string $ersatz): int {
        $in = self::platzhalter($ids);
        $stmt = $db->prepare("UPDATE horses SET breeding_station = ? WHERE breeding_station_id IN ({$in}) AND NOT (breeding_station <=> ?)");
        $stmt->execute([$ersatz, ...$ids, $ersatz]);
        $anzahl = $stmt->rowCount();

        if ($namen === []) {
            return $anzahl;
        }
        $namenIn = self::platzhalter($namen);
        $stmt = $db->prepare(
            "UPDATE horses h JOIN contacts z ON z.id = h.breeding_station_id
                SET h.breeding_station = z.name
              WHERE h.breeding_station IN ({$namenIn}) AND h.breeding_station_id NOT IN ({$in})
                AND NOT (h.breeding_station <=> z.name)"
        );
        $stmt->execute([...$namen, ...$ids]);
        $anzahl += $stmt->rowCount();

        $stmt = $db->prepare(
            "UPDATE horse_persons SET breeding_station_text = NULL
              WHERE station_contact_id IS NOT NULL AND breeding_station_text IN ({$namenIn})"
        );
        $stmt->execute($namen);
        return $anzahl + $stmt->rowCount();
    }

    // ---- Bestand (einmaliger Update-Schritt, rollback-336.php) ---------

    /**
     * Zieht den Bestand einmalig nach (Entscheidung D18, Umfang b):
     *  1. Kontakte, die im gdpr-Protokoll als anonymisiert bzw. endgültig
     *     gelöscht stehen oder den Anonymnamen tragen: Kopien am Pferd,
     *     Dubletten-Notizen, Altkopien und Protokoll wie bei einer neuen
     *     DSGVO-Aktion. Ein Kontakt, der trotz Protokolleintrag existiert und
     *     NICHT den Anonymnamen trägt, bleibt unberührt.
     *  2. Altkopien, an denen kein Kontakt mehr hängt (seit der Umstellung
     *     hart gelöscht - DSGVO, Papierkorb). Personenzeilen nur, wenn auch
     *     ID-treu kein Kontakt mehr existiert - so bleiben `id` und
     *     `membership_status` jeder Zeile stehen, die MitgliedsstatusAltbestand
     *     noch zählen könnte (Audit N78).
     *  3. Einmalcodes und Adressen im Mail-Protokoll (Audit N17).
     *
     * Kopien am Pferd, deren Stationsverknüpfung schon früher auf NULL ging,
     * lassen sich nicht sicher von importiertem Freitext unterscheiden und
     * bleiben stehen (CHANGELOG nennt eine Prüfabfrage).
     *
     * Eine Transaktion, nur DML - SHOW COLUMNS und information_schema lösen
     * keinen impliziten Commit aus. $vorCommit läuft unmittelbar vor dem
     * Commit (Marker des Datenschritts, atomar mit den Daten). Idempotent.
     *
     * @return array<string, int>|null null, wenn der Bestand noch nicht
     *         so weit ist (Setup, #336 unvollständig)
     */
    public static function nachholen(PDO $db, ?callable $vorCommit = null): ?array {
        $tabelle = static function (string $name) use ($db): bool {
            return $db->query('SHOW TABLES LIKE ' . $db->quote($name))->rowCount() > 0;
        };
        foreach (['contacts', 'audit_logs', 'contact_id_map', 'match_labels', 'horses', 'horse_persons'] as $noetig) {
            if (!$tabelle($noetig)) {
                return null;
            }
        }
        if ($tabelle('persons') || $tabelle('breeding_stations')) {
            return null;
        }

        $zaehler = self::zaehlerLeer() + ['kontakte' => 0, 'uebersprungen' => 0, 'verwaist' => 0, 'mail' => 0];

        $db->beginTransaction();
        try {
            $geloeschtLaut = [];
            $faelle = [];
            $stmt = $db->prepare(
                "SELECT action, details FROM audit_logs WHERE category = 'gdpr' AND action IN (?, ?) ORDER BY id"
            );
            $stmt->execute(self::GDPR_AKTIONEN);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
                if (preg_match('/^Person ID (\d+)(?!\d)/', (string)$zeile['details'], $t) === 1) {
                    $faelle[(int)$t[1]] = true;
                    if ($zeile['action'] === self::GDPR_AKTIONEN[1]) {
                        $geloeschtLaut[(int)$t[1]] = true;
                    }
                }
            }
            foreach ($db->query("SELECT id FROM contacts WHERE name = CONCAT('Anonymisierte Person (#', id, ')')")->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $faelle[(int)$id] = true;
            }
            ksort($faelle);

            foreach (array_keys($faelle) as $id) {
                $stmt = $db->prepare('SELECT * FROM contacts WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $kontakt = $stmt->fetch(PDO::FETCH_ASSOC);

                if (is_array($kontakt) && (string)$kontakt['name'] === self::anonymName($id)) {
                    $einzeln = self::bestandAnonymisiert($db, $id, $kontakt);
                } elseif (!is_array($kontakt) && isset($geloeschtLaut[$id])) {
                    $einzeln = self::bestandGeloescht($db, $id);
                } else {
                    $zaehler['uebersprungen']++;
                    continue;
                }
                foreach ($einzeln as $schluessel => $wert) {
                    $zaehler[$schluessel] += $wert;
                }
                if (array_sum($einzeln) > 0) {
                    $zaehler['kontakte']++;
                }
            }

            $zaehler['verwaist'] = self::verwaisteAltzeilenEntfernen($db);
            $zaehler['mail'] = AuditLogger::emailProtokollMaskieren($db);

            if ($vorCommit !== null) {
                $vorCommit();
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return $zaehler;
    }

    /**
     * Wie viel würde nachholen() an den Altkopien ändern? Für den Prüfmodus
     * von database/rollback-336.php.
     *
     * @return array{geloescht: int, anonymisiert: int}
     */
    public static function altbestandBefund(PDO $db): array {
        $befund = ['geloescht' => 0, 'anonymisiert' => 0];
        $tabellen = self::altTabellen($db);
        if (isset($tabellen['person'])) {
            $befund['geloescht'] += (int)$db->query(
                "SELECT COUNT(*) FROM persons_pre_contacts p
                 WHERE NOT EXISTS (SELECT 1 FROM contact_id_map m WHERE m.old_type = 'person' AND m.old_id = p.id)
                   AND NOT EXISTS (SELECT 1 FROM contacts c WHERE c.id = p.id)"
            )->fetchColumn();
            $befund['anonymisiert'] += (int)$db->query(
                "SELECT COUNT(*) FROM persons_pre_contacts p
                 JOIN contacts c ON c.id = p.id OR c.id IN (SELECT m.contact_id FROM contact_id_map m WHERE m.old_type = 'person' AND m.old_id = p.id)
                 WHERE c.name = CONCAT('Anonymisierte Person (#', c.id, ')')
                   AND NOT (p.name <=> c.name)"
            )->fetchColumn();
        }
        if (isset($tabellen['station'])) {
            $befund['geloescht'] += (int)$db->query(
                "SELECT COUNT(*) FROM breeding_stations_pre_contacts s
                 WHERE NOT EXISTS (SELECT 1 FROM contact_id_map m WHERE m.old_type = 'station' AND m.old_id = s.id)"
            )->fetchColumn();
            $befund['anonymisiert'] += (int)$db->query(
                "SELECT COUNT(*) FROM breeding_stations_pre_contacts s
                 JOIN contact_id_map m ON m.old_type = 'station' AND m.old_id = s.id
                 JOIN contacts c ON c.id = m.contact_id
                 WHERE c.name = CONCAT('Anonymisierte Person (#', c.id, ')')
                   AND NOT (s.name <=> c.name)"
            )->fetchColumn();
        }
        return $befund;
    }

    /**
     * Bestand: ein früher anonymisierter Kontakt (Name ist schon ersetzt).
     *
     * @param array<string, mixed> $kontakt
     * @return array<string, int>
     */
    private static function bestandAnonymisiert(PDO $db, int $id, array $kontakt): array {
        $alt = self::altKennungen($db, [$id])[$id];
        // Namen VOR dem Anonymisieren der Altzeilen lesen - danach stehen
        // dort nur noch Anonymnamen.
        $namen = self::bekannteNamen($db, $id, $alt, $kontakt);
        $emails = self::bekannteEmails($db, $alt, $kontakt);

        $zaehler = self::zaehlerLeer();
        $zaehler['stationskopien'] = self::kopienBereinigen($db, [$id], $namen, self::anonymName($id));
        $stmt = $db->prepare("UPDATE match_labels SET note = NULL WHERE kind = 'contact' AND (left_id = ? OR right_id = ?) AND note IS NOT NULL");
        $stmt->execute([$id, $id]);
        $zaehler['dubletten'] = $stmt->rowCount();
        $zaehler['altkopien'] = self::altzeilenAnonymisieren($db, [$id => $alt]);
        $zaehler['protokoll'] = AuditLogger::kontaktPseudonymisieren($db, $id, $alt['person'], $alt['station'], $namen, $emails);
        return $zaehler;
    }

    /**
     * Bestand: ein früher per DSGVO endgültig gelöschter Kontakt. Die Karte
     * ist mit ihm gefallen (CASCADE); übrig ist ID-treu höchstens die
     * Personen-Altzeile. Stationszeilen findet erst der Schritt für
     * verwaiste Altzeilen - hier nur, um ihre Namen und Kennungen fürs
     * Protokoll zu kennen, wenn der Name wörtlich passt.
     *
     * @return array<string, int>
     */
    private static function bestandGeloescht(PDO $db, int $id): array {
        $alt = ['person' => [], 'station' => []];
        $tabellen = self::altTabellen($db);
        if (isset($tabellen['person'])) {
            $stmt = $db->prepare(
                "SELECT p.id FROM persons_pre_contacts p
                 WHERE p.id = ?
                   AND NOT EXISTS (SELECT 1 FROM contact_id_map m JOIN contacts c ON c.id = m.contact_id
                                   WHERE m.old_type = 'person' AND m.old_id = p.id)"
            );
            $stmt->execute([$id]);
            $alt['person'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        // Gelöscht wird nur, was oben gefunden wurde. Fürs Protokoll zählt die
        // Personenkennung ID-treu auch ohne Altzeile: "Person ID {id}" von vor
        // #336 - auch wenn die Altzeile inzwischen einem anderen Kontakt gehört.
        $loeschen = $alt;
        $alt['person'] = self::kennungen([...$alt['person'], $id]);

        $namen = self::bekannteNamen($db, $id, $alt, ['name' => '']);
        if ($namen !== [] && isset($tabellen['station'])) {
            $stmt = $db->prepare(
                "SELECT s.id FROM breeding_stations_pre_contacts s
                 WHERE s.name IN (" . self::platzhalter($namen) . ")
                   AND NOT EXISTS (SELECT 1 FROM contact_id_map m WHERE m.old_type = 'station' AND m.old_id = s.id)"
            );
            $stmt->execute($namen);
            $alt['station'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $loeschen['station'] = $alt['station'];
            if ($alt['station'] !== []) {
                $namen = self::bekannteNamen($db, $id, $alt, ['name' => '']);
            }
        }
        $emails = self::bekannteEmails($db, $alt, []);

        $zaehler = self::zaehlerLeer();
        $zaehler['stationskopien'] = self::kopienBereinigen($db, [$id], $namen, null);
        $stmt = $db->prepare("DELETE FROM match_labels WHERE kind = 'contact' AND (left_id = ? OR right_id = ?)");
        $stmt->execute([$id, $id]);
        $zaehler['dubletten'] = $stmt->rowCount();
        $zaehler['altkopien'] = self::altzeilenLoeschen($db, [$id => $loeschen], [$id], true);
        $zaehler['protokoll'] = AuditLogger::kontaktPseudonymisieren($db, $id, $alt['person'], $alt['station'], $namen, $emails);
        return $zaehler;
    }

    /** Altzeilen ohne lebenden Kontakt entfernen (siehe nachholen(), Punkt 2). */
    private static function verwaisteAltzeilenEntfernen(PDO $db): int {
        $tabellen = self::altTabellen($db);
        $verwaist = [];
        if (isset($tabellen['person'])) {
            $verwaist['person'] = array_map('intval', $db->query(
                "SELECT p.id FROM persons_pre_contacts p
                 WHERE NOT EXISTS (SELECT 1 FROM contact_id_map m WHERE m.old_type = 'person' AND m.old_id = p.id)
                   AND NOT EXISTS (SELECT 1 FROM contacts c WHERE c.id = p.id)"
            )->fetchAll(PDO::FETCH_COLUMN));
        }
        if (isset($tabellen['station'])) {
            $verwaist['station'] = array_map('intval', $db->query(
                "SELECT s.id FROM breeding_stations_pre_contacts s
                 WHERE NOT EXISTS (SELECT 1 FROM contact_id_map m WHERE m.old_type = 'station' AND m.old_id = s.id)"
            )->fetchAll(PDO::FETCH_COLUMN));
        }

        $anzahl = 0;
        foreach ($verwaist as $typ => $kennungen) {
            // Kontakt 0 als Anonymname-Quelle im FK-Rückfall: den Kontakt gibt es nicht mehr.
            foreach (array_chunk($kennungen, 500) as $charge) {
                $anzahl += self::altzeilenLoeschen($db, [0 => [$typ => $charge, ($typ === 'person' ? 'station' : 'person') => []]], [0], true);
            }
        }
        return $anzahl;
    }

    // ---- Kleinkram -----------------------------------------------------

    /** @return array{stationskopien: int, altkopien: int, dubletten: int, protokoll: int} */
    private static function zaehlerLeer(): array {
        return ['stationskopien' => 0, 'altkopien' => 0, 'dubletten' => 0, 'protokoll' => 0];
    }

    /** @return int[] */
    private static function kennungen(array $ids): array {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
    }

    private static function platzhalter(array $werte): string {
        return implode(',', array_fill(0, count($werte), '?'));
    }
}
