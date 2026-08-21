<?php
// src/Views/2fa_reauth.php
/**
 * Step-up-Reauthentifizierung vor einer 2FA-Neukonfiguration (#112, siehe
 * AuthController::show2faSetup()/process2faReauth()): Wer die bestehende
 * 2FA neu einrichten will, muss zunächst Passwort UND aktuellen Code der
 * bestehenden Authentikator-App bestätigen.
 *
 * Welcher Code verlangt wird, richtet sich nach dem Faktor, den das Konto
 * HAT (#354) - sonst waere die Seite fuer ein Konto ohne Authentikator-App
 * eine Sackgasse: Es koennte nie eine nachruesten.
 *
 * @var string|null $error
 * @var array<int, string> $faktoren
 * @var bool $mailcodeAngefordert
 */
$hatTotp = in_array(App\Security\SecondFactors::TOTP, $faktoren ?? [], true);
?>
<div class="card" style="max-width: 500px; margin: 2rem auto;">
    <h1 style="border-bottom: 2px solid var(--primary-fg); padding-bottom: 0.5rem; margin-bottom: 1rem;">
        🔐 2FA-Änderung bestätigen
    </h1>

    <p style="color: var(--text-muted); margin-bottom: 1.5rem;">
        Für Ihr Konto ist bereits eine 2-Faktor-Authentifizierung aktiv. Um sie neu
        einzurichten (neuer geheimer Schlüssel und neue Backup-Codes), bestätigen Sie
        bitte zunächst Ihr aktuelles Passwort und
        <?= $hatTotp
            ? 'einen aktuellen 6-stelligen Code aus Ihrer Authentikator-App.'
            : 'einen Einmalcode an Ihre hinterlegte E-Mail-Adresse.' ?>
    </p>

    <?php if (!$hatTotp): ?>
        <form action="/2fa/reauth/code" method="POST" style="margin-bottom: 1.2rem;">
            <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
            <button type="submit" class="btn btn-secondary" style="width: 100%;">
                <?= $mailcodeAngefordert ?? false ? 'Neuen Code schicken' : 'Code per E-Mail schicken' ?>
            </button>
        </form>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
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
            <?php if ($hatTotp): ?>
                <label for="totp_code">Aktueller 6-stelliger Code *</label>
                <input type="text" id="totp_code" name="totp_code" class="form-control" placeholder="123456" maxlength="6" pattern="[0-9]{6}" required autocomplete="off" style="font-size: 1.3rem; letter-spacing: 4px; text-align: center; max-width: 200px;">
            <?php else: ?>
                <label for="email_code">Code aus der E-Mail *</label>
                <input type="text" id="email_code" name="email_code" class="form-control" placeholder="123456" maxlength="6" pattern="[0-9]{6}" required inputmode="numeric" autocomplete="one-time-code" style="font-size: 1.3rem; letter-spacing: 4px; text-align: center; max-width: 200px;">
            <?php endif; ?>
        </div>

        <button type="submit" class="btn mt-2" style="width: 100%; font-size: 1.1rem; padding: 0.8rem;">
            Bestätigen & 2FA neu einrichten
        </button>
    </form>
</div>
