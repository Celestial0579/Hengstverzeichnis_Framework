<?php
// src/Views/auth_forgot_password.php
/**
 * @var string|null $error
 * @var string|null $success
 */
?>
<div class="card" style="max-width: 450px; margin: 2rem auto;">
    <h2><?= htmlspecialchars(App\I18n\Translator::t('auth.forgot_heading')) ?></h2>
    <p style="color: var(--text-muted); font-size: 0.95rem;"><?= htmlspecialchars(App\I18n\Translator::t('auth.forgot_intro')) ?></p>

    <?php if (isset($_GET['sent'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars(App\I18n\Translator::t('auth.forgot_sent')) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form action="/forgot-password" method="POST" style="margin-top: 1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <div class="form-group">
            <label for="email"><?= htmlspecialchars(App\I18n\Translator::t('auth.email_field_label')) ?></label>
            <input type="email" id="email" name="email" class="form-control" placeholder="<?= htmlspecialchars(App\I18n\Translator::t('auth.email_field_placeholder')) ?>" required autofocus>
        </div>

        <button type="submit" class="btn" style="width: 100%; margin-top: 1rem;"><?= htmlspecialchars(App\I18n\Translator::t('auth.request_reset_button')) ?></button>
    </form>

    <div style="margin-top: 1.5rem; text-align: center;">
        <a href="/login" style="color: var(--primary-fg); font-size: 0.9rem;"><?= htmlspecialchars(App\I18n\Translator::t('auth.back_to_login')) ?></a>
    </div>
</div>
