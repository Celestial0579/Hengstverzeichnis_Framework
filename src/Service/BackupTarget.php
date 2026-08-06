<?php
// src/Service/BackupTarget.php

namespace App\Service;

/**
 * Interface BackupTarget
 *
 * Gemeinsame Schnittstelle für Backup-Ziele (#59, #93) - App\Service\BackupService
 * kennt nur diese drei Operationen und ist damit unabhängig vom konkreten
 * Ziel-Typ (S3-kompatibler Objektspeicher, FTPS, WebDAV). Implementierungen:
 * App\Service\S3Client, App\Service\FtpsClient, App\Service\WebDavClient.
 */
interface BackupTarget {

    /**
     * Lädt ein Objekt hoch (überschreibt ein ggf. bestehendes Objekt mit
     * demselben Schlüssel).
     */
    public function putObject(string $key, string $body, string $contentType = 'application/octet-stream'): void;

    public function deleteObject(string $key): void;

    /**
     * Listet Objekte unter einem Schlüssel-Präfix, aufsteigend sortiert
     * (siehe BackupService::applyRetention() - durch das
     * "backup-<ISO-Zeitstempel>"-Namensschema entspricht das der
     * chronologischen Reihenfolge, älteste zuerst).
     *
     * @return array<int, array{key: string}>
     */
    public function listObjects(string $prefix = ''): array;
}
