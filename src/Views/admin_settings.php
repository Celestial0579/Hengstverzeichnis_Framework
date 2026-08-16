<?php
// src/Views/admin_settings.php
?>
<div class="card">
    <h2>🎨 Branding & Erscheinungsbild</h2>
    <p>Passen Sie Namen, Logo, Farben und Texte des Frameworks für Ihren Verband an.</p>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            Branding-Einstellungen erfolgreich gespeichert.
        </div>
    <?php endif; ?>

    <form action="/admin/settings" method="POST" enctype="multipart/form-data" style="max-width: 600px;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <div class="form-group">
            <label for="site_name">Name des Verbands / der Anwendung</label>
            <input type="text" id="site_name" name="site_name" class="form-control" value="<?= htmlspecialchars($settings['site_name'] ?? 'Hengstverzeichnis') ?>" required>
        </div>

        <div class="form-group">
            <label for="copyright_holder">Copyright-Inhaber / Betreiber (Footer)</label>
            <input type="text" id="copyright_holder" name="copyright_holder" class="form-control" placeholder="Optional, falls abweichend vom Anwendungstitel" value="<?= htmlspecialchars($settings['copyright_holder'] ?? '') ?>">
            <small style="color: var(--text-muted); display: block; margin-top: 0.3rem;">
                Name des Rechteinhabers für den Footer (z. B. Vereins- oder Verbandsname). Bleibt das Feld leer, wird der oben stehende Name verwendet.
            </small>
        </div>

        <div class="form-group" style="background: var(--surface-muted); padding: 1rem; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 1.5rem;">
            <label for="logo_file" style="font-weight: bold; color: var(--primary-fg);">🖼️ Verbands-Logo hochladen</label>

            <?php if (!empty($settings['site_logo'])): ?>
                <div style="display: flex; align-items: center; gap: 1rem; margin: 0.8rem 0; background: var(--card-bg); padding: 0.8rem; border-radius: 6px; border: 1px solid var(--border-color);">
                    <img src="<?= htmlspecialchars($settings['site_logo']) ?>" alt="Logo Vorschau" style="max-height: 60px; max-width: 200px; object-fit: contain;">
                    <div>
                        <label style="color: var(--danger-fg); font-size: 0.9rem; cursor: pointer;">
                            <input type="checkbox" name="remove_logo" value="1"> 🗑️ Logo entfernen
                        </label>
                    </div>
                </div>
            <?php endif; ?>

            <input type="file" id="logo_file" name="logo_file" accept="image/png,image/jpeg,image/svg+xml,image/webp" class="form-control">
            <small style="color: var(--text-muted); display: block; margin-top: 0.3rem;">Erlaubte Formate: PNG, SVG, JPG, WEBP (Max. 5 MB).</small>
        </div>

        <div class="form-group">
            <label for="primary_color">Primärfarbe (Hex-Code, z.B. #2c3e50)</label>
            <input type="color" id="primary_color" name="primary_color" class="form-control" style="height: 50px;" value="<?= htmlspecialchars($settings['primary_color'] ?? '#2c3e50') ?>">
        </div>

        <div class="form-group">
            <label for="secondary_color">Sekundärfarbe (Hex-Code, z.B. #18bc9c)</label>
            <input type="color" id="secondary_color" name="secondary_color" class="form-control" style="height: 50px;" value="<?= htmlspecialchars($settings['secondary_color'] ?? '#18bc9c') ?>">
        </div>

        <!-- Startseiten-Inhalt -->
        <h3 style="margin-top: 2rem; margin-bottom: 1rem; color: var(--primary-fg); border-bottom: 1px solid var(--border-color); padding-bottom: 0.3rem;">
            🏠 Startseiten-Inhalte (Homepage)
        </h3>

        <div class="form-group">
            <label for="home_title">Startseiten Haupt-Überschrift (Hero Titel)</label>
            <input type="text" id="home_title" name="home_title" class="form-control" placeholder="Willkommen im <?= htmlspecialchars($settings['site_name'] ?? 'Hengstverzeichnis') ?>" value="<?= htmlspecialchars($settings['home_title'] ?? '') ?>">
            <small style="color: var(--text-muted);">Standard falls leer: "Willkommen im [Verbandsname]".</small>
        </div>

        <div class="form-group">
            <label for="home_text">Startseiten Willkommenstext / Beschreibung</label>
            <textarea id="home_text" name="home_text" class="form-control" rows="5" placeholder="Das Open-Source Framework zur Nachverfolgung von Blutlinien in der Pferdezucht."><?= htmlspecialchars($settings['home_text'] ?? '') ?></textarea>
            <small style="color: var(--text-muted);">Wird auf der Startseite (`/`) angezeigt. Unterstützt Markdown (Fett, Links, Aufzählungen).</small>
        </div>

        <!-- Rechtliche Texte (Impressum & Datenschutz) -->
        <h3 style="margin-top: 2rem; margin-bottom: 1rem; color: var(--primary-fg); border-bottom: 1px solid var(--border-color); padding-bottom: 0.3rem;">
            📜 Rechtliche Seiten (Impressum & Datenschutz)
        </h3>

        <div class="form-group">
            <label for="impressum_text">Impressum Text / Anbieterkennzeichnung</label>
            <textarea id="impressum_text" name="impressum_text" class="form-control" rows="6" placeholder="Angaben gemäß § 5 TMG&#10;Musterverein e.V.&#10;Vertreten durch: Max Mustermann&#10;Kontakt: info@musterverein.de"><?= htmlspecialchars($settings['impressum_text'] ?? '') ?></textarea>
            <small style="color: var(--text-muted);">Wird auf der öffentlichen Impressums-Seite (`/impressum`) angezeigt.</small>
        </div>

        <div class="form-group">
            <label for="datenschutz_text">Datenschutzerklärung (DSGVO)</label>
            <textarea id="datenschutz_text" name="datenschutz_text" class="form-control" rows="8" placeholder="Informationen zur Datenverarbeitung gemäß DSGVO..."><?= htmlspecialchars($settings['datenschutz_text'] ?? '') ?></textarea>
            <small style="color: var(--text-muted);">Wird auf der öffentlichen Datenschutz-Seite (`/datenschutz`) angezeigt.</small>
        </div>

        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
            <button type="submit" class="btn">Speichern</button>
            <a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a>
        </div>
    </form>
</div>
