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
 *
 * ZWEI ARTEN, IHN ZU BENUTZEN (Audit M20):
 *
 * - reserveAttempt()/releaseAttempt(): Der Versuch wird ZUERST gebucht und
 *   erst danach gezählt. Das ist der Weg für alles, was ein Geheimnis prüft
 *   (Passwort, Codes) oder etwas Teures auslöst (Mail). Parallel gestartete
 *   Anfragen kommen damit höchstens bis zur Grenze durch.
 * - tooManyAttempts()/recordAttempt()/clearAttempts(): Prüfen, dann später
 *   buchen. Zwischen beidem liegt die eigentliche Prüfung, und parallele
 *   Anfragen sehen dort alle denselben alten Stand - so ließ sich bisher ein
 *   Vielfaches der erlaubten Versuche erzwingen. Die Methoden bleiben
 *   unverändert (Addon-API), für neue Aufrufer gilt reserveAttempt().
 */
class RateLimiter {

    /**
     * Längste zulässige Typbezeichnung - muss zur Spalte `login_attempts.type`
     * (VARCHAR(20)) passen (Audit M8).
     *
     * Ein längerer Typ scheiterte bisher STILL: Das INSERT schlug fehl, der
     * Fehler wurde als "Datenbank weg" geschluckt, und der Zähler stand für
     * immer auf null. So griff die Sperre beim erzwungenen Passwortwechsel
     * nie ('force_password_change' hat 21 Zeichen). Jetzt ist das ein lauter
     * Programmierfehler statt einer stillen Lücke.
     */
    public const MAX_TYPE_LENGTH = 20;

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
        self::assertType($type);
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
        self::assertType($type);
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
        self::assertType($type);
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("DELETE FROM login_attempts WHERE identifier = ? AND type = ?");
            $stmt->execute([self::normalizeIdentifier($identifier), $type]);
        } catch (\Throwable $e) {
            // Ausfallsicherheit
        }
    }

    /**
     * Bucht einen Versuch und prüft erst DANACH, ob er noch in die Grenze
     * passt (Audit M20).
     *
     * WARUM DIESE REIHENFOLGE. Bisher hiess es: zählen, prüfen, buchen.
     * Zwanzig gleichzeitig abgeschickte Anmeldungen sahen beim Zählen alle
     * denselben Stand von vier Fehlversuchen, prüften alle ihr Passwort und
     * buchten danach - zwanzig Versuche statt einem. Hier wird die eigene
     * Zeile zuerst geschrieben (Autocommit, also sofort für alle sichtbar)
     * und dann gezählt. Die k-te angenommene Buchung sieht mindestens k
     * Zeilen, denn angenommene Zeilen verschwinden nur über releaseAttempt()
     * oder clearAttempts(). Mehr als $maxAttempts gleichzeitig angenommene
     * Buchungen kann es deshalb nicht geben.
     *
     * DIE KEHRSEITE, bewusst in Kauf genommen: Kommen viele Anfragen exakt
     * gleichzeitig, sehen sie auch die Zeilen der anderen, noch nicht wieder
     * gelöschten Abgelehnten - im Extremfall werden alle abgelehnt. Das
     * passiert nur unter einem Burst, also unter Angriff, und ist dann
     * fail-closed.
     *
     * Rückgabe:
     * - eine Reservierungs-ID (> 0): Der Versuch darf laufen. Scheitert er,
     *   bleibt die Buchung stehen - sie IST der Fehlversuch. Ist er
     *   erfolgreich oder neutral (nichts geprüft), gibt der Aufrufer sie mit
     *   releaseAttempt() frei oder leert mit clearAttempts() den Zähler.
     * - 0: Die Datenbank war nicht erreichbar, der Versuch darf laufen
     *   (fail-open wie tooManyAttempts()).
     * - null: Grenze erreicht, die eigene Buchung ist schon wieder gelöscht.
     *
     * In einer offenen Transaktion wäre das INSERT für andere unsichtbar und
     * der Zählstand veraltet - die Obergrenze gälte dort nicht. Das ist ein
     * Programmierfehler und wird laut gemeldet.
     *
     * @throws \InvalidArgumentException bei unzulässigem Typ
     * @throws \LogicException in einer offenen Transaktion
     */
    public static function reserveAttempt(string $identifier, string $type, int $maxAttempts = 5, int $windowSeconds = 900): ?int {
        self::assertType($type);

        try {
            $db = Database::getInstance();
        } catch (\Throwable $e) {
            return 0;
        }
        if ($db->inTransaction()) {
            throw new \LogicException(
                'RateLimiter::reserveAttempt() darf nicht in einer offenen Transaktion laufen - '
                . 'die Buchung wäre für parallele Anfragen unsichtbar und die Grenze wirkungslos.'
            );
        }

        $identifier = self::normalizeIdentifier($identifier);
        try {
            $stmt = $db->prepare("INSERT INTO login_attempts (identifier, type, ip_address) VALUES (?, ?, ?)");
            $stmt->execute([$identifier, $type, ClientIp::resolve()]);
            $id = (int)$db->lastInsertId();

            $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND type = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
            $stmt->execute([$identifier, $type, $windowSeconds]);
            if ((int)$stmt->fetchColumn() > $maxAttempts) {
                self::releaseAttempt($id);
                return null;
            }
            return $id > 0 ? $id : 0;
        } catch (\Throwable $e) {
            // Ausfallsicherheit wie bei tooManyAttempts()
            return 0;
        }
    }

    /**
     * Gibt eine Buchung aus reserveAttempt() wieder frei - nach Erfolg, bei
     * einem neutralen Ausstieg (es wurde nichts geprüft) oder wenn ein
     * nachgelagerter Zähler sperrt. 0 (fail-open) wird ignoriert.
     */
    public static function releaseAttempt(?int $reservationId): void {
        if ($reservationId === null || $reservationId <= 0) {
            return;
        }
        try {
            Database::getInstance()
                ->prepare("DELETE FROM login_attempts WHERE id = ?")
                ->execute([$reservationId]);
        } catch (\Throwable $e) {
            // Ausfallsicherheit
        }
    }

    /**
     * Unzulässige Typen fallen laut auf, nicht still (Audit M8). Bewusst
     * AUSSERHALB der try-Blöcke: Deren Aufgabe ist es, Datenbankausfälle
     * abzufangen, nicht Programmierfehler.
     */
    private static function assertType(string $type): void {
        if ($type === '' || strlen($type) > self::MAX_TYPE_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                "RateLimiter-Typ '%s' ist unzulässig: Er muss 1 bis %d Zeichen lang sein (Spalte login_attempts.type).",
                $type,
                self::MAX_TYPE_LENGTH
            ));
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
     *
     * Sicherheitsnetz für IPv6 (Audit M7): Ist der Bezeichner als Ganzes eine
     * IPv6-Adresse, zählt ihr /64-Präfix (ClientIp::rateLimitKey()). Der Kern
     * übergibt seine IP-Schlüssel schon so; Addons, die die nackte Adresse
     * aus ClientIp::resolve() übergeben, bekommen die Aggregation damit ohne
     * eigene Änderung.
     */
    private static function normalizeIdentifier(string $identifier): string {
        $kompakt = trim($identifier);
        if (filter_var($kompakt, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $identifier = ClientIp::rateLimitKey($kompakt);
        }

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
