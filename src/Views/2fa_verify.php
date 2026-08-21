<?php
// src/Views/2fa_verify.php
/**
 * @var string|null $error
 * @var bool $mailcodeMoeglich Hat das Konto zusaetzlich den Mailcode (#354)?
 */
?>
<div class="card" style="max-width: 400px; margin: 4rem auto;">
    <h2 class="text-center" style="margin-bottom: 0.5rem;"><?= htmlspecialchars(App\I18n\Translator::t('auth.2fa_heading')) ?></h2>
    <p class="text-center" style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 1.5rem;">
        <?= htmlspecialchars(App\I18n\Translator::t('auth.2fa_instructions')) ?>
    </p>

    <?php if (isset($error)): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 0.8rem; border-radius: 4px; margin-bottom: 1rem; text-align: center;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form action="/login/2fa" method="POST">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <div class="form-group" style="text-align: center;">
            <label for="totp_code" style="display: block; margin-bottom: 0.5rem;"><?= htmlspecialchars(App\I18n\Translator::t('auth.2fa_code_label')) ?></label>
            <input type="text" id="totp_code" name="totp_code" class="form-control" placeholder="123456" maxlength="6" pattern="[0-9]{6}" required autofocus autocomplete="off" style="font-size: 1.5rem; letter-spacing: 5px; text-align: center; margin: 0 auto; max-width: 220px;">
        </div>

        <button type="submit" class="btn" style="width: 100%; margin-top: 1rem;"><?= htmlspecialchars(App\I18n\Translator::t('auth.login_button')) ?></button>
    </form>

    <div style="text-align: center; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
        <?php if (!empty($mailcodeMoeglich)): ?>
            <?php // Als Formular, nicht als Link: Der Versand ist eine
                  // Aktion mit Nebenwirkung und gehoert nicht hinter ein GET. ?>
            <form action="/login/2fa/email/senden" method="POST" style="margin-bottom: 0.8rem;">
                <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                <button type="submit" class="btn btn-secondary" style="width: 100%; font-size: 0.9rem;">
                    <?= htmlspecialchars(App\I18n\Translator::t('auth.2fa_email_use_instead')) ?>
                </button>
            </form>
        <?php endif; ?>
        <a href="/2fa/backup" style="font-size: 0.9rem; color: var(--text-muted); text-decoration: none;">
            <?= htmlspecialchars(App\I18n\Translator::t('auth.2fa_lost_phone')) ?>
        </a>
    </div>
</div>
