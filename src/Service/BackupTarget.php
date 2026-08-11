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

    /**
     * Lädt den Inhalt einer lokalen Datei streamend als Objekt hoch (#237) -
     * semantisch identisch zu putObject(), aber ohne den Inhalt je als
     * Gesamtstring in den Speicher zu laden. Für die von
     * App\Service\BackupService streamend erzeugten Temp-Dateien (SQL-Dump
     * #231, Uploads-Archiv #233), deren Größe mit dem Bildbestand der
     * Instanz wächst - erst dieser Weg macht den konstanten Speicherbedarf
     * der Backup-Kette bis zum Ziel durchgängig.
     */
    public function putObjectFromFile(string $key, string $path, string $contentType = 'application/octet-stream'): void;

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
