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
// Lesbare Textfarbe auf der Primärfarbfläche (#196): Die Markenfarbe ist im
// Admin frei wählbar, ein hartkodiertes Weiß (bisher color: white) wird auf
// hellen Primärfarben unlesbar. Berechnet aus dem Roh-Settingwert; der Helfer
// validiert selbst und fällt bei Unbrauchbarem auf Weiß zurück.
$onPrimary = \App\Helper\ColorContrast::readableTextOn((string)($settings['primary_color'] ?? '#2c3e50'));
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

    <script>
        // Darkmode (#91): synchron und so früh wie möglich im <head>, damit das
        // data-theme-Attribut bereits vor dem ersten Rendern von <body> steht -
        // verhindert ein kurzes Aufblitzen des falschen Farbschemas (FOUC).
        // Gespeicherte manuelle Wahl (localStorage) hat Vorrang, sonst greift
        // die CSS-Regel @media (prefers-color-scheme: dark) in style.css von
        // selbst (kein "system"-Wert nötig, siehe dortiger Kommentar).
        (function () {
            var stored = localStorage.getItem('theme');
            if (stored === 'dark' || stored === 'light') {
                document.documentElement.setAttribute('data-theme', stored);
            }
        })();
    </script>

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

    <?php // KEINE Schrift von einem fremden Host mehr.
          //
          // Bisher lud jede Seite - auch die öffentliche, auch ohne Anmeldung -
          // ein Stylesheet von fonts.googleapis.com und die Schriftdateien von
          // fonts.gstatic.com. Das sind drei Dinge auf einmal: Jeder Besucher
          // meldet seine IP-Adresse und die aufgerufene Seite an einen Dritten
          // (nach EuGH-Rechtsprechung nicht ohne Einwilligung zulässig, und ein
          // Zuchtverzeichnis führt Personendaten), die Darstellung hängt an der
          // Verfügbarkeit eines fremden Dienstes, und ein kompromittiertes
          // Font-CSS könnte über die dort ohnehin nötige style-src-Freigabe
          // wirken (A08).
          //
          // Der Ersatz kostet nichts: --font-family in public/css/style.css
          // führte 'Inter' ohnehin nur als erste Wahl vor einem System-Stack.
          // Wer die Inter-Typografie exakt behalten will, liefert die
          // woff2-Dateien selbst aus (OFL erlaubt das) - dann bleibt der
          // Datenfluss trotzdem im eigenen Haus.
          //
          // Nebenwirkung, die gelegen kommt: Damit verschwindet der letzte
          // Inline-onload-Handler aus dem Layout, ein Schritt weniger auf dem
          // Weg zu einer CSP ohne 'unsafe-inline'. ?>

    <?php // style.css bleibt BEWUSST blockierend (#263): Es ist das eigene
          // Grund-Stylesheet, kein Zusatz. Es asynchron nachzuladen hieße, die Seite
          // garantiert einmal ungestylt zu zeigen - ein sichtbarer Rückschritt
          // zugunsten einer Messzahl. Kritisches CSS auszulagern wäre der saubere Weg
          // dorthin und ist ein eigener Schritt, kein Nebenbei. ?>
    <!-- Base Stylesheet -->
    <link rel="stylesheet" href="/css/style.css">

    <!-- Dynamic Theme Variables Injected from Database -->
    <style>
        :root {
            --primary-color: <?= $primaryColor ?>;
            --secondary-color: <?= $secondaryColor ?>;
            --on-primary: <?= htmlspecialchars($onPrimary) ?>;
        }

        /* Menu alignment matching Admin Login aesthetic */
        header {
            background: var(--header-bg);
            border-bottom: 2px solid var(--border-color);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            padding: 0.8rem 2rem;
            transition: background-color var(--transition-speed), border-color var(--transition-speed);
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
            color: var(--text-color);
            font-weight: 500;
            font-size: 0.95rem;
            /* Nur die Eigenschaften animieren, die sich beim Hover/Aktiv-Wechsel
               tatsächlich ändern - "all" nimmt font-weight/padding mit und
               erzwingt Layout-Neuberechnung pro Mausbewegung (#181). */
            transition: background-color 0.2s ease-in-out, color 0.2s ease-in-out;
        }

        nav a.nav-link:hover {
            background: var(--bg-color);
            color: var(--primary-fg);
        }

        nav a.nav-link.active {
            background: rgba(44, 62, 80, 0.08);
            color: var(--primary-fg);
            font-weight: 700;
        }

        <?php
            // Die frueheren Admin-Button-Sonderstile (Insel-Duplikat von
            // .btn/.btn-secondary mit eigenen Metriken und hartem Weiss) sind
            // entfernt (#196) - die Admin-Buttons nutzen jetzt die gemeinsamen
            // Button-Klassen aus style.css plus den .btn-nav-Modifier.
        ?>
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
                <!-- Barrierefreiheit (#51): alt="" statt eines Textes wie "Logo" - der
                     Vereinsname steht als sichtbarer Text direkt daneben im selben
                     Link, ein zusätzlicher Alt-Text würde vom Screenreader doppelt
                     vorgelesen. -->
                <img src="<?= $logoUrl ?>" alt="">
            <?php endif; ?>
            <?= $siteName ?>
        </a>
        <nav>
            <ul>
                <li>
                    <a href="/" class="nav-link <?= $currentPath === '/' ? 'active' : '' ?>">🏠 <?= htmlspecialchars($t('nav.home')) ?></a>
                </li>
                <li>
                    <a href="/katalog" class="nav-link <?= $currentPath === '/katalog' || $currentPath === '/horse' || $currentPath === '/hengst' ? 'active' : '' ?>">🐴 <?= htmlspecialchars($t('nav.catalog')) ?></a>
                </li>
                <li style="margin-left: 0.5rem;">
                    <?php if ($isLoggedIn): ?>
                        <a href="/admin" class="btn btn-nav">
                            🔒 <?= htmlspecialchars($t('nav.admin_portal')) ?>
                        </a>
                    <?php else: ?>
                        <a href="/login" class="btn btn-secondary btn-nav">
                            🔑 <?= htmlspecialchars($t('nav.admin_login')) ?>
                        </a>
                    <?php endif; ?>
                </li>
                <li>
                    <?php
                        // Darkmode-Umschalter (#91): togglt data-theme auf <html> und
                        // persistiert die Wahl in localStorage (siehe Init-Script im
                        // <head>). Icon/aria-label werden per JS anhand des aktuell
                        // AKTIVEN Farbschemas gesetzt (nicht des gespeicherten Werts -
                        // ohne gespeicherte Wahl greift ja die Systemeinstellung, siehe
                        // style.css), daher hier nur ein neutraler Startzustand.
                    ?>
                    <button
                        type="button"
                        id="theme-toggle"
                        class="theme-toggle"
                        aria-label="<?= htmlspecialchars($t('nav.toggle_theme')) ?>"
                        title="<?= htmlspecialchars($t('nav.toggle_theme')) ?>"
                        onclick="window.__toggleTheme()"
                    >🌙</button>
                </li>
            </ul>
        </nav>
    </header>

    <main>
        <!-- Content will be injected here -->
        <?= $content ?? '' ?>
    </main>

    <footer>
        <?php
            // Zweiteilige Copyright-Zeile (#199): Das Betreiber-(c) deckt
            // Inhalte/Daten der Installation, das Framework-(c) den Code -
            // sichtbare Namensnennung des Urhebers (§ 13 UrhG) und Teil der
            // "Appropriate Legal Notices" der AGPL-3.0 (§ 5(d)); Nachnutzer
            // muessen diesen Vermerk erhalten. 2026 = Jahr des ersten Commits,
            // ab 2027 automatisch als Spanne.
            $frameworkYears = '2026' . ((int)date('Y') > 2026 ? '–' . date('Y') : '');
        ?>
        <?php
            // Zwei Blöcke statt einer gemeinsamen Copyright-Zeile (#257): Jedes
            // Copyright steht jetzt unmittelbar über den Links, die zu ihm
            // gehören - Betreiber-(c) über Impressum/Datenschutz/DSGVO (Angaben
            // zur Instanz), Framework-(c) über Handbuch/Austausch/Fehlermeldung/
            // Lizenz (Angaben zum Projekt). Vorher standen beide Copyrights
            // zusammen in Zeile 1 und ihre Links darunter, ohne erkennbare
            // Zuordnung.
            //
            // Die Tagline hängt am Framework-Block, weil sie das Projekt
            // beschreibt und nicht die Installation - so bleibt es bei zwei
            // Blöcken statt drei.
        ?>
        <div class="footer-group">
            <p>&copy; <?= date('Y') ?> <?= $displayCopyright ?></p>
            <p class="footer-links">
                <a href="/impressum"><?= htmlspecialchars($t('footer.impressum')) ?></a> | <a href="/datenschutz"><?= htmlspecialchars($t('footer.datenschutz')) ?></a> | <a href="/dsgvo"><?= htmlspecialchars($t('footer.dsgvo')) ?></a>
            </p>
        </div>
        <?php
            // Projekt-Links (#184-#187): Handbuch/Lizenz/Fehlermeldung/Austausch
            // sind sonst nur über die README auffindbar.
        ?>
        <div class="footer-group">
            <p><?= htmlspecialchars($t('footer.framework_copyright')) ?> &copy; <?= $frameworkYears ?> <a href="https://github.com/Celestial0579" target="_blank" rel="noopener">Tim Heyne</a> &middot; <?= htmlspecialchars($t('footer.tagline')) ?></p>
            <p class="footer-links">
                <a href="https://github.com/Celestial0579/Hengstverzeichnis_Framework/wiki" target="_blank" rel="noopener"><?= htmlspecialchars($t('footer.manual')) ?></a> | <a href="https://github.com/Celestial0579/Hengstverzeichnis_Framework/discussions" target="_blank" rel="noopener"><?= htmlspecialchars($t('footer.discussions')) ?></a> | <a href="https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/new" target="_blank" rel="noopener"><?= htmlspecialchars($t('footer.report_bug')) ?></a> | <a href="https://github.com/Celestial0579/Hengstverzeichnis_Framework/blob/main/LICENSE" target="_blank" rel="noopener"><?= htmlspecialchars($t('footer.license')) ?></a>
            </p>
        </div>
        <?php
            // Sprachumschalter (#48, #198): Mit zwölf Sprachen sprengt eine
            // Link-Liste die Leiste - deshalb ein Dropdown. Das GET-Formular
            // erzeugt exakt das etablierte ?lang=xx auf dem aktuellen Pfad
            // (ohne bestehende Query-Parameter, bewusst einfach gehalten);
            // BaseController::initLocale() übernimmt den Wert in die Session.
            // Ohne JavaScript übernimmt der <noscript>-Knopf das Absenden,
            // mit JavaScript sendet onchange direkt.
        ?>
        <form method="get" action="<?= htmlspecialchars($currentPath) ?>" class="footer-lang-form">
            <label for="footer-lang-select"><?= htmlspecialchars($t('footer.language_label')) ?>:</label>
            <select id="footer-lang-select" name="lang" class="footer-lang-select" onchange="this.form.submit()">
                <?php foreach (\App\I18n\Translator::activeLocales($settings) as $code => $label): ?>
                    <option value="<?= htmlspecialchars($code) ?>"<?= $code === $locale ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="footer-lang-submit"><?= htmlspecialchars($t('footer.language_apply')) ?></button></noscript>
        </form>
    </footer>

    <!-- Darkmode-Umschalter (#263): ausgelagert und defer geladen. Laeuft
         vor DOMContentLoaded, findet den Button also fertig geparst vor.
         Der FOUC-Fix im <head> bleibt bewusst inline und synchron. -->
    <script defer src="/js/theme-toggle.js"></script>
    <?php // Sicherheitsabfragen vor dem Absenden (data-confirm), ausgelagert
          // statt als onsubmit-Attribut - siehe public/js/confirm-submit.js. ?>
    <script defer src="/js/confirm-submit.js"></script>
</body>
</html>
