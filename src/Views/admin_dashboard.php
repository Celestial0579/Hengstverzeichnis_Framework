<?php
// src/Views/admin_dashboard.php
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$trashCount = \App\Controllers\TrashController::getTrashCount();
?>
<div style="display: flex; flex-direction: column; gap: 1.5rem; max-width: 900px; margin: 0 auto;">
    
    <div class="card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2 style="margin: 0; color: var(--primary-color);">Admin Dashboard</h2>
            <p style="margin: 0.3rem 0 0 0; color: #666; font-size: 0.95rem;">
                Angemeldet als: <strong><?= htmlspecialchars($_SESSION['username'] ?? 'Benutzer') ?></strong> 
                (<span style="color: var(--secondary-color); font-weight: bold;"><?= $isAdmin ? 'Administrator' : 'Editor' ?></span>)
            </p>
        </div>
        <form action="/logout" method="POST" style="margin: 0;">
            <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
            <button type="submit" class="btn btn-secondary" style="border-color: #dc3545; color: #dc3545; padding: 0.5rem 1rem;">🚪 Abmelden</button>
        </form>
    </div>

    <!-- Section 1: Verwaltung -->
    <div class="card">
        <h3 style="margin-top: 0; color: var(--primary-color); border-bottom: 2px solid var(--secondary-color); padding-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
            📁 Verwaltung & Daten
        </h3>
        <p style="color: #666; font-size: 0.9rem; margin-bottom: 1.2rem;">
            Verwaltung des Hengstkatalogs, Züchter-, Besitzer- und Stammbaumdaten.
        </p>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
            <a href="/admin/horses" class="btn btn-secondary" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.9rem; font-size: 1rem;">
                🐴 Pferde verwalten
            </a>
            <a href="/admin/persons" class="btn btn-secondary" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.9rem; font-size: 1rem;">
                👤 Personen verwalten
            </a>
            <a href="/admin/breeding-stations" class="btn btn-secondary" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.9rem; font-size: 1rem;">
                🏠 Deckstationen verwalten
            </a>
            <a href="/admin/matches" class="btn btn-secondary" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.9rem; font-size: 1rem;">
                🔗 Blutlinien zusammenführen
            </a>
            <a href="/admin/trash" class="btn btn-secondary" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.9rem; font-size: 1rem; position: relative;">
                🗑️ Papierkorb
                <?php if ($trashCount > 0): ?>
                    <span style="background: #dc3545; color: white; border-radius: 10px; padding: 0.15rem 0.5rem; font-size: 0.8rem; font-weight: bold;">
                        <?= $trashCount ?>
                    </span>
                <?php endif; ?>
            </a>
        </div>
    </div>

    <!-- Section 2: Systemeinstellungen (Admin-Only) -->
    <?php if ($isAdmin): ?>
        <div class="card">
            <h3 style="margin-top: 0; color: var(--primary-color); border-bottom: 2px solid var(--secondary-color); padding-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                ⚙️ Systemeinstellungen & Konfiguration
            </h3>
            <p style="color: #666; font-size: 0.9rem; margin-bottom: 1.2rem;">
                Globale Konfigurationen, Branding, E-Mail-Server und Benutzerverwaltung.
            </p>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
                <a href="/admin/system-settings" class="btn btn-secondary" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.9rem; font-size: 1rem;">
                    ⚙️ Systemeinstellungen
                </a>
                <a href="/admin/settings" class="btn btn-secondary" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.9rem; font-size: 1rem;">
                    🎨 Branding & Erscheinungsbild
                </a>
                <a href="/admin/mail-settings" class="btn btn-secondary" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.9rem; font-size: 1rem;">
                    ✉️ E-Mail & SMTP Einstellungen
                </a>
                <a href="/admin/users" class="btn btn-secondary" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.9rem; font-size: 1rem;">
                    👥 Benutzer verwalten
                </a>
                <a href="/admin/groups" class="btn btn-secondary" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.9rem; font-size: 1rem;">
                    🛂 Gruppen & Berechtigungen
                </a>
                <a href="/admin/gdpr" class="btn btn-secondary" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.9rem; font-size: 1rem;">
                    🛡️ DSGVO Anfragen
                </a>
                <a href="/admin/logs" class="btn btn-secondary" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.9rem; font-size: 1rem;">
                    📜 Audit-Log (Protokoll)
                </a>
                <a href="/admin/plugins" class="btn btn-secondary" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.9rem; font-size: 1rem;">
                    🧩 Plugins verwalten
                </a>
                <a href="/admin/cron" class="btn btn-secondary" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.9rem; font-size: 1rem;">
                    ⏱️ Automatisierung (Cron)
                </a>
                <?php foreach ($pluginTiles ?? [] as $tile): ?>
                    <?php if (empty($tile['url']) || empty($tile['label'])) continue; ?>
                    <a href="<?= htmlspecialchars($tile['url']) ?>" class="btn btn-secondary" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.9rem; font-size: 1rem;">
                        <?= htmlspecialchars(($tile['icon'] ?? '🧩') . ' ' . $tile['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</div>
