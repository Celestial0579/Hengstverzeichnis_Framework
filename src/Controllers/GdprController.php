<?php
// src/Controllers/GdprController.php

namespace App\Controllers;

use App\Database;
use App\Service\AuditLogger;
use App\Service\KontaktDsgvo;

class GdprController extends BaseController {

    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requireAdmin();
    }

    /** Seitengröße der Anfragen-Liste - deckelt zugleich die Batch-Personensuche (#126). */
    private const PER_PAGE = 25;

    /**
     * Trefferdeckel der manuellen Personensuche (#266). Bewusst begrenzt: Ein
     * Auswahlfeld, das den kompletten Personenbestand lädt, ist genau die Falle
     * aus Addons#87 - dort lud die Hengstauswahl ungebremst den ganzen Bestand.
     * Derselbe Wert wie im Galerie-Addon, an dem das Muster schon hängt.
     */
    private const SEARCH_LIMIT = 50;

    /**
     * Kürzeste Eingabe, ab der gesucht wird (#318). Muss mit MIN_LENGTH in
     * public/js/gdpr-person-search.js übereinstimmen - der Wert steht an zwei
     * Stellen, weil der Client gar nicht erst anfragen soll und der Server
     * sich nicht darauf verlassen darf.
     */
    private const MIN_SEARCH_LENGTH = 3;

    public function index(): void {
        $db = Database::getInstance();

        // SQL-seitige Pagination statt "alle jemals eingegangenen Anfragen" -
        // gdpr_requests wächst monoton und wurde bisher komplett geladen (#126).
        $total = (int)$db->query("SELECT COUNT(*) FROM gdpr_requests")->fetchColumn();
        $totalPages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));

        $offset = ($page - 1) * self::PER_PAGE;
        $stmt = $db->prepare("SELECT * FROM gdpr_requests ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, self::PER_PAGE, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $requests = $stmt->fetchAll();

        // Automatischer Abgleich (#126, #266) mit denselben Regeln wie die
        // manuelle Suche (Audit M12). Vorher lief EINE Batch-Abfrage ohne
        // Mindestlänge, ohne LIMIT und mit ungeschützten LIKE-Platzhaltern:
        // Eine anonyme Anfrage mit dem Namen "e" lieferte praktisch den
        // ganzen Kontaktbestand samt Lösch-Knöpfen - bei größeren Beständen
        // bis zum Speicherabbruch, und die Seite ist der einzige Ort, an dem
        // sich eine Anfrage bearbeiten lässt.
        //
        // Jetzt je DISTINCT-Begriff der Seite (höchstens PER_PAGE) EIN Aufruf
        // von kontakteSuchen() mit SEARCH_LIMIT + 1: höchstens 25 x 2 kleine
        // Abfragen mit LIMIT. Ist der Name kürzer als MIN_SEARCH_LENGTH,
        // läuft der Abgleich über die E-Mail-Adresse; ist auch die zu kurz,
        // gar nicht (matching_skipped) - dann bleibt die manuelle Suche.
        //
        // Seit #266 auch für Auskunftsanfragen: Wer Auskunft verlangt, will
        // wissen, was gespeichert ist - dafür muss der Datensatz erst einmal
        // gefunden werden. Was die Oberfläche danach anbietet, unterscheidet
        // sich weiterhin: Auskunft heißt einsehen, nicht löschen.
        $treffer = [];
        foreach ($requests as &$req) {
            $req['matching_persons'] = [];
            $req['matching_more'] = false;
            $req['matching_skipped'] = false;
            $req['matching_via_email'] = false;
            if (!self::needsMatching($req)) {
                continue;
            }
            [$begriff, $ueberEmail] = self::automatchBegriff($req);
            if ($begriff === '') {
                $req['matching_skipped'] = true;
                continue;
            }
            $req['matching_via_email'] = $ueberEmail;
            if (!isset($treffer[$begriff])) {
                $treffer[$begriff] = self::kontakteSuchen($db, $begriff, self::SEARCH_LIMIT + 1);
            }
            $zeilen = $treffer[$begriff];
            if (count($zeilen) > self::SEARCH_LIMIT) {
                $req['matching_more'] = true;
                $zeilen = array_slice($zeilen, 0, self::SEARCH_LIMIT);
            }
            $req['matching_persons'] = $zeilen;
        }
        unset($req);

        // Altkopien aus der Kontaktlisten-Umstellung (#336, Audit M23): Bei
        // Auskunftsanfragen gehören sie zur Antwort, bei Löschanfragen werden
        // sie mitbehandelt - die Oberfläche sagt beides dazu.
        $ids = [];
        foreach ($requests as $req) {
            foreach ($req['matching_persons'] as $p) {
                $ids[] = (int)$p['id'];
            }
        }
        $altkopie = $ids !== [] ? KontaktDsgvo::hatAltkopie($db, $ids) : [];
        foreach ($requests as &$req) {
            foreach ($req['matching_persons'] as &$p) {
                $p['altkopie'] = $altkopie[(int)$p['id']] ?? false;
            }
            unset($p);
        }
        unset($req);

        $this->render('admin_gdpr', [
            'title' => 'DSGVO Anfragen verwalten',
            'requests' => $requests,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'searchLimit' => self::SEARCH_LIMIT,
            'minSearchLength' => self::MIN_SEARCH_LENGTH,
        ]);
    }

    /**
     * Braucht diese Anfrage eine Personenzuordnung? Beide Anfragearten - die
     * Löschung wie die Auskunft (#266) - solange sie nicht abgeschlossen ist.
     * An einer erledigten Anfrage gibt es nichts mehr zuzuordnen.
     *
     * @param array<string, mixed> $req
     */
    private static function needsMatching(array $req): bool {
        return in_array($req['request_type'], ['deletion', 'info'], true)
            && $req['status'] !== 'processed';
    }

    /**
     * Suchbegriff des automatischen Abgleichs (Audit M12): der Name ab
     * MIN_SEARCH_LENGTH Zeichen, sonst die E-Mail-Adresse, sonst nichts.
     *
     * /dsgvo prüft die Adresse per FILTER_VALIDATE_EMAIL, sie ist also immer
     * lang genug - ein Ein-Buchstaben-Name führt damit praktisch immer zum
     * Abgleich über die Adresse. Der leere Fall ist die Absicherung für
     * Altbestand und direkt eingefügte Zeilen.
     *
     * @param array<string, mixed> $req
     * @return array{0: string, 1: bool} Begriff, und ob er die E-Mail ist
     */
    private static function automatchBegriff(array $req): array {
        $name = trim((string)($req['name'] ?? ''));
        if (mb_strlen($name) >= self::MIN_SEARCH_LENGTH) {
            return [$name, false];
        }
        $email = trim((string)($req['email'] ?? ''));
        if (mb_strlen($email) >= self::MIN_SEARCH_LENGTH) {
            return [$email, true];
        }
        return ['', false];
    }

    /**
     * Gemeinsame Kontaktsuche für Automatch und manuelle Suche (#318, Audit
     * M12).
     *
     * Zwei Stufen statt einer teuren Abfrage (#318). Vorher lief je
     * Tastendruck: LEFT JOIN auf horse_persons, GROUP BY über alle Treffer,
     * ORDER BY in einer temporären Tabelle - und erst GANZ ZULETZT das
     * LIMIT. Deshalb:
     *
     * 1. horse_count kommt als Unterabfrage je ausgegebener Zeile statt aus
     *    JOIN und GROUP BY. Sie läuft damit für höchstens $limit Zeilen und
     *    nutzt den Fremdschlüssel-Index auf horse_persons.contact_id. Der
     *    Zähler zählt bewusst NUR den Personen-Steckplatz (contact_id): Er
     *    beantwortet "an wie vielen Pferden hängt dieser Mensch als
     *    Züchter/Besitzer/Halter" - die Zahl, an der ein Bearbeiter die
     *    Tragweite einer Löschung abschätzt.
     * 2. Zuerst die Präfixsuche, die den Bestand wirklich eingrenzt. Nur wenn
     *    sie den Deckel nicht füllt, kommt die Enthält-Suche über Name,
     *    Kontaktfeld und E-Mail dazu (seit #188 eigenes Feld - Anträge nennen
     *    oft nur die Adresse).
     *
     * Platzhalter werden wörtlich genommen (likeMuster(), ESCAPE '!' in
     * JEDER LIKE-Klausel, auch der Präfixstufe - der Index-Range-Scan für
     * 'x%' bleibt dabei erhalten). Bewusst nicht Backslash: '!' ist
     * unabhängig von NO_BACKSLASH_ESCAPES.
     *
     * Positivliste statt `SELECT *` (#336): An einem Treffer hängen seit der
     * Kontaktliste auch Anschrift und Telefonnummern; mehr als die Zuordnung
     * braucht, soll nicht im Speicher liegen.
     *
     * BEWUSST OHNE deleted_at-Filter: Ein weich gelöschter Datensatz ist aus
     * der Oberfläche verschwunden, seine personenbezogenen Daten stehen aber
     * unverändert in der Tabelle. Wer Löschung verlangt, hat Anspruch auch
     * auf diese - würde die Suche sie ausblenden, entstünde genau die Lücke,
     * die niemandem auffällt. Die Oberfläche kennzeichnet solche Treffer.
     *
     * Gesucht wird über die ganze Kontaktliste (#336): Wer Löschung
     * verlangt, kann auch als Betriebs-Kontakt stehen.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function kontakteSuchen(\PDO $db, string $q, int $limit): array {
        $sql = 'SELECT p.id, p.name, p.contact_info, p.email, p.deleted_at,
                       (SELECT COUNT(*) FROM horse_persons hp WHERE hp.contact_id = p.id) AS horse_count
                  FROM contacts p
                 WHERE %s
                 ORDER BY p.name ASC, p.id ASC
                 LIMIT ?';

        $muster = self::likeMuster($q);
        $stmt = $db->prepare(sprintf($sql, "p.name LIKE ? ESCAPE '!'"));
        $stmt->bindValue(1, $muster . '%');
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        if (count($rows) < $limit) {
            $like = '%' . $muster . '%';
            $stmt = $db->prepare(sprintf(
                $sql,
                "(p.name LIKE ? ESCAPE '!' OR p.contact_info LIKE ? ESCAPE '!' OR p.email LIKE ? ESCAPE '!')"
            ));
            $stmt->bindValue(1, $like);
            $stmt->bindValue(2, $like);
            $stmt->bindValue(3, $like);
            $stmt->bindValue(4, $limit, \PDO::PARAM_INT);
            $stmt->execute();

            // Nach ID zusammenführen: Die Präfixtreffer stehen zwangsläufig
            // auch in der Enthält-Menge, und sie sollen die vorderen Plätze
            // behalten - ein Treffer, der mit dem Suchbegriff BEGINNT, ist der
            // wahrscheinlichere.
            $bekannt = array_column($rows, 'id');
            foreach ($stmt->fetchAll() as $row) {
                if (count($rows) >= $limit) {
                    break;
                }
                if (!in_array($row['id'], $bekannt, false)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /** LIKE-Platzhalter wörtlich nehmen (Escape-Zeichen '!', Audit M12). */
    private static function likeMuster(string $s): string {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
    }

    /**
     * Manuelle Personensuche für die DSGVO-Verwaltung (#266).
     *
     * Der Automatch greift nur bei wörtlicher Übereinstimmung und scheitert
     * schon an abweichender Schreibweise, Tippfehlern oder einem geänderten
     * Namen. Ohne Rückfallweg blieb die Anfrage dann auf `pending` liegen -
     * bei einem Verfahren, dessen ganzer Zweck die Einhaltung gesetzlicher
     * Fristen ist, ist das der ungünstigste denkbare Ausgang.
     *
     * Liefert höchstens SEARCH_LIMIT Treffer als JSON. Der Konstruktor
     * erzwingt Anmeldung und Admin-Rolle, die Action erbt diesen Schutz - die
     * Antwort enthält personenbezogene Daten und darf nirgends sonst landen.
     */
    public function searchPersons(): void {
        header('Content-Type: application/json; charset=utf-8');
        // Treffer enthalten PII: weder Browser noch Zwischenspeicher sollen sie
        // aufbewahren, und eine fremde Seite soll sie nicht einbetten können.
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');

        $q = trim((string)($_GET['q'] ?? ''));
        // Ab drei Zeichen (#318, vorher zwei). Ein Zweibuchstaben-Fragment wie
        // "an" trifft praktisch den gesamten Bestand - der Deckel schneidet die
        // Antwort dann auf 50 Zeilen zurecht, die Datenbank hat aber alles
        // andere vorher trotzdem angefasst. Für die Zuordnung einer
        // DSGVO-Anfrage ist ein solcher Treffer ohnehin wertlos, und
        // MIN_LENGTH im Skript daneben ist auf denselben Wert gesetzt.
        if (mb_strlen($q) < self::MIN_SEARCH_LENGTH) {
            echo json_encode([]);
            exit;
        }
        $db = Database::getInstance();
        $rows = self::kontakteSuchen($db, $q, self::SEARCH_LIMIT);
        $altkopie = $rows !== [] ? KontaktDsgvo::hatAltkopie($db, array_column($rows, 'id')) : [];

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'contact_info' => (string)($row['contact_info'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'horse_count' => (int)$row['horse_count'],
                'is_deleted' => $row['deleted_at'] !== null,
                // Additiv (Audit M23): Altkopie aus der Kontaktlisten-Umstellung.
                'has_legacy_copy' => $altkopie[(int)$row['id']] ?? false,
            ];
        }

        echo json_encode($results, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function updateStatus(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'pending';
        $notes = trim($_POST['admin_notes'] ?? '');

        if ($id > 0 && in_array($status, ['pending', 'processed', 'rejected'], true)) {
            $db = Database::getInstance();
            $stmt = $db->prepare("UPDATE gdpr_requests SET status = ?, admin_notes = ? WHERE id = ?");
            $stmt->execute([$status, $notes ?: null, $id]);

            // Auch die Entscheidung über eine Anfrage gehört ins Audit-Log
            // (#135, #453) - ohne sie endet die Historie eines DSGVO-Vorgangs
            // bei seinem Eingang. Den Notiztext selbst NICHT mitschreiben: Er
            // kann personenbezogene Daten enthalten, und das Audit-Log
            // überlebt jeden Reset. Dass eine Notiz hinterlegt wurde, reicht.
            if ($stmt->rowCount() > 0) {
                \App\Service\AuditLogger::log(
                    "DSGVO: Anfrage-Status geändert",
                    "gdpr",
                    "Anfrage ID {$id} -> {$status}" . ($notes !== '' ? " (Notiz hinterlegt)" : "")
                );
            }
        }

        header("Location: /admin/gdpr?success=status_updated");
        exit;
    }

    /**
     * Altkopie eines Kontakts für die Auskunft (Art. 15, Audit M23): die
     * Zeilen aus persons_pre_contacts/breeding_stations_pre_contacts, die
     * seit der Kontaktlisten-Umstellung (#336) stillgelegt neben `contacts`
     * liegen. Sie gehören zu dem, was über einen Menschen gespeichert ist.
     * Admin-only über den Konstruktor, wie der ganze Bereich.
     */
    public function legacyCopy(): void {
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');

        $id = (int)($_GET['id'] ?? 0);
        $db = Database::getInstance();
        $kontakt = null;
        if ($id > 0) {
            $stmt = $db->prepare('SELECT id, name, deleted_at FROM contacts WHERE id = ?');
            $stmt->execute([$id]);
            $kontakt = $stmt->fetch() ?: null;
        }

        $this->render('admin_gdpr_legacy_copy', [
            'title' => 'Altkopie (#336)',
            'kontakt' => $kontakt,
            'kontaktId' => $id,
            'altkopien' => $kontakt !== null ? KontaktDsgvo::altkopien($db, $id) : [],
        ]);
    }

    /**
     * Kontakt anonymisieren (Art. 17, Pferde bleiben) - samt Kopien am Pferd,
     * Dubletten-Notizen, Altkopien und Protokoll (Audit M11, M23, N45), alles
     * in EINER Transaktion über App\Service\KontaktDsgvo.
     *
     * Die Liste der genullten Spalten steht in KontaktDsgvo::PII_SPALTEN, der
     * Anonymname in KontaktDsgvo::anonymName() - DER NAME IST EIN VERTRAG
     * (Audit N78): Das Addon mitgliedsstatus und
     * App\Service\MitgliedsstatusAltbestand erkennen anonymisierte Kontakte an
     * genau diesem Wortlaut. Die Altkopie in persons_pre_contacts wird
     * mitanonymisiert; ihre id bleibt, membership_status wird genullt.
     *
     * Fehlt der Kontakt (Doppelklick, veraltetes Formular), bleibt die
     * Anfrage offen und die Seite sagt es - vorher meldete sie Erfolg und
     * schloss die Anfrage ab. `contact.anonymized` feuert NACH dem Commit:
     * Ein Fremdschlüssel-CASCADE greift hier nicht, Addons müssen ihre Daten
     * zum Kontakt selbst nachziehen.
     */
    public function anonymizePerson(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $personId = (int)($_POST['person_id'] ?? 0);
        $requestId = (int)($_POST['request_id'] ?? 0);
        if ($personId <= 0) {
            header("Location: /admin/gdpr?error=not_found");
            exit;
        }

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $ergebnis = KontaktDsgvo::anonymisieren($db, $personId);
            if ($ergebnis === null) {
                $db->rollBack();
                header("Location: /admin/gdpr?error=not_found&person_id=" . $personId);
                exit;
            }
            $vermerk = "Kontakt #{$personId} anonymisiert; bereinigt: " . KontaktDsgvo::zaehlerText($ergebnis['bereinigt']);
            $this->anfrageErledigen($db, $requestId, $vermerk);
            $db->commit();
        } catch (\Throwable $e) {
            $this->fehlschlag($db, 'DSGVO: Anonymisierung fehlgeschlagen', $personId, $requestId, $e);
        }

        // DSGVO-Maßnahmen müssen im Audit-Log nachvollziehbar sein (#135) -
        // nur Kennungen und Zähler. "Person ID {id}" bleibt vorn: Daran
        // erkennt der Bestand (KontaktDsgvo::nachholen()) DSGVO-Fälle.
        AuditLogger::log(
            "DSGVO: Person anonymisiert",
            "gdpr",
            "Person ID {$personId}" . ($requestId > 0 ? ", Anfrage ID {$requestId}" : "")
                . "; bereinigt: " . KontaktDsgvo::zaehlerText($ergebnis['bereinigt'])
        );
        $this->hooks()->doAction('contact.anonymized', $personId, $ergebnis['vorher']);

        header("Location: /admin/gdpr?success=anonymized&person_id=" . $personId);
        exit;
    }

    /**
     * Kontakt endgültig löschen (Art. 17) - samt Kopien am Pferd,
     * Dubletten-Entscheidungen, Altkopien und Protokoll (Audit M11, M23,
     * N45), in EINER Transaktion über App\Service\KontaktDsgvo.
     *
     * Die Fremdschlüssel räumen den Rest auf (#336): horse_persons.contact_id
     * fällt per ON DELETE CASCADE mit, horse_persons.station_contact_id und
     * horses.breeding_station_id gehen auf NULL - die Aussage "dieses Pferd
     * stand irgendwo" bleibt, der Mensch dahinter verschwindet. Vorher
     * bereinigt der Dienst die Namenskopien, die sonst ab da als öffentlicher
     * Freitext gälten.
     */
    public function deletePerson(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden("CSRF-Sicherheits-Token ungültig oder abgelaufen.");
        }

        $personId = (int)($_POST['person_id'] ?? 0);
        $requestId = (int)($_POST['request_id'] ?? 0);
        if ($personId <= 0) {
            header("Location: /admin/gdpr?error=not_found");
            exit;
        }

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $ergebnis = KontaktDsgvo::loeschen($db, [$personId], KontaktDsgvo::DSGVO);
            $kontakt = $ergebnis['kontakte'][$personId] ?? null;
            if ($kontakt === null) {
                $db->rollBack();
                header("Location: /admin/gdpr?error=not_found&person_id=" . $personId);
                exit;
            }
            $vermerk = "Kontakt #{$personId} gelöscht; bereinigt: " . KontaktDsgvo::zaehlerText($ergebnis['bereinigt']);
            $this->anfrageErledigen($db, $requestId, $vermerk);
            $db->commit();
        } catch (\Throwable $e) {
            $this->fehlschlag($db, 'DSGVO: Löschung fehlgeschlagen', $personId, $requestId, $e);
        }

        // DSGVO-Löschungen müssen im Audit-Log nachvollziehbar sein (#135).
        AuditLogger::log(
            "DSGVO: Person endgültig gelöscht",
            "gdpr",
            "Person ID {$personId}" . ($requestId > 0 ? ", Anfrage ID {$requestId}" : "")
                . "; bereinigt: " . KontaktDsgvo::zaehlerText($ergebnis['bereinigt'])
        );
        // Nach dem Commit; die CASCADE-Zeilen sind schon weg. Gleicher Hook
        // wie beim Löschen aus dem Papierkorb, der Anlass unterscheidet.
        $this->hooks()->doAction('contact.erased', $personId, $kontakt, 'dsgvo');

        header("Location: /admin/gdpr?success=deleted&person_id=" . $personId);
        exit;
    }

    /** Anfrage abschließen, mit ehrlichem Vermerk (nur Kennung und Zähler). */
    private function anfrageErledigen(\PDO $db, int $requestId, string $vermerk): void {
        if ($requestId > 0) {
            $stmt = $db->prepare("UPDATE gdpr_requests SET status = 'processed', admin_notes = ? WHERE id = ?");
            $stmt->execute([$vermerk, $requestId]);
        }
    }

    /**
     * Rollback, Protokoll nur mit Kennungen und Ausnahmeklasse (die Meldung
     * kann Daten enthalten), zurück mit ?error=failed. Die Anfrage bleibt
     * offen.
     */
    private function fehlschlag(\PDO $db, string $aktion, int $personId, int $requestId, \Throwable $e): never {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        AuditLogger::log(
            $aktion,
            "gdpr",
            "Kontakt ID {$personId}" . ($requestId > 0 ? ", Anfrage ID {$requestId}" : "") . ": " . get_class($e)
        );
        error_log("DSGVO-Aktion fehlgeschlagen ({$aktion}, Kontakt {$personId}): " . get_class($e));
        header("Location: /admin/gdpr?error=failed&person_id=" . $personId);
        exit;
    }
}
