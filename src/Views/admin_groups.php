<?php
// src/Views/admin_groups.php
/**
 * @var array $groups Zeilen aus `groups`
 * @var array $permissions [group_id => [module => [action => true]]]
 * @var array $modules App\Permission\PermissionRegistry::MODULES
 */

$errorMessages = [
    'name_required' => 'Bitte einen Namen für die neue Gruppe angeben.',
    'invalid_slug' => 'Ungültiger oder reservierter Gruppenname.',
    'slug_taken' => 'Eine Gruppe mit diesem Namen existiert bereits.',
    'cannot_delete_builtin' => 'Eingebaute Gruppen (Admin/Editor/Öffentlich) können nicht gelöscht werden.',
    'protected_group' => 'Die Berechtigungen dieser Gruppe können nicht verändert werden.',
    'unknown_group' => 'Unbekannte Gruppe.',
    'save_failed' => 'Speichern fehlgeschlagen. Bitte erneut versuchen.',
];
?>
<div style="display: flex; flex-direction: column; gap: 1.5rem; max-width: 1000px; margin: 0 auto;">

    <div class="card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <h2 style="margin: 0;">👥 Gruppen & Berechtigungen</h2>
        <a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="card" style="background-color: #d4edda; color: #155724;">Aktion erfolgreich ausgeführt.</div>
    <?php endif; ?>
    <?php if (isset($_GET['error']) && isset($errorMessages[$_GET['error']])): ?>
        <div class="card" style="background-color: #f8d7da; color: #721c24;"><?= htmlspecialchars($errorMessages[$_GET['error']]) ?></div>
    <?php endif; ?>

    <div class="card">
        <p style="color: #666; font-size: 0.9rem; margin-bottom: 1rem;">
            <strong>Admin</strong> hat immer alle Rechte (fest, nicht einschränkbar).
            <strong>Editor</strong> hat standardmäßig alle Rechte wie bisher, kann hier
            aber granular eingeschränkt werden. <strong>Öffentlich / Gäste</strong> kann
            aus Sicherheitsgründen keine schreibenden Berechtigungen erhalten. Zusätzliche
            eigene Gruppen können unten angelegt und Benutzern im
            <a href="/admin/users">Benutzer-Formular</a> zugeordnet werden.
        </p>

        <form action="/admin/groups/create" method="POST" style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end; border-top: 1px solid #eee; padding-top: 1rem;">
            <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
            <div class="form-group" style="margin: 0; flex: 1; min-width: 180px;">
                <label for="name">Neue Gruppe: Name</label>
                <input type="text" id="name" name="name" class="form-control" placeholder="z. B. Mitglieder" required>
            </div>
            <div class="form-group" style="margin: 0; flex: 2; min-width: 220px;">
                <label for="description">Beschreibung (optional)</label>
                <input type="text" id="description" name="description" class="form-control" placeholder="Kurze Beschreibung">
            </div>
            <button type="submit" class="btn">Gruppe anlegen</button>
        </form>
    </div>

    <?php foreach ($groups as $group): ?>
        <?php
            $groupId = (int)$group['id'];
            $isProtected = in_array($group['slug'], ['admin', 'public'], true);
            $groupPermissions = $permissions[$groupId] ?? [];
        ?>
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.5rem;">
                <div>
                    <h3 style="margin: 0;">
                        <?= htmlspecialchars($group['name']) ?>
                        <?php if ($group['is_builtin']): ?>
                            <span style="padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; background-color: #e2e3e5; color: #383d41; font-weight: 600; vertical-align: middle;">Eingebaut</span>
                        <?php endif; ?>
                    </h3>
                    <?php if (!empty($group['description'])): ?>
                        <p style="margin: 0.2rem 0 0 0; color: #666; font-size: 0.85rem;"><?= htmlspecialchars($group['description']) ?></p>
                    <?php endif; ?>
                </div>
                <?php if (!$group['is_builtin']): ?>
                    <form action="/admin/groups/delete" method="POST" onsubmit="return confirm('Gruppe \'<?= htmlspecialchars(addslashes($group['name'])) ?>\' wirklich löschen? Benutzer verlieren dadurch alle über diese Gruppe erhaltenen Berechtigungen.');">
                        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                        <input type="hidden" name="id" value="<?= $groupId ?>">
                        <button type="submit" class="btn" style="padding: 0.3rem 0.7rem; font-size: 0.85rem; background-color: #dc3545;">Gruppe löschen</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($group['slug'] === 'admin'): ?>
                <p style="color: #666; font-size: 0.85rem;">✅ Hat systemseitig fest immer alle Berechtigungen - keine Konfiguration nötig oder möglich.</p>
            <?php elseif ($group['slug'] === 'public'): ?>
                <p style="color: #666; font-size: 0.85rem;">🚫 Kann aus Sicherheitsgründen keine schreibenden Berechtigungen erhalten.</p>
            <?php endif; ?>

            <form action="/admin/groups/permissions" method="POST">
                <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                <input type="hidden" name="group_id" value="<?= $groupId ?>">

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-top: 0.5rem;">
                    <?php foreach ($modules as $moduleKey => $moduleDef): ?>
                        <div style="border: 1px solid #eee; border-radius: 6px; padding: 0.7rem;">
                            <strong style="font-size: 0.9rem;"><?= htmlspecialchars($moduleDef['label']) ?></strong>
                            <div style="margin-top: 0.4rem; display: flex; flex-direction: column; gap: 0.3rem;">
                                <?php foreach ($moduleDef['actions'] as $actionKey => $actionLabel): ?>
                                    <?php $isChecked = !empty($groupPermissions[$moduleKey][$actionKey]) || ($group['slug'] === 'admin'); ?>
                                    <label style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; font-weight: normal; <?= $isProtected ? 'opacity: 0.6;' : 'cursor: pointer;' ?>">
                                        <input
                                            type="checkbox"
                                            name="permissions[<?= htmlspecialchars($moduleKey) ?>][]"
                                            value="<?= htmlspecialchars($actionKey) ?>"
                                            style="width: auto;"
                                            <?= $isChecked ? 'checked' : '' ?>
                                            <?= $isProtected ? 'disabled' : '' ?>
                                        >
                                        <?= htmlspecialchars($actionLabel) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!$isProtected): ?>
                    <button type="submit" class="btn" style="margin-top: 1rem;">Berechtigungen speichern</button>
                <?php endif; ?>
            </form>
        </div>
    <?php endforeach; ?>

</div>
