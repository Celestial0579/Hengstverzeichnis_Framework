<?php
// src/Service/UserProvisioning.php

namespace App\Service;

use App\Controllers\GroupController;
use App\Permission\EmailRequirement;
use App\Security\LoginIdentifier;
use PDO;

/**
 * Der eine Weg, ein Benutzerkonto anzulegen (#384).
 *
 * WARUM ES DIESE KLASSE GIBT. Bis v0.9 entstand ein Konto ausschliesslich in
 * UserController::store() - verwoben mit dem Formular: Fehler wurden als View
 * gerendert, Erfolg war ein Redirect. Wer von woanders ein Konto anlegen will
 * (Addons#131 legt Konten aus einer Mitgliederliste an), musste den Vorgang
 * nachbauen. Und dabei jede einzelne Vorgabe treffen:
 *
 *   - `must_change_password = 1`, sonst bleibt ein erzeugtes Passwort gueltig
 *   - die Adresspflicht nach Rechten (#348), sonst entsteht ein Konto mit
 *     Rechten und ohne Rueckweg
 *   - das `@`-Verbot im Benutzernamen (#348), sonst wird die Anmeldekennung
 *     mehrdeutig
 *   - die reservierten Benutzernamen
 *   - die Filterung nicht zuweisbarer Gruppen (`public`)
 *   - der Audit-Eintrag
 *
 * Jede davon ist einzeln begruendet und einzeln zu uebersehen. Eine zweite
 * Fassung dieser Liste waere eine zweite Wahrheit darueber, was ein gueltiges
 * Konto ausmacht.
 *
 * WAS HIER NICHT HINEINGEHOERT: alles, was vom ANLASS abhaengt. Der
 * Mailversand bleibt beim Aufrufer - ob und an wen Zugangsdaten gehen, ist
 * eine Frage des Anlasses (Addons#131 schickt sie bei Mitgliedern ohne eigene
 * Adresse an das Verwaltungsteam), nicht des Anlegens. Ebenso HTTP: kein
 * header(), kein render(), keine Session.
 */
final class UserProvisioning {

    public const MIN_PASSWORD_LENGTH = 8;

    /**
     * Benutzernamen, die nie vergeben werden duerfen.
     *
     * Steht hier und nicht mehr im BaseController: Sie gilt fuer JEDES
     * Anlegen, nicht nur fuer das ueber ein Formular.
     * BaseController::isReservedUsername() ruft diese Stelle auf.
     *
     * @var array<int, string>
     */
    public const RESERVIERTE_NAMEN = [
        'system', 'sys', 'sysadmin', 'systemadmin', 'system_admin',
        'admin', 'administrator', 'administrateur', 'superadmin', 'super_admin',
        'root', 'superuser', 'su',
        'support', 'help', 'helpdesk', 'service', 'info', 'webmaster', 'hostmaster',
        'postmaster', 'security', 'abuse', 'contact',
        'api', 'bot', 'daemon', 'guest', 'test', 'testing', 'demo', 'null', 'undefined',
    ];

    private function __construct() {}

    public static function istReservierterName(string $username): bool {
        return in_array(mb_strtolower(trim($username), 'UTF-8'), self::RESERVIERTE_NAMEN, true);
    }

    /**
     * Erzeugt ein Erstpasswort.
     *
     * `random_bytes` und nicht `uniqid()` oder `rand()`: Das hier ist der
     * einzige Schutz eines Kontos, bis sein Eigentuemer es zum ersten Mal
     * wechselt. Base64 ohne die leicht zu verwechselnden Zeichen waere
     * huebscher, kostete aber Entropie ohne Gegenwert - das Passwort wird
     * kopiert, nicht abgetippt.
     */
    public static function erzeugePasswort(int $laenge = 20): string {
        $roh = rtrim(strtr(base64_encode(random_bytes((int)ceil($laenge * 3 / 4))), '+/', 'Aa'), '=');
        return substr($roh, 0, max($laenge, self::MIN_PASSWORD_LENGTH));
    }

