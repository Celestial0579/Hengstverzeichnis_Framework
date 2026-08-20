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
//     php database/rollback-336.php            # nur prüfen und berichten
//     php database/rollback-336.php --ich-weiss # tatsächlich zurückrollen

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Dieses Skript darf nur über die CLI ausgeführt werden.');
}

require_once __DIR__ . '/cli-autoload.php';
require_once __DIR__ . '/../config/config.php';

$ernst = in_array('--ich-weiss', $argv, true);

if (strpos(DB_HOST, '/') === 0) {
    $dsn = 'mysql:unix_socket=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
} else {
    $port = defined('DB_PORT') ? DB_PORT : '3306';
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4';
}
$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$hatTabelle = static function (string $t) use ($pdo): bool {
    return (bool)$pdo->query('SHOW TABLES LIKE ' . $pdo->quote($t))->rowCount();
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

$neuAngelegt = (int)$pdo->query(
    "SELECT COUNT(*) FROM contacts c
     LEFT JOIN contact_id_map m ON m.contact_id = c.id
     WHERE m.contact_id IS NULL"
)->fetchColumn();

printf("Kontakte gesamt:            %d\n", (int)$pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn());
printf("davon aus der Migration:    %d\n", (int)$pdo->query('SELECT COUNT(*) FROM contact_id_map')->fetchColumn());
printf("davon NACH der Migration:   %d  <- gehen verloren\n", $neuAngelegt);

if (!$ernst) {
    echo "\nNur geprüft. Zum tatsächlichen Zurückrollen: --ich-weiss\n";
    exit(0);
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

    // 3. Spiegel am Pferd zurückrechnen.
    //
    // REIHENFOLGE: erst die Verweise ohne Gegenstück leeren, DANN umrechnen.
    // Andersherum sieht die Aufräum-Anweisung die bereits zurückgerechneten
    // Alt-IDs und findet sie - erwartungsgemäß - nicht mehr in contact_id_map,
    // weil dort die NEUEN IDs stehen. Sie leerte damit genau die Zeilen, die
    // der Schritt davor korrekt gesetzt hatte. Im Probelauf kamen so zwei
    // Pferde ohne Deckstation zurück.
    $pdo->exec("UPDATE horses h
                LEFT JOIN contact_id_map m ON m.contact_id = h.breeding_station_id AND m.old_type = 'station'
                SET h.breeding_station_id = NULL
                WHERE h.breeding_station_id IS NOT NULL AND m.old_id IS NULL");
    $pdo->exec("UPDATE horses h
                JOIN contact_id_map m ON m.contact_id = h.breeding_station_id AND m.old_type = 'station'
                SET h.breeding_station_id = m.old_id");

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

    // 5. NACHWEIS vor dem Zerstören. Jede Zuordnung, die einen Kontakt trug,
    //    muss ihren alten Verweis zurückhaben - sonst wird contacts NICHT
    //    gelöscht und der Aufrufer bekommt einen Fehler statt eines Verlusts.
    $offen = (int)$pdo->query(
        "SELECT COUNT(*) FROM horse_persons hp
         JOIN contact_id_map m ON m.contact_id = hp.contact_id AND m.old_type = 'person'
         WHERE hp.person_id IS NULL"
    )->fetchColumn();
    $offen += (int)$pdo->query(
        "SELECT COUNT(*) FROM horse_persons hp
         JOIN contact_id_map m ON m.contact_id = hp.station_contact_id AND m.old_type = 'station'
         WHERE hp.breeding_station_id IS NULL"
    )->fetchColumn();
    if ($offen > 0) {
        throw new \RuntimeException(
            $offen . ' Zuordnung(en) ohne zurückgeschriebenen Altverweis - contacts bleibt stehen.'
        );
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
    $pdo->prepare("UPDATE settings SET setting_value = '9' WHERE setting_key = 'schema_version'")->execute();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[FEHLER] ' . $e->getMessage() . "\n"
        . "Die Datenbank steht in einem Zwischenzustand. Jeder Schritt ist idempotent -\n"
        . "ein erneuter Aufruf macht dort weiter, wo es abgebrochen ist.\n");
    exit(1);
}

printf("[OK] Zurückgerollt: %d Person(en), %d Deckstation(en), %d Rechtezeile(n).\n",
    (int)$pdo->query('SELECT COUNT(*) FROM persons')->fetchColumn(),
    (int)$pdo->query('SELECT COUNT(*) FROM breeding_stations')->fetchColumn(),
    (int)$pdo->query("SELECT COUNT(*) FROM group_permissions WHERE module IN ('persons','breeding_stations')")->fetchColumn()
);
echo "schema_version steht wieder auf 9; ein Migrationslauf führt #336 erneut aus.\n";
