<?php
// src/Views/admin_matches.php
/**
 * @var array $unlinkedMatches Platzhalter der aktuellen Seite (#215, 50 je Seite)
 * @var array $allHorses
 * @var array $sexMismatches Bestehende Eltern-Verknüpfungen mit unpassendem Geschlecht (#166)
 * @var int $matchPage Aktuelle Seite (1-basiert)
 * @var int $matchTotalPages Gesamtzahl Seiten
 * @var int $matchTotal Offene Platzhalter mit Vorfilter-Kandidaten insgesamt
 */
$sexMismatches = $sexMismatches ?? [];
$matchPage = (int)($matchPage ?? 1);
$matchTotalPages = (int)($matchTotalPages ?? 1);
$matchTotal = (int)($matchTotal ?? count($unlinkedMatches));
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <div>
            <h2>🔗 Blutlinien Zusammenführen & Match-Vorschläge</h2>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Werkzeug für Administratoren und Editoren zur manuellen und automatischen Verknüpfung unvollständiger Eltern-Einträge.
            </p>
        </div>
        <a href="/admin/horses" class="btn btn-secondary">Zurück zur Pferdeübersicht</a>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            Verknüpfung erfolgreich durchgeführt! Der Stammbaum wurde aktualisiert.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            <?php
            // Fehlercodes aus HorseController::linkMatch() (#131/#167).
            $errorMessages = [
                'self_link' => 'Nicht verknüpft: Ein Pferd kann nicht sein eigener Elternteil sein.',
                'sex_mismatch' => 'Nicht verknüpft: Das Geschlecht des gewählten Pferds passt nicht zur Eltern-Rolle (Stute als Vater bzw. Hengst/Wallach als Mutter).',
            ];
            echo htmlspecialchars($errorMessages[$_GET['error']] ?? 'Aktion fehlgeschlagen.');
            ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($sexMismatches)): ?>
        <div style="background-color: var(--warning-soft-bg); color: var(--warning-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            <strong>⚠️ Bestehende Verknüpfungen mit unpassendem Geschlecht (<?= count($sexMismatches) ?>):</strong>
            <p style="margin: 0.4rem 0 0.6rem; font-size: 0.9rem;">
                Diese Eltern-Verknüpfungen entstanden, bevor das Geschlechtsfeld eingeführt wurde,
                und widersprechen ihm jetzt. Bitte Stammbaum oder Geschlechtsangabe korrigieren.
            </p>
            <ul style="margin: 0; padding-left: 1.4rem;">
                <?php foreach ($sexMismatches as $m): ?>
                    <li style="margin-bottom: 0.3rem;">
                        <a href="/admin/horses/edit?id=<?= (int)$m['id'] ?>" style="color: inherit; font-weight: bold;"><?= htmlspecialchars($m['name']) ?></a>:
                        <?php if ($m['sire_sex'] === 'mare'): ?>
                            Vater „<?= htmlspecialchars($m['sire_name']) ?>" ist als Stute erfasst.
                        <?php endif; ?>
                        <?php if (in_array($m['dam_sex'], ['stallion', 'gelding'], true)): ?>
                            Mutter „<?= htmlspecialchars($m['dam_name']) ?>" ist als <?= $m['dam_sex'] === 'stallion' ? 'Hengst' : 'Wallach' ?> erfasst.
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($matchTotal === 0): ?>
        <div style="text-align: center; padding: 3rem 1rem; background: var(--surface-muted); border-radius: 8px; border: 1px dashed var(--border-color);">
            <h3 style="color: var(--success-fg); margin-bottom: 0.5rem;">🎉 Keine unvollständigen Eltern-Einträge gefunden!</h3>
            <p style="color: var(--text-muted);">Alle eingetragenen Väter und Mütter sind bereits perfekt mit echten Pferdeprofilen verknüpft.</p>
        </div>
    <?php elseif (empty($unlinkedMatches)): ?>
        <!-- Seltener Randfall (#215): Die Seite enthält zwar offene Platzhalter,
             aber keiner ihrer Vorfilter-Kandidaten erreicht die Anzeigeschwelle
             der Bewertung - solche Einträge wurden noch nie angezeigt. -->
        <div style="text-align: center; padding: 3rem 1rem; background: var(--surface-muted); border-radius: 8px; border: 1px dashed var(--border-color);">
            <p style="color: var(--text-muted); margin: 0;">Auf dieser Seite gibt es keine Vorschläge oberhalb der Wahrscheinlichkeits-Schwelle.</p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <?php foreach ($unlinkedMatches as $match): ?>
                <div class="card" style="border-left: 4px solid var(--primary-fg); margin-bottom: 0; background: var(--card-bg);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
                        <div>
                            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; color: var(--text-subtle); font-weight: bold;">
                                Kind-Pferd:
                            </span>
                            <h3 style="margin: 0.2rem 0;">
                                <?= htmlspecialchars($match['child_name']) ?>
                            </h3>
                            <p style="margin: 0; color: var(--text-muted);">
                                Unverknüpfter <strong><?= htmlspecialchars($match['parent_type_label']) ?></strong>:
                                <span style="background: var(--warning-soft-bg); padding: 0.2rem 0.5rem; border-radius: 4px; color: var(--warning-fg); font-weight: bold;">
                                    <?= htmlspecialchars($match['placeholder_name'] ?: 'Kein Name') ?>
                                    <?= $match['placeholder_ueln'] ? '[' . htmlspecialchars($match['placeholder_ueln']) . ']' : '' ?>
                                </span>
                            </p>
                        </div>
                    </div>

                    <!-- Match Proposals -->
                    <div style="margin-top: 1.2rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
                        <h4 style="font-size: 0.95rem; color: var(--text-muted); margin-bottom: 0.8rem;">Beste Wahrscheinlichkeits-Vorschläge aus der Datenbank:</h4>

                        <div style="display: flex; flex-direction: column; gap: 0.6rem;">
                            <?php foreach ($match['suggestions'] as $sug): ?>
                                <?php
                                    $score = $sug['score'];
                                    $badgeColor = $score >= 90 ? '#28a745' : ($score >= 70 ? '#ffc107' : '#17a2b8');
                                    $textColor = $score >= 70 && $score < 90 ? '#212529' : '#ffffff';
                                ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; background: var(--surface-muted); padding: 0.6rem 1rem; border-radius: 6px; border: 1px solid #e9ecef;">
                                    <div style="display: flex; align-items: center; gap: 1rem;">
                                        <span style="background: <?= $badgeColor ?>; color: <?= $textColor ?>; padding: 0.25rem 0.6rem; border-radius: 12px; font-weight: bold; font-size: 0.85rem;">
                                            <?= $score ?>% Wahrscheinlich
                                        </span>
                                        <div>
                                            <strong><?= htmlspecialchars($sug['horse']['name']) ?></strong>
                                            <span style="color: var(--text-muted); font-size: 0.85rem;">
                                                <?= $sug['horse']['birth_year'] ? 'geb. ' . htmlspecialchars((string)$sug['horse']['birth_year']) : '' ?>
                                                <?= $sug['horse']['ueln'] ? ' (UELN: ' . htmlspecialchars($sug['horse']['ueln']) . ')' : '' ?>
                                                <?= !empty($sug['horse']['foreign_ueln']) ? ' [Ausland: ' . htmlspecialchars($sug['horse']['foreign_ueln']) . ']' : '' ?>
                                            </span>
                                            <?php if (!empty($sug['reasons'])): ?>
                                                <div style="margin-top: 0.3rem; display: flex; gap: 0.4rem; flex-wrap: wrap;">
                                                    <?php foreach ($sug['reasons'] as $reason): ?>
                                                        <span style="background: var(--surface-muted); color: var(--text-muted); border-radius: 4px; padding: 0.1rem 0.4rem; font-size: 0.75rem; border: 1px solid #dee2e6;">
                                                            <?= htmlspecialchars($reason) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div style="display: flex; gap: 0.4rem; align-items: center;">
                                        <form action="/admin/matches/link" method="POST" style="margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                            <input type="hidden" name="child_id" value="<?= $match['child_id'] ?>">
                                            <input type="hidden" name="parent_type" value="<?= $match['parent_type'] ?>">
                                            <input type="hidden" name="parent_horse_id" value="<?= $sug['horse']['id'] ?>">
                                            <button type="submit" class="btn" style="padding: 0.3rem 0.8rem; font-size: 0.85rem;">
                                                ✓ Jetzt verknüpfen
                                            </button>
                                        </form>
                                        <?php // #355: das Gegenteil von "verknüpfen". Ohne diesen Knopf kam
                                              // dasselbe Paar bei jedem Aufruf wieder, und der Digest zählte
                                              // es dauerhaft als offen. ?>
                                        <form action="/admin/matches/label" method="POST" style="margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                            <input type="hidden" name="art" value="horse">
                                            <input type="hidden" name="a" value="<?= (int)$match['child_id'] ?>">
                                            <input type="hidden" name="b" value="<?= (int)$sug['horse']['id'] ?>">
                                            <input type="hidden" name="label" value="different">
                                            <button type="submit" class="btn btn-secondary" style="padding: 0.3rem 0.8rem; font-size: 0.85rem;"
                                                    title="Blendet diesen Vorschlag dauerhaft aus - widerrufbar unten in der Liste der Entscheidungen">
                                                ✕ Verschiedene Pferde
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Manual Selection Override -->
                        <div style="margin-top: 1rem; padding-top: 0.8rem; border-top: 1px dashed var(--border-color); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                            <span style="font-size: 0.85rem; color: var(--text-muted);">Anderes Pferd manuell auswählen:</span>
                            <form action="/admin/matches/link" method="POST" style="display: flex; gap: 0.5rem; margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                <input type="hidden" name="child_id" value="<?= $match['child_id'] ?>">
                                <input type="hidden" name="parent_type" value="<?= $match['parent_type'] ?>">
                                <select name="parent_horse_id" class="form-control" required style="width: auto; padding: 0.3rem 0.5rem; font-size: 0.85rem;">
                                    <option value="">-- Manuell wählen --</option>
                                    <?php foreach ($allHorses as $candidate): ?>
                                        <?php if ($candidate['id'] == $match['child_id']) continue; ?>
                                        <?php
                                        // Geschlechtsfremde Kandidaten je Rolle ausblenden (#167);
                                        // NULL (unbekannt) bleibt wählbar. Serverseitig prüft
                                        // linkMatch() zusätzlich.
                                        $wrongSex = $match['parent_type'] === 'sire'
                                            ? in_array($candidate['sex'] ?? null, ['mare', 'gelding'], true)
                                            : in_array($candidate['sex'] ?? null, ['stallion', 'gelding'], true);
                                        if ($wrongSex) continue;
                                        ?>
                                        <option value="<?= $candidate['id'] ?>">
                                            <?= htmlspecialchars($candidate['name']) ?> <?= $candidate['ueln'] ? '[' . htmlspecialchars($candidate['ueln']) . ']' : '' ?>
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

    <?php if ($matchTotalPages > 1): ?>
        <!-- Blätter-Navigation (#215), Markup analog zu admin_gdpr.php. -->
        <div style="display: flex; justify-content: center; align-items: center; gap: 1rem; margin-top: 1.5rem;">
            <?php if ($matchPage > 1): ?>
                <a href="/admin/matches?page=<?= $matchPage - 1 ?>" class="btn btn-secondary" style="padding: 0.4rem 0.9rem; font-size: 0.9rem;">&laquo; Zurück</a>
            <?php endif; ?>
            <span style="font-size: 0.9rem; color: var(--text-muted);">Seite <?= $matchPage ?> von <?= $matchTotalPages ?> (<?= $matchTotal ?> offene Platzhalter)</span>
            <?php if ($matchPage < $matchTotalPages): ?>
                <a href="/admin/matches?page=<?= $matchPage + 1 ?>" class="btn btn-secondary" style="padding: 0.4rem 0.9rem; font-size: 0.9rem;">Weiter &raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php // ===================== Kontakt-Dubletten (#355) ===================== ?>
<?php if (!empty($darfKontakteBearbeiten)): ?>
<div class="card" style="margin-top: 1.5rem;">
    <h2 style="margin-top: 0;">👥 Mögliche Kontakt-Dubletten</h2>
    <p style="color: var(--text-muted); font-size: 0.9rem;">
        Namensähnlichkeit trägt die Bewertung, Ort, PLZ und Land stützen sie.
        Platzhalter wie „Nichtmitglied NO" nehmen bewusst <strong>nicht</strong>
        teil — sie unterscheiden sich nur im Länderkürzel, und jede
        Ähnlichkeitsmetrik hielte sie für denselben Kontakt.
    </p>

    <?php if (!empty($kontaktDubletten['abgeschnitten'])): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 0.8rem; border-radius: 4px; margin-bottom: 1rem;">
            Es wurden nur die ersten <?= (int)$kontaktDubletten['geprueft'] ?> Kontakte geprüft —
            der Vergleich ist ein Kreuzprodukt und würde sonst die Seite hängen lassen.
            <strong>Diese Liste ist damit nicht vollständig.</strong>
        </div>
    <?php endif; ?>

    <?php if (!empty($kontaktDubletten['uebersprungen'])): ?>
        <p style="color: var(--text-muted); font-size: 0.85rem;">
            <?= (int)$kontaktDubletten['uebersprungen'] ?> Platzhalter-Kontakt(e) nehmen nicht teil.
            <?php // Ausdrücklich genannt, damit "gefiltert" nicht wie "nichts gefunden"
                  // aussieht (#370) - vorher meldete die Seite schlicht die reduzierte Zahl. ?>
        </p>
    <?php endif; ?>

    <?php if (empty($kontaktDubletten['paare'])): ?>
        <p style="color: var(--success-fg);">Keine offenen Vorschläge — alles entschieden oder nichts gefunden.</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr><th>Kontakt A</th><th>Kontakt B</th><th>Punkte</th><th>Warum</th><th>Entscheidung</th></tr>
            </thead>
            <tbody>
            <?php foreach ($kontaktDubletten['paare'] as $paar): ?>
                <tr>
                    <td>
                        <a href="/admin/contacts/edit?id=<?= (int)$paar['a']['id'] ?>"><?= htmlspecialchars((string)$paar['a']['name']) ?></a>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">
                            <?= htmlspecialchars(trim(($paar['a']['postal_code'] ?? '') . ' ' . ($paar['a']['city'] ?? '') . ' ' . ($paar['a']['country'] ?? ''))) ?>
                        </div>
                    </td>
                    <td>
                        <a href="/admin/contacts/edit?id=<?= (int)$paar['b']['id'] ?>"><?= htmlspecialchars((string)$paar['b']['name']) ?></a>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">
                            <?= htmlspecialchars(trim(($paar['b']['postal_code'] ?? '') . ' ' . ($paar['b']['city'] ?? '') . ' ' . ($paar['b']['country'] ?? ''))) ?>
                        </div>
                    </td>
                    <td><strong><?= (int)$paar['score'] ?></strong></td>
                    <td style="font-size: 0.85rem; color: var(--text-muted);"><?= htmlspecialchars((string)$paar['begruendung']) ?></td>
                    <td>
                        <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                            <?php // Zusammenführen bleibt eine Einbahnstraße - deshalb führt der
                                  // Weg über das Merge-Formular mit seiner Vorschau, nicht über
                                  // einen Knopf hier. ?>
                            <a class="btn" style="padding: 0.3rem 0.8rem; font-size: 0.85rem;"
                               href="/admin/contacts/merge?id=<?= (int)$paar['a']['id'] ?>&amp;other=<?= (int)$paar['b']['id'] ?>">
                                → Zusammenführen prüfen
                            </a>
                            <form action="/admin/matches/label" method="POST" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                <input type="hidden" name="art" value="contact">
                                <input type="hidden" name="a" value="<?= (int)$paar['a']['id'] ?>">
                                <input type="hidden" name="b" value="<?= (int)$paar['b']['id'] ?>">
                                <input type="hidden" name="label" value="different">
                                <button type="submit" class="btn btn-secondary" style="padding: 0.3rem 0.8rem; font-size: 0.85rem;">
                                    ✕ Verschieden
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php // Die getroffenen Entscheidungen - und der Weg zurück. Eine falsch
      // gesetzte Trennung darf nicht endgültig sein (#355). ?>
<?php $alleLabels = array_merge(
        array_map(static fn($z) => $z + ['art' => 'horse'], $pferdeLabels ?? []),
        array_map(static fn($z) => $z + ['art' => 'contact'], $kontaktLabels ?? [])
      ); ?>
<?php if ($alleLabels !== []): ?>
<div class="card" style="margin-top: 1.5rem;">
    <h2 style="margin-top: 0;">🗂️ Getroffene Entscheidungen</h2>
    <p style="color: var(--text-muted); font-size: 0.9rem;">
        Was hier steht, taucht in den Vorschlägen nicht mehr auf. Ein Widerruf
        holt den Vorschlag zurück — am Bestand ändert weder das eine noch das
        andere etwas.
    </p>
    <table class="table">
        <thead><tr><th>Art</th><th>Paar</th><th>Entscheidung</th><th>Beleg</th><th>Wer, wann</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($alleLabels as $eintrag): ?>
            <tr>
                <td><?= $eintrag['art'] === 'horse' ? 'Pferd' : 'Kontakt' ?></td>
                <td>#<?= (int)$eintrag['left_id'] ?> / #<?= (int)$eintrag['right_id'] ?></td>
                <td><?= htmlspecialchars(match ($eintrag['label']) {
                        'merged' => 'zusammengeführt',
                        'different' => 'verschieden',
                        default => 'unklar',
                    }) ?></td>
                <td style="font-size: 0.85rem;"><?= htmlspecialchars((string)($eintrag['note'] ?? '')) ?></td>
                <td style="font-size: 0.85rem; color: var(--text-muted);">
                    <?= htmlspecialchars((string)$eintrag['username']) ?>,
                    <?= htmlspecialchars((string)$eintrag['created_at']) ?>
                </td>
                <td>
                    <form action="/admin/matches/label" method="POST" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                        <input type="hidden" name="art" value="<?= htmlspecialchars((string)$eintrag['art']) ?>">
                        <input type="hidden" name="a" value="<?= (int)$eintrag['left_id'] ?>">
                        <input type="hidden" name="b" value="<?= (int)$eintrag['right_id'] ?>">
                        <input type="hidden" name="label" value="">
                        <button type="submit" class="btn btn-secondary" style="padding: 0.3rem 0.8rem; font-size: 0.85rem;">
                            ↺ Widerrufen
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
