<?php
// src/Security/RateLimiter.php

namespace App\Security;

use App\Database;

/**
 * Class RateLimiter
 *
 * Einfacher, datenbankgestützter Brute-Force-Schutz für Login, 2FA-Code
 * und Backup-Code-Verifizierung. Zählt fehlgeschlagene Versuche pro
 * Identifier (z. B. E-Mail oder Benutzer-ID) innerhalb eines Zeitfensters.
 */
class RateLimiter {

    /**
     * Prüft, ob für den gegebenen Identifier/Typ das Versuchslimit erreicht wurde.
     *
     * @param string $identifier Eindeutiger Bezug (z. B. E-Mail-Adresse oder Benutzer-ID)
     * @param string $type Art des Versuchs ('login', '2fa', 'backup')
     * @param int $maxAttempts Maximale Anzahl fehlgeschlagener Versuche im Zeitfenster
     * @param int $windowSeconds Länge des Zeitfensters in Sekunden
     * @return bool True, wenn das Limit erreicht ist und der Versuch geblockt werden muss
     */
    public static function tooManyAttempts(string $identifier, string $type, int $maxAttempts = 5, int $windowSeconds = 900): bool {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND type = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
            $stmt->execute([self::normalizeIdentifier($identifier), $type, $windowSeconds]);
            return (int)$stmt->fetchColumn() >= $maxAttempts;
        } catch (\Throwable $e) {
            // Bei DB-Fehlern nicht blockieren (Ausfallsicherheit)
            return false;
        }
    }

    /**
     * Protokolliert einen fehlgeschlagenen Versuch.
     */
    public static function recordAttempt(string $identifier, string $type): void {
        try {
            $db = Database::getInstance();
            $ipAddress = ClientIp::resolve();

            $stmt = $db->prepare("INSERT INTO login_attempts (identifier, type, ip_address) VALUES (?, ?, ?)");
            $stmt->execute([self::normalizeIdentifier($identifier), $type, $ipAddress]);
        } catch (\Throwable $e) {
            // Ausfallsicherheit: Rate-Limiter-Fehler dürfen den Login-Flow nicht blockieren
        }
    }

    /**
     * Löscht alle protokollierten Fehlversuche nach erfolgreicher Authentifizierung.
     */
    public static function clearAttempts(string $identifier, string $type): void {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("DELETE FROM login_attempts WHERE identifier = ? AND type = ?");
            $stmt->execute([self::normalizeIdentifier($identifier), $type]);
        } catch (\Throwable $e) {
            // Ausfallsicherheit
        }
    }

    /**
     * Vereinheitlicht den Bezeichner, bevor er in die Zähltabelle geht.
     *
     * strtolower() allein reichte nicht: Die Spalte `identifier` ist eine
     * gewöhnliche VARCHAR-Spalte mit PAD-SPACE-Collation, "opfer@example.org"
     * und "opfer@example.org   " sind darin beim Vergleich zwar gleich - aber
     * der Zähler wird über GENAU diesen Wert geführt, und der Login-Controller
     * setzt ihn aus der ungetrimmten Eingabe plus IP zusammen. Ein Angreifer
     * hängte einfach ein Leerzeichen an die E-Mail-Adresse und begann bei
     * jedem Versuch mit einem frischen Konto-Zähler; die Adresse selbst wird
     * in der Datenbank ohnehin gleich gefunden, das Passwortraten lief also
     * ungebremst weiter.
     *
     * Zusätzlich eine Längengrenze: Der Bezeichner ist nutzergesteuert, und
     * ein überlanger Wert würde beim Einfügen abgeschnitten - zwei
     * verschiedene Eingaben teilten sich dann still denselben Zähler.
     */
    private static function normalizeIdentifier(string $identifier): string {
        // Whitespace wird ENTFERNT, nicht nur getrimmt oder zusammengefasst.
        // Der Bezeichner des Logins ist zusammengesetzt ("email|ip"), das
        // angehängte Leerzeichen des Angreifers steht darin also mittendrin -
        // ein trim() über das Ganze fasst es nicht, und ein Zusammenfassen zu
        // einem einzelnen Leerzeichen erzeugt weiterhin einen eigenen Zähler.
        // In keinem der verwendeten Bezeichner (E-Mail|IP, Benutzer-ID, IP)
        // kommt Whitespace vor, es gibt also nichts zu erhalten.
        //
        // mb_strtolower und NICHT strtolower: Die Datenbank vergleicht in
        // utf8mb4_unicode_ci, also auch bei Umlauten ohne Ruecksicht auf
        // Gross- und Kleinschreibung. Ein byteweises strtolower() liesse
        // "MÜLLER" stehen - die Anmeldung faende dasselbe Konto, der Zaehler
        // fuehrte "MÜLLER" und "müller" aber getrennt, und ein Angreifer
        // haette doppelt so viele Versuche. Aufgefallen bei #348, seit die
        // Kennung auch ein Benutzername sein darf; fuer Adressen galt es
        // vorher genauso.
        $normalized = mb_strtolower((string)preg_replace('/\s+/u', '', $identifier), 'UTF-8');
        return mb_substr($normalized, 0, 190);
    }
}
