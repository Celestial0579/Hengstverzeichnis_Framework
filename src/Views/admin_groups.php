<?php
// src/Views/admin_groups.php
/**
 * @var array $groups Zeilen aus `groups`
 * @var array $permissions [group_id => [module => [action => true]]]
 * @var array $modules App\Permission\PermissionRegistry::MODULES
 * @var int $selectedGroupId Aktuell zur Bearbeitung ausgewählte Gruppe
 * @var int $totalPermissionCount Gesamtzahl aller Modul/Aktion-Kombinationen (für die Zusammenfassung)
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

$groupsById = [];
foreach ($groups as $g) {
    $groupsById[(int)$g['id']] = $g;
}
$selected = $groupsById[$selectedGroupId] ?? null;
$isProtected = $selected && in_array($selected['slug'], ['admin', 'public'], true);
$selectedPermissions = $selected ? ($permissions[(int)$selected['id']] ?? []) : [];

/**
 * Kurze Zusammenfassung der Berechtigungen einer Gruppe für die Übersichtstabelle.
 */
function summarizeGroupPermissions(array $group, array $permissions, int $totalCount): string {
    if ($group['slug'] === 'admin') {
        return 'Alle (fest)';
    }
    $count = 0;
    foreach ($permissions as $actions) {
        $count += count($actions);
    }
    if ($count === 0) {
        return 'Keine';
    }
    return "{$count} von {$totalCount}";
}
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

    <!-- Kompakte Übersicht aller Gruppen -->
    <div class="card">
        <p style="color: #666; font-size: 0.9rem; margin-bottom: 1rem;">
            <strong>Admin</strong> hat immer alle Rechte (fest, nicht einschränkbar).
            <strong>Editor</strong> hat standardmäßig alle Rechte wie bisher, kann unten
            aber granular eingeschränkt werden. <strong>Öffentlich / Gäste</strong> steht
            für nicht angemeldete Besucher und erhält niemals Zugriff auf das Backend
            oder irgendeine Berechtigung - unabhängig vom Gruppensystem bereits durch
            den bestehenden Login-Zwang abgesichert.
        </p>

        <table style="width: 100%; border-collapse: collapse; margin-bottom: 1.5rem;">
            <thead>
                <tr style="border-bottom: 2px solid #eee; text-align: left;">
                    <th style="padding: 0.4rem;">Gruppe</th>
                    <th style="padding: 0.4rem;">Typ</th>
                    <th style="padding: 0.4rem;">Berechtigungen</th>
                    <th style="padding: 0.4rem;">Aktion</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $group): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 0.4rem;">
                            <strong><?= htmlspecialchars($group['name']) ?></strong>
                            <?php if (!empty($group['description'])): ?>
                                <br><span style="color: #888; font-size: 0.8rem;"><?= htmlspecialchars($group['description']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.4rem; font-size: 0.85rem; color: #666;">
                            <?= $group['is_builtin'] ? 'Eingebaut' : 'Eigene Gruppe' ?>
                        </td>
                        <td style="padding: 0.4rem; font-size: 0.85rem;">
                            <?= htmlspecialchars(summarizeGroupPermissions($group, $permissions[(int)$group['id']] ?? [], $totalPermissionCount)) ?>
                        </td>
                        <td style="padding: 0.4rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <a href="/admin/groups?group=<?= (int)$group['id'] ?>" class="btn btn-secondary" style="padding: 0.2rem 0.6rem; font-size: 0.85rem;">
                                <?= (int)$group['id'] === $selectedGroupId ? 'Ausgewählt' : 'Bearbeiten' ?>
                            </a>
                            <?php if (!$group['is_builtin']): ?>
                                <form action="/admin/groups/delete" method="POST" onsubmit="return confirm('Gruppe \'<?= htmlspecialchars(addslashes($group['name'])) ?>\' wirklich löschen? Benutzer verlieren dadurch alle über diese Gruppe erhaltenen Berechtigungen.');">
                                    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                    <input type="hidden" name="id" value="<?= (int)$group['id'] ?>">
                                    <button type="submit" class="btn" style="padding: 0.2rem 0.6rem; font-size: 0.85rem; background-color: #dc3545;">Löschen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

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

    <!-- Berechtigungen der ausgewählten Gruppe -->
    <div class="card">
        <form action="/admin/groups" method="GET" style="margin-bottom: 1rem;">
            <div class="form-group" style="margin: 0; max-width: 400px;">
                <label for="group-select">Gruppe zur Bearbeitung auswählen</label>
                <select id="group-select" name="group" class="form-control" onchange="this.form.submit()">
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= (int)$group['id'] ?>" <?= (int)$group['id'] === $selectedGroupId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($group['name']) ?><?= $group['is_builtin'] ? ' (eingebaut)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if (!$selected): ?>
            <p style="color: #888;">Keine Gruppe ausgewählt.</p>
        <?php else: ?>
            <h3 style="margin-top: 0;">
                <?= htmlspecialchars($selected['name']) ?>
                <?php if ($selected['is_builtin']): ?>
                    <span style="padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; background-color: #e2e3e5; color: #383d41; font-weight: 600; vertical-align: middle;">Eingebaut</span>
                <?php endif; ?>
            </h3>

            <?php if ($selected['slug'] === 'admin'): ?>
                <p style="color: #666; font-size: 0.85rem;">✅ Hat systemseitig fest immer alle Berechtigungen - keine Konfiguration nötig oder möglich.</p>
            <?php elseif ($selected['slug'] === 'public'): ?>
                <p style="color: #666; font-size: 0.85rem;">🚫 Nicht angemeldete Besucher - erhält niemals Zugriff auf das Backend und keine Berechtigungen.</p>
            <?php elseif (!empty($selected['description'])): ?>
                <p style="color: #666; font-size: 0.85rem;"><?= htmlspecialchars($selected['description']) ?></p>
            <?php endif; ?>

            <?php if (!$isProtected && count($groups) > 1): ?>
                <!-- Berechtigungen von einer anderen Gruppe kopieren -->
                <form action="/admin/groups/copy-permissions" method="POST" onsubmit="return confirm('Berechtigungen der ausgewählten Quell-Gruppe übernehmen? Die aktuellen Berechtigungen von \'<?= htmlspecialchars(addslashes($selected['name'])) ?>\' werden dabei vollständig überschrieben.');" style="display: flex; gap: 0.5rem; align-items: flex-end; flex-wrap: wrap; background: #f8f9fa; padding: 0.8rem; border-radius: 6px; border: 1px solid #e0e0e0; margin-bottom: 1.2rem;">
                    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                    <input type="hidden" name="target_group_id" value="<?= (int)$selected['id'] ?>">
                    <div class="form-group" style="margin: 0;">
                        <label for="source-group-select" style="font-size: 0.85rem;">📋 Berechtigungen kopieren von</label>
                        <select id="source-group-select" name="source_group_id" class="form-control" required>
                            <option value="">- Quell-Gruppe wählen -</option>
                            <?php foreach ($groups as $group): ?>
                                <?php if ((int)$group['id'] === (int)$selected['id']) continue; ?>
                                <option value="<?= (int)$group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.9rem;">Kopieren</button>
                </form>
            <?php endif; ?>

            <form action="/admin/groups/permissions" method="POST">
                <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                <input type="hidden" name="group_id" value="<?= (int)$selected['id'] ?>">

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
                    <?php foreach ($modules as $moduleKey => $moduleDef): ?>
                        <div style="border: 1px solid #eee; border-radius: 6px; padding: 0.7rem;">
                            <strong style="font-size: 0.9rem;"><?= htmlspecialchars($moduleDef['label']) ?></strong>
                            <div style="margin-top: 0.4rem; display: flex; flex-direction: column; gap: 0.3rem;">
                                <?php foreach ($moduleDef['actions'] as $actionKey => $actionLabel): ?>
                                    <?php $isChecked = !empty($selectedPermissions[$moduleKey][$actionKey]) || ($selected['slug'] === 'admin'); ?>
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
        <?php endif; ?>
    </div>

</div>
