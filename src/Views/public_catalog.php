<?php
// src/Views/public_catalog.php
/**
 * @var array $horses
 * @var array $filters
 * @var array $colors
 * @var array $stations
 * @var array $persons
 */

$hasActiveFilters = !empty(array_filter($filters ?? [], fn($v) => $v !== '' && $v !== null));
?>
<div class="card" style="margin-bottom: 2rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
        <h2>🐴 <?= htmlspecialchars(App\I18n\Translator::t('catalog.heading')) ?></h2>
        <div style="display: flex; align-items: center; gap: 0.8rem;">
            <span id="loading-spinner" style="display: none; font-size: 0.9rem; color: var(--primary-fg);">🔄 <?= htmlspecialchars(App\I18n\Translator::t('catalog.loading')) ?></span>
            <span id="hit-count-badge" aria-live="polite" style="background: var(--primary-color); color: white; padding: 0.3rem 0.8rem; border-radius: 12px; font-weight: bold; font-size: 0.9rem;">
                <?php $hitCount = $totalHorses ?? count($horses); ?>
                <?= htmlspecialchars(App\I18n\Translator::t($hitCount === 1 ? 'catalog.hit_count_one' : 'catalog.hit_count_other', ['count' => $hitCount])) ?>
            </span>
        </div>
    </div>

    <!-- Search & Filter Form (Asynchronous AJAX-Enabled) -->
    <form id="catalog-filter-form" action="/katalog" method="GET" style="background: var(--surface-muted); padding: 1.2rem; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 1.5rem;">
        
        <!-- Main Quick Search Bar -->
        <div style="display: flex; gap: 0.8rem; margin-bottom: 1rem; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 260px;">
                <label for="input-search" class="sr-only"><?= htmlspecialchars(App\I18n\Translator::t('catalog.search_placeholder')) ?></label>
                <input type="text" name="search" id="input-search" class="form-control" placeholder="🔍 <?= htmlspecialchars(App\I18n\Translator::t('catalog.search_placeholder')) ?>" value="<?= htmlspecialchars($filters['search'] ?? '') ?>" autocomplete="off">
            </div>
            <button type="submit" class="btn" style="padding: 0.75rem 1.5rem;"><?= htmlspecialchars(App\I18n\Translator::t('catalog.search_button')) ?></button>
            <a href="/katalog" id="btn-reset-filters" class="btn btn-secondary" style="padding: 0.75rem 1.2rem; text-decoration: none; <?= $hasActiveFilters ? '' : 'display: none;' ?>"><?= htmlspecialchars(App\I18n\Translator::t('catalog.reset_filters')) ?></a>
        </div>

        <!-- Toggle for Advanced Attribute Filters -->
        <details <?= $hasActiveFilters ? 'open' : '' ?> style="margin-top: 1rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
            <summary style="font-weight: bold; color: var(--primary-fg); cursor: pointer; user-select: none;">
                <?= htmlspecialchars(App\I18n\Translator::t('catalog.advanced_filters')) ?>
            </summary>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1.2rem;">

                <!-- Pferdename -->
                <div class="form-group">
                    <label for="filter-q-name" style="font-size: 0.85rem; font-weight: bold;"><?= htmlspecialchars(App\I18n\Translator::t('catalog.horse_name')) ?></label>
                    <input type="text" id="filter-q-name" name="q_name" class="form-control filter-field" style="padding: 0.5rem;" placeholder="<?= htmlspecialchars(App\I18n\Translator::t('catalog.horse_name_placeholder')) ?>" value="<?= htmlspecialchars($filters['q_name'] ?? '') ?>">
                </div>

                <!-- UELN (DE & Ausland) -->
                <div class="form-group">
                    <label for="filter-q-ueln" style="font-size: 0.85rem; font-weight: bold;"><?= htmlspecialchars(App\I18n\Translator::t('catalog.ueln_label')) ?></label>
                    <input type="text" id="filter-q-ueln" name="q_ueln" class="form-control filter-field" style="padding: 0.5rem;" placeholder="<?= htmlspecialchars(App\I18n\Translator::t('catalog.ueln_placeholder')) ?>" value="<?= htmlspecialchars($filters['q_ueln'] ?? '') ?>">
                </div>

                <!-- Züchter -->
                <div class="form-group">
                    <label for="filter-q-breeder" style="font-size: 0.85rem; font-weight: bold;"><?= htmlspecialchars(App\I18n\Translator::t('field.breeder')) ?></label>
                    <input type="text" id="filter-q-breeder" name="q_breeder" class="form-control filter-field" style="padding: 0.5rem;" placeholder="<?= htmlspecialchars(App\I18n\Translator::t('catalog.breeder_placeholder')) ?>" value="<?= htmlspecialchars($filters['q_breeder'] ?? '') ?>" list="breeder_list">
                    <datalist id="breeder_list">
                        <?php foreach ($persons as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <!-- Besitzer -->
                <div class="form-group">
                    <label for="filter-q-owner" style="font-size: 0.85rem; font-weight: bold;"><?= htmlspecialchars(App\I18n\Translator::t('field.owner')) ?></label>
                    <input type="text" id="filter-q-owner" name="q_owner" class="form-control filter-field" style="padding: 0.5rem;" placeholder="<?= htmlspecialchars(App\I18n\Translator::t('catalog.owner_placeholder')) ?>" value="<?= htmlspecialchars($filters['q_owner'] ?? '') ?>" list="owner_list">
                    <datalist id="owner_list">
                        <?php foreach ($persons as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <!-- Deckstation / Gestüt -->
                <div class="form-group">
                    <label for="filter-q-station" style="font-size: 0.85rem; font-weight: bold;"><?= htmlspecialchars(App\I18n\Translator::t('field.breeding_station')) ?></label>
                    <input type="text" id="filter-q-station" name="q_station" class="form-control filter-field" style="padding: 0.5rem;" placeholder="<?= htmlspecialchars(App\I18n\Translator::t('catalog.station_placeholder')) ?>" value="<?= htmlspecialchars($filters['q_station'] ?? '') ?>" list="station_list">
                    <datalist id="station_list">
                        <?php foreach ($stations as $st): ?>
                            <option value="<?= htmlspecialchars($st) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <!-- Vater (Sire) -->
                <div class="form-group">
                    <label for="filter-q-sire" style="font-size: 0.85rem; font-weight: bold;"><?= htmlspecialchars(App\I18n\Translator::t('catalog.sire')) ?></label>
                    <input type="text" id="filter-q-sire" name="q_sire" class="form-control filter-field" style="padding: 0.5rem;" placeholder="<?= htmlspecialchars(App\I18n\Translator::t('catalog.sire_placeholder')) ?>" value="<?= htmlspecialchars($filters['q_sire'] ?? '') ?>">
                </div>

                <!-- Mutter (Dam) -->
                <div class="form-group">
                    <label for="filter-q-dam" style="font-size: 0.85rem; font-weight: bold;"><?= htmlspecialchars(App\I18n\Translator::t('catalog.dam')) ?></label>
                    <input type="text" id="filter-q-dam" name="q_dam" class="form-control filter-field" style="padding: 0.5rem;" placeholder="<?= htmlspecialchars(App\I18n\Translator::t('catalog.dam_placeholder')) ?>" value="<?= htmlspecialchars($filters['q_dam'] ?? '') ?>">
                </div>

                <!-- Farbe -->
                <div class="form-group">
                    <label for="filter-q-color" style="font-size: 0.85rem; font-weight: bold;"><?= htmlspecialchars(App\I18n\Translator::t('field.color')) ?></label>
                    <select id="filter-q-color" name="q_color" class="form-control filter-field" style="padding: 0.5rem;">
                        <option value=""><?= htmlspecialchars(App\I18n\Translator::t('catalog.all_colors')) ?></option>
                        <?php foreach ($colors as $col): ?>
                            <option value="<?= htmlspecialchars($col) ?>" <?= ($filters['q_color'] ?? '') === $col ? 'selected' : '' ?>>
                                <?= htmlspecialchars($col) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Geschlecht (#165) -->
                <div class="form-group">
                    <label for="filter-q-sex" style="font-size: 0.85rem; font-weight: bold;"><?= htmlspecialchars(App\I18n\Translator::t('field.sex')) ?></label>
                    <select id="filter-q-sex" name="q_sex" class="form-control filter-field" style="padding: 0.5rem;">
                        <option value=""><?= htmlspecialchars(App\I18n\Translator::t('catalog.all_sexes')) ?></option>
                        <?php foreach (['stallion', 'mare', 'gelding'] as $sexOption): ?>
                            <option value="<?= $sexOption ?>" <?= ($filters['q_sex'] ?? '') === $sexOption ? 'selected' : '' ?>>
                                <?= htmlspecialchars(App\I18n\Translator::t('value.sex.' . $sexOption)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Rasse (#163) -->
                <div class="form-group">
                    <label for="filter-q-breed" style="font-size: 0.85rem; font-weight: bold;"><?= htmlspecialchars(App\I18n\Translator::t('field.breed')) ?></label>
                    <input type="text" id="filter-q-breed" name="q_breed" class="form-control filter-field" style="padding: 0.5rem;" placeholder="<?= htmlspecialchars(App\I18n\Translator::t('catalog.breed_placeholder')) ?>" value="<?= htmlspecialchars($filters['q_breed'] ?? '') ?>" list="breed_list">
                    <datalist id="breed_list">
                        <?php foreach ($breeds ?? [] as $b): ?>
                            <option value="<?= htmlspecialchars($b) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <!-- Geburtsjahr Von -->
                <div class="form-group">
                    <label for="filter-birth-year-from" style="font-size: 0.85rem; font-weight: bold;"><?= htmlspecialchars(App\I18n\Translator::t('catalog.birth_year_from')) ?></label>
                    <input type="number" id="filter-birth-year-from" name="birth_year_from" class="form-control filter-field" style="padding: 0.5rem;" placeholder="<?= htmlspecialchars(App\I18n\Translator::t('catalog.birth_year_from_placeholder')) ?>" value="<?= htmlspecialchars($filters['birth_year_from'] ?? '') ?>">
                </div>

                <!-- Geburtsjahr Bis -->
                <div class="form-group">
                    <label for="filter-birth-year-to" style="font-size: 0.85rem; font-weight: bold;"><?= htmlspecialchars(App\I18n\Translator::t('catalog.birth_year_to')) ?></label>
                    <input type="number" id="filter-birth-year-to" name="birth_year_to" class="form-control filter-field" style="padding: 0.5rem;" placeholder="<?= htmlspecialchars(App\I18n\Translator::t('catalog.birth_year_to_placeholder')) ?>" value="<?= htmlspecialchars($filters['birth_year_to'] ?? '') ?>">
                </div>

                <!-- Status -->
                <div class="form-group">
                    <label for="filter-q-status" style="font-size: 0.85rem; font-weight: bold;"><?= htmlspecialchars(App\I18n\Translator::t('catalog.status_label')) ?></label>
                    <select id="filter-q-status" name="q_status" class="form-control filter-field" style="padding: 0.5rem;">
                        <option value=""><?= htmlspecialchars(App\I18n\Translator::t('catalog.all_statuses')) ?></option>
                        <option value="active" <?= ($filters['q_status'] ?? '') === 'active' ? 'selected' : '' ?>><?= htmlspecialchars(App\I18n\Translator::t('catalog.status_active_option')) ?></option>
                        <option value="inactive" <?= ($filters['q_status'] ?? '') === 'inactive' ? 'selected' : '' ?>><?= htmlspecialchars(App\I18n\Translator::t('status.inactive')) ?></option>
                        <option value="deceased" <?= ($filters['q_status'] ?? '') === 'deceased' ? 'selected' : '' ?>><?= htmlspecialchars(App\I18n\Translator::t('status.deceased')) ?></option>
                    </select>
                </div>

            </div>

            <div style="margin-top: 1rem; text-align: right;">
                <button type="submit" class="btn" style="padding: 0.6rem 1.5rem;"><?= htmlspecialchars(App\I18n\Translator::t('catalog.apply_filters')) ?></button>
            </div>
        </details>
    </form>

    <!-- Async Horse Card Grid -->
    <div id="catalog-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem; transition: opacity 0.15s ease-in-out;">
        <?php include __DIR__ . '/public_catalog_cards.php'; ?>
    </div>
</div>

<!-- Katalog-Filter (#263): ausgelagert und defer geladen. Als Inline-Block
     waere defer wirkungslos - das Attribut gilt nur fuer externe Skripte. -->
<script defer src="/js/catalog-filter.js"></script>
