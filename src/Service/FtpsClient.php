<?php
// src/Service/FtpsClient.php

namespace App\Service;

/**
 * Class FtpsClient
 *
 * Minimaler FTPS-Client (explizites/implizites TLS über die PHP-eigene
 * `ftp`-Extension, `ftp_ssl_connect()`) für Backup-Ziele (#93), z. B. ein
 * bereits vorhandener FTPS-Zugang beim Hoster. Reines unverschlossenes FTP
 * wird bewusst nicht angeboten (gleiche Begründung wie bei der
 * Pflicht-TLS-Verschlüsselung für SMTP, siehe
 * AdminController::updateMailSettings()) - Zucht-/Blutliniendaten dürfen
 * nie unverschlüsselt über das Netz übertragen werden.
 *
 * Anders als App\Service\S3Client/WebDavClient (reine PHP-Streams, siehe
 * dortige Begründung) unvermeidbar auf eine zusätzliche PHP-Extension
 * angewiesen - das FTP-Protokoll selbst (Kontroll-/Datenkanal, aktiv/passiv)
 * lässt sich nicht sinnvoll über `stream_context_create()` nachbilden.
 * `docker-php-ext-install ftp` ist im mitgelieferten Dockerfile enthalten;
 * auf Shared-Hosting ohne diese Extension schlägt jede Operation mit einer
 * klaren Fehlermeldung fehl statt eines Fatal Errors (siehe connect()).
 */
