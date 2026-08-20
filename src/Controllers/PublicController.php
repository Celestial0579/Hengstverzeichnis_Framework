<?php
// src/Controllers/PublicController.php

namespace App\Controllers;

use App\Database;

class PublicController extends BaseController {

    // requestInt() liegt seit der Admin-Pagination im BaseController - dort
    // brauchen es die Verwaltungslisten genauso, und zwei Fassungen einer
    // Eingabepruefung sind eine zuviel.

    public function index(): void {
        // Fetch some recent or featured horses for the homepage - nur veröffentlichte
        // (is_published), und nur wenn die Gast-Gruppe Pferde überhaupt sehen darf
        // (horses.view). Sichtbarkeit hängt bewusst NICHT mehr am Lebenszyklus-Status.
        $db = Database::getInstance();
        $featuredHorses = [];
        if ($this->hasPermission('horses', 'view')) {
            $stmt = $db->query("SELECT id, name, color, status, image_url FROM horses WHERE is_published = 1 AND deleted_at IS NULL ORDER BY id DESC LIMIT 3");
            $featuredHorses = $stmt->fetchAll();
        }

        // Erweiterungspunkte der Startseite (#356). Bis v0.7 hatte ausgerechnet
        // die meistbesuchte Seite des Verzeichnisses keinen einzigen - ein
        // Addon konnte an der Pferdeseite, an der Kontaktseite und im
        // Adminbereich etwas beitragen, aber nicht dort, wo die Besucher
        // ankommen. "Pferd des Tages" (Addons#135) liesse sich ohne diesen
        // Hook nur bauen, indem man public_home.php im Kern anfasst.
        //
        // ZWEI Einhaengepunkte, oben und unten, statt eines: Ein Addon, das
        // etwas bewerben will, gehoert ueber die Pferdeliste; eines, das
        // Zusatzinformationen nachreicht, darunter. Mit nur einem Punkt
        // muessten beide um dieselbe Stelle streiten, und die Reihenfolge
        // haenge davon ab, welches Addon zuerst geladen wurde.
        //
        // Wie bei den uebrigen Abschnitts-Hooks ist jedes Element ein fertiger
        // HTML-String, der UNESCAPED ausgegeben wird - das Addon ist selbst
        // fuer die XSS-Vermeidung seines Fragments verantwortlich (siehe
        // docs/plugin-development.md).
        $hooks = $this->hooks();
        $homeSectionsTop = $hooks->applyFilters('home.sections_top', [], $featuredHorses);
        $homeSectionsBottom = $hooks->applyFilters('home.sections_bottom', [], $featuredHorses);

        $this->render('public_home', [
            'title' => \App\I18n\Translator::t('meta.title_home') . ' - ' . ($this->settings['site_name'] ?? 'Hengstverzeichnis'),
            'featuredHorses' => $featuredHorses,
            'homeSectionsTop' => is_array($homeSectionsTop) ? $homeSectionsTop : [],
            'homeSectionsBottom' => is_array($homeSectionsBottom) ? $homeSectionsBottom : [],
        ]);
    }

    /** Kartengröße je Katalogseite (SQL-seitige Pagination, #125). */
    private const CATALOG_PER_PAGE = 24;

    /**
     * Zuechter- und Besitzernamen fuer eine bereits ermittelte Kartenseite
     * nachladen (#320).
     *
     * Zwei Abfragen statt einer sind hier billiger als es klingt: Die zweite
     * laeuft ueber hoechstens CATALOG_PER_PAGE Pferde-IDs und nutzt den Index
     * auf horse_persons.horse_id, waehrend die eingebettete Variante bei jedem
     * Aufruf eine Temp-Tabelle ueber den GESAMTEN Bestand aufbaute.
     *
     * Die Sichtbarkeitsregel steckt bewusst nicht hier, sondern in
     * HorseSearchSql::personNamesSql() - dieselbe Klasse, die sie auch fuer
     * die eingebettete Fassung haelt.
     *
     * @param array<int, array<string, mixed>> $horses
     * @return array<int, array<string, mixed>>
     */
    private function attachPersonNames(\PDO $db, \App\Service\HorseSearchSql $sql, array $horses): array {
        if ($horses === []) {
            return $horses;
        }

        // Auf die feste Losgroesse auffuellen: personNamesSql() kommt bewusst
        // ohne Parameter aus (siehe dort), erwartet also immer gleich viele
        // Platzhalter. Die Pferde-ID 0 gibt es nicht, die Fuellwerte treffen
        // also nichts.
        $ids = array_map(static fn(array $h): int => (int)$h['id'], $horses);
        $gebunden = array_pad($ids, \App\Service\HorseSearchSql::PERSON_NAMES_BATCH, 0);

        $stmt = $db->prepare($sql->personNamesSql());
        $stmt->execute($gebunden);

        $namen = [];
        foreach ($stmt->fetchAll() as $zeile) {
            $namen[(int)$zeile['horse_id']] = $zeile;
        }

        foreach ($horses as $i => $horse) {
            $treffer = $namen[(int)$horse['id']] ?? null;
            $horses[$i]['breeder_name'] = $treffer['breeder_name'] ?? null;
            $horses[$i]['owner_name'] = $treffer['owner_name'] ?? null;
        }

        return $horses;
    }

    public function catalog(): void {
        $db = Database::getInstance();

        // Suchlogik gemeinsam mit der Pferdeverwaltung, aufgeteilt in zwei
        // Bausteine: HorseSearchSql erzeugt Klausel und JOINs und bekommt die
        // Anfrage nie zu sehen, HorseSearchCriteria liest die Anfrage und
        // erzeugt kein SQL. Über applyTo() geht nur, WELCHE Bedingungen
        // gelten; die Werte kommen gebunden aus params(). Warum das so
        // auseinandergezogen ist, steht ausführlich in
        // HorseSearchCondition - kurz: Lesen und Erzeugen in einer Klasse
        // hielt den nächsten Missgriff immer in Reichweite, und Semgrep
        // meldete die Interpolation unten folgerichtig als
        // tainted-sql-string.
        //
        // $nurOeffentlich = true haelt die Sichtbarkeitsgrenzen des Katalogs
        // aufrecht: verknuepfte Personen, Stationen und Elterntiere zaehlen nur
        // veroeffentlicht, sonst waere der Filter ein Existenz-Orakel
        // (#121/#122/#151). Der Admin bekommt dieselben Bausteine mit false.
        $sql = new \App\Service\HorseSearchSql(true);
        $criteria = \App\Service\HorseSearchCriteria::fromRequest($_GET, true);
        $criteria->applyTo($sql);

        $params = $criteria->params();
        $whereSql = $sql->whereSql();
        $joinSql = $sql->joinSql();

        // Gast-Gruppe ohne horses.view sieht keinerlei Pferde (leerer Katalog),
        // sonst würde die Rechte-Entziehung wirkungslos bleiben.
        $horses = [];
        $totalHorses = 0;
        $totalPages = 1;
        $page = self::requestInt('page', 1, 1);

        // Nachladen (#264) heisst: Der Client hat die Trefferzahl schon und
        // braucht nur die naechsten Karten. Das COUNT(*) lief trotzdem bei
        // jedem Scrollschritt mit - ueber den dreifachen Selbst-JOIN, und beim
        // Durchscrollen des ganzen Bestands sind das gut 130 Wiederholungen
        // derselben Zahl (#320). $totalHorses bleibt in diesem Fall null; die
        // AJAX-Antwort sagt das ausdruecklich, statt eine erfundene Zahl zu
        // liefern.
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_GET['ajax']);
        // Nur im AJAX-Nachladeweg. Ein direkter Aufruf von /katalog?append=1
        // rendert die volle Seite, und die braucht die Trefferzahl.
        $anhaengen = $isAjax && !empty($_GET['append']);

