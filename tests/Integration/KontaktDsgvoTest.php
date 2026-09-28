<?php
// tests/Integration/KontaktDsgvoTest.php

namespace Tests\Integration;

use App\Service\AuditLogger;
use App\Service\KontaktDsgvo;
use App\Service\MitgliedsstatusAltbestand;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\AltbestandV072;
use Tests\Support\WegwerfDatenbank;

/**
 * App\Service\KontaktDsgvo und AuditLogger::kontaktPseudonymisieren() (Audit
 * M11, M23, N45) auf einer Wegwerf-Datenbank mit aktuellem schema.sql und
 * nachgebauten Alttabellen im v0.7-Schnitt (inkl. membership_status und einer
 * NOT-NULL-Textspalte, die es so nicht gab - die Anonymisierung muss
 * schemagetrieben arbeiten, nicht nach einer festen Liste).
 */
class KontaktDsgvoTest extends TestCase {

    private static function db(): string {
        return WegwerfDatenbank::name('kontakt_dsgvo');
    }

    private PDO $pdo;

    protected function setUp(): void {
        if (!defined('DB_HOST')) {
            $this->markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }
        $this->pdo = AltbestandV072::aktuell(self::db());
    }

    protected function tearDown(): void {
        if (defined('DB_HOST')) {
            AltbestandV072::entfernen(self::db());
        }
    }

