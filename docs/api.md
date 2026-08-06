# Öffentliche JSON-API (`/api/horses`)

Schlanke, **rein lesende** JSON-API für Katalogdaten ([#47](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/47)), gedacht für Verbandsseiten oder Drittsysteme, die Katalogdaten programmatisch einbinden wollen, statt HTML zu scrapen.

## Sicherheits-/Sichtbarkeitsmodell

Die API liefert **ausschließlich Felder, die bereits über den öffentlichen
HTML-Katalog** (`/katalog`, `/hengst?id=...`) einsehbar sind - kein eigenes
Berechtigungsmodell, keine Authentifizierung, kein API-Key. Das ist bewusst
so:

- Es werden keine zusätzlichen Daten offengelegt, nur ein maschinenlesbares
  Format derselben, ohnehin öffentlichen Informationen.
- Gelöschte (`deleted_at`) Pferde erscheinen nie, genau wie im HTML-Katalog.
- Der Endpunkt ist unauthentifiziert per CORS für beliebige Origins
  erreichbar (`Access-Control-Allow-Origin: *`) - ein zusätzlicher
  CORS-Schutz brächte hier keinen echten Sicherheitsgewinn (dieselben Daten
  sind bereits über den HTML-Katalog crawlbar), würde aber die Einbindung
  durch Drittsysteme unnötig erschweren.
- Sollten künftig nicht-öffentliche Felder relevant werden, braucht das eine
  eigene Betrachtung (z. B. API-Key-Pflicht) - siehe Ursprungs-Issue.
- Kein eigenes Rate-Limiting über das der übrigen öffentlichen Seiten hinaus
  (dieselbe Vertrauensstufe wie der bestehende HTML-Katalog).

## `GET /api/horses`

Liste aller öffentlich sichtbaren Pferde, optional gefiltert und paginiert.

**Query-Parameter (alle optional):**

| Parameter | Beschreibung |
|---|---|
| `search` | Volltextsuche über Name, UELN, ausländische UELN |
| `name` | Teilstring-Suche im Namen |
| `ueln` | Teilstring-Suche über UELN/ausländische UELN |
| `color` | Teilstring-Suche in der Farbbezeichnung |
| `status` | Exakt `active`, `inactive` oder `deceased` |
| `birth_year_from` / `birth_year_to` | Geburtsjahr-Bereich |
| `page` | Seite (Standard `1`) |
| `per_page` | `10`/`25`/`50`/`100`/`all` (Standard `50`) |

Bewusst ein kleinerer Filtersatz als die interaktive Katalog-Seite (dort u. a.
zusätzlich Zucht-/Besitzer-/Deckstation-Filter) - bei Bedarf später
erweiterbar, siehe `App\Controllers\ApiController::fetchHorses()`.

**Beispiel:**

```bash
curl "https://example.org/api/horses?status=active&birth_year_from=2015&per_page=25"
```

```json
{
  "data": [
    {
      "id": 42,
      "name": "Quantum",
      "ueln": "DE001TESTM01",
      "foreign_ueln": null,
      "birth_year": 2015,
      "color": "Rappe",
      "status": "active",
      "image_url": "/uploads/horses/quantum.jpg",
      "breeding_station": "Gestüt Musterhof",
      "sire": { "name": "Quantensprung", "ueln": "DE002TESTM02" },
      "dam": null,
      "breeder": "Max Mustermann",
      "owner": "Erika Musterfrau",
      "profile_url": "/hengst?id=42"
    }
  ],
  "meta": { "page": 1, "per_page": 25, "total_pages": 1, "total": 1 }
}
```

## `GET /api/horses/show?ueln=...`

Einzelnes Pferd über seine UELN (exakte Übereinstimmung, `ueln` oder
`foreign_ueln`). Antwortet mit `404`, falls keine Übereinstimmung existiert,
oder `400`, falls der Parameter fehlt.

Aus Konsistenz mit dem restlichen Routing des Kerns (`App\Router` unterstützt
ausschließlich exakte Pfade, keine Platzhalter-Segmente) als Query-Parameter
statt Pfad-Segment (`/api/horses/{ueln}`) umgesetzt.

```json
{ "data": { "id": 42, "name": "Quantum", "...": "..." } }
```
