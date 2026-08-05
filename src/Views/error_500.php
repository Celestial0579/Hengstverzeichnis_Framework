<?php
// src/Views/error_500.php
/**
 * @var string $title
 * @var string $message
 */
?>
<div class="card text-center" style="max-width: 600px; margin: 4rem auto; padding: 2.5rem 2rem;">
    <div style="font-size: 4rem; line-height: 1; margin-bottom: 1rem;">⚠️</div>
    <h1 style="color: #dc3545; font-size: 2rem; margin-bottom: 0.5rem;"><?= htmlspecialchars(App\I18n\Translator::t('errors.500_title')) ?></h1>
    <p style="font-size: 1.1rem; color: #555; margin-bottom: 2rem;">
        <?= htmlspecialchars($message ?? App\I18n\Translator::t('errors.500_default_message')) ?>
    </p>

    <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
        <a href="/" class="btn" style="min-width: 200px;"><?= htmlspecialchars(App\I18n\Translator::t('errors.to_home')) ?></a>
    </div>
</div>
