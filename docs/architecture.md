# Architektur

## Grundprinzip

Das Framework ist ein selbstgebautes, minimalistisches MVC-ähnliches System
ohne externe Abhängigkeiten (kein Composer, kein Framework wie Laravel/Symfony).
Alles läuft über einen einzigen Front-Controller (`public/index.php`), einen
schlanken Router, Controller-Klassen und PHP-Views mit einem gemeinsamen Layout.

```
Request → public/index.php → Router::dispatch() → Controller::method() → render() → Views/layout.php
```

## Verzeichnisstruktur

```
config/             Konfigurationsdatei (config.php) + optional generierte db_config.php
database/           schema.sql (Erststand), migrate.php, seed.php, reset.php
lang/               Kern-Sprachdateien (de.php, en.php) für App\I18n\Translator, siehe unten
plugins/             Lokal aktivierte Plugins (siehe unten, nicht versioniert außer .gitkeep)
public/             Docroot des Webservers (Apache DocumentRoot zeigt hierher)
  index.php          Front-Controller: Autoloader, Routing-Tabelle, Dispatch
  css/, js/          Statische Assets
  uploads/           Branding-Dateien, z. B. das Logo (persistentes Docker-Volume).
                     Pferdefotos liegen seit #366 NICHT mehr hier, sondern unter
                     storage/horses - im Webroot lieferte der Webserver sie an der
                     Sichtbarkeitspruefung vorbei aus.
src/
  Router.php          Routing + CSRF-Token-Hilfsmethoden
  Database.php         PDO-Singleton + automatisches Schema-Update
  Controllers/         Ein Controller pro fachlichem Bereich (siehe unten)
  Views/                Ein PHP-Template pro Seite + layout.php als Rahmen
  Security/             Crypto, Totp, RateLimiter, ClientIp, ApiKey, OidcIdToken, TrustedHost
  Service/               AuditLogger, Mailer, PedigreeBuilder, Backup-/Dump-Dienste (S3, WebDAV, FTPS), Scheduler, DigestService, UpdateService, HorseCsvImporter, GithubAddonRepository u. a.
  Plugin/                 PluginManager, HookManager (Plugin-System, siehe unten)
  Permission/             PermissionRegistry, GroupMembership, FeatureRegistry, FeatureGate (Gruppen-/Berechtigungssystem, siehe unten)
  I18n/                   Translator (Mehrsprachigkeit, siehe unten)
  Helper/                 Markdown (einfacher Markdown→HTML-Parser), Paginator
tests/               PHPUnit-Suite (Unit/Integration/Functional, siehe development.md)
security/            DAST-Scan-Harness (run-security-scan.sh, checks/, baseline/)
storage/logs/        Audit-Log-Ablage (wird zur Laufzeit angelegt)
.github/             CI-Workflows (Tests, CodeQL, Semgrep, Scorecard, Release)
```

Es gibt bewusst **kein `src/Models`**-Verzeichnis mit ORM-Klassen: Die
Controller sprechen direkt per PDO/Prepared Statements mit der Datenbank.
Das ist ein bewusster Trade-off für Einfachheit, nicht ein unfertiger Zustand.

## Autoloading

