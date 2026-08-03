<?php
// src/Views/public_datenschutz.php
?>
<div class="card" style="max-width: 800px; margin: 0 auto;">
    <h1>Datenschutzerklärung</h1>
    
    <?php if (!empty($settings['datenschutz_text'])): ?>
        <div style="line-height: 1.6; font-size: 1.05rem;">
            <?= App\Helper\Markdown::parse($settings['datenschutz_text']) ?>
        </div>
    <?php else: ?>
        <p><em>(Hinweis: Dies ist eine Vorlage und sollte in den Branding-Einstellungen unter `/admin/settings` angepasst werden.)</em></p>

        <h3>1. Datenschutz auf einen Blick</h3>
        <p>
            Wir nehmen den Schutz Ihrer persönlichen Daten sehr ernst. Wir behandeln Ihre personenbezogenen Daten vertraulich und entsprechend der gesetzlichen Datenschutzvorschriften sowie dieser Datenschutzerklärung.
        </p>

        <h3>2. Datenerfassung auf dieser Website</h3>
        <p>
            Die Datenerfassung auf dieser Website erfolgt durch den Websitebetreiber. Personenbezogene Daten werden insbesondere dann erhoben, wenn Sie uns diese über ein Kontakt- oder DSGVO-Anfrageformular mitteilen.
        </p>
        
        <h3>3. Ihre Rechte (DSGVO)</h3>
        <p>
            Sie haben jederzeit das Recht, unentgeltlich Auskunft über Herkunft, Empfänger und Zweck Ihrer gespeicherten personenbezogenen Daten zu erhalten. Sie haben außerdem ein Recht, die Berichtigung oder Löschung dieser Daten zu verlangen. Hierzu können Sie unser bereitgestelltes Formular nutzen.
        </p>
    <?php endif; ?>

    <div style="margin-top: 2rem;">
        <a href="/dsgvo" class="btn">Formular für DSGVO-Anfragen öffnen</a>
    </div>
</div>
