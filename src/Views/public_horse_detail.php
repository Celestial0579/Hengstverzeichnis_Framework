<?php
// src/Views/public_horse_detail.php
/**
 * @var array $horse
 * @var array|null $pedigree
 * @var array|null $pedigreeTree
 */

$pedigree = $pedigree ?? $pedigreeTree ?? null;

function renderPedigreeNode(?array $node, int $targetLevel = 1): void {
    if (!$node) {
        echo '<div class="pedigree-box empty">Unbekannt</div>';
        return;
    }

    $name = htmlspecialchars($node['name']);
    $year = !empty($node['birth_year']) ? '(' . htmlspecialchars((string)$node['birth_year']) . ')' : '';
    $ueln = !empty($node['ueln']) ? '[' . htmlspecialchars((string)$node['ueln']) . ']' : '';
    $isPlaceholder = !empty($node['is_placeholder']);

    if (!empty($node['id'])) {
        echo '<a href="/hengst?id=' . $node['id'] . '" class="pedigree-box pedigree-link-box gen-level-' . $node['depth'] . '" title="' . $name . ' Profile ansehen">';
        echo '<strong style="color: var(--primary-color); font-size: 0.95rem;">' . $name . '</strong>';
        if ($year) echo '<div class="pedigree-year">' . $year . '</div>';
        if ($ueln) echo '<div class="pedigree-year" style="font-size: 0.75rem;">' . $ueln . '</div>';
        echo '</a>';
    } else {
        echo '<div class="pedigree-box gen-level-' . $node['depth'] . ($isPlaceholder ? ' placeholder' : '') . '">';
        echo '<strong style="color: #666; font-size: 0.95rem;">' . $name . '</strong>';
        if ($year) echo '<div class="pedigree-year">' . $year . '</div>';
        if ($ueln) echo '<div class="pedigree-year" style="font-size: 0.75rem;">' . $ueln . '</div>';
        echo '</div>';
    }
}
?>
<div style="margin-bottom: 1rem;">
    <a href="/katalog" style="color: var(--primary-color); text-decoration: none; font-weight: 500;">&larr; Zurück zum Katalog</a>
</div>

