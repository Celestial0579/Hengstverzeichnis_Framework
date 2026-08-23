<?php
/**
 * Selbstbedienung fuer das eigene Konto (#357).
 *
 * Deutsch hartkodiert wie die Nachbarseite api_keys.php - beide sind
 * Selbstbedienungsseiten desselben Bereichs, und ein uebersetztes Profil neben
 * einer deutschen Schluesselverwaltung waere ein Bruch mitten im Ablauf.
 *
 * @var array $konto
 * @var array<int, string> $faktoren Aktive zweite Faktoren (#354)
 * @var bool $emailFaktorErlaubt
 * @var bool $istAdmin
 * @var bool $mailcodeAngefordert Liegt ein gueltiger Probecode bereit?
 * @var int $backupCodesOffen
 * @var array<int, string> $neueCodes
 */
$meldungen = [
    'password_changed' => 'Passwort geändert.',
    'backup_codes' => 'Neue Backup-Codes erzeugt. Die alten gelten nicht mehr.',
    'email_requested' => 'Bestätigungslink an die neue Adresse geschickt. Sie gilt erst, wenn er angeklickt wurde.',
    'email_changed' => 'E-Mail-Adresse bestätigt und übernommen.',
    'email_cancelled' => 'Adressänderung abgebrochen.',
    'code_sent' => 'Probecode verschickt. Er gilt 10 Minuten.',
    'email_factor_on' => 'Zweiter Faktor per E-Mail eingeschaltet.',
    'email_factor_off' => 'Zweiter Faktor per E-Mail ausgeschaltet.',
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
    'code_wrong' => 'Der Code aus der E-Mail stimmt nicht, ist abgelaufen oder wurde schon verwendet.',
    'code_send_failed' => 'Der Code konnte nicht versendet werden. Bitte prüfen Sie die Mail-Einstellungen oder wenden Sie sich an das Verwaltungsteam.',
    'no_email' => 'Ohne hinterlegte E-Mail-Adresse gibt es keinen Faktor per E-Mail.',
    'email_factor_not_allowed' => 'Für dieses Konto ist der Mailcode als zweiter Faktor nicht zugelassen.',
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

<?php
// Zweite Faktoren (#354). Die Seite fuehrt sie NEBENEINANDER auf, statt einen
// einzelnen Schalter zu zeigen: Es gibt heute zwei Verfahren, und mit
// Passkeys (#353) kommt ein drittes dazu.
$hatTotp = in_array(App\Security\SecondFactors::TOTP, $faktoren, true);
$hatMailcode = in_array(App\Security\SecondFactors::EMAIL, $faktoren, true);
?>
<div class="card" style="max-width: 780px; margin-top: 1.5rem;">
    <h2 style="font-size: 1.15rem; margin-top: 0;">Zwei-Faktor-Anmeldung</h2>

    <?php if ($faktoren === []): ?>
        <p style="color: var(--text-muted);">Noch kein zweiter Faktor eingerichtet.</p>
    <?php endif; ?>

    <?php
    // ---- Passkeys (#353) ------------------------------------------------
    $passkeys = App\Security\Passkeys::fuerBenutzer((int)$_SESSION['user_id']);
    $passkeyFehler = $_SESSION['passkey_fehler'] ?? null;
    $passkeyHinweis = $_SESSION['passkey_hinweis'] ?? null;
    unset($_SESSION['passkey_fehler'], $_SESSION['passkey_hinweis']);
    $passkeyCsrf = App\Router::generateCsrfToken();
    ?>
    <h3 id="passkeys" style="font-size: 1rem; margin-bottom: 0.3rem;">Passkeys</h3>
    <p style="color: var(--text-muted); margin-top: 0;">
        Der stärkste der drei Faktoren, und als einziger gegen Phishing geschützt:
        Ein Passkey ist fest an diese Adresse gebunden und lässt sich auf einer
        nachgebauten Seite gar nicht erst verwenden. Ein abgetippter Code schon.
        <?= $passkeys === [] ? 'Noch keiner eingerichtet.' : count($passkeys) . ' eingerichtet.' ?>
    </p>

    <?php if ($passkeyFehler !== null): ?>
        <div class="alert alert-danger"><?= htmlspecialchars((string)$passkeyFehler) ?></div>
    <?php endif; ?>
    <?php if ($passkeyHinweis !== null): ?>
        <div class="alert alert-success"><?= htmlspecialchars((string)$passkeyHinweis) ?></div>
    <?php endif; ?>

    <?php if ($passkeys !== []): ?>
        <div class="tabelle-scroll">
        <table class="table" style="margin-bottom: 0.8rem;">
            <thead><tr><th>Bezeichnung</th><th>Eingerichtet</th><th>Zuletzt benutzt</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($passkeys as $pk): ?>
                <tr>
                    <td><?= htmlspecialchars((string)$pk['label']) ?></td>
                    <td><?= htmlspecialchars(date('d.m.Y', strtotime((string)$pk['created_at']))) ?></td>
                    <td>
                        <?= $pk['last_used_at']
                            ? htmlspecialchars(date('d.m.Y', strtotime((string)$pk['last_used_at'])))
                            : '<span style="color: var(--text-muted);">nie</span>' ?>
                    </td>
                    <td style="text-align: right;">
                        <form method="POST" action="/passkeys/entziehen" style="display:inline;"
                              data-confirm="Diesen Passkey wirklich entziehen?">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($passkeyCsrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$pk['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Entziehen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <?php if (App\Security\Passkeys::verfuegbar()): ?>
        <p data-passkey-meldung class="passkey-meldung" hidden></p>
        <label for="passkey-bezeichnung" style="display:block; font-size:0.9rem; margin-bottom:0.3rem;">
            Bezeichnung (damit Sie ihn später wiedererkennen)
        </label>
        <input type="text" id="passkey-bezeichnung" data-passkey-bezeichnung maxlength="100"
               placeholder="z. B. Diensttelefon" style="max-width: 320px; margin-bottom: 0.6rem;">
        <br>
        <button type="button" class="btn" data-passkey-registrieren
                data-csrf="<?= htmlspecialchars($passkeyCsrf) ?>">
            Passkey hinzufügen
        </button>
        <noscript>
            <p style="color: var(--text-muted); font-size: 0.85rem;">
                Zum Einrichten eines Passkeys wird JavaScript benötigt.
            </p>
        </noscript>
    <?php else: ?>
        <p style="color: var(--text-muted); font-size: 0.85rem;">
            Passkeys brauchen eine gesicherte Verbindung (HTTPS). Über diese Verbindung
            lässt sich keiner einrichten.
        </p>
    <?php endif; ?>

    <h3 style="font-size: 1rem; margin-top: 1.6rem; margin-bottom: 0.3rem;">Authentikator-App (TOTP)</h3>
    <p style="color: var(--text-muted); margin-top: 0;">
        Der Code entsteht auf Ihrem Gerät und geht nirgends über das Netz. Schwächer als ein Passkey nur darin, dass er sich abtippen und damit auf eine nachgebaute Seite tragen lässt.
        <?= $hatTotp ? 'Eingerichtet.' : 'Nicht eingerichtet.' ?>
    </p>
    <?php if (!$hatTotp): ?>
        <a href="/2fa/setup" class="btn">Jetzt einrichten</a>
    <?php endif; ?>

    <h3 style="font-size: 1rem; margin-top: 1.6rem; margin-bottom: 0.3rem;">Einmalcode per E-Mail</h3>
    <p style="color: var(--text-muted); margin-top: 0;">
        <strong>Der schwächste der gängigen zweiten Faktoren:</strong> Wer Zugriff auf Ihr Postfach hat, hat
        damit auch diesen Faktor. Er schützt gut gegen gestohlene Passwortlisten, kaum gegen einen
        übernommenen Mailzugang &ndash; und die Zustellung ist der unzuverlässigste Teil daran. Wenn Sie die
        Wahl haben, nehmen Sie die Authentikator-App.
    </p>

    <?php if ($hatMailcode): ?>
        <p style="color: var(--text-muted);">Eingeschaltet für <strong><?= htmlspecialchars((string)$konto['email']) ?></strong>.</p>
        <?php // Der Knopf gehoert AUCH hierher, nicht nur in den Einschalt-Zweig:
              // Fuer neue Backup-Codes verlangt dieses Konto einen gueltigen
              // Mailcode (es hat ja keine App), und der Hinweis weiter unten
              // verwiese sonst auf einen Knopf, den es auf dieser Seite gar
              // nicht gibt. ?>
        <form method="POST" action="/profil/2fa/email/code" style="margin-bottom:1rem;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="btn btn-secondary"><?= $mailcodeAngefordert ? 'Neuen Code schicken' : 'Code schicken (für neue Backup-Codes)' ?></button>
        </form>
        <form method="POST" action="/profil/2fa/email/aus"
              data-confirm="Zweiten Faktor per E-Mail ausschalten?">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <label for="off_password" style="display:block; font-weight:bold;">Aktuelles Passwort</label>
            <input type="password" id="off_password" name="current_password" required autocomplete="current-password" style="width:100%; padding:0.5rem;">
            <button type="submit" class="btn btn-secondary" style="margin-top:1rem;">Ausschalten</button>
        </form>
    <?php elseif ($istAdmin): ?>
        <p style="color: var(--danger-fg);">
            Für Administratoren nicht zugelassen. Ein Konto mit allen Rechten soll nicht an einem Postfach
            hängen &ndash; richten Sie die Authentikator-App ein.
        </p>
    <?php elseif (empty($konto['email'])): ?>
        <p style="color: var(--text-muted);">
            Nicht möglich: Für Ihr Konto ist keine E-Mail-Adresse hinterlegt. Tragen Sie unten eine ein,
            wenn Sie diesen Faktor nutzen wollen.
        </p>
    <?php else: ?>
        <p style="color: var(--text-muted); font-size: 0.9rem;">
            Zum Einschalten schicken wir einen Probecode an <strong><?= htmlspecialchars((string)$konto['email']) ?></strong>.
            Erst wenn er einmal richtig eingegeben wurde, wird der Faktor scharf &ndash; eine falsch
            eingetragene Adresse sperrte Sie sonst in genau dem Moment aus.
        </p>
        <form method="POST" action="/profil/2fa/email/code" style="margin-bottom:1rem;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="btn btn-secondary"><?= $mailcodeAngefordert ? 'Neuen Probecode schicken' : 'Probecode schicken' ?></button>
        </form>
        <?php if ($mailcodeAngefordert): ?>
            <form method="POST" action="/profil/2fa/email/ein">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <label for="on_password" style="display:block; font-weight:bold;">Aktuelles Passwort</label>
                <input type="password" id="on_password" name="current_password" required autocomplete="current-password" style="width:100%; padding:0.5rem;">
                <label for="on_code" style="display:block; font-weight:bold; margin-top:0.8rem;">Probecode aus der E-Mail</label>
                <input type="text" id="on_code" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="6" style="padding:0.5rem;">
                <button type="submit" class="btn" style="margin-top:1rem;">Einschalten</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($faktoren !== []): ?>
        <h3 style="font-size: 1rem; margin-top: 1.6rem; margin-bottom: 0.3rem;">Backup-Codes</h3>
        <p style="color: var(--text-muted); margin-top: 0;">
            Noch <strong><?= (int)$backupCodesOffen ?></strong> ungenutzte Backup-Code(s).
            <?php if ($backupCodesOffen <= 2): ?>
                <span style="color: var(--danger-fg); font-weight: bold;">Das wird knapp &ndash; erzeugen Sie neue.</span>
            <?php endif; ?>
            Sie sind der Rückweg, wenn das Gerät fehlt oder keine Mail ankommt.
        </p>
        <?php if (!$hatTotp && !$mailcodeAngefordert): ?>
            <p style="color: var(--text-muted); font-size: 0.9rem;">
                Für neue Backup-Codes brauchen wir einen gültigen Code aus Ihrer E-Mail &ndash; fordern Sie
                oben einen Probecode an.
            </p>
        <?php else: ?>
            <form method="POST" action="/profil/backup-codes"
                  data-confirm="Neue Backup-Codes erzeugen? Die bisherigen verlieren damit sofort ihre Gültigkeit.">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 0.5rem;">
                    Verlangt Passwort <em>und</em> einen gültigen zweiten Faktor &ndash; derselbe Maßstab
                    wie beim Einrichten. Zehn frische Backup-Codes sind dasselbe Material wie ein neues Geheimnis.
                </p>
                <label for="bc_password" style="display:block; font-weight:bold;">Aktuelles Passwort</label>
                <input type="password" id="bc_password" name="current_password" required autocomplete="current-password" style="width:100%; padding:0.5rem;">
                <?php if ($hatTotp): ?>
                    <label for="bc_totp" style="display:block; font-weight:bold; margin-top:0.8rem;">6-stelliger Code aus der App</label>
                    <input type="text" id="bc_totp" name="totp_code" required inputmode="numeric" autocomplete="one-time-code" maxlength="6" style="padding:0.5rem;">
                <?php else: ?>
                    <label for="bc_mail" style="display:block; font-weight:bold; margin-top:0.8rem;">Probecode aus der E-Mail</label>
                    <input type="text" id="bc_mail" name="email_code" required inputmode="numeric" autocomplete="one-time-code" maxlength="6" style="padding:0.5rem;">
                <?php endif; ?>
                <button type="submit" class="btn" style="margin-top:1rem;">Backup-Codes neu erzeugen</button>
            </form>
        <?php endif; ?>
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

<script defer src="/js/passkeys.js"></script>
