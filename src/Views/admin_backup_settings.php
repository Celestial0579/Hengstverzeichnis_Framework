<?php
// src/Views/admin_backup_settings.php
/**
 * @var array<string, string> $settings
 * @var array{name:string, intervalSeconds:int, lastRunAt:?int}|null $schedulerTask
 */
$lastStatus = $settings['backup_last_status'] ?? null;
$lastRunAt = isset($settings['backup_last_run_at']) ? (int)$settings['backup_last_run_at'] : null;
$lastError = $settings['backup_last_error'] ?? '';
$currentTarget = $settings['backup_target'] ?? \App\Service\BackupService::TARGET_S3;
?>
<div class="card" style="max-width: 800px;">
    <h2>💾 Backups</h2>
    <p style="color: #666;">
        Automatisierte, periodische Sicherung der Datenbank an ein von drei
        wählbaren externen Zielen (#59, #93) - als Kernfunktion, aufbauend auf der
        Cron-/Scheduler-Infrastruktur (#67, siehe
        <a href="/admin/cron">Automatisierung (Cron)</a>). Enthält aktuell nur die
        Datenbank, keine hochgeladenen Dateien (Logos/Pferdebilder).
    </p>

    <?php if (!empty($_GET['success'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            <?= $_GET['success'] === 'backup_run' ? '✓ Backup wurde erfolgreich manuell ausgeführt.' : 'Backup-Einstellungen erfolgreich gespeichert.' ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($_GET['error'])): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            Backup fehlgeschlagen: <?= htmlspecialchars($_GET['error']) ?>
        </div>
    <?php endif; ?>

    <div style="background: #f8f9fa; padding: 1rem; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 1.5rem;">
        <strong>Letzter Lauf:</strong>
        <?php if ($lastRunAt === null): ?>
            noch nie
        <?php else: ?>
            <?= htmlspecialchars(date('d.m.Y H:i:s', $lastRunAt)) ?>
            - <span style="color: <?= $lastStatus === 'ok' ? '#28a745' : '#dc3545' ?>; font-weight: bold;">
                <?= $lastStatus === 'ok' ? '✓ Erfolgreich' : '✗ Fehlgeschlagen' ?>
            </span>
            <?php if ($lastStatus !== 'ok' && $lastError !== ''): ?>
                <div style="color: #721c24; font-size: 0.85rem; margin-top: 0.3rem;"><?= htmlspecialchars($lastError) ?></div>
            <?php endif; ?>
        <?php endif; ?>
        <br>
        <strong>Nächster automatischer Lauf:</strong>
        <?php if ($schedulerTask === null): ?>
            <span style="color: #666;">nicht aktiv (Backup deaktiviert oder unvollständig konfiguriert)</span>
        <?php else: ?>
            spätestens <?= (int)round($schedulerTask['intervalSeconds'] / 3600) ?>h nach dem letzten Lauf,
            ausgelöst über <a href="/admin/cron">Automatisierung (Cron)</a>
        <?php endif; ?>
    </div>

    <form action="/admin/backups" method="POST">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <div class="form-group">
            <label style="cursor: pointer; font-weight: 500;">
                <input type="checkbox" name="backup_enabled" value="1" <?= ($settings['backup_enabled'] ?? '') === '1' ? 'checked' : '' ?>>
                Automatisierte Backups aktivieren
            </label>
        </div>

        <div class="form-group">
            <label for="backup_target">Backup-Ziel</label>
            <select id="backup_target" name="backup_target" class="form-control" onchange="updateBackupTargetVisibility(this.value)">
                <option value="s3" <?= $currentTarget === 's3' ? 'selected' : '' ?>>🪣 S3-kompatibler Speicher (AWS S3, MinIO, Hetzner Object Storage o. Ä.)</option>
                <option value="ftps" <?= $currentTarget === 'ftps' ? 'selected' : '' ?>>📁 FTPS</option>
                <option value="webdav" <?= $currentTarget === 'webdav' ? 'selected' : '' ?>>☁️ WebDAV (z. B. Nextcloud/ownCloud)</option>
            </select>
        </div>

        <div id="backup-target-s3" style="background: #f8f9fa; padding: 1.2rem; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 1.5rem;">
            <h4 style="margin-top: 0; color: var(--primary-color);">🪣 S3-kompatibler Speicher</h4>

            <div style="display: flex; gap: 1rem;">
                <div class="form-group" style="flex: 2;">
                    <label for="backup_s3_endpoint">Endpunkt *</label>
                    <input type="text" id="backup_s3_endpoint" name="backup_s3_endpoint" class="form-control" value="<?= htmlspecialchars($settings['backup_s3_endpoint'] ?? '') ?>" placeholder="z. B. s3.eu-central-1.amazonaws.com oder fsn1.your-objectstorage.com">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label for="backup_s3_region">Region</label>
                    <input type="text" id="backup_s3_region" name="backup_s3_region" class="form-control" value="<?= htmlspecialchars($settings['backup_s3_region'] ?? '') ?>" placeholder="us-east-1">
                </div>
            </div>

            <div class="form-group">
                <label for="backup_s3_bucket">Bucket *</label>
                <input type="text" id="backup_s3_bucket" name="backup_s3_bucket" class="form-control" value="<?= htmlspecialchars($settings['backup_s3_bucket'] ?? '') ?>">
            </div>

            <div style="display: flex; gap: 1rem;">
                <div class="form-group" style="flex: 1;">
                    <label for="backup_s3_access_key">Access Key *</label>
                    <input type="text" id="backup_s3_access_key" name="backup_s3_access_key" class="form-control" value="<?= htmlspecialchars($settings['backup_s3_access_key'] ?? '') ?>">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label for="backup_s3_secret_key">Secret Key *</label>
                    <input type="password" id="backup_s3_secret_key" name="backup_s3_secret_key" class="form-control" placeholder="<?= !empty($settings['backup_s3_secret_key']) ? '•••••••• (unverändert)' : 'Secret Key eingeben' ?>">
                    <small style="color: #666;">Wird mit AES-256-GCM verschlüsselt gespeichert.</small>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 0.3rem;">
                <label style="cursor: pointer; font-weight: 500;">
                    <input type="checkbox" name="backup_s3_path_style" value="1" <?= ($settings['backup_s3_path_style'] ?? '') === '1' ? 'checked' : '' ?>>
                    Path-Style-URLs verwenden (für die meisten selbstgehosteten MinIO-Installationen nötig)
                </label>
            </div>
            <div class="form-group">
                <label style="cursor: pointer; font-weight: 500;">
                    <input type="checkbox" name="backup_s3_use_https" value="1" <?= ($settings['backup_s3_use_https'] ?? '1') !== '0' ? 'checked' : '' ?>>
                    HTTPS verwenden (nur für selbstgehosteten Speicher in einem vertrauenswürdigen internen Netz deaktivieren)
                </label>
            </div>
        </div>

        <div id="backup-target-ftps" style="background: #f8f9fa; padding: 1.2rem; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 1.5rem;">
            <h4 style="margin-top: 0; color: var(--primary-color);">📁 FTPS</h4>
            <p style="color: #666; font-size: 0.85rem; margin-top: 0;">
                Ausschließlich TLS-verschlüsseltes FTP (FTPS) - unverschlüsseltes FTP wird
                aus Sicherheitsgründen nicht angeboten.
            </p>

            <div style="display: flex; gap: 1rem;">
                <div class="form-group" style="flex: 3;">
                    <label for="backup_ftps_host">Host *</label>
                    <input type="text" id="backup_ftps_host" name="backup_ftps_host" class="form-control" value="<?= htmlspecialchars($settings['backup_ftps_host'] ?? '') ?>" placeholder="ftp.beispiel-hoster.de">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label for="backup_ftps_port">Port</label>
                    <input type="number" id="backup_ftps_port" name="backup_ftps_port" class="form-control" min="1" value="<?= htmlspecialchars((string)($settings['backup_ftps_port'] ?? '21')) ?>">
                </div>
            </div>

            <div style="display: flex; gap: 1rem;">
                <div class="form-group" style="flex: 1;">
                    <label for="backup_ftps_user">Benutzername *</label>
                    <input type="text" id="backup_ftps_user" name="backup_ftps_user" class="form-control" value="<?= htmlspecialchars($settings['backup_ftps_user'] ?? '') ?>">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label for="backup_ftps_pass">Passwort *</label>
                    <input type="password" id="backup_ftps_pass" name="backup_ftps_pass" class="form-control" placeholder="<?= !empty($settings['backup_ftps_pass']) ? '•••••••• (unverändert)' : 'Passwort eingeben' ?>">
                    <small style="color: #666;">Wird mit AES-256-GCM verschlüsselt gespeichert.</small>
                </div>
            </div>

            <div class="form-group">
                <label for="backup_ftps_path">Zielverzeichnis</label>
                <input type="text" id="backup_ftps_path" name="backup_ftps_path" class="form-control" value="<?= htmlspecialchars($settings['backup_ftps_path'] ?? '') ?>" placeholder="/hengstverzeichnis-backups (muss bereits existieren)">
            </div>
        </div>

        <div id="backup-target-webdav" style="background: #f8f9fa; padding: 1.2rem; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 1.5rem;">
            <h4 style="margin-top: 0; color: var(--primary-color);">☁️ WebDAV</h4>

            <div class="form-group">
                <label for="backup_webdav_url">WebDAV-URL (bis einschließlich Zielordner) *</label>
                <input type="text" id="backup_webdav_url" name="backup_webdav_url" class="form-control" value="<?= htmlspecialchars($settings['backup_webdav_url'] ?? '') ?>" placeholder="https://cloud.beispiel-verband.de/remote.php/dav/files/verband/backups">
                <small style="color: #666;">Bei Nextcloud/ownCloud über "Einstellungen &rarr; Sicherheit &rarr; WebDAV" zu finden. Der Zielordner wird bei Bedarf automatisch angelegt.</small>
            </div>

            <div style="display: flex; gap: 1rem;">
                <div class="form-group" style="flex: 1;">
                    <label for="backup_webdav_user">Benutzername *</label>
                    <input type="text" id="backup_webdav_user" name="backup_webdav_user" class="form-control" value="<?= htmlspecialchars($settings['backup_webdav_user'] ?? '') ?>">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label for="backup_webdav_pass">Passwort / App-Passwort *</label>
                    <input type="password" id="backup_webdav_pass" name="backup_webdav_pass" class="form-control" placeholder="<?= !empty($settings['backup_webdav_pass']) ? '•••••••• (unverändert)' : 'Passwort eingeben' ?>">
                    <small style="color: #666;">Wird mit AES-256-GCM verschlüsselt gespeichert. Bei Nextcloud/ownCloud wird ein eigenes App-Passwort empfohlen statt des Hauptpassworts.</small>
                </div>
            </div>
        </div>

        <script>
            function updateBackupTargetVisibility(target) {
                ['s3', 'ftps', 'webdav'].forEach(function (t) {
                    document.getElementById('backup-target-' + t).style.display = (t === target) ? 'block' : 'none';
                });
            }
            updateBackupTargetVisibility(document.getElementById('backup_target').value);
        </script>

        <h4 style="color: var(--primary-color);">Zeitplan & Aufbewahrung</h4>

        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 1;">
                <label for="backup_interval_hours">Intervall (Stunden)</label>
                <input type="number" id="backup_interval_hours" name="backup_interval_hours" class="form-control" min="1" value="<?= htmlspecialchars((string)($settings['backup_interval_hours'] ?? '24')) ?>">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="backup_retention_count">Aufbewahrung (Anzahl Backups)</label>
                <input type="number" id="backup_retention_count" name="backup_retention_count" class="form-control" min="1" value="<?= htmlspecialchars((string)($settings['backup_retention_count'] ?? '14')) ?>">
                <small style="color: #666;">Ältere Backups werden nach jedem erfolgreichen Lauf automatisch gelöscht.</small>
            </div>
        </div>

        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
            <button type="submit" class="btn">Einstellungen Speichern</button>
            <a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a>
        </div>
    </form>

    <hr style="margin: 2rem 0; border: none; border-top: 1px solid var(--border-color);">

    <h3>🧪 Backup jetzt manuell ausführen</h3>
    <p style="color: #666; font-size: 0.9rem;">Führt sofort einen Backup-Lauf mit den oben gespeicherten Einstellungen aus, unabhängig vom konfigurierten Intervall.</p>
    <form action="/admin/backups/test" method="POST">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        <button type="submit" class="btn btn-secondary">💾 Jetzt sichern</button>
    </form>
</div>
