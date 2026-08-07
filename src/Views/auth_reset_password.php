<?php
// src/Views/auth_reset_password.php
/**
 * @var string $token
 * @var string|null $error
 */
?>
<div class="card" style="max-width: 450px; margin: 2rem auto;">
    <h2><?= htmlspecialchars(App\I18n\Translator::t('auth.reset_heading')) ?></h2>
    <p style="color: var(--text-muted); font-size: 0.95rem;"><?= htmlspecialchars(App\I18n\Translator::t('auth.reset_intro')) ?></p>

    <?php if (isset($error)): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form action="/reset-password" method="POST" style="margin-top: 1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

        <div class="form-group">
            <label for="password"><?= htmlspecialchars(App\I18n\Translator::t('auth.new_password_label')) ?></label>
            <input type="password" id="password" name="password" class="form-control" required minlength="8" autofocus>
        </div>

        <div class="form-group">
            <label for="password_confirm"><?= htmlspecialchars(App\I18n\Translator::t('auth.confirm_password_label')) ?></label>
            <input type="password" id="password_confirm" name="password_confirm" class="form-control" required minlength="8">
        </div>

        <div style="background: #e9ecef; padding: 0.8rem; border-radius: 6px; font-size: 0.85rem; color: #495057; margin-bottom: 1rem;">
            <?= App\I18n\Translator::t('auth.security_hint_html') ?>
        </div>

        <button type="submit" class="btn" style="width: 100%;"><?= htmlspecialchars(App\I18n\Translator::t('auth.save_password_button')) ?></button>
    </form>
</div>
