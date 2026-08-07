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
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-top: 1rem;">
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

        <div class="form-group">
            <label for="contact_info">Kontaktinformationen & Standort (Adresse, E-Mail, Telefon)</label>
            <textarea id="contact_info" name="contact_info" class="form-control" rows="4" placeholder="Musterstraße 12, 12345 Musterstadt&#10;E-Mail: kontakt@example.de&#10;Tel: 01234-56789"><?= htmlspecialchars($old['contact_info'] ?? $person['contact_info'] ?? '') ?></textarea>
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
