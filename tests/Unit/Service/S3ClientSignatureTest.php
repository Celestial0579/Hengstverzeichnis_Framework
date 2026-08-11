<?php
// tests/Unit/Service/S3ClientSignatureTest.php

namespace Tests\Unit\Service;

use App\Service\S3Client;
use PHPUnit\Framework\TestCase;

/**
 * Prüft App\Service\S3Client::sign() (AWS Signature Version 4) gegen fest
 * verdrahtete Eingaben mit fest verdrahteten erwarteten Ausgaben.
 *
 * Die erwarteten Werte wurden NICHT aus S3Client selbst übernommen, sondern
 * gegen eine unabhängig entwickelte Python-Referenzimplementierung
 * desselben öffentlich dokumentierten Algorithmus geprüft (hashlib/hmac,
 * ohne Code-Wiederverwendung mit S3Client) - beide Implementierungen
 * lieferten für dieselben Eingaben byte-identische kanonische Anfragen,
 * String-to-Sign-Werte und Signaturen. Damit ist ausgeschlossen, dass sich
 * hier lediglich ein in S3Client selbst enthaltener Fehler bestätigt.
 */
class S3ClientSignatureTest extends TestCase {

    public function testPutObjectSignatureMatchesIndependentReferenceImplementation(): void {
        $result = S3Client::sign(
            'PUT',
            '/backups/db-20260805-120000.sql.gz',
            [],
            ['Host' => 'test-bucket.s3.eu-central-1.amazonaws.com', 'Content-Type' => 'application/gzip'],
            'hello world backup content',
            'eu-central-1',
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            '20260805T120000Z'
        );

        $expectedCanonicalRequest = implode("\n", [
            'PUT',
            '/backups/db-20260805-120000.sql.gz',
            '',
            'content-type:application/gzip',
            'host:test-bucket.s3.eu-central-1.amazonaws.com',
            'x-amz-content-sha256:ead190a427b6cc692f9196479d2631757aba045329358ae4718a6942113a0b5d',
            'x-amz-date:20260805T120000Z',
            '',
            'content-type;host;x-amz-content-sha256;x-amz-date',
            'ead190a427b6cc692f9196479d2631757aba045329358ae4718a6942113a0b5d',
        ]);

        $this->assertSame($expectedCanonicalRequest, $result['canonicalRequest']);
        $this->assertSame('badafc0aebe287219f827cd78d6566179a10a391b02abade90bafac4fbecf440', $result['signature']);
        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20260805/eu-central-1/s3/aws4_request, SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date, Signature=badafc0aebe287219f827cd78d6566179a10a391b02abade90bafac4fbecf440',
            $result['authorizationHeader']
        );
    }

    public function testListObjectsSignatureWithSpecialCharactersInQueryAndPathMatchesReference(): void {
        $result = S3Client::sign(
            'GET',
            '/my%20bucket/backups/db%202026.sql',
            ['list-type' => '2', 'prefix' => 'backups/db 2026'],
            ['Host' => 's3.example-endpoint.com'],
            '',
            'us-east-1',
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            '20260101T000000Z'
        );

        // Query-Werte müssen ebenfalls vollständig URI-kodiert werden (inkl. '/'),
        // anders als im Pfad, wo '/' als Trenner erhalten bleibt.
        $this->assertSame('list-type=2&prefix=backups%2Fdb%202026', $result['canonicalQueryString']);
        $this->assertSame('624c507ddd377f4dcdd5227cb2b7b094ef717be6ed449c4147d2d006e06feede', $result['signature']);
    }

    public function testEmptyBodyPayloadHashIsSha256OfEmptyString(): void {
        $result = S3Client::sign(
            'DELETE',
            '/bucket/object.txt',
            [],
            ['Host' => 's3.example.com'],
            '',
            'us-east-1',
            'AKID',
            'secret',
            '20260101T000000Z'
        );

        $this->assertStringContainsString(
            'x-amz-content-sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            $result['canonicalRequest']
        );
    }

    /**
     * Der streamende Datei-Weg (#237, S3Client::putObjectFromFile()) signiert
     * mit vorab berechnetem Payload-Hash (hash_file()) statt mit dem Body
     * selbst - beide Wege müssen für denselben Inhalt dieselbe Signatur
     * ergeben, sonst wiese das Ziel den streamenden Upload ab.
     */
    public function testSignWithPayloadHashMatchesSignForSameBody(): void {
        $body = 'hello world backup content';

        $viaBody = S3Client::sign(
            'PUT',
            '/backups/db-20260805-120000.sql.gz',
            [],
            ['Host' => 'test-bucket.s3.eu-central-1.amazonaws.com', 'Content-Type' => 'application/gzip'],
            $body,
            'eu-central-1',
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            '20260805T120000Z'
        );
        $viaHash = S3Client::signWithPayloadHash(
            'PUT',
            '/backups/db-20260805-120000.sql.gz',
            [],
            ['Host' => 'test-bucket.s3.eu-central-1.amazonaws.com', 'Content-Type' => 'application/gzip'],
            hash('sha256', $body),
            'eu-central-1',
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            '20260805T120000Z'
        );

        $this->assertSame($viaBody, $viaHash);
    }
}
