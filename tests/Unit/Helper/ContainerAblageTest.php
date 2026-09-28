<?php
// tests/Unit/Helper/ContainerAblageTest.php

namespace Tests\Unit\Helper;

use App\Helper\ContainerAblage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Erkennung, ob storage/horses im Container dauerhaft liegt (Audit M31).
 *
 * Die Zeilen folgen dem Aufbau von /proc/self/mountinfo (proc(5)): Feld 4 ist
 * die Wurzel in der Quelle, Feld 5 der Mountpunkt. Die Fixtures sind echten
 * Docker-Containern nachgebildet.
 */
class ContainerAblageTest extends TestCase {

    private const ZIEL = '/var/www/html/storage/horses';
    private const HEX = 'a3f1c2d4e5b6978800112233445566778899aabbccddeeff0011223344556677';

    private const WURZEL = "612 540 0:52 / / rw,relatime master:218 - overlay overlay rw,lowerdir=/x,upperdir=/y,workdir=/z\n"
        . "613 612 0:55 / /proc rw,nosuid,nodev,noexec,relatime - proc proc rw\n";

    protected function tearDown(): void {
        ContainerAblage::overrideForTests(null, null);
    }

    private static function zeile(string $wurzel, string $punkt, string $nr = '700'): string {
        return "{$nr} 612 254:1 {$wurzel} {$punkt} rw,relatime - ext4 /dev/vda1 rw\n";
    }

    /** @return array<string, array{string, string, string}> */
    public static function faelle(): array {
        return [
            '(a) nur Root-Overlay' => [self::WURZEL, self::ZIEL, ContainerAblage::KEIN_MOUNT],
            '(b) benanntes Volume' => [
                self::WURZEL . self::zeile('/var/lib/docker/volumes/hengst_horses_data/_data', self::ZIEL),
                self::ZIEL,
                ContainerAblage::EIGENER_MOUNT,
            ],
            '(c) anonymes Volume unter /var/lib/docker' => [
                self::WURZEL . self::zeile('/var/lib/docker/volumes/' . self::HEX . '/_data', self::ZIEL),
                self::ZIEL,
                ContainerAblage::ANONYMES_VOLUME,
            ],
            '(d) anonymes Volume, /var/lib/docker eigenes Dateisystem' => [
                self::WURZEL . self::zeile('/volumes/' . self::HEX . '/_data', self::ZIEL),
                self::ZIEL,
                ContainerAblage::ANONYMES_VOLUME,
            ],
            '(e) Bind-Mount' => [
                self::WURZEL . self::zeile('/srv/hengst/fotos', self::ZIEL),
                self::ZIEL,
                ContainerAblage::EIGENER_MOUNT,
            ],
            '(f) Mount auf storage/' => [
                self::WURZEL . self::zeile('/var/lib/docker/volumes/hengst_storage/_data', '/var/www/html/storage'),
                self::ZIEL,
                ContainerAblage::EIGENER_MOUNT,
            ],
            '(g) Namensverwandter horses2 zaehlt nicht' => [
                self::WURZEL . self::zeile('/var/lib/docker/volumes/hengst_x/_data', '/var/www/html/storage/horses2'),
                self::ZIEL,
                ContainerAblage::KEIN_MOUNT,
            ],
            '(g2) Mount auf horses zaehlt nicht fuer horses2' => [
                self::WURZEL . self::zeile('/var/lib/docker/volumes/hengst_horses_data/_data', self::ZIEL),
                self::ZIEL . '2',
                ContainerAblage::KEIN_MOUNT,
            ],
            '(h) Pfad mit Leerzeichen (\040)' => [
                self::WURZEL . self::zeile('/srv/meine\040fotos', '/srv/app\040dir/storage/horses'),
                '/srv/app dir/storage/horses',
                ContainerAblage::EIGENER_MOUNT,
            ],
            '(i) zwei Mounts am selben Punkt: der letzte gewinnt (anonym verdeckt benannt)' => [
                self::WURZEL
                    . self::zeile('/var/lib/docker/volumes/hengst_horses_data/_data', self::ZIEL, '700')
                    . self::zeile('/var/lib/docker/volumes/' . self::HEX . '/_data', self::ZIEL, '701'),
                self::ZIEL,
                ContainerAblage::ANONYMES_VOLUME,
            ],
            '(i2) zwei Mounts am selben Punkt: der letzte gewinnt (benannt verdeckt anonym)' => [
                self::WURZEL
                    . self::zeile('/var/lib/docker/volumes/' . self::HEX . '/_data', self::ZIEL, '700')
                    . self::zeile('/var/lib/docker/volumes/hengst_horses_data/_data', self::ZIEL, '701'),
                self::ZIEL,
                ContainerAblage::EIGENER_MOUNT,
            ],
            '(l) benanntes Volume mit 64 Zeichen, aber kein Hex' => [
                self::WURZEL . self::zeile('/var/lib/docker/volumes/' . str_pad('pferdefotosvomhengstverzeichnis', 64, 'z') . '/_data', self::ZIEL),
                self::ZIEL,
                ContainerAblage::EIGENER_MOUNT,
            ],
            'Unterordner eines Mounts' => [
                self::WURZEL . self::zeile('/var/lib/docker/volumes/hengst_horses_data/_data', self::ZIEL),
                self::ZIEL . '/unterordner',
                ContainerAblage::EIGENER_MOUNT,
            ],
            'laengster Mountpunkt gewinnt (anonym unter benanntem storage/)' => [
                self::WURZEL
                    . self::zeile('/var/lib/docker/volumes/' . self::HEX . '/_data', self::ZIEL, '701')
                    . self::zeile('/var/lib/docker/volumes/hengst_storage/_data', '/var/www/html/storage', '702'),
                self::ZIEL,
                ContainerAblage::ANONYMES_VOLUME,
            ],
        ];
    }

