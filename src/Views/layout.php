<?php
// src/Views/layout.php
/**
 * @var string $title
 * @var string $content
 * @var array $settings
 */

// Default settings if not loaded
$primaryColor = htmlspecialchars($settings['primary_color'] ?? '#2c3e50');
$secondaryColor = htmlspecialchars($settings['secondary_color'] ?? '#18bc9c');
$siteName = htmlspecialchars($settings['site_name'] ?? 'Hengstverzeichnis');
$copyrightHolder = htmlspecialchars($settings['copyright_holder'] ?? '');
$displayCopyright = !empty($copyrightHolder) ? $copyrightHolder : $siteName;
$logoUrl = htmlspecialchars($settings['site_logo'] ?? $settings['logo_url'] ?? '');

// Favicon MIME type and Cache-Busting calculation
$faviconMime = 'image/png';
$faviconCacheBust = '';
if (!empty($logoUrl)) {
    $pathOnly = parse_url($logoUrl, PHP_URL_PATH);
    $ext = strtolower(pathinfo($pathOnly, PATHINFO_EXTENSION));
    
    if ($ext === 'svg') {
        $faviconMime = 'image/svg+xml';
    } elseif ($ext === 'jpg' || $ext === 'jpeg') {
        $faviconMime = 'image/jpeg';
    } elseif ($ext === 'webp') {
        $faviconMime = 'image/webp';
    } elseif ($ext === 'ico') {
        $faviconMime = 'image/x-icon';
    }

    $localFilePath = __DIR__ . '/../../public' . $pathOnly;
    $version = file_exists($localFilePath) ? filemtime($localFilePath) : time();
    $faviconCacheBust = $logoUrl . '?v=' . $version;
}

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$isLoggedIn = isset($_SESSION['user_id']);

