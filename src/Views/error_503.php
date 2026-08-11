<?php
// src/Views/error_503.php
//
// Wartungsmodus-Hinweisseite (#232). Anders als error_403/404/500 KEIN
// Layout-Fragment, sondern ein vollständiges, eigenständiges Dokument:
// Sie wird von App\Service\Maintenance::guard() VOR Router und Datenbank
// gerendert - das Haupt-Layout (layout.php) braucht aber die Settings aus
// der Datenbank (Site-Name, Farben, Navigation), die im Wartungsfall
// gerade nicht verfügbar sein dürfen. /css/style.css ist eine statische
// Datei und wird von Apache (.htaccess) wie von php -S am Front-Controller
// vorbei ausgeliefert, funktioniert also auch im Wartungsmodus.

use App\I18n\Translator;

$locale = Translator::getLocale();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($locale) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= htmlspecialchars(Translator::t('errors.503_title')) ?></title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <main>
        <div class="card text-center" style="max-width: 600px; margin: 4rem auto; padding: 2.5rem 2rem;">
            <div style="font-size: 4rem; line-height: 1; margin-bottom: 1rem;">🔧</div>
            <h1 style="color: var(--primary-fg); font-size: 2rem; margin-bottom: 0.5rem;"><?= htmlspecialchars(Translator::t('errors.503_title')) ?></h1>
            <p style="font-size: 1.1rem; color: var(--text-muted); margin-bottom: 0;">
                <?= htmlspecialchars(Translator::t('errors.503_default_message')) ?>
            </p>
        </div>
    </main>
</body>
</html>
