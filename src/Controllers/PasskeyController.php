<?php
// src/Controllers/PasskeyController.php

namespace App\Controllers;

use App\Security\Passkeys;
use App\Security\SecondFactors;
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
 */
class PasskeyController extends BaseController {

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
        $benutzername = (string)($_SESSION['username'] ?? 'Konto');

        header('Content-Type: application/json; charset=utf-8');
        echo Passkeys::registrierungsOptionen($userId, $benutzername, $benutzername);
        exit;
    }

    /** Schliesst die Registrierung ab. */
    public function registrierungAbschliessen(): void {
        $this->checkAuth();

        if (!\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->jsonFehler('CSRF-Sicherheits-Token ungültig oder abgelaufen.', 403);
        }

        $antwort = (string)($_POST['antwort'] ?? '');
        $bezeichnung = (string)($_POST['bezeichnung'] ?? '');

        if ($antwort === '') {
            $this->jsonFehler('Es kam keine Antwort vom Sicherheitsschlüssel an.', 400);
        }

        try {
            Passkeys::registrierungAbschliessen($antwort, $bezeichnung);
        } catch (\Throwable $e) {
            $this->jsonFehler($e->getMessage(), 400);
        }

        $this->json(['ok' => true]);
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

        header('Content-Type: application/json; charset=utf-8');
        echo Passkeys::anmeldeOptionen((int)$userId);
        exit;
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
