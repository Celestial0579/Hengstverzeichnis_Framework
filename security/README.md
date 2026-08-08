# Sicherheits-Scan (DAST) — Release-Gate

Dynamischer Sicherheitstest des laufenden Frameworks mit Kali-Werkzeugen, als
Ergänzung zu den statischen Prüfungen (PHPUnit, Semgrep). Gedacht als **Gate vor
jedem Release**: bricht ab, wenn blockierende Funde (CRIT/HIGH) auftreten.

Der Scan baut aus dem **aktuellen Stand des Repos** eine **eigene, ephemere
Instanz** (isolierter compose-Namensraum, Wegwerf-Datenbank, an eine host-interne
IP gebunden), scannt sie und räumt sie danach restlos wieder ab.

> **Kein echter Dienst wird angefasst.** Der Scan zielt nie auf eine laufende
> Installation, sondern startet dafür eine wegwerfbare Instanz. Das ist bewusst
> so: Ein Test, der ein echtes Ziel treffen *kann*, richtet genau dann Schaden
> an, wenn der Schutz davor versagt.

## Aufruf

```bash
# Bauen, starten, scannen, abräumen — der Normalfall:
security/run-security-scan.sh

# Nur bestimmte Checks:
security/run-security-scan.sh --only headers,exposed-paths,sqli

# Bereits laufende, autorisierte Testinstanz scannen (kein Bauen/Abräumen):
security/run-security-scan.sh --url http://127.0.0.1:8080

# MED-Funde ebenfalls als blockierend werten:
security/run-security-scan.sh --strict

# Instanz zum Nachschauen stehen lassen:
security/run-security-scan.sh --keep
```

Exit-Code: `0` = Gate bestanden, `2` = blockierende Funde, `1` = Aufruf-/Startfehler.

## Werkzeug-Umgebung (`--runner`, Autoerkennung)

Die Scan-Werkzeuge werden über eine Abstraktion aufgerufen (`lib/common.sh`),
damit derselbe Code auf dem Devhost, lokal und in CI läuft:

| Modus    | Wann                         | Wie Werkzeuge laufen                          |
|----------|------------------------------|-----------------------------------------------|
| `kali`   | Devhost (Befehl `kali` da)   | ephemere `sys-kali`-Container (`kali run …`)   |
| `local`  | Werkzeuge direkt im `PATH`   | direkt aufgerufen                              |
| `docker` | `SEC_RUNNER=docker`          | in `SEC_DOCKER_IMAGE` (Kali-Image)            |

Autoerkennung: `kali` bevorzugt, sonst `local`. Übersteuern mit `--runner` oder
`SEC_RUNNER`.

**Netzwerk:** Im `kali`/`docker`-Modus wird die Instanz an die Docker-Bridge-
Gateway-IP gebunden — von dort aus erreichen die (auf dem Bridge-Netz laufenden)
Scanner-Container die App, das LAN jedoch nicht. Im `local`-Modus an `127.0.0.1`.

## Was geprüft wird

| Check              | Werkzeug        | Sucht nach                                              | Gewicht |
|--------------------|-----------------|--------------------------------------------------------|---------|
| `headers`          | curl            | Security-Header, Cookie-Flags, Versions-Leaks          | MED/LOW |
| `exposed-paths`    | curl            | erreichbarer Quellcode/Config/`.git`/Uploads-Listing   | HIGH/MED|
| `open-ports`       | docker + nmap   | versehentlich veröffentlichte Ports (v. a. die DB)     | HIGH    |
| `fingerprint`      | whatweb, nmap, wafw00f | Software-/Versionspreisgabe                     | LOW     |
| `nikto`            | nikto           | Server-Fehlkonfiguration (nur Hinweis, blockiert nie)  | LOW     |
| `sqli`             | sqlmap          | SQL-Injection in öffentlichen Formularen/Parametern    | CRIT    |
| `content-discovery`| gobuster        | vergessene Backups/Dumps/Test-Endpunkte                | MED/INFO|

Die **deterministischen** Checks (headers, exposed-paths, open-ports) und `sqli`
bilden das harte Gate. `nikto` und `fingerprint` sind ergänzende Hinweise (LOW),
weil sie gegen eine App mit Catch-all-Routing zu Fehlalarmen neigen.

## Baseline / Allowlist

Damit das Gate stabil bleibt und nur *neue* Funde auffallen:

- `baseline/findings.allow` — bewusst akzeptierte Funde (`<check>|<titel>`-Muster).
  Ein Treffer zählt als „acknowledged“ und blockiert nicht. Nur für bewertete,
  dokumentierte Ausnahmen.
- `baseline/nikto.allow` — bekannte nikto-Rauschzeilen.
- `baseline/discovery.expected` — reguläre, erwartet erreichbare Routen.
- `baseline/sqli-targets.txt` — zusätzliche Parameter-URLs für sqlmap.

## Vor einem Release ausführen

Vor dem Tag (`vX.Y.Z`) auf dem Devhost, gegen den aktuellen Stand:

```bash
cd /pfad/zu/Hengstverzeichnis_Framework
git fetch origin && git checkout origin/main   # aktueller Stand
security/run-security-scan.sh
```

Bei Exit `0` ist das dynamische Gate bestanden. Ergänzend läuft der Scan in
GitHub Actions (`.github/workflows/security-scan.yml`, wöchentlich + manuell +
`workflow_call`). Um ihn als Pflicht-Gate an die Release-Pipeline zu hängen, in
`.github/workflows/release.yml` einen Job ergänzen:

```yaml
  security:
    uses: ./.github/workflows/security-scan.yml
  release:
    needs: [tests, security]   # 'security' hier ergänzen
```

## Abhängigkeiten

`docker` + `docker compose`, `curl`, `openssl` (Fallback vorhanden) und die
Scan-Werkzeuge in der gewählten Umgebung. Fehlt ein Werkzeug, überspringt der
betreffende Check sich selbst (INFO), statt den Lauf abzubrechen.
