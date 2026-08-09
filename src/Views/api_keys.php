<?php
// src/Views/api_keys.php
/**
 * Selfservice-Verwaltung eigener API-Schlüssel (siehe ApiKeyController).
 *
 * @var array<int, array<string, mixed>> $keys
 * @var array<int, array{key: string, label: string}> $availableScope
 * @var int $maxKeys
 * @var string|null $newToken Klartext-Schlüssel, nur unmittelbar nach dem Anlegen
 * @var string|null $error
 * @var string|null $success
 */

$errorMessages = [
    'limit_reached' => 'Du hast bereits die maximale Anzahl aktiver Schlüssel erreicht. Widerrufe zuerst einen bestehenden Schlüssel.',
    'missing_label' => 'Bitte gib eine Bezeichnung an, damit du den Schlüssel später zuordnen kannst.',
    'empty_scope' => 'Es wurde keine gültige Berechtigung ausgewählt. Wähle mindestens eine Berechtigung, die du selbst besitzt.',
    'revoke_failed' => 'Der Schlüssel konnte nicht widerrufen werden.',
    'db_error' => 'Der Schlüssel konnte nicht gespeichert werden. Bitte versuche es erneut.',
];
$successMessages = [
    'created' => 'Der API-Schlüssel wurde erstellt.',
    'revoked' => 'Der API-Schlüssel wurde widerrufen und ist sofort ungültig.',
];

