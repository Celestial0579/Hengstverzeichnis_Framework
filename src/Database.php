<?php
// src/Database.php

namespace App;

use PDO;
use PDOException;

// Die CLI-Skripte unter database/ (seed.php, reset.php) laden diese Datei per
// require_once OHNE Autoloader - der Verbindungsaufbau unten delegiert die
// Schema-Migration aber an App\Service\SchemaMigrator (#230). Damit die Klasse
// dort garantiert verfügbar ist, wird sie hier explizit mitgeladen; unter dem
// Autoloader der Web-App bzw. von Composer ist das require_once ein No-Op.
require_once __DIR__ . '/Service/SchemaMigrator.php';

/**
 * Class Database
 *
 * Verwalte die PDO-Verbindung zur MySQL/MariaDB Datenbank im Singleton-Muster.
 * Gewährleistet, dass während der gesamten Anfrage-Laufzeit nur eine einzige
 * Datenbank-Verbindung aufgebaut wird, injiziert SSL/TLS-Optionen und prüft
 * automatisch beim Verbindungsaufbau, ob alle Tabellen & Spalten vorhanden sind.
 * Die Prüfung ist über settings.schema_version versioniert (#213): Ist der
 * persistierte Stand gleich SCHEMA_VERSION, kostet sie nur eine einzige Abfrage -
 * die eigentlichen Migrationsschritte laufen ausschließlich nach einem Update
 * mit erhöhter SCHEMA_VERSION (bzw. beim Setup) genau einmal. Die Schritte
 * selbst leben seit #230 in App\Service\SchemaMigrator, damit Restore-/
 * Import-Wege sie auch explizit (ohne shell_exec) anstoßen können.
 */
class Database {
    /**
     * Kompatibilitäts-Alias: Konstante und Migrationsschritte leben seit #230
     * in App\Service\SchemaMigrator (siehe dortige DISZIPLIN-Regel - jede
     * Schemaänderung erhöht die Version). Bestehende Aufrufer erreichen den
     * Stand weiterhin über Database::SCHEMA_VERSION.
     */
    public const SCHEMA_VERSION = Service\SchemaMigrator::SCHEMA_VERSION;

    /**
     * Statische Instanz des PDO-Datenbankverbindungsobjekts.
     * @var PDO|null
     */
    private static ?PDO $instance = null;

    /**
     * Privater Konstruktor zur Verhinderung direkter Instanziierung (Singleton-Pattern).
     */
    private function __construct() {}

    /**
     * Privater Klon-Konstruktor zur Verhinderung von Duplizierung.
     */
    private function __clone() {}

    /**
     * Liefert die zentrale PDO-Datenbankinstanz zurück oder baut diese bei Erstaufruf auf.
     *
     * @return PDO Aktive PDO-Verbindung
     * @throws PDOException Falls im Entwicklungsmodus ein Verbindungsfehler auftritt
     */
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $host = DB_HOST;
            $port = defined('DB_PORT') ? DB_PORT : '3306';
            $db   = DB_NAME;
            $user = DB_USER;
            $pass = DB_PASS;
            $charset = 'utf8mb4';

