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
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'hengstverzeichnis.de';
        return $scheme . $host . '/';
    }

    /**
     * Send an email using SMTP (SSL/TLS enforced) or PHP mail()
     */
    public function send(string $toEmail, string $subject, string $htmlBody, string $textBody = ''): bool {
        $driver = $this->config['mail_driver'] ?? 'smtp';
        $fromEmail = $this->config['mail_from_email'] ?? ($this->config['smtp_user'] ?? 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $fromName = $this->config['mail_from_name'] ?? ($this->config['site_name'] ?? 'Hengstverzeichnis');

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
