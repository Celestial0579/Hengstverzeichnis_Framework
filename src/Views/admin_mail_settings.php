<?php
// src/Views/admin_mail_settings.php
/**
 * @var array $settings
 * @var string|null $success
 * @var string|null $error
 */
?>
<div class="card" style="max-width: 750px;">
    <h2>✉️ E-Mail & SMTP Einstellungen</h2>
    <p style="color: #666;">Konfigurieren Sie den E-Mail-Versand für Willkommensmails, DSGVO-Benachrichtigungen und Passwort-Resets.</p>

    <?php if (!empty($_GET['success'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            <?= $_GET['success'] === 'test_sent' ? '✓ Test-E-Mail wurde erfolgreich versendet!' : 'E-Mail-Einstellungen erfolgreich gespeichert.' ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form action="/admin/mail-settings" method="POST">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <div class="form-group">
            <label for="mail_driver">E-Mail Treiber</label>
            <select id="mail_driver" name="mail_driver" class="form-control" onchange="toggleSmtpFields(this.value)">
                <option value="smtp" <?= ($settings['mail_driver'] ?? 'smtp') === 'smtp' ? 'selected' : '' ?>>SMTP Server (Empfohlen - Verschlüsselt)</option>
                <option value="mail" <?= ($settings['mail_driver'] ?? '') === 'mail' ? 'selected' : '' ?>>PHP mail() Funktion</option>
            </select>
        </div>

        <div id="smtp_fields" style="background: #f8f9fa; padding: 1.2rem; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 1.5rem;">
            <h4 style="margin-top: 0; color: var(--primary-color);">🔒 SMTP Server Konfiguration</h4>

            <div style="display: flex; gap: 1rem;">
                <div class="form-group" style="flex: 2;">
                    <label for="smtp_host">SMTP Host *</label>
                    <input type="text" id="smtp_host" name="smtp_host" class="form-control" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>" placeholder="z. B. smtp.hetzner.de oder mail.gmx.net">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label for="smtp_port">Port *</label>
                    <input type="number" id="smtp_port" name="smtp_port" class="form-control" value="<?= htmlspecialchars((string)($settings['smtp_port'] ?? '587')) ?>" placeholder="587 / 465">
                </div>
            </div>

            <div class="form-group">
                <label for="smtp_encryption">Verschlüsselung (Pflicht) *</label>
                <select id="smtp_encryption" name="smtp_encryption" class="form-control" style="border: 2px solid var(--primary-color); font-weight: bold;">
                    <option value="tls" <?= ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>🔒 STARTTLS / TLS (Standard Port 587)</option>
                    <option value="ssl" <?= ($settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>🔒 SSL / SMTPS (Standard Port 465)</option>
                </select>
                <small style="color: #28a745; font-weight: bold; display: block; margin-top: 0.3rem;">✓ Unverschlüsselter Versand ist aus Sicherheitsgründen gesperrt.</small>
            </div>

            <div style="display: flex; gap: 1rem;">
                <div class="form-group" style="flex: 1;">
                    <label for="smtp_user">SMTP Benutzername *</label>
                    <input type="text" id="smtp_user" name="smtp_user" class="form-control" value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>" placeholder="absender@beispiel.de">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label for="smtp_pass">SMTP Passwort *</label>
                    <input type="password" id="smtp_pass" name="smtp_pass" class="form-control" placeholder="<?= !empty($settings['smtp_pass']) ? '•••••••• (unverändert)' : 'Passwort eingeben' ?>">
                    <small style="color: #666;">Wird mit AES-256-GCM verschlüsselt gespeichert.</small>
                </div>
            </div>
        </div>

        <h4 style="color: var(--primary-color);">Absender & Benachrichtigungen</h4>

        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 1;">
                <label for="mail_from_email">Absender E-Mail</label>
                <input type="email" id="mail_from_email" name="mail_from_email" class="form-control" value="<?= htmlspecialchars($settings['mail_from_email'] ?? '') ?>" placeholder="noreply@verband.de">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="mail_from_name">Absender Name</label>
                <input type="text" id="mail_from_name" name="mail_from_name" class="form-control" value="<?= htmlspecialchars($settings['mail_from_name'] ?? '') ?>" placeholder="Verbands-Portal">
            </div>
        </div>

        <div class="form-group">
            <label for="admin_notification_email">Empfänger E-Mail für DSGVO-Benachrichtigungen</label>
            <input type="email" id="admin_notification_email" name="admin_notification_email" class="form-control" value="<?= htmlspecialchars($settings['admin_notification_email'] ?? '') ?>" placeholder="datenschutz@verband.de">
            <small style="color: #666;">An diese Adresse werden neue Anfragen aus dem DSGVO-Formular gesendet.</small>
        </div>

        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
            <button type="submit" class="btn">Einstellungen Speichern</button>
            <a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a>
        </div>
    </form>

    <hr style="margin: 2rem 0; border: none; border-top: 1px solid #eee;">

    <h3>🧪 Test E-Mail Senden</h3>
    <form action="/admin/mail-settings/test" method="POST" style="display: flex; gap: 1rem; align-items: flex-end;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        <div style="flex: 1;">
            <label for="test_email">Empfänger E-Mail-Adresse</label>
            <input type="email" id="test_email" name="test_email" class="form-control" placeholder="ihre-adresse@example.com" required>
        </div>
        <button type="submit" class="btn btn-secondary">✉️ Test-E-Mail senden</button>
    </form>
</div>

<script>
function toggleSmtpFields(driver) {
    document.getElementById('smtp_fields').style.display = driver === 'smtp' ? 'block' : 'none';
}
</script>
