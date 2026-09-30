<?php
// src/Controllers/HorseController.php

namespace App\Controllers;

use App\Database;
use App\Service\HorseSearchCriteria;
use App\Service\HorseSearchSql;

class HorseController extends BaseController {

    /** Gültige Werte der horses.sex-ENUM (#165); NULL = unbekannt. */
    private const SEXES = ['stallion', 'mare', 'gelding'];

    /**
     * Genauigkeit des Geburtsdatums (#379) - Werte englisch wie im Schema.
     * Alles, was nicht in dieser Liste steht, gilt als 'day'; ein
     * unbekannter Wert darf keine Zeile anders erscheinen lassen, als sie
     * gemeint ist.
     */
    private const BIRTH_DATE_PRECISIONS = ['day', 'year'];

    /** Gültige Werte der horses.status-ENUM (Zuchtstatus seit #188). */
    private const STATUSES = ['active', 'inactive'];

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }

    /**
     * Zeilen je Seite der Verwaltungsliste. Die Liste lud bis dahin den
     * KOMPLETTEN Bestand ohne LIMIT - in der Dev-Instanz über 3200 Pferde auf
     * einer einzigen Seite.
     */
    public const PER_PAGE = 50;

    public function index(): void {
        $this->requirePermission('horses', 'view');

        // Optionaler Veröffentlichungs-Filter (?published=1|0), siehe
        // BaseController::normalizePublishedFilter(). Der normalisierte Wert (0/1)
        // geht als gebundener Parameter in den Filterbaustein.
        $publishedFilter = self::normalizePublishedFilter($_GET['published'] ?? null);

        // Zwei Fragen, zwei Rechte (Audit M13):
        // - Interne Einsicht (horses.internal/edit/delete/publish): Nur dann
        //   gilt die Liste OHNE die Sichtbarkeitsgrenzen des Katalogs.
        //   `view` allein öffnet nur den veröffentlichten Bestand - bis dahin
        //   sah jedes Konto mit horses.view, etwa aus der
        //   Registrierungs-Standardgruppe, alles Unveröffentlichte.
        // - contacts.view: Nur dann gibt es Filter und Vorschläge für
        //   Züchter, Besitzer, Halter und Deckstation. Sonst wären die Liste
        //   der Kontaktnamen und die Trefferzahl der Personenfilter ein
        //   Zugang zu Kontakten, die das Konto nicht lesen darf.
        $pferdeIntern = $this->hasInternalAccess('horses');
        $kontakteSichtbar = $this->hasPermission('contacts', 'view');
        if (!$pferdeIntern) {
            $publishedFilter = null; // Es gibt ohnehin nur Veröffentlichtes.
        }

        // Dieselbe Filterlogik wie der öffentliche Katalog - mit interner
        // Einsicht OHNE dessen Sichtbarkeitsgrenzen ($nurOeffentlich =
        // false): Die Verwaltung muss gerade die unveröffentlichten Züchter,
        // Stationen und Elterntiere finden können - das ist ihre Aufgabe.
        // Ohne interne Einsicht gelten dieselben Grenzen wie im Katalog.
        // Gelöschte bleiben draußen, die stehen im Papierkorb.
        //
        // Zwei Bausteine statt einem: HorseSearchSql erzeugt die Klausel und
        // bekommt die Anfrage nie zu sehen; HorseSearchCriteria liest die
        // Anfrage und erzeugt kein SQL. Über applyTo() geht ausschließlich,
        // WELCHE Bedingungen gelten - als Aufzählungsfälle, in denen kein
        // Anfragewert stecken kann. Die Werte selbst kommen als gebundene
        // Parameter aus params(). Anlass war der Semgrep-Fund
        // tainted-sql-string an genau dieser Interpolation: Er war sachlich
        // ein Fehlalarm, zeigte aber auf die Bauform dahinter - eine Klasse,
        // die die Anfrage liest UND SQL baut, hat den nächsten Missgriff
        // immer in Reichweite. Jetzt gibt es diese Reichweite nicht mehr, und
        // die Klausel unten besteht nachweislich nur aus Literalen des
        // Quelltexts.
        $sql = new HorseSearchSql(!$pferdeIntern);
        $criteria = HorseSearchCriteria::fromRequest($_GET, !$pferdeIntern, $publishedFilter, $kontakteSichtbar);
        $criteria->applyTo($sql);

        $whereSql = $sql->whereSql();
        $joinSql = $sql->joinSql();
        $params = $criteria->params();

        $db = Database::getInstance();

        $countStmt = $db->prepare("SELECT COUNT(*) {$joinSql} WHERE {$whereSql}");
        $countStmt->execute($params);
        $totalHorses = (int)$countStmt->fetchColumn();

        $totalPages = max(1, (int)ceil($totalHorses / self::PER_PAGE));
        // Seitenzahl validiert statt gecastet (BaseController::requestInt) und
        // auf den vorhandenen Bereich geklemmt - eine Seite 999 zeigt sonst
        // eine leere Tabelle ohne Hinweis darauf, warum.
        $page = min(self::requestInt('page', 1, 1), $totalPages);
        $offset = ($page - 1) * self::PER_PAGE;

        $stmt = $db->prepare("
            SELECT h.id, h.name, h.ueln, h.birth_year, h.status, h.is_deceased, h.is_published, h.image_url
            {$joinSql}
            WHERE {$whereSql}
            ORDER BY h.name ASC
            LIMIT ? OFFSET ?
        ");
        $index = 1;
        foreach ($params as $value) {
            $stmt->bindValue($index++, $value);
        }
        $stmt->bindValue($index++, self::PER_PAGE, \PDO::PARAM_INT);
        $stmt->bindValue($index, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $horses = $stmt->fetchAll();

        // Auswahllisten der Detailfilter. Mit interner Einsicht ohne
        // is_published-Einschränkung, sonst wie im Katalog - eine Farbe, die
        // nur ein unveröffentlichtes Pferd trägt, verriete es (Audit M13).
        $nurVeroeffentlicht = $pferdeIntern ? '' : ' AND is_published = 1';
        $colors = $db->query("SELECT DISTINCT color FROM horses WHERE color IS NOT NULL AND color != '' AND deleted_at IS NULL{$nurVeroeffentlicht} ORDER BY color ASC")->fetchAll(\PDO::FETCH_COLUMN);
        $breeds = $db->query("SELECT DISTINCT breed FROM horses WHERE breed IS NOT NULL AND breed != '' AND deleted_at IS NULL{$nurVeroeffentlicht} ORDER BY breed ASC")->fetchAll(\PDO::FETCH_COLUMN);
        // Namensvorschläge für die Filterfelder "Person" und "Deckstation".
        // Seit #336 speisen sich BEIDE aus derselben Abfrage: Personen und
        // Deckstationen stehen in einer Tabelle, und welcher Kontakt für ein
        // Pferd Züchter, Besitzer oder Deckstation ist, entscheidet erst die
        // Zuordnung (horse_persons.role bzw. der Stations-Steckplatz) - nicht
        // mehr die Tabelle. Die beiden Filterfelder bleiben trotzdem getrennt:
        // Sie fragen Verschiedenes ab (wer / wo), nur die Vorschlagsliste ist
        // dieselbe.
        //
        // Nur mit contacts.view, und ohne interne Kontakt-Einsicht nur
        // veröffentlichte Kontakte (Audit M13). Bis dahin genügte
        // horses.view, und die Liste enthielt jeden Kontaktnamen.
        $contactNames = [];
        if ($kontakteSichtbar) {
            $nurOeffentlicheKontakte = $this->hasInternalAccess('contacts') ? '' : ' AND is_published = 1';
            $contactNames = $db->query("SELECT DISTINCT name FROM contacts WHERE deleted_at IS NULL{$nurOeffentlicheKontakte} ORDER BY name ASC")->fetchAll(\PDO::FETCH_COLUMN);
        }

        $this->render('admin_horses', [
            'title' => 'Pferde verwalten',
            'horses' => $horses,
            'publishedFilter' => $publishedFilter,
            // Nur die vom Filterbaustein tatsächlich gelesenen (und geprüften)
            // Werte gehen ins Formular und in die Links zurück.
            'filters' => $criteria->activeParams(),
            'hasActiveFilters' => $criteria->hasActiveFilters(),
            'colors' => $colors,
            'breeds' => $breeds,
            // Zwei Schlüssel, eine Quelle (#336) - siehe oben.
            'stations' => $contactNames,
            'persons' => $contactNames,
            'kontakteSichtbar' => $kontakteSichtbar,
            'pferdeIntern' => $pferdeIntern,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalHorses,
            'perPage' => self::PER_PAGE,
            'canCreate' => $this->hasPermission('horses', 'create'),
            'canEdit' => $this->hasPermission('horses', 'edit'),
            'canDelete' => $this->hasPermission('horses', 'delete'),
            'canPublish' => $this->hasPermission('horses', 'publish')
        ]);
    }

    /**
     * Massen-Veröffentlichung / -Depublikation der ausgewählten Pferde. Nur mit
     * 'horses.publish' erlaubt; setzt is_published unabhängig vom Lebenszyklus-Status.
     */
    /**
     * Fragt die Addons, ob dieses Pferd veroeffentlicht werden darf (#335).
     *
     * WARUM EIN EIGENER HOOK UND NICHT `horse.before_save`. Weil ein Veto beim
     * SPEICHERN etwas anderes ist als ein Veto beim VEROEFFENTLICHEN. Ein
     * Addon, das Widersprueche findet - Elternteil juenger als das Fohlen,
     * Vater gleich Mutter, Halterzeitraum nach dem Todesjahr (Addons#114) -,
     * soll verhindern, dass so ein Datensatz oeffentlich wird. Es soll aber
     * NICHT verhindern koennen, dass ein Bearbeiter seine halbfertige Eingabe
     * ueberhaupt speichert; sonst kaeme er nie an den Punkt, an dem er den
     * Widerspruch aufloesen kann. `horse.before_save` bleibt deshalb ein
     * doAction ohne Rueckgabewert - diese Entscheidung wird hier ausdruecklich
     * nicht angetastet.
     *
     * ZEITPUNKT: NACH dem Speichern, nicht davor. Ein Addon muss den
     * tatsaechlichen Stand befragen koennen - die Zuordnungen in
     * horse_persons etwa entstehen erst nach dem INSERT des Pferds. Ein Veto
     * vor dem Speichern muesste gegen die rohen POST-Daten urteilen und
     * traefe damit reihenweise falsche Entscheidungen.
     *
     * FAIL-CLOSED, ABER NUR FUER DIE VEROEFFENTLICHUNG: Liefert der Filter
     * Unbrauchbares (ein Addon ist abgestuerzt, HookManager verschluckt die
     * Ausnahme und behaelt den vorherigen Wert), gilt "keine Einwaende" - der
     * Startwert ist die leere Liste. Ein abgestuerztes Addon darf keine
     * Veroeffentlichung blockieren, denn niemand koennte den Grund beheben.
     *
     * Die Abfrage selbst lebt seit Audit N49 in App\Service\HorsePublishVeto
     * (einwaende()/freigeben()), damit Formular, Massen-Veroeffentlichung und
     * CSV-Import denselben Weg gehen.
     *
     * Diese Huelle veroeffentlicht das Pferd, wenn es gewuenscht ist und kein Addon
     * Einwaende hat (#335), und liefert die Gruende fuer die Rueckmeldung an
     * den Bearbeiter.
     *
     * Seit Audit N49 der EINZIGE Uebergang 0→1 im Formular: store() und
     * update() schreiben das Haekchen nicht mehr im Haupt-INSERT/-UPDATE,
     * sondern rufen am Ende des Speicherpfads (nach horse.after_save) diese
     * Methode. Bricht ein Zwischenschritt ab, bleibt ein unveroeffentlichtes
     * Pferd unveroeffentlicht (fail-safe).
     *
     * Der Datensatz bleibt gespeichert - nur das Haekchen faellt. Das ist der
     * ganze Punkt: Die Arbeit geht nicht verloren, sie wird nur nicht
     * oeffentlich.
     *
     * @return string[]
     */
    private function enforcePublishBlockers(int $horseId, int $gewuenscht): array {
        if ($gewuenscht !== 1) {
            return [];
        }
        $blockers = \App\Service\HorsePublishVeto::freigeben($horseId);
        if ($blockers === []) {
            return [];
        }

        \App\Service\AuditLogger::log(
            "Veroeffentlichung durch Addon verhindert",
            "horses",
            "Pferd ID {$horseId}: " . implode(' | ', $blockers)
        );

        return $blockers;
    }

    /**
     * Die Gruende so an die Liste weiterreichen, dass sie dort anzeigbar sind,
     * ohne in der Adresszeile zu landen (sie koennen lang sein und Namen
     * enthalten).
     *
     * @param string[] $blockers
     */
    private function rememberPublishBlockers(int $horseId, array $blockers): void {
        if ($blockers === []) {
            return;
        }
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $_SESSION['publish_blockers'] = ['horse_id' => $horseId, 'gruende' => $blockers];
    }

    public function bulkPublish(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('horses', 'publish');

        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($id) => $id > 0));
        $publish = !empty($_POST['publish']) ? 1 : 0;

        if ($ids) {
            $db = Database::getInstance();
            // Einzelne, vollständig parametrisierte UPDATEs statt einer dynamisch
            // zusammengesetzten IN (...)-Liste - inhaltlich identisch, vermeidet aber
            // jede String-Interpolation im SQL (auch die des ?-Platzhalter-Strings).
            $stmt = $db->prepare("UPDATE horses SET is_published = ? WHERE id = ? AND deleted_at IS NULL");
            $blockiert = [];
            foreach ($ids as $id) {
                // Das Veto gilt nur fuer das Veroeffentlichen (#335) -
                // Depublizieren muss immer moeglich bleiben, sonst haenge die
                // Ruecknahme einer Veroeffentlichung am Zustand eines Addons.
                if ($publish === 1) {
                    $gruende = \App\Service\HorsePublishVeto::einwaende((int)$id);
                    if ($gruende !== []) {
                        $blockiert[(int)$id] = $gruende;
                        continue;
                    }
                }
                $stmt->execute([$publish, $id]);
            }

            $durchgelaufen = count($ids) - count($blockiert);
            \App\Service\AuditLogger::log(
                $publish ? "Pferde veröffentlicht" : "Veröffentlichung von Pferden zurückgenommen",
                "horses",
                $durchgelaufen . " Datensätze (IDs: " . implode(', ', array_diff($ids, array_keys($blockiert))) . ")"
                . ($blockiert === [] ? '' : sprintf(
                    ' - %d durch Addon-Einwand nicht veröffentlicht (IDs: %s)',
                    count($blockiert),
                    implode(', ', array_keys($blockiert))
                ))
            );

            if ($blockiert !== []) {
                // Sammelmeldung: Bei einer Massenaktion interessiert weniger der
                // einzelne Grund als die Frage, WELCHE Pferde nicht durchkamen.
                $this->rememberPublishBlockers(0, array_map(
                    static fn($id, $gruende) => "Pferd #{$id}: " . implode(', ', $gruende),
                    array_keys($blockiert),
                    array_values($blockiert)
                ));
            }
        }

        // Zurück zur Liste, wie der Benutzer sie verlassen hat: Suche, Seite und
        // Veröffentlichungs-Filter reisen als versteckte Felder mit (siehe
        // partials/publish_bulk_bar.php) und werden hier gegen eine Weißliste
        // wieder zum Query-String zusammengesetzt.
        header("Location: /admin/horses?success=published"
            . self::publishedFilterQuery($_POST['published'] ?? null)
            . self::listFilterQuery($_POST, [...HorseSearchCriteria::FILTER_KEYS, 'page']));
        exit;
    }

    /**
     * Obergrenze der Eltern-Auswahllisten im Pferdeformular.
     *
     * Das Formular lud bisher die KOMPLETTE horses-Tabelle - fünf Spalten je
     * Zeile - und rendert sie zweimal als <option>-Liste (Vater und Mutter),
     * das Geschlecht erst in der Schleife in PHP gefiltert. Bei jedem Aufruf
     * von "Neues Pferd" und "Pferd bearbeiten", auch wenn niemand die Eltern
     * anfasst. Mit dem Bestand wächst das linear, und der Browser bekommt
     * zweimal dieselbe Liste.
     *
     * Der saubere Endzustand ist eine serverseitige Suche wie in den Addons
     * (SEARCH_LIMIT 50 + datalist). Bis dahin ist die Liste hier gedeckelt -
     * das nimmt der Seite das unbegrenzte Wachstum, ohne die Bedienung zu
     * ändern.
     */
    private const PARENT_OPTION_LIMIT = 1000;

    /**
     * Auswahlliste möglicher Elterntiere - gedeckelt, aber immer inklusive
     * der bereits gesetzten Eltern.
     *
     * Ohne das Nachladen fiele die gespeicherte Zuordnung beim nächsten
     * Öffnen des Formulars still auf "kein Elternteil" zurück, sobald das
     * Pferd hinter der Obergrenze liegt - ein Datenverlust, den niemand
     * bemerkt, bis es zu spät ist.
     *
     * Dasselbe gilt für gesetzte Eltern im Papierkorb (Audit M34): Sie stehen
     * nicht in der gefilterten Grundliste, und ohne das Nachladen nullte jedes
     * Speichern des Fohlens die Verknüpfung. Nachgeladen wird deshalb OHNE
     * deleted_at-Filter; jede Zeile trägt `deleted_at`, damit die View
     * Papierkorb-Einträge nur für den Steckplatz anbietet, in dem sie schon
     * stehen, und sie als "(im Papierkorb)" kennzeichnet.
     *
     * @param array<int, int|null> $mustInclude IDs, die enthalten sein müssen
     * @return array<int, array<string, mixed>>
     */
    private function parentOptions(\PDO $db, array $mustInclude = []): array {
        $stmt = $db->query(
            "SELECT id, name, ueln, birth_year, sex, NULL AS deleted_at FROM horses WHERE deleted_at IS NULL"
            . " ORDER BY name ASC LIMIT " . self::PARENT_OPTION_LIMIT
        );
        $horses = $stmt->fetchAll();

        $vorhanden = array_map('intval', array_column($horses, 'id'));
        $fehlend = array_values(array_unique(array_filter(
            array_map('intval', $mustInclude),
            static fn (int $id): bool => $id > 0 && !in_array($id, $vorhanden, true)
        )));

        if ($fehlend !== []) {
            $nachladen = $db->prepare(
                "SELECT id, name, ueln, birth_year, sex, deleted_at FROM horses"
                . " WHERE id IN (" . implode(',', array_fill(0, count($fehlend), '?')) . ")"
            );
            $nachladen->execute($fehlend);
            foreach ($nachladen->fetchAll() as $row) {
                $horses[] = $row;
            }
            usort($horses, static fn (array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));
        }

        return $horses;
    }

    public function create(): void {
        $this->requirePermission('horses', 'create');

        $db = Database::getInstance();
        $allHorses = $this->parentOptions($db);

        // EINE Auswahlliste für BEIDE Steckplätze der Verlaufszeile (#336).
        // Vorher kamen sie aus zwei Tabellen, und wer einen Hof anlegte, musste
        // vorab entscheiden, ob er "Person" oder "Deckstation" ist - genau die
        // Frage, die #336 abgeschafft hat. Die Steckplätze selbst bleiben
        // getrennt (wer / wo, siehe Tabellenkommentar zu horse_persons),
        // deshalb nur eine Liste, aber weiterhin zwei Felder.
        $stmt = $db->query("SELECT id, name FROM contacts WHERE deleted_at IS NULL ORDER BY name ASC");
        $allContacts = $stmt->fetchAll();

        $this->render('admin_horse_form', [
            'title' => 'Neues Pferd anlegen',
            'horse' => null,
            'allHorses' => $allHorses,
            'allContacts' => $allContacts,
            'horsePersons' => [],
            'horseRegistrations' => [],
            'canPublish' => $this->hasPermission('horses', 'publish')
        ]);
    }

    public function store(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('horses', 'create');

        $name = trim($_POST['name'] ?? '');
        // NULL statt '' bei leerer UELN: die Spalte ist UNIQUE, mehrere Pferde ohne
        // UELN würden sonst als doppelter Leerstring-Eintrag kollidieren (SQLSTATE
        // 23000) - NULL-Werte sind für UNIQUE-Constraints in MySQL/MariaDB dagegen
        // nie doppelt.
        $ueln = trim($_POST['ueln'] ?? '') ?: null;
        // foreign_ueln wird vom Formular seit #246 nicht mehr übermittelt (die
        // Nummern leben in horse_registrations); das Feld bleibt aus
        // Abwärtskompatibilität beschreibbar (z. B. Skript-POSTs), sonst NULL.
        $foreign_ueln = trim($_POST['foreign_ueln'] ?? '') ?: null;
        $birth_year = !empty($_POST['birth_year']) ? (int)$_POST['birth_year'] : null;
        // Geburtsdatum (#188) ist führend: wenn gesetzt, wird birth_year daraus
        // abgeleitet und ein abweichend übermitteltes Jahr ignoriert.
        $birth_date = $this->parseDate($_POST['birth_date'] ?? '');
        if ($birth_date !== null) {
            $birth_year = (int)substr($birth_date, 0, 4);
        }
        // Genauigkeit des Geburtsdatums (#379). Kanonisch gehalten: Ohne
        // Datum ist eine Genauigkeit bedeutungslos, und bliebe sie nach dem
        // Leeren des Datums auf 'year' stehen, traege der Datensatz eine
        // Aussage, die niemand mehr sieht und die beim naechsten Erfassen
        // eines echten Datums still weiterwirkte.
        $birth_date_precision = in_array($_POST['birth_date_precision'] ?? '', self::BIRTH_DATE_PRECISIONS, true)
            ? (string)$_POST['birth_date_precision']
            : 'day';
        if ($birth_date === null) {
            $birth_date_precision = 'day';
        }
        $color = trim($_POST['color'] ?? '');
        $sex = in_array($_POST['sex'] ?? '', self::SEXES, true) ? $_POST['sex'] : null;
        // Kastrationsdatum (#239): fachlich nur bei Wallachen sinnvoll (das
        // Formular blendet das Feld entsprechend ein/aus), serverseitig aber
        // tolerant für jedes Geschlecht übernommen - ein späterer Wechsel der
        // Geschlechtsangabe darf das erfasste Datum nicht still verwerfen.
        $castration_date = $this->parseDate($_POST['castration_date'] ?? '');
        $breed = trim($_POST['breed'] ?? '') ?: null;
        $height_cm = $this->parseHeightCm($_POST['height_cm'] ?? '');
        $breeding_station_id = !empty($_POST['breeding_station_id']) ? (int)$_POST['breeding_station_id'] : null;
        // Freitext-Deckstation nur übernehmen, wenn das Feld überhaupt übermittelt
        // wurde (#214): das Formular kennt kein name="breeding_station" mehr (nur
        // persons[N][station_contact_id]), regulär setzt den Wert der CSV-Import.
        // NULL bedeutet hier "nicht übermittelt" - beim INSERT bleibt die Spalte
        // dann leer statt auf '' gesetzt.
        $breeding_station = array_key_exists('breeding_station', $_POST) ? trim($_POST['breeding_station']) : null;
        $description = trim($_POST['description'] ?? '');
        $status = in_array($_POST['status'] ?? '', self::STATUSES, true) ? $_POST['status'] : 'active';
        // Lebensstatus (#188): ein gesetztes Todesjahr impliziert verstorben.
        $death_year = $this->parseYear($_POST['death_year'] ?? '');
        $is_deceased = (!empty($_POST['is_deceased']) || $death_year !== null) ? 1 : 0;

        // Todesjahr vor Geburtsjahr ist unmöglich - vor dem Bild-Upload prüfen,
        // damit bei Ablehnung keine verwaiste Datei zurückbleibt.
        if ($death_year !== null && $birth_year !== null && $death_year < $birth_year) {
            header("Location: /admin/horses?error=death_before_birth");
            exit;
        }

        // Veröffentlichung (öffentliche Sichtbarkeit) ist bewusst UNABHÄNGIG vom
        // Zucht-/Lebensstatus und wird über ein eigenes Flag gesteuert. Nur mit der
        // Berechtigung 'horses.publish' darf ein Pferd veröffentlicht werden - fehlt
        // sie, bleibt es unveröffentlicht, egal welchen Status es hat.
        $isPublished = (!empty($_POST['is_published']) && $this->hasPermission('horses', 'publish')) ? 1 : 0;

        // Sire handling
        $sire_id = !empty($_POST['sire_id']) ? (int)$_POST['sire_id'] : null;
        $sire_name = $sire_id ? null : (trim($_POST['sire_name'] ?? '') ?: null);
        $sire_ueln = $sire_id ? null : (trim($_POST['sire_ueln'] ?? '') ?: null);

        // Dam handling
        $dam_id = !empty($_POST['dam_id']) ? (int)$_POST['dam_id'] : null;
        $dam_name = $dam_id ? null : (trim($_POST['dam_name'] ?? '') ?: null);
        $dam_ueln = $dam_id ? null : (trim($_POST['dam_ueln'] ?? '') ?: null);

        // Eltern müssen existieren und dürfen nicht im Papierkorb liegen
        // (Audit M34) - ersetzt zugleich den früheren 500er aus dem
        // Fremdschlüssel bei einer unbekannten ID. Ein neues Pferd hat keine
        // Nachkommen, eine Zyklusprüfung entfällt hier.
        foreach ([$sire_id, $dam_id] as $elternId) {
            if ($elternId !== null && ($error = $this->linkTargetError(null, $elternId))) {
                header("Location: /admin/horses?error={$error}");
                exit;
            }
        }

        // Abstammungs-Validierung (#166 Geschlecht, #298 Widersprüche) - vor
        // dem Bild-Upload, damit bei Ablehnung keine verwaiste Datei
        // zurückbleibt.
        if ($error = $this->pedigreeContradiction($sire_id, $dam_id, $birth_year)) {
            header("Location: /admin/horses?error={$error}");
            exit;
        }

        if ($error = $this->parentSexMismatch($sire_id, $dam_id)) {
            header("Location: /admin/horses?error={$error}");
            exit;
        }

        // Zeitraum nach dem Todesjahr (#334) - ebenfalls vor dem Bild-Upload.
        if ($error = $this->personPeriodAfterDeath((array)($_POST['persons'] ?? []), $death_year)) {
            header("Location: /admin/horses?error={$error}");
            exit;
        }

        // Feldlängen und UELN-Eindeutigkeit (Audit N48) - vor dem Upload.
        // Bisher liefen beide erst beim INSERT in einen DB-Fehler (500), und
        // das schon abgelegte Foto blieb verwaist liegen.
        $this->pruefeFelder([
            'name' => $name, 'ueln' => $ueln, 'foreign_ueln' => $foreign_ueln,
            'sire_name' => $sire_name, 'sire_ueln' => $sire_ueln,
            'dam_name' => $dam_name, 'dam_ueln' => $dam_ueln,
            'color' => $color, 'breed' => $breed,
            'breeding_station' => $breeding_station, 'description' => $description,
        ], $ueln, 0);

        // Handle Photo Upload
        $imageUrl = $this->handleImageUpload($_FILES['horse_image'] ?? null);

        // Plugin-Hook (#56): Erweiterungspunkt VOR dem Anlegen eines Pferdes, z. B. für
        // zusätzliche verbandsspezifische Prüfungen. Kann das Anlegen selbst nicht
        // blockieren (siehe HookManager-Isolation) - ein fehlerhaftes Plugin darf den
        // Kern-Workflow nie verhindern.
        $this->hooks()->doAction('horse.before_save', null, $_POST);

        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO horses (name, ueln, foreign_ueln, sire_id, sire_name, sire_ueln, dam_id, dam_name, dam_ueln, birth_year, birth_date, birth_date_precision, color, sex, castration_date, breed, height_cm, breeding_station_id, breeding_station, description, status, is_deceased, death_year, is_published, image_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        try {
            // is_published = 0 (Audit N49): Veröffentlicht wird erst am Ende,
            // wenn kein Addon Einwände hat - siehe enforcePublishBlockers().
            $stmt->execute([$name, $ueln, $foreign_ueln, $sire_id, $sire_name, $sire_ueln, $dam_id, $dam_name, $dam_ueln, $birth_year, $birth_date, $birth_date_precision, $color, $sex, $castration_date, $breed, $height_cm, $breeding_station_id, $breeding_station, $description, $status, $is_deceased, $death_year, 0, $imageUrl]);
        } catch (\PDOException $e) {
            // Das Foto liegt schon in der Ablage, der Datensatz, der darauf
            // verweisen sollte, nicht (Audit N48).
            if ($imageUrl !== null) {
                @unlink(\App\Helper\HorseImagePath::dir() . '/' . basename($imageUrl));
            }
            // 1062: Rennen zwischen uelnConflict() und dem INSERT.
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                header("Location: /admin/horses?error=ueln_taken");
                exit;
            }
            throw $e;
        }
        $newHorseId = (int)$db->lastInsertId();

        // Das beim Anlegen hochgeladene Foto ist ab #339 auch eine Zeile in
        // `horse_media` - sonst waere es das einzige Bild, das die Medienliste
        // beim naechsten Bearbeiten nicht kennt, und ein "Als Hauptbild" auf
        // ein spaeteres Foto liesse es unerreichbar zurueck.
        if ($imageUrl !== null) {
            \App\Service\HorseMedia::hinzufuegen($newHorseId, $imageUrl, null, null, 10);
        }

        \App\Service\AuditLogger::log("Pferd angelegt", "horses", "Name: {$name}" . ($ueln ? " (UELN: {$ueln})" : ""));

        // Save Person Roles & Ownership History (horse_persons)
        $this->saveHorsePersons($db, $newHorseId, $_POST['persons'] ?? []);

        // Weitere Lebensnummern (#246) speichern; die normalisierte Liste geht
        // zusätzlich ins Auto-Linking, denn auch diese Nummern identifizieren
        // das Pferd eindeutig (analog ueln/foreign_ueln).
        $registrationNumbers = $this->saveRegistrations($db, $newHorseId, $ueln);

        // Run auto-linking to automatically attach existing unlinked placeholders to this new horse
        $this->autoLinkMatches($newHorseId, $name, $ueln, $foreign_ueln, $birth_year, $registrationNumbers, $sex);

        // Plugin-Hook (#56): Erweiterungspunkt NACH dem Anlegen, z. B. für Folgeaktionen
        // in einem Plugin (Benachrichtigung, verknüpfte Zusatzdaten anlegen etc.).
        $this->hooks()->doAction('horse.after_save', $newHorseId, $_POST, true);

        // Veto gegen die VEROEFFENTLICHUNG (#335) - erst jetzt, wo der
        // Datensatz samt Zuordnungen tatsaechlich steht.
        $blockers = $this->enforcePublishBlockers($newHorseId, $isPublished);
        $this->rememberPublishBlockers($newHorseId, $blockers);

        header("Location: /admin/horses?success=" . ($blockers === [] ? 'created' : 'created_not_published'));
        exit;
    }

    public function edit(): void {
        $this->requirePermission('horses', 'edit');

        $id = $_GET['id'] ?? null;
        if (!$id) {
            header("Location: /admin/horses");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM horses WHERE id = ?");
        $stmt->execute([$id]);
        $horse = $stmt->fetch();

        if (!$horse) {
            header("Location: /admin/horses");
            exit;
        }

        // Das Formular eines Papierkorb-Datensatzes wird weiterhin
        // ausgeliefert (etwa fuer eine DSGVO-Auskunft), sagt aber jetzt, dass
        // Speichern nicht geht - wie admin_contact_form seit #296 (damals noch
        // getrennt als admin_person_form und admin_breeding_station_form, vor
        // dem Zusammenlegen in #336). Ohne den Hinweis fuellt
        // jemand das Formular aus und bekommt erst beim Absenden eine
        // Fehlermeldung.
        $isDeleted = $horse['deleted_at'] !== null;

        $allHorses = $this->parentOptions($db, [
            $horse['sire_id'] ?? null,
            $horse['dam_id'] ?? null,
        ]);

        // Eine Liste für beide Steckplätze (#336) - Begründung siehe create().
        $stmt = $db->query("SELECT id, name FROM contacts WHERE deleted_at IS NULL ORDER BY name ASC");
        $allContacts = $stmt->fetchAll();

        // Beide Steckplätze holen ihren Namen jetzt aus derselben Tabelle
        // (#336), bleiben aber zwei getrennte JOINs: Eine Zeile kann eine
        // Person UND eine Deckstation nennen, und das sind verschiedene
        // Kontakte.
        //
        // Neu WÄHLBAR sind nur aktive Kontakte ($allContacts, auch für neue
        // Zeilen per JS). Eine BESTEHENDE Zuordnung auf einen Kontakt im
        // Papierkorb bleibt dagegen sichtbar und erhalten (Audit M34): Früher
        // filterten beide JOINs deleted_at, die Zeile bekam keine passende
        // Option, schickte '' zurück - und jedes Speichern des Pferds löschte
        // die Zuordnung. Die View zeigt sie als "(im Papierkorb)" nur in
        // dieser einen Zeile; saveHorsePersons() nimmt sie nur an, wenn sie
        // dort schon stand.
        $stmt = $db->prepare("SELECT hp.*, c.name, s.name AS station_name, c.deleted_at AS contact_deleted_at, s.deleted_at AS station_deleted_at FROM horse_persons hp LEFT JOIN contacts c ON hp.contact_id = c.id LEFT JOIN contacts s ON hp.station_contact_id = s.id WHERE hp.horse_id = ? ORDER BY hp.id ASC");
        $stmt->execute([$id]);
        $horsePersons = $stmt->fetchAll();

        // Weitere Lebensnummern (#246). Hat ein Bestandspferd noch keine
        // Zeilen in der Kindtabelle, aber ein befülltes foreign_ueln (z. B.
        // per CSV-Import nach der Migration entstanden), wird das Feld als
        // Vorbelegung zerlegt angeboten - beim Speichern wandern die Nummern
        // dann in die Kindtabelle, foreign_ueln selbst bleibt unangetastet.
        $stmt = $db->prepare("SELECT registration_number FROM horse_registrations WHERE horse_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt->execute([$id]);
        $horseRegistrations = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (!$horseRegistrations && !empty($horse['foreign_ueln'])) {
            $horseRegistrations = array_values(array_filter(array_map('trim', preg_split('~\s*/\s*~', (string)$horse['foreign_ueln']) ?: []), fn($n) => $n !== ''));
        }

        // Plugin-Hook (#255): Erweiterungspunkt für eigene Abschnitte im
        // Bearbeitungsformular - das Admin-Gegenstück zu horse.detail_sections.
        // Addons, die pferdbezogene Daten pflegen, bekommen die horse_id damit
        // aus dem Aufrufkontext und brauchen keine eigene Pferdeauswahl mehr
        // (Anlass: Addons#87 lud dafür den kompletten Bestand als <select>).
        //
        // Bewusst NICHT in create(): dort existiert noch keine horses.id, ein
        // Abschnitt könnte nichts speichern und würde auf eine nicht vorhandene
        // ID posten.
        //
        // Callbacks liefern fertiges, selbst escapetes HTML (die View gibt es
        // absichtlich unescaped aus, siehe admin_horse_form.php) - dieselbe
        // Verantwortungsteilung wie bei horse.detail_sections. Hier wiegt sie
        // allerdings schwerer, nicht leichter: Der Abschnitt steht hinter Login
        // und horses.edit, ein XSS trifft also Redakteure und Admins mit vollen
        // Rechten.
        //
        // Achtung beim Datenvertrag: $horse ist hier der ROHE Datensatz aus
        // "SELECT * FROM horses" - ohne die Sichtbarkeitsfilter der öffentlichen
        // Seite, ohne die station_*-Felder und ohne deleted_at-Filter (der Hook
        // feuert also auch für Pferde im Papierkorb).
        $pluginEditSections = $this->hooks()->applyFilters('horse.edit_sections', [], $horse);

        $this->render('admin_horse_form', [
            'title' => 'Pferd bearbeiten',
            'horse' => $horse,
            'allHorses' => $allHorses,
            'allContacts' => $allContacts,
            'horsePersons' => $horsePersons,
            'horseRegistrations' => $horseRegistrations,
            'canPublish' => $this->hasPermission('horses', 'publish'),
            'isDeleted' => $isDeleted,
            'horseMedia' => \App\Service\HorseMedia::forHorse((int)$horse['id']),
            'pluginEditSections' => $pluginEditSections
        ]);
    }

    public function update(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('horses', 'edit');

        $id = $_POST['id'] ?? null;
        if (!$id) {
            header("Location: /admin/horses");
            exit;
        }

        $name = trim($_POST['name'] ?? '');
        // NULL statt '' bei leerer UELN: die Spalte ist UNIQUE, mehrere Pferde ohne
        // UELN würden sonst als doppelter Leerstring-Eintrag kollidieren (SQLSTATE
        // 23000) - NULL-Werte sind für UNIQUE-Constraints in MySQL/MariaDB dagegen
        // nie doppelt.
        $ueln = trim($_POST['ueln'] ?? '') ?: null;
        // foreign_ueln nur überschreiben, wenn das Feld übermittelt wurde
        // (#246, gleiches Muster wie breeding_station/#214): Das Formular kennt
        // kein name="foreign_ueln" mehr - die Nummern leben in
        // horse_registrations, und das Kompatibilitätsfeld darf durch einen
        // normalen Edit nicht still genullt werden. NULL heißt "nicht
        // übermittelt" und lässt den Bestandswert im UPDATE unten (CASE)
        // unangetastet; ein übermittelter Leerstring löscht weiterhin bewusst.
        $foreign_ueln = array_key_exists('foreign_ueln', $_POST) ? trim($_POST['foreign_ueln']) : null;
        $birth_year = !empty($_POST['birth_year']) ? (int)$_POST['birth_year'] : null;
        // Geburtsdatum (#188) ist führend, siehe store().
        $birth_date = $this->parseDate($_POST['birth_date'] ?? '');
        if ($birth_date !== null) {
            $birth_year = (int)substr($birth_date, 0, 4);
        }
        // Genauigkeit des Geburtsdatums (#379). Kanonisch gehalten: Ohne
        // Datum ist eine Genauigkeit bedeutungslos, und bliebe sie nach dem
        // Leeren des Datums auf 'year' stehen, traege der Datensatz eine
        // Aussage, die niemand mehr sieht und die beim naechsten Erfassen
        // eines echten Datums still weiterwirkte.
        $birth_date_precision = in_array($_POST['birth_date_precision'] ?? '', self::BIRTH_DATE_PRECISIONS, true)
            ? (string)$_POST['birth_date_precision']
            : 'day';
        if ($birth_date === null) {
            $birth_date_precision = 'day';
        }
        $color = trim($_POST['color'] ?? '');
        $sex = in_array($_POST['sex'] ?? '', self::SEXES, true) ? $_POST['sex'] : null;
        // Kastrationsdatum (#239): tolerant für jedes Geschlecht, siehe store().
        $castration_date = $this->parseDate($_POST['castration_date'] ?? '');
        $breed = trim($_POST['breed'] ?? '') ?: null;
        $height_cm = $this->parseHeightCm($_POST['height_cm'] ?? '');
        $breeding_station_id = !empty($_POST['breeding_station_id']) ? (int)$_POST['breeding_station_id'] : null;
        // Freitext-Deckstation nur überschreiben, wenn das Feld übermittelt wurde
        // (#214): das Bearbeiten-Formular kennt kein name="breeding_station" mehr,
        // ein normaler Edit lieferte daher immer '' und löschte damit still den
        // z. B. per CSV-Import gesetzten Wert. NULL heißt "nicht übermittelt" und
        // lässt den Bestandswert im UPDATE unten per COALESCE unangetastet; ein
        // übermittelter Leerstring löscht dagegen weiterhin bewusst.
        $breeding_station = array_key_exists('breeding_station', $_POST) ? trim($_POST['breeding_station']) : null;
        $description = trim($_POST['description'] ?? '');
        $status = in_array($_POST['status'] ?? '', self::STATUSES, true) ? $_POST['status'] : 'active';
        // Lebensstatus (#188): ein gesetztes Todesjahr impliziert verstorben.
        $death_year = $this->parseYear($_POST['death_year'] ?? '');
        $is_deceased = (!empty($_POST['is_deceased']) || $death_year !== null) ? 1 : 0;

        // Todesjahr vor Geburtsjahr ist unmöglich - vor Bild-Änderungen prüfen
        // (gleiche Begründung wie bei parentSexMismatch unten).
        if ($death_year !== null && $birth_year !== null && $death_year < $birth_year) {
            header("Location: /admin/horses?error=death_before_birth");
            exit;
        }

        // Sire handling
        $sire_id = !empty($_POST['sire_id']) ? (int)$_POST['sire_id'] : null;
        $sire_name = $sire_id ? null : (trim($_POST['sire_name'] ?? '') ?: null);
        $sire_ueln = $sire_id ? null : (trim($_POST['sire_ueln'] ?? '') ?: null);

        // Dam handling
        $dam_id = !empty($_POST['dam_id']) ? (int)$_POST['dam_id'] : null;
        $dam_name = $dam_id ? null : (trim($_POST['dam_name'] ?? '') ?: null);
        $dam_ueln = $dam_id ? null : (trim($_POST['dam_ueln'] ?? '') ?: null);

        // Prevent self-referencing
        if ($sire_id === (int)$id) $sire_id = null;
        if ($dam_id === (int)$id) $dam_id = null;

        // Abstammungs-Validierung (#166 Geschlecht, #298 Widersprüche) - vor
        // Bild-Änderungen, damit bei Ablehnung weder Dateien gelöscht noch
        // verwaiste angelegt werden.
        if ($error = $this->pedigreeContradiction($sire_id, $dam_id, $birth_year)) {
            header("Location: /admin/horses?error={$error}");
            exit;
        }

        if ($error = $this->parentSexMismatch($sire_id, $dam_id)) {
            header("Location: /admin/horses?error={$error}");
            exit;
        }

        // Zeitraum nach dem Todesjahr (#334) - vor den Bild-Änderungen, aus
        // demselben Grund wie die Prüfungen darüber.
        if ($error = $this->personPeriodAfterDeath((array)($_POST['persons'] ?? []), $death_year)) {
            header("Location: /admin/horses?error={$error}");
            exit;
        }

        // Feldlängen und UELN-Eindeutigkeit (Audit N48), siehe store().
        // foreign_ueln und breeding_station sind NULL, wenn nicht übermittelt,
        // und werden dann nicht geprüft.
        $this->pruefeFelder([
            'name' => $name, 'ueln' => $ueln, 'foreign_ueln' => $foreign_ueln,
            'sire_name' => $sire_name, 'sire_ueln' => $sire_ueln,
            'dam_name' => $dam_name, 'dam_ueln' => $dam_ueln,
            'color' => $color, 'breed' => $breed,
            'breeding_station' => $breeding_station, 'description' => $description,
        ], $ueln, (int)$id);

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT status, is_published, deleted_at, sire_id, dam_id FROM horses WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            header("Location: /admin/horses");
            exit;
        }

        // Schreibschutz fuer den Papierkorb (#296, fuer Pferde nachgezogen mit
        // #322). Personen und Deckstationen hatten ihn, die Pferde nicht -
        // obwohl hier am meisten daran haengt.
        //
        // Der Guard steht bewusst VOR der Bildbehandlung und vor
        // saveHorsePersons()/saveRegistrations(): Ein UPDATE mit
        // "AND deleted_at IS NULL" allein liefe zu spaet. remove_image loescht
        // die Bilddatei mit unlink() physisch von der Platte, und die beiden
        // save-Methoden bauen die Kindtabellen komplett neu auf - all das
        // waere am geloeschten Datensatz laengst passiert, bevor das UPDATE
        // ueberhaupt null Zeilen meldet. Ein spaeteres "Wiederherstellen" im
        // Papierkorb brachte dann einen stillschweigend veraenderten Datensatz
        // zurueck.
        //
        // Der Fall braucht keine Boshaftigkeit: Redakteur A legt Pferd 42 in
        // den Papierkorb, Redakteur B hat /admin/horses/edit?id=42 noch offen
        // und speichert.
        if ($existing['deleted_at'] !== null) {
            header("Location: /admin/horses?error=deleted");
            exit;
        }

        // Neu gesetzte Eltern (Audit M34, N47): müssen existieren, dürfen
        // nicht im Papierkorb liegen und keinen Stammbaum-Zyklus schließen -
        // letzteres auch dann, wenn Geburtsjahre fehlen und
        // pedigreeContradiction() deshalb nichts sieht. Geprüft wird nur, was
        // sich gegenüber dem Bestand ÄNDERT: Eine bestehende Verknüpfung auf
        // einen Elternteil im Papierkorb bleibt speicherbar.
        foreach (['sire_id' => $sire_id, 'dam_id' => $dam_id] as $spalte => $elternId) {
            $bisher = $existing[$spalte] !== null ? (int)$existing[$spalte] : null;
            if ($elternId !== null && $elternId !== $bisher
                && ($error = $this->linkTargetError((int)$id, $elternId))) {
                header("Location: /admin/horses?error={$error}");
                exit;
            }
        }

        // Veröffentlichung (öffentliche Sichtbarkeit) ist unabhängig vom Status und
        // darf nur mit 'horses.publish' geändert werden. Ohne diese Berechtigung
        // bleibt der bisherige Veröffentlichungszustand unverändert erhalten (ein
        // übermittelter Wunsch wird stillschweigend ignoriert statt gespeichert).
        if ($this->hasPermission('horses', 'publish')) {
            $isPublished = !empty($_POST['is_published']) ? 1 : 0;
        } else {
            $isPublished = (int)($existing['is_published'] ?? 0);
        }

        // KEIN Foto-Upload und kein "Foto entfernen" mehr in diesem Formular
        // (#339). Beides laeuft ueber den Medien-Abschnitt und
        // HorseMediaController; `image_url` traegt weiterhin das Hauptbild und
        // wird von HorseMedia::syncMainImage() nachgefuehrt. Dieses UPDATE
        // fasst die Spalte deshalb GAR NICHT an (Audit N68). Frueher schrieb
        // es den eingangs gelesenen Bestandswert zurueck - ein Lost Update,
        // das seit dem echten Entfernen geloeschter Hauptbilder schadet:
        // Liest das Formular image_url = A, loescht parallel ein
        // Medien-Request A samt Datei, und schreibt das Formular danach A
        // zurueck, zeigt das Katalogbild auf eine Datei, die es nicht mehr
        // gibt.
        //
        // Die beiden bisherigen Zweige sind damit nicht nur ueberfluessig,
        // sondern waren seit #366 auch falsch: Sie loeschten die Datei unter
        // `public/` . image_url - also im Webroot, wo seither keine
        // Pferdefotos mehr liegen. Ein "Foto entfernen" leerte die Spalte und
        // liess die Datei stehen.

        // Plugin-Hook (#56): siehe store() für die Begründung, hier für den Update-Pfad.
        $this->hooks()->doAction('horse.before_save', (int)$id, $_POST);

        // breeding_station = COALESCE(?, breeding_station) (#214): NULL steht für
        // "Feld nicht übermittelt" (siehe oben) und erhält den Bestandswert.
        // foreign_ueln analog (#246), aber per CASE statt COALESCE: ein
        // übermittelter Leerstring soll NULL speichern (wie früher `?: null`),
        // nicht den Leerstring selbst.
        $stmt = $db->prepare("UPDATE horses SET name = ?, ueln = ?, foreign_ueln = CASE WHEN ? IS NULL THEN foreign_ueln ELSE NULLIF(?, '') END, sire_id = ?, sire_name = ?, sire_ueln = ?, dam_id = ?, dam_name = ?, dam_ueln = ?, birth_year = ?, birth_date = ?, birth_date_precision = ?, color = ?, sex = ?, castration_date = ?, breed = ?, height_cm = ?, breeding_station_id = ?, breeding_station = COALESCE(?, breeding_station), description = ?, status = ?, is_deceased = ?, death_year = ?, is_published = ? WHERE id = ? AND deleted_at IS NULL");
        // Das Häkchen steht NICHT im Haupt-UPDATE (Audit N49): Bisher wurde
        // is_published = 1 hier festgeschrieben und erst am Ende bei einem
        // Addon-Einwand zurückgenommen. Brach ein Zwischenschritt ab
        // (saveHorsePersons() & Co.), blieb das Pferd ungeprüft öffentlich.
        // Jetzt: Depublizieren wirkt sofort, ein bereits veröffentlichtes
        // Pferd bleibt es vorläufig, der Übergang 0→1 geschieht nur in
        // enforcePublishBlockers(). Bekannte Restlücke (Status quo, nicht
        // Gegenstand des Befunds): Ein BEREITS veröffentlichtes Pferd bleibt
        // bei einem Abbruch veröffentlicht, auch mit neuen, widersprüchlichen
        // Stammdaten. Die bekannten Abbruch-Auslöser entfallen durch
        // jahrAusEingabe() und die Prüfungen aus Audit N48. Eine Transaktion
        // über den ganzen Speicherpfad gibt es bewusst nicht - Hooks sollen
        // nicht in einer offenen Transaktion laufen.
        $vorlaeufig = ($isPublished === 1 && (int)$existing['is_published'] === 1) ? 1 : 0;
        try {
            $stmt->execute([$name, $ueln, $foreign_ueln, $foreign_ueln, $sire_id, $sire_name, $sire_ueln, $dam_id, $dam_name, $dam_ueln, $birth_year, $birth_date, $birth_date_precision, $color, $sex, $castration_date, $breed, $height_cm, $breeding_station_id, $breeding_station, $description, $status, $is_deceased, $death_year, $vorlaeufig, $id]);
        } catch (\PDOException $e) {
            // 1062 (Audit N48): Rennen zwischen uelnConflict() und dem UPDATE.
            // Bis hierher wurde nichts geschrieben.
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                header("Location: /admin/horses?error=ueln_taken");
                exit;
            }
            throw $e;
        }

        \App\Service\AuditLogger::log("Pferd aktualisiert", "horses", "Pferd ID {$id}: {$name}" . ($ueln ? " (UELN: {$ueln})" : ""));

        // Save Person Roles & Ownership History (horse_persons)
        $this->saveHorsePersons($db, (int)$id, $_POST['persons'] ?? []);

        // Weitere Lebensnummern (#246), siehe store().
        $registrationNumbers = $this->saveRegistrations($db, (int)$id, $ueln);

        // Run auto-linking for matches
        $this->autoLinkMatches((int)$id, $name, $ueln, ($foreign_ueln !== null && $foreign_ueln !== '') ? $foreign_ueln : null, $birth_year, $registrationNumbers, $sex);

        // Plugin-Hook (#56): siehe store() für die Begründung, hier für den Update-Pfad.
        $this->hooks()->doAction('horse.after_save', (int)$id, $_POST, false);

        // Siehe store(): Veto gegen die Veroeffentlichung, nicht gegen das
        // Speichern (#335).
        $blockers = $this->enforcePublishBlockers((int)$id, $isPublished);
        $this->rememberPublishBlockers((int)$id, $blockers);

        header("Location: /admin/horses?success=" . ($blockers === [] ? 'updated' : 'updated_not_published'));
        exit;
    }

    /**
     * Stockmaß (#188) aus dem Formular: nur Ganzzahlen im plausiblen Bereich
     * 50-250 cm (identisch zum CSV-Import), alles andere wird zu NULL - die
     * min/max-Attribute des Formulars sind rein clientseitig, und ein Wert
     * jenseits von SMALLINT UNSIGNED liefe sonst in einen DB-Fehler nach dem
     * Bild-Upload (verwaiste Datei).
     */
    private function parseHeightCm(string $value): ?int {
        $value = trim($value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        $height = (int)$value;
        return ($height >= 50 && $height <= 250) ? $height : null;
    }

    /**
     * Jahresangabe (#188, Todesjahr) aus dem Formular: Ganzzahl im selben
     * Bereich wie der CSV-Import (1600 bis Folgejahr), sonst NULL - gleiche
     * Begründung wie bei parseHeightCm().
     */
    private function parseYear(string $value): ?int {
        $value = trim($value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        $year = (int)$value;
        return ($year >= 1600 && $year <= (int)date('Y') + 1) ? $year : null;
    }

    /**
     * Jahresangabe einer Zuordnungszeile (from_year/until_year): wie
     * parseYear(), Nicht-Skalare (persons[0][from_year][]=…) werden NULL.
     */
    private function jahrAusEingabe(mixed $wert): ?int {
        return is_scalar($wert) ? $this->parseYear((string)$wert) : null;
    }

    /**
     * Datumsangabe (#188 Geburtsdatum, #239 Kastrationsdatum) aus dem
     * Formular: erwartet YYYY-MM-DD (input type="date"), verlangt ein reales
     * Kalenderdatum und denselben Jahresbereich wie der CSV-Import (1600 bis
     * Folgejahr). Alles andere wird zu NULL - das Formular behandelt die
     * Felder als optional, die strenge Variante mit Zeilenfehlern lebt im
     * HorseCsvImporter.
     */
    private function parseDate(string $value): ?string {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }
        [, $year, $month, $day] = $m;
        if (!checkdate((int)$month, (int)$day, (int)$year)) {
            return null;
        }
        if ((int)$year < 1600 || (int)$year > (int)date('Y') + 1) {
            return null;
        }
        return $value;
    }

    /**
     * Geschlechts-Validierung der Abstammung (#166): Der Vater darf keine Stute
     * sein, die Mutter weder Hengst noch Wallach. NULL (unbekannt) besteht die
     * Prüfung immer - so bleibt der Altbestand ohne Geschlechtsangabe editierbar.
     * Ein Wallach ist als Vater serverseitig zulässig (Nachkommen können vor dem
     * Legen entstanden sein); das Formular bietet ihn lediglich nicht an.
     * Liefert den Fehlercode für den Redirect oder null, wenn alles passt.
     */
    private function parentSexMismatch(?int $sireId, ?int $damId): ?string {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT sex FROM horses WHERE id = ?");

        if ($sireId) {
            $stmt->execute([$sireId]);
            // Nur die Stute ist ausgeschlossen. Ein Wallach als Vater ist
            // ausdruecklich erlaubt: Ein spaeter kastrierter Hengst wird als
            // 'gelding' gefuehrt und hat trotzdem gedeckt (#298).
            if ($stmt->fetchColumn() === 'mare') {
                return 'sex_mismatch_sire';
            }
        }
        if ($damId) {
            $stmt->execute([$damId]);
            $sex = $stmt->fetchColumn();
            if ($sex === 'stallion' || $sex === 'gelding') {
                return 'sex_mismatch_dam';
            }
        }
        return null;
    }

    /**
     * Widersprueche in der Abstammung, die beim Speichern nie entstehen
     * duerfen (#298). Bis dahin pruefte nichts: Erlaubt waren ein Vater, der
     * juenger ist als sein Fohlen, und dasselbe Pferd als Vater UND Mutter.
     * Im Altbestand der Dev-Instanz stecken davon zwoelf bzw. ein Fall - sie
     * stammen aus der Migration, haetten aber genauso ueber das Formular
     * entstehen koennen.
     *
     * Die Schwelle gab es bereits, nur an der falschen Stelle: autoLinkMatches()
     * verknuepft Freitext-Eltern nur bei plausiblem Elternalter. Beim MANUELLEN
     * Setzen von sire_id/dam_id griff sie nicht.
     *
     * Bewusst nur die harten Widersprueche, keine Altersspanne: Ein Elternteil
     * darf nicht gleich alt oder juenger sein als sein Nachkomme - das ist
     * unmoeglich, nicht bloss ungewoehnlich. Die 3-30-Jahre-Spanne aus dem
     * Auto-Linking bleibt dort, wo sie hingehoert: Sie ist eine Heuristik fuer
     * "welchen Datensatz meint dieser Freitext", kein Naturgesetz. Frueh oder
     * spaet deckende Tiere kommen vor, und eine Eingabe abzulehnen, die richtig
     * sein kann, waere schlimmer als sie zuzulassen.
     *
     * Fehlt ein Geburtsjahr, wird nicht geprueft - wie im Auto-Linking auch.
     */
    /**
     * Prüft die Zeiträume der Personen-/Stationszeilen gegen das Todesjahr
     * (#334).
     *
     * Für Geburts- und Todesjahr gibt es diese Prüfung längst
     * (death_before_birth), für die Abstammung ebenso
     * (pedigreeContradiction, parentSexMismatch) - für die Zeiträume in
     * horse_persons fehlte das Gegenstück. Im Bestand standen dadurch
     * Halterzeiträume, die NACH dem Todesjahr des Pferdes beginnen.
     *
     * Geprüft wird bewusst nur gegen ein bekanntes Todesjahr: Ist keines
     * erfasst, gibt es nichts zu widersprechen. Und geprüft wird nur der
     * Beginn und das Ende gegen dieses eine Jahr - alles Weitere (Zeiträume,
     * die sich überschneiden, Lücken) ist eine fachliche Bewertung und gehört
     * in die Plausibilitätsprüfung, nicht in den Speicherpfad.
     *
     * @param array<int, array<string, mixed>> $personsData Rohdaten aus $_POST
     */
    private function personPeriodAfterDeath(array $personsData, ?int $deathYear): ?string {
        if ($deathYear === null) {
            return null;
        }

        foreach ($personsData as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (['from_year', 'until_year'] as $feld) {
                // Derselbe Helfer wie in saveHorsePersons(): geprüft wird genau
                // der Wert, der gespeichert wird (Audit N49).
                $jahr = $this->jahrAusEingabe($item[$feld] ?? null);
                if ($jahr !== null && $jahr > $deathYear) {
                    return 'period_after_death';
                }
            }
        }

        return null;
    }

    /**
     * Darf $parentId als Elternteil (neu) gesetzt werden? (Audit M34, N47)
     *
     * - 'parent_missing': Die ID gibt es nicht (mehr), etwa hart gelöscht,
     *   während das Formular offen war. Früher lief das in den
     *   Fremdschlüssel (500).
     * - 'parent_in_trash': Ein Pferd im Papierkorb ist nicht neu wählbar;
     *   eine BESTEHENDE Verknüpfung bleibt erlaubt (der Aufrufer prüft nur
     *   geänderte Rollen).
     * - 'pedigree_cycle': $childId ist bereits Vorfahre von $parentId - die
     *   Verknüpfung schlösse einen Kreis. Ein Vorfahre als Elternteil ist
     *   dagegen kein Widerspruch (Inzucht) und bleibt erlaubt.
     *
     * $childId null heißt: neues Pferd, ohne Nachkommen, keine Zyklusprüfung.
     */
    private function linkTargetError(?int $childId, int $parentId): ?string {
        $stmt = Database::getInstance()->prepare("SELECT deleted_at FROM horses WHERE id = ?");
        $stmt->execute([$parentId]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($zeile === false) {
            return 'parent_missing';
        }
        if ($zeile['deleted_at'] !== null) {
            return 'parent_in_trash';
        }
        if ($childId !== null && ($childId === $parentId || in_array($childId, $this->ancestorIds($parentId), true))) {
            return 'pedigree_cycle';
        }
        return null;
    }

    /**
     * Alle Vorfahren von $horseId (Audit N47), generationenweise über
     * sire_id/dam_id mit gebundenen Platzhaltern. Datensätze im Papierkorb
     * zählen mit - auch sie sind Teil des Stammbaums, und ein Wiederherstellen
     * machte einen so entstandenen Zyklus sichtbar. Eine Besucht-Menge und
     * die Tiefengrenze beenden die Suche auch bei einem Altbestand, der
     * bereits einen Zyklus enthält (analog $visited in PedigreeBuilder).
     *
     * @return int[]
     */
    private function ancestorIds(int $horseId, int $maxGenerationen = 40): array {
        $db = Database::getInstance();
        $besucht = [];
        $ebene = [$horseId];
        for ($generation = 0; $generation < $maxGenerationen && $ebene !== []; $generation++) {
            $platzhalter = implode(',', array_fill(0, count($ebene), '?'));
            $stmt = $db->prepare("SELECT sire_id, dam_id FROM horses WHERE id IN ({$platzhalter})");
            $stmt->execute($ebene);
            $naechste = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $zeile) {
                foreach ([$zeile['sire_id'], $zeile['dam_id']] as $elternId) {
                    if ($elternId === null) {
                        continue;
                    }
                    $elternId = (int)$elternId;
                    if (!isset($besucht[$elternId])) {
                        $besucht[$elternId] = true;
                        $naechste[] = $elternId;
                    }
                }
            }
            $ebene = $naechste;
        }
        return array_keys($besucht);
    }

    /**
     * Feldlängen und UELN-Eindeutigkeit vor dem Speichern (Audit N48); leitet
     * bei einem Verstoß um und beendet den Request.
     *
     * Die Spaltenbreiten kommen aus HorseCsvImporter::MAX_LENGTHS - EINE
     * Quelle für Formular und Import. Der Feldname im Redirect stammt aus
     * der Konstante, nie aus der Eingabe.
     *
     * @param array<string, mixed> $werte
     */
    private function pruefeFelder(array $werte, ?string $ueln, int $eigeneId): void {
        $feld = \App\Service\HorseCsvImporter::ersteUeberlaenge($werte);
        if ($feld !== null) {
            header("Location: /admin/horses?error=too_long&field=" . rawurlencode($feld));
            exit;
        }
        if ($error = $this->uelnConflict($ueln, $eigeneId)) {
            header("Location: /admin/horses?error={$error}");
            exit;
        }
    }

    /**
     * Ist die UELN schon an ein anderes Pferd vergeben? (Audit N48)
     *
     * Vergleicht in SQL mit der Spalten-Collation - genau wie der
     * UNIQUE-Index, also auch 'de 123' gegen 'DE 123' - und bewusst ohne
     * deleted_at-Filter, weil der Index auch den Papierkorb umfasst.
     * Liefert 'ueln_taken', 'ueln_taken_trash' oder null.
     */
    private function uelnConflict(?string $ueln, int $eigeneId): ?string {
        if ($ueln === null || $ueln === '') {
            return null;
        }
        $stmt = Database::getInstance()->prepare("SELECT deleted_at FROM horses WHERE ueln = ? AND id <> ? LIMIT 1");
        $stmt->execute([$ueln, $eigeneId]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($zeile === false) {
            return null;
        }
        return $zeile['deleted_at'] === null ? 'ueln_taken' : 'ueln_taken_trash';
    }

    private function pedigreeContradiction(?int $sireId, ?int $damId, ?int $birthYear): ?string {
        if ($sireId !== null && $damId !== null && $sireId === $damId) {
            return 'same_sire_and_dam';
        }

        if ($birthYear === null) {
            return null;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT birth_year FROM horses WHERE id = ?");
        foreach (['sire' => $sireId, 'dam' => $damId] as $rolle => $parentId) {
            if ($parentId === null) {
                continue;
            }
            $stmt->execute([$parentId]);
            $parentYear = $stmt->fetchColumn();
            if ($parentYear !== false && $parentYear !== null && (int)$parentYear >= $birthYear) {
                return $rolle === 'sire' ? 'sire_not_older' : 'dam_not_older';
            }
        }
        return null;
    }

    /**
     * Foto beim Anlegen eines Pferds.
     *
     * Eigene Positivliste (JPEG, PNG, WebP - kein GIF als Hauptbild beim
     * Anlegen, wie bisher), aber EINE Ablage- und Bereinigungsstelle:
     * HorseMedia::speichereUpload() entfernt Metadaten vor der Ablage
     * (Audit M21) und schreibt atomar nach storage/horses (#366).
     */
    private function handleImageUpload(?array $file): ?string {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) === 0) {
            return null;
        }

        return \App\Service\HorseMedia::speichereUpload(
            $file,
            ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp']
        );
    }

    /**
     * Weitere Lebensnummern (#246) aus dem Formular speichern: kompletter
     * Ersatz der Zeilen dieses Pferds (gleiches Muster wie saveHorsePersons()).
     *
     * Der Block gilt nur als übermittelt, wenn registrations[] oder der
     * Formular-Marker registrations_present im POST steht - ein Request OHNE
     * beides (z. B. ein Skript-POST, der das Feld nicht kennt) lässt den
     * Bestand unangetastet, analog zum breeding_station-COALESCE (#214).
     * Das versteckte registrations_present-Feld ist nötig, weil eine komplett
     * geleerte Nummernliste sonst gar keinen registrations-Schlüssel sendet
     * und sich nicht von "nicht übermittelt" unterscheiden ließe.
     *
     * Validierung analog der bestehenden Felder (still normalisieren statt
     * DB-Fehler): trimmen, Leereinträge und Duplikate (case-insensitiv)
     * verwerfen, Überlängen (> 50 Zeichen, Spaltenbreite) verwerfen, und die
     * Primärnummer ueln wird nicht dupliziert.
     *
     * @return string[] Die gespeicherten (bzw. bei "nicht übermittelt" die
     *                  bestehenden) Nummern in Reihenfolge - für das Auto-Linking.
     */
    private function saveRegistrations(\PDO $db, int $horseId, ?string $primaryUeln): array {
        if (!array_key_exists('registrations', $_POST) && !array_key_exists('registrations_present', $_POST)) {
            $stmt = $db->prepare("SELECT registration_number FROM horse_registrations WHERE horse_id = ? ORDER BY sort_order ASC, id ASC");
            $stmt->execute([$horseId]);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        $numbers = [];
        $seen = [];
        $primaryKey = mb_strtolower(trim($primaryUeln ?? ''));
        foreach ((array)($_POST['registrations'] ?? []) as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $number = trim($raw);
            if ($number === '' || mb_strlen($number) > 50) {
                continue;
            }
            $key = mb_strtolower($number);
            if ($key === $primaryKey || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $numbers[] = $number;
        }

        // Dasselbe DELETE-vor-INSERT-Muster wie in saveHorsePersons und
        // deshalb dieselbe Klammer (#317): Scheitert ein INSERT, stuende das
        // Pferd sonst ohne jede weitere Lebensnummer da.
        $eigeneTransaktion = !$db->inTransaction();
        if ($eigeneTransaktion) {
            $db->beginTransaction();
        }

        try {
            $db->prepare("DELETE FROM horse_registrations WHERE horse_id = ?")->execute([$horseId]);
            $insert = $db->prepare("INSERT INTO horse_registrations (horse_id, registration_number, sort_order) VALUES (?, ?, ?)");
            foreach ($numbers as $sortOrder => $number) {
                $insert->execute([$horseId, $number, $sortOrder]);
            }

            if ($eigeneTransaktion) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($eigeneTransaktion && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return $numbers;
    }

    /**
     * Auto-links unlinked placeholders matching $ueln, $foreignUeln, one of the
     * horse's registration numbers (#246) or $name to $horseId
     *
     * Seit Audit N47 mit denselben Prüfungen wie das Formular, als
     * SQL-Bedingungen an den UPDATEs (alle Werte gebunden, die Bausteine sind
     * Literale):
     * - Geschlecht (#166): Als Vater nur, wenn $sex nicht 'mare' ist (ein
     *   Wallach bleibt erlaubt, #298); als Mutter nur, wenn $sex weder
     *   'stallion' noch 'gelding' ist.
     * - Alter (#298) auch im UELN-Zweig: Das Kind muss jünger sein. Die
     *   3-30-Jahre-Heuristik bleibt dem Namenszweig vorbehalten.
     * - Vater ≠ Mutter: Ist $horseId schon der andere Elternteil des Kindes,
     *   wird nicht verknüpft.
     * - Zyklus: Vorfahren von $horseId werden nie seine Kinder. Die Menge
     *   bleibt während der UPDATEs gültig, weil nur Kinder-Zeiger auf
     *   $horseId gesetzt werden.
     * Was hier nicht verknüpft wird, bleibt als Platzhalter im Match-Werkzeug.
     *
     * @param string[] $registrationNumbers
     */
    private function autoLinkMatches(int $horseId, string $name, ?string $ueln, ?string $foreignUeln = null, ?int $birthYear = null, array $registrationNumbers = [], ?string $sex = null): void {
        $db = Database::getInstance();

        $uelnsToMatch = array_unique(array_filter(array_merge(
            [trim($ueln ?? ''), trim($foreignUeln ?? '')],
            array_map('trim', $registrationNumbers)
        )));

        $alsVater = $sex !== 'mare';
        $alsMutter = !in_array($sex, ['stallion', 'gelding'], true);

        // Zyklus-Ausschluss (N47): einmal je Aufruf.
        $vorfahren = $this->ancestorIds($horseId);
        $zyklusBedingung = $vorfahren !== []
            ? ' AND id NOT IN (' . implode(',', array_fill(0, count($vorfahren), '?')) . ')'
            : '';

        // Alle UPDATEs schließen die eben gespeicherte Zeile selbst aus (AND id != ?):
        // ohne diesen Guard kann sich ein Pferd selbst als Elternteil zugewiesen
        // bekommen (z. B. eigene UELN im Vater-UELN-Feld oder gleichlautender
        // Freitext-Vatername) - ein Stammbaum-Zyklus (#131).
        $uelnAlter = '';
        $uelnAlterParams = [];
        if ($birthYear !== null) {
            // Das Kind (Zeile) muss jünger sein als $horseId (N47).
            $uelnAlter = ' AND (birth_year IS NULL OR birth_year > ?)';
            $uelnAlterParams = [$birthYear];
        }
        foreach ($uelnsToMatch as $u) {
            // Auto-link Sires matching UELN or Foreign UELN (UELN ist eindeutig, keine Mehrdeutigkeit möglich)
            if ($alsVater) {
                $stmt = $db->prepare("UPDATE horses SET sire_id = ?, sire_name = NULL, sire_ueln = NULL WHERE sire_id IS NULL AND sire_ueln = ? AND id != ? AND (dam_id IS NULL OR dam_id <> ?){$uelnAlter}{$zyklusBedingung}");
                $stmt->execute([$horseId, $u, $horseId, $horseId, ...$uelnAlterParams, ...$vorfahren]);
                $countSires = $stmt->rowCount();
                if ($countSires > 0) {
                    \App\Service\AuditLogger::log("Automatische Zusammenführung", "horses", "{$countSires} Nachkommen automatisch mit Vater ID {$horseId} (UELN: {$u}) verknüpft");
                }
            }

            // Auto-link Dams matching UELN or Foreign UELN
            if ($alsMutter) {
                $stmt = $db->prepare("UPDATE horses SET dam_id = ?, dam_name = NULL, dam_ueln = NULL WHERE dam_id IS NULL AND dam_ueln = ? AND id != ? AND (sire_id IS NULL OR sire_id <> ?){$uelnAlter}{$zyklusBedingung}");
                $stmt->execute([$horseId, $u, $horseId, $horseId, ...$uelnAlterParams, ...$vorfahren]);
                $countDams = $stmt->rowCount();
                if ($countDams > 0) {
                    \App\Service\AuditLogger::log("Automatische Zusammenführung", "horses", "{$countDams} Nachkommen automatisch mit Mutter ID {$horseId} (UELN: {$u}) verknüpft");
                }
            }
        }

        if (!empty($name)) {
            // Namensbasiertes Auto-Linking nur, wenn der Name in der Datenbank eindeutig ist -
            // bei mehreren gleichnamigen Pferden kann nicht sicher bestimmt werden, welches
            // davon tatsächlich gemeint ist (siehe #41). Mehrdeutige Fälle bleiben als
            // Platzhalter stehen und tauchen stattdessen im manuellen Match-Tool auf.
            $stmt = $db->prepare("SELECT COUNT(*) FROM horses WHERE deleted_at IS NULL AND LOWER(name) = LOWER(?) AND id != ?");
            $stmt->execute([$name, $horseId]);
            $nameIsAmbiguous = (int)$stmt->fetchColumn() > 0;

            if (!$nameIsAmbiguous) {
                // Zusätzlich nur bei plausiblem Elternalter verknüpfen (3-30 Jahre älter als
                // das Kind), analog zur "plausibel"-Schwelle im manuellen Match-Tool
                // (calculateSuggestions()). Fehlt ein Geburtsjahr, ist keine Prüfung möglich -
                // dann wie bisher ohne Alters-Einschränkung verknüpfen.
                $ageCondition = '';
                $ageParams = [];
                if ($birthYear !== null) {
                    $ageCondition = " AND (birth_year IS NULL OR (birth_year - ?) BETWEEN 3 AND 30)";
                    $ageParams = [$birthYear];
                }

                // Auto-link Sires matching exact Name (where sire_ueln is empty)
                if ($alsVater) {
                    $stmt = $db->prepare("UPDATE horses SET sire_id = ?, sire_name = NULL, sire_ueln = NULL WHERE sire_id IS NULL AND (sire_ueln IS NULL OR sire_ueln = '') AND LOWER(sire_name) = LOWER(?) AND id != ? AND (dam_id IS NULL OR dam_id <> ?){$ageCondition}{$zyklusBedingung}");
                    $stmt->execute([$horseId, $name, $horseId, $horseId, ...$ageParams, ...$vorfahren]);
                    $countNameSires = $stmt->rowCount();
                    if ($countNameSires > 0) {
                        \App\Service\AuditLogger::log("Automatische Zusammenführung", "horses", "{$countNameSires} Nachkommen anhand Name '{$name}' mit Vater ID {$horseId} verknüpft");
                    }
                }

                // Auto-link Dams matching exact Name (where dam_ueln is empty)
                if ($alsMutter) {
                    $stmt = $db->prepare("UPDATE horses SET dam_id = ?, dam_name = NULL, dam_ueln = NULL WHERE dam_id IS NULL AND (dam_ueln IS NULL OR dam_ueln = '') AND LOWER(dam_name) = LOWER(?) AND id != ? AND (sire_id IS NULL OR sire_id <> ?){$ageCondition}{$zyklusBedingung}");
                    $stmt->execute([$horseId, $name, $horseId, $horseId, ...$ageParams, ...$vorfahren]);
                    $countNameDams = $stmt->rowCount();
                    if ($countNameDams > 0) {
                        \App\Service\AuditLogger::log("Automatische Zusammenführung", "horses", "{$countNameDams} Nachkommen anhand Name '{$name}' mit Mutter ID {$horseId} verknüpft");
                    }
                }
            }
        }
    }

    /**
     * Merge Tool: Scans DB for placeholders and suggests probabilities
     */
    public function matches(): void {
        $this->requirePermission('horses', 'view');
        $this->requirePermission('horses', 'edit');

        // SQL-seitige Pagination über die offenen Platzhalter (#215, 50 je
        // Seite, gleiches Muster wie ApiController::index()): countOpen() ist
        // ein reiner Vorfilter-COUNT, findAll() bewertet nur noch die
        // Platzhalter der angefragten Seite.
        $perPage = 50;
        $matchTotal = \App\Service\MatchSuggestionFinder::countOpen();
        $matchTotalPages = max(1, (int)ceil($matchTotal / $perPage));
        $matchPage = max(1, min($matchTotalPages, (int)($_GET['page'] ?? 1)));
        $unlinkedMatches = \App\Service\MatchSuggestionFinder::findAll($perPage, ($matchPage - 1) * $perPage);

        $db = Database::getInstance();
        // Nur noch die Felder, die das manuelle Auswahl-Dropdown der View
        // tatsächlich braucht (#215): die frühere Abfrage lud hier eine zweite
        // Vollkopie der horses-Tabelle mit allen Match-Spalten, obwohl
        // findAll() die Kandidaten-Daten bereits selbst ermittelt.
        $allHorses = $db->query("SELECT id, name, ueln, sex FROM horses WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll();

        // Datenqualitäts-Report (#166): bestehende Verknüpfungen, deren Elternteil
        // ein unpassendes Geschlecht trägt - entstanden, bevor es das Geschlechtsfeld
        // und die Speicher-Validierung gab. NULL (unbekannt) gilt nicht als Verstoß.
        $sexMismatches = $db->query(
            "SELECT c.id, c.name,
                    s.id AS sire_id, s.name AS sire_name, s.sex AS sire_sex,
                    d.id AS dam_id, d.name AS dam_name, d.sex AS dam_sex
             FROM horses c
             LEFT JOIN horses s ON c.sire_id = s.id
             LEFT JOIN horses d ON c.dam_id = d.id
             WHERE c.deleted_at IS NULL
               AND (s.sex = 'mare' OR d.sex IN ('stallion', 'gelding'))
             ORDER BY c.name ASC"
        )->fetchAll();

        // Kontakt-Dubletten (#355) - derselbe Ort für dieselbe Tätigkeit.
        // Zwei getrennte Werkzeuge für "zwei Datensätze, die einer sein
        // könnten" wären die nächste Doppelung. Sie erscheinen nur, wenn der
        // Benutzer Kontakte überhaupt bearbeiten darf; wer nur Pferde pflegt,
        // bekommt die Liste nicht zu sehen.
        $kontaktDubletten = ['paare' => [], 'abgeschnitten' => false, 'geprueft' => 0];
        if ($this->hasPermission('contacts', 'edit')) {
            $kontaktDubletten = \App\Service\ContactSuggestionFinder::findAll(100);
        }

        $this->render('admin_matches', [
            'title' => 'Dubletten: Blutlinien und Kontakte',
            'unlinkedMatches' => $unlinkedMatches,
            'allHorses' => $allHorses,
            'sexMismatches' => $sexMismatches,
            'matchPage' => $matchPage,
            'matchTotalPages' => $matchTotalPages,
            'matchTotal' => $matchTotal,
            'kontaktDubletten' => $kontaktDubletten,
            'kontaktLabels' => \App\Service\MatchLabel::alle('contact'),
            'pferdeLabels' => \App\Service\MatchLabel::alle('horse'),
            'darfKontakteBearbeiten' => $this->hasPermission('contacts', 'edit'),
        ]);
    }

    /**
     * POST /admin/matches/label - eine Dubletten-Entscheidung festhalten (#355).
     *
     * Bis v0.7 konnte man einen Vorschlag nur ANNEHMEN. "Das sind zwei
     * verschiedene Pferde" wurde nirgends gespeichert, also erschien dasselbe
     * Paar bei jedem Aufruf wieder und der Digest zählte es dauerhaft als
     * offen. Wer einmal geprüft und verworfen hatte, prüfte beim nächsten Mal
     * erneut.
     *
     * Ein leeres Label widerruft - eine falsch gesetzte Trennung darf nicht
     * endgültig sein.
     */
    public function labelMatch(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $art = (string)($_POST['art'] ?? '');
        if (!in_array($art, \App\Service\MatchLabel::ARTEN, true)) {
            $this->renderNotFound('Unbekannte Art.');
        }

        // Das Recht richtet sich nach dem Bestand, über den entschieden wird -
        // nicht nach der Seite, auf der der Knopf steht.
        $this->requirePermission($art === 'horse' ? 'horses' : 'contacts', 'edit');

        $a = (int)($_POST['a'] ?? 0);
        $b = (int)($_POST['b'] ?? 0);
        if ($a <= 0 || $b <= 0 || $a === $b) {
            $this->renderNotFound('Unvollständiges Paar.');
        }

        $label = (string)($_POST['label'] ?? '');
        \App\Service\MatchLabel::setzen(
            $art,
            $a,
            $b,
            $label === '' ? null : $label,
            // Nicht-Strings abweisen statt in einen TypeError laufen (#376):
            // ?note[]=x lieferte sonst eine 500-Seite. Dieselbe Klasse wie bei
            // HorseSearchCriteria::readString().
            is_string($_POST['note'] ?? null) ? $_POST['note'] : null
        );

        header('Location: /admin/matches?success=label');
        exit;
    }

    /**
     * Link/Merge a parent match manually or via suggestion
     */
    public function linkMatch(): void {
        $this->requirePermission('horses', 'edit');

        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $childId = (int)($_POST['child_id'] ?? 0);
        $parentType = $_POST['parent_type'] ?? ''; // 'sire' or 'dam'
        $parentHorseId = (int)($_POST['parent_horse_id'] ?? 0);

        // Dieselben Prüfungen wie das Formular (#166, #298, Audit N47). Bis
        // dahin kannte linkMatch nur Selbst-Link und Geschlecht: Ein
        // Vorschlag mit unmöglichem Alter, Vater = Mutter oder ein
        // Stammbaum-Zyklus ließen sich mit einem Klick übernehmen, und ein
        // unvollständiger Request meldete still "verknüpft".
        if (!in_array($parentType, ['sire', 'dam'], true) || $childId <= 0 || $parentHorseId <= 0) {
            header("Location: /admin/matches?error=invalid");
            exit;
        }

        // Serverseitig ablehnen, dass ein Pferd sein eigener Elternteil wird -
        // die Absicherung existierte bisher nur clientseitig (#131).
        if ($childId === $parentHorseId) {
            header("Location: /admin/matches?error=self_link");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, name, birth_year, sire_id, dam_id, deleted_at FROM horses WHERE id = ?");
        $stmt->execute([$childId]);
        $kind = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($kind === false) {
            header("Location: /admin/matches?error=not_found");
            exit;
        }
        // Schreibschutz für den Papierkorb (#322): Ein veralteter Match-Tab
        // könnte sonst einen Datensatz im Papierkorb verändern.
        if ($kind['deleted_at'] !== null) {
            header("Location: /admin/matches?error=child_in_trash");
            exit;
        }

        $spalte = $parentType === 'sire' ? 'sire_id' : 'dam_id';
        $andererElternteil = $parentType === 'sire' ? $kind['dam_id'] : $kind['sire_id'];
        if ($kind[$spalte] !== null && (int)$kind[$spalte] === $parentHorseId) {
            // Schon so verknüpft - nichts zu tun.
            header("Location: /admin/matches?success=linked");
            exit;
        }

        // Geschlechts-Guard analog zur Selbst-Link-Sperre (#167): eine Stute kann
        // nicht als Vater, ein Hengst/Wallach nicht als Mutter verknüpft werden.
        $mismatch = ($parentType === 'sire')
            ? $this->parentSexMismatch($parentHorseId, null)
            : $this->parentSexMismatch(null, $parentHorseId);
        if ($mismatch) {
            header("Location: /admin/matches?error=sex_mismatch");
            exit;
        }

        if ($andererElternteil !== null && (int)$andererElternteil === $parentHorseId) {
            header("Location: /admin/matches?error=same_sire_and_dam");
            exit;
        }

        // Alter: nur der neue Elternteil gegen das Geburtsjahr des Kindes.
        $kindJahr = $kind['birth_year'] !== null ? (int)$kind['birth_year'] : null;
        $alter = $parentType === 'sire'
            ? $this->pedigreeContradiction($parentHorseId, null, $kindJahr)
            : $this->pedigreeContradiction(null, $parentHorseId, $kindJahr);
        if ($alter) {
            header("Location: /admin/matches?error={$alter}");
            exit;
        }

        if ($error = $this->linkTargetError($childId, $parentHorseId)) {
            header("Location: /admin/matches?error={$error}");
            exit;
        }

        $childName = (string)$kind['name'];
        $stmt = $db->prepare("SELECT name FROM horses WHERE id = ?");
        $stmt->execute([$parentHorseId]);
        $parentName = $stmt->fetchColumn() ?: "Pferd #{$parentHorseId}";

        // Rollen-Bezeichnung ohne Geschlechts-Behauptung (#167): das verknüpfte
        // Tier kann auch ohne hinterlegtes Geschlecht (NULL) gespeichert sein.
        $roleLabel = ($parentType === 'sire') ? 'Vater' : 'Mutter';

        if ($parentType === 'sire') {
            $stmt = $db->prepare("UPDATE horses SET sire_id = ?, sire_name = NULL, sire_ueln = NULL WHERE id = ? AND deleted_at IS NULL");
        } else {
            $stmt = $db->prepare("UPDATE horses SET dam_id = ?, dam_name = NULL, dam_ueln = NULL WHERE id = ? AND deleted_at IS NULL");
        }
        $stmt->execute([$parentHorseId, $childId]);

        \App\Service\AuditLogger::log(
            "Abstammung zusammengeführt",
            "horses",
            "Kind '{$childName}' (ID {$childId}) mit {$roleLabel} '{$parentName}' (ID {$parentHorseId}) verknüpft"
        );

        header("Location: /admin/matches?success=linked");
        exit;
    }

    public function delete(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('horses', 'delete');

        $id = $_POST['id'] ?? null;
        if ($id) {
            $db = Database::getInstance();

            // Kompletter Datensatz: fürs Audit-Log und als Hook-Payload (#164) -
            // Plugins sollen beim Aufräumen nicht selbst nachladen müssen.
            $stmt = $db->prepare("SELECT * FROM horses WHERE id = ?");
            $stmt->execute([$id]);
            $horse = $stmt->fetch() ?: null;
            $horseName = $horse['name'] ?? 'Unbekannt';

            // Plugin-Hook (#164): VOR dem Verschieben in den Papierkorb. Kann das
            // Löschen nicht blockieren (HookManager-Isolation, wie horse.before_save).
            $this->hooks()->doAction('horse.before_delete', (int)$id, $horse ?? [], false);

            $stmt = $db->prepare("UPDATE horses SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            \App\Service\AuditLogger::log("Pferd in Papierkorb verschoben", "horses", "Pferd ID {$id}: {$horseName}");

            // Plugin-Hook (#164): NACH dem Soft-Delete - z. B. damit ein Plugin
            // abhängige Daten (Inserate, Verknüpfungen) deaktivieren kann.
            $this->hooks()->doAction('horse.trashed', (int)$id, $horse ?? []);
        }

        header("Location: /admin/horses?success=deleted");
        exit;
    }

    /**
     * Save person roles & ownership history in horse_persons table
     */
    /**
     * Gibt es die Zeile noch, und liegt sie im Papierkorb? (#317, Audit M34)
     *
     * Liefert 'aktiv', 'papierkorb' oder null (unbekannt bzw. hart
     * gelöscht). Früher nur "existiert"; ein Kontakt im Papierkorb galt
     * damit als frei wählbar, obwohl ihn das Formular nicht anbietet.
     *
     * Die Tabelle kommt ueber eine Positivliste in die Abfrage und nie aus
     * einem Aufrufwert - ein Tabellenname laesst sich nicht als Parameter
     * binden, und ein durchgereichter String waere genau die Stelle, an der
     * das eines Tages jemand tut.
     */
    private function zeilenZustand(\PDO $db, string $table, int $id): ?string {
        // Seit #336 kennt die Liste nur noch einen Namen - beide Steckplaetze
        // zeigen auf `contacts`. Die Positivliste bleibt trotzdem stehen: Sie
        // ist die Zusicherung, dass hier nie ein Aufrufwert in die Abfrage
        // geraet, und sie traegt genau dann wieder, wenn jemand einen zweiten
        // Fall ergaenzt.
        $tabelle = match ($table) {
            'contacts' => 'contacts',
        };
        $stmt = $db->prepare("SELECT deleted_at FROM `{$tabelle}` WHERE id = ?");
        $stmt->execute([$id]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($zeile === false) {
            return null;
        }
        return $zeile['deleted_at'] === null ? 'aktiv' : 'papierkorb';
    }

    private function saveHorsePersons(\PDO $db, int $horseId, array $personsData): void {
        // Ein Request OHNE persons-Block meint nicht "keine Zuordnungen" - er
        // meint "dazu sage ich nichts" (Skript-POST, Teilformular). Ohne diese
        // Unterscheidung loeschte jeder solche Request saemtliche Zuordnungen
        // des Pferds (#295). Der versteckte Marker persons_present trennt das
        // vom bewussten Leeren aller Zeilen im Formular - dieselbe Loesung wie
        // bei registrations_present (#246), siehe saveRegistrations().
        if (!array_key_exists('persons', $_POST) && !array_key_exists('persons_present', $_POST)) {
            return;
        }

        // Bestand VOR dem Loeschen sichern. Grund: Das Formular rendert fuer
        // breeding_station_text bis #295 kein Feld, der Wert kommt also gar
        // nicht zurueck - und eine Zeile ohne Person und ohne Stations-ID fiel
        // damit ersatzlos weg. Betroffen war der gesamte Importbestand, bei dem
        // die Station nur als Freitext vorliegt.
        //
        // Die Zuordnung laeuft ueber die Position: edit() rendert die Zeilen mit
        // derselben Sortierung (ORDER BY hp.id ASC) und vergibt die
        // Formularindizes in dieser Reihenfolge. Sie greift ohnehin nur dort, wo
        // der Schluessel im Request FEHLT - ein uebermittelter Leerstring
        // loescht weiterhin bewusst.
        $snapshotSql = "SELECT contact_id, role, station_contact_id, breeding_station_text, origin_country, from_year, until_year
                        FROM horse_persons WHERE horse_id = ? ORDER BY id ASC";
        $stmt = $db->prepare($snapshotSql);
        $stmt->execute([$horseId]);
        $vorher = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $existingTexts = array_column($vorher, 'breeding_station_text');

        // Kontakte, die dieses Pferd schon VOR dem Speichern zugeordnet hatte
        // (Audit M34). Nur sie dürfen im Papierkorb liegen und trotzdem
        // übernommen werden: Die bestehende Zuordnung bleibt erhalten, neu
        // setzen lässt sich ein Kontakt aus dem Papierkorb nicht. store() hat
        // einen leeren Snapshot und nimmt solche Kontakte daher nie an.
        $bisherigeKontakte = [];
        foreach ($vorher as $zeile) {
            foreach (['contact_id', 'station_contact_id'] as $spalte) {
                if (!empty($zeile[$spalte])) {
                    $bisherigeKontakte[(int)$zeile[$spalte]] = true;
                }
            }
        }
        $kontaktZustaende = [];
        $kontaktZustand = function (int $kontaktId) use ($db, &$kontaktZustaende): ?string {
            if (!array_key_exists($kontaktId, $kontaktZustaende)) {
                $kontaktZustaende[$kontaktId] = $this->zeilenZustand($db, 'contacts', $kontaktId);
            }
            return $kontaktZustaende[$kontaktId];
        };
        $zulaessigerKontakt = function (?int $kontaktId) use ($kontaktZustand, $bisherigeKontakte): ?int {
            if ($kontaktId === null) {
                return null;
            }
            return match ($kontaktZustand($kontaktId)) {
                'aktiv' => $kontaktId,
                'papierkorb' => isset($bisherigeKontakte[$kontaktId]) ? $kontaktId : null,
                default => null,
            };
        };

        // DELETE und INSERTs gehoeren zusammen (#317).
        //
        // Ohne Klammer war das Loeschen bereits festgeschrieben, sobald ein
        // INSERT scheiterte - das Pferd stand danach ganz ohne Personen- und
        // Stationszuordnungen da, obwohl der Bearbeiter nur speichern wollte.
        // Der Ausloeser braucht keine Boshaftigkeit: Redakteur A hat das
        // Bearbeitungsformular offen, Admin B leert waehrenddessen den
        // Papierkorb (TrashController::emptyTrash() loescht Kontakte HART),
        // und A speichert. Das INSERT laeuft in den Fremdschluessel, PDO wirft
        // (ERRMODE_EXCEPTION), der Request endet mit 500 - und auch das
        // Aenderungs-Protokoll unten kommt nicht mehr dazu. Es blieb nicht
        // einmal ein Hinweis darauf, dass es die Zuordnungen gab.
        //
        // inTransaction() abgefragt, weil PDO ein verschachteltes
        // beginTransaction() mit einer Ausnahme quittiert: Ein Aufrufer (oder
        // ein Plugin am Hook horse.before_save) koennte laengst eine
        // Transaktion offen haben, und dann traegt sie die Atomizitaet
        // ohnehin.
        $eigeneTransaktion = !$db->inTransaction();
        if ($eigeneTransaktion) {
            $db->beginTransaction();
        }

        try {
            // Clear existing relations
            $stmt = $db->prepare("DELETE FROM horse_persons WHERE horse_id = ?");
            $stmt->execute([$horseId]);

            $insertStmt = $db->prepare("INSERT INTO horse_persons (horse_id, contact_id, role, station_contact_id, breeding_station_text, origin_country, from_year, until_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

            $validRoles = ['breeder', 'owner', 'keeper'];

            $currentStationId = null;
            $currentStationText = null;
            $currentStationImPapierkorb = false;
            $highestScore = -1;

            foreach ($personsData as $index => $item) {
                // Die Formularfelder heissen seit #336 contact_id und
                // station_contact_id wie die Spalten.
                //
                // DIE ALTEN NAMEN person_id / breeding_station_id WERDEN SEIT
                // v0.9.0 NICHT MEHR ANGENOMMEN - zusammen mit den Hook-Aliassen
                // person.*/station.* und aus demselben Grund (#347). Sie liefen
                // seit v0.8 mit, damit Skript-POSTs und externe Formulare nicht
                // still ins Leere schreiben; das Ende war fuer v0.9.0
                // angekuendigt. Wer sie noch sendet, schreibt ab jetzt genau
                // das: ins Leere. Das ist gewollt und angekuendigt - eine
                // Schnittstelle, die zwei Namen fuer dieselbe Sache dauerhaft
                // annimmt, hat am Ende zwei Wahrheiten.
                $contactId = !empty($item['contact_id']) ? (int)$item['contact_id'] : null;
                $role = $item['role'] ?? 'owner';
                $stationId = !empty($item['station_contact_id'])
                    ? (int)$item['station_contact_id']
                    : null;
                // Unbekannte IDs auf NULL statt in den Fremdschluessel laufen
                // lassen (#317). Die Auswahl im Formular ist beim Oeffnen der
                // Seite eingefroren; was dort stand, kann inzwischen hart
                // geloescht sein. Eine Zeile ohne Person ist ein Verlust, ein
                // abgebrochener Speichervorgang ohne JEDE Zuordnung waere ein
                // groesserer - und die Zeile faellt unten ohnehin weg, wenn ausser
                // der verwaisten ID nichts mehr in ihr steht.
                //
                // Beide Steckplaetze pruefen jetzt gegen dieselbe Tabelle
                // (#336), bleiben aber getrennte Werte. Kontakte im
                // Papierkorb nur, wenn sie schon zugeordnet waren (Audit M34).
                $contactId = $zulaessigerKontakt($contactId);
                $stationId = $zulaessigerKontakt($stationId);
                // Fehlender Schluessel erhaelt den Bestand, uebermittelter
                // Leerstring loescht - dieselbe Unterscheidung wie beim COALESCE
                // fuer horses.breeding_station in update() (#214).
                $stationText = array_key_exists('breeding_station_text', $item)
                    ? trim((string)$item['breeding_station_text'])
                    : trim((string)($existingTexts[$index] ?? ''));
                // Die Spalte ist VARCHAR(255); ein laengerer Text braeche im Strict
                // Mode den ganzen Speichervorgang ab, und Verwerfen kostete die
                // komplette Zeile. maxlength im Formular ist nur clientseitig.
                if (mb_strlen($stationText) > 255) {
                    $stationText = mb_substr($stationText, 0, 255);
                }
                // Herkunftsland ohne bekannte Person (#294) - siehe die
                // Gueltigkeitsregel unten. Freitext wie contacts.country.
                $originCountry = trim((string)($item['origin_country'] ?? ''));
                if (mb_strlen($originCountry) > 100) {
                    $originCountry = mb_substr($originCountry, 0, 100);
                }

                // Breeders do not have a time period!
                if ($role === 'breeder') {
                    $fromYear = null;
                    $untilYear = null;
                } else {
                    // Wie das Todesjahr normalisiert (Audit N49): Werte
                    // außerhalb 1600 bis Folgejahr werden NULL. Vorher lief
                    // ein Jahr "70000" per (int) in die SMALLINT-Spalte und
                    // brach das Speichern mitten im Pfad ab.
                    $fromYear = $this->jahrAusEingabe($item['from_year'] ?? null);
                    $untilYear = $this->jahrAusEingabe($item['until_year'] ?? null);
                }

                // Calculate score to identify the current/latest active breeding station
                if ($stationId || $stationText) {
                    // If until_year IS NULL, the entry is currently active -> boost score with 99999 + from_year
                    $calcFrom = $fromYear ?: 0;
                    $score = ($untilYear === null && $role !== 'breeder') ? (99999 + $calcFrom) : ($untilYear ?: $calcFrom);

                    if ($score >= $highestScore) {
                        $highestScore = $score;
                        if ($stationId) {
                            // Eine erhaltene Station im Papierkorb gewinnt die
                            // Wahl, wird aber nicht gespiegelt: Ihr Name soll
                            // nicht neu nach horses.breeding_station
                            // denormalisiert werden (Audit M34, abgestimmt mit
                            // M11). Der Bestandswert des Pferds bleibt stehen.
                            $currentStationImPapierkorb = $kontaktZustand($stationId) === 'papierkorb';
                            $currentStationId = $stationId;
                            // Nur die Zieltabelle heisst jetzt anders (#336) -
                            // gespiegelt wird unveraendert Name und ID der
                            // aktuellen/letzten aktiven Deckstation.
                            $stStmt = $db->prepare("SELECT name FROM contacts WHERE id = ?");
                            $stStmt->execute([$stationId]);
                            $currentStationText = $stStmt->fetchColumn() ?: null;
                        } else {
                            $currentStationImPapierkorb = false;
                            $currentStationId = null;
                            $currentStationText = $stationText;
                        }
                    }
                }

                // Validation: Row must have a valid Role/Type AND at least one of Person OR Breeding Station (2 of the fields)
                $hasPerson = !empty($contactId);
                $hasStation = !empty($stationId) || !empty($stationText);
                // Dritte Alternative (#294): Ist die Person unbekannt, aber ihre
                // Herkunft bekannt, ist das eine vollwertige Aussage - und der
                // einzige Weg, sie OHNE eine Platzhalter-Person in der PII-Tabelle
                // contacts festzuhalten.
                $hasOrigin = $originCountry !== '';
                $hasValidRole = in_array($role, $validRoles, true);

                if ($hasValidRole && ($hasPerson || $hasStation || $hasOrigin)) {
                    $insertStmt->execute([$horseId, $contactId ?: null, $role, $stationId ?: null, $stationText ?: null, $originCountry ?: null, $fromYear, $untilYear]);
                }
            }

            if ($eigeneTransaktion) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($eigeneTransaktion && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        // Zuordnungsaenderungen protokollieren. Bis #295 lief dieser Vorgang
        // spurlos - er konnte Zeilen vernichten, ohne dass danach irgendwo
        // stand, dass es sie gab. Nur bei tatsaechlicher Aenderung, sonst
        // erzeugte jedes Speichern ohne Aenderung eine Zeile.
        $stmt = $db->prepare($snapshotSql);
        $stmt->execute([$horseId]);
        $nachher = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($vorher !== $nachher) {
            \App\Service\AuditLogger::log(
                "Pferdezuordnungen geändert",
                "horses",
                "Pferd ID {$horseId}: " . count($vorher) . " -> " . count($nachher) . " Zuordnungen"
            );
        }

        // Aktuelle/letzte aktive Deckstation auf den Pferde-Hauptdatensatz
        // spiegeln - aber NUR, wenn aus den Personenzeilen tatsächlich eine
        // ermittelt wurde (#214). Vorher lief der Sync bedingungslos und nullte
        // bei leerem Personen-Block (der Normalfall beim Bearbeiten importierter
        // Pferde) die per CSV-Import gesetzte Freitext-Station wieder aus.
        // Kehrseite dieser bewussten Entscheidung: Wer die Station eines Pferds
        // entfernen will, muss das über eine Personenzeile mit neuer Station
        // (oder einen Request mit explizit leerem breeding_station-Feld, siehe
        // COALESCE in update()) tun - ein gelöschter Personen-Block lässt den
        // Bestandswert stehen.
        if (!$currentStationImPapierkorb && ($currentStationId !== null || ($currentStationText ?? '') !== '')) {
            $syncStmt = $db->prepare("UPDATE horses SET breeding_station_id = ?, breeding_station = ? WHERE id = ?");
            $syncStmt->execute([$currentStationId, $currentStationText, $horseId]);
        }
    }
}
