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

    <?php if (isset($_GET['error']) && $_GET['error'] === 'invalid_base_url'): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            Ungültiges Format der Stamm-URL. Bitte eine gültige Adresse angeben (z. B. <code>https://hengstverzeichnis.de/</code>). Es wurden keine Änderungen gespeichert.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) && $_GET['error'] === 'trusted_proxies_invalid'): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            Ungültiger Eintrag bei „Vertrauenswürdige Reverse-Proxy-IPs": <code><?= htmlspecialchars($_GET['invalid_entry'] ?? '') ?></code>.
            Bitte einzelne IP-Adressen oder CIDR-Netze (z. B. <code>10.0.0.5</code> oder <code>172.16.0.0/12</code>), kommagetrennt, angeben. Stamm-URL wurde trotzdem gespeichert.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) && $_GET['error'] === 'trusted_proxies_write_failed'): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            „Vertrauenswürdige Reverse-Proxy-IPs" konnten nicht gespeichert werden: <code>config/db_config.php</code> ist nicht beschreibbar. Bitte Schreibrechte im Ordner <code>config/</code> prüfen.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) && $_GET['error'] === 'tracking_domains_invalid'): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            Ungültiger Eintrag bei „Tracking-Domains": <code><?= htmlspecialchars($_GET['invalid_entry'] ?? '') ?></code>.
            Bitte nur vollständige <code>https://</code>-Adressen ohne Pfad, kommagetrennt, angeben (z. B. <code>https://analytics.example.com</code>). Übrige Einstellungen wurden trotzdem gespeichert.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) && $_GET['error'] === 'tracking_domains_write_failed'): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            „Tracking-Domains" konnten nicht gespeichert werden: <code>config/db_config.php</code> ist nicht beschreibbar. Bitte Schreibrechte im Ordner <code>config/</code> prüfen.
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

        <div class="form-group" style="margin-top: 1.5rem;">
            <label for="language">🌍 Standardsprache</label>
            <select id="language" name="language" class="form-control">
                <?php foreach ($availableLocales as $code => $label): ?>
                    <option value="<?= htmlspecialchars($code) ?>" <?= ($settings['language'] ?? 'de') === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <small style="color: #666; display: block; margin-top: 0.3rem;">
                Standardsprache der öffentlichen Seiten (#48). Besucher können sie über den Sprachumschalter im Footer für ihre eigene Sitzung übersteuern.
                Weitere Sprachen sowie Übersetzungen im Admin-Bereich folgen schrittweise.
            </small>
        </div>

        <?php if (!empty($registeredFeatures)): ?>
            <div class="form-group" style="margin-top: 1.5rem;">
                <label>✨ Sichtbarkeit von Zusatzfunktionen</label>
                <small style="color: #666; display: block; margin-bottom: 0.5rem;">
                    Von Plugins bereitgestellte Zusatzfunktionen (#57): „Öffentlich" sehen alle Besucher,
                    „Nur für Gruppen mit Leseberechtigung" sehen ausschließlich angemeldete Benutzer,
                    deren Gruppe die jeweilige Leseberechtigung besitzt (zuweisbar unter
                    <a href="/admin/groups">Gruppen &amp; Berechtigungen</a>; Administratoren immer).
                </small>
                <?php foreach ($registeredFeatures as $featureKey => $featureDef): ?>
                    <?php $currentVisibility = $settings['feature_visibility__' . $featureKey] ?? $featureDef['default']; ?>
                    <div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0; border-bottom: 1px solid var(--border-color);">
                        <span style="flex: 1;"><?= htmlspecialchars($featureDef['label']) ?> <code style="font-size: 0.8rem; color: #888;"><?= htmlspecialchars($featureKey) ?></code></span>
                        <select name="feature_visibility[<?= htmlspecialchars($featureKey) ?>]" class="form-control" style="max-width: 320px;">
                            <option value="public" <?= $currentVisibility === 'public' ? 'selected' : '' ?>>Öffentlich</option>
                            <option value="members" <?= $currentVisibility === 'members' ? 'selected' : '' ?>>Nur für Gruppen mit Leseberechtigung</option>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="form-group" style="margin-top: 1.5rem;">
            <label for="trusted_proxies">🛡️ Vertrauenswürdige Reverse-Proxy-IPs (TRUSTED_PROXIES)</label>
            <input
                type="text"
                id="trusted_proxies"
                name="trusted_proxies"
                class="form-control"
                placeholder="z. B. 10.0.0.5,172.16.0.0/12"
                value="<?= htmlspecialchars($trustedProxies ?? '') ?>"
                <?= !empty($trustedProxiesFromEnv) ? 'disabled' : '' ?>
            >
            <small style="color: #666; display: block; margin-top: 0.3rem;">
                <?php if (!empty($trustedProxiesFromEnv)): ?>
                    Wird aktuell über die Umgebungsvariable <code>TRUSTED_PROXIES</code> gesetzt — diese hat Vorrang und kann hier nicht überschrieben werden.
                <?php else: ?>
                    Nur nötig, wenn ein Reverse Proxy/Load Balancer vor dieser Instanz läuft (nginx, Traefik, Cloudflare, CDN des Hosters o. Ä.).
                    Kommagetrennte Liste vertrauenswürdiger Proxy-IPs/-Netze (CIDR). <strong>Ohne eigenen Reverse Proxy bitte leer lassen</strong> —
                    eine falsche Angabe erlaubt es Besuchern, ihre eigene IP-Adresse über den <code>X-Forwarded-For</code>-Header vorzutäuschen und damit
                    Login-Rate-Limiting sowie Audit-Log-Einträge zu manipulieren. Details siehe
                    <a href="https://github.com/Celestial0579/Hengstverzeichnis_Framework#reverse-proxy--client-ip-erkennung" target="_blank" rel="noopener">README</a>.
                <?php endif; ?>
            </small>
        </div>

        <div class="form-group" style="margin-top: 1.5rem;">
            <label for="tracking_domains">📊 Tracking-Domains (für Matomo/Google Analytics o. Ä.)</label>
            <input
                type="text"
                id="tracking_domains"
                name="tracking_domains"
                class="form-control"
                placeholder="z. B. https://www.googletagmanager.com,https://www.google-analytics.com"
                value="<?= htmlspecialchars($trackingDomains ?? '') ?>"
                <?= !empty($trackingDomainsFromEnv) ? 'disabled' : '' ?>
            >
            <small style="color: #666; display: block; margin-top: 0.3rem;">
                <?php if (!empty($trackingDomainsFromEnv)): ?>
                    Wird aktuell über die Umgebungsvariable <code>TRACKING_DOMAINS</code> gesetzt — diese hat Vorrang und kann hier nicht überschrieben werden.
                <?php else: ?>
                    Kommagetrennte Liste von <code>https://</code>-Origins (ohne Pfad), die für den unten eingetragenen Tracking-Code in der
                    Content-Security-Policy freigeschaltet werden müssen. <strong>Ohne Eintrag hier wird der Tracking-Code vom Browser lautlos
                    blockiert</strong>, da die Policy standardmäßig nur Ressourcen von dieser Seite selbst erlaubt.
                <?php endif; ?>
            </small>
        </div>

        <div class="form-group" style="margin-top: 1.5rem;">
            <label for="tracking_code">📈 Tracking-Code (Matomo-/Google-Analytics-Snippet)</label>
            <textarea id="tracking_code" name="tracking_code" class="form-control" rows="5" placeholder="&lt;script&gt;...&lt;/script&gt;" style="font-family: monospace; font-size: 0.85rem;"><?= htmlspecialchars($settings['tracking_code'] ?? '') ?></textarea>
            <small style="color: #666; display: block; margin-top: 0.3rem;">
                Wird unverändert vor <code>&lt;/head&gt;</code> auf jeder Seite eingefügt. Nur für vertrauenswürdigen Code von Matomo, Google Analytics
                o. Ä. verwenden — der Inhalt wird bewusst nicht escaped, damit <code>&lt;script&gt;</code>-Tags funktionieren. Denken Sie an Ihre
                DSGVO-Pflichten (z. B. Cookie-Consent), bevor Sie Tracking aktivieren.
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
