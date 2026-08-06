<?php
// src/Views/admin_cron_settings.php
/**
 * @var string $cronSecret
 * @var array<int, array{name:string, intervalSeconds:int, lastRunAt:?int}> $tasks
 */
$cronUrl = rtrim(APP_URL, '/') . '/cron/run';
?>
<div class="card" style="max-width: 800px;">
    <h2>⏱️ Automatisierung (Cron)</h2>
    <p style="color: #666;">
        Grundlegende Scheduler-Infrastruktur (#67) für periodisch auszuführende Aufgaben
        (z. B. künftige automatisierte Backups oder E-Mail-Digests). Ein externer System-Cron
        ruft dazu regelmäßig einen geschützten Endpunkt auf - alternativ lassen sich fällige
        Aufgaben auch manuell hier auslösen.
    </p>

    <?php if (($_GET['success'] ?? '') === 'secret_regenerated'): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            ✓ Neues Cron-Secret wurde erzeugt. Bitte den Cron-Aufruf beim Betreiber (System-Cron) entsprechend aktualisieren.
        </div>
    <?php elseif (($_GET['success'] ?? '') === 'run_now'): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            ✓ Manueller Lauf abgeschlossen: <?= (int)($_GET['ran'] ?? 0) ?> fällige Aufgabe(n) ausgeführt.
        </div>
    <?php endif; ?>

    <h3 style="color: var(--primary-color);">1. System-Cron einrichten</h3>
    <?php if ($cronSecret === ''): ?>
        <p style="color: #666;">Noch kein Secret hinterlegt - bitte zuerst eines erzeugen, bevor der externe Aufruf konfiguriert wird.</p>
    <?php else: ?>
        <p style="color: #666;">Auf dem Server, der diese Installation betreibt, z. B. per <code>crontab -e</code> minütlich einrichten:</p>
        <pre style="background: #f4f4f4; padding: 1rem; border-radius: 6px; overflow-x: auto; font-size: 0.85rem;">* * * * * curl -fsS -H "X-Cron-Secret: <?= htmlspecialchars($cronSecret) ?>" <?= htmlspecialchars($cronUrl) ?> &gt;/dev/null</pre>
        <p style="color: #666; font-size: 0.9rem;">
            Das Secret wird ausschließlich über den Header <code>X-Cron-Secret</code>
            akzeptiert. Eine Übergabe als Query-Parameter wird nicht unterstützt, da das
            Secret dabei in Server-/Proxy-Logs landen würde.
        </p>
    <?php endif; ?>

    <form action="/admin/cron/regenerate-secret" method="POST" style="margin-top: 1rem;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        <button type="submit" class="btn btn-secondary" onclick="return confirm('<?= $cronSecret === '' ? 'Neues Cron-Secret erzeugen?' : 'Bestehendes Secret ersetzen? Der bisherige Cron-Aufruf beim Betreiber muss danach angepasst werden.' ?>');">
            <?= $cronSecret === '' ? '🔑 Secret erzeugen' : '🔄 Secret neu generieren' ?>
        </button>
    </form>

    <hr style="margin: 2rem 0; border: none; border-top: 1px solid var(--border-color);">

    <h3 style="color: var(--primary-color);">2. Registrierte Aufgaben</h3>
    <?php if (empty($tasks)): ?>
        <p style="color: #666;">
            Aktuell sind keine Aufgaben registriert. Diese Infrastruktur ist als Voraussetzung
            für künftige Kern-Features (automatisierte externe Backups, E-Mail-Digest für
            Admins/Editoren) vorbereitet.
        </p>
    <?php else: ?>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border-color); text-align: left;">
                    <th style="padding: 0.5rem 0;">Aufgabe</th>
                    <th style="padding: 0.5rem 0;">Intervall</th>
                    <th style="padding: 0.5rem 0;">Zuletzt ausgeführt</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tasks as $task): ?>
                    <tr style="border-bottom: 1px solid #f0f0f0;">
                        <td style="padding: 0.5rem 0;"><code><?= htmlspecialchars($task['name']) ?></code></td>
                        <td style="padding: 0.5rem 0;"><?= (int)round($task['intervalSeconds'] / 60) ?> Min.</td>
                        <td style="padding: 0.5rem 0;">
                            <?= $task['lastRunAt'] !== null ? htmlspecialchars(date('d.m.Y H:i:s', $task['lastRunAt'])) : 'noch nie' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <form action="/admin/cron/run-now" method="POST" style="margin-top: 1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        <button type="submit" class="btn btn-secondary">▶️ Fällige Aufgaben jetzt manuell ausführen</button>
    </form>

    <div style="margin-top: 2rem;">
        <a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a>
    </div>
</div>
