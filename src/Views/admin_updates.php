<?php
// src/Views/admin_updates.php
/**
 * Automatisches Update (#85, siehe UpdateController/UpdateService).
 *
 * @var string $currentVersion
 * @var bool $backupConfigured
 * @var string $updateChannel 'stable' oder 'beta'
 * @var array|null $checkResult
 * @var string|null $checkError
 * @var bool $inPlaceEnabled  In-Place-Selbstaktualisierung erlaubt (UPDATE_IN_PLACE)
 * @var string|null $targetVersion  Zielversion eines verfügbaren Kern-Updates (#197)
 * @var array $addonRows  Addon-Übersicht aus App\Service\AddonOverview::rows()
 * @var bool $addonCatalogAvailable
 * @var string|null $addonCatalogCachedAt
 * @var bool $notifyEnabled  E-Mail bei neu verfügbaren Updates (#290)
 * @var bool $autoInstallEnabled  Unbeaufsichtigte Installation aktiviert (#290)
 * @var string $autoInstallScope  'patch_only' oder 'any'
 * @var bool $mailDeliverable  Kann diese Installation Mail versenden (Mailer::isDeliverable())
 * @var bool $adminRecipientReachable  Gibt es ueberhaupt eine erreichbare Admin-Adresse
 */
$inPlaceEnabled = $inPlaceEnabled ?? true;
$addonRows = $addonRows ?? [];
$addonCatalogAvailable = $addonCatalogAvailable ?? false;
$notifyEnabled = $notifyEnabled ?? false;
$mailDeliverable = $mailDeliverable ?? true;
$adminRecipientReachable = $adminRecipientReachable ?? true;
$autoInstallEnabled = $autoInstallEnabled ?? false;
$autoInstallScope = $autoInstallScope ?? 'patch_only';
// Ohne Backup bzw. ohne In-Place-Recht kann die Automatik nicht laufen -
// dieselben Bedingungen wie beim manuellen Knopf, hier nur vorab sichtbar
// gemacht. Serverseitig setzt UpdateController::saveAutomation() sie durch.
$automationPossible = $inPlaceEnabled && $backupConfigured;
// Aktive Addons, die die ZIELversion des anstehenden Kern-Updates nicht
// unterstützen - sie würden nach dem Update kommentarlos deaktiviert (#197).
$addonTargetWarnings = array_values(array_filter(
    $addonRows,
    static fn(array $r): bool => $r['enabled'] && $r['reasonTarget'] !== null
));
// Davon die, für die es AUCH KEINEN Ersatz gibt (#364). Nur sie verlangen die
// getippte Bestätigung: Wo eine passende Fassung im Store liegt, zieht die
// Addon-Phase sie nach dem Kern von selbst mit, und das Update ist harmlos.
$addonOhneErsatz = \App\Service\UpdateService::addonsBlockingAutoInstall($addonRows);
?>
<div class="card" style="max-width: 700px; margin: 0 auto;">
    <h2>🔄 Updates</h2>
    <p style="color: var(--text-muted);">
        Installierte Version: <strong><?= htmlspecialchars($currentVersion) ?></strong>
        <span style="margin-left: 0.5rem; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background: <?= $updateChannel === 'beta' ? '#fff3cd' : '#e2e3e5' ?>; color: <?= $updateChannel === 'beta' ? '#856404' : '#383d41' ?>;">
            Kanal: <?= $updateChannel === 'beta' ? 'Beta' : 'Stabil' ?>
        </span>
    </p>

    <?php if (isset($_GET['channel_saved'])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 0.6rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: 0.9rem;">
            Update-Kanal gespeichert.
        </div>
    <?php endif; ?>

    <!-- Update-Kanal / Beta-Opt-in: Kandidaten sind IMMER nur strikt neuere
         Versionen (UpdateService::selectBestRelease()) - auch ein Wechsel von
         Beta zurück auf Stabil kann daher nie ein Downgrade auslösen. -->
    <form action="/admin/updates/channel" method="POST" style="display: flex; gap: 0.5rem; align-items: flex-end; flex-wrap: wrap; background: var(--surface-muted); padding: 0.8rem; border-radius: 6px; border: 1px solid #e0e0e0; margin-bottom: 1.2rem;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        <div class="form-group" style="margin: 0;">
            <label for="update_channel" style="font-size: 0.9rem;">Update-Kanal</label>
            <select id="update_channel" name="update_channel" class="form-control" style="max-width: 320px;">
                <option value="stable" <?= $updateChannel === 'stable' ? 'selected' : '' ?>>Stabil (empfohlen)</option>
                <option value="beta" <?= $updateChannel === 'beta' ? 'selected' : '' ?>>Beta (Vorabversionen einbeziehen)</option>
            </select>
        </div>
        <button type="submit" class="btn btn-secondary" style="padding: 0.4rem 0.9rem;">Speichern &amp; prüfen</button>
        <small style="color: var(--text-muted); flex-basis: 100%;">
            „Beta" bezieht als Vorabversion (Prerelease) markierte Releases ein.
            Angeboten werden in beiden Kanälen ausschließlich Versionen, die <strong>neuer</strong>
            als die installierte sind - ein Downgrade findet niemals statt, auch nicht
            beim Wechsel von Beta zurück auf Stabil (die Installation bleibt dann so lange
            auf der Beta-Version, bis ein neueres stabiles Release erscheint).
        </small>
    </form>

    <?php if (isset($_GET['automation_saved'])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 0.6rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: 0.9rem;">
            Einstellungen für automatische Updates gespeichert.
        </div>
    <?php endif; ?>

    <!-- Unbeaufsichtigte Update-Automatik (#290, zweite Stufe aus #85).
         Bewusst ein eigenes Formular neben dem Kanal: Der Kanal gilt auch
         fürs manuelle Update, diese Einstellungen nur für den Cron-Lauf. -->
    <form action="/admin/updates/automation" method="POST" style="background: var(--surface-muted); padding: 0.8rem; border-radius: 6px; border: 1px solid #e0e0e0; margin-bottom: 1.2rem;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        <div class="form-group" style="margin: 0 0 0.5rem 0;">
            <label style="font-size: 0.9rem;">
                <input type="checkbox" name="update_notify" value="1" <?= $notifyEnabled ? 'checked' : '' ?>>
                Per E-Mail über verfügbare Updates informieren
            </label>
        </div>
        <div class="form-group" style="margin: 0 0 0.5rem 0;">
            <label style="font-size: 0.9rem;">
                <input type="checkbox" name="update_auto_install" value="1"
                       <?= $autoInstallEnabled ? 'checked' : '' ?>
                       <?= $automationPossible ? '' : 'disabled' ?>>
                Updates zusätzlich automatisch installieren
            </label>
        </div>
        <div class="form-group" style="margin: 0 0 0.5rem 0;">
            <label for="update_auto_install_scope" style="font-size: 0.9rem;">Reichweite</label>
            <select id="update_auto_install_scope" name="update_auto_install_scope" class="form-control" style="max-width: 320px;" <?= $automationPossible ? '' : 'disabled' ?>>
                <option value="patch_only" <?= $autoInstallScope !== 'any' ? 'selected' : '' ?>>Nur Patch-Versionen der laufenden Linie (empfohlen)</option>
                <option value="any" <?= $autoInstallScope === 'any' ? 'selected' : '' ?>>Jede neuere Version</option>
            </select>
        </div>
        <?php if (!$automationPossible): ?>
            <!-- Deaktivierte Felder senden nichts mit. Ohne diese Ersatzwerte
                 fiele die Reichweite beim Speichern still auf die Vorgabe
                 zurück. Die Installation steht hier zwangsläufig auf "aus" -
                 sie kann ohne In-Place-Recht bzw. Backup gar nicht laufen -,
                 die Benachrichtigung bleibt davon unberührt. -->
            <input type="hidden" name="update_auto_install_scope" value="<?= htmlspecialchars($autoInstallScope) ?>">
        <?php endif; ?>
        <!-- Der Knopf ist bewusst IMMER bedienbar: Die Benachrichtigung ist
             auch dann sinnvoll (im Container-Betrieb sogar der einzig
             nutzbare Teil), wenn nicht automatisch installiert werden kann.
             Ein hier deaktivierter Knopf hätte genau das verhindert - die
             Bedingungen für die Installation setzt saveAutomation() ohnehin
             serverseitig durch. -->
        <?php if ($mailDeliverable && !$adminRecipientReachable && ($notifyEnabled || $autoInstallEnabled)): ?>
            <!-- Der Transport steht, aber es gibt niemanden, den die Mail
                 erreichen koennte. Beobachtet auf der Entwicklungsinstanz
                 dieses Hosts: drei von vier Admin-Konten trugen
                 @migration.invalid aus einer Altdatenmigration. Die Endung
                 ist nach RFC 2606 reserviert und nie zustellbar - die
                 Benachrichtigung ging also formal raus und kam nirgends an. -->
            <div style="background: var(--danger-soft-bg); color: var(--danger-fg); padding: 0.7rem; border-radius: 4px; margin-bottom: 0.6rem; font-size: 0.9rem;">
                <strong>Kein erreichbarer Empfänger.</strong>
                Der Mailversand ist eingerichtet, aber unter den
                <a href="/admin/users">Admin-Konten</a> steht keine Adresse, die zugestellt
                werden könnte - alle zeigen auf reservierte Endungen wie <code>.invalid</code>
                oder <code>.test</code>, die es per Norm nicht gibt.
                <?php if ($autoInstallEnabled): ?>
                    Die automatische Installation liefe damit <strong>unbemerkt</strong>.
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (!$mailDeliverable && ($notifyEnabled || $autoInstallEnabled)): ?>
            <!-- Die Zusage dieser Automatik ist "du erfaehrst, was passiert
                 ist". Ohne Mailversand ist sie formal eingeschaltet und
                 praktisch wirkungslos: Ein automatisches Update liefe stumm
                 durch, nachlesbar nur im Audit-Log, in das niemand schaut,
                 der nichts ahnt. Deshalb steht das hier und nicht bloss in
                 den Mail-Einstellungen. -->
            <div style="background: var(--danger-soft-bg); color: var(--danger-fg); padding: 0.7rem; border-radius: 4px; margin-bottom: 0.6rem; font-size: 0.9rem;">
                <strong>Es geht keine E-Mail raus.</strong>
                Diese Installation hat keinen vollständigen Mailversand (unter
                <a href="/admin/mail-settings">E-Mail-Einstellungen</a> fehlen SMTP-Server oder
                -Benutzer).
                <?php if ($autoInstallEnabled): ?>
                    Die automatische Installation läuft trotzdem - sie würde also
                    <strong>unbemerkt</strong> aktualisieren. Nachlesbar wäre das nur im
                    <a href="/admin/logs?category=update">Audit-Log</a>.
                <?php else: ?>
                    Die Benachrichtigung über verfügbare Updates erreicht deshalb niemanden.
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-secondary" style="padding: 0.4rem 0.9rem;">Speichern</button>
        <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">
            Geprüft wird alle 3 Stunden, installiert höchstens einmal täglich - beides über den
            <a href="/admin/cron">Cron-Auslöser</a>, der dafür eingerichtet sein muss. Die E-Mail geht an
            alle Admin-Konten und wird je Fund nur einmal versendet, nicht bei jeder Prüfung erneut.
            Vor jeder Installation läuft das <strong>Pflicht-Backup</strong>; schlägt es fehl, unterbleibt
            das Update. Während des Einspielens ist die Seite kurz im Wartungsmodus.
            Automatisch installieren lässt sich nur zusammen mit der Benachrichtigung.
            <?php if (!$automationPossible): ?>
                <br><strong>
                    <?php if (!$inPlaceEnabled): ?>
                        Automatisch installieren ist in dieser Installation nicht möglich: Die
                        In-Place-Aktualisierung ist deaktiviert (Container-Betrieb).
                    <?php else: ?>
                        Automatisch installieren ist erst möglich, wenn unter
                        <a href="/admin/backups">Backups</a> ein externes Backup eingerichtet ist -
                        ohne Sicherung wird grundsätzlich nicht aktualisiert.
                    <?php endif; ?>
                    Die Benachrichtigung lässt sich unabhängig davon einschalten.
                </strong>
            <?php endif; ?>
        </small>
    </form>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            ✓ Update von <strong><?= htmlspecialchars($_GET['from'] ?? '') ?></strong> auf
            <strong><?= htmlspecialchars($_GET['to'] ?? '') ?></strong> angewendet.
            Datenbank-Migrationen laufen automatisch beim nächsten Seitenaufruf.
            <?php if (isset($_GET['addons_ok']) || isset($_GET['addons_fail'])): ?>
                <br>Addon-Phase: <?= (int)($_GET['addons_ok'] ?? 0) ?> mitgezogen<?php
                    ?><?php if ((int)($_GET['addons_fail'] ?? 0) > 0): ?>,
                    <strong><?= (int)$_GET['addons_fail'] ?> fehlgeschlagen</strong>
                    (weitere Details im <a href="/admin/logs?category=plugin">Audit-Log</a> und in der Tabelle unten)<?php endif; ?>.
                <?php if (!empty($_GET['addons_fail_reasons'])): ?>
                    <!-- Klartext-Grund der Addon-Phase (#290): ohne ihn stand hier
                         nur eine Zahl, und der eigentliche Grund - meist ein noch
                         fehlender Addon-Release zur neuen Kern-Linie - war nur im
                         Audit-Log auffindbar. -->
                    <ul style="margin: 0.5rem 0 0 1.2rem;">
                        <?php foreach (explode(';', (string)$_GET['addons_fail_reasons']) as $reason): ?>
                            <li><?= htmlspecialchars($reason) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (!empty($_GET['addons_fail_slugs'])): ?>
                        <small>Betroffen: <?php foreach (explode(',', (string)$_GET['addons_fail_slugs']) as $i => $failedSlug): ?><?= $i > 0 ? ', ' : '' ?><code><?= htmlspecialchars($failedSlug) ?></code><?php endforeach; ?></small>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['addon_success'])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            ✓ Addon <code><?= htmlspecialchars($_GET['slug'] ?? '') ?></code> von
            <strong><?= htmlspecialchars($_GET['from'] ?? '?') ?></strong> auf
            <strong><?= htmlspecialchars($_GET['to'] ?? '?') ?></strong> aktualisiert.
            War das Addon aktiv, greift wie gewohnt die Freigabe-Logik unter
            <a href="/admin/plugins">Plugins verwalten</a> (neue Manifest-Version wird
            automatisch übernommen).
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['addon_error'])): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            Addon-Update<?= isset($_GET['slug']) ? ' für <code>' . htmlspecialchars($_GET['slug']) . '</code>' : '' ?> fehlgeschlagen:
            <?= htmlspecialchars($_GET['addon_error']) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars($_GET['error']) ?>
        </div>
    <?php endif; ?>

    <?php if (!$backupConfigured): ?>
        <div style="background-color: var(--warning-soft-bg); color: var(--warning-fg); border: 1px solid #ffeeba; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
            ⚠️ <strong>Automatische Backups sind nicht konfiguriert.</strong>
            Ein Update wird grundsätzlich nur nach einem unmittelbar zuvor erfolgreichen
            externen Backup ausgeführt - bitte zunächst unter
            <a href="/admin/backups">Backups</a> einrichten.
        </div>
    <?php endif; ?>

    <?php if (isset($checkError)): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            Release-Prüfung fehlgeschlagen: <?= htmlspecialchars($checkError) ?>
        </div>
    <?php endif; ?>

    <?php if (is_array($checkResult)): ?>
        <?php if ($checkResult['update_available']): ?>
            <div style="background-color: #d1ecf1; color: #0c5460; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
                📦 Neue Version verfügbar: <strong><?= htmlspecialchars($checkResult['latest']) ?></strong>
                <?php if (!empty($checkResult['is_prerelease'])): ?>
                    <span style="padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background: var(--warning-soft-bg); color: var(--warning-fg); font-weight: 600;">Beta-Vorabversion</span>
                <?php endif; ?>
                (installiert: <?= htmlspecialchars($checkResult['current']) ?>).
                <?php if (!empty($checkResult['html_url'])): ?>
                    <a href="<?= htmlspecialchars($checkResult['html_url']) ?>" target="_blank" rel="noopener">Release-Notes ansehen</a>
                <?php endif; ?>
            </div>

            <?php
            // Liegt diese Version ausserhalb der eingestellten Reichweite? (#364)
            //
            // Bis v0.8.0-beta.1 stand hier "Neue Version verfuegbar" und darueber
            // das Auswahlfeld "Nur Patch-Versionen der laufenden Linie" - und
            // nichts verband die beiden. Der naheliegende Schluss ("die Automatik
            // erledigt das") war falsch, und das Ueberspringen ist bewusst stumm.
            // Der Betreiber wartete auf ein Update, das nie kam.
            //
            // Die Version wird weiterhin ANGEZEIGT, und das ist Absicht: Wuerde
            // die Seite Minor-Versionen verbergen, erfuehre niemand je, dass es
            // sie gibt, und die Instanz bliebe fuer immer auf der alten Linie.
            // Was fehlte, war der Satz dazu.
            $ausserhalbReichweite = $autoInstallEnabled
                && !\App\Service\UpdateService::isEligibleForAutoInstall(
                    (string)$checkResult['current'],
                    (string)$checkResult['latest'],
                    $autoInstallScope
                );
            ?>
            <?php if ($ausserhalbReichweite): ?>
                <div style="background-color: var(--warning-soft-bg); color: var(--warning-fg); border: 1px solid #ffeeba; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
                    ⏸️ <strong>Diese Version wird NICHT automatisch eingespielt.</strong>
                    Ihre Einstellung lautet
                    „<?= $autoInstallScope === 'any' ? 'Jede neue Version' : 'Nur Patch-Versionen der laufenden Linie' ?>",
                    und <?= htmlspecialchars((string)$checkResult['latest']) ?> liegt ausserhalb davon
                    (installiert: <?= htmlspecialchars((string)$checkResult['current']) ?>).
                    <br>
                    <small>
                        Das ist die gewollte Wirkung der Einstellung — ein Sprung auf eine neue
                        Linie kann Breaking Changes enthalten und gehört unter Aufsicht.
                        Zum Einspielen entweder den Knopf unten benutzen, oder die Reichweite
                        oben auf „Jede neue Version" stellen.
                    </small>
                </div>
            <?php endif; ?>

            <?php if ($addonTargetWarnings !== []): ?>
                <!-- Addon-Warnung VOR dem Update-Knopf (#197): Ein Kern-Update
                     deaktiviert inkompatible Addons kommentarlos - hier steht
                     es vorher, nicht hinterher. -->
                <div style="background-color: var(--warning-soft-bg); color: var(--warning-fg); border: 1px solid #ffeeba; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
                    ⚠️ <strong>Nach dem Update auf <?= htmlspecialchars($targetVersion ?? '') ?> werden folgende aktive Addons deaktiviert:</strong>
                    <ul style="margin: 0.5rem 0 0 1.2rem;">
                        <?php foreach ($addonTargetWarnings as $warnRow): ?>
                            <li>
                                <code><?= htmlspecialchars($warnRow['slug']) ?></code> — <?= htmlspecialchars($warnRow['reasonTarget']) ?>
                                <?php if (($warnRow['availableSupportsTarget'] ?? null) === true): ?>
                                    <span style="color: var(--success-fg);">— passende Fassung liegt im Store und wird beim Update mitgezogen</span>
                                <?php elseif (($warnRow['availableSupportsTarget'] ?? null) === false): ?>
                                    <strong>— im Store liegt keine passende Fassung</strong>
                                <?php else: ?>
                                    <strong>— kein Katalog-Eintrag, Ersatz nicht feststellbar</strong>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <small>Zuerst im <a href="/admin/plugins/store">Addon-Store</a> nach passenden Addon-Updates sehen.</small>
                </div>
            <?php endif; ?>

            <?php if ($inPlaceEnabled): ?>
                <form action="/admin/updates/run" method="POST" data-confirm="Jetzt auf Version <?= htmlspecialchars(($checkResult['latest'])) ?><?= !empty($checkResult['is_prerelease']) ? ' (Beta-Vorabversion)' : '' ?> aktualisieren? Zuvor wird zwingend ein externes Backup ausgeführt - schlägt es fehl, wird das Update abgebrochen." style="margin-bottom: 1rem;">
                    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                    <?php if ($addonOhneErsatz !== []): ?>
                        <?php // #364: Getippte Bestaetigung genau dann, wenn eine Funktion
                              // verschwindet und niemand sie zurueckholen kann. Ein Dialog
                              // wird mit "OK" beantwortet, ohne gelesen zu werden; eine
                              // Versionsnummer tippt man nicht versehentlich ab.
                              // Durchgesetzt wird das serverseitig in
                              // UpdateController::run() - dieses Feld macht es nur sichtbar. ?>
                        <div style="background: var(--danger-soft-bg); color: var(--danger-fg); border: 1px solid #f5c6cb; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
                            <strong><?= count($addonOhneErsatz) ?> Addon(s) verlieren ersatzlos ihre Funktion.</strong>
                            Für sie liegt keine Fassung bereit, die
                            <?= htmlspecialchars((string)$checkResult['latest']) ?> unterstützt.
                            Sie werden nicht gelöscht — ihre Daten bleiben —, aber sie sind
                            unsichtbar, bis es eine passende Fassung gibt.
                            <p style="margin: 0.8rem 0 0.3rem 0;">
                                Zum Bestätigen die Zielversion
                                <code><?= htmlspecialchars((string)$checkResult['latest']) ?></code> eintippen:
                            </p>
                            <input type="text" name="bestaetigung" class="form-control" autocomplete="off"
                                   placeholder="<?= htmlspecialchars((string)$checkResult['latest']) ?>"
                                   style="max-width: 320px;">
                        </div>
                    <?php endif; ?>
                    <button type="submit" class="btn" <?= $backupConfigured ? '' : 'disabled title="Backups zuerst konfigurieren"' ?>>
                        ⬆️ Jetzt aktualisieren (mit Pflicht-Backup)
                    </button>
                </form>
            <?php else: ?>
                <!-- Container-Betrieb: nur anzeigen, dass es ein Update gibt - die
                     Installation läuft NICHT in-place (der Web-Prozess darf den Code
                     aus Sicherheitsgründen nicht überschreiben, #158), sondern über
                     ein neues Image. -->
                <div style="background-color: #e2e3f3; color: #2f2f6b; border: 1px solid #c9c9e6; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
                    Aktualisiert wird in dieser Installation über ein <strong>neues Image</strong>,
                    nicht in-place. Neues Image holen:
                    <code>docker compose pull &amp;&amp; docker compose up -d</code> — oder automatisch
                    mit einem Watchtower-Fork (<code>nickfedor/watchtower</code>,
                    Image <code>ghcr.io/nicholas-fedor/watchtower</code>), der neue Images erkennt
                    und den Container neu startet.
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
                ✓ Diese Installation ist aktuell<?php if (!empty($checkResult['latest'])): ?> (neuestes Release im Kanal „<?= $checkResult['channel'] === 'beta' ? 'Beta' : 'Stabil' ?>": <?= htmlspecialchars($checkResult['latest']) ?>)<?php endif; ?>.
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <a href="/admin/updates?check=1" class="btn btn-secondary">🔍 Auf Updates prüfen</a>

    <hr style="margin: 1.5rem 0; border: none; border-top: 1px solid var(--border-color);">
    <h3>🧩 Addons</h3>
    <?php if ($addonRows === []): ?>
        <p style="color: var(--text-muted); font-size: 0.9rem;">Keine Addons installiert.</p>
    <?php else: ?>
        <div class="tabelle-scroll">
            <table style="width: 100%; font-size: 0.9rem; border-collapse: collapse;">
                <thead>
                    <tr style="text-align: left; border-bottom: 1px solid var(--border-color);">
                        <th style="padding: 0.4rem 0.5rem;">Addon</th>
                        <th style="padding: 0.4rem 0.5rem;">installiert</th>
                        <th style="padding: 0.4rem 0.5rem;">verfügbar (offizielles Repo)</th>
                        <th style="padding: 0.4rem 0.5rem;">kompatibel<?= $targetVersion !== null ? ' mit Ziel ' . htmlspecialchars($targetVersion) : '' ?>?</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($addonRows as $row): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 0.4rem 0.5rem;">
                                <code><?= htmlspecialchars($row['slug']) ?></code>
                                <?= $row['enabled'] ? '' : '<span style="color: var(--text-subtle); font-size: 0.8rem;">(inaktiv)</span>' ?>
                            </td>
                            <td style="padding: 0.4rem 0.5rem;"><?= htmlspecialchars($row['installedVersion']) ?></td>
                            <td style="padding: 0.4rem 0.5rem;">
                                <?php if ($row['availableVersion'] === null): ?>
                                    <span style="color: var(--text-subtle);">—</span>
                                <?php elseif ($row['hasUpdate']): ?>
                                    <strong><?= htmlspecialchars($row['availableVersion']) ?></strong>
                                    <span style="padding: 0.1rem 0.4rem; border-radius: 4px; font-size: 0.75rem; background: var(--info-soft-bg);">Update</span>
                                    <!-- Manuelles Addon-Update innerhalb der laufenden Kern-Linie
                                         (#197, Stufe 2) - nur offizielles Repo, Fremd-Quellen lehnt
                                         der Server ab. -->
                                    <form action="/admin/updates/addon" method="POST" style="display: inline; margin-left: 0.3rem;"
                                          data-confirm="Addon <?= htmlspecialchars(($row['slug'])) ?> jetzt auf <?= htmlspecialchars(($row['availableVersion'])) ?> aktualisieren?" >
                                        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                        <input type="hidden" name="slug" value="<?= htmlspecialchars($row['slug']) ?>">
                                        <button type="submit" class="btn btn-secondary" style="padding: 0.15rem 0.6rem; font-size: 0.8rem;">⬆️ Aktualisieren</button>
                                    </form>
                                <?php else: ?>
                                    <?= htmlspecialchars($row['availableVersion']) ?>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 0.4rem 0.5rem;">
                                <?php if ($row['manifestError'] !== null): ?>
                                    <span style="color: var(--danger-fg);">⚠ Manifest ungültig</span>
                                <?php elseif ($targetVersion !== null && $row['reasonTarget'] !== null): ?>
                                    <span style="color: var(--danger-fg);">⚠ <?= htmlspecialchars($row['reasonTarget']) ?></span>
                                <?php elseif ($row['reasonCurrent'] !== null): ?>
                                    <span style="color: var(--danger-fg);">⚠ <?= htmlspecialchars($row['reasonCurrent']) ?></span>
                                <?php else: ?>
                                    <span style="color: var(--success-fg);">✓</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">
        <?php if ($addonCatalogAvailable): ?>
            Katalog-Stand: <?= htmlspecialchars((string)($addonCatalogCachedAt ?? 'unbekannt')) ?> —
            wird vom nächtlichen Update-Lauf und beim Aufruf des
            <a href="/admin/plugins/store">Addon-Stores</a> aufgefrischt
            (dort werden Addon-Updates auch eingespielt).
            <a href="/admin/updates?refresh=1">Katalog jetzt auffrischen</a>.
        <?php else: ?>
            Noch kein Katalog-Stand des offiziellen Addon-Repos vorhanden —
            <a href="/admin/updates?refresh=1">Katalog jetzt auffrischen</a> oder einmal den
            <a href="/admin/plugins/store">Addon-Store</a> aufrufen, dann erscheinen
            hier auch verfügbare Addon-Versionen.
        <?php endif; ?>
        <?php // Der Abruf lädt und entpackt das komplette Repo-Tarball von
              // GitHub. Bis #319 lief er bei JEDEM Aufruf dieser reinen
              // Anzeigeseite mit; jetzt nur noch auf ausdrücklichen Klick
              // und im nächtlichen Lauf. ?>
    </small>

    <?php if ($inPlaceEnabled): ?>
        <hr style="margin: 1.5rem 0; border: none; border-top: 1px solid var(--border-color);">
        <p style="color: var(--text-muted); font-size: 0.85rem;">
            Ablauf: Release-Prüfung → <strong>Pflicht-Backup</strong> (Abbruch bei Fehler) →
            Herunterladen des offiziellen Release-Archivs → Anwenden (Konfiguration,
            Uploads und Plugins bleiben unangetastet). Datenbank-Migrationen laufen wie
            gewohnt automatisch beim nächsten Seitenaufruf. Details: docs/releasing.md.
        </p>
    <?php endif; ?>
</div>

<?php
// ---------------------------------------------------------------------------
// Integritaet des Codebaums (#403)
// ---------------------------------------------------------------------------
$integritaet = $_SESSION['integritaet'] ?? null;
$integritaetFehler = $_SESSION['integritaet_fehler'] ?? null;
$repariert = $_SESSION['integritaet_repariert'] ?? null;
unset($_SESSION['integritaet'], $_SESSION['integritaet_fehler'], $_SESSION['integritaet_repariert']);
$csrf = \App\Router::generateCsrfToken();
?>
<div class="card" id="integritaet" style="margin-top: 1.5rem;">
    <h2>Unversehrtheit des Codebaums</h2>

    <p style="color: var(--text-muted); font-size: 0.9rem;">
        Vergleicht die ausgelieferten Programmdateien mit dem Sollzustand dieser Version.
        Eigene Daten &ndash; hochgeladene Bilder, Einstellungen, installierte Addons &ndash;
        werden dabei nicht angefasst und nicht geprüft; sie gehören Ihnen, nicht dem Release.
    </p>

    <?php if ($integritaetFehler !== null): ?>
        <div class="alert alert-danger"><?= htmlspecialchars((string)$integritaetFehler) ?></div>
    <?php endif; ?>

    <?php if (is_array($repariert)): ?>
        <div class="alert alert-success">
            <?= count($repariert['wiederhergestellt']) ?> Datei(en) wiederhergestellt.
            <?php if ($repariert['uebersprungen'] !== []): ?>
                <br><small>
                    Übersprungen, weil nicht in der veröffentlichten Liste oder kein Kern-Pfad:
                    <?= htmlspecialchars(implode(', ', $repariert['uebersprungen'])) ?>
                </small>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/updates/integritaet" style="display: inline;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="quelle" value="mitgeliefert">
        <button type="submit" class="btn btn-secondary">Gegen mitgelieferte Liste prüfen</button>
    </form>
    <form method="post" action="/admin/updates/integritaet" style="display: inline;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="quelle" value="veroeffentlicht">
        <button type="submit" class="btn btn-primary">Gegen veröffentlichte Liste prüfen</button>
    </form>

    <p style="color: var(--text-muted); font-size: 0.82rem; margin-top: 0.6rem;">
        Der Unterschied ist wesentlich. Die <strong>mitgelieferte</strong> Liste liegt im selben
        Verzeichnisbaum wie die geprüften Dateien &ndash; sie findet kaputte Uploads und halb
        eingespielte Updates, aber niemanden, der Datei <em>und</em> Liste ändern kann.
        Die <strong>veröffentlichte</strong> wird bei GitHub geholt und liegt damit außerhalb
        der Reichweite von jemandem, der nur Zugriff auf diesen Webspace hat. Dafür braucht
        sie eine Internetverbindung.
    </p>

    <?php if (is_array($integritaet)): ?>
        <hr style="margin: 1.25rem 0; border: none; border-top: 1px solid var(--border-color);">

        <?php if ($integritaet['quelle'] === \App\Service\Integritaet::QUELLE_FEHLT): ?>
            <div class="alert alert-warning">
                <strong>Nicht geprüft.</strong>
                <?= htmlspecialchars((string)($integritaet['hinweis'] ?? '')) ?>
            </div>
        <?php else: ?>
            <p>
                <?= (int)$integritaet['geprueft'] ?> Datei(en) geprüft, Version
                <?= htmlspecialchars((string)$integritaet['version']) ?>, gemessen an der
                <strong><?= $integritaet['quelle'] === \App\Service\Integritaet::QUELLE_VEROEFFENTLICHT
                    ? 'veröffentlichten' : 'mitgelieferten' ?></strong> Liste.
            </p>

            <?php if ($integritaet['heil']): ?>
                <div class="alert alert-success">
                    Keine Abweichung. Alle geprüften Dateien entsprechen dem Release.
                </div>
            <?php else: ?>
                <div class="alert alert-danger">
                    <strong>Abweichungen gefunden.</strong>
                    <?= count($integritaet['geaendert']) ?> geändert,
                    <?= count($integritaet['fehlt']) ?> fehlend.
                </div>
            <?php endif; ?>

            <?php if ($integritaet['hinweis'] !== null): ?>
                <p style="color: var(--text-muted); font-size: 0.82rem;">
                    <?= htmlspecialchars((string)$integritaet['hinweis']) ?>
                </p>
            <?php endif; ?>

            <?php $reparierbar = array_merge($integritaet['geaendert'], $integritaet['fehlt']); ?>
            <?php if ($reparierbar !== []): ?>
                <form method="post" action="/admin/updates/reparieren">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <table class="table" style="margin-top: 0.75rem;">
                        <thead><tr><th style="width: 2rem;"></th><th>Datei</th><th style="width: 8rem;">Befund</th></tr></thead>
                        <tbody>
                        <?php foreach ($integritaet['geaendert'] as $pfad): ?>
                            <tr>
                                <td><input type="checkbox" name="pfade[]" value="<?= htmlspecialchars($pfad) ?>" checked></td>
                                <td><code><?= htmlspecialchars($pfad) ?></code></td>
                                <td>geändert</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($integritaet['fehlt'] as $pfad): ?>
                            <tr>
                                <td><input type="checkbox" name="pfade[]" value="<?= htmlspecialchars($pfad) ?>" checked></td>
                                <td><code><?= htmlspecialchars($pfad) ?></code></td>
                                <td>fehlt</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="submit" class="btn btn-primary">Ausgewählte aus dem Release wiederherstellen</button>
                    <p style="color: var(--text-muted); font-size: 0.82rem; margin-top: 0.5rem;">
                        Wiederhergestellt wird ausschließlich aus dem offiziellen Release dieser Version,
                        dessen Prüfsumme vorher geprüft wird. Schlägt etwas fehl, wird der Stand von
                        vorher wiederhergestellt.
                    </p>
                </form>
            <?php endif; ?>

            <?php if ($integritaet['zusaetzlich'] !== []): ?>
                <hr style="margin: 1.25rem 0; border: none; border-top: 1px solid var(--border-color);">
                <p>
                    <strong><?= count($integritaet['zusaetzlich']) ?> Datei(en)</strong> liegen in
                    Programmverzeichnissen, gehören aber nicht zu diesem Release:
                </p>
                <ul style="font-size: 0.88rem;">
                    <?php foreach (array_slice($integritaet['zusaetzlich'], 0, 50) as $pfad): ?>
                        <li><code><?= htmlspecialchars($pfad) ?></code></li>
                    <?php endforeach; ?>
                    <?php if (count($integritaet['zusaetzlich']) > 50): ?>
                        <li>&hellip; und <?= count($integritaet['zusaetzlich']) - 50 ?> weitere</li>
                    <?php endif; ?>
                </ul>
                <p style="color: var(--text-muted); font-size: 0.82rem;">
                    Das ist <em>nicht</em> zwangsläufig ein Schaden &ndash; es kann etwas sein, das Sie
                    selbst dort abgelegt haben. Es wird deshalb nur genannt und nie entfernt.
                    Was Sie nicht selbst dort abgelegt haben, gehört allerdings angesehen.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
