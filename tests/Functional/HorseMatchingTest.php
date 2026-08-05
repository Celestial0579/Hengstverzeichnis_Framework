<?php
// tests/Functional/HorseMatchingTest.php

namespace Tests\Functional;

/**
 * HTTP-Funktionstest für die Blutlinien-Match-Logik (siehe Issue #54):
 * Anlegen eines Pferds mit unaufgelöster Vater-Angabe (nur sire_name, kein
 * sire_id/sire_ueln), Prüfen des Match-Vorschlags in /admin/matches und
 * manuelles Verknüpfen über /admin/matches/link.
 *
 * Alles in einer einzigen Testmethode, damit keine Annahme über die
 * Ausführungsreihenfolge mehrerer Testmethoden nötig ist (PHPUnit garantiert
 * das nicht) - die Schritte bauen zwingend aufeinander auf.
 */
class HorseMatchingTest extends FunctionalTestCase {

    public function testUnresolvedSirePlaceholderCanBeMatchedAndLinked(): void {
        $client = $this->authenticatedClient();

        // 1. Kandidat-Hengst anlegen ("Quantum") - potenzieller Vater.
        $createForm = $client->get('/admin/horses/create');
        $parentResponse = $client->post('/admin/horses/store', [
            'csrf_token' => $createForm->formField('csrf_token') ?? '',
            'name' => 'Quantum',
            'color' => 'Fuchs',
            'breeding_station' => 'Testgestüt',
            'birth_year' => '2005',
            'status' => 'active',
        ]);
        $this->assertSame('/admin/horses?success=created', $parentResponse->location());

        // 2. Nachkommen mit unaufgelöster Vater-Angabe anlegen (nur sire_name,
        // absichtlich eine leichte Abweichung "Quantom" statt "Quantum" - ein
        // exakter Namenstreffer würde sonst sofort von autoLinkMatches() beim
        // Anlegen des Kandidaten automatisch verknüpft, statt als Vorschlag im
        // Match-Tool zu erscheinen, siehe HorseController::autoLinkMatches()).
        $createForm2 = $client->get('/admin/horses/create');
        $childResponse = $client->post('/admin/horses/store', [
            'csrf_token' => $createForm2->formField('csrf_token') ?? '',
            'name' => 'Quantom Junior',
            'sire_name' => 'Quantom',
            'color' => 'Fuchs',
            'breeding_station' => 'Testgestüt',
            'birth_year' => '2015',
            'status' => 'active',
        ]);
        $this->assertSame('/admin/horses?success=created', $childResponse->location());

        // 3. Match-Vorschlag muss erscheinen (Name-Ähnlichkeit "quantom"/"quantum"
        // ~86%, plausibles Elternalter, identische Farbe/Deckstation -> Score
        // deutlich über der 45%-Schwelle in calculateSuggestions()).
        $matchesPage = $client->get('/admin/matches');
        $this->assertSame(200, $matchesPage->statusCode);
        $this->assertStringContainsString('Quantom Junior', $matchesPage->body);
        $this->assertStringContainsString(
            'Quantum',
            $matchesPage->body,
            'Kandidat "Quantum" sollte als Vorschlag für "Quantom Junior" auftauchen'
        );

        $childId = $matchesPage->formField('child_id');
        $parentType = $matchesPage->formField('parent_type');
        $parentHorseId = $matchesPage->formField('parent_horse_id');
        $linkCsrf = $matchesPage->formField('csrf_token');

        $this->assertNotNull($childId, 'child_id nicht im Match-Vorschlag gefunden');
        $this->assertSame('sire', $parentType, 'parent_type sollte "sire" sein');
        $this->assertNotNull($parentHorseId, 'parent_horse_id nicht im Match-Vorschlag gefunden');

        // 4. Vorschlag bestätigen/verknüpfen.
        $linkResponse = $client->post('/admin/matches/link', [
            'csrf_token' => $linkCsrf ?? '',
            'child_id' => $childId,
            'parent_type' => $parentType,
            'parent_horse_id' => $parentHorseId,
        ]);
        $this->assertSame('/admin/matches?success=linked', $linkResponse->location());

        // 5. Danach keine offenen Vorschläge mehr für dieses Paar.
        $matchesAfterLink = $client->get('/admin/matches');
        $this->assertStringContainsString(
            'Keine unvollständigen Eltern-Einträge gefunden',
            $matchesAfterLink->body
        );

        // 6. Bearbeiten-Ansicht des Nachkommen zeigt die Platzhalter-Vater-Angabe
        // nicht mehr - sire_name wurde beim Verknüpfen genullt (siehe linkMatch()).
        $editPage = $client->get('/admin/horses/edit?id=' . $childId);
        $this->assertNotSame('Quantom', $editPage->formField('sire_name'));
    }
}
