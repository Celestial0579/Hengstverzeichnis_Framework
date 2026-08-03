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
        <h2>🐴 Hengstkatalog</h2>
        <div style="display: flex; align-items: center; gap: 0.8rem;">
            <span id="loading-spinner" style="display: none; font-size: 0.9rem; color: var(--primary-color);">🔄 Lädt...</span>
            <span id="hit-count-badge" style="background: var(--primary-color); color: white; padding: 0.3rem 0.8rem; border-radius: 12px; font-weight: bold; font-size: 0.9rem;">
                <?= count($horses) ?> <?= count($horses) === 1 ? 'Hengst gefunden' : 'Hengste gefunden' ?>
            </span>
        </div>
    </div>

    <!-- Search & Filter Form (Asynchronous AJAX-Enabled) -->
    <form id="catalog-filter-form" action="/katalog" method="GET" style="background: #fafafa; padding: 1.2rem; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 1.5rem;">
        
        <!-- Main Quick Search Bar -->
        <div style="display: flex; gap: 0.8rem; margin-bottom: 1rem; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 260px;">
                <input type="text" name="search" id="input-search" class="form-control" placeholder="🔍 Volltextsuche (Pferd, UELN, Züchter, Besitzer, Deckstation, Vater, Mutter)..." value="<?= htmlspecialchars($filters['search'] ?? '') ?>" autocomplete="off">
            </div>
            <button type="submit" class="btn" style="padding: 0.75rem 1.5rem;">Suchen</button>
            <a href="/katalog" id="btn-reset-filters" class="btn btn-secondary" style="padding: 0.75rem 1.2rem; text-decoration: none; <?= $hasActiveFilters ? '' : 'display: none;' ?>">Filter zurücksetzen</a>
        </div>

        <!-- Toggle for Advanced Attribute Filters -->
        <details <?= $hasActiveFilters ? 'open' : '' ?> style="margin-top: 1rem; border-top: 1px solid #eee; padding-top: 1rem;">
            <summary style="font-weight: bold; color: var(--primary-color); cursor: pointer; user-select: none;">
                ⚙️ Erweiterte Attribute-Filter (Pferd, Züchter, Deckstation, Abstammung)
            </summary>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1.2rem;">
                
                <!-- Pferdename -->
                <div class="form-group">
                    <label style="font-size: 0.85rem; font-weight: bold;">Pferdename</label>
                    <input type="text" name="q_name" class="form-control filter-field" style="padding: 0.5rem;" placeholder="z. B. Storm" value="<?= htmlspecialchars($filters['q_name'] ?? '') ?>">
                </div>

                <!-- UELN (DE & Ausland) -->
                <div class="form-group">
                    <label style="font-size: 0.85rem; font-weight: bold;">UELN / Lebensnummer (DE & Ausland)</label>
                    <input type="text" name="q_ueln" class="form-control filter-field" style="padding: 0.5rem;" placeholder="z. B. DE431... oder NLD003..." value="<?= htmlspecialchars($filters['q_ueln'] ?? '') ?>">
                </div>

                <!-- Züchter -->
                <div class="form-group">
                    <label style="font-size: 0.85rem; font-weight: bold;">Züchter</label>
                    <input type="text" name="q_breeder" class="form-control filter-field" style="padding: 0.5rem;" placeholder="Name des Züchters" value="<?= htmlspecialchars($filters['q_breeder'] ?? '') ?>" list="breeder_list">
                    <datalist id="breeder_list">
                        <?php foreach ($persons as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <!-- Besitzer -->
                <div class="form-group">
                    <label style="font-size: 0.85rem; font-weight: bold;">Besitzer</label>
                    <input type="text" name="q_owner" class="form-control filter-field" style="padding: 0.5rem;" placeholder="Name des Besitzers" value="<?= htmlspecialchars($filters['q_owner'] ?? '') ?>" list="owner_list">
                    <datalist id="owner_list">
                        <?php foreach ($persons as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <!-- Deckstation / Gestüt -->
                <div class="form-group">
                    <label style="font-size: 0.85rem; font-weight: bold;">Deckstation / Gestüt</label>
                    <input type="text" name="q_station" class="form-control filter-field" style="padding: 0.5rem;" placeholder="Name der Deckstation" value="<?= htmlspecialchars($filters['q_station'] ?? '') ?>" list="station_list">
                    <datalist id="station_list">
                        <?php foreach ($stations as $st): ?>
                            <option value="<?= htmlspecialchars($st) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <!-- Vater (Sire) -->
                <div class="form-group">
                    <label style="font-size: 0.85rem; font-weight: bold;">♂ Vater (Sire)</label>
                    <input type="text" name="q_sire" class="form-control filter-field" style="padding: 0.5rem;" placeholder="Name des Vaters" value="<?= htmlspecialchars($filters['q_sire'] ?? '') ?>">
                </div>

                <!-- Mutter (Dam) -->
                <div class="form-group">
                    <label style="font-size: 0.85rem; font-weight: bold;">♀ Mutter (Dam)</label>
                    <input type="text" name="q_dam" class="form-control filter-field" style="padding: 0.5rem;" placeholder="Name der Mutter" value="<?= htmlspecialchars($filters['q_dam'] ?? '') ?>">
                </div>

                <!-- Farbe -->
                <div class="form-group">
                    <label style="font-size: 0.85rem; font-weight: bold;">Farbe</label>
                    <select name="q_color" class="form-control filter-field" style="padding: 0.5rem;">
                        <option value="">-- Alle Farben --</option>
                        <?php foreach ($colors as $col): ?>
                            <option value="<?= htmlspecialchars($col) ?>" <?= ($filters['q_color'] ?? '') === $col ? 'selected' : '' ?>>
                                <?= htmlspecialchars($col) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Geburtsjahr Von -->
                <div class="form-group">
                    <label style="font-size: 0.85rem; font-weight: bold;">Geburtsjahr Von</label>
                    <input type="number" name="birth_year_from" class="form-control filter-field" style="padding: 0.5rem;" placeholder="z. B. 2010" value="<?= htmlspecialchars($filters['birth_year_from'] ?? '') ?>">
                </div>

                <!-- Geburtsjahr Bis -->
                <div class="form-group">
                    <label style="font-size: 0.85rem; font-weight: bold;">Geburtsjahr Bis</label>
                    <input type="number" name="birth_year_to" class="form-control filter-field" style="padding: 0.5rem;" placeholder="z. B. 2024" value="<?= htmlspecialchars($filters['birth_year_to'] ?? '') ?>">
                </div>

                <!-- Status -->
                <div class="form-group">
                    <label style="font-size: 0.85rem; font-weight: bold;">Status</label>
                    <select name="q_status" class="form-control filter-field" style="padding: 0.5rem;">
                        <option value="">-- Alle Status --</option>
                        <option value="active" <?= ($filters['q_status'] ?? '') === 'active' ? 'selected' : '' ?>>Aktiv im Zuchtbuch</option>
                        <option value="inactive" <?= ($filters['q_status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inaktiv</option>
                        <option value="deceased" <?= ($filters['q_status'] ?? '') === 'deceased' ? 'selected' : '' ?>>Verstorben</option>
                    </select>
                </div>

            </div>

            <div style="margin-top: 1rem; text-align: right;">
                <button type="submit" class="btn" style="padding: 0.6rem 1.5rem;">Filter anwenden</button>
            </div>
        </details>
    </form>

    <!-- Async Horse Card Grid -->
    <div id="catalog-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem; transition: opacity 0.15s ease-in-out;">
        <?php include __DIR__ . '/public_catalog_cards.php'; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('catalog-filter-form');
    const grid = document.getElementById('catalog-grid');
    const badge = document.getElementById('hit-count-badge');
    const spinner = document.getElementById('loading-spinner');
    const resetBtn = document.getElementById('btn-reset-filters');

    let debounceTimer = null;

    function performAsyncFetch() {
        const formData = new FormData(form);
        const params = new URLSearchParams(formData);
        
        // Remove empty values
        for (const [key, value] of Array.from(params.entries())) {
            if (!value || value.trim() === '') {
                params.delete(key);
            }
        }

        const queryString = params.toString();
        const fetchUrl = '/katalog?' + queryString;

        // Show spinner and dim grid slightly
        if (spinner) spinner.style.display = 'inline-block';
        if (grid) grid.style.opacity = '0.5';

        fetch(fetchUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                grid.innerHTML = data.cards_html;
                badge.textContent = data.count_text;

                // Update URL history without page reload
                const newUrl = window.location.pathname + (queryString ? '?' + queryString : '');
                window.history.pushState({ path: newUrl }, '', newUrl);

                // Show/hide reset button
                if (resetBtn) {
                    resetBtn.style.display = queryString ? 'inline-block' : 'none';
                }
            }
        })
        .catch(err => console.error('Async Filter Error:', err))
        .finally(() => {
            if (spinner) spinner.style.display = 'none';
            if (grid) grid.style.opacity = '1';
        });
    }

    // Debounced listener for typing
    form.querySelectorAll('input[type="text"], input[type="number"]').forEach(input => {
        input.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(performAsyncFetch, 250);
        });
    });

    // Immediate listener for select dropdowns
    form.querySelectorAll('select').forEach(select => {
        select.addEventListener('change', performAsyncFetch);
    });

    // Prevent full form submit reload
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        performAsyncFetch();
    });
});
</script>
