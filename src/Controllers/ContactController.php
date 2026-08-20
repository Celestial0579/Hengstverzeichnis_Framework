<?php
// src/Controllers/ContactController.php

namespace App\Controllers;

use App\Database;

/**
 * Verwaltung der Kontaktliste (#336).
 *
 * Entstanden aus PersonController und BreedingStationController, die bis v0.7
 * fast deckungsgleich waren - zwei Tabellen, zwei Formulare, zwei Listen fuer
 * denselben Vorgang. Wo sie sich unterschieden, gewinnt hier bewusst die
 * strengere Fassung; die Abweichungen sind an Ort und Stelle begruendet.
 *
 * Der Datenschutz-Teil des Umbaus betrifft die OEFFENTLICHEN Pfade
 * (PublicController), nicht diesen Controller: Wer hier ist, ist angemeldet
 * und hat 'contacts.view'. Die Verwaltung sieht deshalb weiterhin den ganzen
 * Datensatz - auch contact_info, das oeffentlich nie erscheinen darf (siehe
 * docs/kontaktliste-umstellung.md). Das Verbot von "SELECT *" gilt fuer den
 * oeffentlichen Bereich, wo ein zu breites SELECT die naechste Ausgabe
 * versehentlich mit PII fuellt - das ist die Lehre aus #293.
 */
