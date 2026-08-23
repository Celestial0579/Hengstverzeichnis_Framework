<?php
// src/Security/Passkeys.php

namespace App\Security;

use App\Database;
use App\Service\AuditLogger;
use Cose\Algorithm\Manager as AlgorithmManager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\ECDSA\ES384;
use Cose\Algorithm\Signature\ECDSA\ES512;
use Cose\Algorithm\Signature\EdDSA\Ed25519;
use Cose\Algorithm\Signature\RSA\RS256;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Class Passkeys
 *
 * WebAuthn/FIDO2 (#353): Registrieren, Anmelden, Verwalten und Entziehen.
 *
 * ## Warum eine Bibliothek
 *
 * Der Kern hatte bis hierher keine einzige Laufzeit-Abhängigkeit, und OIDC
 * wurde entsprechend von Hand gebaut. WebAuthn ist eine andere Größenordnung:
 * CBOR-Dekodierung, COSE-Schlüssel, Attestation-Formate, Signaturprüfung. Das
 * ist kryptografischer Code, und Fehler darin sind **still** — sie fallen
 * nicht als Ausnahme auf, sondern als eine Anmeldung, die durchgeht, obwohl
 * sie es nicht sollte.
 *
 * Bei der Auswahl kam ein Kriterium hinzu, das vorher nicht auf der Liste
 * stand: **Gibt es einen Weg, Lücken zu melden?** `lbuchs/webauthn` war der
 * naheliegendste Treffer, hat aber weder SECURITY.md noch privates Reporting —
 * und bei der Prüfung fand sich dort ein zu lockerer Origin-Vergleich. Ohne
 * Meldeweg ist eine Bibliothek für den Anmeldepfad disqualifiziert, egal wie
 * gut der Code aussieht.
 *
 * ## Die RP-ID wird nicht aus der Anfrage genommen
 *
 * Sie ist die Bindung zwischen Passkey und Domain. Käme sie aus `HTTP_HOST`,
 * bestimmte der Aufrufer, wofür sein Passkey gilt. Vorrang hat deshalb die
 * konfigurierte `base_url`; erst danach der über App\Security\TrustedHost
 * geprüfte Host, und auch der nur ohne Port.
 *
 * ## Was bewusst NICHT gemacht wird
 *
 * Keine Attestation-Prüfung gegen die FIDO-Metadaten (`attestation: none`).
 * Ein Verband, der Züchterdaten pflegt, hat kein Interesse daran,
 * Authenticator-Modelle vorzuschreiben — und eine halbherzige
 * Attestation-Prüfung ist schlechter als gar keine, weil sie Sicherheit
 * behauptet, die sie nicht liefert.
 */
final class Passkeys {

    /** Wie lange eine Challenge gilt (Sekunden). */
    private const GUELTIGKEIT = 300;

    /** Sitzungsschlüssel für die laufende Zeremonie. */
    private const SESSION_REG = 'passkey_registrierung';
    private const SESSION_ANM = 'passkey_anmeldung';

    // ---- Ist das überhaupt nutzbar? -------------------------------------

    /**
     * Passkeys brauchen einen sicheren Kontext. Über eine ungesicherte
     * Verbindung verweigert der Browser die Zeremonie ohnehin - dann soll die
     * Oberfläche den Schalter gar nicht erst anbieten, statt den Benutzer in
     * einen Fehler laufen zu lassen.
     */
    public static function verfuegbar(): bool {
        return self::istSicher() && class_exists(PublicKeyCredentialCreationOptions::class);
    }

    private static function istSicher(): bool {
        if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off') {
            return true;
        }
        if ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        // localhost gilt dem Browser als sicherer Kontext - sonst waere
        // Entwicklung ohne Zertifikat unmoeglich.
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $host = explode(':', $host)[0];
        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
    }

    /**
     * Die RP-ID: die Domain, an die ein Passkey gebunden wird.
     *
     * NIE ungeprüft aus der Anfrage - siehe Klassenkommentar.
     */
    public static function rpId(): string {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'base_url' LIMIT 1");
        $baseUrl = trim((string)($stmt->fetchColumn() ?: ''));

        if ($baseUrl === '') {
            $baseUrl = trim((string)(getenv('APP_URL') ?: ''));
        }

        if ($baseUrl !== '') {
            $host = parse_url($baseUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return strtolower($host);
            }
        }

        // Rückfall: der geprüfte Host, ohne Port. Ein Port gehört nicht in die
        // RP-ID - die Spezifikation kennt dort nur die Domain.
        $host = TrustedHost::current();
        $host = strtolower(trim(explode(':', (string)$host)[0]));

        return $host;
    }

    // ---- Registrierung ---------------------------------------------------

    /**
     * Erzeugt die Optionen für eine neue Registrierung und legt sie in der
     * Sitzung ab.
     *
     * Die Challenge NICHT im Formular mitzugeben ist wesentlich: Sie muss aus
     * einer Quelle stammen, die der Aufrufer nicht bestimmt, sonst prüft die
     * Zeremonie am Ende gegen einen Wert, den der Angreifer gesetzt hat.
     *
     * @return string JSON für navigator.credentials.create()
     */
    public static function registrierungsOptionen(int $userId, string $benutzername, string $anzeigename): string {
        $vorhandene = [];
        foreach (self::fuerBenutzer($userId) as $eintrag) {
            $vorhandene[] = PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                (string)base64_decode($eintrag['credential_id'], true)
            );
        }

        $optionen = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create(self::siteName(), self::rpId()),
            PublicKeyCredentialUserEntity::create(
                $benutzername,
                self::benutzerHandle($userId),
                $anzeigename !== '' ? $anzeigename : $benutzername
            ),
            random_bytes(32),
            [
                PublicKeyCredentialParameters::create('public-key', ES256::ID),
                PublicKeyCredentialParameters::create('public-key', ES384::ID),
                PublicKeyCredentialParameters::create('public-key', ES512::ID),
                PublicKeyCredentialParameters::create('public-key', Ed25519::ID),
                PublicKeyCredentialParameters::create('public-key', RS256::ID),
            ],
            AuthenticatorSelectionCriteria::create(
                null,
                // "preferred", nicht "required": Ein Authenticator ohne
                // Benutzerverifikation ist als ZWEITER Faktor voellig in
                // Ordnung; erzwingen wuerde aeltere Sicherheitsschluessel
                // aussperren. Fuer den passwortlosen Weg wird die Verifikation
                // beim Anmelden geprueft, nicht hier.
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
                // Discoverable, damit derselbe Passkey spaeter auch
                // passwortlos taugt. "preferred", damit die Registrierung
                // nicht scheitert, wenn der Schluessel keinen Platz mehr hat.
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED
            ),
            // Keine Attestation - siehe Klassenkommentar.
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            // Schon registrierte Schluessel ausschliessen, damit derselbe
            // Authenticator nicht zweimal fuer dasselbe Konto landet.
            $vorhandene,
            self::GUELTIGKEIT * 1000
        );

        $_SESSION[self::SESSION_REG] = [
            'optionen' => self::serializer()->serialize($optionen, 'json'),
            'user_id'  => $userId,
            'bis'      => time() + self::GUELTIGKEIT,
        ];

        return self::serializer()->serialize($optionen, 'json');
    }

    /**
     * Prüft die Antwort des Browsers und legt den Passkey ab.
     *
     * @throws \RuntimeException bei jedem Fehlschlag - die Meldung ist für den
     *         Benutzer gedacht und nennt nie Interna.
     */
    public static function registrierungAbschliessen(string $antwortJson, string $bezeichnung): void {
        $zeremonie = $_SESSION[self::SESSION_REG] ?? null;
        unset($_SESSION[self::SESSION_REG]);

        if (!is_array($zeremonie) || ($zeremonie['bis'] ?? 0) < time()) {
            throw new \RuntimeException('Die Anmeldeanfrage ist abgelaufen. Bitte erneut versuchen.');
        }

        $optionen = self::serializer()->deserialize(
            (string)$zeremonie['optionen'],
            PublicKeyCredentialCreationOptions::class,
            'json'
        );

        $credential = self::serializer()->deserialize($antwortJson, PublicKeyCredential::class, 'json');
        $antwort = $credential->response;
        if (!$antwort instanceof AuthenticatorAttestationResponse) {
            throw new \RuntimeException('Der Sicherheitsschlüssel hat unerwartet geantwortet.');
        }

        $validator = AuthenticatorAttestationResponseValidator::create(
            self::zeremonieFabrik()->creationCeremony()
        );

        try {
            $datensatz = $validator->check($antwort, $optionen, self::rpId());
        } catch (\Throwable $e) {
            AuditLogger::log(
                'Passkey-Registrierung abgelehnt',
                'security',
                'Benutzer-ID ' . (int)$zeremonie['user_id'] . ': ' . $e->getMessage()
            );
            throw new \RuntimeException('Der Sicherheitsschlüssel konnte nicht überprüft werden.');
        }

        self::speichern((int)$zeremonie['user_id'], $datensatz, $bezeichnung);
    }

    // ---- Anmeldung -------------------------------------------------------

    /**
     * Optionen für eine Anmeldung.
     *
     * Ohne Benutzer-ID (passwortloser Weg) bleibt `allowCredentials` LEER -
     * der Browser sucht den passenden Passkey selbst. Eine Liste dort wäre
     * eine Benutzernamen-Auskunft: Wer fragt, erführe, welche Schlüssel es zu
     * einem Konto gibt und ob es das Konto überhaupt gibt.
     */
    public static function anmeldeOptionen(?int $userId = null): string {
        $erlaubt = [];
        if ($userId !== null) {
            foreach (self::fuerBenutzer($userId) as $eintrag) {
                $erlaubt[] = PublicKeyCredentialDescriptor::create(
                    PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                    (string)base64_decode($eintrag['credential_id'], true)
                );
            }
        }

        $optionen = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            self::rpId(),
            $erlaubt,
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            self::GUELTIGKEIT * 1000
        );

        $_SESSION[self::SESSION_ANM] = [
            'optionen' => self::serializer()->serialize($optionen, 'json'),
            'user_id'  => $userId,
            'bis'      => time() + self::GUELTIGKEIT,
        ];

        return self::serializer()->serialize($optionen, 'json');
    }

    /**
     * Prüft eine Anmeldung und liefert die Benutzer-ID.
     *
     * @throws \RuntimeException bei jedem Fehlschlag.
     */
    public static function anmeldungPruefen(string $antwortJson): int {
        $zeremonie = $_SESSION[self::SESSION_ANM] ?? null;
        unset($_SESSION[self::SESSION_ANM]);

        if (!is_array($zeremonie) || ($zeremonie['bis'] ?? 0) < time()) {
            throw new \RuntimeException('Die Anmeldeanfrage ist abgelaufen. Bitte erneut versuchen.');
        }

        $optionen = self::serializer()->deserialize(
            (string)$zeremonie['optionen'],
            PublicKeyCredentialRequestOptions::class,
            'json'
        );

        $credential = self::serializer()->deserialize($antwortJson, PublicKeyCredential::class, 'json');
        $antwort = $credential->response;
        if (!$antwort instanceof AuthenticatorAssertionResponse) {
            throw new \RuntimeException('Der Sicherheitsschlüssel hat unerwartet geantwortet.');
        }

        $eintrag = self::nachCredentialId($credential->rawId);
        if ($eintrag === null) {
            // Bewusst dieselbe Meldung wie bei einer fehlgeschlagenen Pruefung:
            // Ein eigener Text hier verriete, ob es den Schluessel gibt.
            throw new \RuntimeException('Anmeldung mit diesem Sicherheitsschlüssel nicht möglich.');
        }

        $quelle = self::serializer()->deserialize(
            (string)$eintrag['credential'],
            PublicKeyCredentialSource::class,
            'json'
        );

        $validator = AuthenticatorAssertionResponseValidator::create(
            self::zeremonieFabrik()->requestCeremony()
        );

        // Das ERWARTETE Benutzer-Handle, nicht das aus der Antwort.
        //
        // Hier stand urspruenglich $antwort->userHandle - also der Wert aus
        // der Antwort als sein eigener Erwartungswert. Das ist in zweierlei
        // Hinsicht falsch:
        //
        // 1. Ein Schluessel, der das Credential nicht auffindbar ablegt
        //    (residentKey ist PREFERRED, aeltere Sticks oder solche ohne
        //    freien Speicher tun das), liefert userHandle = null. CheckUserHandle
        //    nimmt dann den else-Zweig, der genau dieses null-Feld als NICHT
        //    leer verlangt - logisch unerfuellbar. Der Schluessel liesse sich
        //    registrieren und nie wieder benutzen.
        // 2. Selbst wenn ein Handle kaeme, praefte man es gegen sich selbst.
        //
        // Mit dem erwarteten Handle greift der andere Zweig: Er prueft, dass
        // der GESPEICHERTE Schluessel zu diesem Konto gehoert, und zusaetzlich
        // die Antwort, falls sie ein Handle mitbringt. Also strenger, nicht
        // nachsichtiger.
        $erwartetesHandle = self::benutzerHandle((int)$eintrag['user_id']);

        try {
            $aktualisiert = $validator->check(
                $quelle,
                $antwort,
                $optionen,
                self::rpId(),
                $erwartetesHandle
            );
        } catch (\Throwable $e) {
            AuditLogger::log(
                'Passkey-Anmeldung abgelehnt',
                'security',
                'Benutzer-ID ' . (int)$eintrag['user_id'] . ': ' . $e->getMessage()
            );
            throw new \RuntimeException('Anmeldung mit diesem Sicherheitsschlüssel nicht möglich.');
        }

        // Wurde die Zeremonie fuer einen BESTIMMTEN Benutzer eroeffnet, muss
        // der Schluessel auch zu ihm gehoeren. Ohne diese Pruefung koennte
        // jemand mit einem eigenen Passkey den zweiten Faktor eines fremden
        // Kontos erfuellen, dessen Passwort er kennt.
        if ($zeremonie['user_id'] !== null && (int)$zeremonie['user_id'] !== (int)$eintrag['user_id']) {
            AuditLogger::log(
                'Passkey-Anmeldung abgelehnt',
                'security',
                sprintf(
                    'Schlüssel gehört Benutzer-ID %d, die Zeremonie lief für %d.',
                    (int)$eintrag['user_id'],
                    (int)$zeremonie['user_id']
                )
            );
            throw new \RuntimeException('Anmeldung mit diesem Sicherheitsschlüssel nicht möglich.');
        }

        self::zaehlerUndZeitpunktSchreiben((int)$eintrag['id'], $aktualisiert);

        return (int)$eintrag['user_id'];
    }

    // ---- Verwaltung ------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public static function fuerBenutzer(int $userId): array {
        $stmt = Database::getInstance()->prepare(
            "SELECT id, credential_id, label, created_at, last_used_at
               FROM user_passkeys WHERE user_id = ? ORDER BY created_at"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function anzahl(int $userId): int {
        $stmt = Database::getInstance()->prepare("SELECT COUNT(*) FROM user_passkeys WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Entzieht einen Passkey.
     *
     * Die Benutzer-ID steht in der WHERE-Klausel, nicht in einer vorherigen
     * Prüfung: Sonst hinge die Berechtigung an der Reihenfolge zweier
     * Abfragen, und ein späterer Umbau könnte sie trennen.
     */
    public static function entziehen(int $userId, int $passkeyId): bool {
        $stmt = Database::getInstance()->prepare(
            "DELETE FROM user_passkeys WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$passkeyId, $userId]);

        if ($stmt->rowCount() > 0) {
            AuditLogger::log('Passkey entzogen', 'security', "Passkey-ID {$passkeyId}");
            return true;
        }
        return false;
    }

    // ---- Innereien -------------------------------------------------------

    /**
     * Die Zeremonie-Fabrik mit UNSEREN Algorithmen.
     *
     * Ohne setAlgorithmManager() setzt die Fabrik von sich aus nur ES256 und
     * RS256 (CeremonyStepManagerFactory Zeile 52). Die Registrierung bietet
     * aber fuenf an - ES256/384/512, RS256 und Ed25519 -, und CheckAlgorithm
     * prueft dort gegen genau diese Liste, laesst also Ed25519 durch.
     *
     * Die Folge waere die unangenehmste Sorte Fehler: Ein SoloKey oder
     * Nitrokey liesse sich anstandslos registrieren und beim ersten
     * Anmeldeversuch abweisen ("Unsupported algorithm"), ohne dass der
     * Benutzer versteht, warum. Registrierung und Anmeldung muessen dieselbe
     * Liste kennen - deshalb steht sie an einer Stelle.
     */
    private static function zeremonieFabrik(): CeremonyStepManagerFactory {
        $fabrik = new CeremonyStepManagerFactory();
        $fabrik->setAlgorithmManager(self::algorithmen());
        return $fabrik;
    }

    /** Die angebotenen Signaturverfahren - Registrierung wie Anmeldung. */
    private static function algorithmen(): AlgorithmManager {
        // create() nimmt KEINE Argumente - die Algorithmen kommen ueber add().
        // Ein Manager, dem man sie als Array uebergibt, kennt anschliessend
        // gar keine, und die Anmeldung schluege fuer JEDEN Schluessel fehl.
        return AlgorithmManager::create()->add(
            ES256::create(),
            ES384::create(),
            ES512::create(),
            RS256::create(),
            Ed25519::create()
        );
    }

    private static function serializer(): \Symfony\Component\Serializer\SerializerInterface {
        static $serializer = null;
        if ($serializer === null) {
            $serializer = (new WebauthnSerializerFactory(self::algorithmen()))->create();
        }
        return $serializer;
    }

    /**
     * Das Benutzer-Handle. Bewusst NICHT die Benutzer-ID im Klartext: Es
     * landet auf dem Authenticator und kann von dort ausgelesen werden.
     * Ein HMAC über die ID mit dem Anwendungsschlüssel ist stabil, ohne
     * etwas über den Bestand zu verraten.
     */
    private static function benutzerHandle(int $userId): string {
        $schluessel = (string)(defined('APP_KEY') ? constant('APP_KEY') : '');
        return hash_hmac('sha256', 'passkey-user:' . $userId, $schluessel, true);
    }

    private static function siteName(): string {
        $stmt = Database::getInstance()->query(
            "SELECT setting_value FROM settings WHERE setting_key = 'site_name' LIMIT 1"
        );
        $name = trim((string)($stmt->fetchColumn() ?: ''));
        return $name !== '' ? $name : 'Hengstverzeichnis';
    }

    private static function speichern(int $userId, object $datensatz, string $bezeichnung): void {
        $bezeichnung = trim($bezeichnung);
        if ($bezeichnung === '') {
            $bezeichnung = 'Sicherheitsschlüssel';
        }
        $bezeichnung = mb_substr($bezeichnung, 0, 100);

        $stmt = Database::getInstance()->prepare(
            "INSERT INTO user_passkeys (user_id, credential_id, credential, label, sign_count, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $userId,
            base64_encode($datensatz->publicKeyCredentialId),
            self::serializer()->serialize($datensatz, 'json'),
            $bezeichnung,
            (int)$datensatz->counter,
        ]);

        AuditLogger::log('Passkey registriert', 'security', "Bezeichnung: {$bezeichnung}");
    }

    /** @return array<string, mixed>|null */
    private static function nachCredentialId(string $rawId): ?array {
        $stmt = Database::getInstance()->prepare(
            "SELECT id, user_id, credential FROM user_passkeys WHERE credential_id = ? LIMIT 1"
        );
        $stmt->execute([base64_encode($rawId)]);
        $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $zeile ?: null;
    }

    /**
     * Signaturzähler fortschreiben.
     *
     * Der Zähler ist die Klon-Erkennung der Spezifikation: Bleibt er stehen
     * oder faellt zurueck, ist der Schluessel womoeglich kopiert. Die Pruefung
     * darauf macht die Bibliothek; hier wird der neue Stand nur festgehalten,
     * denn ohne Fortschreiben liefe sie ins Leere.
     */
    private static function zaehlerUndZeitpunktSchreiben(int $id, object $datensatz): void {
        $stmt = Database::getInstance()->prepare(
            "UPDATE user_passkeys SET sign_count = ?, credential = ?, last_used_at = NOW() WHERE id = ?"
        );
        $stmt->execute([
            (int)$datensatz->counter,
            self::serializer()->serialize($datensatz, 'json'),
            $id,
        ]);
    }
}
