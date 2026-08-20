<?php
// tests/Functional/ContactLegacyRedirectTest.php

namespace Tests\Functional;

use App\Database;

/**
 * Die alten öffentlichen Adressen `/person?id=` und `/station?id=` (#336).
 *
 * Beide bleiben dauerhaft (HTTP 301) erreichbar und werden über
 * `contact_id_map` auf `/kontakt?id=<neu>` aufgelöst - dieselbe Zusage wie bei
 * `/hengst` (#171, siehe HorseRouteRedirectTest): Was in Suchmaschinen und in
 * fremden Verlinkungen steht, soll weiter funktionieren. Anders als dort steht
 * das Ziel aber nicht im Pfad, sondern in einer Abbildungstabelle - es gibt
 * also keine statische Router-Regel, sondern eine Abfrage, und damit auch
 * einen Fall "keine Abbildung".
 *
 * Der ist der eigentliche Grund für diese Klasse. Festgehalten wird:
 *
 *   1. Eine bekannte Alt-Kennung leitet mit 301 auf die neue Kennung um -
 *      dauerhaft, nicht mit 302: Ein Übergangs-Redirect würde Suchmaschinen
 *      dazu bringen, die alte Adresse weiter als die maßgebliche zu führen.
 *   2. Eine unbekannte Alt-Kennung liefert 404 und leitet NICHT auf den
 *      Katalog um. Wortlos auf einer Trefferliste zu landen, sähe aus wie ein
 *      Treffer; Suchmaschinen würden daraus schließen, die alte Adresse sei
 *      durch den Katalog ersetzt worden.
 *   3. Ein unveröffentlichtes Ziel liefert ebenfalls 404 - sonst verriete
 *      allein die Umleitung, dass es die alte Kennung einmal gab. Bis v0.7
 *      lieferte eine unveröffentlichte Station unter `/station?id=` schlicht
 *      404, und dabei bleibt es.
 *
 * Die beiden Typen werden einzeln geprüft: `old_type` ist der einzige
 * Unterschied zwischen den zwei Aufrufwegen, und die Kennungen der beiden
 * Altbestände überschneiden sich (Person 7 und Station 7 sind verschiedene
 * Datensätze). Ein vertauschter Typ führte also nicht auf 404, sondern auf den
 * FALSCHEN Kontakt - der Fehler, den ein gemeinsamer Test nicht sähe.
 */
class ContactLegacyRedirectTest extends FunctionalTestCase {

    /** @var array<int, int> */
    private array $contactIds = [];

    /**
     * Alt-Kennungen, die es in diesem Testlauf nicht geben soll. Der Bereich
     * liegt bewusst weit oberhalb des Bestands: contact_id_map wird bei der
     * Migration befüllt, und ein zufällig kollidierender Eintrag machte die
     * Negativprüfungen still gegenstandslos.
     */
    private const UNBEKANNTE_ALT_ID = 987654321;

    protected function tearDown(): void {
        $db = Database::getInstance();
        // contact_id_map hängt per FK (ON DELETE CASCADE) am Kontakt und
        // verschwindet mit ihm.
        foreach ($this->contactIds as $id) {
            $db->prepare("DELETE FROM contacts WHERE id = ?")->execute([$id]);
        }
        $this->contactIds = [];
        parent::tearDown();
    }

    public function testOldPersonUrlRedirectsPermanentlyToTheContactPage(): void {
        [$kontaktId, $alteId] = $this->seedContactWithLegacyId('person', true);

        $antwort = $this->newClient()->get('/person?id=' . $alteId);

        $this->assertSame(301, $antwort->statusCode, 'Die alte Personenadresse muss DAUERHAFT umleiten');
        $this->assertSame('/kontakt?id=' . $kontaktId, $antwort->location());
    }

    public function testOldStationUrlRedirectsPermanentlyToTheContactPage(): void {
        [$kontaktId, $alteId] = $this->seedContactWithLegacyId('station', true);

        $antwort = $this->newClient()->get('/station?id=' . $alteId);

        $this->assertSame(301, $antwort->statusCode, 'Die alte Stationsadresse muss DAUERHAFT umleiten');
        $this->assertSame('/kontakt?id=' . $kontaktId, $antwort->location());
    }

