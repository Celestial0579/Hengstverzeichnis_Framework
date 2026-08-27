# Umstellung auf die Kontaktliste (#336) — verbindliche Festlegungen

Diese Datei hält fest, wie der Umbau von `persons` + `breeding_stations` auf
`contacts` im Code aussieht. Sie entstand, weil der Umbau 76 Dateien berührt
und mehrere Leute (bzw. mehrere Arbeitsstränge) gleichzeitig daran arbeiten —
ohne einen festen Satz Namen driftet das auseinander.

## Datenmodell

| vorher | nachher |
|---|---|
| `persons` | `contacts` |
| `breeding_stations` | `contacts` |
| `horse_persons.person_id` | `horse_persons.contact_id` |
| `horse_persons.breeding_station_id` | `horse_persons.station_contact_id` |
| `horses.breeding_station_id` | unverändert — zeigt jetzt auf `contacts` |
| `horses.breeding_station` | unverändert (Freitext-Spiegel) |
| — | `contact_id_map(old_type, old_id, contact_id)` — bleibt dauerhaft |

**Zwei Steckplätze, nicht einer.** Eine Zuordnungszeile sagt zwei Dinge
gleichzeitig: wer (in der Rolle aus `role`) und wo (an welcher Deckstation).
`role` kennt nur `breeder|owner|keeper`. Sie zusammenzulegen — wie #336 es
skizziert — würde die Station ersatzlos wegwerfen.

## Datenschutz-Grenze (die eigentliche Gefahr dieses Umbaus)

Bis v0.7 schützte die Trennung selbst: `PublicController::personDetail()`
wählte eine **Positivliste** von Spalten, `stationDetail()` ein `SELECT *`.
Fällt die Trennung, ist der Schutz nur noch ein Feld.

Deshalb gilt ab v0.8 für **alle** Kontakte die strengere Regel:

* Öffentlich immer: `id`, `name`, `city`, `state`, `country`, `website`,
  `is_breeder`, `contact_public` (`membership_status` stand bis v0.8 mit in
  dieser Zeile und ist mit #349 herausgefallen)
* Nur bei `contact_public = 1`: `email`, `phone`, `mobile`, `street`,
  `house_number`, `postal_code`, `address`, `contact_person`
* Nie öffentlich: `contact_info`
* **Kein `SELECT *` auf `contacts` in einem öffentlichen Pfad.** Was gar nicht
  erst ankommt, kann der nächste nicht versehentlich ausgeben — das ist die
  Lehre aus #293, und sie darf beim Zusammenlegen nicht verlorengehen.

## Namen

| Sache | Name |
|---|---|
| Controller | `App\Controllers\ContactController` (ersetzt `PersonController` + `BreedingStationController`) |
| Rechte-Modul | `contacts` (Label „Kontakte") |
| Admin-Views | `admin_contacts.php`, `admin_contact_form.php`, `admin_contact_merge.php` |
| Öffentliche View | `public_contact_detail.php` |
| Öffentliche Route | `/kontakt?id=` |
| Admin-Routen | `/admin/contacts`, `/create`, `/store`, `/edit`, `/update`, `/delete`, `/merge` (GET+POST), `/publish` |

## Alte Adressen

`/person?id=` und `/station?id=` bleiben als **dauerhafte Weiterleitung**
(HTTP 301) auf `/kontakt?id=<neu>`, aufgelöst über `contact_id_map`. Die
Adressen stehen in Suchmaschinen. Findet sich keine Abbildung, wird 404
geliefert — nicht auf den Katalog umgeleitet, sonst sieht eine tote Kennung
aus wie ein Treffer.

Die alten Admin-Routen (`/admin/persons`, `/admin/breeding-stations`) leiten
ebenfalls dauerhaft um.

## Hooks

Neu: `contact.detail_sections`, `contact.edit_sections`, `contact.after_save`,
`contact.deleted`.

Die alten Namen `person.*` und `station.*` wurden in der 0.8-Linie
**zusätzlich** ausgelöst, mit denselben Argumenten — ein Addon, das sie
registriert hatte, lief unverändert weiter. **Mit v0.9.0 sind sie entfallen**,
wie hier und in `docs/plugin-development.md` angekündigt. Dasselbe gilt für die
alten POST-Feldnamen `person_id` und `breeding_station_id` am Pferdeformular:
Sie werden nicht mehr angenommen, die Felder heissen `contact_id` und
`station_contact_id`.

## Was NICHT umbenannt wird

* `horse_persons` (Tabellenname) — der Umbau ist groß genug
* `horses.breeding_station_id` / `horses.breeding_station`
* Die Übersetzungsschlüssel `person.*` und `station.*`, soweit sie
  Feldbeschriftungen sind