class ContactController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
    }

    /** Zeilen je Seite der Verwaltungsliste, wie bei den Pferden. */
    public const PER_PAGE = 50;

    /**
     * Suchparameter der Kontaktliste - zugleich die Weißliste für
     * Blätter-Links und den Redirect nach einer Bulk-Aktion.
     *
     * Die Liste ist die VEREINIGUNG der beiden alten Suchmasken: `q_contact`
     * (Ansprechpartner) konnten bisher nur die Stationen, `q_email`,
     * `q_membership` und `q_breeder_only` nur die Personen. Nach dem
     * Zusammenlegen stehen beide Bestände in einer Liste - wer eine der
     * Möglichkeiten wegließe, nähme genau der Hälfte der Datensätze ihre
     * Suche weg.
     *
     * Dazu zwei Filter, die es vorher nicht geben konnte: `q_contact_public`
     * (siehe unten - die Freigabe ist seit v0.8 der einzige Schutz und muss
     * deshalb prüfbar sein) und `q_origin` (Personen- bzw. Stationsbestand,
     * über contact_id_map).
     *
     * @var array<int, string>
     */
    public const FILTER_KEYS = [
        'search', 'q_name', 'q_contact', 'q_city', 'q_postal_code', 'q_state',
        'q_country', 'q_email', 'q_membership', 'q_breeder_only',
        'q_contact_public', 'q_origin',
    ];

    public function index(): void {
        $this->requirePermission('contacts', 'view');

        // Optionaler Veröffentlichungs-Filter (?published=1|0). Ohne Parameter werden
        // alle Kontakte angezeigt; nur die exakten Werte '1'/'0' filtern, alles andere
        // wird als "alle" behandelt.
        $publishedFilter = self::normalizePublishedFilter($_GET['published'] ?? null);

        // Die Verwaltung sieht unveröffentlichte Kontakte - anders als der
        // öffentliche Bereich, wo genau das ein Existenz-Orakel wäre (#121/#151).
        // Gelöschte bleiben auch hier draußen, die stehen im Papierkorb.
        $filters = self::readListFilters(self::FILTER_KEYS);

        $where = ['c.deleted_at IS NULL'];
        $params = [];

        if ($publishedFilter !== null) {
            $where[] = 'c.is_published = ?';
            $params[] = $publishedFilter;
        }

        // Allgemeiner Begriff quer über alle Textfelder, die einen Kontakt
        // identifizieren - inklusive des Freitext-Restfelds contact_info und
        // der alten Freitext-Anschrift address. In der Verwaltung ist genau
        // das oft der einzige Ort, an dem eine Telefonnummer oder ein Hinweis
        // steht; im öffentlichen Bereich wird beides nie durchsucht.
        $search = $filters['search'] ?? '';
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = "(
                c.name LIKE ? OR
                c.contact_person LIKE ? OR
                c.city LIKE ? OR
                c.postal_code LIKE ? OR
                c.state LIKE ? OR
                c.country LIKE ? OR
                c.street LIKE ? OR
                c.address LIKE ? OR
                c.email LIKE ? OR
                c.phone LIKE ? OR
                c.mobile LIKE ? OR
                c.membership_status LIKE ? OR
                c.contact_info LIKE ?
            )";
            array_push(
                $params,
                $like, $like, $like, $like, $like, $like, $like,
                $like, $like, $like, $like, $like, $like
            );
        }

        foreach ([
            'q_name' => 'c.name',
            'q_contact' => 'c.contact_person',
            'q_city' => 'c.city',
            'q_postal_code' => 'c.postal_code',
            'q_state' => 'c.state',
            'q_country' => 'c.country',
            'q_email' => 'c.email',
            'q_membership' => 'c.membership_status',
        ] as $key => $column) {
            $value = $filters[$key] ?? '';
            if ($value !== '') {
                $where[] = "{$column} LIKE ?";
                $params[] = '%' . $value . '%';
            }
        }

        // "Nur Züchter" liest das redaktionell gepflegte Kennzeichen
        // contacts.is_breeder - ausdrücklich NICHT die Zuordnungen mit
        // role='breeder' (siehe database/schema.sql, das sind verschiedene
        // Aussagen). Nur der Wert '1' zählt; alles andere gilt als nicht
        // gesetzt und wird auch nicht in Links weitergetragen.
        if (($filters['q_breeder_only'] ?? '') === '1') {
            $where[] = 'c.is_breeder = 1';
        } else {
            unset($filters['q_breeder_only']);
        }

        // Freigabe der Kontaktdaten als eigener Filter: Seit die Trennung der
        // Tabellen weggefallen ist, ist contact_public der einzige Schutz je
        // Datensatz - dann muss die Redaktion auch nachsehen können, für wen
        // er gesetzt ist. Ohne diesen Filter bliebe die Frage "wessen Telefon
        // steht öffentlich im Netz?" nur über die Datenbank beantwortbar.
        $contactPublicFilter = self::normalizePublishedFilter($filters['q_contact_public'] ?? null);
        if ($contactPublicFilter !== null) {
            $where[] = 'c.contact_public = ?';
            $params[] = $contactPublicFilter;
        } else {
            unset($filters['q_contact_public']);
        }

        // Herkunft aus contact_id_map: Vor dem Zusammenlegen waren "Personen"
        // und "Deckstationen" zwei getrennte Listen. Der Filter gibt diese
        // Sicht zurück, ohne die Trennung wieder in die Daten zu schreiben.
        // Bewusst über die Abbildungstabelle (die laut
        // docs/kontaktliste-umstellung.md dauerhaft bleibt) statt über ein
        // neues Typ-Feld: Ein Kontakt IST nach dem Umbau beides zugleich
        // (eine Station mit Ansprechpartner, ein Züchter mit eigenem Hof),
        // und ein Typ-Feld müsste sich für eine Seite entscheiden. Nach dem
        // Umbau neu angelegte Kontakte haben keine Herkunft und erscheinen
        // nur unter "Alle" - die View sagt das dazu.
        $origin = $filters['q_origin'] ?? '';
        if ($origin === 'person' || $origin === 'station') {
            $where[] = 'EXISTS (SELECT 1 FROM contact_id_map m WHERE m.contact_id = c.id AND m.old_type = ?)';
            $params[] = $origin;
        } else {
            unset($filters['q_origin']);
        }

        $whereSql = implode(' AND ', $where);
        $db = Database::getInstance();

        $countStmt = $db->prepare("SELECT COUNT(*) FROM contacts c WHERE {$whereSql}");
        $countStmt->execute($params);
        $totalContacts = (int)$countStmt->fetchColumn();

        $totalPages = max(1, (int)ceil($totalContacts / self::PER_PAGE));
        $page = min(self::requestInt('page', 1, 1), $totalPages);
        $offset = ($page - 1) * self::PER_PAGE;

        // Die beiden Zahlen als Unterabfragen statt über GROUP BY: Mit
        // LIMIT/OFFSET müsste sonst erst gruppiert und dann geschnitten
        // werden, und die COUNT(*)-Abfrage oben zählte Gruppen statt Zeilen.
        //
        // Geloeschte Pferde zaehlen in keiner der beiden mit: Die Zahl neben
        // dem Datensatz war bisher eine Obermenge der tatsaechlich sichtbaren
        // Zuordnungen (#296, Nebenbefund).
        //
        // ZWEI Zahlen, weil ein Kontakt zwei Rollen haben kann (#336): die
        // Zuordnungen, in denen er Züchter/Besitzer/Halter IST
        // (horse_persons.contact_id), und die Pferde, die AN IHM als
        // Deckstation hängen (horses.breeding_station_id). Die alte
        // Stationsliste zeigte nur die zweite, die alte Personenliste nur die
        // erste - beide wegzulassen hieße, der jeweiligen Hälfte des Bestands
        // ihre Kennzahl zu nehmen.
        $stmt = $db->prepare("
            SELECT c.*, (
                SELECT COUNT(*)
                FROM horse_persons hp
                JOIN horses h ON h.id = hp.horse_id AND h.deleted_at IS NULL
                WHERE hp.contact_id = c.id
            ) AS horse_count, (
                SELECT COUNT(*)
                FROM horses hs
                WHERE hs.breeding_station_id = c.id AND hs.deleted_at IS NULL
            ) AS station_horse_count
            FROM contacts c
            WHERE {$whereSql}
            ORDER BY c.name ASC
            LIMIT ? OFFSET ?
        ");
        $index = 1;
        foreach ($params as $value) {
            $stmt->bindValue($index++, $value);
        }
        $stmt->bindValue($index++, self::PER_PAGE, \PDO::PARAM_INT);
        $stmt->bindValue($index, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $contacts = $stmt->fetchAll();

        $countries = $db->query("SELECT DISTINCT country FROM contacts WHERE country IS NOT NULL AND country != '' AND deleted_at IS NULL ORDER BY country ASC")->fetchAll(\PDO::FETCH_COLUMN);
        $memberships = $db->query("SELECT DISTINCT membership_status FROM contacts WHERE membership_status IS NOT NULL AND membership_status != '' AND deleted_at IS NULL ORDER BY membership_status ASC")->fetchAll(\PDO::FETCH_COLUMN);

        $this->render('admin_contacts', [
            'title' => 'Kontakte verwalten',
            'contacts' => $contacts,
            'publishedFilter' => $publishedFilter,
            'filters' => $filters,
            'hasActiveFilters' => $filters !== [],
            'countries' => $countries,
            'memberships' => $memberships,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalContacts,
            'perPage' => self::PER_PAGE,
            // Ergebniszahlen des Zusammenfuehrens (siehe merge()), als geprüfte
            // Ganzzahlen. Sie stehen im Redirect und damit in der Anfrage - die
            // Bereinigung gehoert deshalb hierher und nicht in die View: Ein
            // Cast mitten in einer Ausgabe ist fuer eine statische Analyse
            // keine erkennbare Bereinigung, siehe requestInt().
            'mergeReport' => [
                self::requestInt('merged_moved', 0, 0),
                self::requestInt('merged_dropped', 0, 0),
                self::requestInt('merged_filled', 0, 0),
                self::requestInt('merged_stations', 0, 0),
            ],
            'canCreate' => $this->hasPermission('contacts', 'create'),
            'canEdit' => $this->hasPermission('contacts', 'edit'),
            'canDelete' => $this->hasPermission('contacts', 'delete'),
            'canPublish' => $this->hasPermission('contacts', 'publish')
        ]);
    }

    /**
     * Massen-Veröffentlichung / -Depublikation der ausgewählten Kontakte. Nur mit
     * 'contacts.publish' erlaubt; setzt is_published für alle übergebenen IDs.
     *
     * Betrifft ausdrücklich NUR is_published, nicht contact_public: Ob ein
     * Datensatz überhaupt öffentlich erreichbar ist, ist eine andere Frage als
     * ob seine Telefonnummer dabei mitkommt. Eine Massenaktion über die
     * Kontaktfreigabe gibt es bewusst nicht - die gehört je Datensatz
     * entschieden (#293).
     */
    public function bulkPublish(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('contacts', 'publish');

        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($id) => $id > 0));
        $publish = !empty($_POST['publish']) ? 1 : 0;

        if ($ids) {
            $db = Database::getInstance();
            // Einzelne, vollständig parametrisierte UPDATEs statt einer dynamisch
            // zusammengesetzten IN (...)-Liste - inhaltlich identisch, vermeidet aber
            // jede String-Interpolation im SQL (auch die des ?-Platzhalter-Strings).
            $stmt = $db->prepare("UPDATE contacts SET is_published = ? WHERE id = ? AND deleted_at IS NULL");
            foreach ($ids as $id) {
                $stmt->execute([$publish, $id]);
            }

            \App\Service\AuditLogger::log(
                $publish ? "Kontakte veröffentlicht" : "Veröffentlichung von Kontakten zurückgenommen",
                "contacts",
                count($ids) . " Datensätze (IDs: " . implode(', ', $ids) . ")"
            );
        }

        // Zurück zur Liste, wie der Benutzer sie verlassen hat (Suche + Seite),
        // siehe partials/publish_bulk_bar.php.
        header("Location: /admin/contacts?success=published"
            . self::publishedFilterQuery($_POST['published'] ?? null)
            . self::listFilterQuery($_POST, [...self::FILTER_KEYS, 'page']));
        exit;
    }

    public function create(): void {
        $this->requirePermission('contacts', 'create');

        $this->render('admin_contact_form', [
            'title' => 'Neuen Kontakt anlegen',
            'contact' => null,
            'canPublish' => $this->hasPermission('contacts', 'publish')
        ]);
    }

    /**
     * Alle Textspalten eines Kontakts in Spaltenreihenfolge - die Vereinigung
     * der bisherigen Personen- (#188, `state` seit #256) und Stationsfelder.
     * Einzige Stelle, an der die Liste steht: INSERT, UPDATE, Fehlerpfad und
     * das Einlesen aus dem POST leiten sich daraus ab. Vorher stand sie in
     * jedem der beiden Controller mehrfach ausgeschrieben - ein neues Feld an
     * nur zwei von drei Stellen zu ergänzen hätte still funktioniert und beim
     * Speichern Daten verloren.
     *
     * `name` fehlt bewusst (NOT NULL, eigene Prüfung), ebenso die drei
     * Schalter is_breeder / contact_public / is_published: Sie sind keine
     * Freitexte und werden je einzeln entschieden.
     */
    private const CONTACT_FIELDS = [
        'contact_person', 'contact_info', 'street', 'house_number', 'postal_code',
        'city', 'state', 'country', 'address', 'email', 'phone', 'mobile',
        'website', 'membership_status',
    ];

    /**
     * Der Name aus dem POST. Eigene Methode, weil die Spalte als einzige NOT
     * NULL ist und deshalb nicht in CONTACT_FIELDS gehoert. Ein Nicht-String
     * (etwa ?name[]=x) gilt als leer und laeuft damit in die Pflichtfeld-
     * Meldung, statt in einen TypeError mitten im Speichern.
     */
    private function parseName(): string {
        $name = $_POST['name'] ?? '';
        return is_string($name) ? trim($name) : '';
    }

    /**
     * Kontaktfelder aus dem POST, jeweils leer -> NULL. Bewusst ohne
     * Formatvalidierung (Freitext-Philosophie wie bei breed; auch die
     * E-Mail-Adresse einer Deckstation wurde nie geprüft). Das gilt auch für
     * `state`: Bundesland- und Kantonsnamen sind in DACH uneinheitlich
     * geschrieben, eine ISO-3166-2-Prüfung würde mehr korrekte Eingaben
     * ablehnen als falsche.
     *
     * @return array<string, string|null>
     */
    private function parseContactFields(): array {
        $fields = [];
        foreach (self::CONTACT_FIELDS as $field) {
            $value = $_POST[$field] ?? '';
            // Nicht-Strings (etwa ?street[]=x) gelten als nicht gesetzt, statt
            // in einen TypeError zu laufen - dieselbe Haltung wie in
            // BaseController::readListFilters().
            $value = is_string($value) ? trim($value) : '';
            // Vergleich gegen '' statt ?:-Kurzschluss: "0" ist eine gültige
            // Hausnummer und darf nicht als leer gelten.
            $fields[$field] = $value !== '' ? $value : null;
        }
        return $fields;
    }

    /**
     * Prüft die Pflicht- und Formatregeln des Formulars.
     *
     * Die Website-Prüfung stammt aus dem Stationsformular; die Personen kannten
     * sie nicht. Beim Zusammenlegen gewinnt die strengere Fassung: Eine Adresse
     * ohne http:// wird von App\Helper\ExternalUrl ohnehin nicht verlinkt, sie
     * stünde also gespeichert, aber wirkungslos da - und niemand erführe warum.
     *
     * @return array<int, string> Leere Liste = in Ordnung.
     */
    private function validateContact(string $name, ?string $website): array {
        $errors = [];
        if ($name === '') {
            $errors[] = "Der Name des Kontakts ist erforderlich.";
        }
        $website = (string)$website;
        if ($website !== '' && !str_starts_with($website, 'http://') && !str_starts_with($website, 'https://')) {
            $errors[] = "Website muss eine gültige Adresse beginnend mit http:// oder https:// sein.";
        }
        return $errors;
    }

    /**
     * Löst einen Kontakt-Hook aus - und zusätzlich die alten Namen `person.*`
     * und `station.*` mit denselben Argumenten (#347).
     *
     * Grund: Ein Addon, das sich bis v0.7 an `person.…`/`station.…` gehängt
     * hat, läuft in v0.8 unverändert weiter. Die Aliasse entfallen in v0.9.0,
     * so steht es in docs/plugin-development.md und
     * docs/kontaktliste-umstellung.md. Für Filter gilt dieselbe Reihenfolge,
     * dort aber kaskadierend - siehe edit().
     */
    private function doContactAction(string $event, mixed ...$args): void {
        foreach (['contact.', 'person.', 'station.'] as $prefix) {
            $this->hooks()->doAction($prefix . $event, ...$args);
        }
    }

    public function store(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('contacts', 'create');

        $name = $this->parseName();
        $fields = $this->parseContactFields();

        $errors = $this->validateContact($name, $fields['website']);
        if ($errors !== []) {
            $this->render('admin_contact_form', [
                'title' => 'Neuen Kontakt anlegen',
                'contact' => null,
                'errors' => $errors,
                'old' => $_POST,
                'canPublish' => $this->hasPermission('contacts', 'publish')
            ]);
            return;
        }

        // Veröffentlichung (öffentliche Sichtbarkeit) nur mit 'contacts.publish' und
        // nur bei explizit angehakter Checkbox - andernfalls unveröffentlicht (analog
        // HorseController::store()).
        $isPublished = (!empty($_POST['is_published']) && $this->hasPermission('contacts', 'publish')) ? 1 : 0;
        // is_breeder ist ein Schalter, kein Freitext, und die Spalte ist NOT
        // NULL - deshalb neben CONTACT_FIELDS gefuehrt wie is_published.
        $isBreeder = !empty($_POST['is_breeder']) ? 1 : 0;
        // Freigabe der Kontaktdaten: Vorgabe 0, es muss aktiv angehakt werden.
        //
        // Die Stationen hatten hier bis v0.7 die Vorgabe 1 (Geschäftsadresse).
        // Sie faellt weg, und zwar nicht aus Bequemlichkeit: Personen und
        // Stationen stehen jetzt in EINER Tabelle, mit EINEM Formular. Eine
        // vorbelegte Freigabe wuerde die naechste Privatperson, die jemand
        // ueber dieses Formular anlegt, mit Telefonnummer ins Netz stellen -
        // still, weil ein vorbelegtes Haekchen niemandem auffaellt. Der
        // Bestand der Stationen behaelt seinen Wert, die Migration nimmt ihn
        // zeilenweise mit (siehe database/schema.sql).
        $contactPublic = !empty($_POST['contact_public']) ? 1 : 0;

        $db = Database::getInstance();
        $columns = implode(', ', self::CONTACT_FIELDS);
        $placeholders = implode(', ', array_fill(0, count(self::CONTACT_FIELDS), '?'));
        $stmt = $db->prepare("INSERT INTO contacts (name, {$columns}, is_breeder, contact_public, is_published) VALUES (?, {$placeholders}, ?, ?, ?)");
        $stmt->execute([$name, ...array_values($fields), $isBreeder, $contactPublic, $isPublished]);
        $newContactId = (int)$db->lastInsertId();

        \App\Service\AuditLogger::log("Kontakt angelegt", "contacts", "Kontakt ID {$newContactId}: {$name}");

        // Muster wie horse.after_save: erst speichern, dann melden - ein Addon
        // soll den fertigen Datensatz vorfinden, nicht einen halben.
        $this->doContactAction('after_save', $newContactId, $_POST, true);

        header("Location: /admin/contacts?success=created");
        exit;
    }

    public function edit(): void {
        $this->requirePermission('contacts', 'edit');

        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            header("Location: /admin/contacts");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM contacts WHERE id = ?");
        $stmt->execute([$id]);
        $contact = $stmt->fetch();

        if (!$contact) {
            header("Location: /admin/contacts");
            exit;
        }

        // Weich geloeschte Kontakte werden bewusst WEITER ANGEZEIGT, aber nicht
        // mehr gespeichert (#296). Ein Filter schon hier waere der naheliegende,
        // aber falsche Fix: admin_gdpr.php verlinkt genau auf diese Route, und
        // die DSGVO-Suche nimmt geloeschte Treffer ausdruecklich mit (siehe
        // GdprController: wer Loeschung verlangt, hat Anspruch auch auf weich
        // geloeschte Daten). Wer den Datensatz nicht mehr oeffnen kann, kann
        // auch nicht pruefen, was noch drinsteht - genau die Luecke, die dort
        // vermieden werden soll. Das Schreiben verhindert update().
        //
        // Erweiterungspunkt fuer Addons, Muster: horse.edit_sections (#255).
        // Feuert nur beim BEARBEITEN, nicht beim Anlegen - ein Addon braucht
        // eine ID, an der es seine Daten festmachen kann. Damit koennen Addons
        // eigene Felder am Datensatz pflegen (etwa ein Kontaktanfragen-Opt-out),
        // ohne dass der Kern eine Spalte dafuer mitbringen muss.
        //
        // Die beiden alten Namen laufen KASKADIEREND hinterher (#347): erst
        // contact.*, dann person.*, dann station.*, jedes auf dem Ergebnis des
        // vorherigen. Anders als bei einer Action ist die Reihenfolge hier
        // wesentlich - wer $sections zurueckgibt, muss die Abschnitte der
        // vorherigen Fassung mitbekommen, sonst wirft der zweite Hook die
        // Arbeit des ersten weg. So steht es in
        // docs/kontaktliste-umstellung.md; ab v0.9.0 bleibt nur contact.*.
        $pluginEditSections = $this->hooks()->applyFilters('contact.edit_sections', [], $contact);
        $pluginEditSections = $this->hooks()->applyFilters('person.edit_sections', $pluginEditSections, $contact);
        $pluginEditSections = $this->hooks()->applyFilters('station.edit_sections', $pluginEditSections, $contact);

        $this->render('admin_contact_form', [
            'title' => 'Kontakt bearbeiten',
            'contact' => $contact,
            'isDeleted' => ($contact['deleted_at'] ?? null) !== null,
            'pluginEditSections' => $pluginEditSections,
            'canPublish' => $this->hasPermission('contacts', 'publish')
        ]);
    }

    public function update(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('contacts', 'edit');

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            header("Location: /admin/contacts");
            exit;
        }

        $name = $this->parseName();
        $fields = $this->parseContactFields();

        // is_breeder (#293) und contact_public sind Schalter, keine Freitexte,
        // und beide Spalten sind NOT NULL - deshalb neben CONTACT_FIELDS
        // gefuehrt wie is_published.
        $isBreeder = !empty($_POST['is_breeder']) ? 1 : 0;
        $contactPublic = !empty($_POST['contact_public']) ? 1 : 0;

        $errors = $this->validateContact($name, $fields['website']);
        if ($errors !== []) {
            // Fehlerpfad baut den Datensatz von Hand - alle Felder mitgeben,
            // sonst gehen die Eingaben beim erneuten Rendern verloren. Das gilt
            // auch fuer die drei Haekchen: Sonst verlöre ein Validierungsfehler
            // eine gerade getroffene Entscheidung, ohne es zu sagen.
            $this->render('admin_contact_form', [
                'title' => 'Kontakt bearbeiten',
                'contact' => array_merge(
                    [
                        'id' => $id,
                        'name' => $name,
                        'is_published' => !empty($_POST['is_published']) ? 1 : 0,
                        'is_breeder' => $isBreeder,
                        'contact_public' => $contactPublic,
                    ],
                    $fields
                ),
                'errors' => $errors,
                'canPublish' => $this->hasPermission('contacts', 'publish')
            ]);
            return;
        }

        // Veröffentlichung nur mit 'contacts.publish' änderbar; ohne das Recht bleibt der
        // bisherige Zustand erhalten (ein übermittelter Wunsch wird ignoriert, analog
        // HorseController::update()).
        $setSql = implode(', ', array_map(static fn(string $f): string => "{$f} = ?", self::CONTACT_FIELDS));
        $values = array_values($fields);
        $db = Database::getInstance();
        // Schreibschutz fuer den Papierkorb (#296): Ein Datensatz, der aus der
        // Oberflaeche verschwunden ist, darf nicht ueber einen alten Link oder
        // ein Lesezeichen weiter bearbeitet werden - er bliebe geloescht und
        // bekaeme trotzdem neue Werte, ohne dass jemand es merkt. Dasselbe
        // Muster wie beim Bulk-Publish darueber.
        if ($this->hasPermission('contacts', 'publish')) {
            $isPublished = !empty($_POST['is_published']) ? 1 : 0;
            $stmt = $db->prepare("UPDATE contacts SET name = ?, {$setSql}, is_breeder = ?, contact_public = ?, is_published = ? WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$name, ...$values, $isBreeder, $contactPublic, $isPublished, $id]);
        } else {
            $stmt = $db->prepare("UPDATE contacts SET name = ?, {$setSql}, is_breeder = ?, contact_public = ? WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$name, ...$values, $isBreeder, $contactPublic, $id]);
        }

        // Keine betroffene Zeile heisst: Der Datensatz liegt im Papierkorb.
        // Nicht als Erfolg melden - sonst glaubt der Bearbeiter, gespeichert zu
        // haben.
        if ($stmt->rowCount() === 0) {
            $stillDeleted = $db->prepare("SELECT 1 FROM contacts WHERE id = ? AND deleted_at IS NOT NULL");
            $stillDeleted->execute([$id]);
            if ($stillDeleted->fetchColumn()) {
                header("Location: /admin/contacts?error=deleted");
                exit;
            }
        }

        \App\Service\AuditLogger::log("Kontakt aktualisiert", "contacts", "Kontakt ID {$id}: {$name}");

        $this->doContactAction('after_save', $id, $_POST, false);

        header("Location: /admin/contacts?success=updated");
        exit;
    }

    /**
     * Hoechstzahl der im Merge-Formular angebotenen Ziel-Kontakte (#312).
     * Wie GdprController::SEARCH_LIMIT: ein Auswahlfeld ist kein Ort fuer
     * einen vollstaendigen Kontaktbestand - wer mehr braucht, sucht.
     */
    public const MERGE_CANDIDATE_LIMIT = 50;

    /**
     * Felder, die beim Zusammenfuehren aus dem aufgegebenen Datensatz
     * uebernommen werden, sofern das Ziel dort nichts stehen hat (NULL-Fill).
     * Bewusst nur auffuellen und nie ueberschreiben: Das Ziel ist der
     * Datensatz, den der Bearbeiter behalten will.
     *
     * Dass hier alle Textfelder stehen, ist Absicht - `contact_person` und
     * `address` kamen mit den Stationen dazu und wuerden sonst beim Aufgeben
     * eines Stationsdatensatzes verschwinden.
     */
    private const MERGE_FILL_FIELDS = self::CONTACT_FIELDS;

    /**
     * Vorschau: zeigt Quelle, moegliche Ziele und die betroffenen Zuordnungen,
     * bevor irgendetwas passiert (#297).
     */
    public function mergeForm(): void {
        $this->requirePermission('contacts', 'edit');

        $id = (int)($_GET['id'] ?? 0);
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM contacts WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $source = $stmt->fetch();
        if (!$source) {
            header("Location: /admin/contacts");
            exit;
        }

        $stmt = $db->prepare(
            "SELECT hp.role, hp.from_year, hp.until_year, h.name AS horse_name, h.id AS horse_id
             FROM horse_persons hp
             JOIN horses h ON h.id = hp.horse_id
             WHERE hp.contact_id = ? ORDER BY h.name ASC"
        );
        $stmt->execute([$id]);
        $assignments = $stmt->fetchAll();

        // Der zweite Steckplatz (#336): Zeilen und Pferde, die diesen Kontakt
        // als DECKSTATION nennen. Sie ziehen beim Zusammenfuehren mit um, und
        // wer das nicht vorher sieht, haelt die Vorschau fuer vollstaendig.
        $stmt = $db->prepare(
            "SELECT h.id AS horse_id, h.name AS horse_name
             FROM horse_persons hp
             JOIN horses h ON h.id = hp.horse_id
             WHERE hp.station_contact_id = ?
             UNION
             SELECT h.id AS horse_id, h.name AS horse_name
             FROM horses h
             WHERE h.breeding_station_id = ?
             ORDER BY horse_name ASC"
        );
        $stmt->execute([$id, $id]);
        $stationUses = $stmt->fetchAll();

        // Kandidaten gedeckelt und durchsuchbar statt Gesamtbestand (#312):
        // Ohne LIMIT wurde jeder Kontakt des Bestands zu einem <option> - bei
        // 20.000 Datensaetzen rund 1,1 MB Markup und ein Auswahlfeld, das
        // mobile Browser sekundenlang aufbauen. Dasselbe Muster wie in der
        // Kontaktliste (#306) und im GdprController (#266).
        //
        // Eine Zeile mehr holen als angezeigt wird: nur so laesst sich
        // ehrlich sagen, ob die Liste abgeschnitten ist. Eine gedeckelte
        // Liste, die sich als vollstaendig ausgibt, ist schlimmer als eine
        // lange - wer sein Ziel nicht findet, haelt es fuer nicht vorhanden.
        $suche = trim((string)($_GET['q'] ?? ''));
        $bedingungen = ['id <> ?', 'deleted_at IS NULL'];
        $werte = [$id];
        if ($suche !== '') {
            $like = '%' . $suche . '%';
            // contact_person mit in der Suche: Bei einer Station steht der
            // gesuchte Name oft dort und nicht in `name`.
            $bedingungen[] = '(name LIKE ? OR contact_person LIKE ? OR city LIKE ? OR postal_code LIKE ?)';
            array_push($werte, $like, $like, $like, $like);
        }
        $stmt = $db->prepare(
            "SELECT id, name, contact_person, city, postal_code FROM contacts
             WHERE " . implode(' AND ', $bedingungen) . "
             ORDER BY name ASC LIMIT " . (self::MERGE_CANDIDATE_LIMIT + 1)
        );
        $stmt->execute($werte);
        $candidates = $stmt->fetchAll();
        $abgeschnitten = count($candidates) > self::MERGE_CANDIDATE_LIMIT;
        if ($abgeschnitten) {
            array_pop($candidates);
        }

        $this->render('admin_contact_merge', [
            'title' => 'Kontakte zusammenführen',
            'source' => $source,
            'assignments' => $assignments,
            'stationUses' => $stationUses,
            'candidates' => $candidates,
            'search' => $suche,
            'truncated' => $abgeschnitten,
            'candidateLimit' => self::MERGE_CANDIDATE_LIMIT,
        ]);
    }

    /**
     * Fuehrt zwei Kontaktdatensaetze zusammen (#297).
     *
     * Die REIHENFOLGE ist der Kern und nicht beliebig: erst die Zuordnungen
     * umhaengen, DANN die Quelle in den Papierkorb. Andersherum waere es ein
     * stiller Datenverlust - horse_persons.contact_id traegt ON DELETE CASCADE,
     * und TrashController::emptyTrash() loescht Datensaetze im Papierkorb HART.
     * Ein weich geloeschter Kontakt mit noch daran haengenden Zuordnungen
     * verliert diese also beim naechsten Leeren des Papierkorbs, lautlos.
     *
     * Genau deshalb gibt es diese Aktion ueberhaupt: Wer das von Hand nachbaut
     * (Kontakt loeschen, Pferde neu zuordnen), verliert die Zuordnungen dazwischen.
     *
     * Seit #336 haengt an einem Kontakt ein ZWEITER Steckplatz: Er kann
     * Deckstation sein (horse_persons.station_contact_id, horses.breeding_station_id).
     * Der wandert mit - sonst zeigten Pferde nach dem Zusammenfuehren auf einen
     * Datensatz im Papierkorb, und beim Leeren waere die Stationsangabe weg
     * (horses.breeding_station_id) bzw. auf NULL gesetzt (station_contact_id).
     */
    public function merge(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        // Zusammenfuehren legt einen Datensatz still - deshalb zusaetzlich das
        // Loeschrecht, nicht nur das Bearbeitungsrecht.
        $this->requirePermission('contacts', 'edit');
        $this->requirePermission('contacts', 'delete');

        $sourceId = (int)($_POST['source_id'] ?? 0);
        $targetId = (int)($_POST['target_id'] ?? 0);

        if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
            header("Location: /admin/contacts?error=merge_invalid");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM contacts WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$sourceId]);
        $source = $stmt->fetch();
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();

        if (!$source || !$target) {
            header("Location: /admin/contacts?error=merge_invalid");
            exit;
        }

        $db->beginTransaction();
        try {
            // 1. Zuordnungen umhaengen - aber keine exakten Doppel erzeugen.
            //    Gleiches Pferd, gleiche Rolle, gleicher Zeitraum UND gleiche
            //    Fachangaben sind dieselbe Aussage; alles andere ist echte
            //    Historie und muss erhalten bleiben.
            //
            //    Die drei Fachspalten gehoeren zwingend in den Vergleich
            //    (#310): Schritt 2 loescht hart, was hier stehenbleibt. Ohne
            //    sie galt eine Zeile mit Deckstation oder Herkunftsland als
            //    "exaktes Doppel" einer leeren Zeile und war danach
            //    unwiederbringlich weg - und bei role='breeder' trifft das
            //    zwangslaeufig zu, weil HorseController::saveHorsePersons()
            //    from_year/until_year dort hart auf NULL setzt. Der Merge
            //    verspricht ausdruecklich, nie zu ueberschreiben, sondern nur
            //    aufzufuellen; das ist die Stelle, an der das Versprechen
            //    einzuloesen ist.
            //
            //    <=> statt = ist wesentlich: Die Spalten sind NULL-faehig, und
            //    NULL = NULL waere NULL, also nie wahr - jede Zeile ohne
            //    Angabe gaelte als verschieden.
            //
            //    Verglichen wird der Stand VOR Schritt 3, die Stationskennung
            //    also noch unveraendert. Das kann eine Zeile als "verschieden"
            //    einstufen, die nach dem Umhaengen gleich aussaehe - dann
            //    bleiben beide erhalten. Der Fehler faellt damit auf die
            //    Seite des Behaltens, und nur die ist verzeihlich: Schritt 2
            //    loescht hart.
            $stmt = $db->prepare(
                "UPDATE horse_persons AS quelle
                 SET contact_id = ?
                 WHERE contact_id = ?
                   AND NOT EXISTS (
                       SELECT 1 FROM (SELECT * FROM horse_persons) AS ziel
                       WHERE ziel.contact_id = ?
                         AND ziel.horse_id = quelle.horse_id
                         AND ziel.role = quelle.role
                         AND (ziel.from_year <=> quelle.from_year)
                         AND (ziel.until_year <=> quelle.until_year)
                         AND (ziel.station_contact_id <=> quelle.station_contact_id)
                         AND (ziel.breeding_station_text <=> quelle.breeding_station_text)
                         AND (ziel.origin_country <=> quelle.origin_country)
                   )"
            );
            $stmt->execute([$targetId, $sourceId, $targetId]);
            $umgehaengt = $stmt->rowCount();

            // 2. Was jetzt noch an der Quelle haengt, ist ein exaktes Doppel -
            //    beim Ziel steht dieselbe Aussage bereits.
            $stmt = $db->prepare("DELETE FROM horse_persons WHERE contact_id = ?");
            $stmt->execute([$sourceId]);
            $verworfen = $stmt->rowCount();

            // 3. Der zweite Steckplatz: Wo die Quelle als Deckstation genannt
            //    ist, tritt das Ziel an ihre Stelle. Ohne diesen Schritt zeigte
            //    die Zuordnung nach dem Zusammenfuehren auf einen Datensatz im
            //    Papierkorb - fachlich falsch, und beim Leeren des Papierkorbs
            //    still auf NULL gesetzt (ON DELETE SET NULL).
            $stmt = $db->prepare("UPDATE horse_persons SET station_contact_id = ? WHERE station_contact_id = ?");
            $stmt->execute([$targetId, $sourceId]);
            $stationen = $stmt->rowCount();

            // 4. Dasselbe fuer die Deckstation am Pferd selbst. horses.breeding_station_id
            //    zeigt seit #336 auf contacts; der Freitext-Spiegel
            //    horses.breeding_station bleibt unangetastet, er ist eine
            //    eigene Aussage.
            $stmt = $db->prepare("UPDATE horses SET breeding_station_id = ? WHERE breeding_station_id = ?");
            $stmt->execute([$targetId, $sourceId]);
            $stationen += $stmt->rowCount();

            // 5. Leere Felder des Ziels aus der Quelle auffuellen, nie ueberschreiben.
            $fill = [];
            foreach (self::MERGE_FILL_FIELDS as $feld) {
                $zielWert = trim((string)($target[$feld] ?? ''));
                $quellWert = trim((string)($source[$feld] ?? ''));
                if ($zielWert === '' && $quellWert !== '') {
                    $fill[$feld] = $quellWert;
                }
            }
            // is_breeder ist ein Kennzeichen, kein Text: gesetzt bleibt gesetzt.
            if (empty($target['is_breeder']) && !empty($source['is_breeder'])) {
                $fill['is_breeder'] = 1;
            }
            // contact_public wird ausdruecklich NICHT mit uebernommen. Es waere
            // dieselbe Zeile Code wie oben und genau der falsche Schluss: Die
            // Freigabe gilt dem Datensatz, dem sie erteilt wurde. Wer zwei
            // Kontakte zusammenfuehrt, trifft keine Aussage darueber, dass die
            // Telefonnummer des behaltenen jetzt oeffentlich sein soll -
            // "aufgefuellt" waere hier eine stille Veroeffentlichung.
            if ($fill !== []) {
                $sql = implode(', ', array_map(static fn(string $f): string => "{$f} = ?", array_keys($fill)));
                $stmt = $db->prepare("UPDATE contacts SET {$sql} WHERE id = ?");
                $stmt->execute([...array_values($fill), $targetId]);
            }

            // 6. Erst JETZT die Quelle in den Papierkorb.
            $db->prepare("UPDATE contacts SET deleted_at = NOW() WHERE id = ?")->execute([$sourceId]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            header("Location: /admin/contacts?error=merge_failed");
            exit;
        }

        \App\Service\AuditLogger::log(
            "Kontakte zusammengeführt",
            "contacts",
            "Quelle ID {$sourceId} ({$source['name']}) -> Ziel ID {$targetId} ({$target['name']}): "
            . "{$umgehaengt} Zuordnung(en) umgehängt, {$verworfen} doppelte verworfen, "
            . "{$stationen} Deckstations-Verweis(e) umgehängt, "
            . count($fill) . " Feld(er) ergänzt"
        );

        // Die Zahlen wandern mit in die Liste, nicht nur ins Audit-Log. Der
        // Fall, gegen den das hilft: Wer die Paarrichtung verdreht, verliert
        // dank NULL-Fill zwar keine Daten - aber der ueberlebende Datensatz
        // traegt dann den falschen Namen, und ein "Aktion erfolgreich"
        // verraet davon nichts. Viele ergaenzte Felder sind das Zeichen, dass
        // der aufgegebene Satz der reichhaltigere war (beobachtet bei der
        // Datenmigration: bei vier von zehn Paaren stand die Adresse
        // ausgerechnet im aufgegebenen Satz).
        header("Location: /admin/contacts?success=merged"
            . "&merged_moved=" . $umgehaengt
            . "&merged_dropped=" . $verworfen
            . "&merged_filled=" . count($fill)
            . "&merged_stations=" . $stationen);
        exit;
    }

    public function delete(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }
        $this->requirePermission('contacts', 'delete');

        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db = Database::getInstance();

            // Ganzen Datensatz holen, nicht nur den Namen: Der Hook unten gibt
            // ihn weiter, und danach ist er nur noch ueber den Papierkorb
            // erreichbar (Muster: horse.trashed).
            $stmt = $db->prepare("SELECT * FROM contacts WHERE id = ?");
            $stmt->execute([$id]);
            $contact = $stmt->fetch() ?: [];
            $contactName = $contact['name'] ?? "ID {$id}";

            $stmt = $db->prepare("UPDATE contacts SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            \App\Service\AuditLogger::log("Kontakt in Papierkorb verschoben", "contacts", "Kontakt ID {$id}: {$contactName}");

            // contact.deleted meldet den Weg in den Papierkorb - den Zeitpunkt,
            // an dem der Kontakt aus der Oberflaeche verschwindet und ein Addon
            // seine abhaengigen Daten stilllegen muss. Der FK-CASCADE greift
            // beim Soft-Delete nicht, dieselbe Lage wie bei horse.trashed.
            $this->doContactAction('deleted', $id, $contact);
        }

        header("Location: /admin/contacts?success=deleted");
        exit;
    }
}
