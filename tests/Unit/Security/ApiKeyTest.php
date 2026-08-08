<?php
// tests/Unit/Security/ApiKeyTest.php

namespace Tests\Unit\Security;

use App\Security\ApiKey;
use PHPUnit\Framework\TestCase;

/**
 * Deckt die zustandslosen Anteile von App\Security\ApiKey ab: Erzeugung und
 * Hashing der Schlüssel sowie den Scope-Anteil der Rechteprüfung, der bereits
 * VOR jedem Datenbankzugriff entscheidet (permits() bricht ab, sobald der
 * Scope die Aktion nicht enthält).
 *
 * Alles Weitere - insbesondere die Schnittmenge mit den echten Rechten des
 * Besitzers, das Limit von 5 Schlüsseln und der Widerruf - hängt an Datenbank
 * und HTTP-Kontext und wird deshalb in tests/Functional/ApiKeyAuthTest.php
 * gegen eine echte Instanz geprüft (gleiche Aufteilung wie bei den übrigen
 * Security-Klassen dieses Repos).
 */
class ApiKeyTest extends TestCase {

    public function testGeneratedTokenHasExpectedFormat(): void {
        $token = ApiKey::generateToken();

        $this->assertMatchesRegularExpression(
            '/^hv_[0-9a-f]{64}$/',
            $token,
            'Schlüssel sollten am Präfix erkennbar sein und 32 Byte (64 Hex-Zeichen) Zufall enthalten.'
        );
    }

    public function testGeneratedTokensAreUnique(): void {
        $tokens = [];
        for ($i = 0; $i < 50; $i++) {
            $tokens[] = ApiKey::generateToken();
        }

        $this->assertCount(50, array_unique($tokens), 'Erzeugte Schlüssel dürfen sich nie wiederholen.');
    }

    public function testHashIsDeterministicSha256AndNotThePlaintext(): void {
        $token = ApiKey::generateToken();
        $hash = ApiKey::hashToken($token);

        $this->assertSame(hash('sha256', $token), $hash);
        $this->assertSame($hash, ApiKey::hashToken($token), 'Gleicher Schlüssel muss denselben Hash liefern.');
        $this->assertNotSame($token, $hash);
        $this->assertStringNotContainsString($token, $hash, 'Der Hash darf den Klartext nicht enthalten.');
    }

    public function testDifferentTokensProduceDifferentHashes(): void {
        $this->assertNotSame(
            ApiKey::hashToken(ApiKey::generateToken()),
            ApiKey::hashToken(ApiKey::generateToken())
        );
    }

    /**
     * Ein eingeschränkter Scope, der die angefragte Aktion nicht enthält, muss
     * bereits ohne Datenbankzugriff ablehnen - unabhängig davon, welche Rechte
     * der Besitzer selbst hätte. Das ist die "weniger Rechte sind möglich"-
     * Hälfte des Rechtemodells.
     */
    public function testPermitsRejectsActionOutsideScopeWithoutConsultingOwnerRights(): void {
        $key = ['user_id' => 1, 'scope' => ['horses.view']];

        $this->assertFalse(ApiKey::permits($key, 'horses', 'edit'));
        $this->assertFalse(ApiKey::permits($key, 'persons', 'view'));
    }

    /**
     * Fail-closed: ein leerer Scope erlaubt nichts (so wird u. a. ein
     * unlesbar gewordener Scope-Eintrag behandelt, siehe authenticate()).
     */
    public function testPermitsRejectsEverythingForEmptyScope(): void {
        $key = ['user_id' => 1, 'scope' => []];

        $this->assertFalse(ApiKey::permits($key, 'horses', 'view'));
    }

    public function testMaxKeysPerUserIsFive(): void {
        $this->assertSame(5, ApiKey::MAX_KEYS_PER_USER);
    }
}