            if (strpos($host, '/') === 0) {
                $dsn = "mysql:unix_socket=$host;dbname=$db;charset=$charset";
            } else {
                $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
            }
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Löst Exceptions bei Fehlern aus
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Standard-Fetch: Assoziatives Array
                PDO::ATTR_EMULATE_PREPARES   => false,                  // Verwendet echte vorbereitete Statements
            ];

            // SSL/TLS-Verschlüsselung aktivieren, falls in den Einstellungen hinterlegt
            if (defined('DB_SSL') && DB_SSL) {
                if (defined('PDO::MYSQL_ATTR_SSL_CA') && defined('DB_SSL_CA') && !empty(DB_SSL_CA)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
                }
                if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (defined('DB_SSL_VERIFY') && DB_SSL_VERIFY);
                }
            }

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);

                self::alignSessionTimeZone(self::$instance);

                // Datenbank-Schema bei Verbindungsaufbau automatisch auf den neuesten Stand bringen
                self::ensureSchemaUpToDate(self::$instance);
            } catch (PDOException $e) {
                // Fallback: Versuch über Unix Sockets falls TCP fehlgeschlagen ist
                $fallbackSockets = ['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'];
                $connected = false;
                foreach ($fallbackSockets as $sock) {
                    if (file_exists($sock)) {
                        try {
                            $fallbackDsn = "mysql:unix_socket=$sock;dbname=$db;charset=$charset";
                            self::$instance = new PDO($fallbackDsn, $user, $pass, $options);
                            self::alignSessionTimeZone(self::$instance);
                            self::ensureSchemaUpToDate(self::$instance);
                            $connected = true;
                            break;
                        } catch (PDOException $ex) {
                            // Fallback nicht erfolgreich
                        }
                    }
                }

                if (!$connected) {
                    if (APP_ENV === 'development') {
                        throw new PDOException($e->getMessage(), (int)$e->getCode());
                    } else {
                        die("Datenbank-Verbindung fehlgeschlagen. Bitte überprüfen Sie die Einstellungen.");
                    }
                }
            }
        }

        return self::$instance;
    }

    /**
     * Stellt sicher, dass alle erforderlichen Tabellen und Spalten in der Datenbank existieren.
     * Ermöglicht reibungslose Updates ohne manuelle SQL-Migrationsskripte.
     *
     * Delegiert seit #230 vollständig an App\Service\SchemaMigrator::run() -
     * dort liegen der versionierte Kurzschluss über settings.schema_version
     * (#213: ist der persistierte Stand aktuell, kostet der Mechanismus pro
     * Request genau EINE Abfrage) und sämtliche idempotenten
     * Migrationsschritte. Es gibt genau EINE Quelle für die Schritte; dieser
     * Aufruf hier ist nur der automatische Weg beim Verbindungsaufbau.
     *
     * Setup-Fall: Existiert die settings-Tabelle noch nicht (frische Datenbank,
     * bevor der SetupController database/schema.sql importiert hat), läuft die
     * vollständig idempotente Migration, aber das Persistieren des Stands
     * wirft - deshalb der try/catch: Die App bleibt bewusst lauffähig, und
     * jeder weitere Verbindungsaufbau wiederholt die Migration, bis das Setup
     * die Tabelle angelegt hat - danach greift der Kurzschluss.
     *
     * Kein request-lokaler static-Guard mehr nötig: getInstance() ruft diese
     * Methode nur beim Verbindungsaufbau auf (einmal pro Request), und der
     * persistierte Stand ist der eigentliche, request-übergreifende Schutz.
     * Nebeneffekt: Der Mechanismus ist damit im Integrationstest über einen
     * zurückgesetzten Singleton nachprüfbar (siehe tests/Integration/DatabaseTest.php).
     *
     * @param PDO $pdo Aktive Datenbankverbindung
     */
    /**
     * Bringt die Sitzungs-Zeitzone der Datenbank mit der von PHP zur Deckung.
     *
     * Ohne das rechnen beide Seiten in verschiedenen Zeitzonen, und zwar
     * unbemerkt: `NOW()`/`CURDATE()` (an über dreißig Stellen im Kern) laufen
     * in der Zeitzone des Datenbankservers, jeder PHP-seitige Vergleich in
     * der von `date.timezone`. Solange beide zufällig übereinstimmen, fällt
     * nichts auf - im offiziellen Container tun sie das (php:8.5-apache steht
     * auf UTC, der MariaDB-Container ebenfalls). Auf klassischem Hosting, wo
     * PHP üblicherweise auf der lokalen Zeitzone steht, gehen sie auseinander.
     *
     * Die Folgen sind still und schwer zu finden: Der Katalog-Cache der
     * Addon-Übersicht galt dadurch dauerhaft als abgelaufen (#290) und lud bei
     * jedem Aufruf neu; die Tagesstatistik (`/api/stats`) buchte frisch
     * angelegte Datensätze in den Kübel des Vortags, solange die Datumsgrenzen
     * der beiden Zeitzonen auseinanderfielen. Beides sah nach Langsamkeit bzw.
     * nach einem Zählfehler aus, nicht nach einem Zeitzonenproblem.
     *
     * Gesetzt wird der numerische Versatz (`+02:00`), nicht der Zonenname:
     * Namen setzen die geladenen Zeitzonentabellen der Datenbank voraus
     * (`mysql_tzinfo_to_sql`), die in Containern und bei Hostern regelmäßig
     * fehlen - der Versatz funktioniert immer. Er wird je Verbindungsaufbau
     * neu bestimmt und ist damit auch über den Sommerzeitwechsel korrekt.
     *
     * Ein Fehlschlag ist bewusst nicht tödlich: Eine Datenbank, die `SET
     * time_zone` verweigert, soll die Anwendung nicht am Starten hindern -
     * dann gilt wieder das bisherige Verhalten.
     */
    private static function alignSessionTimeZone(PDO $pdo): void {
        try {
            $offset = (new \DateTimeImmutable('now'))->format('P');
            $stmt = $pdo->prepare('SET time_zone = ?');
            $stmt->execute([$offset]);
        } catch (\Throwable $e) {
            // Siehe PHPDoc: lieber die alte Uneinheitlichkeit als keine App.
        }
    }

    private static function ensureSchemaUpToDate(PDO $pdo): void {
        try {
            Service\SchemaMigrator::run($pdo);
        } catch (\Throwable $e) {
            // Kein harter Fehler: Die App bleibt mit dem vorhandenen Schema
            // lauffähig, die Migration wird beim nächsten Verbindungsaufbau wiederholt.
        }
    }
}
