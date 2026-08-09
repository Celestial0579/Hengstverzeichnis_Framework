<?php
// src/Views/public_horse_detail.php
/**
 * @var array $horse
 * @var array|null $pedigree
 */

function renderPedigreeNode(?array $node, int $targetLevel = 1): void {
    if (!$node) {
        echo '<div class="pedigree-box empty">' . htmlspecialchars(App\I18n\Translator::t('field.unknown')) . '</div>';
        return;
    }

    $name = htmlspecialchars($node['name']);
    $year = !empty($node['birth_year']) ? '(' . htmlspecialchars((string)$node['birth_year']) . ')' : '';
    $ueln = !empty($node['ueln']) ? '[' . htmlspecialchars((string)$node['ueln']) . ']' : '';
    $isPlaceholder = !empty($node['is_placeholder']);

    if (!empty($node['id'])) {
        $viewProfileTitle = htmlspecialchars(App\I18n\Translator::t('horse.view_profile_title', ['name' => $node['name']]));
        echo '<a href="/horse?id=' . $node['id'] . '" class="pedigree-box pedigree-link-box gen-level-' . $node['depth'] . '" title="' . $viewProfileTitle . '">';
        echo '<strong style="color: var(--primary-fg); font-size: 0.95rem;">' . $name . '</strong>';
        if ($year) echo '<div class="pedigree-year">' . $year . '</div>';
        if ($ueln) echo '<div class="pedigree-year" style="font-size: 0.75rem;">' . $ueln . '</div>';
        echo '</a>';
    } else {
        echo '<div class="pedigree-box gen-level-' . $node['depth'] . ($isPlaceholder ? ' placeholder' : '') . '">';
        echo '<strong style="color: var(--text-muted); font-size: 0.95rem;">' . $name . '</strong>';
        if ($year) echo '<div class="pedigree-year">' . $year . '</div>';
        if ($ueln) echo '<div class="pedigree-year" style="font-size: 0.75rem;">' . $ueln . '</div>';
        echo '</div>';
    }
}

/**
 * Erzeugt alle Abstammungspfade einer bestimmten Tiefe in fester
 * Sire-vor-Dam-Reihenfolge, z. B. depth=2: [sire,sire], [sire,dam],
 * [dam,sire], [dam,dam].
 * @return array<int, array<int, 'sire'|'dam'>>
 */
function pedigreePaths(int $depth): array {
    if ($depth <= 0) {
        return [[]];
    }
    $paths = [];
    foreach (['sire', 'dam'] as $branch) {
        foreach (pedigreePaths($depth - 1) as $rest) {
            $paths[] = array_merge([$branch], $rest);
        }
    }
    return $paths;
}

function pedigreeAncestor(?array $pedigree, array $path): ?array {
    $node = $pedigree;
    foreach ($path as $branch) {
        $node = $node[$branch] ?? null;
        if ($node === null) {
            return null;
        }
    }
    return $node;
}

/**
 * Rendert eine unbeschriftete Generationsspalte (ab den Urgroßeltern):
 * je eine Box-Gruppe für den väterlichen und mütterlichen Zweig.
 */
function renderPedigreeGeneration(?array $pedigree, int $depth): void {
    foreach (['sire', 'dam'] as $rootBranch) {
        echo '<div class="pedigree-group">';
        foreach (pedigreePaths($depth - 1) as $subPath) {
            renderPedigreeNode(pedigreeAncestor($pedigree, array_merge([$rootBranch], $subPath)));
        }
        echo '</div>';
    }
}
?>
<div style="margin-bottom: 1rem;">
    <a href="/katalog" style="color: var(--primary-fg); text-decoration: none; font-weight: 500;"><?= htmlspecialchars(App\I18n\Translator::t('common.back_to_catalog')) ?></a>
</div>

