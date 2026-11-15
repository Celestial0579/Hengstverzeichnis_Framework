<?php
// tests/Unit/AuxiliaryServerTest.php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\AuxiliaryServer;

/**
 * #528: Ein belegter Port bricht den Start des Hilfsservers ab, statt dass
 * der Test still mit dem fremden Prozess spricht.
 */
class AuxiliaryServerTest extends TestCase {

    public function testBelegterPortBrichtDenStartAb(): void {
        $fremd = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($fremd, $errstr);
        $port = (int)substr((string)strrchr((string)stream_socket_get_name($fremd, false), ':'), 1);

        $server = new AuxiliaryServer($port, sys_get_temp_dir());
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("Port {$port} ist schon belegt");
            $server->start();
        } finally {
            $server->stop();
            fclose($fremd);
        }
    }
}
