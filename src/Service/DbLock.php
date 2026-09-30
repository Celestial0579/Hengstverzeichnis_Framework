<?php
// src/Service/DbLock.php

namespace App\Service;

use App\Database;

/**
 * Benannte Sperren über `GET_LOCK()` der Datenbank (Audit M38).
 *
 * WARUM GET_LOCK. Die Sperre hängt an der Datenbankverbindung des Requests.
 * `App\Database` baut sie bewusst NICHT persistent auf (kein
 * `PDO::ATTR_PERSISTENT`), also fällt die Sperre beim Request-Ende von
 * selbst - auch nach einem Fatal Error, einem `exit` oder einem Kill des
 * Workers. Eine Sperrzeile in einer Tabelle bräuchte dagegen eine Ablaufzeit
 * und bliebe nach einem Kill verwaist.
 *
 * Mehrere gleichzeitig gehaltene Sperren je Verbindung setzen MariaDB
 * ≥ 10.0.2 bzw. MySQL ≥ 5.7 voraus; ältere Versionen werden nicht
 * unterstützt.
 *
 * NICHT VERFÜGBAR IST KEIN FEHLER. Auf manchen Plattformen ist GET_LOCK
 * verboten (Percona XtraDB Cluster mit `pxc_strict_mode=ENFORCING`) oder
 * liefert NULL. `acquire()` meldet das als `null`, und der Aufrufer
 * entscheidet, ob er ohne Sperre weitermacht. Würfe es, liefe auf solchen
 * Plattformen gar nichts mehr - schlimmer als ohne Sperre. In einem
 * Galera-Cluster wirkt die Sperre nur auf dem jeweiligen Knoten.
 */
final class DbLock {

    private static bool $simulateUnavailable = false;

    private function __construct() {}

    /**
     * Serverweit eindeutiger Name. GET_LOCK-Namen gelten für den ganzen
     * Datenbankserver und sind auf 64 Zeichen begrenzt; zwei Installationen
     * auf demselben Server dürfen sich nicht gegenseitig sperren. Deshalb
     * fließt der Datenbankname in den Hash ein.
     */
    public static function lockName(string $name): string {
        $database = defined('DB_NAME') ? (string)DB_NAME : '';
        return 'hv_' . substr(hash('sha256', $database . "\0" . $name), 0, 40);
    }

    /**
     * @return bool|null true = Sperre erhalten, false = von einer anderen
     *         Verbindung belegt, null = auf dieser Plattform nicht nutzbar
     */
    public static function acquire(string $name, int $timeoutSeconds = 0): ?bool {
        if (self::$simulateUnavailable) {
            return null;
        }
        try {
            $stmt = Database::getInstance()->prepare('SELECT GET_LOCK(?, ?)');
            $stmt->execute([self::lockName($name), max(0, $timeoutSeconds)]);
            $value = $stmt->fetchColumn();
        } catch (\PDOException $e) {
            error_log('DbLock: GET_LOCK nicht verfügbar (' . $name . '): ' . $e->getMessage());
            return null;
        }
        if ($value === null || $value === false) {
            return null;
        }
        return (int)$value === 1;
    }

    /**
     * Gibt die Sperre frei. Fehler werden geschluckt: Die Verbindung kann
     * nach einem langen Lauf per `wait_timeout` schon weg sein - dann ist
     * die Sperre ohnehin gefallen.
     */
    public static function release(string $name): void {
        if (self::$simulateUnavailable) {
            return;
        }
        try {
            $stmt = Database::getInstance()->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([self::lockName($name)]);
        } catch (\Throwable $e) {
            error_log('DbLock: RELEASE_LOCK fehlgeschlagen (' . $name . '): ' . $e->getMessage());
        }
    }

    /**
     * @return bool|null true = irgendeine Verbindung hält die Sperre,
     *         false = frei, null = unbekannt
     */
    public static function isHeld(string $name): ?bool {
        if (self::$simulateUnavailable) {
            return null;
        }
        try {
            $stmt = Database::getInstance()->prepare('SELECT IS_USED_LOCK(?) IS NOT NULL');
            $stmt->execute([self::lockName($name)]);
            $value = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }
        return $value === false || $value === null ? null : (int)$value === 1;
    }

    /**
     * Nur für Tests: verhält sich, als gäbe es GET_LOCK auf dieser
     * Plattform nicht.
     */
    public static function simulateUnavailableForTests(bool $unavailable): void {
        self::$simulateUnavailable = $unavailable;
    }
}
