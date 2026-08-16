<?php
// src/Router.php

namespace App;

/**
 * Class Router
 *
 * Zentrale Routing-Komponente des Frameworks.
 * Verwalte HTTP-Routen (GET & POST), löst Anfragen basierend auf der URL auf
 * und bietet statische Hilfsmethoden für den CSRF-Sicherheitsschutz (Cross-Site Request Forgery).
 */
class Router {
    /**
     * Registrierte Routen der Anwendung.
     * @var array
     */
    private array $routes = [];

    /**
     * Registriert eine neue HTTP-GET Route.
     *
     * @param string $path Ziel-Pfad (z. B. '/katalog')
     * @param string|array|\Closure $callback Controller-Array [Class, 'method'] oder Callable
     */
    public function get(string $path, string|array|\Closure $callback): void {
        $this->addRoute('GET', $path, $callback);
    }

    /**
     * Registriert eine neue HTTP-POST Route.
     *
     * @param string $path Ziel-Pfad (z. B. '/login')
     * @param string|array|\Closure $callback Controller-Array [Class, 'method'] oder Callable
     */
    public function post(string $path, string|array|\Closure $callback): void {
        $this->addRoute('POST', $path, $callback);
    }

    /**
     * Registriert eine dauerhafte GET-Weiterleitung von einem alten auf einen
     * neuen Pfad (#171). Der Query-String wird unverändert mitgenommen -
     * dispatch() verwirft ihn vor dem Routen-Match, deshalb wird er hier aus
     * $_SERVER wieder angehängt. Standard 301 (permanent): gedruckte QR-Codes
     * und exportierte PDFs mit alten URLs sollen dauerhaft weiterfunktionieren.
     *
     * @param string $from Alter Pfad (z. B. '/hengst')
     * @param string $to Neuer Pfad (z. B. '/horse')
     * @param int $statusCode HTTP-Statuscode, Standard 301
     */
    public function redirect(string $from, string $to, int $statusCode = 301): void {
        $this->get($from, function () use ($to, $statusCode): void {
            $query = (string)($_SERVER['QUERY_STRING'] ?? '');
            header('Location: ' . $to . ($query !== '' ? '?' . $query : ''), true, $statusCode);
            exit;
        });
    }

    /**
     * Fügt eine Route der internen Routen-Tabelle hinzu.
     *
     * @param string $method HTTP-Methode ('GET' oder 'POST')
     * @param string $path Relativer URL-Pfad
     * @param string|array|\Closure $callback Auszuführender Controller/Funktion
     */
    private function addRoute(string $method, string $path, string|array|\Closure $callback): void {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'callback' => $callback
        ];
    }

    /**
     * Verarbeitet eine eingehende HTTP-Anfrage und führt den passenden Controller aus.
     *
     * @param string $uri Eingehende URL (z. B. $_SERVER['REQUEST_URI'])
     * @param string $requestMethod HTTP-Methode (z. B. $_SERVER['REQUEST_METHOD'])
     */
    public function dispatch(string $uri, string $requestMethod): void {
        // Query-String (Parameter nach '?') abtrennen
        $uri = strtok($uri, '?');

        // Pfad normalisieren (abschließenden Slash entfernen, außer bei '/')
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        // Routen-Tabelle nach Übereinstimmung durchsuchen
        foreach ($this->routes as $route) {
            if ($route['method'] === $requestMethod && $route['path'] === $uri) {
                $callback = $route['callback'];

                if (is_array($callback)) {
                    // Controller-Instanz erstellen und Methode aufrufen
                    $controller = new $callback[0]();
                    $method = $callback[1];
                    call_user_func([$controller, $method]);
                    return;
                }

                if (is_callable($callback)) {
                    call_user_func($callback);
                    return;
                }
            }
        }

        // Falls keine passende Route gefunden wurde: 404 Fehlerseite rendern
        $publicController = new \App\Controllers\PublicController();
        $publicController->renderNotFound(\App\I18n\Translator::t('errors.404_route_not_found', ['path' => htmlspecialchars($uri)]));
    }

    /**
     * Generiert ein cryptographisch sicheres CSRF-Sicherheits-Token in der Benutzersitzung.
     *
     * @return string 64-Zeichen Hexadezimaler CSRF-Token
     */
    public static function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Überprüft ein übergebenes Formular-Token gegen den in der Session gespeicherten CSRF-Token.
     * Verwendet hash_equals() gegen Timing-Angriffe.
     *
     * @param string|null $token Das aus $_POST übergebene Token
     * @return bool True, wenn der Token valide ist, sonst false
     */
    public static function verifyCsrfToken(?string $token): bool {
        if (!isset($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}
