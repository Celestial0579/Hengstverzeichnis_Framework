<?php
// src/Views/admin_horse_form.php
/**
 * @var array|null $horse
 * @var array $allHorses
 * @var string $title
 */
$isEdit = !empty($horse);
$actionUrl = $isEdit ? '/admin/horses/update' : '/admin/horses/store';
?>
<div class="card">
    <h2><?= htmlspecialchars($title) ?></h2>

    <form action="<?= $actionUrl ?>" method="POST" enctype="multipart/form-data" style="max-width: 700px; margin-top: 1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        
        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= $horse['id'] ?>">
        <?php endif; ?>

        <!-- Foto-Upload -->
        <div class="form-group" style="background: #f8f9fa; padding: 1rem; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 1.5rem;">
            <label for="horse_image" style="font-weight: bold; color: var(--primary-color);">📷 Foto des Pferdes hochladen</label>
            
            <?php if (!empty($horse['image_url'])): ?>
                <div style="display: flex; align-items: center; gap: 1rem; margin: 0.8rem 0;">
                    <img src="<?= htmlspecialchars($horse['image_url']) ?>" alt="Pferdefoto" style="width: 100px; height: 100px; object-fit: cover; border-radius: 8px; border: 1px solid #ccc;">
                    <div>
                        <label style="color: #dc3545; font-size: 0.9rem; cursor: pointer;">
                            <input type="checkbox" name="remove_image" value="1"> 🗑️ Vorhandenes Foto entfernen
                        </label>
                    </div>
                </div>
            <?php endif; ?>

            <input type="file" id="horse_image" name="horse_image" accept="image/jpeg,image/png,image/webp" class="form-control">
            <small style="color: #666; display: block; margin-top: 0.3rem;">Erlaubte Formate: JPG, PNG, WEBP (Max. 5 MB).</small>
        </div>
        
        <div class="form-group">
            <label for="name">Name des Pferdes *</label>
            <input type="text" id="name" name="name" class="form-control" value="<?= htmlspecialchars($horse['name'] ?? '') ?>" required>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
            <div class="form-group" style="margin-bottom: 0;">
                <label for="ueln">UELN (Haupt-Lebensnummer / Deutschland)</label>
                <input type="text" id="ueln" name="ueln" class="form-control" value="<?= htmlspecialchars($horse['ueln'] ?? '') ?>" placeholder="z. B. DE 434340123418">
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label for="foreign_ueln">Lebensnummer Ursprungsland (Ausländische UELN)</label>
                <input type="text" id="foreign_ueln" name="foreign_ueln" class="form-control" value="<?= htmlspecialchars($horse['foreign_ueln'] ?? '') ?>" placeholder="z. B. NLD003201801234 / FRA...">
            </div>
        </div>

        <!-- Abstammung: Vater (Sire) -->
        <fieldset style="border: 1px solid #ddd; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; background: #fafafa;">
            <legend style="padding: 0 0.5rem; font-weight: bold; color: var(--primary-color);">♂ Vater (Sire)</legend>
            
            <div class="form-group">
                <label for="sire_id">Existierendes Pferd aus der Datenbank wählen:</label>
                <select id="sire_id" name="sire_id" class="form-control">
                    <option value="">-- Nicht verknüpft / Text-Eingabe nutzen --</option>
                    <?php foreach ($allHorses as $h): ?>
                        <?php if ($isEdit && $h['id'] == $horse['id']) continue; ?>
                        <option value="<?= $h['id'] ?>" <?= ($horse['sire_id'] ?? '') == $h['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($h['name']) ?> <?= $h['birth_year'] ? '(' . $h['birth_year'] . ')' : '' ?> <?= $h['ueln'] ? '[' . $h['ueln'] . ']' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="font-size: 0.85rem; color: #777; text-align: center; margin: 0.5rem 0;">— ODER falls nicht in der Datenbank vorhanden —</div>

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
        <fieldset style="border: 1px solid #ddd; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; background: #fafafa;">
            <legend style="padding: 0 0.5rem; font-weight: bold; color: var(--primary-color);">♀ Mutter (Dam)</legend>
            
            <div class="form-group">
                <label for="dam_id">Existierendes Pferd aus der Datenbank wählen:</label>
                <select id="dam_id" name="dam_id" class="form-control">
                    <option value="">-- Nicht verknüpft / Text-Eingabe nutzen --</option>
                    <?php foreach ($allHorses as $h): ?>
                        <?php if ($isEdit && $h['id'] == $horse['id']) continue; ?>
                        <option value="<?= $h['id'] ?>" <?= ($horse['dam_id'] ?? '') == $h['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($h['name']) ?> <?= $h['birth_year'] ? '(' . $h['birth_year'] . ')' : '' ?> <?= $h['ueln'] ? '[' . $h['ueln'] . ']' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="font-size: 0.85rem; color: #777; text-align: center; margin: 0.5rem 0;">— ODER falls nicht in der Datenbank vorhanden —</div>

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
                <label for="birth_year">Geburtsjahr</label>
                <input type="number" id="birth_year" name="birth_year" min="1700" max="<?= date('Y') + 1 ?>" class="form-control" value="<?= htmlspecialchars((string)($horse['birth_year'] ?? '')) ?>">
            </div>
            
            <div class="form-group" style="flex: 1;">
                <label for="color">Farbe</label>
                <input type="text" id="color" name="color" class="form-control" value="<?= htmlspecialchars($horse['color'] ?? '') ?>">
            </div>
        </div>

        <!-- Personen, Besitzer & Deckstationenverlauf -->
        <div class="form-group" style="background: #fdfdfd; padding: 1.2rem; border-radius: 8px; border: 1px solid #ddd; margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.8rem; flex-wrap: wrap; gap: 0.5rem;">
                <label style="font-weight: bold; color: var(--primary-color); margin-bottom: 0;">👤 Züchter-, Besitzer- & Deckstationenverlauf</label>
                <div style="display: flex; gap: 1rem; font-size: 0.85rem;">
                    <a href="/admin/persons/create" target="_blank" style="color: var(--primary-color);">+ Neue Person anlegen</a>
                    <a href="/admin/breeding-stations/create" target="_blank" style="color: var(--primary-color);">+ Neue Deckstation anlegen</a>
                </div>
            </div>
            
            <div id="persons_container" style="display: flex; flex-direction: column; gap: 0.8rem;">
                <?php if (empty($horsePersons)): ?>
                    <!-- Initial empty row if none -->
                    <div class="person-row" style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; background: #f8f9fa; padding: 0.8rem; border-radius: 6px; border: 1px solid #eee;">
                        <div style="flex: 2; min-width: 180px;">
                            <select name="persons[0][person_id]" class="form-control">
                                <option value="">-- Person (Züchter/Besitzer) --</option>
                                <?php foreach ($allPersons as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
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
                            <select name="persons[0][breeding_station_id]" class="form-control">
                                <option value="">-- Deckstation / Gestüt (Optional) --</option>
                                <?php foreach ($allBreedingStations as $bs): ?>
                                    <option value="<?= $bs['id'] ?>"><?= htmlspecialchars($bs['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="year-inputs" style="display: flex; gap: 0.4rem; flex: 1.8; min-width: 160px;">
                            <input type="number" name="persons[0][from_year]" placeholder="Von (Jahr)" class="form-control" style="flex: 1;" min="1700" max="<?= date('Y') + 1 ?>">
                            <input type="number" name="persons[0][until_year]" placeholder="Bis (Jahr)" class="form-control" style="flex: 1;" min="1700" max="<?= date('Y') + 1 ?>">
                        </div>

                        <button type="button" class="btn" style="background: #dc3545; color: #fff; padding: 0.4rem 0.6rem;" onclick="this.closest('.person-row').remove();">🗑️</button>
                    </div>
                <?php else: ?>
                    <?php foreach ($horsePersons as $idx => $hp): ?>
                        <div class="person-row" style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; background: #f8f9fa; padding: 0.8rem; border-radius: 6px; border: 1px solid #eee;">
                            <div style="flex: 2; min-width: 180px;">
                                <select name="persons[<?= $idx ?>][person_id]" class="form-control">
                                    <option value="">-- Person (Züchter/Besitzer) --</option>
                                    <?php foreach ($allPersons as $p): ?>
                                        <option value="<?= $p['id'] ?>" <?= $hp['person_id'] == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
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
                                <select name="persons[<?= $idx ?>][breeding_station_id]" class="form-control">
                                    <option value="">-- Deckstation / Gestüt (Optional) --</option>
                                    <?php foreach ($allBreedingStations as $bs): ?>
                                        <option value="<?= $bs['id'] ?>" <?= ($hp['breeding_station_id'] ?? '') == $bs['id'] ? 'selected' : '' ?>><?= htmlspecialchars($bs['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
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

        function toggleYears(selectElem) {
            const row = selectElem.closest('.person-row');
            const yearInputs = row.querySelector('.year-inputs');
            if (selectElem.value === 'breeder') {
                yearInputs.style.display = 'none';
            } else {
                yearInputs.style.display = 'flex';
            }
        }

        function addPersonRow() {
            const container = document.getElementById('persons_container');
            const div = document.createElement('div');
            div.className = 'person-row';
            div.style = 'display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; background: #f8f9fa; padding: 0.8rem; border-radius: 6px; border: 1px solid #eee;';
            div.innerHTML = `
                <div style="flex: 2; min-width: 180px;">
                    <select name="persons[${personRowIndex}][person_id]" class="form-control">
                        <option value="">-- Person (Züchter/Besitzer) --</option>
                        <?php foreach ($allPersons as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars(addslashes($p['name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex: 1.5; min-width: 140px;">
                    <select name="persons[${personRowIndex}][role]" class="form-control" onchange="toggleYears(this)">
                        <option value="breeder">Züchter</option>
                        <option value="owner" selected>Besitzer</option>
                        <option value="keeper">Halter / Deckstation</option>
                    </select>
                </div>
                <div style="flex: 2; min-width: 180px;">
                    <select name="persons[${personRowIndex}][breeding_station_id]" class="form-control">
                        <option value="">-- Deckstation / Gestüt (Optional) --</option>
                        <?php foreach ($allBreedingStations as $bs): ?>
                            <option value="<?= $bs['id'] ?>"><?= htmlspecialchars(addslashes($bs['name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="year-inputs" style="display: flex; gap: 0.4rem; flex: 1.8; min-width: 160px;">
                    <input type="number" name="persons[${personRowIndex}][from_year]" placeholder="Von (Jahr)" class="form-control" style="flex: 1;" min="1700" max="<?= date('Y') + 1 ?>">
                    <input type="number" name="persons[${personRowIndex}][until_year]" placeholder="Bis (Jahr)" class="form-control" style="flex: 1;" min="1700" max="<?= date('Y') + 1 ?>">
                </div>
                <button type="button" class="btn" style="background: #dc3545; color: #fff; padding: 0.4rem 0.6rem;" onclick="this.closest('.person-row').remove();">🗑️</button>
            `;
            container.appendChild(div);
            personRowIndex++;
        }
        </script>

        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status" class="form-control">
                <option value="active" <?= ($horse['status'] ?? '') === 'active' ? 'selected' : '' ?>>Aktiv (Gekört)</option>
                <option value="inactive" <?= ($horse['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inaktiv</option>
                <option value="deceased" <?= ($horse['status'] ?? '') === 'deceased' ? 'selected' : '' ?>>Verstorben</option>
            </select>
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