// Mehrsprachigkeit (#48): Locale wird bereits in BaseController::initLocale()
// für den gesamten Request gesetzt, siehe App\I18n\Translator.
$locale = \App\I18n\Translator::getLocale();
$t = fn(string $key, array $params = []) => \App\I18n\Translator::t($key, $params);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($locale) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($siteName) ?> - <?= htmlspecialchars($t('meta.description_suffix')) ?>">
    <title><?= htmlspecialchars($title ?? $siteName) ?></title>

    <?php if (!empty($settings['base_url'])): ?>
        <?php $canonicalUrl = htmlspecialchars(rtrim($settings['base_url'], '/') . $currentPath); ?>
        <link rel="canonical" href="<?= $canonicalUrl ?>">
        <meta property="og:site_name" content="<?= htmlspecialchars($siteName) ?>">
        <meta property="og:title" content="<?= htmlspecialchars($title ?? $siteName) ?>">
        <meta property="og:url" content="<?= $canonicalUrl ?>">
        <meta property="og:type" content="website">
    <?php endif; ?>

    <!-- Favicon & Touch Icon -->
    <?php if (!empty($logoUrl)): ?>
        <link rel="icon" type="<?= $faviconMime ?>" href="<?= $faviconCacheBust ?>">
        <link rel="shortcut icon" type="<?= $faviconMime ?>" href="<?= $faviconCacheBust ?>">
        <link rel="apple-touch-icon" href="<?= $faviconCacheBust ?>">
    <?php else: ?>
        <!-- Default Horse Favicon -->
        <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🐴</text></svg>">
    <?php endif; ?>
    
    <!-- Google Fonts for premium typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Base Stylesheet -->
    <link rel="stylesheet" href="/css/style.css">
    
    <!-- Dynamic Theme Variables Injected from Database -->
    <style>
        :root {
            --primary-color: <?= $primaryColor ?>;
            --secondary-color: <?= $secondaryColor ?>;
        }

        /* Menu alignment matching Admin Login aesthetic */
        header {
            background: #ffffff;
            border-bottom: 2px solid #f0f0f0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            padding: 0.8rem 2rem;
        }

        nav ul {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            list-style: none;
        }

        nav a.nav-link {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            color: #495057;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.2s ease-in-out;
        }

        nav a.nav-link:hover {
            background: #f8f9fa;
            color: var(--primary-color);
        }

        nav a.nav-link.active {
            background: rgba(44, 62, 80, 0.08);
            color: var(--primary-color);
            font-weight: 700;
        }

        .nav-btn-admin {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 1.1rem;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .nav-btn-admin-login {
            background: transparent;
            color: var(--primary-color);
            border: 2px solid var(--primary-color);
        }

        .nav-btn-admin-login:hover {
            background: var(--primary-color);
            color: #ffffff;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .nav-btn-admin-dashboard {
            background: var(--primary-color);
            color: #ffffff;
            border: 2px solid var(--primary-color);
        }

        .nav-btn-admin-dashboard:hover {
            background: var(--secondary-color);
            border-color: var(--secondary-color);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
    </style>

    <?php if (!empty($settings['tracking_code'])): ?>
        <!-- Tracking-Code aus Admin > Systemeinstellungen - absichtlich unescaped,
             siehe AdminController::updateSystemSettings() für die Begründung. -->
        <?= $settings['tracking_code'] ?>
    <?php endif; ?>
</head>
<body>
    <header>
        <a href="/" class="brand">
            <?php if (!empty($logoUrl)): ?>
                <img src="<?= $logoUrl ?>" alt="Logo">
            <?php endif; ?>
            <?= $siteName ?>
        </a>
        <nav>
            <ul>
                <li>
                    <a href="/" class="nav-link <?= $currentPath === '/' ? 'active' : '' ?>">🏠 <?= htmlspecialchars($t('nav.home')) ?></a>
                </li>
                <li>
                    <a href="/katalog" class="nav-link <?= $currentPath === '/katalog' || $currentPath === '/hengst' ? 'active' : '' ?>">🐴 <?= htmlspecialchars($t('nav.catalog')) ?></a>
                </li>
                <li style="margin-left: 0.5rem;">
                    <?php if ($isLoggedIn): ?>
                        <a href="/admin" class="nav-btn-admin nav-btn-admin-dashboard">
                            🔒 <?= htmlspecialchars($t('nav.admin_portal')) ?>
                        </a>
                    <?php else: ?>
                        <a href="/login" class="nav-btn-admin nav-btn-admin-login">
                            🔑 <?= htmlspecialchars($t('nav.admin_login')) ?>
                        </a>
                    <?php endif; ?>
                </li>
            </ul>
        </nav>
    </header>

    <main>
        <!-- Content will be injected here -->
        <?= $content ?? '' ?>
    </main>

    <footer>
        <p>&copy; <?= date('Y') ?> <?= $displayCopyright ?> | <?= htmlspecialchars($t('footer.tagline')) ?></p>
        <p style="font-size: 0.85rem; margin-top: 0.5rem;">
            <a href="/impressum"><?= htmlspecialchars($t('footer.impressum')) ?></a> | <a href="/datenschutz"><?= htmlspecialchars($t('footer.datenschutz')) ?></a> | <a href="/dsgvo"><?= htmlspecialchars($t('footer.dsgvo')) ?></a>
        </p>
        <?php
            // Sprachumschalter (#48): setzt ?lang=xx auf dem aktuellen Pfad (ohne
            // bestehende Query-Parameter mitzuführen, bewusst einfach gehalten) -
            // BaseController::initLocale() übernimmt den Wert danach in die Session.
        ?>
        <p style="font-size: 0.8rem; margin-top: 0.5rem;">
            <?php $localeIndex = 0; foreach (\App\I18n\Translator::getAvailableLocales() as $code => $label): ?>
                <?= $localeIndex++ > 0 ? ' | ' : '' ?><a href="<?= htmlspecialchars($currentPath) ?>?lang=<?= urlencode($code) ?>"<?= $code === $locale ? ' style="font-weight:700;"' : '' ?>><?= htmlspecialchars($label) ?></a>
            <?php endforeach; ?>
        </p>
    </footer>
</body>
</html>
