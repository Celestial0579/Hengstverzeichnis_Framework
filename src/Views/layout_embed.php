<?php
// src/Views/layout_embed.php
/**
 * Minimal-Layout für einbettbare Ansichten (#260).
 *
 * @var string $title
 * @var string $content
 * @var array $settings
 *
 * Kein Kopfbereich, keine Navigation, keine Fußzeile - nur der Inhalt. Alles
 * andere wäre in einem fremden iframe sinnlos bis irreführend: Ein Menü, das
 * aus dem Rahmen heraus navigieren will, landet im Rahmen; eine Fußzeile mit
 * Impressum und DSGVO-Links gehört zur einbettenden Seite, nicht in ihren
 * Rahmen hinein.
 *
 * Bewusst ÜBERNOMMEN aus dem Hauptlayout, weil es sonst in der Einbettung
 * kaputtginge:
 *
 * - Der Darkmode-FOUC-Fix im <head>. Ein iframe rendert eigenständig; ohne ihn
 *   blitzt hier dasselbe falsche Farbschema auf wie überall sonst (#91).
 * - Die Theme-Variablen aus der Datenbank. Ein iframe erbt KEIN CSS von der
 *   einbettenden Seite - die Markenfarben müssen mit, sonst steht das Snippet
 *   ungestylt in einer fremden Seite.
 * - style.css. Aus demselben Grund.
 *
 * Bewusst WEGGELASSEN:
 *
 * - Der Theme-Umschalter. Er ist eine Bedienung der eigenen Oberfläche; im
 *   Rahmen einer fremden Seite hat der Besucher sie nicht angefordert.
 * - Der Tracking-Code. Ihn in einer fremden Seite auszulösen, ohne dass deren
 *   Einwilligungsbanner davon weiß, wäre datenschutzrechtlich genau der
 *   Fehler, den das DSGVO-Modul dieses Projekts vermeiden soll.
 * - Canonical- und og:-Angaben. Der Rahmeninhalt ist keine eigenständige Seite
 *   und soll nicht als solche indexiert werden - deshalb stattdessen noindex.
 */
$primaryColor = htmlspecialchars($settings['primary_color'] ?? '#2c3e50');
$secondaryColor = htmlspecialchars($settings['secondary_color'] ?? '#18bc9c');
$onPrimary = \App\Helper\ColorContrast::readableTextOn((string)($settings['primary_color'] ?? '#2c3e50'));
$siteName = htmlspecialchars($settings['site_name'] ?? 'Hengstverzeichnis');
$locale = \App\I18n\Translator::getLocale();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($locale) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php // Der Rahmeninhalt ist keine eigenstaendige Seite. Ohne noindex
          // konkurrierte er in Suchergebnissen mit der echten Katalogseite. ?>
    <meta name="robots" content="noindex, follow">
    <title><?= htmlspecialchars($title ?? $siteName) ?></title>

    <script>
        // Darkmode-FOUC-Fix (#91), wortgleich zum Hauptlayout: Ein iframe
        // rendert eigenstaendig, das Aufblitzen des falschen Farbschemas
        // passiert hier genauso.
        (function () {
            var stored = localStorage.getItem('theme');
            if (stored === 'dark' || stored === 'light') {
                document.documentElement.setAttribute('data-theme', stored);
            }
        })();
    </script>

    <link rel="stylesheet" href="/css/style.css">

    <style>
        :root {
            --primary-color: <?= $primaryColor ?>;
            --secondary-color: <?= $secondaryColor ?>;
            --on-primary: <?= htmlspecialchars($onPrimary) ?>;
        }

        /* Im Rahmen gibt es keinen Seitenrahmen zu fuellen: Der Inhalt sitzt
           buendig, damit die einbettende Seite den Abstand bestimmt. */
        body {
            margin: 0;
            padding: 0;
            background: transparent;
        }

        main.embed-main {
            padding: 1rem;
        }
    </style>
</head>
<body>
    <main class="embed-main">
        <?= $content ?>
    </main>
</body>
</html>
