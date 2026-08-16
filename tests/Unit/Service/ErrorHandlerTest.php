<?php
// tests/Unit/Service/ErrorHandlerTest.php

namespace Tests\Unit\Service;

use App\Service\ErrorHandler;
use PHPUnit\Framework\TestCase;

/**
 * Die frühere Fassung setzte in Produktion `error_reporting(0)`. Die Stufe
 * ist aber die Maske für Anzeige UND Protokollierung - es wurde damit auch
 * nichts mehr geloggt. Dieser Test nagelt die Trennung fest: alles
 * betrachten, alles protokollieren, nur die Anzeige hängt an der Umgebung.
 */
class ErrorHandlerTest extends TestCase {

    private string $errorReporting;
    private string $logErrors;
    private string $displayErrors;

    protected function setUp(): void {
        $this->errorReporting = (string)ini_get('error_reporting');
        $this->logErrors = (string)ini_get('log_errors');
        $this->displayErrors = (string)ini_get('display_errors');
    }

    protected function tearDown(): void {
        ini_set('error_reporting', $this->errorReporting);
        ini_set('log_errors', $this->logErrors);
        ini_set('display_errors', $this->displayErrors);
        restore_exception_handler();
    }

    public function testProductionLogsEverythingButShowsNothing(): void {
        $this->callRegister(true);

        $this->assertSame(E_ALL, error_reporting(), 'In Produktion muss weiterhin ALLES betrachtet werden');
        $this->assertTrue(self::iniFlag('log_errors'), 'In Produktion muss protokolliert werden');
        $this->assertFalse(self::iniFlag('display_errors'), 'In Produktion darf nichts angezeigt werden');
    }

    public function testDevelopmentAlsoDisplays(): void {
        $this->callRegister(false);

        $this->assertSame(E_ALL, error_reporting());
        $this->assertTrue(self::iniFlag('log_errors'));
        $this->assertTrue(self::iniFlag('display_errors'));
    }

    /**
     * PHP gibt für Schalter je nach Setzweg '', '0', 'Off' oder '1' zurück -
     * ein Vergleich auf einen bestimmten String prüfte die Schreibweise
     * statt den Zustand.
     */
    private static function iniFlag(string $name): bool {
        return filter_var((string)ini_get($name), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * register() schützt sich mit einem statischen Merker gegen doppelte
     * Registrierung (config.php wird in Tests mehrfach eingebunden). Für den
     * Test muss der Merker zurückgesetzt werden, sonst prüfte der zweite
     * Testfall nur, dass nichts passiert ist.
     */
    private function callRegister(bool $isProduction): void {
        $reflection = new \ReflectionClass(ErrorHandler::class);
        $property = $reflection->getProperty('registered');
        $property->setValue(null, false);

        ErrorHandler::register($isProduction);
    }
}
