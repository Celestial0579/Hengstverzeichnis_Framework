<?php
// tests/Integration/KontoTestbestand.php

namespace Tests\Integration;

use App\Database;
use PDO;
use PDOException;

/**
 * Gemeinsamer Aufbau der Kontotests (Audit M15, M16, M17, N10, N52, N53,
 * N60): frisches Schema, Konten per INSERT, Aufräumen im tearDown.
 *
 * Das Schema wird neu angelegt wie in ApiKeyLifecycleTest - die Klassen
 * dürfen nicht davon abhängen, ob vor ihnen zufällig eine andere lief.
 */
trait KontoTestbestand {

    private static PDO $db;

    public static function setUpBeforeClass(): void {
        if (!defined('DB_HOST')) {
            self::markTestSkipped('Keine Test-Datenbank konfiguriert (DB_HOST fehlt) - siehe tests/bootstrap.php.');
        }

        $setupPdo = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($setupPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $setupPdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $setupPdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        try {
            $setupPdo->exec(file_get_contents(__DIR__ . '/../../database/schema.sql'));
        } catch (PDOException $e) {
            // Ignorieren, analog zu SetupController::provision()
        }

        self::$db = Database::getInstance();
    }

    /** @param array<string, mixed> $spalten */
    private function kontoAnlegen(string $name, array $spalten = []): int {
        $spalten += [
            'username' => $name,
            'email' => $name . '@example.org',
            'password_hash' => password_hash('Passwort123!', PASSWORD_DEFAULT),
        ];
        $namen = array_keys($spalten);
        $stmt = self::$db->prepare(sprintf(
            'INSERT INTO users (%s) VALUES (%s)',
            implode(', ', array_map(static fn(string $n): string => "`{$n}`", $namen)),
            implode(', ', array_fill(0, count($namen), '?'))
        ));
        $stmt->execute(array_values($spalten));
        return (int)self::$db->lastInsertId();
    }

    /** @return array<string, mixed> */
    private function zeile(int $userId): array {
        $stmt = self::$db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $zeile = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($zeile, "Konto {$userId} nicht gefunden.");
        return $zeile;
    }

    private function passkeyAnlegen(int $userId): void {
        self::$db->prepare(
            "INSERT INTO user_passkeys (user_id, credential_id, credential, label, sign_count, created_at)
             VALUES (?, ?, '{\"test\":true}', 'IT-TEST', 0, NOW())"
        )->execute([$userId, base64_encode('IT-TEST-' . bin2hex(random_bytes(12)))]);
    }

    private function auditAnzahl(string $aktion): int {
        $stmt = self::$db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = ?");
        $stmt->execute([$aktion]);
        return (int)$stmt->fetchColumn();
    }
}
