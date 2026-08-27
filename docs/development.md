# Entwicklungsumgebung

## Voraussetzungen

Genau eine Laufzeit-Abhängigkeit seit v0.9.0 (`web-auth/webauthn-lib`, #353),
kein npm. `vendor/` liegt dem Release bei; wer entwickelt, holt es mit
`composer install`. Ansonsten gilt weiter –
das Framework läuft mit reinem PHP 8.5 + PDO MySQL-Erweiterung. Für lokale
Entwicklung entweder Docker (empfohlen) oder ein klassischer lokaler
PHP/MySQL-Stack. Composer wird ausschließlich **dev-only** für die
PHPUnit-Testsuite benötigt (siehe [Tests](#tests) unten) – für Betrieb/
Deployment der App selbst weiterhin nicht erforderlich.

## Schnellstart mit Docker

Empfohlener Weg — das Skript [`docker-start.sh`](../docker-start.sh) legt
beim ersten Aufruf automatisch eine `.env` aus `.env.example` an und
generiert `DB_PASS`, `DB_ROOT_PASS` und `APP_KEY`:

```bash
./docker-start.sh
```

Weitere Kommandos: `./docker-start.sh logs` (Logs des App-Containers),
`./docker-start.sh down` (Container stoppen). Ein erneuter Aufruf von
`./docker-start.sh` verwendet die bestehende `.env` weiter, statt sie zu
überschreiben.

<details>
<summary>Manuell, z. B. um Werte vorab selbst zu setzen</summary>

```bash
cp .env.example .env
```

`.env` ausfüllen (mindestens `DB_PASS` und `APP_KEY`, siehe Hauptteil der
[README.md](../README.md)):

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"   # für APP_KEY
```

```bash
docker compose up --build
```

</details>

Die App ist danach unter `http://localhost:8080` erreichbar. Beim ersten
Aufruf greift entweder der Setup-Wizard (`/setup`) oder – falls `SITE_NAME`
und `ADMIN_*`-Variablen in `.env` gesetzt sind – die vollautomatische
Ersteinrichtung (siehe README, Abschnitt „Ersteinrichtung ganz ohne Wizard“).

Aufbau von `docker-compose.yml`: ein `app`-Service (PHP 8.5 + Apache, Docroot
auf `public/`, siehe [Dockerfile](../Dockerfile)) und ein `db`-Service
(MariaDB 11) mit Healthcheck, sodass die App erst startet, wenn die DB bereit
ist. `public/uploads` liegt in einem eigenen Docker-Volume (`uploads_data`),
die Pferdefotos seit #366 in `horses_data` (`storage/horses`),
damit hochgeladene Pferdebilder Container-Neustarts überleben.

## Ohne Docker (lokaler PHP-Server)

```bash
php -S localhost:8000 -t public
```

Voraussetzung: lokale MySQL/MariaDB-Instanz, `config/db_config.php` (vom
Setup-Wizard erzeugt) oder entsprechende Umgebungsvariablen gesetzt. Ohne
DB-Konfiguration leitet die App automatisch auf `/setup` weiter.

## Datenbank-Hilfsskripte (CLI)

Diese Skripte laufen direkt gegen die konfigurierte Datenbank
(`config/config.php` wird eingebunden) und sind für lokale Entwicklung
gedacht – **nicht in Produktion ausführen**, ohne die Konsequenzen zu kennen:

| Skript | Zweck |
|---|---|
| `php database/migrate.php` | Dünner CLI-Wrapper um `App\Service\SchemaMigrator::run()` (#230): führt den vollständigen, idempotenten Schema-Migrationslauf aus und listet die durchgeführten Schritte auf. Im normalen Betrieb übernimmt das die App automatisch bei jedem Verbindungsaufbau; explizit relevant nach einem Restore/Import eines älteren Dumps (Reihenfolge Restore → Migration → App, siehe [database.md](database.md#schema-migration-versioniert-idempotent)) |
| `php database/seed.php` | Legt einen Test-Admin an (`admin@example.com` / `admin123`) oder setzt dessen Passwort zurück – **nur für lokale Entwicklung**, niemals in Produktion |
| `php database/reset.php` | **Destruktiv:** Leert alle Kern-Tabellen (`TRUNCATE`) und löscht `config/db_config.php`, sodass die App wieder im Setup-Modus startet. Nur für lokales Zurücksetzen des Entwicklungsstands gedacht |

## Tests

PHPUnit-Testsuite unter [`tests/`](../tests) (dev-only Composer-Abhängigkeit,
siehe [`composer.json`](../composer.json) – betrifft nicht die
Anwendungs-Runtime). Läuft bei jedem Push/PR gegen `main`
automatisch über [`.github/workflows/tests.yml`](../.github/workflows/tests.yml),
siehe [Issue #54](../../../issues/54). Die Suite ist streng konfiguriert:
`phpunit.xml` setzt `failOnDeprecation`/`failOnNotice`/`failOnWarning` —
eine Deprecation ist wie ein fehlgeschlagener Test zu behandeln, nicht zu
tolerieren. Drei Ebenen:

### `tests/Unit` – reine Logik, keine Abhängigkeiten

U. a. `Security\Totp`, `Security\Crypto`, `Security\ApiKey`,
`Security\OidcIdToken`, `Security\TrustedHost`,
`Security\ClientIp::isValidProxyEntry()`, `Helper\Markdown::parse()`,
`I18n\Translator`, `Plugin\HookManager` sowie die Service-Klassen
(S3-Signaturen, WebDAV-/FTPS-Clients, CSV-Import, Update-Auswahl). Läuft
ohne weitere Voraussetzungen:

```bash
composer install
composer test -- --testsuite Unit
```

### `tests/Integration` – `App\Database` direkt im PHPUnit-Prozess

Läuft gegen eine echte MariaDB-Testdatenbank: Schema-Aufbau
(`DatabaseTest`), Dump/Restore, externe Backup-Ziele, Digest, Scheduler,
Pedigree-Builder und die S3-/WebDAV-Clients (siehe `tests/Integration/`).
Braucht `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` als Umgebungsvariable (siehe
`tests/bootstrap.php`) – ohne diese wird die Suite übersprungen:

```bash
DB_HOST=127.0.0.1 DB_NAME=hengst_integration DB_USER=hengst DB_PASS=hengst \
  composer test -- --testsuite Integration
```

### `tests/Functional` – HTTP-Requests gegen eine echte, laufende Instanz

Inzwischen 27 Testklassen: Auth-Flow inkl. 2FA-Gruppenpflicht, TOTP-Replay
und Session-Invalidierung, CSRF-Schutz, Stamm-URL-SSRF-Härtung,
Blutlinien-Matching, JSON-API und API-Schlüssel, Gruppen-Berechtigungen,
Feature-Sichtbarkeit, Plugin-Verwaltung/Addon-Store, Plugin-Hook-Vertrag
(`HorseDetailSectionsHookTest`), CSV-Import, DSGVO-Verwaltung, Papierkorb,
Registrierung, Entra-SSO, Backups, Digest, Cron und Updates. **Nicht**
in-process testbar: praktisch jede
Controller-Aktion, die Formulardaten verarbeitet, endet mit
`header(...); exit;` – das würde den PHPUnit-Prozess selbst beenden. Die
Suite startet daher automatisch einen `php -S`-Subprozess (siehe
`tests/Support/PhpBuiltInServer.php`) und treibt ihn über einen minimalen
curl-basierten HTTP-Client (`tests/Support/HttpClient.php`) an – kein
Headless-Browser/WebDriver nötig, da die App serverseitig gerendertes PHP
ohne clientseitige JS-Logik ist. Die App-Instanz provisioniert sich beim
ersten Test selbst über die vollautomatische Ersteinrichtung (siehe README,
Abschnitt „Ersteinrichtung ganz ohne Wizard“) – zusätzlich zu `DB_*` werden
`APP_KEY`, `SITE_NAME`, `ADMIN_USERNAME`, `ADMIN_EMAIL` und `ADMIN_PASSWORD`
als Umgebungsvariable benötigt:

```bash
DB_HOST=127.0.0.1 DB_NAME=hengst_functional DB_USER=hengst DB_PASS=hengst \
  APP_KEY=lokaler-test-schluessel SITE_NAME="Testverband" \
  ADMIN_USERNAME=e2eadmin ADMIN_EMAIL=e2e@example.com ADMIN_PASSWORD=Test1234! \
  composer test -- --testsuite Functional
```

`Integration` und `Functional` brauchen **getrennte** Datenbanken (siehe
`.github/workflows/tests.yml`) – die Integration-Suite legt sich ihr eigenes
reduziertes Alt-Schema an, die Functional-Suite importiert `database/schema.sql`
automatisch über den Setup-Wizard.

Neue reine Logik (keine DB-/Session-/`$_SERVER`-Abhängigkeit) sollte nach
Möglichkeit mit einem Unit-Test unter `tests/Unit` begleitet werden; neue
Controller-Aktionen mit einem Functional-Test.

## Branches

Single-Branch-Modell: `main` ist der einzige lang laufende Branch, alle
Feature-/Fix-Arbeit passiert über PRs direkt gegen `main`. Der frühere
`beta`-Zwischenbranch (samt Merge-Source-Gate, monatlichem Promote und
wöchentlichem Beta-Release-Tag) wurde aufgelöst, weil Feature-Branches in
der Praxis regelmäßig vom (dann veralteten) `main`-Stand statt von `beta`
abgezweigt wurden und das laufend unnötige Merge-Konflikte beim Promote
verursacht hat.

- **`main`**: PRs brauchen grüne Pflicht-Checks (CodeQL, PHPUnit,
  Semgrep SAST, dependency-review), bevor gemergt werden kann — die
  Protection gilt mit `enforce_admins` auch für Administratoren; eine
  Review-Pflicht ist bewusst nicht konfiguriert (Ein-Personen-Projekt).
  Semgrep blockiert dabei nur bei ERROR-Severity-Funden, siehe
  [semgrep.yml](../.github/workflows/semgrep.yml). Daneben laufen
  nicht-blockierend `security-scan.yml` (DAST-Harness aus
  [`security/`](../security), siehe [security.md](security.md)),
  `scorecard.yml` und `dependabot-auto-merge.yml`. Quelle für die
  öffentlichen `latest`-Artefakte, siehe [releasing.md](releasing.md).

## Responsives Verhalten (#345)

**Geprüft wird bei 360 · 414 · 768 · 1024 · 1440 px, dazu Querformat auf dem
Telefon.** 360 px ist nicht die Ausnahme, sondern die schmalste verbreitete
Gerätebreite — was dort bricht, bricht für einen erheblichen Teil der
Besucher der öffentlichen Seiten.

### Die Umbruchpunkte stehen an einer Stelle

`public/css/style.css`, benannt und dort begründet:

| | | |
|---|---|---|
| schmal | `max-width: 480px` | Telefon, Hochformat |
| mittel | `max-width: 768px` | Telefon quer, kleines Tablet |
| breit | `max-width: 1024px` | Tablet quer |

CSS-Variablen taugen in einer Media-Query-Bedingung nicht — die Zahlen sind
deshalb Konvention, keine Variablen.

### Layout gehört in eine Klasse, nicht ins `style`-Attribut

**Ein Inline-Style kann keine Media Query tragen.** Was dort steht, gilt auf
jeder Bildschirmbreite gleich. Das war der eigentliche Befund der Prüfung: Es
fehlten nicht nur Umbruchpunkte, sondern die Stelle, an der sie hätten wirken
können — allein `admin_horse_form.php` hatte 113 `style`-Attribute.

Was auf jeder Breite gleich bleibt (eine Farbe, ein Abstand von `0.3rem`),
darf im Attribut stehen. Was sich mit der Breite ändern könnte, gehört in eine
Klasse:

| Klasse | wofür |
|---|---|
| `.tabelle-scroll` | Behälter um jede `<table>` — waagerechter Bildlauf statt gesprengter Seite |
| `.raster` | Raster, das sich selbst umbricht (`auto-fit`, min. 240 px) |
| `.raster-eng` | dasselbe für schmale Zellen (min. 150 px) |
| `.aktionen` | Reihe von Knöpfen, die umbricht statt überzulaufen |

### Zwei Regeln sind als Test festgehalten

`tests/Unit/Views/ResponsiveLintTest.php` prüft, dass jede View mit einer
Tabelle einen Bildlauf-Behälter hat und dass kein Raster feste Spalten zählt
(`1fr 1fr` heisst auf 360 px zwei Felder von je ~150 px).

Warum als Test und nicht als Notiz: Der vorgefundene Zustand ist nicht durch
eine falsche Entscheidung entstanden, sondern dadurch, dass niemand beim
Hinzufügen der siebzehnten Tabelle an die erste dachte. Eine Prüfliste im PR
hilft erst, wenn sie jemand liest; ein roter Lauf hilft immer.

## Coding-Konventionen

- **PHP 8.5**, `strict_types` wird aktuell nicht projektweit erzwungen –
  bei neuem Code an bestehendem Stil orientieren (Typed Properties/Return-Types
  werden aber durchgängig verwendet, siehe z. B. `Router.php`, `Database.php`).
- **Namespace `App\`** folgt PSR-4-artig der Verzeichnisstruktur unter `src/`.
- **Alle Controller** erben von `BaseController` und rufen im Konstruktor
  `parent::__construct()` sowie ggf. `$this->checkAuth()` /
  `$this->requireAdmin()` auf – siehe bestehende Controller als Vorlage.
- **Jede neue POST-Route**, die Daten ändert, muss das CSRF-Token prüfen
  (`\App\Router::verifyCsrfToken($_POST['csrf_token'] ?? '')`) – es gibt
  keine automatische Middleware dafür.
- **Datenbankzugriffe** ausschließlich über Prepared Statements (PDO),
  niemals String-Interpolation von Nutzereingaben in SQL.
- **Views**: Ausgabe von Nutzereingaben immer mit `htmlspecialchars()`
  escapen; für Freitext mit Formatierung `Helper\Markdown::parse()` nutzen,
  nie rohes HTML durchreichen.
- **Schema-Änderungen**: Neue Spalten/Tabellen müssen sowohl in
  `database/schema.sql` (Ersteinrichtung) als auch in
  `App\Service\SchemaMigrator` (Bestandsinstallationen, idempotent per
  `ADD COLUMN`/`CREATE TABLE IF NOT EXISTS`) ergänzt werden — und
  `SchemaMigrator::SCHEMA_VERSION` muss erhöht werden, sonst überspringt
  der versionierte Kurzschluss (#213) den neuen Schritt.
- **Sicherheitsrelevante/-datenändernde Aktionen** sollten über
  `\App\Service\AuditLogger::log($action, $category, $details)` protokolliert
  werden – siehe [security.md](security.md#audit-log) für bestehende
  Kategorien.
- Deutsche Kommentare/UI-Texte sind projektweit üblich (Zielgruppe: deutsche
  Zuchtverbände) – neuer Code sollte sich sprachlich daran orientieren.

## Deployment

Zwei unterstützte Wege, siehe README für Details:

1. **Docker/VPS** über Umgebungsvariablen (`docker-compose.yml` als Referenz,
   fertige Images unter `ghcr.io/celestial0579/hengstverzeichnis_framework`,
   siehe [releasing.md](releasing.md)).
2. **Klassisches Shared-Hosting** über den Setup-Wizard, der
   `config/db_config.php` schreibt (Docroot muss auf `public/` zeigen,
   `config/`, `public/uploads` und `storage/horses` müssen für den PHP-Prozess
   beschreibbar sein).
   Für Releases steht dafür ein bereinigtes Source-Zip ohne Dev-Tooling als
   Release-Asset bereit, siehe [releasing.md](releasing.md).

Bei Betrieb hinter einem Reverse Proxy/Load Balancer unbedingt
`TRUSTED_PROXIES` setzen (siehe [security.md](security.md#reverse-proxy--client-ip-erkennung)),
sonst werden Client-IP und HTTPS-Erkennung falsch ermittelt.
