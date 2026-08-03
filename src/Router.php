<?php
// src/Router.php

namespace App;

class Router {
    private array $routes = [];

    public function get(string $path, string|array $callback): void {
        $this->addRoute('GET', $path, $callback);
    }

    public function post(string $path, string|array $callback): void {
        $this->addRoute('POST', $path, $callback);
    }

    private function addRoute(string $method, string $path, string|array $callback): void {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'callback' => $callback
        ];
    }

    public function dispatch(string $uri, string $requestMethod): void {
        // Remove query string from URI
        $uri = strtok($uri, '?');
        // Basic normalization
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] === $requestMethod && $route['path'] === $uri) {
                $callback = $route['callback'];

                if (is_array($callback)) {
                    // It's a controller class and method e.g. [Controller::class, 'method']
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

        // Handle 404
        $publicController = new \App\Controllers\PublicController();
        $publicController->renderNotFound("Die angeforderte Adresse (" . htmlspecialchars($uri) . ") wurde auf dem Server nicht gefunden.");
    }

    // Helper for CSRF protection
    public static function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrfToken(?string $token): bool {
        if (!isset($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}
