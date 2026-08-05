<?php
// tests/Support/fake-s3-server.php
//
// Minimaler, NUR für Tests genutzter S3-kompatibler Mock-Server (gestartet
// über `php -S` in tests/Integration/S3ClientTest.php), da echte S3-/MinIO-
// Zugangsdaten in dieser Umgebung nicht verfügbar sind. Implementiert genau
// die drei von App\Service\S3Client genutzten Operationen (PUT/DELETE
// Object, ListObjectsV2) path-style unter /{bucket}/{key...}, gespeichert
// als Dateien unter dem in FAKE_S3_STORAGE_DIR angegebenen Verzeichnis.
//
// Bewusst KEINE Signaturprüfung (das ist bereits durch
// tests/Unit/Service/S3ClientSignatureTest.php gegen eine unabhängige
// Referenzimplementierung abgedeckt) - dieser Server prüft stattdessen, dass
// die vom Client tatsächlich über das Netzwerk gesendete Anfrage (Methode,
// Pfad, Query-Parameter, Body) korrekt ankommt und eine plausible
// Authorization-Kopfzeile mitschickt.

$storageDir = getenv('FAKE_S3_STORAGE_DIR');
if (!$storageDir || !is_dir($storageDir)) {
    http_response_code(500);
    echo 'FAKE_S3_STORAGE_DIR nicht gesetzt oder existiert nicht.';
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!str_starts_with($authHeader, 'AWS4-HMAC-SHA256 Credential=')) {
    http_response_code(403);
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><Error><Code>AccessDenied</Code><Message>Missing or malformed Authorization header</Message></Error>';
    exit;
}

$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$segments = explode('/', $path, 2);
$bucket = $segments[0] ?? '';
$key = $segments[1] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$objectPath = function (string $bucket, string $key) use ($storageDir): string {
    // Anführungszeichen/Slashes im Key werden für den lokalen Dateinamen
    // sicher kodiert - reiner Test-Hilfscode, keine Produktionslogik.
    return $storageDir . '/' . $bucket . '__' . str_replace('/', '~', $key);
};

if ($method === 'PUT' && $key !== '') {
    $body = file_get_contents('php://input');
    file_put_contents($objectPath($bucket, $key), $body);
    http_response_code(200);
    header('ETag: "' . md5($body) . '"');
    exit;
}

if ($method === 'DELETE' && $key !== '') {
    $file = $objectPath($bucket, $key);
    if (is_file($file)) {
        unlink($file);
    }
    http_response_code(204);
    exit;
}

if ($method === 'GET' && ($_GET['list-type'] ?? '') === '2') {
    $prefix = $_GET['prefix'] ?? '';
    $prefixWithBucket = $bucket . '__' . str_replace('/', '~', $prefix);

    $entries = [];
    foreach (glob($storageDir . '/' . $bucket . '__*') as $file) {
        $basename = basename($file);
        if (!str_starts_with($basename, $bucket . '__')) {
            continue;
        }
        $objectKey = str_replace('~', '/', substr($basename, strlen($bucket) + 2));
        if ($prefix !== '' && !str_starts_with($objectKey, $prefix)) {
            continue;
        }
        $entries[] = ['key' => $objectKey, 'mtime' => filemtime($file)];
    }

    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult>';
    foreach ($entries as $entry) {
        echo '<Contents>';
        echo '<Key>' . htmlspecialchars($entry['key'], ENT_XML1) . '</Key>';
        echo '<LastModified>' . gmdate('Y-m-d\TH:i:s\Z', $entry['mtime']) . '</LastModified>';
        echo '</Contents>';
    }
    echo '</ListBucketResult>';
    exit;
}

http_response_code(400);
header('Content-Type: application/xml');
echo '<?xml version="1.0" encoding="UTF-8"?><Error><Code>UnsupportedOperation</Code><Message>Vom Fake-S3-Testserver nicht unterstützt: ' . htmlspecialchars($method . ' ' . $path, ENT_XML1) . '</Message></Error>';