<!-- Main Details -->
<div class="card" style="margin-bottom: 2rem;">
    <h1 style="border-bottom: 2px solid var(--primary-color); padding-bottom: 0.5rem; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between;">
        <span><?= htmlspecialchars((string)$horse['name']) ?></span>
        <span style="font-size: 1rem; font-weight: normal; padding: 0.3rem 0.8rem; border-radius: 20px; background-color: <?= $horse['status'] === 'active' ? '#d4edda' : '#f8d7da' ?>; color: <?= $horse['status'] === 'active' ? '#155724' : '#721c24' ?>;">
            <?= $horse['status'] === 'active' ? 'Gekört / Aktiv' : ($horse['status'] === 'inactive' ? 'Inaktiv' : 'Verstorben') ?>
        </span>
    </h1>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
        <?php if (!empty($horse['image_url'])): ?>
            <div style="text-align: center;">
                <img src="<?= htmlspecialchars($horse['image_url']) ?>" alt="<?= htmlspecialchars((string)$horse['name']) ?>" style="width: 100%; max-height: 350px; object-fit: cover; border-radius: var(--border-radius); border: 1px solid #ddd; box-shadow: var(--shadow-md);">
            </div>
        <?php endif; ?>

        <div>
            <h3 style="font-size: 1.2rem; margin-bottom: 1rem; color: #555;">Stammdaten</h3>
            <table style="width: 100%; border-collapse: collapse;">
                <tr style="border-bottom: 1px solid #eee;">
                    <th style="text-align: left; padding: 0.6rem 0; color: #666;">UELN (Haupt-Lebensnummer)</th>
                    <td style="padding: 0.6rem 0; font-weight: 500;"><?= htmlspecialchars((string)($horse['ueln'] ?: 'Unbekannt')) ?></td>
                </tr>
                <?php if (!empty($horse['foreign_ueln'])): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <th style="text-align: left; padding: 0.6rem 0; color: #666;">Lebensnummer Ursprungsland</th>
                        <td style="padding: 0.6rem 0; font-weight: 500; color: var(--primary-color);"><?= htmlspecialchars((string)$horse['foreign_ueln']) ?></td>
                    </tr>
                <?php endif; ?>
                <tr style="border-bottom: 1px solid #eee;">
                    <th style="text-align: left; padding: 0.6rem 0; color: #666;">Geburtsjahr</th>
                    <td style="padding: 0.6rem 0; font-weight: 500;"><?= htmlspecialchars((string)($horse['birth_year'] ?: 'Unbekannt')) ?></td>
                </tr>
                <tr style="border-bottom: 1px solid #eee;">
                    <th style="text-align: left; padding: 0.6rem 0; color: #666;">Farbe</th>
                    <td style="padding: 0.6rem 0; font-weight: 500;"><?= htmlspecialchars((string)($horse['color'] ?: 'Unbekannt')) ?></td>
                </tr>
                <?php if (!empty($horse['station_name']) || !empty($horse['breeding_station'])): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <th style="text-align: left; padding: 0.6rem 0; color: #666; vertical-align: top;">Deckstation / Gestüt</th>
                        <td style="padding: 0.6rem 0; font-weight: 500; color: var(--primary-color);">
                            <strong>
                                <?php if (!empty($horse['station_name']) && !empty($horse['breeding_station_id'])): ?>
                                    <a href="/station?id=<?= $horse['breeding_station_id'] ?>" style="color: var(--primary-color); text-decoration: underline;"><?= htmlspecialchars($horse['station_name']) ?></a>
                                <?php else: ?>
                                    <?= htmlspecialchars($horse['station_name'] ?: $horse['breeding_station']) ?>
                                <?php endif; ?>
                            </strong>
                            <?php if (!empty($horse['breeding_station']) && !empty($horse['station_name']) && $horse['breeding_station'] !== $horse['station_name']): ?>
                                <br><small style="color: #666; font-weight: normal;"><?= htmlspecialchars($horse['breeding_station']) ?></small>
                            <?php endif; ?>
                            <?php if (!empty($horse['station_contact'])): ?>
                                <br><span style="font-size: 0.85rem; color: #555; font-weight: normal;">👤 Ansprechpartner: <?= htmlspecialchars($horse['station_contact']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($horse['station_address'])): ?>
                                <br><span style="font-size: 0.85rem; color: #555; font-weight: normal;">📍 <?= nl2br(htmlspecialchars($horse['station_address'])) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($horse['station_phone'])): ?>
                                <br><span style="font-size: 0.85rem; color: #555; font-weight: normal;">📞 <?= htmlspecialchars($horse['station_phone']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($horse['station_email'])): ?>
                                <br><span style="font-size: 0.85rem; color: #555; font-weight: normal;">✉️ <a href="mailto:<?= htmlspecialchars($horse['station_email']) ?>"><?= htmlspecialchars($horse['station_email']) ?></a></span>
                            <?php endif; ?>
                            <?php if (!empty($horse['station_website'])): ?>
                                <br><span style="font-size: 0.85rem; font-weight: normal;">🌐 <a href="<?= htmlspecialchars($horse['station_website']) ?>" target="_blank" rel="noopener">Website besuchen</a></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </table>

            <!-- Züchter, Besitzer & Deckstationenverlauf -->
            <div style="margin-top: 1.5rem;">
                <h4 style="font-size: 1.1rem; color: var(--primary-color); margin-bottom: 0.8rem; border-bottom: 1px solid #eee; padding-bottom: 0.3rem;">
                    👤 Züchter-, Besitzer- & Deckstationenverlauf
                </h4>

                <?php if (empty($horsePersons)): ?>
                    <p style="color: #777; font-size: 0.9rem;">Keine Züchter- oder Besitzerangaben vorhanden.</p>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 0.6rem;">
                        <?php 
                        $roleLabels = [
                            'breeder' => ['Züchter', '#6f42c1'],
                            'owner' => ['Besitzer', '#007bff'],
                            'keeper' => ['Halter / Deckstation', '#fd7e14']
                        ];
                        foreach ($horsePersons as $hp): 
                            $roleMeta = $roleLabels[$hp['role']] ?? ['Besitzer', '#6c757d'];
                            $yearsText = '';
                            if ($hp['role'] !== 'breeder' && ($hp['from_year'] || $hp['until_year'])) {
                                $yearsText = ' (' . ($hp['from_year'] ?: '?') . ' - ' . ($hp['until_year'] ?: 'heute') . ')';
                            }
                            $stationDisplayName = $hp['station_name'] ?? $hp['breeding_station_text'] ?? '';
                        ?>
                            <div style="background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 6px; padding: 0.7rem 0.9rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.3rem;">
                                    <div>
                                        <?php if (!empty($hp['person_name'])): ?>
                                            <strong><?= htmlspecialchars($hp['person_name']) ?></strong>
                                        <?php else: ?>
                                            <strong>
                                                <?php if (!empty($hp['station_id'])): ?>
                                                    <a href="/station?id=<?= $hp['station_id'] ?>" style="color: var(--primary-color); text-decoration: underline;">
                                                        🏠 <?= htmlspecialchars($stationDisplayName) ?>
                                                    </a>
                                                <?php else: ?>
                                                    🏠 <?= htmlspecialchars($stationDisplayName) ?>
                                                <?php endif; ?>
                                            </strong>
                                        <?php endif; ?>
                                    </div>
                                    <span style="background: <?= $roleMeta[1] ?>; color: #fff; padding: 0.2rem 0.6rem; border-radius: 12px; font-size: 0.75rem; font-weight: bold;">
                                        <?= $roleMeta[0] ?><?= htmlspecialchars($yearsText) ?>
                                    </span>
                                </div>

                                <?php if (!empty($hp['person_name']) && !empty($stationDisplayName)): ?>
                                    <div style="font-size: 0.85rem; color: var(--primary-color); margin-top: 0.4rem; display: flex; align-items: center; gap: 0.4rem; font-weight: 500;">
                                        <span>🏠 Deckstation / Gestüt:</span>
                                        <?php if (!empty($hp['station_id'])): ?>
                                            <a href="/station?id=<?= $hp['station_id'] ?>" style="color: var(--primary-color); text-decoration: underline;">
                                                <?= htmlspecialchars($stationDisplayName) ?>
                                            </a>
                                        <?php else: ?>
                                            <span><?= htmlspecialchars($stationDisplayName) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($hp['contact_info'])): ?>
                                    <div style="font-size: 0.85rem; color: #555; margin-top: 0.3rem;">
                                        <?= nl2br(htmlspecialchars($hp['contact_info'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <h3 style="font-size: 1.2rem; margin-bottom: 1rem; color: #555;">Beschreibung & Zuchthinweise</h3>
            <div style="background: #f9f9f9; padding: 1rem; border-radius: var(--border-radius); border: 1px solid #eee; min-height: 120px;">
                <?php if (!empty($horse['description'])): ?>
                    <?= App\Helper\Markdown::parse($horse['description']) ?>
                <?php else: ?>
                    <p style="color: #777;">Keine Beschreibung vorhanden.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Interaktiver Stammbaum (Pedigree Tree) -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; border-bottom: 2px solid #eee; padding-bottom: 1rem;">
        <div>
            <h2 style="margin: 0; color: var(--primary-color);">🧬 Abstammungsnachweis (Stammbaum)</h2>
            <p style="margin: 0; color: #666; font-size: 0.9rem;">Interaktive Blutlinien-Ansicht</p>
        </div>

        <!-- Zoom & Level Controls -->
        <div style="display: flex; gap: 0.5rem; align-items: center; background: #f4f4f4; padding: 0.5rem; border-radius: 8px;">
            <label for="genSelect" style="font-size: 0.9rem; font-weight: bold; margin-right: 0.3rem;">Generationen:</label>
            <select id="genSelect" class="form-control" style="width: auto; padding: 0.3rem 0.5rem;" onchange="setGenerations(this.value)">
                <option value="2">2 Generationen (Eltern)</option>
                <option value="3" selected>3 Generationen (Großeltern)</option>
                <option value="4">4 Generationen (Urgroßeltern)</option>
            </select>

            <div style="width: 1px; height: 24px; background: #ccc; margin: 0 0.5rem;"></div>

            <button type="button" class="btn btn-secondary" onclick="zoomPedigree(0.1)" title="Vergrößern" style="padding: 0.3rem 0.7rem;">🔍 +</button>
            <button type="button" class="btn btn-secondary" onclick="zoomPedigree(-0.1)" title="Verkleinern" style="padding: 0.3rem 0.7rem;">🔍 -</button>
            <button type="button" class="btn btn-secondary" onclick="resetZoom()" title="Zoom zurücksetzen" style="padding: 0.3rem 0.7rem;">🔄 Reset</button>
            <span id="zoomLevelText" style="font-size: 0.85rem; color: #555; min-width: 45px; text-align: center;">100%</span>
        </div>
    </div>

    <!-- Pedigree Container with Zoom Wrapper -->
    <div class="pedigree-tree-container" style="overflow-x: auto; padding: 1rem 0; width: 100%; text-align: center; -webkit-overflow-scrolling: touch;">
        <div id="pedigreeCanvas" style="transform-origin: top center; transition: transform 0.2s ease, max-height 0.3s ease; display: inline-block; min-width: 800px; text-align: left;">
            
            <div class="pedigree-grid gen-view-3" id="pedigreeTree">
                
                <!-- Gen 1: Proband (Centrally aligned between Vater and Mutter) -->
                <div class="pedigree-col gen-1" style="display: flex; flex-direction: column; justify-content: center;">
                    <div class="pedigree-group" style="background: transparent; border-left: none; padding: 0;">
                        <div class="pedigree-label" style="text-align: center;">Proband (Ausgewähltes Pferd)</div>
                        <div class="pedigree-box main-horse" style="text-align: center; padding: 0.9rem 1rem;">
                            <strong style="font-size: 1.1rem;"><?= htmlspecialchars($horse['name']) ?></strong>
                            <div class="pedigree-year" style="color: rgba(255,255,255,0.85); margin-top: 0.2rem;"><?= $horse['birth_year'] ? '(' . htmlspecialchars((string)$horse['birth_year']) . ')' : '' ?></div>
                            <?php if (!empty($horse['ueln'])): ?>
                                <div style="font-size: 0.75rem; color: rgba(255,255,255,0.8); margin-top: 0.2rem;"><?= htmlspecialchars($horse['ueln']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Gen 2: Eltern (Vater & Mutter) -->
                <div class="pedigree-col gen-2">
                    <div class="pedigree-group">
                        <div class="pedigree-label">Vater (Sire)</div>
                        <?php renderPedigreeNode($pedigree['sire'] ?? null); ?>
                    </div>
                    <div class="pedigree-group">
                        <div class="pedigree-label">Mutter (Dam)</div>
                        <?php renderPedigreeNode($pedigree['dam'] ?? null); ?>
                    </div>
                </div>

                <!-- Gen 3: Großeltern -->
                <div class="pedigree-col gen-3">
                    <div class="pedigree-group">
                        <div class="pedigree-label">Großvater (Vater-Seite)</div>
                        <?php renderPedigreeNode($pedigree['sire']['sire'] ?? null); ?>
                        <div class="pedigree-label" style="margin-top: 0.5rem;">Großmutter (Vater-Seite)</div>
                        <?php renderPedigreeNode($pedigree['sire']['dam'] ?? null); ?>
                    </div>
                    <div class="pedigree-group">
                        <div class="pedigree-label">Großvater (Mutter-Seite)</div>
                        <?php renderPedigreeNode($pedigree['dam']['sire'] ?? null); ?>
                        <div class="pedigree-label" style="margin-top: 0.5rem;">Großmutter (Mutter-Seite)</div>
                        <?php renderPedigreeNode($pedigree['dam']['dam'] ?? null); ?>
                    </div>
                </div>

                <!-- Gen 4: Urgroßeltern -->
                <div class="pedigree-col gen-4">
                    <div class="pedigree-group">
                        <?php renderPedigreeNode($pedigree['sire']['sire']['sire'] ?? null); ?>
                        <?php renderPedigreeNode($pedigree['sire']['sire']['dam'] ?? null); ?>
                        <?php renderPedigreeNode($pedigree['sire']['dam']['sire'] ?? null); ?>
                        <?php renderPedigreeNode($pedigree['sire']['dam']['dam'] ?? null); ?>
                    </div>
                    <div class="pedigree-group">
                        <?php renderPedigreeNode($pedigree['dam']['sire']['sire'] ?? null); ?>
                        <?php renderPedigreeNode($pedigree['dam']['sire']['dam'] ?? null); ?>
                        <?php renderPedigreeNode($pedigree['dam']['dam']['sire'] ?? null); ?>
                        <?php renderPedigreeNode($pedigree['dam']['dam']['dam'] ?? null); ?>
                    </div>
                </div>

            </div>

        </div>
    </div>
</div>

<!-- Pedigree Styling & Interactivity -->
<style>
.pedigree-grid {
    display: flex;
    gap: 1rem;
    align-items: stretch;
}

.pedigree-col {
    display: flex;
    flex-direction: column;
    justify-content: space-around;
    flex: 1;
    min-width: 180px;
}

.pedigree-group {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.5rem;
    border-left: 2px solid var(--primary-color);
    background: #fafafa;
    border-radius: 4px;
    margin: 0.25rem 0;
}

.pedigree-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    color: #888;
    font-weight: bold;
    margin-bottom: 0.2rem;
}

.pedigree-box {
    display: block;
    text-decoration: none;
    color: inherit;
    background: #ffffff;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 0.6rem 0.8rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s, background-color 0.2s;
}

.pedigree-box.pedigree-link-box {
    cursor: pointer;
    touch-action: manipulation;
}

.pedigree-box.pedigree-link-box:hover,
.pedigree-box.pedigree-link-box:active {
    border-color: var(--primary-color);
    background-color: #f8fbfd;
    box-shadow: 0 4px 10px rgba(0,0,0,0.12);
    transform: translateY(-2px);
}

.pedigree-box.main-horse {
    background: var(--primary-color);
    color: #ffffff;
    border-color: var(--primary-color);
}

.pedigree-box.empty {
    background: #f0f0f0;
    color: #aaa;
    border-style: dashed;
    font-style: italic;
    font-size: 0.85rem;
}

.pedigree-link {
    color: var(--primary-color);
    text-decoration: none;
}

.pedigree-box.main-horse .pedigree-link {
    color: #ffffff;
}

.pedigree-year {
    font-size: 0.8rem;
    color: #777;
}

.pedigree-box.main-horse .pedigree-year {
    color: #ddd;
}

/* Generation Hiding Logic */
.gen-view-2 .gen-3,
.gen-view-2 .gen-4 {
    display: none !important;
}

.gen-view-3 .gen-4 {
    display: none !important;
}

.gen-view-4 .gen-4 {
    display: flex !important;
}
</style>

<script>
let currentZoom = 1.0;

function zoomPedigree(delta) {
    currentZoom = Math.min(Math.max(0.5, currentZoom + delta), 2.0);
    applyZoom();
}

function resetZoom() {
    currentZoom = 1.0;
    applyZoom();
}

function applyZoom() {
    const canvas = document.getElementById('pedigreeCanvas');
    const text = document.getElementById('zoomLevelText');
    canvas.style.transform = `scale(${currentZoom})`;
    text.innerText = `${Math.round(currentZoom * 100)}%`;
}

function setGenerations(levels) {
    const tree = document.getElementById('pedigreeTree');
    tree.className = `pedigree-grid gen-view-${levels}`;
}
</script>
