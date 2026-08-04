<?php
// src/Views/public_dsgvo.php
?>
<div class="card" style="max-width: 650px; margin: 0 auto;">
    <h2>Datenschutz-Anfrage (DSGVO)</h2>
    <p>Hier können Sie Auskunft über Ihre gespeicherten personenbezogenen Daten anfordern oder deren Löschung bzw. Anonymisierung beantragen.</p>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            ✓ Ihre Anfrage wurde erfolgreich übermittelt. Wir werden diese gemäß den gesetzlichen Vorgaben bearbeiten.
        </div>
    <?php endif; ?>

    <?php if (($_GET['error'] ?? '') === 'rate_limited'): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            Zu viele Anfragen von Ihrer Adresse. Bitte versuchen Sie es in 15 Minuten erneut.
        </div>
    <?php endif; ?>

    <form action="/dsgvo" method="POST" style="margin-top: 1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        
        <div class="form-group">
            <label for="name">Ihr Name (Betroffene Person)</label>
            <input type="text" id="name" name="name" class="form-control" placeholder="z. B. Max Mustermann">
        </div>

        <div class="form-group">
            <label for="email">Ihre E-Mail-Adresse *</label>
            <input type="email" id="email" name="email" class="form-control" placeholder="ihre.email@beispiel.de" required>
            <small style="color: #666;">Wir benötigen Ihre E-Mail, um Sie identifizieren und bezüglich Ihrer Anfrage kontaktieren zu können.</small>
        </div>

        <div class="form-group">
            <label for="request_type">Art der Anfrage *</label>
            <select id="request_type" name="request_type" class="form-control" required>
                <option value="info">Auskunft über meine gespeicherten Daten anfordern (Art. 15 DSGVO)</option>
                <option value="deletion">Löschung / Anonymisierung meiner Daten beantragen (Art. 17 DSGVO)</option>
            </select>
        </div>

        <div class="form-group">
            <label for="message">Ihre Nachricht / Details zur Anfrage (optional)</label>
            <textarea id="message" name="message" class="form-control" rows="4" placeholder="Geben Sie hier optionale Hinweise an (z. B. Züchter- / Besitzername, betroffene Hengste oder spezifische Wünsche zur Anonymisierung)."></textarea>
        </div>

        <button type="submit" class="btn mt-2">🛡️ Datenschutz-Anfrage absenden</button>
    </form>
</div>
