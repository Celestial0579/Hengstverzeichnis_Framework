<?php
// src/Security/ApiKey.php

namespace App\Security;

use App\Database;
use App\Permission\GroupMembership;
use PDO;

/**
 * Class ApiKey
 *
 * Benutzergebundene API-Schlüssel für die JSON-API (`/api/...`, siehe
 * App\Controllers\ApiController und docs/api.md). Ersetzt den früheren
 * anonymen Zugriff: die API ist jetzt ausschließlich mit einem gültigen
 * Schlüssel erreichbar.
 *
 * Rechtemodell ("maximal die eigenen Rechte, weniger ist möglich"):
 * Die effektiven Rechte eines Schlüssels sind die SCHNITTMENGE aus
 *   (a) den aktuellen Rechten seines Besitzers (Gruppen/`admin`, live geprüft) und
 *   (b) dem beim Anlegen gewählten Scope (`scope_permissions`).
 * Daraus folgt beides bewusst:
 * - Ein Schlüssel kann NIE mehr dürfen als sein Besitzer. Verliert der Besitzer
 *   ein Recht (Gruppenwechsel, Rechteentzug), verliert es derselbe Schlüssel
 *   sofort mit - es wird nichts eingefroren, was zum Anlegezeitpunkt galt.
 * - Ein Schlüssel kann bewusst WENIGER dürfen (Least Privilege), z. B. ein
 *   reiner Lese-Schlüssel für ein Drittsystem, obwohl der Besitzer selbst
 *   Schreibrechte hat.
 *
 * Speicherung: Der Klartext-Schlüssel wird NIE gespeichert, nur sein
 * SHA-256-Hash (`token_hash`) - derselbe Grundsatz wie bei den 2FA-Backup-Codes.
 * Ein Datenbank-Leak gibt damit keine nutzbaren Schlüssel preis. Bewusst
 * SHA-256 statt password_hash(): der Schlüssel ist ein 256-Bit-Zufallswert,
 * kein vom Menschen gewähltes Passwort - er ist nicht erratbar, weshalb ein
 * absichtlich langsames Hash-Verfahren hier keinen Schutz hinzufügt, aber
 * jeden API-Request spürbar verlangsamen würde.
 */
final class ApiKey {

    /**
     * Obergrenze aktiver Schlüssel je Benutzer. Bewusst niedrig: ein Benutzer
     * braucht typischerweise einen Schlüssel je angebundenem System, und eine
     * kleine Zahl hält den Bestand überschaubar/widerrufbar.
     */
    public const MAX_KEYS_PER_USER = 5;

    /** Erkennbares Präfix im Klartext-Schlüssel (hilft z. B. Secret-Scannern). */
    private const TOKEN_PREFIX = 'hv_';

    /** 32 Byte = 256 Bit Entropie. */
    private const TOKEN_BYTES = 32;

    /**
     * Anzeige-Präfix (Klartext-Anfang), damit ein Benutzer seine Schlüssel in
     * der Übersicht auseinanderhalten kann, ohne dass der vollständige Wert
     * gespeichert werden muss.
     */
    private const DISPLAY_PREFIX_LENGTH = 11;

    private function __construct() {}

