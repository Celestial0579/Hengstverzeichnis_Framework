<?php
// tests/Functional/BrandingLogoUploadTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Logo-Upload im Branding (Audit N85): Das Formular bot SVG an, der Server
 * verwarf SVG (zu Recht, eingebettete Skripte) - und meldete trotzdem
 * „erfolgreich gespeichert“.
 */
class BrandingLogoUploadTest extends FunctionalTestCase {

    /** 1x1-PNG, transparent. */
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private const BRANDING_FELDER = [
        'site_name', 'copyright_holder', 'primary_color', 'secondary_color',
        'home_title', 'home_text', 'impressum_text', 'datenschutz_text',
    ];

    private static function brandingDir(): string {
        return dirname(__DIR__, 2) . '/public/uploads/branding';
    }

    /**
     * Die aktuellen Branding-Werte, damit der Test die Einstellungen anderer
     * Tests (Verbandsname, Farben) nicht überschreibt.
     *
     * @return array<string, string>
     */
    private function aktuelleFelder(): array {
        $stmt = Database::getInstance()->query(
            "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('" . implode("','", self::BRANDING_FELDER) . "')"
        );
        $werte = array_fill_keys(self::BRANDING_FELDER, '');
        foreach ($stmt->fetchAll(\PDO::FETCH_KEY_PAIR) as $k => $v) {
            $werte[$k] = (string)$v;
        }
        return $werte;
    }

    private function siteLogo(): ?string {
        $wert = Database::getInstance()->query("SELECT setting_value FROM settings WHERE setting_key = 'site_logo'")->fetchColumn();
        return $wert === false ? null : (string)$wert;
    }

    /** @return string[] */
    private function brandingDateien(string $muster): array {
        return glob(self::brandingDir() . '/' . $muster) ?: [];
    }

    public function testDasFormularBietetKeinSvgMehrAn(): void {
        $seite = $this->authenticatedClient()->get('/admin/settings');

        $this->assertSame(200, $seite->statusCode);
        // Nur das Upload-Feld - das Favicon im Layout ist selbst ein SVG.
        $this->assertSame(1, preg_match('#<input type="file" id="logo_file"[^>]*>#', $seite->body, $feld));
        $this->assertStringNotContainsString('svg', $feld[0]);
        $this->assertStringContainsString('accept="image/png,image/jpeg,image/webp"', $feld[0]);
        $this->assertStringNotContainsString('PNG, SVG', $seite->body);
    }

    public function testSvgWirdMitMeldungAbgelehntUndDerRestGespeichert(): void {
        $admin = $this->authenticatedClient();
        $logoVorher = $this->siteLogo();
        $svgVorher = $this->brandingDateien('*.svg');
        $felder = $this->aktuelleFelder();
        $felder['home_title'] = 'Titel ' . uniqid();

        $antwort = $admin->postFile(
            '/admin/settings',
            ['csrf_token' => $this->csrfTokenFrom($admin, '/admin/settings')] + $felder,
            'logo_file',
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'image/svg+xml'
        );

        $this->assertSame('/admin/settings?error=logo_type', $antwort->location(), "Body: {$antwort->body}");
        $seite = $admin->get('/admin/settings?error=logo_type');
        $this->assertStringContainsString('Nur PNG, JPG und WEBP', $seite->body);
        $this->assertStringNotContainsString('erfolgreich gespeichert', $seite->body);
        $this->assertSame($logoVorher, $this->siteLogo(), 'Das Logo darf sich nicht geändert haben.');
        $this->assertSame($svgVorher, $this->brandingDateien('*.svg'), 'Es darf keine SVG-Datei abgelegt werden.');
        $this->assertSame($felder['home_title'], $this->aktuelleFelder()['home_title'], 'Die übrigen Felder werden trotzdem gespeichert.');
    }

    public function testUnbekannteFehlerparameterWerdenNichtWiedergegeben(): void {
        $seite = $this->authenticatedClient()->get('/admin/settings?error=%3Cb%3Eeingeschleust%3C%2Fb%3E');
        $this->assertStringNotContainsString('eingeschleust', $seite->body);
    }

    public function testPngWirdUebernommen(): void {
        $admin = $this->authenticatedClient();
        $felder = $this->aktuelleFelder();

        try {
            $antwort = $admin->postFile(
                '/admin/settings',
                ['csrf_token' => $this->csrfTokenFrom($admin, '/admin/settings')] + $felder,
                'logo_file',
                'logo.png',
                (string)base64_decode(self::PNG_1X1, true),
                'image/png'
            );

            $this->assertSame('/admin/settings?success=1', $antwort->location(), "Body: {$antwort->body}");
            $this->assertMatchesRegularExpression('#^/uploads/branding/logo_\d+_[0-9a-f]{8}\.png$#', (string)$this->siteLogo());
            $this->assertFileExists(dirname(__DIR__, 2) . '/public' . $this->siteLogo());
        } finally {
            $logo = $this->siteLogo();
            $admin->post('/admin/settings', ['csrf_token' => $this->csrfTokenFrom($admin, '/admin/settings'), 'remove_logo' => '1'] + $felder);
            $this->assertNull($this->siteLogo());
            if ($logo !== null) {
                $this->assertFileDoesNotExist(dirname(__DIR__, 2) . '/public' . $logo);
            }
        }
    }
}
