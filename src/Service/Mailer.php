<?php
// src/Service/Mailer.php

namespace App\Service;

use App\Database;
use App\Security\Crypto;

class Mailer {

    private array $config = [];

    public function __construct() {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'mail_%' OR setting_key LIKE 'smtp_%' OR setting_key = 'site_name' OR setting_key = 'base_url' OR setting_key = 'admin_notification_email'");
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $this->config[$row['setting_key']] = $row['setting_value'];
        }
    }

    /**
     * Helper to get configured base URL
     */
    public function getBaseUrl(): string {
        if (!empty($this->config['base_url'])) {
            return rtrim($this->config['base_url'], '/') . '/';
        }
        if (defined('APP_URL') && !empty(APP_URL)) {
            return rtrim(APP_URL, '/') . '/';
        }
        // Fallback ohne base_url/APP_URL: Host-Header nur validiert übernehmen
        // (Issue #116, Reset-Link-Poisoning) - siehe App\Security\TrustedHost.
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = \App\Security\TrustedHost::resolve() ?: 'hengstverzeichnis.de';
        return $scheme . $host . '/';
    }

    /**
     * Kann diese Installation ueberhaupt Mail versenden? Prueft NUR die
     * Konfiguration, nimmt keine Verbindung auf und verschickt nichts.
     *
     * Gedacht fuer Stellen, die eine Zusage an den Mailversand knuepfen und
     * das dem Betreiber sagen sollten, BEVOR er sich darauf verlaesst - allen
     * voran die Update-Automatik: "wer automatisch einspielen laesst, muss
     * erfahren, was passiert ist" ist wertlos, wenn keine Mail rausgeht.
     *
     * Der Anlass ist ein echter Befund von der Dev-Instanz dieses Hosts: Dort
     * war die Automatik samt Benachrichtigung eingeschaltet, aber in den
     * Settings stand keine einzige smtp_*-Zeile. Formal war die Bedingung
     * erfuellt, praktisch waere jedes automatische Update stumm geblieben -
     * sichtbar nur im Audit-Log, in das niemand schaut, der nichts ahnt.
     *
     * Bewusst dieselben Bedingungen wie in send()/sendViaSmtp(), nur ohne
     * Nebenwirkung; wer dort etwas aendert, zieht hier nach. Reine Funktion,
     * damit sie sich isoliert festnageln laesst.
     *
     * @param array<string, mixed> $config Settings-Array wie in $this->config
     */
    public static function isDeliverable(array $config): bool {
        $driver = $config['mail_driver'] ?? 'smtp';

        // PHP mail() braucht keine eigene Konfiguration - ob der MTA des
        // Systems tatsaechlich zustellt, laesst sich von hier aus nicht
        // sagen, und eine Vermutung waere schlechter als keine Aussage.
        if ($driver === 'mail') {
            return true;
        }

        $verschluesselung = strtolower(trim((string)($config['smtp_encryption'] ?? 'tls')));
        if (!in_array($verschluesselung, ['ssl', 'tls'], true)) {
            return false;
        }

        return trim((string)($config['smtp_host'] ?? '')) !== ''
            && trim((string)($config['smtp_user'] ?? '')) !== '';
    }

    /**
     * Send an email using SMTP (SSL/TLS enforced) or PHP mail()
     */
    public function send(string $toEmail, string $subject, string $htmlBody, string $textBody = ''): bool {
        $driver = $this->config['mail_driver'] ?? 'smtp';
        // ?: statt ??, weil updateMailSettings() die Schlüssel auch mit Leerstring
        // schreibt - ein leeres "Absender E-Mail"-Feld muss trotzdem auf smtp_user
        // zurückfallen, sonst geht jede Mail mit leerem MAIL FROM raus (#132).
        $fromEmail = ($this->config['mail_from_email'] ?? '') ?: (($this->config['smtp_user'] ?? '') ?: 'noreply@' . (\App\Security\TrustedHost::resolve() ?: 'localhost'));
        $fromName = ($this->config['mail_from_name'] ?? '') ?: (($this->config['site_name'] ?? '') ?: 'Hengstverzeichnis');

        // Defense in depth gegen SMTP-Command-/Header-Injection: Empfänger- und
        // Absenderadresse fließen roh in MAIL FROM/RCPT TO bzw. die To:/From:-Header
        // ein. In den Aufrufpfaden sind sie zwar bereits per FILTER_VALIDATE_EMAIL
        // geprüft, ein hier durchrutschendes CR/LF könnte aber zusätzliche
        // Befehle/Header einschleusen - daher zusätzlich hart ablehnen.
        if (preg_match('/[\r\n]/', $toEmail) || preg_match('/[\r\n]/', $fromEmail)) {
            AuditLogger::log("E-Mail-Versand abgelehnt (ungültige Adresse)", "email", "CR/LF in Empfänger- oder Absenderadresse", null, "SYSTEM");
            return false;
        }

        if (empty($textBody)) {
            $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));
        }

        if ($driver === 'mail') {
            return $this->sendViaPhpMail($toEmail, $subject, $htmlBody, $fromEmail, $fromName);
        }

        return $this->sendViaSmtp($toEmail, $subject, $htmlBody, $textBody, $fromEmail, $fromName);
    }

    /**
     * Ultra-secure SMTP client with enforced SSL or TLS
     */
    private function sendViaSmtp(string $toEmail, string $subject, string $htmlBody, string $textBody, string $fromEmail, string $fromName): bool {
        $host = trim($this->config['smtp_host'] ?? '');
        $port = (int)($this->config['smtp_port'] ?? 587);
        $encryption = strtolower(trim($this->config['smtp_encryption'] ?? 'tls')); // 'ssl' or 'tls'
        $user = trim($this->config['smtp_user'] ?? '');
        $rawPass = $this->config['smtp_pass'] ?? '';

        // Strict security enforcement: Unencrypted SMTP is prohibited!
        if (!in_array($encryption, ['ssl', 'tls'], true)) {
            $msg = "Unverschlüsselter SMTP-Versand verboten. Modus muss 'ssl' oder 'tls' sein.";
            error_log("Security Policy Violation: " . $msg);
            AuditLogger::log("SMTP Fehler: Unverschlüsselt verboten", "email", "Empfänger: {$toEmail}, Betreff: {$subject}, Host: {$host}:{$port}", null, "SYSTEM");
            return false;
        }

        if (empty($host) || empty($user)) {
            error_log("SMTP Configuration Incomplete: Missing host or user.");
            AuditLogger::log("SMTP Fehler: Unvollständige Konfiguration", "email", "Empfänger: {$toEmail}, Host oder Benutzer fehlt", null, "SYSTEM");
            return false;
        }

        // Decrypt password if encrypted
        $pass = Crypto::decrypt($rawPass);

        $timeout = 15;
        $socket = null;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ]
        ]);

        if ($encryption === 'ssl') {
            $socket = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        } else {
            // TLS / STARTTLS
            $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        }

        if (!$socket) {
            error_log("SMTP Connection Error ({$errno}): {$errstr}");
            return false;
        }

        stream_set_timeout($socket, $timeout);

        // Helper to read server response
        $readResponse = function() use ($socket) {
            $response = '';
            while ($line = fgets($socket, 512)) {
                $response .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $response;
        };

        // Helper to send SMTP command
        $sendCommand = function(string $command) use ($socket, $readResponse) {
            fputs($socket, $command . "\r\n");
            return $readResponse();
        };

        $greeting = $readResponse();
        if (substr($greeting, 0, 3) !== '220') {
            fclose($socket);
            return false;
        }

        // EHLO
        $ehlo = $sendCommand("EHLO " . gethostname());
        if (substr($ehlo, 0, 3) !== '250') {
            fclose($socket);
            return false;
        }

        // Handle STARTTLS for TLS encryption
        if ($encryption === 'tls') {
            $startTls = $sendCommand("STARTTLS");
            if (substr($startTls, 0, 3) !== '220') {
                fclose($socket);
                return false;
            }

            $cryptoResult = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if (!$cryptoResult) {
                fclose($socket);
                return false;
            }

            // Re-EHLO after TLS handshake
            $sendCommand("EHLO " . gethostname());
        }

        // AUTH LOGIN
        $authRes = $sendCommand("AUTH LOGIN");
        if (substr($authRes, 0, 3) !== '334') {
            fclose($socket);
            return false;
        }

        $userRes = $sendCommand(base64_encode($user));
        if (substr($userRes, 0, 3) !== '334') {
            fclose($socket);
            return false;
        }

        $passRes = $sendCommand(base64_encode($pass));
        if (substr($passRes, 0, 3) !== '235') {
            fclose($socket);
            return false;
        }

        // MAIL FROM
        $mailFromRes = $sendCommand("MAIL FROM:<{$fromEmail}>");
        if (substr($mailFromRes, 0, 3) !== '250') {
            fclose($socket);
            return false;
        }

        // RCPT TO
        $rcptToRes = $sendCommand("RCPT TO:<{$toEmail}>");
        if (substr($rcptToRes, 0, 3) !== '250') {
            fclose($socket);
            return false;
        }

        // DATA
        $dataRes = $sendCommand("DATA");
        if (substr($dataRes, 0, 3) !== '354') {
            fclose($socket);
            return false;
        }

        // Construct MIME Message
        $boundary = "====_Boundary_" . md5(uniqid(time(), true));
        $headers = [];
        $headers[] = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>";
        $headers[] = "To: <{$toEmail}>";
        $headers[] = "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
        $headers[] = "Date: " . date('r');

        $body = implode("\r\n", $headers) . "\r\n\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($textBody)) . "\r\n";

        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";

        $body .= "--{$boundary}--\r\n.\r\n";

        fputs($socket, $body);
        $sendRes = $readResponse();

        $sendCommand("QUIT");
        fclose($socket);

        $success = (substr($sendRes, 0, 3) === '250');
        if ($success) {
            AuditLogger::log("E-Mail versendet (SMTP)", "email", "Empfänger: {$toEmail}, Betreff: {$subject}", null, "SYSTEM");
        } else {
            AuditLogger::log("SMTP Fehler: E-Mail abgelehnt", "email", "Empfänger: {$toEmail}, Antwort: " . trim($sendRes), null, "SYSTEM");
        }

        return $success;
    }

    private function sendViaPhpMail(string $toEmail, string $subject, string $htmlBody, string $fromEmail, string $fromName): bool {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . "=?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>",
            'Reply-To: ' . $fromEmail,
            'X-Mailer: PHP/' . phpversion()
        ];

        $success = @mail($toEmail, "=?UTF-8?B?" . base64_encode($subject) . "?=", $htmlBody, implode("\r\n", $headers));
        if ($success) {
            AuditLogger::log("E-Mail versendet (mail())", "email", "Empfänger: {$toEmail}, Betreff: {$subject}", null, "SYSTEM");
        } else {
            AuditLogger::log("E-Mail Fehler (mail() fehlgeschlagen)", "email", "Empfänger: {$toEmail}, Betreff: {$subject}", null, "SYSTEM");
        }

        return $success;
    }

    // --- High-level Triggers ---

    public function sendWelcomeEmail(string $userEmail, string $userName, string $initialPassword): bool {
        $siteName = $this->config['site_name'] ?? 'Hengstverzeichnis';
        $loginUrl = $this->getBaseUrl() . 'login';

        $subject = "Willkommen bei {$siteName} - Ihr Konto wurde erstellt";
        $html = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;'>
                <h2 style='color: #2a52be;'>Willkommen bei {$siteName}!</h2>
                <p>Hallo <strong>" . htmlspecialchars($userName) . "</strong>,</p>
                <p>für Sie wurde ein Benutzerkonto im Portal <strong>{$siteName}</strong> eingerichtet.</p>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 6px; margin: 20px 0;'>
                    <p style='margin: 0 0 10px 0;'><strong>Benutzername / E-Mail:</strong> " . htmlspecialchars($userEmail) . "</p>
                    <p style='margin: 0;'><strong>Passwort:</strong> <code>" . htmlspecialchars($initialPassword) . "</code></p>
                </div>
                <p>Bitte melden Sie sich an und richten Sie umgehend die verpflichtende 2-Faktor-Authentifizierung (2FA) ein.</p>
                <p style='margin-top: 25px;'><a href='{$loginUrl}' style='background: #2a52be; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Jetzt Anmelden</a></p>
            </div>
        ";

        return $this->send($userEmail, $subject, $html);
    }

    public function sendDsgvoNotification(string $requesterEmail, string $requestType, string $messageDetails = '', ?string $requesterName = null): bool {
        $adminEmail = $this->config['admin_notification_email'] ?? ($this->config['mail_from_email'] ?? 'admin@example.com');
        $siteName = $this->config['site_name'] ?? 'Hengstverzeichnis';

        $subject = "⚠️ Neue DSGVO-Anfrage ({$requestType}) - {$siteName}";
        $nameText = $requesterName ? htmlspecialchars($requesterName) . " &lt;" . htmlspecialchars($requesterEmail) . "&gt;" : htmlspecialchars($requesterEmail);
        $messageText = !empty($messageDetails) ? htmlspecialchars($messageDetails) : 'Keine zusätzlichen Anmerkungen angegeben.';
        $gdprAdminUrl = $this->getBaseUrl() . 'admin/gdpr';

        $html = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;'>
                <h2 style='color: #dc3545;'>Neue DSGVO-Anfrage eingegangen</h2>
                <p>Auf der Website <strong>{$siteName}</strong> wurde eine neue Datenschutzanfrage eingereicht:</p>
                <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                    <tr><td style='padding: 8px; font-weight: bold; width: 140px;'>Anfragender:</td><td style='padding: 8px;'>{$nameText}</td></tr>
                    <tr><td style='padding: 8px; font-weight: bold;'>Art der Anfrage:</td><td style='padding: 8px;'>" . htmlspecialchars($requestType) . "</td></tr>
                </table>
                <p><strong>Nachricht / Details:</strong></p>
                <div style='background: #f8f9fa; padding: 15px; border-radius: 6px; white-space: pre-wrap;'>{$messageText}</div>
                <p style='color: #666; font-size: 0.85rem; margin-top: 25px;'>Sie können diese Anfrage direkt im Admin-Bereich unter <a href='{$gdprAdminUrl}'>{$gdprAdminUrl}</a> verwalten und verarbeiten.</p>
            </div>
        ";

        return $this->send($adminEmail, $subject, $html);
    }

    public function sendPasswordResetEmail(string $userEmail, string $resetToken): bool {
        $siteName = $this->config['site_name'] ?? 'Hengstverzeichnis';
        $resetUrl = $this->getBaseUrl() . "reset-password?token={$resetToken}";

        $subject = "Passwort zurücksetzen - {$siteName}";
        $html = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;'>
                <h2 style='color: #2a52be;'>Passwort zurücksetzen</h2>
                <p>Sie haben das Zurücksetzen Ihres Passworts für <strong>{$siteName}</strong> angefordert.</p>
                <p>Klicken Sie auf den folgenden Link, um ein neues Passwort zu vergeben. Der Link ist <strong>15 Minuten</strong> lang gültig und verfällt sofort nach der Nutzung:</p>
                <p style='margin: 25px 0;'><a href='{$resetUrl}' style='background: #2a52be; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Passwort zurücksetzen</a></p>
                <p style='font-size: 0.85rem; color: #666;'>Falls der Button nicht funktioniert, kopieren Sie diesen Link in Ihren Browser:<br><a href='{$resetUrl}'>{$resetUrl}</a></p>
                <p style='color: #888; font-size: 0.85rem; margin-top: 20px;'>Hinweis: Aus Sicherheitsgründen bleibt Ihre 2-Faktor-Authentifizierung (2FA) weiterhin aktiv.</p>
            </div>
        ";

        return $this->send($userEmail, $subject, $html);
    }

    /**
     * Verifizierungs-Mail der Selfservice-Registrierung (#83): Der Link
     * bestätigt die E-Mail-Adresse und schaltet damit die Erstanmeldung frei
     * (siehe RegistrationController::verify()).
     */
    public function sendEmailVerification(string $userEmail, string $verificationToken): bool {
        $siteName = $this->config['site_name'] ?? 'Hengstverzeichnis';
        $verifyUrl = $this->getBaseUrl() . "verify-email?token={$verificationToken}";

        $subject = "E-Mail-Adresse bestätigen - {$siteName}";
        $html = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;'>
                <h2 style='color: #2a52be;'>E-Mail-Adresse bestätigen</h2>
                <p>Sie haben sich bei <strong>{$siteName}</strong> registriert.</p>
                <p>Klicken Sie auf den folgenden Link, um Ihre E-Mail-Adresse zu bestätigen und Ihr Konto zu aktivieren. Der Link ist <strong>48 Stunden</strong> lang gültig:</p>
                <p style='margin: 25px 0;'><a href='{$verifyUrl}' style='background: #2a52be; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold;'>E-Mail-Adresse bestätigen</a></p>
                <p style='font-size: 0.85rem; color: #666;'>Falls der Button nicht funktioniert, kopieren Sie diesen Link in Ihren Browser:<br><a href='{$verifyUrl}'>{$verifyUrl}</a></p>
                <p style='color: #888; font-size: 0.85rem; margin-top: 20px;'>Falls Sie sich nicht registriert haben, können Sie diese E-Mail ignorieren - das Konto bleibt ohne Bestätigung dauerhaft inaktiv.</p>
            </div>
        ";

        return $this->send($userEmail, $subject, $html);
    }

    /**
     * Meldet NEU verfügbare Updates (#290, siehe
     * App\Service\UpdateService::runCheckAndNotify()). Wird bewusst nur bei
     * neuen Funden versendet - eine Erinnerung an dieselbe, längst gemeldete
     * Version alle drei Stunden wäre Lärm, den man wegfiltert, und damit
     * genau die Meldung entwertet, auf die es ankommt.
     *
     * @param array<int, array{slug: string, version: string}> $newAddons
     */
    public function sendUpdatesAvailableNotification(
        string $recipientEmail,
        ?string $coreVersion,
        array $newAddons,
        bool $autoInstallEnabled
    ): bool {
        $siteName = $this->config['site_name'] ?? 'Hengstverzeichnis';
        $updatesUrl = $this->getBaseUrl() . 'admin/updates';

        $subject = "📦 Update verfügbar - {$siteName}";

        $rows = '';
        if ($coreVersion !== null) {
            $rows .= "
                <tr>
                    <td style='padding: 10px; border-bottom: 1px solid #eee;'>Kern (Hengstverzeichnis)</td>
                    <td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right; font-weight: bold;'>"
                        . htmlspecialchars($coreVersion) . "</td>
                </tr>
            ";
        }
        foreach ($newAddons as $addon) {
            $rows .= "
                <tr>
                    <td style='padding: 10px; border-bottom: 1px solid #eee;'>Addon <code>"
                        . htmlspecialchars($addon['slug']) . "</code></td>
                    <td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right; font-weight: bold;'>"
                        . htmlspecialchars($addon['version']) . "</td>
                </tr>
            ";
        }

        $hint = $autoInstallEnabled
            ? 'Die automatische Installation ist aktiviert - sofern die Version in den gewählten Rahmen fällt, wird sie beim nächsten täglichen Lauf eingespielt (mit vorherigem Pflicht-Backup).'
            : 'Die automatische Installation ist nicht aktiviert - das Update wird erst eingespielt, wenn Sie es im Admin-Bereich anstoßen.';

        $html = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;'>
                <h2 style='color: #2a52be;'>Neue Version verfügbar</h2>
                <p>Für <strong>{$siteName}</strong> steht Folgendes bereit:</p>
                <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                    {$rows}
                </table>
                <p>{$hint}</p>
                <p style='margin: 25px 0;'><a href='{$updatesUrl}' style='background: #2a52be; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Zur Update-Seite</a></p>
                <p style='color: #888; font-size: 0.85rem; margin-top: 20px;'>Diese Nachricht wird je Fund nur einmal versendet, nicht bei jeder Prüfung erneut.</p>
            </div>
        ";

        return $this->send($recipientEmail, $subject, $html);
    }

    /**
     * Ergebnis eines UNBEAUFSICHTIGTEN Update-Laufs (#290/#85). Anders als
     * bei einem manuell angestoßenen Update sitzt hier niemand davor - ohne
     * diese Nachricht fiele ein fehlgeschlagener nächtlicher Lauf erst beim
     * nächsten zufälligen Blick ins Audit-Log auf.
     *
     * @param array<int, string> $addonFailureReasons
     */
    public function sendAutoUpdateNotification(
        string $recipientEmail,
        bool $success,
        string $fromVersion,
        ?string $toVersion,
        ?string $errorMessage,
        array $addonFailureReasons = []
    ): bool {
        $siteName = $this->config['site_name'] ?? 'Hengstverzeichnis';
        $updatesUrl = $this->getBaseUrl() . 'admin/updates';

        if ($success) {
            $subject = "✅ Automatisches Update auf {$toVersion} eingespielt - {$siteName}";
            $heading = "<h2 style='color: #1e7e34;'>Update erfolgreich eingespielt</h2>";
            $body = "<p><strong>{$siteName}</strong> wurde automatisch von Version <strong>"
                . htmlspecialchars($fromVersion) . "</strong> auf <strong>"
                . htmlspecialchars((string)$toVersion) . "</strong> aktualisiert. "
                . "Vor dem Einspielen wurde wie vorgeschrieben ein Backup ausgeführt.</p>";
        } else {
            $subject = "⚠️ Automatisches Update fehlgeschlagen - {$siteName}";
            $heading = "<h2 style='color: #dc3545;'>Automatisches Update fehlgeschlagen</h2>";
            $body = "<p>Der automatische Update-Lauf für <strong>{$siteName}</strong> (installiert: <strong>"
                . htmlspecialchars($fromVersion) . "</strong>) wurde abgebrochen:</p>"
                . "<div style='background: #f8f9fa; padding: 15px; border-radius: 6px; white-space: pre-wrap;'>"
                . htmlspecialchars((string)$errorMessage) . "</div>"
                . "<p>Die Installation läuft unverändert weiter. Bitte prüfen Sie die Ursache - "
                . "solange sie besteht, wird jeder weitere Lauf ebenfalls scheitern.</p>";
        }

        $addonHint = '';
        if ($addonFailureReasons !== []) {
            $items = '';
            foreach ($addonFailureReasons as $reason) {
                $items .= '<li>' . htmlspecialchars($reason) . '</li>';
            }
            $addonHint = "<p><strong>Addons konnten nicht vollständig mitgezogen werden:</strong></p><ul>{$items}</ul>";
        }

        $html = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;'>
                {$heading}
                {$body}
                {$addonHint}
                <p style='margin: 25px 0;'><a href='{$updatesUrl}'>Update-Seite öffnen</a></p>
            </div>
        ";

        return $this->send($recipientEmail, $subject, $html);
    }

    /**
     * Periodischer E-Mail-Digest an Admins/Editoren (#52, siehe
     * App\Service\DigestService): fasst Ereignisse zusammen, für die es
     * keine sofortige Benachrichtigung gibt (offene Blutlinien-Match-
     * Vorschläge, bald ablaufende Papierkorb-Fristen).
     */
    public function sendAdminDigest(string $recipientEmail, int $matchSuggestionCount, int $expiringTrashCount): bool {
        $siteName = $this->config['site_name'] ?? 'Hengstverzeichnis';
        $matchesUrl = $this->getBaseUrl() . 'admin/matches';
        $trashUrl = $this->getBaseUrl() . 'admin/trash';

        $subject = "📋 Digest: Offene Aufgaben - {$siteName}";

        $items = '';
        if ($matchSuggestionCount > 0) {
            $items .= "
                <tr>
                    <td style='padding: 10px; border-bottom: 1px solid #eee;'>🔗 Offene Blutlinien-Match-Vorschläge</td>
                    <td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right; font-weight: bold;'>{$matchSuggestionCount}</td>
                    <td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right;'><a href='{$matchesUrl}'>Ansehen</a></td>
                </tr>
            ";
        }
        if ($expiringTrashCount > 0) {
            $items .= "
                <tr>
                    <td style='padding: 10px; border-bottom: 1px solid #eee;'>🗑️ Papierkorb-Einträge nahe der 30-Tage-Frist</td>
                    <td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right; font-weight: bold;'>{$expiringTrashCount}</td>
                    <td style='padding: 10px; border-bottom: 1px solid #eee; text-align: right;'><a href='{$trashUrl}'>Ansehen</a></td>
                </tr>
            ";
        }

        $html = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;'>
                <h2 style='color: #2a52be;'>Offene Aufgaben bei {$siteName}</h2>
                <p>Dieser periodische Digest fasst Ereignisse zusammen, die aktuell auf Ihre Prüfung warten:</p>
                <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                    {$items}
                </table>
                <p style='color: #888; font-size: 0.85rem; margin-top: 20px;'>Sie erhalten diesen Digest, weil Sie als Admin oder Editor bei {$siteName} registriert sind. Der Digest wird nur versendet, wenn es tatsächlich etwas zu berichten gibt.</p>
            </div>
        ";

        return $this->send($recipientEmail, $subject, $html);
    }
}
