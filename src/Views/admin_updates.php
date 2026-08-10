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
 */
$inPlaceEnabled = $inPlaceEnabled ?? true;
$addonRows = $addonRows ?? [];
$addonCatalogAvailable = $addonCatalogAvailable ?? false;
// Aktive Addons, die die ZIELversion des anstehenden Kern-Updates nicht
// unterstützen - sie würden nach dem Update kommentarlos deaktiviert (#197).
$addonTargetWarnings = array_values(array_filter(
    $addonRows,
    static fn(array $r): bool => $r['enabled'] && $r['reasonTarget'] !== null
));
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

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            ✓ Update von <strong><?= htmlspecialchars($_GET['from'] ?? '') ?></strong> auf
            <strong><?= htmlspecialchars($_GET['to'] ?? '') ?></strong> angewendet.
            Datenbank-Migrationen laufen automatisch beim nächsten Seitenaufruf.
            <?php if (isset($_GET['addons_ok']) || isset($_GET['addons_fail'])): ?>
                <br>Addon-Phase: <?= (int)($_GET['addons_ok'] ?? 0) ?> mitgezogen<?php
                    ?><?php if ((int)($_GET['addons_fail'] ?? 0) > 0): ?>,
                    <strong><?= (int)$_GET['addons_fail'] ?> fehlgeschlagen</strong>
                    (Details im <a href="/admin/logs">Audit-Log</a> und in der Tabelle unten)<?php endif; ?>.
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

            <?php if ($addonTargetWarnings !== []): ?>
                <!-- Addon-Warnung VOR dem Update-Knopf (#197): Ein Kern-Update
                     deaktiviert inkompatible Addons kommentarlos - hier steht
                     es vorher, nicht hinterher. -->
                <div style="background-color: var(--warning-soft-bg); color: var(--warning-fg); border: 1px solid #ffeeba; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
                    ⚠️ <strong>Nach dem Update auf <?= htmlspecialchars($targetVersion ?? '') ?> werden folgende aktive Addons deaktiviert:</strong>
                    <ul style="margin: 0.5rem 0 0 1.2rem;">
                        <?php foreach ($addonTargetWarnings as $warnRow): ?>
                            <li><code><?= htmlspecialchars($warnRow['slug']) ?></code> — <?= htmlspecialchars($warnRow['reasonTarget']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <small>Zuerst im <a href="/admin/plugins/store">Addon-Store</a> nach passenden Addon-Updates sehen.</small>
                </div>
            <?php endif; ?>

            <?php if ($inPlaceEnabled): ?>
                <form action="/admin/updates/run" method="POST" onsubmit="return confirm('Jetzt auf Version <?= htmlspecialchars(addslashes($checkResult['latest'])) ?><?= !empty($checkResult['is_prerelease']) ? ' (Beta-Vorabversion)' : '' ?> aktualisieren? Zuvor wird zwingend ein externes Backup ausgeführt - schlägt es fehl, wird das Update abgebrochen.');" style="margin-bottom: 1rem;">
                    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
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
                                      onsubmit="return confirm('Addon <?= htmlspecialchars(addslashes($row['slug'])) ?> jetzt auf <?= htmlspecialchars(addslashes($row['availableVersion'])) ?> aktualisieren?');">
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
        <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">
            <?php if ($addonCatalogAvailable): ?>
                Katalog-Stand: <?= htmlspecialchars((string)($addonCatalogCachedAt ?? 'unbekannt')) ?> —
                aktualisiert sich beim Aufruf des <a href="/admin/plugins/store">Addon-Stores</a>
                (dort werden Addon-Updates auch eingespielt).
            <?php else: ?>
                Noch kein Katalog-Stand des offiziellen Addon-Repos vorhanden — einmal den
                <a href="/admin/plugins/store">Addon-Store</a> aufrufen, dann erscheinen
                hier auch verfügbare Addon-Versionen.
            <?php endif; ?>
        </small>
    <?php endif; ?>

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