`public/index.php` registriert einen simplen PSR-4-artigen Autoloader für den
Namespace `App\` → `src/`. Er wird **vor** `config/config.php` eingebunden,
weil `config.php` bereits `App\Security\ClientIp` benötigt (für die
HTTPS-/Proxy-Erkennung beim Setzen der Security-Header).

## Routing (`src/Router.php`)

- Sehr einfaches Array von `['method', 'path', 'callback']`-Tupeln, exakter
  String-Vergleich (kein Pattern-/Parameter-Matching wie `/horse/{id}`).
  IDs werden stattdessen über Query-Parameter übergeben (z. B. `/horse?id=5`).
- Alle Routen werden zentral in `public/index.php` registriert.
- Kein Treffer → `PublicController::renderNotFound()` (404).
- `Router::generateCsrfToken()` / `Router::verifyCsrfToken()`: zentrale
  CSRF-Token-Erzeugung/-Prüfung (siehe [security.md](security.md)).

## Controller (`src/Controllers/`)

Alle Controller erben von `BaseController`, der folgende Querschnittsfunktionen
bereitstellt:

| Methode | Zweck |
|---|---|
| `__construct()` | Lädt globale Einstellungen (`settings`-Tabelle: Site-Name, Farben, Logo) |
| `render($view, $data)` | Rendert eine View aus `src/Views/` innerhalb von `layout.php` |
| `checkAuth()` | Session-Prüfung inkl. Anti-Session-Hijacking, Inaktivitäts-Timeout, ID-Rotation, Passwortänderungs-Zwang |
| `requireAdmin()` | Prüft Mitgliedschaft in der Gruppe `admin` (#66), sonst 403 |
| `renderForbidden()/renderNotFound()/renderServerError()` | Einheitliche Fehlerseiten (403/404/500), 403 wird zusätzlich im Audit-Log protokolliert |
| `isReservedUsername()` | Verhindert reservierte Benutzernamen wie `admin`, `root`, `system` |

Fachliche Controller (jeweils `__construct()` ruft i. d. R. `checkAuth()`
und ggf. `requireAdmin()` auf, außer bei öffentlichen Controllern):

| Controller | Zuständigkeit |
|---|---|
| `PublicController` | Startseite, öffentlicher Katalog, Pferdedetail inkl. Pedigree-Baum, Impressum, Datenschutz, DSGVO-Kontaktformular |
| `AuthController` | Login, Logout, Passwort vergessen/zurücksetzen, 2FA-Setup/-Verifikation, Backup-Codes, erzwungene Passwortänderung |
| `SetupController` | Ersteinrichtungs-Wizard (DB-Verbindung, Verbandsname, erster Admin-Account) |
| `AdminController` | Dashboard, Verbandseinstellungen, Systemeinstellungen, Mail-/SMTP-Einstellungen, System-Reset, Audit-Log-Ansicht |
| `HorseController` | Pferde-CRUD, Bild-Upload, automatische Blutlinien-Verknüpfung, Match-/Merge-Vorschlagswerkzeug |
| `ContactController` | CRUD für Kontakte - Personen wie Deckstationen (#336). Bis v0.7 waren das zwei Controller auf zwei Tabellen; die Trennung erzeugte laufend Fälle, die niemand entscheiden kann (ein Hof, den zwei Privatleute betreiben, ist beides) |
| `UserController` | Admin-only Benutzerverwaltung (anlegen, bearbeiten, löschen, 2FA zurücksetzen) |
| `GdprController` | Verwaltung eingegangener DSGVO-Anfragen (Status, Anonymisierung, Löschung) |
| `TrashController` | Papierkorb: Wiederherstellen/endgültig Löschen von Soft-Deletes |
| `ApiController` / `ApiKeyController` | JSON-API (`/api/horses`) und Selfservice-Verwaltung der API-Schlüssel (`/api-keys`) |
| `GroupController` | Gruppen-/Berechtigungsmatrix, 2FA-Pflicht je Gruppe |
| `PluginController` / `AddonStoreController` | Plugin-Verwaltung und Addon-Store unter `/admin/plugins` |
| `ImportController` | CSV-Massenimport von Pferden |
| `RegistrationController` / `EntraSsoController` | Selfservice-Registrierung und optionaler Entra-ID-Login |
| `CronController` | Zeitgesteuerte Aufgaben (Header-Secret, siehe Scheduler) |

Registrierte Cron-Aufgaben: `backup.external`, `digest.admin_editor`,
`update.check` und seit #358 `users.deactivate_dormant` (täglich) — sie
deaktiviert Konten, die länger als 180 Tage weder einen zweiten Faktor noch
eine E-Mail-Adresse führen, und verschont dabei das letzte aktive Admin-Konto.
Die Vorwarnung läuft 14 Tage vorher über den Digest an die Administratoren; der
Betroffene selbst ist definitionsgemäss nicht erreichbar.
| `UpdateController` | Auto-Update mit Pflicht-Backup und Kanalwahl |

Details zu den einzelnen Features (Merge-Tool, Pedigree-Aufbau, GDPR-Workflow
etc.) siehe Inline-PHPDoc der jeweiligen Methoden – die Kommentare im Code
sind bewusst ausführlich gehalten.

## Views (`src/Views/`)

- Reine PHP-Templates (kein Templating-Engine wie Twig), `<?= htmlspecialchars(...) ?>`
  wird durchgängig zur XSS-Vermeidung genutzt.
- `layout.php` ist der gemeinsame Rahmen (Navigation, Branding aus
  `settings`-Tabelle, Footer) – wird von `BaseController::render()` immer
  um die eigentliche View-Datei herum eingebunden.
- Naming-Konvention: `admin_*` für Seiten im Admin-Bereich, `public_*` für
  öffentliche Seiten, `auth_*`/`2fa_*` für Login/2FA-Flows, `error_*` für
  Fehlerseiten.
- `Helper\Markdown::parse()` wird für Freitextfelder (z. B. Pferdebeschreibung)
  genutzt: escaped zuerst HTML komplett, parst dann eine kleine Teilmenge von
  Markdown (Headings, Bold/Italic, Links, Listen, Absätze) – kein XSS-Risiko,
  da roher HTML-Input nie durchgereicht wird.

## Datenbank-Zugriff (`src/Database.php`)

- Singleton-PDO-Verbindung, `ATTR_ERRMODE = EXCEPTION`, `EMULATE_PREPARES = false`
  (echte Prepared Statements).
- Verbindungsaufbau: TCP/Unix-Socket je nach `DB_HOST`, optionaler SSL/TLS-Modus,
  Fallback auf bekannte lokale Unix-Sockets falls TCP fehlschlägt.
- **Kein klassisches Migrationssystem** (kein `up()`/`down()` pro Version):
  `Database::ensureSchemaUpToDate()` wird bei jedem ersten Verbindungsaufbau pro
  Request ausgeführt und legt fehlende Tabellen/Spalten idempotent per
  `CREATE TABLE IF NOT EXISTS` / `ADD COLUMN` an. Die Schritte selbst leben
  seit #230 in `App\Service\SchemaMigrator` und sind darüber auch explizit
  aufrufbar (`SchemaMigrator::run()`, z. B. nach dem Restore eines älteren
  Dumps — siehe [database.md](database.md#schema-migration-versioniert-idempotent)).
  Das hält Deployments auf Shared-Hosting ohne SSH-Zugriff einfach, bedeutet
  aber: Schema-Änderungen im Code müssen **zusätzlich** in
  `database/schema.sql` (Ersteinrichtung) UND in `SchemaMigrator`
  (Bestandsinstallationen) nachgezogen werden.

## Request-Flow im Detail

1. `public/index.php` registriert Autoloader, lädt `config/config.php`
   (DB-Konstanten, Security-Header, Session-Start).
2. Prüft `SetupController::needsSetup()` — falls keine DB-Config und kein
   Admin-Account existiert, Redirect auf `/setup`.
3. Router-Instanz wird mit allen Routen befüllt (siehe `public/index.php`
   für die vollständige Liste – Setup, Public, Auth/2FA/SSO, Registrierung,
   Admin, GDPR, Trash, User, Person, Breeding-Station, Horse, CSV-Import,
   Gruppen, API und API-Schlüssel, Plugins/Addon-Store, Plugin-Routen,
   Cron, Backups, Digest, Updates, Audit-Log).
4. `Router::dispatch()` matcht Pfad + Methode, instanziiert den Controller,
   ruft die Methode auf.
5. Controller lädt ggf. Daten aus der DB, ruft `$this->render($view, $data)`.
6. `layout.php` bindet den View-Content ein und liefert vollständiges HTML aus.

## Sicherheits-Header & Session (config/config.php)

Wird zentral beim Bootstrap gesetzt (nicht pro Controller) – siehe
[security.md](security.md) für Details zu CSP, Session-Cookie-Konfiguration
und Trusted-Proxy-Handling.

## Plugin-System (`src/Plugin/`, #56)

Erlaubt Zusatzfunktionalität, ohne Kern-Dateien zu ändern – siehe
[plugin-development.md](plugin-development.md) für die vollständige
Entwickler-Referenz (Manifest-Format, Hooks, Routen-Konvention,
Sicherheitsgrenzen) und [plugin-system-plan.md](plugin-system-plan.md) für
die zugrundeliegenden Architekturentscheidungen.

- `App\Plugin\PluginManager` (Singleton, gebootet in `public/index.php` vor
  der Routen-Registrierung): scannt `plugins/*/plugin.json`, validiert
  Manifeste, prüft Kompatibilität gegen `CORE_VERSION` und lädt nur zuvor
  über `/admin/plugins` aktivierte Plugins (Tabelle `plugins`, per
  `ensureSchemaUpToDate()`-Pattern wie der Rest des Schemas).
- `App\Plugin\HookManager`: Action-/Filter-Registry mit try/catch-Isolation
  pro Hook-Aufruf – ein fehlerhaftes Plugin bricht nie den gesamten Request
  ab. Zugriff aus Controllern über `BaseController::hooks()`.
- Definierte Erweiterungspunkte: `horse.before_save`/
  `horse.after_save` (`HorseController`), `horse.detail_sections`
  (`PublicController::horseDetail`, erhält zusätzlich den bereits
  berechneten Pedigree-Baum als vierten Filter-Parameter),
  `catalog.card_sections` (je gerenderter Katalogkarte,
  `PublicController::catalog`), `horse.edit_sections`
  (`HorseController::edit`, Admin-Gegenstück zu `horse.detail_sections`;
  rendert außerhalb des Kern-Formulars, damit Addons eigene Formulare mit
  eigener Berechtigungsprüfung mitbringen können), `admin.dashboard_tiles`
  (`AdminController::dashboard`). Vollständige Referenz samt Datenvertrag:
  [plugin-development.md](plugin-development.md).
- `App\Service\PedigreeBuilder`: rekursiver Pedigree-Baum-Aufbau, aus
  `PublicController` herausgelöst und für Plugins direkt mit eigener
  Generationstiefe aufrufbar (siehe
  [plugin-development.md](plugin-development.md), Abschnitt
  „Wiederverwendbarer Dienst“) — Voraussetzung u. a. für einen
  Inzuchtkoeffizienten-Rechner als Addon.
- Zusätzliche Plugin-Routen (optionale `routes()`-Methode je Plugin) werden
  zwingend unter `/plugin/<slug>/...` registriert – der Präfix wird vom
  `PluginManager` selbst vorangestellt, ein Plugin kann daher nie eine
  Kern-Route überschreiben.
- `plugins/` ist bewusst nicht Teil des Kern-Repositories (nur
  `plugins/.gitkeep` versioniert) – Plugins werden separat gepflegt, siehe
  Referenz-/Beispielplugin unter `docs/examples/demo-plugin/`.
- Optionale `permissions()`-Methode je Plugin registriert eigene Aktionen im
  Gruppen-/Berechtigungssystem (#66) – neue Aktion an einem bestehenden
  Modul (z. B. `horses`/`export`) oder komplett neues eigenes Modul, siehe
  `App\Permission\PermissionRegistry::registerAction()` und
  plugin-development.md → Abschnitt „Berechtigungen“.
- Eindeutige Kennung pro Plugin-Version (`installed_version`/`content_hash`
  in der Tabelle `plugins`, SHA-256-Fingerabdruck über den gesamten
  Plugin-Ordner): verhindert, dass unter demselben Slug ausgetauschter Code
  stillschweigend unter einer alten Freigabe weiterläuft. Reguläre Updates
  (neue Manifest-`version`) werden automatisch akzeptiert; gleiche Version
  mit abweichendem Code blockiert das Laden, bis ein Admin erneut freigibt
  – nicht-destruktiv (nie Datenverlust), siehe plugin-development.md →
  Abschnitt „Update-Erkennung“.

## Gruppen-/Berechtigungssystem (`src/Permission/`, `groups`/`user_groups`/`group_permissions`, #66)

Einziges Rechtesystem der App (das frühere `users.role` wurde vollständig
entfernt, siehe [user-groups-plan.md](user-groups-plan.md) für die
Architekturentscheidungen und deren spätere Ablösung). Granulare,
admin-konfigurierbare Rechtevergabe je Modul × Aktion (Erstellen/Bearbeiten/
Löschen/Veröffentlichen) plus ein fest verdrahteter Admin-Sonderfall.

- Drei feste (`is_builtin`) Gruppen: `admin` (hat systemseitig immer implizit
  ALLE Rechte, unabhängig vom Inhalt von `group_permissions` – ihre eigene
  Berechtigungs-Matrix bleibt deshalb leer und nicht editierbar, ist aber wie
  jede andere Gruppe regulär über `user_groups` zuweisbar, sonst könnte nie
  ein Administrator angelegt werden), `editor` (Komfort-Gruppe mit
  Standardrechten für die fachlichen CRUD-Bereiche, über die Admin-UI frei
  editierbar), `public` (die nicht angemeldeten Besucher – erhält
  serverseitig mehrfach unabhängig abgesichert niemals Zugriff auf das
  Backend, steuert aber über ihre Leseberechtigungen die **öffentliche
  Sichtbarkeit**: der Seed gibt ihr `horses.view` und
  `breeding_stations.view`, und `PublicController` gatet den Katalog, die
  Detailseiten und die Filterlisten darüber). **Security-by-Design:**
  Für angemeldete Benutzer ist Mitgliedschaft ausschließlich explizit über
  `user_groups` – einzig nicht angemeldete Besucher werden automatisch der
  Gast-Gruppe `public` zugeordnet (`GroupMembership::groupIds(null)`), denn
  ohne diese Zuordnung wäre die öffentliche Sichtbarkeit nicht über die
  Matrix steuerbar. Jede neu angelegte Gruppe startet ohne Rechte.
  `App\Permission\GroupMembership` bündelt "Gruppen-IDs eines Benutzers" und
  "ist Mitglied von `admin`" als einzige Quelle für beide Fragen (genutzt
  sowohl von `BaseController` als auch von Stellen ohne Controller-Instanz).
  Die Matrix-Bearbeitung ist nur für `admin` gesperrt
  (`GroupController::PROTECTED_PERMISSION_SLUGS = ['admin']`) – die Matrix
  von `public` ist bewusst editierbar, sie ist der Steuerungspunkt der
  öffentlichen Sichtbarkeit. Die Zuweisbarkeit an Benutzer regelt
  `GroupController::NON_ASSIGNABLE_SLUGS` (nur `public`). Verwaltung unter
  `/admin/groups` bzw. Gruppenzuordnung im Benutzer-Formular.
- `App\Permission\PermissionRegistry`: Katalog der verfügbaren Module/
  Aktionen – fester Kern-Anteil (`horses` inkl. `publish`, `persons`,
  `breeding_stations`) plus zur Laufzeit von aktivierten Plugins
  registrierte Ergänzungen (`registerAction()`, #56-Integration, "wer
  zuerst registriert, gewinnt" gegen Überschreiben). Bewusst als PHP-Array,
  keine DB-Katalogtabelle.
- `BaseController::hasPermission()`/`requirePermission()`: Prüfung fail-closed
  (fehlende Zuordnung oder DB-Fehler → Zugriff verweigert), Admin-Bypass über
  `isAdmin()`/`GroupMembership::isAdmin()`. Eingesetzt in
  `HorseController`/`ContactController` anstelle
  eines reinen `checkAuth()`.
- Benutzerverwaltung, Gruppenverwaltung selbst, DSGVO, System-/Mail-
  Einstellungen, Papierkorb-Vollzugriff und Plugin-Aktivierung bleiben
  bewusst weiterhin ausschließlich admin-only (`requireAdmin()`, geprüft über
  Mitgliedschaft in der Gruppe `admin`), nicht Teil der Modul-Tabelle.

## Mehrsprachigkeit / i18n (`src/I18n/`, `lang/`, #48)

Minimalistisches, Array-basiertes i18n-Gerüst statt `gettext` (passend zur
"keine externen Abhängigkeiten"-Philosophie) - Grundlage sowohl für den
mehrsprachigen öffentlichen Katalog als auch für #56/#66-Folgearbeiten wie
admin-konfigurierbare, gruppenspezifische Sichtbarkeit (#57).

- `App\I18n\Translator::t($key, $params, $domain)`: übersetzt einen flachen
  Schlüssel für die aktive Locale, mit Fallback auf Deutsch (Quellsprache)
  bei fehlender Übersetzung und auf den Schlüssel selbst bei komplett
  fehlendem Eintrag - macht Lücken in der UI sofort sichtbar statt sie
  stillschweigend zu verschlucken.
- Kern-Sprachdateien liegen unter `lang/<locale>.php` im Projekt-Root
  (Domain `core`, reserviert): `de` (Quellsprache) und elf weitere
  (`en`, `da`, `nl`, `fr`, `lb`, `it`, `cs`, `pl`, `nb`, `sv`, `fi`, #198).
  Jede Datei muss den vollständigen Schlüsselsatz aus `de.php` abdecken -
  `tests/Unit/I18n/LocaleCompletenessTest.php` erzwingt das für jede
  registrierte Locale.
- **Anschluss ans Plugin-System (#56):** Ein Plugin mit eigenem
  `lang/<locale>.php`-Verzeichnis wird beim Laden automatisch unter seinem
  Slug als eigene, kollisionsfreie Übersetzungs-Domain registriert
  (`Translator::registerDomain()`, "wer zuerst registriert, gewinnt") - reine
  Konvention, keine Manifest-Pflicht. Siehe
  [plugin-development.md](plugin-development.md), Abschnitt
  „Mehrsprachigkeit“, und das Referenz-Plugin unter
  `docs/examples/demo-plugin/`.
- Aktive Locale pro Request: `BaseController::initLocale()` liest die
  admin-konfigurierte Standardsprache (`settings.language`, Verwaltung unter
  `/admin/system-settings`) als Basis, überschreibbar für die laufende
  Session eines einzelnen Besuchers über `?lang=xx` (Sprachumschalter im
  Footer, `layout.php`).
- Deckt alle öffentlich (auch ohne Login) erreichbaren Seiten vollständig ab:
  Seiten-Grundgerüst (`layout.php`: Navigation, Footer), Startseite,
  das Verzeichnis samt Filtern und asynchroner AJAX-Ergebnisliste, Pferde- und
  Deckstation-Detailseiten inkl. Stammbaum, Impressum/Datenschutz/
  DSGVO-Anfrageformular sowie der nicht angemeldete Auth-Flow (Login,
  2FA-Verifikation, Passwort vergessen/zurücksetzen) und die Fehlerseiten
  403/404/500. Der Admin-Bereich (`/admin/...`, inkl. 2FA-/Passwort-Ersteinrichtung
  nach dem Login) bleibt bewusst deutsch - geringerer Nutzen (Vereins-Admins
  bedienen i. d. R. dieselbe Sprache), deutlich größerer Umfang; kann bei
  Bedarf schrittweise mit künftigen Feature-PRs ergänzt werden, nicht als
  einmaliger Komplett-Umbau.

## Cron-/Scheduler-Infrastruktur (`src/Service/Scheduler.php`, #67)

Grundlegende Registry für periodisch auszuführende Aufgaben - Voraussetzung
für spätere Kern-Features wie automatisierte externe Backups (#59) und einen
E-Mail-Digest für Admins/Editoren (#52). Es gibt bewusst KEINEN dauerhaft
laufenden PHP-Prozess (klassisches Request-Modell, siehe oben): "Cron"
bedeutet hier ausschließlich, dass ein von außen angestoßener HTTP-Request
beim Eintreffen prüft, welche registrierten Aufgaben fällig sind, und diese
synchron innerhalb dieses einen Requests ausführt.

- `App\Service\Scheduler::register($name, $intervalSeconds, $callback)`:
  registriert eine Aufgabe für die Dauer des aktuellen Requests (analog zu
  `App\Plugin\HookManager` - Callbacks müssen sich bei jedem Bootstrap neu
  registrieren, es gibt keinen dauerhaften In-Memory-Zustand zwischen
  Requests). `runDue()` führt alle fälligen Aufgaben aus, mit derselben
  try/catch-Isolation pro Aufgabe wie beim Hook-System (ein Fehler
  protokolliert im Audit-Log, blockiert aber nie die übrigen Aufgaben
  desselben Laufs).
- Persistenz des Zuletzt-ausgeführt-Zeitstempels je Aufgabe über die
  bestehende generische `settings`-Tabelle (Schlüssel
  `cron_last_run__<name>`), keine eigene Tabelle nötig.
- Zwei Auslösewege, beide letztlich `Scheduler::runDue()`:
  - **Extern:** `App\Controllers\CronController::run()` unter `/cron/run` -
    öffentlich erreichbar, aber durch ein admin-generiertes Secret
    geschützt (ausschließlich per `X-Cron-Secret`-Header,
    `hash_equals()`-Vergleich; ein Query-Parameter-Weg wurde entfernt, da
    Secrets im Query-String in Access-/Proxy-Logs landen, siehe #114).
    Bewusst ohne Admin-Login, da ein System-Cron keine Session mitbringen
    kann.
  - **Manuell:** `/admin/cron` (`AdminController::cronSettings()`/
    `runCronNow()`) zeigt registrierte Aufgaben samt letztem Lauf und
    erlaubt einen sofortigen manuellen Lauf - Alternative für Betreiber ohne
    Zugriff auf einen System-Cron.
- Verbraucher: `App\Service\BackupService` (#59, siehe unten),
  `App\Service\DigestService` (#52, siehe unten) und `App\Service\UpdateService`
  (#290: `update.check` alle 3 h meldet neu verfügbare Versionen per E-Mail,
  `update.auto_install` spielt sie höchstens einmal täglich ein - beides
  Opt-in, siehe unten).

## Automatisierte externe Backups (`src/Service/BackupService.php`, `src/Service/DatabaseDumper.php`, `src/Service/BackupTarget.php`, #59, #93)

Periodische Sicherung der Datenbank an ein externes Speicherziel - als
Kernfunktion, nicht als optionales Plugin, da die hier verwalteten
Zucht-/Blutlinien-Daten teils unwiederbringlich sind. Registriert sich bei
aktivierter/vollständiger Konfiguration selbst über `App\Service\Scheduler`
(siehe oben).

- `App\Service\DatabaseDumper`: reine-PHP-Alternative zu
  `mysqldump` (PDO-basiert, `SHOW CREATE TABLE` + `INSERT`-Anweisungen je
  Tabelle) - kein `mysqldump`-Client-Binary nötig, das im mitgelieferten
  Dockerfile nicht installiert ist und auf klassischem Webhosting oft fehlt
  oder per `shell_exec` gesperrt ist. Zwei APIs (#231): `dumpTo(callable
  $write)` streamt den Dump statement-/zeilenweise mit konstantem
  Speicherbedarf (Daten-SELECTs unbuffered, der Dump liegt nie als
  Gesamtstring im Speicher); `dump(): string` bleibt als dünner,
  byte-identischer Wrapper für Bestandsaufrufer erhalten.
- `App\Service\TarArchive` (#233): streamender ustar-Schreiber in reinem
  PHP (aus dem bewährten TarWriter des Addons `datenmigration` in den Kern
  übernommen) - schreibt Datei für Datei in 512-KiB-Chunks direkt in die
  Zieldatei (optional gzip via zlib), archiviert ausschließlich reguläre
  Dateien und trägt Pfade bis 255 Zeichen über das ustar-prefix-Feld.
  Hauptnutzer ist das optionale Uploads-Backup (siehe unten).
- `App\Service\BackupTarget`: gemeinsame Schnittstelle (`putObject`/
  `putObjectFromFile`/`deleteObject`/`listObjects`) für alle drei
  unterstützten Ziele (#93), damit `BackupService` unabhängig vom konkret
  gewählten Ziel arbeitet. `putObjectFromFile` (#237) lädt eine lokale Datei
  streamend hoch, ohne ihren Inhalt je als Gesamtstring zu laden - bei den
  HTTP-Zielen über `App\Service\HttpFileUpload` (roher TCP-/TLS-Socket, da
  der http-Stream-Wrapper keine Stream-Bodys unterstützt und curl bewusst
  nicht vorausgesetzt wird), bei FTPS über `ftp_fput()` mit Datei-Handle:
  - `App\Service\S3Client`: ein externer, S3-kompatibler Speicher (AWS S3,
    MinIO, Hetzner Object Storage o. Ä.). Signiert Anfragen selbst mit AWS
    Signature Version 4, ohne AWS-SDK/Composer-Laufzeitabhängigkeit. Nutzt
    wie `App\Service\Mailer` PHP-Streams statt der curl-Extension.
    Unterstützt Path-Style- (MinIO) und Virtual-Hosted-Style-URLs
    (AWS-Standard) sowie optional HTTP statt HTTPS (nur für
    selbstgehosteten Speicher in einem vertrauenswürdigen internen Netz
    gedacht).
  - `App\Service\WebDavClient`: ein WebDAV-Server, z. B. eine vereinseigene
    Nextcloud-/ownCloud-Instanz. Ebenfalls reine PHP-Streams, kein curl
    nötig. Legt den Zielordner bei Bedarf selbst per `MKCOL` an.
  - `App\Service\FtpsClient`: ein klassischer FTPS-Zugang (z. B. beim
    Hoster). Anders als die beiden anderen Ziele auf die PHP-`ftp`-Extension
    angewiesen (im mitgelieferten Dockerfile installiert), da sich das
    FTP-Protokoll nicht über PHP-Streams nachbilden lässt. Verbindet sich
    immer per TLS (`ftp_ssl_connect()`) - reines unverschlüsseltes FTP wird
    bewusst nicht angeboten.
- `App\Service\BackupService::run()`: Dump per `DatabaseDumper::dumpTo()`
  streamend in eine Temp-Datei schreiben, dabei mit `gzip` komprimieren
  (Fallback auf unkomprimiert, falls die zlib-Extension fehlt), über das
  konfigurierte `BackupTarget` streamend hochladen (`putObjectFromFile`,
  #237 - damit gilt der konstante Speicherbedarf über die gesamte
  Backup-Kette bis zum Ziel), anschließend Aufbewahrungsrotation
  anwenden (älteste Backups über dem konfigurierten Zähler löschen - ein
  Rotationsfehler zählt dabei bewusst NICHT als Fehlschlag des gesamten
  Laufs, da das eigentliche Backup zu diesem Zeitpunkt bereits sicher
  hochgeladen ist). Mit der Opt-in-Einstellung „Hochgeladene Dateien
  mitsichern" (`backup_include_uploads`, #233) wird zusätzlich ein
  tar(.gz)-Archiv von `public/uploads` **und `storage/horses`** ans selbe Ziel
  hochgeladen (die Pferdefotos liegen seit #366 dort; im Archiv stehen sie
  weiterhin unter `uploads/horses`)
  (`backups/uploads-<Zeitstempel>.tar.gz` neben
  `backups/backup-<Zeitstempel>.sql.gz`); die Rotation läuft getrennt je
  Backup-Art mit derselben Aufbewahrungsanzahl. Status des letzten Laufs
  (`backup_last_status`/`backup_last_run_at`/`backup_last_error`) wird in
  der `settings`-Tabelle für die Admin-Anzeige unter `/admin/backups`
  persistiert.
- Zielauswahl (S3/FTPS/WebDAV) sowie die jeweiligen Zugangsdaten, Intervall
  und Aufbewahrungsanzahl sind unter `/admin/backups` konfigurierbar
  (`AdminController::backupSettings()`/`updateBackupSettings()`/
  `testBackup()` für einen sofortigen manuellen Testlauf). Alle Passwörter/
  Secret Keys werden wie das SMTP-Passwort mit AES-256-GCM verschlüsselt
  gespeichert (`App\Security\Crypto`).
- Die Uploads-Sicherung ist bewusst **Opt-in** (Standard: aus): Die
  Datenbank ist der eigentlich unwiederbringliche Teil, das Uploads-Archiv
  kann je nach Bildbestand deutlich größer sein als der Dump.

## E-Mail-Digest für Admins/Editoren (`src/Service/DigestService.php`, #52)

Optionaler periodischer E-Mail-Digest an alle Admin-/Editor-Konten für
Ereignisse, die bisher keine sofortige Benachrichtigung auslösen -
DSGVO-Anfragen sind davon ausgenommen, da `Mailer::sendDsgvoNotification()`
dafür bereits sofort informiert. Registriert sich analog zu
`App\Service\BackupService` (siehe oben) bei Aktivierung selbst über
`App\Service\Scheduler`.

- `App\Service\MatchSuggestionFinder::findAll()`: die Blutlinien-Match-/
  Merge-Vorschlagslogik (Bewertungsalgorithmus samt Schwellenwerten), aus
  `HorseController::matches()`/`calculateSuggestions()` extrahiert -
  unverändertes Verhalten, reine Verschiebung, damit sowohl die Admin-
  Match-Seite als auch der Digest exakt dieselbe Anzahl offener Vorschläge
  sehen.
- Inhalt bewusst auf zwei Punkte beschränkt (siehe Issue): Anzahl offener
  Match-Vorschläge sowie Anzahl Papierkorb-Einträge, die sich der
  30-Tage-Löschfrist nähern (Warnfenster: 23-30 Tage seit `deleted_at`, bevor
  ab Tag 30 auch Editoren - nicht nur Admins - endgültig löschen dürfen,
  siehe `TrashController::permanentDelete()`). Meldet stets den aktuellen
  Stand zum Laufzeitpunkt, kein Delta "neu seit letztem Digest" - Match-
  Vorschläge sind reine Berechnung ohne eigenen Zeitstempel.
- Gibt es nichts zu berichten, wird bewusst **nichts versendet** (kein
  "alles ruhig"-Digest) - `run()` protokolliert diesen Fall dennoch als
  erfolgreichen Lauf (`digest_last_status`/`digest_last_run_at`/
  `digest_last_sent_count` in der `settings`-Tabelle).
- Empfänger: alle nicht gelöschten Benutzerkonten, die Mitglied der Gruppe
  `admin` oder `editor` sind (`user_groups`/`groups`, #66). Ein vollständiger
  Versandfehlschlag (keine Empfänger oder
  Versand an niemanden erfolgreich) wirft eine Exception, die der
  Scheduler zentral im Audit-Log protokolliert - ein NUR teilweiser
  Fehlschlag (einzelne Empfänger nicht erreichbar) gilt als Erfolg des
  Laufs.
- Aktivierung/Intervall unter `/admin/digest` konfigurierbar
  (`AdminController::digestSettings()`/`updateDigestSettings()`/
  `testDigest()` für einen sofortigen manuellen Testlauf).
