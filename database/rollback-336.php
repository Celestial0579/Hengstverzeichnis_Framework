<?php
// database/rollback-336.php
//
// Rückweg der Kontaktlisten-Migration (#336): contacts -> persons +
// breeding_stations.
//
// WOZU ÜBERHAUPT. #336 fasst jeden Kontakt und jede Zuordnung an. Eine
// Migration dieser Größe ohne beschriebenen und einmal gegangenen Rückweg ist
// eine Wette. Dieses Skript ist der Rückweg - es ist einmal gegen eine Kopie
// gelaufen, bevor v0.8.0 freigegeben wurde.
//
// WAS ES BRAUCHT. Die Migration legt die Alttabellen nicht still, sondern
// benennt sie um (persons_pre_contacts, breeding_stations_pre_contacts),
// hält die ID-Zuordnung in contact_id_map und die alten Rechtezeilen als JSON
// in settings.migration_336_rechte_vorher. Fehlt eines davon, bricht das
// Skript ab statt zu raten.
//
// WAS ES KOSTET. Kontakte, die NACH der Migration angelegt wurden, gibt es in
// den Alttabellen nicht - sie gehen verloren. Das Skript zählt sie vorher und
// verlangt eine ausdrückliche Bestätigung.
//
// Dasselbe gilt für Zuordnungen "über Kreuz" (Audit N40): Seit #336 kann
// jeder Kontakt in jedem Steckplatz stehen - eine frühere Station als
// Besitzer, eine Person als Deckstation. v0.7 hat dafür keinen Platz. Solche
// Zuordnungen werden vorab gezählt; ohne --zuordnungen-verwerfen bricht das
// Skript ab. Eine Rettung als Freitext (breeding_station_text,
// horses.breeding_station) gibt es bewusst nicht: Beide Felder sind in v0.7
// öffentlich, ein womöglich unveröffentlichter Kontakt stünde dann dort.
//
// VORBEDINGUNG: Keine andere Tabelle zeigt per Fremdschlüssel auf contacts
// (etwa die des Addons mitgliedsstatus). Sonst scheiterte Schritt 6 an
// DROP TABLE contacts, nachdem contact_id und contact_id_map schon weg sind -
// ein Zustand, aus dem kein erneuter Aufruf herausführt. Das Skript prüft das
// vorab; das Addon vorher deinstallieren.
//
// WARTUNGSMODUS (Audit N39). Vor dem ersten Schritt setzt das Skript
// var/wartung.lock ohne Prozesskennung und mit Migrationssperre - und LÄSST
// IHN STEHEN, nach Erfolg wie nach einem Fehler. Ohne ihn machte der nächste
// Request den Rückweg sofort rückgängig (schema_version 9 -> Migration
// läuft). Der Betreiber spielt die alte Version ein und löscht danach die
// Datei. Eine Sperre in settings wäre die Alternative gewesen, aber die alte
// Version kennt sie nicht und könnte sie nie aufheben - ein späteres
// erneutes Update bliebe still blockiert.
//
//     php database/rollback-336.php            # nur prüfen und berichten
//     php database/rollback-336.php --ich-weiss # tatsächlich zurückrollen
//     php database/rollback-336.php --ich-weiss --zuordnungen-verwerfen
//                                               # auch, wenn Zuordnungen ohne
//                                               # Rückschreibziel verloren gehen

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Dieses Skript darf nur über die CLI ausgeführt werden.');
}

require_once __DIR__ . '/cli-autoload.php';
require_once __DIR__ . '/../config/config.php';

use App\Service\Maintenance;

$ernst = in_array('--ich-weiss', $argv, true);
$verwerfen = in_array('--zuordnungen-verwerfen', $argv, true);

// DSN aus demselben Helfer wie die Anwendung (Audit N55).
$dsn = \App\Database::buildDsn((string)DB_HOST, (string)(defined('DB_PORT') ? DB_PORT : '3306'), (string)DB_NAME);
$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$hatTabelle = static function (string $t) use ($pdo): bool {
    return (bool)$pdo->query('SHOW TABLES LIKE ' . $pdo->quote($t))->rowCount();
};
$hatSpalte = static function (string $t, string $s) use ($pdo): bool {
    try {
        return (bool)$pdo->query("SHOW COLUMNS FROM `{$t}` LIKE " . $pdo->quote($s))->rowCount();
    } catch (\Throwable $e) {
        return false;
    }
};
$einstellung = static function (string $k) use ($pdo) {
    $s = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? null : $v;
};

