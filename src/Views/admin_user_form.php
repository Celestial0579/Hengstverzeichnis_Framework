<?php
// src/Views/admin_user_form.php
/**
 * @var array|null $user
 * @var array|null $errors
 * @var array|null $old
 * @var string $title
 * @var array $assignableGroups Alle zuweisbaren Gruppen (jede außer public), siehe #66
 * @var array $userGroupIds IDs der aktuell zugewiesenen Gruppen
 * @var array $apiKeys Aktive API-Schlüssel des Kontos (nur im Bearbeiten-Modus, #217)
 */
$isEdit = !empty($user);
$actionUrl = $isEdit ? '/admin/users/update' : '/admin/users/store';
$assignableGroups = $assignableGroups ?? [];
$userGroupIds = $userGroupIds ?? [];
$apiKeys = $apiKeys ?? [];
?>
<div class="card" style="max-width: 600px;">
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
            <input type="hidden" name="id" value="<?= $user['id'] ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="username">Benutzername *</label>
            <input type="text" id="username" name="username" class="form-control" value="<?= htmlspecialchars($old['username'] ?? $user['username'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label for="email">E-Mail-Adresse *</label>
            <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($old['email'] ?? $user['email'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label for="password">
                Passwort <?= $isEdit ? '(Leer lassen, um unverändert zu lassen)' : '*' ?>
            </label>
            <input type="password" id="password" name="password" class="form-control" minlength="8" <?= $isEdit ? '' : 'required' ?>>
        </div>

        <div class="form-group">
            <label>Gruppen (bestimmen die Rechte)</label>
            <p style="color: var(--text-subtle); font-size: 0.85rem; margin: 0.3rem 0 0.5rem 0;">
                Ausschließlich die hier zugewiesenen Gruppen bestimmen, was dieser Benutzer darf (siehe <a href="/admin/groups">Gruppen &amp; Berechtigungen</a>). Ohne jede Gruppe entspricht der Zugriff dem eines nicht angemeldeten Besuchers. Die Gruppe "Administrator" gewährt vollen Zugriff auf alle Funktionen.
            </p>
            <?php if (empty($assignableGroups)): ?>
                <p style="color: var(--text-subtle); font-size: 0.85rem; margin: 0.3rem 0 0 0;">
                    Noch keine Gruppen vorhanden - siehe <a href="/admin/groups">Gruppen &amp; Berechtigungen</a>.
                </p>
            <?php else: ?>
                <select name="groups[]" id="groups" class="form-control" multiple size="<?= min(8, max(3, count($assignableGroups))) ?>" style="margin-top: 0.3rem;">
                    <?php foreach ($assignableGroups as $group): ?>
                        <option value="<?= (int)$group['id'] ?>" <?= in_array((int)$group['id'], $userGroupIds, true) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($group['name']) ?><?= $group['is_builtin'] ? ' (eingebaut)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p style="color: var(--text-subtle); font-size: 0.8rem; margin: 0.3rem 0 0 0;">
                    Mehrfachauswahl: Strg/Cmd gedrückt halten und klicken (bzw. per Ziehen markieren).
                </p>
            <?php endif; ?>
        </div>

        <?php if (!$isEdit): ?>
            <div class="form-group" style="background: var(--surface-muted); padding: 0.8rem; border-radius: 6px; border: 1px solid #e0e0e0;">
                <label style="display: flex; gap: 0.6rem; align-items: center; font-weight: bold; margin: 0; cursor: pointer;">
                    <input type="checkbox" name="send_welcome_email" value="1" checked style="width: auto; height: 1.2rem; cursor: pointer;">
                    ✉️ Willkommens-E-Mail mit Zugangsdaten automatisch senden
                </label>
            </div>
        <?php endif; ?>

        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
            <button type="submit" class="btn">Speichern</button>
            <a href="/admin/users" class="btn btn-secondary">Abbrechen</a>
        </div>
    </form>
</div>

<?php if ($isEdit): ?>
    <!-- Aktive API-Schlüssel des Kontos (#217): Bei einem Kompromittierungs-
         verdacht muss ein Admin sehen können, welche Schlüssel ein Konto
         besitzt, und sie hier widerrufen können - direkt neben der Passwort-
         Neusetzung, deren Incident-Response die Aktion ergänzt. Angezeigt
         werden nur Metadaten; der Schlüsselwert selbst existiert nur als Hash
         und ist auch für Admins nicht einsehbar. -->
    <div class="card" style="max-width: 600px; margin-top: 1.5rem;">
        <h3 style="margin-top: 0;">🔑 Aktive API-Schlüssel dieses Kontos</h3>

        <?php if (($_GET['success'] ?? '') === 'api_keys_revoked'): ?>
            <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
                Alle API-Schlüssel dieses Kontos wurden widerrufen und sind sofort ungültig.
            </div>
        <?php endif; ?>

        <?php if (empty($apiKeys)): ?>
            <p style="color: var(--text-subtle); margin-bottom: 0;">Dieses Konto besitzt keine aktiven API-Schlüssel.</p>
        <?php else: ?>
            <p style="color: var(--text-subtle); font-size: 0.85rem; margin: 0.3rem 0 0.8rem 0;">
                Ein Schlüssel gewährt Zugriff auf die JSON-API mit höchstens den Rechten des Kontos.
                Eine Passwortänderung widerruft automatisch alle Schlüssel; hier geht es auch ohne, z. B. bei einem geleakten Schlüssel.
            </p>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 1rem;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-color); text-align: left;">
                        <th style="padding: 0.5rem;">Bezeichnung</th>
                        <th style="padding: 0.5rem;">Schlüssel</th>
                        <th style="padding: 0.5rem;">Erstellt</th>
                        <th style="padding: 0.5rem;">Zuletzt benutzt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($apiKeys as $key): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 0.5rem;"><?= htmlspecialchars((string)$key['label']) ?></td>
                            <td style="padding: 0.5rem;"><code><?= htmlspecialchars((string)$key['token_prefix']) ?>…</code></td>
                            <td style="padding: 0.5rem;"><?= htmlspecialchars((string)$key['created_at']) ?></td>
                            <td style="padding: 0.5rem;">
                                <?= $key['last_used_at'] !== null ? htmlspecialchars((string)$key['last_used_at']) : '<span style="color: var(--text-subtle);">nie</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <form action="/admin/users/revoke-api-keys" method="POST" data-confirm="Wirklich ALLE aktiven API-Schlüssel dieses Kontos widerrufen? Angebundene Systeme verlieren sofort den Zugriff; der Benutzer kann sich anschließend neue Schlüssel anlegen." >
                <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                <button type="submit" class="btn" style="background-color: #c62a38;">Alle widerrufen</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>
