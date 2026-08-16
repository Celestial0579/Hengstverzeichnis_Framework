<?php
// src/Views/2fa_setup.php
/**
 * @var string $secret
 * @var string $otpAuthUrl
 * @var array $backupCodes
 * @var string|null $error
 */
?>
<div class="card" style="max-width: 650px; margin: 2rem auto;">
    <h1 style="border-bottom: 2px solid var(--primary-fg); padding-bottom: 0.5rem; margin-bottom: 1rem;">
        🔐 2-Faktor-Authentifizierung (2FA) Einrichtung
    </h1>

    <p style="color: var(--text-muted); margin-bottom: 1.5rem;">
        Zur Erhöhung der Sicherheit ist die 2-Faktor-Authentifizierung für alle Benutzer verpflichtet. Bitte richten Sie Ihre Authentikator-App (z. B. Google Authenticator, 1Password, Bitwarden) ein.
    </p>

    <?php if (isset($error)): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Secret und Backup-Codes liegen serverseitig in der Session (#112,
         siehe AuthController::show2faSetup()) - sie werden hier nur angezeigt
         und bewusst NICHT als Formularfelder zurückgeschickt. -->
    <form action="/2fa/enable" method="POST">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <h3 style="color: var(--primary-fg); margin-bottom: 0.5rem;">Schritt 1: Authentikator-App verknüpfen</h3>
        <p style="font-size: 0.95rem; color: var(--text-muted); margin-bottom: 1rem;">
            Scannen Sie diesen QR-Code oder geben Sie den Schlüssel manuell in Ihrer App ein:
        </p>

        <!-- Hintergrund bleibt bewusst fest weiß und schaltet NICHT mit dem Darkmode um:
             Der QR-Code darunter wird als dunkles Muster gezeichnet und braucht hellen
             Grund, sonst kehrt sich der Kontrast um und Authentikator-Apps erkennen ihn
             nicht mehr. Der Kasten enthält nur das Bild, kein erbendes Textelement. -->
        <div style="text-align: center; margin: 1.5rem 0; background: #fff; padding: 1rem; border: 1px solid var(--border-color); border-radius: 8px;">
            <!-- QR Code wird lokal im Browser gerendert (public/js/qrcode.js) - das TOTP-Secret
                 verlässt dafür nie den Server/Client, anders als bei einem Drittanbieter-API-Aufruf -->
            <div id="qrcode-canvas" role="img" aria-label="2FA QR Code" style="display: inline-block;"></div>
            <!-- Liegt im bewusst fest weißen QR-Kasten und setzt deshalb ebenfalls
                 ein festes Farbpaar: Eine mitschaltende Fläche wäre hier eine dunkle
                 Pille auf weißem Grund. -->
            <p style="margin-top: 1rem; font-family: monospace; font-size: 1.1rem; background: #f0f0f0; color: #222222; padding: 0.5rem; display: inline-block; border-radius: 4px;">
                Geheimer Schlüssel: <strong><?= htmlspecialchars($secret) ?></strong>
            </p>
        </div>
        <script src="/js/qrcode.js"></script>
        <script>
            new QRCode(document.getElementById('qrcode-canvas'), {
                text: <?= json_encode($otpAuthUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                width: 200,
                height: 200,
                correctLevel: QRCode.CorrectLevel.M
            });
        </script>

        <h3 style="color: var(--danger-fg); margin-top: 2rem; margin-bottom: 0.5rem;">
            ⚠️ Schritt 2: WICHTIG – Ihre 10 Backup-Codes
        </h3>
        <p style="font-size: 0.95rem; color: var(--text-muted); margin-bottom: 1rem;">
            Speichern oder drucken Sie diese 10 Wiederherstellungscodes aus. Falls Sie Ihr Smartphone oder Ihre Authentikator-App verlieren, sind diese Codes der <strong>einzige Weg</strong>, um wieder Zugriff auf Ihr Konto zu erhalten! Jeder Code kann genau 1x verwendet werden.
        </p>

        <div style="background: var(--surface-muted); border: 2px dashed #dc3545; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-family: monospace; font-size: 1.1rem; text-align: center;">
                <?php foreach ($backupCodes as $code): ?>
                    <div style="background: var(--card-bg); padding: 0.4rem; border: 1px solid var(--border-color); border-radius: 4px; font-weight: bold;">
                        <?= htmlspecialchars($code) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group" style="margin-bottom: 1.5rem;">
            <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: bold; cursor: pointer; color: var(--danger-fg);">
                <input type="checkbox" name="confirm_backup" value="1" required style="width: 18px; height: 18px;">
                Ich habe meine 10 Backup-Codes sicher abgespeichert.
            </label>
        </div>

        <h3 style="color: var(--primary-fg); margin-bottom: 0.5rem;">Schritt 3: Verifizierung</h3>
        <div class="form-group">
            <label for="totp_code">Geben Sie den 6-stelligen Code aus Ihrer Authentikator-App ein *</label>
            <input type="text" id="totp_code" name="totp_code" class="form-control" placeholder="123456" maxlength="6" pattern="[0-9]{6}" required autocomplete="off" style="font-size: 1.3rem; letter-spacing: 4px; text-align: center; max-width: 200px;">
        </div>

        <button type="submit" class="btn mt-2" style="width: 100%; font-size: 1.1rem; padding: 0.8rem;">
            2FA aktivieren & Einrichtung abschließen
        </button>
    </form>
</div>
