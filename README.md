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
| `TRUSTED_PROXIES`| –  | – (kein Proxy vertraut) | Kommagetrennte Liste vertrauenswürdiger Reverse-Proxy-IPs/-Netze, siehe unten |

Neuen `APP_KEY` generieren:
```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

Beispiel `.env` für Docker:
```env
DB_HOST=db
DB_PORT=3306
DB_NAME=hengstverzeichnis
DB_USER=hengst_user
DB_PASS=change-me
APP_KEY=<generierter 64-stelliger Hex-Wert>
APP_ENV=production
```

### Variante B: Ohne Umgebungsvariablen (Setup-Wizard, klassisches Webhosting)

Ohne gesetzte Umgebungsvariablen und ohne vorhandene `config/db_config.php` leitet die App beim ersten Aufruf automatisch auf `/setup` weiter. Dort werden DB-Zugangsdaten über ein Formular eingegeben; beim Absenden erzeugt die App `config/db_config.php` (inkl. eines frisch generierten `app_key`) und legt den ersten Admin-Account an.

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

Typische Werte:
- Reverse Proxy als eigener Container im selben Docker-Netzwerk: das Docker-Bridge-Subnetz (z. B. `172.16.0.0/12` für den Standard-Bridge-Bereich, oder das projektspezifische Subnetz — mit `docker network inspect` prüfen) oder die feste Container-IP
- Externer Reverse Proxy/Load Balancer: dessen öffentliche oder interne IP
- Cloudflare o. ä.: die vom Anbieter veröffentlichten IP-Bereiche

Betrifft: Client-IP in `AuditLogger`/`RateLimiter` (Login-Rate-Limiting, Audit-Log) sowie die HTTPS-Erkennung für sichere Session-Cookies und die automatische `APP_URL`-Ermittlung.

### Priorität & Rotation

Ist sowohl eine Umgebungsvariable als auch `config/db_config.php` vorhanden, gewinnt die Umgebungsvariable pro Einzelwert. Beim Rotieren von `DB_PASS` oder `APP_KEY`: Anwendung danach neu starten, damit die neuen Werte geladen werden. Ein neuer `APP_KEY` macht bereits verschlüsselte Werte (SMTP-Passwort, TOTP-Secrets) unlesbar — diese müssen anschließend neu gesetzt bzw. neu eingerichtet werden.
