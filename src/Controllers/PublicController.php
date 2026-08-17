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

        $this->render('public_home', [
            'title' => \App\I18n\Translator::t('meta.title_home') . ' - ' . ($this->settings['site_name'] ?? 'Hengstverzeichnis'),
            'featuredHorses' => $featuredHorses
        ]);
    }

    /** Kartengröße je Katalogseite (SQL-seitige Pagination, #125). */
    private const CATALOG_PER_PAGE = 24;

    public function catalog(): void {
        $db = Database::getInstance();

        // Suchlogik gemeinsam mit der Pferdeverwaltung (HorseSearchFilter).
        // $nurOeffentlich = true haelt die Sichtbarkeitsgrenzen des Katalogs
        // aufrecht: verknuepfte Personen, Stationen und Elterntiere zaehlen nur
        // veroeffentlicht, sonst waere der Filter ein Existenz-Orakel
        // (#121/#122/#151). Der Admin bekommt denselben Baustein mit false.
        $filter = \App\Service\HorseSearchFilter::fromRequest($_GET, true);
        $params = $filter->params();
        $whereSql = $filter->whereSql();
        $joinSql = $filter->joinSql();
        $personAggregateJoin = $filter->personAggregateJoin();

        // Gast-Gruppe ohne horses.view sieht keinerlei Pferde (leerer Katalog),
        // sonst würde die Rechte-Entziehung wirkungslos bleiben.
        $horses = [];
        $totalHorses = 0;
        $totalPages = 1;
        $page = self::requestInt('page', 1, 1);

        if ($this->hasPermission('horses', 'view')) {
            // Echte SQL-Pagination statt "alle Treffer laden" (#125).
            $countStmt = $db->prepare("SELECT COUNT(*) {$joinSql} WHERE {$whereSql}");
            $countStmt->execute($params);
            $totalHorses = (int)$countStmt->fetchColumn();

            $totalPages = max(1, (int)ceil($totalHorses / self::CATALOG_PER_PAGE));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * self::CATALOG_PER_PAGE;

            // h.breeding_station ist bei gesetzter breeding_station_id die
            // denormalisierte Kopie des Stationsnamens, und die Katalogkarte zeigt sie
            // als Fallback zu station_name. Sie wird deshalb unterdrückt, sobald die
            // Station öffentlich nicht sichtbar ist - der bs-JOIN ist bereits auf
            // is_published = 1 AND deleted_at IS NULL eingeschränkt, "bs.id IS NULL"
            // heißt dort also exakt: nicht öffentlich sichtbar. Freitext ohne
            // Stations-Datensatz hat keine breeding_station_id und bleibt (#151/#122).
            $sql = "
                SELECT
                    h.id, h.name, h.ueln, h.foreign_ueln, h.birth_year, h.birth_date, h.color, h.status, h.is_deceased, h.death_year, h.image_url,
                    (SELECT GROUP_CONCAT(hr.registration_number ORDER BY hr.sort_order ASC, hr.id ASC SEPARATOR ' / ')
                     FROM horse_registrations hr WHERE hr.horse_id = h.id) AS registration_numbers,
                    CASE WHEN h.breeding_station_id IS NOT NULL AND bs.id IS NULL
                         THEN NULL ELSE h.breeding_station END AS breeding_station,
                    bs.name as station_name,
                    sire.name as linked_sire_name, h.sire_name as unlinked_sire_name,
                    dam.name as linked_dam_name, h.dam_name as unlinked_dam_name,
                    hpx.breeder_name,
                    hpx.owner_name
                {$joinSql}
                {$personAggregateJoin}
                WHERE {$whereSql}
                ORDER BY h.name ASC
                LIMIT ? OFFSET ?
            ";

            $stmt = $db->prepare($sql);
            $paramIndex = 1;
            foreach ($params as $value) {
                $stmt->bindValue($paramIndex++, $value);
            }
            $stmt->bindValue($paramIndex++, self::CATALOG_PER_PAGE, \PDO::PARAM_INT);
            $stmt->bindValue($paramIndex, $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $horses = $stmt->fetchAll();
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

        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_GET['ajax']);

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

            echo json_encode([
                'success' => true,
                'count' => $totalHorses,
                'count_text' => \App\I18n\Translator::t($totalHorses === 1 ? 'catalog.hit_count_one' : 'catalog.hit_count_other', ['count' => $totalHorses]),
                'cards_html' => $cardsHtml,
                'page' => (int)$page,
                'total_pages' => (int)$totalPages,
                'has_more' => (int)$page < (int)$totalPages,
            ]);
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
        // Nur veröffentlichte Stationen/Personen als öffentliche Filteroptionen anbieten
        // (is_published), konsistent mit der Sichtbarkeit im übrigen öffentlichen Bereich.
        $stations = $db->query("SELECT DISTINCT name FROM breeding_stations WHERE deleted_at IS NULL AND is_published = 1 ORDER BY name ASC")->fetchAll(\PDO::FETCH_COLUMN);
        $persons = $db->query("SELECT DISTINCT name FROM persons WHERE deleted_at IS NULL AND is_published = 1 ORDER BY name ASC")->fetchAll(\PDO::FETCH_COLUMN);

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
        // Pferde-Detailseite abrufbar, obwohl /station?id=... korrekt 404 liefert (#122).
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT h.*, bs.name as station_name, bs.contact_person as station_contact, bs.address as station_address,
                   bs.street as station_street, bs.house_number as station_house_number, bs.postal_code as station_postal_code,
                   bs.city as station_city, bs.state as station_state, bs.country as station_country,
                   bs.phone as station_phone, bs.email as station_email, bs.website as station_website
            FROM horses h
            LEFT JOIN breeding_stations bs ON h.breeding_station_id = bs.id AND bs.deleted_at IS NULL AND bs.is_published = 1
            WHERE h.id = ? AND h.deleted_at IS NULL AND h.is_published = 1
        ");
        $stmt->execute([$id]);
        $horse = $stmt->fetch();

        if (!$horse) {
            $this->renderNotFound(\App\I18n\Translator::t('horse.not_found'));
        }

        // Stations-Kontaktblock zusätzlich nur rendern, wenn Gäste Deckstationen
        // überhaupt sehen dürfen - analog zur eigenen Stationsroute stationDetail() (#122).
        if (!$this->hasPermission('breeding_stations', 'view')) {
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
        // Ursachen ab: gefilterter JOIN und fehlendes breeding_stations.view des Gastes
        // (Block darüber) führen gleichermaßen zu leerem station_name.
        // Freie Texteingaben (CSV-Import, Personenzeile ohne Stations-Datensatz) haben
        // KEINE breeding_station_id und bleiben deshalb unangetastet (#151).
        if (!empty($horse['breeding_station_id']) && empty($horse['station_name'])) {
            $horse['breeding_station'] = null;
        }

        // Fetch ownership history, person roles and associated breeding stations/studs.
        // Nur veröffentlichte Personen/Stationen (#121/#122) - unveröffentlichte
        // Namen und Kontaktdaten dürfen auf der öffentlichen Seite nicht erscheinen.
        // Von den strukturierten Personenfeldern (#188, state seit #256,
        // Kontaktfelder seit #293) werden bewusst NUR
        // city/state/country/membership_status/website selektiert -
        // email/phone/mobile/street/house_number/postal_code und das
        // Freitextfeld contact_info bleiben Admin-only und erreichen weder die
        // View noch den horse.detail_sections-Hook (siehe
        // docs/plugin-development.md).
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
        $stmt = $db->prepare("
            SELECT hp.*, p.name as person_name, p.city, p.state, p.country, p.membership_status, p.website, bs.name as station_name, bs.id as station_id
            FROM horse_persons hp
            LEFT JOIN persons p ON hp.person_id = p.id AND p.deleted_at IS NULL AND p.is_published = 1
            LEFT JOIN breeding_stations bs ON hp.breeding_station_id = bs.id AND bs.deleted_at IS NULL AND bs.is_published = 1
            WHERE hp.horse_id = ?
            ORDER BY hp.from_year ASC, hp.id ASC
        ");
        $stmt->execute([$id]);
        $horsePersons = $stmt->fetchAll();

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

    public function stationDetail(): void {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            header("Location: /katalog");
            exit;
        }

        // Öffentliche Stationsseite nur, wenn die Gast-Gruppe Deckstationen sehen darf.
        if (!$this->hasPermission('breeding_stations', 'view')) {
            $this->renderNotFound(\App\I18n\Translator::t('station.not_found'));
        }

        // Nur veröffentlichte Stationen (is_published) sind öffentlich erreichbar -
        // unveröffentlichte liefern wie ein fehlender Datensatz eine 404.
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM breeding_stations WHERE id = ? AND deleted_at IS NULL AND is_published = 1");
        $stmt->execute([$id]);
        $station = $stmt->fetch();

        if (!$station) {
            $this->renderNotFound(\App\I18n\Translator::t('station.not_found'));
        }

        // Zugeordnete Pferde nur, wenn Gäste Pferde sehen dürfen UND das jeweilige
        // Pferd veröffentlicht ist (Status ist für die Sichtbarkeit irrelevant).
        $horses = [];
        if ($this->hasPermission('horses', 'view')) {
            $stmt = $db->prepare("
                SELECT id, name, ueln, birth_year, color, status, is_deceased, death_year, image_url
                FROM horses
                WHERE breeding_station_id = ? AND deleted_at IS NULL AND is_published = 1
                ORDER BY name ASC
            ");
            $stmt->execute([$id]);
            $horses = $stmt->fetchAll();
        }

        $pluginDetailSections = $this->hooks()->applyFilters('station.detail_sections', [], $station, $horses);

        $this->render('public_station_detail', [
            'title' => $station['name'] . ' - ' . \App\I18n\Translator::t('meta.title_station_detail_suffix'),
            'station' => $station,
            'horses' => $horses,
            'pluginDetailSections' => $pluginDetailSections,
        ]);
    }

    /**
     * Öffentliche Personenseite (#293). Bis dahin gab es sie nicht: Personen
     * erschienen nur als Name in der Pferde-Detailseite, ohne eigenen Ort und
     * ohne Möglichkeit, die Pferde einer Person zusammen zu sehen - anders als
     * bei Deckstationen, die ihre Seite längst haben.
     *
     * Die Spalten stehen hier bewusst EINZELN statt als `SELECT *`. Bei
     * Deckstationen ist das anders (deren Anschrift ist eine Geschäftsadresse
     * und vollständig öffentlich); persons enthält dagegen
     * E-Mail/Telefon/Mobil/Straße/PLZ und das interne Freitextfeld
     * contact_info. Ein `SELECT *` würde all das in die View reichen, und der
     * nächste, der dort etwas ausgibt, hätte es versehentlich veröffentlicht -
     * genau so ist #293 überhaupt entstanden. Was hier nicht steht, kann nicht
     * verraten werden.
     */
    public function personDetail(): void {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            header("Location: /katalog");
            exit;
        }

        if (!$this->hasPermission('persons', 'view')) {
            $this->renderNotFound(\App\I18n\Translator::t('person.not_found'));
        }

        $db = Database::getInstance();

        // Die Kontaktspalten kommen nur mit, wenn der Datensatz sie ausdruecklich
        // freigibt. Bewusst so herum statt "holen und in der View verstecken":
        // Was gar nicht erst ankommt, kann auch der naechste nicht versehentlich
        // ausgeben - genau daran ist #293 gescheitert. Die Spaltenliste ist eine
        // feste Aufzaehlung im Code, kein Eingabewert.
        $stmt = $db->prepare(
            "SELECT contact_public FROM persons WHERE id = ? AND deleted_at IS NULL AND is_published = 1"
        );
        $stmt->execute([$id]);
        $kontaktFrei = $stmt->fetchColumn();

        $spalten = 'id, name, city, state, country, membership_status, website, is_breeder, contact_public';
        if ($kontaktFrei) {
            $spalten .= ', email, phone, mobile';
        }
        $stmt = $db->prepare(
            "SELECT {$spalten}
             FROM persons
             WHERE id = ? AND deleted_at IS NULL AND is_published = 1"
        );
        $stmt->execute([$id]);
        $person = $stmt->fetch();

        if (!$person) {
            $this->renderNotFound(\App\I18n\Translator::t('person.not_found'));
        }

        // Pferde dieser Person, nach Rolle gruppiert - nur veröffentlichte,
        // und nur wenn Gäste Pferde überhaupt sehen dürfen (wie bei Stationen).
        $horsesByRole = [];
        if ($this->hasPermission('horses', 'view')) {
            $stmt = $db->prepare("
                SELECT DISTINCT hp.role, h.id, h.name, h.ueln, h.birth_year, h.color,
                       h.status, h.is_deceased, h.death_year, h.image_url
                FROM horse_persons hp
                JOIN horses h ON h.id = hp.horse_id AND h.deleted_at IS NULL AND h.is_published = 1
                WHERE hp.person_id = ?
                ORDER BY hp.role ASC, h.name ASC
            ");
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll() as $row) {
                $role = (string)($row['role'] ?? '');
                unset($row['role']);
                $horsesByRole[$role][] = $row;
            }
        }

        // Erweiterungspunkt fuer Addons (Muster: horse.detail_sections, #56).
        // Anlass ist die geplante Kontaktanfrage: Ein Addon soll hier ein
        // Formular anbieten koennen, OHNE dass die Adresse dafuer oeffentlich
        // werden muss.
        $pluginDetailSections = $this->hooks()->applyFilters('person.detail_sections', [], $person, $horsesByRole);

        $this->render('public_person_detail', [
            'title' => $person['name'] . ' - ' . \App\I18n\Translator::t('meta.title_person_detail_suffix'),
            'person' => $person,
            'horsesByRole' => $horsesByRole,
            'pluginDetailSections' => $pluginDetailSections,
        ]);
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