<!-- Main Details -->
<div class="card" style="margin-bottom: 2rem;">
    <h1 style="border-bottom: 2px solid var(--primary-fg); padding-bottom: 0.5rem; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between;">
        <span><?= htmlspecialchars((string)$horse['name']) ?></span>
        <span style="font-size: 1rem; font-weight: normal; padding: 0.3rem 0.8rem; border-radius: 20px; background-color: <?= $horse['status'] === 'active' ? '#d4edda' : '#f8d7da' ?>; color: <?= $horse['status'] === 'active' ? '#155724' : '#721c24' ?>;">
            <?= htmlspecialchars(App\I18n\Translator::t($horse['status'] === 'active' ? 'status.active' : ($horse['status'] === 'inactive' ? 'status.inactive' : 'status.deceased'))) ?>
        </span>
    </h1>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
        <?php if (!empty($horse['image_url'])): ?>
            <div style="text-align: center;">
                <img src="<?= htmlspecialchars($horse['image_url']) ?>" alt="<?= htmlspecialchars((string)$horse['name']) ?>" style="width: 100%; max-height: 350px; object-fit: cover; border-radius: var(--border-radius); border: 1px solid var(--border-color); box-shadow: var(--shadow-md);">
            </div>
        <?php endif; ?>

        <div>
            <h3 style="font-size: 1.2rem; margin-bottom: 1rem; color: var(--text-muted);"><?= htmlspecialchars(App\I18n\Translator::t('horse.master_data')) ?></h3>
            <table style="width: 100%; border-collapse: collapse;">
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);"><?= htmlspecialchars(App\I18n\Translator::t('field.ueln_full')) ?></th>
                    <td style="padding: 0.6rem 0; font-weight: 500;"><?= htmlspecialchars((string)($horse['ueln'] ?: App\I18n\Translator::t('field.unknown'))) ?></td>
                </tr>
                <?php if (!empty($horse['foreign_ueln'])): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);"><?= htmlspecialchars(App\I18n\Translator::t('field.foreign_ueln_label')) ?></th>
                        <td style="padding: 0.6rem 0; font-weight: 500; color: var(--primary-fg);"><?= htmlspecialchars((string)$horse['foreign_ueln']) ?></td>
                    </tr>
                <?php endif; ?>
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);"><?= htmlspecialchars(App\I18n\Translator::t('field.birth_year')) ?></th>
                    <td style="padding: 0.6rem 0; font-weight: 500;"><?= htmlspecialchars((string)($horse['birth_year'] ?: App\I18n\Translator::t('field.unknown'))) ?></td>
                </tr>
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);"><?= htmlspecialchars(App\I18n\Translator::t('field.color')) ?></th>
                    <td style="padding: 0.6rem 0; font-weight: 500;"><?= htmlspecialchars((string)($horse['color'] ?: App\I18n\Translator::t('field.unknown'))) ?></td>
                </tr>
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);"><?= htmlspecialchars(App\I18n\Translator::t('field.sex')) ?></th>
                    <td style="padding: 0.6rem 0; font-weight: 500;"><?= htmlspecialchars(!empty($horse['sex']) ? App\I18n\Translator::t('value.sex.' . $horse['sex']) : App\I18n\Translator::t('field.unknown')) ?></td>
                </tr>
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);"><?= htmlspecialchars(App\I18n\Translator::t('field.breed')) ?></th>
                    <td style="padding: 0.6rem 0; font-weight: 500;"><?= htmlspecialchars((string)(($horse['breed'] ?? '') ?: App\I18n\Translator::t('field.unknown'))) ?></td>
                </tr>
                <?php if (!empty($horse['station_name']) || !empty($horse['breeding_station'])): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted); vertical-align: top;"><?= htmlspecialchars(App\I18n\Translator::t('field.breeding_station')) ?></th>
                        <td style="padding: 0.6rem 0; font-weight: 500; color: var(--primary-fg);">
                            <strong>
                                <?php if (!empty($horse['station_name']) && !empty($horse['breeding_station_id'])): ?>
                                    <a href="/station?id=<?= $horse['breeding_station_id'] ?>" style="color: var(--primary-fg); text-decoration: underline;"><?= htmlspecialchars($horse['station_name']) ?></a>
                                <?php else: ?>
                                    <?= htmlspecialchars($horse['station_name'] ?: $horse['breeding_station']) ?>
                                <?php endif; ?>
                            </strong>
                            <?php if (!empty($horse['breeding_station']) && !empty($horse['station_name']) && $horse['breeding_station'] !== $horse['station_name']): ?>
                                <br><small style="color: var(--text-muted); font-weight: normal;"><?= htmlspecialchars($horse['breeding_station']) ?></small>
                            <?php endif; ?>
                            <?php if (!empty($horse['station_contact'])): ?>
                                <br><span style="font-size: 0.85rem; color: var(--text-muted); font-weight: normal;">👤 <?= htmlspecialchars(App\I18n\Translator::t('field.contact_person')) ?>: <?= htmlspecialchars($horse['station_contact']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($horse['station_address'])): ?>
                                <br><span style="font-size: 0.85rem; color: var(--text-muted); font-weight: normal;">📍 <?= nl2br(htmlspecialchars($horse['station_address'])) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($horse['station_phone'])): ?>
                                <br><span style="font-size: 0.85rem; color: var(--text-muted); font-weight: normal;">📞 <?= htmlspecialchars($horse['station_phone']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($horse['station_email'])): ?>
                                <br><span style="font-size: 0.85rem; color: var(--text-muted); font-weight: normal;">✉️ <a href="mailto:<?= htmlspecialchars($horse['station_email']) ?>"><?= htmlspecialchars($horse['station_email']) ?></a></span>
                            <?php endif; ?>
                            <?php if (!empty($horse['station_website'])): ?>
                                <br><span style="font-size: 0.85rem; font-weight: normal;">🌐 <a href="<?= htmlspecialchars($horse['station_website']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars(App\I18n\Translator::t('field.visit_website')) ?></a></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </table>

            <!-- Züchter, Besitzer & Deckstationenverlauf -->
            <div style="margin-top: 1.5rem;">
                <h4 style="font-size: 1.1rem; color: var(--primary-fg); margin-bottom: 0.8rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.3rem;">
                    <?= htmlspecialchars(App\I18n\Translator::t('horse.history_heading')) ?>
                </h4>

                <?php if (empty($horsePersons)): ?>
                    <p style="color: var(--text-subtle); font-size: 0.9rem;"><?= htmlspecialchars(App\I18n\Translator::t('horse.no_persons')) ?></p>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 0.6rem;">
                        <?php
                        $roleLabels = [
                            'breeder' => [App\I18n\Translator::t('field.breeder'), '#6f42c1'],
                            'owner' => [App\I18n\Translator::t('field.owner'), '#007bff'],
                            'keeper' => [App\I18n\Translator::t('field.keeper'), '#fd7e14']
                        ];
                        foreach ($horsePersons as $hp):
                            $roleMeta = $roleLabels[$hp['role']] ?? [App\I18n\Translator::t('field.owner'), '#6c757d'];
                            $yearsText = '';
                            if ($hp['role'] !== 'breeder' && ($hp['from_year'] || $hp['until_year'])) {
                                $yearsText = ' (' . ($hp['from_year'] ?: '?') . ' - ' . ($hp['until_year'] ?: App\I18n\Translator::t('horse.years_until_today')) . ')';
                            }
                            $stationDisplayName = $hp['station_name'] ?? $hp['breeding_station_text'] ?? '';
                        ?>
                            <div style="background: var(--surface-muted); border: 1px solid #e0e0e0; border-radius: 6px; padding: 0.7rem 0.9rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.3rem;">
                                    <div>
                                        <?php if (!empty($hp['person_name'])): ?>
                                            <strong><?= htmlspecialchars($hp['person_name']) ?></strong>
                                        <?php else: ?>
                                            <strong>
                                                <?php if (!empty($hp['station_id'])): ?>
                                                    <a href="/station?id=<?= $hp['station_id'] ?>" style="color: var(--primary-fg); text-decoration: underline;">
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
                                    <div style="font-size: 0.85rem; color: var(--primary-fg); margin-top: 0.4rem; display: flex; align-items: center; gap: 0.4rem; font-weight: 500;">
                                        <span><?= htmlspecialchars(App\I18n\Translator::t('horse.breeding_station_colon')) ?></span>
                                        <?php if (!empty($hp['station_id'])): ?>
                                            <a href="/station?id=<?= $hp['station_id'] ?>" style="color: var(--primary-fg); text-decoration: underline;">
                                                <?= htmlspecialchars($stationDisplayName) ?>
                                            </a>
                                        <?php else: ?>
                                            <span><?= htmlspecialchars($stationDisplayName) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($hp['contact_info'])): ?>
                                    <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.3rem;">
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
            <h3 style="font-size: 1.2rem; margin-bottom: 1rem; color: var(--text-muted);"><?= htmlspecialchars(App\I18n\Translator::t('horse.description_heading')) ?></h3>
            <div style="background: var(--surface-muted); padding: 1rem; border-radius: var(--border-radius); border: 1px solid var(--border-color); min-height: 120px;">
                <?php if (!empty($horse['description'])): ?>
                    <?= App\Helper\Markdown::parse($horse['description']) ?>
                <?php else: ?>
                    <p style="color: var(--text-subtle);"><?= htmlspecialchars(App\I18n\Translator::t('horse.no_description')) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Interaktiver Stammbaum (Pedigree Tree) -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; border-bottom: 2px solid var(--border-color); padding-bottom: 1rem;">
        <div>
            <h2 style="margin: 0; color: var(--primary-fg);"><?= htmlspecialchars(App\I18n\Translator::t('horse.pedigree_heading')) ?></h2>
            <p style="margin: 0; color: var(--text-muted); font-size: 0.9rem;"><?= htmlspecialchars(App\I18n\Translator::t('horse.pedigree_subheading')) ?></p>
        </div>

        <!-- Zoom & Level Controls -->
        <div style="display: flex; gap: 0.5rem; align-items: center; background: var(--surface-muted); padding: 0.5rem; border-radius: 8px;">
            <label for="genSelect" style="font-size: 0.9rem; font-weight: bold; margin-right: 0.3rem;"><?= htmlspecialchars(App\I18n\Translator::t('horse.generations_label')) ?></label>
            <select id="genSelect" class="form-control" style="width: auto; padding: 0.3rem 0.5rem;" onchange="setGenerations(this.value)">
                <option value="2"><?= htmlspecialchars(App\I18n\Translator::t('horse.gen_2')) ?></option>
                <option value="3" selected><?= htmlspecialchars(App\I18n\Translator::t('horse.gen_3')) ?></option>
                <option value="4"><?= htmlspecialchars(App\I18n\Translator::t('horse.gen_4')) ?></option>
                <option value="5"><?= htmlspecialchars(App\I18n\Translator::t('horse.gen_5')) ?></option>
                <option value="6"><?= htmlspecialchars(App\I18n\Translator::t('horse.gen_6')) ?></option>
            </select>

            <div style="width: 1px; height: 24px; background: var(--border-color); margin: 0 0.5rem;"></div>

            <button type="button" class="btn btn-secondary" onclick="zoomPedigree(0.1)" title="<?= htmlspecialchars(App\I18n\Translator::t('horse.zoom_in_title')) ?>" aria-label="<?= htmlspecialchars(App\I18n\Translator::t('horse.zoom_in_title')) ?>" style="padding: 0.3rem 0.7rem;">🔍 +</button>
            <button type="button" class="btn btn-secondary" onclick="zoomPedigree(-0.1)" title="<?= htmlspecialchars(App\I18n\Translator::t('horse.zoom_out_title')) ?>" aria-label="<?= htmlspecialchars(App\I18n\Translator::t('horse.zoom_out_title')) ?>" style="padding: 0.3rem 0.7rem;">🔍 -</button>
            <button type="button" class="btn btn-secondary" onclick="resetZoom()" title="<?= htmlspecialchars(App\I18n\Translator::t('horse.zoom_reset_title')) ?>" style="padding: 0.3rem 0.7rem;"><?= htmlspecialchars(App\I18n\Translator::t('horse.zoom_reset_label')) ?></button>
            <span id="zoomLevelText" aria-live="polite" style="font-size: 0.85rem; color: var(--text-muted); min-width: 45px; text-align: center;">100%</span>
        </div>
    </div>

    <!-- Pedigree Container with Zoom Wrapper -->
    <div class="pedigree-tree-container" style="overflow-x: auto; padding: 1rem 0; width: 100%; text-align: center; -webkit-overflow-scrolling: touch;">
        <div id="pedigreeCanvas" style="transform-origin: top center; transition: transform 0.2s ease, max-height 0.3s ease; display: inline-block; min-width: 800px; text-align: left;">
            
            <div class="pedigree-grid gen-view-3" id="pedigreeTree">
                
                <!-- Gen 1: Proband (Centrally aligned between Vater and Mutter) -->
                <div class="pedigree-col gen-1" style="display: flex; flex-direction: column; justify-content: center;">
                    <div class="pedigree-group" style="background: transparent; border-left: none; padding: 0;">
                        <div class="pedigree-label" style="text-align: center;"><?= htmlspecialchars(App\I18n\Translator::t('horse.proband_label')) ?></div>
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
                        <div class="pedigree-label"><?= htmlspecialchars(App\I18n\Translator::t('horse.sire_label')) ?></div>
                        <?php renderPedigreeNode($pedigree['sire'] ?? null); ?>
                    </div>
                    <div class="pedigree-group">
                        <div class="pedigree-label"><?= htmlspecialchars(App\I18n\Translator::t('horse.dam_label')) ?></div>
                        <?php renderPedigreeNode($pedigree['dam'] ?? null); ?>
                    </div>
                </div>

                <!-- Gen 3: Großeltern -->
                <div class="pedigree-col gen-3">
                    <div class="pedigree-group">
                        <div class="pedigree-label"><?= htmlspecialchars(App\I18n\Translator::t('horse.grandsire_paternal')) ?></div>
                        <?php renderPedigreeNode($pedigree['sire']['sire'] ?? null); ?>
                        <div class="pedigree-label" style="margin-top: 0.5rem;"><?= htmlspecialchars(App\I18n\Translator::t('horse.granddam_paternal')) ?></div>
                        <?php renderPedigreeNode($pedigree['sire']['dam'] ?? null); ?>
                    </div>
                    <div class="pedigree-group">
                        <div class="pedigree-label"><?= htmlspecialchars(App\I18n\Translator::t('horse.grandsire_maternal')) ?></div>
                        <?php renderPedigreeNode($pedigree['dam']['sire'] ?? null); ?>
                        <div class="pedigree-label" style="margin-top: 0.5rem;"><?= htmlspecialchars(App\I18n\Translator::t('horse.granddam_maternal')) ?></div>
                        <?php renderPedigreeNode($pedigree['dam']['dam'] ?? null); ?>
                    </div>
                </div>

                <!-- Gen 4: Urgroßeltern -->
                <div class="pedigree-col gen-4">
                    <?php renderPedigreeGeneration($pedigree, 3); ?>
                </div>

                <!-- Gen 5: Ururgroßeltern -->
                <div class="pedigree-col gen-5">
                    <?php renderPedigreeGeneration($pedigree, 4); ?>
                </div>

                <!-- Gen 6: Urururgroßeltern -->
                <div class="pedigree-col gen-6">
                    <?php renderPedigreeGeneration($pedigree, 5); ?>
                </div>

            </div>

        </div>
    </div>
