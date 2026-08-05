<?php
// src/Views/public_home.php
/**
 * @var array $featuredHorses
 */

?>
<div class="text-center" style="padding: 4rem 0;">
    <h1 style="font-size: 3rem; margin-bottom: 1rem;">
        <?= htmlspecialchars(!empty($settings['home_title']) ? $settings['home_title'] : App\I18n\Translator::t('home.welcome_title', ['site' => $settings['site_name'] ?? 'Hengstverzeichnis'])) ?>
    </h1>
    <div style="font-size: 1.2rem; color: #666; max-width: 700px; margin: 0 auto 2rem auto; text-align: center;">
        <?php if (!empty($settings['home_text'])): ?>
            <?= App\Helper\Markdown::parse($settings['home_text']) ?>
        <?php else: ?>
            <p><?= htmlspecialchars(App\I18n\Translator::t('home.default_text')) ?></p>
        <?php endif; ?>
    </div>
    <a href="/katalog" class="btn" style="font-size: 1.1rem; padding: 1rem 2rem;"><?= htmlspecialchars(App\I18n\Translator::t('home.cta_catalog')) ?></a>
</div>

<div class="mt-2">
    <h2 class="text-center mb-2"><?= htmlspecialchars(App\I18n\Translator::t('home.latest_entries')) ?></h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
        <?php foreach ($featuredHorses as $horse): ?>
            <div class="card" style="text-align: center;">
                <h3><?= htmlspecialchars((string)$horse['name']) ?></h3>
                <p><?= htmlspecialchars(App\I18n\Translator::t('field.color')) ?>: <?= htmlspecialchars((string)$horse['color']) ?></p>
                <div style="margin-top: 1rem;">
                    <a href="/hengst?id=<?= $horse['id'] ?>" class="btn btn-secondary"><?= htmlspecialchars(App\I18n\Translator::t('home.view_details')) ?></a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
