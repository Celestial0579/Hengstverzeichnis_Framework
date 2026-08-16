<?php
// src/Security/DbIdentifier.php

namespace App\Security;

/**
 * Prüfregeln für die Bestandteile der Datenbank-Verbindung, die sich NICHT
 * als Parameter binden lassen.
 *
 * Der Datenbankname landet in `CREATE DATABASE` und `DROP DATABASE`, Host und
 * Port im DSN - alle drei per Stringinterpolation, weil SQL für Bezeichner und
 * PDO für den DSN keine Platzhalter kennen. Die einzige Absicherung ist
 * deshalb eine Prüfung des Werts, bevor er verwendet wird.
 *
 * Sie steht hier und nicht im SetupController, weil sie genau einmal existieren
 * soll: Im Controller war sie an einen Zweig gebunden ("nur prüfen, wenn der
 * DB-Abschnitt im Formular sichtbar war"), und eine gesetzte
 * DB_HOST-Umgebungsvariable ließ sie für einen aus dem Formular stammenden
 * Datenbanknamen entfallen.
 *
 * Positivlisten, keine Sperrlisten: Was nicht ausdrücklich erlaubt ist, wird
 * abgelehnt.
 *
 * Alle Muster enden auf `\z`, nicht auf `$`. In PCRE erlaubt `$` einen
 * abschließenden Zeilenumbruch - "db\n" käme sonst durch eine Positivliste
 * durch, die Zeilenumbrüche gar nicht enthält. Genau daran ist beim Bau
 * dieser Klasse der erste Testlauf hängengeblieben.
 */
final class DbIdentifier {

    private function __construct() {}

    /**
     * MySQL erlaubt in Bezeichnern zwar mehr, aber alles darüber hinaus
     * bräuchte korrektes Quoting mit verdoppelten Backticks - und ein
     * Datenbankname mit Sonderzeichen ist kein Bedarf, den diese Anwendung
     * bedienen muss.
     */
    public static function isValidDatabaseName(string $name): bool {
        return preg_match('/^[A-Za-z0-9_]{1,64}\z/', $name) === 1;
    }

    /**
     * Rechnername, IPv4, IPv6 (auch in eckigen Klammern) oder ein absoluter
     * Pfad für Unix-Sockets - App\Database behandelt einen mit '/'
     * beginnenden Host als Socket.
     *
     * Entscheidend ist, was NICHT durchkommt: Semikolon und
     * Gleichheitszeichen hängten sonst weitere Parameter an den DSN
     * ("host=db;unix_socket=/pfad" o. ä.).
     */
    public static function isValidHost(string $host): bool {
        if ($host === '') {
            return false;
        }
        if (str_starts_with($host, '/')) {
            return preg_match('#^/[A-Za-z0-9._/-]+\z#', $host) === 1;
        }
        return preg_match('/^\[?[A-Za-z0-9._:-]+\]?\z/', $host) === 1;
    }

    public static function isValidPort(string $port): bool {
        if (preg_match('/^[0-9]{1,5}\z/', $port) !== 1) {
            return false;
        }
        $value = (int)$port;
        return $value >= 1 && $value <= 65535;
    }
}