    private function alttabellen(): void {
        $this->pdo->exec(
            "CREATE TABLE `persons_pre_contacts` (
                `id` INT NOT NULL PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `contact_info` TEXT,
                `street` VARCHAR(150) NULL DEFAULT NULL,
                `email` VARCHAR(100) NULL DEFAULT NULL,
                `phone` VARCHAR(50) NULL DEFAULT NULL,
                `membership_status` VARCHAR(100) NULL DEFAULT NULL,
                `kuerzel` VARCHAR(20) NOT NULL DEFAULT '',
                `is_breeder` TINYINT(1) NOT NULL DEFAULT 0,
                `is_published` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `deleted_at` DATETIME NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->pdo->exec(
            "CREATE TABLE `breeding_stations_pre_contacts` (
                `id` INT NOT NULL PRIMARY KEY,
                `name` VARCHAR(150) NOT NULL,
                `contact_person` VARCHAR(100) NULL,
                `address` TEXT NULL,
                `email` VARCHAR(100) NULL,
                `contact_public` TINYINT(1) NOT NULL DEFAULT 1,
                `is_published` TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * Person 1 (ID-treu), Station 7 -> Kontakt 20, eine unbeteiligte Person 3.
     */
    private function bestand(): void {
        $this->alttabellen();
        $this->pdo->exec("INSERT INTO contacts (id, name, email) VALUES
            (1, 'Anna Beispiel', 'anna@example.com'), (3, 'Cora Dritte', NULL), (20, 'Gestüt Sonnenhof', 'hof@example.com')");
        $this->pdo->exec("INSERT INTO contact_id_map (old_type, old_id, contact_id) VALUES
            ('person', 1, 1), ('person', 3, 3), ('station', 7, 20)");
        $this->pdo->exec("INSERT INTO persons_pre_contacts (id, name, contact_info, street, email, phone, membership_status, kuerzel, is_breeder) VALUES
            (1, 'Anna Geborene', 'Tel. 0170', 'Weg 1', 'anna-alt@example.com', '0123', 'Mitglied', 'AB', 1),
            (3, 'Cora Dritte', NULL, NULL, NULL, NULL, 'Mitglied', 'CD', 0)");
        $this->pdo->exec("INSERT INTO breeding_stations_pre_contacts (id, name, contact_person, address, email) VALUES
            (7, 'Gestüt Sonnenhof Alt', 'Herr Hof', 'Hofweg 2', 'hof-alt@example.com')");
    }

    private function wert(string $sql, array $werte = []): mixed {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($werte);
        return $stmt->fetchColumn();
    }

    private function protokoll(string $kategorie, string $details, string $aktion = 'Test'): int {
        $this->pdo->prepare("INSERT INTO audit_logs (username, action, category, details) VALUES ('t', ?, ?, ?)")
            ->execute([$aktion, $kategorie, $details]);
        return (int)$this->pdo->lastInsertId();
    }

    private function details(int $id): string {
        return (string)$this->wert('SELECT details FROM audit_logs WHERE id = ?', [$id]);
    }

    public function testDsgvoLoeschenEntferntAltzeilenKopienUndNamen(): void {
        $this->bestand();
        // Stationskopie am eigenen Pferd, Merge-Rest (Pferd zeigt auf 3, trägt
        // aber den Namen von 20), Freitext neben verknüpfter Station.
        $this->pdo->exec("INSERT INTO horses (id, name, breeding_station_id, breeding_station) VALUES
            (100, 'Stute Eins', 20, 'Gestüt Sonnenhof'),
            (101, 'Stute Zwei', 3, 'Gestüt Sonnenhof Alt'),
            (102, 'Stute Drei', NULL, 'Gestüt Sonnenhof')");
        $this->pdo->exec("INSERT INTO horse_persons (id, horse_id, contact_id, role, station_contact_id, breeding_station_text) VALUES
            (500, 100, NULL, 'keeper', 20, 'Gestüt Sonnenhof'),
            (501, 101, NULL, 'keeper', 3, 'Gestüt Sonnenhof Alt'),
            (502, 101, NULL, 'keeper', 20, 'Gestüt Musterstadt'),
            (503, 102, NULL, 'keeper', NULL, 'Gestüt Sonnenhof')");
        $this->pdo->exec("INSERT INTO match_labels (kind, left_id, right_id, label, note) VALUES
            ('contact', 3, 20, 'different', 'Hof von Frau Beispiel')");
        $eintrag = $this->protokoll('breeding_stations', 'Deckstation ID 7: Gestüt Sonnenhof Alt');

        $this->pdo->beginTransaction();
        $ergebnis = KontaktDsgvo::loeschen($this->pdo, [20], KontaktDsgvo::DSGVO);
        $this->pdo->commit();

        $this->assertSame([20], array_keys($ergebnis['kontakte']));
        $this->assertSame(0, (int)$this->wert('SELECT COUNT(*) FROM contacts WHERE id = 20'));
        $this->assertSame(0, (int)$this->wert('SELECT COUNT(*) FROM breeding_stations_pre_contacts'), 'Altzeile der Station fällt mit');
        $this->assertNull($this->wert('SELECT breeding_station FROM horses WHERE id = 100'));
        $this->assertSame('Cora Dritte', $this->wert('SELECT breeding_station FROM horses WHERE id = 101'), 'Merge-Rest: Kopie auf die verknüpfte Station synchronisiert');
        $this->assertSame('Gestüt Sonnenhof', $this->wert('SELECT breeding_station FROM horses WHERE id = 102'), 'Freitext ohne Verknüpfung bleibt');
        $this->assertNull($this->wert('SELECT breeding_station_text FROM horse_persons WHERE id = 500'));
        $this->assertNull($this->wert('SELECT breeding_station_text FROM horse_persons WHERE id = 501'), 'Altname neben anderer Station');
        $this->assertSame('Gestüt Musterstadt', $this->wert('SELECT breeding_station_text FROM horse_persons WHERE id = 502'));
        $this->assertSame('Gestüt Sonnenhof', $this->wert('SELECT breeding_station_text FROM horse_persons WHERE id = 503'), 'Freitext ohne Verknüpfung bleibt');
        $this->assertSame(0, (int)$this->wert("SELECT COUNT(*) FROM match_labels WHERE kind = 'contact'"));
        $this->assertSame('Deckstation ID 7: ' . AuditLogger::ENTFERNT, $this->details($eintrag));
        $this->assertSame(2, (int)$this->wert('SELECT COUNT(*) FROM persons_pre_contacts'), 'Unbeteiligte Altzeilen bleiben');
    }

    public function testAnonymisierenBehandeltAltzeileSchemagetrieben(): void {
        $this->bestand();
        $this->pdo->exec("INSERT INTO horses (id, name, breeding_station_id, breeding_station) VALUES (100, 'Stute', 1, 'Anna Geborene')");

        $this->pdo->beginTransaction();
        $ergebnis = KontaktDsgvo::anonymisieren($this->pdo, 1);
        $this->pdo->commit();

        $this->assertSame('Anna Beispiel', $ergebnis['vorher']['name']);
        $zeile = $this->pdo->query('SELECT * FROM persons_pre_contacts WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($zeile, 'Die Zeile und ihre id bleiben (Vertrag mit mitgliedsstatus, Audit N78)');
        $this->assertSame('Anonymisierte Person (#1)', $zeile['name']);
        foreach (['contact_info', 'street', 'email', 'phone', 'membership_status'] as $feld) {
            $this->assertNull($zeile[$feld], "{$feld} muss NULL sein");
        }
        $this->assertSame('', $zeile['kuerzel'], 'NOT-NULL-Textspalte wird leer');
        $this->assertSame(1, (int)$zeile['is_breeder'], 'Kennzeichen des Datensatzes bleibt');
        $this->assertSame('Anonymisierte Person (#1)', $this->wert('SELECT breeding_station FROM horses WHERE id = 100'));
        $this->assertSame('Mitglied', $this->wert('SELECT membership_status FROM persons_pre_contacts WHERE id = 3'), 'Unbeteiligte behalten ihren Status');
        $this->assertSame(1, MitgliedsstatusAltbestand::offen($this->pdo), 'Nur noch Cora zählt');
        $this->assertNull($this->wert('SELECT email FROM contacts WHERE id = 1'));
    }

    public function testAnonymisierenOhneKontaktLiefertNull(): void {
        $this->pdo->beginTransaction();
        $this->assertNull(KontaktDsgvo::anonymisieren($this->pdo, 999));
        $this->pdo->rollBack();
    }

    public function testFremdschluesselAufAltzeileFuehrtZurAnonymisierung(): void {
        $this->bestand();
        $this->pdo->exec(
            "CREATE TABLE addon_verweis (id INT PRIMARY KEY, person_id INT NOT NULL,
             FOREIGN KEY (person_id) REFERENCES persons_pre_contacts(id) ON DELETE RESTRICT) ENGINE=InnoDB"
        );
        $this->pdo->exec('INSERT INTO addon_verweis VALUES (1, 1)');

        $this->pdo->beginTransaction();
        $ergebnis = KontaktDsgvo::loeschen($this->pdo, [1], KontaktDsgvo::DSGVO);
        $this->pdo->commit();

        $this->assertSame([1], array_keys($ergebnis['kontakte']));
        $this->assertSame(0, (int)$this->wert('SELECT COUNT(*) FROM contacts WHERE id = 1'), 'Der Kontakt ist trotzdem gelöscht');
        $this->assertSame('Anonymisierte Person (#1)', $this->wert('SELECT name FROM persons_pre_contacts WHERE id = 1'));
        $this->assertNull($this->wert('SELECT email FROM persons_pre_contacts WHERE id = 1'));
    }

    public function testOhneAlttabellenLaeuftAllesOhneFehler(): void {
        $this->pdo->exec("INSERT INTO contacts (id, name) VALUES (5, 'Fünf Person'), (6, 'Sechs Person')");

        $this->pdo->beginTransaction();
        $this->assertNotNull(KontaktDsgvo::anonymisieren($this->pdo, 5));
        $ergebnis = KontaktDsgvo::loeschen($this->pdo, [6], KontaktDsgvo::DSGVO);
        $this->pdo->commit();

        $this->assertSame(0, $ergebnis['bereinigt']['altkopien']);
        $this->assertSame([], KontaktDsgvo::altkopien($this->pdo, 5));
        $this->assertSame([5 => false], KontaktDsgvo::hatAltkopie($this->pdo, [5]));
        $z = KontaktDsgvo::nachholen($this->pdo);
        $this->assertSame(0, $z['verwaist']);
    }

    public function testPapierkorbLoeschtNurWasImPapierkorbLiegt(): void {
        $this->pdo->exec("INSERT INTO contacts (id, name, deleted_at) VALUES
            (5, 'Aktiv', NULL), (6, 'Im Papierkorb', NOW()), (7, 'Frisch im Papierkorb', NOW())");
        $this->pdo->exec("UPDATE contacts SET deleted_at = NOW() - INTERVAL 40 DAY WHERE id = 6");

        $this->pdo->beginTransaction();
        $ergebnis = KontaktDsgvo::loeschen($this->pdo, [5, 6, 7], KontaktDsgvo::PAPIERKORB, 30);
        $this->pdo->commit();

        $this->assertSame([6], array_keys($ergebnis['kontakte']), 'Guard und Frist gelten unter Sperre');
        $this->assertSame(2, (int)$this->wert('SELECT COUNT(*) FROM contacts WHERE id IN (5, 7)'));

        // Admin-Pfad ohne Frist: ein (wieder) aktiver Kontakt bleibt trotzdem.
        $this->pdo->beginTransaction();
        $ergebnis = KontaktDsgvo::loeschen($this->pdo, [5, 7], KontaktDsgvo::PAPIERKORB);
        $this->pdo->commit();
        $this->assertSame([7], array_keys($ergebnis['kontakte']));
        $this->assertSame(1, (int)$this->wert('SELECT COUNT(*) FROM contacts WHERE id = 5'));
    }

    public function testPapierkorbLaesstAltzeileEinesUeberlebendenKontaktsStehen(): void {
        $this->bestand();
        // Person 1 wurde in Kontakt 3 zusammengeführt: die Karte hängt an 3,
        // Kontakt 1 liegt im Papierkorb. Wird 3 gelöscht, hängt die Altzeile
        // 1 ID-treu weiter am lebenden Kontakt 1.
        $this->pdo->exec("UPDATE contact_id_map SET contact_id = 3 WHERE contact_id = 1");
        $this->pdo->exec("UPDATE contacts SET deleted_at = NOW() WHERE id IN (1, 3)");

        $this->pdo->beginTransaction();
        KontaktDsgvo::loeschen($this->pdo, [3], KontaktDsgvo::PAPIERKORB);
        $this->pdo->commit();

        $this->assertSame('Mitglied', $this->wert('SELECT membership_status FROM persons_pre_contacts WHERE id = 1'));
        $this->assertSame(0, (int)$this->wert('SELECT COUNT(*) FROM persons_pre_contacts WHERE id = 3'), 'Altzeile 3 hat keinen Kontakt mehr');
    }

    public function testPseudonymisierungTrifftNurIdVerankerteEintraege(): void {
        $name = 'Erika Einzig';
        $e = [
            'angelegt' => $this->protokoll('contacts', "Kontakt ID 5: {$name}"),
            'umbenannt' => $this->protokoll('contacts', 'Kontakt ID 5: Erika Früher'),
            'fremd55' => $this->protokoll('contacts', 'Kontakt ID 55: Anderer Mensch'),
            'quelle' => $this->protokoll('contacts', "Quelle ID 5 ({$name}) -> Ziel ID 9 (Otto (Hof)): 1 Zuordnung(en) umgehängt"),
            'ziel' => $this->protokoll('contacts', "Quelle ID 8 (Otto (Hof)) -> Ziel ID 5 ({$name}): 2 Zuordnung(en) umgehängt"),
            'altperson' => $this->protokoll('persons', "Person ID 5: {$name}"),
            'altstation' => $this->protokoll('breeding_stations', "Deckstation ID 12: {$name} Hof"),
            'fremdstation' => $this->protokoll('breeding_stations', 'Deckstation ID 120: Nachbarhof'),
            'dublette' => $this->protokoll('matches', "contact 5/9: different (Schwester von {$name})"),
            'dublette2' => $this->protokoll('matches', 'contact 3/5: unclear (Notiz hinterlegt)'),
            'dublette55' => $this->protokoll('matches', 'contact 3/55: different (fremd)'),
            'pferd' => $this->protokoll('horses', "Pferd ID 3: {$name}"),
            'mail' => $this->protokoll('email', 'Empfänger: erika@example.com, Betreff: Hallo'),
        ];

        $namen = AuditLogger::kontaktNamenAusProtokoll($this->pdo, 5, [5], [12]);
        $this->assertEqualsCanonicalizing([$name, 'Erika Früher', "{$name} Hof"], array_values(array_unique($namen)));

        $anzahl = AuditLogger::kontaktPseudonymisieren($this->pdo, 5, [5], [12], [$name], ['erika@example.com']);

        $x = AuditLogger::ENTFERNT;
        $this->assertSame("Kontakt ID 5: {$x}", $this->details($e['angelegt']));
        $this->assertSame("Kontakt ID 5: {$x}", $this->details($e['umbenannt']));
        $this->assertSame('Kontakt ID 55: Anderer Mensch', $this->details($e['fremd55']));
        $this->assertSame("Quelle ID 5 ({$x}) -> Ziel ID 9 (Otto (Hof)): 1 Zuordnung(en) umgehängt", $this->details($e['quelle']));
        $this->assertSame("Quelle ID 8 (Otto (Hof)) -> Ziel ID 5 ({$x}): 2 Zuordnung(en) umgehängt", $this->details($e['ziel']));
        $this->assertSame("Person ID 5: {$x}", $this->details($e['altperson']));
        $this->assertSame("Deckstation ID 12: {$x}", $this->details($e['altstation']));
        $this->assertSame('Deckstation ID 120: Nachbarhof', $this->details($e['fremdstation']));
        $this->assertSame("contact 5/9: different ({$x})", $this->details($e['dublette']));
        $this->assertSame('contact 3/5: unclear (Notiz hinterlegt)', $this->details($e['dublette2']));
        $this->assertSame('contact 3/55: different (fremd)', $this->details($e['dublette55']));
        $this->assertSame("Pferd ID 3: {$name}", $this->details($e['pferd']), 'Andere Kategorien bleiben unberührt');
        $this->assertSame('Empfänger: ' . AuditLogger::ADRESSE . ', Betreff: Hallo', $this->details($e['mail']));
        $this->assertSame(8, $anzahl);

        $this->assertSame(0, AuditLogger::kontaktPseudonymisieren($this->pdo, 5, [5], [12], [$name], ['erika@example.com']), 'Idempotent');
    }

    public function testMailProtokollWirdMaskiert(): void {
        $code = $this->protokoll('email', 'Empfänger: max@verein.de, Betreff: Anmeldecode 483920 - Verband');
        $antwort = $this->protokoll('email', 'Empfänger: a@b.de, Antwort: 550 5.1.1 <a@b.de>: Recipient address rejected');
        $sauber = $this->protokoll('email', 'CR/LF in Empfänger- oder Absenderadresse');
        $andere = $this->protokoll('auth', 'Login max@verein.de');

        $this->assertSame(2, AuditLogger::emailProtokollMaskieren($this->pdo));
        $this->assertSame('Empfänger: [Adresse], Betreff: Anmeldecode ****** - Verband', $this->details($code));
        $this->assertSame('Empfänger: [Adresse], Antwort: 550 5.1.1 <[Adresse]>: Recipient address rejected', $this->details($antwort));
        $this->assertSame('CR/LF in Empfänger- oder Absenderadresse', $this->details($sauber));
        $this->assertSame('Login max@verein.de', $this->details($andere), 'Nur die Kategorie email');
        $this->assertSame(0, AuditLogger::emailProtokollMaskieren($this->pdo), 'Idempotent');
    }

    public function testUmbenannterKontaktVerliertAuchDenAltenNamenAmPferd(): void {
        $this->pdo->exec("INSERT INTO contacts (id, name) VALUES (5, 'Neuer Name'), (6, 'Andere Station')");
        $this->protokoll('contacts', 'Kontakt ID 5: Altname Hof');
        $this->pdo->exec("INSERT INTO horses (id, name) VALUES (100, 'Stute')");
        $this->pdo->exec("INSERT INTO horse_persons (id, horse_id, role, station_contact_id, breeding_station_text) VALUES
            (500, 100, 'keeper', 5, 'Altname Hof'), (501, 100, 'keeper', 6, 'Altname Hof')");

        $this->pdo->beginTransaction();
        KontaktDsgvo::anonymisieren($this->pdo, 5);
        $this->pdo->commit();

        $this->assertSame(0, (int)$this->wert("SELECT COUNT(*) FROM horse_persons WHERE breeding_station_text IS NOT NULL"));
        $this->assertSame(0, (int)$this->wert("SELECT COUNT(*) FROM audit_logs WHERE details LIKE '%Altname%'"));
    }
}
