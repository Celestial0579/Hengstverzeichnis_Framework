<?php
// tests/Functional/HorseImageDeliveryTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Einbettungsschutz für Pferdefotos (#262).
 *
 * Der naheliegende Weg wäre ein Referer-Filter in `public/uploads/.htaccess`
 * gewesen. Genau den prüft diese Suite NICHT: Sie fährt `php -S`, und das
 * wertet .htaccess nicht aus. Ein Schutz, den keine Prüfung erreicht, fällt
 * beim Umzug auf nginx oder bei einer verstellten Serverkonfiguration still
 * aus - deshalb liegt die Entscheidung im Anwendungscode, und deshalb ist sie
 * hier prüfbar.
 *
 * Der Test sichert vier Dinge:
 * 1. Das Foto wird über /media/horse-image ausgeliefert, mit
 *    Cross-Origin-Resource-Policy (die eigentliche, browserseitig
 *    durchgesetzte Sperre gegen fremde Einbettung).
 * 2. Ein fremder Referer bekommt 403, ein eigener und ein LEERER nicht -
 *    Letzteres bewusst, sonst bräche direktes Aufrufen und Lesezeichen.
 * 3. Das Foto eines UNVERÖFFENTLICHTEN Pferdes ist für Gäste nicht abrufbar.
 *    Als statische Datei lag es offen, sobald jemand die URL kannte.
 * 4. Bedingte Anfragen liefern 304. Ohne sie wäre die Auslieferung über PHP
 *    der Rückschritt, den das Issue befürchtet.
 */
class HorseImageDeliveryTest extends FunctionalTestCase {

    /** @var array<int, int> */
    private array $seededHorseIds = [];
    /** @var array<int, string> */
    private array $seededFiles = [];

    protected function tearDown(): void {
        $db = Database::getInstance();
        foreach ($this->seededHorseIds as $id) {
            $db->prepare("DELETE FROM horses WHERE id = ?")->execute([$id]);
        }
        foreach ($this->seededFiles as $file) {
            @unlink($file);
        }
        $this->seededHorseIds = [];
        $this->seededFiles = [];
        parent::tearDown();
    }

    public function testPublishedHorsePhotoIsDeliveredWithCrossOriginResourcePolicy(): void {
        $id = $this->seedHorseWithPhoto(true);

        $response = $this->newClient()->get('/media/horse-image?id=' . $id);

        $this->assertSame(200, $response->statusCode, 'Das Foto eines veröffentlichten Pferdes muss ausgeliefert werden.');
        $this->assertSame('image/png', $response->header('Content-Type'));
        $this->assertSame(
            'same-origin',
            $response->header('Cross-Origin-Resource-Policy'),
            'Ohne CORP kann jede fremde Seite das Bild per <img> einbetten - das ist der Kern von #262.'
        );
        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
        $this->assertStringStartsWith("\x89PNG", $response->body, 'Es kommt kein Bild zurück.');
    }

    public function testForeignRefererIsRejectedButOwnAndEmptyRefererAreNot(): void {
        $id = $this->seedHorseWithPhoto(true);
        $url = '/media/horse-image?id=' . $id;

        $foreign = $this->newClient()->get($url, ['Referer' => 'https://fremde-seite.example/pferde']);
        $this->assertSame(403, $foreign->statusCode, 'Eine fremde einbettende Seite muss abgewiesen werden.');

        $own = $this->newClient()->get($url, ['Referer' => \Tests\Support\PhpBuiltInServer::baseUrl() . '/katalog']);
        $this->assertSame(200, $own->statusCode, 'Die eigene Katalogseite muss das Bild weiterhin bekommen.');

        // Bewusst erlaubt: Ohne das bräche der direkte Aufruf einer Bild-URL,
        // Lesezeichen und jeder Browser, der den Referer unterdrückt. Die Lücke
        // deckt CORP ab - deshalb sind es zwei Schichten und nicht eine.
        $empty = $this->newClient()->get($url);
        $this->assertSame(200, $empty->statusCode, 'Ein leerer Referer darf nicht blockiert werden.');
    }

    /**
     * Der Nebenbefund, den der Umbau mit erledigt: Als statische Datei war das
     * Foto unabhängig von is_published abrufbar.
     */
    public function testUnpublishedHorsePhotoIsNotReachableForGuests(): void {
        $id = $this->seedHorseWithPhoto(false);

        $response = $this->newClient()->get('/media/horse-image?id=' . $id);

        $this->assertSame(
            404,
            $response->statusCode,
            'Das Foto eines unveröffentlichten Pferdes darf für Gäste nicht abrufbar sein.'
        );
    }

