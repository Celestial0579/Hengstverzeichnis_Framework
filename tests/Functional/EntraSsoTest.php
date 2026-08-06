<?php
// tests/Functional/EntraSsoTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstests für den optionalen EntraID-SSO-Login (#42, siehe
 * EntraSsoController): Ohne konfigurierte ENTRA_*-Werte (wie in dieser
 * Testumgebung) sind die SSO-Routen nicht erreichbar (404) und der
 * Login-Button erscheint nicht - SSO ist strikt opt-in. Der vollständige
 * OIDC-Flow gegen Microsoft lässt sich ohne echten Tenant nicht funktional
 * testen; die Claim-Validierung ist in OidcIdTokenTest (Unit) abgedeckt.
 */
class EntraSsoTest extends FunctionalTestCase {

    public function testSsoRoutesAreUnavailableWithoutConfiguration(): void {
        $this->authenticatedClient(); // provisioniert bei isoliertem Lauf
        $client = $this->newClient();

        $this->assertSame(404, $client->get('/auth/entra')->statusCode);
        $this->assertSame(404, $client->get('/auth/entra/callback')->statusCode);
    }

    public function testLoginPageHidesSsoButtonWithoutConfiguration(): void {
        $this->authenticatedClient();
        $client = $this->newClient();

        $loginPage = $client->get('/login');
        $this->assertSame(200, $loginPage->statusCode);
        $this->assertStringNotContainsString('/auth/entra', $loginPage->body);
    }
}
