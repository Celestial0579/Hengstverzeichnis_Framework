<?php
// src/Service/Integritaet.php

namespace App\Service;

/**
 * Class Integritaet
 *
 * Prüft den Codebaum gegen den Sollzustand des Releases und stellt ihn
 * wieder her (#403).
 *
 * ## Warum es das gibt
 *
 * Addons haben seit #224 eine Manipulationserkennung: Verzeichnis-Stempel als
 * billiger Vorfilter, SHA-256 über alle Dateien als Beweis, und wer davon
 * abweicht, wird nicht geladen. Ausgerechnet der Kern, der diese Prüfung
 * durchführt, hatte selbst keine. Der Grund war nie fehlender Wille, sondern
 * eine fehlende Antwort auf die Frage: Woran soll man ihn messen? Über die
 * ganze Installation geht es nicht - dort liegen hochgeladene Bilder,
 * Protokolle und die Addons anderer Leute. Über die KERN-Pfade der
 * Baumordnung geht es.
 *
 * ## Woher der Sollwert kommt - und was das jeweils wert ist
 *
 * Das ist die eigentliche Frage jeder Integritätsprüfung, und sie hat hier
 * zwei Antworten mit sehr unterschiedlicher Aussagekraft:
 *
 * - **Mitgeliefert** (`KERN-SHA256SUMS.txt` im Release-Zip). Beim Einspielen
 *   vertrauenswürdig, weil das Zip gegen `SHA256SUMS.txt` geprüft wird und
 *   eine SLSA-Provenance trägt. Danach liegt die Liste aber im selben
 *   Dateibaum wie die Dateien, die sie beschreibt. Wer eine Datei ändern
 *   kann, kann auch die Liste ändern.
 *   Findet: kaputte Uploads, halb eingespielte Updates, versehentlich
 *   editierte Dateien, einen abgebrochenen Kopiervorgang.
 *   Findet NICHT: einen Angreifer, der beides anfasst.
 *
 * - **Veröffentlicht** (dasselbe Asset, von GitHub geholt). Liegt außerhalb
 *   der Reichweite von jemandem, der nur den Webspace hat.
 *   Findet zusätzlich: absichtliche Manipulation.
 *   Braucht: Netz.
 *
 * Die Datenbank taugt ausdrücklich NICHT als dritter, unabhängiger Anker:
 * Wer Dateien schreiben kann, liest `config/db_config.php` und schreibt dort
 * genauso. Ein lokal gespeicherter Sollwert ist deshalb kein Sicherheitsnetz,
 * sondern nur eine Bequemlichkeit.
 *
 * Das Ergebnis sagt immer dazu, gegen WELCHE Liste geprüft wurde. Eine
 * Prüfung, die ihre eigene Aussagekraft verschweigt, ist schlimmer als keine:
 * Sie liest sich grün und ist es nur unter einer Annahme, die niemand nennt.
 *
 * ## Was sie nicht kann
 *
 * Sie wirkt erst ab der Version, die sie einführt. Eine Installation auf
 * v0.8.0 hat keine mitgelieferte Liste, und für v0.8.0 wurde auch keine
 * veröffentlicht - dort ist nichts zu messen.
 */
final class Integritaet {

    /** Name der Solliste, im Release-Zip und als Release-Asset. */
    public const MANIFEST = 'KERN-SHA256SUMS.txt';

    public const QUELLE_MITGELIEFERT = 'mitgeliefert';
    public const QUELLE_VEROEFFENTLICHT = 'veroeffentlicht';
    public const QUELLE_FEHLT = 'fehlt';

