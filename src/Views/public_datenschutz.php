<?php
// src/Views/public_datenschutz.php
?>
<div class="card" style="max-width: 800px; margin: 0 auto;">
    <h1><?= htmlspecialchars(App\I18n\Translator::t('legal.datenschutz_title')) ?></h1>

    <?php if (!empty($settings['datenschutz_text'])): ?>
        <div style="line-height: 1.6; font-size: 1.05rem;">
            <?= App\Helper\Markdown::parse($settings['datenschutz_text']) ?>
        </div>
    <?php else: ?>
        <p><em><?= htmlspecialchars(App\I18n\Translator::t('legal.datenschutz_placeholder_note')) ?></em></p>

        <h3><?= htmlspecialchars(App\I18n\Translator::t('legal.datenschutz_s1_heading')) ?></h3>
        <p>
            <?= htmlspecialchars(App\I18n\Translator::t('legal.datenschutz_s1_text')) ?>
        </p>

        <h3><?= htmlspecialchars(App\I18n\Translator::t('legal.datenschutz_s2_heading')) ?></h3>
        <p>
            <?= htmlspecialchars(App\I18n\Translator::t('legal.datenschutz_s2_text')) ?>
        </p>

        <h3><?= htmlspecialchars(App\I18n\Translator::t('legal.datenschutz_s3_heading')) ?></h3>
        <p>
            <?= htmlspecialchars(App\I18n\Translator::t('legal.datenschutz_s3_text')) ?>
        </p>
    <?php endif; ?>

    <div style="margin-top: 2rem;">
        <a href="/dsgvo" class="btn"><?= htmlspecialchars(App\I18n\Translator::t('legal.datenschutz_cta')) ?></a>
    </div>
</div>
