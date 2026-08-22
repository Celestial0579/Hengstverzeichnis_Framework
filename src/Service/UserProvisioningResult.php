<?php
// src/Service/UserProvisioningResult.php

namespace App\Service;

/**
 * Ergebnis eines Anlegeversuchs (#384).
 *
 * Warum ein Objekt und kein Zahlen- oder Array-Rueckgabewert: Ein `int` waere
 * mehrdeutig (0 = Fehler? oder eine ID?), ein Array laedt dazu ein, den
 * Fehlerzweig zu vergessen. Hier muss der Aufrufer sich entscheiden.
 */
final class UserProvisioningResult {

    /**
     * @param int $userId 0, wenn nichts angelegt wurde
     * @param array<int, string> $errors leer, wenn es geklappt hat
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $errors = []
    ) {}

    public function erfolgreich(): bool {
        return $this->userId > 0 && $this->errors === [];
    }
}