echo "===============================================\n";
echo " Rückweg #336: Kontaktliste -> Personen/Stationen\n";
echo "===============================================\n";

$fehlt = [];
foreach (['contacts', 'contact_id_map', 'persons_pre_contacts', 'breeding_stations_pre_contacts'] as $t) {
    if (!$hatTabelle($t)) {
        $fehlt[] = $t;
    }
}
if ($einstellung('migration_336_rechte_vorher') === null) {
    $fehlt[] = 'settings.migration_336_rechte_vorher';
}
if ($fehlt) {
    fwrite(STDERR, "[ABBRUCH] Es fehlt: " . implode(', ', $fehlt) . "\n"
        . "Ohne diese Bestandteile lässt sich der Stand vor #336 nicht wiederherstellen.\n");
    exit(1);
}

// Zwischenzustand nach Schritt 6 (Spalten schon entfernt): Die Zählungen
// unten liefen sonst als ungefangene PDOException ins Leere.
$fehltSpalte = [];
foreach (['contact_id', 'station_contact_id'] as $s) {
    if (!$hatSpalte('horse_persons', $s)) {
        $fehltSpalte[] = "horse_persons.{$s}";
    }
}
if ($fehltSpalte) {
    fwrite(STDERR, "[ABBRUCH] Es fehlt: " . implode(', ', $fehltSpalte) . "\n"
        . "Zwischenzustand nach Schritt 6 - von Hand prüfen.\n");
    exit(1);
}

$neuAngelegt = (int)$pdo->query(
    "SELECT COUNT(*) FROM contacts c
     LEFT JOIN contact_id_map m ON m.contact_id = c.id
     WHERE m.contact_id IS NULL"
)->fetchColumn();

printf("Kontakte gesamt:            %d\n", (int)$pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn());
printf("davon aus der Migration:    %d\n", (int)$pdo->query('SELECT COUNT(*) FROM contact_id_map')->fetchColumn());
printf("davon NACH der Migration:   %d  <- gehen verloren\n", $neuAngelegt);

