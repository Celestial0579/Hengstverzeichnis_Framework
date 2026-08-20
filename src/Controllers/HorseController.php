<?php
// src/Controllers/HorseController.php

namespace App\Controllers;

use App\Database;
use App\Service\HorseSearchCriteria;
use App\Service\HorseSearchSql;

class HorseController extends BaseController {

    /** Gültige Werte der horses.sex-ENUM (#165); NULL = unbekannt. */
    private const SEXES = ['stallion', 'mare', 'gelding'];

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

        // Dieselbe Filterlogik wie der öffentliche Katalog, aber OHNE dessen
        // Sichtbarkeitsgrenzen ($nurOeffentlich = false): Die Verwaltung muss
        // gerade die unveröffentlichten Züchter, Stationen und Elterntiere
        // finden können - das ist ihre Aufgabe. Gelöschte bleiben draußen, die
        // stehen im Papierkorb.
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
        $sql = new HorseSearchSql(false);
        $criteria = HorseSearchCriteria::fromRequest($_GET, false, $publishedFilter);
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

        // Auswahllisten der Detailfilter. Anders als im Katalog ohne
        // is_published-Einschränkung - siehe oben.
        $colors = $db->query("SELECT DISTINCT color FROM horses WHERE color IS NOT NULL AND color != '' AND deleted_at IS NULL ORDER BY color ASC")->fetchAll(\PDO::FETCH_COLUMN);
        $breeds = $db->query("SELECT DISTINCT breed FROM horses WHERE breed IS NOT NULL AND breed != '' AND deleted_at IS NULL ORDER BY breed ASC")->fetchAll(\PDO::FETCH_COLUMN);
        $stations = $db->query("SELECT DISTINCT name FROM breeding_stations WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll(\PDO::FETCH_COLUMN);
        $persons = $db->query("SELECT DISTINCT name FROM persons WHERE deleted_at IS NULL ORDER BY name ASC")->fetchAll(\PDO::FETCH_COLUMN);

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
            'stations' => $stations,
            'persons' => $persons,
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
            foreach ($ids as $id) {
                $stmt->execute([$publish, $id]);
            }

            \App\Service\AuditLogger::log(
                $publish ? "Pferde veröffentlicht" : "Veröffentlichung von Pferden zurückgenommen",
                "horses",
                count($ids) . " Datensätze (IDs: " . implode(', ', $ids) . ")"
            );
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
     * @param array<int, int|null> $mustInclude IDs, die enthalten sein müssen
     * @return array<int, array<string, mixed>>
     */
    private function parentOptions(\PDO $db, array $mustInclude = []): array {
        $stmt = $db->query(
            "SELECT id, name, ueln, birth_year, sex FROM horses WHERE deleted_at IS NULL"
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
                "SELECT id, name, ueln, birth_year, sex FROM horses"
                . " WHERE deleted_at IS NULL AND id IN (" . implode(',', array_fill(0, count($fehlend), '?')) . ")"
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

        $stmt = $db->query("SELECT id, name FROM persons WHERE deleted_at IS NULL ORDER BY name ASC");
        $allPersons = $stmt->fetchAll();

        $stmt = $db->query("SELECT id, name FROM breeding_stations WHERE deleted_at IS NULL ORDER BY name ASC");
        $allBreedingStations = $stmt->fetchAll();

        $this->render('admin_horse_form', [
            'title' => 'Neues Pferd anlegen',
            'horse' => null,
            'allHorses' => $allHorses,
            'allPersons' => $allPersons,
            'allBreedingStations' => $allBreedingStations,
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
        // persons[N][breeding_station_id]), regulär setzt den Wert der CSV-Import.
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

        // Handle Photo Upload
        $imageUrl = $this->handleImageUpload($_FILES['horse_image'] ?? null);

        // Plugin-Hook (#56): Erweiterungspunkt VOR dem Anlegen eines Pferdes, z. B. für
        // zusätzliche verbandsspezifische Prüfungen. Kann das Anlegen selbst nicht
        // blockieren (siehe HookManager-Isolation) - ein fehlerhaftes Plugin darf den
        // Kern-Workflow nie verhindern.
        $this->hooks()->doAction('horse.before_save', null, $_POST);

        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO horses (name, ueln, foreign_ueln, sire_id, sire_name, sire_ueln, dam_id, dam_name, dam_ueln, birth_year, birth_date, color, sex, castration_date, breed, height_cm, breeding_station_id, breeding_station, description, status, is_deceased, death_year, is_published, image_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $ueln, $foreign_ueln, $sire_id, $sire_name, $sire_ueln, $dam_id, $dam_name, $dam_ueln, $birth_year, $birth_date, $color, $sex, $castration_date, $breed, $height_cm, $breeding_station_id, $breeding_station, $description, $status, $is_deceased, $death_year, $isPublished, $imageUrl]);
        $newHorseId = (int)$db->lastInsertId();

        \App\Service\AuditLogger::log("Pferd angelegt", "horses", "Name: {$name}" . ($ueln ? " (UELN: {$ueln})" : ""));

        // Save Person Roles & Ownership History (horse_persons)
        $this->saveHorsePersons($db, $newHorseId, $_POST['persons'] ?? []);

        // Weitere Lebensnummern (#246) speichern; die normalisierte Liste geht
        // zusätzlich ins Auto-Linking, denn auch diese Nummern identifizieren
        // das Pferd eindeutig (analog ueln/foreign_ueln).
        $registrationNumbers = $this->saveRegistrations($db, $newHorseId, $ueln);

        // Run auto-linking to automatically attach existing unlinked placeholders to this new horse
        $this->autoLinkMatches($newHorseId, $name, $ueln, $foreign_ueln, $birth_year, $registrationNumbers);

        // Plugin-Hook (#56): Erweiterungspunkt NACH dem Anlegen, z. B. für Folgeaktionen
        // in einem Plugin (Benachrichtigung, verknüpfte Zusatzdaten anlegen etc.).
        $this->hooks()->doAction('horse.after_save', $newHorseId, $_POST, true);

        header("Location: /admin/horses?success=created");
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
        // Speichern nicht geht - wie admin_person_form und
        // admin_breeding_station_form seit #296. Ohne den Hinweis fuellt
        // jemand das Formular aus und bekommt erst beim Absenden eine
        // Fehlermeldung.
        $isDeleted = $horse['deleted_at'] !== null;

        $allHorses = $this->parentOptions($db, [
            $horse['sire_id'] ?? null,
            $horse['dam_id'] ?? null,
        ]);

        $stmt = $db->query("SELECT id, name FROM persons WHERE deleted_at IS NULL ORDER BY name ASC");
        $allPersons = $stmt->fetchAll();

        $stmt = $db->query("SELECT id, name FROM breeding_stations WHERE deleted_at IS NULL ORDER BY name ASC");
        $allBreedingStations = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT hp.*, p.name, bs.name AS station_name FROM horse_persons hp LEFT JOIN persons p ON hp.person_id = p.id AND p.deleted_at IS NULL LEFT JOIN breeding_stations bs ON hp.breeding_station_id = bs.id WHERE hp.horse_id = ? ORDER BY hp.id ASC");
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
            'allPersons' => $allPersons,
            'allBreedingStations' => $allBreedingStations,
            'horsePersons' => $horsePersons,
            'horseRegistrations' => $horseRegistrations,
            'canPublish' => $this->hasPermission('horses', 'publish'),
            'isDeleted' => $isDeleted,
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

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT image_url, status, is_published, deleted_at FROM horses WHERE id = ?");
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

        $currentImageUrl = $existing['image_url'] ?? null;

        // Veröffentlichung (öffentliche Sichtbarkeit) ist unabhängig vom Status und
        // darf nur mit 'horses.publish' geändert werden. Ohne diese Berechtigung
        // bleibt der bisherige Veröffentlichungszustand unverändert erhalten (ein
        // übermittelter Wunsch wird stillschweigend ignoriert statt gespeichert).
        if ($this->hasPermission('horses', 'publish')) {
            $isPublished = !empty($_POST['is_published']) ? 1 : 0;
        } else {
            $isPublished = (int)($existing['is_published'] ?? 0);
        }

        // Check for remove image request
        if (!empty($_POST['remove_image']) && $currentImageUrl) {
            $filePath = __DIR__ . '/../../public' . $currentImageUrl;
            if (file_exists($filePath)) @unlink($filePath);
            $currentImageUrl = null;
        }

        // Check for new upload
        $newUploadedUrl = $this->handleImageUpload($_FILES['horse_image'] ?? null);
        if ($newUploadedUrl) {
            // Delete old file if present
            if ($currentImageUrl) {
                $filePath = __DIR__ . '/../../public' . $currentImageUrl;
                if (file_exists($filePath)) @unlink($filePath);
            }
            $currentImageUrl = $newUploadedUrl;
        }

        // Plugin-Hook (#56): siehe store() für die Begründung, hier für den Update-Pfad.
        $this->hooks()->doAction('horse.before_save', (int)$id, $_POST);

        // breeding_station = COALESCE(?, breeding_station) (#214): NULL steht für
        // "Feld nicht übermittelt" (siehe oben) und erhält den Bestandswert.
        // foreign_ueln analog (#246), aber per CASE statt COALESCE: ein
        // übermittelter Leerstring soll NULL speichern (wie früher `?: null`),
        // nicht den Leerstring selbst.
        $stmt = $db->prepare("UPDATE horses SET name = ?, ueln = ?, foreign_ueln = CASE WHEN ? IS NULL THEN foreign_ueln ELSE NULLIF(?, '') END, sire_id = ?, sire_name = ?, sire_ueln = ?, dam_id = ?, dam_name = ?, dam_ueln = ?, birth_year = ?, birth_date = ?, color = ?, sex = ?, castration_date = ?, breed = ?, height_cm = ?, breeding_station_id = ?, breeding_station = COALESCE(?, breeding_station), description = ?, status = ?, is_deceased = ?, death_year = ?, is_published = ?, image_url = ? WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$name, $ueln, $foreign_ueln, $foreign_ueln, $sire_id, $sire_name, $sire_ueln, $dam_id, $dam_name, $dam_ueln, $birth_year, $birth_date, $color, $sex, $castration_date, $breed, $height_cm, $breeding_station_id, $breeding_station, $description, $status, $is_deceased, $death_year, $isPublished, $currentImageUrl, $id]);

        \App\Service\AuditLogger::log("Pferd aktualisiert", "horses", "Pferd ID {$id}: {$name}" . ($ueln ? " (UELN: {$ueln})" : ""));

        // Save Person Roles & Ownership History (horse_persons)
        $this->saveHorsePersons($db, (int)$id, $_POST['persons'] ?? []);

        // Weitere Lebensnummern (#246), siehe store().
        $registrationNumbers = $this->saveRegistrations($db, (int)$id, $ueln);

        // Run auto-linking for matches
        $this->autoLinkMatches((int)$id, $name, $ueln, ($foreign_ueln !== null && $foreign_ueln !== '') ? $foreign_ueln : null, $birth_year, $registrationNumbers);

        // Plugin-Hook (#56): siehe store() für die Begründung, hier für den Update-Pfad.
        $this->hooks()->doAction('horse.after_save', (int)$id, $_POST, false);

        header("Location: /admin/horses?success=updated");
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
                $jahr = $this->parseYear((string)($item[$feld] ?? ''));
                if ($jahr !== null && $jahr > $deathYear) {
                    return 'period_after_death';
                }
            }
        }

        return null;
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
     * Helper to validate and save uploaded horse image
     */
    private function handleImageUpload(?array $file): ?string {
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] === 0) {
            return null;
        }

        // Max 5MB
        if ($file['size'] > 5 * 1024 * 1024) {
            return null;
        }

        // Validate MIME type
        $allowedMimeTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        if (!isset($allowedMimeTypes[$mime])) {
            return null;
        }

        $ext = $allowedMimeTypes[$mime];
        $uploadDir = __DIR__ . '/../../public/uploads/horses/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = 'horse_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetPath = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            return '/uploads/horses/' . $filename;
        }

        return null;
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
     * @param string[] $registrationNumbers
     */
    private function autoLinkMatches(int $horseId, string $name, ?string $ueln, ?string $foreignUeln = null, ?int $birthYear = null, array $registrationNumbers = []): void {
        $db = Database::getInstance();

        $uelnsToMatch = array_unique(array_filter(array_merge(
            [trim($ueln ?? ''), trim($foreignUeln ?? '')],
            array_map('trim', $registrationNumbers)
        )));

        // Alle UPDATEs schließen die eben gespeicherte Zeile selbst aus (AND id != ?):
        // ohne diesen Guard kann sich ein Pferd selbst als Elternteil zugewiesen
        // bekommen (z. B. eigene UELN im Vater-UELN-Feld oder gleichlautender
        // Freitext-Vatername) - ein Stammbaum-Zyklus (#131).
        foreach ($uelnsToMatch as $u) {
            // Auto-link Sires matching UELN or Foreign UELN (UELN ist eindeutig, keine Mehrdeutigkeit möglich)
            $stmt = $db->prepare("UPDATE horses SET sire_id = ?, sire_name = NULL, sire_ueln = NULL WHERE sire_id IS NULL AND sire_ueln = ? AND id != ?");
            $stmt->execute([$horseId, $u, $horseId]);
            $countSires = $stmt->rowCount();
            if ($countSires > 0) {
                \App\Service\AuditLogger::log("Automatische Zusammenführung", "horses", "{$countSires} Nachkommen automatisch mit Vater ID {$horseId} (UELN: {$u}) verknüpft");
            }

            // Auto-link Dams matching UELN or Foreign UELN
            $stmt = $db->prepare("UPDATE horses SET dam_id = ?, dam_name = NULL, dam_ueln = NULL WHERE dam_id IS NULL AND dam_ueln = ? AND id != ?");
            $stmt->execute([$horseId, $u, $horseId]);
            $countDams = $stmt->rowCount();
            if ($countDams > 0) {
                \App\Service\AuditLogger::log("Automatische Zusammenführung", "horses", "{$countDams} Nachkommen automatisch mit Mutter ID {$horseId} (UELN: {$u}) verknüpft");
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
                $stmt = $db->prepare("UPDATE horses SET sire_id = ?, sire_name = NULL, sire_ueln = NULL WHERE sire_id IS NULL AND (sire_ueln IS NULL OR sire_ueln = '') AND LOWER(sire_name) = LOWER(?) AND id != ?{$ageCondition}");
                $stmt->execute([$horseId, $name, $horseId, ...$ageParams]);
                $countNameSires = $stmt->rowCount();
                if ($countNameSires > 0) {
                    \App\Service\AuditLogger::log("Automatische Zusammenführung", "horses", "{$countNameSires} Nachkommen anhand Name '{$name}' mit Vater ID {$horseId} verknüpft");
                }

                // Auto-link Dams matching exact Name (where dam_ueln is empty)
                $stmt = $db->prepare("UPDATE horses SET dam_id = ?, dam_name = NULL, dam_ueln = NULL WHERE dam_id IS NULL AND (dam_ueln IS NULL OR dam_ueln = '') AND LOWER(dam_name) = LOWER(?) AND id != ?{$ageCondition}");
                $stmt->execute([$horseId, $name, $horseId, ...$ageParams]);
                $countNameDams = $stmt->rowCount();
                if ($countNameDams > 0) {
                    \App\Service\AuditLogger::log("Automatische Zusammenführung", "horses", "{$countNameDams} Nachkommen anhand Name '{$name}' mit Mutter ID {$horseId} verknüpft");
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

        $this->render('admin_matches', [
            'title' => 'Blutlinien Zusammenführen & Match-Vorschläge',
            'unlinkedMatches' => $unlinkedMatches,
            'allHorses' => $allHorses,
            'sexMismatches' => $sexMismatches,
            'matchPage' => $matchPage,
            'matchTotalPages' => $matchTotalPages,
            'matchTotal' => $matchTotal
        ]);
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

        // Serverseitig ablehnen, dass ein Pferd sein eigener Elternteil wird -
        // die Absicherung existierte bisher nur clientseitig (#131).
        if ($childId > 0 && $childId === $parentHorseId) {
            header("Location: /admin/matches?error=self_link");
            exit;
        }

        // Geschlechts-Guard analog zur Selbst-Link-Sperre (#167): eine Stute kann
        // nicht als Vater, ein Hengst/Wallach nicht als Mutter verknüpft werden.
        if ($parentHorseId > 0 && in_array($parentType, ['sire', 'dam'], true)) {
            $mismatch = ($parentType === 'sire')
                ? $this->parentSexMismatch($parentHorseId, null)
                : $this->parentSexMismatch(null, $parentHorseId);
            if ($mismatch) {
                header("Location: /admin/matches?error=sex_mismatch");
                exit;
            }
        }

        if ($childId && $parentHorseId && in_array($parentType, ['sire', 'dam'])) {
            $db = Database::getInstance();

            // Fetch names for audit log
            $stmt = $db->prepare("SELECT name FROM horses WHERE id = ?");
            $stmt->execute([$childId]);
            $childName = $stmt->fetchColumn() ?: "Pferd #{$childId}";

            $stmt->execute([$parentHorseId]);
            $parentName = $stmt->fetchColumn() ?: "Pferd #{$parentHorseId}";

            // Rollen-Bezeichnung ohne Geschlechts-Behauptung (#167): das verknüpfte
            // Tier kann auch ohne hinterlegtes Geschlecht (NULL) gespeichert sein.
            $roleLabel = ($parentType === 'sire') ? 'Vater' : 'Mutter';

            if ($parentType === 'sire') {
                $stmt = $db->prepare("UPDATE horses SET sire_id = ?, sire_name = NULL, sire_ueln = NULL WHERE id = ?");
            } else {
                $stmt = $db->prepare("UPDATE horses SET dam_id = ?, dam_name = NULL, dam_ueln = NULL WHERE id = ?");
            }
            $stmt->execute([$parentHorseId, $childId]);

            \App\Service\AuditLogger::log(
                "Abstammung zusammengeführt",
                "horses",
                "Kind '{$childName}' (ID {$childId}) mit {$roleLabel} '{$parentName}' (ID {$parentHorseId}) verknüpft"
            );
        }

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
     * Gibt es die Zeile noch? (#317)
     *
     * Die Tabelle kommt ueber eine Positivliste in die Abfrage und nie aus
     * einem Aufrufwert - ein Tabellenname laesst sich nicht als Parameter
     * binden, und ein durchgereichter String waere genau die Stelle, an der
     * das eines Tages jemand tut.
     */
    private function rowExists(\PDO $db, string $table, int $id): bool {
        $tabelle = match ($table) {
            'persons' => 'persons',
            'breeding_stations' => 'breeding_stations',
        };
        $stmt = $db->prepare("SELECT 1 FROM `{$tabelle}` WHERE id = ?");
        $stmt->execute([$id]);
        return (bool)$stmt->fetchColumn();
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
        $snapshotSql = "SELECT person_id, role, breeding_station_id, breeding_station_text, origin_country, from_year, until_year
                        FROM horse_persons WHERE horse_id = ? ORDER BY id ASC";
        $stmt = $db->prepare($snapshotSql);
        $stmt->execute([$horseId]);
        $vorher = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $existingTexts = array_column($vorher, 'breeding_station_text');

        // DELETE und INSERTs gehoeren zusammen (#317).
        //
        // Ohne Klammer war das Loeschen bereits festgeschrieben, sobald ein
        // INSERT scheiterte - das Pferd stand danach ganz ohne Personen- und
        // Stationszuordnungen da, obwohl der Bearbeiter nur speichern wollte.
        // Der Ausloeser braucht keine Boshaftigkeit: Redakteur A hat das
        // Bearbeitungsformular offen, Admin B leert waehrenddessen den
        // Papierkorb (TrashController::emptyTrash() loescht Personen HART),
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

            $insertStmt = $db->prepare("INSERT INTO horse_persons (horse_id, person_id, role, breeding_station_id, breeding_station_text, origin_country, from_year, until_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

            $validRoles = ['breeder', 'owner', 'keeper'];

            $currentStationId = null;
            $currentStationText = null;
            $highestScore = -1;

            foreach ($personsData as $index => $item) {
                $personId = !empty($item['person_id']) ? (int)$item['person_id'] : null;
                $role = $item['role'] ?? 'owner';
                $stationId = !empty($item['breeding_station_id']) ? (int)$item['breeding_station_id'] : null;
                // Unbekannte IDs auf NULL statt in den Fremdschluessel laufen
                // lassen (#317). Die Auswahl im Formular ist beim Oeffnen der
                // Seite eingefroren; was dort stand, kann inzwischen hart
                // geloescht sein. Eine Zeile ohne Person ist ein Verlust, ein
                // abgebrochener Speichervorgang ohne JEDE Zuordnung waere ein
                // groesserer - und die Zeile faellt unten ohnehin weg, wenn ausser
                // der verwaisten ID nichts mehr in ihr steht.
                if ($personId !== null && !$this->rowExists($db, 'persons', $personId)) {
                    $personId = null;
                }
                if ($stationId !== null && !$this->rowExists($db, 'breeding_stations', $stationId)) {
                    $stationId = null;
                }
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
                // Gueltigkeitsregel unten. Freitext wie persons.country.
                $originCountry = trim((string)($item['origin_country'] ?? ''));
                if (mb_strlen($originCountry) > 100) {
                    $originCountry = mb_substr($originCountry, 0, 100);
                }

                // Breeders do not have a time period!
                if ($role === 'breeder') {
                    $fromYear = null;
                    $untilYear = null;
                } else {
                    $fromYear = !empty($item['from_year']) ? (int)$item['from_year'] : null;
                    $untilYear = !empty($item['until_year']) ? (int)$item['until_year'] : null;
                }

                // Calculate score to identify the current/latest active breeding station
                if ($stationId || $stationText) {
                    // If until_year IS NULL, the entry is currently active -> boost score with 99999 + from_year
                    $calcFrom = $fromYear ?: 0;
                    $score = ($untilYear === null && $role !== 'breeder') ? (99999 + $calcFrom) : ($untilYear ?: $calcFrom);

                    if ($score >= $highestScore) {
                        $highestScore = $score;
                        if ($stationId) {
                            $currentStationId = $stationId;
                            $stStmt = $db->prepare("SELECT name FROM breeding_stations WHERE id = ?");
                            $stStmt->execute([$stationId]);
                            $currentStationText = $stStmt->fetchColumn() ?: null;
                        } else {
                            $currentStationId = null;
                            $currentStationText = $stationText;
                        }
                    }
                }

                // Validation: Row must have a valid Role/Type AND at least one of Person OR Breeding Station (2 of the fields)
                $hasPerson = !empty($personId);
                $hasStation = !empty($stationId) || !empty($stationText);
                // Dritte Alternative (#294): Ist die Person unbekannt, aber ihre
                // Herkunft bekannt, ist das eine vollwertige Aussage - und der
                // einzige Weg, sie OHNE eine Platzhalter-Person in der PII-Tabelle
                // persons festzuhalten.
                $hasOrigin = $originCountry !== '';
                $hasValidRole = in_array($role, $validRoles, true);

                if ($hasValidRole && ($hasPerson || $hasStation || $hasOrigin)) {
                    $insertStmt->execute([$horseId, $personId ?: null, $role, $stationId ?: null, $stationText ?: null, $originCountry ?: null, $fromYear, $untilYear]);
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
        if ($currentStationId !== null || ($currentStationText ?? '') !== '') {
            $syncStmt = $db->prepare("UPDATE horses SET breeding_station_id = ?, breeding_station = ? WHERE id = ?");
            $syncStmt->execute([$currentStationId, $currentStationText, $horseId]);
        }
    }
}
