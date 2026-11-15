<?php
// tests/Support/fake_oidc_idp.php
//
// Router-Skript für `php -S`: minimaler Fake-OIDC-Identity-Provider für den
// Funktionstest OidcSsoConfiguredTest. Bildet das Authentik-Pfadschema nach
// (Issuer /application/o/<slug>/ mit trailing slash, Endpunkte providerweit
// unter /application/o/authorize|token/).
//
// Das ID-Token ist bewusst UNSIGNIERT ("alg":"none" mit Platzhalter-Signatur):
// Das dokumentierte Trust-Modell der App prüft keine JWT-Signatur, sondern
// verlässt sich darauf, dass das Token serverseitig vom issuer-geprüften
// Token-Endpunkt kommt (siehe App\Security\OidcIdToken) - genau dieser Pfad
// wird hier End-zu-End getestet.
//
// Konfiguration über Umgebungsvariablen (gesetzt von AuxiliaryServer):
//   FAKE_OIDC_ISSUER    Issuer-URL, die das Discovery-Dokument ausweist
//   FAKE_OIDC_BASE      Basis-URL dieses Servers (für die Endpunkte)
//   FAKE_OIDC_CLIENT_ID aud-Claim des ausgestellten Tokens
//   FAKE_OIDC_EMAIL     email-Claim des ausgestellten Tokens (Standardfall)
//
// Die E-Mail lässt sich zusätzlich PRO ANFRAGE steuern: Ein Autorisierungscode
// der Form "email:<adresse>" stellt genau diese Adresse im Token aus. Damit
// können die Abweisungsfälle des SSO-Logins (unbekannte Identität,
// unverifiziertes oder soft-gelöschtes Konto, siehe #216) unterschiedliche
// Identitäten gegen DIESELBE IdP-Instanz durchspielen - ohne je Testfall
// einen eigenen Server mit anderem FAKE_OIDC_EMAIL starten zu müssen.
//
// Beliebige Claims (Audit M19, N9): Ein Code der Form
// "claims:<base64url-JSON>" legt das JSON-Objekt über die Standardclaims;
// ein Wert null ENTFERNT den Schlüssel. So lassen sich fehlende, leere oder
// unbestätigte Adressen und amr/acr-Nachweise durchspielen.
//
// Bindung an einen Flow (GHSA-g8jp-xvcw-m27p): Ein echter Provider merkt
// sich beim Authorize nonce und code_challenge zum ausgestellten Code. Dieser
// Fake ist zustandslos, die Tests hängen beides deshalb an den Code an:
// "<code>~<base64url-JSON {nonce, code_challenge, code_challenge_method}>".
// Mit Bindung prüft der Token-Endpunkt den code_verifier nach S256 wie ein
// echter Provider (sonst invalid_grant) und schreibt den nonce ins Token.
// Ohne Bindung gibt es weder nonce noch PKCE-Prüfung. So verhält sich ein
// Code aus einem fremden Flow, und die App muss ihn abweisen.
//
// Jedes Token trägt standardmäßig `email_verified: true` und
// `sub: fake-<sha1(email)>` - auch bei der "email:"-Konvention. Ohne das
// würden seit Audit M19 alle Tokens im generischen Modus abgewiesen, und die
// Abweisungstests aus #216 blieben aus dem FALSCHEN Grund grün.

declare(strict_types=1);

$issuer = (string)getenv('FAKE_OIDC_ISSUER');
$base = (string)getenv('FAKE_OIDC_BASE');
$clientId = (string)getenv('FAKE_OIDC_CLIENT_ID');
$email = (string)getenv('FAKE_OIDC_EMAIL');

$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

$b64url = static function (string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
};

if ($path === '/application/o/test/.well-known/openid-configuration') {
    header('Content-Type: application/json');
    echo json_encode([
        'issuer' => $issuer,
        'authorization_endpoint' => $base . '/application/o/authorize/',
        'token_endpoint' => $base . '/application/o/token/',
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code'],
        'scopes_supported' => ['openid', 'profile', 'email'],
    ]);
    return true;
}

if ($path === '/application/o/token/' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Minimale Plausibilitätsprüfung wie ein echter Provider: ohne
    // Code/Client-Daten kein Token.
    if (empty($_POST['code']) || ($_POST['client_id'] ?? '') !== $clientId || empty($_POST['client_secret'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_request']);
        return true;
    }

    // E-Mail pro Anfrage aus dem Code ableiten (Konvention "email:<adresse>",
    // siehe Kopfkommentar) - alle anderen Codes erhalten FAKE_OIDC_EMAIL.
    $code = (string)$_POST['code'];
    $bindung = null;
    $trenner = strrpos($code, '~');
    if ($trenner !== false) {
        $bindung = json_decode((string)base64_decode(strtr(substr($code, $trenner + 1), '-_', '+/')), true);
        $code = substr($code, 0, $trenner);
        $verifier = (string)($_POST['code_verifier'] ?? '');
        if (!is_array($bindung)
            || ($bindung['code_challenge_method'] ?? '') !== 'S256'
            || $verifier === ''
            || !hash_equals((string)($bindung['code_challenge'] ?? ''), $b64url(hash('sha256', $verifier, true)))) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed']);
            return true;
        }
    }
    $tokenEmail = str_starts_with($code, 'email:') ? substr($code, strlen('email:')) : $email;

    $claims = [
        'iss' => $issuer,
        'aud' => $clientId,
        'exp' => time() + 300,
        'iat' => time(),
        'sub' => 'fake-' . sha1($tokenEmail),
        'email' => $tokenEmail,
        'email_verified' => true,
    ];
    if (is_array($bindung) && is_string($bindung['nonce'] ?? null) && $bindung['nonce'] !== '') {
        $claims['nonce'] = $bindung['nonce'];
    }
    if (str_starts_with($code, 'claims:')) {
        $roh = substr($code, strlen('claims:'));
        $extra = json_decode((string)base64_decode(strtr($roh, '-_', '+/')), true);
        if (!is_array($extra)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'invalid_grant']);
            return true;
        }
        foreach ($extra as $name => $wert) {
            if ($wert === null) {
                unset($claims[$name]);
            } else {
                $claims[$name] = $wert;
            }
        }
    }

    $header = $b64url(json_encode(['alg' => 'none', 'typ' => 'JWT']));
    $payload = $b64url(json_encode($claims));

    header('Content-Type: application/json');
    echo json_encode([
        'token_type' => 'Bearer',
        'id_token' => $header . '.' . $payload . '.unsigned',
    ]);
    return true;
}

http_response_code(404);
echo 'fake-oidc-idp: unbekannter Pfad ' . htmlspecialchars($path);
return true;
