<?php
// src/Views/admin_plugins.php
/**
 * @var array $plugins Ergebnis von App\Plugin\PluginManager::getDiscoveredPlugins()
 */

$errorMessages = [
    'unknown_plugin' => 'Unbekanntes Plugin - bitte Seite neu laden.',
    'incompatible' => 'Dieses Plugin kann nicht aktiviert werden: fehlerhaftes Manifest oder nicht mit dieser Kern-Version kompatibel.',
];
$manager = \App\Plugin\PluginManager::getInstance();
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
        <h2 style="margin: 0;">🧩 Plugins verwalten</h2>
        <div style="display: flex; gap: 0.5rem;">
            <a href="/admin/plugins/store" class="btn">🛒 Addon-Store durchsuchen</a>
            <a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a>
        </div>
    </div>

    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.2rem;">
        Plugins erweitern das Framework über definierte Hooks, ohne Kern-Dateien zu verändern
        (siehe <a href="https://github.com/Celestial0579/Hengstverzeichnis_Framework/blob/main/docs/plugin-development.md" target="_blank" rel="noopener">Entwickler-Dokumentation</a>).
        Plugins werden lokal im Verzeichnis <code>plugins/</code> abgelegt und müssen hier bewusst aktiviert werden - eine gefundene
        Plugin-Datei allein hat noch keine Wirkung.
    </p>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            Aktion erfolgreich ausgeführt.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) && isset($errorMessages[$_GET['error']])): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars($errorMessages[$_GET['error']]) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($plugins)): ?>
        <p style="color: var(--text-subtle); font-style: italic;">Keine Plugins im Verzeichnis <code>plugins/</code> gefunden.</p>
    <?php else: ?>
        <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border-color); text-align: left;">
                    <th style="padding: 0.5rem;">Plugin</th>
                    <th style="padding: 0.5rem;">Version</th>
                    <th style="padding: 0.5rem;">Hooks</th>
                    <th style="padding: 0.5rem;">Status</th>
                    <th style="padding: 0.5rem;">Aktion</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plugins as $slug => $plugin): ?>
                    <?php
                        $manifest = $plugin['manifest'];
                        $isEnabled = $manager->isEnabled($slug);
                        $needsReapproval = $manager->needsReapproval($slug);
                        $isUsable = $plugin['error'] === null && $plugin['compatible'];
                    ?>
                    <tr style="border-bottom: 1px solid var(--border-color); vertical-align: top;">
                        <td style="padding: 0.5rem;">
                            <strong><?= htmlspecialchars($manifest['name'] ?? $slug) ?></strong><br>
                            <span style="color: var(--text-subtle); font-size: 0.85rem;"><?= htmlspecialchars($slug) ?></span>
                            <?php if (!empty($manifest['description'])): ?>
                                <p style="margin: 0.3rem 0 0 0; color: var(--text-muted); font-size: 0.85rem;"><?= htmlspecialchars($manifest['description']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.5rem;">
                            <?= htmlspecialchars($manifest['version'] ?? '?') ?><br>
                            <span style="color: var(--text-subtle); font-size: 0.8rem;">Kompatibilität: <?= htmlspecialchars($manifest['core_compatibility'] ?? '?') ?></span>
                        </td>
                        <td style="padding: 0.5rem; font-size: 0.85rem;">
                            <?php if (!empty($manifest['hooks']) && is_array($manifest['hooks'])): ?>
                                <?= htmlspecialchars(implode(', ', $manifest['hooks'])) ?>
                            <?php else: ?>
                                <span style="color: var(--text-subtle);">-</span>
                            <?php endif; ?>
                            <?php if (!empty($manifest['permissions']) && is_array($manifest['permissions'])): ?>
                                <br><span style="color: var(--text-subtle);">Berechtigungen: <?= htmlspecialchars(implode(', ', $manifest['permissions'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.5rem;">
                            <?php if ($plugin['error'] !== null): ?>
                                <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background-color: var(--danger-soft-bg); color: var(--danger-fg); font-weight: 600;" title="<?= htmlspecialchars($plugin['error']) ?>">⚠️ Ungültiges Manifest</span>
                            <?php elseif (!$plugin['compatible']): ?>
                                <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background-color: var(--warning-soft-bg); color: var(--warning-fg); font-weight: 600;"<?= !empty($plugin['incompatible_reason']) ? ' title="' . htmlspecialchars($plugin['incompatible_reason']) . '"' : '' ?>>⚠️ Inkompatibel</span>
                                <?php if (!empty($plugin['incompatible_reason'])): ?>
                                    <br><small style="color: var(--text-subtle);"><?= htmlspecialchars($plugin['incompatible_reason']) ?></small>
                                <?php endif; ?>
                            <?php elseif ($needsReapproval): ?>
                                <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background-color: var(--warning-soft-bg); color: var(--warning-fg); font-weight: 600;" title="Der Code hat sich seit der letzten Freigabe verändert, ohne dass die Versionsnummer im Manifest erhöht wurde. Das Plugin wird deshalb aktuell NICHT geladen. Die Aktivierung selbst ist NICHT verloren gegangen - ein Klick auf 'Erneut freigeben' reicht.">⚠️ Code geändert - erneute Freigabe nötig</span>
                            <?php elseif ($isEnabled): ?>
                                <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background-color: var(--success-soft-bg); color: var(--success-fg); font-weight: 600;">✅ Aktiv</span>
                            <?php else: ?>
                                <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background-color: var(--surface-muted); color: var(--text-color); font-weight: 600;">⏸️ Inaktiv</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <?php if ($isUsable && $needsReapproval): ?>
                                <form action="/admin/plugins/toggle" method="POST" style="display:inline;" data-confirm="<?= htmlspecialchars('Plugin \'' . ($manifest['name'] ?? $slug) . '\' erneut freigeben? Der Code hat sich seit der letzten Freigabe verändert - nur fortfahren, wenn Sie die Änderung selbst vorgenommen haben oder ihr vertrauen. Die bisherige Aktivierung bleibt dabei erhalten, es wird lediglich der neue Code als vertrauenswürdig bestätigt.', ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                    <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
                                    <input type="hidden" name="enable" value="1">
                                    <button type="submit" class="btn" style="padding: 0.25rem 0.75rem; font-size: 0.85rem;" title="Aktiviert das Plugin mit dem aktuellen Code erneut, ohne dass etwas an der bisherigen Konfiguration verloren geht.">Mit bisherigem Status erneut freigeben</button>
                                </form>
                                <form action="/admin/plugins/toggle" method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                    <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
                                    <input type="hidden" name="enable" value="0">
                                    <button type="submit" class="btn btn-secondary" style="padding: 0.25rem 0.75rem; font-size: 0.85rem; border-color: var(--danger-fg); color: var(--danger-fg);">Deaktivieren</button>
                                </form>
                            <?php elseif ($isUsable): ?>
                                <form action="/admin/plugins/toggle" method="POST" style="display:inline;" <?= $isEnabled ? '' : 'data-confirm="' . htmlspecialchars('Plugin \'' . ($manifest['name'] ?? $slug) . '\' aktivieren? Der Plugin-Code läuft danach im selben Prozess wie der Kern - nur Plugins aus vertrauenswürdiger Quelle aktivieren.', ENT_QUOTES, 'UTF-8') . '"' ?>>
                                    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                    <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">
                                    <input type="hidden" name="enable" value="<?= $isEnabled ? '0' : '1' ?>">
                                    <button type="submit" class="btn <?= $isEnabled ? 'btn-secondary' : '' ?>" style="padding: 0.25rem 0.75rem; font-size: 0.85rem; <?= $isEnabled ? 'border-color: var(--danger-fg); color: var(--danger-fg);' : '' ?>">
                                        <?= $isEnabled ? 'Deaktivieren' : 'Aktivieren' ?>
                                    </button>
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
