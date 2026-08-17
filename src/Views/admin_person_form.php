<?php
// src/Views/admin_person_form.php
/**
 * @var array|null $person
 * @var string|null $error
 * @var array|null $old
 * @var string $title
 * @var bool|null $canPublish
 */
$canPublish = $canPublish ?? false;
$isEdit = !empty($person);
$actionUrl = $isEdit ? '/admin/persons/update' : '/admin/persons/store';
?>
<div class="card" style="max-width: 600px;">
    <h2><?= htmlspecialchars($title) ?></h2>

    <?php if (isset($error)): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-top: 1rem;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form action="<?= $actionUrl ?>" method="POST" style="margin-top: 1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= $person['id'] ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="name">Name der Person *</label>
            <input type="text" id="name" name="name" class="form-control" value="<?= htmlspecialchars($old['name'] ?? $person['name'] ?? '') ?>" placeholder="z. B. Max Mustermann" required>
        </div>

        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 3;">
                <label for="street">Straße</label>
                <input type="text" id="street" name="street" class="form-control" value="<?= htmlspecialchars($old['street'] ?? $person['street'] ?? '') ?>" placeholder="z. B. Musterstraße">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="house_number">Hausnr.</label>
                <input type="text" id="house_number" name="house_number" class="form-control" value="<?= htmlspecialchars($old['house_number'] ?? $person['house_number'] ?? '') ?>" placeholder="12">
            </div>
        </div>

        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 1;">
                <label for="postal_code">PLZ</label>
                <input type="text" id="postal_code" name="postal_code" class="form-control" value="<?= htmlspecialchars($old['postal_code'] ?? $person['postal_code'] ?? '') ?>" placeholder="12345">
            </div>
            <div class="form-group" style="flex: 2;">
                <label for="city">Ort</label>
                <input type="text" id="city" name="city" class="form-control" value="<?= htmlspecialchars($old['city'] ?? $person['city'] ?? '') ?>" placeholder="Musterstadt">
            </div>
        </div>

        <?php
            // Bundesland/Kanton (#256) in einer eigenen Zeile mit dem Land: Die
            // Zeile darüber ist ein display:flex OHNE wrap - ein viertes Feld
            // hätte PLZ und Ort auf schmalen Bildschirmen zusammengequetscht.
        ?>
        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 2;">
                <label for="state">Bundesland / Kanton</label>
                <input type="text" id="state" name="state" class="form-control" value="<?= htmlspecialchars($old['state'] ?? $person['state'] ?? '') ?>" placeholder="z. B. Schleswig-Holstein, Bern, Tirol">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="country">Land</label>
                <input type="text" id="country" name="country" class="form-control" value="<?= htmlspecialchars($old['country'] ?? $person['country'] ?? '') ?>" placeholder="z. B. DE, NO">
            </div>
        </div>

        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 1;">
                <label for="email">E-Mail</label>
                <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($old['email'] ?? $person['email'] ?? '') ?>" placeholder="kontakt@example.de">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="membership_status">Mitgliedsstatus beim Verband</label>
                <input type="text" id="membership_status" name="membership_status" class="form-control" value="<?= htmlspecialchars($old['membership_status'] ?? $person['membership_status'] ?? '') ?>" placeholder="z. B. Mitglied / Nichtmitglied NO">
            </div>
        </div>
        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 1;">
                <label for="phone">Telefon</label>
                <input type="text" id="phone" name="phone" class="form-control" value="<?= htmlspecialchars($old['phone'] ?? $person['phone'] ?? '') ?>" placeholder="z. B. 01234 56789">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="mobile">Mobil</label>
                <input type="text" id="mobile" name="mobile" class="form-control" value="<?= htmlspecialchars($old['mobile'] ?? $person['mobile'] ?? '') ?>" placeholder="z. B. 0170 1234567">
            </div>
        </div>

        <div class="form-group">
            <label for="website">Website</label>
            <input type="text" id="website" name="website" class="form-control" value="<?= htmlspecialchars($old['website'] ?? $person['website'] ?? '') ?>" placeholder="https://www.example.de">
            <small style="color: var(--text-muted);">Wird öffentlich als Verweis angezeigt. Verlinkt werden nur Adressen mit <code>http://</code> oder <code>https://</code>.</small>
        </div>

        <p style="color: var(--text-subtle); font-size: 0.8rem; margin: -0.3rem 0 1rem 0;">
            Öffentlich sichtbar sind nur Ort, Bundesland/Kanton, Land, Mitgliedsstatus und Website -
            Straße, Hausnummer, PLZ, E-Mail, Telefon und Mobil bleiben intern.
        </p>

        <div class="form-group">
            <label for="contact_info">Interne Notiz zum Kontakt</label>
            <textarea id="contact_info" name="contact_info" class="form-control" rows="3" placeholder="Nur intern sichtbar"><?= htmlspecialchars($old['contact_info'] ?? $person['contact_info'] ?? '') ?></textarea>
            <small style="color: var(--text-muted);">
                Restfeld für alles ohne eigene Spalte. <strong>Nicht öffentlich.</strong>
                Telefon, Mobil und Website haben seit #293 eigene Felder - dieses Feld lud
                zuvor zu Telefonnummern ein und wurde zugleich öffentlich angezeigt.
            </small>
        </div>

        <div class="form-group">
            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                <input type="checkbox" name="is_breeder" value="1" <?= !empty($old['is_breeder'] ?? $person['is_breeder'] ?? 0) ? 'checked' : '' ?>>
                <span>🐴 Diese Person züchtet</span>
            </label>
            <small style="color: var(--text-muted);">
                Kennzeichnet die Person als Züchter - unabhängig davon, ob ihr schon Pferde
                zugeordnet sind. Grundlage für die Zucht-Suche; wird öffentlich angezeigt.
            </small>
        </div>

        <?php if ($canPublish): ?>
        <div class="form-group">
            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                <input type="checkbox" name="is_published" value="1" <?= !empty($old['is_published'] ?? $person['is_published'] ?? 0) ? 'checked' : '' ?>>
                <span>🌐 Öffentlich sichtbar (in Katalog-Filterlisten anzeigen)</span>
            </label>
            <small style="color: var(--text-muted);">Ohne Häkchen bleibt die Person unveröffentlicht und erscheint nicht in den öffentlichen Filtern.</small>
        </div>
        <?php endif; ?>

        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
            <button type="submit" class="btn">Speichern</button>
            <a href="/admin/persons" class="btn btn-secondary">Abbrechen</a>
        </div>
    </form>
</div>
