<?php
// tests/Integration/RateLimiterIdentifierTest.php

namespace Tests\Integration;

use App\Database;
use App\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Der Konto-Zähler des Logins war durch angehängte Leerzeichen umgehbar.
 *
 * `AuthController::loginSubmit()` baut den Bezeichner aus der ungetrimmten
 * Eingabe plus Client-IP ("opfer@example.org|1.2.3.4"). Der RateLimiter
 * normalisierte davon nur die Groß-/Kleinschreibung, zählte also für
 * "opfer@example.org " einen eigenen, frischen Zähler - während die
 * Benutzersuche in der Datenbank die Adresse dank PAD-SPACE-Collation
 * unverändert findet. Ein Angreifer hängte pro Versuch ein Leerzeichen mehr an
 * und riet ungebremst weiter.
 *
 * Läuft als Integrationstest, weil der Zähler in der Tabelle `login_attempts`
 * lebt - eine Zusage über Zeichenketten allein würde genau den Punkt
 * verfehlen.
 */
class RateLimiterIdentifierTest extends TestCase {

    // Höchstens RateLimiter::MAX_TYPE_LENGTH Zeichen (Spalte
    // login_attempts.type) - ein längerer Typ wird seit Audit M8 laut
    // abgelehnt.
    private const TYPE = 'test_rl_ident';

    protected function setUp(): void {
        $this->purge();
    }

    protected function tearDown(): void {
        $this->purge();
    }

    private function purge(): void {
        Database::getInstance()
            ->prepare("DELETE FROM login_attempts WHERE type = ?")
            ->execute([self::TYPE]);
    }

    public function testTrailingWhitespaceDoesNotStartAFreshCounter(): void {
        $identifier = 'opfer@example.org|203.0.113.7';

        for ($i = 0; $i < 5; $i++) {
            RateLimiter::recordAttempt($identifier, self::TYPE);
        }

        $this->assertTrue(
            RateLimiter::tooManyAttempts($identifier, self::TYPE),
            'Fünf Fehlversuche müssen das Limit erreichen'
        );

        foreach ([
            'opfer@example.org |203.0.113.7',
            ' opfer@example.org|203.0.113.7',
            "opfer@example.org\t|203.0.113.7",
            'OPFER@example.org|203.0.113.7',
        ] as $variante) {
            $this->assertTrue(
                RateLimiter::tooManyAttempts($variante, self::TYPE),
                'Auch die Schreibvariante ' . var_export($variante, true) . ' muss auf denselben Zähler treffen'
            );
        }
    }

    public function testClearingAlsoWorksAcrossSpellings(): void {
        RateLimiter::recordAttempt('  Nutzer@Example.ORG|198.51.100.4  ', self::TYPE);
        RateLimiter::recordAttempt('nutzer@example.org|198.51.100.4', self::TYPE);

        $count = (int)Database::getInstance()
            ->query("SELECT COUNT(DISTINCT identifier) FROM login_attempts WHERE type = '" . self::TYPE . "'")
            ->fetchColumn();
        $this->assertSame(1, $count, 'Beide Schreibweisen müssen unter demselben Bezeichner landen');

        RateLimiter::clearAttempts('NUTZER@example.org|198.51.100.4', self::TYPE);

        $remaining = (int)Database::getInstance()
            ->query("SELECT COUNT(*) FROM login_attempts WHERE type = '" . self::TYPE . "'")
            ->fetchColumn();
        $this->assertSame(0, $remaining);
    }

    /**
     * IPv6: Adressen aus demselben /64 teilen sich einen Zähler (Audit M7).
     * Sonst wechselte ein Angreifer mit eigenem Präfix bei jedem Versuch die
     * Adresse. Greift auch für Addons, die die nackte Adresse übergeben.
     */
    public function testIpv6AdressenEinesPraefixesTeilenSichEinenZaehler(): void {
        foreach (['2001:db8:1:2::1', '2001:db8:1:2::2', '2001:db8:1:2:1::1', '2001:DB8:1:2:ffff::7', '2001:db8:1:2::abcd'] as $ip) {
            RateLimiter::recordAttempt($ip, self::TYPE);
        }

        $this->assertTrue(RateLimiter::tooManyAttempts('2001:db8:1:2:abcd::9', self::TYPE));
        $this->assertFalse(
            RateLimiter::tooManyAttempts('2001:db8:1:3::1', self::TYPE),
            'Ein anderes /64 hat seinen eigenen Zähler.'
        );

        $stmt = Database::getInstance()->prepare("SELECT DISTINCT identifier FROM login_attempts WHERE type = ?");
        $stmt->execute([self::TYPE]);
        $this->assertSame(['2001:db8:1:2::/64'], $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testIpv4GemapptUndIpv4TeilenSichEinenZaehler(): void {
        for ($i = 0; $i < 3; $i++) {
            RateLimiter::recordAttempt('::ffff:198.51.100.4', self::TYPE);
            RateLimiter::recordAttempt('198.51.100.4', self::TYPE);
        }
        $this->assertTrue(RateLimiter::tooManyAttempts('198.51.100.4', self::TYPE, 6));
        $this->assertTrue(RateLimiter::tooManyAttempts('::ffff:198.51.100.4', self::TYPE, 6));
    }
}
