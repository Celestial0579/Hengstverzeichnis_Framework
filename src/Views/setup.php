<?php
// src/Views/setup.php
/**
 * @var array|null $errors
 * @var array|null $old
 * @var bool|null $hideDb Datenbank-Abschnitt ausblenden, weil DB_HOST/DB_USER/DB_PASS bereits per Env-Variable gesetzt sind
 * @var bool|null $hideSite Verbandsname-Abschnitt ausblenden, weil SITE_NAME bereits per Env-Variable gesetzt ist
 */
$hideDb = $hideDb ?? false;
$hideSite = $hideSite ?? false;
?>
<div class="card" style="max-width: 650px; margin: 3rem auto;">
    <h1 style="border-bottom: 2px solid var(--primary-color); padding-bottom: 0.5rem; margin-bottom: 1rem;">
        Willkommen beim Hengstverzeichnis Framework
    </h1>
    <p style="color: #666; margin-bottom: 1.5rem;">
        <?php if ($hideDb && $hideSite): ?>
            Willkommen beim Erst-Einrichtungsassistenten. Bitte erstellen Sie Ihr erstes Administrator-Konto.
        <?php else: ?>
            Willkommen beim Erst-Einrichtungsassistenten. Bitte konfigurieren Sie Ihre Datenbankverbindung, die Verbandseinstellungen und erstellen Sie Ihr erstes Administrator-Konto.
        <?php endif; ?>
    </p>

    <?php if (!empty($errors)): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            <ul style="margin-left: 1.2rem;">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($hideDb || $hideSite): ?>
        <div style="background-color: #d1ecf1; color: #0c5460; padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem; font-size: 0.9rem;">
            <?= $hideDb && $hideSite ? 'Datenbankverbindung und Verbandsname sind bereits über Umgebungsvariablen konfiguriert.' : ($hideDb ? 'Die Datenbankverbindung ist bereits über Umgebungsvariablen konfiguriert.' : 'Der Verbandsname ist bereits über die Umgebungsvariable SITE_NAME konfiguriert.') ?>
            Es wird nur noch das erste Administrator-Konto benötigt.
        </div>
    <?php endif; ?>

    <form action="/setup" method="POST">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <?php if (!$hideDb): ?>
        <!-- 1. Datenbank-Einstellungen -->
        <h3 style="margin-bottom: 1rem; color: var(--primary-color); border-bottom: 1px solid #eee; padding-bottom: 0.3rem;">
            1. Datenbank-Verbindung (MySQL/MariaDB)
        </h3>

        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 3;">
                <label for="db_host">Server / Host *</label>
                <input type="text" id="db_host" name="db_host" class="form-control" value="<?= htmlspecialchars($old['db_host'] ?? '127.0.0.1') ?>" required>
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="db_port">Port *</label>
                <input type="number" id="db_port" name="db_port" class="form-control" value="<?= htmlspecialchars($old['db_port'] ?? '3306') ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label for="db_name">Datenbank-Name *</label>
            <input type="text" id="db_name" name="db_name" class="form-control" value="<?= htmlspecialchars($old['db_name'] ?? 'hengstverzeichnis') ?>" required>
        </div>

        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 1;">
                <label for="db_user">Datenbank-Benutzer *</label>
                <input type="text" id="db_user" name="db_user" class="form-control" value="<?= htmlspecialchars($old['db_user'] ?? 'hengst_user') ?>" required>
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="db_pass">Datenbank-Passwort</label>
                <input type="password" id="db_pass" name="db_pass" class="form-control" value="<?= htmlspecialchars($old['db_pass'] ?? '') ?>">
            </div>
        </div>

        <!-- Verschlüsselte SQL Verbindung (SSL/TLS) -->
        <div style="background: #f8f9fa; padding: 1rem; border-radius: 8px; border: 1px solid #e0e0e0; margin-top: 1rem; margin-bottom: 1.5rem;">
            <label style="font-weight: bold; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem;">
                🔒 Verschlüsselte Datenbank-Verbindung (SSL/TLS)
            </label>
            <p style="font-size: 0.85rem; color: #666; margin: 0.3rem 0 0.8rem 0;">
                Aktivieren Sie SSL/TLS für eine Ende-zu-Ende verschlüsselte Verbindung zwischen dem Webserver und dem Datenbankserver (z. B. bei Managed Cloud Databases).
            </p>

            <div class="form-group" style="margin-bottom: 0.5rem;">
                <label style="cursor: pointer; font-weight: 500;">
                    <input type="checkbox" id="db_ssl" name="db_ssl" value="1" <?= !empty($old['db_ssl']) ? 'checked' : '' ?> onchange="document.getElementById('ssl_options').style.display = this.checked ? 'block' : 'none';">
                    SSL/TLS-Verschlüsselung erzwingen
                </label>
            </div>

            <div id="ssl_options" style="display: <?= !empty($old['db_ssl']) ? 'block' : 'none' ?>; margin-top: 0.8rem; padding-left: 1.2rem; border-left: 3px solid var(--primary-color);">
                <div class="form-group" style="margin-bottom: 0.5rem;">
                    <label style="cursor: pointer;">
                        <input type="checkbox" name="db_ssl_verify" value="1" <?= !empty($old['db_ssl_verify']) ? 'checked' : '' ?>>
                        SSL-Serverzertifikat verifizieren (Empfohlen)
                    </label>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="db_ssl_ca">Pfad zum CA-Zertifikat (Optional, z. B. <code>/etc/ssl/certs/ca-certificates.crt</code>)</label>
                    <input type="text" id="db_ssl_ca" name="db_ssl_ca" class="form-control" placeholder="Optionaler Zertifikatspfad" value="<?= htmlspecialchars($old['db_ssl_ca'] ?? '') ?>">
                </div>
            </div>
        </div>

        <!-- Danger Zone for Overwriting Database -->
        <div style="border: 2px solid #dc3545; background-color: #fff8f8; padding: 1rem; border-radius: 6px; margin-top: 1rem; margin-bottom: 1.5rem;">
            <label style="display: flex; align-items: flex-start; gap: 0.6rem; color: #dc3545; font-weight: bold; cursor: pointer;">
                <input type="checkbox" name="overwrite_db" value="1" style="margin-top: 3px; width: 18px; height: 18px;">
                <span>
                    ⚠️ Danger Zone: Bestehende Datenbank neu erstellen / überschreiben<br>
                    <span style="font-weight: normal; font-size: 0.85rem; color: #666;">
                        Aktivieren Sie dies nur, wenn Sie eine bereits existierende Datenbank <strong>vollständig löschen (DROP DATABASE)</strong> und neu initialisieren möchten.
                    </span>
                </span>
            </label>
        </div>
        <?php endif; ?>

        <?php if (!$hideSite): ?>
        <!-- 2. Verbandseinstellungen -->
        <h3 style="margin-top: 1.5rem; margin-bottom: 1rem; color: var(--primary-color); border-bottom: 1px solid #eee; padding-bottom: 0.3rem;">
            2. Verbandseinstellungen
        </h3>
        <div class="form-group">
            <label for="site_name">Name des Verbands / der Seite *</label>
            <input type="text" id="site_name" name="site_name" class="form-control" value="<?= htmlspecialchars($old['site_name'] ?? 'Hengstverzeichnis') ?>" required>
        </div>
        <?php endif; ?>

        <!-- 3. Administrator-Konto -->
        <h3 style="margin-top: 1.5rem; margin-bottom: 1rem; color: var(--primary-color); border-bottom: 1px solid #eee; padding-bottom: 0.3rem;">
            3. Erstes Administrator-Konto
        </h3>
        <div class="form-group">
            <label for="username">Benutzername *</label>
            <input type="text" id="username" name="username" class="form-control" value="<?= htmlspecialchars($old['username'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label for="email">E-Mail-Adresse *</label>
            <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($old['email'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label for="password">Passwort * (Mindestens 8 Zeichen)</label>
            <input type="password" id="password" name="password" class="form-control" minlength="8" required>
        </div>

        <div class="form-group">
            <label for="password_confirm">Passwort wiederholen *</label>
            <input type="password" id="password_confirm" name="password_confirm" class="form-control" minlength="8" required>
        </div>

        <button type="submit" class="btn mt-2" style="width: 100%; font-size: 1.1rem; padding: 0.8rem;">
            Einrichtung abschließen & System starten
        </button>
    </form>
</div>
