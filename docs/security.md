# Sicherheitskonzept

Das Framework verwaltet personenbezogene Daten (Züchter, Besitzer) und
unterliegt daher besonderen Sorgfaltspflichten. Dieses Dokument beschreibt
die implementierten Schutzmaßnahmen auf Code-Ebene.

## Authentifizierung & Sessions

### Selbstbedienung `/profil` (#357)

Steht **jedem angemeldeten Benutzer** offen und arbeitet ausschliesslich auf
`$_SESSION['user_id']` — es gibt keinen Parameter, über den sich ein fremdes
Konto adressieren liesse. Drei Punkte, die dort mehr sind als Bequemlichkeit:

- **Passwortwechsel** zählt `session_version` hoch **und** ruft
  `ApiKey::revokeAllForUser()`. Ohne beides bewirkte er weniger als der
  erzwungene Wechsel, während die Seite dem Benutzer das Gegenteil verspricht.
  Die eigene Sitzung endet mit — bei einem Verdacht ist „alle Sitzungen sind
  weg, auch meine" die ehrlichere Zusage.
- **Backup-Codes neu erzeugen** verlangt Passwort **und** einen gültigen
  zweiten Faktor, denselben Maßstab wie die 2FA-Einrichtung (#112): Zehn
  frische Codes sind dasselbe Material wie ein neues Geheimnis. Welcher Faktor,
  entscheidet das Konto — TOTP, wenn vorhanden, sonst der Mailcode (#354). Bei
  TOTP wird der verbrauchte Zeitschlitz mitgeschrieben, sonst löchert die
  Aktion den Replay-Schutz (#111).
- **Adressänderung** braucht das aktuelle Passwort, gilt erst nach Bestätigung
  über einen Link an die NEUE Adresse — und schickt gleichzeitig einen Hinweis
  an die BISHERIGE. Den kann ein Angreifer nicht verhindern; er ist der
  einzige Weg, auf dem der rechtmäßige Eigentümer von einer Übernahme erfährt,
  solange sie noch rückgängig zu machen ist.

`GET /profil/email/bestaetigen` ist bewusst **ohne** Anmeldung erreichbar: Der
Empfänger der neuen Adresse ist nicht zwingend angemeldet, und der Besitz des
Tokens ist der Nachweis. Deshalb ruft `ProfileController` `checkAuth()` je
Aktion statt im Konstruktor.

### Anmeldung mit Benutzername oder E-Mail (#348)

Das Anmeldefeld heisst seit v0.9 `kennung` und nimmt **beides** an. Vier Punkte
tragen dabei die Sicherheit:

- **Getrennte Namensräume.** Neue Benutzernamen dürfen kein `@` enthalten
  (`LoginIdentifier::usernameErrors()`, durchgesetzt in `UserController` und in
  der Selbstregistrierung). Sonst könnte ein Benutzername die Adresse eines
  anderen Kontos sein.
- **Fail-closed bei Mehrdeutigkeit.** Gesucht wird mit
  `WHERE (email = ? OR username = ?) … LIMIT 2` — das findet auch Bestandsnamen
  mit `@`. Treffen zwei Konten, wird die Anmeldung abgelehnt und protokolliert,
  statt zu raten. Die Migration meldet solche Paare beim Update (Schritt 35b).
- **Der Zähler hängt am Konto, nicht an der Schreibweise.** Der Schlüssel ist
  `uid:<id>|ip`, sobald das Konto gefunden ist, sonst
  `kennung:<normalisiert>|ip`. Wäre er weiterhin die Eingabe, hätte ein
  Angreifer gegen dasselbe Konto zwei Töpfe — fünf Versuche über den
  Benutzernamen, fünf über die Adresse. `RateLimiter::normalizeIdentifier()`
  faltet dafür mit `mb_strtolower`: Die Datenbank vergleicht
  `utf8mb4_unicode_ci`, ein byteweises `strtolower()` zählte „MÜLLER" und
  „müller" getrennt.
- **Gleich lange Antwort.** Trifft die Kennung kein Konto, läuft trotzdem ein
  `password_verify()` gegen einen festen Vergleichsabdruck. Ohne das verriete
  die Dauer, welche Benutzernamen und Adressen es gibt.

**Die E-Mail-Adresse ist keine Pflichtangabe mehr** — aber nur für Konten ohne
Bearbeitungs- oder Veröffentlichungsrechte. Die Regel steht in
`App\Permission\EmailRequirement`: Pflicht, sobald eine Gruppe des Kontos eine
Aktion erlaubt, die **nicht** in `READ_ONLY_ACTIONS` steht (auch auf
Addon-Modulen), oder es Mitglied von `admin` ist. Lesend sind **zwei**
Aktionen: `view` und `read` — letztere legt `FeatureRegistry` für jede
Plugin-Zusatzfunktion an (`feature_<key>`/`read`). Eine Positivliste, keine
Liste der Schreibaktionen: Eine unbekannte Plugin-Aktion muss als schreibend
gelten, das verlangt höchstens eine Adresse zu viel — andersherum entstünde ein
Konto mit Rechten und ohne Rückweg. `admin` braucht den Sonderfall, weil die Gruppe
systemseitig alle Rechte hat und absichtlich **keine** Zeilen in
`group_permissions` — wer nur die Tabelle abfragt, hält Administratoren für
Nur-Leser.

Geprüft wird an **drei** Zeitpunkten, nicht nur einem:

1. Beim **Anlegen/Ändern** eines Kontos (`UserController`).
2. Bei der **Rechtevergabe** an eine Gruppe (`GroupController::updatePermissions()`
   und `copyPermissions()`). Ohne diesen wäre die Regel Zierde — eine Gruppe
   bekommt später ein Bearbeitungsrecht, und alle ihre Mitglieder haben eines.
   Die Ablehnung nennt die betroffenen Konten.
3. Beim **Zurückholen aus dem Papierkorb** (`TrashController::restore()`). Die
   Gruppenzugehörigkeiten überleben den Soft-Delete, gelöschte Konten zählen
   bei Punkt 2 aber bewusst nicht mit (sonst blockierte ein nie
   zurückgeholtes Konto die Rechtevergabe für immer). Ohne diesen dritten
   Punkt liesse sich der verbotene Zustand über den Umweg
   „löschen → Rechte vergeben → wiederherstellen" doch herstellen.

### Zweiter Faktor per E-Mail (#354)

Der Einmalcode per Mail ist **der schwächste der gängigen zweiten Faktoren**:
Wer das Postfach hat, hat den Faktor. Er wird trotzdem angeboten, weil er für
viele der einzige ist, den sie tatsächlich einrichten — aber ehrlich
beschriftet und mit Schranken:

- **Für Administratoren gesperrt** (`SecondFactors::emailFactorAllowedFor()`).
  Wird ein Konto *später* Administrator, verlangt die Anmeldung nach dem
  bestandenen zweiten Faktor zusätzlich die Einrichtung von TOTP
  (`AuthController::afterSecondFactor()`) — nicht davor, sonst führte der Weg
  am Faktor vorbei.
- **Gespeichert wird nur der Abdruck** (`password_hash`, nicht SHA-256 —
  gerade *weil* der Code nur sechs Stellen hat), mit Ablaufzeitpunkt und
  Versuchszähler. Nach `MAX_ATTEMPTS` ist der Code **verbraucht**, nicht nur
  gebremst.
- **Der Zweck ist Teil des Primärschlüssels.** Ein Probecode aus der
  Einrichtung lässt sich nicht als Anmeldefaktor einlösen.
- **Ein Nachweis pro Zähler.** Die Codeprüfung läuft über denselben
  RateLimiter-Topf (`2fa`) wie TOTP — sonst gäbe das zweite Verfahren doppelt
  so viele Rateversuche. Der Versand hat einen eigenen, engeren Topf, damit er
  kein Verstärker für fremde Postfächer wird.
- **Versand nur über POST.** Ein GET, der Mail auslöst, tut das auch beim
  Neuladen und beim Vorausladen des Browsers.
- **Ein Probecode vor dem Einschalten.** Eine falsch eingetragene Adresse
  sperrte das Konto sonst in genau dem Moment aus, in dem der Faktor scharf
  wird. Die Bestätigung aus der Selbstregistrierung (#83) reicht dafür nicht —
  admin-angelegte Konten tragen dort `NULL`.
- **Backup-Codes entstehen beim Einschalten**, falls es noch keine gibt: Der
  Mailversand ist der unzuverlässigste Teil, und sie sind der Rückweg.
- **Ein Passwortwechsel verwirft offene Codes** (`EmailSecondFactor::discard()`)
  — in allen vier Wegen: Selbstbedienung, Reset per Link, erzwungener Wechsel
  und Neusetzung durch einen Admin. Ebenso beim Bestätigen einer neuen Adresse,
  denn offene Codes gingen an die alte.

- **Die Step-up-Schranke (#112) fragt nach JEDEM Faktor, nicht nach TOTP.**
  Bis v0.8 war `totp_enabled = 0` gleichbedeutend mit „kein zweiter Faktor".
  Wer nur diese Spalte prüft, lässt ein Mailcode-Konto auf `/2fa/setup` und
  `/2fa/enable` ohne jeden Nachweis durch: Der Angreifer bräuchte nur das
  Passwort, holte sich dort ein frisches TOTP-Secret, bestätigte es mit dem
  eigenen Gerät — und wäre angemeldet, mit den Backup-Codes des Opfers
  überschrieben. Beide Wege prüfen deshalb `SecondFactors::fromRow()`, und
  beide für sich allein (`/2fa/setup` gibt das Secret bereits aus, ein Fix nur
  im POST käme zu spät).
- **Der Step-up ist mit dem Faktor führbar, den das Konto hat.** Für ein Konto
  ohne TOTP verlangt `/2fa/reauth` einen Mailcode, den `POST /2fa/reauth/code`
  ausstellt. Ohne das wäre die Schranke oben eine Sackgasse: Ein
  Mailcode-Konto könnte nie eine Authentikator-App nachrüsten.

Welche Faktoren ein Konto hat, beantwortet **ausschliesslich**
`App\Security\SecondFactors` — Speicherung bleibt beim Material des jeweiligen
Verfahrens (`users.totp_*`, `users.email_2fa_enabled`), damit Schalter und
Geheimnis nicht auseinanderlaufen können. Die 180-Tage-Regel aus #358 fragt
denselben Ort (`sqlHasAnyFactor()`).

### Gesperrte Konten (#358)

`users.deactivated_at` ist ein eigener Zustand neben `deleted_at`. Geprüft wird
er überall dort, wo bisher nur der Papierkorb geprüft wurde:
`BaseController::checkAuth()` (laufende Sitzungen enden beim nächsten Aufruf,
mit `?error=account_deactivated`), der Login, alle fünf 2FA-Zwischenschritte,
der erzwungene Passwortwechsel, SSO, die E-Mail-Verifizierung der
Selbstregistrierung, `ApiKey::authenticate()` und `ApiKey::create()`, sowie die
Empfängerlisten von Digest und Update-Benachrichtigung.

Ausdrücklich **beide** Reset-Pfade: Das Anfordern eines Links, das Einlösen des
Tokens und das abschliessende `UPDATE` filtern getrennt. Ohne den Filter am
`UPDATE` bliebe ein vor der Sperre verschickter Link bis zu 15 Minuten lang ein
Weg, ihr ein frisches Passwort unterzuschieben.

Die Anmeldemaske bleibt generisch (`Ungültige Zugangsdaten.`) — die
eigene Meldung erscheint nur nach einer beendeten Sitzung, hängt also am
URL-Marker und nicht an einer Eingabe. `SetupController::needsSetup()` prüft
`deactivated_at` bewusst **nicht**: Ein gesperrtes Admin-Konto zählt weiter als
vorhandener Administrator, sonst böte die Installation nach einer Sperre wieder
den Setup-Assistenten an.

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
- **Alle Nachweise der 2FA-Einrichtung tragen die Konto-ID.** Zwei
  Session-Werte können ein Konto benennen und dabei auf verschiedene zeigen:
  `pending_2fa_user_id` (Faktor 1 des laufenden Logins) und `user_id` (eine
  bestehende Anmeldung). Welches Konto gemeint ist, beantwortet deshalb genau
  eine Stelle (`AuthController::twofaTargetUserId()`), und jede Prüfung
  vergleicht ausdrücklich dagegen: Die Step-up-Freigabe (`twofa_reauth`) und
  das vorbereitete Secret (`totp_setup`) gelten nur für das Konto, für das sie
  entstanden sind, und bei aktiver 2FA muss die Sitzung als **dieses** Konto
  angemeldet sein. Ein neuer Passwort-Login löst zudem jede bestehende
  Anmeldung derselben Sitzung ab, damit erst gar keine zwei Identitäten
  nebeneinander laufen. Ohne diese Bindung hätte der Step-up des einen Kontos
  die Neukonfiguration eines anderen bezahlt - abgedeckt durch
  `tests/Functional/TwoFaCrossAccountTest.php`.
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
    `/force-password-change` selbst läuft durch dieselbe `checkAuth()`-Prüfung
    wie jede andere geschützte Route (die Ausnahme oben betrifft nur die
    Weiterleitung) und verlangt zusätzlich das bisherige Passwort - sonst wäre
    ausgerechnet diese Route der Rückweg aus der Session-Invalidierung
    darunter: Wer eine invalidierte Sitzung hält, setzte dort ein neues
    Passwort und schriebe sich die frische `session_version` selbst zurück.
    Abgedeckt durch `tests/Functional/ForcePasswordChangeGuardTest.php`.
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
eigene, session-unabhängige Auth-Fläche: max. 5 GÜLTIGE je Benutzer
(abgelaufene zählen nicht mit, #340), mit Pflicht-Ablauf von höchstens zwei
Jahren ab Ausstellung, gespeichert
nur als SHA-256-Hash, effektive Rechte stets die **Schnittmenge** aus den
aktuellen Rechten des Besitzers und dem Scope des Schlüssels — ein
Schlüssel kann nie mehr als sein Besitzer, und Rechteverlust wirkt sofort.

## Brute-Force-Schutz (`src/Security/RateLimiter.php`)

Datenbankgestützter Zähler fehlgeschlagener Versuche pro `identifier` + `type`
(`login`, `login_ip`, `2fa`, `backup`, `password_reset`, `registration`,
`dsgvo_attempt`, `dsgvo_request`) in einem Zeitfenster (Default: 5
Versuche / 15 Min). Bei DB-Fehlern **fail-open** (blockiert nicht) – bewusste
Ausfallsicherheits-Entscheidung, damit ein DB-Problem nicht versehentlich alle
Logins sperrt. Öffentliche Formulare bekommen deshalb zusätzlich eine
DB-unabhängige Schicht – siehe Abschnitt „DSGVO-Portal" weiter unten.

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

Ist die Instanz überhaupt konfiguriert - also entweder über
DB-Umgebungsvariablen (`DB_HOST`/`DB_USER`/`DB_NAME`/`DB_PASS`) **oder** über
`config/db_config.php` -, gilt ohne explizite `APP_ENV`-Angabe automatisch
`production` (keine PHP-Fehlerdetails an Besucher). Nur ein komplett
unkonfigurierter Checkout gilt als lokale Entwicklungsumgebung
(`development`, Fehler werden angezeigt).

Die Prüfung hing zunächst allein an der Existenz von `db_config.php`. Damit
fiel ausgerechnet der in der README als Variante A beschriebene Weg -
Konfiguration rein über Umgebungsvariablen, also der Container-Betrieb - auf
`development` zurück: `display_errors` an, und der erste PDO-Fehler zeigte dem
Besucher DSN samt Datenbankbenutzer. Wer nach Anleitung installiert, darf
nicht in der unsichereren Betriebsart landen.

### Fehlerprotokollierung (`App\Service\ErrorHandler`)

Anzeige und Protokollierung sind getrennt: `error_reporting` steht in **jeder**
Umgebung auf `E_ALL` und `log_errors` ist immer an; nur `display_errors` hängt
an `APP_ENV`. Zuvor setzte die Produktionsumgebung `error_reporting(0)` - die
Stufe ist aber die Maske für beides, es wurde also auch nichts mehr
protokolliert. Zusammen mit dem fehlenden Exception-Handler hieß das: Eine
unbehandelte `PDOException` lieferte eine leere Seite und hinterließ nirgends
eine Spur (OWASP A09). Registriert sind ein `set_exception_handler` und eine
`register_shutdown_function` für fatale Fehler; beide schreiben nach
`error_log()` - bewusst nicht in die Datenbank, weil genau sie der Ausfall
sein kann - und liefern eine schlichte 500-Seite ohne Details.

## Security-Header & CSP (`config/config.php`)

Global gesetzt (nicht optional pro Route): `X-Content-Type-Options: nosniff`,
`X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`,
`Permissions-Policy` (Kamera/Mikro/Standort deaktiviert), sowie eine
`Content-Security-Policy`. `X-XSS-Protection` steht einheitlich auf `0`
(OWASP-Empfehlung — der veraltete Browser-Filter kann selbst Lücken reißen,
der Schutz kommt aus der CSP), gesetzt sowohl PHP-seitig in
`config/config.php` als auch per `public/.htaccess` — bewusst an beiden
Stellen identisch, damit auch Nicht-Apache-Umgebungen (`php -S` in Tests
und Entwicklung) denselben Wert senden. Die CSP erlaubt neben `'self'` gezielt
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

Optionaler SSO-Login per OIDC Authorization-Code-Flow als **zusätzliche**
Login-Methode neben dem lokalen Login — mit zwei Betriebsarten:

- **Generischer OIDC-Modus** (Authentik, Keycloak, jeder standardkonforme
  Provider): `OIDC_ISSUER_URL`, `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`
  (optional `OIDC_PROVIDER_LABEL` für den Login-Button, Default „SSO").
  Authorize- und Token-Endpunkt werden pro Login-Versuch per OIDC-Discovery
  (`<issuer>/.well-known/openid-configuration`) ermittelt und in
  `App\Security\OidcDiscovery` fail-closed geprüft: Der `issuer` im Dokument
  muss der konfigurierten URL **exakt** entsprechen (RFC 8414, trailing
  slash zählt), und Issuer wie Endpunkte müssen `https://` sein — `http://`
  ist einzig für Loopback-Adressen erlaubt (lokale Tests). Der ermittelte
  Token-Endpunkt wird in der Session festgehalten und im Callback verwendet.
- **ENTRA-Modus** (Microsoft-Kurzform, unverändertes Verhalten):
  `ENTRA_TENANT_ID`, `ENTRA_CLIENT_ID`, `ENTRA_CLIENT_SECRET` mit den
  festen `login.microsoftonline.com`-Endpunkten, ohne Discovery. Bei
  vollständiger `OIDC_*`-Konfiguration hat der generische Modus Vorrang.

Strikt opt-in: Ohne vollständige Konfiguration eines der beiden Modi
(Umgebungsvariable oder `db_config.php`, analog `TRUSTED_PROXIES`) sind die
Routen `/auth/entra*` nicht erreichbar und der Login-Button erscheint nicht.

- **Kein Auto-Provisioning:** SSO meldet ausschließlich bestehende lokale
  Konten an (Zuordnung über die E-Mail-Adresse); unbekannte Identitäten
  werden abgewiesen und protokolliert.
- **Flow-Härtung:** `state`-Parameter (Einmalwert in der Session,
  `hash_equals`), Code-Tausch ausschließlich serverseitig mit Client-Secret
  über TLS; ID-Token-Claims (`aud`/`iss`/`exp`) werden in
  `App\Security\OidcIdToken` fail-closed validiert. Es findet bewusst keine
  JWT-Signaturprüfung statt: Das Token stammt immer aus der serverseitigen
  TLS-Verbindung zum Token-Endpunkt — im generischen Modus aus dem
  issuer-geprüften Discovery-Dokument, im ENTRA-Modus von der festen
  Microsoft-URL. Genau deshalb ist die `https://`-Pflicht der Discovery
  Teil des Sicherheitsmodells, nicht Kosmetik.
- **2FA:** Die lokale TOTP-Pflicht gilt für SSO-Logins nicht zusätzlich —
  der Identity-Provider bringt eigene MFA-Richtlinien mit. Die
  Session-Härtung (`App\Service\LoginSession`) ist identisch zum lokalen
  Login, inkl. Session-Invalidierung bei Passwortänderung (#113).
- **`email_verified` wird ausgewertet.** Die E-Mail-Adresse ist der einzige
  Anknüpfungspunkt an das lokale Konto. Sagt der Provider ausdrücklich, dass
  sie ihm nicht nachgewiesen wurde, wird der Claim verworfen — bei einem IdP
  mit Selbstregistrierung (Keycloak, Authentik) genügte es sonst, sich dort
  mit der Adresse eines Administrators anzulegen. Ein **fehlender** Claim
  bleibt akzeptiert: Entra ID sendet ihn für Geschäftskonten nicht, und dort
  vergibt ohnehin nur der Tenant-Administrator Adressen.
  - **Restrisiko `preferred_username`:** Fehlt der `email`-Claim ganz, gilt
    ersatzweise `preferred_username` (bei Entra der UPN). OIDC Core 5.7 nennt
    ihn weder eindeutig noch unveränderlich; er bleibt nur deshalb, weil
    Entra die E-Mail als optionalen Claim ausliefert und ein Entfernen
    bestehende Installationen aussperren würde. Ein vorhandener, aber
    unbestätigter `email`-Claim weicht **nicht** mehr auf ihn aus. Wer einen
    IdP mit Selbstregistrierung betreibt, sollte den `email`-Claim samt
    `email_verified` ausliefern lassen.
- **Redirect-URI** beim Provider: `<Stamm-URL>/auth/entra/callback` — der
  Pfad heißt aus Kompatibilität zu bestehenden Entra-App-Registrierungen
  für alle Provider gleich.

**Beispiel Authentik:** Provider „OAuth2/OpenID" (Confidential, Redirect-URI
wie oben, Scopes `openid profile email`), Application mit Slug
`hengstverzeichnis` daran binden, dann:

```
OIDC_ISSUER_URL=https://auth.example.org/application/o/hengstverzeichnis/   # exakt wie von Authentik ausgewiesen, inkl. Slash
OIDC_CLIENT_ID=<Client-ID aus Authentik>
OIDC_CLIENT_SECRET=<Client-Secret aus Authentik>
OIDC_PROVIDER_LABEL=Authentik
```

Der SSO-Benutzer braucht beim Provider dieselbe E-Mail-Adresse wie sein
bestehendes lokales Konto (kein Auto-Provisioning, unverändert).

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

## DSGVO-Portal (öffentlich, `src/Security/Captcha.php`)

Das Formular unter `/dsgvo` ist neben `/register` die zweite
unauthentifizierte Schreibfläche: Jede angenommene Anfrage legt eine Zeile in
`gdpr_requests` an **und** löst eine echte Benachrichtigungs-E-Mail an den
Admin aus. Ohne Schutz wäre das ein bequemer Verstärker für Spam und
Mailbox-Fluten. Vier voneinander unabhängige Schichten, in dieser Reihenfolge
geprüft (`PublicController::dsgvoSubmit()`):

1. **CSRF-Token** (wie bei allen POST-Routen).
2. **Rate-Limiting pro Client-IP** über zwei getrennte Zähler, analog zum
   Login (#115): `dsgvo_attempt` zählt **jeden** POST (20/Stunde) und bremst
   automatisiertes Durchprobieren des CAPTCHAs; `dsgvo_request` zählt nur
   **angenommene** Anfragen (3/Stunde) und begrenzt eng, wie viele echte
   Admin-Benachrichtigungen ein Client auslösen kann. Getrennt, damit ein
   Tippfehler im CAPTCHA nicht das kleine Kontingent echter Anfragen
   aufbraucht.
3. **Honeypot:** ein für Menschen unsichtbares, aus Fokus- und Vorlese-Fluss
   genommenes Feld. Ist es befüllt, sieht der Absender die normale
   Erfolgsmeldung (er erfährt nicht, dass er erkannt wurde), gespeichert und
   benachrichtigt wird aber nichts.
4. **CAPTCHA** (`App\Security\Captcha`): standardmäßig eine kleine
   Rechenaufgabe, deren Lösung ausschließlich serverseitig in der Session
   liegt.
   - **Single-Use:** Jede Prüfung verbraucht die Aufgabe (auch bei Erfolg) –
     eine einmal gelöste Antwort taugt nicht für eine Serie von Submits, jeder
     weitere Versuch braucht ein neues GET des Formulars.
   - **Zeitfenster nach oben und unten:** 15 Minuten Gültigkeit, aber
     mindestens 3 Sekunden zwischen Ausliefern und Absenden – sofort
     abgeschickte Formulare stammen nicht von Menschen.
   - **Ausgeschrieben gestellt** („sieben plus fünf", Zahlwörter je Sprache in
     `lang/<locale>.php`), damit die Aufgabe nicht per Zahlen-Regex aus dem
     HTML lösbar ist.

**Der eingebaute Anbieter ist bewusst der Standard, nicht ein Drittanbieter.**
Ausgerechnet auf dem Formular, mit dem Betroffene ihre Rechte aus Art. 15/17
DSGVO geltend machen, wäre die Übertragung ihrer IP-Adresse und eines
Browser-Fingerprints an einen weiteren Empfänger (i. d. R. Drittland) kaum zu
rechtfertigen und müsste zusätzlich in der Datenschutzerklärung stehen. Ein
Bild-CAPTCHA scheidet ebenfalls aus, da die App ohne GD-Extension auskommt
(siehe `Dockerfile`). Die eingebaute Aufgabe braucht weder Schlüssel noch
Netzzugang noch eine Lockerung der CSP und ist deshalb ohne jede Einrichtung
wirksam.

Wer als Betreiber dennoch einen Fremdanbieter (Cloudflare Turnstile, hCaptcha)
einsetzen will, kann ihn über ein Addon nachrüsten – siehe die Hooks
`captcha.providers`, `captcha.render` und `captcha.verify` in
[plugin-development.md](plugin-development.md). Die Wahl trifft der Admin
ausdrücklich unter *Systemeinstellungen*; die datenschutzrechtlichen Folgen
(Hinweis in der Datenschutzerklärung, CSP-Lockerung für das Widget) liegen
dann bei ihm. **Antwortet ein so gewählter Anbieter nicht** – Addon
deaktiviert, deinstalliert oder abgestürzt –, prüft der Kern wieder mit seiner
eigenen Aufgabe. Weder fail-open (Formular ungeschützt) noch hartes Blockieren
(Betroffene kämen nicht mehr an ihre Auskunft) wäre hier vertretbar; dass es
diesen dritten Weg überhaupt gibt, ist der Grund, den Standard im Kern zu
halten statt ihn selbst zum Addon zu machen.

Grenzen, bewusst in Kauf genommen: Eine Rechenaufgabe hält keinen gezielt für
diese Seite geschriebenen Angreifer auf – sie verteuert die üblichen
generischen Spam-Bots. Der eigentliche Mengenschutz bleibt Schicht 2. Beide
ergänzen einander gerade deshalb, weil der RateLimiter bei DB-Fehlern
fail-open ist, CAPTCHA und Honeypot dagegen ohne Datenbank auskommen und in
genau diesem Fall weiter greifen.

Zusätzlich validiert der Endpunkt serverseitig E-Mail-Adresse, Anfrage-Typ und
Feldlängen (100 Zeichen für Name/E-Mail entsprechend `gdpr_requests`, 5000 für
den Freitext) und meldet Fehler zurück, statt eine ungültige Eingabe still zu
verwerfen und dem Absender trotzdem Erfolg zu melden.

## Reservierte Benutzernamen

`BaseController::isReservedUsername()` verhindert Accounts mit Namen wie
`admin`, `root`, `system`, `support`, `api`, `test` etc. — sowohl im
Setup-Wizard als auch (implizit über dieselbe Methode) bei der
Benutzerverwaltung, um Verwechslung mit Systemkonten oder Phishing-artige
Benutzernamen zu vermeiden.
