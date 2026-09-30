<?php
// src/Service/UpdateLaeuftBereits.php

namespace App\Service;

/**
 * Ein Update, eine Reparatur oder ein manuelles Addon-Update läuft bereits
 * (Audit M44), oder dieser Prozess hat noch eine ältere Kern-Version geladen,
 * als auf der Platte liegt.
 *
 * Eigene Klasse, damit die Automatik sie vom echten Fehlschlag trennen kann:
 * Ein zweiter Cron-Lauf, der auf die Sperre trifft, ist kein Fehler, über
 * den die Admins eine Mail bekommen sollen, sondern schlicht überflüssig.
 */
final class UpdateLaeuftBereits extends \RuntimeException {
}
