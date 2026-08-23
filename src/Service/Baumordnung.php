<?php
// src/Service/Baumordnung.php

namespace App\Service;

/**
 * Class Baumordnung
 *
 * Sagt für jeden Pfad einer Installation, WEM er gehört (#403).
 *
 * Bis hierher war das eine Frage, die an drei Stellen halb beantwortet wurde:
 * `UpdateService::PROTECTED_PATHS` wusste, was ein Update nicht überschreiben
 * darf; `.gitignore` wusste, was nicht ins Repo gehört; und der Rest stand in
 * der Dokumentation oder in niemandes Kopf. Was fehlte, war die Aussage
 * darüber, welcher Teil des Baums überhaupt welcher ART ist - und genau daran
 * hängen zwei Dinge, die ohne sie nicht gehen.
 *
 * ## Die drei Arten
 *
 * - **KERN** - kommt aus dem Release-Archiv und soll ihm entsprechen. Hier
 *   liegt nichts, das nicht der Kern hingelegt hat. Ein Update darf hier
 *   aufräumen, und eine Prüfung darf hier Abweichungen melden.
 * - **BETREIBER** - gehört dem Betreiber: hochgeladene Bilder, Zugangsdaten,
 *   installierte Addons. Ein Update fasst das nie an, unter keinen Umständen.
 * - **LAUFZEIT** - entsteht im Betrieb und ist entbehrlich: Protokolle,
 *   Zwischenablagen. Ein Update überschreibt hier nur, was das Archiv selbst
 *   mitbringt (die `.gitkeep`-Marker), und räumt NICHT auf - in einer
 *   Logdatei steht Betriebsgeschichte, auch wenn sie entbehrlich ist.
 *
 * ## Wofür das da ist
 *
 * **1. Das Update kann aufräumen.** `UpdateService::copyTree()` ist rein
 * additiv - es überschreibt und ergänzt, aber löscht nie. Für BETREIBER ist
 * das genau richtig. Für KERN ist es falsch, und #403 zeigt, was daraus wird:
 * Mit #344 wanderten zehn Sprachen aus `lang/` in Addons; die alten
 * Kerndateien blieben auf jeder in-place aktualisierten Installation liegen,
 * und `Translator::loadTable()` zieht die Kerndatei dem Addon ausdrücklich
 * vor. Zehn Sprachen fielen damit auf den Stand von v0.8.0 zurück, und der
 * Adminbereich meldete sie als "kern", also als sei alles in Ordnung.
 *
 * **2. Eine Selbstreparatur wird überhaupt erst möglich.** Addons haben seit
 * #224 eine Manipulationserkennung - Verzeichnis-Stempel und SHA-256 über
 * alle Dateien, und wer davon abweicht, wird nicht geladen. Ausgerechnet der
 * Kern, der diese Prüfung durchführt, hat selbst keine. Der Grund war nie
 * fehlender Wille, sondern eine fehlende Antwort auf die Frage: Woran soll
 * man ihn messen? Über die ganze Installation kann man nicht prüfen, dort
 * liegen Bilder und Protokolle. Über KERN kann man es.
 *
 * ## Wer hier etwas einträgt, sagt etwas zu
 *
 * Ein Pfad unter KERN heißt: In diesem Verzeichnis liegt nichts, das nicht
 * aus dem Release stammt - und was ein Update dort vorfindet und nicht
 * mitbringt, darf weg. Wer ein Verzeichnis hier einträgt, in das die
 * Anwendung zur Laufzeit schreibt, baut einen Datenverlust.
 *
 * Umgekehrt ist Vergessen genauso ein Fehler: Ein nicht eingeordneter Pfad
 * ist keine harmlose Lücke, sondern eine stille Ausnahme von beidem. Deshalb
 * prüft `BaumordnungTest`, dass **jeder** Pfad des Release-Archivs eingeordnet
 * ist. Ein neues Verzeichnis ohne Eintrag kommt nicht durch die Testsuite.
 */
final class Baumordnung {

    /** Kommt aus dem Release-Archiv und soll ihm entsprechen. */
    public const KERN = 'kern';

    /** Gehört dem Betreiber - ein Update fasst es nie an. */
    public const BETREIBER = 'betreiber';

    /** Entsteht im Betrieb, ist entbehrlich, wird aber nicht aufgeräumt. */
    public const LAUFZEIT = 'laufzeit';

    /**
     * Die Einordnung. Schlüssel sind Pfade relativ zur Installationswurzel.
     *
     * Es gewinnt der LÄNGSTE passende Eintrag, damit gemischte Verzeichnisse
     * darstellbar sind: `public` ist KERN, `public/uploads` ist BETREIBER.
     * Ohne diese Regel müsste jede Datei einzeln aufgeführt werden.
     */
    private const ORDNUNG = [
        // --- Kern: Code und mitgelieferte Inhalte ------------------------
        'src'                    => self::KERN,
        'lang'                   => self::KERN,
        'database'               => self::KERN,
        'docs'                   => self::KERN,
        'security'               => self::KERN,
        'public'                 => self::KERN,
        'config'                 => self::KERN,
        '.htaccess'              => self::KERN,
        '.gitignore'             => self::KERN,
        '.env.example'           => self::KERN,
        '.pre-commit-config.yaml' => self::KERN,
        'eslint.config.js'       => self::KERN,
        'CHANGELOG.md'           => self::KERN,
        'LICENSE'                => self::KERN,
        'README.md'              => self::KERN,
        'SECURITY.md'            => self::KERN,

        // --- Betreiber: Daten, Zugänge, fremder Code ----------------------
        // Die Zugangsdaten zur Datenbank. Liegt IN config/, gehört aber nicht
        // dem Kern - deshalb der längere Eintrag, der den kürzeren schlägt.
        'config/db_config.php'   => self::BETREIBER,
        // Hochgeladene Dateien. Liegt in public/, gehört aber dem Betreiber.
        'public/uploads'         => self::BETREIBER,
        // Pferdefotos, seit #366 ausserhalb des Webroots.
        'storage/horses'         => self::BETREIBER,
        // Installierte Addons. Fremder Code und fremde Daten.
        'plugins'                => self::BETREIBER,
        // Lokale Konfiguration. Steht nicht im Archiv, deshalb hier genannt.
        '.env'                   => self::BETREIBER,

        // --- Laufzeit: entsteht im Betrieb --------------------------------
        // Das Archiv bringt storage/logs/.gitkeep mit, damit das Verzeichnis
        // existiert; die Protokolle darin entstehen erst im Betrieb.
        'storage'                => self::LAUFZEIT,
        'var'                    => self::LAUFZEIT,
    ];

