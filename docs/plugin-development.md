# Plugin-Entwicklung

Diese Seite beschreibt das Plugin-/Erweiterungssystem aus Entwicklersicht:
Manifest-Format, verfügbare Hooks, Routen-Konvention und die tatsächlichen
Sicherheitsgrenzen. Für die Umsetzungsplanung/Architekturentscheidungen
siehe [plugin-system-plan.md](plugin-system-plan.md); für die
allgemeine Kern-Architektur siehe [architecture.md](architecture.md).

Plugins leben **nicht** im Kern-Repository (`plugins/` ist absichtlich in
`.gitignore` eingetragen, nur `plugins/.gitkeep` wird versioniert) — sie
werden separat gepflegt und lokal in `plugins/<slug>/` abgelegt.

**Installation über den Addon-Store:** Statt der unten beschriebenen
manuellen `cp -r`-Installation kann ein Admin Plugins auch direkt über
**Admin → Plugins verwalten → 🛒 Addon-Store durchsuchen**
(`/admin/plugins/store`) aus dem offiziellen
[Hengstverzeichnis_Addons](https://github.com/Celestial0579/Hengstverzeichnis_Addons)-Repo
oder einem beliebigen selbst hinzugefügten GitHub-Repo installieren - siehe
[plugin-system-plan.md](plugin-system-plan.md), Abschnitt 2.7. Auch dabei
gilt unverändert: Installieren legt den Code nur unter `plugins/<slug>/` ab,
aktiviert ihn aber nicht - die Aktivierung bleibt der bewusste, separate
Schritt unten auf dieser Seite.

## Schnellstart: Referenz-Plugin ausprobieren

```bash
cp -r docs/examples/demo-plugin plugins/demo-plugin
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren.
Das Referenz-Plugin (`docs/examples/demo-plugin/Plugin.php`) demonstriert
die Hooks `admin.dashboard_tiles`, `horse.detail_sections`,
`horse.edit_sections` und `horse.after_save`, das Registrieren einer eigenen
Route sowie `permissions()` und `features()` — nicht jedoch
`horse.before_save` und `catalog.card_sections` (deren Signaturen stehen in
der Tabelle unten).

## Verzeichnisstruktur eines Plugins

```
plugins/<slug>/
  plugin.json      Manifest (Pflicht)
  Plugin.php         Einstiegspunkt (Pflicht, Dateiname konfigurierbar über "entry")
  ...                 Beliebige weitere Dateien, vom Plugin selbst verwaltet
```

`<slug>` ist gleichzeitig der Verzeichnisname, das `slug`-Feld im Manifest
und der Bezeichner in der Datenbank-Tabelle `plugins` (Aktivierungsstatus).
Erlaubt sind Kleinbuchstaben, Ziffern und Bindestriche (`^[a-z0-9][a-z0-9-]*$`).

## Manifest (`plugin.json`)

```json
{
    "slug": "demo-plugin",
    "name": "Demo-Plugin",
    "version": "1.0.0",
    "core_compatibility": ">=0.1.0-beta.1",
    "core_supported_max": "0.5",
    "description": "Kurzbeschreibung, wird im Admin-Bereich angezeigt.",
    "author": "...",
    "hooks": ["horse.after_save", "horse.detail_sections", "admin.dashboard_tiles"],
    "entry": "Plugin.php"
}
```

| Feld | Pflicht | Beschreibung |
|---|:---:|---|
| `slug` | ✅ | Muss exakt dem Verzeichnisnamen entsprechen. |
| `name` | ✅ | Anzeigename im Admin-Bereich. |
| `version` | ✅ | Frei wählbar (z. B. SemVer). Bei jedem Update **muss** sie sich ändern - siehe Abschnitt "Update-Erkennung" unten, sonst wird ein reguläres Update fälschlich als verdächtige Änderung erkannt. |
| `core_compatibility` | ✅ | Vergleichsausdruck gegen `CORE_VERSION` (siehe unten). |
| `core_supported_max` | ✅ | Höchste unterstützte Kern-Linie als `"Major.Minor"` (z. B. `"0.4"`). **Pflicht** seit dem Addon-Autoupdate (#197): Manifeste ohne (gültige) Angabe werden abgewiesen - Installation und Laden verweigert, fail-closed. Läuft ein neuerer Kern als angegeben, gilt das Plugin als inkompatibel (wird nicht geladen); die Update-Seite prüft die Angabe zusätzlich gegen die **Ziel**version eines anstehenden Kern-Updates und warnt vor dem Einspielen. |
| `description` | – | Anzeigetext im Admin-Bereich. |
| `author` | – | Anzeigetext im Admin-Bereich. |
| `hooks` | – | Rein deklarativ/informativ - zeigt Admins vor der Aktivierung, was das Plugin laut Selbstauskunft tut. Wird **nicht** technisch erzwungen. |
| `permissions` | – | Ebenfalls rein deklarativ/informativ (Selbstauskunft wie `hooks`). Tatsächlich registriert werden Berechtigungen ausschließlich über die `permissions()`-Methode der Plugin-Klasse, siehe Abschnitt „Berechtigungen". |
| `entry` | – | PHP-Datei mit der Plugin-Klasse, relativ zum Plugin-Verzeichnis. Default: `Plugin.php`. |

### Kompatibilitätsprüfung (`core_compatibility`)

Format: optionaler Operator (`>=`, `<=`, `>`, `<`, `=`) gefolgt von einer
Versionsnummer, ausgewertet mit PHP `version_compare()` gegen die im Kern
definierte Konstante `CORE_VERSION` (aktuell `0.2.0`, siehe
`config/config.php`). Ohne Operator wird exakte Übereinstimmung geprüft.

**Bewusst genau EIN Operator + eine Version** - Bereichs-Syntax wie
`">=0.3.0, <0.4.0"` ist ungültig und würde fail-closed als inkompatibel
gewertet. Die Obergrenze ist deshalb das eigene Feld `core_supported_max`
(siehe Tabelle oben): abwärtskompatibel, ältere Kern-Versionen ignorieren
es einfach.

Ein Plugin, dessen Manifest fehlerhaft ist oder dessen
`core_compatibility`-/`core_supported_max`-Angaben nicht zur laufenden
Kern-Version passen, wird beim Scan übersprungen (in `/admin/plugins`
sichtbar als "Ungültiges Manifest"/"Inkompatibel", seit #197 mit
Begründung) und lässt sich nicht aktivieren. Die Update-Seite
(`/admin/updates`) zeigt zusätzlich je Addon, ob es zur Zielversion eines
anstehenden Kern-Updates passt.

**Hinweis zur Beta-Phase:** `version_compare()` behandelt Suffixe wie
`-beta.1` als Vorabversion (niedriger als die reine Versionsnummer ohne
Suffix). `>=0.1.0` wäre gegen `0.1.0-beta.1` daher `false`. Solange der Kern
sich im Beta-Stadium befindet, den Suffix in `core_compatibility` mit
angeben (z. B. `>=0.1.0-beta.1`).

### Update-Erkennung: eindeutige Kennung pro Plugin-Version

Ein Plugin wird nicht allein über seinen Verzeichnisnamen (Slug) identifiziert
- bei Aktivierung speichert `App\Plugin\PluginManager` zusätzlich die
Manifest-`version` und einen SHA-256-Fingerabdruck über den **gesamten**
Plugin-Ordner (`installed_version`/`content_hash` in der Tabelle `plugins`).
Bei jedem folgenden Request wird das verglichen:

- **Neue Versionsnummer im Manifest** → wird **nur dann** automatisch als
  reguläres Update akzeptiert (Freigabe wandert auf die neue Version/den
  neuen Fingerabdruck), wenn die gespeicherte Herkunft des Plugins
  (`plugins.source`) auf einen **unveränderlichen Release-Tag** zeigt -
  Muster `owner/repo@vX.Y.z` (Framework-Issue #212). Das ist der Normalfall
  für Plugins, die über den Addon-Store bzw. das Addon-Update aus einem
  Release eingespielt wurden; deren Updates verlieren dadurch **nie** ihre
  Aktivierung. Für **manuell kopierte** Plugins (`source` leer) und Stände
  aus einem **Branch** (`owner/repo@main` oder ohne Ref) gilt ein
  Versionswechsel dagegen fail-closed als freigabepflichtig: Das Plugin wird
  nicht geladen, bis ein Admin die neue Version unter `/admin/plugins`
  bestätigt - sonst wäre das bloße Erhöhen der Manifest-Version ein
  trivialer Umweg um die gesamte Fingerabdruck-Kette.
- **Gleiche Versionsnummer, aber abweichender Code** → wird als verdächtig
  behandelt (Code wurde unter demselben Slug ausgetauscht, ohne ein
  reguläres Update mit neuer Versionsnummer zu sein). Das Plugin wird für
  diesen Request **nicht geladen**, bis ein Admin es unter `/admin/plugins`
  über den Button "Mit bisherigem Status erneut freigeben" bestätigt.

**Deshalb: Bei jeder inhaltlichen Änderung am Plugin-Code die `version` im
Manifest erhöhen** - sonst zeigt `/admin/plugins` nach dem nächsten Request
fälschlich "Code geändert - erneute Freigabe nötig" an, obwohl es sich um
ein gewolltes Update handelt. Und: Plugins über Releases (Tags `vX.Y.z`)
ausliefern, nicht über Branch-Stände - nur dann laufen Updates ohne erneuten
Freigabe-Klick durch.

**Performance-Detail** (Framework-Issue #224, für Plugin-Entwickler ohne
Handlungsbedarf): Der SHA-256 über alle Dateien wird nicht mehr bei jedem
Request berechnet. Beim Freigeben wird zusätzlich ein billiger
Verzeichnis-Stempel (`plugins.dir_stamp`: höchste `filemtime`, Dateianzahl,
Gesamtgröße) gespeichert; stimmt er beim Bootstrap überein, entfällt das
Hashen komplett. Jede Abweichung führt weiterhin zum vollen
Fingerabdruck-Vergleich - an den Freigabe-Regeln oben ändert das nichts.

**Nicht-destruktive Garantie:** Die Erkennung einer verdächtigen Änderung
verändert oder löscht nie die bestehende `plugins`-Zeile, zugewiesene
Berechtigungen (`group_permissions`) oder sonstige Konfiguration - sie
markiert das Plugin nur für den aktuellen Request als "nicht laden". Ein
Bug in der Fingerabdruck-Berechnung kann daher höchstens fälschlich diese
Markierung auslösen, nie Daten zerstören; die Wiederherstellung ist immer
ein einzelner Klick.

## Plugin-Klasse (`Plugin.php`)

Konvention: Die Datei definiert eine Klasse `Plugin` im Namespace
`Plugin\<StudlySlug>` — der Slug wird dafür in StudlyCase umgewandelt
(`demo-plugin` → `DemoPlugin`, `mein_plugin` → `MeinPlugin`).

```php
<?php
namespace Plugin\DemoPlugin;

use App\Plugin\HookManager;

class Plugin {
    public function register(HookManager $hooks): void {
        // Hooks hier registrieren (siehe unten)
    }

    // Optional: eigene Routen (siehe Abschnitt "Routen")
    public function routes(): array {
        return [];
    }
}
```

Beide Methoden sind optional — ein Plugin, das nur `routes()` ohne
`register()` implementiert (oder umgekehrt), ist gültig.

`App\Plugin\PluginManager` lädt die Datei einmalig **pro Request** über
`require_once` und instanziiert die Klasse ohne Konstruktor-Argumente.
Ein Fehler beim Laden (fehlende Datei, fehlende Klasse, Exception in
`register()`/`routes()`) wird abgefangen, im Audit-Log protokolliert
(Kategorie `plugin`) und verhindert **nicht** den Bootstrap der übrigen
Anwendung — betrifft aber ausschließlich dieses eine Plugin für den
gesamten Request (kein partieller Erfolg innerhalb eines Plugins).

## Installation & Migrationen: `install()`

Braucht ein Plugin einmalige Einrichtungsarbeiten — typischerweise das
Anlegen eigener Tabellen — implementiert es dafür eine öffentliche
`install()`-Methode (Addons-Issue #75):

```php
public function install(): void {
    \App\Database::getInstance()->exec("CREATE TABLE IF NOT EXISTS `plugin_demo_notes` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `note` TEXT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
```

Der Kern ruft `install()` auf:

- bei jeder **(Re-)Aktivierung** über `/admin/plugins`
  (`PluginManager::setEnabled(..., true)`), und
- nach jedem eingespielten **Addon-Update**
  (`AddonUpdateService` → `PluginManager::runInstallHook()`).

Daraus folgt der Vertrag:

- **`install()` muss idempotent sein.** Der Hook garantiert "mindestens
  einmal nach Installation/Update", nicht "genau einmal" —
  `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN` hinter einer
  `SHOW COLUMNS`-Prüfung usw. sind das richtige Muster (analog zu
  `Database::runMigrations()` im Kern).
- **Fehler brechen die Admin-Aktion nicht ab.** Eine Exception in
  `install()` wird abgefangen und im Audit-Log (Kategorie `plugin`)
  protokolliert; Aktivierung bzw. Update bleiben bestehen.
- **`register()` führt kein DDL mehr aus.** `register()` läuft im Bootstrap
  **jedes** Requests — ein `CREATE TABLE IF NOT EXISTS` dort ist ein echtes
  DDL-Statement gegen den Datenbank-Server bei jeder Anfrage, inklusive
  kurzem Schema-Lock (Addons-Issue #75 hat das quantifiziert: sechs Plugins
  × jeder Katalog-AJAX-Request). Schema-Arbeit gehört in `install()`.
- **Bestands-Plugins** von vor diesem Hook dürfen übergangsweise ihren
  marker-geführten Fallback behalten (Marker-Datei im Plugin-Verzeichnis,
  `is_file()`-Prüfung vor dem DDL in `register()`) — er kostet nur noch
  einen `stat()`-Aufruf pro Request. Neue Plugins implementieren direkt
  `install()`.

Wie alle Plugin-Methoden ist `install()` optional — ein Plugin ohne eigene
Datenhaltung braucht sie nicht.

## Verfügbare Hooks (Phase 1)

Registrierung über `$hooks->addAction(...)` (reagieren, kein Rückgabewert)
oder `$hooks->addFilter(...)` (Wert entgegennehmen, verändert zurückgeben).
Jeder einzelne Hook-Aufruf läuft in eigener try/catch-Isolation (siehe
`App\Plugin\HookManager`) — eine Exception in einem Callback wird geloggt
und bricht nur diesen einen Aufruf ab, nie den restlichen Request.

| Hook | Typ | Wann | Signatur |
|---|---|---|---|
| `horse.before_save` | Action | Direkt vor `INSERT`/`UPDATE` in `HorseController::store()`/`update()` | `function(?int $horseId, array $postData): void` — `$horseId` ist `null` beim Anlegen |
| `horse.after_save` | Action | Direkt nach dem erfolgreichen Speichern (inkl. Personen-/Match-Verknüpfung) | `function(int $horseId, array $postData, bool $isNew): void` |
| `horse.before_delete` | Action | Direkt vor dem Verschieben in den Papierkorb (`$permanent = false`) **und** direkt vor dem endgültigen Löschen (`$permanent = true`, auch beim Papierkorb-Leeren je Pferd) | `function(int $horseId, array $horse, bool $permanent): void` — `$horse` ist der komplette Datensatz; bei `$permanent = true` die letzte Gelegenheit, ihn zu lesen. Kann das Löschen nicht blockieren (HookManager-Isolation, wie `horse.before_save`) |
| `horse.trashed` | Action | Nach dem Verschieben in den Papierkorb (Soft-Delete, `deleted_at` gesetzt) | `function(int $horseId, array $horse): void` — `$horse` ist der Stand VOR dem Soft-Delete. Hier abhängige Plugin-Daten deaktivieren (z. B. Inserate), der FK-`ON DELETE CASCADE` greift beim Soft-Delete nicht |
| `horse.restored` | Action | Nach der Wiederherstellung aus dem Papierkorb | `function(int $horseId, array $horse): void` — `$horse` ist der aktuelle Stand (mit `deleted_at = NULL`) |
| `horse.deleted` | Action | Nach dem endgültigen Löschen (einzeln wie beim Papierkorb-Leeren, je Pferd) | `function(int $horseId, array $horse): void` — `$horse` ist der letzte Stand vor dem `DELETE`; abhängige Zeilen mit FK-`ON DELETE CASCADE` sind zu diesem Zeitpunkt bereits weg |
| `horse.detail_sections` | Filter | Beim Rendern der öffentlichen Pferde-Detailseite | `function(array $sections, array $horse, array $horsePersons, ?array $pedigree): array` — jedes Element ist ein fertiger HTML-String, wird **unescaped** ausgegeben. `$pedigree` ist der bereits berechnete 6-Generationen-Baum (siehe `App\Service\PedigreeBuilder` unten), `null` falls das Pferd nicht gefunden wurde. Zum Inhalt von `$horse`/`$horsePersons` siehe [Was in `$horse` und `$horsePersons` steht](#was-in-horse-und-horsepersons-steht--und-wann-felder-null-sind) |
| `catalog.card_sections` | Filter | Beim Rendern jeder einzelnen Karte im öffentlichen Katalog (`src/Views/public_catalog_cards.php`) — sowohl im normalen als auch im AJAX-Filterpfad, da beide dieselbe View nutzen | `function(array $sections, array $horse): array` — jedes Element ist ein fertiger HTML-String, wird **unescaped** ausgegeben, direkt vor dem "Profil ansehen"-Button eingefügt. Läuft für jede sichtbare Karte einzeln, siehe Performance-Hinweis unten. `$horse` unterliegt denselben Sichtbarkeitsfiltern, siehe [Was in `$horse` und `$horsePersons` steht](#was-in-horse-und-horsepersons-steht--und-wann-felder-null-sind) |
| `horse.edit_sections` | Filter | Beim Rendern des Admin-Bearbeitungsformulars eines Hengstes (`HorseController::edit()`) | `function(array $sections, array $horse): array` — jedes Element ist ein fertiger HTML-String, wird **unescaped** ausgegeben. Feuert **nur beim Bearbeiten**, nicht beim Anlegen. `$horse` ist hier der **rohe** Datensatz, siehe Warnkasten unten |
| `admin.dashboard_tiles` | Filter | Beim Rendern des Admin-Dashboards | `function(array $tiles): array` — jedes Element: `['url' => string, 'label' => string, 'icon' => string]` |

**Performance-Hinweis zu `catalog.card_sections`:** Der Filter läuft einmal
pro gerenderter Karte der aktuellen Katalogseite — der Katalog paginiert
serverseitig (24 Karten je Seite, #125), pro Request sind es also höchstens
24 Aufrufe. Teure Callbacks (z. B. eigene DB-Abfragen je Pferd) summieren
sich trotzdem; Daten für alle Pferde einer Seite besser vorab in einer
einzigen Abfrage laden statt im Callback selbst zu queryen.

**Achtung, `$horse` ist hier schmaler als bei `horse.detail_sections`:**
Die Katalog-Query liefert eine feste Spaltenteilmenge (`id`, `name`, `ueln`,
`foreign_ueln`, `birth_year`, `birth_date`, `color`, `status`, `is_deceased`,
`death_year`, `image_url`,
`breeding_station`, `station_name`, verknüpfte/unverknüpfte Elternnamen,
`breeder_name`, `owner_name`). Insbesondere fehlen `description`,
`sire_id`/`dam_id` und sämtliche Stations-Kontaktfelder
(`station_contact`/`address`/`phone`/`email`/`website`). Der Abschnitt
„Was in `$horse` … steht" unten beschreibt den vollen Satz der
**Detailseite**; für Katalogkarten gilt nur diese Teilmenge.

**Achtung, `horse.edit_sections` funktioniert anders als alle anderen
Abschnitts-Hooks — in drei Punkten:**

1. **`$horse` ist der rohe Datensatz.** Er kommt aus
   `SELECT * FROM horses WHERE id = ?` — es sind **keine** Sichtbarkeitsfilter
   angewandt, es gibt **keine** `station_*`-Felder (die Abfrage hat keinen Join),
   und `deleted_at` wird nicht gefiltert: Der Hook feuert auch für ein Pferd im
   Papierkorb, das über `/admin/horses/edit?id=…` weiterhin erreichbar ist.
   Der Abschnitt „Was in `$horse` … steht" unten beschreibt die **öffentliche**
   Seite und gilt hier ausdrücklich **nicht**.
2. **Der Abschnitt steht außerhalb des Kern-Formulars.** Ein Plugin bringt
   deshalb sein **eigenes** `<form>` mit eigener POST-Route und eigener
   `requirePermission()`-Prüfung mit. Der Speichern-Knopf des Kerns speichert
   Plugin-Felder **nicht** mit. Das ist Absicht: Verschachtelte `<form>` sind
   ungültiges HTML, und ein Speichern über den Kern-POST liefe nur gegen
   `horses.edit` — nie gegen die Berechtigung des Plugins.
3. **Auf einer Seite gibt es dadurch zwei Speichern-Knöpfe.** Wer oben die
   Stammdaten ändert und dann unten den Knopf des Plugins drückt, verliert die
   Stammdaten-Änderung. Beschrifte den eigenen Knopf deshalb nicht mit
   „Speichern", sondern mit der konkreten Handlung („Auszeichnung hinzufügen"),
   und weise im Abschnitt darauf hin, dass Änderungen oben zuerst zu speichern
   sind.

Da der Abschnitt hinter Login und `horses.edit` steht, wiegt die
Escaping-Verantwortung hier **schwerer** als auf der öffentlichen Seite, nicht
leichter: Ein XSS trifft dort Redakteure und Administratoren mit vollen Rechten.

**Wichtig zu `horse.before_save`:** Da ein fehlgeschlagener Hook-Aufruf den
Kern-Workflow nicht blockieren darf (siehe Sicherheitsmodell unten), kann
ein Plugin über diesen Hook **kein** Speichern verhindern — er eignet sich
für Nebenwirkungen (z. B. Logging, externe Benachrichtigung vorbereiten),
nicht für blockierende Validierung. Blockierende serverseitige Validierung
bleibt bewusst Aufgabe des Kerns bzw. eines eigenen, expliziten
Erweiterungspunkts (bisher nicht Teil von Phase 1).

Weitere Hooks werden nach Bedarf ergänzt (siehe
[plugin-system-plan.md](plugin-system-plan.md), Phase 2) — Vorschläge gerne
als Issue.

### Was in `$horse` und `$horsePersons` steht — und wann Felder `null` sind

`horse.detail_sections` und `catalog.card_sections` erhalten **öffentlich
gefilterte** Daten, keine Roh-Datensätze: Die Sichtbarkeitsregeln aus #121/#122
sind bereits angewandt, bevor der Filter läuft. Ein Plugin darf sich deshalb nie
darauf verlassen, dass ein Feld gesetzt ist, nur weil der Datensatz im
Admin-Bereich gepflegt ist. Ein fehlendes Feld ist kein Fehler, sondern die
Zusicherung, dass diese Angabe öffentlich nicht gezeigt werden darf — ein Plugin
darf sie dann auch nicht per eigener Abfrage nachladen und ausgeben.

**`$horse`** enthält alle Spalten von `horses` (siehe `database/schema.sql`) plus
die Deckstationsfelder `station_name`, `station_contact`, `station_address`,
`station_phone`, `station_email`, `station_website` sowie seit #256 die
strukturierte Anschrift `station_street`, `station_house_number`,
`station_postal_code`, `station_city`, `station_state`, `station_country`.
`station_address` bleibt das alte Freitextfeld und wird **nicht** automatisch
zerlegt — bei Bestandsstationen steht die Anschrift weiterhin dort und die
Einzelfelder sind leer. Wer die Adresse ausgibt, nimmt deshalb die Einzelfelder
und fällt auf `station_address` zurück, wenn sie leer sind (so macht es auch
`src/Views/public_station_detail.php`). Seit dem Status-Split
(#188) enthält `$horse['status']` nur noch den Zuchtstatus
(`active`/`inactive`) und nie mehr `deceased` — der Lebensstatus steht in
`$horse['is_deceased']`/`$horse['death_year']`. Alle sechs `station_*`-Felder
sind **gemeinsam** `null`, wenn

- die verknüpfte Deckstation unveröffentlicht ist (`is_published = 0` — neu
  angelegte Stationen sind das per Default),
- die Deckstation im Papierkorb liegt (`deleted_at IS NOT NULL`),
- oder die Gast-Gruppe die Leseberechtigung `breeding_stations.view` nicht
  besitzt (dann fehlen sie auch bei veröffentlichter Station).

`$horse['breeding_station_id']` bleibt in allen drei Fällen gesetzt und ist
deshalb **kein** Indikator dafür, dass Stationsdaten vorliegen.
`$horse['breeding_station']` ist doppelt belegt: bei gesetzter
`breeding_station_id` ist es die denormalisierte Kopie des Stationsnamens und
wird in den drei Fällen oben ebenfalls auf `null` gesetzt; ohne
`breeding_station_id` ist es freier Text (z. B. aus dem CSV-Import) und bleibt
immer erhalten.

**`$horsePersons`** enthält die Zeilen aus `horse_persons` (`role`, `from_year`,
`until_year`, `breeding_station_id`, `breeding_station_text`) plus `person_name`,
`contact_info`, `city`, `state`, `country`, `membership_status`, `station_name`,
`station_id`. Von den strukturierten Personenfeldern (#188, `state` seit #256)
sind das **bewusst die einzigen vier** im Payload: `email`, `street`,
`house_number` und `postal_code` werden nie mitgeliefert — sie sind Admin-only,
und ein Plugin darf sie auch nicht per eigener Abfrage öffentlich machen.

Die Trennlinie ist dabei nicht die Feldanzahl, sondern die Art der Angabe: Was
eine Sendung zustellbar macht, bleibt intern; die grobe geografische Verortung
ist öffentlich. Deshalb gehört `state` (Bundesland/Kanton) auf die öffentliche
Seite — es ist gröber als der ohnehin sichtbare Ort. Bei Deckstationen gilt das
nicht: Deren Anschrift ist vollständig öffentlich, weil eine Deckstation eine
Geschäftsadresse ist und keine Privatperson.

Dabei gilt:

- `person_name`/`contact_info`/`city`/`state`/`country`/`membership_status` sind `null`,
  wenn die Person unveröffentlicht oder gelöscht ist (#121);
- `station_name`/`station_id` sind `null`, wenn die Station unveröffentlicht oder
  gelöscht ist (#122);
- Zeilen, bei denen danach weder `person_name` noch `station_name` noch der
  Freitext `breeding_station_text` übrig bleibt, sind gar nicht erst enthalten.
  `$horsePersons` kann also leer sein, obwohl im Admin-Bereich Personen zugeordnet
  sind — und die Indizes sind neu durchnummeriert, entsprechen also **nicht** den
  `horse_persons.id`-Werten.

**`$pedigree`** enthält nur veröffentlichte Vorfahren; unveröffentlichte
erscheinen als namenlose Platzhalterknoten (siehe `App\Service\PedigreeBuilder`).

**Muster:** immer das konkret benötigte Feld prüfen, nie die Verknüpfung.

```php
public function addDetailSection(array $sections, array $horse, array $horsePersons, ?array $pedigree): array {
    // richtig: prüft das Feld, das tatsächlich gebraucht wird
    if (empty($horse['station_email'])) {
        return $sections; // Station unveröffentlicht, gelöscht oder für Gäste gesperrt
    }

    // falsch: breeding_station_id ist auch dann gesetzt, wenn die Station
    // öffentlich gar nicht sichtbar ist
    // if (empty($horse['breeding_station_id'])) { ... }

    return $sections;
}
```

Diese Zusicherungen sind Teil des Hook-Vertrags und in
`tests/Functional/HorseDetailSectionsHookTest.php` festgenagelt; sie ändern sich
nicht ohne Eintrag in [CHANGELOG.md](../CHANGELOG.md).

## Wiederverwendbarer Dienst: `App\Service\PedigreeBuilder`

Die Pedigree-Baum-Logik hinter `horse.detail_sections`' viertem Parameter
steht Plugins auch unabhängig vom Hook zur Verfügung — z. B. für einen
Inzuchtkoeffizienten-Rechner, der eine größere Generationstiefe braucht als
die öffentliche Detailseite serverseitig lädt (dort fest 6, per UI bis zu
dieser Tiefe umschaltbar), oder für einen Pedigree-Export mit eigener
Aufbereitung:

```php
$tree = \App\Service\PedigreeBuilder::build($horseId, $maxDepth, $publishedOnly);
```

- `$horseId`: ID des Wurzel-Pferdes (`?int`, `null`/`0` liefert `null` zurück).
- `$maxDepth`: gewünschte Generationstiefe (Standard `4`, kann von Plugins
  frei gewählt werden — unabhängig von der öffentlichen Seite).
- `$publishedOnly` (Standard `false`): **zwingend `true` für jede öffentliche
  Ausgabe oder darauf aufbauende Berechnung.** Nur dann erscheinen
  unveröffentlichte Vorfahren als anonyme Platzhalter statt mit Namen — der
  Default `false` liefert den ungefilterten Baum und ist ausschließlich für
  berechtigungsgeschützte Backoffice-Funktionen gedacht. Ein Plugin, das den
  Parameter auf einer öffentlichen Route weglässt, leakt unveröffentlichte
  Pferde.
- Rückgabe: verschachteltes Array pro Knoten mit `id`, `name`, `ueln`,
  `birth_year`, `color`, `depth`, `is_placeholder`, `sire`, `dam` (jeweils
  wieder derselbe Knoten-Typ oder `null`), oder `null` insgesamt, wenn kein
  passendes Pferd existiert.
- Auflösung von Sire/Dam: primär über `sire_id`/`dam_id`, sonst Fallback auf
  eine Suche nach `sire_ueln`/`sire_name` bzw. `dam_ueln`/`dam_name` gegen
  UELN/Fremd-UELN/Name anderer Pferde-Datensätze. Bleibt der Fallback
  erfolglos, wird ein synthetischer Platzhalter-Blattknoten erzeugt
  (`id => null`, `is_placeholder => true`). Platzhalter jenseits von
  `$maxDepth` werden nicht mehr erzeugt — die frühere
  `depth = $maxDepth + 1`-Besonderheit ist bewusst entfallen (siehe
  Klassen-Docblock von `PedigreeBuilder`).
- Führt bei jedem Aufruf eigene DB-Abfragen aus (kein Caching) — bei
  wiederholtem Zugriff auf denselben Baum im selben Request ggf. selbst
  zwischenspeichern.

## Routen

Ein Plugin kann über eine optionale `routes()`-Methode zusätzliche
HTTP-Routen registrieren:

```php
public function routes(): array {
    return [
        [
            'method' => 'GET',           // 'GET' oder 'POST'
            'path' => '/hello',           // relativ zum Plugin
            'callback' => [self::class, 'helloPage'],
        ],
    ];
}

public function helloPage(): void {
    \App\Plugin\PluginPage::render('Mein Addon', '<div class="card"><h1>Hallo!</h1></div>');
}
```

**Der Pfad wird zwingend unter `/plugin/<slug>/...` registriert** — der
Präfix wird von `App\Plugin\PluginManager` selbst vorangestellt, unabhängig
davon, welchen Pfad das Plugin angibt. Ein Plugin kann dadurch **nie** eine
Kern-Route überschreiben oder sich als Kernfunktionalität ausgeben. Aus dem
Beispiel oben wird also `/plugin/<slug>/hello`.

Callbacks folgen derselben Konvention wie Kern-Routen: `[KlassenName::class,
'methode']` (Klassen-**Name als String**, keine Objekt-Instanz). Der
zentrale `Router` instanziiert pro Request eine frische Instanz dieser
Klasse und ruft die Methode auf — ein Callback der Form `[$this, 'methode']`
würde **nicht** die zur Registrierungszeit aktive Plugin-Instanz verwenden,
sondern eine neu erzeugte.

**Zugriffsschutz für eigene Routen ist Aufgabe des Plugins.** Route-Handler
laufen durch denselben `Router::dispatch()` wie Kern-Routen, aber **nicht**
automatisch durch `checkAuth()`/`requireAdmin()`. Soll eine Plugin-Route nur
für angemeldete Benutzer oder nur für Admins erreichbar sein, muss der
Handler das selbst prüfen — z. B. indem die Route-Klasse von
`App\Controllers\BaseController` erbt und im Konstruktor `checkAuth()`/
`requireAdmin()` aufruft, genau wie ein Kern-Controller:

```php
namespace Plugin\DemoPlugin;

use App\Controllers\BaseController;

class AdminPage extends BaseController {
    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requireAdmin();
    }

    public function show(): void {
        $this->render(/* ... */);
    }
}
```

## Theming & Darkmode für Plugin-Seiten (Addons#66)

**Plugin-Seiten rendern im zentralen Haupt-Layout** über
`App\Plugin\PluginPage::render(string $title, string $contentHtml)`. Der
Dienst lädt Einstellungen und Locale (Plugin-Routen laufen ohne
`BaseController`) und bindet `src/Views/layout.php` ein - die Seite bekommt
damit automatisch:

- Header, Navigation, Footer und den **Theme-Umschalter** (hell/dunkel),
- die **admin-konfigurierten Markenfarben** (`--primary-color`/
  `--secondary-color` samt abgeleiteter kontrastsicherer Varianten),
- Schriftart, zentrale CSS-Variablen und die gemeinsamen Klassen aus
  `public/css/style.css`.

Regeln für das Inhalts-HTML:

1. **Kein eigenständiges Dokument** ausgeben (kein `<!DOCTYPE>`, `<head>`,
   `<body>`) - nur das Inhaltsfragment; `$title` landet escaped im
   `<title>`, dynamische Werte im Fragment escaped das Plugin selbst mit
   `htmlspecialchars()`.
2. **Gemeinsame Klassen statt eigener Stile**: `.card` für Inhaltsblöcke,
   `.btn`/`.btn-secondary` für Schaltflächen, `.form-group`/`.form-control`
   für Formulare. Eigenes CSS nur für tatsächlich addon-spezifische
   Geometrie (Raster, Thumbnails o. Ä.).
3. **Farben ausschließlich über die Theme-Variablen** (`var(--text-color)`,
   `var(--card-bg)`, `var(--surface-muted)`, `var(--border-radius)`, ...,
   siehe `:root` in `public/css/style.css`) - nie rohe Hex-Werte, sonst
   bricht der Darkmode oder die Markenfarbe des Betreibers.
4. **Dokumentierte Ausnahmen**: Druckansichten dürfen bewusst hell und
   theme-unabhängig sein (`@media print`), Overlay-Scrims dürfen fest
   abdunkeln. Solche Stellen tragen einen Marker-Kommentar
   `/* theming-ausnahme: <grund> */`, damit sie von Prüfwerkzeugen nicht
   als Verstoß gewertet werden.

Das Referenz-Plugin (`docs/examples/demo-plugin/`) zeigt das Muster auf
allen drei Seiten. Die früheren eigenständigen HTML-Dokumente der
Beispielseiten waren die Vorlage der Theming-Drift in den offiziellen
Addons (Addons#66) - neue Plugins nicht mehr daran orientieren.

## Berechtigungen (#66)

Ein Plugin kann über eine optionale `permissions()`-Methode eigene Aktionen
im Gruppen-/Berechtigungssystem registrieren — entweder als **neue Aktion an
einem bestehenden Modul** (Kern oder ein anderes Plugin) oder als **komplett
neues, eigenes Modul**:

```php
public function permissions(): array {
    return [
        // Neue Aktion am bestehenden Kern-Modul "horses" - erscheint als
        // zusätzliche Checkbox "Exportieren" unter "Pferde" in der
        // Berechtigungsmatrix unter /admin/groups.
        ['module' => 'horses', 'action' => 'export', 'label' => 'Exportieren'],

        // Alternativ: komplett neues, eigenes Modul (module_label nötig,
        // da das Modul noch nicht existiert).
        ['module' => 'demo-plugin', 'action' => 'access', 'label' => 'Nutzen', 'module_label' => 'Demo-Plugin'],
    ];
}
```

Jeder Eintrag braucht `module`, `action` und `label` (Anzeigetext der
Aktion); `module_label` ist nur relevant, wenn `module` noch nicht existiert
- bei einem bereits vorhandenen Modul (Kern oder anderes Plugin) wird es
ignoriert.

**Standard-Aktionen `view` und `publish`:** Jedes Modul - Kern **wie Plugin** -
erhält automatisch die beiden Standard-Aktionen `view` (Lesen: darf den Bereich
sehen) und `publish` (Veröffentlichen), siehe
`App\Permission\PermissionRegistry::STANDARD_ACTIONS`. Ein Plugin muss diese
also **nicht** selbst registrieren; sie erscheinen für sein eigenes Modul
ebenso als Checkboxen unter `/admin/groups`. Möchte das Plugin eine eigene
Beschriftung, kann es `view`/`publish` mit eigenem `label` registrieren (greift
nur, wenn es das zuerst tut - "wer zuerst registriert, gewinnt", siehe unten).
Die **Durchsetzung** bleibt Aufgabe des Plugins: Öffentliche Ausgaben über die
Gast-Gruppe (`public`) mit `hasPermission('<modul>','view')` gaten, das
Veröffentlichen eigener Inhalte mit `hasPermission('<modul>','publish')`. Für
öffentliche Ableitungen/Berechnungen auf Basis von Pferdedaten immer den
`publishedOnly`-Modus nutzen (`PedigreeBuilder::build(..., true)`), damit keine
unveröffentlichten Daten durchsickern.

**Sicherheits-Leitplanke ("wer zuerst registriert, gewinnt"):** Existiert die
Kombination aus `module` und `action` bereits (egal ob aus dem Kern oder
einem zuvor geladenen Plugin), wird die neue Registrierung stillschweigend
ignoriert. Ein Plugin kann dadurch **nie** die Bedeutung einer bestehenden
Berechtigung umdefinieren (z. B. `horses`/`delete` anders belegen) - es kann
nur neue, bisher unbenutzte Kombinationen ergänzen. Siehe
`App\Permission\PermissionRegistry::registerAction()`.

**Die Registrierung selbst schaltet nichts frei** - sie sorgt nur dafür,
dass ein Admin die neue Aktion überhaupt in der Berechtigungsmatrix sehen
und einer Gruppe zuweisen kann (Standard: keiner Gruppe zugewiesen, also
fail-closed wie jede andere Berechtigung, siehe unten). Die eigentliche
Durchsetzung ist weiterhin Aufgabe des Plugins selbst - im eigenen Hook
oder der eigenen Route genau wie ein Kern-Controller `hasPermission()`/
`requirePermission()` aufrufen:

```php
class ExportController extends \App\Controllers\BaseController {
    public function __construct() {
        parent::__construct();
        $this->checkAuth();
        $this->requirePermission('horses', 'export');
    }

    public function export(): void {
        // ...
    }
}
```

Das Referenz-Plugin (`docs/examples/demo-plugin/Plugin.php`) demonstriert
das vollständig: registriert `horses.export`, und die Route
`/plugin/demo-plugin/export-preview` ist nur erreichbar, wenn die aktuelle
Gruppe diese Berechtigung besitzt.

## Zusatzfunktionen mit admin-konfigurierbarer Sichtbarkeit (#57)

Für Funktionen, die sich an Besucher bzw. Vereins-/Verbandsmitglieder richten
(z. B. der Verpaarungsrechner aus Hengstverzeichnis_Addons), gibt es neben den
Backend-Berechtigungen ein eigenes Konzept: **Zusatzfunktionen**, deren
Sichtbarkeit der Admin pro Installation umschaltet — „Öffentlich" (jeder
Besucher) oder „Nur für Gruppen mit Leseberechtigung" (ausschließlich
angemeldete Benutzer, deren Gruppe die zugehörige Leseberechtigung besitzt;
Admin-Mitglieder immer).

Registrierung über die optionale `features()`-Methode der Plugin-Klasse:

```php
public function features(): array {
    return [
        [
            'key' => 'verpaarungsrechner',          // a-z, 0-9, '-', '_'
            'label' => 'Verpaarungsrechner',        // Anzeigetext in der Admin-UI
            'default_visibility' => 'members',      // optional, Default 'members'
        ],
    ];
}
```

Die Registrierung bewirkt zweierlei:

- Die Funktion erscheint unter **Admin → Systemeinstellungen** mit dem
  Sichtbarkeits-Umschalter (gespeichert als Setting
  `feature_visibility__<key>`). Solange der Admin nichts wählt, gilt
  `default_visibility` — Standard ist `members` (fail-closed: neue
  Premium-Funktionen erscheinen nicht ungefragt öffentlich).
- In der Berechtigungsmatrix unter **Admin → Gruppen** erscheint automatisch
  das Modul `feature_<key>` mit der Aktion `read` („Sehen/Nutzen"), die pro
  Gruppe zuweisbar ist. Hinweis: In der Matrix unter `/admin/groups`
  erscheinen für das Modul zusätzlich die Standard-Aktionen `view` und
  `publish`, die jedes Modul automatisch erhält — für die
  Feature-Sichtbarkeit ausgewertet wird nur `read`.

Die **Durchsetzung** ist — wie bei normalen Berechtigungen — Aufgabe des
Plugins selbst, in der eigenen (typischerweise öffentlichen, also ohne
`checkAuth()` erreichbaren) Route bzw. im eigenen Hook:

```php
if (!\App\Permission\FeatureGate::isVisible('verpaarungsrechner', $this->settings)) {
    $this->renderForbidden('Diese Zusatzfunktion ist Mitgliedern vorbehalten.');
}
```

`FeatureGate::isVisible()` ist fail-closed: unbekannte Funktionen sind nie
sichtbar, ohne Anmeldung gibt es bei `members` keinen Zugriff, und DB-Fehler
führen zu „nicht sichtbar". Das Referenz-Plugin demonstriert das Muster mit
dem Feature `demo-premium` und der öffentlichen Route
`/plugin/demo-plugin/premium`.

## Mehrsprachigkeit (i18n, #48)

Der Kern übersetzt UI-Texte über `App\I18n\Translator` - flache,
Array-basierte Sprachdateien statt `gettext`, passend zur
"keine externen Abhängigkeiten"-Philosophie. Ein Plugin kann sich daran
anhängen, **ohne** eine Manifest-Angabe zu benötigen: Legt es ein eigenes
`lang/<locale>.php`-Verzeichnis an, wird dieses beim Laden automatisch unter
seinem Slug als eigene Übersetzungs-Domain registriert (Konvention, analog
zum Default-Entry `Plugin.php`):

```
plugins/<slug>/
  lang/
    de.php    // return ['detail_heading' => '...', ...];
    en.php
```

Verwendung im Plugin-Code:

```php
\App\I18n\Translator::t('detail_heading', [], 'demo-plugin');

// Mit Platzhaltern ({name} wird ersetzt):
\App\I18n\Translator::t('greeting', ['name' => $horseName], 'demo-plugin');
```

- **Domain = Plugin-Slug.** Verhindert Kollisionen zwischen Kern-Schlüsseln
  (Domain `core`, reserviert) und den Schlüsseln verschiedener Plugins -
  jedes Plugin hat seinen eigenen flachen Schlüsselraum.
- **"Wer zuerst registriert, gewinnt"** bei doppelter Domain-Registrierung,
  analog zu `PermissionRegistry::registerAction()`.
- **Fehlender Schlüssel:** `Translator::t()` gibt in diesem Fall den
  Schlüssel selbst zurück (nie eine leere Zeichenkette) - fehlende
  Übersetzungen fallen beim Testen sofort optisch auf, statt lautlos zu
  verschwinden.
- **Verfügbare Locales sind kern-seitig fest** (`Translator::getAvailableLocales()`,
  seit #198 zwölf Sprachen: `de`, `en`, `da`, `nl`, `fr`, `lb`, `it`, `cs`,
  `pl`, `nb`, `sv`, `fi`) - ein Plugin kann bestehende Sprachdateien um
  weitere Schlüssel ergänzen, aber (Phase 1) keine komplett neue Locale zur
  Kern-Auswahl hinzufügen.

Das Referenz-Plugin (`docs/examples/demo-plugin/`) demonstriert dies
vollständig: `lang/de.php`/`lang/en.php` sowie deren Nutzung in
`addDetailSection()`.

## Sicherheitsmodell — was durchgesetzt wird und was nicht

**Technisch durchgesetzt vom Kern, nicht vom Plugin umgehbar:**
- CSRF-Prüfung und Rollenprüfung (`checkAuth()`/`requireAdmin()`) bleiben
  zentral in `Router`/`BaseController` verankert. Alle oben dokumentierten
  Hooks sitzen in Controller-Methoden **nach** diesen Prüfungen — ein
  Plugin-Hook läuft nie, ohne dass sie bereits durchlaufen wurden.
- Fehler in einem Hook-Callback (Exception oder `Error`, z. B. `TypeError`)
  werden pro Aufruf isoliert abgefangen (siehe `HookManager`) und
  protokolliert, statt den Request abzubrechen.
- Plugin-Routen können ausschließlich unter `/plugin/<slug>/...` liegen
  (erzwungen durch `PluginManager`, siehe oben) und daher nie eine
  Kern-Route überschreiben.
- Ein Plugin wird **nie automatisch geladen** — nur wenn ein Administrator
  es zuvor explizit unter `/admin/plugins` aktiviert hat (Tabelle `plugins`).
- Über `permissions()` registrierte Aktionen können bestehende Berechtigungen
  (Kern oder anderes Plugin) nicht überschreiben oder umdefinieren ("wer
  zuerst registriert, gewinnt", siehe Abschnitt "Berechtigungen").

**Nicht technisch erzwungen — bewusstes Vertrauen bei der Aktivierung:**
- PHP bietet ohne zusätzliche Abhängigkeiten (die der Kern bewusst
  vermeidet) kein Prozess-Sandboxing. Ein aktiviertes Plugin läuft im
  selben PHP-Prozess wie der Kern und hat technisch vollen Zugriff auf
  `Database::getInstance()`, das Dateisystem und beliebigen PHP-Code.
- Das `hooks`-Feld im Manifest ist reine Selbstauskunft, keine
  Sicherheitsgrenze — es wird nicht geprüft, ob ein Plugin sich tatsächlich
  nur auf die deklarierten Hooks beschränkt.
- Fatale PHP-Parse-Fehler in der Plugin-Datei selbst lassen sich in PHP
  grundsätzlich nicht per try/catch abfangen. `PluginManager` lädt
  Plugin-Dateien deshalb einzeln in try/catch um `require_once`, was einen
  Parse-Fehler zur Ladezeit selbst zwar nicht "catchen" kann (das ist eine
  PHP-Grenze), aber verhindert, dass ein Fehler beim Instanziieren/
  Registrieren eines Plugins andere Plugins am Laden hindert.

**Konsequenz für Admins:** Nur Plugins aus vertrauenswürdiger Quelle
aktivieren — die Aktivierung selbst ist der eigentliche Sicherheits-
Kontrollpunkt, nicht eine technische Sandbox danach. Die Admin-UI
(`/admin/plugins`) weist beim Aktivieren entsprechend darauf hin.

## Datenhaltung eigener Plugin-Tabellen

Eigene Tabellen legt ein Plugin im `install()`-Hook an — siehe den
Abschnitt [Installation & Migrationen: `install()`](#installation--migrationen-install)
oben. Der frühere Weg (`CREATE TABLE IF NOT EXISTS` direkt in `register()`)
ist überholt: Er führte das DDL bei **jedem** Request aus (Addons-Issue #75)
und soll in neuen Plugins nicht mehr verwendet werden.
