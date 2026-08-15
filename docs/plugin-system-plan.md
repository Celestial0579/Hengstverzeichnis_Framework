# Plugin-/Erweiterungssystem — Umsetzungsplanung

**Status:** Phase 1 und Phase 3 (siehe Abschnitt 3) umgesetzt -
Kern-Plugin-System, Admin-UI, initiale Hooks, Referenz-Plugin, Entwickler-Doku
([plugin-development.md](plugin-development.md)) sowie der Addon-Store
(`/admin/plugins/store`) sind implementiert.
**Bezug:** [#56](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/56) (Kern-Anforderung), [#58](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/58) (Anwendungsfälle für spätere Plugins)

Dieses Dokument bricht die Anforderung aus Issue #56 auf eine konkrete,
mit der bestehenden Architektur (siehe [architecture.md](architecture.md))
kompatible technische Umsetzung herunter.

> **Historisches Planungsdokument.** Der Plan ist umgesetzt; einzelne
> Details wurden in der Umsetzung anders entschieden (in Abschnitt 4
> nachgetragen). Der **verbindliche Ist-Stand** für Plugin-Autoren steht in
> [plugin-development.md](plugin-development.md) — bei Widersprüchen gilt
> jenes Dokument. Noch offen aus diesem Plan ist einzig der
> `plugin.schema`-Hook für plugin-eigene Tabellen (Abschnitt 2.5).

## 1. Ausgangslage & Ziel

Heute lässt sich Zusatzfunktionalität nur durch direkte Änderungen an
`src/Controllers/`, `src/Router.php`, `public/index.php` und `src/Views/`
einbauen. Das führt zu Update-Konflikten bei individuellen
Verbandsanpassungen, fehlender Isolation (ein Fehler in einer Erweiterung
kann Kernfunktionalität gefährden) und keinem erzwungenen Sicherheitsrahmen.

**Ziel:** Ein Plugin-System, mit dem Zusatzfunktionen **ohne Änderungen an
Kern-Dateien** ergänzt werden können — über ein Verzeichnis, ein Manifest
und ein definiertes Hook-/Event-System, das weiterhin durch die
bestehenden Sicherheitsmechanismen (CSRF, `checkAuth()`/`requireAdmin()`)
läuft und einzelne Plugin-Fehler isoliert.

Wichtige Randbedingung aus den Issue-Kommentaren: Plugins selbst sollen
**nicht** im Kern-Repository landen, sondern in einem separaten
Plugin-Repository/einer späteren Registry. Dieses Issue (#56) liefert nur
das **Plugin-System selbst** im Kern — lauffähig auch mit lokal manuell
abgelegten Plugins, unabhängig davon, ob/wann eine externe Registry kommt.

## 2. Architektur-Grundentscheidungen

Angelehnt an die bestehenden Prinzipien des Frameworks (keine externen
Abhängigkeiten, kein Composer, minimalistisches MVC, `ensureSchemaUpToDate()`-
Pattern statt klassischer Migrationen):

### 2.1 Verzeichnisstruktur

```
plugins/
  <plugin-slug>/
    plugin.json      Manifest (siehe 2.3)
    Plugin.php        Einstiegspunkt, registriert sich bei den Hooks
    src/               Optionaler eigener Code-Namespace des Plugins
    Views/             Optionale eigene View-Templates
```

`plugins/` liegt auf Repo-Root-Ebene (parallel zu `src/`), **nicht** unter
`public/` — kein direkter HTTP-Zugriff auf Plugin-Dateien, analog zu `src/`.
Ein `.gitignore`-Eintrag hält das Verzeichnisinhalt aus dem Kern-Repo
heraus (nur die leere Struktur + `plugins/.gitkeep` wird versioniert),
konsistent mit der "Plugins leben in eigenen Repos"-Entscheidung aus dem
Issue-Kommentar.

### 2.2 Bootstrap-Integration

Neue Klasse `App\Plugin\PluginManager` (Singleton, analog zu `Database`):

- Wird in `public/index.php` **nach** dem Autoloader und **vor** der
  Routen-Registrierung instanziiert.
- Scannt `plugins/*/plugin.json`, validiert Manifeste, lädt nur
  **aktivierte** Plugins (Aktivierungsstatus in DB, siehe 2.5).
  Plugin-Code läuft unter einem eigenen Namespace `Plugin\<StudlySlug>`
  (aus dem Slug abgeleitet), getrennt vom `App\`-Namespace des Kerns.
  Umgesetzt als einfaches `require_once` der Entry-Datei (kein eigener
  `spl_autoload_register` im Kern nötig) — ein Plugin, das eigene
  Unter-Namespaces/Klassen braucht, registriert dafür bei Bedarf selbst
  einen Autoloader in seiner Entry-Datei.
- Bindet `Plugin.php` jedes aktivierten Plugins ein und ruft eine
  standardisierte Registrierungsmethode auf (`register(HookManager
  $hooks)`), über die sich das Plugin an Hooks anmeldet — **kein**
  automatischer Vollzugriff auf Router/Controller-Internas.
- `public/index.php` selbst bleibt unverändert lesbar: Es kommt **eine**
  neue Zeile hinzu (PluginManager-Init), keine Verzweigungslogik pro Plugin.

### 2.3 Manifest (`plugin.json`)

```json
{
  "slug": "beispiel-plugin",
  "name": "Beispiel Plugin",
  "version": "1.0.0",
  "core_compatibility": ">=1.4.0",
  "description": "...",
  "author": "...",
  "hooks": ["horse.after_save", "admin.dashboard_tiles"],
  "entry": "Plugin.php"
}
```

- `core_compatibility` wird gegen eine im Kern gepflegte `CORE_VERSION`-
  Konstante geprüft — inkompatible Plugins werden beim Scan übersprungen
  und im Admin-Bereich sichtbar als "inkompatibel" markiert statt geladen.
- `hooks` ist rein deklarativ/informativ für die Admin-Übersicht ("was tut
  dieses Plugin tatsächlich, bevor ich es aktiviere?") — technisch
  durchgesetzt wird die tatsächliche Hook-Nutzung durch den `HookManager`
  selbst (siehe 2.4), das Manifest ist keine Sicherheitsgrenze, sondern
  Transparenz für den Admin.

### 2.4 Hook-/Event-System

Neue Klasse `App\Plugin\HookManager`:

- `addAction(string $hook, callable $callback, int $priority = 10)`
- `doAction(string $hook, ...$args)` — führt alle registrierten Callbacks
  für einen Hook aus, **jeden einzeln in eigenem try/catch**: Eine
  Exception in einem Plugin-Callback wird geloggt (`AuditLogger`, Kategorie
  `plugin`) und bricht nur diesen einen Hook-Aufruf ab, nicht den gesamten
  Request.
- `addFilter(string $hook, callable $callback, int $priority = 10)` /
  `applyFilters(string $hook, $value, ...$args)` — für Fälle, in denen ein
  Plugin einen Wert verändern statt nur reagieren soll (z. B. zusätzliche
  Validierungsregeln).

Definierte Erweiterungspunkte für die erste Ausbaustufe (bewusst klein
gehalten, erweiterbar nach Bedarf):

| Hook | Ort | Zweck | Stand |
|---|---|---|---|
| `horse.before_save` / `horse.after_save` | `HorseController` | Zusätzliche Validierung / Folgeaktionen | umgesetzt |
| `horse.detail_sections` (Filter) | `PublicController::horseDetail` | Zusätzlicher Abschnitt auf der Pferde-Detailseite | umgesetzt |
| `admin.dashboard_tiles` (Filter) | `AdminController::dashboard` | Zusätzliche Kachel im Admin-Dashboard | umgesetzt |
| `router.register` | `public/index.php`, nach Kern-Routen | Zusätzliche Routen registrieren | **so nie gebaut** — ersetzt durch die `routes()`-Methode (Abschnitt 4) |
| `nav.public_links` / `nav.admin_links` (Filter) | `layout.php` | Zusätzliche Navigationspunkte | **nicht umgesetzt** (Abschnitt 4) |

Zusätzlich später umgesetzt (nicht Teil dieser Planung):
`catalog.card_sections` (#97) je gerenderter Katalogkarte und
`horse.edit_sections` (#255) im Admin-Bearbeitungsformular — der erste
Erweiterungspunkt aus Phase 2 (siehe unten), damit Addons pferdbezogene Daten
in der bestehenden Hengstverwaltung pflegen können, statt eigene
Verwaltungsseiten mit vollständiger Pferdeauswahl zu bauen.

Wichtig: Hooks sitzen **innerhalb** der bestehenden Controller-Methoden,
**nach** `checkAuth()`/`requireAdmin()` und CSRF-Prüfung — ein Plugin-Hook
kann diese Prüfungen nicht umgehen, weil er sie nie selbst durchführt,
sondern nur an einem Punkt läuft, der sie bereits durchlaufen hat. Für
Plugin-Routen (umgesetzt als `routes()`-Methode statt des hier geplanten
`router.register`-Hooks) gilt: sie laufen durch denselben
`Router::dispatch()` wie Kern-Routen; ein Plugin-Controller, der z. B.
Admin-Funktionalität anbietet, muss weiterhin selbst `checkAuth()`/
`requireAdmin()` aufrufen (dokumentierte Konvention + Beispiel-Boilerplate
im Plugin-Template, keine technisch erzwingbare Sandbox — siehe 2.6).

### 2.5 Datenhaltung & Verwaltung

Neue Tabelle `plugins` über das bestehende `ensureSchemaUpToDate()`-Pattern
in `Database.php` (keine separate Migration-Infrastruktur, konsistent mit
dem Rest des Kerns):

```sql
CREATE TABLE IF NOT EXISTS `plugins` (
    `slug` VARCHAR(100) PRIMARY KEY,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `installed_version` VARCHAR(20) NOT NULL,
    `activated_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

*(Ist-Schema in `database/schema.sql` hat zusätzlich `content_hash
VARCHAR(64) NULL` — den SHA-256-Fingerabdruck über den Plugin-Ordner, der
die Update-Erkennung trägt; er kam mit #129 nach dieser Planung hinzu.)*

Admin-UI (neuer `PluginController`, admin-only, `requireAdmin()`):

- `/admin/plugins` — Übersicht aller in `plugins/` gefundenen Plugins:
  Name, Version, Beschreibung, deklarierte Hooks, Kompatibilitätsstatus,
  Aktiv/Inaktiv-Toggle.
- Aktivieren/Deaktivieren über CSRF-geschützten POST, schreibt nur den
  `enabled`-Flag in die `plugins`-Tabelle — Wirksamkeit erst nach nächstem
  Request (kein Hot-Reload nötig, PHP lädt ohnehin pro Request neu).
- Jede Statusänderung wird im Audit-Log protokolliert (Kategorie `plugin`),
  konsistent mit dem bestehenden Audit-Konzept für sicherheitsrelevante
  Aktionen.

### 2.6 Sicherheitsmodell — ehrliche Grenzen

PHP bietet ohne zusätzliche Abhängigkeiten (die der Kern bewusst vermeidet)
**kein echtes Prozess-Sandboxing**. Das Sicherheitsversprechen aus Issue #56
wird daher wie folgt eingehalten, mit klar kommunizierten Grenzen:

- **Was der Kern technisch durchsetzt:** CSRF-Prüfung und Rollenprüfung
  bleiben zentral in `Router`/`BaseController` verankert, nicht duplizierbar
  vom Plugin umgehbar, solange das Plugin über die vorgesehenen Hooks statt
  eigener globaler Bootstrap-Manipulation arbeitet. Try/catch-Isolation pro
  Hook-Aufruf verhindert, dass ein fehlerhaftes Plugin die gesamte
  Anfrage/Seite zum Absturz bringt (Fatal Errors in `include`s lassen sich
  in PHP nicht abfangen — deshalb ist sauberer Plugin-Code, der Exceptions
  statt Fatal Errors wirft, Voraussetzung; das Manifest/die Dokumentation
  macht das explizit).
- **Was nicht technisch erzwungen wird:** Ein aktiviertes Plugin läuft im
  selben PHP-Prozess und hat theoretisch Zugriff auf `Database::getInstance()`
  und beliebigen PHP-Code (`eval`, Dateisystem etc.). Es gibt keine
  Kernel-Sandbox. Der Schutz ist organisatorisch: Plugins werden bewusst
  aktiviert (Admin-Entscheidung, sichtbar im Manifest, was deklariert ist),
  nicht automatisch nachgeladen. Das wird in der Admin-UI und der
  Entwickler-Doku (`docs/plugin-development.md`, Phase 2) unmissverständlich
  benannt — kein falsches Sicherheitsversprechen.
- Damit bleibt die im Issue geforderte "Leitplanke statt Vertrauen" für den
  **eigentlichen Sicherheitskern der Anwendung** (Auth/CSRF/DB-Zugriffsmuster)
  gültig, während für den Plugin-Code selbst realistischerweise weiterhin
  Code-Vertrauen vor Aktivierung nötig ist (daher: kein automatischer
  Download+Aktivierung, siehe Phase 3).

### 2.7 Verhältnis zum separaten Plugin-Repository / zur Registry

Laut Klärung in den Issue-Kommentaren sollen die eigentlichen Plugins in
einem **separaten Repository** leben (eigener Lebenszyklus/Versionierung).
Das ist bewusst **nicht Teil des Kern-Issues #56** — der Plugin-*Manager*
im Kern muss nur lokal in `plugins/` abgelegte Plugins laden können.

**Umgesetzt (Addon-Store, siehe Phase 3):** Statt der ursprünglich skizzierten
kuratierten `index.json`-Registry (Tag-/Release-gebunden, mit Prüfsumme pro
Asset) liest `App\Service\GithubAddonRepository` den Katalog **live** aus dem
Tarball eines GitHub-Repos (`plugins/<slug>/plugin.json` je Addon, wie im
mitgelieferten [Hengstverzeichnis_Addons](https://github.com/Celestial0579/Hengstverzeichnis_Addons)-Repo,
oder ein einzelnes `plugin.json` im Repo-Root für ein Ein-Plugin-Repo).
Grund für die Abweichung: Ein Admin soll nicht nur das eine offizielle Repo,
sondern **beliebige GitHub-Repos per Link** als weitere Addon-Quelle
hinzufügen können (`AddonStoreController::addRepo()`, Tabelle `addon_repos`)
- eine feste, kuratierte `index.json` mit Prüfsumme ergibt für ein vom Admin
selbst gewähltes, beliebiges Drittanbieter-Repo keinen zusätzlichen
Sicherheitsgewinn (das Repo kontrolliert seine eigene Prüfsumme ohnehin
selbst). Die Vertrauensentscheidung liegt hier bewusst und ausschließlich
beim Admin, der ein Repo hinzufügt - identisch zum bestehenden Modell "nur
Plugins aus vertrauenswürdiger Quelle aktivieren" (siehe
[plugin-development.md](plugin-development.md), Abschnitt "Sicherheitsmodell").
Technisch abgesichert wird stattdessen: striktes Owner/Repo/Ref-Validieren
vor jeder URL-Konstruktion (fester Zielhost `api.github.com`, kein SSRF über
einen manipulierten Host), Größenlimit + Timeout beim Download, und
Entpacken ausschließlich in ein isoliertes temporäres Verzeichnis mit
anschließender Pfad-/Symlink-Prüfung (`verifyExtractedTreeIsSafe()`) - siehe
Docblock von `GithubAddonRepository` für Details. **Installieren aktiviert
ein Plugin weiterhin nie automatisch** - das bleibt der bestehende, separate
Schritt unter `/admin/plugins` (`PluginController`/`PluginManager`).

## 3. Phasenplan

**Phase 1 — Kern-Plugin-System (dieses Issue, #56):**
1. `plugins/`-Verzeichnisstruktur + `.gitignore`-Eintrag + `plugins/.gitkeep`
2. `App\Plugin\PluginManager` (Scan, Manifest-Validierung,
   Kompatibilitätsprüfung, Autoload-Registrierung, Laden aktivierter Plugins)
3. `App\Plugin\HookManager` (Actions + Filters, try/catch-Isolation, Logging
   fehlgeschlagener Hook-Aufrufe ins Audit-Log)
4. `plugins`-Tabelle über `Database::ensureSchemaUpToDate()`
5. Hook-Punkte in bestehende Controller/Views einziehen (siehe Tabelle 2.4)
   — minimal-invasive Einzeiler an den definierten Extension-Points
6. `PluginController` + `admin_plugins`-View (Übersicht, Aktivieren/
   Deaktivieren, CSRF-geschützt, Audit-Log-Eintrag pro Statusänderung)
7. `router.register`-Hook in `public/index.php` (nach den Kern-Routen)
8. Ein minimales Beispiel-/Demo-Plugin (z. B. schlichte Dashboard-Kachel)
   als Referenzimplementierung + Doku-Grundlage, **nicht** produktiv
9. Entwickler-Doku `docs/plugin-development.md`: Manifest-Format,
   verfügbare Hooks, Sicherheitsgrenzen (siehe 2.6), Beispiel-Plugin
10. Ergänzung in `docs/architecture.md` (neuer Abschnitt "Plugin-System")

**Phase 2 — Erweiterung der Hook-Punkte (Folge-Issue, nach Bedarf):**
Weitere Extension-Points je nachdem, welche der in #58 gesammelten
Plugin-Ideen konkret umgesetzt werden (z. B. Hooks für Deckstations-
Detailseite, Personen-CRUD, eigene DB-Tabellen pro Plugin über einen
`plugin.schema`-Hook analog zu `ensureSchemaUpToDate()`).

**Phase 3 — Addon-Store (umgesetzt):** `App\Service\GithubAddonRepository`
(Download, sicheres Entpacken, Katalog-Scan) + `App\Controllers\AddonStoreController`
(`/admin/plugins/store`) - Auflisten und Installieren von Plugins aus dem
mitgelieferten offiziellen Repo sowie beliebigen, per Link hinzugefügten
GitHub-Repos, siehe Abschnitt 2.7 für die Sicherheitsabwägung gegenüber der
ursprünglich skizzierten kuratierten `index.json`-Registry.

## 4. Entscheidungen zu den offenen Punkten (bei Umsetzung getroffen)

Die folgenden Punkte waren vor der Implementierung offen und wurden beim
Umsetzen von Phase 1 wie folgt entschieden (Rückmeldung/Korrektur jederzeit
möglich, siehe PR):

- **Hook-Umfang Phase 1:** Auf die drei im Issue #56 selbst explizit
  genannten Beispiele reduziert (`horse.before_save`/`horse.after_save`,
  `horse.detail_sections`, zusätzliche Routen) plus `admin.dashboard_tiles`
  als naheliegende Ergänzung für eine sichtbare Admin-UI-Erweiterung. Die
  ursprünglich in 2.4 zusätzlich skizzierten `nav.*`-Filter wurden bewusst
  **nicht** in Phase 1 umgesetzt (kein konkreter Bedarf, hätte nur Fläche
  ohne Demonstrationswert hinzugefügt) — Kandidat für Phase 2 bei Bedarf.
- **Routen-Präfix:** Wie empfohlen erzwungen. Statt eines generischen
  `router.register`-Hooks registriert `PluginManager` die von einem Plugin
  über eine optionale `routes()`-Methode deklarierten Pfade selbst und
  stellt dabei zwingend `/plugin/<slug>/` voran — technisch unmöglich für
  ein Plugin, eine Kern-Route zu überschreiben (siehe
  [plugin-development.md](plugin-development.md), Abschnitt "Routen").
- **Demo-Plugin:** Demonstriert drei der Phase-1-Hooks
  (`admin.dashboard_tiles`, `horse.detail_sections`, `horse.after_save` —
  nicht `horse.before_save`) sowie den Phase-2-Hook `horse.edit_sections`,
  plus eine eigene Route sowie inzwischen
  `permissions()` und `features()`
  (`docs/examples/demo-plugin/`, zum Ausprobieren nach `plugins/`
  kopieren — liegt selbst nicht in `plugins/`, da dieses Verzeichnis
  bewusst nicht versioniert wird, siehe 2.1). Bewusst **kein** absichtlich
  fehlschlagender Hook-Callback im ausgelieferten Demo-Plugin (würde bei
  jedem Pferde-Speichern im laufenden Betrieb unnötig einen Audit-Log-
  Fehleintrag erzeugen) — die Isolationsgarantie ist stattdessen in
  [plugin-development.md](plugin-development.md) dokumentiert und lässt
  sich bei Bedarf lokal durch einen testweise eingefügten `throw` verifizieren.

## 5. Nächste Schritte

Phase 1 und Phase 3 (Addon-Store gegen das separate Repository
`Hengstverzeichnis_Addons`) sind umgesetzt; aus Phase 2 kam
`catalog.card_sections` (#97) hinzu. Offen aus dieser Planung ist nur noch
der `plugin.schema`-Hook für plugin-eigene Tabellen (Abschnitt 2.5) —
weitere Hooks entstehen nach Bedarf aus konkreten Addon-Anforderungen.

## 6. Nachträgliche Anforderung: Benutzergruppen-Konzept (#66)

Nach Umsetzung von Phase 1 wurde in den Issue-Kommentaren zu #56 (05.08.,
09:52–09:57 Uhr) ergänzt, dass das generelle Benutzergruppen-/
Berechtigungskonzept aus dem neuen Vorgänger-Issue
[#66](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/66)
vor bzw. zusammen mit dem Plugin-System stehen soll, damit Plugins ihre
Funktionen/Hooks granular auf admin-definierte Benutzergruppen einschränken
können, statt das später nachzurüsten. Separate Planung dazu:
[user-groups-plan.md](user-groups-plan.md) (Abschnitt 4.2 dort beschreibt
konkret die geplante, additive Anbindung an das hier umgesetzte
Plugin-System - **kein** Bruch des bestehenden Manifest-Formats).