final class FtpsClient implements BackupTarget {

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        // Basis-Verzeichnis auf dem FTP-Server, z. B. "/hengstverzeichnis-backups"
        // - muss bereits existieren, Unterordner (z. B. "backups/") werden bei
        // Bedarf automatisch angelegt (siehe ensureDirectoryExists()).
        private readonly string $basePath = '',
    ) {}

    public function putObject(string $key, string $body, string $contentType = 'application/octet-stream'): void {
        // ftp_fput() erwartet ein Handle - der String-Weg war hier also schon
        // immer eine Umkopie in einen Temp-Stream vor demselben Upload-Pfad
        // wie beim Datei-Weg (putObjectFromFile()).
        $stream = fopen('php://temp', 'r+b');
        try {
            fwrite($stream, $body);
            rewind($stream);
            $this->uploadStream($key, $stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Lädt eine Datei streamend hoch (#237) - Gegenstück zu putObject() für
     * Inhalte, die als fertige Datei vorliegen (Backup-Zwischendateien,
     * siehe App\Service\BackupService): ftp_fput() liest das Datei-Handle
     * selbst blockweise, der Inhalt liegt nie als Gesamtstring im Speicher.
     * $contentType wird bei FTP nicht übertragen (das Protokoll kennt keine
     * Inhaltstypen) - der Parameter existiert nur für die einheitliche
     * BackupTarget-Schnittstelle, wie schon bei putObject().
     */
    public function putObjectFromFile(string $key, string $path, string $contentType = 'application/octet-stream'): void {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Upload-Datei nicht lesbar: {$path}");
        }
        try {
            $this->uploadStream($key, $stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param resource $stream Lesbarer Stream, positioniert am Anfang
     */
    private function uploadStream(string $key, $stream): void {
        $conn = $this->connect();
        try {
            $remotePath = self::joinPath($this->basePath, $key);
            $this->ensureDirectoryExists($conn, (string)dirname($remotePath));

            if (!@ftp_fput($conn, $remotePath, $stream, FTP_BINARY)) {
                throw new \RuntimeException("FTPS-Upload fehlgeschlagen: {$remotePath}");
            }
        } finally {
            ftp_close($conn);
        }
    }

    public function deleteObject(string $key): void {
        $conn = $this->connect();
        try {
            // Bereits nicht (mehr) vorhanden gilt als Erfolg (idempotent),
            // analog zum S3-/WebDAV-Verhalten.
            @ftp_delete($conn, self::joinPath($this->basePath, $key));
        } finally {
            ftp_close($conn);
        }
    }

    /**
     * @return array<int, array{key: string}>
     */
    public function listObjects(string $prefix = ''): array {
        $conn = $this->connect();
        try {
            $dir = self::joinPath($this->basePath, rtrim($prefix, '/'));
            $entries = @ftp_nlist($conn, $dir);
            if ($entries === false) {
                return []; // Verzeichnis existiert (noch) nicht - keine Objekte
            }

            $objects = [];
            foreach ($entries as $entry) {
                // ftp_nlist() liefert je nach Server absolute oder relative
                // Pfade - in beiden Fällen reicht der Dateiname am Ende.
                $filename = basename($entry);
                if ($filename === '.' || $filename === '..') {
                    continue;
                }
                $key = $prefix !== '' ? rtrim($prefix, '/') . '/' . $filename : $filename;
                $objects[] = ['key' => $key];
            }

            sort($objects);
            return $objects;
        } finally {
            ftp_close($conn);
        }
    }

    /**
     * @return resource
     */
    private function connect() {
        if (!function_exists('ftp_ssl_connect')) {
            throw new \RuntimeException('PHP-FTP-Extension (mit SSL-Unterstützung) ist auf diesem Server nicht verfügbar.');
        }

        // BEKANNTE GRENZE: ftp_ssl_connect() verschlüsselt die Verbindung,
        // prüft das Serverzertifikat aber NICHT - und PHPs FTP-Erweiterung
        // bietet dafür keine Einstellung (kein ftp_set_option dafür,
        // openssl.cafile wirkt hier nicht, der eigene SSL-Kontext der
        // Erweiterung verifiziert grundsätzlich nicht). Vertraulichkeit ja,
        // Authentizität nein: Wer sich in die Verbindung setzen kann, kann
        // sich mit einem beliebigen Zertifikat als der Backup-Server ausgeben
        // und bekommt Zugangsdaten und Sicherung.
        //
        // Deshalb ist das hier nicht wegzuprogrammieren, sondern zu benennen:
        // Der Hinweis steht im Admin-Bereich am FTPS-Abschnitt und in
        // docs/security.md. Wer die Wahl hat, nimmt WebDAV oder S3 - dort
        // prüfen die verwendeten Stream-/curl-Wege das Zertifikat.
        $conn = @ftp_ssl_connect($this->host, $this->port, 10);
        if ($conn === false) {
            throw new \RuntimeException("FTPS-Verbindung zu {$this->host}:{$this->port} fehlgeschlagen.");
        }

        if (!@ftp_login($conn, $this->username, $this->password)) {
            ftp_close($conn);
            throw new \RuntimeException('FTPS-Login fehlgeschlagen (Benutzername/Passwort prüfen).');
        }

        // Passiv-Modus: der Server teilt dem Client einen Datenkanal-Port mit,
        // statt umgekehrt - in praktisch jeder Hoster-/NAT-/Firewall-Umgebung
        // nötig, aktiver Modus scheitert sonst am ausgehend blockierten
        // Datenkanal.
        ftp_pasv($conn, true);

        return $conn;
    }

    /**
     * @param resource $conn
     */
    private function ensureDirectoryExists($conn, string $dir): void {
        $dir = trim($dir, '/');
        if ($dir === '') {
            return;
        }

        $segments = explode('/', $dir);
        $built = '';
        foreach ($segments as $segment) {
            $built = $built === '' ? $segment : "{$built}/{$segment}";
            // @ unterdrückt die (erwartete) Warnung, falls der Ordner schon
            // existiert - ftp_mkdir() hat dafür keinen "existiert bereits"-
            // Rückgabewert, nur false bei jedem Fehlschlag.
            @ftp_mkdir($conn, '/' . $built);
        }
    }

    /**
     * Reine, netzwerkfreie Pfad-Verknüpfung (Basis-Verzeichnis + Schlüssel,
     * doppelte/fehlende Schrägstriche normalisiert) - als eigene statische
     * Methode isoliert und direkt testbar, analog zu
     * App\Service\WebDavClient::parsePropfindHrefs().
     */
    public static function joinPath(string $basePath, string $key): string {
        $base = trim($basePath, '/');
        $key = trim($key, '/');

        if ($base === '') {
            return '/' . $key;
        }

        return $key === '' ? '/' . $base : '/' . $base . '/' . $key;
    }
}
