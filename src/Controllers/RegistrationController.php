<?php
// src/Controllers/RegistrationController.php

namespace App\Controllers;

use App\Database;

/**
 * Class RegistrationController
 *
 * Optionale Selfservice-Registrierung (#83): pro Installation zentral über
 * die Systemeinstellung `registration_enabled` de-/aktivierbar (Standard:
 * deaktiviert - die Registrierung ist die einzige öffentliche Schreibfläche
 * für Benutzerkonten und vergrößert die Angriffsfläche nicht ungefragt).
 *
 * Sicherheits-Leitplanken:
 * - E-Mail-Verifizierung vor Erstaktivierung: Das Konto erhält bei der
 *   Registrierung einen Einmal-Token (48 h gültig); solange er gesetzt ist,
 *   blockiert AuthController::loginSubmit() jede Anmeldung.
 * - Rate-Limiting pro Client-IP (RateLimiter, Typ 'registration') gegen
 *   automatisierte Massenregistrierung, analog zum Passwort-Reset.
 * - Neue Konten landen ausschließlich in der vom Admin gewählten
 *   Standard-Gruppe (`registration_default_group`, nie admin/public) oder -
 *   falls keine gewählt ist - in gar keiner Gruppe (keinerlei Rechte,
 *   2FA-Pflicht greift fail-safe, siehe #84/#66).
 * - Reservierte Benutzernamen sind wie bei admin-angelegten Konten gesperrt.
 * - must_change_password = 0 (Passwort wurde selbst gewählt).
 */
class RegistrationController extends BaseController {

    private function registrationEnabled(): bool {
        return ($this->settings['registration_enabled'] ?? '0') === '1';
    }

    public function showForm(): void {
        if (!$this->registrationEnabled()) {
            $this->renderNotFound();
        }

        $this->render('register', [
            'title' => \App\I18n\Translator::t('register.title'),
        ]);
    }

    public function submit(): void {
        if (!$this->registrationEnabled()) {
            $this->renderNotFound();
        }
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden(\App\I18n\Translator::t('errors.csrf_invalid'));
        }

        // Rate-Limit pro Client-IP: 5 Registrierungsversuche / Stunde. Jeder
        // POST zählt (auch fehlgeschlagene), damit Enumeration über wiederholte
        // Versuche ebenso gebremst wird wie Massenregistrierung.
        $clientIp = \App\Security\ClientIp::resolve();
        if (\App\Security\RateLimiter::tooManyAttempts($clientIp, 'registration', 5, 3600)) {
            $this->render('register', [
                'title' => \App\I18n\Translator::t('register.title'),
                'error' => \App\I18n\Translator::t('register.rate_limited'),
            ]);
            return;
        }
        \App\Security\RateLimiter::recordAttempt($clientIp, 'registration');

        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        $error = null;
        // Auch hier gilt das `@`-Verbot aus #348: Der Benutzername ist seit
        // v0.9 eine Anmeldekennung neben der E-Mail-Adresse, und die beiden
        // Namensraeume duerfen sich nicht ueberschneiden. Sonst legte sich
        // jemand per Selbstregistrierung einen Benutzernamen an, der die
        // Adresse eines fremden Kontos ist - und beide kaemen nicht mehr
        // hinein (AuthController::findeKontoFuerAnmeldung() weist die
        // mehrdeutige Kennung fail-closed ab).
        if ($username === '' || strlen($username) > 50 || \App\Security\LoginIdentifier::looksLikeEmail($username)) {
            $error = \App\I18n\Translator::t('register.username_invalid');
        } elseif ($this->isReservedUsername($username)) {
            $error = \App\I18n\Translator::t('register.username_reserved');
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = \App\I18n\Translator::t('register.email_invalid');
        } elseif (strlen($password) < 8 || $password !== $passwordConfirm) {
            $error = \App\I18n\Translator::t('register.password_invalid');
        }

        if ($error !== null) {
            $this->render('register', [
                'title' => \App\I18n\Translator::t('register.title'),
                'error' => $error,
                'old' => ['username' => $username, 'email' => $email],
            ]);
            return;
        }

        $db = Database::getInstance();
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 48 * 3600);

        try {
            $stmt = $db->prepare(
                "INSERT INTO users (username, email, password_hash, must_change_password, email_verification_token, email_verification_expires_at)
                 VALUES (?, ?, ?, 0, ?, ?)"
            );
            $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $token, $expiresAt]);
            $newUserId = (int)$db->lastInsertId();
        } catch (\PDOException $e) {
            // UNIQUE-Verletzung (Benutzername oder E-Mail vergeben) - eine
            // generische Meldung; die Existenz einzelner Adressen lässt sich
            // bei einer Registrierung prinzipbedingt nicht vollständig
            // verbergen, das Rate-Limit oben begrenzt die Enumeration.
            $this->render('register', [
                'title' => \App\I18n\Translator::t('register.title'),
                'error' => \App\I18n\Translator::t('register.already_taken'),
                'old' => ['username' => $username, 'email' => $email],
            ]);
            return;
        }

        $this->assignDefaultGroup($db, $newUserId);

        \App\Service\AuditLogger::log("Selfservice-Registrierung", "users", "Benutzer: {$username} ({$email}), wartet auf E-Mail-Verifizierung", $newUserId, $username);

        $mailer = new \App\Service\Mailer();
        $mailer->sendEmailVerification($email, $token);

        header("Location: /register?sent=1");
        exit;
    }

    /**
     * Bestätigt die E-Mail-Adresse über den Link aus der Verifizierungs-Mail
     * und schaltet damit die Anmeldung frei.
     */
    public function verify(): void {
        $token = trim($_GET['token'] ?? '');
        if ($token === '') {
            header("Location: /login");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, username FROM users WHERE email_verification_token = ? AND email_verification_expires_at > NOW() AND deleted_at IS NULL AND deactivated_at IS NULL");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            $this->render('register', [
                'title' => \App\I18n\Translator::t('register.title'),
                'error' => \App\I18n\Translator::t('register.verification_invalid'),
                'hideForm' => true,
            ]);
            return;
        }

        $stmt = $db->prepare("UPDATE users SET email_verification_token = NULL, email_verification_expires_at = NULL WHERE id = ?");
        $stmt->execute([$user['id']]);

        \App\Service\AuditLogger::log("E-Mail-Adresse verifiziert", "users", "Selfservice-Konto freigeschaltet", (int)$user['id'], $user['username']);

        header("Location: /login?success=email_verified");
        exit;
    }

    /**
     * Weist dem neuen Konto die vom Admin gewählte Standard-Gruppe zu
     * (Systemeinstellung `registration_default_group`). Serverseitige
     * Leitplanke: admin/public sind nie zulässig - ist die Einstellung leer
     * oder ungültig, erhält das Konto KEINE Gruppe (fail-closed: keinerlei
     * Rechte, siehe BaseController::userGroupIds()).
     */
    private function assignDefaultGroup(\PDO $db, int $userId): void {
        $groupId = (int)($this->settings['registration_default_group'] ?? 0);
        if ($groupId <= 0) {
            return;
        }

        try {
            $stmt = $db->prepare("SELECT id FROM `groups` WHERE id = ? AND slug NOT IN ('admin', 'public')");
            $stmt->execute([$groupId]);
            if ($stmt->fetchColumn() === false) {
                return;
            }

            $stmt = $db->prepare("INSERT IGNORE INTO user_groups (user_id, group_id) VALUES (?, ?)");
            $stmt->execute([$userId, $groupId]);
        } catch (\Throwable $e) {
            // Fail-closed: lieber keine Gruppe als eine falsche
        }
    }
}
