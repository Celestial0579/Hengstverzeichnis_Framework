<?php
// src/Helper/Markdown.php

namespace App\Helper;

class Markdown {

    /**
     * Parses Markdown text into safe HTML
     */
    public static function parse(?string $text): string {
        if (empty($text)) {
            return '';
        }

        // 1. Escape HTML for XSS protection
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // 2. Headings (# H1, ## H2, ### H3)
        $text = preg_replace('/^### (.*?)$/m', '<h3 style="margin-top: 1.2rem; margin-bottom: 0.5rem; color: var(--primary-color);">$1</h3>', $text);
        $text = preg_replace('/^## (.*?)$/m', '<h2 style="margin-top: 1.5rem; margin-bottom: 0.6rem; color: var(--primary-color);">$1</h2>', $text);
        $text = preg_replace('/^# (.*?)$/m', '<h1 style="margin-top: 1.8rem; margin-bottom: 0.8rem; color: var(--primary-color);">$1</h1>', $text);

        // 3. Unordered Lists (- item or * item) - MUSS vor Bold/Italic laufen:
        // die Kursiv-Regel (*...*) matcht dank /s-Flag auch über Zeilenumbrüche
        // hinweg und würde sonst bei mehrzeiligen *-Listen die Listenmarker der
        // Folgezeilen als Kursiv-Begrenzer verschlucken (siehe #38). Ein Zeilen-
        // anfang wie "**Bold**" matcht hier nicht, da nach dem Marker ein
        // Leerzeichen verlangt wird (\s+); Bold/Italic innerhalb eines
        // Listenpunkts funktioniert unverändert, da die Regeln unten einfach
        // auf den Text in den bereits erzeugten <li>-Tags weiterlaufen.
        $text = preg_replace('/^\s*[\-\*]\s+(.*?)$/m', '<li>$1</li>', $text);
        $text = preg_replace('/((?:<li>.*?<\/li>\s*)+)/s', '<ul style="margin: 0.8rem 0 0.8rem 1.5rem; padding-left: 1rem;">$1</ul>', $text);

        // 4. Links [Text](http://example.com) - vor Bold/Italic, und für deren
        //    Dauer gegen weitere Ersetzungen geschützt.
        //
        // WARUM DER PLATZHALTER: Es genügt nicht, die Link-Regel nur
        // vorzuziehen. Die Kursiv- und Fett-Regeln laufen über den GESAMTEN
        // Text, also auch über das gerade erzeugte <a href="...">, und
        // schieben ihre Tags mitten in den Attributwert:
        // "[x](http://a.com/_foo_bar)" wurde zu
        // href="http://a.com/<em>foo</em>bar", ein unpaariges Sternchen
        // hinterließ sogar ein hängendes </em> darin. Ein Ausbruch aus den
        // Anführungszeichen war das nicht - htmlspecialchars(ENT_QUOTES) in
        // Schritt 1 hat sie längst entschärft -, aber ein kaputter Link und
        // eine Ausgabe, die man niemandem erklären möchte.
        //
        // Der fertige Anker wird deshalb durch einen Platzhalter ersetzt, der
        // keine Markdown-Sonderzeichen enthält, und ganz am Ende wieder
        // eingesetzt. Der Linktext selbst durchläuft die weiteren Regeln
        // bewusst NICHT - er ist eine Beschriftung, kein Textkörper.
        //
        // Zusätzlich wird die URL geprüft statt nur eingesetzt: Was nicht als
        // http(s)-URL durchgeht, bleibt schlichter Text. Kein Link ist besser
        // als ein Link an eine Adresse, die niemand geprüft hat.
        $linkPlaceholders = [];
        $text = preg_replace_callback(
            '/\[([^\]]*)\]\((https?:\/\/[^\s<>()]+)\)/',
            static function (array $m) use (&$linkPlaceholders): string {
                // Der Text ist hier bereits HTML-escaped; für die Prüfung wird
                // die URL zurückverwandelt und danach wieder sauber für ein
                // Attribut kodiert.
                $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');

                if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                    return $m[0];
                }
                $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
                if (!in_array($scheme, ['http', 'https'], true)) {
                    return $m[0];
                }

                $href = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                $key = "\x02MDLINK" . count($linkPlaceholders) . "\x03";
                $linkPlaceholders[$key] = '<a href="' . $href . '" target="_blank" rel="noopener noreferrer"'
                    . ' style="color: var(--primary-color); text-decoration: underline;">' . $m[1] . '</a>';
                return $key;
            },
            $text
        );

        // 5. Bold & Italic
        $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.*?)__/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.*?)\*/s', '<em>$1</em>', $text);
        $text = preg_replace('/_(.*?)_/s', '<em>$1</em>', $text);

        // 6. Paragraphs and Line breaks
        // Convert double newlines to paragraphs
        $paragraphs = explode("\n\n", $text);
        $formattedParagraphs = array_map(function($p) {
            $p = trim($p);
            if (empty($p)) return '';
            // If paragraph already starts with a block-level tag (h1, h2, h3, ul), don't wrap in <p>
            if (preg_match('/^<(h[1-6]|ul|ol|p|div|blockquote)/i', $p)) {
                return nl2br($p);
            }
            return '<p style="margin-bottom: 1rem; line-height: 1.6;">' . nl2br($p) . '</p>';
        }, $paragraphs);

        $html = implode("\n", array_filter($formattedParagraphs));

        // 7. Die geschützten Links wieder einsetzen - nachdem keine Regel mehr
        //    über den Text läuft.
        return $linkPlaceholders === [] ? $html : strtr($html, $linkPlaceholders);
    }
}
