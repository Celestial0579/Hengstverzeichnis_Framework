<?php
// src/Views/login.php
/**
 * @var string $error (optional)
 */
?>
<div class="card" style="max-width: 400px; margin: 4rem auto;">
    <h2 class="text-center">Admin Login</h2>
    
    <?php if (isset($_GET['success']) && $_GET['success'] === 'password_reset'): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars(App\I18n\Translator::t('auth.password_reset_success')) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['success']) && $_GET['success'] === 'email_verified'): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars(App\I18n\Translator::t('auth.email_verified_success')) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form action="/login" method="POST">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <div class="form-group">
            <label for="email"><?= htmlspecialchars(App\I18n\Translator::t('auth.email_label')) ?></label>
            <input type="email" id="email" name="email" class="form-control" required autofocus>
        </div>

        <div class="form-group">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <label for="password"><?= htmlspecialchars(App\I18n\Translator::t('auth.password_label')) ?></label>
                <a href="/forgot-password" style="font-size: 0.85rem; color: var(--primary-fg);"><?= htmlspecialchars(App\I18n\Translator::t('auth.forgot_password_link')) ?></a>
            </div>
            <input type="password" id="password" name="password" class="form-control" required>
        </div>

        <button type="submit" class="btn" style="width: 100%"><?= htmlspecialchars(App\I18n\Translator::t('auth.login_button')) ?></button>
    </form>

    <?php if (\App\Controllers\EntraSsoController::isConfigured()): ?>
        <div style="text-align: center; margin-top: 1rem;">
            <a href="/auth/entra" class="btn btn-secondary" style="width: 100%; display: block; box-sizing: border-box;">
                <?= htmlspecialchars(App\I18n\Translator::t('auth.entra_login_button')) ?>
            </a>
        </div>
    <?php endif; ?>

    <?php if (($settings['registration_enabled'] ?? '0') === '1'): ?>
        <p class="text-center" style="margin-top: 1.5rem; font-size: 0.9rem;">
            <a href="/register" style="color: var(--primary-fg);"><?= htmlspecialchars(App\I18n\Translator::t('auth.register_link')) ?></a>
        </p>
    <?php endif; ?>
</div>
