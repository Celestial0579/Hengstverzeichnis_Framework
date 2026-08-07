<?php
// src/Views/public_dsgvo.php
?>
<div class="card" style="max-width: 650px; margin: 0 auto;">
    <h2><?= htmlspecialchars(App\I18n\Translator::t('dsgvo.heading')) ?></h2>
    <p><?= htmlspecialchars(App\I18n\Translator::t('dsgvo.intro')) ?></p>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars(App\I18n\Translator::t('dsgvo.success')) ?>
        </div>
    <?php endif; ?>

    <?php if (($_GET['error'] ?? '') === 'rate_limited'): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars(App\I18n\Translator::t('dsgvo.rate_limited')) ?>
        </div>
    <?php endif; ?>

    <form action="/dsgvo" method="POST" style="margin-top: 1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <div class="form-group">
            <label for="name"><?= htmlspecialchars(App\I18n\Translator::t('dsgvo.name_label')) ?></label>
            <input type="text" id="name" name="name" class="form-control" placeholder="<?= htmlspecialchars(App\I18n\Translator::t('dsgvo.name_placeholder')) ?>">
        </div>

        <div class="form-group">
            <label for="email"><?= htmlspecialchars(App\I18n\Translator::t('dsgvo.email_label')) ?></label>
            <input type="email" id="email" name="email" class="form-control" placeholder="<?= htmlspecialchars(App\I18n\Translator::t('dsgvo.email_placeholder')) ?>" required>
            <small style="color: var(--text-muted);"><?= htmlspecialchars(App\I18n\Translator::t('dsgvo.email_hint')) ?></small>
        </div>

        <div class="form-group">
            <label for="request_type"><?= htmlspecialchars(App\I18n\Translator::t('dsgvo.request_type_label')) ?></label>
            <select id="request_type" name="request_type" class="form-control" required>
                <option value="info"><?= htmlspecialchars(App\I18n\Translator::t('dsgvo.request_type_info')) ?></option>
                <option value="deletion"><?= htmlspecialchars(App\I18n\Translator::t('dsgvo.request_type_deletion')) ?></option>
            </select>
        </div>

        <div class="form-group">
            <label for="message"><?= htmlspecialchars(App\I18n\Translator::t('dsgvo.message_label')) ?></label>
            <textarea id="message" name="message" class="form-control" rows="4" placeholder="<?= htmlspecialchars(App\I18n\Translator::t('dsgvo.message_placeholder')) ?>"></textarea>
        </div>

        <button type="submit" class="btn mt-2"><?= htmlspecialchars(App\I18n\Translator::t('dsgvo.submit')) ?></button>
    </form>
</div>