// Zuordnungen ohne Rückschreibziel (Audit N40): Der Rückweg schreibt
// person_id nur aus Kontakten mit Personenherkunft zurück und
// breeding_station_id nur aus solchen mit Stationsherkunft. Alles andere -
// nach der Migration angelegte Kontakte, Zuordnungen über Kreuz - wird leer.
// Schritt 3 lief schon (Marker aus einem abgebrochenen Vorlauf)? Dann stehen
// am Pferd bereits Alt-IDs, und die Zählung c) hätte keine Aussage.
$pferdeSchonZurueck = $einstellung('migration_336_rueckweg_pferde') !== null;
$ohneZiel = [
    'a' => "FROM horse_persons hp
            LEFT JOIN contact_id_map m ON m.contact_id = hp.contact_id AND m.old_type = 'person'
            WHERE hp.contact_id IS NOT NULL AND m.contact_id IS NULL",
    'b' => "FROM horse_persons hp
            LEFT JOIN contact_id_map m ON m.contact_id = hp.station_contact_id AND m.old_type = 'station'
            WHERE hp.station_contact_id IS NOT NULL AND m.contact_id IS NULL",
    'c' => "FROM horses hp
            LEFT JOIN contact_id_map m ON m.contact_id = hp.breeding_station_id AND m.old_type = 'station'
            WHERE hp.breeding_station_id IS NOT NULL AND m.contact_id IS NULL",
];
$verlust = ['a' => 0, 'b' => 0, 'c' => 0];
$beispiele = [];
foreach ($ohneZiel as $art => $sql) {
    if ($art === 'c' && $pferdeSchonZurueck) {
        continue;
    }
    $verlust[$art] = (int)$pdo->query("SELECT COUNT(*) {$sql}")->fetchColumn();
    $spalte = ['a' => 'contact_id', 'b' => 'station_contact_id', 'c' => 'breeding_station_id'][$art];
    $pferd = $art === 'c' ? 'hp.id' : 'hp.horse_id';
    foreach ($pdo->query("SELECT hp.id, {$pferd} AS pferd, hp.{$spalte} AS kontakt {$sql} ORDER BY hp.id LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) as $z) {
        if (count($beispiele) >= 10) {
            break;
        }
        $beispiele[] = match ($art) {
            'a' => sprintf('Zuordnung #%d (Pferd #%d): Kontakt #%d als Person', (int)$z['id'], (int)$z['pferd'], (int)$z['kontakt']),
            'b' => sprintf('Zuordnung #%d (Pferd #%d): Kontakt #%d als Deckstation', (int)$z['id'], (int)$z['pferd'], (int)$z['kontakt']),
            default => sprintf('Pferd #%d: Kontakt #%d als Deckstation', (int)$z['pferd'], (int)$z['kontakt']),
        };
    }
}
$verlustSumme = array_sum($verlust);
printf(
    "Zuordnungen ohne Rückschreibziel: %d/%d/%d  <- gehen verloren\n"
    . "  (horse_persons als Person / horse_persons als Deckstation / horses.breeding_station_id%s)\n",
    $verlust['a'],
    $verlust['b'],
    $verlust['c'],
    $pferdeSchonZurueck ? ', schon zurückgerechnet' : ''
);
foreach ($beispiele as $b) {
    echo "  - {$b}\n";
}

// Fremde Fremdschlüssel auf contacts - siehe Kopfkommentar.
$fremd = $pdo->query(
    "SELECT DISTINCT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'contacts'
       AND TABLE_NAME NOT IN ('horse_persons', 'horses', 'contact_id_map')
     ORDER BY TABLE_NAME"
)->fetchAll(PDO::FETCH_COLUMN);
if ($fremd) {
    fwrite(STDERR, "[ABBRUCH] Diese Tabellen zeigen per Fremdschlüssel auf contacts: " . implode(', ', $fremd) . "\n"
        . "Der Rückweg könnte contacts sonst nicht entfernen und bliebe in einem nicht fortsetzbaren\n"
        . "Zwischenzustand stehen. Das zugehörige Addon (etwa mitgliedsstatus) vorher deinstallieren\n"
        . "oder die Tabelle sichern und entfernen. Es wurde nichts geändert.\n");
    exit(1);
}

if (!$ernst) {
    echo "\nNur geprüft. Zum tatsächlichen Zurückrollen: --ich-weiss\n";
    exit(0);
}

if ($verlustSumme > 0 && !$verwerfen) {
    fwrite(STDERR, "[ABBRUCH] {$verlustSumme} Zuordnung(en) ohne Rückschreibziel gingen verloren.\n"
        . "Wer das in Kauf nimmt: zusätzlich --zuordnungen-verwerfen angeben. Es wurde nichts geändert.\n");
    exit(1);
}

// Ab hier wird geändert - vorher die Sperre. Scheitert sie, ist noch nichts
// passiert.
try {
    Maintenance::enable('Rückweg #336 (database/rollback-336.php)', true);
} catch (\Throwable $e) {
    fwrite(STDERR, '[ABBRUCH] Wartungsmodus lässt sich nicht setzen: ' . $e->getMessage() . "\n"
        . "Es wurde nichts geändert.\n");
    exit(1);
}

$dropFk = static function (string $tabelle, string $spalte) use ($pdo): void {
    $s = $pdo->prepare(
        "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
           AND REFERENCED_TABLE_NAME IS NOT NULL"
    );
    $s->execute([$tabelle, $spalte]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $pdo->exec("ALTER TABLE `{$tabelle}` DROP FOREIGN KEY `{$name}`");
    }
};

// REIHENFOLGE IST DER SCHUTZ, NICHT EINE TRANSAKTION.
//
// MariaDB löst bei jedem DDL (ALTER/DROP/RENAME) ein implizites COMMIT aus -
// eine Klammer um Schema- und Datenänderungen gibt es schlicht nicht. Der
// erste Entwurf dieses Skripts hatte sie trotzdem und endete mit
// "There is no active transaction", nachdem die Hälfte schon geschrieben war.
//
// Stattdessen: erst alles Aufbauende (Spalten anlegen, Daten zurückschreiben,
// Rechte wiederherstellen), dann NACHWEISEN, dass nichts fehlt - und erst
// danach das Zerstörende (contacts löschen). Scheitert es davor, steht die
// Datenbank in einem Zwischenzustand, aus dem heraus ein erneuter Aufruf
// weitermachen kann, weil jeder Schritt für sich idempotent ist.
try {
    // 1. Fremdschlüssel auf contacts lösen.
    $dropFk('horse_persons', 'contact_id');
    $dropFk('horse_persons', 'station_contact_id');
    $dropFk('horses', 'breeding_station_id');

    // 2. Altspalten zurück und aus der Zuordnungstabelle füllen.
    $spalten = $pdo->query('SHOW COLUMNS FROM `horse_persons`')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('person_id', $spalten, true)) {
        $pdo->exec('ALTER TABLE `horse_persons` ADD COLUMN `person_id` INT NULL DEFAULT NULL AFTER `horse_id`');
    }
    if (!in_array('breeding_station_id', $spalten, true)) {
        $pdo->exec('ALTER TABLE `horse_persons` ADD COLUMN `breeding_station_id` INT NULL AFTER `role`');
    }
    // Ab hier reine Datenänderungen - die dürfen und sollen in einer Klammer
    // laufen, damit die Rechte nicht halb zurückgeschrieben liegen bleiben.
    $pdo->beginTransaction();
    $pdo->exec("UPDATE horse_persons hp
                JOIN contact_id_map m ON m.contact_id = hp.contact_id AND m.old_type = 'person'
                SET hp.person_id = m.old_id");
    $pdo->exec("UPDATE horse_persons hp
                JOIN contact_id_map m ON m.contact_id = hp.station_contact_id AND m.old_type = 'station'
                SET hp.breeding_station_id = m.old_id");

    // 3. Spiegel am Pferd zurückrechnen - genau EINMAL (Audit N40). Das
    //    Umrechnen ist nicht idempotent: Ein zweiter Aufruf läse die schon
    //    zurückgerechneten Alt-IDs als Kontakt-IDs. Deshalb hält der Marker
    //    migration_336_rueckweg_pferde in derselben Transaktion fest, dass es
    //    geschehen ist; Schritt 7 räumt ihn über das LIKE mit ab.
    //
    // REIHENFOLGE: erst die Verweise ohne Gegenstück leeren, DANN umrechnen.
    // Andersherum sieht die Aufräum-Anweisung die bereits zurückgerechneten
    // Alt-IDs und findet sie - erwartungsgemäß - nicht mehr in contact_id_map,
    // weil dort die NEUEN IDs stehen. Sie leerte damit genau die Zeilen, die
    // der Schritt davor korrekt gesetzt hatte. Im Probelauf kamen so zwei
    // Pferde ohne Deckstation zurück.
    if ($einstellung('migration_336_rueckweg_pferde') === null) {
        $pdo->exec("UPDATE horses h
                    LEFT JOIN contact_id_map m ON m.contact_id = h.breeding_station_id AND m.old_type = 'station'
                    SET h.breeding_station_id = NULL
                    WHERE h.breeding_station_id IS NOT NULL AND m.old_id IS NULL");
        $pdo->exec("UPDATE horses h
                    JOIN contact_id_map m ON m.contact_id = h.breeding_station_id AND m.old_type = 'station'
                    SET h.breeding_station_id = m.old_id");
        $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('migration_336_rueckweg_pferde', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([gmdate('c')]);
    }

    // 4. Rechte aus dem Archiv zurück - noch in derselben Klammer.
    $pdo->exec("DELETE FROM group_permissions WHERE module = 'contacts'");
    $rechte = json_decode((string)$einstellung('migration_336_rechte_vorher'), true) ?: [];
    $einfuegen = $pdo->prepare(
        'INSERT IGNORE INTO group_permissions (group_id, module, action) VALUES (?, ?, ?)'
    );
    foreach ($rechte as $r) {
        $einfuegen->execute([$r['group_id'], $r['module'], $r['action']]);
    }
    $pdo->commit();

    // 5. NACHWEIS vor dem Zerstören - über ALLE Zeilen, ohne Blick auf die
    //    Herkunft (Audit N40). Früher prüfte der Nachweis nur dieselben
    //    Typpaare wie das Zurückschreiben und sah deshalb genau die
    //    Zuordnungen nicht, die verloren gingen. Mehr leere Altverweise als
    //    vorab gezählt und bestätigt heißt: Es kam etwas hinzu (etwa eine
    //    Zuordnung zwischen Vorprüfung und Wartungsmodus) - dann wird
    //    contacts NICHT gelöscht.
    $offen = (int)$pdo->query(
        "SELECT COUNT(*) FROM horse_persons WHERE contact_id IS NOT NULL AND person_id IS NULL"
    )->fetchColumn();
    $offen += (int)$pdo->query(
        "SELECT COUNT(*) FROM horse_persons WHERE station_contact_id IS NOT NULL AND breeding_station_id IS NULL"
    )->fetchColumn();
    $bestaetigt = $verlust['a'] + $verlust['b'];
    if ($offen > $bestaetigt) {
        throw new \RuntimeException(sprintf(
            '%d Zuordnung(en) ohne zurückgeschriebenen Altverweis, bestätigt waren %d - contacts bleibt stehen.',
            $offen,
            $bestaetigt
        ));
    }

    // 6. Erst jetzt das Zerstörende.
    $pdo->exec('ALTER TABLE `horse_persons` DROP COLUMN `contact_id`, DROP COLUMN `station_contact_id`');
    $pdo->exec('DROP TABLE `contact_id_map`');
    $pdo->exec('DROP TABLE `contacts`');
    $pdo->exec('RENAME TABLE `persons_pre_contacts` TO `persons`');
    $pdo->exec('RENAME TABLE `breeding_stations_pre_contacts` TO `breeding_stations`');
    $pdo->exec('ALTER TABLE `horse_persons` ADD FOREIGN KEY (`person_id`) REFERENCES `persons`(`id`) ON DELETE CASCADE');
    $pdo->exec('ALTER TABLE `horses` ADD FOREIGN KEY (`breeding_station_id`) REFERENCES `breeding_stations`(`id`) ON DELETE SET NULL');

    // 7. Marker und Schema-Stand zurücksetzen, damit ein erneuter
    //    Migrationslauf die Übernahme wieder ausführt.
    $pdo->exec("DELETE FROM settings WHERE setting_key LIKE 'migration\\_336\\_%'");
    $pdo->exec("DELETE FROM settings WHERE setting_key = 'schema_migration_status'");
    $pdo->prepare("UPDATE settings SET setting_value = '9' WHERE setting_key = 'schema_version'")->execute();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[FEHLER] ' . $e->getMessage() . "\n"
        . "Die Datenbank steht in einem Zwischenzustand. Jeder Schritt ist idempotent -\n"
        . "ein erneuter Aufruf macht dort weiter, wo es abgebrochen ist.\n"
        . "Der Wartungsmodus (var/wartung.lock) bleibt AKTIV.\n");
    exit(1);
}

printf("[OK] Zurückgerollt: %d Person(en), %d Deckstation(en), %d Rechtezeile(n).\n",
    (int)$pdo->query('SELECT COUNT(*) FROM persons')->fetchColumn(),
    (int)$pdo->query('SELECT COUNT(*) FROM breeding_stations')->fetchColumn(),
    (int)$pdo->query("SELECT COUNT(*) FROM group_permissions WHERE module IN ('persons','breeding_stations')")->fetchColumn()
);
if ($verlustSumme > 0) {
    printf("Verworfene Zuordnungen ohne Rückschreibziel: %d.\n", $verlustSumme);
}
echo "schema_version steht wieder auf 9.\n\n";
echo "Wartungsmodus bleibt AKTIV (var/wartung.lock).\n"
    . "  1. Alte Version (v0.7.x) einspielen - von Hand, der Web-Updater ist gesperrt.\n"
    . "  2. Danach var/wartung.lock löschen.\n"
    . "Wer auf dieser Fassung bleiben will: var/wartung.lock löschen - der nächste Request führt #336 erneut aus.\n";