    /**
     * Die Typen dürfen nicht durcheinandergehen: Dieselbe Zahl bezeichnete im
     * Altbestand eine Person UND eine Station. Wer den Typ ignoriert, landet
     * beim falschen Menschen - eine Verwechslung, die auf einer Kontaktseite
     * mit Adresse und Telefonnummer teuer ist.
     */
    public function testTheOldTypeIsPartOfTheLookup(): void {
        [, $alteId] = $this->seedContactWithLegacyId('person', true);

        $antwort = $this->newClient()->get('/station?id=' . $alteId);

        $this->assertSame(404, $antwort->statusCode, 'Eine Personen-Altkennung ist keine Stations-Altkennung');
        $this->assertNull($antwort->location());
    }

    public function testUnknownLegacyIdIsNotFoundAndDoesNotFallBackToTheCatalog(): void {
        foreach (['/person', '/station'] as $pfad) {
            $antwort = $this->newClient()->get($pfad . '?id=' . self::UNBEKANNTE_ALT_ID);

            $this->assertSame(
                404,
                $antwort->statusCode,
                "{$pfad} muss für eine unbekannte Kennung 404 liefern"
            );
            // Der Kern der Zusicherung: KEINE Umleitung. Weder auf den Katalog
            // noch sonstwohin - eine tote Kennung darf nicht wie ein Treffer
            // aussehen.
            $this->assertNull(
                $antwort->location(),
                "{$pfad} darf eine unbekannte Kennung nicht auf den Katalog umleiten"
            );
        }
    }

    /**
     * Auch ohne Kennung wird nicht auf den Katalog umgeleitet. Das ist der
     * Unterschied zu /horse und /kontakt, die genau das tun - hier wäre es
     * dieselbe Falschaussage wie bei einer toten Kennung.
     */
    public function testLegacyUrlWithoutAnIdIsNotFoundEither(): void {
        foreach (['/person', '/station'] as $pfad) {
            $antwort = $this->newClient()->get($pfad);

            $this->assertSame(404, $antwort->statusCode, "{$pfad} ohne Kennung muss 404 liefern");
            $this->assertNull($antwort->location(), "{$pfad} ohne Kennung darf nicht umleiten");
        }
    }

    public function testUnpublishedTargetIsNotFoundInsteadOfRedirecting(): void {
        [, $alteId] = $this->seedContactWithLegacyId('station', false);

        $antwort = $this->newClient()->get('/station?id=' . $alteId);

        $this->assertSame(
            404,
            $antwort->statusCode,
            'Ein unveröffentlichter Kontakt darf nicht einmal über die Umleitung bestätigt werden'
        );
        $this->assertNull($antwort->location());
    }

    /**
     * Legt einen Kontakt an und trägt ihn unter einer freien Alt-Kennung in
     * `contact_id_map` ein.
     *
     * @return array{0: int, 1: int} Neue Kontakt-ID, alte Kennung
     */
    private function seedContactWithLegacyId(string $alterTyp, bool $veroeffentlicht): array {
        $db = Database::getInstance();

        $db->prepare("INSERT INTO contacts (name, is_published, created_at) VALUES (?, ?, NOW())")
           ->execute(['Altadresse ' . uniqid(), $veroeffentlicht ? 1 : 0]);
        $kontaktId = (int)$db->lastInsertId();
        $this->contactIds[] = $kontaktId;

        // Freie Alt-Kennung: Der Primärschlüssel (old_type, old_id) verbietet
        // Dubletten, und die Tabelle wird von der Migration befüllt - die
        // nächste freie Zahl oberhalb des Bestands ist deshalb die einzige,
        // die in jedem Zustand der Test-DB funktioniert.
        $stmt = $db->prepare("SELECT COALESCE(MAX(old_id), 0) + 1 FROM contact_id_map WHERE old_type = ?");
        $stmt->execute([$alterTyp]);
        $alteId = (int)$stmt->fetchColumn();

        $db->prepare("INSERT INTO contact_id_map (old_type, old_id, contact_id) VALUES (?, ?, ?)")
           ->execute([$alterTyp, $alteId, $kontaktId]);

        $this->assertLessThan(
            self::UNBEKANNTE_ALT_ID,
            $alteId,
            'Die Test-Altkennung darf der als unbekannt geprüften nicht in die Quere kommen'
        );

        return [$kontaktId, $alteId];
    }
}
