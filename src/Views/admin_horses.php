<?php
// src/Views/admin_horses.php
/**
 * @var array $horses
 * @var bool $canCreate
 * @var bool $canEdit
 * @var bool $canDelete
 * @var bool $canPublish
 * @var int|null $publishedFilter Aktiver Filter: 1, 0 oder null (alle)
 * @var array<string, string> $filters Geprüfte Suchparameter (HorseSearchCriteria)
 * @var bool $hasActiveFilters
 * @var array<int, string> $colors
 * @var array<int, string> $breeds
 * @var array<int, string> $stations
 * @var array<int, string> $persons
 * @var int $page
 * @var int $totalPages
 * @var int $totalCount
 */
$canPublish = $canPublish ?? false;
$publishedFilter = $publishedFilter ?? null;
$publishBase = '/admin/horses'; // Basis-Pfad für Filter-/Bulk-/Blätter-Partials
$publishFormId = 'horsePublishForm';
$filters = $filters ?? [];
$hasActiveFilters = $hasActiveFilters ?? false;
// Beim Zurücksetzen bleibt der Veröffentlichungs-Filter stehen: Er ist eine
// Ansicht der Liste, kein Suchbegriff.
$resetHref = '/admin/horses' . ($publishedFilter !== null ? '?published=' . (int)$publishedFilter : '');
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
                // #298: Widersprüche in der Abstammung, die nie stimmen können.
                'same_sire_and_dam' => 'Nicht gespeichert: Vater und Mutter dürfen nicht dasselbe Pferd sein.',
                'sire_not_older' => 'Nicht gespeichert: Der Vater ist im selben Jahr oder später geboren als das Pferd.',
                'dam_not_older' => 'Nicht gespeichert: Die Mutter ist im selben Jahr oder später geboren als das Pferd.',
            ];
            echo htmlspecialchars($errorMessages[$_GET['error']] ?? 'Aktion fehlgeschlagen.');
            ?>
        </div>
    <?php endif; ?>

    <?php // Suche (#Admin-Suche): dieselben Felder wie im öffentlichen Katalog,
          // aber ohne dessen Sichtbarkeitsgrenzen - hier sollen gerade die
          // unveröffentlichten Datensätze auffindbar sein. Die Detailfelder
          // stecken in einem <details>, damit die Liste nicht von einem
          // Filterblock erschlagen wird (Vorbild: public_catalog.php). ?>
    <form action="/admin/horses" method="GET" style="background: var(--surface-muted); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 1rem;">
        <?php if ($publishedFilter !== null): ?><input type="hidden" name="published" value="<?= (int)$publishedFilter ?>"><?php endif; ?>
        <div style="display: flex; gap: 0.8rem; flex-wrap: wrap; align-items: center;">
            <div style="flex: 1; min-width: 240px;">
                <label for="admin-horse-search" class="sr-only">Pferde durchsuchen</label>
                <input type="text" id="admin-horse-search" name="search" class="form-control" autocomplete="off"
                       placeholder="🔍 Name, UELN, Züchter, Besitzer, Deckstation, Eltern …"
                       value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
            </div>
            <button type="submit" class="btn">Suchen</button>
            <?php if ($hasActiveFilters): ?>
                <a href="<?= htmlspecialchars($resetHref) ?>" class="btn btn-secondary">Zurücksetzen</a>
            <?php endif; ?>
            <span style="background: var(--surface-muted); border: 1px solid var(--border-color); padding: 0.3rem 0.8rem; border-radius: 12px; font-size: 0.85rem; color: var(--text-muted);">
                <?= (int)($totalCount ?? count($horses)) ?> Treffer
            </span>
        </div>

        <details <?= $hasActiveFilters ? 'open' : '' ?> style="margin-top: 1rem; border-top: 1px solid var(--border-color); padding-top: 0.8rem;">
            <summary style="font-weight: bold; color: var(--primary-fg); cursor: pointer; user-select: none;">Erweiterte Suche</summary>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.8rem; margin-top: 1rem;">
                <div class="form-group">
                    <label for="admin-horse-q-name" style="font-size: 0.85rem; font-weight: bold;">Pferdename</label>
                    <input type="text" id="admin-horse-q-name" name="q_name" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-horse-q-ueln" style="font-size: 0.85rem; font-weight: bold;">UELN / Lebensnummer</label>
                    <input type="text" id="admin-horse-q-ueln" name="q_ueln" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_ueln'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-horse-q-breeder" style="font-size: 0.85rem; font-weight: bold;">Züchter</label>
                    <input type="text" id="admin-horse-q-breeder" name="q_breeder" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_breeder'] ?? '') ?>" list="admin_horse_person_list">
                </div>
                <div class="form-group">
                    <label for="admin-horse-q-owner" style="font-size: 0.85rem; font-weight: bold;">Besitzer</label>
                    <input type="text" id="admin-horse-q-owner" name="q_owner" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_owner'] ?? '') ?>" list="admin_horse_person_list">
                </div>
                <div class="form-group">
                    <label for="admin-horse-q-station" style="font-size: 0.85rem; font-weight: bold;">Deckstation / Gestüt</label>
                    <input type="text" id="admin-horse-q-station" name="q_station" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_station'] ?? '') ?>" list="admin_horse_station_list">
                </div>
                <div class="form-group">
                    <label for="admin-horse-q-sire" style="font-size: 0.85rem; font-weight: bold;">Vater</label>
                    <input type="text" id="admin-horse-q-sire" name="q_sire" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_sire'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-horse-q-dam" style="font-size: 0.85rem; font-weight: bold;">Mutter</label>
                    <input type="text" id="admin-horse-q-dam" name="q_dam" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_dam'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-horse-q-color" style="font-size: 0.85rem; font-weight: bold;">Farbe</label>
                    <select id="admin-horse-q-color" name="q_color" class="form-control" style="padding: 0.5rem;">
                        <option value="">Alle Farben</option>
                        <?php foreach (($colors ?? []) as $col): ?>
                            <option value="<?= htmlspecialchars((string)$col) ?>" <?= ($filters['q_color'] ?? '') === $col ? 'selected' : '' ?>><?= htmlspecialchars((string)$col) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="admin-horse-q-sex" style="font-size: 0.85rem; font-weight: bold;">Geschlecht</label>
                    <select id="admin-horse-q-sex" name="q_sex" class="form-control" style="padding: 0.5rem;">
                        <option value="">Alle</option>
                        <?php foreach (['stallion' => 'Hengst', 'mare' => 'Stute', 'gelding' => 'Wallach'] as $sexValue => $sexLabel): ?>
                            <option value="<?= $sexValue ?>" <?= ($filters['q_sex'] ?? '') === $sexValue ? 'selected' : '' ?>><?= $sexLabel ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="admin-horse-q-breed" style="font-size: 0.85rem; font-weight: bold;">Rasse</label>
                    <input type="text" id="admin-horse-q-breed" name="q_breed" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_breed'] ?? '') ?>" list="admin_horse_breed_list">
                </div>
                <div class="form-group">
                    <label for="admin-horse-birth-from" style="font-size: 0.85rem; font-weight: bold;">Jahrgang von</label>
                    <input type="number" id="admin-horse-birth-from" name="birth_year_from" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['birth_year_from'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-horse-birth-to" style="font-size: 0.85rem; font-weight: bold;">Jahrgang bis</label>
                    <input type="number" id="admin-horse-birth-to" name="birth_year_to" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['birth_year_to'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-horse-q-status" style="font-size: 0.85rem; font-weight: bold;">Status</label>
                    <select id="admin-horse-q-status" name="q_status" class="form-control" style="padding: 0.5rem;">
                        <option value="">Alle</option>
                        <option value="active" <?= ($filters['q_status'] ?? '') === 'active' ? 'selected' : '' ?>>Aktiv (Gekört)</option>
                        <option value="inactive" <?= ($filters['q_status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inaktiv</option>
                        <option value="deceased" <?= ($filters['q_status'] ?? '') === 'deceased' ? 'selected' : '' ?>>Verstorben</option>
                    </select>
                </div>
            </div>
            <div style="margin-top: 0.8rem; text-align: right;">
                <button type="submit" class="btn" style="padding: 0.5rem 1.2rem;">Filter anwenden</button>
            </div>
        </details>

        <?php // Vorschlagslisten: im Admin bewusst ohne is_published-Filter. ?>
        <datalist id="admin_horse_person_list">
            <?php foreach (($persons ?? []) as $personName): ?><option value="<?= htmlspecialchars((string)$personName) ?>"><?php endforeach; ?>
        </datalist>
        <datalist id="admin_horse_station_list">
            <?php foreach (($stations ?? []) as $stationName): ?><option value="<?= htmlspecialchars((string)$stationName) ?>"><?php endforeach; ?>
        </datalist>
        <datalist id="admin_horse_breed_list">
            <?php foreach (($breeds ?? []) as $breedName): ?><option value="<?= htmlspecialchars((string)$breedName) ?>"><?php endforeach; ?>
        </datalist>
    </form>

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
                                <form action="/admin/horses/delete" method="POST" data-confirm="Möchten Sie dieses Pferd wirklich löschen?" style="display:inline;">
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

    <?php require __DIR__ . '/partials/admin_pagination.php'; ?>

    <div style="margin-top: 2rem;">
        <a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a>
    </div>
</div>