    /**
     * Zu welcher Art gehört dieser Pfad? Es gewinnt der längste passende
     * Eintrag; ist gar keiner da, gilt der Pfad als NICHT eingeordnet und
     * die Methode liefert null.
     *
     * Null ist ausdrücklich kein Standardwert im Sinne von "harmlos". Jeder
     * Aufrufer muss selbst entscheiden, wie er damit umgeht - und alle
     * entscheiden sich fail-closed: nicht kopieren, nicht löschen, nicht
     * prüfen. Ein stiller Standard hätte genau die Lücke erzeugt, die diese
     * Klasse schließen soll.
     */
    public static function klasse(string $relPath): ?string {
        $relPath = self::normalisiere($relPath);
        if ($relPath === '') {
            return null;
        }

        $treffer = null;
        $laenge = -1;
        foreach (self::ORDNUNG as $pfad => $art) {
            if ($relPath !== $pfad && !str_starts_with($relPath, $pfad . '/')) {
                continue;
            }
            if (strlen($pfad) > $laenge) {
                $treffer = $art;
                $laenge = strlen($pfad);
            }
        }

        return $treffer;
    }

    /** Gehört der Pfad dem Kern - darf ein Update ihn also abgleichen? */
    public static function istKern(string $relPath): bool {
        return self::klasse($relPath) === self::KERN;
    }

    /**
     * Gehört der Pfad dem Betreiber - muss ein Update ihn also in Ruhe
     * lassen?
     *
     * Ausdrücklich NUR bei einer echten BETREIBER-Einordnung. Ein nicht
     * eingeordneter Pfad zählt hier nicht mit, und das ist Absicht: Diese
     * Frage steuert das KOPIEREN, und ein "im Zweifel nicht" hieße dort, dass
     * ein neues Kern-Verzeichnis, das jemand einzuordnen vergessen hat, still
     * nicht mehr ausgeliefert würde. Das wäre ein schlimmerer Fehler als der,
     * den es zu verhindern gälte - und er fiele niemandem auf.
     *
     * Fail-closed ist deshalb hier die ANDERE Richtung, und sie steht in
     * darfAbgeglichenWerden(): gelöscht wird nur bei einer echten
     * KERN-Einordnung. Vergessen führt damit zu einer liegengebliebenen
     * Datei, nicht zu einer verlorenen.
     */
    public static function istBetreiber(string $relPath): bool {
        return self::klasse($relPath) === self::BETREIBER;
    }

    /**
     * Darf ein Update in diesem Pfad aufräumen - also entfernen, was die
     * Installation hat und das Archiv nicht?
     *
     * Nur bei einer echten KERN-Einordnung. Alles andere - BETREIBER,
     * LAUFZEIT und vor allem "gar nicht eingeordnet" - bleibt liegen. Das ist
     * die fail-closed-Richtung dieser Klasse: Im Zweifel eine Leiche, nie ein
     * Datenverlust.
     */
    public static function darfAbgeglichenWerden(string $relPath): bool {
        return self::klasse($relPath) === self::KERN;
    }

    /**
     * Die Pfade, die ein Update nie überschreiben darf.
     *
     * Ersetzt die frühere Konstante `UpdateService::PROTECTED_PATHS`. Die
     * Liste stand dort richtig, aber an der falschen Stelle: Sie beantwortete
     * die Eigentumsfrage für den einen Aufrufer, der sie gerade brauchte,
     * statt einmal für alle.
     *
     * @return array<int, string>
     */
    public static function geschuetztePfade(): array {
        $pfade = [];
        foreach (self::ORDNUNG as $pfad => $art) {
            if ($art === self::BETREIBER) {
                $pfade[] = $pfad;
            }
        }
        return $pfade;
    }

    /**
     * Die Kern-Verzeichnisse - die Wurzeln, unter denen ein Update abgleichen
     * darf. Gemischte Verzeichnisse sind dabei ausdrücklich enthalten
     * (`public` ist KERN, obwohl `public/uploads` darin dem Betreiber gehört);
     * der Abgleich selbst fragt für jeden Pfad einzeln nach.
     *
     * @return array<int, string>
     */
    public static function kernPfade(): array {
        $pfade = [];
        foreach (self::ORDNUNG as $pfad => $art) {
            if ($art === self::KERN) {
                $pfade[] = $pfad;
            }
        }
        return $pfade;
    }

    /**
     * Die vollständige Einordnung - für Tests und für die Anzeige im
     * Adminbereich.
     *
     * @return array<string, string>
     */
    public static function alle(): array {
        return self::ORDNUNG;
    }

    /** Führende und doppelte Schrägstriche weg, damit Vergleiche greifen. */
    private static function normalisiere(string $relPath): string {
        $relPath = str_replace('\\', '/', $relPath);
        $relPath = (string)preg_replace('#/+#', '/', $relPath);
        return trim($relPath, '/');
    }
}
