<?php
// src/Views/public_impressum.php
?>
<div class="card" style="max-width: 800px; margin: 0 auto;">
    <h1><?= htmlspecialchars(App\I18n\Translator::t('legal.impressum_title')) ?></h1>

    <?php if (!empty($settings['impressum_text'])): ?>
        <div style="line-height: 1.6; font-size: 1.05rem;">
            <?= App\Helper\Markdown::parse($settings['impressum_text']) ?>
        </div>
    <?php else: ?>
        <p><em><?= htmlspecialchars(App\I18n\Translator::t('legal.impressum_placeholder_note')) ?></em></p>

        <h3><?= htmlspecialchars(App\I18n\Translator::t('legal.impressum_section_ddg')) ?></h3>
        <p>
            <?= htmlspecialchars($settings['site_name'] ?? 'Musterverein e.V.') ?><br>
            <?= htmlspecialchars(App\I18n\Translator::t('legal.sample_address')) ?><br>
            <?= htmlspecialchars(App\I18n\Translator::t('legal.sample_city')) ?>
        </p>

        <h3><?= htmlspecialchars(App\I18n\Translator::t('legal.represented_by')) ?></h3>
        <p><?= htmlspecialchars(App\I18n\Translator::t('legal.sample_name')) ?></p>

        <h3><?= htmlspecialchars(App\I18n\Translator::t('legal.contact_heading')) ?></h3>
        <p>
            <?= htmlspecialchars(App\I18n\Translator::t('legal.sample_phone')) ?><br>
            <?= htmlspecialchars(App\I18n\Translator::t('legal.sample_email')) ?>
        </p>
    <?php endif; ?>

    <div style="margin-top: 2rem;">
        <a href="/dsgvo" class="btn btn-secondary"><?= htmlspecialchars(App\I18n\Translator::t('legal.dsgvo_cta')) ?></a>
    </div>
</div>
