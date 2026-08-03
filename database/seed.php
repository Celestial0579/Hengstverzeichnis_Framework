<?php
// database/seed.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Database.php';

use App\Database;

try {
    $db = Database::getInstance();

    $email = 'admin@example.com';
    $username = 'admin';
    $password = 'admin123';
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $role = 'admin';

    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $stmt = $db->prepare("UPDATE users SET password_hash = ?, role = ? WHERE email = ?");
        $stmt->execute([$passwordHash, $role, $email]);
        echo "Admin-Benutzer erfolgreich aktualisiert!\n";
    } else {
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([$username, $email, $passwordHash, $role]);
        echo "Admin-Benutzer erfolgreich erstellt!\n";
    }

    echo "-----------------------------------\n";
    echo "Login-Daten:\n";
    echo "E-Mail:   " . $email . "\n";
    echo "Passwort: " . $password . "\n";
    echo "-----------------------------------\n";

} catch (Exception $e) {
    echo "Fehler beim Erstellen des Admin-Benutzers: " . $e->getMessage() . "\n";
}
