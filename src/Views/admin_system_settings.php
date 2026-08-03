<?php
// src/Views/admin_system_settings.php
?>
<div class="card">
    <h2>⚙️ Systemeinstellungen</h2>
    <p>Verwalten Sie globale Systemparameter, Stamm-URLs und Wartungsoptionen.</p>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            Systemeinstellungen erfolgreich gespeichert.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['warning']) && $_GET['warning'] === 'http_unencrypted'): ?>
        <div style="background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 1rem; border-radius: 6px; margin-bottom: 1rem; font-weight: 500;">
            ⚠️ <strong>Sicherheits-Warnung:</strong> Die Stamm-URL wurde als unverschlüsselte HTTP-Adresse (<code>http://...</code>) gespeichert. Für eine sichere Übertragung von Zugangsdaten und Passwörtern wird die Verwendung von HTTPS (<code>https://...</code> mit SSL-Zertifikat) dringend empfohlen!
        </div>
    <?php endif; ?>

    <form action="/admin/system-settings" method="POST" style="max-width: 600px;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <div class="form-group">
            <label for="base_url">🌐 Stamm-URL der Webseite (z. B. https://hengstverzeichnis.de/)</label>
            <input type="url" id="base_url" name="base_url" class="form-control" placeholder="https://hengstverzeichnis.de/" value="<?= htmlspecialchars($settings['base_url'] ?? '') ?>">
            <small style="color: #666; display: block; margin-top: 0.3rem;">
                Basis-Adresse der Instanz inklusive Protokoll (`https://`) und abschließendem Slash (`/`). Wird u. a. für E-Mail-Links, Canonical URLs und Systembenachrichtigungen genutzt.
            </small>
        </div>

        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
            <button type="submit" class="btn">Speichern</button>
            <a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a>
        </div>
    </form>
</div>

<!-- Danger Zone: System Zurücksetzen -->
<div class="card" style="border: 2px solid #dc3545; background-color: #fff8f8; margin-top: 3rem;">
    <h3 style="color: #dc3545; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
        ⚠️ Danger Zone: System zurücksetzen
    </h3>
    <p style="color: #666; font-size: 0.95rem; margin-bottom: 1.5rem;">
        Hier können Sie das gesamte System zurücksetzen. Alle Benutzer, Pferde, Einstellungen und Nachrichten werden unwiderruflich aus der Datenbank gelöscht. Danach wird der **Setup-Wizard** neu gestartet.
    </p>

    <?php if (isset($_GET['error']) && $_GET['error'] === 'reset_confirm_failed'): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 0.8rem; border-radius: 4px; margin-bottom: 1rem;">
            Bestätigung fehlgeschlagen! Sie müssen exakt den Text <strong>RESET</strong> eingeben.
        </div>
    <?php endif; ?>

    <form action="/admin/reset" method="POST" style="max-width: 500px;" onsubmit="return confirm('WARNUNG: Möchten Sie wirklich ALLE Daten unwiderruflich löschen?');">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <div class="form-group">
            <label for="confirm_text" style="color: #dc3545; font-weight: bold;">
                Geben Sie "RESET" ein, um das Zurücksetzen zu bestätigen:
            </label>
            <input type="text" id="confirm_text" name="confirm_text" class="form-control" placeholder="RESET" required style="border-color: #dc3545;">
        </div>

        <button type="submit" class="btn" style="background-color: #dc3545;">🔥 Instanz unwiderruflich zurücksetzen</button>
    </form>
</div>
