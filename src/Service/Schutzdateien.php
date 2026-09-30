<?php
// src/Service/Schutzdateien.php

namespace App\Service;

/**
 * Die beiden Schutzdateien unter public/uploads (Audit N19).
 *
 * public/uploads gehörte als Ganzes dem Betreiber, das Update übersprang es
 * vollständig - auch public/uploads/.htaccess und
 * public/uploads/horses/.htaccess. Auf aktualisierten Installationen fehlten
 * damit die harte Sperre des statischen Wegs zu Pferdefotos (#366) und die
 * Korrektur der Referer-Regel (#367), die in der Altfassung falsch herum
 * wirkte. Seit N19 sind beide KERN (App\Service\Baumordnung): Updates liefern
 * sie aus, die Integritätsprüfung prüft und repariert sie.
 *
 * Für Bestandsinstallationen legt ein einmaliger Migrationsschritt sie an
 * (SchemaMigrator, kern_schutzdateien_uploads) - über sicherstellen(). Der
 * Sollinhalt steht deshalb HIER als Konstante: Gerade auf den betroffenen
 * Installationen liegt im Codebaum nur die alte Fassung (oder gar keine).
 * tests/Unit/Service/SchutzdateienTest.php erzwingt Byte-Gleichheit mit den
 * Dateien im Repo.
 *
 * Ersetzt wird nur, was fehlt oder einer bekannten Altfassung entspricht.
 * Bekannt heißt: SHA-256 über den NORMALISIERTEN Inhalt (CRLF zu LF,
 * Leerraum am Zeilenende und Leerzeilen am Ende entfernt) - FTP-Uploads im
 * ASCII-Modus ändern Zeilenenden, und ein roher Hash ließe gerade die
 * unveränderte Altfassung mit der falschen Referer-Regel stehen. Eine vom
 * Betreiber angepasste Fassung bleibt stehen und wird gemeldet; sie
 * erscheint in der Integritätsprüfung als "geändert".
 */
final class Schutzdateien {

    public const UPLOADS = <<<'HTACCESS'
# Security .htaccess for Public Uploads Directory
# Disables PHP script execution to prevent web shell / remote code execution attacks
#
# IM OFFIZIELLEN DOCKER-IMAGE wird diese Datei NICHT ausgewertet (Audit N41):
# public/uploads ist dort ein Volume, das sie auf dem Stand des ersten Starts
# einfrieren wuerde. Dieselben Regeln stehen in docker/apache-uploads.conf
# (AllowOverride None). Wer hier etwas aendert, aendert es dort mit - die
# Endungsliste unten prueft tests/Unit/Views/DockerImageTest.php.

Options -Indexes -ExecCGI

<FilesMatch "\.(php|php\d*|phtml|pl|py|jsp|asp|sh|cgi|phar|inc)$">
    SetHandler default-handler
    Require all denied
</FilesMatch>

<IfModule mod_php7.c>
    php_flag engine off
</IfModule>

<IfModule mod_php.c>
    php_flag engine off
</IfModule>

# --- Einbettungsschutz fuer die verbliebenen Bilder (#262) ---
#
# Pferdefotos liegen seit #366 gar nicht mehr hier, sondern unter storage/horses
# ausserhalb des Webroots; fuer sie gilt zusaetzlich das harte
# public/uploads/horses/.htaccess. Was hier noch statisch ausgeliefert wird,
# sind die Branding-Dateien (Logo) - die sollen oeffentlich sein, aber nicht von
# fremden Seiten eingebettet werden.
#
# WICHTIG: Diese Datei gilt nur fuer Apache. Eine nginx-/Caddy-Installation
# braucht das Gegenstueck in ihrer Serverkonfiguration.
<IfModule mod_headers.c>
    <FilesMatch "\.(jpe?g|png|gif|webp)$">
        Header set Cross-Origin-Resource-Policy "same-origin"
        Header set X-Content-Type-Options "nosniff"
    </FilesMatch>
</IfModule>

# Frueher stand hier zusaetzlich ein Referer-Filter. Er ist ersatzlos entfallen
# (#367), und zwar nicht nur, weil er ueberfluessig geworden ist: Er war falsch
# herum wirksam. mod_rewrite ersetzt %{HTTP_HOST} nur in der TestString-Haelfte
# einer RewriteCond, nicht im CondPattern - das Muster
#     RewriteCond %{HTTP_REFERER} !^https?://%{HTTP_HOST}(:[0-9]+)?/ [NC]
# enthielt die Zeichenfolge literal und traf deshalb nie. Damit war die negierte
# Bedingung fuer JEDEN nicht leeren Referer wahr: Abrufe von der eigenen Seite
# bekamen 403, waehrend genau der Fall ohne Referer - der einzige, den die Regel
# bewusst durchlassen wollte - ungeprueft durchlief. Wer so eine Regel wieder
# aufnimmt, zieht den Host in die TestString-Haelfte:
#     RewriteCond %{HTTP_REFERER}::%{HTTP_HOST} !^https?://([^/:]+)(?::[0-9]+)?/.*::\1$ [NC]
HTACCESS . "\n";

