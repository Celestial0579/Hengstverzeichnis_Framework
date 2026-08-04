# Hengstverzeichnis_Framework

**Status: 🧪 Beta** — funktional vollständig für den Kern-Usecase und intern getestet, aber noch nicht in großem Umfang im Produktivbetrieb erprobt. Feedback und Fehlermeldungen über [Issues](../../issues) sind ausdrücklich erwünscht. Sicherheitsrelevante Funde bitte **nicht** öffentlich melden, siehe [SECURITY.md](SECURITY.md).

Es wird versucht ein Open Source Framework für die Nachverfolgung von Blutlinien in der Pferdezucht zu erstellen. Ziel ist es ein Framework bereitzustellen, welches alle Usecases abdeckt, die für das IGF Hengstverzeichnis und vergleichbare Zuchtverzeichnisse nötig sind.

## Dokumentation

- **Entwicklerdokumentation** (Architektur, Datenmodell, Sicherheitskonzept, lokale Entwicklung): [`docs/`](docs/README.md)
- **Installation, Administration & Benutzerhandbuch**: [GitHub Wiki](../../wiki)
- **Änderungen zwischen Releases**: [CHANGELOG.md](CHANGELOG.md)

## Schnellstart (Docker)

```bash
git clone https://github.com/Celestial0579/Hengstverzeichnis_Framework.git
cd Hengstverzeichnis_Framework
./docker-start.sh
```