    /**
     * Prüft den Codebaum.
     *
     * @param bool $gegenVeroeffentlichte true = Solliste von GitHub holen.
     *        Aussagekräftiger, braucht aber Netz. Bei Fehlschlag wird NICHT
     *        still auf die mitgelieferte zurückgefallen - das wäre genau die
     *        Verwechslung von "konnte nicht prüfen" mit "geprüft, ist heil".
     *
     * @return array{
     *     quelle: string, version: string, geprueft: int,
     *     geaendert: array<int, string>, fehlt: array<int, string>,
     *     zusaetzlich: array<int, string>, heil: bool, hinweis: ?string
     * }
     */
    public static function pruefe(bool $gegenVeroeffentlichte = false): array {
        $version = self::version();
        $leer = [
            'quelle' => self::QUELLE_FEHLT, 'version' => $version, 'geprueft' => 0,
            'geaendert' => [], 'fehlt' => [], 'zusaetzlich' => [],
            'heil' => false, 'hinweis' => null,
        ];

        if ($gegenVeroeffentlichte) {
            $soll = self::veroeffentlichteListe($version);
            if ($soll === null) {
                // "Konnte nicht pruefen" ist kein Ergebnis - und vor allem
                // kein gutes. Es wird ausdruecklich NICHT still auf die
                // mitgelieferte Liste zurueckgefallen.
                return array_merge($leer, [
                    'hinweis' => 'Die veröffentlichte Prüfliste war nicht erreichbar. '
                        . 'Es wurde NICHT geprüft - das ist etwas anderes als "geprüft und heil".',
                ]);
            }
            $quelle = self::QUELLE_VEROEFFENTLICHT;
        } else {
            $soll = self::mitgelieferteListe();
            if ($soll === null) {
                return array_merge($leer, [
                    'hinweis' => 'Diese Installation bringt keine Prüfliste mit. Sie stammt aus einem '
                        . 'Release vor Einführung der Integritätsprüfung; es gibt nichts, woran '
                        . 'sich der Codebaum messen ließe.',
                ]);
            }
            $quelle = self::QUELLE_MITGELIEFERT;
        }

        $wurzel = UpdateService::baseDir();
        $geaendert = [];
        $fehlt = [];

        foreach ($soll as $pfad => $hash) {
            $voll = $wurzel . '/' . $pfad;
            if (!is_file($voll)) {
                $fehlt[] = $pfad;
                continue;
            }
            if (!hash_equals($hash, (string)@hash_file('sha256', $voll))) {
                $geaendert[] = $pfad;
            }
        }

        sort($geaendert);
        sort($fehlt);
        $zusaetzlich = self::zusaetzliche($wurzel, $soll);

        return [
            'quelle' => $quelle,
            'version' => $version,
            'geprueft' => count($soll),
            'geaendert' => $geaendert,
            'fehlt' => $fehlt,
            'zusaetzlich' => $zusaetzlich,
            'heil' => $geaendert === [] && $fehlt === [],
            'hinweis' => $quelle === self::QUELLE_MITGELIEFERT
                ? 'Geprüft gegen die mitgelieferte Liste. Sie liegt im selben Dateibaum wie die '
                  . 'geprüften Dateien - wer beides ändern kann, bleibt damit unentdeckt. Für eine '
                  . 'belastbare Aussage gegen die veröffentlichte Liste prüfen.'
                : null,
        ];
    }