    public function testAdminStillSeesUnpublishedHorsePhoto(): void {
        $id = $this->seedHorseWithPhoto(false);

        $response = $this->authenticatedClient()->get('/media/horse-image?id=' . $id);

        $this->assertSame(
            200,
            $response->statusCode,
            'Die Verwaltung muss das Foto eines unveröffentlichten Pferdes sehen - sonst bliebe das Bearbeitungsformular leer.'
        );
    }

    /**
     * Ohne bedingte Anfragen holte der Browser das Bild bei jedem Neuladen
     * vollständig - dann wäre die Auslieferung über PHP tatsächlich der
     * Rückschritt gegenüber der statischen Datei, den das Issue befürchtet.
     */
    public function testConditionalRequestAnswersWithNotModified(): void {
        $id = $this->seedHorseWithPhoto(true);
        $url = '/media/horse-image?id=' . $id;

        $first = $this->newClient()->get($url);
        $etag = $first->header('ETag');
        $this->assertNotNull($etag, 'Ohne ETag kann der Browser nicht bedingt nachfragen.');
        $this->assertStringContainsString('max-age=', (string)$first->header('Cache-Control'));

        $second = $this->newClient()->get($url, ['If-None-Match' => $etag]);
        $this->assertSame(304, $second->statusCode, 'Eine bedingte Anfrage muss 304 liefern.');
        $this->assertSame('', trim($second->body), 'Eine 304-Antwort darf keinen Rumpf haben.');
    }

    public function testUnknownHorseAndHorseWithoutPhotoYieldNotFound(): void {
        $this->assertSame(404, $this->newClient()->get('/media/horse-image?id=999999999')->statusCode);
        $this->assertSame(404, $this->newClient()->get('/media/horse-image')->statusCode);

        $withoutPhoto = $this->seedHorse(true, null);
        $this->assertSame(404, $this->newClient()->get('/media/horse-image?id=' . $withoutPhoto)->statusCode);
    }

    /**
     * Der gespeicherte Spaltenwert stammt aus der eigenen Datenbank - aber
     * genau darauf hat sich schon manche Anwendung verlassen, bevor ein
     * CSV-Import oder eine Altdatenübernahme dort etwas anderes hineinschrieb.
     */
    public function testStoredPathCannotEscapeTheUploadDirectory(): void {
        $id = $this->seedHorse(true, '/uploads/horses/../../../config/config.php');
        $this->assertSame(404, $this->newClient()->get('/media/horse-image?id=' . $id)->statusCode);

        $id2 = $this->seedHorse(true, '/uploads/horses/config.php');
        $this->assertSame(404, $this->newClient()->get('/media/horse-image?id=' . $id2)->statusCode);
    }

    /**
     * Eigener Fall für die realpath-Prüfung. Die beiden Fälle oben fängt schon
     * die Endungs-Positivliste ab - sie belegen die Pfadprüfung also NICHT.
     * Aufgefallen ist das beim Gegenbeweis: Beide Schutzmaßnahmen gemeinsam
     * abzuschalten brachte die Suite zum Absturz statt sie rot zu machen, und
     * genau daran zeigte sich, dass eine der beiden gar nicht belegt war.
     *
     * Ein Symlink mit erlaubter Endung, der aus dem Verzeichnis herauszeigt,
     * kommt an der Positivliste vorbei - ihn hält nur realpath auf.
     */
    public function testSymlinkOutOfTheUploadDirectoryIsRefused(): void {
        $dir = __DIR__ . '/../../public/uploads/horses';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $target = __DIR__ . '/../../config/config.php';
        $link = $dir . '/ausbruch_' . uniqid() . '.png';
        if (!@symlink($target, $link)) {
            $this->markTestSkipped('Dateisystem erlaubt keine Symlinks.');
        }
        $this->seededFiles[] = $link;

        $id = $this->seedHorse(true, '/uploads/horses/' . basename($link));
        $response = $this->newClient()->get('/media/horse-image?id=' . $id);

        $this->assertSame(
            404,
            $response->statusCode,
            'Ein Symlink mit Bildendung darf keine Datei ausserhalb des Upload-Verzeichnisses ausliefern.'
        );
        $this->assertStringNotContainsString(
            'DB_PASS',
            $response->body,
            'Der Inhalt der Konfigurationsdatei wurde ausgeliefert.'
        );
    }

