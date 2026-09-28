<?php
// src/Views/2fa_reauth.php
/**
 * Step-up-Bestätigung (#112, Audit M15, M17, N10; siehe App\Security\StepUp
 * und AuthController::reauthSeite()): Wer die zweiten Faktoren, die
 * Backup-Codes oder die Adresse eines Kontos mit Faktor ändern will,
 * bestätigt zuerst Passwort UND einen vorhandenen Faktor.
 *
 * Welche Felder erscheinen, richtet sich EXAKT nach StepUp::codePruefen()
 * ($codeArt): das TOTP-Feld bei TOTP, das Mailcodefeld nur bei Mailcode ohne
 * TOTP - ein Feld, das nie zählt, wäre eine Falle. Ein Konto mit Passkey
 * bekommt zusätzlich (bzw. als einzigen Weg) den Passkey-Knopf; er nimmt
 * das Passwort aus demselben Feld.
 *
 * @var string|null $error
 * @var array<int, string> $faktoren
 * @var string|null $codeArt 'totp', 'email' oder null (nur Passkey)
 * @var string $fuer Rückweg-Schlüssel (StepUp::fuer())
 * @var bool $mailcodeAngefordert
 * @var bool $passkeyMoeglich
 */
$fuer = $fuer ?? 'setup';
$codeArt = $codeArt ?? App\Security\StepUp::codeArt($faktoren ?? []);
$passkeyMoeglich = $passkeyMoeglich ?? false;
$csrf = App\Router::generateCsrfToken();

$ueberschriften = [
    'setup'    => '2FA-Änderung bestätigen',
    'profil'   => 'Änderung bestätigen',
    'passkeys' => 'Passkey-Änderung bestätigen',
    'email'    => 'Adressänderung bestätigen',
];
$anlaesse = [
    'setup'    => 'Für Ihr Konto ist bereits eine 2-Faktor-Authentifizierung aktiv. Um sie neu einzurichten (neuer geheimer Schlüssel und neue Backup-Codes), bestätigen Sie bitte zunächst Ihr aktuelles Passwort und',
    'profil'   => 'Änderungen an Ihren zweiten Faktoren und Backup-Codes verlangen eine frische Bestätigung. Bitte bestätigen Sie Ihr aktuelles Passwort und',
    'passkeys' => 'Bevor Sie einen Passkey hinzufügen oder entziehen, bestätigen Sie bitte Ihr aktuelles Passwort und',
    'email'    => 'Die E-Mail-Adresse ist der Weg für „Passwort vergessen“ und für den Mailcode. Bevor Sie sie ändern, bestätigen Sie bitte Ihr aktuelles Passwort und',
];
$nachweis = match ($codeArt) {
    App\Security\SecondFactors::TOTP => 'einen aktuellen 6-stelligen Code aus Ihrer Authentikator-App.',
    App\Security\SecondFactors::EMAIL => 'einen Einmalcode an Ihre bisherige E-Mail-Adresse.',
    default => 'einen Ihrer Passkeys.',
};
?>
<div class="card" style="max-width: 500px; margin: 2rem auto;">
    <h1 style="border-bottom: 2px solid var(--primary-fg); padding-bottom: 0.5rem; margin-bottom: 1rem;">
        🔐 <?= htmlspecialchars($ueberschriften[$fuer] ?? $ueberschriften['setup']) ?>
    </h1>

    <p style="color: var(--text-muted); margin-bottom: 1.5rem;">
        <?= htmlspecialchars(($anlaesse[$fuer] ?? $anlaesse['setup']) . ' ' . $nachweis) ?>
        Die Bestätigung gilt 10 Minuten.
    </p>

    <?php if ($codeArt === App\Security\SecondFactors::EMAIL): ?>
        <form action="/2fa/reauth/code" method="POST" style="margin-bottom: 1.2rem;">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="fuer" value="<?= htmlspecialchars($fuer) ?>">
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

    <?php // Ohne Codefeld (nur Passkey) KEIN Formular: Ein Formular mit einem
          // einzigen Feld schickt der Browser bei Enter ab - das wäre ein
          // sicherer Fehlversuch im Zähler. ?>
    <?php if ($codeArt !== null): ?>
    <form action="/2fa/reauth" method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="fuer" value="<?= htmlspecialchars($fuer) ?>">
    <?php endif; ?>

        <div class="form-group">
            <label for="password">Aktuelles Passwort *</label>
            <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password">
        </div>

        <?php if ($codeArt === App\Security\SecondFactors::TOTP): ?>
            <div class="form-group">
                <label for="totp_code">Aktueller 6-stelliger Code *</label>
                <input type="text" id="totp_code" name="totp_code" class="form-control" placeholder="123456" maxlength="6" pattern="[0-9]{6}" required autocomplete="off" style="font-size: 1.3rem; letter-spacing: 4px; text-align: center; max-width: 200px;">
            </div>
        <?php elseif ($codeArt === App\Security\SecondFactors::EMAIL): ?>
            <div class="form-group">
                <label for="email_code">Code aus der E-Mail *</label>
                <input type="text" id="email_code" name="email_code" class="form-control" placeholder="123456" maxlength="6" pattern="[0-9]{6}" required inputmode="numeric" autocomplete="one-time-code" style="font-size: 1.3rem; letter-spacing: 4px; text-align: center; max-width: 200px;">
            </div>
        <?php endif; ?>

    <?php if ($codeArt !== null): ?>
        <button type="submit" class="btn mt-2" style="width: 100%; font-size: 1.1rem; padding: 0.8rem;">
            <?= $fuer === 'setup' ? 'Bestätigen & 2FA neu einrichten' : 'Bestätigen' ?>
        </button>
    </form>
    <?php endif; ?>

    <?php if ($passkeyMoeglich): ?>
        <div style="margin-top: 1.2rem;">
            <?php if ($codeArt !== null): ?>
                <p style="color: var(--text-muted); font-size: 0.9rem;">Oder statt des Codes mit einem Passkey:</p>
            <?php endif; ?>
            <p data-passkey-meldung class="passkey-meldung" hidden></p>
            <button type="button" class="btn<?= $codeArt !== null ? ' btn-secondary' : '' ?>" style="width: 100%;" data-passkey-stepup
                    data-csrf="<?= htmlspecialchars($csrf) ?>" data-fuer="<?= htmlspecialchars($fuer) ?>">
                Mit Passkey bestätigen
            </button>
            <noscript>
                <p style="color: var(--text-muted); font-size: 0.85rem;">Die Bestätigung mit einem Passkey braucht JavaScript.</p>
            </noscript>
        </div>
        <script defer src="/js/passkeys.js"></script>
    <?php elseif ($codeArt === null): ?>
        <p style="color: var(--danger-fg); margin-top: 1rem;">
            Ihr Konto bestätigt mit einem Passkey, und Passkeys brauchen eine gesicherte Verbindung (HTTPS).
            Über diese Verbindung ist die Bestätigung nicht möglich.
        </p>
    <?php endif; ?>
</div>
