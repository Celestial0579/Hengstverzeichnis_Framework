<?php
// src/Views/admin_logs.php
/**
 * @var array $logs
 * @var array $categories
 * @var array $filters
 */
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2>📜 System Audit-Log (Standardansicht: letzte 30 Tage)</h2>
            <p style="color: #666; font-size: 0.95rem; margin: 0;">
                Revisionssicheres, unlöschbares Protokoll aller Systemaktivitäten und automatischen Aktionen. Es gibt keine automatische Löschfrist - die 30 Tage betreffen nur diese Standardansicht, die Daten selbst bleiben dauerhaft erhalten.
            </p>
        </div>
        <div style="background: #e8f4fd; border: 1px solid #b6d4fe; color: #084298; padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.85rem; font-weight: bold;">
            🔒 Revisionssicher & Unlöschbar (dauerhaft gespeichert)
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" action="/admin/logs" style="background: #f8f9fa; padding: 1rem; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 1.5rem;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; align-items: end;">
            
            <div class="form-group" style="margin: 0;">
                <label style="font-size: 0.85rem; font-weight: bold;">Kategorie</label>
                <select name="category" class="form-control" onchange="this.form.submit()">
                    <option value="">-- Alle Kategorien --</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" <?= ($filters['category'] === $cat) ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucfirst($cat)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin: 0;">
                <label style="font-size: 0.85rem; font-weight: bold;">Benutzer</label>
                <input type="text" name="user" class="form-control" placeholder="z. B. SYSTEM oder Admin Name" value="<?= htmlspecialchars($filters['user'] ?? '') ?>">
            </div>

            <div class="form-group" style="margin: 0;">
                <label style="font-size: 0.85rem; font-weight: bold;">Suche in Aktion / Details</label>
                <input type="text" name="search" class="form-control" placeholder="Aktion oder Detail-Text..." value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
            </div>

            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" class="btn" style="padding: 0.5rem 1rem;">🔍 Filtern</button>
                <a href="/admin/logs" class="btn btn-secondary" style="padding: 0.5rem 1rem;">🔄 Reset</a>
            </div>

        </div>
    </form>

    <!-- Logs Table -->
    <?php if (empty($logs)): ?>
        <div style="text-align: center; padding: 3rem 1rem; background: #fafafa; border-radius: 8px; border: 1px dashed #ccc;">
            <p style="color: #777; margin: 0;">Keine Log-Einträge für die gewählten Kriterien in den letzten 30 Tagen gefunden.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                <thead>
                    <tr style="background: #f1f3f5; border-bottom: 2px solid #dee2e6; text-align: left;">
                        <th style="padding: 0.8rem;">Zeitstempel</th>
                        <th style="padding: 0.8rem;">Benutzer</th>
                        <th style="padding: 0.8rem;">Kategorie</th>
                        <th style="padding: 0.8rem;">Aktion</th>
                        <th style="padding: 0.8rem;">Details</th>
                        <th style="padding: 0.8rem;">IP-Adresse</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php 
                            $isSystem = ($log['username'] === 'SYSTEM');
                            $userBadgeBg = $isSystem ? '#6c757d' : '#2a52be';
                        ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 0.7rem; white-space: nowrap; color: #555;">
                                <?= date('d.m.Y H:i:s', strtotime($log['created_at'])) ?>
                            </td>
                            <td style="padding: 0.7rem; white-space: nowrap;">
                                <span style="background: <?= $userBadgeBg ?>; color: #fff; padding: 0.2rem 0.5rem; border-radius: 12px; font-weight: bold; font-size: 0.75rem;">
                                    <?= htmlspecialchars($log['username']) ?>
                                </span>
                            </td>
                            <td style="padding: 0.7rem; white-space: nowrap;">
                                <span style="background: #eef2f5; color: #495057; padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.8rem; border: 1px solid #ced4da;">
                                    <?= htmlspecialchars($log['category']) ?>
                                </span>
                            </td>
                            <td style="padding: 0.7rem; font-weight: 500;">
                                <?= htmlspecialchars($log['action']) ?>
                            </td>
                            <td style="padding: 0.7rem; color: #444; max-width: 400px; word-break: break-word;">
                                <?= htmlspecialchars($log['details'] ?? '-') ?>
                            </td>
                            <td style="padding: 0.7rem; color: #777; font-family: monospace; font-size: 0.85rem;">
                                <?= htmlspecialchars($log['ip_address'] ?? '-') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
