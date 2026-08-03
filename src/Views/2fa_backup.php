<?php
// src/Views/2fa_backup.php
/**
 * @var string|null $error
 */
?>
<div class="card" style="max-width: 420px; margin: 4rem auto;">
    <h2 class="text-center" style="margin-bottom: 0.5rem;">🔑 Backup-Code verwenden</h2>
    <p class="text-center" style="color: #666; font-size: 0.95rem; margin-bottom: 1.5rem;">
        Geben Sie einen Ihrer 10 Einmal-Wiederherstellungscodes ein.
    </p>
    
    <?php if (isset($error)): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 0.8rem; border-radius: 4px; margin-bottom: 1rem; text-align: center;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form action="/login/backup-code" method="POST">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        
        <div class="form-group" style="text-align: center;">
            <label for="backup_code" style="display: block; margin-bottom: 0.5rem;">Wiederherstellungscode (z. B. A1B2-C3D4)</label>
            <input type="text" id="backup_code" name="backup_code" class="form-control" placeholder="A1B2-C3D4" required autofocus autocomplete="off" style="font-size: 1.2rem; text-transform: uppercase; letter-spacing: 2px; text-align: center; margin: 0 auto; max-width: 260px;">
        </div>
        
        <button type="submit" class="btn" style="width: 100%; margin-top: 1rem;">Mit Backup-Code anmelden</button>
    </form>

    <div style="text-align: center; margin-top: 1.5rem; border-top: 1px solid #eee; padding-top: 1rem;">
        <a href="/login/2fa" style="font-size: 0.9rem; color: #666; text-decoration: none;">
            &larr; Zurück zur normalen 2FA-Eingabe
        </a>
    </div>
</div>
