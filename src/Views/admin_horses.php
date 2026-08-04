<?php
// src/Views/admin_horses.php
/**
 * @var array $horses
 */
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
        <h2>🐴 Pferde verwalten</h2>
        <div style="display: flex; gap: 0.5rem;">
            <a href="/admin/matches" class="btn btn-secondary">🔗 Blutlinien Zusammenführen</a>
            <a href="/admin/horses/create" class="btn">Neues Pferd anlegen</a>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            Aktion erfolgreich durchgeführt.
        </div>
    <?php endif; ?>

    <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
        <thead>
            <tr style="border-bottom: 2px solid #eee; text-align: left;">
                <th style="padding: 0.5rem;">ID</th>
                <th style="padding: 0.5rem;">Foto</th>
                <th style="padding: 0.5rem;">Name</th>
                <th style="padding: 0.5rem;">UELN</th>
                <th style="padding: 0.5rem;">Geburtsjahr</th>
                <th style="padding: 0.5rem;">Status</th>
                <th style="padding: 0.5rem;">Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($horses)): ?>
                <tr>
                    <td colspan="7" style="padding: 1rem; text-align: center;">Keine Pferde gefunden.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($horses as $horse): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 0.5rem;"><?= htmlspecialchars((string)$horse['id']) ?></td>
                        <td style="padding: 0.5rem;">
                            <?php if (!empty($horse['image_url'])): ?>
                                <img src="<?= htmlspecialchars($horse['image_url']) ?>" alt="Foto" style="width: 45px; height: 45px; object-fit: cover; border-radius: 6px; border: 1px solid #ddd;">
                            <?php else: ?>
                                <span style="font-size: 1.2rem; opacity: 0.3;">🐴</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.5rem;"><strong><?= htmlspecialchars((string)$horse['name']) ?></strong></td>
                        <td style="padding: 0.5rem;"><?= htmlspecialchars((string)$horse['ueln']) ?></td>
                        <td style="padding: 0.5rem;"><?= htmlspecialchars((string)$horse['birth_year']) ?></td>
                        <td style="padding: 0.5rem;">
                            <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background-color: <?= $horse['status'] === 'active' ? '#d4edda' : '#f8d7da' ?>; color: <?= $horse['status'] === 'active' ? '#155724' : '#721c24' ?>;">
                                <?= $horse['status'] === 'active' ? 'Aktiv (Gekört)' : ($horse['status'] === 'inactive' ? 'Inaktiv' : 'Verstorben') ?>
                            </span>
                        </td>
                        <td style="padding: 0.5rem; display: flex; gap: 0.5rem;">
                            <a href="/admin/horses/edit?id=<?= $horse['id'] ?>" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.9rem;">Bearbeiten</a>
                            <form action="/admin/horses/delete" method="POST" onsubmit="return confirm('Möchten Sie dieses Pferd wirklich löschen?');" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                <input type="hidden" name="id" value="<?= $horse['id'] ?>">
                                <button type="submit" class="btn" style="padding: 0.25rem 0.5rem; font-size: 0.9rem; background-color: #dc3545;">Löschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <div style="margin-top: 2rem;">
        <a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a>
    </div>
</div>
