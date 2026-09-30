<?php
// tests/Unit/Helper/MediaUrlTest.php

namespace Tests\Unit\Helper;

use App\Helper\MediaUrl;
use PHPUnit\Framework\TestCase;

/**
 * Versionierte Bildadressen (Audit M14): Die Adresse wechselt mit dem Foto,
 * damit der Browser ein Jahr cachen darf, ohne ein altes Foto festzuhalten.
 */
class MediaUrlTest extends TestCase {

    public function testHauptbildadresseTraegtDieVersionDesDateiwerts(): void {
        $url = MediaUrl::horseImage(['id' => 42, 'image_url' => '/uploads/horses/horse_1_ab.jpg']);

        $this->assertMatchesRegularExpression('#^/media/horse-image\?id=42&v=[0-9a-f]{12}$#', (string)$url);
        $this->assertSame('/media/horse-image?id=42&v=' . MediaUrl::version('/uploads/horses/horse_1_ab.jpg'), $url);
    }

    public function testEinAnderesFotoErgibtEineAndereAdresse(): void {
        $a = MediaUrl::horseImage(['id' => 42, 'image_url' => '/uploads/horses/horse_1_ab.jpg']);
        $b = MediaUrl::horseImage(['id' => 42, 'image_url' => '/uploads/horses/horse_2_cd.jpg']);

        $this->assertNotSame($a, $b);
    }

    public function testGroesseUndVersionStehenBeide(): void {
        $this->assertMatchesRegularExpression(
            '#^/media/horse-image\?id=7&groesse=thumb&v=[0-9a-f]{12}$#',
            (string)MediaUrl::horseImage(['id' => 7, 'image_url' => 'x.jpg'], 'thumb')
        );
    }

    public function testOhneFotoOderIdBleibtEsBeiNull(): void {
        $this->assertNull(MediaUrl::horseImage(['id' => 42, 'image_url' => '']));
        $this->assertNull(MediaUrl::horseImage(['id' => 42]));
        $this->assertNull(MediaUrl::horseImage(['image_url' => 'x.jpg']));
    }

    public function testMedienadresseOhneDateinameBleibtUnveraendert(): void {
        $this->assertSame('/media/horse-media?id=7', MediaUrl::horseMediaImage(7));
        $this->assertSame('/media/horse-media?id=7&groesse=thumb', MediaUrl::horseMediaImage(7, 'thumb'));
        $this->assertNull(MediaUrl::horseMediaImage(0, null, 'x.jpg'));
    }

    public function testMedienadresseMitDateinameTraegtDieVersion(): void {
        $this->assertSame(
            '/media/horse-media?id=7&groesse=thumb&v=' . MediaUrl::version('/uploads/horses/m.jpg'),
            MediaUrl::horseMediaImage(7, 'thumb', '/uploads/horses/m.jpg')
        );
    }

    public function testVersionPasst(): void {
        $wert = '/uploads/horses/horse_1_ab.jpg';

        $this->assertTrue(MediaUrl::versionPasst($wert, MediaUrl::version($wert)));
        $this->assertFalse(MediaUrl::versionPasst($wert, MediaUrl::version('anders.jpg')));
        $this->assertFalse(MediaUrl::versionPasst($wert, ''));
        $this->assertFalse(MediaUrl::versionPasst($wert, null));
        $this->assertFalse(MediaUrl::versionPasst($wert, [MediaUrl::version($wert)]));
        $this->assertFalse(MediaUrl::versionPasst('', MediaUrl::version('')));
    }
}