    /**
     * Erzeugt einen neuen Klartext-Schlüssel. Rückgabe ist der EINZIGE Moment,
     * in dem der Wert existiert - danach ist nur noch sein Hash bekannt.
     */
    public static function generateToken(): string {
        return self::TOKEN_PREFIX . bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    public static function hashToken(string $token): string {
        return hash('sha256', $token);
    }

    /**
     * Legt einen Schlüssel für $userId an.
     *
     * @param array<int, string>|null $scope Liste erlaubter "modul.aktion"-Paare;
     *        null = "alle Rechte des Besitzers" (dynamisch, kein Einfrieren).
     * @return array{ok: bool, token?: string, error?: string}
     */
    public static function create(int $userId, string $label, ?array $scope): array {
        $label = trim($label);
        if ($label === '') {
            return ['ok' => false, 'error' => 'missing_label'];
        }
        if (mb_strlen($label) > 100) {
            $label = mb_substr($label, 0, 100);
        }

        if (self::countActive($userId) >= self::MAX_KEYS_PER_USER) {
            return ['ok' => false, 'error' => 'limit_reached'];
        }

        // Ein Scope darf nur Rechte enthalten, die der Besitzer aktuell selbst
        // hat - andernfalls könnte man sich über einen Schlüssel Rechte
        // "erschleichen", die man in der UI nie hätte. Zusätzlich zur
        // Live-Prüfung in permits(), damit schon gar nichts Unerlaubtes in der
        // Datenbank landet.
        if ($scope !== null) {
            $scope = array_values(array_unique(array_filter(
                $scope,
                static fn($entry): bool => is_string($entry) && self::ownerHasScopeEntry($userId, $entry)
            )));
            if (empty($scope)) {
                return ['ok' => false, 'error' => 'empty_scope'];
            }
        }

        $token = self::generateToken();

        try {
            $stmt = Database::getInstance()->prepare(
                "INSERT INTO api_keys (user_id, label, token_hash, token_prefix, scope_permissions)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $userId,
                $label,
                self::hashToken($token),
                substr($token, 0, self::DISPLAY_PREFIX_LENGTH),
                $scope === null ? null : json_encode(array_values($scope)),
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }

        return ['ok' => true, 'token' => $token];
    }

    /**
     * Löst einen Klartext-Schlüssel auf. Liefert null, wenn er unbekannt oder
     * widerrufen ist bzw. sein Besitzer gelöscht/deaktiviert wurde.
     *
     * Die Suche erfolgt direkt über den indizierten Hash: bei einem
     * 256-Bit-Zufallswert gibt es nichts zu erraten, ein zeitkonstanter
     * Vergleich über alle Zeilen wäre reiner Aufwand ohne Schutzgewinn (analog
     * zur Suche nach einer Session-ID).
     *
     * @return array{id: int, user_id: int, scope: array<int, string>|null}|null
     */
    public static function authenticate(string $token): ?array {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT k.id, k.user_id, k.scope_permissions
                 FROM api_keys k
                 JOIN users u ON u.id = k.user_id AND u.deleted_at IS NULL
                 WHERE k.token_hash = ? AND k.revoked_at IS NULL
                 LIMIT 1"
            );
            $stmt->execute([self::hashToken($token)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null; // fail-closed
        }

        if (!$row) {
            return null;
        }

        self::touch((int)$row['id']);

        $scope = null;
        if ($row['scope_permissions'] !== null) {
            $decoded = json_decode((string)$row['scope_permissions'], true);
            // Fail-closed: ein unlesbarer Scope wird als "leer" behandelt
            // (permits() verweigert dann alles), nie als "alles erlaubt".
            $scope = is_array($decoded)
                ? array_values(array_filter($decoded, 'is_string'))
                : [];
        }

        return [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'scope' => $scope,
        ];
    }

    /**
     * Effektive Rechteprüfung: Scope UND aktuelle Rechte des Besitzers müssen
     * die Aktion erlauben (Schnittmenge, siehe Klassen-PHPDoc).
     *
     * @param array{user_id: int, scope: array<int, string>|null} $key
     */
    public static function permits(array $key, string $module, string $action): bool {
        $scope = $key['scope'] ?? null;
        if ($scope !== null && !in_array("{$module}.{$action}", $scope, true)) {
            return false;
        }

        return GroupMembership::hasPermission((int)$key['user_id'], $module, $action);
    }

    /**
     * Aktive (nicht widerrufene) Schlüssel eines Benutzers - ohne Geheimnisse,
     * nur Metadaten für die Verwaltungsansicht.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forUser(int $userId): array {
        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT id, label, token_prefix, scope_permissions, last_used_at, created_at
                 FROM api_keys
                 WHERE user_id = ? AND revoked_at IS NULL
                 ORDER BY created_at DESC"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function countActive(int $userId): int {
        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT COUNT(*) FROM api_keys WHERE user_id = ? AND revoked_at IS NULL"
            );
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            // Fail-closed: im Zweifel gilt das Limit als erreicht, statt
            // unbegrenzt weitere Schlüssel zuzulassen.
            return self::MAX_KEYS_PER_USER;
        }
    }

    /**
     * Widerruft einen Schlüssel. Die user_id-Bedingung stellt sicher, dass ein
     * Benutzer ausschließlich EIGENE Schlüssel widerrufen kann (IDOR-Schutz).
     * Bewusst ein Soft-Widerruf (`revoked_at`), damit der Vorgang
     * nachvollziehbar bleibt.
     */
    public static function revoke(int $userId, int $keyId): bool {
        try {
            $stmt = Database::getInstance()->prepare(
                "UPDATE api_keys SET revoked_at = NOW()
                 WHERE id = ? AND user_id = ? AND revoked_at IS NULL"
            );
            $stmt->execute([$keyId, $userId]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Alle Modul × Aktion-Paare, die $userId aktuell selbst besitzt - die
     * Auswahlmenge für den Scope beim Anlegen eines Schlüssels.
     *
     * @return array<int, string>
     */
    public static function availableScopeEntries(int $userId): array {
        $entries = [];
        foreach (\App\Permission\PermissionRegistry::modules() as $module => $definition) {
            foreach (array_keys($definition['actions']) as $action) {
                if (GroupMembership::hasPermission($userId, $module, $action)) {
                    $entries[] = "{$module}.{$action}";
                }
            }
        }
        return $entries;
    }

    private static function ownerHasScopeEntry(int $userId, string $entry): bool {
        if (!str_contains($entry, '.')) {
            return false;
        }
        [$module, $action] = explode('.', $entry, 2);
        if ($module === '' || $action === '') {
            return false;
        }
        return GroupMembership::hasPermission($userId, $module, $action);
    }

    private static function touch(int $keyId): void {
        try {
            $stmt = Database::getInstance()->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?");
            $stmt->execute([$keyId]);
        } catch (\Throwable $e) {
            // Reine Statistik - ein Fehler hier darf den API-Zugriff nie verhindern.
        }
    }
}
