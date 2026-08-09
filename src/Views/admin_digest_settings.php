<?php
// src/Views/admin_digest_settings.php
/**
 * @var array<string, string> $settings
 * @var array{name:string, intervalSeconds:int, lastRunAt:?int}|null $schedulerTask
 */
$lastStatus = $settings['digest_last_status'] ?? null;
$lastRunAt = isset($settings['digest_last_run_at']) ? (int)$settings['digest_last_run_at'] : null;
$lastError = $settings['digest_last_error'] ?? '';
$lastSentCount = isset($settings['digest_last_sent_count']) ? (int)$settings['digest_last_sent_count'] : null;
?>
<div class="card" style="max-width: 800px;">
    <h2>📋 E-Mail-Digest</h2>
    <p style="color: var(--text-muted);">
        Optionaler periodischer E-Mail-Digest an alle Admin- und Editor-Konten mit
        offenen Blutlinien-Match-/Merge-Vorschlägen (siehe
        <a href="/admin/matches">Blutlinien Zusammenführen</a>) sowie Papierkorb-Einträgen,
        die sich der 30-Tage-Löschfrist nähern (siehe <a href="/admin/trash">Papierkorb</a>).
        DSGVO-Anfragen sind bereits über eine sofortige Benachrichtigung abgedeckt und daher
        nicht Teil dieses Digests. Wird nur versendet, wenn es tatsächlich etwas zu berichten
        gibt - kein "alles ruhig"-Spam.
    </p>

    <?php if (($_GET['success'] ?? '') === 'digest_run'): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            ✓ Digest wurde an <?= htmlspecialchars((string)(int)($_GET['sent'] ?? 0)) ?> Empfänger versendet.
        </div>
    <?php elseif (($_GET['success'] ?? '') === 'digest_skipped'): ?>
        <div style="background-color: #d1ecf1; color: #0c5460; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            ℹ️ Aktuell nichts zu berichten - kein Digest versendet.
        </div>
    <?php elseif (!empty($_GET['success'])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            Digest-Einstellungen erfolgreich gespeichert.
        </div>
    <?php endif; ?>

    <?php if (!empty($_GET['error'])): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            Digest fehlgeschlagen: <?= htmlspecialchars($_GET['error']) ?>
        </div>
    <?php endif; ?>

    <div style="background: var(--surface-muted); padding: 1rem; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 1.5rem;">
        <strong>Letzter Lauf:</strong>
        <?php if ($lastRunAt === null): ?>
            noch nie
        <?php else: ?>
            <?= htmlspecialchars(date('d.m.Y H:i:s', $lastRunAt)) ?>
            - <span style="color: <?= $lastStatus === 'ok' ? '#28a745' : '#dc3545' ?>; font-weight: bold;">
                <?= $lastStatus === 'ok' ? '✓ Erfolgreich' : '✗ Fehlgeschlagen' ?>
            </span>
            <?php if ($lastStatus === 'ok' && $lastSentCount !== null): ?>
                <span style="color: var(--text-muted);">(<?= $lastSentCount ?> Empfänger benachrichtigt<?= $lastSentCount === 0 ? ', nichts zu berichten' : '' ?>)</span>
            <?php endif; ?>
            <?php if ($lastError !== ''): ?>
                <div style="color: <?= $lastStatus === 'ok' ? '#856404' : '#721c24' ?>; font-size: 0.85rem; margin-top: 0.3rem;"><?= htmlspecialchars($lastError) ?></div>
            <?php endif; ?>
        <?php endif; ?>
        <br>
        <strong>Nächster automatischer Lauf:</strong>
        <?php if ($schedulerTask === null): ?>
            <span style="color: var(--text-muted);">nicht aktiv (Digest deaktiviert)</span>
        <?php else: ?>
            spätestens <?= (int)round($schedulerTask['intervalSeconds'] / 3600) ?>h nach dem letzten Lauf,
            ausgelöst über <a href="/admin/cron">Automatisierung (Cron)</a>
        <?php endif; ?>
    </div>

    <form action="/admin/digest" method="POST">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <div class="form-group">
            <label style="cursor: pointer; font-weight: 500;">
                <input type="checkbox" name="digest_enabled" value="1" <?= ($settings['digest_enabled'] ?? '') === '1' ? 'checked' : '' ?>>
                E-Mail-Digest aktivieren
            </label>
        </div>

        <div class="form-group" style="max-width: 250px;">
            <label for="digest_interval_hours">Intervall (Stunden)</label>
            <input type="number" id="digest_interval_hours" name="digest_interval_hours" class="form-control" min="1" value="<?= htmlspecialchars((string)($settings['digest_interval_hours'] ?? '24')) ?>">
        </div>

        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
            <button type="submit" class="btn">Einstellungen Speichern</button>
            <a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a>
        </div>
    </form>

    <hr style="margin: 2rem 0; border: none; border-top: 1px solid var(--border-color);">

    <h3>🧪 Digest jetzt manuell auslösen</h3>
    <p style="color: var(--text-muted); font-size: 0.9rem;">Prüft sofort auf offene Punkte und versendet bei Bedarf an alle Admin-/Editor-Konten, unabhängig vom konfigurierten Intervall.</p>
    <form action="/admin/digest/test" method="POST">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        <button type="submit" class="btn btn-secondary">📋 Jetzt prüfen &amp; ggf. senden</button>
    </form>
</div>