</div>

<?php if (!empty($pluginDetailSections)): ?>
    <!-- Plugin-Erweiterungspunkt 'horse.detail_sections' (#56) -->
    <?php foreach ($pluginDetailSections as $section): ?>
        <div class="card" style="margin-bottom: 2rem;">
            <?= $section ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

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
    border-left: 2px solid var(--primary-fg);
    background: var(--surface-muted);
    border-radius: 4px;
    margin: 0.25rem 0;
}

.pedigree-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    /* Barrierefreiheit (#51): #888 auf Weiß erreicht nur ~3.5:1 Kontrast,
       WCAG AA verlangt für diese Textgröße (12px, auch fett unter der
       "große Schrift"-Schwelle) mindestens 4.5:1 - #666 erreicht ~5.7:1. */
    color: var(--text-muted);
    font-weight: bold;
    margin-bottom: 0.2rem;
}

.pedigree-box {
    display: block;
    text-decoration: none;
    color: inherit;
    background: var(--card-bg);
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
    border-color: var(--primary-fg);
    background-color: var(--surface-muted);
    box-shadow: 0 4px 10px rgba(0,0,0,0.12);
    transform: translateY(-2px);
}

.pedigree-box.main-horse {
    background: var(--primary-color);
    color: #ffffff;
    border-color: var(--primary-fg);
}

.pedigree-box.empty {
    background: var(--surface-muted);
    color: var(--text-subtle);
    border-style: dashed;
    font-style: italic;
    font-size: 0.85rem;
}

.pedigree-link {
    color: var(--primary-fg);
    text-decoration: none;
}

.pedigree-box.main-horse .pedigree-link {
    color: #ffffff;
}

.pedigree-year {
    font-size: 0.8rem;
    color: var(--text-subtle);
}

.pedigree-box.main-horse .pedigree-year {
    color: #ddd;
}

/* Generation Hiding Logic */
.gen-view-2 .gen-3,
.gen-view-2 .gen-4,
.gen-view-2 .gen-5,
.gen-view-2 .gen-6 {
    display: none !important;
}

.gen-view-3 .gen-4,
.gen-view-3 .gen-5,
.gen-view-3 .gen-6 {
    display: none !important;
}

.gen-view-4 .gen-5,
.gen-view-4 .gen-6 {
    display: none !important;
}

.gen-view-5 .gen-6 {
    display: none !important;
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
