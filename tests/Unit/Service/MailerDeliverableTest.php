<?php
// tests/Unit/Service/MailerDeliverableTest.php

namespace Tests\Unit\Service;

use App\Service\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * Mailer::isDeliverable() - kann diese Installation überhaupt Mail versenden?
 *
 * Rein, ohne Netz: Geprüft wird ausschließlich die Konfiguration, nicht die
 * Erreichbarkeit des Servers. Die Aussage lautet „so wie es konfiguriert ist,
 * kommt send() gar nicht erst bis zum Verbindungsaufbau", und genau die
 * braucht die Update-Automatik, bevor sie sich auf ihre Benachrichtigung
 * verlässt (#290).
 */
class MailerDeliverableTest extends TestCase {

    public function testCompleteSmtpConfigurationIsDeliverable(): void {
        $this->assertTrue(Mailer::isDeliverable([
            'smtp_host' => 'mail.example.test',
            'smtp_user' => 'post@example.test',
            'smtp_encryption' => 'tls',
        ]));
    }

    /** Ohne mail_driver gilt 'smtp' - dieselbe Vorgabe wie in send(). */
    public function testDriverDefaultsToSmtp(): void {
        $this->assertFalse(
            Mailer::isDeliverable([]),
            'Leere Settings sind der Fall der Dev-Instanz: keine einzige smtp_*-Zeile.'
        );
    }

    public function testMissingHostOrUserIsNotDeliverable(): void {
        $this->assertFalse(Mailer::isDeliverable(['smtp_user' => 'post@example.test']));
        $this->assertFalse(Mailer::isDeliverable(['smtp_host' => 'mail.example.test']));
        $this->assertFalse(Mailer::isDeliverable([
            'smtp_host' => '   ',
            'smtp_user' => 'post@example.test',
        ]), 'Nur Leerzeichen ist keine Konfiguration.');
    }

    /**
     * Unverschlüsselter Versand ist im Kern verboten (sendViaSmtp bricht ab) -
     * die Vorschau muss dieselbe Grenze ziehen, sonst verspricht sie einen
     * Versand, den der Kern gleich darauf verweigert.
     */
    public function testUnencryptedOrUnknownEncryptionIsNotDeliverable(): void {
        foreach (['none', '', 'starttls', 'plain'] as $modus) {
            $this->assertFalse(
                Mailer::isDeliverable([
                    'smtp_host' => 'mail.example.test',
                    'smtp_user' => 'post@example.test',
                    'smtp_encryption' => $modus,
                ]),
                "Verschlüsselung '{$modus}' darf nicht als zustellbar gelten."
            );
        }
    }

    public function testEncryptionIsComparedCaseInsensitively(): void {
        $this->assertTrue(Mailer::isDeliverable([
            'smtp_host' => 'mail.example.test',
            'smtp_user' => 'post@example.test',
            'smtp_encryption' => 'SSL',
        ]));
    }

    /**
     * Fehlt die Angabe ganz, greift wie in sendViaSmtp() die Vorgabe 'tls' -
     * eine vollständige Konfiguration ohne ausdrückliche Verschlüsselung ist
     * also zustellbar.
     */
    public function testMissingEncryptionFallsBackToTls(): void {
        $this->assertTrue(Mailer::isDeliverable([
            'smtp_host' => 'mail.example.test',
            'smtp_user' => 'post@example.test',
        ]));
    }

    /**
     * Beim Treiber 'mail' hängt die Zustellung am MTA des Systems. Von hier
     * aus lässt sich darüber nichts sagen - und eine Vermutung wäre schlechter
     * als keine Aussage, denn sie erzeugte eine Warnung, die immer da steht.
     */
    public function testPhpMailDriverIsAssumedDeliverable(): void {
        $this->assertTrue(Mailer::isDeliverable(['mail_driver' => 'mail']));
        $this->assertTrue(Mailer::isDeliverable([
            'mail_driver' => 'mail',
            'smtp_host' => '',
            'smtp_user' => '',
        ]));
    }
}
