<?php
// tests/Support/send-update-notification.php
//
// Verschickt genau eine Update-Benachrichtigung und meldet auf stdout, ob es
// geklappt hat. Aufgerufen wird das Skript von
// tests/Integration/UpdateNotificationMailTest.php als eigener Prozess mit
// umgebogenem `sendmail_path`.
//
// Der eigene Prozess ist kein Umweg, sondern die Sache selbst: `sendmail_path`
// ist PHP_INI_SYSTEM und im laufenden Prozess nicht änderbar. Anders lässt
// sich der mail()-Versandweg nicht prüfen, ohne die zu prüfende Klasse
// umzubauen - und ein Versandweg, der in Installationen im Einsatz ist,
// gehört geprüft.

require __DIR__ . '/../bootstrap.php';

$mailer = new \App\Service\Mailer();

$ok = $mailer->sendUpdatesAvailableNotification(
    'update-mail-admin@example.com',
    '9.9.9',
    [],
    false
);

echo $ok ? "VERSAND=ok\n" : "VERSAND=fehlgeschlagen\n";
exit($ok ? 0 : 1);
