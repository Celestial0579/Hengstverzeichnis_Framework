<?php
// database/seed.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Dieses Skript darf nur über die CLI ausgeführt werden.');
}

require_once __DIR__ . '/cli-autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Database.php';

use App\Database;

try {
    $db = Database::getInstance();

    $email = 'admin@example.com';
    $username = 'admin';
    $password = 'admin123';
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $userId = (int)$user['id'];
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
        $stmt->execute([$passwordHash, $email]);
        echo "Admin-Benutzer erfolgreich aktualisiert!\n";
    } else {
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
        $stmt->execute([$username, $email, $passwordHash]);
        $userId = (int)$db->lastInsertId();
        echo "Admin-Benutzer erfolgreich erstellt!\n";
    }

    // Mitgliedschaft in der Gruppe `admin` sicherstellen (#66, einziges Rechtesystem).
    $adminGroupId = $db->query("SELECT id FROM `groups` WHERE slug = 'admin'")->fetchColumn();
    if ($adminGroupId) {
        $stmt = $db->prepare("INSERT IGNORE INTO user_groups (user_id, group_id) VALUES (?, ?)");
        $stmt->execute([$userId, $adminGroupId]);
    }

    echo "-----------------------------------\n";
    echo "Login-Daten:\n";
    echo "E-Mail:   " . $email . "\n";
    echo "Passwort: " . $password . "\n";
    echo "-----------------------------------\n";

} catch (Exception $e) {
    echo "Fehler beim Erstellen des Admin-Benutzers: " . $e->getMessage() . "\n";
}
