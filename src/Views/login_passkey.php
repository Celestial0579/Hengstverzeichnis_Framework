<?php
// src/Views/login_passkey.php
/**
 * Passkey als zweiter Faktor im Anmeldeweg (#353).
 *
 * @var array<int, string> $andereFaktoren Weitere Faktoren dieses Kontos
 * @var bool $verfuegbar Erlaubt die Verbindung überhaupt Passkeys?
 */
$csrf = App\Router::generateCsrfToken();
$hatTotp = in_array(App\Security\SecondFactors::TOTP, $andereFaktoren, true);
$hatMail = in_array(App\Security\SecondFactors::EMAIL, $andereFaktoren, true);
?>
<div class="card" style="max-width: 420px; margin: 4rem auto;">
    <h2 class="text-center" style="margin-bottom: 0.5rem;">Anmeldung bestätigen</h2>
    <p class="text-center" style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 1.5rem;">
        Bestätigen Sie mit Ihrem Passkey &ndash; Fingerabdruck, Gesicht, Geräte-PIN
        oder Sicherheitsschlüssel.
    </p>

    <?php if (!$verfuegbar): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 0.8rem; border-radius: 4px; margin-bottom: 1rem;">
            Diese Verbindung ist nicht gesichert. Passkeys brauchen HTTPS.
            <?php if ($andereFaktoren !== []): ?>
                Bitte weichen Sie unten auf ein anderes Verfahren aus.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p data-passkey-meldung class="passkey-meldung" hidden></p>

        <button type="button" class="btn btn-primary" style="width: 100%;"
                data-passkey-anmelden data-passkey-sofort
                data-csrf="<?= htmlspecialchars($csrf) ?>">
            Mit Passkey anmelden
        </button>

        <noscript>
            <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 0.8rem; border-radius: 4px; margin-top: 1rem;">
                Passkeys brauchen JavaScript.
                <?php if ($andereFaktoren !== []): ?>
                    Bitte weichen Sie unten auf ein anderes Verfahren aus.
                <?php endif; ?>
            </div>
        </noscript>
    <?php endif; ?>

    <?php if ($hatTotp || $hatMail): ?>
        <hr style="margin: 1.5rem 0; border: none; border-top: 1px solid var(--border-color);">
        <p style="text-align: center; color: var(--text-muted); font-size: 0.9rem; margin-bottom: 0.5rem;">
            Gerät nicht zur Hand?
        </p>
        <?php if ($hatTotp): ?>
            <p style="text-align: center;">
                <a href="/login/2fa">Code aus der Authentikator-App</a>
            </p>
        <?php endif; ?>
        <?php if ($hatMail): ?>
            <?php // Ein POST, kein Link (Audit N42): Der GET auf /login/2fa/email
                  // verschickt bewusst nichts - wer von hier kommt, stuende
                  // sonst vor einem Codefeld ohne Code. ?>
            <form action="/login/2fa/email/senden" method="POST" style="text-align: center;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" style="background: none; border: none; padding: 0; cursor: pointer; color: var(--primary-fg); text-decoration: underline; font-size: inherit;">
                    Code per E-Mail senden
                </button>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <?php // Kein anderer Faktor. Wer hier nicht weiterkommt, braucht einen
              // Administrator - und soll das wissen, statt es zu raten. ?>
        <p style="text-align: center; color: var(--text-muted); font-size: 0.85rem; margin-top: 1.5rem;">
            Der Passkey ist der einzige zweite Faktor dieses Kontos.
            Kommen Sie nicht weiter, hilft nur eine Zurücksetzung durch die Verwaltung.
        </p>
    <?php endif; ?>

    <?php // Abmelden geht nur per POST mit CSRF-Token (Audit N86) - der
          // fruehere Link auf GET /logout endete in "Seite nicht gefunden",
          // und der Anmeldevorgang blieb stehen. ?>
    <form action="/logout" method="POST" style="text-align:center; margin-top:1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" style="background:none; border:none; padding:0; cursor:pointer; color:var(--text-muted); font-size:0.85rem; text-decoration:underline;">Abbrechen</button>
    </form>
</div>

<script defer src="/js/passkeys.js"></script>
