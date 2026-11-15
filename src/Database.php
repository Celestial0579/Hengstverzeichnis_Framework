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
 *
 * Wer neben der geteilten Verbindung eine eigene braucht (Import, Rückweg),
 * baut sie über neueVerbindung() - mit denselben Einstellungen, aber ohne
 * Migration (#519).
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
     * Grundoptionen jeder Verbindung der Anwendung, siehe verbindungsOptionen().
     */
    private const GRUND_OPTIONEN = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Löst Exceptions bei Fehlern aus
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Standard-Fetch: Assoziatives Array
        PDO::ATTR_EMULATE_PREPARES   => false,                  // Verwendet echte vorbereitete Statements
    ];

    /**
     * Lokale Unix-Sockets, über die verbinden() es versucht, wenn der
     * eingestellte Server nicht antwortet.
     */
    private const RUECKFALL_SOCKETS = ['/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'];

    /**
     * Privater Konstruktor zur Verhinderung direkter Instanziierung (Singleton-Pattern).
     */
    private function __construct() {}

    /**
     * Privater Klon-Konstruktor zur Verhinderung von Duplizierung.
     */
    private function __clone() {}

    /**
     * Der DSN für MySQL/MariaDB - EINE Stelle für Anwendung, Einrichtung und
     * CLI-Skripte (Audit N55).
     *
     * Ein Host, der mit '/' beginnt, ist ein Unix-Socket-Pfad (laut README
     * und App\Security\DbIdentifier::isValidHost() zulässig); der Port
     * spielt dann keine Rolle. Die Einrichtung baute bis hierher immer
     * `host=` und scheiterte an genau diesem dokumentierten Fall.
     *
     * Ohne $dbName (null oder '') entsteht ein DSN ohne Datenbank - für die
     * Einrichtung, die die Datenbank erst anlegt.
     *
     * Der Helfer prüft NICHTS: Werte aus Setup und Umgebung sind vorher über
     * App\Security\DbIdentifier geprüft (insbesondere kein ';', das weitere
     * DSN-Parameter einschleusen würde). Wer ihn mit anderen Werten aufruft,
     * prüft selbst.
     */
    public static function buildDsn(string $host, string $port, ?string $dbName, string $charset = 'utf8mb4'): string {
        $dsn = str_starts_with($host, '/')
            ? "mysql:unix_socket={$host}"
            : "mysql:host={$host};port={$port}";
        if ($dbName !== null && $dbName !== '') {
            $dsn .= ";dbname={$dbName}";
        }
        return $dsn . ";charset={$charset}";
    }

    /**
     * Die TLS-Optionen einer Verbindung - EINE Stelle für Anwendung und
     * Einrichtung (#520).
     *
     * Die Konstanten kommen aus Pdo\Mysql. Die gleichnamigen der Klasse PDO
     * (PDO::MYSQL_ATTR_SSL_CA ...) sind seit PHP 8.5 veraltet; mit
     * eingeschaltetem TLS löste jeder Verbindungsaufbau eine Deprecation aus -
     * im Produktivbetrieb eine Protokollzeile je Anfrage, im
     * Entwicklungsmodus Ausgabe vor der ersten Kopfzeile.
     *
     * Keine Option fällt still weg: Ein defined()-Wächter ohne Sonst-Zweig
     * macht aus einer fehlenden Konstante eine fehlende TLS-Einstellung.
     * Kennt der Treiber den Schalter für die Zertifikatsprüfung nicht
     * (pdo_mysql ohne mysqlnd), ist eine verlangte Prüfung deshalb ein
     * Fehler.
     *
     * @return array<int, mixed> Optionen für den PDO-Konstruktor; leer ohne TLS
     * @throws PDOException Wenn $verify verlangt ist, der Treiber die Prüfung aber nicht einschalten kann
     */
    public static function tlsOptionen(bool $ssl, string $ca, bool $verify): array {
        // Ohne pdo_mysql gibt es keine Verbindung, an der Optionen hängen
        // könnten - der PDO-Konstruktor meldet dann "could not find driver".
        if (!$ssl || !class_exists(\Pdo\Mysql::class)) {
            return [];
        }

        $optionen = [];
        if ($ca !== '') {
            $optionen[\Pdo\Mysql::ATTR_SSL_CA] = $ca;
        }
        if (defined(\Pdo\Mysql::class . '::ATTR_SSL_VERIFY_SERVER_CERT')) {
            $optionen[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = $verify;
        } elseif ($verify) {
            throw new PDOException(
                'DB_SSL_VERIFY verlangt die Prüfung des Server-Zertifikats, der PDO-Treiber '
                . '(pdo_mysql ohne mysqlnd) kann sie nicht einschalten.'
            );
        }
        return $optionen;
    }

    /**
     * Die Optionen einer Verbindung der Instanz: TLS aus der Konfiguration,
     * dann die des Aufrufers, dann die Grundoptionen.
     *
     * Die Reihenfolge ist die Rangfolge (Array-Vereinigung behält den linken
     * Wert): Die TLS-Einstellungen der Instanz gewinnen immer, alles andere
     * darf ein Aufrufer von neueVerbindung() ergänzen oder überschreiben.
     *
     * @param array<int, mixed> $zusatzOptionen
     * @return array<int, mixed>
     */
    private static function verbindungsOptionen(array $zusatzOptionen = []): array {
        $tls = self::tlsOptionen(
            defined('DB_SSL') && DB_SSL,
            defined('DB_SSL_CA') ? (string)DB_SSL_CA : '',
            defined('DB_SSL_VERIFY') && DB_SSL_VERIFY
        );

        return $tls + $zusatzOptionen + self::GRUND_OPTIONEN;
    }

    /**
     * Baut eine Verbindung mit den Zugangsdaten der Instanz auf - der EINE
     * Weg, über den getInstance() und neueVerbindung() verbinden (#519).
     *
     * Enthält, was jede Verbindung der Anwendung ausmacht: DSN aus
     * buildDsn(), der Rückfall auf lokale Unix-Sockets und die
     * Sitzungs-Zeitzone. Die Schema-Migration gehört NICHT dazu - die stößt
     * nur getInstance() an.
     *
     * @param array<int, mixed> $optionen
     * @throws PDOException Der Fehler des eingestellten Servers, wenn auch kein Socket trägt
     */
    private static function verbinden(array $optionen): PDO {
        $host = (string)DB_HOST;
        $port = defined('DB_PORT') ? (string)DB_PORT : '3306';
        $db   = (string)DB_NAME;

        try {
            $pdo = new PDO(self::buildDsn($host, $port, $db), DB_USER, DB_PASS, $optionen);
        } catch (PDOException $e) {
            // Fallback: Versuch über Unix Sockets falls TCP fehlgeschlagen ist
            $pdo = null;
            foreach (self::RUECKFALL_SOCKETS as $sock) {
                if (!file_exists($sock)) {
                    continue;
                }
                try {
                    $pdo = new PDO(self::buildDsn($sock, '', $db), DB_USER, DB_PASS, $optionen);
                    break;
                } catch (PDOException $ex) {
                    // Fallback nicht erfolgreich
                }
            }
            if ($pdo === null) {
                throw $e;
            }
        }

        self::alignSessionTimeZone($pdo);

        return $pdo;
    }

    /**
     * Eine NEUE, nicht geteilte Verbindung mit den Eigenschaften der Instanz
     * (#519) - für Aufrufer, die neben getInstance() eine eigene brauchen.
     *
     * Wozu: Ein Import setzt Sitzungsvariablen (FOREIGN_KEY_CHECKS, NAMES,
     * time_zone), die an der geteilten Verbindung hängen blieben, und eine
     * abgebrochene Anweisung (Paket zu groß, "server has gone away") nähme
     * die geteilte Verbindung für den Rest der Anfrage mit. Wer so etwas
     * vorhat, arbeitet auf einer eigenen Verbindung - und baut sie hier,
     * statt DSN, TLS-Optionen und Zeitzone nachzubauen (das Addon
     * datenmigration tat genau das; ein Nachbau läuft beim nächsten Umbau
     * des Kerns still auseinander).
     *
     * Gleich wie bei getInstance(): Zugangsdaten und DSN (auch Unix-Socket),
     * TLS-Einstellungen, Fehlermodus, Fetch-Modus, echte Prepared Statements,
     * Rückfall auf lokale Sockets, Sitzungs-Zeitzone.
     *
     * Anders als bei getInstance():
     *   - Jeder Aufruf baut eine weitere Verbindung auf; sie endet, sobald
     *     der Aufrufer die letzte Referenz loslässt.
     *   - KEINE Schema-Migration. Mitten in einem Import oder Rückweg wäre
     *     sie das Letzte, was man will; database/migrate.php stößt sie
     *     ausdrücklich an.
     *   - Ein Fehlschlag ist immer eine PDOException, in jeder Umgebung -
     *     der Aufrufer entscheidet, was daraus wird.
     *
     * $zusatzOptionen ergänzen oder überschreiben die Grundoptionen, etwa
     * [\Pdo\Mysql::ATTR_MULTI_STATEMENTS => false]. Die TLS-Einstellungen der
     * Instanz lassen sich darüber nicht ändern. PDO::ATTR_PERSISTENT wird
     * abgelehnt: Eine persistente Verbindung ist weder neu noch die des
     * Aufrufers - PHP gäbe eine aus einer früheren Anfrage zurück, samt
     * deren Sitzungsvariablen und Sperren (siehe Audit M38 in getInstance()).
     *
     * @param array<int, mixed> $zusatzOptionen Weitere Optionen für den PDO-Konstruktor
     * @throws PDOException Wenn keine Verbindung zustande kommt
     * @throws \InvalidArgumentException Bei PDO::ATTR_PERSISTENT
     */
    public static function neueVerbindung(array $zusatzOptionen = []): PDO {
        if (!empty($zusatzOptionen[PDO::ATTR_PERSISTENT])) {
            throw new \InvalidArgumentException(
                'Database::neueVerbindung() baut keine persistente Verbindung auf (PDO::ATTR_PERSISTENT).'
            );
        }

        return self::verbinden(self::verbindungsOptionen($zusatzOptionen));
    }

    /**
     * Liefert die zentrale PDO-Datenbankinstanz zurück oder baut diese bei Erstaufruf auf.
     *
     * @return PDO Aktive PDO-Verbindung
     * @throws PDOException Falls im Entwicklungsmodus ein Verbindungsfehler auftritt
     */
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            try {
                // Bewusst KEIN PDO::ATTR_PERSISTENT: App\Service\DbLock hängt
                // seine GET_LOCK-Sperren an diese Verbindung und verlässt sich
                // darauf, dass sie mit dem Request endet (auch nach Fatal oder
                // Kill). Eine persistente Verbindung würde Sperren über das
                // Request-Ende hinaus halten (Audit M38).
                self::$instance = self::verbinden(self::verbindungsOptionen());
            } catch (PDOException $e) {
                self::verbindungsfehler($e);
            }

            // Datenbank-Schema bei Verbindungsaufbau automatisch auf den neuesten Stand bringen.
            // Erst NACH dem Setzen der Instanz: Ein verschachteltes
            // getInstance() während der Migration (AuditLogger) bekommt so
            // diese Verbindung und baut keine zweite auf (Audit N75).
            self::ensureSchemaUpToDate(self::$instance);
        }

        return self::$instance;
    }

    /**
     * Endet für getInstance(), wenn keine Verbindung zustande kam (#521).
     *
     * Die Ursache steht in jeder Umgebung im Fehlerprotokoll, BEVOR etwas
     * anderes passiert. Früher endete der Produktivbetrieb hier in einem
     * blanken die(): kein Protokolleintrag (App\Service\ErrorHandler kommt
     * bei die() nie zum Zug), HTTP 200 und auf der Kommandozeile Exit-Code 0.
     * Der eine Ausfall, den ein Betreiber sofort sehen muss, sah von außen
     * gesund aus - für die Überwachung, für den Cron-Aufruf von /cron/run
     * und für jedes Deploy-Skript, das den Exit-Code prüft.
     *
     * Die Meldung der PDOException nennt Benutzer und Host, kein Passwort.
     * An den Besucher geht sie trotzdem nur im Entwicklungsmodus.
     *
     * @throws PDOException Im Entwicklungsmodus
     */
    private static function verbindungsfehler(PDOException $e): never {
        error_log('Datenbank-Verbindung fehlgeschlagen: ' . $e->getMessage());

        if (APP_ENV === 'development') {
            throw new PDOException($e->getMessage(), (int)$e->getCode());
        }

        $meldung = 'Datenbank-Verbindung fehlgeschlagen. Bitte überprüfen Sie die Einstellungen.';
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $meldung . PHP_EOL);
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(503);
        }
        die($meldung);
    }

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
    private static function ensureSchemaUpToDate(PDO $pdo): void {
        try {
            // Gedrosselt (Audit N76): Nach einem offenen oder gescheiterten
            // Lauf versucht es der implizite Weg erst nach
            // SchemaMigrator::WIEDERHOLEN_NACH erneut, und ein solcher
            // Wiederholungslauf wartet nicht auf die Migrationssperre.
            // Explizite Aufrufer (database/migrate.php) laufen ungedrosselt.
            Service\SchemaMigrator::run($pdo, true);
        } catch (\Throwable $e) {
            // Kein harter Fehler: Die App bleibt mit dem vorhandenen Schema
            // lauffähig. Der Fehler steht in settings.schema_migration_status
            // (Admin-Dashboard), der nächste Versuch folgt gedrosselt.
        }
    }
}
