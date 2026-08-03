<?php
// src/Views/auth_force_password_change.php
/**
 * @var string|null $error
 */
?>
<div class="card" style="max-width: 480px; margin: 3rem auto;">
    <h2>⚠️ Erstmalige Passwortänderung erforderlich</h2>
    <p style="color: #666; font-size: 0.95rem;">
        Aus Sicherheitsgründen müssen Sie bei Ihrer ersten Anmeldung ein neues, persönliches Passwort festlegen.
    </p>

    <?php if (isset($error)): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form action="/force-password-change" method="POST" style="margin-top: 1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <div class="form-group">
            <label for="password">Neues Passwort (Mind. 8 Zeichen) *</label>
            <input type="password" id="password" name="password" class="form-control" required minlength="8" autofocus>
        </div>

        <div class="form-group">
            <label for="password_confirm">Passwort bestätigen *</label>
            <input type="password" id="password_confirm" name="password_confirm" class="form-control" required minlength="8">
        </div>

        <button type="submit" class="btn" style="width: 100%; margin-top: 1rem;">Passwort speichern & Fortfahren</button>
    </form>
</div>
