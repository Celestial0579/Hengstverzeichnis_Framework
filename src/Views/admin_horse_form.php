<?php
// src/Views/admin_horse_form.php
/**
 * @var array|null $horse
 * @var array $allHorses
 * @var array $allContacts Kontakte fuer BEIDE Steckplaetze der Verlaufszeile (#336)
 * @var string $title
 * @var bool $canPublish Berechtigung 'horses.publish' (#66)
 */
$isEdit = !empty($horse);
$actionUrl = $isEdit ? '/admin/horses/update' : '/admin/horses/store';

// Für den client-seitigen "Verlaufseintrag hinzufügen"-Button: als reine JSON-Daten
// einbetten und im JS per textContent rendern, statt Namen in HTML-/JS-Strings zu
// interpolieren - verhindert Script-Injection über Personen-/Deckstationsnamen,
// die Backticks oder andere Steuerzeichen enthalten könnten.
$jsonOptions = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
// Eine Liste fuer beide Auswahlfelder (#336): Personen und Deckstationen
// stehen seit dem Zusammenlegen in einer Tabelle. Die Felder bleiben zwei -
// sie sagen Verschiedenes (wer / wo) -, nur der Topf ist derselbe.
$contactsForJs = [];
foreach (($allContacts ?? []) as $c) {
    $contactsForJs[] = ['id' => (int)$c['id'], 'name' => (string)$c['name']];
}
?>
<div class="card">
    <h2><?= htmlspecialchars($title) ?></h2>

    <?php if (!empty($isDeleted ?? false)): ?>
    <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
        <strong>Dieser Datensatz liegt im Papierkorb.</strong>
        Er wird hier nur angezeigt, damit sich pruefen laesst, welche Daten noch
        gespeichert sind. <strong>Speichern ist nicht moeglich</strong> - dazu muss
        er zuerst unter <a href="/admin/trash">Papierkorb</a> wiederhergestellt
        werden.
    </div>
