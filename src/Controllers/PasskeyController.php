<?php
// src/Controllers/PasskeyController.php

namespace App\Controllers;

use App\Security\BackupCodes;
use App\Security\KontoSicherheit;
use App\Security\Passkeys;
use App\Security\SecondFactors;
use App\Security\StepUp;
use App\Service\AuditLogger;

/**
 * Class PasskeyController
 *
 * Registrieren, Anmelden, Verwalten und Entziehen von Passkeys (#353).
 *
 * Die eigentliche Zeremonie steckt in App\Security\Passkeys; hier stehen nur
 * die Wege dorthin - und die Schranken davor.
 *
 * ## Warum die Zeremonie-Endpunkte JSON sprechen
 *
 * `navigator.credentials.create()` und `.get()` laufen im Browser und
 * brauchen ihre Optionen als JSON. Ein Formular-Rundlauf ginge nicht: Der
 * Browser muss die Antwort des Authenticators zurückschicken, und die
 * entsteht erst nach einer Benutzerbestätigung.
 *
 * ## Die Challenge steht nie im Formular
 *
 * Sie wird serverseitig erzeugt und in der Sitzung abgelegt. Käme sie über
 * das Formular zurück, prüfte die Zeremonie am Ende gegen einen Wert, den
 * der Aufrufer gesetzt hat - die Prüfung wäre dann Zierrat.
 *
 * ## Hinzufügen und Entziehen verlangen einen Step-up
 *
 * Ein Passkey ist ein vollwertiger zweiter Faktor, und die Anmeldung bietet
 * ihn sogar zuerst an. Bis Audit M15 genügte eine angemeldete Sitzung, um
 * einen eigenen anzuhängen: Wer eine unbeaufsichtigte Sitzung übernahm und
 * das Passwort kannte, registrierte in Sekunden seinen Sicherheitsschlüssel
 * und meldete sich danach von überall an - ohne den TOTP-Code je zu sehen.
 * Die Schranke aus #112 war damit für jedes Konto umgehbar. Jetzt gilt für
 * Konten mit Faktor dieselbe Bestätigung wie vor der App-Einrichtung
 * (App\Security\StepUp), und nach dem Hinzufügen geht ein Hinweis an die
 * hinterlegte Adresse.
 */
class PasskeyController extends BaseController {

    /** Rückweg für eine fehlende Bestätigung (siehe StepUp::ziel()). */
    private const STEPUP_PFAD = '/2fa/reauth?fuer=passkeys';

    private const STEPUP_TEXT = 'Bitte bestätigen Sie zuerst Ihr Passwort und Ihren zweiten Faktor.';

    // ---- Registrierung (angemeldeter Benutzer) --------------------------

    /**
     * Optionen für einen neuen Passkey.
     *
     * Verlangt eine ANGEMELDETE Sitzung. Ein Passkey ist ein Anmeldemittel;
     * ihn ohne bestehende Anmeldung anzulegen hiesse, dass jeder sich einen
     * Zweitschlüssel für ein fremdes Konto ausstellen lassen könnte.
     */
    public function registrierungsOptionen(): void {
        $this->checkAuth();

        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->jsonFehler('CSRF-Sicherheits-Token ungültig oder abgelaufen.', 403);
        }
        if (!Passkeys::verfuegbar()) {
            $this->jsonFehler('Passkeys brauchen eine gesicherte Verbindung (HTTPS).', 400);
        }

        $userId = (int)$_SESSION['user_id'];
        $this->stepUpVerlangen($userId);
        $benutzername = (string)($_SESSION['username'] ?? 'Konto');

        $json = $this->zeremonieOptionen(
            static fn(): string => Passkeys::registrierungsOptionen($userId, $benutzername, $benutzername)
        );

