# JSON-API (`/api/horses`, `/api/stats`)

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
  Schreibrechte hat. Für `/api/horses` ist `horses.view` das relevante Recht,
  für `/api/stats` das eigene Recht `stats.view` (siehe unten).

## Sicherheits-/Sichtbarkeitsmodell

Die API liefert **ausschließlich Felder, die auch über den öffentlichen
HTML-Katalog** (`/katalog`, `/horse?id=...`) einsehbar sind:

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
| `status` | Exakt `active` oder `inactive` (Zuchtstatus) |
| `deceased` | `0` (nur lebende) oder `1` (nur verstorbene) |
| `birth_year_from` / `birth_year_to` | Geburtsjahr-Bereich |
| `page` | Seite (Standard `1`) |
| `per_page` | `10`/`25`/`50`/`100`/`all` (Standard `50`) |

Bewusst ein kleinerer Filtersatz als die interaktive Katalog-Seite (dort u. a.
zusätzlich Zucht-/Besitzer-/Deckstation-Filter) - bei Bedarf später
erweiterbar, siehe `App\Controllers\ApiController::fetchHorses()`.

> **Breaking in 0.x (Status-Split, #188):** Das Response-Feld `status` liefert
> nur noch den Zuchtstatus (`active`/`inactive`) und nie mehr `deceased`; der
> Lebensstatus steht in den neuen Feldern `is_deceased`/`death_year`. Ein
> Filter `status=deceased` fällt wie jeder unbekannte Wert aus der Whitelist
> und wird ignoriert - stattdessen `deceased=1` verwenden.

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
      "birth_date": "2015-06-13",
      "color": "Rappe",
      "sex": "stallion",
      "breed": "Trakehner",
      "height_cm": 146,
      "status": "active",
      "is_deceased": false,
      "death_year": null,
      "image_url": "/media/horse-image?id=42",
      "breeding_station": "Gestüt Musterhof",
      "sire": { "name": "Quantensprung", "ueln": "DE002TESTM02" },
      "dam": null,
      "breeder": "Max Mustermann",
      "owner": "Erika Musterfrau",
      "profile_url": "/horse?id=42"
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

## `GET /api/stats`

Zeitreihen für externe Dashboards ([#270](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/270)).
Gedacht für Grafana über eine generische JSON-Datenquelle (z. B. das
Infinity-Plugin), die Bearer-Header nativ kennt — es braucht kein eigenes
Grafana-Plugin.

### Eigenes Recht: `stats.view`

Dieser Endpunkt verlangt **zusätzlich** zum Schlüssel das Recht
`stats.view`. Ein Katalog-Schlüssel mit `horses.view` bekommt hier `403`.

Das ist Absicht: Die Zahlen sind betriebsintern — DSGVO-Anfragen,
Login-Fehlversuche, Benutzer- und Schlüsselbestand. Ein offener oder nur
katalogweit geschützter Endpunkt verriete einem Angreifer, ob seine Versuche
ankommen. Das Modul `stats` ist **in keiner Standardgruppe vorbelegt**; der
Betreiber vergibt es bewusst (Benutzergruppen → Statistik-Schnittstelle →
Lesen). Ein `admin` hat es wie alles andere ohnehin.

### Parameter

| Parameter | Pflicht | Bedeutung |
|---|---|---|
| `metric` | ja¹ | Welche Reihe. Unbekannter Wert → `400 unknown_metric` mit Liste. |
| `from`, `to` | nein | `JJJJ-MM-TT`, beide **einschließlich**. Standard: die letzten 30 Tage bis heute. |
| `interval` | nein | `day` (Standard), `week` (ab Montag, ISO-8601) oder `month`. |
| `filter` | nein | Wertfilter, nur bei Reihen mit Filterspalte. Sonst `400 filter_not_supported`. |

¹ Ohne `metric` liefert der Endpunkt den **Katalog** der verfügbaren Reihen —
so lässt sich die Datenquelle einrichten, ohne hier nachzuschlagen.

### Reihen

| `metric` | zählt | `filter` |
|---|---|---|
| `horses.created` | angelegte Pferde (ohne gelöschte) | — |
| `horses.published` | angelegte Pferde, die **heute** veröffentlicht sind | — |
| `gdpr_requests.created` | DSGVO-Anfragen | `info`, `deletion` |
| `audit_logs.created` | Audit-Ereignisse | Kategorie, z. B. `security` |
| `login_attempts.created` | fehlgeschlagene Anmeldeversuche | Typ, z. B. `login` |
| `api_keys.created` | ausgestellte API-Schlüssel | — |
| `api_keys.last_used` | zuletzt benutzte API-Schlüssel | — |
| `users.created` | angelegte Benutzerkonten | — |

`horses.published` gruppiert nach **Anlage**-Datum, nicht nach
Veröffentlichungsdatum: Das Schema führt keinen Zeitstempel fürs
Veröffentlichen. Die Reihe beantwortet also „wie viele der damals angelegten
Pferde sind heute öffentlich“ — nicht „wann wurde veröffentlicht“.

### Antwort

```json
{
  "data": [
    { "bucket": "2026-08-14", "value": 3 },
    { "bucket": "2026-08-15", "value": 0 },
    { "bucket": "2026-08-16", "value": 7 }
  ],
  "meta": {
    "metric": "horses.created",
    "label": "Angelegte Pferde (ohne gelöschte)",
    "interval": "day",
    "from": "2026-08-14",
    "to": "2026-08-16",
    "filter": null,
    "buckets": 3,
    "total": 10
  }
}
```

**Lücken sind mit `0` gefüllt.** Ohne das zieht Grafana eine Linie vom letzten
Datenpunkt zum nächsten und überspringt die leeren Tage — ein Tag ohne
Anmeldeversuche sähe aus wie ein Tag, den es nicht gab.

### Grenzen und Fehler

Eine Antwort umfasst höchstens **1500 Kübel**. Darüber `400 range_too_large`
mit dem Hinweis, den Zeitraum zu verkürzen oder gröber zu gruppieren — die
Lückenfüllung erzeugt die Zeilen sonst auch dann, wenn gar keine Daten
vorliegen.

Ungültige Angaben werden **abgewiesen statt still ersetzt**: `invalid_date`
(kein `JJJJ-MM-TT`, auch `2026-02-31` oder `yesterday`), `invalid_range`
(`from` nach `to`), `unknown_metric`, `unknown_interval`,
`filter_not_supported`. Ein Dashboard soll nach einem Tippfehler nichts
anzeigen, statt plausible falsche Zahlen zu zeigen.

`metric` und `interval` werden nie in SQL eingesetzt, sondern als Schlüssel in
feste Definitionen nachgeschlagen; Zeitgrenzen und Filterwert gehen als
gebundene Parameter in Prepared Statements.

### Grafana einrichten (Infinity-Datenquelle)

1. Datenquelle *Infinity* anlegen, Authentifizierung → *Bearer Token*, Wert:
   der unter `/api-keys` erzeugte Schlüssel (Scope auf `stats.view` begrenzen).
2. Query-Typ *JSON*, URL z. B.
   `https://<host>/api/stats?metric=horses.created&interval=week`.
3. *Rows/Root selector*: `data`, Spalten `bucket` (Format `Time`, `YYYY-MM-DD`)
   und `value` (`Number`).
4. Zeitraum aus dem Dashboard durchreichen: `from`/`to` als
   `${__from:date:YYYY-MM-DD}` bzw. `${__to:date:YYYY-MM-DD}`.

### Warum kein Prometheus-`/metrics` und keine Historien-Tabelle

Ein Prometheus-Exporter brächte ein weiteres Composer-Paket und ein eigenes
Auth-Modell neben dem vorhandenen Bearer-Mechanismus; der JSON-Weg fügt sich
in beides ein. Und die Zeitstempel für die interessanten Verläufe stehen
längst in den Fachtabellen — eine zweite, redundante Ablage müsste befüllt,
überwacht und aufgeräumt werden und wäre nach einem Rollback still
unvollständig. Reicht die Tagesauflösung nicht mehr, ist das der Moment für
einen Sammler; vorher nicht.
