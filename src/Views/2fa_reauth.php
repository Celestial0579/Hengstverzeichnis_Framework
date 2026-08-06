<?php
// src/Views/2fa_reauth.php
/**
 * Step-up-Reauthentifizierung vor einer 2FA-Neukonfiguration (#112, siehe
 * AuthController::show2faSetup()/process2faReauth()): Wer die bestehende
 * 2FA neu einrichten will, muss zunächst Passwort UND aktuellen Code der
 * bestehenden Authentikator-App bestätigen.
 *
 * @var string|null $error
 */
?>
<div class="card" style="max-width: 500px; margin: 2rem auto;">
    <h1 style="border-bottom: 2px solid var(--primary-color); padding-bottom: 0.5rem; margin-bottom: 1rem;">
        🔐 2FA-Änderung bestätigen
    </h1>

    <p style="color: #555; margin-bottom: 1.5rem;">
        Für Ihr Konto ist bereits eine 2-Faktor-Authentifizierung aktiv. Um sie neu
        einzurichten (neuer geheimer Schlüssel und neue Backup-Codes), bestätigen Sie
        bitte zunächst Ihr aktuelles Passwort und einen aktuellen 6-stelligen Code
        aus Ihrer Authentikator-App.
    </p>

    <?php if (isset($error)): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form action="/2fa/reauth" method="POST">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <div class="form-group">
            <label for="password">Aktuelles Passwort *</label>
            <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password">
        </div>

        <div class="form-group">
            <label for="totp_code">Aktueller 6-stelliger Code *</label>
            <input type="text" id="totp_code" name="totp_code" class="form-control" placeholder="123456" maxlength="6" pattern="[0-9]{6}" required autocomplete="off" style="font-size: 1.3rem; letter-spacing: 4px; text-align: center; max-width: 200px;">
        </div>

        <button type="submit" class="btn mt-2" style="width: 100%; font-size: 1.1rem; padding: 0.8rem;">
            Bestätigen & 2FA neu einrichten
        </button>
    </form>
</div>
