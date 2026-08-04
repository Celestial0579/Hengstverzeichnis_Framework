# Hengstverzeichnis_Framework

Es wird versucht ein Open Source Framework für die Nachverfolgung von Blutlinien in der Pferdezucht zu erstellen. Ziel ist es ein Framework bereitzustellen, welches alle Usecases abdeckt, die für das IGF Hengstverzeichnis und vergleichbare Zuchtverzeichnisse nötig sind

Anforderungen an die Entwicklung:
- EntraID SSO
- Trackingfähigkeit für Weblinks

Breits umgesetzt:
- Öffentlich zugängliches Frontend
- Datenbank MySQL
- HTML5, CSS, PHP
- Multiuserfähigkeit
- Formular für DSGVO anfragen
- Impressum
- Datenschutz Informationen

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

Ohne gesetzte Umgebungsvariablen und ohne vorhandene `config/db_config.php` leitet die App beim ersten Aufruf automatisch auf `/setup` weiter. Dort werden DB-Zugangsdaten über ein Formular eingegeben; beim Absenden erzeugt die App `config/db_config.php` (inkl. eines frisch generierten `app_key`) und legt den ersten Admin-Account an.

Voraussetzung: Der Webserver-Prozess braucht Schreibrechte auf den Ordner `config/`, sonst schlägt das Schreiben der Datei fehl.

`config/db_config.php` enthält Klartext-Secrets und ist bewusst in `.gitignore` eingetragen — sie darf **niemals** committet werden.

### Priorität & Rotation

Ist sowohl eine Umgebungsvariable als auch `config/db_config.php` vorhanden, gewinnt die Umgebungsvariable pro Einzelwert. Beim Rotieren von `DB_PASS` oder `APP_KEY`: Anwendung danach neu starten, damit die neuen Werte geladen werden. Ein neuer `APP_KEY` macht bereits verschlüsselte Werte (SMTP-Passwort, TOTP-Secrets) unlesbar — diese müssen anschließend neu gesetzt bzw. neu eingerichtet werden.
