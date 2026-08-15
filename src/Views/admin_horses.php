<?php
// src/Views/admin_horses.php
/**
 * @var array $horses
 * @var bool $canCreate
 * @var bool $canEdit
 * @var bool $canDelete
 * @var bool $canPublish
 * @var int|null $publishedFilter Aktiver Filter: 1, 0 oder null (alle)
 */
$canPublish = $canPublish ?? false;
$publishedFilter = $publishedFilter ?? null;
$publishBase = '/admin/horses'; // Basis-Pfad für Filter-/Bulk-Partials
$publishFormId = 'horsePublishForm';
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
        <h2>🐴 Pferde verwalten</h2>
        <div style="display: flex; gap: 0.5rem;">
            <a href="/admin/matches" class="btn btn-secondary">🔗 Blutlinien Zusammenführen</a>
            <?php if ($canCreate): ?>
                <a href="/admin/import/horses" class="btn btn-secondary">📄 CSV-Import</a>
                <a href="/admin/horses/create" class="btn">Neues Pferd anlegen</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            Aktion erfolgreich durchgeführt.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?php
            // Fehlercodes aus HorseController::store()/update() (#166); unbekannte
            // Codes bekommen eine neutrale Meldung statt roher Parameter-Ausgabe.
            $errorMessages = [
                'sex_mismatch_sire' => 'Nicht gespeichert: Das als Vater gewählte Pferd ist als Stute erfasst.',
                'sex_mismatch_dam' => 'Nicht gespeichert: Das als Mutter gewählte Pferd ist als Hengst oder Wallach erfasst.',
                'death_before_birth' => 'Nicht gespeichert: Das Todesjahr liegt vor dem Geburtsjahr.',
            ];
            echo htmlspecialchars($errorMessages[$_GET['error']] ?? 'Aktion fehlgeschlagen.');
            ?>
        </div>
    <?php endif; ?>

    <?php require __DIR__ . '/partials/publish_filter_bar.php'; ?>
    <?php if ($canPublish): require __DIR__ . '/partials/publish_bulk_bar.php'; endif; ?>

    <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
        <thead>
            <tr style="border-bottom: 2px solid var(--border-color); text-align: left;">
                <?php if ($canPublish): ?><th style="padding: 0.5rem;"><input type="checkbox" onclick="togglePublishSelection(this)" title="Alle auswählen"></th><?php endif; ?>
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
                    <td colspan="<?= $canPublish ? 8 : 7 ?>" style="padding: 1rem; text-align: center;">Keine Pferde gefunden.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($horses as $horse): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <?php if ($canPublish): ?><td style="padding: 0.5rem;"><input type="checkbox" name="ids[]" value="<?= (int)$horse['id'] ?>" form="<?= $publishFormId ?>"></td><?php endif; ?>
                        <td style="padding: 0.5rem;"><?= htmlspecialchars((string)$horse['id']) ?></td>
                        <td style="padding: 0.5rem;">
                            <?php if (!empty($horse['image_url'])): ?>
                                <?php // Lazy-Loading (#263): Die Verwaltungsliste zeigt viele Zeilen
                                      // untereinander, die Vorschaubilder liegen fast alle unter dem
                                      // Falz. Feste Kantenlänge im style, also kein Layout-Sprung. ?>
                                <img src="<?= htmlspecialchars(App\Helper\MediaUrl::horseImage($horse) ?? '') ?>" alt="Foto" loading="lazy" decoding="async" style="width: 45px; height: 45px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color);">
                            <?php else: ?>
                                <span style="font-size: 1.2rem; opacity: 0.3;">🐴</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.5rem;"><strong><?= htmlspecialchars((string)$horse['name']) ?></strong></td>
                        <td style="padding: 0.5rem;"><?= htmlspecialchars((string)$horse['ueln']) ?></td>
                        <td style="padding: 0.5rem;"><?= htmlspecialchars((string)$horse['birth_year']) ?></td>
                        <td style="padding: 0.5rem;">
                            <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background-color: var(--surface-muted); color: var(--text-color);">
                                <?= $horse['status'] === 'active' ? 'Aktiv (Gekört)' : 'Inaktiv' ?>
                            </span>
                            <?php if (!empty($horse['is_deceased'])): ?>
                                <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background-color: var(--surface-muted); color: var(--text-muted); border: 1px solid var(--border-color);">
                                    ✝ Verstorben
                                </span>
                            <?php endif; ?>
                            <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background-color: <?= !empty($horse['is_published']) ? '#d4edda' : '#f8d7da' ?>; color: <?= !empty($horse['is_published']) ? '#155724' : '#721c24' ?>;">
                                <?= !empty($horse['is_published']) ? '🌐 Veröffentlicht' : 'Nicht veröffentlicht' ?>
                            </span>
                        </td>
                        <td style="padding: 0.5rem; display: flex; gap: 0.5rem;">
                            <?php if ($canEdit): ?>
                                <a href="/admin/horses/edit?id=<?= $horse['id'] ?>" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.9rem;">Bearbeiten</a>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form action="/admin/horses/delete" method="POST" onsubmit="return confirm('Möchten Sie dieses Pferd wirklich löschen?');" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                    <input type="hidden" name="id" value="<?= $horse['id'] ?>">
                                    <button type="submit" class="btn" style="padding: 0.25rem 0.5rem; font-size: 0.9rem; background-color: #c62a38;">Löschen</button>
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
