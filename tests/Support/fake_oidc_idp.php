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
    $tokenEmail = str_starts_with($code, 'email:') ? substr($code, strlen('email:')) : $email;

    $header = $b64url(json_encode(['alg' => 'none', 'typ' => 'JWT']));
    $payload = $b64url(json_encode([
        'iss' => $issuer,
        'aud' => $clientId,
        'exp' => time() + 300,
        'iat' => time(),
        'email' => $tokenEmail,
    ]));

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
