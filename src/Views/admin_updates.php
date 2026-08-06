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
 */
?>
<div class="card" style="max-width: 700px; margin: 0 auto;">
    <h2>🔄 Updates</h2>
    <p style="color: #666;">
        Installierte Version: <strong><?= htmlspecialchars($currentVersion) ?></strong>
        <span style="margin-left: 0.5rem; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background: <?= $updateChannel === 'beta' ? '#fff3cd' : '#e2e3e5' ?>; color: <?= $updateChannel === 'beta' ? '#856404' : '#383d41' ?>;">
            Kanal: <?= $updateChannel === 'beta' ? 'Beta' : 'Stabil' ?>
        </span>
    </p>

    <?php if (isset($_GET['channel_saved'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 0.6rem 1rem; border-radius: 4px; margin-bottom: 1rem; font-size: 0.9rem;">
            Update-Kanal gespeichert.
        </div>
    <?php endif; ?>

    <!-- Update-Kanal / Beta-Opt-in: Kandidaten sind IMMER nur strikt neuere
         Versionen (UpdateService::selectBestRelease()) - auch ein Wechsel von
         Beta zurück auf Stabil kann daher nie ein Downgrade auslösen. -->
    <form action="/admin/updates/channel" method="POST" style="display: flex; gap: 0.5rem; align-items: flex-end; flex-wrap: wrap; background: #f8f9fa; padding: 0.8rem; border-radius: 6px; border: 1px solid #e0e0e0; margin-bottom: 1.2rem;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        <div class="form-group" style="margin: 0;">
            <label for="update_channel" style="font-size: 0.9rem;">Update-Kanal</label>
            <select id="update_channel" name="update_channel" class="form-control" style="max-width: 320px;">
                <option value="stable" <?= $updateChannel === 'stable' ? 'selected' : '' ?>>Stabil (empfohlen)</option>
                <option value="beta" <?= $updateChannel === 'beta' ? 'selected' : '' ?>>Beta (Vorabversionen einbeziehen)</option>
            </select>
        </div>
        <button type="submit" class="btn btn-secondary" style="padding: 0.4rem 0.9rem;">Speichern &amp; prüfen</button>
        <small style="color: #666; flex-basis: 100%;">
            „Beta" bezieht als Vorabversion (Prerelease) markierte Releases ein.
            Angeboten werden in beiden Kanälen ausschließlich Versionen, die <strong>neuer</strong>
            als die installierte sind - ein Downgrade findet niemals statt, auch nicht
            beim Wechsel von Beta zurück auf Stabil (die Installation bleibt dann so lange
            auf der Beta-Version, bis ein neueres stabiles Release erscheint).
        </small>
    </form>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            ✓ Update von <strong><?= htmlspecialchars($_GET['from'] ?? '') ?></strong> auf
            <strong><?= htmlspecialchars($_GET['to'] ?? '') ?></strong> angewendet.
            Datenbank-Migrationen laufen automatisch beim nächsten Seitenaufruf.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars($_GET['error']) ?>
        </div>
    <?php endif; ?>

    <?php if (!$backupConfigured): ?>
        <div style="background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
            ⚠️ <strong>Automatische Backups sind nicht konfiguriert.</strong>
            Ein Update wird grundsätzlich nur nach einem unmittelbar zuvor erfolgreichen
            externen Backup ausgeführt - bitte zunächst unter
            <a href="/admin/backups">Backups</a> einrichten.
        </div>
    <?php endif; ?>

    <?php if (isset($checkError)): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            Release-Prüfung fehlgeschlagen: <?= htmlspecialchars($checkError) ?>
        </div>
    <?php endif; ?>

    <?php if (is_array($checkResult)): ?>
        <?php if ($checkResult['update_available']): ?>
            <div style="background-color: #d1ecf1; color: #0c5460; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
                📦 Neue Version verfügbar: <strong><?= htmlspecialchars($checkResult['latest']) ?></strong>
                <?php if (!empty($checkResult['is_prerelease'])): ?>
                    <span style="padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background: #fff3cd; color: #856404; font-weight: 600;">Beta-Vorabversion</span>
                <?php endif; ?>
                (installiert: <?= htmlspecialchars($checkResult['current']) ?>).
                <?php if (!empty($checkResult['html_url'])): ?>
                    <a href="<?= htmlspecialchars($checkResult['html_url']) ?>" target="_blank" rel="noopener">Release-Notes ansehen</a>
                <?php endif; ?>
            </div>

            <form action="/admin/updates/run" method="POST" onsubmit="return confirm('Jetzt auf Version <?= htmlspecialchars($checkResult['latest']) ?><?= !empty($checkResult['is_prerelease']) ? ' (Beta-Vorabversion)' : '' ?> aktualisieren? Zuvor wird zwingend ein externes Backup ausgeführt - schlägt es fehl, wird das Update abgebrochen.');" style="margin-bottom: 1rem;">
                <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                <button type="submit" class="btn" <?= $backupConfigured ? '' : 'disabled title="Backups zuerst konfigurieren"' ?>>
                    ⬆️ Jetzt aktualisieren (mit Pflicht-Backup)
                </button>
            </form>
        <?php else: ?>
            <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
                ✓ Diese Installation ist aktuell<?php if (!empty($checkResult['latest'])): ?> (neuestes Release im Kanal „<?= $checkResult['channel'] === 'beta' ? 'Beta' : 'Stabil' ?>": <?= htmlspecialchars($checkResult['latest']) ?>)<?php endif; ?>.
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <a href="/admin/updates?check=1" class="btn btn-secondary">🔍 Auf Updates prüfen</a>

    <hr style="margin: 1.5rem 0; border: none; border-top: 1px solid var(--border-color);">
    <p style="color: #666; font-size: 0.85rem;">
        Ablauf: Release-Prüfung → <strong>Pflicht-Backup</strong> (Abbruch bei Fehler) →
        Herunterladen des offiziellen Release-Archivs → Anwenden (Konfiguration,
        Uploads und Plugins bleiben unangetastet). Datenbank-Migrationen laufen wie
        gewohnt automatisch beim nächsten Seitenaufruf. Details: docs/releasing.md.
    </p>
</div>
