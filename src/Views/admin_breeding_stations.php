<?php
// src/Views/admin_breeding_stations.php
/**
 * @var array $stations
 * @var bool $canCreate
 * @var bool $canEdit
 * @var bool $canDelete
 * @var bool $canPublish
 * @var int|null $publishedFilter Aktiver Filter: 1, 0 oder null (alle)
 * @var array<string, string> $filters Geprüfte Suchparameter (BreedingStationController::index)
 * @var bool $hasActiveFilters
 * @var array<int, string> $countries
 * @var int $page
 * @var int $totalPages
 * @var int $totalCount
 */
$canPublish = $canPublish ?? false;
$publishedFilter = $publishedFilter ?? null;
$publishBase = '/admin/breeding-stations';
$publishFormId = 'stationPublishForm';
$filters = $filters ?? [];
$hasActiveFilters = $hasActiveFilters ?? false;
$resetHref = '/admin/breeding-stations' . ($publishedFilter !== null ? '?published=' . (int)$publishedFilter : '');
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2>🏠 Deckstationen & Gestüte verwalten</h2>
            <p style="color: var(--text-muted); font-size: 0.95rem; margin-top: 0.2rem;">Zentrale Pflege von Deckstationen, Ansprechpartnern und Kontaktdaten für Hengststandorte.</p>
        </div>
        <?php if ($canCreate): ?>
            <a href="/admin/breeding-stations/create" class="btn">+ Neue Deckstation anlegen</a>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= $_GET['success'] === 'created' ? '✓ Deckstation erfolgreich angelegt.' : ($_GET['success'] === 'updated' ? '✓ Deckstation aktualisiert.' : '✓ Deckstation gelöscht.') ?>
        </div>
    <?php endif; ?>

    <form action="/admin/breeding-stations" method="GET" style="background: var(--surface-muted); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 1rem;">
        <?php if ($publishedFilter !== null): ?><input type="hidden" name="published" value="<?= (int)$publishedFilter ?>"><?php endif; ?>
        <div style="display: flex; gap: 0.8rem; flex-wrap: wrap; align-items: center;">
            <div style="flex: 1; min-width: 240px;">
                <label for="admin-station-search" class="sr-only">Deckstationen durchsuchen</label>
                <input type="text" id="admin-station-search" name="search" class="form-control" autocomplete="off"
                       placeholder="🔍 Name, Ansprechpartner, Ort, PLZ, Land, Kontakt …"
                       value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
            </div>
            <button type="submit" class="btn">Suchen</button>
            <?php if ($hasActiveFilters): ?>
                <a href="<?= htmlspecialchars($resetHref) ?>" class="btn btn-secondary">Zurücksetzen</a>
            <?php endif; ?>
            <span style="background: var(--surface-muted); border: 1px solid var(--border-color); padding: 0.3rem 0.8rem; border-radius: 12px; font-size: 0.85rem; color: var(--text-muted);">
                <?= (int)($totalCount ?? count($stations)) ?> Treffer
            </span>
        </div>

        <details <?= $hasActiveFilters ? 'open' : '' ?> style="margin-top: 1rem; border-top: 1px solid var(--border-color); padding-top: 0.8rem;">
            <summary style="font-weight: bold; color: var(--primary-fg); cursor: pointer; user-select: none;">Erweiterte Suche</summary>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.8rem; margin-top: 1rem;">
                <div class="form-group">
                    <label for="admin-station-q-name" style="font-size: 0.85rem; font-weight: bold;">Name der Station</label>
                    <input type="text" id="admin-station-q-name" name="q_name" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-station-q-contact" style="font-size: 0.85rem; font-weight: bold;">Ansprechpartner</label>
                    <input type="text" id="admin-station-q-contact" name="q_contact" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_contact'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-station-q-city" style="font-size: 0.85rem; font-weight: bold;">Ort</label>
                    <input type="text" id="admin-station-q-city" name="q_city" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_city'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-station-q-plz" style="font-size: 0.85rem; font-weight: bold;">PLZ</label>
                    <input type="text" id="admin-station-q-plz" name="q_postal_code" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_postal_code'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-station-q-state" style="font-size: 0.85rem; font-weight: bold;">Bundesland / Kanton</label>
                    <input type="text" id="admin-station-q-state" name="q_state" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_state'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-station-q-country" style="font-size: 0.85rem; font-weight: bold;">Land</label>
                    <input type="text" id="admin-station-q-country" name="q_country" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_country'] ?? '') ?>" list="admin_station_country_list">
                    <datalist id="admin_station_country_list">
                        <?php foreach (($countries ?? []) as $countryOption): ?><option value="<?= htmlspecialchars((string)$countryOption) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
            </div>
            <div style="margin-top: 0.8rem; text-align: right;">
                <button type="submit" class="btn" style="padding: 0.5rem 1.2rem;">Filter anwenden</button>
            </div>
        </details>
    </form>

    <?php require __DIR__ . '/partials/publish_filter_bar.php'; ?>
    <?php if ($canPublish): require __DIR__ . '/partials/publish_bulk_bar.php'; endif; ?>

    <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
        <thead>
            <tr style="border-bottom: 2px solid var(--border-color); text-align: left;">
                <?php if ($canPublish): ?><th style="padding: 0.6rem;"><input type="checkbox" onclick="togglePublishSelection(this)" title="Alle auswählen"></th><?php endif; ?>
                <th style="padding: 0.6rem;">ID</th>
                <th style="padding: 0.6rem;">Name der Station / Gestüt</th>
                <th style="padding: 0.6rem;">Ansprechpartner</th>
                <th style="padding: 0.6rem;">Kontakt / E-Mail / Telefon</th>
                <th style="padding: 0.6rem;">Pferde</th>
                <th style="padding: 0.6rem;">Sichtbarkeit</th>
                <th style="padding: 0.6rem;">Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($stations)): ?>
                <tr>
                    <td colspan="<?= $canPublish ? 8 : 7 ?>" style="padding: 1.5rem; text-align: center; color: var(--text-subtle);">Noch keine Deckstationen angelegt.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($stations as $st): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <?php if ($canPublish): ?><td style="padding: 0.6rem;"><input type="checkbox" name="ids[]" value="<?= (int)$st['id'] ?>" form="<?= $publishFormId ?>"></td><?php endif; ?>
                        <td style="padding: 0.6rem;"><?= htmlspecialchars((string)$st['id']) ?></td>
                        <td style="padding: 0.6rem;">
                            <strong><?= htmlspecialchars((string)$st['name']) ?></strong>
                            <?php $stWebsite = App\Helper\ExternalUrl::hrefOrNull($st['website'] ?? null); ?>
                            <?php if ($stWebsite !== null): ?>
                                <br><a href="<?= htmlspecialchars($stWebsite) ?>" target="_blank" rel="noopener noreferrer" style="font-size: 0.8rem; color: var(--primary-fg);">🌐 Website</a>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.6rem;"><?= htmlspecialchars((string)($st['contact_person'] ?: '-')) ?></td>
                        <td style="padding: 0.6rem; font-size: 0.9rem;">
                            <?php if (!empty($st['phone'])): ?>📞 <?= htmlspecialchars((string)$st['phone']) ?><br><?php endif; ?>
                            <?php if (!empty($st['email'])): ?>✉️ <?= htmlspecialchars((string)$st['email']) ?><?php endif; ?>
                            <?php if (empty($st['phone']) && empty($st['email'])): ?>-<?php endif; ?>
                        </td>
                        <td style="padding: 0.6rem;">
                            <span style="background: var(--surface-muted); color: var(--text-color); padding: 0.2rem 0.5rem; border-radius: 12px; font-size: 0.85rem; font-weight: bold;">
                                <?= (int)$st['horse_count'] ?> Pferde
                            </span>
                        </td>
                        <td style="padding: 0.6rem;">
                            <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background-color: <?= !empty($st['is_published']) ? '#d4edda' : '#f8d7da' ?>; color: <?= !empty($st['is_published']) ? '#155724' : '#721c24' ?>;">
                                <?= !empty($st['is_published']) ? '🌐 Veröffentlicht' : 'Nicht veröffentlicht' ?>
                            </span>
                        </td>
                        <td style="padding: 0.6rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <?php if ($canEdit): ?>
                                <a href="/admin/breeding-stations/edit?id=<?= $st['id'] ?>" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.85rem;">Bearbeiten</a>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form action="/admin/breeding-stations/delete" method="POST" data-confirm="Möchten Sie diese Deckstation wirklich löschen?" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                    <input type="hidden" name="id" value="<?= $st['id'] ?>">
                                    <button type="submit" class="btn" style="padding: 0.25rem 0.5rem; font-size: 0.85rem; background-color: #c62a38;">Löschen</button>
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
