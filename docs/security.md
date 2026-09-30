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
  weg, auch meine" die ehrlichere Zusage. Ein offener Adressantrag endet im
  selben `UPDATE` (Audit M16, siehe unten).
- **Backup-Codes neu erzeugen** verlangt Passwort **und** einen gültigen
  zweiten Faktor, denselben Maßstab wie die 2FA-Einrichtung (#112): Zehn
  frische Codes sind dasselbe Material wie ein neues Geheimnis. Welcher Faktor,
  entscheidet das Konto über dieselbe Weiche wie der Step-up
  (`StepUp::codePruefen()`, Audit N10): TOTP, wenn vorhanden, sonst der
  Mailcode, aber nur, wenn er ein Faktor des Kontos ist. Ein reines
  Passkey-Konto bestätigt vorher mit dem Passkey; eine frische Bestätigung
  ersetzt den Code auch sonst. Bei TOTP wird der verbrauchte Zeitschlitz
  mitgeschrieben, sonst löchert die Aktion den Replay-Schutz (#111).
- **Adressänderung** braucht das aktuelle Passwort und, bei einem Konto mit
  zweitem Faktor, die frische Bestätigung (Audit M17, siehe „Step-up“). Sie
  gilt erst nach Bestätigung über einen Link an die NEUE Adresse — und schickt
  gleichzeitig einen Hinweis an die BISHERIGE. Den kann ein Angreifer nicht
  verhindern; er ist der einzige Weg, auf dem der rechtmäßige Eigentümer von
  einer Übernahme erfährt, solange sie noch rückgängig zu machen ist. Er nennt
  auch den Ausweg: Ein Passwortwechsel bricht den Antrag ab, ebenso der Knopf
  unter „Mein Profil“. Nach der Übernahme geht ein zweiter Hinweis an die alte
  Adresse — wichtig auch, weil der SSO-Login lokale Konten über die Adresse
  zuordnet.
- **Der Antrag überlebt keine Incident-Response** (Audit M16). Jeder
  Passwortwechsel (Profil, Reset per Link, erzwungener Wechsel, Neusetzung
  durch die Verwaltung), eine Adressänderung durch die Verwaltung und der
  „2FA Reset“ verwerfen ihn (`App\Security\KontoSicherheit`). Bis dahin konnte
  ein Angreifer einen vorbereiteten Antrag nach dem Passwortwechsel des Opfers
  noch bestätigen und sich das Konto über „Passwort vergessen“ zurückholen.
- **Die Übernahme ist atomar** (Audit N53, `App\Service\AdressWechsel`): Das
  `UPDATE` trägt Token, Frist und aktives Konto selbst, nur eine getroffene
  Zeile gilt. Zwei fast gleichzeitige Aufrufe des Links (Mailscanner) setzten
  vorher `email = NULL`. Eine inzwischen vergebene Adresse — auch die eines
  Kontos im Papierkorb, der `UNIQUE`-Index zählt es mit — verwirft den Antrag
  mit Meldung statt HTTP 500 (Audit N52).

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
Plugin-Zusatzfunktion an (`feature_<key>`/`read`). Dazu kommen zwei
Kern-Lesepaare (`READ_ONLY_PAIRS`): `horses.internal` und
`contacts.internal` („Intern lesen“, Audit M10/M13). Bewusst als Paare: Eine
Addon-Aktion namens `internal` an einem anderen Modul bleibt schreibend. Eine Positivliste, keine
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
  am Faktor vorbei. Dasselbe gilt für einen Admin, dessen einziger Faktor ein
  Passkey ist (etwa nach dem Zurücksetzen der eigenen 2FA): App und
  Backup-Codes sind der Rückweg, wenn das Gerät verloren geht.
  **Ablauf (Audit M32):** `afterSecondFactor()` setzt die Session-Marke
  `zweiter_faktor_bestanden` (`user_id`, Zeitpunkt) und leitet auf
  `/2fa/setup?grund=starker_faktor`. Die Marke öffnet `/2fa/setup` und
  `/2fa/enable` ohne angemeldete Sitzung und ohne Step-up — aber nur für
  genau dieses Konto, nur solange `pending_2fa_user_id` noch darauf zeigt und
  höchstens 10 Minuten (`TWOFA_REAUTH_TTL`). Passwort plus vorhandener Faktor
  in genau diesem Login ist derselbe Nachweis, den `/2fa/reauth` verlangt.
  Bis dahin verlangte die Einrichtung eine angemeldete Sitzung, die es im
  Anmeldeweg nie gibt — die Anmeldung sprang endlos zwischen Faktorseite und
  `/2fa/setup`. Eine neue Anmeldung, der fertige Login
  (`LoginSession::establish()`) und die erfolgreiche Einrichtung räumen die
  Marke weg. Läuft sie auf der Einrichtungsseite ab, führt `/2fa/enable`
  zurück zur Faktorseite; nach dem erneuten Faktor gibt es eine frische Marke
  (auf der Mailcode-Seite dafür „Code erneut senden“ drücken — der GET
  verschickt nichts).
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
  und Neusetzung durch einen Admin, jetzt gebündelt in
  `KontoSicherheit::nachPasswortwechsel()`. Ebenso beim Bestätigen einer neuen
  Adresse, denn offene Codes gingen an die alte.
- **Probecodes nur, wo der Mailcode etwas bewirkt** (Audit N10):
  `POST /profil/2fa/email/code` stellt nur für Konten aus, die den Mailcode
  einschalten dürfen oder ihn schon nutzen. Vorher bekam jedes Konto mit
  Adresse einen — auch eines, dessen Faktor ein Passkey ist.

- **Die Step-up-Schranke (#112) fragt nach JEDEM Faktor, nicht nach TOTP.**
  Bis v0.8 war `totp_enabled = 0` gleichbedeutend mit „kein zweiter Faktor".
  Wer nur diese Spalte prüft, lässt ein Mailcode-Konto auf `/2fa/setup` und
  `/2fa/enable` ohne jeden Nachweis durch: Der Angreifer bräuchte nur das
  Passwort, holte sich dort ein frisches TOTP-Secret, bestätigte es mit dem
  eigenen Gerät — und wäre angemeldet, mit den Backup-Codes des Opfers
  überschrieben. Beide Wege prüfen deshalb `SecondFactors::fromRow()`, und
  beide für sich allein (`/2fa/setup` gibt das Secret bereits aus, ein Fix nur
  im POST käme zu spät).
- **Der Step-up ist mit dem Faktor führbar, den das Konto hat** (Audit N10).
  TOTP, wenn vorhanden — auch bei TOTP + Mailcode, strenger als die Anmeldung.
  Sonst der Mailcode, den `POST /2fa/reauth/code` an die **bisherige** Adresse
  ausstellt, aber nur, wenn er ein Faktor des Kontos ist. Ein reines
  Passkey-Konto bestätigt mit dem Passkey. Ohne das wäre die Schranke oben
  eine Sackgasse: Ein Mailcode-Konto könnte nie eine Authentikator-App
  nachrüsten. Wer bei TOTP + Mailcode das Gerät verloren hat, geht über die
  Verwaltung („2FA Reset“).

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
- **Step-up für jede Änderung an den Faktoren (#112, Audit M15, M17, N10,
  `App\Security\StepUp`):** Hat ein Konto einen zweiten Faktor, verlangt jede
  Aktion, die einen Faktor hinzufügt, abschaltet oder seinen Zustellweg
  ändert, eine frische Bestätigung mit Passwort UND einem vorhandenen Faktor:
  - Authentikator-App neu einrichten (`/2fa/setup`, `/2fa/enable`)
  - Passkey hinzufügen (`/passkeys/optionen`, `/passkeys/registrieren`) und
    entziehen
  - Mailcode ein- oder ausschalten
  - E-Mail-Adresse ändern
  - Backup-Codes erneuern bei einem reinen Passkey-Konto

  Bis dahin galt die Schranke nur für die App: Wer eine Sitzung übernommen
  hatte und das Passwort kannte, hängte einen eigenen Passkey an, schaltete
  den Mailcode ab und richtete danach ohne Nachweis eine eigene App ein, oder
  trug die Adresse samt Mailcode auf ein eigenes Postfach um. Die
  Bestätigungsseite `/2fa/reauth?fuer=…` ist direkt erreichbar; `fuer` führt
  nur auf die Ziele aus `StepUp::ziel()` zurück (`setup`, `profil`,
  `passkeys`, `email`), nie auf einen übergebenen Pfad. Ihre Felder folgen
  exakt `StepUp::codePruefen()`. Passkey-Konten bestätigen über
  `POST /2fa/reauth/passkey/optionen` und `POST /2fa/reauth/passkey`
  (Passwort + Assertion, eigener Zeremonie-Zweck `passkey_stepup`, immer an
  das angemeldete Konto gebunden, Fehlversuche im Topf `2fa`). Die Freigabe
  (`twofa_reauth`) gilt 10 Minuten für genau dieses Konto und mehrere
  Aktionen; nur `/2fa/enable` verbraucht sie. Konten **ohne** Faktor brauchen
  keinen Nachweis — der erste Faktor lässt sich wie bisher mit der
  angemeldeten Sitzung einrichten. Secret und Backup-Codes entstehen
  ausschließlich serverseitig und liegen bis zur Bestätigung in der Session -
  POST-Werte des Clients werden ignoriert.

  **Folgen für den Betrieb:** Mailcode-Konten ohne Zugang zum bisherigen
  Postfach können ihre Adresse nicht mehr selbst ändern — Rückweg ist die
  Verwaltung. SSO-Benutzer (Entra/OIDC) mit lokalem Faktor brauchen für die
  Bestätigung ihr lokales Passwort; wer es nicht kennt, setzt es über
  „Passwort vergessen“.
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
    Gesetzt wird das Flag für neu angelegte Konten und, seit Audit N13, wenn
    die Verwaltung das Passwort eines **anderen** Kontos neu setzt — das
    Passwort kennt dann die Verwaltung und jeder, der den Zettel sieht. Auch
    eine SSO-Anmeldung eines solchen Kontos landet im Zwangswechsel und
    braucht das von der Verwaltung gesetzte Passwort.
    Abgedeckt durch `tests/Functional/ForcePasswordChangeGuardTest.php`.
  - **Session-Invalidierung bei Passwortänderung (#113):** `users.session_version`
    wird bei jeder Passwortänderung (Reset per Mail-Token, erzwungener Wechsel,
    Admin-Änderung) erhöht; `checkAuth()` vergleicht den beim Login in der
    Session abgelegten Stand bei jedem Request und beendet Sessions mit
    veraltetem Wert. Eine von einem Angreifer gehaltene Alt-Session überlebt
    den Passwort-Reset des Opfers damit nicht. Die Session, die die Änderung
    selbst ausgelöst hat, übernimmt den neuen Stand und bleibt angemeldet.
  - **Eine Regel, zwei Formen (Audit N14, `App\Service\LoginSession`):**
    Die Gültigkeitsregel (Installationsepoche, gelöscht, deaktiviert,
    `session_version`, User-Agent, Inaktivität) steht seiteneffektfrei in
    `LoginSession::validate()`. `checkAuth()` setzt sie mit Weiterleitung,
    Rotation und `last_activity`-Update durch. Stellen ohne Anmeldeschranke -
    öffentliche Seiten mit Berechtigungsprüfung (`BaseController::isAdmin()`,
    `userGroupIds()`, `hasPermission()`), `FeatureGate::isVisible()`, die
    Bildauslieferung - fragen `LoginSession::currentUserId()`: dieselbe Regel,
    aber ohne Weiterleitung und Rotation. Eine ungültige Sitzung verliert dort
    ihre Identität (Audit-Eintrag mit „ohne Anmeldeschranke erkannt“) und wird
    wie ein Gast behandelt. Zusätzlich liefern `GroupMembership::isAdmin()`
    und `groupIds()` für gelöschte oder deaktivierte Konten keine Gruppen
    mehr - auch für Aufrufer mit roher ID.
  - **Bildanfragen ohne Rotation (Audit N44):** `/media/horse-image` und
    `/media/horse-media` nutzen die leichte Form. Parallele Bildanfragen einer
    Seite rotierten vorher die Sitzungs-ID gegenseitig weg und meldeten den
    Benutzer sporadisch ab. Bildabrufe verlängern die Inaktivitätsfrist nicht.
  - **Installationsepoche (Audit M24, `App\Service\InstallEpoch`):** Jede
    Einrichtung und jeder Werksreset würfeln `settings.install_epoch` neu,
    Anmeldung und halbe Anmeldung (`LoginSession::beginSecondFactor()`)
    merken sie sich. Der `BaseController`-Konstruktor verwirft jede
    Identität (`user_id`, `pending_2fa_user_id`, `passkey_bestanden`) mit
    abweichender oder fehlender Epoche - ohne zusätzliche Abfrage, die
    Einstellungen sind ohnehin geladen. Vorher galt eine alte Sitzung nach
    einem Reset für das neue Konto mit derselben ID, beim Setup-Admin bis
    hin zu Administratorrechten. Zusätzlich leert der Werksreset per
    `DELETE`, die ID-Zähler laufen weiter.

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

**Lesen ist nicht Intern lesen** (Audit M10/M13). `view` an Pferden und
Kontakten öffnet in der Verwaltung nur den veröffentlichten Bestand – ohne
E-Mail, Telefon, Mobil, Straße, PLZ, Freitext-Anschrift, Ansprechpartner und
Notiz, und Suche und Filter laufen dann nicht über diese Felder. Das gilt für
`/admin/contacts`, `/admin/horses` (samt Farb-/Rassenauswahl und
Kontaktvorschlägen), `/admin/horses/search` und die Bildauslieferung.
Unveröffentlichtes und private Daten gibt nur die interne Einsicht
(`hasInternalAccess()`: Admin, `internal`, `edit`, `delete` oder `publish`).
`create` allein gehört bewusst nicht dazu. Die Gast-Gruppe kann `internal`
nicht bekommen. Kontaktfilter und -vorschläge in `/admin/horses` verlangen
zusätzlich `contacts.view`. Bekannte Restgrenze: Wer Pferde intern sieht und
`contacts.view`, aber nicht `contacts.internal` hat, trifft mit den
Personenfiltern weiter unveröffentlichte Kontakte, ohne ihre Namen
vorgeschlagen zu bekommen.

**Öffentliche Sichtbarkeit** ist die zweite Funktion desselben Systems
(#121/#122/#151): Was Gäste sehen, steuern die Leseberechtigungen der
Gast-Gruppe (`horses.view`, `contacts.view` — per Seed vergeben,
über die Matrix entziehbar) **in Kombination** mit dem
`is_published`-Flag der Datensätze (Default: unveröffentlicht; setzen
erfordert das `publish`-Recht). Für angemeldete Konten sind die Leserechte
der Gast-Gruppe eine **Untergrenze** (`hasPublicPermission()`, Audit N61):
Öffentlich sieht niemand weniger als ein Gast – über die Gast-Rechte hinaus
öffnet das nichts, und Verwaltungsprüfungen (`hasPermission()`) bleiben
unberührt. `PublicController` und `ApiController`
erzwingen beides durchgängig — bis hinein in verknüpfte Datensätze: Namen
und Kontaktdaten unveröffentlichter Personen/Stationen erscheinen weder auf
Detailseiten noch in Filterlisten, der öffentliche Pedigree-Baum zeigt
unveröffentlichte Vorfahren nur als Platzhalter, und die an Plugins
übergebenen Hook-Daten sind bereits gefiltert (siehe
[plugin-development.md](plugin-development.md)).

`contacts.view` gilt dabei auf **jeder** öffentlichen Fläche gleich
(`PublicController::kontakteSichtbar()`, Audit M18/N11): Fehlt es, stehen
Namen von Züchtern, Besitzern, Haltern und Deckstationen weder auf der
Pferdeseite (samt Ort, Bundesland, Land und Website der Person) noch auf
den Katalogkarten, beim Nachladen oder in den Vorschlagslisten, und die
Kontaktfilter des Katalogs (Züchter, Besitzer, Halter, Deckstation, Personen
und Station im Suchbegriff) treffen nichts — fail-closed, damit die
Trefferzahl kein Namens-Orakel wird; ein vorgefilterter Aufruf zeigt einen
Hinweis. Technisch ist das die Kontaktsperre in `HorseSearchSql`: Jeder
`contacts`-JOIN bekommt `AND 0 = 1`, sodass auch künftige Kontaktfelder gar
nicht erst ankommen. Freitext-Stationen ohne Datensatz sind keine Kontakte
und bleiben. Die Auswahllisten des Katalogs (Farbe, Rasse, Kontakte) gibt es
nur mit `horses.view`, Farbe und Rasse nur aus veröffentlichten Pferden
(Audit N12). `/api/horses` gibt die Kontaktnamen nur mit `contacts.view` des
Schlüssels aus (Audit N7, siehe [api.md](api.md)).

**API-Schlüssel** (`src/Security/ApiKey.php`, [api.md](api.md)) sind eine
eigene, session-unabhängige Auth-Fläche: max. 5 GÜLTIGE je Benutzer
(abgelaufene zählen nicht mit, #340), mit Pflicht-Ablauf von höchstens zwei
Jahren ab Ausstellung, gespeichert
nur als SHA-256-Hash, effektive Rechte stets die **Schnittmenge** aus den
aktuellen Rechten des Besitzers und dem Scope des Schlüssels — ein
Schlüssel kann nie mehr als sein Besitzer, und Rechteverlust wirkt sofort.

## Brute-Force-Schutz (`src/Security/RateLimiter.php`)

Datenbankgestützter Zähler fehlgeschlagener Versuche pro `identifier` + `type`
in einem Zeitfenster (Default: 5 Versuche / 15 Min). Bei DB-Fehlern
**fail-open** (blockiert nicht) – bewusste Ausfallsicherheits-Entscheidung,
damit ein DB-Problem nicht versehentlich alle Logins sperrt. Öffentliche
Formulare bekommen deshalb zusätzlich eine DB-unabhängige Schicht – siehe
Abschnitt „DSGVO-Portal" weiter unten.

**Erst buchen, dann zählen** (Audit M20). Bis v0.9.0 zählte der Kern zuerst,
prüfte dann Passwort oder Code und buchte den Fehlversuch zuletzt. Parallel
abgeschickte Anfragen sahen dazwischen alle denselben alten Stand – so ließ
sich ein Vielfaches der erlaubten Versuche erzwingen. `reserveAttempt()`
schreibt die eigene Zeile zuerst (Autocommit) und zählt danach; über der
Grenze löscht es sie wieder und liefert `null`. Die k-te angenommene Buchung
sieht mindestens k Zeilen, mehr als die Grenze kommt also auch parallel nie
durch. Kehrseite: Unter einem exakt gleichzeitigen Burst können alle
abgelehnt werden (fail-closed, nur unter Angriff).

- Fehlschlag: Die Buchung bleibt stehen, sie *ist* der Fehlversuch.
- Erfolg: `clearAttempts()` wie bisher bzw. `releaseAttempt()` für Zähler,
  die ein Erfolg nicht leeren soll (`login_ip`, `login_konto`).
- Neutraler Ausstieg (nichts geprüft, z. B. Konto inzwischen gelöscht, neues
  Passwort zu kurz) und Sperre eines nachgelagerten Zählers:
  `releaseAttempt()` der schon gemachten Buchungen.
- In einer offenen Transaktion wirft `reserveAttempt()` eine
  `LogicException` – dort wäre die Buchung für andere unsichtbar.

So arbeiten Anmeldung, alle zweiten Faktoren samt Step-up, erzwungener
Passwortwechsel, „Passwort vergessen“, Registrierung, DSGVO-Formular und der
Versand von Mail- und Bestätigungscodes. Die `profile_*`-Zähler bleiben beim
alten Muster: Sie setzen eine voll angemeldete Sitzung voraus, deren Anfragen
die Sitzungssperre ohnehin serialisiert. `tooManyAttempts()`,
`recordAttempt()` und `clearAttempts()` bleiben unverändert (Addon-API); für
neue Aufrufer, auch in Addons, gilt `reserveAttempt()`.

**Mailcodes** (`EmailSecondFactor::verify()`) buchen ihren Versuch je Code
ebenfalls vor der Prüfung, gebunden an den gelesenen Abdruck; eingelöst ist
ein Code nur, wenn genau diese Anfrage seine Zeile löscht. **Backup-Codes und
TOTP-Zeitschlitze** werden per Vergleich mit dem gelesenen Stand verbraucht
(`App\Security\OneTimeProofs`, Audit N43): Ein Backup-Code gilt auch bei
parallelen Anfragen einmal, eine laufende Einlösung überschreibt keinen
inzwischen neu erzeugten Codesatz, und der gespeicherte Zeitschlitz kann nur
steigen.

**Typen** (Spalte `login_attempts.type`, `VARCHAR(20)`): `login`, `login_ip`,
`login_net`, `login_konto`, `2fa`, `2fa_email_send`, `backup`,
`force_pw_change`, `password_reset`, `password_reset_to`, `registration`,
`verify_resend`, `dsgvo_attempt`, `dsgvo_request`, `profile_*`. Längere oder
leere Typen lösen eine `InvalidArgumentException` aus
(`RateLimiter::MAX_TYPE_LENGTH`, Audit M8) – bis dahin scheiterte das Buchen
still, und die Sperre beim erzwungenen Passwortwechsel (`force_password_change`,
21 Zeichen) griff nie.

**IP-Zähler je /64** (Audit M7). Jeder IPv6-Anschluss hat mindestens ein /64
und kann jede Anfrage von einer neuen Adresse schicken; ein Zähler je voller
Adresse griff dann nie. `ClientIp::rateLimitKey()` kürzt IPv6 auf das /64
(`2001:db8:1:2::/64`) und macht aus IPv4-gemappten Adressen IPv4. Alle
IP-Zähler des Kerns nutzen diesen Schlüssel; `RateLimiter` wendet ihn
zusätzlich auf jeden Bezeichner an, der als Ganzes eine IPv6-Adresse ist – so
zählen auch die Formulare der Addons je /64, ohne eigene Änderung. Die Spalte
`ip_address` speichert weiter die volle Adresse. Geräte im selben /64 teilen
sich damit einen Zähler, wie hinter einem IPv4-NAT.

Der Login nutzt mehrere getrennte Zähler (#115):

- Der Konto-Zähler ist an die Client-IP gekoppelt (`uid:<id>|<ip>`, 5
  Versuche), damit gezielte Fehlversuche eines Angreifers keine bekannten
  Konten global aussperren können (Account-Lockout-DoS).
- Ein reiner IP-Zähler (`login_ip`, 20 Versuche) bremst Passwort-Spraying
  über viele Konten vom selben Anschluss. Nur bei IPv6 kommt eine zweite
  Stufe je /48 dazu (`login_net`, 100 Versuche): Ein /48 sind 65.536 /64, und
  so viel gibt es bei Tunnelbrokern kostenlos.
- **Kontoweite Bremse ohne Sperre** (`login_konto`, Audit M7): Ab 10
  Fehlversuchen gegen dasselbe Konto in 15 Minuten, gleich von welchen
  Adressen, verlangt die Anmeldung zusätzlich die Spam-Schutz-Abfrage
  (Captcha-Kontext „Anmeldung“, `login`). Ohne gelöste Abfrage wird das
  Passwort gar nicht erst geprüft. Gesperrt wird das Konto nie – mit gelöster
  Abfrage kommt der Besitzer jederzeit hinein, der Schutz aus #115 bleibt.
  Unbekannte Kennungen werden genauso behandelt, die Bremse verrät also
  nicht, ob es ein Konto gibt. Eine erfolgreiche Anmeldung leert diesen
  Zähler bewusst nicht, sonst setzte jeder Login des Opfers das Budget des
  Angreifers zurück. Ein einzelnes /64 erreicht die Schwelle nie, weil der
  Konto|IP-Zähler vorher sperrt.

**Restrisiko und Empfehlung.** Wer ein /48 hat, verteilt seine Versuche auf
viele /64; danach bremsen nur noch `login_net` und die kontoweite Abfrage.
Die eingebaute Rechenaufgabe lässt sich per Skript lösen und kostet dann nur
einen GET, 3 Sekunden Wartezeit und eine eigene Sitzung je Versuch. Für den
Kontext „Anmeldung“ empfiehlt sich deshalb ein Proof-of-Work-Anbieter
(Addon `captcha-altcha`, *Systemeinstellungen → Spam-Schutz je Formular*).
Ein Angreifer kann einem Opfer die Zusatzabfrage mit Fehlversuchen von drei
Anschlüssen dauerhaft aufzwingen – lästig, aber keine Sperre.

**„Passwort vergessen“** ist doppelt begrenzt: je Absender (5 je 15 Minuten,
bei IPv6 je /64) und je Empfänger (höchstens 3 Reset-Mails je Adresse und
Stunde, `password_reset_to`, Audit M7). Über der Empfängergrenze wird still
nichts erzeugt; Antwort und Antwortzeit bleiben gleich. Gezählt wird nur ein
Abdruck der Adresse (SHA-256 der kleingeschriebenen Adresse) – die Tabelle
wird nie aufgeräumt, und beliebige eingetippte Fremdadressen haben dort im
Klartext nichts verloren.

## Verschlüsselung sensibler Werte (`src/Security/Crypto.php`)

AES-256-GCM (authenticated encryption) für Werte, die zwar in der DB
gespeichert, aber wieder im Klartext benötigt werden: das SMTP-Passwort
sowie sämtliche Backup-Ziel-Zugangsdaten (S3 Secret Key, WebDAV-/
FTPS-Passwort) in `settings`. Schlüssel wird aus `APP_KEY`
(32-Byte-Hex-Env-Variable) per
SHA-256 abgeleitet. **Rotation von `APP_KEY` macht bestehende verschlüsselte
Werte unlesbar** – siehe README, Abschnitt „Priorität & Rotation“.

TOTP-Secrets (`users.totp_secret`) werden ebenfalls über `Crypto::encrypt()`
verschlüsselt abgelegt. **Gelesen wird fail-closed** (Audit N8,
`Totp::secretAusSpeicher()`, die eine Lesestelle für Anmeldung, Step-up und
Backup-Code-Neuerzeugung): Es gilt nur, was sich entschlüsseln lässt, oder
ein gespeicherter Wert im Base32-Klartextformat von `generateSecret()`
(`/^[A-Z2-7]{16,64}$/`, Altbestand von vor der Verschlüsselung). Bis dahin
wurde bei jedem Entschlüsselungsfehler der Rohwert selbst zum Secret — nach
einem Wechsel des `APP_KEY` also der Chiffretext, aus dem jeder mit einem
Datenbank-Dump gültige Codes berechnen konnte. Jetzt wird die Prüfung
abgelehnt, zählt als Fehlversuch und landet als „TOTP-Secret nicht lesbar“ im
Audit-Log; Betroffene melden sich per Backup-Code an, die Verwaltung setzt
ihre 2FA zurück. SCHEMA_VERSION 24 verschlüsselt vorhandenen Klartext
(`migration_totp_klartext_verschluesseln`) und nennt im Update-Protokoll die
Konten mit nicht lesbarem Secret. Der Klartext-Rückfall bleibt als
Sicherheitsnetz (Restore alter Dumps) bis zum nächsten Minor-Release und
entfällt dann. Backup-Codes
(`users.backup_codes`) werden dagegen **gehasht** (`password_hash()`, wie
Passwörter) und beim Verbrauch aus dem Array entfernt – atomar, siehe
„Brute-Force-Schutz“ (Audit N43). Sie sind also Single-Use und selbst bei
DB-Zugriff nicht im Klartext einsehbar.

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

`db_config.php` trägt APP_KEY und DB-Passwort und wird deshalb **atomar**
geschrieben (`App\Helper\AtomicFile`, Audit N56): exklusiv angelegte
temporäre Datei im selben Ordner (nie im System-Temp-Verzeichnis, daher kein
`tempnam()`), Rechte 0600 vor dem Schreiben, `fsync`, dann `rename()`.
Scheitert ein Schritt, bleibt die alte Datei unverändert. Lesen, Ändern und
Schreiben laufen unter der Sperre `config/.db_config.lock`, damit sich zwei
gleichzeitige Speichervorgänge nicht gegenseitig überschreiben.

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

**Kontakte nur mit Kennung** (Audit N45). Anlegen, Ändern, Papierkorb und
Zusammenführen von Kontakten protokollieren nur `Kontakt ID {id}` bzw.
`Quelle ID {a} -> Ziel ID {b}`, Dubletten-Entscheidungen nur „(Notiz
hinterlegt)“. Über „Anonymisierte Person (#id)“ ließe sich ein anonymisierter
Datensatz sonst anhand älterer Einträge wieder zuordnen.

**Ausnahme vom Append-only-Prinzip: DSGVO-Pseudonymisierung** (Entscheidung
D18). Beim Anonymisieren oder Löschen eines Kontakts über `/admin/gdpr`
ersetzt der Kern Namens- und Notizteile der ID-verankerten Einträge zu diesem
Kontakt durch `[DSGVO entfernt]` (Formate siehe
[database.md](database.md#audit_logs)); das Ereignis selbst bleibt. Der
Update-Schritt `dsgvo_nachfuehrung` holt das einmalig für früher bearbeitete
Kontakte nach und maskiert Einmalcodes und Adressen in der Kategorie `email`.
Nicht erfasst werden: Freitext in anderen Kategorien, Einträge von Addons,
extern exportierte Protokolle und **bereits erstellte Sicherungen** – die
bleiben unverändert und müssen über ihre Aufbewahrungsfrist auslaufen.

## DSGVO-Löschung und -Anonymisierung (`src/Service/KontaktDsgvo.php`)

„Kontakt anonymisieren“ und „Kontakt endgültig löschen“ (DSGVO wie Papierkorb)
behandeln in EINER Transaktion auch die abhängigen Kopien (Audit M11, M23,
N45):

- **Namenskopie der Deckstation am Pferd** (`horses.breeding_station`): beim
  Löschen `NULL`, beim Anonymisieren der Anonymname. Nach dem früheren
  `ON DELETE SET NULL` galt sie sonst als öffentlicher Freitext – Katalog,
  Detailseite, Stationssuche und `/api/horses` zeigten den Namen, auch bei nie
  veröffentlichten Kontakten. Kopien nach einem Zusammenführen werden auf die
  tatsächlich verknüpfte Station synchronisiert. Wörtliche Namenskopien in
  `horse_persons.breeding_station_text` neben einer verknüpften Station
  werden geleert; abweichender Freitext bleibt Pferdehistorie. Abgeglichen
  wird gegen alle bekannten Namen (aktuell, Altkopie, ID-verankerte
  Protokolleinträge), wörtlich, ab drei Zeichen – Schreibvarianten bleiben.
- **Dubletten-Entscheidungen** (`match_labels`): gelöscht bzw. Notiz geleert.
- **Altkopien** aus #336 (`persons_pre_contacts`,
  `breeding_stations_pre_contacts`): gelöscht bzw. anonymisiert, siehe
  [database.md](database.md#audit_logs).
- **Protokoll**: nur beim DSGVO-Anlass pseudonymisiert.
- **Addons**: `contact.anonymized` bzw. `contact.erased` (Anlass `dsgvo` oder
  `papierkorb`) feuern nach dem Commit.

Ein fehlender Kontakt (Doppelklick, veraltetes Formular) führt zu einer
Fehlermeldung, die Anfrage bleibt offen; ein Fehler rollt alles zurück.
`gdpr_requests` selbst (Name und E-Mail des Anfragenden) bleibt als Nachweis
der Bearbeitung bestehen.

Die automatische Zuordnung von Anfragen zu Kontakten (Audit M12) folgt den
Regeln der manuellen Suche: ab drei Zeichen (sonst über die E-Mail-Adresse),
LIKE-Platzhalter wörtlich, höchstens 50 Treffer je Anfrage mit Hinweis.

## Metadaten hochgeladener Fotos (`src/Service/BildMetadaten.php`)

Handyfotos tragen im EXIF-Block die GPS-Position der Aufnahme, Zeitpunkt,
Kameramodell und Seriennummer, oft zusätzlich als XMP. Ein Foto vom eigenen
Hof verriet so die Hofadresse, auch wenn die Kontaktdaten des Besitzers gar
nicht öffentlich sind (Audit M21). Seitdem gilt:

- **Beim Upload** (Galerie, Foto im Anlegeformular, Verbandslogo) wird der
  Inhalt eingelesen, bereinigt und erst dann atomar an seinen endgültigen
  Platz geschrieben. Eine Rohfassung liegt zu keinem Zeitpunkt in
  `storage/horses` oder `public/uploads/branding` und damit auch in keiner
  Sicherung. Einen Aufbau, den der Parser nicht lesen kann, lehnt der Upload
  ab (`media_invalid` bzw. `logo_type`, das alte Logo bleibt).
- **Entfernt** werden EXIF, XMP (auch Extended XMP), IPTC/Photoshop,
  Kommentare, eingebettete Vorschaubilder, MPF, JUMBF/C2PA sowie alles hinter
  dem Dateiende (Bewegungsfotos, HDR-Gain-Maps, Herstellerdaten). Bei PNG
  bleiben nur Farb-, Maß- und Animationschunks, bei WebP die Bild-, Alpha-,
  Animations- und ICC-Chunks, bei GIF Bilddaten, Graphic Control, Plain Text
  und die Animations-Anwendungsblöcke.
- **Erhalten** bleiben das Farbprofil (ICC, Adobe-APP14) und die
  Ausrichtung. Sie wird als minimaler EXIF-Eintrag mit genau einem Tag
  (0x0112) neu geschrieben.
- Der Parser ist ein reiner **Strukturparser** mit Positivliste. Er dekodiert
  keine Pixel, braucht weder GD noch ext/exif und kopiert die Bilddaten
  bytegleich. Vor jedem Schreiben müssen `getimagesizefromstring()` für
  Original und Ergebnis dieselben Maße und denselben Typ melden.
- **Bestand:** Das Update mit `SCHEMA_VERSION` 29 bereinigt einmalig alle
  in `horses.image_url` und `horse_media.file_name` referenzierten Fotos
  (auch im Papierkorb) und das Verbandslogo. Im Web läuft der Schritt
  höchstens 20 Sekunden je Aufruf und setzt danach hinter dem Cursor
  `bildmetadaten_cursor` fort; `php database/migrate.php` läuft ohne
  Zeitgrenze. Nicht lesbare Dateien bleiben unverändert und stehen je Datei
  im Fehlerprotokoll (`BildMetadaten: nicht lesbar …`).
- **Was der Schutz nicht erreicht:** Sicherungen von vor dem Update, ein
  manuell zurückgespieltes altes `uploads`-Archiv und Importe über das Addon
  `datenmigration` bringen Rohdateien zurück. Danach entweder
  `\App\Service\BildMetadaten::bestandBereinigen($pdo)` aufrufen oder den
  Marker `migration_bildmetadaten_entfernen` in `settings` löschen und
  `php database/migrate.php` ausführen (der Schritt ist idempotent).

## Zwischenspeichern von Pferdefotos (`src/Controllers/MediaController.php`)

Die Bildadressen aus `App\Helper\MediaUrl` tragen eine Version
(`&v=<12 hex>`), einen Hash des gespeicherten Dateiwerts (Audit M14). Sie
ändert sich mit dem Foto.

- Veröffentlicht und passende Version:
  `Cache-Control: public, max-age=31536000, s-maxage=300`. Ein Jahr gilt nur
  für den Browser; gemeinsame Caches fragen nach spätestens fünf Minuten
  nach, denn eine Depublikation ändert die Adresse nicht.
- Veröffentlicht, ohne oder mit falscher Version: `public, max-age=300`.
- Nicht veröffentlicht: `private, no-store` (#315).

**Betrieb hinter Reverse-Proxy oder CDN:** Der Cache muss `s-maxage`
beachten und den Query-String im Cache-Schlüssel führen. Eigene Regeln, die
eine Edge-TTL erzwingen (etwa „Cache Everything“ mit fester TTL), heben den
Schutz auf: Das Foto eines depublizierten Pferds bliebe dann so lange
abrufbar, wie die Regel es festlegt.

Bedingte Anfragen folgen RFC 9110 (Audit N50): Sendet der Client
`If-None-Match`, entscheidet allein das ETag (schwacher Vergleich, Listen und
`*`); `If-Modified-Since` gilt nur ohne `If-None-Match`.

## E-Mail-Versand (`src/Service/Mailer.php`)

Eigener minimaler SMTP-Client (kein PHPMailer/Symfony-Mailer-Abhängigkeit).
**Unverschlüsselter SMTP-Versand ist hart verboten** (`smtp_encryption` muss
`ssl` oder `tls` sein, sonst wird der Versand abgelehnt und geloggt).
Zertifikatsprüfung (`verify_peer`/`verify_peer_name`) ist aktiv,
selbstsignierte Zertifikate werden abgelehnt. STARTTLS erzwingt TLS 1.2/1.3.

## Host-Header-Validierung und feste Stamm-URL (`src/Security/TrustedHost.php`, `src/Security/BaseUrl.php`)

Absolute URLs in ausgehenden Mails (u. a. der Passwort-Reset-Link) dürfen nie
aus dem vom Client mitgeschickten `Host:`-Header entstehen, denn dieser ist
Angreifer-kontrolliert (Reset-Link-Poisoning, siehe #116 und Audit M6):
`POST /forgot-password` mit `Host: evil.example` erzeugte sonst eine echte
Verbandsmail mit einem Link auf die Domain des Angreifers, und das Token
landete dort, sobald das Opfer klickt.

**Quellen für Links mit Einmal-Token** (`App\Security\BaseUrl::forLinks()`),
in dieser Reihenfolge:

1. `settings.base_url` (Admin → Systemeinstellungen oder Einrichtungsassistent),
2. die Umgebungsvariable `APP_URL` (bewusst per `getenv()` gelesen – die
   Konstante `APP_URL` aus `config/config.php` enthält auch den
   Host-Header-Rückfall),
3. nur mit konfigurierter Allowlist `TRUSTED_HOSTS`: Schema und geprüfter
   Host der Anfrage.

Gibt es keine dieser Quellen, **verweigert der Mailer den Versand** von
Passwort-Reset-, Registrierungs- und Adressbestätigungs-Mails
(Audit-Log „E-Mail-Versand verweigert (keine feste Stamm-URL)“, Kategorie
`email`). `/forgot-password` antwortet unverändert, damit die Route kein
Orakel wird. Selbstregistrierung und Adressänderung im Profil prüfen VORAB
und melden „derzeit nicht möglich“ – sonst entstünde ein Konto bzw. ein
Antrag, der nie bestätigt werden kann (Benutzername und Adresse wären per
UNIQUE blockiert). Der Neuversand des Bestätigungslinks bei der Anmeldung
prüft ebenfalls vorab, damit die Tagesdrossel nicht aufgebraucht wird. Die
anonym auslösbare DSGVO-Benachrichtigung an die Admins geht weiterhin
hinaus (Fristen), enthält ohne vertrauenswürdige Basis aber keinen
absoluten Link. Das Admin-Dashboard zeigt in diesem Zustand eine rote
Warnung „Keine feste Stamm-URL“.

Mails ohne Token (Update-, Digest-, Willkommensmails; Addons wie
`kontaktanfrage`) nutzen weiterhin `Mailer::getBaseUrl()` mit dem
bisherigen Rückfall.

`TrustedHost::resolve()` validiert den Header syntaktisch (Hostname/IP-Literal,
optional `:Port`, keine Sonderzeichen) und prüft ihn gegen die optionale
Allowlist `TRUSTED_HOSTS` (kommagetrennte Hostnamen; führender Punkt =
beliebige Subdomain, z. B. `.example.org`; Konfiguration per
Umgebungsvariable oder `db_config.php`, analog `TRUSTED_PROXIES`). Die rein
syntaktische Prüfung schützt vor Header-Injection, nicht vor einer fremden,
aber gültigen Domain – deshalb reicht sie ohne Allowlist nicht für
Token-Links.

**Prüfung der Stamm-URL** (`BaseUrl::normalize()`, identisch in
Systemeinstellungen und Einrichtungsassistent): Protokoll `http://` oder
`https://` Pflicht (wird nicht ergänzt), Host nicht leer, nicht `localhost`,
keine private oder reservierte IP (auch IPv6-Literale). Der Assistent schlägt
die aufgerufene Adresse vor, wenn sie diese Prüfung besteht; bei lokalen
Adressen bleibt das Feld leer.

**Empfehlung:** In Produktion immer `APP_URL` setzen (auch bei der
Env-Ersteinrichtung – dort ist es bewusst keine Pflicht, damit CI und
Healthchecks nicht blockieren) oder die Stamm-URL unter
Admin → Systemeinstellungen eintragen.

Offener Folgepunkt: `EntraSsoController::redirectUri()` und die
Cron-Einstellungsseite verwenden noch die Konstante `APP_URL`. Beim
OIDC-Redirect verhindert die beim IdP registrierte Redirect-URI eine
Umlenkung; mittelfristig sollen beide auf `BaseUrl::fixed()` umgestellt
werden.

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
- **Lokaler zweiter Faktor auch nach SSO (Audit N9).** Der Callback führt
  über dieselbe Faktorweiche wie der Passwort-Login
  (`AuthController::nachErstemFaktor()`): Konten mit TOTP, Passkey oder
  Mailcode werden danach gefragt; Administratoren, Mitglieder von Gruppen
  mit 2FA-Pflicht (#84) und Konten ohne Gruppe richten beim ersten
  SSO-Login TOTP ein (die Einrichtung ist im laufenden Login ohne Passwort
  erreichbar, reine SSO-Nutzer werden nicht ausgesperrt). Bis dahin baute
  der Callback die Sitzung direkt auf — die in der Gruppenverwaltung
  zugesagte 2FA-Pflicht galt für SSO nicht. Eine bestehende Anmeldung einer
  anderen Identität in derselben Sitzung wird beim SSO-Login verworfen.
  Nach einem lokalen Faktor ist das Ziel `/admin` statt `/admin?sso=entra`.
  - **`OIDC_TRUST_IDP_MFA`** (Standard aus, Umgebungsvariable oder
    `db_config.php`-Schlüssel `oidc_trust_idp_mfa`): Der lokale Faktor
    entfällt nur, wenn das ID-Token eine MFA beim IdP **nachweist**
    (fail-closed). Nachweis über `amr` — Werte in `OIDC_MFA_AMR_VALUES`,
    kommagetrennt, Standard `mfa`; `pwd` zählt nie — oder über `acr` —
    Werte in `OIDC_MFA_ACR_VALUES`, Standard leer. Dann entfallen auch die
    Admin-TOTP-Pflicht und die Gruppen-Pflicht. Fehlt der Nachweis, wird der
    lokale Faktor verlangt und „SSO: IdP-MFA nicht nachgewiesen“
    protokolliert. Gilt im generischen und im ENTRA-Modus.
  - **Provider-Hinweise:** Entra ID schreibt `amr` ins v2-ID-Token nur als
    konfigurierten optionalen Claim (App-Registrierung → Token-Konfiguration,
    `amr` für das ID-Token); `mfa` steht dort nur nach erfolgter MFA.
    Keycloak braucht einen „Authentication Method Reference (AMR)“-Mapper;
    Keycloaks `acr` `1`/`0` sagen je nach Konfiguration nichts über MFA aus
    und gehören nicht in `OIDC_MFA_ACR_VALUES`. Authentik liefert `amr` nur
    über ein eigenes Scope-Mapping.
  - **Audit-Kennzeichnung:** „Benutzer eingeloggt“ trägt bei SSO den Zusatz
    „per SSO (Provider, iss=…, sub=…; zweiter Faktor: beim IdP
    nachgewiesen (…) | lokal verlangt | nicht verlangt)“. Die Session-Marke
    `anmeldeweg` dafür ist an die Konto-ID gebunden und wird bei jedem neuen
    Login verworfen — ein abgebrochener SSO-Versuch etikettiert keinen
    späteren Passwort-Login.
  - Die Session-Härtung (`App\Service\LoginSession`) ist identisch zum
    lokalen Login, inkl. Session-Invalidierung bei Passwortänderung (#113).
- **E-Mail-Zuordnung je Modus (Audit M19, `OidcIdToken::extractEmail()`).**
  Die Adresse ist der einzige Anknüpfungspunkt an das lokale Konto — was
  hier herauskommt, entscheidet, welches Konto angemeldet wird.

  | Claims | Generischer Modus | ENTRA-Modus |
  |---|---|---|
  | `email` + `email_verified: true` | Adresse | Adresse |
  | `email`, `email_verified` fehlt | **abgewiesen** | Adresse |
  | `email_verified` false / `"false"` / 0 / JSON-null / kein Wahrheitswert | abgewiesen | abgewiesen |
  | `email` fehlt oder leer, `preferred_username` wie eine Adresse | **abgewiesen** | UPN (`preferred_username`), sofern `email_verified` nicht ausdrücklich falsch ist |
  | `email` kein Text (Array, Zahl) | abgewiesen | abgewiesen |

  Bis Audit M19 prüfte die Anmeldung `email_verified` nur neben einer nicht
  leeren Adresse. Ein IdP-Konto ohne Adresse (Keycloak sendet dann
  `email_verified=false` und kein `email`, Authentik `email=""`) mit dem
  frei wählbaren Benutzernamen `admin@verein.de` wurde als lokaler
  Administrator angemeldet. `preferred_username` ist nach OIDC Core 5.7
  weder eindeutig noch unveränderlich; im generischen Modus gibt es keinen
  Rückfall mehr darauf. Im ENTRA-Modus bleibt der UPN-Rückfall, weil der
  Tenant Adressen und UPNs vergibt und Entra `email` nur als optionalen
  Claim liefert. Abweisungen stehen als „SSO-Login abgewiesen“ mit dem
  Grund (Form der Claims und Modus, nie eine Adresse) im Audit-Log; die
  Meldung an den Benutzer bleibt „keine verwendbare E-Mail-Adresse“.
  - **Betreiberhinweise:** Authentik sendet ab 2025.10 standardmäßig
    `email_verified: false` — ein eigenes Scope-Mapping mit `true` nur
    dann, wenn Adressen dort wirklich geprüft oder ausschließlich von der
    Verwaltung gepflegt werden. Authentik vor 2025.10 meldet immer `true`:
    Selbstregistrierung ohne Mail-Bestätigung abschalten oder aktualisieren.
    Keycloak: „Email verified“ am Benutzer pflegen.
  - **Restrisiko ENTRA:** `email` und UPN sind veränderlich; Microsoft rät,
    sie nicht zur Autorisierung zu verwenden. Für Gastkonten härtet der
    optionale Claim `xms_edov` (Domain-Besitz der Adresse geprüft). Eine
    dauerhafte Verknüpfung über (`iss`, `sub`) ist als eigenes Folgepaket
    vorgemerkt (Entscheidung D12).
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
bestehendes lokales Konto (kein Auto-Provisioning, unverändert), und der
Provider muss sie als bestätigt ausweisen (`email_verified: true`, siehe
Betreiberhinweise oben — bei Authentik ab 2025.10 ein eigenes
Scope-Mapping). Lokale 2FA-Pflichten gelten auch nach SSO: Administratoren
und Mitglieder von Gruppen mit 2FA-Pflicht richten beim ersten SSO-Login
TOTP ein, sofern nicht `OIDC_TRUST_IDP_MFA` mit MFA-Nachweis greift.

## Selfservice-Registrierung (#83, `src/Controllers/RegistrationController.php`)

Standardmäßig **deaktiviert** — die öffentliche Registrierung unter `/register`
ist die einzige unauthentifizierte Schreibfläche für Benutzerkonten und wird
nur aktiv, wenn der Admin sie in den Systemeinstellungen einschaltet
(`registration_enabled`). Schutzmechanismen:

- **E-Mail-Verifizierung vor Erstaktivierung:** Das Konto erhält einen
  Einmal-Token (48 h gültig, `users.email_verification_token`); solange er
  gesetzt ist, blockiert der Login. Admin-angelegte Konten erhalten nie
  einen Token.
- **Kein Sackgassen-Link, keine Dauerbelegung** (Audit N54,
  `App\Service\EmailVerification`). Wer sich mit korrektem Passwort an einem
  unbestätigten Konto anmeldet, bekommt automatisch einen aktuellen Link —
  höchstens dreimal in 24 Stunden (RateLimiter-Typ `verify_resend`), und nur
  nach der Passwortprüfung, also weder Orakel noch Mail-Bombing für Dritte.
  Ein noch gültiger Link wird wiederverwendet. Ein Passwort-Reset per
  Mail-Link bestätigt die Adresse ebenfalls: Er beweist die Kontrolle über das
  Postfach genauso. Unbestätigte Konten löscht die tägliche Cron-Aufgabe
  `users.purge_unverified` **9 Tage nach der Registrierung** endgültig
  (harter DELETE, nur so werden Benutzername und Adresse wieder frei;
  Admin-Gruppenmitglieder ausgenommen, höchstens 500 je Lauf). Die Frist
  läuft ab `created_at`, und kein neuer Link gilt darüber hinaus — hinge sie
  am Ablauf des Links, verlängerte jeder Neuversand sie, und wer eine fremde
  Adresse belegt (er kennt ja das Passwort), hielte sie mit gelegentlichen
  Anmeldungen für immer. Voraussetzung ist ein eingerichteter Cron.
  **Restrisiko:** Die Bestätigung läuft per GET. Ein Link-Scanner im Postfach
  eines Opfers, auf dessen Adresse jemand anderes registriert hat, kann das
  fremde Konto aktivieren; der Neuversand erhöht die Zahl solcher Mails leicht
  (höchstens drei am Tag). Eine Bestätigung per POST ist als Folgepaket
  vorgemerkt.
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
(siehe `Dockerfile`). Das gilt weiterhin: Die Vorschaubilder aus #397 sind der
einzige Ort, der GD überhaupt nutzen *könnte*. Sie sind doppelt gegatet — die
Erweiterung muss vorhanden **und** vom Betreiber eingeschaltet sein (Vorgabe:
aus) — und tun ohne sie schlicht nichts. Das offizielle Image bringt GD
unverändert nicht mit; wer die Vorschaubilder will, entscheidet sich bewusst
für den zusätzlichen Bilddecoder in seinem eigenen Image. Die eingebaute Aufgabe braucht weder Schlüssel noch
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

## Passkeys (WebAuthn)

Seit v0.9.0 (#353). Der stärkste der drei zweiten Faktoren, und der einzige,
der gegen Phishing trägt: Ein Passkey ist an die Domain gebunden und lässt
sich auf einer nachgebauten Seite nicht verwenden.

**Die RP-ID kommt nie aus der Anfrage.** Sie ist die Bindung zwischen Passkey
und Domain; käme sie aus `HTTP_HOST`, bestimmte der Aufrufer selbst, wofür
sein Schlüssel gilt. Vorrang hat die konfigurierte `base_url` (bzw.
`APP_URL`), danach der über `App\Security\TrustedHost::resolveHostname()`
geprüfte Host — und in beiden Fällen ohne Port. Empfohlen sind `base_url`
bzw. `TRUSTED_HOSTS`. Bis Audit M36 rief der Rückfall eine nicht vorhandene
Methode auf, und ohne `base_url`/`APP_URL` (Auslieferungszustand) endete jede
Zeremonie mit HTTP 500. Ist kein Host bestimmbar, antworten die
Optionen-Endpunkte jetzt mit HTTP 503 und einer Meldung; der Grund steht im
Audit-Log („Passkey-Zeremonie nicht startbar“).
**Wichtig:** Wird `base_url` später auf einen **anderen Hostnamen** gesetzt
(etwa `www.` statt der Apex-Domain), sind vorher registrierte Passkeys an die
alte RP-ID gebunden und nicht mehr nutzbar. Betroffene brauchen dann einen
anderen Faktor oder eine Zurücksetzung.

**Die Challenge steht nie im Formular.** Sie wird serverseitig erzeugt und in
der Sitzung abgelegt. Über das Formular zurückgereicht prüfte die Zeremonie
gegen einen Wert, den der Aufrufer gesetzt hat.

**Der Schlüssel muss zum Konto gehören.** Wird die Zeremonie für einen
bestimmten Benutzer eröffnet (Passkey als zweiter Faktor nach dem Passwort),
wird zusätzlich geprüft, dass der vorgelegte Schlüssel diesem Konto gehört.
Ohne das könnte jemand mit einem eigenen Passkey den zweiten Faktor eines
fremden Kontos erfüllen, dessen Passwort er kennt. Die Prüfung steht
absichtlich **zweimal** da (Dienst und Controller) — die Folge eines Fehlers
an dieser Stelle wäre eine Anmeldung als fremde Person.

**Keine Attestation-Prüfung.** `attestation: none`. Ein Verband hat kein
Interesse daran, Authenticator-Modelle vorzuschreiben, und eine halbherzige
Attestation-Prüfung ist schlechter als gar keine: Sie behauptet Sicherheit,
die sie nicht liefert.

**Anmeldeweg (Audit N42).** Nach dem Passwort — und seit Audit N9 ebenso
nach dem SSO-Callback — entscheidet die zentrale Faktorweiche
`AuthController::nachErstemFaktor()` (öffentlich, aber `@internal`: prüft
keinen ersten Faktor, nie als Route; die Faktoren lädt sie selbst) über
`faktorPfad()`: Passkey
vor Authentikator-App vor Mailcode. Ein Konto mit Passkey landet also auch
dann zuerst auf `/login/passkey`, wenn es zusätzlich TOTP oder Mailcode hat;
die anderen Verfahren stehen dort als Ausweichweg (der Mailcode per
POST-Knopf, weil der GET auf `/login/2fa/email` nichts verschickt). Auf einer
Verbindung, auf der Passkeys nicht funktionieren (HTTP außerhalb von
localhost), geht es direkt zum nächsten Verfahren; ist der Passkey der
einzige Faktor, bleibt es bei seiner Seite mit Hinweis. Ein Mailcode entsteht
nur noch, wenn er der gewählte Faktor ist — bis dahin bekam jedes Konto ohne
TOTP einen, auch eines, dessen einziger Faktor ein Passkey war, und landete
in einer Sackgasse. „Abbrechen“ auf der Passkey-Seite meldet per POST mit
CSRF-Token ab (Audit N86) und führt zurück zu `/login`.

**Hinzufügen und Entziehen verlangen den Step-up** (Audit M15, Entscheidung
D06), sobald das Konto einen Faktor hat. Ohne Freigabe entstehen weder
Optionen noch eine Challenge; die Antwort ist `403` mit dem Weg zur
Bestätigung. Die Registrierungs-Zeremonie ist an das angemeldete Konto
gebunden, und ein neuer Login räumt angefangene Zeremonien
(`passkey_registrierung`, `passkey_stepup`) weg. Nach dem Hinzufügen geht
ein Hinweis an die hinterlegte Adresse. Wer als ersten Faktor einen Passkey
einrichtet und keine Backup-Codes hat, bekommt dabei zehn (einmalige
Anzeige im Profil).

**Wiederherstellung.** Geht das Gerät verloren, hilft ein zweiter Passkey, die
Authentikator-App oder das Zurücksetzen durch die Verwaltung. Der letzte
verbleibende zweite Faktor lässt sich deshalb nicht über die Profilseite
entziehen. Der „2FA Reset“ der Verwaltung entfernt **alle** zweiten Faktoren
— App, Mailcode, Backup-Codes und seit Audit N60 auch die Passkeys — dazu
offene Mailcodes und einen offenen Adressantrag, in einer Transaktion
(`KontoSicherheit::zweiteFaktorenZuruecksetzen()`). Er beendet außerdem alle
Sitzungen des Kontos (`session_version + 1`) und widerruft seine
API-Schlüssel (Entscheidung D07): Danach ist das Konto faktorlos, und eine
noch lebende Sitzung — etwa auf dem gestohlenen Gerät — hätte sonst ohne
Nachweis sofort einen eigenen Faktor gebunden. `/2fa/setup` und
`/2fa/enable` prüfen eine bestehende Anmeldung deshalb wie jede geschützte
Seite. Der Benutzer bekommt einen Hinweis an seine Adresse. Die
Benutzerliste zeigt Passkeys an und bietet den Reset auch für Konten an,
deren einziger Faktor ein Passkey ist.