        header('Content-Type: application/json; charset=utf-8');
        echo $json;
        exit;
    }

    /**
     * Schliesst die Registrierung ab.
     *
     * Die Step-up-Prüfung steht hier NOCH EINMAL - doppelter Boden: Die
     * Zeremonie gilt 300 Sekunden, die Freigabe 600. Wer die Optionen kurz
     * vor Ablauf der Freigabe geholt hat, soll danach nicht ohne sie
     * abschliessen können.
     */
    public function registrierungAbschliessen(): void {
        $this->checkAuth();

        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->jsonFehler('CSRF-Sicherheits-Token ungültig oder abgelaufen.', 403);
        }

        $userId = (int)$_SESSION['user_id'];
        $this->stepUpVerlangen($userId);

        $antwort = (string)($_POST['antwort'] ?? '');
        $bezeichnung = (string)($_POST['bezeichnung'] ?? '');

        if ($antwort === '') {
            $this->jsonFehler('Es kam keine Antwort vom Sicherheitsschlüssel an.', 400);
        }

        try {
            $gespeichert = Passkeys::registrierungAbschliessen($antwort, $bezeichnung);
        } catch (\Throwable $e) {
            $this->jsonFehler($e->getMessage(), 400);
        }

        // Gegen die Sackgasse (Audit N10): Wer als ersten Faktor einen
        // Passkey einrichtet und keine Backup-Codes hat, steht nach dem
        // Verlust des Geräts ohne Rückweg da. Angezeigt werden sie einmal,
        // nach dem Neuladen im Profil.
        $neueCodes = false;
        if (Passkeys::anzahl($userId) === 1 && BackupCodes::offenFuer($userId) === 0) {
            BackupCodes::erneuern($userId);
            AuditLogger::log('Backup-Codes erzeugt (erster Passkey)', 'auth', 'User ID ' . $userId);
            $neueCodes = true;
        }

        KontoSicherheit::hinweisSenden(
            $userId,
            'Neuer Passkey für Ihr Konto',
            sprintf(
                'Für Ihr Konto wurde am %s ein neuer Passkey mit der Bezeichnung „%s“ hinterlegt. '
                . 'Mit ihm lässt sich die Anmeldung bestätigen.',
                date('d.m.Y \u\m H:i'),
                $gespeichert
            )
        );

        $this->json(['ok' => true, 'backupCodes' => $neueCodes]);
    }

    /**
     * Entzieht einen Passkey.
     *
     * Kein JSON, sondern ein normales Formular mit Weiterleitung: Das
     * Entziehen ist eine gewöhnliche Verwaltungshandlung und soll ohne
     * JavaScript funktionieren.
     */
    public function entziehen(): void {
        $this->checkAuth();

        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->renderForbidden('CSRF-Sicherheits-Token ungültig oder abgelaufen.');
        }

        $userId = (int)$_SESSION['user_id'];
        $passkeyId = (int)($_POST['id'] ?? 0);

        // Auch das Entziehen verändert die Faktoren des Kontos und verlangt
        // deshalb die Bestätigung (Entscheidung D06) - die Regel kennt so
        // keinen Sonderfall.
        if (!StepUp::erfuellt($userId)) {
            $_SESSION['passkey_fehler'] = 'Zum Entziehen bestätigen Sie bitte zuerst Ihr Passwort und Ihren zweiten Faktor.';
            header('Location: /profil#passkeys');
            exit;
        }

        // Der letzte Faktor darf nicht ueber diesen Weg verschwinden. Wer
        // seinen einzigen Passkey entzieht und sonst nichts hat, saesse
        // anschliessend vor einem Konto, das einen zweiten Faktor verlangt
        // und keinen mehr hat - und muesste sich an einen Administrator
        // wenden. Also vorher pruefen, nicht hinterher erklaeren.
        $faktoren = SecondFactors::forUser($userId);
        $nurNochDieser = $faktoren === [SecondFactors::PASSKEY] && Passkeys::anzahl($userId) === 1;

        if ($nurNochDieser) {
            $_SESSION['passkey_fehler'] = 'Das ist Ihr einziger zweiter Faktor. '
                . 'Richten Sie zuerst einen weiteren Passkey oder eine Authentikator-App ein.';
            header('Location: /profil#passkeys');
            exit;
        }

        if (!Passkeys::entziehen($userId, $passkeyId)) {
            $_SESSION['passkey_fehler'] = 'Dieser Passkey gehört nicht zu Ihrem Konto.';
        } else {
            $_SESSION['passkey_hinweis'] = 'Passkey entzogen.';
        }

        header('Location: /profil#passkeys');
        exit;
    }

    // ---- Step-up per Passkey (Audit N10) -------------------------------

    /**
     * Optionen für die Bestätigung einer angemeldeten Sitzung mit dem
     * Passkey.
     *
     * Bis hierher konnte ein Konto, dessen einziger Faktor ein Passkey ist,
     * Änderungen nur mit einem Mailcode bestätigen - der Postfachzugang
     * ersetzte damit den phishing-resistenten Faktor, und ohne Adresse ging
     * gar nichts. Eigener Zeremonie-Zweck, siehe Passkeys::ZWECK_STEPUP.
     */
    public function stepUpOptionen(): void {
        $this->checkAuth();

        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->jsonFehler('CSRF-Sicherheits-Token ungültig oder abgelaufen.', 403);
        }
        if (!Passkeys::verfuegbar()) {
            $this->jsonFehler('Passkeys brauchen eine gesicherte Verbindung (HTTPS).', 400);
        }

        $userId = (int)$_SESSION['user_id'];
        if (Passkeys::anzahl($userId) === 0) {
            $this->jsonFehler('Für Ihr Konto ist kein Passkey hinterlegt.', 400);
        }

        $json = $this->zeremonieOptionen(
            static fn(): string => Passkeys::anmeldeOptionen($userId, Passkeys::ZWECK_STEPUP)
        );

        header('Content-Type: application/json; charset=utf-8');
        echo $json;
        exit;
    }

    /**
     * Prüft Passwort und Passkey und erteilt die Freigabe.
     *
     * Das Passwort gehört dazu wie bei /2fa/reauth: Der Step-up belegt
     * Passwort UND Faktor. Fehlversuche zählen im selben Topf (`2fa`), sonst
     * wäre dieser Weg ein Passwort-Orakel ohne Bremse.
     */
    public function stepUpPruefen(): void {
        $this->checkAuth();

        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->jsonFehler('CSRF-Sicherheits-Token ungültig oder abgelaufen.', 403);
        }

        $userId = (int)$_SESSION['user_id'];
        // Erst buchen, dann prüfen (Audit M20): Ein Fehlschlag lässt die
        // Buchung stehen, ein Erfolg leert den Zähler.
        $res = \App\Security\RateLimiter::reserveAttempt((string)$userId, '2fa');
        if ($res === null) {
            $this->jsonFehler('Zu viele Fehlversuche. Bitte versuchen Sie es später erneut.', 429);
        }

        $stmt = \App\Database::getInstance()->prepare(
            "SELECT password_hash FROM users WHERE id = ? AND deleted_at IS NULL AND deactivated_at IS NULL"
        );
        $stmt->execute([$userId]);
        $hash = (string)($stmt->fetchColumn() ?: '');
        if ($hash === '' || !password_verify((string)($_POST['password'] ?? ''), $hash)) {
            $this->jsonFehler('Das Passwort stimmt nicht.', 400);
        }

        $antwort = (string)($_POST['antwort'] ?? '');
        if ($antwort === '') {
            // Das Passwort stimmte, der Schlüssel hat nur nicht geantwortet -
            // kein Rateversuch.
            \App\Security\RateLimiter::releaseAttempt($res);
            $this->jsonFehler('Es kam keine Antwort vom Sicherheitsschlüssel an.', 400);
        }

        try {
            $bestaetigt = Passkeys::anmeldungPruefen($antwort, Passkeys::ZWECK_STEPUP);
        } catch (\Throwable $e) {
            $this->jsonFehler($e->getMessage(), 400);
        }

        // Doppelter Boden, wie bei der Anmeldung: Passkeys::anmeldungPruefen()
        // prüft das bereits gegen die Zeremonie; die Folge eines Fehlers wäre
        // eine Freigabe mit einem fremden Schlüssel.
        if ($bestaetigt !== $userId) {
            AuditLogger::log(
                'Passkey-Step-up abgelehnt',
                'security',
                sprintf('Schlüssel gehört %d, angemeldet ist %d.', $bestaetigt, $userId)
            );
            $this->jsonFehler('Bestätigung mit diesem Sicherheitsschlüssel nicht möglich.', 400);
        }

        \App\Security\RateLimiter::clearAttempts((string)$userId, '2fa');
        StepUp::markieren($userId);
        AuditLogger::log('Step-up per Passkey bestätigt', 'auth', 'User ID ' . $userId);

        $this->json(['ok' => true, 'weiter' => StepUp::ziel($_POST['fuer'] ?? null)]);
    }

    // ---- Anmeldung -------------------------------------------------------

    /**
     * Zeigt die Passkey-Seite im Anmeldeweg.
     *
     * Erreichbar nur mit bestandenem ersten Faktor - `pending_2fa_user_id`
     * steht dann in der Sitzung. Ohne den Nachweis führte die Seite an der
     * Passwortprüfung vorbei.
     */
    public function anmeldeSeite(): void {
        $userId = $_SESSION['pending_2fa_user_id'] ?? null;
        if (!is_int($userId) && !ctype_digit((string)$userId)) {
            header('Location: /login');
            exit;
        }

        $andereFaktoren = array_values(array_diff(
            SecondFactors::forUser((int)$userId),
            [SecondFactors::PASSKEY]
        ));

        $this->render('login_passkey', [
            'title' => 'Anmeldung bestätigen',
            'andereFaktoren' => $andereFaktoren,
            'verfuegbar' => Passkeys::verfuegbar(),
        ]);
    }

    /** Optionen für die Anmelde-Zeremonie. */
    public function anmeldeOptionen(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->jsonFehler('CSRF-Sicherheits-Token ungültig oder abgelaufen.', 403);
        }

        $userId = $_SESSION['pending_2fa_user_id'] ?? null;
        if ($userId === null) {
            $this->jsonFehler('Bitte melden Sie sich zuerst mit Ihrem Passwort an.', 403);
        }

        $json = $this->zeremonieOptionen(static fn(): string => Passkeys::anmeldeOptionen((int)$userId));

        header('Content-Type: application/json; charset=utf-8');
        echo $json;
        exit;
    }

    /**
     * Optionen einer Zeremonie erzeugen - oder verständlich scheitern.
     *
     * Ohne diesen Rahmen endete jeder Fehler beim Erzeugen (etwa keine
     * bestimmbare RP-ID, Audit M36) in einem HTTP 500 ohne Text, und
     * public/js/passkeys.js konnte nichts anzeigen. Den Grund bekommt die
     * Verwaltung im Audit-Log, der Benutzer eine Meldung.
     *
     * @param callable(): string $erzeugen
     */
    private function zeremonieOptionen(callable $erzeugen): string {
        try {
            return $erzeugen();
        } catch (\Throwable $e) {
            AuditLogger::log('Passkey-Zeremonie nicht startbar', 'security', $e->getMessage());
            $this->jsonFehler(
                'Passkeys sind auf dieser Installation derzeit nicht nutzbar. Bitte wenden Sie sich an die Verwaltung.',
                503
            );
        }
    }

    /**
     * Prüft die Anmeldung.
     *
     * Der Erfolgsfall geht durch denselben Abschluss wie jeder andere zweite
     * Faktor (AuthController::afterSecondFactor()) - unter anderem, weil dort
     * die TOTP-Pflicht für Administratoren steht. Ein eigener Abschluss hier
     * wäre eine zweite Stelle, an der die Anmeldung fertig wird, und die
     * zweite ist immer die, die eine Regel vergisst.
     */
    public function anmeldungPruefen(): void {
        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->jsonFehler('CSRF-Sicherheits-Token ungültig oder abgelaufen.', 403);
        }

        $erwartet = $_SESSION['pending_2fa_user_id'] ?? null;
        if ($erwartet === null) {
            $this->jsonFehler('Bitte melden Sie sich zuerst mit Ihrem Passwort an.', 403);
        }

        $antwort = (string)($_POST['antwort'] ?? '');
        if ($antwort === '') {
            $this->jsonFehler('Es kam keine Antwort vom Sicherheitsschlüssel an.', 400);
        }

        try {
            $userId = Passkeys::anmeldungPruefen($antwort);
        } catch (\Throwable $e) {
            $this->jsonFehler($e->getMessage(), 400);
        }

        // Doppelter Boden. Passkeys::anmeldungPruefen() prueft das bereits;
        // hier steht es noch einmal, weil die Folge eines Fehlers an dieser
        // Stelle eine Anmeldung als fremde Person waere. Zwei unabhaengige
        // Pruefungen kosten eine Zeile.
        if ((int)$erwartet !== $userId) {
            AuditLogger::log(
                'Passkey-Anmeldung abgelehnt',
                'security',
                sprintf('Schlüssel gehört %d, erwartet war %d.', $userId, (int)$erwartet)
            );
            $this->jsonFehler('Anmeldung mit diesem Sicherheitsschlüssel nicht möglich.', 400);
        }

        // Die Marke fuer den Abschlussschritt. Sie ersetzt
        // pending_2fa_user_id NICHT, sondern kommt daneben - der Abschluss
        // raeumt beides weg. Ein Zustand, in dem nur noch die neue Marke
        // steht, waere ein zweiter Weg zur Anmeldung, und der zweite Weg ist
        // immer der, der eine Pruefung vergisst.
        $_SESSION['passkey_bestanden'] = $userId;

        $this->json(['ok' => true, 'weiter' => '/login/passkey/fertig']);
    }

    /**
     * Schliesst die Anmeldung ab.
     *
     * Eigener Schritt statt einer Weiterleitung aus dem JSON-Endpunkt: Der
     * gemeinsame Abschluss setzt Kopfzeilen und leitet weiter, und beides
     * geht in einer fetch()-Antwort ins Leere.
     */
    public function anmeldungAbschliessen(): void {
        $userId = $_SESSION['passkey_bestanden'] ?? null;
        $erwartet = $_SESSION['pending_2fa_user_id'] ?? null;
        unset($_SESSION['passkey_bestanden']);

        // Beide Marken muessen da sein UND uebereinstimmen. Eine allein
        // reichte nicht: pending_2fa_user_id belegt den ersten Faktor,
        // passkey_bestanden den zweiten. Wer nur eine davon hat, hat nicht
        // beide Huerden genommen.
        if ($userId === null || $erwartet === null || (int)$userId !== (int)$erwartet) {
            unset($_SESSION['pending_2fa_user_id']);
            header('Location: /login');
            exit;
        }

        (new AuthController())->passkeyAbschluss((int)$userId);
    }

    // ---- Hilfen ----------------------------------------------------------

    /**
     * Beendet die Anfrage mit 403 und dem Weg zur Bestätigung, wenn das Konto
     * einen Faktor hat und keine frische Freigabe vorliegt. Es entstehen dann
     * weder Optionen noch eine Challenge in der Sitzung.
     */
    private function stepUpVerlangen(int $userId): void {
        if (!StepUp::erfuellt($userId)) {
            $this->json([
                'ok' => false,
                'fehler' => self::STEPUP_TEXT,
                'stepup' => self::STEPUP_PFAD,
            ], 403);
        }
    }

    /** @param array<string, mixed> $daten */
    private function json(array $daten, int $status = 200): never {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($daten, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function jsonFehler(string $meldung, int $status): never {
        $this->json(['ok' => false, 'fehler' => $meldung], $status);
    }
}
