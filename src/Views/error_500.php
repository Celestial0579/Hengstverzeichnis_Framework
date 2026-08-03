<?php
// src/Views/error_500.php
/**
 * @var string $title
 * @var string $message
 */
?>
<div class="card text-center" style="max-width: 600px; margin: 4rem auto; padding: 2.5rem 2rem;">
    <div style="font-size: 4rem; line-height: 1; margin-bottom: 1rem;">⚠️</div>
    <h1 style="color: #dc3545; font-size: 2rem; margin-bottom: 0.5rem;">500 - Serverfehler</h1>
    <p style="font-size: 1.1rem; color: #555; margin-bottom: 2rem;">
        <?= htmlspecialchars($message ?? 'Unerwarteter Systemfehler. Bitte versuchen Sie es zu einem späteren Zeitpunkt erneut.') ?>
    </p>

    <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
        <a href="/" class="btn" style="min-width: 200px;">🏠 Zur Startseite</a>
    </div>
</div>
