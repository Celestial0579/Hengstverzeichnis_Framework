<?php
// src/Views/admin_users.php
/**
 * @var array $users Aktuelle Seite der gefilterten Benutzerliste
 * @var string $search Aktueller Suchbegriff (Benutzername/E-Mail)
 * @var int $totalUsersUnfiltered Anzahl aller Benutzer ohne Suchfilter
 * @var int|string $perPage Seitengröße (10/25/50/100 oder 'all')
 * @var array $perPageOptions Erlaubte feste Seitengrößen (ohne 'all')
 * @var int $page
 * @var int $totalPages
 * @var int $totalUsers Anzahl NACH Anwendung der Suche (Basis der Pagination)
 */
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h2>Benutzer verwalten</h2>
        <a href="/admin/users/create" class="btn">Neuen Benutzer anlegen</a>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            Aktion erfolgreich ausgeführt.
        </div>
    <?php endif; ?>

    <?php if (($_GET['error'] ?? '') === 'self_delete'): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            Das eigene Konto kann nicht gelöscht werden.
        </div>
    <?php endif; ?>

    <form action="/admin/users" method="GET" style="display: flex; align-items: flex-end; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.5rem;">
        <div class="form-group" style="margin: 0; flex: 1; min-width: 160px;">
            <label for="user-search" style="font-size: 0.85rem; font-weight: normal;">🔍 Suche (Benutzername/E-Mail)</label>
            <input type="text" id="user-search" name="search" class="form-control" placeholder="z. B. admin..." value="<?= htmlspecialchars($search) ?>" style="padding: 0.3rem 0.5rem; font-size: 0.85rem;">
        </div>
        <div class="form-group" style="margin: 0;">
            <label for="per-page-select" style="font-size: 0.85rem; font-weight: normal;">Anzeigen</label>
            <select id="per-page-select" name="per_page" class="form-control" onchange="this.form.submit()" style="width: auto; padding: 0.3rem 0.5rem; font-size: 0.85rem;">
                <?php foreach ($perPageOptions as $opt): ?>
                    <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                <?php endforeach; ?>
                <option value="all" <?= $perPage === 'all' ? 'selected' : '' ?>>Alle</option>
            </select>
        </div>
        <button type="submit" class="btn btn-secondary" style="padding: 0.4rem 0.9rem; font-size: 0.85rem;">Suchen</button>
        <?php if ($search !== ''): ?>
            <a href="/admin/users?per_page=<?= htmlspecialchars((string)$perPage) ?>" class="btn btn-secondary" style="padding: 0.4rem 0.9rem; font-size: 0.85rem;">Zurücksetzen</a>
        <?php endif; ?>
    </form>

    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0 0 0.5rem 0;">
        <?php if ($search !== ''): ?>
            <?= $totalUsers ?> von <?= $totalUsersUnfiltered ?> Benutzern gefunden für "<?= htmlspecialchars($search) ?>"
        <?php else: ?>
            <?= $totalUsers ?> Benutzer insgesamt
        <?php endif; ?>
    </p>

    <!-- Ab ca. 5-10 Zeilen scrollbar (max-height), damit die Seite bei vielen Benutzern
         nicht beliebig lang wird - unabhängig von der gewählten Seitengröße. -->
    <div style="max-height: 420px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 6px; margin-bottom: 1rem;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border-color); text-align: left; position: sticky; top: 0; background: var(--card-bg); z-index: 1;">
                    <th style="padding: 0.5rem;">ID</th>
                    <th style="padding: 0.5rem;">Benutzername</th>
                    <th style="padding: 0.5rem;">E-Mail</th>
                    <th style="padding: 0.5rem;">Gruppen</th>
                    <th style="padding: 0.5rem;">2FA Status</th>
                    <th style="padding: 0.5rem;">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="6" style="padding: 1rem; text-align: center; color: var(--text-subtle);">
                            <?= $search !== '' ? 'Keine Benutzer für diese Suche gefunden.' : 'Keine Benutzer gefunden.' ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($users as $user): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: 0.5rem;"><?= htmlspecialchars((string)$user['id']) ?></td>
                        <td style="padding: 0.5rem;"><strong><?= htmlspecialchars((string)$user['username']) ?></strong></td>
                        <td style="padding: 0.5rem;"><?= htmlspecialchars((string)$user['email']) ?></td>
                        <td style="padding: 0.5rem;">
                            <?php if (!empty($user['group_names'])): ?>
                                <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem; background-color: var(--surface-muted); color: var(--text-color); font-weight: 600;">
                                    <?= htmlspecialchars($user['group_names']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color: var(--text-subtle); font-size: 0.85rem;">– keine –</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.5rem;">
                            <?php if (!empty($user['totp_enabled'])): ?>
                                <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background-color: var(--success-soft-bg); color: var(--success-fg); font-weight: 600;">🔒 Aktiv</span>
                            <?php else: ?>
                                <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background-color: var(--warning-soft-bg); color: var(--warning-fg); font-weight: 600;">⚠️ Ausstehend</span>
                            <?php endif; ?>
                            <?php // Gesperrt ist nicht geloescht (#358) - der Zustand
                                  // gehoert sichtbar in die Liste, sonst findet ihn
                                  // niemand wieder, um ihn aufzuheben. ?>
                            <?php if (!empty($user['deactivated_at'])): ?>
                                <br><span style="display: inline-block; margin-top: 0.3rem; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background-color: var(--danger-soft-bg); color: var(--danger-fg); font-weight: 600;"
                                          title="Grund: <?= htmlspecialchars((string)($user['deactivated_reason'] ?? '-')) ?>">
                                    ⛔ Deaktiviert seit <?= htmlspecialchars(substr((string)$user['deactivated_at'], 0, 10)) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <a href="/admin/users/edit?id=<?= $user['id'] ?>" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.9rem;">Bearbeiten</a>

                            <?php if (!empty($user['deactivated_at'])): ?>
                                <form action="/admin/users/reactivate" method="POST" style="display:inline;"
                                      data-confirm="Konto '<?= htmlspecialchars((string)$user['username']) ?>' wieder einschalten? Die 180-Tage-Frist beginnt damit von vorn - ohne zweiten Faktor oder E-Mail-Adresse wird das Konto erneut fällig.">
                                    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                    <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                                    <button type="submit" class="btn" style="padding: 0.25rem 0.5rem; font-size: 0.9rem;">Wieder einschalten</button>
                                </form>
                            <?php endif; ?>

                            <?php if (!empty($user['totp_enabled'])): ?>
                                <form action="/admin/users/reset-2fa" method="POST" data-confirm="Möchten Sie die 2-Faktor-Authentifizierung für den Benutzer '<?= htmlspecialchars(($user['username'])) ?>' wirklich zurücksetzen? Der Benutzer muss 2FA bei der nächsten Anmeldung neu einrichten." style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                    <input type="hidden" name="id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn" style="padding: 0.25rem 0.5rem; font-size: 0.85rem; background-color: #fd7e14;">🔑 2FA Reset</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <form action="/admin/users/delete" method="POST" data-confirm="Möchten Sie diesen Benutzer wirklich löschen?" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                    <input type="hidden" name="id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn" style="padding: 0.25rem 0.5rem; font-size: 0.85rem; background-color: #c62a38;">Löschen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div style="display: flex; justify-content: center; align-items: center; gap: 1rem; margin-bottom: 1.5rem; font-size: 0.85rem;">
            <?php if ($page > 1): ?>
                <a href="/admin/users?per_page=<?= htmlspecialchars((string)$perPage) ?>&page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>" class="btn btn-secondary" style="padding: 0.3rem 0.8rem;">← Zurück</a>
            <?php endif; ?>
            <span>Seite <?= $page ?> von <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a href="/admin/users?per_page=<?= htmlspecialchars((string)$perPage) ?>&page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>" class="btn btn-secondary" style="padding: 0.3rem 0.8rem;">Weiter →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div style="margin-top: 1rem;">
        <a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a>
    </div>
</div>
