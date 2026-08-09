# JSON-API (`/api/horses`)

Schlanke, **rein lesende** JSON-API für Katalogdaten ([#47](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/47)), gedacht für Verbandsseiten oder Drittsysteme, die Katalogdaten programmatisch einbinden wollen, statt HTML zu scrapen.

## Authentifizierung

Die API ist **ausschließlich mit einem gültigen API-Schlüssel** erreichbar -
es gibt keinen anonymen Zugriff. Der Schlüssel wird als Bearer-Token im
`Authorization`-Header übergeben:

```bash
curl -H "Authorization: Bearer hv_..." "https://example.org/api/horses"
```

Ohne gültigen Schlüssel antwortet die API mit `401`, dem Header
`WWW-Authenticate: Bearer realm="api"` und einem Body der Form
`{"error":"unauthorized","message":"..."}`.

**Bewusst kein `?api_key=`-Parameter:** Query-Parameter landen in
Server-Logfiles, `Referer`-Headern und der Browser-History. Aus demselben
Grund akzeptiert auch der Cron-Endpunkt sein Secret nur noch per Header
([#114](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/114)).

### Schlüssel verwalten

Jeder angemeldete Benutzer verwaltet seine eigenen Schlüssel unter
**`/api-keys`** (auch als Kachel „🔑 API-Schlüssel" im Dashboard):

- **Maximal 5 aktive Schlüssel** je Benutzer.
- Der Klartext-Schlüssel wird **genau einmal** direkt nach dem Anlegen
  angezeigt. Gespeichert wird nur sein SHA-256-Hash - er kann später nicht
  erneut abgerufen werden (wie die 2FA-Backup-Codes). Geht er verloren:
  widerrufen und einen neuen anlegen.
- Ein Widerruf wirkt sofort für alle Anwendungen, die den Schlüssel nutzen.

### Rechtemodell

Die effektiven Rechte eines Schlüssels sind die **Schnittmenge** aus

1. den **aktuellen** Rechten seines Besitzers (Gruppen bzw. `admin`) und
2. dem beim Anlegen gewählten **Scope** des Schlüssels.

Daraus folgt beides bewusst:

- Ein Schlüssel kann **nie mehr** als sein Besitzer. Verliert der Besitzer ein
  Recht, verliert der bereits ausgegebene Schlüssel es im selben Moment mit -
  es wird nichts eingefroren, was beim Anlegen galt.
- Ein Schlüssel kann bewusst **weniger** dürfen (Least Privilege), z. B. ein
  reiner Lese-Schlüssel für ein Drittsystem, obwohl der Besitzer selbst
  Schreibrechte hat. Für `/api/horses` ist `horses.view` das relevante Recht.

## Sicherheits-/Sichtbarkeitsmodell

Die API liefert **ausschließlich Felder, die auch über den öffentlichen
HTML-Katalog** (`/katalog`, `/hengst?id=...`) einsehbar sind:

- Nur veröffentlichte Pferde (`is_published`); gelöschte (`deleted_at`)
  erscheinen nie - genau wie im HTML-Katalog.
- Dieselbe Filterung gilt für **verknüpfte** Datensätze: `breeder`/`owner`
  stammen nur aus veröffentlichten Personen; ein unveröffentlichter
  verknüpfter Elternteil fällt auf den Freitext-Namen (`sire_name`/
  `dam_name`) zurück. Das Feld `breeding_station` ist `null`, wenn die
  verknüpfte Deckstation unveröffentlicht oder gelöscht ist - auch dann,
  wenn im Backend ein Name gepflegt ist (die denormalisierte Namenskopie
  wird bewusst unterdrückt, damit sie kein Leck bildet); reiner Freitext
  ohne Stations-Verknüpfung bleibt erhalten.
- Fehlt der Gast-Gruppe `horses.view`, liefert `GET /api/horses` eine
  **leere Liste mit `200`** (kein `403`) und `GET /api/horses/show` ein
  `404` - die API verrät dann nicht, ob Daten existieren.
- **Kein Wildcard-CORS mehr.** Seit der Schlüsselpflicht wird kein
  `Access-Control-Allow-Origin: *` gesetzt: ein Schlüssel gehört nicht in
  Browser-JavaScript, wo ihn jeder Besucher auslesen könnte. Serverseitige
  Aufrufe - der vorgesehene Weg für Drittsysteme - unterliegen keiner
  Same-Origin-Policy und sind davon nicht betroffen. Wer die Daten im Browser
  braucht, kapselt die API hinter einem eigenen Backend-Proxy.
- Antworten tragen `Cache-Control: no-store`, damit rechtegebundene Inhalte
  nicht in gemeinsam genutzten Proxy-Caches landen.
- Kein Rate-Limiting: Der `RateLimiter` des Kerns schützt gezielt
  Missbrauchsflächen (Login, 2FA, Registrierung, Passwort-Reset,
  DSGVO-Formular) - Katalog und API sind bewusst nicht limitiert.

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
curl -H "Authorization: Bearer hv_..." \
  "https://example.org/api/horses?status=active&birth_year_from=2015&per_page=25"
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
      "sex": "stallion",
      "breed": "Trakehner",
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
`foreign_ueln`). Antwortet mit `404` und
`{"error":"not_found","message":"..."}`, falls keine Übereinstimmung
existiert, oder `400` und `{"error":"missing_ueln","message":"..."}`, falls
der Parameter fehlt.

Aus Konsistenz mit dem restlichen Routing des Kerns (`App\Router` unterstützt
ausschließlich exakte Pfade, keine Platzhalter-Segmente) als Query-Parameter
statt Pfad-Segment (`/api/horses/{ueln}`) umgesetzt.

```json
{ "data": { "id": 42, "name": "Quantum", "...": "..." } }
```
