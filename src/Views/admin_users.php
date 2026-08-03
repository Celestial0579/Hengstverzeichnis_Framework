<?php
// src/Views/admin_users.php
/**
 * @var array $users
 */
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <h2>Benutzer verwalten</h2>
        <a href="/admin/users/create" class="btn">Neuen Benutzer anlegen</a>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            Aktion erfolgreich ausgeführt.
        </div>
    <?php endif; ?>

    <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
        <thead>
            <tr style="border-bottom: 2px solid #eee; text-align: left;">
                <th style="padding: 0.5rem;">ID</th>
                <th style="padding: 0.5rem;">Benutzername</th>
                <th style="padding: 0.5rem;">E-Mail</th>
                <th style="padding: 0.5rem;">Rolle</th>
                <th style="padding: 0.5rem;">2FA Status</th>
                <th style="padding: 0.5rem;">Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding: 0.5rem;"><?= htmlspecialchars((string)$user['id']) ?></td>
                    <td style="padding: 0.5rem;"><strong><?= htmlspecialchars((string)$user['username']) ?></strong></td>
                    <td style="padding: 0.5rem;"><?= htmlspecialchars((string)$user['email']) ?></td>
                    <td style="padding: 0.5rem;">
                        <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem; background-color: <?= $user['role'] === 'admin' ? '#d1ecf1' : '#e2e3e5' ?>; color: <?= $user['role'] === 'admin' ? '#0c5460' : '#383d41' ?>; font-weight: 600;">
                            <?= $user['role'] === 'admin' ? 'Administrator' : 'Editor' ?>
                        </span>
                    </td>
                    <td style="padding: 0.5rem;">
                        <?php if (!empty($user['totp_enabled'])): ?>
                            <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background-color: #d4edda; color: #155724; font-weight: 600;">🔒 Aktiv</span>
                        <?php else: ?>
                            <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background-color: #fff3cd; color: #856404; font-weight: 600;">⚠️ Ausstehend</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 0.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <a href="/admin/users/edit?id=<?= $user['id'] ?>" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.9rem;">Bearbeiten</a>
                        
                        <?php if (!empty($user['totp_enabled'])): ?>
                            <form action="/admin/users/reset-2fa" method="POST" onsubmit="return confirm('Möchten Sie die 2-Faktor-Authentifizierung für den Benutzer \'<?= htmlspecialchars(addslashes($user['username'])) ?>\' wirklich zurücksetzen? Der Benutzer muss 2FA bei der nächsten Anmeldung neu einrichten.');" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                <input type="hidden" name="id" value="<?= $user['id'] ?>">
                                <button type="submit" class="btn" style="padding: 0.25rem 0.5rem; font-size: 0.85rem; background-color: #fd7e14;">🔑 2FA Reset</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                            <form action="/admin/users/delete" method="POST" onsubmit="return confirm('Möchten Sie diesen Benutzer wirklich löschen?');" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                <input type="hidden" name="id" value="<?= $user['id'] ?>">
                                <button type="submit" class="btn" style="padding: 0.25rem 0.5rem; font-size: 0.85rem; background-color: #dc3545;">Löschen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin-top: 2rem;">
        <a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a>
    </div>
</div>
