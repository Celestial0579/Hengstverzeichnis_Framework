<?php
// src/Views/admin_dashboard.php
$isAdmin = \App\Permission\GroupMembership::isAdmin($_SESSION['user_id'] ?? null);
$trashCount = \App\Controllers\TrashController::getTrashCount();
// Offene Addon-Updates (#197): bewusst NUR aus dem Katalog-Cache des Stores
// (netzwerkfrei, siehe AddonOverview) - ohne Cache zeigt das Badge 0.
$addonUpdateCount = \App\Service\AddonOverview::openUpdateCount();

// Erster i18n-Schritt im Admin-Bereich (#48): Das Dashboard nutzt den
// Translator wie die öffentlichen Seiten; die übrigen Admin-Views folgen
// schrittweise.
$t = fn(string $key, array $params = []) => \App\I18n\Translator::t($key, $params);

// Anzeige der eigenen Gruppenmitgliedschaft (#66) statt der früheren
// pauschalen "Editor"-Rollenanzeige - es gibt keine Rolle mehr, "Editor" wäre
// ein konkreter, möglicherweise falscher Gruppenname.
$ownGroupNames = [];
if (!empty($_SESSION['user_id'])) {
    $stmt = \App\Database::getInstance()->prepare("
        SELECT g.name FROM user_groups ug
        JOIN `groups` g ON g.id = ug.group_id
        WHERE ug.user_id = ?
        ORDER BY g.is_builtin DESC, g.name ASC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $ownGroupNames = $stmt->fetchAll(\PDO::FETCH_COLUMN);
}
$ownGroupLabel = $isAdmin ? 'Administrator' : ($ownGroupNames ? implode(', ', $ownGroupNames) : $t('admin.dashboard.no_group'));

$tileStyle = 'display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.9rem; font-size: 1rem;';
?>
<div style="display: flex; flex-direction: column; gap: 1.5rem; max-width: 900px; margin: 0 auto;">

    <div class="card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2 style="margin: 0; color: var(--primary-fg);"><?= htmlspecialchars($t('admin.dashboard.title')) ?></h2>
            <p style="margin: 0.3rem 0 0 0; color: var(--text-muted); font-size: 0.95rem;">
                <?= htmlspecialchars($t('admin.dashboard.logged_in_as')) ?> <strong><?= htmlspecialchars($_SESSION['username'] ?? 'Benutzer') ?></strong>
                (<span style="color: var(--secondary-color); font-weight: bold;"><?= htmlspecialchars($ownGroupLabel) ?></span>)
            </p>
        </div>
        <form action="/logout" method="POST" style="margin: 0;">
            <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
            <button type="submit" class="btn btn-secondary" style="border-color: var(--danger-fg); color: var(--danger-fg); padding: 0.5rem 1rem;">🚪 <?= htmlspecialchars($t('admin.dashboard.logout')) ?></button>
        </form>
    </div>

    <!-- Section 1: Verwaltung -->
    <div class="card">
        <h3 style="margin-top: 0; color: var(--primary-fg); border-bottom: 2px solid var(--secondary-color); padding-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
            📁 <?= htmlspecialchars($t('admin.dashboard.management_heading')) ?>
        </h3>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.2rem;">
            <?= htmlspecialchars($t('admin.dashboard.management_text')) ?>
        </p>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
            <?php if ($canViewHorses ?? false): ?>
            <a href="/admin/horses" class="btn btn-secondary" style="<?= $tileStyle ?>">
                🐴 <?= htmlspecialchars($t('admin.dashboard.tile_horses')) ?>
            </a>
            <?php endif; ?>
            <?php // Eine Kachel fuer alle Kontakte (#336). Die beiden frueheren
                  // ("Personen verwalten", "Deckstationen verwalten") fuehrten in
                  // dieselbe Tabelle, sobald sie zusammengelegt war - zwei
                  // Eintraege haetten nur noch behauptet, es gaebe zwei Bestaende. ?>
            <?php if ($canViewContacts ?? false): ?>
            <a href="/admin/contacts" class="btn btn-secondary" style="<?= $tileStyle ?>">
                👤 <?= htmlspecialchars($t('admin.dashboard.tile_contacts')) ?>
            </a>
            <?php endif; ?>
            <?php if ($canViewHorses ?? false): ?>
            <a href="/admin/matches" class="btn btn-secondary" style="<?= $tileStyle ?>">
                🔗 <?= htmlspecialchars($t('admin.dashboard.tile_matches')) ?>
            </a>
            <?php endif; ?>
            <a href="/admin/trash" class="btn btn-secondary" style="<?= $tileStyle ?> position: relative;">
                🗑️ <?= htmlspecialchars($t('admin.dashboard.tile_trash')) ?>
                <?php if ($trashCount > 0): ?>
                    <span style="background: #dc3545; color: white; border-radius: 10px; padding: 0.15rem 0.5rem; font-size: 0.8rem; font-weight: bold;">
                        <?= $trashCount ?>
                    </span>
                <?php endif; ?>
            </a>
            <!-- Selfservice, bewusst ohne Rechteprüfung: ein API-Schlüssel kann
                 nie mehr als die eigenen Rechte erhalten (siehe App\Security\ApiKey). -->
            <a href="/api-keys" class="btn btn-secondary" style="<?= $tileStyle ?>">
                🔑 API-Schlüssel
            </a>
        </div>
    </div>

    <!-- Section 2: Systemeinstellungen (Admin-Only) -->
    <?php if ($isAdmin): ?>
        <div class="card">
            <h3 style="margin-top: 0; color: var(--primary-fg); border-bottom: 2px solid var(--secondary-color); padding-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                ⚙️ <?= htmlspecialchars($t('admin.dashboard.system_heading')) ?>
            </h3>
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.2rem;">
                <?= htmlspecialchars($t('admin.dashboard.system_text')) ?>
            </p>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
                <a href="/admin/system-settings" class="btn btn-secondary" style="<?= $tileStyle ?>">
                    ⚙️ <?= htmlspecialchars($t('admin.dashboard.tile_system_settings')) ?>
                </a>
                <a href="/admin/settings" class="btn btn-secondary" style="<?= $tileStyle ?>">
                    🎨 <?= htmlspecialchars($t('admin.dashboard.tile_branding')) ?>
                </a>
                <a href="/admin/mail-settings" class="btn btn-secondary" style="<?= $tileStyle ?>">
                    ✉️ <?= htmlspecialchars($t('admin.dashboard.tile_mail')) ?>
                </a>
                <a href="/admin/users" class="btn btn-secondary" style="<?= $tileStyle ?>">
                    👥 <?= htmlspecialchars($t('admin.dashboard.tile_users')) ?>
                </a>
                <a href="/admin/groups" class="btn btn-secondary" style="<?= $tileStyle ?>">
                    🛂 <?= htmlspecialchars($t('admin.dashboard.tile_groups')) ?>
                </a>
                <a href="/admin/gdpr" class="btn btn-secondary" style="<?= $tileStyle ?>">
                    🛡️ <?= htmlspecialchars($t('admin.dashboard.tile_gdpr')) ?>
                </a>
                <a href="/admin/logs" class="btn btn-secondary" style="<?= $tileStyle ?>">
                    📜 <?= htmlspecialchars($t('admin.dashboard.tile_logs')) ?>
                </a>
                <a href="/admin/plugins" class="btn btn-secondary" style="<?= $tileStyle ?>">
                    🧩 <?= htmlspecialchars($t('admin.dashboard.tile_plugins')) ?>
                </a>
                <a href="/admin/cron" class="btn btn-secondary" style="<?= $tileStyle ?>">
                    ⏱️ <?= htmlspecialchars($t('admin.dashboard.tile_cron')) ?>
                </a>
                <a href="/admin/backups" class="btn btn-secondary" style="<?= $tileStyle ?>">
                    💾 <?= htmlspecialchars($t('admin.dashboard.tile_backups')) ?>
                </a>
                <a href="/admin/digest" class="btn btn-secondary" style="<?= $tileStyle ?>">
                    📋 <?= htmlspecialchars($t('admin.dashboard.tile_digest')) ?>
                </a>
                <a href="/admin/updates" class="btn btn-secondary" style="<?= $tileStyle ?> position: relative;">
                    🔄 <?= htmlspecialchars($t('admin.dashboard.tile_updates')) ?>
                    <?php if ($addonUpdateCount > 0): ?>
                        <!-- Zähler offener ADDON-Updates (#197), gleiche Stelle
                             wie das Kern-Update - Muster wie beim Papierkorb. -->
                        <span style="background: #dc3545; color: white; border-radius: 10px; padding: 0.15rem 0.5rem; font-size: 0.8rem; font-weight: bold;">
                            <?= $addonUpdateCount ?>
                        </span>
                    <?php endif; ?>
                </a>
                <?php foreach ($pluginTiles ?? [] as $tile): ?>
                    <?php if (empty($tile['url']) || empty($tile['label'])) continue; ?>
                    <a href="<?= htmlspecialchars($tile['url']) ?>" class="btn btn-secondary" style="<?= $tileStyle ?>">
                        <?= htmlspecialchars(($tile['icon'] ?? '🧩') . ' ' . $tile['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</div>
