<?php
// src/Views/auth_forgot_password.php
/**
 * @var string|null $error
 * @var string|null $success
 */
?>
<div class="card" style="max-width: 450px; margin: 2rem auto;">
    <h2>🔑 Passwort vergessen</h2>
    <p style="color: #666; font-size: 0.95rem;">Geben Sie Ihre E-Mail-Adresse ein. Wir senden Ihnen einen Link zum Zurücksetzen Ihres Passworts.</p>

    <?php if (isset($_GET['sent'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            Falls ein Konto mit dieser E-Mail-Adresse existiert, wurde ein Zurücksetzungs-Link versendet. Bitte prüfen Sie Ihren Posteingang.
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
            <label for="email">E-Mail-Adresse *</label>
            <input type="email" id="email" name="email" class="form-control" placeholder="ihre-adresse@beispiel.de" required autofocus>
        </div>

        <button type="submit" class="btn" style="width: 100%; margin-top: 1rem;">Link zum Zurücksetzen anfordern</button>
    </form>

    <div style="margin-top: 1.5rem; text-align: center;">
        <a href="/login" style="color: var(--primary-color); font-size: 0.9rem;">Zurück zum Login</a>
    </div>
</div>
