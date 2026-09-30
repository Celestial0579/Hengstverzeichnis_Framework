<?php
// tests/Unit/Permission/FeatureRegistryKeyTest.php

namespace Tests\Unit\Permission;

use App\Permission\FeatureRegistry;
use App\Security\CaptchaContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Einstellungs- und Modulschlüssel von Zusatzfunktionen und Captcha-Kontexten
 * passen in VARCHAR(50) (Audit N74). Bis 30 bzw. 42 Zeichen Funktionsname
 * bleiben sie wie bisher, darüber werden sie gehasht.
 */
class FeatureRegistryKeyTest extends TestCase {

    /**
     * @return array<string, array{0:int}>
     */
    public static function lengths(): array {
        return ['21' => [21], '31' => [31], '43' => [43], '60' => [60]];
    }

    #[DataProvider('lengths')]
    public function testKeysFitTheirColumns(int $length): void {
        $key = substr('f' . str_repeat('abcdefghij', 7), 0, $length);

        $setting = FeatureRegistry::settingKey($key);
        $module = FeatureRegistry::permissionModule($key);

        $this->assertLessThanOrEqual(50, mb_strlen($setting));
        $this->assertLessThanOrEqual(50, mb_strlen($module));
        $this->assertStringStartsWith('feature_visibility__', $setting);
        $this->assertStringStartsWith('feature_', $module);

        // Bisherige Form, solange sie passt.
        if (20 + $length <= 50) {
            $this->assertSame('feature_visibility__' . $key, $setting);
        } else {
            $this->assertSame(50, mb_strlen($setting));
        }
        if (8 + $length <= 50) {
            $this->assertSame('feature_' . $key, $module);
        } else {
            $this->assertSame(50, mb_strlen($module));
        }
    }

    public function testExistingKeysAreUnchanged(): void {
        $this->assertSame('feature_visibility__beispiel-schaufenster', FeatureRegistry::settingKey('beispiel-schaufenster'));
        $this->assertSame('feature_demo-premium', FeatureRegistry::permissionModule('demo-premium'));
    }

    public function testLongestCaptchaContextFits(): void {
        $context = 'k' . str_repeat('x', 63);

        $this->assertSame(50, mb_strlen(CaptchaContext::settingKey($context)));
        $this->assertStringStartsWith('captcha_provider_', CaptchaContext::settingKey($context));
        $this->assertSame('captcha_provider_kontaktanfrage', CaptchaContext::settingKey('kontaktanfrage'));
    }
}
