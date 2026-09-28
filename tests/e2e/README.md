# End-to-End-Nachtlauf

Ein „maximal echter" Integrationstest des Frameworks **mit allen Addons gegen
echte externe Dienste** — als Ergänzung zu den schnellen Unit-/Integration-/
Functional-Tests (die den Code netzwerkfrei abdecken). Gedacht für den lokalen
`devhost-tests`-Nachtlauf, der Fehler als selbst-schließendes GitHub-Issue
meldet.

## Was geprüft wird

Der Parcours (`parcours.py`, im Playwright-Image `unclecode/crawl4ai`) fährt
gegen eine frisch aus dem Repo-`Dockerfile` gebaute Instanz:

- Ersteinrichtung (Env-Provisionierung) inkl. 2FA
- **Addon-Store**: alle 15 Addons installiert (harte Gegenprüfung: real in
  `/admin/plugins`) und aktiviert
- Stammdaten über die UI, alle öffentlichen/Auth-/Admin-/Addon-Ansichten,
  Katalog-**Filter + Reset**, API mit Bearer-Key
- **Backups** an echte Ziele: S3 (MinIO), WebDAV (wsgidav), FTPS (pyftpdlib) —
  jeweils mit Gegenprüfung des Erfolgs-Resultats
- **Mailversand** über mailpit (STARTTLS), Cron
- **Update-Prüfung** (im Container ist In-Place aus, siehe #158 — geprüft wird,
  dass der Stand angezeigt wird)

**Passkeys nur auf Anforderung** (`PHASES=…,passkey`): Die Phase prüft
Step-up vor der Registrierung, die Registrierung mit einem virtuellen
Authenticator (CDP `WebAuthn.addVirtualAuthenticator`), den Step-up per
Passkey, neue Backup-Codes und den „2FA Reset“ eines Zweitkontos (Audit M15,
N10, N60). WebAuthn braucht einen sicheren Kontext; gegen `http://hvapp` des
Docker-Laufs überspringt sie sich deshalb selbst. Mit `MAILPIT_URL` prüft sie
zusätzlich die Hinweis-Mail. Sie läuft zuletzt, weil sie dem Admin einen
Passkey hinzufügt.

**Kein SSO**: Der OIDC-Code ist durch die Fake-IdP-Tests der Functional-Suite
abgedeckt; ein echtes Authentik wäre für ein Nacht-Gate zu schwer/fragil.

**Kein echter Mailserver**: mailpit statt des Host-Mailservers — der geschützte
Dienst wird nie angefasst (genau die Verkettung, die hier mal einen Ausfall
verursacht hat).

## Aufbau

| Skript | Rolle im `devhost-tests` | Verhalten bei Fehler |
|---|---|---|
| `spinup.sh` | `aufbau` | reine Umgebung hoch — Fehler ⇒ **nicht geprüft** |
| `run.sh` | `schritt` | Parcours + Assertions — echter Fehler ⇒ **rot** |
| `teardown.sh` | `abbau` | räumt immer alles ab |

Der wichtige Unterschied: Kommt die Umgebung nicht hoch (Container, Netz), ist
das **kein Ergebnis** (`aufbau`-Fehler ⇒ „nicht geprüft"). Nur wenn die App
sich falsch verhält, wird es rot. Sonderfall im `schritt`: schlägt es **nur**
wegen des GitHub-Rate-Limits (HTTP 403 beim Store/Update) fehl, ist das kein
App-Fehler — `run.sh` behandelt es als übersprungen (ein 403 von GitHub ist
eindeutig Rate-Limit). Bei einem einzelnen nächtlichen Lauf (~16 API-Anfragen
gegen das 60/h-Limit) tritt das praktisch nicht auf.

## Aktivieren im Nachtlauf

Ein eigener Abschnitt in `/etc/devhost-tests.conf` (bewusst getrennt von
`[Hengstverzeichnis_Framework]`, damit ein Umgebungsfehler des E2E nicht die
schnellen Tests als „nicht geprüft" markiert):

```ini
[Hengstverzeichnis_E2E]
herkunft = https://github.com/Celestial0579/Hengstverzeichnis_Framework.git
github   = Celestial0579/Hengstverzeichnis_Framework
aufbau   = TESTLAUF_NS="$TESTLAUF_NS" tests/e2e/spinup.sh
schritt E2E = TESTLAUF_NS="$TESTLAUF_NS" tests/e2e/run.sh
abbau    = TESTLAUF_NS="$TESTLAUF_NS" tests/e2e/teardown.sh
```

Alles Erzeugte trägt das Präfix `$TESTLAUF_NS-` (Container/Netz), fällt also
unter den `container:devhost-tests-*`, den der Nachtlauf bei `arbeit`
beansprucht.

## Manuell ausführen

```bash
export TESTLAUF_NS=e2e-manuell         # eigener Namensraum, vorher arbeit-claim
tests/e2e/spinup.sh && tests/e2e/run.sh ; tests/e2e/teardown.sh
```

Screenshots/Log liegen währenddessen unter `tests/e2e/artefakte/`.
