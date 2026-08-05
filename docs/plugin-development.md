# Plugin-Entwicklung

Diese Seite beschreibt das Plugin-/Erweiterungssystem aus Entwicklersicht:
Manifest-Format, verfügbare Hooks, Routen-Konvention und die tatsächlichen
Sicherheitsgrenzen. Für die Umsetzungsplanung/Architekturentscheidungen
siehe [plugin-system-plan.md](plugin-system-plan.md); für die
allgemeine Kern-Architektur siehe [architecture.md](architecture.md).

Plugins leben **nicht** im Kern-Repository (`plugins/` ist absichtlich in
`.gitignore` eingetragen, nur `plugins/.gitkeep` wird versioniert) — sie
werden separat gepflegt und lokal in `plugins/<slug>/` abgelegt.

## Schnellstart: Referenz-Plugin ausprobieren

```bash
cp -r docs/examples/demo-plugin plugins/demo-plugin
```

Danach unter **Admin → Plugins verwalten** (`/admin/plugins`) aktivieren.
Das Referenz-Plugin (`docs/examples/demo-plugin/Plugin.php`) demonstriert
alle unten dokumentierten Hooks sowie das Registrieren einer eigenen Route.

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
| `description` | – | Anzeigetext im Admin-Bereich. |
| `author` | – | Anzeigetext im Admin-Bereich. |
| `hooks` | – | Rein deklarativ/informativ - zeigt Admins vor der Aktivierung, was das Plugin laut Selbstauskunft tut. Wird **nicht** technisch erzwungen. |
| `entry` | – | PHP-Datei mit der Plugin-Klasse, relativ zum Plugin-Verzeichnis. Default: `Plugin.php`. |

### Kompatibilitätsprüfung (`core_compatibility`)

Format: optionaler Operator (`>=`, `<=`, `>`, `<`, `=`) gefolgt von einer
Versionsnummer, ausgewertet mit PHP `version_compare()` gegen die im Kern
definierte Konstante `CORE_VERSION` (aktuell `0.1.0-beta.1`, siehe
`config/config.php`). Ohne Operator wird exakte Übereinstimmung geprüft.

Ein Plugin, dessen Manifest fehlerhaft ist oder dessen
`core_compatibility`-Angabe nicht zur laufenden Kern-Version passt, wird
beim Scan übersprungen (in `/admin/plugins` sichtbar als "Ungültiges
Manifest"/"Inkompatibel") und lässt sich nicht aktivieren.

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
Bei jedem folgenden Request wird beides neu berechnet und verglichen:

- **Neue Versionsnummer im Manifest** → reguläres Update, wird automatisch
  akzeptiert (Freigabe wandert auf die neue Version/den neuen
  Fingerabdruck) - ein normales Plugin-Update verliert dadurch **nie** seine
  Aktivierung, auch nicht durch eine erneute manuelle Freigabe.
- **Gleiche Versionsnummer, aber abweichender Code** → wird als verdächtig
  behandelt (Code wurde unter demselben Slug ausgetauscht, ohne ein
  reguläres Update mit neuer Versionsnummer zu sein). Das Plugin wird für
  diesen Request **nicht geladen**, bis ein Admin es unter `/admin/plugins`
  über den Button "Mit bisherigem Status erneut freigeben" bestätigt.

**Deshalb: Bei jeder inhaltlichen Änderung am Plugin-Code die `version` im
Manifest erhöhen** - sonst zeigt `/admin/plugins` nach dem nächsten Request
fälschlich "Code geändert - erneute Freigabe nötig" an, obwohl es sich um
ein gewolltes Update handelt.

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
| `horse.detail_sections` | Filter | Beim Rendern der öffentlichen Pferde-Detailseite | `function(array $sections, array $horse, array $horsePersons, ?array $pedigree): array` — jedes Element ist ein fertiger HTML-String, wird **unescaped** ausgegeben. `$pedigree` ist der bereits berechnete 6-Generationen-Baum (siehe `App\Service\PedigreeBuilder` unten), `null` falls das Pferd nicht gefunden wurde |
| `admin.dashboard_tiles` | Filter | Beim Rendern des Admin-Dashboards | `function(array $tiles): array` — jedes Element: `['url' => string, 'label' => string, 'icon' => string]` |

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

## Wiederverwendbarer Dienst: `App\Service\PedigreeBuilder`

Die Pedigree-Baum-Logik hinter `horse.detail_sections`' viertem Parameter
steht Plugins auch unabhängig vom Hook zur Verfügung — z. B. für einen
Inzuchtkoeffizienten-Rechner, der eine größere Generationstiefe braucht als
die öffentliche Detailseite serverseitig lädt (dort fest 6, per UI bis zu
dieser Tiefe umschaltbar), oder für einen Pedigree-Export mit eigener
Aufbereitung:

```php
$tree = \App\Service\PedigreeBuilder::build($horseId, $maxDepth);
```

- `$horseId`: ID des Wurzel-Pferdes (`?int`, `null`/`0` liefert `null` zurück).
- `$maxDepth`: gewünschte Generationstiefe (Standard `4`, kann von Plugins
  frei gewählt werden — unabhängig von der öffentlichen Seite).
- Rückgabe: verschachteltes Array pro Knoten mit `id`, `name`, `ueln`,
  `birth_year`, `color`, `depth`, `is_placeholder`, `sire`, `dam` (jeweils
  wieder derselbe Knoten-Typ oder `null`), oder `null` insgesamt, wenn kein
  passendes Pferd existiert.
- Auflösung von Sire/Dam: primär über `sire_id`/`dam_id`, sonst Fallback auf
  eine Suche nach `sire_ueln`/`sire_name` bzw. `dam_ueln`/`dam_name` gegen
  UELN/Fremd-UELN/Name anderer Pferde-Datensätze. Bleibt der Fallback
  erfolglos, wird ein synthetischer Platzhalter-Blattknoten erzeugt
  (`id => null`, `is_placeholder => true`) — dieser kann `depth` von
  `$maxDepth + 1` tragen (bestehendes, absichtlich unverändertes Verhalten).
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
    echo 'Hallo!';
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
  aktuell `de`/`en`) - ein Plugin kann bestehende Sprachdateien um weitere
  Schlüssel ergänzen, aber (Phase 1) keine komplett neue Locale zur
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

Phase 1 bietet keinen eigenen Hook für Plugin-Schema-Migrationen. Ein
Plugin, das eigene Tabellen benötigt, kann diese in `register()` bei Bedarf
selbst anlegen (`CREATE TABLE IF NOT EXISTS ...` über
`App\Database::getInstance()`), analog zum
`Database::ensureSchemaUpToDate()`-Muster des Kerns. Ein dedizierter
Plugin-Migrations-Hook ist für eine spätere Ausbaustufe vorgesehen (siehe
[plugin-system-plan.md](plugin-system-plan.md)).
