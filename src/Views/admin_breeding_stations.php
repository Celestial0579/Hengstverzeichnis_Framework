<?php
// src/Views/admin_breeding_stations.php
/**
 * @var array $stations
 * @var bool $canCreate
 * @var bool $canEdit
 * @var bool $canDelete
 */
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2>🏠 Deckstationen & Gestüte verwalten</h2>
            <p style="color: #666; font-size: 0.95rem; margin-top: 0.2rem;">Zentrale Pflege von Deckstationen, Ansprechpartnern und Kontaktdaten für Hengststandorte.</p>
        </div>
        <?php if ($canCreate): ?>
            <a href="/admin/breeding-stations/create" class="btn">+ Neue Deckstation anlegen</a>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= $_GET['success'] === 'created' ? '✓ Deckstation erfolgreich angelegt.' : ($_GET['success'] === 'updated' ? '✓ Deckstation aktualisiert.' : '✓ Deckstation gelöscht.') ?>
        </div>
    <?php endif; ?>

    <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
        <thead>
            <tr style="border-bottom: 2px solid var(--border-color); text-align: left;">
                <th style="padding: 0.6rem;">ID</th>
                <th style="padding: 0.6rem;">Name der Station / Gestüt</th>
                <th style="padding: 0.6rem;">Ansprechpartner</th>
                <th style="padding: 0.6rem;">Kontakt / E-Mail / Telefon</th>
                <th style="padding: 0.6rem;">Pferde</th>
                <th style="padding: 0.6rem;">Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($stations)): ?>
                <tr>
                    <td colspan="6" style="padding: 1.5rem; text-align: center; color: #777;">Noch keine Deckstationen angelegt.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($stations as $st): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: 0.6rem;"><?= htmlspecialchars((string)$st['id']) ?></td>
                        <td style="padding: 0.6rem;">
                            <strong><?= htmlspecialchars((string)$st['name']) ?></strong>
                            <?php if (!empty($st['website'])): ?>
                                <br><a href="<?= htmlspecialchars((string)$st['website']) ?>" target="_blank" style="font-size: 0.8rem; color: var(--primary-color);">🌐 Website</a>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.6rem;"><?= htmlspecialchars((string)($st['contact_person'] ?: '-')) ?></td>
                        <td style="padding: 0.6rem; font-size: 0.9rem;">
                            <?php if (!empty($st['phone'])): ?>📞 <?= htmlspecialchars((string)$st['phone']) ?><br><?php endif; ?>
                            <?php if (!empty($st['email'])): ?>✉️ <?= htmlspecialchars((string)$st['email']) ?><?php endif; ?>
                            <?php if (empty($st['phone']) && empty($st['email'])): ?>-<?php endif; ?>
                        </td>
                        <td style="padding: 0.6rem;">
                            <span style="background: #e2e3e5; color: #383d41; padding: 0.2rem 0.5rem; border-radius: 12px; font-size: 0.85rem; font-weight: bold;">
                                <?= (int)$st['horse_count'] ?> Pferde
                            </span>
                        </td>
                        <td style="padding: 0.6rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <?php if ($canEdit): ?>
                                <a href="/admin/breeding-stations/edit?id=<?= $st['id'] ?>" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.85rem;">Bearbeiten</a>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form action="/admin/breeding-stations/delete" method="POST" onsubmit="return confirm('Möchten Sie diese Deckstation wirklich löschen?');" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                    <input type="hidden" name="id" value="<?= $st['id'] ?>">
                                    <button type="submit" class="btn" style="padding: 0.25rem 0.5rem; font-size: 0.85rem; background-color: #dc3545;">Löschen</button>
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
