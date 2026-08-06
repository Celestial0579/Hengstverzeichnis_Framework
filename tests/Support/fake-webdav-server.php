<?php
// tests/Support/fake-webdav-server.php
//
// Minimaler, NUR für Tests genutzter WebDAV-Mock-Server (gestartet über
// `php -S` in tests/Integration/WebDavClientTest.php), analog zu
// tests/Support/fake-s3-server.php. Implementiert genau die vier von
// App\Service\WebDavClient genutzten Methoden (PUT, DELETE, MKCOL, PROPFIND
// mit Depth:1) gegen ein lokales Verzeichnis unter FAKE_WEBDAV_STORAGE_DIR.
//
// Bewusst KEINE Basic-Auth-Prüfung der Zugangsdaten gegen echte Werte -
// prüft nur, dass überhaupt ein Authorization-Header mitgeschickt wird
// (WebDavClient::request() baut ihn immer mit, das reicht für den
// Transport-Test).

$storageDir = getenv('FAKE_WEBDAV_STORAGE_DIR');
if (!$storageDir || !is_dir($storageDir)) {
    http_response_code(500);
    echo 'FAKE_WEBDAV_STORAGE_DIR nicht gesetzt oder existiert nicht.';
    exit;
}

if (!str_starts_with($_SERVER['HTTP_AUTHORIZATION'] ?? '', 'Basic ')) {
    http_response_code(401);
    exit;
}

$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$method = $_SERVER['REQUEST_METHOD'];
$localPath = $storageDir . '/' . $path;

if ($method === 'MKCOL') {
    if (is_dir($localPath)) {
        http_response_code(405);
        exit;
    }
    mkdir($localPath, 0777, true);
    http_response_code(201);
    exit;
}

if ($method === 'PUT') {
    $dir = dirname($localPath);
    if (!is_dir($dir)) {
        http_response_code(409); // Conflict: Elternordner fehlt, wie bei echtem WebDAV
        exit;
    }
    file_put_contents($localPath, file_get_contents('php://input'));
    http_response_code(201);
    exit;
}

if ($method === 'DELETE') {
    if (!file_exists($localPath)) {
        http_response_code(404);
        exit;
    }
    is_dir($localPath) ? rmdir($localPath) : unlink($localPath);
    http_response_code(204);
    exit;
}

if ($method === 'PROPFIND') {
    if (!is_dir($localPath)) {
        http_response_code(404);
        exit;
    }

    $entries = [$path]; // der angefragte Ordner selbst, siehe WebDavClient::listObjects()
    foreach (scandir($localPath) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $entryPath = ($path !== '' ? $path . '/' : '') . $entry;
        $entries[] = is_dir($localPath . '/' . $entry) ? $entryPath . '/' : $entryPath;
    }

    http_response_code(207);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><d:multistatus xmlns:d="DAV:">';
    foreach ($entries as $entry) {
        echo '<d:response><d:href>/' . htmlspecialchars($entry, ENT_XML1) . '</d:href></d:response>';
    }
    echo '</d:multistatus>';
    exit;
}

http_response_code(400);
echo 'Vom Fake-WebDAV-Testserver nicht unterstützt: ' . htmlspecialchars($method . ' ' . $path);
