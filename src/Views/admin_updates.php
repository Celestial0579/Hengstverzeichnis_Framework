<?php
// src/Views/admin_updates.php
/**
 * Automatisches Update (#85, siehe UpdateController/UpdateService).
 *
 * @var string $currentVersion
 * @var bool $backupConfigured
 * @var array|null $checkResult
 * @var string|null $checkError
 */
?>
<div class="card" style="max-width: 700px; margin: 0 auto;">
    <h2>🔄 Updates</h2>
    <p style="color: #666;">
        Installierte Version: <strong><?= htmlspecialchars($currentVersion) ?></strong>
    </p>

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
                (installiert: <?= htmlspecialchars($checkResult['current']) ?>).
                <?php if (!empty($checkResult['html_url'])): ?>
                    <a href="<?= htmlspecialchars($checkResult['html_url']) ?>" target="_blank" rel="noopener">Release-Notes ansehen</a>
                <?php endif; ?>
            </div>

            <form action="/admin/updates/run" method="POST" onsubmit="return confirm('Jetzt auf Version <?= htmlspecialchars($checkResult['latest']) ?> aktualisieren? Zuvor wird zwingend ein externes Backup ausgeführt - schlägt es fehl, wird das Update abgebrochen.');" style="margin-bottom: 1rem;">
                <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                <button type="submit" class="btn" <?= $backupConfigured ? '' : 'disabled title="Backups zuerst konfigurieren"' ?>>
                    ⬆️ Jetzt aktualisieren (mit Pflicht-Backup)
                </button>
            </form>
        <?php else: ?>
            <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
                ✓ Diese Installation ist aktuell (neuestes Release: <?= htmlspecialchars($checkResult['latest']) ?>).
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
