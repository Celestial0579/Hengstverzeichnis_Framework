<?php
// src/Views/error_403.php
/**
 * @var string $title
 * @var string $message
 */
?>
<div class="card text-center" style="max-width: 600px; margin: 4rem auto; padding: 2.5rem 2rem;">
    <div style="font-size: 4rem; line-height: 1; margin-bottom: 1rem;">🛑</div>
    <h1 style="color: var(--danger-fg); font-size: 2rem; margin-bottom: 0.5rem;"><?= htmlspecialchars(App\I18n\Translator::t('errors.403_title')) ?></h1>
    <p style="font-size: 1.1rem; color: var(--text-muted); margin-bottom: 1.5rem;">
        <?= htmlspecialchars($message ?? App\I18n\Translator::t('errors.403_default_message')) ?>
    </p>

    <div style="background: var(--warning-soft-bg); color: var(--warning-fg); padding: 0.9rem; border-radius: var(--border-radius); font-size: 0.88rem; margin-bottom: 2rem; border-left: 4px solid #ffeeba;">
        <?= htmlspecialchars(App\I18n\Translator::t('errors.403_audit_note')) ?>
    </div>

    <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="/admin" class="btn" style="min-width: 200px;"><?= htmlspecialchars(App\I18n\Translator::t('errors.403_to_dashboard')) ?></a>
        <?php else: ?>
            <a href="/login" class="btn" style="min-width: 200px;"><?= htmlspecialchars(App\I18n\Translator::t('errors.403_to_login')) ?></a>
        <?php endif; ?>
        <a href="/" class="btn btn-secondary" style="min-width: 200px;"><?= htmlspecialchars(App\I18n\Translator::t('errors.to_home')) ?></a>
    </div>
</div>