$activeCount = count($keys);
$limitReached = $activeCount >= $maxKeys;
?>
<div class="card" style="max-width: 850px; margin: 2rem auto;">
    <h1 style="border-bottom: 2px solid var(--primary-fg); padding-bottom: 0.5rem; margin-bottom: 1rem;">
        🔑 API-Schlüssel
    </h1>

    <p style="color: var(--text-muted); margin-bottom: 1.5rem;">
        Die JSON-API (<code>/api/horses</code>) ist ausschließlich mit einem gültigen Schlüssel erreichbar.
        Ein Schlüssel darf immer <strong>höchstens das, was du selbst darfst</strong> &ndash; verlierst du ein Recht,
        verliert es der Schlüssel sofort mit. Du kannst einen Schlüssel zusätzlich bewusst auf weniger Rechte einschränken.
        Details siehe <code>docs/api.md</code>.
    </p>

    <?php if ($error !== null): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            <?= htmlspecialchars($errorMessages[$error] ?? 'Die Aktion konnte nicht ausgeführt werden.') ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== null && $newToken === null): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            <?= htmlspecialchars($successMessages[$success] ?? 'Aktion erfolgreich.') ?>
        </div>
    <?php endif; ?>

    <?php if ($newToken !== null): ?>
        <div style="background-color: var(--info-soft-bg); border: 1px solid #f0d78c; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            <h3 style="margin-top: 0;">Dein neuer Schlüssel &ndash; jetzt kopieren</h3>
            <p style="margin-bottom: 0.75rem;">
                Dieser Wert wird <strong>nur dieses eine Mal</strong> angezeigt. Er ist nur als Hash gespeichert und
                lässt sich später nicht erneut anzeigen. Bewahre ihn wie ein Passwort auf.
            </p>
            <code style="display: block; word-break: break-all; background: #f0f0f0; color: #222; padding: 0.75rem; border-radius: 4px; font-size: 1rem;">
                <?= htmlspecialchars($newToken) ?>
            </code>
            <p style="margin-top: 0.75rem; margin-bottom: 0; font-size: 0.9rem; color: var(--text-muted);">
                Verwendung: <code>curl -H "Authorization: Bearer &lt;Schlüssel&gt;" https://.../api/horses</code>
            </p>
        </div>
    <?php endif; ?>

    <h2 style="font-size: 1.15rem;">Neuen Schlüssel erstellen</h2>

    <?php if ($limitReached): ?>
        <p style="color: var(--text-muted);">
            Du hast das Maximum von <?= (int)$maxKeys ?> aktiven Schlüsseln erreicht.
            Widerrufe zuerst einen bestehenden Schlüssel, um einen neuen anzulegen.
        </p>
    <?php elseif (empty($availableScope)): ?>
        <p style="color: var(--text-muted);">
            Dein Konto besitzt derzeit keine Berechtigungen, die ein Schlüssel nutzen könnte.
            Ein Schlüssel wäre daher wirkungslos.
        </p>
    <?php else: ?>
        <form method="POST" action="/api-keys/create" style="margin-bottom: 2rem;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(App\Router::generateCsrfToken()) ?>">

            <label for="label" style="display: block; font-weight: bold; margin-top: 0.8rem;">Bezeichnung</label>
            <input type="text" id="label" name="label" required maxlength="100"
                   placeholder="z. B. Verbandswebseite"
                   style="width: 100%; padding: 0.5rem; margin-top: 0.2rem;">

            <fieldset style="margin-top: 1.2rem; border: 1px solid var(--border-color); border-radius: 4px; padding: 0.8rem;">
                <legend style="font-weight: bold; padding: 0 0.4rem;">Rechte des Schlüssels</legend>

                <label style="display: block; margin-bottom: 0.4rem;">
                    <input type="radio" name="scope_mode" value="all" checked>
                    Alle meine aktuellen Rechte (passt sich automatisch an, wenn sich meine Rechte ändern)
                </label>
                <label style="display: block;">
                    <input type="radio" name="scope_mode" value="custom">
                    Nur ausgewählte Rechte (empfohlen &ndash; so wenig wie möglich)
                </label>

                <div style="margin-top: 0.8rem; padding-left: 1.5rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.3rem;">
                    <?php foreach ($availableScope as $choice): ?>
                        <label style="display: block; font-size: 0.92rem;">
                            <input type="checkbox" name="scope[]" value="<?= htmlspecialchars($choice['key']) ?>">
                            <?= htmlspecialchars($choice['label']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p style="margin: 0.8rem 0 0 0; font-size: 0.85rem; color: var(--text-muted);">
                    Angezeigt werden nur Rechte, die du selbst besitzt &ndash; mehr kann ein Schlüssel nie erhalten.
                </p>
            </fieldset>

            <button type="submit" class="btn btn-primary" style="margin-top: 1.2rem;">Schlüssel erstellen</button>
        </form>
    <?php endif; ?>

    <h2 style="font-size: 1.15rem;">Aktive Schlüssel (<?= (int)$activeCount ?>/<?= (int)$maxKeys ?>)</h2>

    <?php if (empty($keys)): ?>
        <p style="color: var(--text-muted);">Du hast derzeit keine aktiven API-Schlüssel.</p>
    <?php else: ?>
        <table style="width: 100%; border-collapse: collapse; margin-top: 0.5rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 2px solid var(--border-color);">
                    <th style="padding: 0.5rem;">Bezeichnung</th>
                    <th style="padding: 0.5rem;">Schlüssel</th>
                    <th style="padding: 0.5rem;">Rechte</th>
                    <th style="padding: 0.5rem;">Zuletzt genutzt</th>
                    <th style="padding: 0.5rem;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($keys as $key): ?>
                    <?php
                    $scope = $key['scope_permissions'] !== null ? json_decode((string)$key['scope_permissions'], true) : null;
                    $scopeText = !is_array($scope) ? 'alle meine Rechte' : implode(', ', array_filter($scope, 'is_string'));
                    ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: 0.5rem;"><?= htmlspecialchars((string)$key['label']) ?></td>
                        <td style="padding: 0.5rem;"><code><?= htmlspecialchars((string)$key['token_prefix']) ?>…</code></td>
                        <td style="padding: 0.5rem; font-size: 0.85rem; color: var(--text-muted);"><?= htmlspecialchars($scopeText) ?></td>
                        <td style="padding: 0.5rem; font-size: 0.9rem;"><?= htmlspecialchars((string)($key['last_used_at'] ?? 'nie')) ?></td>
                        <td style="padding: 0.5rem;">
                            <form method="POST" action="/api-keys/revoke" style="margin: 0;"
                                  onsubmit="return confirm('Diesen Schlüssel wirklich widerrufen? Anwendungen, die ihn nutzen, verlieren sofort den Zugriff.');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(App\Router::generateCsrfToken()) ?>">
                                <input type="hidden" name="id" value="<?= (int)$key['id'] ?>">
                                <button type="submit" class="btn btn-secondary" style="border-color: var(--danger-fg); color: var(--danger-fg); padding: 0.3rem 0.7rem; font-size: 0.85rem;">
                                    Widerrufen
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p style="margin-top: 2rem;"><a href="/admin">Zurück zum Dashboard</a></p>
</div>