    public const HORSES = <<<'HTACCESS'
# Pferdefotos werden NICHT statisch ausgeliefert (#366).
#
# Sie liegen seit v0.8.0 unter storage/horses/, also ausserhalb des Webroots.
# Dieses Verzeichnis bleibt nur bestehen, weil auf Bestandsinstallationen noch
# Dateien darin liegen koennen, solange die Verschiebung (SchemaMigrator,
# Schritt 366_pferdefotos_aus_dem_webroot) nicht durch ist - und weil ein
# Betreiber sie von Hand wiederherstellen koennte.
#
# Der Grund fuer die harte Sperre: Als statische Datei war ein Foto unabhaengig
# von is_published abrufbar. public/.htaccess leitet nur bei !-f auf den Front
# Controller um, eine existierende Bilddatei erreicht ihn also nie - die
# Sichtbarkeitspruefung in App\Controllers\MediaController lief ins Leere, und
# das Bild eines depublizierten Pferdes blieb unter seinem unveraenderten
# Dateinamen erreichbar. CORP und ein Referer-Filter sind Einbettungsschutz,
# kein Zugriffsschutz; sie halten einen direkten Abruf per curl oder Lesezeichen
# nicht auf.
#
# Ausgeliefert wird ausschliesslich ueber /media/horse-image - dort gelten
# dieselben Regeln wie fuer die Detailseite (horses.view, is_published,
# gueltige Sitzung).
#
# WICHTIG: Diese Datei gilt nur fuer Apache. Auf nginx/Caddy gehoert das
# Gegenstueck in die Serverkonfiguration:
#     location ^~ /uploads/horses/ { deny all; }
# Wo die Verschiebung durchgelaufen ist, ist das Verzeichnis ohnehin leer.
#
# Im offiziellen Docker-Image gilt stattdessen die gleichlautende Sperre aus
# docker/apache-uploads.conf (Audit N41) - diese Datei laege dort im Volume
# uploads_data und wird nicht ausgewertet.
Require all denied
HTACCESS . "\n";

    /**
     * Normalisierte SHA-256 der ausgelieferten Altfassungen (aus den Tags,
     * `git show <tag>:<pfad>`).
     */
    private const ALTFASSUNGEN = [
        '.htaccess' => [
            // v0.7.1 bis v0.8.0-beta.2: mit dem falsch herum wirkenden
            // Referer-Filter (#367).
            'fece3be8a2654b898964af8685a1c1f21fcd7729275e6ba4608c7a95def8f0de',
            // v0.8.0 bis v0.9.0-beta.6.
            'd44203b4199f17f8a70d1f15149101f2236185db0a187307a7b0d81781732ad2',
        ],
        'horses/.htaccess' => [
            // v0.8.0 bis v0.9.0-beta.6.
            '46b9371db7d9eb7a629853568c10fa2b6e91a9fda3baf59647f5ca8f9cad6d29',
        ],
    ];

    private function __construct() {}

    /**
     * Legt beide Dateien an bzw. ersetzt bekannte Altfassungen.
     *
     * @param string $uploadsWurzel public/uploads
     * @param string $horsesDir     das Altverzeichnis der Pferdefotos
     *                              (\App\Helper\HorseImagePath::legacyDir())
     * @return array<int, string>|null [] = alles aktuell bzw. hergestellt ohne
     *         Meldung; Meldungszeilen für Geschriebenes und fremde Fassungen;
     *         null = mindestens eine Datei ließ sich nicht schreiben
     */
    public static function sicherstellen(string $uploadsWurzel, string $horsesDir): ?array {
        $meldungen = [];
        $fehler = false;

        foreach ([
            ['.htaccess', rtrim($uploadsWurzel, '/') . '/.htaccess', self::UPLOADS],
            ['horses/.htaccess', rtrim($horsesDir, '/') . '/.htaccess', self::HORSES],
        ] as [$name, $pfad, $soll]) {
            $anzeige = 'public/uploads/' . $name;
            $vorhanden = is_file($pfad) ? @file_get_contents($pfad) : null;
            if ($vorhanden === false) {
                $fehler = true;
                $meldungen[] = "Schutzdatei {$anzeige} ist nicht lesbar";
                continue;
            }
            if ($vorhanden === $soll) {
                continue;
            }
            if ($vorhanden !== null && self::normalisiert($vorhanden) === self::normalisiert($soll)) {
                continue; // nur Zeilenenden anders - inhaltlich aktuell
            }
            if ($vorhanden !== null && !in_array(hash('sha256', self::normalisiert($vorhanden)), self::ALTFASSUNGEN[$name], true)) {
                $meldung = "Schutzdatei {$anzeige} weicht von der ausgelieferten Fassung ab und wurde NICHT ersetzt "
                    . '(eigene Anpassung?) - bitte mit der Fassung des Releases abgleichen; eigene Regeln gehören '
                    . 'nach public/.htaccess oder in die Serverkonfiguration';
                error_log($meldung);
                $meldungen[] = $meldung;
                continue;
            }
            if (!self::schreibe($pfad, $soll)) {
                $fehler = true;
                error_log("Schutzdatei {$anzeige} konnte nicht geschrieben werden");
                continue;
            }
            $meldungen[] = $vorhanden === null
                ? "Schutzdatei {$anzeige} angelegt"
                : "Schutzdatei {$anzeige} auf die aktuelle Fassung gebracht";
        }

        return $fehler ? null : $meldungen;
    }

    /** CRLF zu LF, Leerraum am Zeilenende und Leerzeilen am Ende weg. */
    public static function normalisiert(string $inhalt): string {
        $zeilen = array_map('rtrim', explode("\n", str_replace("\r\n", "\n", $inhalt)));
        while ($zeilen !== [] && end($zeilen) === '') {
            array_pop($zeilen);
        }
        return implode("\n", $zeilen);
    }

    /** Atomar: Temp-Datei im Zielordner, dann rename(). */
    private static function schreibe(string $pfad, string $inhalt): bool {
        $ordner = dirname($pfad);
        if (!is_dir($ordner) && !@mkdir($ordner, 0755, true) && !is_dir($ordner)) {
            return false;
        }
        $temp = $ordner . '/.htaccess.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, $inhalt) !== strlen($inhalt)) {
            @unlink($temp);
            return false;
        }
        @chmod($temp, 0644);
        if (!@rename($temp, $pfad)) {
            @unlink($temp);
            return false;
        }
        return true;
    }
}