<?php endif; ?>
<form action="<?= $actionUrl ?>" method="POST" enctype="multipart/form-data" style="max-width: 700px; margin-top: 1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= $horse['id'] ?>">
        <?php endif; ?>

        <?php // Foto-Upload NUR beim Anlegen (#339).
              //
              // Beim Bearbeiten uebernimmt der Medien-Abschnitt unter dem
              // Formular. Zwei Uploadfelder fuer dieselbe Sache auf einer
              // Seite waren genau der Zustand, den #339 beendet: oben das
              // Kernfeld, darunter die Galerie - zwei Ablagen, zwei
              // Vorstellungen davon, welches Bild das Hauptbild ist.
              //
              // Beim ANLEGEN gibt es das Pferd noch nicht, es hat also auch
              // noch keine Medien-Zeilen. Das eine Feld hier bleibt deshalb
              // und wird nach dem Einfuegen zum Hauptbild
              // (HorseController::store()). ?>
        <?php if (!$isEdit): ?>
        <div class="form-group" style="background: var(--surface-muted); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 1.5rem;">
            <label for="horse_image" style="font-weight: bold; color: var(--primary-fg);">📷 Foto des Pferdes hochladen</label>
            <input type="file" id="horse_image" name="horse_image" accept="image/jpeg,image/png,image/webp" class="form-control">
            <small style="color: var(--text-muted); display: block; margin-top: 0.3rem;">
                Erlaubte Formate: JPG, PNG, WEBP (max. 5 MB). Es wird das Hauptbild;
                weitere Fotos und Videos kommen nach dem Speichern dazu.
            </small>
        </div>
        <?php endif; ?>

        <div class="form-group">
            <label for="name">Name des Pferdes *</label>
            <input type="text" id="name" name="name" class="form-control" value="<?= htmlspecialchars($horse['name'] ?? '') ?>" required>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
            <div class="form-group" style="margin-bottom: 0;">
                <label for="ueln">UELN (Haupt-Lebensnummer / Deutschland)</label>
                <input type="text" id="ueln" name="ueln" class="form-control" value="<?= htmlspecialchars($horse['ueln'] ?? '') ?>" placeholder="z. B. DE 434340123418">
            </div>

            <?php // Weitere Lebensnummern (#246): beliebig viele Registriernummern
                  // (Mehrfachregistrierung, Altbestands-Kennungen) in der
                  // Kindtabelle horse_registrations statt der früheren
                  // ' / '-Verkettung im 50-Zeichen-Feld foreign_ueln. Der
                  // versteckte Marker registrations_present lässt den Server
                  // auch eine komplett geleerte Liste erkennen (siehe
                  // HorseController::saveRegistrations()). ?>
            <div class="form-group" style="margin-bottom: 0;">
                <label>Weitere Lebensnummern / Registriernummern</label>
                <input type="hidden" name="registrations_present" value="1">
                <div id="registrations_container" style="display: flex; flex-direction: column; gap: 0.4rem;">
                    <?php foreach (($horseRegistrations ?? []) as $registrationNumber): ?>
                        <div class="registration-row" style="display: flex; gap: 0.4rem;">
                            <input type="text" name="registrations[]" class="form-control" maxlength="50" value="<?= htmlspecialchars((string)$registrationNumber) ?>" placeholder="z. B. NLD003201801234">
                            <button type="button" class="btn" style="background: #dc3545; color: #fff; padding: 0.4rem 0.6rem;" onclick="this.closest('.registration-row').remove();">🗑️</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-secondary" style="margin-top: 0.4rem; font-size: 0.85rem;" onclick="addRegistrationRow();">+ Nummer hinzufügen</button>
                <small style="color: var(--text-muted); display: block; margin-top: 0.3rem;">Zuchtbuch-/Registriernummern zusätzlich zur Haupt-UELN, je Nummer eine Zeile (max. 50 Zeichen).</small>
            </div>
        </div>

        <script>
        function addRegistrationRow(value) {
            const container = document.getElementById('registrations_container');
            const div = document.createElement('div');
            div.className = 'registration-row';
            div.style = 'display: flex; gap: 0.4rem;';

            const input = document.createElement('input');
            input.type = 'text';
            input.name = 'registrations[]';
            input.className = 'form-control';
            input.maxLength = 50;
            input.placeholder = 'z. B. NLD003201801234';
            if (typeof value === 'string') input.value = value;

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn';
            removeBtn.style = 'background: #dc3545; color: #fff; padding: 0.4rem 0.6rem;';
            removeBtn.textContent = '🗑️';
            removeBtn.addEventListener('click', () => div.remove());

            div.appendChild(input);
            div.appendChild(removeBtn);
            container.appendChild(div);
            input.focus();
        }
        </script>

        <!-- Abstammung: Vater (Sire) -->
        <fieldset style="border: 1px solid var(--border-color); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; background: var(--surface-muted);">
            <legend style="padding: 0 0.5rem; font-weight: bold; color: var(--primary-fg);">♂ Vater (Sire)</legend>

            <div class="form-group">
                <label for="sire_id">Existierendes Pferd aus der Datenbank wählen:</label>
                <select id="sire_id" name="sire_id" class="form-control">
                    <option value="">-- Nicht verknüpft / Text-Eingabe nutzen --</option>
                    <?php foreach ($allHorses as $h): ?>
                        <?php if ($isEdit && $h['id'] == $horse['id']) continue; ?>
                        <?php
                        // Als Vater nur Hengste und Pferde ohne Geschlechtsangabe (#166);
                        // eine bereits gespeicherte (Alt-)Verknüpfung bleibt wählbar,
                        // damit das Formular sie nicht beim Speichern still verwirft.
                        if (in_array($h['sex'] ?? null, ['mare', 'gelding'], true) && ($horse['sire_id'] ?? '') != $h['id']) continue;
                        ?>
                        <option value="<?= $h['id'] ?>" <?= ($horse['sire_id'] ?? '') == $h['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($h['name']) ?> <?= $h['birth_year'] ? '(' . $h['birth_year'] . ')' : '' ?> <?= $h['ueln'] ? '[' . htmlspecialchars($h['ueln']) . ']' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="font-size: 0.85rem; color: var(--text-subtle); text-align: center; margin: 0.5rem 0;">— ODER falls nicht in der Datenbank vorhanden —</div>

            <div style="display: flex; gap: 1rem;">
                <div class="form-group" style="flex: 2;">
                    <label for="sire_name">Name des Vaters (Freitext)</label>
                    <input type="text" id="sire_name" name="sire_name" class="form-control" value="<?= htmlspecialchars($horse['sire_name'] ?? '') ?>" placeholder="Name des Vaters">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label for="sire_ueln">UELN des Vaters</label>
                    <input type="text" id="sire_ueln" name="sire_ueln" class="form-control" value="<?= htmlspecialchars($horse['sire_ueln'] ?? '') ?>" placeholder="UELN">
                </div>
            </div>
        </fieldset>

        <!-- Abstammung: Mutter (Dam) -->
        <fieldset style="border: 1px solid var(--border-color); padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; background: var(--surface-muted);">
            <legend style="padding: 0 0.5rem; font-weight: bold; color: var(--primary-fg);">♀ Mutter (Dam)</legend>

            <div class="form-group">
                <label for="dam_id">Existierendes Pferd aus der Datenbank wählen:</label>
                <select id="dam_id" name="dam_id" class="form-control">
                    <option value="">-- Nicht verknüpft / Text-Eingabe nutzen --</option>
                    <?php foreach ($allHorses as $h): ?>
                        <?php if ($isEdit && $h['id'] == $horse['id']) continue; ?>
                        <?php
                        // Als Mutter nur Stuten und Pferde ohne Geschlechtsangabe (#166);
                        // Alt-Verknüpfung bleibt wählbar (siehe Vater-Auswahl).
                        if (in_array($h['sex'] ?? null, ['stallion', 'gelding'], true) && ($horse['dam_id'] ?? '') != $h['id']) continue;
                        ?>
                        <option value="<?= $h['id'] ?>" <?= ($horse['dam_id'] ?? '') == $h['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($h['name']) ?> <?= $h['birth_year'] ? '(' . $h['birth_year'] . ')' : '' ?> <?= $h['ueln'] ? '[' . htmlspecialchars($h['ueln']) . ']' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="font-size: 0.85rem; color: var(--text-subtle); text-align: center; margin: 0.5rem 0;">— ODER falls nicht in der Datenbank vorhanden —</div>

            <div style="display: flex; gap: 1rem;">
                <div class="form-group" style="flex: 2;">
                    <label for="dam_name">Name der Mutter (Freitext)</label>
                    <input type="text" id="dam_name" name="dam_name" class="form-control" value="<?= htmlspecialchars($horse['dam_name'] ?? '') ?>" placeholder="Name der Mutter">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label for="dam_ueln">UELN der Mutter</label>
                    <input type="text" id="dam_ueln" name="dam_ueln" class="form-control" value="<?= htmlspecialchars($horse['dam_ueln'] ?? '') ?>" placeholder="UELN">
                </div>
            </div>
        </fieldset>

        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 1;">
                <label for="birth_date">Geburtsdatum</label>
                <input type="date" id="birth_date" name="birth_date" class="form-control" value="<?= htmlspecialchars((string)($horse['birth_date'] ?? '')) ?>">
                <small style="color: var(--text-muted);">Bei gesetztem Datum wird das Geburtsjahr automatisch daraus übernommen.</small>
            </div>

            <div class="form-group" style="flex: 1;">
                <label for="birth_year">Geburtsjahr</label>
                <input type="number" id="birth_year" name="birth_year" min="1700" max="<?= date('Y') + 1 ?>" class="form-control" value="<?= htmlspecialchars((string)($horse['birth_year'] ?? '')) ?>">
            </div>
        </div>

        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 1;">
                <label for="color">Farbe</label>
                <input type="text" id="color" name="color" class="form-control" value="<?= htmlspecialchars($horse['color'] ?? '') ?>">
            </div>

            <div class="form-group" style="flex: 1;">
                <label for="height_cm">Stockmaß (cm)</label>
                <input type="number" id="height_cm" name="height_cm" min="50" max="250" class="form-control" value="<?= htmlspecialchars((string)($horse['height_cm'] ?? '')) ?>" placeholder="z. B. 146">
            </div>
        </div>

        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 1;">
                <label for="sex">Geschlecht</label>
                <select id="sex" name="sex" class="form-control" onchange="toggleCastrationDate()">
                    <option value="">-- Unbekannt --</option>
                    <option value="stallion" <?= ($horse['sex'] ?? '') === 'stallion' ? 'selected' : '' ?>>Hengst</option>
                    <option value="mare" <?= ($horse['sex'] ?? '') === 'mare' ? 'selected' : '' ?>>Stute</option>
                    <option value="gelding" <?= ($horse['sex'] ?? '') === 'gelding' ? 'selected' : '' ?>>Wallach</option>
                </select>
            </div>

            <div class="form-group" style="flex: 1;">
                <label for="breed">Rasse</label>
                <input type="text" id="breed" name="breed" class="form-control" value="<?= htmlspecialchars($horse['breed'] ?? '') ?>" placeholder="z. B. Fjordpferd">
            </div>
        </div>

        <?php
        // Kastrationsdatum (#239): nur bei Wallachen sinnvoll - das Ein-/
        // Ausblenden ist rein clientseitig (Komfort), der Server speichert
        // tolerant auch bei anderem Geschlecht (siehe HorseController). Bei
        // gesetztem Datum bleibt der Block trotz abweichendem Geschlecht
        // sichtbar, damit ein erfasster Wert nie unsichtbar "festhängt".
        $showCastration = (($horse['sex'] ?? '') === 'gelding') || !empty($horse['castration_date']);
        ?>
        <div class="form-group" id="castration_date_group" style="<?= $showCastration ? '' : 'display: none;' ?>">
            <label for="castration_date">Kastrationsdatum</label>
            <input type="date" id="castration_date" name="castration_date" class="form-control" value="<?= htmlspecialchars((string)($horse['castration_date'] ?? '')) ?>">
            <small style="color: var(--text-muted);">Nur bei Wallachen relevant - dort endet die Deckeinsatz-Historie.</small>
        </div>

        <script>
        function toggleCastrationDate() {
            const sexValue = document.getElementById('sex').value;
            const group = document.getElementById('castration_date_group');
            const hasValue = document.getElementById('castration_date').value !== '';
            // Ein bereits erfasstes Datum bleibt sichtbar, sonst richtet sich
            // die Anzeige nach dem Geschlecht (nur Wallach).
            group.style.display = (sexValue === 'gelding' || hasValue) ? '' : 'none';
        }
        </script>

        <!-- Personen, Besitzer & Deckstationenverlauf -->
        <div class="form-group" style="background: var(--surface-muted); padding: 1.2rem; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.8rem; flex-wrap: wrap; gap: 0.5rem;">
                <label style="font-weight: bold; color: var(--primary-fg); margin-bottom: 0;">👤 Züchter-, Besitzer- & Deckstationenverlauf</label>
                <div style="display: flex; gap: 1rem; font-size: 0.85rem;">
                    <!-- Ein Ziel statt zweier (#336): Beim Anlegen muss niemand mehr
                         vorab entscheiden, ob ein Hof "Person" oder "Deckstation" ist. -->
                    <a href="/admin/contacts/create" target="_blank" style="color: var(--primary-fg);">+ Neuen Kontakt anlegen</a>
                </div>
            </div>

            <!-- Ohne diesen Marker kann der Controller "keine Zeilen uebermittelt"
                 nicht von "alle Zeilen geloescht" unterscheiden - dann liesse sich
                 die letzte Zeile nicht mehr entfernen (#295, Muster wie bei
                 registrations_present). -->
            <input type="hidden" name="persons_present" value="1">
            <div id="persons_container" style="display: flex; flex-direction: column; gap: 0.8rem;">
                <?php if (empty($horsePersons)): ?>
                    <!-- Initial empty row if none -->
                    <div class="person-row" style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; background: var(--surface-muted); padding: 0.8rem; border-radius: 6px; border: 1px solid var(--border-color);">
                        <div style="flex: 2; min-width: 180px;">
                            <select name="persons[0][contact_id]" class="form-control">
                                <option value="">-- Person (Züchter/Besitzer) --</option>
                                <?php foreach ($allContacts as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="flex: 1.5; min-width: 140px;">
                            <select name="persons[0][role]" class="form-control" onchange="toggleYears(this)">
                                <option value="breeder">Züchter</option>
                                <option value="owner" selected>Besitzer</option>
                                <option value="keeper">Halter / Deckstation</option>
                            </select>
                        </div>

                        <div style="flex: 2; min-width: 180px;">
                            <select name="persons[0][station_contact_id]" class="form-control">
                                <option value="">-- Deckstation / Gestüt (Optional) --</option>
                                <?php foreach ($allContacts as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="flex: 2; min-width: 180px;">
                            <input type="text" name="persons[0][breeding_station_text]" class="form-control" maxlength="255" placeholder="oder Deckstation als Freitext">
                        </div>
                        <div style="flex: 1.2; min-width: 130px;">
                            <input type="text" name="persons[0][origin_country]" class="form-control" maxlength="100" placeholder="Herkunftsland">
                        </div>

                        <div class="year-inputs" style="display: flex; gap: 0.4rem; flex: 1.8; min-width: 160px;">
                            <input type="number" name="persons[0][from_year]" placeholder="Von (Jahr)" class="form-control" style="flex: 1;" min="1700" max="<?= date('Y') + 1 ?>">
                            <input type="number" name="persons[0][until_year]" placeholder="Bis (Jahr)" class="form-control" style="flex: 1;" min="1700" max="<?= date('Y') + 1 ?>">
                        </div>

                        <button type="button" class="btn" style="background: #dc3545; color: #fff; padding: 0.4rem 0.6rem;" onclick="this.closest('.person-row').remove();">🗑️</button>
                    </div>
                <?php else: ?>
                    <?php foreach ($horsePersons as $idx => $hp): ?>
                        <div class="person-row" style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; background: var(--surface-muted); padding: 0.8rem; border-radius: 6px; border: 1px solid var(--border-color);">
                            <div style="flex: 2; min-width: 180px;">
                                <select name="persons[<?= $idx ?>][contact_id]" class="form-control">
                                    <option value="">-- Person (Züchter/Besitzer) --</option>
                                    <?php foreach ($allContacts as $c): ?>
                                        <option value="<?= $c['id'] ?>" <?= ($hp['contact_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div style="flex: 1.5; min-width: 140px;">
                                <select name="persons[<?= $idx ?>][role]" class="form-control" onchange="toggleYears(this)">
                                    <option value="breeder" <?= $hp['role'] === 'breeder' ? 'selected' : '' ?>>Züchter</option>
                                    <option value="owner" <?= $hp['role'] === 'owner' ? 'selected' : '' ?>>Besitzer</option>
                                    <option value="keeper" <?= $hp['role'] === 'keeper' ? 'selected' : '' ?>>Halter / Deckstation</option>
                                </select>
                            </div>

                            <div style="flex: 2; min-width: 180px;">
                                <select name="persons[<?= $idx ?>][station_contact_id]" class="form-control">
                                    <option value="">-- Deckstation / Gestüt (Optional) --</option>
                                    <?php foreach ($allContacts as $c): ?>
                                        <option value="<?= $c['id'] ?>" <?= ($hp['station_contact_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div style="flex: 2; min-width: 180px;">
                                <input type="text" name="persons[<?= $idx ?>][breeding_station_text]" value="<?= htmlspecialchars((string)($hp['breeding_station_text'] ?? '')) ?>" class="form-control" maxlength="255" placeholder="oder Deckstation als Freitext">
                            </div>
                        <div style="flex: 1.2; min-width: 130px;">
                            <input type="text" name="persons[<?= $idx ?>][origin_country]" value="<?= htmlspecialchars((string)($hp['origin_country'] ?? '')) ?>" class="form-control" maxlength="100" placeholder="Herkunftsland">
                        </div>

                            <div class="year-inputs" style="display: <?= $hp['role'] === 'breeder' ? 'none' : 'flex' ?>; gap: 0.4rem; flex: 1.8; min-width: 160px;">
                                <input type="number" name="persons[<?= $idx ?>][from_year]" value="<?= htmlspecialchars((string)($hp['from_year'] ?? '')) ?>" placeholder="Von (Jahr)" class="form-control" style="flex: 1;" min="1700" max="<?= date('Y') + 1 ?>">
                                <input type="number" name="persons[<?= $idx ?>][until_year]" value="<?= htmlspecialchars((string)($hp['until_year'] ?? '')) ?>" placeholder="Bis (Jahr)" class="form-control" style="flex: 1;" min="1700" max="<?= date('Y') + 1 ?>">
                            </div>

                            <button type="button" class="btn" style="background: #dc3545; color: #fff; padding: 0.4rem 0.6rem;" onclick="this.closest('.person-row').remove();">🗑️</button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <button type="button" class="btn btn-secondary" style="margin-top: 0.8rem; font-size: 0.9rem;" onclick="addPersonRow();">+ Verlaufseintrag hinzufügen</button>
        </div>

        <script>
        let personRowIndex = <?= max(1, count($horsePersons ?? [])) ?>;

        // Als reine JSON-Daten eingebettet (nicht als HTML/JS-String-Interpolation),
        // damit Namen mit Sonderzeichen (inkl. Backticks) keine Script-Injection erlauben -
        // Rendering erfolgt unten ausschließlich über textContent.
        // Eine Datenquelle, zwei Auswahlfelder (#336).
        const allContactsData = <?= json_encode($contactsForJs, $jsonOptions) ?>;

        function toggleYears(selectElem) {
            const row = selectElem.closest('.person-row');
            const yearInputs = row.querySelector('.year-inputs');
            if (selectElem.value === 'breeder') {
                yearInputs.style.display = 'none';
            } else {
                yearInputs.style.display = 'flex';
            }
        }

        function populateOptions(selectElem, items, placeholderText) {
            const placeholderOpt = document.createElement('option');
            placeholderOpt.value = '';
            placeholderOpt.textContent = placeholderText;
            selectElem.appendChild(placeholderOpt);

            items.forEach(item => {
                const opt = document.createElement('option');
                opt.value = item.id;
                opt.textContent = item.name;
                selectElem.appendChild(opt);
            });
        }

        function addPersonRow() {
            const container = document.getElementById('persons_container');
            const div = document.createElement('div');
            div.className = 'person-row';
            div.style = 'display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; background: var(--surface-muted); padding: 0.8rem; border-radius: 6px; border: 1px solid var(--border-color);';
            div.innerHTML = `
                <div style="flex: 2; min-width: 180px;">
                    <select name="persons[${personRowIndex}][contact_id]" class="form-control"></select>
                </div>
                <div style="flex: 1.5; min-width: 140px;">
                    <select name="persons[${personRowIndex}][role]" class="form-control" onchange="toggleYears(this)">
                        <option value="breeder">Züchter</option>
                        <option value="owner" selected>Besitzer</option>
                        <option value="keeper">Halter / Deckstation</option>
                    </select>
                </div>
                <div style="flex: 2; min-width: 180px;">
                    <select name="persons[${personRowIndex}][station_contact_id]" class="form-control"></select>
                </div>
                <div style="flex: 2; min-width: 180px;">
                    <input type="text" name="persons[${personRowIndex}][breeding_station_text]" class="form-control" maxlength="255" placeholder="oder Deckstation als Freitext">
                </div>
                        <div style="flex: 1.2; min-width: 130px;">
                            <input type="text" name="persons[${personRowIndex}][origin_country]" class="form-control" maxlength="100" placeholder="Herkunftsland">
                        </div>
                <div class="year-inputs" style="display: flex; gap: 0.4rem; flex: 1.8; min-width: 160px;">
                    <input type="number" name="persons[${personRowIndex}][from_year]" placeholder="Von (Jahr)" class="form-control" style="flex: 1;" min="1700" max="<?= date('Y') + 1 ?>">
                    <input type="number" name="persons[${personRowIndex}][until_year]" placeholder="Bis (Jahr)" class="form-control" style="flex: 1;" min="1700" max="<?= date('Y') + 1 ?>">
                </div>
                <button type="button" class="btn" style="background: #dc3545; color: #fff; padding: 0.4rem 0.6rem;" onclick="this.closest('.person-row').remove();">🗑️</button>
            `;
            populateOptions(div.querySelector(`select[name="persons[${personRowIndex}][contact_id]"]`), allContactsData, '-- Person (Züchter/Besitzer) --');
            populateOptions(div.querySelector(`select[name="persons[${personRowIndex}][station_contact_id]"]`), allContactsData, '-- Deckstation / Gestüt (Optional) --');
            container.appendChild(div);
            personRowIndex++;
        }
        </script>

        <div class="form-group">
            <label for="status">Zuchtstatus</label>
            <select id="status" name="status" class="form-control">
                <option value="active" <?= ($horse['status'] ?? '') === 'active' ? 'selected' : '' ?>>Aktiv (Gekört)</option>
                <option value="inactive" <?= ($horse['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inaktiv</option>
            </select>
            <p style="color: var(--text-subtle); font-size: 0.8rem; margin: 0.3rem 0 0 0;">
                Der Zuchtstatus ist rein informativ und beeinflusst die öffentliche Sichtbarkeit nicht.
                Verstorben ist davon getrennt: ein Tier kann verstorben und dennoch zu Lebzeiten aktiv geführt sein.
            </p>
        </div>

        <div class="form-group">
            <div style="display: flex; gap: 1rem; align-items: flex-end;">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; flex: 1; margin-bottom: 0.6rem;">
                    <input type="checkbox" name="is_deceased" value="1" style="width: auto;" <?= !empty($horse['is_deceased']) ? 'checked' : '' ?>>
                    ✝ Verstorben
                </label>
                <div style="flex: 1;">
                    <label for="death_year">Todesjahr</label>
                    <input type="number" id="death_year" name="death_year" min="1700" max="<?= date('Y') ?>" class="form-control" value="<?= htmlspecialchars((string)($horse['death_year'] ?? '')) ?>">
                </div>
            </div>
            <p style="color: var(--text-subtle); font-size: 0.8rem; margin: 0.3rem 0 0 0;">
                Ein eingetragenes Todesjahr setzt "Verstorben" automatisch.
            </p>
        </div>

        <div class="form-group">
            <label style="display: flex; align-items: center; gap: 0.5rem; <?= $canPublish ? 'cursor: pointer;' : 'opacity: 0.6;' ?>">
                <input
                    type="checkbox"
                    name="is_published"
                    value="1"
                    style="width: auto;"
                    <?= !empty($horse['is_published']) ? 'checked' : '' ?>
                    <?= $canPublish ? '' : 'disabled' ?>
                >
                Im öffentlichen Katalog veröffentlichen
            </label>
            <?php if (!$canPublish): ?>
                <p style="color: var(--text-subtle); font-size: 0.8rem; margin: 0.3rem 0 0 0;">
                    Ihnen fehlt die Berechtigung "Veröffentlichen" - die öffentliche Sichtbarkeit kann daher nicht geändert werden.
                </p>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label for="description">Beschreibung / Zuchthinweise</label>
            <textarea id="description" name="description" class="form-control" rows="5"><?= htmlspecialchars($horse['description'] ?? '') ?></textarea>
        </div>

        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
            <button type="submit" class="btn">Speichern</button>
            <a href="/admin/horses" class="btn btn-secondary">Abbrechen</a>
        </div>
    </form>
</div>

<?php
// Medien je Pferd (#339): Fotos und Video-Links, Hauptbild, Reihenfolge.
//
// AUSSERHALB des Formulars oben - verschachtelte <form> sind ungültiges HTML,
// und jeder Knopf hier schickt für sich ab. Wer ein Medium hinzufügt,
// verliert damit keine ungespeicherten Stammdaten; deshalb steht der Hinweis
// darüber. Nur beim Bearbeiten: Ein Pferd, das es noch nicht gibt, hat keine
// Medien.
$medienMeldungen = [
    'media_added' => ['ok', 'Medium hinzugefügt.'],
    'media_deleted' => ['ok', 'Medium gelöscht.'],
    'media_main' => ['ok', 'Hauptbild gewählt.'],
    'media_invalid' => ['fehler', 'Nicht gespeichert: entweder eine Bilddatei (JPG/PNG/WEBP/GIF, max. 5 MB) oder ein http(s)-Video-Link.'],
];
$medienMarker = (string)($_GET['media'] ?? '');
?>
<?php if ($isEdit): ?>
<div class="card" style="margin-top: 1.5rem;">
    <h3 style="margin-top: 0;">🖼️ Fotos und Videos</h3>

    <?php if (isset($medienMeldungen[$medienMarker])): ?>
        <?php [$medienArt, $medienText] = $medienMeldungen[$medienMarker]; ?>
        <div style="background-color: var(--<?= $medienArt === 'ok' ? 'success' : 'danger' ?>-soft-bg); color: var(--<?= $medienArt === 'ok' ? 'success' : 'danger' ?>-fg); padding: 0.8rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars($medienText) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($horseMedia)): ?>
        <p style="color: var(--text-muted);">Für dieses Pferd sind noch keine Medien erfasst.</p>
    <?php else: ?>
        <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead><tr style="text-align: left; border-bottom: 2px solid var(--border-color);">
                <th style="padding: 0.4rem 0.6rem 0.4rem 0;">Vorschau</th>
                <th style="padding: 0.4rem 0.6rem 0.4rem 0;">Art</th>
                <th style="padding: 0.4rem 0.6rem 0.4rem 0;">Bildunterschrift</th>
                <th style="padding: 0.4rem 0.6rem 0.4rem 0;">Reihenfolge</th>
                <th style="padding: 0.4rem 0;">Aktion</th>
            </tr></thead>
            <tbody>
            <?php foreach ($horseMedia as $medium): ?>
                <tr style="border-bottom: 1px solid var(--border-color);">
                    <td style="padding: 0.4rem 0.6rem 0.4rem 0;">
                        <?php if ($medium['type'] === 'image'): ?>
                            <img src="<?= htmlspecialchars(App\Helper\MediaUrl::horseMediaImage((int)$medium['id']) ?? '') ?>"
                                 alt="" loading="lazy" decoding="async"
                                 style="width: 64px; height: 64px; object-fit: cover; border-radius: 4px; border: 1px solid var(--border-color);">
                        <?php else: ?>
                            <span style="font-size: 1.5rem;" aria-hidden="true">🎬</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 0.4rem 0.6rem 0.4rem 0;">
                        <?= $medium['type'] === 'image' ? 'Bild' : 'Video' ?>
                        <?php if (!empty($medium['is_main'])): ?>
                            <br><span style="font-size: 0.8rem; color: var(--success-fg); font-weight: 600;">★ Hauptbild</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 0.4rem 0.6rem 0.4rem 0;"><?= htmlspecialchars((string)($medium['caption'] ?? '')) ?></td>
                    <td style="padding: 0.4rem 0.6rem 0.4rem 0;"><?= (int)$medium['sort_order'] ?></td>
                    <td style="padding: 0.4rem 0; display: flex; gap: 0.4rem; flex-wrap: wrap;">
                        <?php if ($medium['type'] === 'image' && empty($medium['is_main'])): ?>
                            <form method="POST" action="/admin/horses/media/main" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                <input type="hidden" name="horse_id" value="<?= (int)$horse['id'] ?>">
                                <input type="hidden" name="media_id" value="<?= (int)$medium['id'] ?>">
                                <button type="submit" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.85rem;">Als Hauptbild</button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" action="/admin/horses/media/delete" style="margin: 0;"
                              data-confirm="Medium wirklich löschen? Eine hochgeladene Datei wird dabei mit entfernt.">
                            <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                            <input type="hidden" name="horse_id" value="<?= (int)$horse['id'] ?>">
                            <input type="hidden" name="media_id" value="<?= (int)$medium['id'] ?>">
                            <button type="submit" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.85rem; color: var(--danger-fg);">Löschen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <form method="POST" action="/admin/horses/media/add" enctype="multipart/form-data" style="margin-top: 1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        <input type="hidden" name="horse_id" value="<?= (int)$horse['id'] ?>">
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 0;">
            Entweder eine Bilddatei hochladen <strong>oder</strong> einen Video-Link angeben.
            Wird beides ausgefüllt, gewinnt der Upload und der Link wird verworfen.
            Änderungen an den Stammdaten oben bitte zuerst speichern.
        </p>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem;">
            <div class="form-group">
                <label for="media_image">Bilddatei (max. 5 MB)</label>
                <input type="file" id="media_image" name="media_image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
            </div>
            <div class="form-group">
                <label for="media_video">Video-Link</label>
                <input type="url" id="media_video" name="video_url" class="form-control" placeholder="https://www.youtube.com/watch?v=…">
            </div>
            <div class="form-group">
                <label for="media_caption">Bildunterschrift</label>
                <input type="text" id="media_caption" name="caption" class="form-control" maxlength="255">
            </div>
            <div class="form-group">
                <label for="media_sort">Reihenfolge</label>
                <input type="number" id="media_sort" name="sort_order" class="form-control" value="<?= (count($horseMedia ?? []) + 1) * 10 ?>">
            </div>
        </div>
        <button type="submit" class="btn">Medium hinzufügen</button>
    </form>
</div>
<?php endif; ?>

<?php
// Plugin-Abschnitte (#255, Hook horse.edit_sections, gefüllt in
// HorseController::edit()). Bewusst AUSSERHALB des Formulars oben:
// Verschachtelte <form> sind ungültiges HTML, und die Abnehmer dieses Hooks
// brauchen eigene Formulare (je Zeile ein Löschen-Knopf, bei der Galerie
// zusätzlich ein Datei-Upload). Lägen die Abschnitte innerhalb, müssten
// Addons ihre Daten über den Kern-POST speichern - der aber nur horses.edit
// geprüft hat und nie die Plugin-Berechtigung. So bleibt jeder Schreibvorgang
// beim Plugin-Controller mit dessen requirePermission().
//
// Je Abschnitt eine eigene Karte, damit sichtbar bleibt, welcher Knopf zu
// welchem Block gehört. Keine gemeinsame Kern-Überschrift: jedes Addon
// benennt seinen Abschnitt selbst (und beginnt wie bei horse.detail_sections
// mit <h3>). Beim Anlegen ist die Variable nicht gesetzt - der Hook feuert
// dort nicht, siehe Begründung im Controller.
//
// Ausgabe unescaped: Der Filter liefert fertiges Abschnitts-HTML, Plugins
// escapen selbst (siehe docs/plugin-development.md).
?>
<?php foreach (($pluginEditSections ?? []) as $pluginEditSection): ?>
    <div class="card" style="margin-top: 1.5rem;">
        <?= $pluginEditSection ?>
    </div>
<?php endforeach; ?>
