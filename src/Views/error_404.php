<?php
// src/Views/error_404.php
/**
 * @var string $title
 * @var string $message
 */
?>
<div class="card text-center" style="max-width: 600px; margin: 4rem auto; padding: 2.5rem 2rem;">
    <div style="font-size: 4rem; line-height: 1; margin-bottom: 1rem;">🔍</div>
    <h1 style="color: var(--primary-fg); font-size: 2rem; margin-bottom: 0.5rem;"><?= htmlspecialchars(App\I18n\Translator::t('errors.404_title')) ?></h1>
    <p style="font-size: 1.1rem; color: var(--text-muted); margin-bottom: 2rem;">
        <?= htmlspecialchars($message ?? App\I18n\Translator::t('errors.404_default_message')) ?>
    </p>

    <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
        <a href="/katalog" class="btn" style="min-width: 200px;"><?= htmlspecialchars(App\I18n\Translator::t('errors.404_to_catalog')) ?></a>
        <a href="/" class="btn btn-secondary" style="min-width: 200px;"><?= htmlspecialchars(App\I18n\Translator::t('errors.to_home')) ?></a>
    </div>
</div>
