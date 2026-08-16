<?php
// src/Views/admin_addon_store.php
/**
 * @var array $repos Zeilen aus `addon_repos` (siehe App\Controllers\AddonStoreController::index())
 * @var array $catalogs Repo-ID => ['ok' => bool, 'plugins' => array, 'error' => ?string]
 * @var array $discovered Ergebnis von App\Plugin\PluginManager::getDiscoveredPlugins()
 */

$errorMessages = [
    'invalid_repo' => 'Ungültiger GitHub-Link. Erwartet: https://github.com/owner/repo oder owner/repo.',
    'duplicate_repo' => 'Dieses Repository ist bereits registriert.',
    'cannot_remove_official' => 'Das mitgelieferte offizielle Repository kann nicht entfernt werden.',
    'invalid_install_request' => 'Ungültige Installationsanfrage.',
    'already_installed' => 'Ein Plugin mit diesem Slug ist bereits installiert. Zum Überschreiben "Aktualisieren" verwenden.',
    'install_failed' => 'Installation fehlgeschlagen (Download nicht möglich oder Archiv konnte nicht sicher entpackt werden).',
];
$successMessages = [
    'repo_added' => 'Repository hinzugefügt.',
    'repo_removed' => 'Repository entfernt.',
    'installed' => 'Plugin erfolgreich installiert - jetzt unter "Plugins verwalten" aktivieren.',
];
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
        <h2 style="margin: 0;">🛒 Addon-Store</h2>
        <a href="/admin/plugins" class="btn btn-secondary">Zurück zu Plugins verwalten</a>
    </div>

    <p style="color: var(--text-muted); font-size: 0.9rem;">
        Installiert Plugins direkt aus einem GitHub-Repository nach <code>plugins/</code> - das entspricht
        genau dem manuellen <code>cp -r</code>-Workflow aus der
        <a href="https://github.com/Celestial0579/Hengstverzeichnis_Framework/blob/main/docs/plugin-development.md" target="_blank" rel="noopener">Entwickler-Dokumentation</a>,
        nur automatisiert. <strong>Installieren aktiviert ein Plugin nicht</strong> - das bleibt weiterhin ein
        bewusster, separater Schritt unter "Plugins verwalten".
    </p>
    <div style="background-color: var(--warning-soft-bg); color: var(--warning-fg); padding: 0.8rem 1rem; border-radius: 4px; margin-bottom: 1.2rem; font-size: 0.9rem;">
        ⚠️ <strong>Nur Repositories vertrauen, deren Code Sie kennen oder geprüft haben.</strong>
        Anders als bei den mitgelieferten Kern-Funktionen gibt es für zusätzlich hinzugefügte Repositories
        keine Prüfsumme durch eine dritte Stelle - die Sicherheitsentscheidung liegt beim hinzufügenden
        Administrator, genau wie bei der späteren Aktivierung eines installierten Plugins.
    </div>

    <?php if (isset($_GET['success']) && isset($successMessages[$_GET['success']])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars($successMessages[$_GET['success']]) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) && isset($errorMessages[$_GET['error']])): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars($errorMessages[$_GET['error']]) ?>
        </div>
    <?php endif; ?>

    <form action="/admin/plugins/store/add-repo" method="POST" style="display: flex; align-items: flex-end; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1.5rem; padding: 1rem; background: var(--surface-muted); border-radius: 6px;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        <div class="form-group" style="margin: 0; flex: 2; min-width: 220px;">
            <label for="repo_url" style="font-size: 0.85rem;">GitHub-Repository hinzufügen</label>
            <input type="text" id="repo_url" name="repo_url" class="form-control" placeholder="https://github.com/owner/repo" required>
        </div>
        <div class="form-group" style="margin: 0; flex: 1; min-width: 140px;">
            <label for="ref" style="font-size: 0.85rem;">Branch/Tag (optional)</label>
            <input type="text" id="ref" name="ref" class="form-control" placeholder="Standard-Branch">
        </div>
        <button type="submit" class="btn">Hinzufügen</button>
    </form>

    <?php foreach ($repos as $repoRow): ?>
        <?php
            $repoId = (int)$repoRow['id'];
            $isOfficial = (bool)$repoRow['is_official'];
            $catalog = $catalogs[$repoId] ?? ['ok' => false, 'plugins' => [], 'error' => 'Keine Daten.'];
            $repoLabel = htmlspecialchars($repoRow['owner'] . '/' . $repoRow['repo'] . ($repoRow['ref'] ? '@' . $repoRow['ref'] : ''));
        ?>
        <div id="repo-<?= $repoId ?>" style="border: 1px solid var(--border-color); border-radius: 6px; padding: 1rem; margin-bottom: 1rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.5rem;">
                <div>
                    <strong>
                        <a href="https://github.com/<?= htmlspecialchars($repoRow['owner'] . '/' . $repoRow['repo']) ?>" target="_blank" rel="noopener"><?= $repoLabel ?></a>
                    </strong>
                    <?php if ($isOfficial): ?>
                        <span style="padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.75rem; background-color: #cfe2ff; color: #084298; font-weight: 600;">Offiziell</span>
                    <?php endif; ?>
                </div>
                <div style="display: flex; gap: 0.5rem;">
                    <a href="/admin/plugins/store?refresh=1#repo-<?= $repoId ?>" class="btn btn-secondary" style="padding: 0.25rem 0.75rem; font-size: 0.85rem;">Katalog neu laden</a>
                    <?php if (!$isOfficial): ?>
                        <form action="/admin/plugins/store/remove-repo" method="POST" data-confirm="Repository '<?= htmlspecialchars(($repoLabel)) ?>' wirklich entfernen?" >
                            <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                            <input type="hidden" name="id" value="<?= $repoId ?>">
                            <button type="submit" class="btn btn-secondary" style="padding: 0.25rem 0.75rem; font-size: 0.85rem; border-color: var(--danger-fg); color: var(--danger-fg);">Entfernen</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$catalog['ok']): ?>
                <p style="color: var(--text-subtle); font-style: italic; font-size: 0.9rem;">Katalog konnte nicht geladen werden<?= $catalog['error'] ? ': ' . htmlspecialchars($catalog['error']) : '' ?>.</p>
            <?php elseif (empty($catalog['plugins'])): ?>
                <p style="color: var(--text-subtle); font-style: italic; font-size: 0.9rem;">Keine Plugins in diesem Repository gefunden (erwartet: <code>plugins/&lt;slug&gt;/plugin.json</code> oder ein <code>plugin.json</code> im Repo-Root).</p>
            <?php else: ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--border-color); text-align: left;">
                            <th style="padding: 0.4rem;">Plugin</th>
                            <th style="padding: 0.4rem;">Version</th>
                            <th style="padding: 0.4rem;">Status</th>
                            <th style="padding: 0.4rem;">Aktion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($catalog['plugins'] as $entry): ?>
                            <?php
                                $slug = $entry['slug'];
                                $installedInfo = $discovered[$slug] ?? null;
                                $installedVersion = $installedInfo['manifest']['version'] ?? null;
                                $isInstalled = $installedInfo !== null;
                                $hasUpdate = $isInstalled && $installedVersion !== null && version_compare((string)$entry['version'], (string)$installedVersion, '>');
                            ?>
                            <tr style="border-bottom: 1px solid var(--border-color); vertical-align: top;">
                                <td style="padding: 0.4rem;">
                                    <strong><?= htmlspecialchars($entry['name']) ?></strong><br>
                                    <span style="color: var(--text-subtle); font-size: 0.8rem;"><?= htmlspecialchars($slug) ?></span>
                                    <?php if (!empty($entry['description'])): ?>
                                        <p style="margin: 0.2rem 0 0 0; color: var(--text-muted); font-size: 0.82rem;"><?= htmlspecialchars($entry['description']) ?></p>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.4rem;"><?= htmlspecialchars($entry['version']) ?></td>
                                <td style="padding: 0.4rem;">
                                    <?php if ($hasUpdate): ?>
                                        <span style="padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.78rem; background-color: var(--warning-soft-bg); color: var(--warning-fg); font-weight: 600;">Update verfügbar (aktuell <?= htmlspecialchars((string)$installedVersion) ?>)</span>
                                    <?php elseif ($isInstalled): ?>
                                        <span style="padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.78rem; background-color: var(--success-soft-bg); color: var(--success-fg); font-weight: 600;">Installiert (aktuell)</span>
                                    <?php else: ?>
                                        <span style="padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.78rem; background-color: var(--surface-muted); color: var(--text-color); font-weight: 600;">Nicht installiert</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.4rem;">
                                    <?php if (!$isInstalled || $hasUpdate): ?>
                                        <form action="/admin/plugins/store/install" method="POST" data-confirm="Plugin '<?= htmlspecialchars(($entry['name'])) ?>' aus <?= htmlspecialchars(($repoRow['owner'] . '/' . $repoRow['repo'])) ?> installieren? Der Code wird nach plugins/<?= htmlspecialchars(($slug)) ?>/ kopiert, aber nicht automatisch aktiviert." >
                                            <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                            <input type="hidden" name="repo_id" value="<?= $repoId ?>">
                                            <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
                                            <?php if ($isInstalled): ?>
                                                <input type="hidden" name="overwrite" value="1">
                                            <?php endif; ?>
                                            <button type="submit" class="btn" style="padding: 0.25rem 0.75rem; font-size: 0.85rem;"><?= $isInstalled ? 'Aktualisieren' : 'Installieren' ?></button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: var(--text-subtle); font-size: 0.85rem;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