    /**
     * #314: Eine ungültig gewordene Sitzung hebt is_published nicht mehr auf.
     *
     * Vorher genügte die blosse Anwesenheit von $_SESSION['user_id'], und
     * MediaController rief checkAuth() nirgends auf. Ein Angreifer mit einem
     * gestohlenen Cookie flog auf /admin/horses sofort auf /login, konnte
     * hier aber weiter jedes Foto abrufen - auch die unveröffentlichter oder
     * nach DSGVO-Widerspruch depublizierter Pferde.
     *
     * Der Vorher-Nachher-Aufbau ist der Kern: Erst wird belegt, dass dieses
     * Konto das Bild WIRKLICH bekommt. Ohne diesen Schritt liesse sich der
     * Test auch mit einem fehlenden Recht bestehen - er bewiese dann etwas
     * ganz anderes als das, was draufsteht.
     */
    public function testInvalidatedSessionNoLongerUnlocksUnpublishedPhoto(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $id = $this->seedHorseWithPhoto(false);
        $url = '/media/horse-image?id=' . $id;

        $groupId = $this->createCustomGroup($admin, "Bildleser {$unique}");
        $this->setGroupPermissions($admin, $groupId, ['horses' => ['view']]);
        $email = "bildleser-{$unique}@example.com";
        $leser = $this->createAndLoginEditor($admin, "bildleser{$unique}", $email, [$groupId]);

        $this->assertSame(
            200,
            $leser->get($url)->statusCode,
            'Vorbedingung: Dieses Konto muss das Bild mit gültiger Sitzung bekommen'
        );

        // Der Infostealer-Fall: Das Konto wird gelöscht, der Angreifer hält
        // das alte Sitzungscookie.
        $db->prepare("UPDATE users SET deleted_at = NOW() WHERE email = ?")->execute([$email]);

        $nachher = $leser->get($url);
        $this->assertNotSame(
            200,
            $nachher->statusCode,
            'Ein gelöschtes Konto darf über sein altes Sitzungscookie kein unveröffentlichtes Foto mehr bekommen'
        );
        $this->assertStringNotContainsString(
            "\x89PNG",
            $nachher->body,
            'Es darf kein Bildinhalt zurückkommen'
        );
    }

    /**
     * #314, zweiter Weg: Passwortwechsel erhöht users.session_version (#113).
     * Die Invalidierung lebte ausschliesslich in checkAuth() - eine Route, die
     * checkAuth() nicht aufruft, hat sie nie gesehen.
     */
    public function testSessionVersionBumpAlsoLocksThePhotoRoute(): void {
        $db = Database::getInstance();
        $admin = $this->authenticatedClient();
        $unique = uniqid();
        $id = $this->seedHorseWithPhoto(false);
        $url = '/media/horse-image?id=' . $id;

        $groupId = $this->createCustomGroup($admin, "Bildleser PW {$unique}");
        $this->setGroupPermissions($admin, $groupId, ['horses' => ['view']]);
        $email = "bildleser-pw-{$unique}@example.com";
        $leser = $this->createAndLoginEditor($admin, "bildleserpw{$unique}", $email, [$groupId]);

        $this->assertSame(200, $leser->get($url)->statusCode, 'Vorbedingung: gültige Sitzung sieht das Bild');

        $db->prepare("UPDATE users SET session_version = session_version + 1 WHERE email = ?")->execute([$email]);

        $this->assertNotSame(
            200,
            $leser->get($url)->statusCode,
            'Nach einem Passwortwechsel darf die alte Sitzung das Foto nicht mehr bekommen'
        );
    }

