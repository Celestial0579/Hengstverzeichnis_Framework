# Sicherheitskonzept

Das Framework verwaltet personenbezogene Daten (Züchter, Besitzer) und
unterliegt daher besonderen Sorgfaltspflichten. Dieses Dokument beschreibt
die implementierten Schutzmaßnahmen auf Code-Ebene.

## Authentifizierung & Sessions

- **Passwort-Hashing:** `password_hash()` mit `PASSWORD_DEFAULT` (bcrypt).
- **2FA-Pflicht pro Gruppe konfigurierbar (#84):** TOTP-2FA
  (`src/Security/Totp.php`, RFC-6238-kompatibel, 30s-Zeitfenster, ±1 Fenster
  Toleranz; Setup erzeugt einen `otpauth://`-Link mit QR-Code via
  `public/js/qrcode.js` sowie 10 Einmal-Backup-Codes). Ob das Setup beim
  Login erzwungen wird, steuert `groups.require_2fa` pro Gruppe (Default:
  verpflichtend). Fest verdrahtete Ausnahmen: Mitglieder der Gruppe `admin`
  brauchen 2FA **immer** (nicht abschaltbar), Benutzer ganz ohne Gruppen
  ebenfalls (fail-safe). Ein Benutzer braucht 2FA, sobald mindestens eine
  seiner Gruppen sie verlangt - ohne Bestandsschutz: Wird die Pflicht
  nachträglich aktiviert, greift sie beim nächsten Login. Bereits
  aktivierte 2FA bleibt unabhängig von der Gruppen-Einstellung aktiv.
- **Step-up-Reauth für 2FA-Änderungen (#112):** Ist 2FA bereits aktiv, kann
  eine Session die Konfiguration (neues Secret, neue Backup-Codes) nur nach
  erneuter Bestätigung von Passwort UND aktuellem TOTP-Code ändern
  (`/2fa/reauth`, Freischaltung 10 Minuten gültig). Secret und Backup-Codes
  entstehen ausschließlich serverseitig und liegen bis zur Bestätigung in der
  Session - POST-Werte des Clients werden ignoriert.
- **TOTP-Replay-Schutz (#111):** Jeder erfolgreich verwendete Code verbraucht
  seinen 30s-Zeitschlitz (`users.last_totp_timeslice`);
  `Totp::verifyCodeReturnSlice()` lehnt bereits verbrauchte und ältere
  Schlitze auch bei korrektem Code ab. Ein abgefangener/geschulterter Code
  ist damit single-use statt ~90 s lang wiederverwendbar. Beim Admin-2FA-Reset
  wird der Merker mit zurückgesetzt.
- **Session-Hardening** (`config/config.php` + `BaseController::checkAuth()`):
  - `session.use_strict_mode`, `use_only_cookies`, `cookie_httponly`,
    `cookie_samesite=Lax`, In-Memory-Cookie (`cookie_lifetime=0`).
  - Bei HTTPS: `__Host-`-Cookie-Präfix (erzwingt `Secure`, `Path=/`, keine
    `Domain`) statt normalem Session-Namen.
  - **Anti-Session-Hijacking:** User-Agent-Hash wird bei Login in der Session
    gespeichert und bei jedem Request verglichen; weicht er ab, wird die
    Session sofort zerstört, der Vorfall im Audit-Log protokolliert und der
    Nutzer zum Login mit `?error=session_hijacked` geleitet.
  - **Inaktivitäts-Timeout:** 2 Stunden (7200s), danach automatischer Logout.
  - **Session-ID-Rotation:** alle 15 Minuten (`session_regenerate_id(true)`),
    reduziert das Fenster für Session-Fixation-Angriffe.
  - **Erzwungene Passwortänderung:** `must_change_password`-Flag blockiert
    alle Routen außer `/force-password-change` und `/logout`.
  - **Session-Invalidierung bei Passwortänderung (#113):** `users.session_version`
    wird bei jeder Passwortänderung (Reset per Mail-Token, erzwungener Wechsel,
    Admin-Änderung) erhöht; `checkAuth()` vergleicht den beim Login in der
    Session abgelegten Stand bei jedem Request und beendet Sessions mit
    veraltetem Wert. Eine von einem Angreifer gehaltene Alt-Session überlebt
    den Passwort-Reset des Opfers damit nicht. Die Session, die die Änderung
    selbst ausgelöst hat, übernimmt den neuen Stand und bleibt angemeldet.

## CSRF-Schutz

`Router::generateCsrfToken()`/`verifyCsrfToken()`: ein 32-Byte-Zufallstoken
pro Session, `hash_equals()` beim Vergleich (Timing-Angriff-resistent). **Jede**
zustandsändernde POST-Route prüft das Token manuell am Anfang der Methode
(`if (!\App\Router::verifyCsrfToken(...)) { $this->renderForbidden(...); }`) –
es gibt keine globale Middleware, das Pattern muss bei neuen POST-Routen
konsequent übernommen werden.

## Autorisierung

Einziges Rechtesystem: Gruppen (`groups`/`user_groups`/`group_permissions`,
#66). Für angemeldete Benutzer ist Mitgliedschaft ausschließlich explizit
über `user_groups` (kein `users.role` mehr); die einzige implizite Zuordnung
ist die Gast-Gruppe `public`, der jeder nicht angemeldete Besucher
automatisch angehört (`GroupMembership::groupIds(null)`).
`BaseController::requireAdmin()`/`isAdmin()` prüfen Mitgliedschaft in der
eingebauten Gruppe `admin` (via `App\Permission\GroupMembership`) und schützen
so die Admin-only-Bereiche (Benutzerverwaltung, Gruppenverwaltung,
Systemeinstellungen, Mail-Konfiguration, System-Reset, DSGVO-Verwaltung,
Papierkorb-Vollzugriff) – Mitglieder haben systemseitig immer alle Rechte,
unabhängig vom Inhalt von `group_permissions`. Granulare CRUD-Rechte auf die
fachlichen Bereiche (Pferde, Personen, Deckstationen) regelt
`BaseController::hasPermission()`/`requirePermission()` über
`App\Permission\PermissionRegistry` und die je Gruppe frei konfigurierbare
Berechtigungsmatrix (`/admin/groups`) mit eingeschränkten Papierkorb-Rechten
für Nicht-Admins (siehe [database.md](database.md#soft-delete--papierkorb)).

**Öffentliche Sichtbarkeit** ist die zweite Funktion desselben Systems
(#121/#122/#151): Was Gäste sehen, steuern die Leseberechtigungen der
Gast-Gruppe (`horses.view`, `breeding_stations.view` — per Seed vergeben,
über die Matrix entziehbar) **in Kombination** mit dem
`is_published`-Flag der Datensätze (Default: unveröffentlicht; setzen
erfordert das `publish`-Recht). `PublicController` und `ApiController`
erzwingen beides durchgängig — bis hinein in verknüpfte Datensätze: Namen
und Kontaktdaten unveröffentlichter Personen/Stationen erscheinen weder auf
Detailseiten noch in Filterlisten, der öffentliche Pedigree-Baum zeigt
unveröffentlichte Vorfahren nur als Platzhalter, und die an Plugins
übergebenen Hook-Daten sind bereits gefiltert (siehe
[plugin-development.md](plugin-development.md)).

**API-Schlüssel** (`src/Security/ApiKey.php`, [api.md](api.md)) sind eine
eigene, session-unabhängige Auth-Fläche: max. 5 je Benutzer, gespeichert
nur als SHA-256-Hash, effektive Rechte stets die **Schnittmenge** aus den
aktuellen Rechten des Besitzers und dem Scope des Schlüssels — ein
Schlüssel kann nie mehr als sein Besitzer, und Rechteverlust wirkt sofort.

## Brute-Force-Schutz (`src/Security/RateLimiter.php`)

Datenbankgestützter Zähler fehlgeschlagener Versuche pro `identifier` + `type`
(`login`, `login_ip`, `2fa`, `backup`, `password_reset`, `registration`,
`dsgvo_request`) in einem Zeitfenster (Default: 5
Versuche / 15 Min). Bei DB-Fehlern **fail-open** (blockiert nicht) – bewusste
Ausfallsicherheits-Entscheidung, damit ein DB-Problem nicht versehentlich alle
Logins sperrt.

Der Login nutzt zwei getrennte Zähler (#115): Der Konto-Zähler ist an die
Client-IP gekoppelt (`email|ip`, 5 Versuche), damit gezielte Fehlversuche
eines Angreifers keine bekannten E-Mail-Adressen global aussperren können
(Account-Lockout-DoS); ein zusätzlicher reiner IP-Zähler (`login_ip`, 20
Versuche) bremst Passwort-Spraying über viele Konten von derselben Adresse.

## Verschlüsselung sensibler Werte (`src/Security/Crypto.php`)

AES-256-GCM (authenticated encryption) für Werte, die zwar in der DB
gespeichert, aber wieder im Klartext benötigt werden: das SMTP-Passwort
sowie sämtliche Backup-Ziel-Zugangsdaten (S3 Secret Key, WebDAV-/
FTPS-Passwort) in `settings`. Schlüssel wird aus `APP_KEY`
(32-Byte-Hex-Env-Variable) per
SHA-256 abgeleitet. **Rotation von `APP_KEY` macht bestehende verschlüsselte
Werte unlesbar** – siehe README, Abschnitt „Priorität & Rotation“.

TOTP-Secrets (`users.totp_secret`) werden ebenfalls über `Crypto::encrypt()`
verschlüsselt abgelegt (mit Fallback auf unverschlüsseltes Lesen für
Altbestände, falls vor Einführung der Verschlüsselung angelegt). Backup-Codes
(`users.backup_codes`) werden dagegen **gehasht** (`password_hash()`, wie
Passwörter) und beim Verbrauch aus dem Array entfernt – sie sind also
Single-Use und selbst bei DB-Zugriff nicht im Klartext einsehbar.

## Reverse-Proxy- & Client-IP-Erkennung (`src/Security/ClientIp.php`)

`X-Forwarded-For`/`X-Forwarded-Proto` werden **nur** ausgewertet, wenn die
unmittelbar verbindende Gegenstelle (`REMOTE_ADDR`) über `TRUSTED_PROXIES`
(IPs/CIDR, kommagetrennt) als vertrauenswürdig gelistet ist.
Ohne diese Konfiguration wird immer `REMOTE_ADDR` verwendet – verhindert
IP-Spoofing über gefälschte Header, die sonst Rate-Limiting und Audit-Log
unterlaufen könnten. Betrifft auch die HTTPS-Erkennung für sichere
Session-Cookies. Details siehe [README.md](../README.md#reverse-proxy--client-ip-erkennung).

`TRUSTED_PROXIES` lässt sich sowohl per Umgebungsvariable setzen (Vorrang)
als auch – für Deployments ohne zuverlässige Env-Var-Weitergabe, z. B.
klassisches Webhosting – über **Admin → Systemeinstellungen** im Browser
konfigurieren (`AdminController::updateSystemSettings()`, validiert über
`ClientIp::isValidProxyEntry()`, gespeichert in `config/db_config.php` über
`SetupController::writeDbConfigValue()`). `config/config.php` löst die
Konstante `TRUSTED_PROXIES` aus Env-Variable **oder** `db_config.php` auf,
bevor `ClientIp` sie liest.

### `APP_ENV`-Default

Existiert `config/db_config.php` (App wurde über den Setup-Wizard
eingerichtet), gilt ohne explizite `APP_ENV`-Angabe automatisch `production`
(keine PHP-Fehlerdetails an Besucher). Nur ein komplett unkonfigurierter
Checkout ohne Env-Variable und ohne `db_config.php` gilt als lokale
Entwicklungsumgebung (`development`, Fehler werden angezeigt).

## Security-Header & CSP (`config/config.php`)

Global gesetzt (nicht optional pro Route): `X-Content-Type-Options: nosniff`,
`X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`,
`Permissions-Policy` (Kamera/Mikro/Standort deaktiviert), sowie eine
`Content-Security-Policy`. `X-XSS-Protection` steht im Apache-Deployment per
`public/.htaccess` auf `0` (OWASP-Empfehlung — der veraltete Browser-Filter
kann selbst Lücken reißen, der Schutz kommt aus der CSP); der PHP-seitige
Header in `config/config.php` sendet derzeit noch den Altwert
`1; mode=block`, den Apache überschreibt — eine bekannte, noch anzugleichende
Doppelung. Die CSP erlaubt neben `'self'` gezielt
`https://fonts.googleapis.com` (`style-src`) und `https://fonts.gstatic.com`
(`font-src`). `'unsafe-inline'` bei `script-src`/`style-src` ist
aktuell nötig, da Views durchgehend `onclick=`/inline `style=` nutzen (kein
Nonce-/Hash-Setup) – `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`,
`frame-ancestors 'self'` bieten trotzdem echten Zusatzschutz.

**Tracking-Domains** (`TRACKING_DOMAINS`, siehe README): Admin → Systemeinstellungen
erlaubt das Freischalten externer `https://`-Origins (z. B. Matomo/Google Analytics)
in `script-src`/`img-src`/`connect-src`, damit ein dort konfiguriertes Tracking-Snippet
(`tracking_code`-Setting, wird unescaped vor `</head>` in `layout.php` ausgegeben)
funktioniert. Ohne konfigurierte Domain bleibt die Policy unverändert streng – die
Lockerung ist opt-in und nur admin-auslösbar (`requireAdmin()`), jeder Eintrag wird
vor der Übernahme in den CSP-Header gegen eine strikte `https://host(:port)`-Regex
validiert (keine Pfade, keine Sonderzeichen), um CSP-Header-Injection über einen
korrupten Konfigurationswert auszuschließen.

## XSS-Schutz

Views nutzen konsequent `htmlspecialchars()` bei Ausgabe von Nutzereingaben.
Freitext mit einfacher Formatierung (z. B. Pferdebeschreibung) läuft über
`Helper\Markdown::parse()`, welches **zuerst** den kompletten Input escaped
und danach nur eine kontrollierte Teilmenge von Markdown-Syntax in HTML
umwandelt – roher HTML-Input eines Nutzers kann nie durchgereicht werden.

## Audit-Log (`src/Service/AuditLogger.php`)

Schreibt in `audit_logs` (siehe [database.md](database.md#audit_logs)) bei
praktisch jeder sicherheits-/datenrelevanten Aktion: Login/Logout, 2FA-Events,
403-Zugriffsverweigerungen, CRUD auf Pferde/Personen/Deckstationen/Benutzer,
Einstellungsänderungen, E-Mail-Versand, Papierkorb-Aktionen, automatische
Blutlinien-Zusammenführungen, Plugin-Aktivierung/-Deaktivierung (Kategorie
`plugin`) und API-Schlüssel-Ereignisse (Kategorie `security`). Ausfallsicher:
schlägt das DB-Insert fehl (z. B.
Tabelle noch nicht vorhanden), wird stattdessen nach `storage/logs/audit_errors.log`
geschrieben (mit automatischer Rotation bei > 5 MB oder > 30 Tagen).

## E-Mail-Versand (`src/Service/Mailer.php`)

Eigener minimaler SMTP-Client (kein PHPMailer/Symfony-Mailer-Abhängigkeit).
**Unverschlüsselter SMTP-Versand ist hart verboten** (`smtp_encryption` muss
`ssl` oder `tls` sein, sonst wird der Versand abgelehnt und geloggt).
Zertifikatsprüfung (`verify_peer`/`verify_peer_name`) ist aktiv,
selbstsignierte Zertifikate werden abgelehnt. STARTTLS erzwingt TLS 1.2/1.3.

## Host-Header-Validierung (`src/Security/TrustedHost.php`)

Absolute URLs in ausgehenden Mails (u. a. der Passwort-Reset-Link) entstehen
bevorzugt aus `settings.base_url` bzw. der Umgebungsvariable `APP_URL`. Nur
wenn beides fehlt, wird auf den vom Client mitgeschickten `Host:`-Header
zurückgegriffen — und dieser ist Angreifer-kontrolliert (Reset-Link-Poisoning,
siehe #116). `TrustedHost::resolve()` validiert den Header daher syntaktisch
(Hostname/IP-Literal, optional `:Port`, keine Sonderzeichen) und prüft ihn
zusätzlich gegen die optionale Allowlist `TRUSTED_HOSTS` (kommagetrennte
Hostnamen; führender Punkt = beliebige Subdomain, z. B. `.example.org`;
Konfiguration per Umgebungsvariable oder `db_config.php`, analog
`TRUSTED_PROXIES`). Wird der Header verworfen, fällt die URL-Erzeugung auf
einen neutralen Platzhalter zurück statt auf den Angreifer-Wert.
**Empfehlung:** In Produktion immer `base_url` (Admin → Systemeinstellungen)
oder `APP_URL` setzen — dann wird der Host-Header gar nicht erst befragt.

## EntraID-SSO (#42, `src/Controllers/EntraSsoController.php`)

Optionaler Microsoft Entra ID (Azure AD)-Login per OIDC
Authorization-Code-Flow als **zusätzliche** Login-Methode neben dem lokalen
Login. Strikt opt-in: Ohne vollständige Konfiguration (`ENTRA_TENANT_ID`,
`ENTRA_CLIENT_ID`, `ENTRA_CLIENT_SECRET` — Umgebungsvariable oder
`db_config.php`, analog `TRUSTED_PROXIES`) sind die Routen `/auth/entra*`
nicht erreichbar und der Login-Button erscheint nicht.

- **Kein Auto-Provisioning:** SSO meldet ausschließlich bestehende lokale
  Konten an (Zuordnung über die E-Mail-Adresse); unbekannte
  Entra-Identitäten werden abgewiesen und protokolliert.
- **Flow-Härtung:** `state`-Parameter (Einmalwert in der Session,
  `hash_equals`), Code-Tausch ausschließlich serverseitig mit Client-Secret
  über TLS; ID-Token-Claims (`aud`/`iss`/`exp`) werden in
  `App\Security\OidcIdToken` fail-closed validiert.
- **2FA:** Die lokale TOTP-Pflicht gilt für SSO-Logins nicht zusätzlich —
  Entra ID bringt eigene MFA-/Conditional-Access-Richtlinien mit. Die
  Session-Härtung (`App\Service\LoginSession`) ist identisch zum lokalen
  Login, inkl. Session-Invalidierung bei Passwortänderung (#113).
- **Redirect-URI** in der App-Registrierung: `<Stamm-URL>/auth/entra/callback`.

## Selfservice-Registrierung (#83, `src/Controllers/RegistrationController.php`)

Standardmäßig **deaktiviert** — die öffentliche Registrierung unter `/register`
ist die einzige unauthentifizierte Schreibfläche für Benutzerkonten und wird
nur aktiv, wenn der Admin sie in den Systemeinstellungen einschaltet
(`registration_enabled`). Schutzmechanismen:

- **E-Mail-Verifizierung vor Erstaktivierung:** Das Konto erhält einen
  Einmal-Token (48 h gültig, `users.email_verification_token`); solange er
  gesetzt ist, blockiert der Login. Admin-angelegte Konten erhalten nie
  einen Token.
- **Rate-Limiting pro Client-IP** (5 Versuche/Stunde, RateLimiter-Typ
  `registration`), reservierte Benutzernamen sind gesperrt.
- **Minimale Rechte:** Neue Konten landen ausschließlich in der vom Admin
  gewählten Standard-Gruppe (`registration_default_group`, nie
  admin/public) oder ganz ohne Gruppe (keinerlei Rechte). Ob für sie
  2FA-Pflicht gilt, steuert die Gruppe (#84); ohne Gruppe greift die
  Fail-safe-Pflicht.

## Reservierte Benutzernamen

`BaseController::isReservedUsername()` verhindert Accounts mit Namen wie
`admin`, `root`, `system`, `support`, `api`, `test` etc. — sowohl im
Setup-Wizard als auch (implizit über dieselbe Methode) bei der
Benutzerverwaltung, um Verwechslung mit Systemkonten oder Phishing-artige
Benutzernamen zu vermeiden.