    /**
     * Stellt Dateien aus dem Release wieder her.
     *
     * Nur Pfade, die in der Solliste stehen - eine Reparatur, die beliebige
     * Pfade schreiben könnte, wäre ein Werkzeug zum Überschreiben fremder
     * Dateien. Und nur aus dem Archiv der laufenden Version, dessen Prüfsumme
     * vorher gegen die veröffentlichte geprüft wird; sonst repariert man
     * kaputte Dateien mit anderen kaputten Dateien.
     *
     * @param array<int, string> $pfade
     * @return array{wiederhergestellt: array<int, string>, uebersprungen: array<int, string>}
     */
    public static function repariere(array $pfade): array {
        $version = self::version();
        $assets = UpdateService::releaseAssets($version);

        $zipName = null;
        foreach (array_keys($assets) as $name) {
            if (preg_match('/^hengstverzeichnis-framework-.*\.zip$/', $name) === 1) {
                $zipName = $name;
                break;
            }
        }
        if ($zipName === null || !isset($assets['SHA256SUMS.txt'])) {
            throw new \RuntimeException(
                "Für Version {$version} ist kein prüfbares Release-Archiv erreichbar. "
                . 'Ohne Prüfsummendatei wird nichts eingespielt.'
            );
        }

        // Soll gegen die VERÖFFENTLICHTE Liste - die mitgelieferte könnte
        // von derselben Hand stammen wie der Schaden.
        $soll = self::veroeffentlichteListe($version);
        if ($soll === null) {
            throw new \RuntimeException(
                'Die veröffentlichte Prüfliste war nicht erreichbar - ohne sie lässt sich nicht '
                . 'feststellen, was der Sollzustand ist.'
            );
        }

        $erlaubt = [];
        $uebersprungen = [];
        foreach ($pfade as $pfad) {
            if (isset($soll[$pfad]) && Baumordnung::istKern($pfad)) {
                $erlaubt[] = $pfad;
            } else {
                $uebersprungen[] = $pfad;
            }
        }
        if ($erlaubt === []) {
            return ['wiederhergestellt' => [], 'uebersprungen' => $uebersprungen];
        }

        $zipPfad = UpdateService::downloadToTempFile($assets[$zipName]);
        try {
            UpdateService::verifyArchiveChecksum($zipPfad, $zipName, $assets['SHA256SUMS.txt']);
            $wiederhergestellt = UpdateService::stelleDateienHer($zipPfad, UpdateService::baseDir(), $erlaubt, $soll);
        } finally {
            @unlink($zipPfad);
        }

        AuditLogger::log(
            'Codebaum repariert',
            'update',
            sprintf(
                '%d Datei(en) aus dem Release %s wiederhergestellt: %s',
                count($wiederhergestellt),
                $version,
                implode(', ', array_slice($wiederhergestellt, 0, 40))
            )
        );

        return ['wiederhergestellt' => $wiederhergestellt, 'uebersprungen' => $uebersprungen];
    }

    // ---- Listen ----------------------------------------------------------

    /** @return array<string, string>|null pfad => sha256 */
    public static function mitgelieferteListe(): ?array {
        $datei = UpdateService::baseDir() . '/' . self::MANIFEST;
        if (!is_file($datei)) {
            return null;
        }
        return self::parse((string)file_get_contents($datei));
    }

    /** @return array<string, string>|null pfad => sha256 */
    public static function veroeffentlichteListe(string $version): ?array {
        $assets = UpdateService::releaseAssets($version);
        if (!isset($assets[self::MANIFEST])) {
            return null;
        }
        try {
            $roh = UpdateService::httpGet($assets[self::MANIFEST]);
        } catch (\Throwable) {
            return null;
        }
        $liste = self::parse($roh);
        return $liste === [] ? null : $liste;
    }

    /**
     * Zeilen im Format von sha256sum, Kommentare mit '#'.
     *
     * @return array<string, string> pfad => sha256
     */
    private static function parse(string $inhalt): array {
        $liste = [];
        foreach (preg_split('/\R/', $inhalt) ?: [] as $zeile) {
            if ($zeile === '' || $zeile[0] === '#') {
                continue;
            }
            if (preg_match('/^([0-9a-f]{64})  (.+)$/', $zeile, $t) === 1) {
                $liste[$t[2]] = $t[1];
            }
        }
        return $liste;
    }

    /**
     * Dateien in KERN-Pfaden, die die Solliste nicht kennt.
     *
     * Werden nur GEMELDET, nie entfernt - aus demselben Grund wie beim
     * Update-Abgleich: Eine Datei, die wir nicht kennen, kann vom Betreiber
     * stammen. Sie ist trotzdem eine Meldung wert, denn eine untergeschobene
     * PHP-Datei in `src/` sieht genau so aus.
     *
     * @param array<string, string> $soll
     * @return array<int, string>
     */
    private static function zusaetzliche(string $wurzel, array $soll): array {
        $gefunden = [];

        foreach (Baumordnung::kernPfade() as $kernPfad) {
            $basis = $wurzel . '/' . $kernPfad;
            if (!is_dir($basis)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basis, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $datei) {
                if (!$datei->isFile()) {
                    continue;
                }
                $rel = substr($datei->getPathname(), strlen($wurzel) + 1);
                if (isset($soll[$rel]) || !Baumordnung::istKern($rel)) {
                    continue;
                }
                $gefunden[] = $rel;
            }
        }

        sort($gefunden);
        return $gefunden;
    }

    private static function version(): string {
        return defined('CORE_VERSION') ? (string)constant('CORE_VERSION') : '';
    }
}
