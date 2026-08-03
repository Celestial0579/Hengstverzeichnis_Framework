<?php
// src/Views/admin_matches.php
/**
 * @var array $unlinkedMatches
 * @var array $allHorses
 */
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <div>
            <h2>🔗 Blutlinien Zusammenführen & Match-Vorschläge</h2>
            <p style="color: #666; font-size: 0.95rem;">
                Werkzeug für Administratoren und Editoren zur manuellen und automatischen Verknüpfung unvollständiger Eltern-Einträge.
            </p>
        </div>
        <a href="/admin/horses" class="btn btn-secondary">Zurück zur Pferdeübersicht</a>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            Verknüpfung erfolgreich durchgeführt! Der Stammbaum wurde aktualisiert.
        </div>
    <?php endif; ?>

    <?php if (empty($unlinkedMatches)): ?>
        <div style="text-align: center; padding: 3rem 1rem; background: #fafafa; border-radius: 8px; border: 1px dashed #ccc;">
            <h3 style="color: #28a745; margin-bottom: 0.5rem;">🎉 Keine unvollständigen Eltern-Einträge gefunden!</h3>
            <p style="color: #666;">Alle eingetragenen Väter und Mütter sind bereits perfekt mit echten Pferdeprofilen verknüpft.</p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <?php foreach ($unlinkedMatches as $match): ?>
                <div class="card" style="border-left: 4px solid var(--primary-color); margin-bottom: 0; background: #fff;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; color: #777; font-weight: bold;">
                                Kind-Pferd:
                            </span>
                            <h3 style="margin: 0.2rem 0;">
                                <?= htmlspecialchars($match['child_name']) ?>
                            </h3>
                            <p style="margin: 0; color: #555;">
                                Unverknüpfter <strong><?= htmlspecialchars($match['parent_type_label']) ?></strong>: 
                                <span style="background: #fff3cd; padding: 0.2rem 0.5rem; border-radius: 4px; color: #856404; font-weight: bold;">
                                    <?= htmlspecialchars($match['placeholder_name'] ?: 'Kein Name') ?> 
                                    <?= $match['placeholder_ueln'] ? '[' . htmlspecialchars($match['placeholder_ueln']) . ']' : '' ?>
                                </span>
                            </p>
                        </div>
                    </div>

                    <!-- Match Proposals -->
                    <div style="margin-top: 1.2rem; border-top: 1px solid #eee; padding-top: 1rem;">
                        <h4 style="font-size: 0.95rem; color: #444; margin-bottom: 0.8rem;">Beste Wahrscheinlichkeits-Vorschläge aus der Datenbank:</h4>

                        <div style="display: flex; flex-direction: column; gap: 0.6rem;">
                            <?php foreach ($match['suggestions'] as $sug): ?>
                                <?php 
                                    $score = $sug['score'];
                                    $badgeColor = $score >= 90 ? '#28a745' : ($score >= 70 ? '#ffc107' : '#17a2b8');
                                    $textColor = $score >= 70 && $score < 90 ? '#212529' : '#ffffff';
                                ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; background: #f8f9fa; padding: 0.6rem 1rem; border-radius: 6px; border: 1px solid #e9ecef;">
                                    <div style="display: flex; align-items: center; gap: 1rem;">
                                        <span style="background: <?= $badgeColor ?>; color: <?= $textColor ?>; padding: 0.25rem 0.6rem; border-radius: 12px; font-weight: bold; font-size: 0.85rem;">
                                            <?= $score ?>% Wahrscheinlich
                                        </span>
                                        <div>
                                            <strong><?= htmlspecialchars($sug['horse']['name']) ?></strong>
                                            <span style="color: #666; font-size: 0.85rem;">
                                                <?= $sug['horse']['birth_year'] ? 'geb. ' . htmlspecialchars((string)$sug['horse']['birth_year']) : '' ?> 
                                                <?= $sug['horse']['ueln'] ? ' (UELN: ' . htmlspecialchars($sug['horse']['ueln']) . ')' : '' ?>
                                                <?= !empty($sug['horse']['foreign_ueln']) ? ' [Ausland: ' . htmlspecialchars($sug['horse']['foreign_ueln']) . ']' : '' ?>
                                            </span>
                                            <?php if (!empty($sug['reasons'])): ?>
                                                <div style="margin-top: 0.3rem; display: flex; gap: 0.4rem; flex-wrap: wrap;">
                                                    <?php foreach ($sug['reasons'] as $reason): ?>
                                                        <span style="background: #eef2f5; color: #495057; border-radius: 4px; padding: 0.1rem 0.4rem; font-size: 0.75rem; border: 1px solid #dee2e6;">
                                                            <?= htmlspecialchars($reason) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <form action="/admin/matches/link" method="POST" style="margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                        <input type="hidden" name="child_id" value="<?= $match['child_id'] ?>">
                                        <input type="hidden" name="parent_type" value="<?= $match['parent_type'] ?>">
                                        <input type="hidden" name="parent_horse_id" value="<?= $sug['horse']['id'] ?>">
                                        <button type="submit" class="btn" style="padding: 0.3rem 0.8rem; font-size: 0.85rem;">
                                            ✓ Jetzt verknüpfen
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Manual Selection Override -->
                        <div style="margin-top: 1rem; padding-top: 0.8rem; border-top: 1px dashed #ddd; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                            <span style="font-size: 0.85rem; color: #666;">Anderes Pferd manuell auswählen:</span>
                            <form action="/admin/matches/link" method="POST" style="display: flex; gap: 0.5rem; margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                <input type="hidden" name="child_id" value="<?= $match['child_id'] ?>">
                                <input type="hidden" name="parent_type" value="<?= $match['parent_type'] ?>">
                                <select name="parent_horse_id" class="form-control" required style="width: auto; padding: 0.3rem 0.5rem; font-size: 0.85rem;">
                                    <option value="">-- Manuell wählen --</option>
                                    <?php foreach ($allHorses as $candidate): ?>
                                        <?php if ($candidate['id'] == $match['child_id']) continue; ?>
                                        <option value="<?= $candidate['id'] ?>">
                                            <?= htmlspecialchars($candidate['name']) ?> <?= $candidate['ueln'] ? '[' . $candidate['ueln'] . ']' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-secondary" style="padding: 0.3rem 0.8rem; font-size: 0.85rem;">Manuell Verknüpfen</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
