<?php
// src/Views/admin_persons.php
/**
 * @var array $persons
 * @var bool $canCreate
 * @var bool $canEdit
 * @var bool $canDelete
 */
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
        <div>
            <h2>👤 Personen verwalten</h2>
            <p style="color: #666; font-size: 0.95rem;">Verwaltung von Züchtern, Besitzern und früheren Eigentümern.</p>
        </div>
        <?php if ($canCreate): ?>
            <a href="/admin/persons/create" class="btn">Neue Person anlegen</a>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            Aktion erfolgreich ausgeführt.
        </div>
    <?php endif; ?>

    <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
        <thead>
            <tr style="border-bottom: 2px solid #eee; text-align: left;">
                <th style="padding: 0.5rem;">ID</th>
                <th style="padding: 0.5rem;">Name</th>
                <th style="padding: 0.5rem;">Kontakt & Ort</th>
                <th style="padding: 0.5rem;">Zugeordnete Pferde</th>
                <th style="padding: 0.5rem;">Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($persons)): ?>
                <tr>
                    <td colspan="5" style="padding: 1rem; text-align: center;">Keine Personen gefunden.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($persons as $p): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 0.5rem;"><?= htmlspecialchars((string)$p['id']) ?></td>
                        <td style="padding: 0.5rem;"><strong><?= htmlspecialchars((string)$p['name']) ?></strong></td>
                        <td style="padding: 0.5rem; font-size: 0.9rem; color: #555;">
                            <?= !empty($p['contact_info']) ? nl2br(htmlspecialchars((string)$p['contact_info'])) : '<em>Keine Angaben</em>' ?>
                        </td>
                        <td style="padding: 0.5rem;">
                            <span style="background: #e2e3e5; padding: 0.25rem 0.6rem; border-radius: 12px; font-weight: bold; font-size: 0.85rem;">
                                <?= (int)$p['horse_count'] ?> Zuordnungen
                            </span>
                        </td>
                        <td style="padding: 0.5rem; display: flex; gap: 0.5rem;">
                            <?php if ($canEdit): ?>
                                <a href="/admin/persons/edit?id=<?= $p['id'] ?>" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.9rem;">Bearbeiten</a>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form action="/admin/persons/delete" method="POST" onsubmit="return confirm('Möchten Sie diese Person wirklich löschen? Die Zuordnung zu allen Pferden wird dabei aufgehoben.');" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn" style="padding: 0.25rem 0.5rem; font-size: 0.9rem; background-color: #dc3545;">Löschen</button>
                                </form>
                            <?php endif; ?>
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
