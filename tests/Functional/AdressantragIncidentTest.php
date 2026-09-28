<?php
// tests/Functional/AdressantragIncidentTest.php

namespace Tests\Functional;

use App\Database;
use Tests\Support\HttpClient;

/**
 * Ein offener Adressantrag überlebt keine Incident-Response (Audit M16).
 *
 * DER ANGRIFF. Wer kurz eine Sitzung und das Passwort hat, beantragt eine
 * eigene Adresse, bestätigt aber noch nicht. Das Opfer bekommt den Hinweis
 * und ändert - wie die Mail empfahl - das Passwort, oder die Verwaltung
 * setzt es neu. Alle Sitzungen und Schlüssel sind weg, der Antrag blieb. Der
 * Angreifer bestätigte ihn danach ohne Anmeldung, bekam die Adresse und
 * holte sich das Konto über „Passwort vergessen".
 *
 * Der Antrag wird direkt in der Datenbank gesetzt - mit dem Abdruck eines
 * bekannten Tokens, wie ProfileController::requestEmailChange() ihn schreibt.
 * So hängt der Test nicht am Step-up vor dem Antrag (M17).
 *
 * Jeder der sechs Wege muss den Antrag verwerfen: der Bestätigungslink führt
 * danach ins Leere, und die Adresse bleibt die alte.
 */
class AdressantragIncidentTest extends FunctionalTestCase {

    /** @var array<int, string> */
    private array $aufraeumen = [];

    protected function tearDown(): void {
        $stmt = Database::getInstance()->prepare("DELETE FROM users WHERE username = ?");
        foreach ($this->aufraeumen as $name) {
            $stmt->execute([$name]);
        }
        $this->aufraeumen = [];
        parent::tearDown();
    }

    /** Setzt einen offenen Antrag und liefert den Klartext des Tokens. */
    private function antragStellen(int $userId): string {
        $token = bin2hex(random_bytes(32));
        Database::getInstance()->prepare(
            "UPDATE users SET pending_email = ?, pending_email_token = ?,
                    pending_email_expires_at = NOW() + INTERVAL 48 HOUR
             WHERE id = ?"
        )->execute(['angreifer-' . $userId . '-' . uniqid() . '@example.org', hash('sha256', $token), $userId]);
        return $token;
    }

    /** Antrag weg, Link wertlos, Adresse unverändert. */
    private function assertAntragVerworfen(int $userId, string $token, string $alteAdresse): void {
        $stmt = Database::getInstance()->prepare(
            "SELECT email, pending_email, pending_email_token, pending_email_expires_at FROM users WHERE id = ?"
        );
        $stmt->execute([$userId]);
        $zeile = $stmt->fetch();
        $this->assertNull($zeile['pending_email'], 'Der offene Antrag muss verworfen sein.');
        $this->assertNull($zeile['pending_email_token']);
        $this->assertNull($zeile['pending_email_expires_at']);

        $link = $this->newClient()->get('/profil/email/bestaetigen?token=' . urlencode($token));
        $this->assertSame('/profil?error=email_token_invalid', $link->location());

        $stmt->execute([$userId]);
        $this->assertSame($alteAdresse, $stmt->fetch()['email'], 'Die Adresse darf sich nicht geändert haben.');
    }

    /** @return array{client: HttpClient, username: string, email: string, passwort: string, id: int} */
    private function konto(string $prefix): array {
        $konto = $this->angemeldetOhneFaktor($this->authenticatedClient(), $prefix);
        $this->aufraeumen[] = $konto['username'];
        return $konto;
    }

    private function adminBearbeiten(HttpClient $admin, array $konto, array $felder): void {
        $form = $admin->get('/admin/users/edit?id=' . $konto['id']);
        $antwort = $admin->post('/admin/users/update', $felder + [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'id' => (string)$konto['id'],
            'username' => $konto['username'],
            'email' => $konto['email'],
        ]);
        $this->assertSame('/admin/users?success=updated', $antwort->location(), "Body: {$antwort->body}");
    }

    // ---- (1) Selbst geändertes Passwort ---------------------------------

    public function testPasswortwechselImProfil(): void {
        $konto = $this->konto('aiprofil');
        $token = $this->antragStellen($konto['id']);

        $antwort = $konto['client']->post('/profil/passwort', [
            'csrf_token' => $this->editorCsrfToken($konto['client']),
            'current_password' => $konto['passwort'],
            'new_password' => 'EinGanzNeuesPasswort1!',
            'new_password_confirm' => 'EinGanzNeuesPasswort1!',
        ]);
        $this->assertSame('/login?success=password_changed', $antwort->location());

        $this->assertAntragVerworfen($konto['id'], $token, $konto['email']);
    }

    // ---- (2) Reset per Link ----------------------------------------------

