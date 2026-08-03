<?php
// src/Views/login.php
/**
 * @var string $error (optional)
 */
?>
<div class="card" style="max-width: 400px; margin: 4rem auto;">
    <h2 class="text-center">Admin Login</h2>
    
    <?php if (isset($_GET['success']) && $_GET['success'] === 'password_reset'): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            ✓ Passwort erfolgreich geändert. Bitte melden Sie sich an.
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form action="/login" method="POST">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        
        <div class="form-group">
            <label for="email">E-Mail</label>
            <input type="email" id="email" name="email" class="form-control" required autofocus>
        </div>
        
        <div class="form-group">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <label for="password">Passwort</label>
                <a href="/forgot-password" style="font-size: 0.85rem; color: var(--primary-color);">Passwort vergessen?</a>
            </div>
            <input type="password" id="password" name="password" class="form-control" required>
        </div>
        
        <button type="submit" class="btn" style="width: 100%">Anmelden</button>
    </form>
</div>
