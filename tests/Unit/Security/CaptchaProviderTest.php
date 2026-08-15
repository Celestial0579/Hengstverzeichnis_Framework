<?php
// tests/Unit/Security/CaptchaProviderTest.php

namespace Tests\Unit\Security;

use App\Plugin\PluginManager;
use App\Security\Captcha;
use PHPUnit\Framework\TestCase;

/**
 * Die Anbieter-Erweiterungspunkte des Spam-Schutzes (#258): `captcha.providers`,
 * `captcha.render` und `captcha.verify`.
 *
 * Standard ist und bleibt die im Kern eingebaute Rechenaufgabe - sie braucht
 * keine Schlüssel, keinen Netzzugang und überträgt keine Daten an Dritte, was
 * ausgerechnet auf dem Formular zählt, mit dem Betroffene ihre Rechte aus
 * Art. 15/17 DSGVO geltend machen. Ein Addon kann einen anderen Anbieter
 * (Cloudflare Turnstile, hCaptcha) beisteuern, wenn ein Betreiber das
 * ausdrücklich will.
 *
 * Der eigentliche Grund für diese Tests sind die Rückfälle: Ein Addon, das
 * abstürzt, deaktiviert oder deinstalliert wurde, darf weder das Formular
 * ungeschützt lassen (fail-open) noch Betroffene aussperren (hartes
 * Blockieren). Beides wäre hier ein echter Schaden.
 */
class CaptchaProviderTest extends TestCase {

    protected function setUp(): void {
        $_SESSION = [];
        PluginManager::getInstance()->getHooks()->reset();
    }

    protected function tearDown(): void {
        PluginManager::getInstance()->getHooks()->reset();
        $_SESSION = [];
    }

    /** Beantwortet die im gerenderten Fragment gestellte Aufgabe korrekt. */
    private function solve(): string {
        return (string)($_SESSION['captcha_challenge']['answer'] ?? -999);
    }

    public function testBuiltinIsTheDefaultAndAlwaysOffered(): void {
        $providers = Captcha::availableProviders();
        $this->assertArrayHasKey(Captcha::PROVIDER_BUILTIN, $providers);
        $this->assertSame(Captcha::PROVIDER_BUILTIN, Captcha::activeProvider([]));
        $this->assertSame(Captcha::PROVIDER_BUILTIN, Captcha::activeProvider(['captcha_provider' => '']));
    }

    public function testAddonProviderIsOfferedButCannotReplaceTheBuiltin(): void {
        PluginManager::getInstance()->getHooks()->addFilter('captcha.providers', fn(array $p) => $p + [
            'turnstile' => 'Cloudflare Turnstile',
            Captcha::PROVIDER_BUILTIN => 'Gekaperter Kern-Anbieter',
        ]);

        $providers = Captcha::availableProviders();
        $this->assertSame('Cloudflare Turnstile', $providers['turnstile']);
        $this->assertStringContainsString(
            'im Kern enthalten',
            $providers[Captcha::PROVIDER_BUILTIN],
            'Der eingebaute Anbieter darf nicht überschreibbar sein - er ist der Rückfallweg.'
        );
        $this->assertSame('turnstile', Captcha::activeProvider(['captcha_provider' => 'turnstile']));
    }

    public function testAddonVerdictWins(): void {
        $hooks = PluginManager::getInstance()->getHooks();
        $hooks->addFilter('captcha.providers', fn(array $p) => $p + ['turnstile' => 'Cloudflare Turnstile']);
        $hooks->addFilter('captcha.render', fn() => '<div data-turnstile="1"></div>');
        $hooks->addFilter('captcha.verify', fn() => Captcha::WRONG);

        $settings = ['captcha_provider' => 'turnstile'];
        $this->assertStringContainsString('data-turnstile="1"', Captcha::renderField($settings, 'dsgvo'));
        $this->assertSame(Captcha::WRONG, Captcha::verify($settings, 'dsgvo', []));
    }

    public function testUnknownConfiguredProviderFallsBackToBuiltin(): void {
        // Zustand nach dem Deaktivieren eines CAPTCHA-Addons: In den
        // Einstellungen steht noch dessen Name.
        $settings = ['captcha_provider' => 'turnstile'];
        $this->assertSame(Captcha::PROVIDER_BUILTIN, Captcha::activeProvider($settings));

        $html = Captcha::renderField($settings, 'dsgvo');
        $this->assertStringContainsString('name="captcha"', $html, 'Ohne Addon rendert der Kern seine eigene Aufgabe.');

        $_SESSION['captcha_challenge']['issued_at'] -= Captcha::MIN_SOLVE_SECONDS + 1;
        $this->assertSame(Captcha::OK, Captcha::verify($settings, 'dsgvo', ['captcha' => $this->solve()]));
    }

    public function testCrashingAddonNeitherOpensTheFormNorLocksItOut(): void {
        $hooks = PluginManager::getInstance()->getHooks();
        $hooks->addFilter('captcha.providers', fn(array $p) => $p + ['turnstile' => 'Cloudflare Turnstile']);
        $hooks->addFilter('captcha.render', fn() => throw new \RuntimeException('Addon kaputt'));
        $hooks->addFilter('captcha.verify', fn() => throw new \RuntimeException('Addon kaputt'));

        $settings = ['captcha_provider' => 'turnstile'];

        // HookManager verschluckt die Exception und behält den Startwert. Genau
        // deshalb ist der Startwert von captcha.verify null - ein abgestürztes
        // Addon liefert damit nie versehentlich OK.
        $html = Captcha::renderField($settings, 'dsgvo');
        $this->assertStringContainsString('name="captcha"', $html);

        $_SESSION['captcha_challenge']['issued_at'] -= Captcha::MIN_SOLVE_SECONDS + 1;
        $this->assertSame(
            Captcha::WRONG,
            Captcha::verify($settings, 'dsgvo', ['captcha' => '999']),
            'Ein abgestürztes Addon darf das Formular nicht ungeschützt lassen.'
        );

        $html = Captcha::renderField($settings, 'dsgvo');
        $_SESSION['captcha_challenge']['issued_at'] -= Captcha::MIN_SOLVE_SECONDS + 1;
        $this->assertSame(
            Captcha::OK,
            Captcha::verify($settings, 'dsgvo', ['captcha' => $this->solve()]),
            'Der Rückfall muss auch lösbar sein - sonst wäre der Auskunftsweg gesperrt.'
        );
    }

    public function testGarbageVerdictFromAddonIsIgnored(): void {
        $hooks = PluginManager::getInstance()->getHooks();
        $hooks->addFilter('captcha.providers', fn(array $p) => $p + ['turnstile' => 'Cloudflare Turnstile']);
        $hooks->addFilter('captcha.render', fn() => '<div data-turnstile="1"></div>');
        // Kein gültiges Urteil, sondern irgendein Wert - etwa aus einem Addon,
        // das die Schnittstelle falsch verstanden hat.
        $hooks->addFilter('captcha.verify', fn() => true);

        $settings = ['captcha_provider' => 'turnstile'];
        Captcha::renderField($settings, 'dsgvo');
        $this->assertSame(
            Captcha::EXPIRED,
            Captcha::verify($settings, 'dsgvo', ['captcha' => '5']),
            'Nur die vier definierten Urteile zählen; alles andere gilt als "nicht geantwortet".'
        );
    }
}
