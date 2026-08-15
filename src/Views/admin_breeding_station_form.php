<?php
// src/Views/admin_breeding_station_form.php
/**
 * @var array|null $station
 * @var array|null $errors
 * @var array|null $old
 * @var string $title
 * @var bool|null $canPublish
 */
$canPublish = $canPublish ?? false;
$isEdit = !empty($station['id']);
$actionUrl = $isEdit ? '/admin/breeding-stations/update' : '/admin/breeding-stations/store';
?>
<div class="card" style="max-width: 650px;">
    <h2><?= htmlspecialchars($title) ?></h2>

    <?php if (!empty($errors)): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-top: 1rem;">
            <ul style="margin-left: 1.2rem;">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form action="<?= $actionUrl ?>" method="POST" style="margin-top: 1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        
        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= $station['id'] ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="name">Name der Deckstation / Gestüt *</label>
            <input type="text" id="name" name="name" class="form-control" value="<?= htmlspecialchars($old['name'] ?? $station['name'] ?? '') ?>" placeholder="z. B. Gestüt Fjordblick" required>
        </div>

        <div class="form-group">
            <label for="contact_person">Ansprechpartner / Leiter</label>
            <input type="text" id="contact_person" name="contact_person" class="form-control" value="<?= htmlspecialchars($old['contact_person'] ?? $station['contact_person'] ?? '') ?>" placeholder="z. B. Max Mustermann">
        </div>

        <?php
            // Strukturierte Adresse (#256). Vorher gab es hier nur ein einziges
            // Freitextfeld - damit liessen sich Stationen weder nach Ort noch
            // nach Bundesland/Kanton einordnen oder filtern.
        ?>
        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 3;">
                <label for="street">Straße</label>
                <input type="text" id="street" name="street" class="form-control" value="<?= htmlspecialchars($old['street'] ?? $station['street'] ?? '') ?>" placeholder="z. B. Weideweg">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="house_number">Hausnr.</label>
                <input type="text" id="house_number" name="house_number" class="form-control" value="<?= htmlspecialchars($old['house_number'] ?? $station['house_number'] ?? '') ?>" placeholder="1">
            </div>
        </div>

        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 1;">
                <label for="postal_code">PLZ</label>
                <input type="text" id="postal_code" name="postal_code" class="form-control" value="<?= htmlspecialchars($old['postal_code'] ?? $station['postal_code'] ?? '') ?>" placeholder="24000">
            </div>
            <div class="form-group" style="flex: 2;">
                <label for="city">Ort</label>
                <input type="text" id="city" name="city" class="form-control" value="<?= htmlspecialchars($old['city'] ?? $station['city'] ?? '') ?>" placeholder="Kiel">
            </div>
        </div>

        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 2;">
                <label for="state">Bundesland / Kanton</label>
                <input type="text" id="state" name="state" class="form-control" value="<?= htmlspecialchars($old['state'] ?? $station['state'] ?? '') ?>" placeholder="z. B. Schleswig-Holstein, Bern, Tirol">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="country">Land</label>
                <input type="text" id="country" name="country" class="form-control" value="<?= htmlspecialchars($old['country'] ?? $station['country'] ?? '') ?>" placeholder="z. B. DE, NO">
            </div>
        </div>

        <?php
            // Das alte Freitextfeld bleibt bestehen und bearbeitbar: Der
            // Altbestand steht dort mehrzeilig drin und wird bewusst NICHT
            // automatisch zerlegt (das wäre geraten). Wer die Angaben oben
            // eingetragen hat, leert es hier von Hand - solange es befüllt ist,
            // zeigt die öffentliche Seite es weiterhin an.
            $hasLegacyAddress = trim((string)($old['address'] ?? $station['address'] ?? '')) !== '';
        ?>
        <div class="form-group">
            <label for="address">Adresse als Freitext<?= $hasLegacyAddress ? ' (Altbestand)' : '' ?></label>
            <textarea id="address" name="address" class="form-control" rows="2" placeholder="Nur noch für Altbestand - bitte die Felder oben verwenden"><?= htmlspecialchars($old['address'] ?? $station['address'] ?? '') ?></textarea>
            <?php if ($hasLegacyAddress): ?>
                <p style="color: var(--text-subtle); font-size: 0.8rem; margin: 0.3rem 0 0 0;">
                    Diese Angabe stammt aus der Zeit vor den Einzelfeldern. Bitte oben eintragen und dieses Feld danach leeren - solange hier etwas steht, wird es öffentlich angezeigt.
                </p>
            <?php endif; ?>
        </div>

        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 1;">
                <label for="phone">Telefonnummer</label>
                <input type="text" id="phone" name="phone" class="form-control" value="<?= htmlspecialchars($old['phone'] ?? $station['phone'] ?? '') ?>" placeholder="+49 123 456789">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="email">E-Mail-Adresse</label>
                <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($old['email'] ?? $station['email'] ?? '') ?>" placeholder="info@gestuet.de">
            </div>
        </div>

        <div class="form-group">
            <label for="website">Website (URL)</label>
            <input type="url" id="website" name="website" class="form-control" value="<?= htmlspecialchars($old['website'] ?? $station['website'] ?? '') ?>" placeholder="https://www.gestuet-beispiel.de">
        </div>

        <?php if ($canPublish): ?>
        <div class="form-group">
            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                <input type="checkbox" name="is_published" value="1" <?= !empty($old['is_published'] ?? $station['is_published'] ?? 0) ? 'checked' : '' ?>>
                <span>🌐 Öffentlich sichtbar (Detailseite & Katalog-Filter)</span>
            </label>
            <small style="color: var(--text-muted);">Ohne Häkchen bleibt die Deckstation unveröffentlicht und ist öffentlich nicht erreichbar.</small>
        </div>
        <?php endif; ?>

        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
            <button type="submit" class="btn">Speichern</button>
            <a href="/admin/breeding-stations" class="btn btn-secondary">Abbrechen</a>
        </div>
    </form>
</div>
