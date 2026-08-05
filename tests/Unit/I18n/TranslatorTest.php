<?php
// tests/Unit/I18n/TranslatorTest.php

namespace Tests\Unit\I18n;

use App\I18n\Translator;
use PHPUnit\Framework\TestCase;

class TranslatorTest extends TestCase {

    protected function setUp(): void {
        Translator::resetForTests();
    }

    protected function tearDown(): void {
        Translator::resetForTests();
    }

    public function testDefaultsToFallbackLocaleWithoutInit(): void {
        $this->assertSame('de', Translator::getLocale());
    }

    public function testInitWithKnownLocaleSwitchesActiveLocale(): void {
        Translator::init('en');
        $this->assertSame('en', Translator::getLocale());
    }

    public function testInitWithUnknownLocaleFallsBackSafely(): void {
        Translator::init('xx-not-a-real-locale');
        $this->assertSame('de', Translator::getLocale());
    }

    public function testTranslatesKnownCoreKeyInGerman(): void {
        Translator::init('de');
        $this->assertSame('Startseite', Translator::t('nav.home'));
    }

    public function testTranslatesKnownCoreKeyInEnglish(): void {
        Translator::init('en');
        $this->assertSame('Home', Translator::t('nav.home'));
    }

    public function testMissingKeyReturnsKeyItselfInsteadOfEmptyString(): void {
        Translator::init('de');
        $this->assertSame('nav.does_not_exist', Translator::t('nav.does_not_exist'));
    }

    public function testUnregisteredDomainReturnsKeyItself(): void {
        Translator::init('de');
        $this->assertSame('detail_heading', Translator::t('detail_heading', [], 'never-registered-plugin'));
    }

    public function testRegisteredPluginDomainIsLoadedFromItsOwnLangDirectory(): void {
        Translator::init('de');
        Translator::registerDomain('demo-plugin', __DIR__ . '/../../../docs/examples/demo-plugin/lang');

        $this->assertSame('👋 Demo-Plugin', Translator::t('detail_heading', [], 'demo-plugin'));
    }

    public function testRegisteredPluginDomainFollowsActiveLocale(): void {
        Translator::init('en');
        Translator::registerDomain('demo-plugin', __DIR__ . '/../../../docs/examples/demo-plugin/lang');

        $this->assertSame('👋 Demo Plugin', Translator::t('detail_heading', [], 'demo-plugin'));
    }

    public function testFirstRegistrationOfADomainWins(): void {
        $firstDir = sys_get_temp_dir() . '/translator-test-first-' . uniqid();
        $secondDir = sys_get_temp_dir() . '/translator-test-second-' . uniqid();
        mkdir($firstDir);
        mkdir($secondDir);
        file_put_contents($firstDir . '/de.php', "<?php return ['greeting' => 'Erste Registrierung'];");
        file_put_contents($secondDir . '/de.php', "<?php return ['greeting' => 'Zweite Registrierung'];");

        Translator::init('de');
        Translator::registerDomain('some-plugin', $firstDir);
        Translator::registerDomain('some-plugin', $secondDir);

        $this->assertSame('Erste Registrierung', Translator::t('greeting', [], 'some-plugin'));

        unlink($firstDir . '/de.php');
        unlink($secondDir . '/de.php');
        rmdir($firstDir);
        rmdir($secondDir);
    }

    public function testCoreDomainCannotBeOverriddenByRegisterDomain(): void {
        Translator::init('de');
        Translator::registerDomain('core', sys_get_temp_dir());

        $this->assertSame('Startseite', Translator::t('nav.home'));
    }

    public function testPlaceholderInterpolation(): void {
        $dir = sys_get_temp_dir() . '/translator-test-params-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/de.php', "<?php return ['greeting' => 'Hallo {name}!'];");

        Translator::init('de');
        Translator::registerDomain('greeting-plugin', $dir);

        $this->assertSame('Hallo Welt!', Translator::t('greeting', ['name' => 'Welt'], 'greeting-plugin'));

        unlink($dir . '/de.php');
        rmdir($dir);
    }
}
