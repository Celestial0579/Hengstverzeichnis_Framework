<?php
// src/Views/public_impressum.php
?>
<div class="card" style="max-width: 800px; margin: 0 auto;">
    <h1>Impressum</h1>
    
    <?php if (!empty($settings['impressum_text'])): ?>
        <div style="line-height: 1.6; font-size: 1.05rem;">
            <?= App\Helper\Markdown::parse($settings['impressum_text']) ?>
        </div>
    <?php else: ?>
        <p><em>(Hinweis: Diese Seite dient als Platzhalter im Framework und sollte in den Branding-Einstellungen unter `/admin/settings` angepasst werden.)</em></p>

        <h3>Angaben gemäß § 5 TMG</h3>
        <p>
            <?= htmlspecialchars($settings['site_name'] ?? 'Musterverein e.V.') ?><br>
            Musterstraße 1<br>
            12345 Musterstadt
        </p>

        <h3>Vertreten durch:</h3>
        <p>Max Mustermann (1. Vorsitzender)</p>

        <h3>Kontakt</h3>
        <p>
            Telefon: +49 (0) 123 44 55 66<br>
            E-Mail: info@musterverein.de
        </p>
    <?php endif; ?>

    <div style="margin-top: 2rem;">
        <a href="/dsgvo" class="btn btn-secondary">Zur DSGVO-Anfrage</a>
    </div>
</div>
