<?php
// src/Views/admin_trash.php
/**
 * @var array $deletedHorses
 * @var array $deletedContacts
 * @var array $deletedUsers
 * @var int $totalCount
 * @var bool $isAdmin
 */
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2>🗑️ Papierkorb</h2>
            <p style="color: var(--text-muted); font-size: 0.95rem; margin-top: 0.2rem;">
                Gelöschte Elemente werden aufbewahrt.
                <strong>Editoren</strong> können Pferde und Kontakte wiederherstellen (endgültige Löschung nach 30 Tagen).
                <strong>Administratoren</strong> haben jederzeit Vollzugriff.
            </p>
        </div>

        <?php if ($totalCount > 0): ?>
            <form action="/admin/trash/empty" method="POST" data-confirm="Möchten Sie alle berechtigten Elemente im Papierkorb leeren?" >
                <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                <button type="submit" class="btn" style="background-color: #c62a38;">
                    🧹 Papierkorb leeren <?= $isAdmin ? '(Alle)' : '(> 30 Tage)' ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            <?php if ($_GET['success'] === 'restored'): ?>
                ✓ Element wurde erfolgreich wiederhergestellt!
            <?php elseif ($_GET['success'] === 'purged'): ?>
                ✓ Element wurde endgültig aus der Datenbank gelöscht.
            <?php else: ?>
                ✓ Der Papierkorb wurde verarbeitet/geleert.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php // Adresspflicht nach Rechten (#348): Ein Konto ohne Adresse darf nicht
          // in Gruppen zurueckkehren, die inzwischen Bearbeitungsrechte haben. ?>
    <?php if (($_GET['error'] ?? '') === 'email_required'): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            Nicht wiederhergestellt: Das Konto hat keine E-Mail-Adresse, seine Gruppen geben inzwischen
            aber Bearbeitungs- oder Veröffentlichungsrechte. Tragen Sie zuerst eine Adresse ein oder
            nehmen Sie das Konto aus diesen Gruppen &ndash; ohne Adresse gibt es kein
            &bdquo;Passwort vergessen&ldquo;, keine Benachrichtigungen und keinen zweiten Faktor per E-Mail.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) && $_GET['error'] === 'retention_period_30_days'): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            ⚠️ <strong>Hinweis zur Aufbewahrungsfrist:</strong> Editoren können Elemente erst nach Ablauf der 30-Tage-Frist endgültig löschen. Für eine sofortige Löschung wenden Sie sich an einen Administrator.
        </div>
    <?php endif; ?>

    <?php if ($totalCount === 0): ?>
        <div style="padding: 3rem; text-align: center; color: var(--text-subtle); background: var(--surface-muted); border-radius: 6px; border: 1px dashed var(--border-color); margin-top: 1rem;">
            <span style="font-size: 2.5rem;">✨</span>
            <h3 style="margin: 0.5rem 0 0 0; color: var(--text-muted);">Der Papierkorb ist leer</h3>
            <p style="margin-top: 0.3rem; font-size: 0.9rem;">Es befinden sich keine gelöschten Einträge im Papierkorb.</p>
        </div>
    <?php else: ?>

        <!-- Deleted Horses -->
        <?php if (!empty($deletedHorses)): ?>
            <div style="margin-bottom: 2rem;">
                <h3 style="color: var(--primary-fg); border-bottom: 2px solid var(--secondary-color); padding-bottom: 0.4rem; margin-bottom: 1rem;">
                    🐴 Gelöschte Pferde (<?= count($deletedHorses) ?>)
                </h3>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--border-color); text-align: left;">
                            <th style="padding: 0.6rem;">Name</th>
                            <th style="padding: 0.6rem;">UELN</th>
                            <th style="padding: 0.6rem;">Gelöscht am</th>
                            <th style="padding: 0.6rem;">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deletedHorses as $h): ?>
                            <?php $isOlder = (strtotime($h['deleted_at']) <= strtotime('-30 days')); ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 0.6rem;"><strong><?= htmlspecialchars($h['name']) ?></strong></td>
                                <td style="padding: 0.6rem;"><?= htmlspecialchars($h['ueln'] ?: '-') ?></td>
                                <td style="padding: 0.6rem; font-size: 0.85rem; color: var(--text-muted);">
                                    <?= date('d.m.Y H:i', strtotime($h['deleted_at'])) ?> Uhr
                                    <?php if ($isOlder): ?>
                                        <span style="background: var(--danger-soft-bg); color: var(--danger-fg); padding: 0.1rem 0.4rem; border-radius: 8px; font-size: 0.75rem; font-weight: bold; margin-left: 0.3rem;">> 30 Tage</span>
                                    <?php else: ?>
                                        <span style="background: var(--surface-muted); color: var(--text-color); padding: 0.1rem 0.4rem; border-radius: 8px; font-size: 0.75rem; margin-left: 0.3rem;">⏳ In Aufbewahrung</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.6rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                    <form action="/admin/trash/restore" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                        <input type="hidden" name="type" value="horse">
                                        <input type="hidden" name="id" value="<?= $h['id'] ?>">
                                        <button type="submit" class="btn" style="padding: 0.3rem 0.6rem; font-size: 0.85rem; background-color: #1e7d34;">♻️ Wiederherstellen</button>
                                    </form>
                                    <?php if ($isAdmin || $isOlder): ?>
                                        <form action="/admin/trash/permanent-delete" method="POST" data-confirm="Möchten Sie dieses Pferd endgültig löschen?" >
                                            <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                            <input type="hidden" name="type" value="horse">
                                            <input type="hidden" name="id" value="<?= $h['id'] ?>">
                                            <button type="submit" class="btn" style="padding: 0.3rem 0.6rem; font-size: 0.85rem; background-color: #c62a38;">🔥 Endgültig löschen</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Deleted Contacts -->
        <?php /* Personen und Deckstationen sind seit #336 EIN Bereich - eine
                 Liste, ein Typwert 'contact'. Die Spalte "Ansprechpartner /
                 Kontakt-Info" fuehrt die beiden frueheren Spalten zusammen:
                 Ein Kontakt, der ein Betrieb ist, traegt contact_person, eine
                 Privatperson das alte Freitextfeld contact_info. */ ?>
        <?php if (!empty($deletedContacts)): ?>
            <div style="margin-bottom: 2rem;">
                <h3 style="color: var(--primary-fg); border-bottom: 2px solid var(--secondary-color); padding-bottom: 0.4rem; margin-bottom: 1rem;">
                    👤 Gelöschte Kontakte (<?= count($deletedContacts) ?>)
                </h3>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--border-color); text-align: left;">
                            <th style="padding: 0.6rem;">Name</th>
                            <th style="padding: 0.6rem;">Ansprechpartner / Kontakt-Info</th>
                            <th style="padding: 0.6rem;">Gelöscht am</th>
                            <th style="padding: 0.6rem;">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deletedContacts as $c): ?>
                            <?php $isOlder = (strtotime($c['deleted_at']) <= strtotime('-30 days')); ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 0.6rem;"><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                                <td style="padding: 0.6rem; font-size: 0.9rem;"><?= htmlspecialchars(($c['contact_person'] ?: $c['contact_info']) ?: '-') ?></td>
                                <td style="padding: 0.6rem; font-size: 0.85rem; color: var(--text-muted);">
                                    <?= date('d.m.Y H:i', strtotime($c['deleted_at'])) ?> Uhr
                                    <?php if ($isOlder): ?>
                                        <span style="background: var(--danger-soft-bg); color: var(--danger-fg); padding: 0.1rem 0.4rem; border-radius: 8px; font-size: 0.75rem; font-weight: bold; margin-left: 0.3rem;">> 30 Tage</span>
                                    <?php else: ?>
                                        <span style="background: var(--surface-muted); color: var(--text-color); padding: 0.1rem 0.4rem; border-radius: 8px; font-size: 0.75rem; margin-left: 0.3rem;">⏳ In Aufbewahrung</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.6rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                    <form action="/admin/trash/restore" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                        <input type="hidden" name="type" value="contact">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <button type="submit" class="btn" style="padding: 0.3rem 0.6rem; font-size: 0.85rem; background-color: #1e7d34;">♻️ Wiederherstellen</button>
                                    </form>
                                    <?php if ($isAdmin || $isOlder): ?>
                                        <form action="/admin/trash/permanent-delete" method="POST" data-confirm="Möchten Sie diesen Kontakt endgültig löschen?" >
                                            <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                            <input type="hidden" name="type" value="contact">
                                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                            <button type="submit" class="btn" style="padding: 0.3rem 0.6rem; font-size: 0.85rem; background-color: #c62a38;">🔥 Endgültig löschen</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Deleted Users (Admins Only) -->
        <?php if ($isAdmin && !empty($deletedUsers)): ?>
            <div style="margin-bottom: 2rem; background: var(--danger-soft-bg); padding: 1rem; border-radius: 8px; border: 1px solid #f5c6cb;">
                <h3 style="color: var(--danger-fg); border-bottom: 2px solid #dc3545; padding-bottom: 0.4rem; margin-bottom: 1rem;">
                    👥 Gelöschte Benutzerkonten (Nur für Administratoren)
                </h3>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--border-color); text-align: left;">
                            <th style="padding: 0.6rem;">Benutzername</th>
                            <th style="padding: 0.6rem;">E-Mail</th>
                            <th style="padding: 0.6rem;">Gelöscht am</th>
                            <th style="padding: 0.6rem;">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deletedUsers as $u): ?>
                            <?php $isOlder = (strtotime($u['deleted_at']) <= strtotime('-30 days')); ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 0.6rem;"><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                                <td style="padding: 0.6rem; font-size: 0.9rem;"><?= htmlspecialchars($u['email']) ?></td>
                                <td style="padding: 0.6rem; font-size: 0.85rem; color: var(--text-muted);">
                                    <?= date('d.m.Y H:i', strtotime($u['deleted_at'])) ?> Uhr
                                </td>
                                <td style="padding: 0.6rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                    <form action="/admin/trash/restore" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                        <input type="hidden" name="type" value="user">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn" style="padding: 0.3rem 0.6rem; font-size: 0.85rem; background-color: #1e7d34;">♻️ Wiederherstellen</button>
                                    </form>
                                    <form action="/admin/trash/permanent-delete" method="POST" data-confirm="Möchten Sie diesen Benutzer endgültig löschen?" >
                                        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                        <input type="hidden" name="type" value="user">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn" style="padding: 0.3rem 0.6rem; font-size: 0.85rem; background-color: #c62a38;">🔥 Endgültig löschen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php endif; ?>

    <div style="margin-top: 2rem;">
        <a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a>
    </div>
</div>
