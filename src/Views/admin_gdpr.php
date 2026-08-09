<?php
// src/Views/admin_gdpr.php
/**
 * @var array $requests
 */
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2>🛡️ DSGVO Anfragen verwalten</h2>
            <p style="color: var(--text-muted); font-size: 0.95rem; margin-top: 0.2rem;">Verarbeitung von Auskunfts- (Art. 15) und Löschanfragen (Art. 17 DSGVO) mit Anonymisierungs-Funktion.</p>
        </div>
        <a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            <?php if ($_GET['success'] === 'anonymized'): ?>
                ✓ Person #<?= htmlspecialchars($_GET['person_id'] ?? '') ?> wurde erfolgreich anonymisiert. Alle verknüpften Pferdeprofile und Stammbäume bleiben erhalten!
            <?php elseif ($_GET['success'] === 'deleted'): ?>
                ✓ Person #<?= htmlspecialchars($_GET['person_id'] ?? '') ?> wurde vollständig aus der Datenbank gelöscht.
            <?php else: ?>
                ✓ Anfrage-Status wurde erfolgreich aktualisiert.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($requests)): ?>
        <div style="padding: 2rem; text-align: center; color: var(--text-subtle); background: var(--surface-muted); border-radius: 6px; border: 1px dashed var(--border-color);">
            Es liegen derzeit keine DSGVO-Anfragen vor.
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <?php foreach ($requests as $req): ?>
                <?php 
                    $isDeletion = $req['request_type'] === 'deletion';
                    $statusBadge = $req['status'] === 'processed' ? 'background: #28a745; color: white;' : ($req['status'] === 'rejected' ? 'background: #dc3545; color: white;' : 'background: #ffc107; color: #212529;');
                    $statusLabel = $req['status'] === 'processed' ? '✓ Erledigt' : ($req['status'] === 'rejected' ? '✕ Abgelehnt' : '⏳ Offen (Ausstehend)');
                ?>
                <div style="border: 1px solid #e0e0e0; border-radius: 8px; padding: 1.2rem; background: var(--card-bg); box-shadow: 0 2px 4px rgba(0,0,0,0.03);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; border-bottom: 1px solid #f0f0f0; padding-bottom: 0.8rem; margin-bottom: 1rem;">
                        <div>
                            <span style="display: inline-block; padding: 0.2rem 0.6rem; border-radius: 12px; font-size: 0.8rem; font-weight: bold; margin-bottom: 0.4rem; <?= $isDeletion ? 'background: var(--danger-soft-bg); color: var(--danger-fg);' : 'background: #cce5ff; color: #004085;' ?>">
                                <?= $isDeletion ? '🗑️ Löschanfrage (Art. 17 DSGVO)' : 'ℹ️ Auskunftsanfrage (Art. 15 DSGVO)' ?>
                            </span>
                            <h3 style="margin: 0; font-size: 1.1rem; color: var(--primary-fg);">
                                <?= htmlspecialchars($req['name'] ?: 'Unbekannter Name') ?> 
                                <span style="font-weight: normal; color: var(--text-muted); font-size: 0.95rem;">(&lt;<?= htmlspecialchars($req['email']) ?>&gt;)</span>
                            </h3>
                            <span style="font-size: 0.85rem; color: var(--text-subtle);">Eingegangen am: <?= date('d.m.Y H:i', strtotime($req['created_at'])) ?> Uhr</span>
                        </div>
                        <div>
                            <span style="display: inline-block; padding: 0.3rem 0.8rem; border-radius: 12px; font-size: 0.85rem; font-weight: bold; <?= $statusBadge ?>">
                                <?= $statusLabel ?>
                            </span>
                        </div>
                    </div>

                    <?php if (!empty($req['message'])): ?>
                        <div style="margin-bottom: 1rem; background: var(--surface-muted); padding: 0.8rem; border-radius: 6px; border-left: 3px solid var(--primary-fg);">
                            <strong style="font-size: 0.85rem; color: var(--text-muted);">Nachricht / Details des Anfragenden:</strong>
                            <p style="margin: 0.3rem 0 0 0; font-size: 0.95rem; white-space: pre-wrap;"><?= htmlspecialchars($req['message']) ?></p>
                        </div>
                    <?php endif; ?>

                    <!-- Matching Persons Section for Deletion / Anonymization -->
                    <?php if ($isDeletion && $req['status'] !== 'processed'): ?>
                        <div style="background: var(--warning-soft-bg); border: 1px solid #ffeeba; border-radius: 6px; padding: 1rem; margin-bottom: 1rem;">
                            <h4 style="margin: 0 0 0.5rem 0; color: var(--warning-fg); display: flex; align-items: center; gap: 0.5rem;">
                                🔍 Gefundene Personeneinträge in der Datenbank:
                            </h4>
                            
                            <?php if (empty($req['matching_persons'])): ?>
                                <p style="margin: 0; font-size: 0.9rem; color: var(--warning-fg);">
                                    Keine direkten Personeneinträge für "<?= htmlspecialchars($req['name'] ?: $req['email']) ?>" gefunden.
                                </p>
                            <?php else: ?>
                                <div style="display: flex; flex-direction: column; gap: 0.8rem; margin-top: 0.8rem;">
                                    <?php foreach ($req['matching_persons'] as $p): ?>
                                        <div style="background: var(--card-bg); padding: 0.8rem; border-radius: 6px; border: 1px solid #eedc9e; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                                            <div>
                                                <strong>👤 <?= htmlspecialchars($p['name']) ?></strong> (ID #<?= $p['id'] ?>)
                                                <?php if (!empty($p['contact_info'])): ?>
                                                    <br><span style="font-size: 0.85rem; color: var(--text-muted);"><?= htmlspecialchars($p['contact_info']) ?></span>
                                                <?php endif; ?>
                                                <br><span style="font-size: 0.8rem; color: var(--primary-fg); font-weight: bold;">🐴 <?= (int)$p['horse_count'] ?> verknüpfte Pferde/Rollen</span>
                                            </div>
                                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                                <!-- Action 1: Anonymisierung (Empfohlen) -->
                                                <form action="/admin/gdpr/anonymize-person" method="POST" onsubmit="return confirm('Möchten Sie diese Person anonymisieren? Name und Kontaktdaten werden überschrieben, aber die Pferdeprofile bleiben erhalten.');">
                                                    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                                    <input type="hidden" name="person_id" value="<?= $p['id'] ?>">
                                                    <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                                    <button type="submit" class="btn" style="background-color: #6f42c1; padding: 0.4rem 0.8rem; font-size: 0.85rem;">
                                                        🔒 Anonymisieren (Pferde behalten)
                                                    </button>
                                                </form>

                                                <!-- Action 2: Vollständiges Löschen -->
                                                <form action="/admin/gdpr/delete-person" method="POST" onsubmit="return confirm('Möchten Sie diese Person wirklich vollständig löschen?');">
                                                    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                                    <input type="hidden" name="person_id" value="<?= $p['id'] ?>">
                                                    <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                                    <button type="submit" class="btn" style="background-color: #c62a38; padding: 0.4rem 0.8rem; font-size: 0.85rem;">
                                                        🗑️ Person Löschen
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Admin Status Update & Notes Form -->
                    <form action="/admin/gdpr/update-status" method="POST" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap; background: var(--surface-muted); padding: 0.8rem; border-radius: 6px;">
                        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                        <input type="hidden" name="id" value="<?= $req['id'] ?>">

                        <div style="flex: 1; min-width: 150px;">
                            <label style="font-size: 0.85rem; font-weight: bold; color: var(--text-muted);">Status ändern:</label>
                            <select name="status" class="form-control" style="padding: 0.4rem; font-size: 0.9rem;">
                                <option value="pending" <?= $req['status'] === 'pending' ? 'selected' : '' ?>>⏳ Offen</option>
                                <option value="processed" <?= $req['status'] === 'processed' ? 'selected' : '' ?>>✓ Als Erledigt markieren</option>
                                <option value="rejected" <?= $req['status'] === 'rejected' ? 'selected' : '' ?>>✕ Ablehnen</option>
                            </select>
                        </div>

                        <div style="flex: 2; min-width: 220px;">
                            <label style="font-size: 0.85rem; font-weight: bold; color: var(--text-muted);">Interne Notiz (z. B. Bearbeitungsvermerk):</label>
                            <input type="text" name="admin_notes" class="form-control" style="padding: 0.4rem; font-size: 0.9rem;" value="<?= htmlspecialchars((string)($req['admin_notes'] ?? '')) ?>" placeholder="Notiz hinzufügen...">
                        </div>

                        <button type="submit" class="btn btn-secondary" style="padding: 0.4rem 0.9rem; font-size: 0.9rem;">
                            Speichern
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (($totalPages ?? 1) > 1): ?>
            <div style="display: flex; justify-content: center; align-items: center; gap: 1rem; margin-top: 1.5rem;">
                <?php if (($page ?? 1) > 1): ?>
                    <a href="/admin/gdpr?page=<?= (int)$page - 1 ?>" class="btn btn-secondary" style="padding: 0.4rem 0.9rem; font-size: 0.9rem;">&laquo; Zurück</a>
                <?php endif; ?>
                <span style="font-size: 0.9rem; color: var(--text-muted);">Seite <?= (int)($page ?? 1) ?> von <?= (int)$totalPages ?> (<?= (int)($total ?? 0) ?> Anfragen)</span>
                <?php if (($page ?? 1) < $totalPages): ?>
                    <a href="/admin/gdpr?page=<?= (int)$page + 1 ?>" class="btn btn-secondary" style="padding: 0.4rem 0.9rem; font-size: 0.9rem;">Weiter &raquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
