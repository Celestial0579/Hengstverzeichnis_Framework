<?php
// src/Views/2fa_verify.php
/**
 * @var string|null $error
 */
?>
<div class="card" style="max-width: 400px; margin: 4rem auto;">
    <h2 class="text-center" style="margin-bottom: 0.5rem;"><?= htmlspecialchars(App\I18n\Translator::t('auth.2fa_heading')) ?></h2>
    <p class="text-center" style="color: #666; font-size: 0.95rem; margin-bottom: 1.5rem;">
        <?= htmlspecialchars(App\I18n\Translator::t('auth.2fa_instructions')) ?>
    </p>

    <?php if (isset($error)): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 0.8rem; border-radius: 4px; margin-bottom: 1rem; text-align: center;">
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

    <div style="text-align: center; margin-top: 1.5rem; border-top: 1px solid #eee; padding-top: 1rem;">
        <a href="/2fa/backup" style="font-size: 0.9rem; color: #666; text-decoration: none;">
            <?= htmlspecialchars(App\I18n\Translator::t('auth.2fa_lost_phone')) ?>
        </a>
    </div>
</div>
