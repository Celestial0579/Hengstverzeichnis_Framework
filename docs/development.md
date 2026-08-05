# Entwicklungsumgebung

## Voraussetzungen

Keine Paketmanager-Abhängigkeiten zur Laufzeit (kein Composer, kein npm) –
das Framework läuft mit reinem PHP 8.3 + PDO MySQL-Erweiterung. Für lokale
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

Aufbau von `docker-compose.yml`: ein `app`-Service (PHP 8.3 + Apache, Docroot
auf `public/`, siehe [Dockerfile](../Dockerfile)) und ein `db`-Service
(MariaDB 11) mit Healthcheck, sodass die App erst startet, wenn die DB bereit
ist. `public/uploads` liegt in einem eigenen Docker-Volume (`uploads_data`),
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
| `php database/migrate.php` | Führt bekannte Spalten-Migrationen manuell/idempotent aus (Subset von `Database::ensureSchemaUpToDate()` – primär als Debug-/Standalone-Werkzeug relevant, im normalen Betrieb übernimmt das die App automatisch bei jedem Verbindungsaufbau) |
| `php database/seed.php` | Legt einen Test-Admin an (`admin@example.com` / `admin123`) oder setzt dessen Passwort zurück – **nur für lokale Entwicklung**, niemals in Produktion |
| `php database/reset.php` | **Destruktiv:** Leert alle Kern-Tabellen (`TRUNCATE`) und löscht `config/db_config.php`, sodass die App wieder im Setup-Modus startet. Nur für lokales Zurücksetzen des Entwicklungsstands gedacht |

## Tests

PHPUnit-Testsuite unter [`tests/`](../tests) (dev-only Composer-Abhängigkeit,
siehe [`composer.json`](../composer.json) – betrifft nicht die
Anwendungs-Runtime). Läuft bei jedem Push/PR gegen `main` oder `beta`
automatisch über [`.github/workflows/tests.yml`](../.github/workflows/tests.yml),
siehe [Issue #54](../../../issues/54). Drei Ebenen:

### `tests/Unit` – reine Logik, keine Abhängigkeiten

`Security\Totp`, `Security\Crypto`, `Security\ClientIp::isValidProxyEntry()`,
`Helper\Markdown::parse()`. Läuft ohne weitere Voraussetzungen:

```bash
composer install
composer test -- --testsuite Unit
```

### `tests/Integration` – `App\Database` direkt im PHPUnit-Prozess

Ruft `Database::getInstance()`/`ensureSchemaUpToDate()` gegen eine echte
MariaDB-Testdatenbank auf (siehe `tests/Integration/DatabaseTest.php`).
Braucht `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` als Umgebungsvariable (siehe
`tests/bootstrap.php`) – ohne diese wird die Suite übersprungen:

```bash
DB_HOST=127.0.0.1 DB_NAME=hengst_integration DB_USER=hengst DB_PASS=hengst \
  composer test -- --testsuite Integration
```

### `tests/Functional` – HTTP-Requests gegen eine echte, laufende Instanz

Deckt Auth-Flow, CSRF-Schutz, Stamm-URL-SSRF-Härtung und die
Blutlinien-Match-Logik ab. **Nicht** in-process testbar: praktisch jede
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

- **`main`**: stabiler Branch. PRs brauchen 1 Review + grüne Pflicht-Checks
  (CodeQL, PHPUnit, Semgrep SAST, Merge Source Gate) und werden manuell
  gemerged. Semgrep blockiert dabei nur bei ERROR-Severity-Funden, siehe
  [semgrep.yml](../.github/workflows/semgrep.yml). Quelle
  für die öffentlichen `latest`-Artefakte, siehe [releasing.md](releasing.md).
  Direkte Feature-/Fix-PRs nach `main` sind gesperrt – der
  [`Merge Source Gate`](../.github/workflows/merge-source-gate.yml)-Check
  lässt nur PRs vom `beta`-Branch (regulärer Promote-Weg) oder von
  Dependabot durch, alles andere muss zuerst nach `beta`.
- **`beta`**: laufender Entwicklungsstand, den die IGFjordpferd parallel zur
  bestehenden Umgebung testet. Gleiche Pflicht-Checks wie `main`, aber
  **kein** Pflicht-Review – PRs von Projektmitgliedern mergen automatisch
  (GitHub Auto-Merge), sobald die Checks grün sind. Neue Feature-/Fix-PRs
  zielen im laufenden Betrieb auf `beta`. Gegen Löschen geschützt (auch nach
  dem monatlichen Merge nach `main` bleibt `beta` bestehen und läuft weiter).
- **Promotion nach `main`**: einmal im Monat öffnet
  [`beta-promote.yml`](../.github/workflows/beta-promote.yml) automatisch
  eine PR `beta → main`; gemerged wird sie bewusst manuell (siehe Kommentar
  im Workflow). **Wichtig: Diese PR immer per „Create a merge commit"
  mergen, niemals per Squash.** Ein Squash-Merge kappt die gemeinsame
  Historie zwischen `beta` und `main` – der nächste Promote bekommt dann
  einen unnötigen (wenn auch harmlosen) Merge-Konflikt in allen seither auf
  `beta` geänderten Dateien, weil Git keinen gemeinsamen Vorfahren mehr
  findet.
- Beta-Releases für die IGFjordpferd werden als `vX.Y.Z-beta.N`-Tag von
  `beta` aus geschnitten – wöchentlich automatisch (falls es Änderungen
  gibt) über [`beta-release.yml`](../.github/workflows/beta-release.yml),
  siehe [releasing.md](releasing.md).

## Coding-Konventionen

- **PHP 8.3**, `strict_types` wird aktuell nicht projektweit erzwungen –
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
  `Database::ensureSchemaUpToDate()` (Bestandsinstallationen, idempotent per
  `ADD COLUMN`/`CREATE TABLE IF NOT EXISTS`) ergänzt werden.
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
   `config/` und `public/uploads` müssen für den PHP-Prozess beschreibbar sein).
   Für Releases steht dafür ein bereinigtes Source-Zip ohne Dev-Tooling als
   Release-Asset bereit, siehe [releasing.md](releasing.md).

Bei Betrieb hinter einem Reverse Proxy/Load Balancer unbedingt
`TRUSTED_PROXIES` setzen (siehe [security.md](security.md#reverse-proxy--client-ip-erkennung)),
sonst werden Client-IP und HTTPS-Erkennung falsch ermittelt.