    /**
     * #315: Die Cache-Direktive folgt der Sichtbarkeit.
     *
     * Ein gemeinsam genutzter Zwischenspeicher (nginx proxy_cache, Varnish,
     * CDN) sah bei `public, max-age=1 Jahr` eine statische, für alle gleiche
     * Ressource. Öffnete ein Redakteur die Bearbeitungsseite eines
     * unveröffentlichten Pferds, lag dessen Foto danach ein Jahr im Cache und
     * ging von dort an jeden Gast - PHP wurde nie wieder gefragt.
     */
    public function testUnpublishedPhotoIsNotCacheableWhilePublishedOneIs(): void {
        $admin = $this->authenticatedClient();

        $unveroeffentlicht = $admin->get('/media/horse-image?id=' . $this->seedHorseWithPhoto(false));
        $this->assertSame(200, $unveroeffentlicht->statusCode);
        $cacheControl = (string)$unveroeffentlicht->header('Cache-Control');
        $this->assertStringContainsString(
            'no-store',
            $cacheControl,
            'Ein zugriffsabhängiges Foto darf nirgends zwischengespeichert werden'
        );
        $this->assertStringNotContainsString(
            'public',
            $cacheControl,
            'public gibt die Antwort für einen gemeinsamen Cache frei - genau das darf hier nicht passieren'
        );

        $veroeffentlicht = $this->newClient()->get('/media/horse-image?id=' . $this->seedHorseWithPhoto(true));
        $this->assertSame(200, $veroeffentlicht->statusCode);
        $this->assertStringContainsString(
            'public, max-age=',
            (string)$veroeffentlicht->header('Cache-Control'),
            'Ein veröffentlichtes Foto soll weiterhin lange und gemeinsam zwischengespeichert werden'
        );
    }

    /**
     * Dieselbe Verwechslung in der anderen Richtung: Ein 404 gilt nur für
     * DIESEN Abrufer. Ohne no-store darf ein gemeinsamer Cache ihn heuristisch
     * aufbewahren und anschliessend einem Berechtigten ausliefern.
     *
     * EHRLICHKEITSHINWEIS zu diesem Fall: Er wäre auch ohne die Zeile in
     * sendStatus() grün, weil PHPs session.cache_limiter ('nocache', der
     * Standard) bei jedem session_start() bereits
     * `Cache-Control: no-store, no-cache, must-revalidate` setzt. Der Test ist
     * damit kein Gate für diese eine Zeile, sondern hält die beobachtbare
     * Zusicherung fest - unabhängig davon, welcher der beiden Mechanismen sie
     * gerade liefert. Genau deshalb steht der Header trotzdem ausdrücklich
     * da: Der Kurzschluss aus #311 macht es denkbar, dass für diesen Pfad
     * eines Tages gar keine Sitzung mehr gestartet wird, und dann fiele die
     * stillschweigende Absicherung weg, ohne dass irgendetwas rot würde.
     */
    public function testRejectionsAreNotCacheable(): void {
        $abgelehnt = $this->newClient()->get('/media/horse-image?id=' . $this->seedHorseWithPhoto(false));
        $this->assertSame(404, $abgelehnt->statusCode);
        $this->assertStringContainsString('no-store', (string)$abgelehnt->header('Cache-Control'));

        $fremderReferer = $this->newClient()->get(
            '/media/horse-image?id=' . $this->seedHorseWithPhoto(true),
            ['Referer' => 'https://fremde-seite.example/pferde']
        );
        $this->assertSame(403, $fremderReferer->statusCode);
        $this->assertStringContainsString('no-store', (string)$fremderReferer->header('Cache-Control'));
    }

    // ------------------------------------------------------------------

    private function createCustomGroup(\Tests\Support\HttpClient $admin, string $name): int {
        $groupsPage = $admin->get('/admin/groups');
        $response = $admin->post('/admin/groups/create', [
            'csrf_token' => $groupsPage->formField('csrf_token') ?? '',
            'name' => $name,
        ]);
        preg_match('/group=(\d+)/', (string)$response->location(), $matches);
        $this->assertNotEmpty($matches, "Konnte neue Gruppen-ID nicht ermitteln, Body: {$response->body}");
        return (int)$matches[1];
    }


    private function seedHorseWithPhoto(bool $published): int {
        $dir = __DIR__ . '/../../public/uploads/horses';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'testbild_' . uniqid() . '.png';
        $path = $dir . '/' . $filename;
        // Kleinstes gültiges PNG - der Test prüft die Auslieferung, nicht das Bild.
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
        $this->seededFiles[] = $path;

        return $this->seedHorse($published, '/uploads/horses/' . $filename);
    }

    private function seedHorse(bool $published, ?string $imageUrl): int {
        $db = Database::getInstance();
        $db->prepare("INSERT INTO horses (name, sex, image_url, is_published, created_at) VALUES (?, 'stallion', ?, ?, NOW())")
           ->execute(['Bildprobe ' . uniqid(), $imageUrl, $published ? 1 : 0]);

        $id = (int)$db->lastInsertId();
        $this->seededHorseIds[] = $id;
        return $id;
    }
}
