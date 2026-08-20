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
    public const SCHEMA_VERSION = 12;

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
     * idempotenten und einzeln per try/catch abgesicherten - Schritte, und
     * der neue Stand wird erst NACH vollständigem Durchlauf persistiert:
     * Wirft ein Schritt doch einmal (oder fehlt settings noch, Setup-Fall),
     * bleibt der alte Stand stehen und der nächste Lauf versucht die
     * Migration erneut.
     *
     * Das Persistieren selbst wirft bei fehlender settings-Tabelle nach oben
     * (der Aufrufer soll erfahren, dass der Stand NICHT festgehalten wurde);
     * Database::ensureSchemaUpToDate() fängt das ab, weil die App im
     * Setup-Fall bewusst ohne persistierten Stand weiterlaufen können muss.
     *
     * @return string[] Durchgeführte Schritte in Ausführungsreihenfolge
     */
    public static function run(PDO $pdo): array {
        $current = self::storedVersion($pdo);
        if ($current >= self::SCHEMA_VERSION) {
            return []; // Normalfall: Schema aktuell, eine einzige Abfrage - fertig.
        }

        $performed = [];
        self::migrate($pdo, $performed);

        $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([(string)self::SCHEMA_VERSION]);
        $performed[] = sprintf('settings.schema_version auf %d gesetzt (vorher %d)', self::SCHEMA_VERSION, $current);

        return $performed;
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
     * @param PDO      $pdo       Aktive Datenbankverbindung
     * @param string[] $performed Sammelliste der durchgeführten Schritte
     */
    private static function migrate(PDO $pdo, array &$performed): void {
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

        // Tabellen, die es nach #336 nicht mehr geben darf.
        $abgeloest = ['persons', 'breeding_stations'];

        // Und dasselbe je Spalte: horse_persons trug die Verweise früher als
        // person_id/breeding_station_id, seit #336 als
        // contact_id/station_contact_id. Die alten Spalten legt Schritt 31f
        // still - ohne diese Liste ergänzte Schritt 4 sie beim nächsten
        // Minor-Sprung leer wieder, und dann stünde neben jedem echten
        // Verweis eine leere Altspalte, die aussieht, als fehlte die Zuordnung.
        $abgeloesteSpalten = ['horse_persons.person_id', 'horse_persons.breeding_station_id'];

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
        $createTable = function (string $table, string $createSql) use ($pdo, &$performed, $abgeloest) {
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
            } catch (\Throwable $e) {}
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
        $addColumn('users', 'totp_secret', 'VARCHAR(64) NULL AFTER `role`');
        $addColumn('users', 'totp_enabled', 'TINYINT(1) DEFAULT 0 AFTER `totp_secret`');
        $addColumn('users', 'backup_codes', 'TEXT NULL AFTER `totp_enabled`');
        $addColumn('users', 'passkeys', 'TEXT NULL AFTER `backup_codes`');

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
        // Fläche) seeden. Ausgelöst wird das genau einmal:
        //  (a) group_permissions wurde gerade NEU angelegt, ODER
        //  (b) die Tabelle existierte bereits, kennt aber noch keine 'view'-Zeile
        //      (Upgrade von einer früheren #66-Version ohne Leseberechtigung) - damit
        //      Editoren den Zugriff auf die Backend-Listen und Gäste den öffentlichen
        //      Katalog nicht verlieren.
        // Die einmalige Ausführung verhindert, dass eine spätere, bewusste Rechte-
        // Entziehung durch einen Admin bei jedem Lauf rückgängig gemacht wird
        // (siehe docs/user-groups-plan.md, 3.4/8). INSERT IGNORE macht das Seeden
        // zusätzlich idempotent gegenüber bereits vorhandenen Editor-Zeilen.
        $needsPermissionSeed = !$groupPermissionsExisted;
        if (!$needsPermissionSeed) {
            try {
                $needsPermissionSeed = (int)$pdo->query("SELECT COUNT(*) FROM `group_permissions` WHERE `action` = 'view'")->fetchColumn() === 0;
            } catch (\Throwable $e) {
                $needsPermissionSeed = false;
            }
        }

        if ($needsPermissionSeed) {
            try {
                $insertPermStmt = $pdo->prepare("INSERT IGNORE INTO `group_permissions` (`group_id`, `module`, `action`) VALUES (?, ?, ?)");

                $editorGroupId = $pdo->query("SELECT id FROM `groups` WHERE slug = 'editor'")->fetchColumn();
                if ($editorGroupId) {
                    $defaultEditorPermissions = [
                        ['horses', 'view'], ['horses', 'create'], ['horses', 'edit'], ['horses', 'delete'], ['horses', 'publish'],
                        ['persons', 'view'], ['persons', 'create'], ['persons', 'edit'], ['persons', 'delete'], ['persons', 'publish'],
                        ['breeding_stations', 'view'], ['breeding_stations', 'create'], ['breeding_stations', 'edit'], ['breeding_stations', 'delete'], ['breeding_stations', 'publish'],
                    ];
                    foreach ($defaultEditorPermissions as [$module, $action]) {
                        $insertPermStmt->execute([$editorGroupId, $module, $action]);
                    }
                }

                // Gast-Gruppe: ausschließlich die Lese-Rechte der heute öffentlich
                // sichtbaren Fläche. Bewusst nichts weiter - neue/Plugin-Bereiche
                // bleiben für Gäste fail-closed unsichtbar, bis ein Admin sie freischaltet.
                $publicGroupId = $pdo->query("SELECT id FROM `groups` WHERE slug = 'public'")->fetchColumn();
                if ($publicGroupId) {
                    foreach ([['horses', 'view'], ['breeding_stations', 'view']] as [$module, $action]) {
                        $insertPermStmt->execute([$publicGroupId, $module, $action]);
                    }
                }

                $performed[] = 'Standardrechte für die Gruppen editor/public geseedet (group_permissions)';
            } catch (\Throwable $e) {}
        }

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
        // öffentlich sind (Ort, Bundesland, Land, Mitgliedsstatus) plus die
        // dafür vorgesehene Website. Wer die Seite nicht möchte, nimmt der
        // Gruppe `public` das Recht wieder weg - diese Migration setzt es
        // dank INSERT IGNORE nicht erneut.
        //
        // Ab #336 heißt das Recht `contacts`.`view` und wird vom Seed in
        // database/schema.sql bzw. von Schritt 31e vergeben. Der Seed hier
        // darf dann NICHT mehr laufen: Er trüge sonst bei jedem künftigen
        // Minor-Sprung ein `persons`.`view` nach, das kein Modul mehr kennt -
        // eine Zeile, die in der Rechte-Matrix nirgends auftaucht und
        // trotzdem in der Datenbank steht.
        if (!$kontaktschemaAktiv) {
            try {
                $pdo->exec(
                    "INSERT IGNORE INTO `group_permissions` (`group_id`, `module`, `action`)
                     SELECT `id`, 'persons', 'view' FROM `groups` WHERE `slug` = 'public'"
                );
            } catch (\Throwable $e) {
                // Tabelle/Gruppe existiert im Setup-Fall noch nicht.
            }
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
        // Deshalb hier das fehlende Primitiv: $dataStep führt einen
        // Datenschritt genau einmal aus und hält das in settings fest.
        // Rückgabe null = "war nicht zuständig" (z. B. Setup-Fall), dann wird
        // NICHTS vermerkt und der nächste Lauf versucht es erneut.
        $dataStep = function (string $key, callable $arbeit) use ($pdo, &$performed): void {
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

            $ergebnis = $arbeit();
            if ($ergebnis === null) {
                return; // Nicht zuständig - kein Marker, damit ein späterer Lauf es erneut versucht.
            }

            $pdo->prepare(
                "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            )->execute([$markerKey, gmdate('c')]);

            foreach ((array)$ergebnis as $zeile) {
                $performed[] = $zeile;
            }
        };

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

        $tabelleExistiert = function (string $table) use ($pdo): bool {
            try {
                $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
                return $stmt && $stmt->rowCount() > 0;
            } catch (\Throwable $e) {
                return false;
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
            `membership_status` VARCHAR(100) NULL DEFAULT NULL,
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
        // DatabaseTest genau das prüft (er entfernt membership_status und
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
        $addColumn('contacts', 'membership_status', 'VARCHAR(100) NULL DEFAULT NULL AFTER `website`');
        $addColumn('contacts', 'is_breeder', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `membership_status`');
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
        $dataStep('336_contacts_uebernahme', function () use ($pdo, $tabelleExistiert): ?array {
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
            if ((int)$pdo->query("SELECT COUNT(*) FROM contacts")->fetchColumn() > 0) {
                // Zieltabelle ist nicht leer, der Marker fehlt aber. Das ist kein
                // Normalfall (etwa ein von Hand abgebrochener Lauf) - und genau
                // hier wäre blindes Kopieren die Verdopplung. Lieber nichts tun
                // und es sagen.
                throw new \RuntimeException(
                    'Migration #336: contacts enthält bereits Zeilen, der Übernahme-Marker fehlt aber. '
                    . 'Bitte den Stand von Hand prüfen (settings.migration_336_contacts_uebernahme).'
                );
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
                         country, email, phone, mobile, website, membership_status, is_breeder,
                         contact_public, is_published, created_at, updated_at, deleted_at)
                    SELECT id, name, contact_info, street, house_number, postal_code, city, state,
                           country, email, phone, mobile, website, membership_status, is_breeder,
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
            $doppelt = $pdo->query("
                SELECT c.name, COUNT(*) AS anzahl
                FROM contacts c
                WHERE c.deleted_at IS NULL
                GROUP BY LOWER(TRIM(c.name))
                HAVING COUNT(*) > 1
                ORDER BY c.name ASC
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

            return $meldungen;
        });

        // 31c. Verweise umhängen. Zwei Steckplätze, nicht einer - siehe den
        // Tabellenkommentar zu horse_persons in database/schema.sql.
        $addColumn('horse_persons', 'contact_id', 'INT NULL DEFAULT NULL AFTER `horse_id`');
        $addColumn('horse_persons', 'station_contact_id', 'INT NULL DEFAULT NULL AFTER `role`');

        $dataStep('336_horse_persons_umhaengen', function () use ($pdo, $tabelleExistiert): ?array {
            if (!$tabelleExistiert('horse_persons') || !$tabelleExistiert('contact_id_map')) {
                return null;
            }
            $spalten = $pdo->query("SHOW COLUMNS FROM `horse_persons`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('person_id', $spalten, true)) {
                return null; // Neuinstallation - die Altspalten gab es nie.
            }

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

            // Nachweis vor dem Löschen der Altspalten: Jede Zeile, die vorher
            // einen Verweis trug, muss ihn jetzt im neuen Steckplatz haben.
            // Sonst bricht der Schritt ab, der Marker bleibt ungesetzt und der
            // nächste Lauf versucht es erneut - statt Daten zu verlieren.
            $offen = (int)$pdo->query("
                SELECT COUNT(*) FROM horse_persons
                WHERE (person_id IS NOT NULL AND contact_id IS NULL)
                   OR (breeding_station_id IS NOT NULL AND station_contact_id IS NULL)
            ")->fetchColumn();
            if ($offen > 0) {
                throw new \RuntimeException(sprintf(
                    'Migration #336: %d horse_persons-Zeile(n) ohne Gegenstück in contact_id_map - '
                    . 'Altspalten werden NICHT entfernt.',
                    $offen
                ));
            }

            return [sprintf(
                'Kontaktliste (#336): %d Personen- und %d Stationsverweise in horse_persons umgehängt',
                $personen,
                $stationen
            )];
        });

        // 31d. horses.breeding_station_id zeigt jetzt auf contacts. Der
        // Spaltenname bleibt - die Aussage hat sich nicht geändert, nur die
        // Zieltabelle, und ein Umbenennen träfe jedes Addon, das den Spiegel
        // liest, ohne irgendetwas zu verbessern.
        $dataStep('336_horses_station_umhaengen', function () use ($pdo, $tabelleExistiert, $dropForeignKey): ?array {
            if (!$tabelleExistiert('horses') || !$tabelleExistiert('contact_id_map')) {
                return null;
            }
            if (!$tabelleExistiert('breeding_stations')) {
                return null; // Neuinstallation.
            }
            // Erst den alten Fremdschlüssel lösen, sonst scheitert das UPDATE an
            // ihm (die neuen IDs gibt es in breeding_stations nicht).
            $dropForeignKey('horses', 'breeding_station_id');
            $pdo->exec("
                UPDATE horses h
                JOIN contact_id_map m ON m.old_type = 'station' AND m.old_id = h.breeding_station_id
                SET h.breeding_station_id = m.contact_id
                WHERE h.breeding_station_id IS NOT NULL
            ");
            $anzahl = (int)$pdo->query(
                "SELECT COUNT(*) FROM horses WHERE breeding_station_id IS NOT NULL"
            )->fetchColumn();

            $verwaist = (int)$pdo->query("
                SELECT COUNT(*) FROM horses h
                LEFT JOIN contacts c ON c.id = h.breeding_station_id
                WHERE h.breeding_station_id IS NOT NULL AND c.id IS NULL
            ")->fetchColumn();
            if ($verwaist > 0) {
                // Nicht abbrechen: Ein verwaister Spiegel-Verweis ist im Bestand
                // real (hart gelöschte Station) und darf den Fremdschlüssel
                // nicht sprengen. Nullen und melden.
                $pdo->exec("
                    UPDATE horses h
                    LEFT JOIN contacts c ON c.id = h.breeding_station_id
                    SET h.breeding_station_id = NULL
                    WHERE h.breeding_station_id IS NOT NULL AND c.id IS NULL
                ");
            }

            return [sprintf(
                'Kontaktliste (#336): %d horses.breeding_station_id auf contacts umgehängt%s',
                $anzahl,
                $verwaist > 0 ? sprintf(' (%d verwaiste Verweise geleert)', $verwaist) : ''
            )];
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
        // sind aber noch da. Entfernt werden sie in v0.9.0.
        $dataStep('336_altbestand_stilllegen', function () use ($pdo, $tabelleExistiert, $dropForeignKey): ?array {
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
                return null;
            }

            $meldungen = [];
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
                    } catch (\Throwable $e) {}
                }
            }

            foreach ([['persons', 'persons_pre_contacts'], ['breeding_stations', 'breeding_stations_pre_contacts']] as [$alt, $neu]) {
                if ($tabelleExistiert($alt) && !$tabelleExistiert($neu)) {
                    $dropForeignKey($alt, 'id');
                    $pdo->exec("RENAME TABLE `{$alt}` TO `{$neu}`");
                    $meldungen[] = "Tabelle {$alt} nach {$neu} umbenannt (Rückweg für #336; entfällt in v0.9.0)";
                }
            }

            return $meldungen;
        });

        // 31g. Fremdschlüssel auf die neue Zieltabelle. Nach dem Umhängen und
        // erst, wenn keine verwaisten Verweise mehr übrig sind - sonst
        // scheitert das ALTER und der ganze Lauf bliebe stehen.
        $dataStep('336_fremdschluessel', function () use ($pdo, $tabelleExistiert): ?array {
            if (!$tabelleExistiert('contacts') || !$tabelleExistiert('horse_persons')) {
                return null;
            }
            $spalten = $pdo->query("SHOW COLUMNS FROM `horse_persons`")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('person_id', $spalten, true)) {
                return null; // Stilllegung lief noch nicht - später erneut versuchen.
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
        // einmal. Bleibt etwas liegen, wird KEIN Marker gesetzt: Der Rückfall
        // in MediaController liefert die Datei weiter, das harte
        // public/uploads/horses/.htaccess hält den statischen Weg zu, und der
        // nächste Migrationslauf nimmt sich den Rest vor.
        $dataStep('366_pferdefotos_aus_dem_webroot', function () use (&$performed): ?array {
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
                $performed[] = 'Pferdefotos (#366): public/uploads/horses ist nicht lesbar - Verschiebung übersprungen, wird erneut versucht';
                return null;
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
                $performed[] = 'Pferdefotos (#366): storage/horses lässt sich nicht anlegen - Verschiebung übersprungen, wird erneut versucht';
                return null;
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
                $performed[] = sprintf(
                    'Pferdefotos (#366): %d von %d Datei(en) nach storage/horses verschoben, %d liegen noch in '
                    . 'public/uploads/horses (Rechte prüfen). Sie werden weiter ausgeliefert und sind statisch '
                    . 'gesperrt; der nächste Migrationslauf versucht es erneut.',
                    $verschoben,
                    count($bilder),
                    $liegengeblieben
                );
                return null;
            }

            return [sprintf(
                'Pferdefotos (#366): %d Datei(en) aus dem Webroot nach storage/horses verschoben',
                $verschoben
            )];
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
        // (siehe AuthController::hashResetToken()). Bestehende Zeilen enthalten
        // noch Klartext-Token, die gegen den Abdruck nie treffen würden - sie
        // werden entfernt statt umgerechnet: Aus dem Klartext ließe sich der
        // Abdruck zwar bilden, aber die Zeilen sind höchstens 15 Minuten
        // gültig, und ein laufender Reset ist mit einem Klick neu angefordert.
        // Ein Vorrat gültiger Klartext-Token soll die Migration nicht
        // überleben.
        try {
            $offen = (int)$pdo->query("SELECT COUNT(*) FROM password_resets")->fetchColumn();
            if ($offen > 0) {
                $pdo->exec("DELETE FROM password_resets");
                $performed[] = "password_resets geleert ({$offen} offene Anforderung(en)) - Token liegen jetzt nur als Abdruck vor";
            }
        } catch (\Throwable $e) {
            // Tabelle existiert im Setup-Fall noch nicht - dann gibt es auch
            // nichts zu bereinigen.
        }
    }
}
