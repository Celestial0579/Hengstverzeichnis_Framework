<?php
// src/Views/admin_import_horses.php
/**
 * @var array|null $preview Validierte Zeilen aus HorseCsvImporter::validateRows(), oder null
 * @var array|null $errors Fehlermeldungen auf Datei-Ebene (z. B. fehlende Pflichtspalte)
 * @var array|null $result ['imported' => int, 'skipped' => int] nach abgeschlossenem Import
 * @var int|null $validCount
 * @var bool|null $canPublish
 */
$preview = $preview ?? null;
$errors = $errors ?? [];
$result = $result ?? null;
$canPublish = $canPublish ?? false;
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
        <h2>📄 Pferde-Bulk-Import (CSV)</h2>
        <a href="/admin/horses" class="btn btn-secondary">Zurück zur Pferdeliste</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <ul style="margin-left: 1.2rem;">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($result !== null): ?>
        <!-- Ergebnis-Ansicht nach abgeschlossenem Import -->
        <div style="background-color: <?= $result['imported'] > 0 ? '#d4edda' : '#fff3cd' ?>; color: <?= $result['imported'] > 0 ? '#155724' : '#856404' ?>; padding: 1.2rem; border-radius: 6px; margin-bottom: 1rem;">
            <strong><?= (int)$result['imported'] ?></strong> Pferd(e) erfolgreich importiert.
            <?php if ($result['skipped'] > 0): ?>
                <br><strong><?= (int)$result['skipped'] ?></strong> Zeile(n) wegen Fehlern übersprungen (siehe vorherige Vorschau).
            <?php endif; ?>
        </div>
        <p style="color: var(--text-muted); font-size: 0.9rem;">
            Importierte Pferde mit unaufgelöster Vater-/Mutter-Angabe (Spalten <code>sire_name</code>/<code>dam_name</code>)
            erscheinen ggf. als Vorschlag unter <a href="/admin/matches">🔗 Blutlinien Zusammenführen</a>, sobald ein
            passendes bestehendes Pferd gefunden wird - der Import selbst verknüpft bewusst nicht automatisch.
        </p>
        <div style="margin-top: 1rem; display: flex; gap: 1rem;">
            <a href="/admin/horses" class="btn">Zur Pferdeliste</a>
            <a href="/admin/import/horses" class="btn btn-secondary">Weitere Datei importieren</a>
        </div>

    <?php elseif ($preview !== null): ?>
        <!-- Vorschau-Ansicht: jede Zeile mit Validierungsstatus, vor dem tatsächlichen Import -->
        <p style="margin-bottom: 1rem;">
            <strong><?= (int)$validCount ?></strong> von <strong><?= count($preview) ?></strong> Zeilen sind gültig und werden beim Import übernommen.
            Fehlerhafte Zeilen werden übersprungen, die Datei muss dafür nicht korrigiert werden.
        </p>

        <?php if (!$canPublish): ?>
            <p style="background-color: var(--warning-soft-bg); color: var(--warning-fg); padding: 0.8rem; border-radius: 4px; margin-bottom: 1rem; font-size: 0.9rem;">
                ⚠️ Ohne die Berechtigung "Veröffentlichen" werden importierte Pferde unveröffentlicht angelegt und erscheinen nicht im öffentlichen Katalog. Sie können später von einer berechtigten Person über die Massen-Veröffentlichung in der Pferdeverwaltung freigegeben werden.
            </p>
        <?php endif; ?>

        <div style="max-height: 500px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 6px; margin-bottom: 1rem;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-color); text-align: left; position: sticky; top: 0; background: var(--card-bg); z-index: 1;">
                        <th style="padding: 0.5rem;">Zeile</th>
                        <th style="padding: 0.5rem;">Status</th>
                        <th style="padding: 0.5rem;">Name</th>
                        <th style="padding: 0.5rem;">UELN</th>
                        <th style="padding: 0.5rem;">Geburtsjahr</th>
                        <th style="padding: 0.5rem;">Fehler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview as $entry): ?>
                        <?php $isValid = empty($entry['errors']); ?>
                        <tr style="border-bottom: 1px solid var(--border-color); <?= $isValid ? '' : 'background-color: rgba(220, 53, 69, 0.08);' ?>">
                            <td style="padding: 0.5rem;"><?= (int)$entry['row'] ?></td>
                            <td style="padding: 0.5rem;">
                                <?php if ($isValid): ?>
                                    <span style="color: var(--success-fg);">✅ Gültig</span>
                                <?php else: ?>
                                    <span style="color: var(--danger-fg);">❌ Fehler</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 0.5rem;"><?= htmlspecialchars((string)$entry['data']['name']) ?></td>
                            <td style="padding: 0.5rem;"><?= htmlspecialchars((string)($entry['data']['ueln'] ?? '-')) ?></td>
                            <td style="padding: 0.5rem;"><?= htmlspecialchars((string)($entry['data']['birth_year'] ?? '-')) ?></td>
                            <td style="padding: 0.5rem; color: var(--danger-fg); font-size: 0.85rem;">
                                <?= htmlspecialchars(implode(' ', $entry['errors'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <form action="/admin/import/horses/commit" method="POST">
            <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
            <?php if ($canPublish): ?>
                <label style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; cursor: pointer;">
                    <input type="checkbox" name="is_published" value="1">
                    <span>🌐 Importierte Pferde direkt öffentlich sichtbar machen (veröffentlichen)</span>
                </label>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1rem;">
                    Ohne Häkchen werden die Pferde unveröffentlicht angelegt und können später über die Massen-Veröffentlichung in der Pferdeverwaltung freigegeben werden.
                </p>
            <?php endif; ?>
            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn" <?= $validCount > 0 ? '' : 'disabled' ?>>
                    <?= (int)$validCount ?> gültige Zeile(n) jetzt importieren
                </button>
                <a href="/admin/import/horses" class="btn btn-secondary">Abbrechen</a>
            </div>
        </form>

    <?php else: ?>
        <!-- Upload-Formular -->
        <p style="color: var(--text-muted); margin-bottom: 1rem;">
            CSV-Datei mit Pferdedaten hochladen (z. B. aus Excel/LibreOffice/Google Sheets exportiert). Die erste
            Zeile muss Spaltennamen enthalten, nur die Spalte <code>name</code> ist Pflicht - Reihenfolge und
            zusätzliche/fehlende Spalten sind egal. Komma oder Semikolon als Trennzeichen werden automatisch erkannt.
        </p>

        <div style="background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 6px; padding: 1rem; margin-bottom: 1.5rem; font-size: 0.85rem;">
            <strong>Unterstützte Spalten:</strong>
            <code>name*, ueln, foreign_ueln, sire_name, sire_ueln, dam_name, dam_ueln, birth_year, birth_date, color, sex, breed, height_cm, breeding_station, description, status, deceased, death_year</code>
            <br>
            <span style="color: var(--text-muted);">
                <code>status</code>: active/inactive = Zuchtstatus (Standard: active; der Alt-Wert deceased wird
                als inactive + verstorben übernommen).
                <code>deceased</code>: ja/nein bzw. 1/0; ein gesetztes <code>death_year</code> setzt verstorben automatisch.
                <code>birth_date</code>: JJJJ-MM-TT oder TT.MM.JJJJ - das Geburtsjahr wird daraus abgeleitet.
                <code>height_cm</code>: Stockmaß in cm (50-250).
                <code>sex</code>: stallion/hengst, mare/stute, gelding/wallach (leer = unbekannt). <code>sire_name</code>/<code>dam_name</code>
                werden wie bei der manuellen Einzelanlage als unverknüpfter Freitext gespeichert - siehe
                <a href="/admin/matches">Blutlinien Zusammenführen</a> für die anschließende Verknüpfung.
                Maximal <?= App\Service\HorseCsvImporter::MAX_ROWS ?> Datenzeilen je Datei.
            </span>
        </div>

        <form action="/admin/import/horses/preview" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
            <div class="form-group">
                <label for="csv_file">CSV-Datei *</label>
                <input type="file" id="csv_file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
            </div>
            <button type="submit" class="btn">Datei prüfen &amp; Vorschau anzeigen</button>
        </form>
    <?php endif; ?>
</div>
