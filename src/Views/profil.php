<?php
/**
 * Selbstbedienung fuer das eigene Konto (#357).
 *
 * Deutsch hartkodiert wie die Nachbarseite api_keys.php - beide sind
 * Selbstbedienungsseiten desselben Bereichs, und ein uebersetztes Profil neben
 * einer deutschen Schluesselverwaltung waere ein Bruch mitten im Ablauf.
 *
 * @var array $konto
 * @var int $backupCodesOffen
 * @var array<int, string> $neueCodes
 */
$meldungen = [
    'password_changed' => 'Passwort geändert.',
    'backup_codes' => 'Neue Backup-Codes erzeugt. Die alten gelten nicht mehr.',
    'email_requested' => 'Bestätigungslink an die neue Adresse geschickt. Sie gilt erst, wenn er angeklickt wurde.',
    'email_changed' => 'E-Mail-Adresse bestätigt und übernommen.',
    'email_cancelled' => 'Adressänderung abgebrochen.',
];
$fehler = [
    'current_password_wrong' => 'Das aktuelle Passwort stimmt nicht.',
    'mismatch' => 'Die beiden neuen Passwörter stimmen nicht überein.',
    'too_short' => 'Das neue Passwort braucht mindestens 12 Zeichen.',
    'same_password' => 'Das neue Passwort ist das alte.',
    'rate_limited' => 'Zu viele Versuche. Bitte später erneut versuchen.',
    'totp_wrong' => 'Der 6-stellige Code stimmt nicht (oder wurde schon verwendet).',
    'no_2fa' => 'Ohne eingerichtete Zwei-Faktor-Anmeldung gibt es keine Backup-Codes.',
    'email_invalid' => 'Bitte eine gültige E-Mail-Adresse angeben (höchstens 100 Zeichen).',
    'email_unchanged' => 'Das ist bereits Ihre Adresse.',
    'email_token_invalid' => 'Der Bestätigungslink ist ungültig oder abgelaufen.',
];
$csrf = App\Router::generateCsrfToken();
?>
<div class="card" style="max-width: 780px;">
    <h1 style="font-size: 1.4rem; margin-top: 0;">Mein Profil</h1>
    <p style="color: var(--text-muted);">
        Angemeldet als <strong><?= htmlspecialchars((string)$konto['username']) ?></strong><?php
        if (!empty($konto['email'])): ?> &middot; <?= htmlspecialchars((string)$konto['email']) ?><?php
        else: ?> &middot; <em>keine E-Mail-Adresse hinterlegt</em><?php endif; ?>
    </p>

    <?php if ($success !== null && isset($meldungen[$success])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 0.8rem; border-radius: 4px; margin: 1rem 0;">
            <?= htmlspecialchars($meldungen[$success]) ?>
        </div>
    <?php endif; ?>
    <?php if ($error !== null && isset($fehler[$error])): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 0.8rem; border-radius: 4px; margin: 1rem 0;">
            <?= htmlspecialchars($fehler[$error]) ?>
        </div>
    <?php endif; ?>

    <?php if ($neueCodes !== []): ?>
        <div style="background-color: var(--warning-soft-bg); color: var(--warning-fg); padding: 0.8rem; border-radius: 4px; margin: 1rem 0;">
            <strong>Ihre neuen Backup-Codes &ndash; jetzt notieren.</strong>
            Sie werden nur dieses eine Mal angezeigt; danach liegen sie nur noch als Hash vor.
            <div style="font-family: monospace; margin-top: 0.6rem; columns: 2;">
                <?php foreach ($neueCodes as $c): ?>
                    <div><?= htmlspecialchars($c) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="card" style="max-width: 780px; margin-top: 1.5rem;">
    <h2 style="font-size: 1.15rem; margin-top: 0;">Passwort ändern</h2>
    <p style="color: var(--text-muted); font-size: 0.9rem;">
        Nach dem Wechsel enden <strong>alle</strong> Sitzungen dieses Kontos &ndash; auch diese hier &ndash;
        und alle ausgestellten API-Schlüssel werden widerrufen. Das ist Absicht: Ein Passwortwechsel
        ist die übliche Reaktion auf einen Verdacht, und dann soll wirklich nichts Altes weiterlaufen.
    </p>
    <form method="POST" action="/profil/passwort">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <label for="current_password" style="display:block; font-weight:bold; margin-top:0.8rem;">Aktuelles Passwort</label>
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password" style="width:100%; padding:0.5rem;">
        <label for="new_password" style="display:block; font-weight:bold; margin-top:0.8rem;">Neues Passwort (mindestens 12 Zeichen)</label>
        <input type="password" id="new_password" name="new_password" required minlength="12" autocomplete="new-password" style="width:100%; padding:0.5rem;">
        <label for="new_password_confirm" style="display:block; font-weight:bold; margin-top:0.8rem;">Neues Passwort wiederholen</label>
        <input type="password" id="new_password_confirm" name="new_password_confirm" required minlength="12" autocomplete="new-password" style="width:100%; padding:0.5rem;">
        <button type="submit" class="btn btn-primary" style="margin-top:1rem;">Passwort ändern</button>
    </form>
</div>

<div class="card" style="max-width: 780px; margin-top: 1.5rem;">
    <h2 style="font-size: 1.15rem; margin-top: 0;">Zwei-Faktor-Anmeldung</h2>
    <?php if (empty($konto['totp_enabled'])): ?>
        <p style="color: var(--text-muted);">Noch nicht eingerichtet.</p>
        <a href="/2fa/setup" class="btn">Jetzt einrichten</a>
    <?php else: ?>
        <p style="color: var(--text-muted);">
            Eingerichtet. Noch <strong><?= (int)$backupCodesOffen ?></strong> ungenutzte Backup-Code(s).
            <?php if ($backupCodesOffen <= 2): ?>
                <span style="color: var(--danger-fg); font-weight: bold;">Das wird knapp &ndash; erzeugen Sie neue.</span>
            <?php endif; ?>
        </p>
        <form method="POST" action="/profil/backup-codes"
              data-confirm="Neue Backup-Codes erzeugen? Die bisherigen verlieren damit sofort ihre Gültigkeit.">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 0.5rem;">
                Verlangt Passwort <em>und</em> einen gültigen Code aus Ihrer App &ndash; derselbe Maßstab
                wie beim Einrichten. Zehn frische Backup-Codes sind dasselbe Material wie ein neues Geheimnis.
            </p>
            <label for="bc_password" style="display:block; font-weight:bold;">Aktuelles Passwort</label>
            <input type="password" id="bc_password" name="current_password" required autocomplete="current-password" style="width:100%; padding:0.5rem;">
            <label for="bc_totp" style="display:block; font-weight:bold; margin-top:0.8rem;">6-stelliger Code</label>
            <input type="text" id="bc_totp" name="totp_code" required inputmode="numeric" autocomplete="one-time-code" maxlength="6" style="padding:0.5rem;">
            <button type="submit" class="btn" style="margin-top:1rem;">Backup-Codes neu erzeugen</button>
        </form>
    <?php endif; ?>
</div>

<div class="card" style="max-width: 780px; margin-top: 1.5rem;">
    <h2 style="font-size: 1.15rem; margin-top: 0;">E-Mail-Adresse</h2>

    <?php if (!empty($konto['pending_email'])): ?>
        <div style="background-color: var(--warning-soft-bg); color: var(--warning-fg); padding: 0.8rem; border-radius: 4px; margin-bottom: 1rem;">
            Offener Antrag auf <strong><?= htmlspecialchars((string)$konto['pending_email']) ?></strong>.
            Bis zur Bestätigung gilt weiterhin die bisherige Adresse.
            <form method="POST" action="/profil/email/abbrechen" style="margin-top:0.6rem;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="btn btn-secondary" style="padding:0.3rem 0.8rem; font-size:0.85rem;">Antrag abbrechen</button>
            </form>
        </div>
    <?php endif; ?>

    <p style="color: var(--text-muted); font-size: 0.9rem;">
        Die neue Adresse gilt erst, wenn Sie den Link darin angeklickt haben. An die bisherige Adresse
        geht gleichzeitig ein Hinweis &ndash; so fällt es auf, wenn jemand anders Ihr Konto umträgt.
        <?php if (empty($konto['email'])): ?>
            <br><strong>Ohne Adresse gibt es kein „Passwort vergessen"</strong>; nur das Verwaltungsteam
            kann Ihr Passwort dann neu setzen. Und ohne zweiten Faktor wird ein solches Konto nach
            180 Tagen deaktiviert.
        <?php endif; ?>
    </p>
    <form method="POST" action="/profil/email">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <label for="new_email" style="display:block; font-weight:bold;">Neue E-Mail-Adresse</label>
        <input type="email" id="new_email" name="new_email" required maxlength="100" style="width:100%; padding:0.5rem;">
        <label for="mail_password" style="display:block; font-weight:bold; margin-top:0.8rem;">Aktuelles Passwort</label>
        <input type="password" id="mail_password" name="current_password" required autocomplete="current-password" style="width:100%; padding:0.5rem;">
        <button type="submit" class="btn" style="margin-top:1rem;">Adresse beantragen</button>
    </form>
</div>

<div class="card" style="max-width: 780px; margin-top: 1.5rem;">
    <h2 style="font-size: 1.15rem; margin-top: 0;">API-Schlüssel</h2>
    <p style="color: var(--text-muted);">
        Schlüssel für die JSON-API werden auf einer eigenen Seite verwaltet &ndash; dort steht auch,
        wann sie ablaufen.
    </p>
    <a href="/api-keys" class="btn btn-secondary">Zu den API-Schlüsseln</a>
</div>