Das Skript legt beim ersten Aufruf automatisch eine `.env` an (inkl.
generiertem `DB_PASS`/`APP_KEY`) und startet die Container. Die App ist
danach unter `http://localhost:8080` erreichbar. Details, Alternativen ohne
Docker sowie die vollautomatische Ersteinrichtung siehe Abschnitt
[Konfiguration](#konfiguration) unten und [docs/development.md](docs/development.md).

## Bereits umgesetzt

- Öffentlich zugänglicher Hengstkatalog mit Suche, Filtern und Blutlinien-/Pedigree-Ansicht
- Pferde-, Personen- und Deckstationsverwaltung (CRUD) inkl. Papierkorb (Soft-Delete)
- Automatische Blutlinien-Verknüpfung mit Match-/Merge-Vorschlagswerkzeug
- Multiuserfähige Benutzerverwaltung mit Rollen (Admin/Editor)
- Verpflichtende 2FA (TOTP), Session-Hardening, Rate-Limiting, revisionssicheres Audit-Log
- DSGVO-Kontaktformular inkl. Verwaltung (Anonymisierung/Löschung) im Admin-Bereich
- Impressum & Datenschutzinformationen
- Docker-Deployment sowie klassisches Shared-Hosting über Setup-Wizard

## Bekannte Einschränkungen (Beta)

- Es gibt noch **keine automatisierte Testsuite** — Release-Verifikation erfolgt aktuell über manuelle/geskriptete Smoke-Tests, siehe [CHANGELOG.md](CHANGELOG.md).
- Es gibt noch **keine Test-/Build-CI**; Codequalität wird über Code-Review sowie automatisiertes SAST (Semgrep) und Dependency-Review in der CI sichergestellt (siehe [.github/workflows](.github/workflows)).

Weitere gewünschte, aber noch nicht umgesetzte Funktionen (z. B. EntraID SSO,
Klick-Tracking für Weblinks) werden als Feature-Requests in den
[Issues](../../issues) verwaltet.

Fehlt dir eine Funktion oder stößt du auf einen Bug? Bitte über [Issues](../../issues) melden.

## Konfiguration

Die Datenbankverbindung und sicherheitsrelevante Werte werden in [`config/config.php`](config/config.php) geladen. Es gibt zwei unterstützte Wege, die App zu konfigurieren — sie können auch gemischt werden, da Umgebungsvariablen immer Vorrang vor `config/db_config.php` haben.

### Variante A: Mit Umgebungsvariablen (empfohlen für Docker/VPS)

Diese Variante braucht keine `config/db_config.php` und funktioniert zuverlässig, solange die Variablen dem PHP-Prozess tatsächlich vererbt werden (z. B. über `docker run --env-file`, `docker-compose.yml` unter `env_file:`, oder eine systemd-Unit mit `Environment=`). Bei klassischem Shared-Hosting mit Apache + PHP-FPM funktioniert das oft **nicht zuverlässig** (siehe Hinweis unten) — dort eher Variante B nutzen.

| Variable        | Pflicht | Default            | Beschreibung |
|------------------|:---:|---------------------|--------------|
| `DB_HOST`        | –  | `127.0.0.1`          | DB-Host oder Unix-Socket-Pfad (beginnt mit `/`) |
| `DB_PORT`        | –  | `3306`               | DB-Port |
| `DB_NAME`        | –  | `hengstverzeichnis`  | Datenbankname |
| `DB_USER`        | ✅ | –                    | DB-Benutzername |
| `DB_PASS`        | ✅ | –                    | DB-Passwort |
| `DB_SSL`         | –  | `false`              | `true`/`1` aktiviert SSL/TLS zur DB |
| `DB_SSL_VERIFY`  | –  | `false`              | Server-Zertifikat der DB verifizieren |
| `DB_SSL_CA`      | –  | –                    | Pfad zur CA-Datei, falls `DB_SSL=true` |
| `APP_KEY`        | ✅ | –                    | 32-Byte-Hex-Schlüssel (AES-256-GCM) für verschlüsselte Werte (u. a. SMTP-Passwort, TOTP-Secrets) |
| `APP_URL`        | –  | dynamisch aus Request | Feste Basis-URL, falls automatische Erkennung nicht passt |
| `APP_ENV`        | –  | `development`         | `production` deaktiviert Fehlerausgaben im Browser |
| `TRUSTED_PROXIES`| –  | – (kein Proxy vertraut) | Kommagetrennte Liste vertrauenswürdiger Reverse-Proxy-IPs/-Netze, siehe unten |
| `TRACKING_DOMAINS`| – | – (kein Tracking) | Kommagetrennte Liste von `https://`-Origins (Matomo/Google Analytics o. Ä.), die in der Content-Security-Policy freigeschaltet werden. Alternative ohne Env-Var: Admin → Systemeinstellungen. |

Neuen `APP_KEY` generieren:
```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

#### Ersteinrichtung ganz ohne Wizard (optional)

Zusätzlich zu den DB-Variablen können folgende optionale Variablen gesetzt werden, um auch den ersten Admin-Account automatisch anzulegen:

| Variable | Pflicht | Beschreibung |
|---|:---:|---|
| `SITE_NAME` | – | Name des Verbands / der Seite |
| `ADMIN_USERNAME` | – | Benutzername des ersten Admin-Accounts |
| `ADMIN_EMAIL` | – | E-Mail-Adresse des ersten Admin-Accounts |
| `ADMIN_PASSWORD` | – | Passwort des ersten Admin-Accounts (mind. 8 Zeichen) |

Sind **alle vier** zusätzlich zur Datenbankverbindung gesetzt, wird der Setup-Wizard komplett übersprungen: Schema wird automatisch importiert, der Admin-Account angelegt, direkte Weiterleitung zu `/login`. Die 2FA-Pflicht bleibt bestehen — sie wird beim ersten Login des Admin-Accounts normal eingerichtet (Klarnamen/Passwort funktionieren erst danach vollständig).

Ist nur ein Teil dieser Variablen gesetzt (z. B. `SITE_NAME`, aber keine `ADMIN_*`-Variablen), zeigt der Wizard nur noch die Abschnitte an, die noch nicht über Env-Variablen feststehen — so kann z. B. nur noch der erste Admin-Account manuell angelegt werden, ohne versehentlich bereits korrekte DB-/Verbandseinstellungen zu überschreiben.

Beispiel `.env` für Docker (mit vollautomatischer Ersteinrichtung):
```env
DB_HOST=db
DB_PORT=3306
DB_NAME=hengstverzeichnis
DB_USER=hengst_user
DB_PASS=change-me
APP_KEY=<generierter 64-stelliger Hex-Wert>
APP_ENV=production

SITE_NAME=Mein Verband
ADMIN_USERNAME=admin
ADMIN_EMAIL=admin@example.org
ADMIN_PASSWORD=change-me-too
```

### Variante B: Ohne Umgebungsvariablen (Setup-Wizard, klassisches Webhosting)

Ohne gesetzte Umgebungsvariablen und ohne vorhandene `config/db_config.php` leitet die App beim ersten Aufruf automatisch auf `/setup` weiter. Dort werden DB-Zugangsdaten über ein Formular eingegeben; beim Absenden erzeugt die App `config/db_config.php` (inkl. eines frisch generierten `app_key` sowie `app_env => 'production'`, damit keine PHP-Fehlerdetails an Besucher ausgegeben werden) und legt den ersten Admin-Account an.

Voraussetzung: Der Webserver-Prozess braucht Schreibrechte auf den Ordner `config/`, sonst schlägt das Schreiben der Datei fehl.

`config/db_config.php` enthält Klartext-Secrets und ist bewusst in `.gitignore` eingetragen — sie darf **niemals** committet werden.

### Reverse Proxy & Client-IP-Erkennung

Läuft die App hinter einem Reverse Proxy oder Load Balancer (nginx, Traefik, Cloudflare, ein weiterer Docker-Container o. ä.), sieht PHP standardmäßig nur die IP des Proxys als `REMOTE_ADDR` — nicht die des echten Clients. Der Proxy meldet die echte Client-IP über `X-Forwarded-For` und das Protokoll (http/https) über `X-Forwarded-Proto`.

**Diese Header werden nur ausgewertet, wenn die unmittelbar verbindende Gegenstelle explizit über `TRUSTED_PROXIES` als vertrauenswürdig gelistet ist.** Ohne das würde jeder Client diese Header selbst gefälscht mitschicken können, um z. B. IP-basiertes Rate-Limiting beim Login zu umgehen oder Audit-Logs zu fälschen — deshalb ist der Default "kein Proxy vertraut", auch wenn ein `X-Forwarded-For`-Header ankommt.

```env
# Einzelne IP(s) und/oder CIDR-Netze, kommagetrennt
TRUSTED_PROXIES=10.0.0.5
TRUSTED_PROXIES=172.16.0.0/12,10.0.0.5
```

**Ohne zuverlässige Umgebungsvariablen** (klassisches Webhosting, siehe Variante B
oben, wo Env-Variablen oft nicht ankommen) lässt sich derselbe Wert alternativ
unter **Admin → Systemeinstellungen → „Vertrauenswürdige Reverse-Proxy-IPs"**
setzen — wird dann in `config/db_config.php` gespeichert. Eine gesetzte
Umgebungsvariable hat weiterhin immer Vorrang vor diesem Wert.

Typische Werte:
- Reverse Proxy als eigener Container im selben Docker-Netzwerk: das Docker-Bridge-Subnetz (z. B. `172.16.0.0/12` für den Standard-Bridge-Bereich, oder das projektspezifische Subnetz — mit `docker network inspect` prüfen) oder die feste Container-IP
- Externer Reverse Proxy/Load Balancer: dessen öffentliche oder interne IP
- Cloudflare o. ä.: die vom Anbieter veröffentlichten IP-Bereiche

Betrifft: Client-IP in `AuditLogger`/`RateLimiter` (Login-Rate-Limiting, Audit-Log) sowie die HTTPS-Erkennung für sichere Session-Cookies und die automatische `APP_URL`-Ermittlung.

### Priorität & Rotation

Ist sowohl eine Umgebungsvariable als auch `config/db_config.php` vorhanden, gewinnt die Umgebungsvariable pro Einzelwert. Beim Rotieren von `DB_PASS` oder `APP_KEY`: Anwendung danach neu starten, damit die neuen Werte geladen werden. Ein neuer `APP_KEY` macht bereits verschlüsselte Werte (SMTP-Passwort, TOTP-Secrets) unlesbar — diese müssen anschließend neu gesetzt bzw. neu eingerichtet werden.
