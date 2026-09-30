<?php
// tests/Unit/Service/MailerAdminRecipientTest.php

namespace Tests\Unit\Service;

use App\Service\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * Empfänger der DSGVO-Benachrichtigung (Audit N71): ?:-Kaskade über
 * admin_notification_email, mail_from_email, smtp_user - jeweils nur eine
 * gültige Adresse. Ein geleertes Feld steht in `settings` als Leerstring;
 * mit ?? griff der Rückfall nie (vgl. #132, #452).
 */
class MailerAdminRecipientTest extends TestCase {

    public function testGesetzteBenachrichtigungsadresseHatVorrang(): void {
        $this->assertSame('dsgvo@x.de', Mailer::resolveAdminRecipient([
            'admin_notification_email' => 'dsgvo@x.de',
            'mail_from_email' => 'a@x.de',
            'smtp_user' => 'relay@x.de',
        ]));
    }

    public function testLeereBenachrichtigungsadresseFaelltAufDenAbsenderZurueck(): void {
        $this->assertSame('a@x.de', Mailer::resolveAdminRecipient([
            'admin_notification_email' => '',
            'mail_from_email' => 'a@x.de',
        ]));
    }

    public function testOhneBeideGiltDerSmtpBenutzer(): void {
        $this->assertSame('relay@x.de', Mailer::resolveAdminRecipient([
            'admin_notification_email' => '',
            'mail_from_email' => '',
            'smtp_user' => 'relay@x.de',
        ]));
    }

    public function testEinSmtpBenutzerOhneAdresseIstKeinEmpfaenger(): void {
        $this->assertNull(Mailer::resolveAdminRecipient([
            'admin_notification_email' => '',
            'mail_from_email' => '',
            'smtp_user' => 'relayuser',
        ]));
    }

    public function testOhneJedeAngabeGibtEsKeinenEmpfaenger(): void {
        $this->assertNull(Mailer::resolveAdminRecipient([]));
        $this->assertNull(Mailer::resolveAdminRecipient([
            'admin_notification_email' => '',
            'mail_from_email' => '',
            'smtp_user' => '',
        ]));
    }

    public function testLeerraumWirdEntferntUndUngueltigesUebersprungen(): void {
        $this->assertSame('a@x.de', Mailer::resolveAdminRecipient([
            'admin_notification_email' => 'kaputt@',
            'mail_from_email' => "  a@x.de \n",
        ]));
    }

    /** Kein ??-Rückfall und kein Platzhalter mehr, an den still versendet würde. */
    public function testQuelltextKenntKeinenPlatzhalterEmpfaenger(): void {
        $code = (string)file_get_contents(__DIR__ . '/../../../src/Service/Mailer.php');
        $this->assertStringNotContainsString("['admin_notification_email'] ??", $code);
        $this->assertStringNotContainsString('admin@example.com', $code);
    }
}
