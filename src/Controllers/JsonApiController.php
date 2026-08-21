<?php
// src/Controllers/JsonApiController.php

namespace App\Controllers;

use App\Security\ApiKey;

/**
 * Gemeinsame Grundlage aller schlüsselgeschützten JSON-Endpunkte.
 *
 * Herausgezogen, als mit `/api/stats` (#270) ein zweiter solcher Endpunkt
 * dazukam: Authentifizierung, Rechteprüfung und Antwortform sind bei einer
 * API keine Nebensache, die man je Controller nachbaut. Läge das Lesen des
 * Bearer-Headers an zwei Stellen, würde eine Härtung irgendwann nur an einer
 * davon ankommen - genau die Fehlerklasse, gegen die die Lehre
 * "eine-regel-eine-stelle" existiert.
 *
 * Die Regeln selbst sind unverändert die von `/api/horses` (#47, #114):
 *
 * - Schlüssel ausschließlich über `Authorization: Bearer ...`, bewusst kein
 *   `?api_key=`-Fallback - Query-Parameter landen in Server-Logs, Referrern
 *   und Browser-History.
 * - Identische 401-Antwort für "kein Header" und "ungültiger/widerrufener
 *   Schlüssel", damit die API kein Orakel dafür wird, welche Schlüsselwerte
 *   existieren.
 * - Ein Schlüssel darf höchstens das, was sein Besitzer aktuell selbst darf
 *   (live geprüfte Schnittmenge aus Gruppenrechten und Scope, siehe
 *   ApiKey::permits()).
 */
abstract class JsonApiController extends BaseController {

    /**
     * Der authentifizierte Schlüssel des aktuellen Requests.
     *
     * @var array{id: int, user_id: int, scope: array<int, string>|null}|null
     */
    protected ?array $apiKey = null;

    /**
     * Erzwingt einen gültigen API-Schlüssel. Bricht den Request andernfalls mit
     * 401 ab - bewusst mit identischer Antwort für "kein Header" und
     * "ungültiger/widerrufener Schlüssel", damit die API kein Orakel dafür
     * wird, welche Schlüsselwerte existieren.
     */
    protected function requireApiKey(): void {
        $token = self::readBearerToken();

        if ($token !== null) {
            $key = ApiKey::authenticate($token);
            if ($key !== null) {
                $this->apiKey = $key;

                // Hinweis an die Gegenstelle, solange der Schluessel noch
                // gilt, aber bald ablaeuft (#340). Ein Zugang, der ohne
                // Vorwarnung stehenbleibt, faellt erst auf, wenn schon etwas
                // kaputt ist - und dann sucht jemand den Fehler an der
                // falschen Stelle.
                $restTage = ApiKey::daysUntilExpiry($key);
                if ($restTage !== null && $restTage <= ApiKey::EXPIRY_WARNING_DAYS) {
                    header('X-Api-Key-Expires-At: ' . $key['expires_at']);
                    header('X-Api-Key-Expires-In-Days: ' . $restTage);
                    header(sprintf(
                        'Warning: 299 - "API-Schluessel laeuft in %d Tag(en) ab (%s). Bitte unter /api-keys einen neuen ausstellen."',
                        $restTage,
                        $key['expires_at']
                    ));
                }
                return;
            }
        }

        header('WWW-Authenticate: Bearer realm="api"');
        $this->respondJson([
            'error' => 'unauthorized',
            'message' => 'Gültiger API-Schlüssel erforderlich: Header "Authorization: Bearer <Schlüssel>". Schlüssel werden unter /api-keys verwaltet.',
        ], 401);
    }

    /**
     * Liest den Schlüssel aus dem Authorization-Header. getallheaders() steht
     * je nach SAPI nicht zur Verfügung, deshalb zusätzlich der von PHP/Apache
     * gefüllte $_SERVER-Weg (HTTP_AUTHORIZATION bzw. das von manchen
     * Konfigurationen genutzte REDIRECT_HTTP_AUTHORIZATION).
     */
    protected static function readBearerToken(): ?string {
        $header = '';

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = (string)$value;
                    break;
                }
            }
        }

        if ($header === '') {
            $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }

        if ($header === '' || !preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Effektive Rechteprüfung für den authentifizierten Schlüssel: Scope UND
     * aktuelle Rechte des Besitzers müssen die Aktion erlauben. Ersetzt in
     * diesen Controllern bewusst BaseController::hasPermission() - dort hängt
     * die Prüfung an der Session, die es bei einem API-Zugriff nicht gibt.
     */
    protected function apiCan(string $module, string $action): bool {
        return $this->apiKey !== null && ApiKey::permits($this->apiKey, $module, $action);
    }

    /**
     * Einheitliche JSON-Antwort.
     *
     * Bewusst OHNE `Access-Control-Allow-Origin: *`: Seit die API einen
     * Schlüssel verlangt, wäre ein Wildcard-CORS-Header eine Einladung, den
     * Schlüssel in Browser-JavaScript einzubetten - dort ist er für jeden
     * Besucher auslesbar. Serverseitige Aufrufe (der vorgesehene Weg für
     * Drittsysteme) unterliegen keiner Same-Origin-Policy und sind davon nicht
     * betroffen. Wer die API wirklich aus dem Browser heraus nutzen will,
     * sollte sie hinter einem eigenen Backend-Proxy kapseln, statt den
     * Schlüssel auszuliefern.
     *
     * `Cache-Control: no-store` verhindert, dass rechtegebundene Antworten in
     * gemeinsam genutzten Caches (Proxys) landen und dort von jemandem gelesen
     * werden, dessen Schlüssel weniger darf.
     *
     * @param array<string, mixed> $payload
     */
    protected function respondJson(array $payload, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
