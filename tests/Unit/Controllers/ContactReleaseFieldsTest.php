<?php
// tests/Unit/Controllers/ContactReleaseFieldsTest.php

namespace Tests\Unit\Controllers;

use App\Controllers\ContactController;
use PHPUnit\Framework\TestCase;

/**
 * ContactController::RELEASE_GATED_FIELDS ist die einzige Liste der Felder,
 * die nur bei `contact_public = 1` öffentlich sind (Audit M9). Die öffentliche
 * Kontaktseite wählt damit ihre Spalten, das Zusammenführen hält damit
 * Angaben zurück. Ein Feld zu viel machte ein immer öffentliches Feld
 * unsichtbar; ein Feld zu wenig machte ein privates öffentlich - deshalb
 * wird die Liste hier genau festgehalten.
 */
class ContactReleaseFieldsTest extends TestCase {

    public function testTheListContainsExactlyTheDeliverableFields(): void {
        $erwartet = ['address', 'contact_person', 'email', 'house_number', 'mobile', 'phone', 'postal_code', 'street'];
        $ist = ContactController::RELEASE_GATED_FIELDS;
        sort($ist);
        $this->assertSame($erwartet, $ist);
    }

    /**
     * Teilmenge von CONTACT_FIELDS: Nur Felder, die das Formular pflegt und
     * das Zusammenführen auffüllt, können dort zurückgehalten werden.
     * CONTACT_FIELDS ist privat, deshalb über Reflection.
     */
    public function testTheListIsASubsetOfTheContactFields(): void {
        $kontaktFelder = (new \ReflectionClassConstant(ContactController::class, 'CONTACT_FIELDS'))->getValue();
        $this->assertIsArray($kontaktFelder);
        $this->assertSame([], array_values(array_diff(ContactController::RELEASE_GATED_FIELDS, $kontaktFelder)));
    }

    /**
     * Immer öffentliche Felder (name, city, state, country, website) und das
     * nie öffentliche contact_info gehören nicht hinein.
     */
    public function testAlwaysPublicAndNeverPublicFieldsAreNotListed(): void {
        foreach (['name', 'city', 'state', 'country', 'website', 'contact_info', 'is_breeder', 'contact_public'] as $feld) {
            $this->assertNotContains($feld, ContactController::RELEASE_GATED_FIELDS, "{$feld} darf nicht freigabepflichtig sein");
        }
    }
}
