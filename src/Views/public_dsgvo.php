<?php
// src/Views/public_dsgvo.php
/**
 * Öffentliches DSGVO-Portal (Art. 15/17), siehe PublicController::dsgvoSubmit().
 *
 * @var string|null $error (optional) Fehlermeldung (Rate-Limit, CAPTCHA, Validierung)
 * @var array $old (optional) Zuvor eingegebene Werte
 * @var string $captchaQuestion Aufgabentext des Spam-Schutzes (siehe App\Security\Captcha)
 */
$t = fn(string $key) => htmlspecialchars(App\I18n\Translator::t($key));
$old = $old ?? [];
?>
<div class="card" style="max-width: 650px; margin: 0 auto;">
    <h2><?= $t('dsgvo.heading') ?></h2>
    <p><?= $t('dsgvo.intro') ?></p>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= $t('dsgvo.success') ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form action="/dsgvo" method="POST" style="margin-top: 1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <div class="form-group">
            <label for="name"><?= $t('dsgvo.name_label') ?></label>
            <input type="text" id="name" name="name" class="form-control" maxlength="100" placeholder="<?= $t('dsgvo.name_placeholder') ?>" value="<?= htmlspecialchars($old['name'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label for="email"><?= $t('dsgvo.email_label') ?></label>
            <input type="email" id="email" name="email" class="form-control" maxlength="100" placeholder="<?= $t('dsgvo.email_placeholder') ?>" required value="<?= htmlspecialchars($old['email'] ?? '') ?>">
            <small class="form-hint"><?= $t('dsgvo.email_hint') ?></small>
        </div>

        <div class="form-group">
            <label for="request_type"><?= $t('dsgvo.request_type_label') ?></label>
            <select id="request_type" name="request_type" class="form-control" required>
                <option value="info"<?= (($old['request_type'] ?? 'info') === 'info') ? ' selected' : '' ?>><?= $t('dsgvo.request_type_info') ?></option>
                <option value="deletion"<?= (($old['request_type'] ?? '') === 'deletion') ? ' selected' : '' ?>><?= $t('dsgvo.request_type_deletion') ?></option>
            </select>
        </div>

        <div class="form-group">
            <label for="message"><?= $t('dsgvo.message_label') ?></label>
            <textarea id="message" name="message" class="form-control" rows="4" maxlength="5000" placeholder="<?= $t('dsgvo.message_placeholder') ?>"><?= htmlspecialchars($old['message'] ?? '') ?></textarea>
        </div>

        <?php
        // Honeypot: für Menschen unsichtbar, aber kein type="hidden" - genau
        // dieses Feld füllen automatische Formularausfüller aus (siehe
        // App\Security\Captcha::honeypotTripped()). Aus dem Fokus- und
        // Vorlese-Fluss genommen, damit es Tastatur- und Screenreader-Nutzer
        // nicht erreicht.
        ?>
        <div style="position: absolute; left: -9999px;" aria-hidden="true">
            <label for="<?= htmlspecialchars(App\Security\Captcha::HONEYPOT_FIELD) ?>"><?= $t('dsgvo.honeypot_label') ?></label>
            <input type="text" id="<?= htmlspecialchars(App\Security\Captcha::HONEYPOT_FIELD) ?>" name="<?= htmlspecialchars(App\Security\Captcha::HONEYPOT_FIELD) ?>" tabindex="-1" autocomplete="off" value="">
        </div>

        <div class="form-group">
            <label for="captcha"><?= $t('dsgvo.captcha_label') ?></label>
            <div style="margin-bottom: 0.5rem; font-size: 1.1rem;">
                <strong><?= htmlspecialchars($captchaQuestion) ?></strong> =
            </div>
            <input type="text" id="captcha" name="captcha" class="form-control" inputmode="numeric" autocomplete="off" maxlength="2" required style="max-width: 8rem;">
            <small class="form-hint"><?= $t('dsgvo.captcha_hint') ?></small>
        </div>

        <button type="submit" class="btn mt-2"><?= $t('dsgvo.submit') ?></button>
    </form>
</div>