    /**
     * Legt ein Konto an - oder sagt, warum nicht.
     *
     * @param array<int, mixed> $groupIds Gruppen, in die das Konto soll
     * @param string $anlass Kurztext fuers Protokoll ("Benutzerverwaltung",
     *                       "CiviCRM-Abgleich", ...)
     */
    public static function create(
        PDO $db,
        string $username,
        ?string $email,
        string $password,
        array $groupIds,
        string $anlass = 'Benutzerverwaltung'
    ): UserProvisioningResult {
        $username = trim($username);
        $email = trim((string)($email ?? ''));
        $groupIds = array_values(array_unique(array_filter(
            array_map('intval', $groupIds),
            static fn(int $id): bool => $id > 0
        )));

        $fehler = self::pruefe($db, $username, $email, $password, $groupIds);
        if ($fehler !== []) {
            return new UserProvisioningResult(0, $fehler);
        }

        try {
            $stmt = $db->prepare(
                "INSERT INTO users (username, email, password_hash, must_change_password)
                 VALUES (?, ?, ?, 1)"
            );
            // Leere Eingabe heisst "keine Adresse", nicht "leere Adresse":
            // Der UNIQUE-Index laesst beliebig viele NULL zu, aber nur EINEN
            // Leerstring - das zweite Konto ohne Adresse liefe sonst in einen
            // Duplikatsfehler (#348).
            $stmt->execute([$username, $email === '' ? null : $email, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int)$db->lastInsertId();
        } catch (\Throwable $e) {
            // Der UNIQUE-Index ist die letzte und einzige verlaessliche
            // Instanz gegen ein Wettrennen: Zwei gleichzeitige Anlagen mit
            // demselben Namen kaemen beide durch die Vorpruefung.
            return new UserProvisioningResult(0, ['E-Mail oder Benutzername bereits vergeben.']);
        }

        self::gruppenSetzen($db, $userId, $groupIds);

        AuditLogger::log(
            'Benutzer angelegt',
            'users',
            sprintf('Benutzer: %s (%s), Anlass: %s', $username, $email !== '' ? $email : 'ohne E-Mail', $anlass)
        );

        return new UserProvisioningResult($userId);
    }

    /**
     * Alle Gruende, aus denen ein Konto nicht entstehen darf - gesammelt, nicht
     * beim ersten abgebrochen: Wer ein Formular ausfuellt, soll alles auf
     * einmal erfahren.
     *
     * @param array<int, int> $groupIds
     * @return array<int, string>
     */
    public static function pruefe(PDO $db, string $username, ?string $email, string $password, array $groupIds): array {
        $username = trim($username);
        $email = trim((string)($email ?? ''));

        $fehler = LoginIdentifier::usernameErrors($username);

        if ($username !== '' && self::istReservierterName($username)) {
            $fehler[] = "Der Benutzername '{$username}' ist aus Sicherheitsgründen reserviert und darf nicht verwendet werden.";
        }
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $fehler[] = 'Passwort muss mindestens ' . self::MIN_PASSWORD_LENGTH . ' Zeichen lang sein.';
        }

        return array_merge($fehler, self::adressFehler($db, $email, $groupIds));
    }

    /**
     * Die Adressregel allein (#348) - fuer das ANLEGEN wie fuer das AENDERN.
     *
     * Zwei verschiedene Fehler, die gern verwechselt werden: Eine ANGEGEBENE
     * Adresse muss gueltig sein, immer. Eine FEHLENDE ist nur dann ein
     * Fehler, wenn die Gruppen des Kontos mehr als Lesen erlauben.
     *
     * @param array<int, int> $groupIds Gruppen, die gespeichert werden sollen
     * @return array<int, string>
     */
    public static function adressFehler(PDO $db, ?string $email, array $groupIds): array {
        $email = trim((string)($email ?? ''));

        if ($email !== '') {
            return filter_var($email, FILTER_VALIDATE_EMAIL)
                ? []
                : ['Die E-Mail-Adresse ist nicht gültig.'];
        }

        if (!EmailRequirement::groupsRequireEmail($db, $groupIds)) {
            return [];
        }

        return ['Ohne E-Mail-Adresse geht das nur für Konten, die ausschließlich lesen dürfen. '
              . 'Mindestens eine der gewählten Gruppen gibt Bearbeitungs- oder Veröffentlichungsrechte - '
              . 'dafür ist eine Adresse Pflicht: Ohne sie gibt es kein "Passwort vergessen", keine '
              . 'Benachrichtigungen und keinen zweiten Faktor per E-Mail.'];
    }

    /**
     * Gruppen zuweisen - ausschliesslich zuweisbare (siehe
     * GroupController::NON_ASSIGNABLE_SLUGS). `public` gilt allein fuer nicht
     * angemeldete Besucher; eine manipulierte oder schlicht falsche Anfrage
     * darf sie einem echten Konto nie geben.
     *
     * @param array<int, int> $groupIds
     */
    private static function gruppenSetzen(PDO $db, int $userId, array $groupIds): void {
        if ($groupIds === []) {
            return;
        }

        $nichtZuweisbar = GroupController::NON_ASSIGNABLE_SLUGS;
        $gruppenPlatzhalter = implode(',', array_fill(0, count($groupIds), '?'));
        $sperrPlatzhalter = implode(',', array_fill(0, count($nichtZuweisbar), '?'));

        $stmt = $db->prepare(
            "SELECT id FROM `groups` WHERE slug NOT IN ({$sperrPlatzhalter}) AND id IN ({$gruppenPlatzhalter})"
        );
        $stmt->execute(array_merge($nichtZuweisbar, $groupIds));

        $insert = $db->prepare('INSERT IGNORE INTO user_groups (user_id, group_id) VALUES (?, ?)');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $gruppenId) {
            $insert->execute([$userId, (int)$gruppenId]);
        }
    }
}
