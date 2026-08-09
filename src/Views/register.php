<?php
// src/Views/register.php
/**
 * Selfservice-Registrierung (#83, siehe RegistrationController). Nur
 * erreichbar, wenn die Systemeinstellung `registration_enabled` aktiv ist.
 *
 * @var string $error (optional)
 * @var array $old (optional) Zuvor eingegebene Werte
 * @var bool $hideForm (optional) Nur Meldung anzeigen (Verifizierungs-Fehler)
 */
$t = fn(string $key) => htmlspecialchars(App\I18n\Translator::t($key));
?>
<div class="card" style="max-width: 460px; margin: 4rem auto;">
    <h2 class="text-center"><?= $t('register.heading') ?></h2>

    <?php if (isset($_GET['sent'])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= $t('register.sent') ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($hideForm) && !isset($_GET['sent'])): ?>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;"><?= $t('register.intro') ?></p>

        <form action="/register" method="POST">
            <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

            <div class="form-group">
                <label for="username"><?= $t('register.username_label') ?></label>
                <input type="text" id="username" name="username" class="form-control" maxlength="50" required autofocus value="<?= htmlspecialchars($old['username'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="email"><?= $t('register.email_label') ?></label>
                <input type="email" id="email" name="email" class="form-control" required value="<?= htmlspecialchars($old['email'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="password"><?= $t('register.password_label') ?></label>
                <input type="password" id="password" name="password" class="form-control" minlength="8" required autocomplete="new-password">
            </div>

            <div class="form-group">
                <label for="password_confirm"><?= $t('register.password_confirm_label') ?></label>
                <input type="password" id="password_confirm" name="password_confirm" class="form-control" minlength="8" required autocomplete="new-password">
            </div>

            <button type="submit" class="btn" style="width: 100%"><?= $t('register.submit_button') ?></button>
        </form>
    <?php endif; ?>

    <p class="text-center" style="margin-top: 1.5rem; font-size: 0.9rem;">
        <a href="/login" style="color: var(--primary-fg);"><?= $t('register.back_to_login') ?></a>
    </p>
</div>
