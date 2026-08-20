<?php
// src/Controllers/HorseSearchController.php

namespace App\Controllers;

use App\Database;
use PDO;

/**
 * Class HorseSearchController
 *
 * Ein Suchendpunkt für Pferde im Adminbereich (#341).
 *
 * WOZU. Sieben Addons brachten je eine eigene Pferdesuche mit - farbvererbung,
 * anpaarungs-empfehlung, inzuchtkoeffizient, titel-praemierungen, galerie,
 * gesundheitstests, verkaufsboerse. Sieben Kopien derselben Abfrage, sieben
 * Kopien desselben JavaScript-Blocks, und sieben Stellen, an denen ein Fehler
 * einzeln behoben werden müsste (Addons#125). Eine davon war das Maskieren der
 * SQL-Platzhalter `%` und `_` - die anderen sechs wissen bis heute nichts davon.
 *
 * SICHERHEIT. Der Endpunkt liegt hinter derselben Sitzungsprüfung wie der
 * übrige Adminbereich und verlangt `horses`.`view`. Er liefert AUSSCHLIESSLICH
 * die zwei Felder, die eine Auswahlliste braucht - `id` und `label`. Kein
 * `SELECT *`, keine Spalte "weil sie vielleicht noch nützlich ist": Ein
 * Suchendpunkt ist eine bequeme Stelle, um an Daten zu kommen, und was hier
 * nicht ausgeliefert wird, kann auch nicht abfließen.
 */
class HorseSearchController extends BaseController {

    /**
     * Höchstzahl gelieferter Treffer.
     *
     * Der Deckel ist keine Bequemlichkeit, sondern der Schutz: Ohne ihn ist
     * `?q=a` ein Ein-Klick-Vollexport des Pferdebestands über einen Endpunkt,
     * der für eine Auswahlliste gedacht ist. 50 Einträge sind mehr, als ein
     * Aufklappmenü sinnvoll zeigt - wer mehr sieht, muss genauer suchen.
     */
    private const MAX_TREFFER = 50;

    /**
     * Kürzeste Suchanfrage. Unter zwei Zeichen liefert der Endpunkt nichts:
     * Ein einzelner Buchstabe ist keine Suche, sondern ein Bestandsabzug in
     * Scheiben.
     */
    private const MIN_ZEICHEN = 2;

    /**
     * GET /admin/horses/search?q=...&geschlecht=...&rolle=...&nur_mit_farbe=1
     *
     * Antwort: [{"id": 42, "label": "Rogar S (DE1, 2010)"}, ...]
     */
    public function search(): void {
        $this->checkAuth();
        if (!$this->hasPermission('horses', 'view')) {
            $this->jsonAntwort(['error' => 'forbidden'], 403);
        }

        $suche = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($suche) < self::MIN_ZEICHEN) {
            $this->jsonAntwort([]);
        }

        // `%` und `_` sind in LIKE Platzhalter. Ohne Maskierung wäre `%` eine
        // Suche nach "alles" - der Deckel oben fängt das ab, aber die Anfrage
        // liefe trotzdem über den Gesamtbestand. `\` muss zuerst maskiert
        // werden, sonst maskiert es die gerade eingefügten Maskierungen.
        $muster = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $suche) . '%';

        $bedingungen = ['h.deleted_at IS NULL'];
        $werte = [];

        // Name, Lebensnummer und die weiteren Registriernummern (#246) - wer
        // eine Nummer im Kopf hat, sucht danach und nicht nach dem Namen.
        $bedingungen[] = '(h.name LIKE ? OR h.ueln LIKE ? OR h.foreign_ueln LIKE ?'
            . ' OR EXISTS (SELECT 1 FROM horse_registrations hr'
            . '            WHERE hr.horse_id = h.id AND hr.registration_number LIKE ?))';
        array_push($werte, $muster, $muster, $muster, $muster);

        // Filter `geschlecht`: der Filter, den die Addons tatsächlich brauchen.
        //
        // Das ist eine Korrektur: Der Endpunkt kannte zunächst nur `rolle`
        // (breeder/owner/keeper aus horse_persons) - und keines der sieben
        // Addons, die ihre eigene Suche mitbrachten, hat je danach gefiltert.
        // Was sie filterten, war das GESCHLECHT: Der Verpaarungsrechner bietet
        // als Vater nur Hengste und als Mutter nur Stuten an (#54). Ohne
        // diesen Filter schlug der gemeinsame Endpunkt zu einer Mutter einen
        // Hengst vor - die serverseitige Prüfung fängt das zwar ab, aber erst
        // nach dem Absenden, und der Vorschlag war schon falsch.
        $geschlecht = (string)($_GET['geschlecht'] ?? '');
        if (in_array($geschlecht, ['stallion', 'mare', 'gelding'], true)) {
            $bedingungen[] = 'h.sex = ?';
            $werte[] = $geschlecht;
        }

        // Filter `rolle`: schränkt auf Pferde ein, die überhaupt eine
        // Zuordnung dieser Rolle haben - etwa nur Pferde mit erfasstem Halter.
        $rolle = (string)($_GET['rolle'] ?? '');
        if (in_array($rolle, ['breeder', 'owner', 'keeper'], true)) {
            $bedingungen[] = 'EXISTS (SELECT 1 FROM horse_persons hp WHERE hp.horse_id = h.id AND hp.role = ?)';
            $werte[] = $rolle;
        }

        // Filter `nur_mit_farbe`: das Farbvererbungs-Addon kann mit Pferden
        // ohne erfasste Farbe nichts anfangen und filterte sie bisher selbst
        // heraus - nachdem es sie geholt hatte.
        if (!empty($_GET['nur_mit_farbe'])) {
            $bedingungen[] = "h.color IS NOT NULL AND h.color <> ''";
        }

        $sql = 'SELECT h.id, h.name, h.ueln, h.birth_year
                FROM horses h
                WHERE ' . implode(' AND ', $bedingungen) . '
                ORDER BY h.name ASC
                LIMIT ' . self::MAX_TREFFER;

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($werte);

        $treffer = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
            $treffer[] = [
                'id' => (int)$zeile['id'],
                'label' => self::beschriftung($zeile),
            ];
        }

        $this->jsonAntwort($treffer);
    }

    /**
     * "Rogar S (DE1, 2010)" - Name plus die beiden Angaben, die zwei
     * gleichnamige Pferde auseinanderhalten. Namensgleichheit ist im Bestand
     * real und nicht selten; eine Liste aus reinen Namen zwingt zum Raten.
     */
    private static function beschriftung(array $zeile): string {
        $zusatz = array_values(array_filter([
            trim((string)($zeile['ueln'] ?? '')),
            ($zeile['birth_year'] ?? null) ? (string)$zeile['birth_year'] : '',
        ], static fn($t) => $t !== ''));

        return (string)$zeile['name'] . ($zusatz === [] ? '' : ' (' . implode(', ', $zusatz) . ')');
    }

    /**
     * JSON ausliefern und beenden.
     *
     * `nosniff` ist hier kein Ritual: Ohne den Header darf der Browser den
     * Inhaltstyp erraten, und eine Antwort, die mit einem Pferdenamen beginnt,
     * kann als HTML durchgehen.
     */
    private function jsonAntwort(array $daten, int $status = 200): never {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        // Eine Trefferliste aus dem Adminbereich gehört in keinen Cache.
        header('Cache-Control: private, no-store');
        echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