    public function testResetPerLink(): void {
        $konto = $this->konto('aireset');
        $token = $this->antragStellen($konto['id']);

        $resetToken = bin2hex(random_bytes(32));
        Database::getInstance()->prepare(
            "INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, NOW() + INTERVAL 15 MINUTE)"
        )->execute([$konto['email'], hash('sha256', $resetToken)]);

        $gast = $this->newClient();
        $seite = $gast->get('/reset-password?token=' . urlencode($resetToken));
        $antwort = $gast->post('/reset-password', [
            'csrf_token' => $seite->formField('csrf_token') ?? '',
            'token' => $resetToken,
            'password' => 'ResetNeuesPasswort1!',
            'password_confirm' => 'ResetNeuesPasswort1!',
        ]);
        $this->assertSame('/login?success=password_reset', $antwort->location(), "Body: {$antwort->body}");

        $this->assertAntragVerworfen($konto['id'], $token, $konto['email']);
    }

    // ---- (3) Erzwungener Wechsel -----------------------------------------

    public function testErzwungenerWechsel(): void {
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $gruppe = $this->createGroupWithoutTwoFa($admin, "Incident {$unique}");
        $name = "aizwang{$unique}";
        $email = "aizwang-{$unique}@example.com";
        $this->aufraeumen[] = $name;

        $form = $admin->get('/admin/users/create');
        $admin->post('/admin/users/store', [
            'csrf_token' => $form->formField('csrf_token') ?? '',
            'username' => $name,
            'email' => $email,
            'password' => 'ZwangErst123!',
            'groups' => [(string)$gruppe],
        ]);
        $client = $this->newClient();
        $login = $client->post('/login', [
            'csrf_token' => $client->get('/login')->formField('csrf_token') ?? '',
            'kennung' => $name,
            'password' => 'ZwangErst123!',
        ]);
        $this->assertSame('/force-password-change', $login->location());

        $id = self::kontoIdNachName($name);
        $token = $this->antragStellen($id);

        $antwort = $client->post('/force-password-change', [
            'csrf_token' => $client->get('/force-password-change')->formField('csrf_token') ?? '',
            'current_password' => 'ZwangErst123!',
            'password' => 'ZwangNeu456!!',
            'password_confirm' => 'ZwangNeu456!!',
        ]);
        $this->assertSame('/admin?password_changed=1', $antwort->location());

        $this->assertAntragVerworfen($id, $token, $email);
    }

    // ---- (4) Neusetzung durch die Verwaltung -----------------------------

    public function testNeusetzungDurchDieVerwaltung(): void {
        $konto = $this->konto('aiadminpw');
        $token = $this->antragStellen($konto['id']);

        $this->adminBearbeiten($this->authenticatedClient(), $konto, ['password' => 'VerwaltungNeu123!']);

        $this->assertAntragVerworfen($konto['id'], $token, $konto['email']);
    }

    // ---- (5) Adressänderung durch die Verwaltung --------------------------

    public function testAdressaenderungDurchDieVerwaltung(): void {
        $konto = $this->konto('aiadminmail');
        $token = $this->antragStellen($konto['id']);
        $neu = 'verwaltung-' . uniqid() . '@example.com';

        $this->adminBearbeiten($this->authenticatedClient(), $konto, ['email' => $neu]);

        $this->assertAntragVerworfen($konto['id'], $token, $neu);
    }

    // ---- (6) 2FA-Reset -----------------------------------------------------

    public function testZweiFaReset(): void {
        $konto = $this->konto('aireset2fa');
        $token = $this->antragStellen($konto['id']);
        $admin = $this->authenticatedClient();

        $antwort = $admin->post('/admin/users/reset-2fa', [
            'csrf_token' => $this->currentCsrfToken($admin),
            'id' => (string)$konto['id'],
        ]);
        $this->assertSame('/admin/users?success=2fa_reset', $antwort->location());

        $this->assertAntragVerworfen($konto['id'], $token, $konto['email']);
    }

    // ---- Gegenprobe ---------------------------------------------------------

    /**
     * Ändert die Verwaltung nur die Gruppen, bleibt der Antrag bestehen - er
     * ist kein Verdachtsfall, und ein legitimer Antrag soll nicht bei jeder
     * Kleinigkeit verschwinden.
     */
    public function testNurGruppenLassenDenAntragStehen(): void {
        $konto = $this->konto('aigruppen');
        $token = $this->antragStellen($konto['id']);

        $this->adminBearbeiten($this->authenticatedClient(), $konto, ['groups' => []]);

        $stmt = Database::getInstance()->prepare("SELECT pending_email_token FROM users WHERE id = ?");
        $stmt->execute([$konto['id']]);
        $this->assertSame(hash('sha256', $token), $stmt->fetchColumn());
    }
}