        if ($this->hasPermission('horses', 'view')) {
            // Echte SQL-Pagination statt "alle Treffer laden" (#125).
            if ($anhaengen) {
                $totalHorses = null;
            } else {
                $countStmt = $db->prepare("SELECT COUNT(*) {$joinSql} WHERE {$whereSql}");
                $countStmt->execute($params);
                $totalHorses = (int)$countStmt->fetchColumn();
                $totalPages = max(1, (int)ceil($totalHorses / self::CATALOG_PER_PAGE));
                $page = min($page, $totalPages);
            }
            $offset = ($page - 1) * self::CATALOG_PER_PAGE;

            // h.breeding_station ist bei gesetzter breeding_station_id die
            // denormalisierte Kopie des Stationsnamens, und die Katalogkarte zeigt sie
            // als Fallback zu station_name. Sie wird deshalb unterdrückt, sobald die
            // Station öffentlich nicht sichtbar ist - der bs-JOIN ist bereits auf
            // is_published = 1 AND deleted_at IS NULL eingeschränkt, "bs.id IS NULL"
            // heißt dort also exakt: nicht öffentlich sichtbar. Freitext ohne
            // Stations-Datensatz hat keine breeding_station_id und bleibt (#151/#122).
            $cardsSql = "
                SELECT
                    h.id, h.name, h.ueln, h.foreign_ueln, h.birth_year, h.birth_date, h.color, h.status, h.is_deceased, h.death_year, h.image_url,
                    (SELECT GROUP_CONCAT(hr.registration_number ORDER BY hr.sort_order ASC, hr.id ASC SEPARATOR ' / ')
                     FROM horse_registrations hr WHERE hr.horse_id = h.id) AS registration_numbers,
                    CASE WHEN h.breeding_station_id IS NOT NULL AND bs.id IS NULL
                         THEN NULL ELSE h.breeding_station END AS breeding_station,
                    bs.name as station_name,
                    sire.name as linked_sire_name, h.sire_name as unlinked_sire_name,
                    dam.name as linked_dam_name, h.dam_name as unlinked_dam_name
                {$joinSql}
                WHERE {$whereSql}
                ORDER BY h.name ASC
                LIMIT ? OFFSET ?
            ";

            $stmt = $db->prepare($cardsSql);
            $paramIndex = 1;
            foreach ($params as $value) {
                $stmt->bindValue($paramIndex++, $value);
            }
            // Eine Zeile mehr holen als angezeigt wird: Damit steht auch ohne
            // COUNT(*) fest, ob es weitergeht - dieselbe Auskunft, die der
            // Client zum Weiterscrollen braucht, nur ohne den teuren Zaehler.
            $stmt->bindValue($paramIndex++, self::CATALOG_PER_PAGE + 1, \PDO::PARAM_INT);
            $stmt->bindValue($paramIndex, $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $horses = $stmt->fetchAll();

            $hatWeitere = count($horses) > self::CATALOG_PER_PAGE;
            if ($hatWeitere) {
                array_pop($horses);
            }
            if ($anhaengen) {
                $totalPages = $hatWeitere ? $page + 1 : $page;
            }

            // Zuechter- und Besitzernamen ERST JETZT aufloesen, fuer die 24
            // Pferde dieser Seite (#320). Als JOIN in der Abfrage darueber
            // enthielt die Ableitung ein GROUP BY, liess sich deshalb nicht
            // hineinziehen und wurde ueber die gesamte
            // horse_persons/contacts-Menge materialisiert - je Scrollschritt
            // erneut, obwohl 24 Zeilen gebraucht werden.
            $horses = $this->attachPersonNames($db, $sql, $horses);
        }

        // Query-String der aktiven Filter (ohne page) für die Pagination-Links.
        $filterParams = $_GET;
        unset($filterParams['page'], $filterParams['ajax']);
        $filterParams = array_filter($filterParams, fn($v) => $v !== '' && $v !== null);
        $catalogPagination = [
            'page' => $page,
            'totalPages' => $totalPages,
            'query' => http_build_query($filterParams),
        ];

        // Plugin-Hook (#56, #97): Erweiterungspunkt für zusätzlichen Inhalt je Katalog-Karte
        // (z. B. ein "Merken"-Button), analog zu horse.detail_sections auf der Detailseite.
        // Pro Pferd im Controller vorberechnet (statt in der View aufgerufen), damit Views
        // wie im gesamten Kern üblich keine eigene Hook-Logik enthalten (siehe
        // horse.detail_sections). Indiziert nach Pferde-ID, da beide Rendering-Pfade
        // (normal + AJAX) dieselbe public_catalog_cards.php-Schleife durchlaufen.
        $cardSections = [];
        foreach ($horses as $horse) {
            $cardSections[$horse['id']] = $this->hooks()->applyFilters('catalog.card_sections', [], $horse);
        }

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');

            // Nachladen statt Ersetzen (#264): Beim Anhängen einer weiteren Seite
            // darf die Seiten-Navigation NICHT mitkommen - sie steckt in derselben
            // Teilansicht und landete sonst mitten zwischen den Karten. Sie bleibt
            // im normalen Seitenaufruf erhalten, denn ohne JavaScript ist sie der
            // einzige Weg durch den Katalog.
            if (!empty($_GET['append'])) {
                $catalogPagination = null;
            }

            ob_start();
            include __DIR__ . '/../Views/public_catalog_cards.php';
            $cardsHtml = ob_get_clean();

            // JSON_HEX_*: Die Antwort trägt zwar Content-Type
            // application/json, enthält in cards_html aber fertiges HTML.
            // Schreibt ein Aufrufer sie je unmaskiert in ein <script>-Element
            // oder schnüffelt ein alter Browser den Typ, beendet ein "</" im
            // Rumpf sonst das Skript. Die Flags kodieren < > & ' " als \uXXXX;
            // JSON_UNESCAPED_UNICODE hält Umlaute trotzdem lesbar. Der
            // zuständige Kodierer für diese Antwort IST json_encode - der
            // Empfänger ist JavaScript, kein HTML-Parser; ein htmlentities()
            // darüber zerstörte das JSON, statt es sicherer zu machen. Die
            // Karten in cards_html sind zuvor in public_catalog_cards.php
            // escaped worden, dieselbe Teilansicht wie im normalen
            // Seitenaufruf.
            //
            // Hier stand bis zur Trennung von Lesen und Erzeugen ein
            // echoed-request-Fund. Er hatte mit dieser Zeile nie etwas zu tun:
            // Die Analyse verfolgte $_GET über die zusammengesetzte
            // COUNT-Abfrage bis in $totalHorses und damit bis hierher. Mit
            // einer Klausel, die nicht mehr aus der Anfrage stammt, endet die
            // Kette an ihrem Anfang - der Fund ist ohne Zutun an dieser Stelle
            // verschwunden.
            echo json_encode([
                'success' => true,
                // Beim Nachladen bewusst null: Die Zahl wurde nicht ermittelt
                // (siehe oben), und eine erfundene waere schlimmer als keine.
                // Der Client behaelt die aus dem letzten vollen Lauf.
                'count' => $totalHorses,
                'count_text' => $totalHorses === null ? null : \App\I18n\Translator::t($totalHorses === 1 ? 'catalog.hit_count_one' : 'catalog.hit_count_other', ['count' => $totalHorses]),
                'cards_html' => $cardsHtml,
                'page' => (int)$page,
                'total_pages' => (int)$totalPages,
                'has_more' => (int)$page < (int)$totalPages,
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Distinct-Filteroptionen für die Dropdowns erst NACH der AJAX-Weiche
        // laden (#221): Die AJAX-Antwort besteht nur aus count/count_text/
        // cards_html und nutzt sie nie - vor der Weiche liefen die vier
        // DISTINCT-Scans (u. a. über die gesamte horses-Tabelle) bei jedem
        // Debounce-Tastendruck der Live-Suche mit und wurden komplett
        // weggeworfen. Nur der volle Seiten-Render braucht sie.
        $colors = $db->query("SELECT DISTINCT color FROM horses WHERE color IS NOT NULL AND color != '' AND deleted_at IS NULL ORDER BY color ASC")->fetchAll(\PDO::FETCH_COLUMN);
        $breeds = $db->query("SELECT DISTINCT breed FROM horses WHERE breed IS NOT NULL AND breed != '' AND deleted_at IS NULL ORDER BY breed ASC")->fetchAll(\PDO::FETCH_COLUMN);
        // Nur veröffentlichte Kontakte als öffentliche Filteroptionen anbieten
        // (is_published), konsistent mit der Sichtbarkeit im übrigen öffentlichen Bereich.
        //
        // Seit der Zusammenlegung auf `contacts` (#336) stammen beide Listen aus
        // DERSELBEN Tabelle. Sie einfach beide mit allen veröffentlichten
        // Kontakten zu füllen, wäre der bequeme, aber falsche Weg: Dann stünde
        // jede Privatperson im Vorschlagsfeld "Deckstation". Die Listen sind
        // deshalb auf die Kontakte eingegrenzt, die in der jeweiligen ROLLE
        // tatsächlich vorkommen - und damit genau auf die, für die der
        // zugehörige Filter überhaupt einen Treffer liefern kann
        // (HorseSearchSql: q_station vergleicht bs.name über
        // horses.breeding_station_id, q_breeder/q_owner vergleichen den Namen
        // über horse_persons.contact_id).
        //
        // Die Einschränkung auf veröffentlichte PFERDE ist dabei kein Beiwerk:
        // Vorher kamen die Listen ohne Pferdebezug aus der Personen- bzw.
        // Stationstabelle. Ein Join über unveröffentlichte Pferde machte die
        // Vorschlagsliste zum Existenz-Orakel für genau die Datensätze, die der
        // Betreiber zurückhält - dieselbe Überlegung wie bei #121/#122.
        $stations = $db->query("
            SELECT DISTINCT c.name
            FROM contacts c
            JOIN horses h ON h.breeding_station_id = c.id AND h.deleted_at IS NULL AND h.is_published = 1
            WHERE c.deleted_at IS NULL AND c.is_published = 1
            ORDER BY c.name ASC
        ")->fetchAll(\PDO::FETCH_COLUMN);
        $persons = $db->query("
            SELECT DISTINCT c.name
            FROM contacts c
            JOIN horse_persons hp ON hp.contact_id = c.id
            JOIN horses h ON h.id = hp.horse_id AND h.deleted_at IS NULL AND h.is_published = 1
            WHERE c.deleted_at IS NULL AND c.is_published = 1
            ORDER BY c.name ASC
        ")->fetchAll(\PDO::FETCH_COLUMN);

        // Minimal-Layout (#260): Das Issue nennt genau diesen fehlenden Schalter.
        // Er wirkt NUR auf die Darstellung; die Frame-Sperre lockert sich davon
        // nicht - dafuer muss der Betreiber Domains freigegeben haben
        // (EMBED_ALLOWED_DOMAINS, siehe App\Security\FrameGuard).
        $embed = isset($_GET['embed']) && $_GET['embed'] !== '0';

        $this->render('public_catalog', [
            'title' => \App\I18n\Translator::t('meta.title_catalog') . ' - ' . ($this->settings['site_name'] ?? 'Hengstverzeichnis'),
            'horses' => $horses,
            'totalHorses' => $totalHorses,
            'filters' => $_GET,
            'colors' => $colors,
            'breeds' => $breeds,
            'stations' => $stations,
            'persons' => $persons,
            'cardSections' => $cardSections,
            'catalogPagination' => $catalogPagination,
            'embed' => $embed,
        ], $embed);
    }

    public function horseDetail(): void {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            header("Location: /katalog");
            exit;
        }

        // Öffentliche Detailseite: nur veröffentlichte Pferde (is_published) und nur,
        // wenn die Gast-Gruppe Pferde sehen darf (horses.view). Andernfalls wie ein
        // nicht existierendes Pferd behandeln, um keine Rückschlüsse zu ermöglichen.
        if (!$this->hasPermission('horses', 'view')) {
            $this->renderNotFound(\App\I18n\Translator::t('horse.not_found'));
        }

        // Deckstation nur, wenn veröffentlicht und nicht gelöscht - sonst wären
        // Kontaktdaten unveröffentlichter/gelöschter Stationen über die
        // Pferde-Detailseite abrufbar, obwohl /kontakt?id=... korrekt 404 liefert (#122).
        //
        // Der Alias `bs` benennt seit #336 die ROLLE "Deckstation dieses
        // Pferdes", nicht mehr eine eigene Tabelle - dieselbe Begründung wie in
        // HorseSearchSql::joinSql().
        //
        // Die zustellbaren Felder hängen jetzt an bs.contact_public und kommen
        // ohne Freigabe als NULL an, statt in der View versteckt zu werden: Was
        // gar nicht erst ankommt, kann der nächste nicht versehentlich ausgeben
        // (Lehre aus #293). Bis v0.7 schützte hier die Trennung der Tabellen -
        // eine Geschäftsadresse war vollständig öffentlich, weil sie in einer
        // eigenen Tabelle ohne PII stand. Nach der Zusammenlegung gibt es diese
        // Trennung nicht mehr, und die strengere Regel gilt für alle Kontakte.
        // bs.contact_info steht bewusst nirgends in dieser Liste.
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT h.*, bs.name as station_name,
                   CASE WHEN bs.contact_public = 1 THEN bs.contact_person END as station_contact,
                   CASE WHEN bs.contact_public = 1 THEN bs.address END as station_address,
                   CASE WHEN bs.contact_public = 1 THEN bs.street END as station_street,
                   CASE WHEN bs.contact_public = 1 THEN bs.house_number END as station_house_number,
                   CASE WHEN bs.contact_public = 1 THEN bs.postal_code END as station_postal_code,
                   bs.city as station_city, bs.state as station_state, bs.country as station_country,
                   CASE WHEN bs.contact_public = 1 THEN bs.phone END as station_phone,
                   CASE WHEN bs.contact_public = 1 THEN bs.email END as station_email,
                   bs.website as station_website
            FROM horses h
            LEFT JOIN contacts bs ON h.breeding_station_id = bs.id AND bs.deleted_at IS NULL AND bs.is_published = 1
            WHERE h.id = ? AND h.deleted_at IS NULL AND h.is_published = 1
        ");
        $stmt->execute([$id]);
        $horse = $stmt->fetch();

        if (!$horse) {
            $this->renderNotFound(\App\I18n\Translator::t('horse.not_found'));
        }

        // Stations-Kontaktblock zusätzlich nur rendern, wenn Gäste Kontakte
        // überhaupt sehen dürfen - analog zur eigenen Kontaktroute
        // contactDetail() (#122). Das Rechte-Modul heißt seit #336 `contacts`
        // und deckt Personen wie Deckstationen ab; die frühere Trennung in
        // `persons` und `breeding_stations` ist mit den Tabellen entfallen.
        if (!$this->hasPermission('contacts', 'view')) {
            // Vollständige Feldliste - die strukturierte Adresse (#256) muss hier
            // genauso mitgenullt werden wie das alte Freitextfeld, sonst wäre der
            // Schutz durch das Nachziehen des Schemas still ausgehebelt.
            foreach ([
                'station_name', 'station_contact', 'station_address',
                'station_street', 'station_house_number', 'station_postal_code',
                'station_city', 'station_state', 'station_country',
                'station_phone', 'station_email', 'station_website',
            ] as $stationField) {
                $horse[$stationField] = null;
            }
        }

        // `horses.breeding_station` ist bei gesetzter breeding_station_id eine
        // DENORMALISIERTE Kopie des Stationsnamens (HorseController::saveHorsePersons()
        // schreibt beide Spalten immer gemeinsam fort). Die View zeigt sie als Fallback,
        // wenn station_name fehlt - ohne diese Zeile erschiene der NAME einer
        // unveröffentlichten oder gelöschten Station also weiterhin öffentlich, obwohl
        // der JOIN oben sie bewusst ausblendet (#122). Die Bedingung deckt beide
        // Ursachen ab: gefilterter JOIN und fehlendes contacts.view des Gastes
        // (Block darüber) führen gleichermaßen zu leerem station_name.
        // Freie Texteingaben (CSV-Import, Personenzeile ohne Stations-Datensatz) haben
        // KEINE breeding_station_id und bleiben deshalb unangetastet (#151).
        if (!empty($horse['breeding_station_id']) && empty($horse['station_name'])) {
            $horse['breeding_station'] = null;
        }

        // Fetch ownership history, person roles and associated breeding stations/studs.
        // Nur veröffentlichte Kontakte (#121/#122) - unveröffentlichte
        // Namen und Kontaktdaten dürfen auf der öffentlichen Seite nicht erscheinen.
        // Von den strukturierten Kontaktfeldern (#188, state seit #256,
        // Kontaktfelder seit #293) werden bewusst NUR
        // city/state/country/membership_status/website selektiert -
        // email/phone/mobile/street/house_number/postal_code/address/
        // contact_person und das Freitextfeld contact_info bleiben Admin-only
        // und erreichen weder die View noch den horse.detail_sections-Hook
        // (siehe docs/plugin-development.md). Hier steht bewusst KEINE
        // contact_public-Ausnahme: Diese Liste ist die Zuordnungszeile eines
        // Pferdes, keine Kontaktseite - wer die freigegebenen Daten sehen will,
        // folgt dem Verweis auf /kontakt?id=.
        //
        // Die Trennlinie ist nicht die Feldanzahl, sondern die Art der Angabe:
        // Was eine Sendung zustellbar macht, bleibt intern; die grobe
        // geografische Verortung ist öffentlich. Ein Bundesland ist gröber als
        // der ohnehin sichtbare Ort - es zu verbergen wäre inkonsistent und
        // zudem wirkungslos, weil es aus dem Ort folgt. Eine Website ist zur
        // Veröffentlichung bestimmt und daher öffentlich.
        //
        // contact_info stand bis #293 in dieser Liste, obwohl das Feld im
        // Admin-Formular ausdrücklich für Telefonnummern angeboten wird - also
        // für zustellbare Angaben, die nach derselben Trennlinie intern
        // gehören. Geschützt hat davor allein is_published; sobald eine
        // Redaktion eine Person freigab, stand die Nummer öffentlich.
        //
        // Die Aliasse person_name/station_name/station_id bleiben, obwohl beide
        // Seiten jetzt aus `contacts` kommen: Sie benennen die ROLLE in dieser
        // Zeile (wer - und wo), nicht die Herkunftstabelle. Denselben Weg geht
        // HorseSearchSql mit seinem Alias `bs`. Umbenannt sind nur die echten
        // Spalten, die es unter dem alten Namen nicht mehr gibt:
        // hp.person_id -> hp.contact_id, hp.breeding_station_id ->
        // hp.station_contact_id.
        $stmt = $db->prepare("
            SELECT hp.*, p.name as person_name, p.city, p.state, p.country, p.membership_status, p.website, bs.name as station_name, bs.id as station_id
            FROM horse_persons hp
            LEFT JOIN contacts p ON hp.contact_id = p.id AND p.deleted_at IS NULL AND p.is_published = 1
            LEFT JOIN contacts bs ON hp.station_contact_id = bs.id AND bs.deleted_at IS NULL AND bs.is_published = 1
            WHERE hp.horse_id = ?
            ORDER BY hp.from_year ASC, hp.id ASC
        ");
        $stmt->execute([$id]);
        $horsePersons = $stmt->fetchAll();

        // Dieselbe Regel wie oben fuer $horse, jetzt auch fuer die
        // Zuordnungszeilen (#316).
        //
        // Der Null-Block weiter oben betrifft ausschliesslich das
        // $horse-Array. Diese Abfrage holt bs.name/bs.id ein ZWEITES Mal und
        // wurde davon nicht erfasst: Wer der Gast-Gruppe das Recht auf
        // Kontakte entzogen hatte, bekam auf /kontakt?id=7
        // korrekt 404 und in der Katalog-Filterliste keine Stationen mehr -
        // im Block "Zucht & Personen" von /horse?id=42 stand die Station
        // trotzdem, samt Link auf genau diese 404-Seite.
        //
        // Der Freitext braucht dieselbe Behandlung wie horses.breeding_station
        // seit #151: Bei GESETZTER station_contact_id ist er die
        // denormalisierte Kopie des Stationsnamens (das Formular rendert beide
        // Felder nebeneinander, saveHorsePersons speichert beide). Faellt
        // station_name weg - weil der JOIN auf is_published/deleted_at
        // einschraenkt oder weil das Recht fehlt -, stuende sonst weiterhin
        // der Name der depublizierten Station da, nur aus der anderen Spalte.
        // Freitext OHNE Stations-Kontakt (CSV-Import, Zeile ohne Datensatz)
        // bleibt unangetastet, er benennt keinen verborgenen Datensatz.
        //
        // Seit #336 entscheidet EIN Recht (contacts.view) ueber beide Seiten
        // der Zeile - Person wie Deckstation. Das ist die Folge davon, dass es
        // die zwei Tabellen nicht mehr gibt; ein Modul je Rolle waere eine
        // Rechtegrenze ohne Datengrenze dahinter.
        //
        // Deshalb faellt hier jetzt auch die PERSONENSEITE weg, wenn das Recht
        // fehlt. Bis v0.7 traf der Null-Block aus #316 nur die Station: Wer
        // persons.view entzogen hatte, bekam auf /person?id=5 korrekt 404 und
        // im Block "Zucht & Personen" trotzdem den Namen samt Link auf genau
        // diese 404-Seite - dieselbe Ungereimtheit, die #316 fuer Stationen
        // behoben hat. Beide Verweise zeigen jetzt auf DIESELBE Route
        // (/kontakt?id=) unter DEMSELBEN Recht; einen davon ungeprueft zu
        // lassen, waere im selben Block sichtbar widerspruechlich.
        $kontakteSichtbar = $this->hasPermission('contacts', 'view');
        $horsePersons = array_map(static function (array $hp) use ($kontakteSichtbar): array {
            if (!$kontakteSichtbar) {
                $hp['person_name'] = null;
                $hp['contact_id'] = null;
                $hp['station_name'] = null;
                $hp['station_id'] = null;
            }
            if (!empty($hp['station_contact_id']) && empty($hp['station_name'])) {
                $hp['breeding_station_text'] = null;
            }
            return $hp;
        }, $horsePersons);

        // Zeilen, deren Person/Station nach den Sichtbarkeitsfiltern komplett
        // weggefallen ist, gar nicht erst an die View geben (leere Einträge).
        $horsePersons = array_values(array_filter($horsePersons, function ($hp) {
            // origin_country (#294) haelt eine Zeile ebenfalls am Leben: "Zuechter
            // unbekannt, kam aus Norwegen" ist eine Aussage, keine leere Zeile -
            // ohne diese Bedingung fiele sie hier still heraus.
            return !empty($hp['person_name']) || !empty($hp['station_name'])
                || !empty($hp['breeding_station_text']) || !empty($hp['origin_country']);
        }));

        // Weitere Lebensnummern (#246): Anzeige aus der Kindtabelle; für
        // Bestand ohne Zeilen dort fällt die View auf das Kompatibilitätsfeld
        // horses.foreign_ueln zurück (siehe public_horse_detail.php).
        $stmt = $db->prepare("SELECT registration_number FROM horse_registrations WHERE horse_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt->execute([$id]);
        $horseRegistrations = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        // Build 6-generation pedigree tree (#53) - die öffentliche Seite selbst
        // zeigt per Default weiterhin 3 Generationen an (JS-Umschalter bis 6),
        // die tieferen Ebenen werden serverseitig mitgeliefert, damit der
        // Generationswechsel ohne Nachladen rein clientseitig funktioniert.
        // publishedOnly = true: der öffentliche Stammbaum (und darauf aufbauende
        // Berechnungen wie ein Inzuchtkoeffizient) darf keine unveröffentlichten
        // Vorfahren einbeziehen - diese erscheinen nur als Platzhalter (siehe
        // PedigreeBuilder), damit aus unveröffentlichten Daten nichts hergeleitet wird.
        $pedigreeTree = \App\Service\PedigreeBuilder::build((int)$id, 6, true);

        // Plugin-Hook (#56): Erweiterungspunkt für einen zusätzlichen Abschnitt auf der
        // Pferde-Detailseite. Callbacks liefern bereits fertiges, selbst escapetes HTML
        // zurück (Filter-Rückgabewert wird in der View absichtlich unescaped ausgegeben,
        // siehe public_horse_detail.php) - Plugins sind für die eigene XSS-Vermeidung
        // verantwortlich, analog zum bestehenden $settings['tracking_code']-Muster.
        // Erhält zusätzlich den bereits berechneten Pedigree-Baum als vierten
        // Filter-Parameter, damit Plugins (z. B. Inzuchtkoeffizient, Pedigree-Export)
        // nicht dieselbe DB-Abfrage erneut ausführen müssen, wenn ihnen die
        // Standardtiefe genügt - für abweichende Tiefe steht ihnen unabhängig davon
        // \App\Service\PedigreeBuilder::build() direkt zur Verfügung.
        $pluginDetailSections = $this->hooks()->applyFilters('horse.detail_sections', [], $horse, $horsePersons, $pedigreeTree);

        $this->render('public_horse_detail', [
            'title' => $horse['name'] . ' - ' . \App\I18n\Translator::t('meta.title_horse_detail_suffix'),
            'horse' => $horse,
            'horsePersons' => $horsePersons,
            'horseRegistrations' => $horseRegistrations,
            'pedigree' => $pedigreeTree,
            'pluginDetailSections' => $pluginDetailSections
        ]);
    }

    /**
     * Höchstzahl der Pferde, die eine öffentliche Kontaktseite auflistet
     * (#372). Kein fachlicher Deckel, sondern ein Schutz der Seite: Sie ist
     * öffentlich, von jeder Pferdeseite verlinkt und wird von Crawlern
     * durchlaufen. Wer mehr sehen will, findet die vollständige Liste über
     * den Katalog.
     */
    private const MAX_PFERDE_JE_KONTAKT = 200;

    /**
     * Öffentliche Kontaktseite (#336) - die eine Seite, die
     * personDetail() (#293) und stationDetail() ersetzt, seit `persons` und
     * `breeding_stations` zu `contacts` zusammengeführt sind.
     *
     * DIE SPALTEN STEHEN HIER EINZELN, und zwar für JEDEN Kontakt. Bis v0.7
     * schützte die Trennung der Tabellen selbst: personDetail() wählte eine
     * Positivliste, stationDetail() durfte `SELECT *` machen, weil eine
     * Geschäftsadresse in einer eigenen Tabelle ohne PII stand. Diese
     * Trennung gibt es nicht mehr - der Schutz ist jetzt EIN FELD je
     * Datensatz (contact_public). Deshalb gilt die strengere der beiden
     * bisherigen Regeln für alle:
     *
     *   immer öffentlich  id, name, city, state, country, membership_status,
     *                     website, is_breeder, contact_public
     *   nur bei Freigabe  email, phone, mobile, street, house_number,
     *                     postal_code, address, contact_person
     *   nie öffentlich    contact_info
     *
     * Bewusst so herum statt "holen und in der View verstecken": Was gar
     * nicht erst ankommt, kann auch der nächste nicht versehentlich ausgeben
     * - genau daran ist #293 gescheitert. Ein `SELECT *` auf `contacts` darf
     * in keinem öffentlichen Pfad stehen. Die Spaltenliste ist eine feste
     * Aufzählung im Code, kein Eingabewert.
     */
    public function contactDetail(): void {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            header("Location: /katalog");
            exit;
        }

        if (!$this->hasPermission('contacts', 'view')) {
            $this->renderNotFound(\App\I18n\Translator::t('contact.not_found'));
        }

        $db = Database::getInstance();

        // Zwei Abfragen wie schon bei personDetail(): erst die Freigabe holen,
        // dann die dazu passende Spaltenliste. Eine einzelne Abfrage mit
        // CASE-Ausdrücken täte es fachlich auch, aber die Spaltenliste bliebe
        // dann eine Aufzählung, in die sich ein Feld unauffällig einreihen
        // kann. So steht die Grenze an einer Stelle, an der man sie sieht.
        $stmt = $db->prepare(
            "SELECT contact_public FROM contacts WHERE id = ? AND deleted_at IS NULL AND is_published = 1"
        );
        $stmt->execute([$id]);
        $kontaktFrei = $stmt->fetchColumn();

        $spalten = 'id, name, city, state, country, membership_status, website, is_breeder, contact_public';
        if ($kontaktFrei) {
            // Die Anschrift kommt aus `breeding_stations` mit dazu (#336): Für
            // einen Betrieb war sie dort immer sichtbar, und die Migration
            // übernimmt dessen Bestandswert für contact_public, damit die
            // Zusammenlegung nichts wegnimmt, was vorher da war.
            $spalten .= ', email, phone, mobile, street, house_number, postal_code, address, contact_person';
        }
        $stmt = $db->prepare(
            "SELECT {$spalten}
             FROM contacts
             WHERE id = ? AND deleted_at IS NULL AND is_published = 1"
        );
        $stmt->execute([$id]);
        $contact = $stmt->fetch();

        if (!$contact) {
            $this->renderNotFound(\App\I18n\Translator::t('contact.not_found'));
        }

        // Ein Kontakt hängt auf ZWEI Wegen an Pferden, und beide gab es vorher
        // je auf einer eigenen Seite (#336):
        //
        //   als Person       horse_persons.contact_id, nach Rolle gruppiert
        //                    (das war /person)
        //   als Deckstation  horse_persons.station_contact_id oder
        //                    horses.breeding_station_id (das war /station)
        //
        // Beide Blöcke werden gezeigt. Sie zu einer Liste zu verschmelzen wäre
        // falsch: "hat dieses Pferd gezüchtet" und "dieses Pferd stand hier"
        // sind verschiedene Aussagen - dieselbe Überlegung wie bei den zwei
        // Steckplätzen einer Zuordnungszeile (siehe
        // docs/kontaktliste-umstellung.md).
        //
        // Beides nur bei veröffentlichten Pferden und nur, wenn Gäste Pferde
        // überhaupt sehen dürfen; der Lebenszyklus-Status ist für die
        // Sichtbarkeit unerheblich.
        $horsesByRole = [];
        $stationHorses = [];
        $horsesGekuerzt = false;
        $stationHorsesGekuerzt = false;
        if ($this->hasPermission('horses', 'view')) {
            // LIMIT auf beiden Abfragen (#372): Die Seite ist öffentlich und
            // von jeder Pferde-Detailseite aus verlinkt, wird also auch von
            // Crawlern durchlaufen. Ein Import-Platzhalterkontakt oder ein
            // Großzüchter, an dem ein erheblicher Teil des Bestands hängt,
            // rendert sonst den halben Bestand in eine HTML-Seite. Eine Zeile
            // mehr holen, als angezeigt wird - daran erkennt der Aufrufer,
            // dass gekürzt wurde, ohne eine zweite COUNT-Abfrage.
            $deckel = self::MAX_PFERDE_JE_KONTAKT;

            $stmt = $db->prepare("
                SELECT DISTINCT hp.role, h.id, h.name, h.ueln, h.birth_year, h.color,
                       h.status, h.is_deceased, h.death_year
                FROM horse_persons hp
                JOIN horses h ON h.id = hp.horse_id AND h.deleted_at IS NULL AND h.is_published = 1
                WHERE hp.contact_id = ?
                ORDER BY hp.role ASC, h.name ASC
                LIMIT " . ($deckel + 1) . "
            ");
            $stmt->execute([$id]);
            $zeilen = $stmt->fetchAll();
            $horsesGekuerzt = count($zeilen) > $deckel;
            foreach (array_slice($zeilen, 0, $deckel) as $row) {
                $role = (string)($row['role'] ?? '');
                unset($row['role']);
                $horsesByRole[$role][] = $row;
            }

            // horses.breeding_station_id ist die AKTUELLE Deckstation eines
            // Pferdes, horse_persons.station_contact_id die einer einzelnen
            // Zuordnungszeile (also auch historische). Die alte Stationsseite
            // kannte nur den ersten Weg; ein Pferd, das hier nur früher stand,
            // fehlte dort.
            //
            // UNION statt OR/EXISTS (#372): Ein OR zwischen einem
            // indizierbaren Spaltenvergleich und einer korrelierten
            // Unterabfrage macht beide Indizes unbrauchbar - der Optimizer
            // muss horses durchlaufen und für JEDE Zeile die Unterabfrage
            // auswerten. Die beiden Zweige der UNION treffen dagegen je einen
            // Index (horses.breeding_station_id als Fremdschlüssel,
            // idx_horse_persons_station_contact), und die UNION entdoppelt
            // zugleich - ein Pferd mit mehreren passenden Zuordnungszeilen
            // erscheint genau einmal. Dasselbe Muster benutzt das Addon
            // zucht-suche, mit derselben Begründung im Kommentar.
            $stmt = $db->prepare("
                SELECT h.id, h.name, h.ueln, h.birth_year, h.color,
                       h.status, h.is_deceased, h.death_year
                FROM horses h
                WHERE h.deleted_at IS NULL AND h.is_published = 1
                  AND h.id IN (
                        SELECT id FROM horses WHERE breeding_station_id = ?
                        UNION
                        SELECT horse_id FROM horse_persons WHERE station_contact_id = ?
                  )
                ORDER BY h.name ASC
                LIMIT " . ($deckel + 1) . "
            ");
            $stmt->execute([$id, $id]);
            $stationHorses = $stmt->fetchAll();
            $stationHorsesGekuerzt = count($stationHorses) > $deckel;
            $stationHorses = array_slice($stationHorses, 0, $deckel);
        }

        // Erweiterungspunkt fuer Addons (Muster: horse.detail_sections, #56).
        // Anlass ist die geplante Kontaktanfrage: Ein Addon soll hier ein
        // Formular anbieten koennen, OHNE dass die Adresse dafuer oeffentlich
        // werden muss.
        //
        // person.detail_sections und station.detail_sections laufen als ALIAS
        // mit denselben Argumenten hinterher, jeweils auf dem Ergebnis des
        // vorherigen - ein Addon, das einen der alten Namen registriert hat,
        // laeuft in v0.8 unveraendert weiter. Die Aliasse entfallen in v0.9.0
        // (siehe docs/plugin-development.md und
        // docs/kontaktliste-umstellung.md).
        $pluginDetailSections = $this->hooks()->applyFilters('contact.detail_sections', [], $contact, $horsesByRole, $stationHorses);
        $pluginDetailSections = $this->hooks()->applyFilters('person.detail_sections', $pluginDetailSections, $contact, $horsesByRole, $stationHorses);
        $pluginDetailSections = $this->hooks()->applyFilters('station.detail_sections', $pluginDetailSections, $contact, $horsesByRole, $stationHorses);

        $this->render('public_contact_detail', [
            'title' => $contact['name'] . ' - ' . \App\I18n\Translator::t('meta.title_contact_detail_suffix'),
            'contact' => $contact,
            'horsesByRole' => $horsesByRole,
            'stationHorses' => $stationHorses,
            'horsesGekuerzt' => $horsesGekuerzt,
            'stationHorsesGekuerzt' => $stationHorsesGekuerzt,
            'pluginDetailSections' => $pluginDetailSections,
        ]);
    }

    /**
     * /person?id= - dauerhafte Weiterleitung auf die Kontaktseite (#336).
     * Die Adresse steht in Suchmaschinen und in fremden Verlinkungen; sie
     * darf nicht einfach verschwinden.
     */
    public function personRedirect(): void {
        $this->redirectLegacyContact('person', 'person.not_found');
    }

    /** /station?id= - Gegenstueck zu personRedirect() (#336). */
    public function stationRedirect(): void {
        $this->redirectLegacyContact('station', 'station.not_found');
    }

    /**
     * Loest eine alte Personen-/Stationskennung ueber `contact_id_map` auf und
     * leitet mit 301 auf /kontakt?id=<neu> um.
     *
     * OHNE TREFFER GIBT ES 404, NICHT den Katalog. Eine tote Kennung darf
     * nicht wie ein Treffer aussehen - wer auf /station?id=999 landet, soll
     * erfahren, dass es diesen Datensatz nicht gibt, und nicht wortlos auf
     * einer Trefferliste stehen, die mit seiner Anfrage nichts zu tun hat.
     * Suchmaschinen wuerden aus einer solchen Umleitung ausserdem folgern, die
     * alte Adresse sei durch den Katalog ersetzt worden.
     *
     * Die Sichtbarkeit des Ziels wird hier schon geprueft, obwohl /kontakt sie
     * ohnehin prueft: Sonst verriete allein die Umleitung, dass es die alte
     * Kennung einmal gab - bis v0.7 lieferte eine unveroeffentlichte Station
     * unter /station?id= schlicht 404, und dabei bleibt es.
     */
    private function redirectLegacyContact(string $alterTyp, string $fehlerSchluessel): void {
        $id = $_GET['id'] ?? null;
        if (!$id || !$this->hasPermission('contacts', 'view')) {
            $this->renderNotFound(\App\I18n\Translator::t($fehlerSchluessel));
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT m.contact_id
            FROM contact_id_map m
            JOIN contacts c ON c.id = m.contact_id AND c.deleted_at IS NULL AND c.is_published = 1
            WHERE m.old_type = ? AND m.old_id = ?
        ");
        $stmt->execute([$alterTyp, $id]);
        $neueId = $stmt->fetchColumn();

        if ($neueId === false) {
            $this->renderNotFound(\App\I18n\Translator::t($fehlerSchluessel));
        }

        header('Location: /kontakt?id=' . (int)$neueId, true, 301);
        exit;
    }

    public function impressum(): void {
        $this->render('public_impressum', [
            'title' => \App\I18n\Translator::t('meta.title_impressum') . ' - ' . ($this->settings['site_name'] ?? 'Hengstverzeichnis')
        ]);
    }

    public function datenschutz(): void {
        $this->render('public_datenschutz', [
            'title' => \App\I18n\Translator::t('meta.title_datenschutz') . ' - ' . ($this->settings['site_name'] ?? 'Hengstverzeichnis')
        ]);
    }

    /**
     * Obergrenze für den Freitext einer DSGVO-Anfrage. Die Spalte selbst ist
     * TEXT (64 KB); die engere Grenze verhindert, dass ein einzelner Absender
     * die Tabelle und die Benachrichtigungs-E-Mails mit Megabyte-Eingaben
     * aufbläht.
     */
    private const DSGVO_MESSAGE_MAX_LENGTH = 5000;

    public function dsgvoForm(): void {
        $this->renderDsgvoForm();
    }

    public function dsgvoSubmit(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden(\App\I18n\Translator::t('errors.csrf_invalid'));
        }

        // Nur echte Zeichenketten übernehmen: Ein manipulierter Request
        // (name[]=x) darf weder eine "Array to string"-Warnung noch einen
        // TypeError in den nachgelagerten Prüfungen auslösen.
        $postString = static fn(string $key, string $default = ''): string
            => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : $default;

        $old = [
            'name' => $postString('name'),
            'email' => $postString('email'),
            'request_type' => $postString('request_type', 'info'),
            'message' => $postString('message')
        ];

        // Zwei getrennte Zähler pro Client-IP (analog zum Login, #115):
        // 'dsgvo_attempt' zählt JEDEN POST und bremst damit automatisiertes
        // Durchprobieren des CAPTCHAs; 'dsgvo_request' zählt nur tatsächlich
        // angenommene Anfragen und begrenzt eng, wie oft ein Client echte
        // Admin-Benachrichtigungen auslösen und Zeilen in gdpr_requests anlegen
        // kann. Die Trennung sorgt dafür, dass ein Tippfehler im CAPTCHA nicht
        // das kleine Kontingent echter Anfragen aufbraucht.
        $clientIp = \App\Security\ClientIp::resolve();
        if (
            \App\Security\RateLimiter::tooManyAttempts($clientIp, 'dsgvo_attempt', 20, 3600)
            || \App\Security\RateLimiter::tooManyAttempts($clientIp, 'dsgvo_request', 3, 3600)
        ) {
            $this->renderDsgvoForm(\App\I18n\Translator::t('dsgvo.rate_limited'), $old);
            return;
        }
        \App\Security\RateLimiter::recordAttempt($clientIp, 'dsgvo_attempt');

        // Honeypot: für Menschen unsichtbares Feld, das nur automatische
        // Formularausfüller befüllen. Bewusst mit der normalen Erfolgsmeldung
        // beantwortet - der Bot erfährt so nicht, dass er erkannt wurde -,
        // aber ohne Speicherung und ohne Benachrichtigungs-E-Mail.
        if (\App\Security\Captcha::honeypotTripped($_POST)) {
            \App\Security\Captcha::clear();
            header("Location: /dsgvo?success=1");
            exit;
        }

        $captchaResult = \App\Security\Captcha::verify($this->settings, 'dsgvo', $_POST);
        if ($captchaResult !== \App\Security\Captcha::OK) {
            $errorKey = match ($captchaResult) {
                \App\Security\Captcha::EXPIRED => 'dsgvo.captcha_expired',
                \App\Security\Captcha::TOO_FAST => 'dsgvo.captcha_too_fast',
                default => 'dsgvo.captcha_wrong'
            };
            $this->renderDsgvoForm(\App\I18n\Translator::t($errorKey), $old);
            return;
        }

        // Serverseitige Validierung: Zuvor wurden ungültige Eingaben still
        // verworfen und dem Absender trotzdem Erfolg gemeldet - eine echte
        // Anfrage konnte so unbemerkt verloren gehen. Die Längen entsprechen
        // den Spaltenbreiten in `gdpr_requests` (siehe database/schema.sql).
        if (
            $old['email'] === ''
            || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)
            || mb_strlen($old['email']) > 100
        ) {
            $this->renderDsgvoForm(\App\I18n\Translator::t('dsgvo.email_invalid'), $old);
            return;
        }
        if (!in_array($old['request_type'], ['info', 'deletion'], true)) {
            $this->renderDsgvoForm(\App\I18n\Translator::t('dsgvo.request_type_invalid'), $old);
            return;
        }
        if (mb_strlen($old['name']) > 100) {
            $this->renderDsgvoForm(\App\I18n\Translator::t('dsgvo.name_too_long'), $old);
            return;
        }
        if (mb_strlen($old['message']) > self::DSGVO_MESSAGE_MAX_LENGTH) {
            $this->renderDsgvoForm(
                \App\I18n\Translator::t('dsgvo.message_too_long', ['max' => self::DSGVO_MESSAGE_MAX_LENGTH]),
                $old
            );
            return;
        }

        \App\Security\RateLimiter::recordAttempt($clientIp, 'dsgvo_request');

        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO gdpr_requests (name, email, request_type, message) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $old['name'] ?: null,
            $old['email'],
            $old['request_type'],
            $old['message'] ?: null
        ]);

        // Send Email Notification to Admin
        $mailer = new \App\Service\Mailer();
        $typeName = $old['request_type'] === 'deletion'
            ? 'Löschung / Anonymisierung von Daten (Art. 17 DSGVO)'
            : 'Auskunft über Daten (Art. 15 DSGVO)';
        $mailer->sendDsgvoNotification($old['email'], $typeName, $old['message'], $old['name']);

        header("Location: /dsgvo?success=1");
        exit;
    }

    /**
     * Rendert das DSGVO-Formular - beim ersten Aufruf und nach jedem
     * Fehlversuch. Bewusst direktes Rendern statt Redirect: So bleiben die
     * bereits eingegebenen Werte erhalten. Eine lange Betroffenen-Anfrage nach
     * einem Rechenfehler noch einmal tippen zu müssen, ist der sicherste Weg,
     * dass jemand sein Auskunftsrecht am Ende nicht wahrnimmt.
     */
    private function renderDsgvoForm(?string $error = null, array $old = []): void {
        $this->render('public_dsgvo', [
            'title' => \App\I18n\Translator::t('meta.title_dsgvo') . ' - ' . ($this->settings['site_name'] ?? 'Hengstverzeichnis'),
            'error' => $error,
            'old' => $old,
            // Fertiges Formularfragment des aktiven Anbieters - im selben
            // Formular, im selben Absendevorgang. Keine vorgeschaltete
            // Prüfseite, kein zweiter Schritt.
            'captchaField' => \App\Security\Captcha::renderField($this->settings, 'dsgvo')
        ]);
    }
}