    #[DataProvider('faelle')]
    public function testArtAus(string $mountinfo, string $pfad, string $erwartet): void {
        $this->assertSame($erwartet, ContainerAblage::artAus($pfad, $mountinfo));
    }

    /** (j) Ohne HV_CONTAINER ändert sich nichts: jeder Pfad gilt als dauerhaft. */
    public function testInaktivIstImmerPersistent(): void {
        ContainerAblage::overrideForTests(false, self::WURZEL);

        $this->assertFalse(ContainerAblage::aktiv());
        $this->assertTrue(ContainerAblage::istPersistent(sys_get_temp_dir()));
        $this->assertTrue(ContainerAblage::istPersistent('/gibt/es/nicht'));
        $this->assertNull(ContainerAblage::warnung(sys_get_temp_dir()));
    }

    /** (k) Ein nicht existierender Pfad lässt sich nicht zuordnen. */
    public function testNichtExistierenderPfadIstKeinMount(): void {
        $mountinfo = self::WURZEL . self::zeile('/srv/x', '/gibt/es/nicht');
        ContainerAblage::overrideForTests(true, $mountinfo);

        $this->assertSame(ContainerAblage::KEIN_MOUNT, ContainerAblage::art('/gibt/es/nicht'));
        $this->assertFalse(ContainerAblage::istPersistent('/gibt/es/nicht'));
        $this->assertSame(ContainerAblage::KEIN_MOUNT, ContainerAblage::warnung('/gibt/es/nicht'));
    }

    /** art() löst den Pfad auf und wertet die (hier untergeschobene) mountinfo aus. */
    public function testArtNutztRealpathUndMountinfo(): void {
        $dir = sys_get_temp_dir() . '/hv_ablage_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $real = (string)realpath($dir);

            ContainerAblage::overrideForTests(true, self::WURZEL);
            $this->assertSame(ContainerAblage::KEIN_MOUNT, ContainerAblage::art($dir . '/.'));
            $this->assertFalse(ContainerAblage::istPersistent($dir));

            ContainerAblage::overrideForTests(true, self::WURZEL . self::zeile('/volumes/' . self::HEX . '/_data', $real));
            $this->assertSame(ContainerAblage::ANONYMES_VOLUME, ContainerAblage::warnung($dir));
            $this->assertFalse(ContainerAblage::istPersistent($dir));

            ContainerAblage::overrideForTests(true, self::WURZEL . self::zeile('/volumes/hengst_horses_data/_data', $real));
            $this->assertSame(ContainerAblage::EIGENER_MOUNT, ContainerAblage::art($dir));
            $this->assertTrue(ContainerAblage::istPersistent($dir));
            $this->assertNull(ContainerAblage::warnung($dir));
        } finally {
            @rmdir($dir);
        }
    }

    /** Aktiv nur mit HV_CONTAINER=1 - kein anderer Wert. */
    public function testAktivNurMitHvContainerEins(): void {
        $vorher = getenv('HV_CONTAINER');
        try {
            foreach (['1' => true, '0' => false, 'true' => false, '' => false] as $wert => $erwartet) {
                putenv('HV_CONTAINER=' . $wert);
                $this->assertSame($erwartet, ContainerAblage::aktiv(), "HV_CONTAINER='{$wert}'");
            }
            putenv('HV_CONTAINER');
            $this->assertFalse(ContainerAblage::aktiv());
        } finally {
            putenv($vorher === false ? 'HV_CONTAINER' : 'HV_CONTAINER=' . $vorher);
        }
    }
}
