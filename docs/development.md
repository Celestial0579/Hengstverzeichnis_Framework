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
Anwendungs-Runtime). Läuft bei jedem Push/PR gegen `main` automatisch über
[`.github/workflows/tests.yml`](../.github/workflows/tests.yml).

```bash
composer install
composer test
```

Aktuell abgedeckt: `tests/Unit` – reine Logik-Tests ohne Datenbank
(`Security\Totp`, `Security\Crypto`, `Security\ClientIp::isValidProxyEntry()`,
`Helper\Markdown::parse()`). `tests/Integration` ist als zweite Ebene für
DB-gestützte Tests (Auth-Flow, CSRF, Blutlinien-Match-Logik,
`Database::ensureSchemaUpToDate()`) vorgesehen, siehe
[Issue #54](../../../issues/54) – benötigt einen MySQL-Service im
CI-Workflow und ist noch nicht implementiert.

Neue reine Logik (keine DB-/Session-/`$_SERVER`-Abhängigkeit) sollte nach
Möglichkeit mit einem Unit-Test unter `tests/Unit` begleitet werden.

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

1. **Docker/VPS** über Umgebungsvariablen (`docker-compose.yml` als Referenz).
2. **Klassisches Shared-Hosting** über den Setup-Wizard, der
   `config/db_config.php` schreibt (Docroot muss auf `public/` zeigen,
   `config/` und `public/uploads` müssen für den PHP-Prozess beschreibbar sein).

Bei Betrieb hinter einem Reverse Proxy/Load Balancer unbedingt
`TRUSTED_PROXIES` setzen (siehe [security.md](security.md#reverse-proxy--client-ip-erkennung)),
sonst werden Client-IP und HTTPS-Erkennung falsch ermittelt.
