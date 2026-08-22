# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei
dokumentiert. Das Format orientiert sich an
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung
an [Semantic Versioning](https://semver.org/lang/de/) (solange `0.y.z`:
Breaking Changes sind jederzeit möglich).

## [Unreleased]

### Neu

- **Die Foto-/Video-Galerie ist Kernmodul** (#339). Bis v0.8 hatte `horses`
  genau ein `image_url`, und das Addon `galerie` brachte eine zweite Ablage
  für dasselbe mit: Ein Redakteur pflegte Fotos zu demselben Pferd an zwei
  Stellen im selben Formular — oben das Kernfeld, darunter die Galerie. Zwei
  Uploads, zwei Ablagen, zwei Ausliefer-Wege, zwei Vorstellungen davon, welches
  Bild das Hauptbild ist.

  Jetzt: mehrere Medien je Pferd mit Reihenfolge und Bildunterschrift, **ein
  ausgezeichnetes Hauptbild**, gepflegt direkt am Pferd. Ausgeliefert wird
  ausschliesslich über `/media/horse-media` — dieselben Sichtbarkeitsregeln wie
  für das Hauptbild, also auch `is_published`.

  `horses.image_url` **bleibt** und trägt weiterhin das Hauptbild, gefüllt aus
  der Galerie. Damit bleiben Katalogkarte, Admin-Liste, Startseite, JSON-API und
  die Addons `merkliste`, `qr-code` und `verkaufsboerse` unverändert gültig.

### ⚠️ Das Addon `galerie` wird beim Update entfernt

Beim Sprung auf diese Fassung wird es **deaktiviert und sein Verzeichnis
gelöscht**. Die Daten bleiben: Der Migrationsschritt `339_galerie_uebernahme`
holt Zeilen und Dateien vorher in den Kern, idempotent, und überspringt
Einträge, deren Datei fehlt — mit Meldung, nicht schweigend.

Dabei kam heraus, dass die dafür vorgesehene Mechanik gar nicht existierte:
`UpdateService::ABGELOESTE_ADDONS` stand seit v0.8 im Code, war ausführlich
kommentiert (»sie ist gebaut, dokumentiert und geprüft«) und wurde **nirgends
gelesen**. Jetzt gibt es sie wirklich, mit zwei Tests.

### Geändert

- Das Bearbeitungsformular eines Pferdes hat **kein eigenes Foto-Feld mehr** —
  der Medien-Abschnitt darunter übernimmt. Beim *Anlegen* bleibt es (das Pferd
  gibt es noch nicht) und wird zum Hauptbild.
- Nebenbefund: „Vorhandenes Foto entfernen" löschte die Datei unter
  `public/` + `image_url` — also im Webroot, wo seit #366 keine Pferdefotos mehr
  liegen. Die Spalte wurde geleert, die Datei blieb stehen. Der Zweig ist mit
  dem Feld entfallen.
- **Video-Links: nur YouTube und Vimeo, nur `https`.** Der erste Wurf des
  Kernmoduls prüfte nur das Schema — das Addon hatte längst eine Host-Allowlist
  und baute die URL aus den geprüften Teilen **neu**, statt die Eingabe
  durchzureichen. Eine Kern-Fassung, die schwächer prüft als das Addon, das sie
  ersetzt, ist der unangenehmste Fall beim Übernehmen: Die Oberfläche sieht
  gleich aus, und es fällt niemandem auf. Prüfung und Tests sind übernommen.
- `SCHEMA_VERSION` 16 → 17: Tabelle `horse_media`.

## [0.9.0-beta.3] – 2026-08-22

Ein reines Zwischen-Beta: **kein sichtbares neues Verhalten**. Es macht nur
den Weg frei für
[Addons#131](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/131)
(Mitglieder als Benutzer anlegen) — ein Addon kann eine Kern-Fassung nur
voraussetzen, die es gibt.

Der passende Addons-Release ist `v0.9.0-beta.2`; er gilt unverändert weiter
(`core_supported_max: "0.9"`).

### Geändert

- **Ein Benutzerkonto entsteht jetzt an genau einer Stelle** (#384):
  `App\Service\UserProvisioning`. Bis dahin steckte der Vorgang in
  `UserController::store()` — verwoben mit dem Formular, also nicht
  wiederverwendbar. Wer von woanders ein Konto anlegen wollte, musste ihn
  nachbauen und dabei jede einzelne Vorgabe treffen: `must_change_password`,
  die Adresspflicht nach Rechten, das `@`-Verbot im Benutzernamen, die
  reservierten Namen, die Filterung nicht zuweisbarer Gruppen, den
  Audit-Eintrag. Jede davon ist einzeln begründet und einzeln zu übersehen.

  Kein neues Verhalten: `store()` ist der erste Aufrufer und behält, was
  wirklich zum Formular gehört. Der Anlass ist
  [Addons#131](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/131).

## [0.9.0-beta.2] – 2026-08-21

Zweites Beta der 0.9er-Linie: der **Anmelde-Block** des Meilensteins. Es
fehlen noch #339 (Galerie im Kern), #344 (Sprach-Addons), #345 und #349.

> Der passende Addons-Release ist `v0.9.0-beta.2`. An der
> Kompatibilitätslinie ändert sich nichts (`core_supported_max: "0.9"`).

### ⚠️ Bruchstelle: das Anmeldeformular

Das Feld des Anmeldeformulars heisst nicht mehr `email`, sondern `kennung`, und
es ist kein `type="email"` mehr. Wer das Formular automatisiert bedient (eigene
Skripte, Monitoring-Checks), muss den Feldnamen anpassen. Für Menschen ändert
sich nichts ausser der Beschriftung.

### Neu

- **Anmeldung mit dem Benutzernamen oder der E-Mail-Adresse** (#348). Beides
  führt zum selben Konto; welches gemeint ist, entscheidet die Datenlage und
  nicht eine Weiche am `@`. Trifft eine Eingabe wegen eines Bestandsnamens
  **zwei** Konten, wird sie abgelehnt statt geraten — die Migration meldet
  solche Paare beim Update, damit sie auffallen, bevor jemand vor der Tür
  steht.

- **Die E-Mail-Adresse ist keine Pflichtangabe mehr** (#348) — für Konten
  **ohne** Bearbeitungs- oder Veröffentlichungsrechte. Genau dafür ist es
  gedacht: Verbandsmitglieder mit Einblick, Praktikanten, ein gemeinsames Konto
  für die Geschäftsstelle. Für die heute eine Adresse zu erfinden, ist der
  schlechteste aller Wege — sie ist entweder falsch oder gehört jemand anderem.

  Die Regel greift an **zwei** Stellen, nicht nur beim Anlegen: Auch die
  **Rechtevergabe** an eine Gruppe wird abgelehnt, wenn Mitglieder ohne Adresse
  darin sind, und nennt sie beim Namen. Ohne das wäre die Regel Zierde — eine
  Gruppe bekommt später ein Bearbeitungsrecht, und alle ihre Mitglieder haben
  eines.

- **Zweiter Faktor per E-Mail** (#354), als Wahlmöglichkeit neben TOTP.
  Sechsstelliger Einmalcode, zehn Minuten gültig, fünf Versuche, danach
  verbraucht. Einzurichten unter `/profil` — mit einem Probecode, der einmal
  richtig eingegeben werden muss: Eine falsch eingetragene Adresse sperrte das
  Konto sonst in genau dem Moment aus, in dem der Faktor scharf wird.

  **Er ist der schwächste der gängigen zweiten Faktoren** — wer das Postfach
  hat, hat den Faktor. Das steht so auf der Seite, und **für Administratoren
  ist er gesperrt**. Wird ein Konto später Administrator, verlangt die
  Anmeldung zusätzlich TOTP.

### Geändert

- **Der Anmelde-Zähler hängt am Konto, nicht an der Schreibweise.** Sonst hätte
  ein Angreifer seit #348 gegen dasselbe Konto zwei Töpfe: fünf Versuche über
  den Benutzernamen, fünf über die Adresse.

- **`RateLimiter` faltet Bezeichner mehrbyte-fähig.** Das bisherige
  `strtolower()` liess „MÜLLER" stehen, während die Datenbank es als „müller"
  fand — zwei Zähler für ein Konto. Betraf auch Adressen mit Umlauten und galt
  damit schon vor #348.

- **Der Admin-Reset der Zwei-Faktor-Anmeldung räumt jetzt *alle* Faktoren ab**,
  nicht nur TOTP. Ein übrig gebliebener Mailcode-Faktor liesse den Benutzer
  genau davor stehen, wovon der Reset ihn befreien soll.

- **Backup-Codes lassen sich mit jedem zweiten Faktor neu erzeugen**, nicht nur
  mit TOTP. Ein Konto, dessen einziger Faktor der Mailcode ist, käme sonst nie
  wieder an frische — und wäre nach dem letzten verbrauchten ausgesperrt,
  sobald einmal keine Mail ankommt.

- **Die Benutzerliste nennt das Verfahren beim Namen** (App/Mailcode) statt nur
  „Aktiv". Der Unterschied in der Stärke gehört sichtbar dorthin, wo jemand die
  Konten durchsieht.

- `SCHEMA_VERSION` 15 → 16: `users.email_2fa_enabled`, Tabelle
  `email_2fa_codes`.

### Aus der Codeprüfung dieser Runde

Vier Befunde einer adversarischen Durchsicht des eigenen Standes, alle vor dem
Merge behoben und je mit einer Gegenprobe belegt (Schutz raus → Test rot →
Schutz zurück):

- **Der Mailcode liess sich über die TOTP-Einrichtung umgehen.** `/2fa/setup`
  und `/2fa/enable` fragten nur `totp_enabled` ab — für ein Konto, dessen
  einziger Faktor der Mailcode ist, war die Step-up-Schranke aus #112 damit
  wirkungslos. Wer nur das Passwort kannte, holte sich dort ein frisches
  Secret und war angemeldet, mit den Backup-Codes des Opfers überschrieben.
  Beide Stellen fragen jetzt nach **jedem** Faktor; damit ein Mailcode-Konto
  trotzdem eine App nachrüsten kann, lässt sich der Step-up auch mit dem
  Mailcode führen.
- **Der Papierkorb war der dritte, unbewachte Zustandsübergang.** „Konto
  löschen → Gruppe Bearbeitungsrecht geben → Konto zurückholen" ergab ein
  aktives Konto mit Schreibrecht und ohne Adresse. Die Wiederherstellung
  prüft jetzt selbst.
- **`read` galt fälschlich als Schreibrecht.** Der Kern legt für jede
  Plugin-Zusatzfunktion `feature_<key>`/`read` an — eine reine Leseaktion. Die
  Adressregel kannte nur `view` und hätte damit in jeder Installation mit
  Addons genau den Fall abgelehnt, für den #348 gebaut wurde.
- **Ein Adresswechsel durch den Admin verwarf offene Mailcodes nicht.** Der
  Code im alten Postfach blieb zehn Minuten gültig.

## [0.9.0-beta.1] – 2026-08-21

Erstes Beta der 0.9er-Linie: der **Konten-Block** des Meilensteins „Konten,
Anmeldung, Responsives". Die übrigen Punkte (#348 Anmeldung über den
Benutzernamen, #354 zweiter Faktor per E-Mail, #339 Galerie im Kern, #344
Sprach-Addons, #345/#349) folgen in den nächsten Betas.

> **Alle Addons brauchen die Linie 0.9.** Ein Addon mit
> `core_supported_max: "0.8"` ist auf diesem Kern fail-closed unsichtbar — das
> ist die Kompatibilitätsmechanik, kein Fehler. Der passende Addons-Release ist
> `v0.9.0-beta.1`.

### ⚠️ Bruchstelle: API-Schlüssel

**Bestandsschlüssel laufen mit dem Update ab** (#340), ohne Übergangsfrist.
Laufende Anbindungen brechen ab, bis unter `/api-keys` ein neuer Schlüssel
ausgestellt und eingetragen ist. Das ist Absicht: Ein Schlüssel unbekannten
Alters, der unbegrenzt weiterläuft, ist genau der Zustand, den #340 beendet.

### Sicherheit

- **API-Schlüssel haben ein Pflicht-Ablaufdatum** (#340), höchstens zwei Jahre
  ab Ausstellung. Die Frist verlängert sich **nicht** durch Benutzung — sonst
  hielte gerade der vergessene, aber noch laufende Schlüssel sich selbst am
  Leben. Läuft einer in weniger als 30 Tagen ab, tragen die API-Antworten
  `X-Api-Key-Expires-In-Days`; danach antwortet die API mit demselben `401` wie
  auf einen unbekannten Schlüssel. Die Obergrenze ist serverseitig.

- **„Gesperrt" ist nicht mehr dasselbe wie „gelöscht"** (#358). Bis v0.8 war
  beides `users.deleted_at`; eine Sperre liess sich weder begründen noch
  gezielt aufheben. Jetzt `deactivated_at` mit eigenem Grund, geprüft an allen
  Türen — Login, Sitzung, die fünf 2FA-Zwischenschritte, SSO, API-Schlüssel,
  Digest- und Update-Empfänger.

  Dabei fielen **zwei Reset-Pfade** auf, die bis dahin **gar nicht** filterten,
  nicht einmal auf `deleted_at`: Ein Konto im Papierkorb konnte sich per Mail
  ein neues Passwort setzen lassen.

- **Konten ohne zweiten Faktor und ohne E-Mail werden nach 180 Tagen
  deaktiviert** (#358), nicht gelöscht. Täglich über `/cron/run`, Vorwarnung 14
  Tage vorher im Digest an die Administratoren — der Betroffene hat
  definitionsgemäss keine Adresse und ist nicht erreichbar. Zwei Schutzgurte:
  eine Karenz nach dem Update (sonst räumte der erste Lauf den Altbestand am
  selben Tag ab, bevor irgendwer die Vorwarnung sah) und das **letzte aktive
  Admin-Konto**, das nie deaktiviert wird.

### Neu

- **Profilseite `/profil`** (#357) für jeden angemeldeten Benutzer. Bis jetzt
  konnte niemand sein eigenes Passwort ändern — es blieb der Umweg über
  „Passwort vergessen" und eine Mail, und **Konten ohne Adresse hatten diesen
  Umweg nicht**. Dazu: Restzahl der Backup-Codes und Neuerzeugung (Passwort
  **und** TOTP), Adresse hinterlegen oder ändern mit Bestätigung, Verweis auf
  die API-Schlüssel.

  Der Passwortwechsel beendet **alle** Sitzungen des Kontos und widerruft alle
  API-Schlüssel — dasselbe wie der erzwungene Wechsel.

- **`users.email` ist optional** (Teil von #348, vorgezogen). Ohne das ist die
  180-Tage-Regel nicht prüfbar; sie handelt ja von Konten ohne Adresse.

### Migration

`SCHEMA_VERSION` 12 → 15. Läuft beim nächsten Seitenaufruf.

## [0.8.0] – 2026-08-21

Die stabile Fassung der 0.8er-Linie. Inhaltlich ist sie `0.8.0-beta.2` plus
die Befunde eines Code-Scans, der vor der Freigabe lief — die beiden
Vorabversionen bleiben unten dokumentiert.

### Sicherheit

- **Pferdefotos waren über den statischen Pfad weiterhin ohne
  Sichtbarkeitsprüfung abrufbar** (#366). Mit #262 kam
  `/media/horse-image` samt der Zusage, dass für Fotos dieselben Regeln
  gelten wie für die Detailseite. Die Dateien lagen aber weiter unter
  `public/uploads/horses/`, also **im Webroot**: `public/.htaccess` leitet nur
  bei `!-f` auf den Front Controller um, eine existierende Bilddatei erreicht
  ihn also nie. Wer den Dateinamen kannte — aus der API, aus einem
  Bestandslink, aus dem Bildindex einer Suchmaschine — bekam das Foto auch
  nach einer Depublikation weiter mit HTTP 200 ausgeliefert. Der Dateiname
  ändert sich dabei nicht.

  Die Ablage liegt jetzt unter `storage/horses/`, also außerhalb des
  Webroots — dasselbe, was das Addon `galerie` von Anfang an tut. Ein
  Migrationsschritt verschiebt vorhandene Dateien (kopieren, Inhalt
  vergleichen, erst dann die Quelle löschen); bis er durch ist, liefert die
  geprüfte Route sie weiter aus dem alten Verzeichnis. Zusätzlich sperrt ein
  `public/uploads/horses/.htaccess` den statischen Weg hart — auch für
  Instanzen, auf denen die Verschiebung nicht durchläuft.

  **Für Docker-Installationen:** `docker-compose.yml` bringt dafür ein neues
  Volume `horses_data` mit. Nach dem Image-Wechsel einmal
  `docker compose up -d` mit der neuen Datei fahren, **bevor** die Migration
  läuft — sonst landen die Fotos in einem Pfad ohne Volume.

- **`GET /api/horses` gab den rohen Speicherpfad als `image_url` aus**
  (#368). Alle Views des Kerns und die Addons waren auf
  `MediaUrl::horseImage()` umgestellt, die JSON-API nicht — ausgerechnet die
  Stelle, auf die die Begründungen in den Addons zeigen. Ein
  API-Schlüssel mit `horses.view` genügte, um für jedes veröffentlichte Pferd
  den Dateinamen einzusammeln; gültig bleiben musste er danach nicht.

  **Nicht abwärtskompatibel:** Das Feld liefert jetzt
  `/media/horse-image?id=42`. Der bisherige Wert war eine Adresse ohne
  Zugriffsprüfung.

### Behoben

- **Ein Update brach ab, wenn ein Release ein neues Verzeichnis in einem
  neuen Verzeichnis mitbrachte** (#365). Die Vorabprüfung meldete
  „Verzeichnis kann nicht angelegt werden", weil `is_writable()` für einen
  noch nicht existierenden Elternordner `false` liefert — das Update endete,
  bevor eine einzige Datei angefasst wurde, beim unbeaufsichtigten Lauf
  täglich erneut. Der Datei-Zweig kannte den Fall längst und fing ihn ab; der
  Verzeichnis-Zweig hatte denselben Wächter nicht.

- **Die Referer-Regel in `public/uploads/.htaccess` wirkte invers** (#367).
  mod_rewrite ersetzt `%{HTTP_HOST}` nur in der TestString-Hälfte einer
  `RewriteCond`, nicht im Muster — die Bedingung traf deshalb nie, und die
  negierte Fassung galt für **jeden** nicht leeren Referer. Abrufe von der
  eigenen Seite bekamen 403, während der einzige Fall, den die Regel bewusst
  durchlassen wollte, ungeprüft durchlief. Der Block ist mit #366 ersatzlos
  entfallen.

- **Das Platzhaltermuster `nn` schloss echte Kontakte aus der
  Dublettensuche aus** (#370). Geprüft wurde per `str_contains()`, also auf
  Teilstring: Zimmermann, Hermann, Bachmann, Johanna, Sonnenhof — im
  deutschsprachigen Zuchtwesen ein erheblicher Teil des Bestands — flogen aus
  dem Kandidatenfeld, und ihre Dubletten erschienen nirgends. Die Seite
  meldete „nichts gefunden". Verglichen wird jetzt wortweise, und die Zahl
  der übersprungenen Platzhalter steht auf der Seite.

- **Die Addon-Deinstallation war nicht erreichbar** (#373). Controller,
  Ansicht und Datenregister waren gebaut und geprüft, die beiden Routen in
  `public/index.php` fehlten: `GET` wie `POST` auf
  `/admin/plugins/uninstall` ergaben 404. Der einzige Weg im Kern, Addon-
  Nutzdaten auf Knopfdruck zu entfernen, war tot ausgeliefert. Die Routen
  sind nachgetragen, und die Addon-Verwaltung verlinkt sie jetzt auch.

- **`?note[]=x` an `/admin/matches/label` löste einen TypeError aus**
  (#376). Ein Nicht-String gilt jetzt als „keine Notiz".

### Geschwindigkeit

- **Die Kontakt-Dublettensuche rechnete bei jedem Seitenaufruf neu** (#369).
  Sie ist ein Kreuzprodukt: 800 Kontakte sind 319.600 Paare. Je Paar liefen
  acht Normalisierungen mit je zwei `preg_replace` — derselbe Name wurde rund
  800-mal neu normalisiert, in Summe über 2,5 Mio. Aufrufe. Gemessen kostete
  ein Aufruf von `/admin/matches` dadurch rund **4,3 s**, und zwar auch beim
  Blättern durch die Pferde-Vorschläge darüber.

  Drei Änderungen: Die Normalisierung läuft einmal je Kontakt. Ein
  **beweisbar verlustfreier** Vorfilter überspringt `similar_text()` für
  Paare, die die Schwelle rechnerisch nicht mehr erreichen können
  (`similar_text` kann höchstens `2*min(len)/(lenA+lenB)` liefern). Und das
  Ergebnis liegt in einem Zwischenspeicher, dessen Fingerabdruck über die
  **Inhalte** von `contacts` und `match_labels` gebildet wird — nicht über
  Zeitstempel, die nur sekundengenau sind und zwei Änderungen derselben
  Sekunde nicht auseinanderhalten könnten.

  Ergebnis: 1,3 s kalt, 0,001 s warm.

  Das im Befund vorgeschlagene Blocking über Anfangsbuchstaben und SOUNDEX
  wurde **nicht** umgesetzt: Es setzt mindestens 88 % Namensähnlichkeit
  voraus, was nur ohne Ort-Stützung gilt — mit Ort, PLZ und Land genügen rund
  46 %, und dort hätte es echte Dubletten verschluckt. Ein Test hält das
  gegen die vollständige Bewertung fest.

- **Der Addon-Suchfilter lief als `FIND_IN_SET` über eine ungedeckelte
  ID-Liste** (#371). Das ist nicht sargable: Der Primärschlüssel blieb
  ungenutzt, und für jede Kandidatenzeile wurde die komplette Zeichenkette
  tokenisiert. Jetzt steht dort eine echte `IN`-Liste, deren Platzhalterzahl
  sich allein aus der Array-Länge ergibt — das Sicherheitsversprechen der
  Suchklassen (kein Anfragewert gerät je in einen SQL-String) hält damit
  weiterhin, durchgesetzt über einen eigenen Typ, den der Signatur-Wächter
  in `HorseSearchSqlSafetyTest` zulässt.

- **Die öffentliche Kontaktseite las die Deckstations-Pferde per `OR`/`EXISTS`
  ohne Obergrenze** (#372). Das `OR` zwischen Spaltenvergleich und
  korrelierter Unterabfrage macht beide Indizes unbrauchbar — jeder Aufruf
  von `/kontakt?id=…` kostete einen Durchlauf über alle Pferde, und die Seite
  ist öffentlich und von jeder Pferdeseite verlinkt, wird also auch von
  Crawlern durchlaufen. Jetzt eine `UNION` (zwei Index-Zugriffe, entdoppelt
  zugleich) mit einer Obergrenze von 200 Zeilen je Liste; wird gekürzt, sagt
  die Seite es.

### Tests

- Vorher ungeprüft, jetzt festgenagelt: die Verweigerung der
  Massen-Veröffentlichung von Kontakten ohne `contacts.publish` (#374), die
  serverseitige Update-Sperre am **Endpunkt** statt nur als Funktion (#375),
  die art-abhängige Rechteprüfung von `/admin/matches/label` (#376) und der
  gesamte Deinstallationspfad inklusive `DROP TABLE` (#373).

- `ContactSuggestionFinder` hatte keinen einzigen Test — jetzt gibt es einen,
  der die Bewertung gegen ein Orakel ohne Vorfilter hält.

### Migration

`SCHEMA_VERSION` 11 → 12. Der Schritt verschiebt die Pferdefotos aus dem
Webroot; er ist markergeschützt und wiederholt sich nicht. Bleibt eine Datei
liegen (Rechte), wird **kein** Marker gesetzt: Sie wird weiter ausgeliefert,
ist statisch gesperrt, und der nächste Lauf nimmt sie sich erneut vor.

## [0.8.0-beta.2] – 2026-08-20

Die Update-Automatik sagt jetzt die Wahrheit — und hält an genau der einen
Stelle an, an der sie es soll.

### Der Grundsatz

**Updates laufen automatisch, so wie es die beiden Einstellungen vorgeben:**
der Kanal (stabil / beta) bestimmt, welche Releases überhaupt in Frage kommen,
die Reichweite (nur Patch-Versionen der laufenden Linie / jede neue Version)
bestimmt, was davon unbeaufsichtigt eingespielt wird.

**Aufsicht braucht genau ein Fall:** ein aktives Addon, das die neue Version
nicht unterstützt und für das auch im Addon-Store keine passende Fassung
liegt. Dann verschwindet eine Funktion, und niemand kann sie zurückholen.

Ausdrücklich **nicht** das Kriterium ist der Versionssprung. Ein Wechsel auf
eine neue Linie, für den passende Addon-Fassungen bereitliegen, ist
unproblematisch — die Addon-Phase zieht sie nach dem Kern von selbst mit.

### Behoben

- **Der unbeaufsichtigte Lauf prüfte keinen Addon-Zustand** (#362). Er sah
  ausschließlich die Versionslinie an. Dass ein Minor-Sprung mit der Vorgabe
  `patch_only` trotzdem ausblieb, war ein **Nebeneffekt** und keine
  Zusicherung: Es galt nur, weil `core_supported_max` zufällig ebenfalls auf
  Major.Minor läuft. Wer die Reichweite auf `any` stellte, hatte gar keinen
  Schutz — der Kern wurde getauscht, und alle Addons der alten Linie waren
  danach fail-closed unsichtbar.

  Genau dieser Zustand bestand nach v0.8.0-beta.1: Kern-Release draußen,
  Addons-Release der Linie 0.8 noch nicht.

- **Ein einzelner Klick konnte Addons ersatzlos abschalten** (#364). Der
  manuelle Knopf spielte jede Version mit einem gewöhnlichen
  Bestätigungsdialog ein — auch dann, wenn Addons dabei ihre Funktion
  verloren. Ein Dialog wird mit „OK" beantwortet, ohne gelesen zu werden; das
  ist seine Funktion im Alltag. Wo eine Funktion ersatzlos verschwindet, ist
  das zu wenig Reibung.

  Jetzt verlangt der Knopf in **genau diesem Fall**, dass die Zielversion
  abgetippt wird — dasselbe Muster wie beim Löschen von Addon-Daten (#338).
  Blockiert wird nicht: Der manuelle Weg ist gerade der Ausweg, den die
  Automatik verweigert. Durchgesetzt wird das **serverseitig**; das
  Eingabefeld macht die Hürde nur sichtbar.

- **Die Update-Seite sagte nicht, ob eine Version automatisch eingespielt
  wird** (#364). Nebeneinander standen „Nur Patch-Versionen der laufenden
  Linie" und „📦 Neue Version verfügbar: 0.8.0-beta.1" — und nichts verband
  die beiden. Der naheliegende Schluss („die Automatik erledigt das") war
  falsch, und das Überspringen ist bewusst stumm. Der Betreiber wartete auf
  ein Update, das nie kam.

  Die Version wird weiterhin **angezeigt** — würde die Seite Versionen
  außerhalb der Reichweite verbergen, erführe niemand je, dass eine neue Linie
  existiert. Was fehlte, war der Satz dazu.

- **Dieselbe Lücke in der Benachrichtigungs-Mail** (#364). Sie schrieb
  „*sofern* die Version in den gewählten Rahmen fällt, wird sie beim nächsten
  täglichen Lauf eingespielt" — eine Bedingung, die der Leser nicht auflösen
  kann. Jetzt steht dort das Ergebnis.

- **Die Einwände eines Addons gegen eine Veröffentlichung sah niemand**
  (#335). `HorseController` legte sie in der Sitzung ab, und **keine View las
  sie**. Der Bearbeiter sah „gespeichert", das Pferd blieb unveröffentlicht,
  und der Grund stand nirgends. Ein Veto, dessen Grund niemand erfährt, ist
  von einem Fehler nicht zu unterscheiden.

- **Vier Hooks fehlten in der Dokumentation** — `horse.publish_blockers`,
  `horse.search_ids`, `home.sections_top` und `home.sections_bottom`. Sie
  waren in beta.1 gebaut, aber nirgends beschrieben. Dazu die Anmeldung
  eigener Captcha-Kontexte (`captchaContexts()`).

- **Der Doku-Satz zu `captcha.verify` war schädlich.** Er las sich wie ein
  Einzelaufruf; tatsächlich ist es eine **Filterkette**, durch die jedes
  installierte Anbieter-Addon läuft. Wer nicht zuständig ist, muss den
  hereingereichten Wert **unverändert** zurückgeben — gibt er `null` zurück,
  löscht er das Urteil des zuständigen Addons, das vor ihm lief.

### Neu

- Der Betreiber erfährt von einer zurückgestellten Version — **einmal je
  Zielversion**, und die Nachricht nennt den **Ausweg**, nicht nur die Sperre:
  Addon aktualisieren · deaktivieren (die Daten bleiben) · entfernen (mit
  Vorschau, wie viele Datensätze das kostet) · bewusst trotzdem einspielen.

  Eine Meldung, die eine Sperre beschreibt, ohne den Ausweg zu nennen, erzeugt
  genau den Zustand, den sie verhindern soll: Die Instanz aktualisiert sich
  nicht mehr, und niemand weiß, wie er das ändert.

  Sie ist bewusst **keine** Fehlschlag-Meldung — es ist nichts fehlgeschlagen.

- Die Update-Seite zeigt je betroffenem Addon, **ob** es Ersatz gibt: „passende
  Fassung liegt im Store und wird beim Update mitgezogen" gegen „im Store liegt
  keine passende Fassung". Nur das zweite hält auf.

## [0.8.0-beta.1] – 2026-08-20

**Breaking Change.** `persons` und `breeding_stations` sind eine Kontaktliste
geworden. Addons der Linie 0.7 sind nach dem Update **fail-closed unsichtbar**,
bis ein Addons-Release der Linie 0.8 installiert ist — das ist gewollt und
nicht behebbar, ohne den Schutz aufzugeben (`core_supported_max` vergleicht
Major.Minor).

**Vor dem Update sichern.** Der Umbau fasst jeden Kontakt und jede Zuordnung
an. Ein Rückweg ist gebaut und einmal gegangen (`database/rollback-336.php`),
aber er kostet alles, was nach dem Update entstanden ist.

### Geändert (Breaking)

- **Eine Kontaktliste statt zweier Tabellen** (#336). `persons` und
  `breeding_stations` werden `contacts`. Was ein Kontakt für ein bestimmtes
  Pferd *ist* — Züchter, Halter, Besitzer, Deckstation — steht seitdem
  ausschließlich an der Zuordnung, nicht mehr in der Wahl der Tabelle.

  Der Anlass ist keine Aufräumlust: Beim Bereinigen der Deckstationsdaten
  blieben **134 Freitexte** übrig, bei denen „Deckstation oder Person?" nicht
  beantwortbar war — und die Frage ist es auch nicht, weil ein Hof, den zwei
  Privatleute betreiben, beides ist. Sie verschwindet nicht durch eine bessere
  Heuristik, sondern dadurch, dass man sie nicht mehr stellen muss.

  **Was mit dem Datenschutz passiert.** Bis v0.7 schützte die Trennung selbst:
  Die öffentliche Personenseite wählte eine Positivliste von Spalten, die
  Stationsseite ein `SELECT *`. Ab v0.8 ist der Schutz ein Feld je Datensatz —
  deshalb gilt für **alle** Kontakte die strengere der beiden Regeln:
  `contact_public` und `is_published` haben die Vorgabe 0, und kein öffentlicher
  Pfad macht `SELECT *` auf `contacts`. Migrierte Deckstationen behalten ihren
  Bestandswert; eine Migration darf nichts wegnehmen, was vorher da war.

  **Rechte wandern als Schnittmenge, nicht als Vereinigung.** Wer nur
  `persons.view` *oder* nur `breeding_stations.view` hatte, hat `contacts.view`
  **nicht**. Die andere Richtung hätte Gruppen Zugriff auf personenbezogene
  Daten gegeben, den sie nie hatten. Die alten Zeilen liegen als JSON in
  `settings.migration_336_rechte_vorher` — ohne sie wäre der Rechte-Teil der
  einzige unumkehrbare Schritt.

  **Namensgleiche Kontakte werden NICHT automatisch zusammengeführt.** Das
  Issue skizziert es so; es wird bewusst anders gemacht. Zusammenführen ist
  nicht umkehrbar, verschiebt Pferdezuordnungen und senkt die Sichtbarkeit des
  Ergebnisses stillschweigend. Die Migration meldet die Fälle stattdessen und
  übergibt sie einem Menschen.

  **`horse_persons` behält ZWEI Steckplätze**, entgegen der Skizze im Issue.
  Eine Zuordnungszeile sagt zwei Dinge gleichzeitig — wer (in der Rolle aus
  `role`) und wo (an welcher Deckstation); das Formular rendert je Zeile beide
  Auswahlen, und `role` kennt nur `breeder|owner|keeper`. Sie zusammenzulegen
  hätte „Besitzer P, an Station S, von 2010 bis 2015" auf die Hälfte reduziert.
  Aus `person_id` wird `contact_id`, aus `breeding_station_id` wird
  `station_contact_id`; beide zeigen auf `contacts`.

- **`contact_id_map` bleibt dauerhaft** (#336). Keine Migrationshilfe, sondern
  Teil des Schemas. Addons speichern Verweise auf Kontakte, und mindestens
  eines tut das ohne Fremdschlüssel — `plugin_kontaktanfrage_optout` hält
  `(target_type, target_id)`. Person 5 und Station 5 gab es beide; ohne diese
  Tabelle zeigte jede gespeicherte Zeile auf einen falschen Kontakt, und beim
  Opt-out hieße das: Wer Kontaktanfragen abbestellt hat, ist wieder erreichbar,
  und jemand anderes ist stumm geschaltet.

- **Alte Adressen leiten dauerhaft um.** `/person?id=` und `/station?id=`
  antworten mit 301 auf `/kontakt?id=`, aufgelöst über `contact_id_map` — die
  Adressen stehen in Suchmaschinen. Eine unbekannte Kennung liefert 404 und
  wird **nicht** auf den Katalog umgeleitet: Eine tote Kennung darf nicht wie
  ein Treffer aussehen.

- **Hook-Namen.** Neu sind `contact.detail_sections`, `contact.edit_sections`,
  `contact.after_save` und `contact.deleted` (#347). Die alten Namen `person.*`
  und `station.*` feuern in v0.8 **zusätzlich** mit denselben Argumenten und
  entfallen in v0.9.0. Ein Addon, das beide alten Paare registriert hat,
  bekommt seit dem Zusammenlegen denselben Datensatz zweimal — das ist der
  Grund, die Aliasse nicht dauerhaft zu führen.

### Hinzugefügt

- **Addon-Daten lassen sich beim Deinstallieren entfernen** (#338).
  Deaktivieren und Deinstallieren sind ab jetzt zwei Dinge: Deaktivieren ist
  umkehrbar und lässt alles stehen, Deinstallieren fragt nach den Daten. Bis
  v0.7 verschwand ein Addon aus der Übersicht und liess alles liegen — darunter
  Kontaktanfragen mit Namen und E-Mail-Adressen, während der Betreiber annahm,
  er sei sie los.

  Ein Addon erklärt in seiner `plugin.json` unter `owns`, was ihm gehört
  (Tabellen, Verzeichnisse, Einstellungsschlüssel). Der Kern zählt vor dem
  Löschen zusammen, was tatsächlich verschwände — `1.284 Datensätze`, nicht
  `3 Tabellen` — und erst danach kommt die Frage. Was die Prüfungen nicht
  durchlassen (fremde Tabellen, Verzeichnisse ausserhalb der Installation,
  geschützte Orte), wird nicht gelöscht und ausdrücklich angezeigt.

- **Ein Suchendpunkt für Pferde im Adminbereich** (#341) samt
  wiederverwendbarem Suchfeld (`/js/horse-search.js`). Sieben Addons brachten je
  eine eigene Kopie mit; nur eine davon maskierte die SQL-Platzhalter `%` und
  `_`, und keine behandelte den Wettlauf zwischen zwei schnellen Anfragen.

- **Erweiterungspunkte auf der Startseite** (#356): `home.sections_top` und
  `home.sections_bottom`. Ausgerechnet die meistbesuchte Seite hatte bis v0.7
  keinen einzigen.

- **Veto gegen das Veröffentlichen** (#335): Der Filter
  `horse.publish_blockers` lässt ein Addon verhindern, dass ein widersprüchlicher
  Datensatz öffentlich wird — **ohne** das Speichern anzutasten. Wer seine
  halbfertige Eingabe nicht speichern kann, kommt nie an den Punkt, an dem er
  den Widerspruch auflöst. `horse.before_save` bleibt deshalb ein `doAction`.

- **Captcha-Kontexte sind anmeldbar** (#351). Bis v0.7 kannte `$context` nur
  `'dsgvo'`; die öffentlichen Formulare dieses Systems liegen aber überwiegend
  in Addons. Ein unbekannter Kontext schaltet den Schutz **nicht** ab, sondern
  erzwingt den eingebauten — ein Tippfehler macht ein Formular höchstens
  strenger, nie ungeschützter.

  Der Betreiber wählt den Anbieter unter *Systemeinstellungen* je Formular. Das
  ist mehr als Bequemlichkeit: Im DSGVO-Formular machen Betroffene ihre Rechte
  aus Art. 15/17 geltend, und ihre IP-Adresse dabei an einen Drittanbieter zu
  übertragen ist ausgerechnet dort kaum zu rechtfertigen — anderswo dagegen
  schon.

- **Ein schmaler Weg zum Protokoll für Addons** (#352):
  `App\Plugin\PluginAudit::log()`. Die Kategorie ist der Slug, der Slug wird
  geprüft (ein Addon kann nicht unter fremdem Namen protokollieren), und der
  Bezug ist ein eigenes Argument — „Dokument gelöscht" ohne Angabe, welches,
  hilft hinterher niemandem.

- **Der Datenbank-Export kann eine Tabellenauswahl** (#342).
  `DatabaseDumper::dumpTo()` nimmt optional eine Positivliste; `null` bleibt
  „alles", weil das automatische Backup genau das braucht. Grundlage für einen
  Export, der nicht länger zwangsläufig Passwort-Hashes, 2FA-Geheimnisse und
  API-Schlüssel mitnimmt.

- **Dubletten lassen sich entscheiden, nicht nur annehmen** (#355). Bis v0.7
  konnte man einen Vorschlag verknüpfen — die Aussage „das sind zwei
  verschiedene Pferde" wurde nirgends gespeichert. Also erschien dasselbe Paar
  bei jedem Aufruf wieder, und der E-Mail-Digest zählte es dauerhaft als offen;
  wer einmal geprüft und verworfen hatte, prüfte beim nächsten Mal erneut.

  Jetzt trägt jedes Paar eine dauerhafte Entscheidung (*zusammengeführt* ·
  *verschieden* · *unklar*) mit Urheber, Zeitpunkt und optionalem Beleg.
  „Verschieden" blendet dauerhaft aus — und ist **widerrufbar**, denn eine
  falsch gesetzte Trennung darf nicht endgültig sein. Ein Label ändert nichts
  am Bestand; Zusammenführen bleibt eine Einbahnstraße mit Vorschau.

  Neu ist auch die Vorschlagssuche **für Kontakte** — die gab es für Personen
  nie und für Deckstationen erst recht nicht. Bei der Bereinigung enthielten
  41 Deckstationen acht Dubletten, die von Hand gefunden werden mussten: Im
  Produkt gab es keine Stelle, die sie zeigt. Platzhalter wie
  „Nichtmitglied NO" nehmen bewusst **nicht** teil — sie unterscheiden sich nur
  im Länderkürzel, und jede Ähnlichkeitsmetrik hielte sie für denselben Kontakt.

- **Suchfelder, die es längst hätte geben müssen** (#346): Halter-Rolle,
  Stockmaß, Todesjahr, Geburtsdatum und Beschreibung. Die Spalten gab es im
  Bestand seit Langem, durchsuchen ließ sich nichts davon. Dazu ein
  Erweiterungspunkt für Addon-Filter (`horse.search_ids`) — bewusst als
  **ID-Liste**, nicht als SQL-Ausschnitt: Die ganze Bauart dieser Klassen
  beruht darauf, dass kein Anfragewert je in einen SQL-String gerät, und ein
  Addon, das SQL beisteuern darf, macht diese Zusicherung dauerhaft zunichte.

- **`HV_TEST_PORT`**: Framework- und Addon-Testsuite benutzten denselben
  Testserver-Port und brachen sich gegenseitig ab. Der Abbruch bei belegtem
  Port bleibt — ein Lauf gegen eine fremde Instanz ist kein Ergebnis —, aber es
  gibt jetzt einen Ausweg, der nicht „warte, bis der andere fertig ist" heisst.

- **`HV_TEST_DB_PREFIX`**: Zwei Integrationstests legen Wegwerf-Datenbanken mit
  festen Namen an (`hengst_…`). In der CI ist das folgenlos, auf einem
  gemeinsam genutzten Entwicklungshost scheitern damit alle Tests der Klasse an
  fehlenden Rechten — und der naheliegende Ausweg (dem Benutzer die Rechte
  geben) verändert die Umgebung für alle und muss hinterher zurückgenommen
  werden. Ohne die Variable bleibt alles wie bisher.

### Behoben

- **Ohne Kontaktfreigabe stand der Ort zweimal untereinander** auf der
  öffentlichen Kontaktseite — einmal als Verortung, einmal als vermeintliche
  Anschrift. Die PLZ fehlt ohne Freigabe, die Adresszeile bestand dann allein
  aus dem Ort.

- **`contacts` konnte sich nicht selbst heilen.** Vor #336 hatte jede Spalte
  von `persons` und `breeding_stations` ihren eigenen Migrationsschritt —
  deshalb fing eine Installation, der eine Spalte abhandengekommen war, sich
  beim nächsten Lauf wieder ein. Für die neue Tabelle fehlte das Gegenstück,
  und `CREATE TABLE IF NOT EXISTS` ergänzt keine fehlende Spalte.

### Nicht enthalten

- **Sprachen als Sprach-Addons** (#344) ist nach v0.9.0 gewandert. Ein halber
  Stand wäre dort kein Teilziel, sondern ein Rückschritt: „Sprache aus `lang/`
  entfernt" heißt ohne den Addon-Unterbau schlicht, dass es die Sprache nicht
  mehr gibt — und das träfe genau die Benutzer, die kein Deutsch und kein
  Englisch lesen. Der Kern behält in v0.8.0 alle dreizehn Sprachen.

- **Galerie als Kernmodul** (#339) und der daran hängende Addon-Umbau
  (Addons#116) sind nach v0.9.0 gewandert. Die Mechanik, mit der ein
  Kern-Update ein abgelöstes Addon entfernen darf, ist gebaut und dokumentiert;
  `UpdateService::ABGELOESTE_ADDONS` ist aber **leer**. Ein Eintrag ohne den
  Kern-Ersatz wäre kein halbes Feature, sondern ein Schaden — das Update
  entfernte das Addon, und die Betreiber stünden ganz ohne Galerie da.

## [0.7.2] – 2026-08-20

Vier Fehlerbehebungen ohne Schemaänderung. Addons der Linie 0.7 laufen
unverändert weiter (`core_supported_max` vergleicht Major.Minor).

### Behoben

- **Katalog: Seitenzahl und Seitenwechsler stapelten sich beim Nachladen**
  (#337). `public/js/catalog-filter.js` blendet die serverseitige
  Seiten-Navigation aus, sobald JavaScript übernimmt — aber nur beim Start und
  nach einem Filterwechsel, der das Grid *ersetzt*. Der Nachlade-Pfad hängt die
  Server-Teilansicht an, und `public_catalog_cards.php` rendert ihren eigenen
  `[data-catalog-pagination]`-Block mit. Mit jeder nachgeladenen Seite kam damit
  ein weiterer Seitenwechsler mitten in die Kartenliste („2 / 5", Karten,
  „3 / 5", …). Das `hidden` vom Start galt nur für den Knoten, der damals da
  war; die Absicht stand längst als Kommentar im Code, nur der Nachlade-Pfad
  setzte sie nicht um.

- **Katalog: Kacheln ohne Bild standen 60 px höher** (#350).
  `public_catalog_cards.php` gab dem Bildfall 180 px und dem Platzhalter mit
  dem 🐴 nur 120 px. Der Textteil einer bildlosen Kachel begann dadurch weiter
  oben als bei jeder Kachel mit Foto — unten glich sich der Knopf über `flex`
  wieder an, Überschrift und Datenzeilen dazwischen blieben versetzt. Der
  Platzhalter hat jetzt dieselbe Höhe; die Überlegung aus #263 (feste Höhe,
  damit beim Nachladen kein Layout-Sprung entsteht) gilt für ihn genauso.

- **„bis heute" bei gestorbenen Pferden** (#334). Ein offenes `until_year` in
  den Personen-/Stationszeilen heißt „läuft noch". Bei einem gestorbenen Pferd
  behauptete das eine laufende Aufstallung: Für ein 2017 verstorbenes Pferd
  stand dort „Halter / Deckstation (2009 - heute)". An die Stelle tritt jetzt
  das Todesjahr, wenn es bekannt ist, sonst „?" — geraten wird nichts.

  Dazu die fehlende **Prüfung beim Speichern**: Für Geburts- und Todesjahr gab
  es sie längst (`death_before_birth`), für die Abstammung ebenso — für die
  Zeiträume in `horse_persons` fehlte das Gegenstück, und im Bestand standen
  dadurch Halterzeiträume, die *nach* dem Todesjahr beginnen. Ein solcher
  Zeitraum wird nun mit `period_after_death` abgelehnt.

- **Telefonnummern sind `tel:`-Links** (#359). Öffentlich gezeigte Nummern
  waren reiner Text; auf dem Telefon ließ sich damit nicht wählen, ohne sie
  abzuschreiben. Die E-Mail-Adresse in derselben Tabelle ist längst ein
  `mailto:`-Link, die Website ein geprüfter externer Link — nur das Telefon
  blieb Text. Der neue Helfer `App\Helper\TelUrl` macht die Nummer für das
  `href` maschinenlesbar.

  **Eine Landesvorwahl wird dabei nicht erfunden**: Aus einer führenden `0` ein
  `+49` zu machen wäre geraten, und der Bestand enthält Deckstationen unter
  anderem in Dänemark und Norwegen. Die geklammerte Null der internationalen
  Schreibweise (`+49 (0) 301 …`) entfällt dagegen — sie bedeutet genau das.
  Was keine eindeutige Nummer ist (Freitext, Zusätze wie „nur vormittags"),
  bleibt unverlinkter Text. Die Sichtbarkeit ändert sich nicht: verlinkt wird
  nur, was ohnehin schon öffentlich dasteht.

## [0.7.1] – 2026-08-19

Reine Fehlerbehebung: die vierzehn Befunde des Codescans vom 2026-08-18
(#309–#322). Keine neuen Funktionen, keine Schemaerweiterung über die eine
nachgeholte Spalte hinaus — Addons der Linie 0.7 laufen unverändert weiter
(`core_supported_max` vergleicht Major.Minor).

### Sicherheit

- **`/media/horse-image` verlangt eine gültige Sitzung** (#314). Die Route
  hob die `is_published`-Sperre allein anhand der Anwesenheit von
  `$_SESSION['user_id']` auf und rief `checkAuth()` an keiner Stelle auf.
  Damit galt hier keine der Sitzungsprüfungen des übrigen Backends:
  gelöschtes oder deaktiviertes Konto, `session_version` nach einem
  Passwortwechsel (#113), User-Agent-Fingerprint, Inaktivitäts-Timeout. Ein
  Angreifer mit einem gestohlenen Cookie flog auf `/admin/horses` sofort auf
  `/login`, konnte über diese Route aber weiter jedes Foto abrufen — auch
  die unveröffentlichter und nach DSGVO-Widerspruch depublizierter Pferde.

- **Zugriffsabhängige Bilder sind nicht mehr zwischenspeicherbar** (#315).
  Jede 200er-Antwort trug `Cache-Control: public, max-age=1 Jahr`. Für einen
  gemeinsamen Zwischenspeicher (nginx `proxy_cache`, Varnish, CDN) war die
  URL damit eine statische Ressource: Öffnete ein Redakteur die
  Bearbeitungsseite eines unveröffentlichten Pferds, lag dessen Foto danach
  ein Jahr im Cache und ging von dort an jeden Gast. Der unveröffentlichte
  Zweig trägt jetzt `private, no-store`.

- **Deckstationen im Personenblock von `/horse` folgen den Rechten** (#316).
  Die Zuordnungsabfrage holte `bs.name`/`bs.id` ein zweites Mal und war von
  den Guards aus #122/#151 nicht erfasst. Wer der Gast-Gruppe
  `breeding_stations.view` entzogen hatte, bekam auf `/station?id=7` korrekt
  404 — im Block „Zucht & Personen" stand die Station trotzdem, samt Link
  auf genau diese Seite. Zweiter Weg: Bei depublizierter Station fiel die
  Anzeige auf `horse_persons.breeding_station_text` zurück, wo bei Import und
  Formular derselbe Name noch einmal steht.

### Behoben

- **`persons.contact_public` fehlte nach dem Update dauerhaft** (#309,
  kritisch). `SchemaMigrator::migrate()` ergänzte die Spalte mit
  `AFTER is_breeder`, legte `is_breeder` aber erst rund 550 Zeilen später an
  — in derselben, strikt von oben nach unten laufenden Methode. Auf jeder
  Installation ohne `is_breeder` (schema_version < 6) scheiterte das ALTER,
  der Fehler wurde verschluckt, und `schema_version` wurde trotzdem auf 8
  gesetzt. Folge: jedes Speichern einer Person warf eine PDOException, die
  öffentliche Personenseite endete mit HTTP 500. Betroffen waren
  Bestandsinstallationen, die von 0.5.x direkt auf 0.7.0 gingen.

  `$addColumn` verschluckt Fehler jetzt nur noch bei der Existenzprüfung;
  steht die Tabelle nachweislich, schlägt ein Fehlschlag des ALTER nach oben
  durch und der Schritt wird beim nächsten Lauf wiederholt.
  `SCHEMA_VERSION` 8 → 9, damit betroffene Instanzen die Spalte nachziehen.

- **Personen-Merge verwarf Zuordnungen mit Deckstation oder Herkunftsland**
  (#310). Der Dublettenschlüssel verglich nur `horse_id`, `role`,
  `from_year` und `until_year`; was dadurch als „exaktes Doppel" galt, wurde
  hart gelöscht. Bei `role='breeder'` sind `from_year`/`until_year`
  zwangsläufig NULL — zwei Züchter-Zuordnungen desselben Pferds stimmten in
  allen vier Spalten also immer überein, und die Deckstation bzw. das
  Herkunftsland war nach dem Zusammenführen unwiederbringlich weg.

- **Pferde im Papierkorb waren weiter beschreibbar** (#322). Der
  Schreibschutz aus #296 kam für Personen und Deckstationen, der
  `HorseController` blieb unangetastet. Ein Speichern am gelöschten
  Datensatz setzte `is_published`, löschte über `remove_image` die Bilddatei
  physisch von der Platte und baute `horse_persons` und
  `horse_registrations` neu auf. Der Guard sitzt bewusst vor der
  Bildbehandlung — ein `AND deleted_at IS NULL` am UPDATE allein käme zu
  spät.

- **Zuordnungen werden atomar gespeichert** (#317). `saveHorsePersons()`
  löschte erst alle Zeilen und fügte sie dann einzeln neu ein. Schlug ein
  INSERT fehl, war das DELETE bereits festgeschrieben: Das Pferd stand ohne
  jede Zuordnung da, der Request endete mit 500, und weil die Ausnahme auch
  das Änderungs-Protokoll übersprang, blieb nicht einmal ein Hinweis darauf,
  dass es die Zuordnungen gab. Unbekannte `person_id`/`breeding_station_id`
  werden jetzt vor dem INSERT auf NULL gesetzt, DELETE und INSERTs stehen in
  einer Transaktion.

### Geändert

- **Pferdefotos laufen nicht mehr durch den kompletten Bootstrap** (#311).
  Eine Katalogseite zeigt 24 Karten und löste damit 24 zusätzliche
  PHP-Requests aus; jeder fuhr `PluginManager::boot()` (stat()et rekursiv
  jede Datei jedes aktivierten Plugins), `SetupController::needsSetup()` mit
  Dreifach-JOIN und die Registrierung der drei Cron-Aufgaben. Die
  Zugriffsentscheidung bleibt vollständig im `MediaController` — nur der
  Bootstrap entfällt. Die Sitzung wird ausserdem unmittelbar nach der
  Prüfung freigegeben statt erst beim Ausliefern.

- **Endlos-Nachladen im Katalog ohne wiederholtes `COUNT(*)`** (#320). Der
  Zähler lief über den dreifachen Selbst-JOIN bei jedem Scrollschritt,
  obwohl der Client die Zahl längst hat; beim Durchscrollen von 3200 Pferden
  sind das gut 130 Wiederholungen. Die Züchter-/Besitzernamen werden
  zusätzlich erst nach dem LIMIT aufgelöst statt über eine materialisierte
  Ableitung über den Gesamtbestand.

- **Merge-Formular mit gedeckelter Suche** (#312). `mergeForm()` holte den
  kompletten Personenbestand ohne LIMIT in ein `<select>` — bei 20.000
  Personen rund 1,1 MB Markup je Seitenaufruf. Jetzt Suchfeld über Name, Ort
  und PLZ mit 50 Treffern; die Maske sagt ausdrücklich, wenn sie kürzt.

- **DSGVO-Personensuche zweistufig** (#318). Erst die Präfixsuche, und nur
  wenn sie den Deckel nicht füllt, die teure Enthält-Suche; `horse_count`
  kommt als Unterabfrage statt aus JOIN und GROUP BY. Mindestlänge 2 → 3.

- **`/admin/updates` lädt nicht mehr das komplette Addon-Tarball** (#319).
  Der Katalog wird vom nächtlichen Lauf warm gehalten; wer den Stand sofort
  braucht, klickt „Katalog jetzt auffrischen". Ein fehlgeschlagener Abruf
  wird jetzt ebenfalls gestempelt — vorher versuchte es jeder Seitenaufruf
  erneut und hing bei nicht erreichbarem GitHub bis zu 20 s.

  Wie weit das reichte, zeigte sich beim Zusammenführen dieser Reihe: Die
  Functional-Suite selbst fiel auf `main` reproduzierbar mit
  `Operation timed out after 10002 milliseconds` auf `/admin/updates` um,
  und weil `php -S` einthreadig ist, riss die hängende Anfrage die
  nachfolgende gleich mit — einmal traf es sogar `/login`. Der synchrone
  Abruf machte damit nicht nur eine Anzeigeseite langsam, sondern die
  Testläufe des Repos von der Erreichbarkeit und Drosselung von GitHub
  abhängig.

- **Die drei Sperren der Update-Automatik in sinnvoller Reihenfolge** (#313).
  Die Container-Prüfung (`UPDATE_IN_PLACE=false`) stand hinter der
  Backup-Sperre und war damit praktisch unerreichbar: Der Betreiber einer
  Container-Installation bekam die Aufforderung, ein externes Backup
  einzurichten — und danach dieselbe Ablehnung aus einem anderen Grund.

### Tests

- Verweigerungsfälle, die bisher fehlten: CSRF und Löschrecht beim
  Personen-Merge (#321), CSRF, Admin-Pflicht und alle drei Sperren der
  Update-Automatik (#313). Jeder Ablehnungsfall belegt zusätzlich
  datenbankseitig, dass nichts geschrieben wurde — ein 403 sagt nur, was die
  Antwort war, nicht was vorher schon passiert ist.

## [0.7.0] – 2026-08-18

### Hinzugefügt


- **Suche und Seitenblättern in den drei Verwaltungslisten** (Pferde,
  Personen, Deckstationen). Bisher gab es dort gar keine Suche, und jede
  Liste lud den kompletten Bestand ohne `LIMIT` — in der Entwicklungsinstanz
  über 3200 Pferde auf einer Seite. Jetzt: ein allgemeiner Suchbegriff, ein
  aufklappbarer Block mit Detailfiltern und 50 Zeilen je Seite.

  Bei den **Pferden** sind es dieselben Filter wie im öffentlichen Katalog,
  denn beide nutzen seit dieser Änderung dieselben Bausteine — zwei Fassungen
  dieser Logik wären auseinandergelaufen, und die zurückbleibende wäre die mit
  den Sichtbarkeitsregeln gewesen. Es sind bewusst **zwei**:
  `App\Service\HorseSearchCriteria` liest die Anfrage und bindet die Werte,
  `App\Service\HorseSearchSql` erzeugt Klausel und JOINs und bekommt die
  Anfragewerte gar nicht erst zu sehen; über die Grenze geht nur ein
  `App\Service\HorseSearchCondition`. Eine Klasse, die beides täte, hielte den
  nächsten Missgriff („hier reicht doch schnell ein Spaltenname aus dem
  Request") stets in Reichweite. **Personen** lassen sich zusätzlich nach Ort,
  PLZ, Bundesland/Kanton, Land, E-Mail, Mitgliedsstatus und dem Kennzeichen
  „nur Züchter" filtern, **Deckstationen** nach Ansprechpartner und Anschrift.

  Der Unterschied zwischen beiden Kontexten hängt an einem einzigen Schalter:
  Der Katalog schränkt verknüpfte Personen, Stationen und Elterntiere überall
  auf `is_published = 1` ein (#121/#122/#151, sonst wäre der Filter ein
  Existenz-Orakel für depublizierte Namen), die Verwaltung tut das
  ausdrücklich nicht — wer freigeben soll, muss das Unveröffentlichte auch
  finden. Beide Hälften dieser Zusicherung sind getestet. Gelöschte
  Datensätze bleiben in beiden Fällen draußen, dafür gibt es den Papierkorb.

- **Menüpunkte aus Addons in der öffentlichen Navigation.** Neuer Filter
  `layout.nav_items`. Die Navigation war fest verdrahtet; ein Addon konnte
  sich nur über eine Dashboard-Kachel und Textlinks auf Detailseiten
  behelfen. Anlass ist die Zucht-Suche
  ([Addons #107](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/107)),
  die als eigener Menüpunkt „Zucht" neben dem Verzeichnis stehen soll.

  Anders als bei den `*_sections`-Hooks liefert ein Addon hier **keinen
  fertigen HTML-String**, sondern Daten, die der Kern prüft
  (`App\Helper\NavItems`): nur seiteneigene absolute Pfade, höchstens fünf
  Einträge, Beschriftung einzeilig und gekürzt. Abgewiesen werden
  `javascript:`, `data:`, fremde Domains, protokollrelative Adressen
  (`//fremd.example` — beginnt mit einem Schrägstrich, führt aber auf einen
  fremden Host), `..`, Backslashes und Steuerzeichen. Der Grund für die
  strengere Behandlung: Die Navigation steht auf jeder öffentlichen Seite,
  ein Fehler dort wirkt überall.

- **Kontaktdaten lassen sich je Datensatz freigeben.** Neu ist
  `contact_public` bei Personen und Deckstationen. Bei **Personen** ist die
  Vorgabe `0`: E-Mail, Telefon und Mobil bleiben intern, bis jemand sie
  ausdrücklich freigibt. Bei **Deckstationen** ist die Vorgabe `1`, denn dort
  waren Telefon und E-Mail seit jeher öffentlich (Geschäftsadresse) — eine
  Vorgabe von `0` hätte bestehende Angaben stillschweigend versteckt, und eine
  Migration darf nichts wegnehmen, was vorher da war.

  Der Unterschied zum Fehler aus #293 ist nicht das Ergebnis, sondern die
  Absicht: Dort wurde ein als „sonstige Kontaktinformationen" beschriftetes
  Freitextfeld **versehentlich** öffentlich gerendert. Hier entscheidet die
  Redaktion je Datensatz und sieht im Formular, was das bedeutet. Die
  Personenseite holt die Kontaktspalten auch nur dann aus der Datenbank —
  was gar nicht erst ankommt, kann niemand versehentlich ausgeben.

- **Zwei neue Erweiterungspunkte für Addons:** `person.detail_sections` und
  `station.detail_sections`, nach dem Muster von `horse.detail_sections`.
  Damit kann ein Addon auf der Personen- und der Deckstationsseite einen
  eigenen Abschnitt rendern — Anlass ist die geplante Kontaktanfrage
  ([Addons #106](https://github.com/Celestial0579/Hengstverzeichnis_Addons/issues/106)),
  die ein Formular anbieten soll, **ohne** dass dafür eine Adresse öffentlich
  werden muss.

### Behoben


- Die Leiste „Alle / Veröffentlicht / Nicht veröffentlicht" baute ihre Links
  ohne die übrigen Parameter. Ein Klick warf damit alles weg, was der
  Benutzer vorher eingestellt hatte; jetzt reisen Suche und Seite mit — auch
  über die Massen-Veröffentlichung hinweg.

- **Die Update-Automatik warnt, wenn ihre Benachrichtigung nicht zugestellt
  werden kann.** „Wer automatisch einspielen lässt, muss erfahren, was
  passiert ist" war die Bedingung des Entwurfs aus 0.6.0 — geprüft wurde
  aber nie, ob überhaupt Mail rausgeht. Auf einer Instanz mit eingeschalteter
  Automatik und ohne SMTP-Konfiguration war die Bedingung damit formal
  erfüllt und praktisch wirkungslos: Ein Update liefe stumm durch, nachlesbar
  nur im Audit-Log. `/admin/updates` sagt das jetzt deutlich, mit
  ausdrücklichem Hinweis auf das unbemerkte Aktualisieren.

  Eine Ebene tiefer sitzt derselbe Fehler noch einmal: Der Transport kann
  stehen und die Mail trotzdem niemanden erreichen. Auf derselben Instanz
  zeigten drei von vier Admin-Konten auf `@migration.invalid` aus einer
  Altdatenmigration — eine Endung, die nach RFC 2606 reserviert und niemals
  zustellbar ist. `UpdateService::hasReachableAdminRecipient()` prüft das
  jetzt und meldet es getrennt vom Transport. Bewusst nur, was sich sicher
  sagen lässt: reservierte Endungen und offensichtlich kaputte Adressen. Ob
  eine plausible Adresse wirklich ankommt, weiß erst die Warteschlange des
  Mailservers — eine Prüfung, die das behauptete, wäre selbst wieder der
  Fehler, den sie finden soll.

  **Grenze beider Hinweise:** Sie stehen unter `/admin/updates` und helfen
  dem, der ohnehin nachsieht — nicht dem, der sich auf die Automatik
  verlässt.

- **Das Zusammenführen von Personen nennt sein Ergebnis.** Bisher stand dort
  nur „Aktion erfolgreich ausgeführt", die Zahlen allein im Audit-Log. Wer
  Quelle und Ziel vertauscht, verliert dank NULL-Fill zwar keine Daten — der
  überlebende Datensatz trägt dann aber den falschen Namen, und das fiel
  nirgends auf. Die Liste nennt jetzt umgehängte Zuordnungen, verworfene
  Doppel und ergänzte Felder; ab drei ergänzten Feldern weist sie
  ausdrücklich darauf hin, dass die Paarrichtung wahrscheinlich verdreht war.
  (Anlass: Bei einer Datenmigration auf diesem Host trug in vier von zehn
  Paaren ausgerechnet der aufgegebene Datensatz die Adresse.)

### Geändert

- `SCHEMA_VERSION` 7 → 8. Die Migration läuft beim nächsten Seitenaufruf.
- Referenz-Plugin und Beispiel in der Plugin-Dokumentation auf
  `core_supported_max: "0.7"`.

## [0.6.0] – 2026-08-17

### Hinzugefügt

- **Öffentliche Personenseite** `/person?id=N` (#293) als Gegenstück zu
  `/station`. Die Pferde-Detailseite verlinkt den Personennamen jetzt dorthin —
  beide Einträge sind vom Pferd aus anklickbar. Der Controller wählt die
  Spalten **einzeln** aus statt `SELECT *`: `persons` führt E-Mail, Telefon,
  Mobil, Straße und das interne `contact_info`, und was gar nicht erst in der
  View ankommt, kann auch niemand versehentlich ausgeben. Die Gast-Gruppe
  bekommt dafür `persons.view`; neue öffentliche Daten entstehen dadurch nicht.

- **Züchter-Kennzeichen** `persons.is_breeder` (#293) als Grundlage für eine
  spätere Zucht-Suche über Züchter und Deckstationen. Ausdrücklich **nicht**
  aus `horse_persons.role='breeder'` abgeleitet: Wer noch kein Pferd im
  Verzeichnis hat, wäre sonst nicht auffindbar, und wer *früher* gezüchtet hat,
  bliebe dauerhaft markiert. Das Kennzeichen sagt „züchtet heute", die
  Zuordnungen sagen „hat dieses Pferd gezüchtet".

- **Kontaktfelder für Personen** (#293): `phone`, `mobile` und `website`,
  analog `breeding_stations`. Telefon und Mobil sind zustellbar und damit
  intern wie die E-Mail-Adresse; die Website ist zur Veröffentlichung bestimmt.

- **Herkunftsland ohne bekannte Person** (#294): `horse_persons.origin_country`
  als dritte Gültigkeits-Alternative neben Person und Deckstation. Das
  Altsystem kannte „der Züchter ist nicht bekannt, aber er kam aus Norwegen" —
  ohne dieses Feld musste dafür eine **Platzhalter-Person** in der PII-Tabelle
  angelegt werden, die dann als echter Züchtername im Katalog erschien und
  durch DSGVO- und Papierkorb-Mechanik lief.

- **Personendubletten zusammenführen** (#297) unter `/admin/persons/merge`, mit
  Vorschau der betroffenen Zuordnungen. Die Reihenfolge ist der Kern und stand
  bisher nirgends: erst umhängen, **dann** die Quelle in den Papierkorb.
  Andersherum ist es ein stiller Datenverlust — `horse_persons.person_id` trägt
  `ON DELETE CASCADE`, und der Papierkorb löscht Personen hart.

- **Freitextfeld für Deckstationen im Pferdeformular** (#295). Es fehlte, und
  genau daran hing der Datenverlust unten.

- **Unbeaufsichtigte Update-Prüfung, Benachrichtigung und Installation**
  (#290, zweite Stufe aus #85). Bisher gab es Updates nur auf Knopfdruck; das
  ursprüngliche Feature-Issue hatte den periodischen Lauf über die
  Cron-Infrastruktur (#67) ausdrücklich als Folgeschritt vorgesehen, das
  Pflicht-Backup war dafür die genannte Voraussetzung. Zwei neue
  Scheduler-Aufgaben schließen das:

  - `update.check` (alle 3 Stunden) prüft Kern **und** Addons und
    benachrichtigt alle Admin-Konten per E-Mail — **einmal je Fund**, nicht
    bei jeder Prüfung erneut. Der gemeldete Stand wird mitgeführt, ein
    erledigtes Addon fällt automatisch heraus.
  - `update.auto_install` (höchstens einmal täglich) spielt ein Update
    unbeaufsichtigt ein und meldet Erfolg wie Fehlschlag sofort per E-Mail.
    Verwendet unverändert `performUpdate()` und damit dieselbe Reihenfolge
    wie der manuelle Knopf: Pflicht-Backup → Kern → Addons.

  Beides ist **Opt-in** — ohne Konfiguration ändert sich für bestehende
  Installationen nichts, wie bei Backup und Digest. Die Reichweite der
  Installation wählt der Betreiber: `nur Patch-Versionen der laufenden Linie`
  (Standard) oder `jede neuere Version`. Der zurückhaltende Standard ist
  Absicht — solange die Versionierung bei `0.y.z` steht, sind Breaking
  Changes laut Format dieser Datei jederzeit möglich. Automatisch
  installieren lässt sich nur zusammen mit der Benachrichtigung: ein stiller
  Codeaustausch auf einem Produktivsystem wäre genau der Vorgang, den
  niemand bemerkt, bis etwas fehlt.

  Ein Fund gilt erst dann als gemeldet, wenn tatsächlich eine E-Mail
  hinausging. Andernfalls wäre ein Ausfallfenster des Mailservers — oder ein
  Admin-Konto ohne hinterlegte Adresse — endgültig: Die Version stünde als
  erledigt im Merkzettel und würde auch nach Behebung der Ursache nie wieder
  gemeldet. Verschwundene Addon-Updates fallen unabhängig davon heraus.

- **Wartungsmodus während des Update-Einspielens.** `Maintenance` (#232)
  existierte bislang nur für das Datenmigrations-Addon und wurde vom Kern
  nirgends genutzt. Solange ein Update einen anwesenden Admin voraussetzte,
  war das folgenlos; bei einem unbeaufsichtigten Lauf kann jederzeit ein
  echter Besucher mitten in den Dateiaustausch geraten. Der Marker umschließt
  jetzt Dateiaustausch und Addon-Phase — Backup und Download bleiben bewusst
  außerhalb, damit das Fenster kurz bleibt. Ein `finally` stellt sicher, dass
  ein abgebrochenes Update die Installation nicht dauerhaft auf 503 nagelt.

### Behoben

- **Datenverlust: Jedes Speichern eines Pferds löschte Freitext-Zuordnungen**
  (#295). `saveHorsePersons()` löscht alle Zeilen und baut sie aus dem Request
  neu auf — das Formular rendert für `breeding_station_text` aber kein Feld,
  der Wert kam also nie zurück, und eine Zeile ohne Person und ohne Stations-ID
  galt als ungültig. Wer nur die Farbe änderte, verlor die Herkunftsangaben.
  Betroffen war praktisch der gesamte Importbestand (482 Zeilen bei 205
  Pferden in der Dev-Instanz), und die Angaben waren öffentlich sichtbar.

  Behoben in drei Teilen: Bestandstexte werden vor dem Löschen gelesen und
  wiederverwendet, wenn der Request den Schlüssel gar nicht enthält (ein
  übermittelter **Leerstring** löscht weiterhin bewusst); ein Marker
  `persons_present` trennt „dazu sage ich nichts" von „keine Zuordnungen" —
  bisher löschte **jeder** POST ohne `persons`-Block sämtliche Zuordnungen;
  und das Formular bekommt endlich ein Eingabefeld. Dazu ein Audit-Log: Der
  Vorgang konnte bis hierher spurlos Daten vernichten.

- **Das Freitextfeld `contact_info` stand öffentlich** (#293). Das
  Admin-Formular lud ausdrücklich ein, Telefonnummern hineinzuschreiben
  („Sonstige Kontaktinformationen (z. B. Telefon)"), und dasselbe Feld wurde
  auf der Pferde-Detailseite gerendert. Damit stand eine **zustellbare** Angabe
  auf der öffentlichen Seite der Trennlinie, die das Schema zwei Zeilen darüber
  selbst zieht. Geschützt hat allein `is_published`: Sobald eine Redaktion eine
  Person freigab, stand deren Telefonnummer öffentlich.

- **Website-Felder wurden ohne Protokollprüfung verlinkt** (#293,
  Nebenbefund). Sie gingen als `href` mit bloßem `htmlspecialchars()` hinaus —
  das kodiert den Attributwert, sagt aber nichts über das Protokoll, und
  `javascript:` übersteht es unverändert. Betroffen waren die öffentliche
  Stationsseite und die Admin-Stationsliste. Die Prüfung, die `Markdown` für
  Fließtext-Links längst anwendet, steht jetzt als `App\Helper\ExternalUrl` an
  einer Stelle.

- **Papierkorb-Datensätze ließen sich überschreiben** (#296). Eine in den
  Papierkorb gelegte Person blieb über die direkte URL bearbeitbar: Sie blieb
  gelöscht, bekam aber neue Werte, und die Oberfläche wies nicht darauf hin.
  Gelöst als **anzeigen ja, speichern nein** — ein Filter schon in der
  Abfrage hätte den DSGVO-Auskunftsweg gekappt, der bewusst auch weich
  gelöschte Treffer erreichbar hält. Deckstationen hatten dasselbe Muster.

- **Widersprüchliche Abstammung wurde gespeichert** (#298): ein Vater, der
  jünger ist als sein Fohlen, oder dasselbe Pferd als Vater **und** Mutter. Die
  Schwelle gab es bereits, nur an der falschen Stelle — beim automatischen
  Verknüpfen von Freitext-Eltern, nicht beim manuellen Setzen. Abgelehnt wird
  nur, was unmöglich ist; die 3–30-Jahre-Spanne bleibt eine Heuristik fürs
  Matching, denn früh oder spät deckende Tiere kommen vor.

- **Die Update-Seite zeigte veraltete Addon-Versionen** (#290). Die
  Addon-Tabelle unter `/admin/updates` liest ausschließlich den
  Katalog-Cache, aufgefrischt wurde der aber nur beim Aufruf des
  Addon-Stores. Wer den Store nie besuchte, sah dort beliebig lange einen
  alten Stand — verfügbare Addon-Updates erschienen schlicht nicht. Die
  Update-Seite frischt den Katalog des offiziellen Repos jetzt selbst auf
  (TTL-gesteuert, kein Netzzugriff bei frischem Cache).

- **Der 15-Minuten-Cache des Addon-Katalogs lief nie an.** Die TTL verglich
  ein von MySQL `NOW()` geschriebenes `cached_at` per PHP-`strtotime()` gegen
  `time()`. Laufen Datenbank und PHP in verschiedenen Zeitzonen — Container
  auf UTC, PHP auf `Europe/Berlin` ist der Normalfall —, liegt die Rechnung
  um genau diesen Versatz daneben und der Cache gilt **dauerhaft** als
  abgelaufen: Jeder Store-Aufruf lud den kompletten Tarball neu. Das Alter
  wird jetzt per `TIMESTAMPDIFF` in SQL bestimmt, wo beide Werte von
  derselben Uhr kommen — dasselbe Prinzip, nach dem `Scheduler` seine
  Fälligkeiten schon immer über Unix-Zeitstempel bestimmt.

- **Warum Addons nach einem Kern-Update fehlten, stand nirgends** (#290).
  Verweigert die Addon-Phase (etwa weil es zur neuen Kern-Linie noch kein
  Addon-Release gibt, #212), meldete die Update-Seite nur „N fehlgeschlagen";
  der Klartextgrund lag allein im Audit-Log. Er erscheint jetzt direkt in der
  Erfolgsmeldung, mit den betroffenen Addons, und der Audit-Log-Link ist auf
  die Kategorie `plugin` vorgefiltert.

- **`curl_close()` entfernt** (`UpdateService`, `EntraSsoController`,
  `OidcDiscovery`). Seit PHP 8.0 wirkungslos, seit 8.5 deprecated — und weil
  `phpunit.xml` auf `failOnDeprecation` steht, hätte jeder Test, der diese
  Pfade erstmals berührt, den Lauf rot gemacht. Genau das trat beim neuen
  Update-Integrationstest ein.

- **Datenbank und PHP rechnen jetzt in derselben Zeitzone.** Der Cache-Fehler
  oben war kein Einzelfall, sondern der sichtbare Teil einer Fehlerklasse:
  Über dreißig Stellen im Kern schreiben Zeitstempel per `NOW()`/`CURDATE()`
  in der Zeitzone des Datenbankservers, während jeder PHP-seitige Vergleich in
  der von `date.timezone` rechnet. Solange beide zufällig übereinstimmen,
  fällt nichts auf — im offiziellen Container tun sie das (php:8.5-apache und
  MariaDB stehen beide auf UTC), auf klassischem Hosting mit lokal
  eingestelltem PHP nicht.

  Nachweisbare zweite Auswirkung: Die Tagesstatistik (`/api/stats`) gruppiert
  mit `DATE(created_at)` in Datenbankzeit, bildet die Zeitraumgrenzen aber in
  PHP-Zeit. Fallen die Datumsgrenzen auseinander, landen frisch angelegte
  Datensätze im Kübel des Vortags — stillschweigend, es sieht wie ein
  Zählfehler aus. Reproduziert mit auseinanderlaufenden Zeitzonen; der
  vorhandene Test schlug dabei fehl, in der CI (Runner und Dienst-Container
  beide UTC) konnte er es nie zeigen.

  `Database::getInstance()` gleicht die Sitzungs-Zeitzone der Verbindung
  deshalb an PHP an. Gesetzt wird der numerische Versatz, nicht der Zonenname:
  Namen setzen die geladenen Zeitzonentabellen der Datenbank voraus, die in
  Containern und bei Hostern regelmäßig fehlen. Ein `SET time_zone`, das die
  Datenbank verweigert, ist bewusst nicht tödlich.

  **Für Bestandsinstallationen, deren Zeitzonen bisher auseinanderliefen:**
  Neue Zeitstempel entstehen ab dem Update in PHP-Zeit, bereits gespeicherte
  bleiben, wie sie sind. Betroffen sind praktisch nur kurzlebige Werte
  (Token- und Sitzungsfristen im Minutenbereich) — dort kann es einmalig zu
  einer verfrühten oder verspäteten Fälligkeit kommen. Wo beide Seiten schon
  vorher übereinstimmten, ändert sich nichts.

- **Ein liegengebliebener Wartungsmodus sperrte die Installation dauerhaft.**
  `performUpdate()` setzt den Marker und entfernt ihn im `finally` — bei einem
  harten Abbruch (`E_COMPILE_ERROR`, abgelaufenes `request_terminate_timeout`,
  getöteter Worker) läuft das nicht mehr. `Maintenance::guard()` beantwortete
  danach **jeden** Request mit 503, bewusst auch für Admins, ohne Höchstalter
  und ohne Notausgang außer dem Löschen der Datei per Shell. Seit das Update
  unbeaufsichtigt laufen kann, sitzt niemand mehr davor.

  `enable()` hinterlegt jetzt die Prozesskennung, und `guard()` räumt einen
  verwaisten Marker weg. Die Bedingungen sind streng, und alle drei müssen
  zutreffen: auswertbare Nutzlast **mit** Kennung, der Prozess läuft
  nachweislich nicht mehr, und der Marker ist älter als 15 Minuten. Eine
  laufende Arbeit verliert ihre Sperre damit nie — das wäre genau der Schaden,
  gegen den es den Wartungsmodus gibt —, und ein von Hand gesetzter Marker
  (`touch var/wartung.lock`, laut Dokumentation ein gültiger Weg) verfällt
  ebenfalls nie.

### Tests

- Neue Funktionstests zu allen oben genannten Fehlern, jeweils **gegengeprüft**
  (ohne die Korrektur fallen sie um) und, wo es darauf ankommt, mit der
  Gegenrichtung: Eine Validierung, die auch richtige Eingaben abweist, wäre
  schlimmer als gar keine.
- **Gast-Standardrechte an einer Stelle** (`GUEST_DEFAULT_PERMISSIONS`). Mehrere
  Tests ersetzen die Rechte der Gast-Gruppe und stellten danach eine
  hartkodierte Liste wieder her — beim Hinzukommen von `persons.view` fiel das
  neue Recht dabei heraus, und ein weit entfernter Test wurde rot.

- Neuer Integrationstest für den **vollständigen Update-Ablauf**
  (`tests/Integration/UpdateRunTest.php`): echtes Pflicht-Backup gegen den
  Fake-S3-Server, echter Download des Release-Zips über einen lokalen
  Fixture-Server, echte SHA256-Prüfung, echtes Anwenden in ein temporäres
  Zielverzeichnis. Bis hierher gab es für `performUpdate()` keinen einzigen
  Test — abgedeckt waren nur die Ablehnungsfälle.

- **Der Mailversand der Benachrichtigung wird wirklich durchgeführt**
  (`tests/Integration/UpdateNotificationMailTest.php`), nicht weggemockt. Die
  übrigen Tests laufen ohne Mail-Konfiguration und konnten deshalb nur den
  Verweigerungsfall zeigen — nie, ob im Erfolgsfall eine Mail entsteht, was
  darin steht und ob der Merkzettel danach richtig fortgeschrieben wird. Genau
  dort saß einer der beiden Fehler, die die Selbstprüfung dieses Zweigs
  gefunden hat.

  Abgedeckt sind **beide** Versandwege, weil beide im Einsatz sind: `smtp`
  gegen einen neuen `FakeSmtpServer` — mit echter TLS-Strecke, denn der Mailer
  lehnt unverschlüsselten Versand ab und prüft das Zertifikat vollständig,
  weshalb der Testserver eine eigene Zertifizierungsstelle mitbringt — und
  `mail` (PHPs `mail()`) über einen Unterprozess mit umgebogenem
  `sendmail_path`, das sich zur Laufzeit nicht setzen lässt. Mitgeprüft wird
  die Zusage „einmal je Fund": erster Lauf verschickt, zweiter schweigt, eine
  neuere Version wird wieder gemeldet.

- **Der Wartungsmodus ist über den echten HTTP-Weg geprüft**
  (`tests/Functional/MaintenanceModeTest.php`): Ein verwaister Marker wird
  weggeräumt und die Seite liefert wieder aus; der Marker eines **laufenden**
  Prozesses blockiert weiter, egal wie alt er ist; ein von Hand gesetzter
  Marker bleibt bestehen.

- **Die Testserver übernehmen die Zeitzone des Testprozesses.** Test und App
  teilen sich die Datenbank; laufen sie in verschiedenen Zeitzonen, schreibt
  der eine Zeitstempel, die der andere falsch liest — was sich nicht als
  Fehler zeigt, sondern etwa als unerklärlich abgelaufener Cache.
  `PhpBuiltInServer` und `AuxiliaryServer` reichen `date.timezone` deshalb an
  den Kindprozess durch.

## [0.5.2] – 2026-08-16

### Sicherheit

- **Die Stamm-URL verlangt ihr Protokoll, statt es zu ergänzen**
  (`AdminController::updateSystemSettings()`). Für Eingaben ohne Schema stand
  dort ein `'https://' . $baseUrl`. Geprüft wurde danach eine Zeichenkette,
  die zur Hälfte von der Anwendung selbst stammte — dieselbe Bauart, die hier
  schon einmal einen Fehler verdeckt hat. Das Formular verlangt das Protokoll
  ohnehin (`<input type="url">`, Beschriftung, Hilfetext); ergänzt wurde also
  nur für Anfragen, die das Formular umgehen.

  Nebenwirkung dieser Präfix-Logik war zugleich die einzige Begrenzung der
  zulässigen Protokolle: `FILTER_VALIDATE_URL` lässt `ftp://` und
  `javascript://` durch. Das Schema wird jetzt aus der **geprüften** Adresse
  gelesen und gegen eine Allowlist (`http`, `https`) gehalten; daran hängt
  auch die HTTP-Unverschlüsselt-Warnung. Zwei neue Funktionstests halten
  beides fest.

  Semgrep meldete die Zeile als `tainted-url-host` (SSRF, CWE-918). Die Regel
  hat **keine** `pattern-sanitizers` — keine nachgelagerte Prüfung kann sie
  erfüllen, nur das Nicht-mehr-Zusammensetzen. Damit ist der Scan über den
  gesamten Kern befundfrei: 0 Funde, 0 Unterdrückungen, über alle Schweregrade
  statt nur über `ERROR`.

- **Anfrageparameter werden validiert statt umgedeutet** (`PublicController::requestInt()`).
  Das Muster `(int)($_GET['x'] ?? n)` machte aus `"abc"` eine 0 und aus `"3x"`
  eine 3; der Standardwert galt nur für „fehlt", nicht für „unbrauchbar".
  `filter_var` mit `FILTER_VALIDATE_INT` lehnt ab, was keine Zahl **ist**.
  Betrifft die Katalog-Seitenzahl und die Geburtsjahr-Filter.
- **`base_url` speichert den geprüften Wert.** Die Prüfung lief auf
  `rtrim($baseUrl, '/')`, abgelegt wurde `$baseUrl` — geprüft und gespeichert
  waren zwei verschiedene Zeichenketten. Jetzt ist der Rückgabewert von
  `filter_var(..., FILTER_VALIDATE_URL)` die einzige Quelle.
- **Die einzige `nosemgrep`-Unterdrückung im Kern ist entfernt** — samt
  Ursache. Sie stand seit `4d37b67` an der JSON-Ausgabe des
  Katalog-Nachladens, weil die Taint-Analyse den `(int)`-Cast nicht als
  Bereinigung erkennt. Mit der Validierung oben entfällt der Grund: Der Scan
  ist ohne Ausnahme sauber. Eine Meldung abzuschalten behebt nichts — sie
  nimmt nur die Sicht darauf, dass dem Code die Bereinigung nicht anzusehen
  war.

### Geändert

- **`pre-commit` in der CI per Hash festgenagelt**
  (`.github/pre-commit-requirements.txt`, erzeugt mit
  `pip-compile --generate-hashes`). Ein unversioniertes `pip install` zieht
  bei jedem Lauf, was gerade neu ist — dann entscheidet der Zeitpunkt mit
  darüber, was geprüft wird. OpenSSF Scorecard meldete das als
  `Pinned-Dependencies`; es war eine Regression gegen die Pinning-Disziplin,
  die hier sonst überall gilt (Actions per SHA, Images per Digest).

  Eine Versionsangabe allein genügt dafür nicht — das ist gemessen, nicht
  vermutet: `pre-commit==4.6.2` stand bereits im Workflow, und Scorecard
  flaggte die Zeile im Lauf gegen `f562d3b` weiter, weil der Baum darunter
  (`virtualenv`, `pyyaml`, `identify`, …) offen blieb. `--require-hashes`
  schließt ihn: pip lehnt jedes Paket ab, dessen Hash nicht in der Datei
  steht, und verlangt, dass **alle** Abhängigkeiten aufgeführt sind. Die
  Datei wird gegen Python 3.12 — die Version des Runners — als installierbar
  geprüft, nicht nur gegen die des Entwicklungsrechners.

## [0.5.1] – 2026-08-16

### Sicherheit

- **Die Nachweise der 2FA-Einrichtung tragen jetzt die Konto-ID.** Zwei
  Session-Werte können ein Konto benennen und dabei auf verschiedene zeigen:
  `pending_2fa_user_id` (Faktor 1 des laufenden Logins) und `user_id` (eine
  bestehende Anmeldung). Die Step-up-Freigabe und das serverseitig
  vorbereitete Secret waren an keines von beiden gebunden, und die Prüfung
  bei aktiver 2FA fragte nur, **ob** eine Anmeldung besteht — nicht, für wen.
  - Welches Konto gemeint ist, beantwortet jetzt genau eine Stelle
    (`AuthController::twofaTargetUserId()`); `twofa_reauth` und `totp_setup`
    führen die Konto-ID mit und werden dagegen geprüft; bei aktiver 2FA muss
    die Sitzung als genau dieses Konto angemeldet sein.
  - Ein neuer Passwort-Login löst außerdem jede bestehende Anmeldung derselben
    Sitzung ab (`discardExistingSessionState()`) — zwei Identitäten
    nebeneinander sind der Zustand, aus dem solche Verwechslungen entstehen.
  - Neuer Funktionstest `TwoFaCrossAccountTest`: Der bestehende
    `TwoFaStepUpTest` prüfte ausschließlich Same-User-Fälle, die Lücke lag
    genau daneben.

- **`/force-password-change` läuft durch `checkAuth()`** — es war die einzige
  Backend-Route ohne diese Prüfung und damit der Rückweg aus der
  Session-Invalidierung (#113): Eine Sitzung, die überall sonst mit
  `session_expired` hinausflog, konnte dort ein neues Passwort setzen und sich
  die frische `session_version` selbst zurückschreiben. Zusätzlich verlangt
  der Wechsel jetzt das bisherige Passwort (eigener Rate-Limit-Zähler,
  Fehlversuche im Audit-Log) — dieselbe Begründung wie beim Step-up vor einer
  2FA-Änderung (#112). Neuer Funktionstest `ForcePasswordChangeGuardTest`.

- **SSO wertet `email_verified` aus.** Ein ausdrücklich als unbestätigt
  gemeldeter `email`-Claim wird verworfen, statt als Kontozuordnung zu gelten;
  bei einem Provider mit Selbstregistrierung genügte sonst ein dort angelegtes
  Konto mit der Adresse eines Administrators. Ein fehlender Claim bleibt
  akzeptiert (Entra ID sendet ihn für Geschäftskonten nicht). Der Rückfall auf
  `preferred_username` greift nur noch, wenn gar kein `email`-Claim vorliegt.

- **`APP_ENV` erkennt die Konfiguration über Umgebungsvariablen.** Der
  Produktions-Automatismus hing allein an der Existenz von
  `config/db_config.php`. Der in der README als Variante A beschriebene Weg -
  Konfiguration rein über `DB_*`-Umgebungsvariablen, also der Container-Betrieb -
  fiel damit auf `development` zurück: `display_errors` an, und der erste
  PDO-Fehler zeigte dem Besucher DSN samt Datenbankbenutzer. Jetzt gilt
  `production`, sobald die Instanz überhaupt konfiguriert ist. Zusätzlich
  `ENV APP_ENV=production` im Dockerfile.

- **In Produktion wird wieder protokolliert** (neu:
  `App\Service\ErrorHandler`). `error_reporting(0)` schaltete nicht nur die
  Anzeige ab, sondern auch das Logging - die Stufe ist die Maske für beides.
  Zusammen mit dem fehlenden Exception-Handler hinterließ eine unbehandelte
  `PDOException` eine leere Seite und **nirgends** einen Eintrag (OWASP A09).
  Jetzt: `error_reporting` immer `E_ALL`, `log_errors` immer an, nur
  `display_errors` hängt an der Umgebung; dazu ein Exception-Handler und eine
  Shutdown-Funktion für fatale Fehler, die nach `error_log()` schreiben (nicht
  in die Datenbank - sie kann der Ausfall sein) und eine schlichte 500-Seite
  ohne Details liefern.

- **Setup-Wizard prüft Datenbankname, Host und Port immer** (neu:
  `App\Security\DbIdentifier`). Die Namensprüfung hing am Zweig „DB-Abschnitt
  im Formular sichtbar", und der gilt schon als erledigt, sobald *irgendeine*
  der Variablen `DB_HOST`/`DB_USER`/`DB_PASS` gesetzt ist - `DB_NAME` zählt
  dabei nicht mit. Wer `DB_HOST` setzte, aber `DB_NAME` nicht, lieferte den
  Namen weiterhin ungeprüft aus dem Formular, und er landet interpoliert in
  `DROP DATABASE`/`CREATE DATABASE`. Host und Port werden neu geprüft, damit
  ein Semikolon keine weiteren DSN-Parameter anhängen kann.

- **`config/db_config.php` wird mit 0600 geschrieben.** Sie enthält
  DB-Passwort und `APP_KEY` im Klartext; mit dem Schlüssel lassen sich alle
  verschlüsselt abgelegten Geheimnisse entschlüsseln. `file_put_contents`
  legte sie mit 0644 an, auf geteiltem Webhosting also für jeden Systembenutzer
  lesbar.

- **`DB_SSL_VERIFY` ist standardmäßig an, sobald eine CA-Datei hinterlegt
  ist.** Zuvor war die Zertifikatsprüfung ohne ausdrückliches Zutun aus - eine
  angegebene CA blieb wirkungslos und die Verbindung verschlüsselt, aber nicht
  authentifiziert.

### Geändert (Betrieb)

- **PHP-Uploadgrenzen im Docker-Image angehoben** (`conf.d/zz-app.ini`).
  `upload_max_filesize` (2 MB) und `post_max_size` (8 MB) lagen **unter** der
  5-MB-Grenze, die die Anwendung selbst prüft: Ein 4-MB-Bild verwarf PHP,
  bevor der Code es sah - `$_FILES` kam leer an, und der Benutzer las „keine
  Datei ausgewählt". Die Grenze der Anwendung ist die verbindliche, PHP muss
  darüber liegen.
  - **Korrektur zum Prüfbericht:** Dort stand zusätzlich „OPcache im Image
    nicht aktiviert". Am laufenden Basis-Image nachgemessen stimmt das nicht -
    in `php:8.5-apache` ist OPcache fest einkompiliert und für die Web-SAPI
    bereits eingeschaltet (`opcache.enable=1`, `memory_consumption=128`,
    `max_accelerated_files=10000`, `revalidate_freq=2`). Die zunächst
    gesetzten Werte waren mit den Vorgaben identisch, und das dazugehörige
    `docker-php-ext-enable opcache` liess den Image-Bau sogar scheitern, weil
    es kein ladbares Modul dieses Namens gibt. Der Block ist wieder draussen.
    `memory_limit` und `max_execution_time` bleiben ebenfalls unangetastet -
    ein Zeitlimit von 60 Sekunden hätte Sicherung, Import und Update
    abgeschnitten.

- **`plugins/` ist ein Volume** in `docker-compose.yml`. Der dokumentierte
  Update-Weg (`docker compose pull && up -d`) nahm bisher jedes über den
  Addon-Store installierte Addon mit; die Datenbankzeilen blieben stehen, die
  Dateien nicht. Zu `config/db_config.php` steht jetzt dort, warum ein Volume
  über `config/` der falsche Weg wäre (es fröre `config.php` - also Code - auf
  dem Stand des ersten Starts ein) und wie man stattdessen gezielt die eine
  Datei einbindet.

- **Kern-Update prüft die Prüfsumme des Archivs, bevor es angewendet wird.**
  Das Update überschreibt den gesamten Codebaum - was durchkommt, läuft danach
  als PHP. Geprüft wird gegen die `SHA256SUMS.txt`, die der Release-Workflow
  ohnehin miterzeugt; fehlt die Datei oder der Eintrag zum Zip, wird nicht
  aktualisiert (fail-closed). Das ist eine Integritäts-, keine Echtheitsprüfung
  — Archiv und Prüfsumme stammen aus derselben Quelle —, fängt aber
  abgebrochene und unterwegs veränderte Downloads sowie zur Version
  unpassende Assets ab. Die Echtheit trägt weiterhin die TLS-Verbindung zur
  fest verdrahteten `api.github.com`-URL.

- **Der Updater spricht nur noch HTTPS**, auch nach einer Umleitung
  (`CURLOPT_PROTOCOLS_STR`/`CURLOPT_REDIR_PROTOCOLS_STR`). Zuvor konnte eine
  302 auf `http://` oder `file://` aus dem gesicherten Transport herausführen.
  In der Entwicklungsumgebung bleibt `http` erlaubt, weil die Funktionstests
  ihr Release-Fixture über einen lokalen `php -S` ohne TLS ausliefern.

- **`UPDATE_RELEASES_URL` greift nur noch in der Entwicklungsumgebung.** Die
  Variable ist ein Test-/Staging-Hilfsmittel; in Produktion bestimmt sie, woher
  der Code kommt, der die Installation überschreibt. Eine ignorierte
  Übersteuerung wird protokolliert, damit sie nicht still wirkungslos bleibt.

- **WebDAV folgt keinen Umleitungen mehr.** PHP hängt beim Folgen die gesetzten
  Header unverändert an die neue Anfrage — also auch den
  `Authorization`-Header mit den Basic-Zugangsdaten, und das an einen Host, den
  die Antwort des Servers bestimmt. Eine Umleitung fällt jetzt in die
  Statusprüfung und wird als Fehler gemeldet; ein dauerhaft umleitender Server
  gehört mit seiner Zieladresse in die Konfiguration.

- **FTPS: fehlende Zertifikatsprüfung benannt.** `ftp_ssl_connect()`
  verschlüsselt, prüft das Serverzertifikat aber nicht, und PHPs
  FTP-Erweiterung bietet dafür keine Einstellung — das ist nicht
  wegzuprogrammieren. Der Admin-Bereich weist am FTPS-Abschnitt jetzt darauf
  hin und empfiehlt WebDAV oder S3, wo beide das Zertifikat prüfen.

### Behoben

- **Ein abgebrochenes Kern-Update hinterlässt keinen Mischstand mehr.** Der
  Kopiervorgang lief additiv über die laufende Installation; brach er in der
  Mitte ab, blieben zwei Versionen nebeneinander liegen — und genau dieser
  Baum wird als Nächstes ausgeführt. Jetzt prüft eine Vorabprüfung erst alle
  Zielpfade, und ein Journal aus Sicherungskopien rollt zurück, was die
  Vorabprüfung nicht vorhersehen kann (volle Platte, entzogene Rechte mitten
  im Lauf). Neuer Test in `UpdateServiceTest`.

- **Keine Schrift mehr von einem fremden Host.** Jede Seite - auch die
  öffentliche, auch ohne Anmeldung - lud ein Stylesheet von
  `fonts.googleapis.com` und die Schriftdateien von `fonts.gstatic.com`. Damit
  meldete jeder Besucher IP-Adresse und aufgerufene Seite an einen Dritten
  (ohne Einwilligung nicht zulässig, und ein Zuchtverzeichnis führt
  Personendaten), die Darstellung hing an einem fremden Dienst, und ein
  kompromittiertes Font-CSS hätte über die dafür nötige `style-src`-Freigabe
  gewirkt. Die Schrift kommt jetzt aus dem System-Stack, den `--font-family`
  ohnehin schon als Rückfall führte; die Freigaben in der CSP entfallen. Wer
  die Inter-Typografie exakt behalten will, liefert die woff2-Dateien selbst
  aus.

- **Reset-Token liegen nur noch als SHA-256-Abdruck in der Datenbank.** Im
  Klartext war `password_resets` ein Vorrat gültiger Zugänge: Wer die Tabelle
  lesen kann - Leselücke, Sicherungskopie, Dump -, übernimmt in den 15 Minuten
  Gültigkeit jedes Konto, für das gerade ein Reset läuft. Bestehende Zeilen
  werden bei der Migration entfernt statt umgerechnet (Schema-Version 5).

- **`/forgot-password` verrät über die Antwortzeit nicht mehr, ob es das Konto
  gibt.** Die Antwort war zwar in beiden Fällen wortgleich, aber nur für ein
  vorhandenes Konto wurde eine SMTP-Verbindung aufgebaut - und die kostet ein
  Vielfaches. Die Antwortzeit liegt jetzt auf einer festen Untergrenze. Das
  deckelt die Auflösung, es beseitigt den Unterschied nicht: Braucht der
  Versand länger als die Untergrenze, ist er wieder messbar. Sauber wäre eine
  Warteschlange.

- **Der Konto-Zähler des Logins ist nicht mehr durch Leerzeichen umgehbar.**
  Der Bezeichner ist zusammengesetzt (`email|ip`), das angehängte Leerzeichen
  stand darin also mittendrin; der Zähler lief auf einen frischen Wert,
  während die Benutzersuche die Adresse dank PAD-SPACE-Collation unverändert
  fand. `RateLimiter` entfernt Whitespace jetzt vollständig, und die Adresse
  wird schon am Rand getrimmt.

- **Sicherheitsabfragen kommen ohne Inline-JavaScript aus.** Der Text wurde
  mit `addslashes()` in ein `onsubmit="return confirm('…')"` geschrieben -
  eine Maskierung, die Anführungszeichen kennt, aber keine Zeilenumbrüche. Ein
  Zeilenumbruch im Wert (etwa im Namen eines Plugins) beendete das
  JS-Stringliteral, der Handler war kaputt und die Abfrage vor dem Aktivieren
  fremden Codes verschwand ersatzlos. Jetzt trägt das Formular ein
  `data-confirm`-Attribut, das `public/js/confirm-submit.js` auswertet - alle
  23 Stellen umgestellt, `addslashes()` kommt im Code nicht mehr vor. Damit
  ist zugleich der letzte Inline-Handler aus den Views verschwunden.

### Behoben

- **`Markdown::parse()` zerlegte URLs mit Unterstrich oder Sternchen.** Die
  Kursiv- und Fett-Regeln liefen nach der Link-Regel über den gesamten Text,
  also auch über das gerade erzeugte `<a href="…">`, und schoben fertige Tags
  in den Attributwert (`href="http://a.com/<em>foo</em>bar"`); ein unpaariges
  Sternchen hinterließ ein hängendes `</em>` darin. Ein Ausbruch aus den
  Anführungszeichen war es nicht - `htmlspecialchars(ENT_QUOTES)` fängt die ab
  -, aber ein kaputter Link. Links werden jetzt vor Bold/Italic erzeugt und
  für deren Dauer durch einen Platzhalter geschützt; zusätzlich muss die URL
  als `http(s)`-URL durchgehen, sonst bleibt sie Text.

### Behoben (Performance)

- **Die Bildauslieferung serialisierte sich selbst.** `/media/horse-image`
  läuft durch den vollen Front-Controller, der für jeden Request eine Sitzung
  startet - und PHPs Standard-Sitzungsspeicher hält die Sitzungsdatei bis zum
  Ende des Requests exklusiv gesperrt. Eine Katalogseite fordert zwei Dutzend
  Bilder über diesen Endpunkt an; statt parallel ausgeliefert zu werden,
  reihten sie sich hintereinander auf. `session_write_close()` vor `readfile()`
  gibt das Schloss frei, sobald die Sichtbarkeitsprüfung durch ist.

- **Der Stammbaum-Cache wird nicht mehr bei jedem Aufruf weggeworfen.**
  `PedigreeBuilder::build()` leerte seine Memoisierung zu Beginn jedes
  Top-Level-Aufrufs, mit der Begründung, dass sonst „zwischen Requests/Tests
  veraltete Daten hängen bleiben" - zwischen Requests kann eine statische
  Eigenschaft das gar nicht. Innerhalb eines Requests kostete es: Die
  Detailseite baut einen Baum, Addons wie `inzuchtkoeffizient` oder
  `genealogie-vergleich` bauen im selben Request weitere über weitgehend
  dieselben Vorfahren, und jeder fing wieder bei null an. Für Tests und
  Import-/Migrationswege gibt es jetzt `PedigreeBuilder::resetCache()`.

- **Das Pferdeformular lädt die Eltern-Auswahl nicht mehr unbegrenzt.**
  „Neues Pferd" und „Pferd bearbeiten" holten die komplette `horses`-Tabelle
  (fünf Spalten je Zeile) und rendern sie zweimal als `<option>`-Liste, das
  Geschlecht erst in der Schleife in PHP gefiltert - bei jedem Aufruf, auch
  wenn niemand die Eltern anfasst. Jetzt gedeckelt; bereits gesetzte Eltern
  werden gezielt nachgeladen, damit die gespeicherte Zuordnung nicht still auf
  „kein Elternteil" zurückfällt.

### Geändert (Prüfkette)

- **pre-commit läuft jetzt in der CI** (`.github/workflows/pre-commit.yml`).
  `.pre-commit-config.yaml` war rein lokal: Wer die Hooks nicht installiert
  hatte - jeder frische Klon, jeder Beitrag von aussen -, umging gitleaks,
  shellcheck, eslint und die Whitespace-Prüflinge vollständig. Ein
  Geheimnis-Scanner, der nur dort läuft, wo ihn jemand eingerichtet hat, ist
  kein Gate, sondern eine Bitte. Der erste erzwungene Lauf hat prompt drei
  Dinge gefunden, die seit Langem unbemerkt lagen (siehe unten).

- **`eslint.config.js` ergänzt.** Der eslint-Hook ist auf v10 gepinnt; seit
  ESLint v9 ist `eslint.config.js` die einzige gesuchte Konfigurationsdatei,
  und es gab keine. Der Hook brach also bei jedem Lauf mit „couldn't find an
  eslint.config file" ab - ein Prüfschritt, der immer rot ist und den niemand
  ausführt, ist von einem, der nicht existiert, nicht zu unterscheiden. Die
  mitgelieferte Fremdbibliothek `public/js/qrcode.js` ist ausgenommen.

- **shellcheck-Funde behoben:** dreimal `A && B || C` in
  `security/checks/10-http-headers.sh` durch `if/else` ersetzt (bei dem
  Kurzmuster läuft C auch, wenn A wahr ist und B fehlschlägt), und die per
  `trap` aufgerufene `teardown()` in `security/run-security-scan.sh` als
  solche gekennzeichnet.

- **Nachlaufende Leerzeichen in 24 Dateien entfernt** - die Bereinigung, die
  der lokale Hook seit jeher gemacht hätte, wenn er gelaufen wäre.

### Sicherheit

- **`security/baseline/sqli-targets.txt` gefüllt.** Die Datei enthielt nur
  einen TODO-Kommentar, der Formular-Crawl aus `60-sqli.sh` findet aber nur,
  was in einem `<form>` steht. Die gesamte Katalogsuche läuft über
  Query-Parameter - also der öffentlich erreichbare, unauthentifizierte
  Lesepfad mit der grössten Angriffsfläche - und wurde vom wöchentlichen
  DAST-Lauf nie angefasst, der trotzdem „bestanden" meldete. Jetzt stehen die
  Filter des Katalogs, die Detail- und Medienrouten sowie die JSON-API-Endpunkte
  drin.

### Hinzugefügt

- **Zeitreihen-Endpunkt `GET /api/stats`** für externe Dashboards (#270).
  Bisher gab es keinerlei Metrik-Historie: `DigestService` liefert zwei
  Live-Zähler per E-Mail, `recordStatus()` speichert nur den letzten
  Schnappschuss, das Admin-Dashboard ist eine Momentaufnahme.
  - Acht Reihen über bereits vorhandene Zeitstempel (Pferdebestand und
    Veröffentlichungen, DSGVO-Anfragen, Audit-Ereignisse, Anmeldeversuche,
    API-Schlüssel, Benutzerkonten), gruppierbar nach Tag, Woche (ab Montag,
    ISO-8601) oder Monat. **Bewusst ohne neue Historien-Tabelle und ohne
    Sammel-Job**: Die Zeitstempel stehen längst in den Fachtabellen, eine
    zweite Ablage müsste befüllt, überwacht und aufgeräumt werden und wäre
    nach einem Rollback still unvollständig.
  - **Neues Recht `stats.view`** (Modul „Statistik-Schnittstelle", ohne
    eigene Oberfläche). Die Zahlen sind betriebsintern — ein Katalog-Schlüssel
    mit `horses.view` kommt hier nicht durch, sonst verrieten
    Login-Fehlversuche ihren Verlauf an jeden Katalog-Integrator. In keiner
    Standardgruppe vorbelegt, der Betreiber vergibt es bewusst.
  - **Lücken sind mit `0` gefüllt.** Sonst zieht Grafana eine Linie über die
    leeren Tage hinweg, und ein Tag ohne Ereignisse sieht aus wie ein Tag, den
    es nicht gab. Dazu ein Deckel von 1500 Kübeln je Antwort, geprüft **bevor**
    die Abfrage läuft — die Füllung erzeugt die Zeilen auch ohne Daten.
  - Ungültige Parameter werden **abgewiesen statt still durch Standardwerte
    ersetzt** (`2026-02-31` und `yesterday` gelten als ungültig): Ein Dashboard
    soll nach einem Tippfehler nichts anzeigen statt plausibler falscher
    Zahlen. `metric` und `interval` landen nie in SQL, sondern werden als
    Schlüssel in feste Definitionen nachgeschlagen.
  - Ohne `?metric=` liefert der Endpunkt den Katalog der verfügbaren Reihen,
    damit die Grafana-Datenquelle ohne Blick in die Doku einrichtbar ist.
  - Kein Prometheus-`/metrics`: Das brächte ein weiteres Composer-Paket und
    ein eigenes Auth-Modell neben dem vorhandenen Bearer-Mechanismus.
    Dokumentiert in `docs/api.md`, inklusive Einrichtung der
    Infinity-Datenquelle.

- **`frame-src` in der Content-Security-Policy**, mit `CAPTCHA_DOMAINS` als
  Erweiterungspunkt für einen externen CAPTCHA-Anbieter (Voraussetzung für ein
  Addon auf Basis der Hooks aus #258). Die Policy hatte bisher **überhaupt kein
  `frame-src`** — es griff der Rückfall auf `default-src 'self'`, und das
  Widget von Turnstile oder hCaptcha (beide rendern in einem iframe) wäre
  lautlos leer geblieben. `TRACKING_DOMAINS` hilft dabei nicht: Es erweitert
  `script-src`/`img-src`/`connect-src`, der Rahmen bliebe gesperrt, das Skript
  lüde und scheiterte danach.
  - Eigener Wert statt `TRACKING_DOMAINS`, weil es zwei verschiedene
    Entscheidungen sind: Wer Tracking zulässt, will damit nicht automatisch
    fremde Rahmen zulassen.
  - `frame-src` wird jetzt **ausdrücklich** gesetzt, auch ohne Konfiguration.
    Gleiche Wirkung wie der Rückfall, aber sichtbar — wer die Policy liest,
    sieht, dass fremde Rahmen gesperrt sind, statt den Fehler anderswo zu
    suchen.

- Katalog: nahtloses Nachladen statt Seitenwechsel (#264). Der bestehende
  AJAX-Pfad hängt die nächste Seite jetzt an, statt die Karten zu ersetzen.
  - **Der „Weitere Pferde laden"-Knopf ist die Bedienung**, der Scroll-Auslöser
    (`IntersectionObserver`) nur Bequemlichkeit obendrauf: Der Knopf ist per
    Tastatur erreichbar, meldet sich über `aria-live` und funktioniert auch,
    wo automatisches Nachladen nie auslöst.
  - **Ohne JavaScript ändert sich nichts**: Der Nachlade-Block bleibt
    ausgeblendet, die serverseitige Seiten-Navigation bleibt vollwertig. Sie
    wird erst ausgeblendet, wenn das Skript übernimmt — zwei Bedienelemente
    für dieselbe Sache nebeneinander wären widersprüchlich.
  - Die AJAX-Antwort führt jetzt `page`, `total_pages` und `has_more`; der
    Client muss `CATALOG_PER_PAGE` nicht nachbauen.
  - Die Adresszeile folgt der zuletzt geladenen Seite über `replaceState` —
    mit `pushState` bräuchte der Zurück-Knopf so viele Klicks, wie nachgeladen
    wurde, um die Seite überhaupt zu verlassen.
  - Vier neue Sprachschlüssel in **allen zwölf** mitgelieferten Sprachen.

- Minimal-Layout für einbettbare Ansichten und eine **Domain-Allowlist** für
  die Frame-Sperre (#260). Voraussetzung für ein Embed-Widget als Addon.
  - `?embed=1` am Katalog rendert `layout_embed.php`: ohne Kopfbereich,
    Navigation und Fußzeile, aber **mit** Theme-Variablen, `style.css` und dem
    Darkmode-FOUC-Fix — ein iframe erbt kein CSS von der einbettenden Seite,
    und er rendert eigenständig. Ohne Tracking-Code (er gehört nicht in eine
    fremde Seite hinein, deren Einwilligungsbanner nichts von ihm weiß) und
    mit `noindex`.
  - `BaseController::render()` und `PluginPage::render()` nehmen dafür ein
    `$embed`-Flag — der eigentliche Anwendungsfall sind Addon-Seiten.
  - **Neu: `EMBED_ALLOWED_DOMAINS`** (Env oder `db_config.php`, Muster wie
    `TRACKING_DOMAINS`). **Im Auslieferungszustand leer — dann bleibt die
    Frame-Sperre auch für Embed-Ansichten bestehen.** Das Minimal-Layout
    lockert von sich aus nichts; `?embed=1` ist eine Darstellungsfrage.

- DSGVO-Modul: manuelle Personenzuordnung, wenn die automatische Suche nichts
  findet (#266). Bisher waren die Anonymisieren-/Löschen-Schaltflächen in
  `if (!empty($req['matching_persons']))` verschachtelt — bei null Treffern gab
  es **keinen** Weg, die betroffene Person trotzdem zu finden, und die Anfrage
  blieb auf `pending` liegen. Der Automatch vergleicht wörtlich und scheitert
  schon an abweichender Schreibweise, einem Tippfehler oder einem geänderten
  Namen; bei einem Verfahren, dessen Zweck die Einhaltung gesetzlicher Fristen
  ist, ist das der ungünstigste Ausgang.
  - Neue Suche `/admin/gdpr/search-persons` (Admin-only, JSON) mit `LIKE` und
    Trefferdeckel 50 — ausdrücklich **kein** Auswahlfeld, das den kompletten
    Personenbestand lädt.
  - Die Suche findet auch **weich gelöschte** Datensätze und kennzeichnet sie:
    Sie sind aus der Oberfläche verschwunden, ihre personenbezogenen Daten
    stehen aber weiter in der Tabelle. Wer Löschung verlangt, hat auch auf
    diese Anspruch.
  - **Auskunftsanfragen** (Art. 15) bekommen erstmals überhaupt ein Matching —
    aber bewusst ohne Löschen/Anonymisieren, sondern mit dem Weg zum
    Datensatz. Auskunft ist nicht Löschung.

- Einbettungsschutz für Pferdefotos (#262). Sie werden jetzt über
  `/media/horse-image` ausgeliefert statt als statische Datei.
  - **`Cross-Origin-Resource-Policy: same-origin`** ist die eigentliche Sperre:
    Sie wird vom Browser durchgesetzt und ist von der einbettenden Seite nicht
    umgehbar — anders als ein Referer, den die einbettende Seite selbst
    bestimmt.
  - Eine **Referer-Prüfung** deckt die Clients ab, die CORP nicht kennen. Ein
    *leerer* Referer bleibt bewusst erlaubt, sonst bräche direktes Aufrufen
    einer Bild-URL, Lesezeichen und jeder Browser, der den Referer
    unterdrückt.
  - **Nebenbefund mit behoben:** Als statische Datei war ein Foto unabhängig
    von `is_published` abrufbar — das Bild eines unveröffentlichten Pferdes lag
    offen, sobald jemand die URL kannte. Jetzt gelten dieselben
    Sichtbarkeitsregeln wie für die Detailseite.
  - Bedingte Anfragen (`ETag`/`Last-Modified` → 304) und `Cache-Control` mit
    einem Jahr: Die Auslieferung über PHP kostet damit kein Browser-Caching.

- Neuer Filter-Hook `horse.edit_sections` (#255): das Admin-Gegenstück zu
  `horse.detail_sections`. Addons können damit einen eigenen Abschnitt in das
  Bearbeitungsformular eines Hengstes hängen und bekommen die `horse_id` aus
  dem Aufrufkontext — bisher gab es dafür keinen Weg, weshalb Addons eigene
  Verwaltungsseiten mit einer Auswahl über den gesamten Pferdebestand bauten
  (Anlass: Addons#87 lud dafür bei jedem Seitenaufruf alle Pferde als
  `<select>`). Der Hook feuert bewusst nur beim Bearbeiten, nicht beim Anlegen
  (dort gibt es noch keine ID), und die Abschnitte werden **außerhalb** des
  Kern-Formulars gerendert: Verschachtelte `<form>` sind ungültiges HTML, und
  ein Speichern über den Kern-POST liefe nur gegen `horses.edit` statt gegen
  die Berechtigung des Addons. Der Datenvertrag weicht deshalb bewusst von
  `horse.detail_sections` ab (roher Datensatz ohne Sichtbarkeitsfilter, ohne
  `station_*`-Felder, ohne `deleted_at`-Filter) — dokumentiert in
  `docs/plugin-development.md`, abgesichert durch
  `tests/Functional/HorseEditSectionsHookTest.php`.

- Bundesland/Kanton und strukturierte Deckstations-Adresse (#256,
  SCHEMA_VERSION 4). Bei DACH-weiten Zuchtdaten reichen Land und PLZ oft nicht,
  um Herkunft oder Zuständigkeit (Landesverband) einzuordnen.
  - `persons.state` als Freitext analog `country` — bewusst ohne
    ISO-3166-2-Prüfung und ohne Helfer-Analogon zu `CountryFlag`:
    Bundesland- und Kantonsnamen sind in DACH uneinheitlich geschrieben, eine
    Validierung würde mehr korrekte Eingaben ablehnen als falsche. Das Feld ist
    **öffentlich** wie `city`/`country` und damit auch im
    `horse.detail_sections`-Payload. Die Trennlinie im Datenmodell ist nicht die
    Feldanzahl, sondern die Art der Angabe: Was eine Sendung zustellbar macht
    (Straße, Hausnummer, PLZ, E-Mail), bleibt intern; die grobe geografische
    Verortung ist öffentlich. Ein Bundesland ist gröber als der ohnehin
    sichtbare Ort. Die DSGVO-Anonymisierung nullt es mit.
  - `breeding_stations` bekommt erstmals überhaupt eine strukturierte Anschrift
    (`street`, `house_number`, `postal_code`, `city`, `state`, `country`) —
    bisher gab es dort nur ein einziges Freitextfeld und nicht einmal ein
    `country`. Anders als bei Personen ist hier alles öffentlich: eine
    Deckstation ist eine Geschäftsadresse, keine Privatperson.
  - Das alte Freitextfeld `breeding_stations.address` **bleibt bestehen** und
    wird **nicht** automatisch zerlegt. Der Bestand ist real mehrzeilig
    (`"Weideweg 1\n24000 Kiel"`), eine Zerlegung wäre geraten — dieselbe
    Entscheidung wie bei den Personendaten in #188. Angezeigt wird der Freitext
    weiterhin, solange die Einzelfelder leer sind; ohne diesen Rückfall wäre
    beim Ausrollen die Anschrift jeder Bestandsstation schlagartig von der
    öffentlichen Seite verschwunden. Das Admin-Formular hält das Feld
    bearbeitbar, damit Altbestand übertragen und danach geleert werden kann.
    Die Erweiterung ist damit rein additiv — `station_address` bleibt
    unverändert Teil des dokumentierten Plugin-Payloads.
  - Der DSGVO-Test prüft die zu nullenden Felder jetzt anhand der tatsächlichen
    Spalten von `persons` statt anhand einer aufgezählten Liste. Eine Aufzählung
    prüft nur, was beim Schreiben des Tests bekannt war — genau so wäre `state`
    ungeprüft durchgerutscht, und eine Anonymisierungslücke meldet weiterhin
    Erfolg.

- Bot-/Spam-Schutz für das öffentliche DSGVO-Portal (#258, baut auf der
  Vorarbeit aus #254 auf). `POST /dsgvo` ist ohne Anmeldung erreichbar und löst
  je angenommener Anfrage eine Zeile in `gdpr_requests` **und** eine echte
  Benachrichtigungs-E-Mail an die Verwaltung aus; abgesichert war der Endpunkt
  bisher allein durch ein IP-Rate-Limit, das bei DB-Fehlern bewusst fail-open
  ist. Jetzt vier unabhängige Schichten: CSRF, zwei getrennte IP-Zähler
  (`dsgvo_attempt` 20/h bremst das Durchprobieren, `dsgvo_request` 3/h begrenzt
  angenommene Anfragen — getrennt, damit ein Tippfehler nicht das kleine
  Kontingent echter Anfragen aufbraucht), ein Honeypot-Feld und ein CAPTCHA.
  Die beiden letzten kommen ohne Datenbank aus und greifen deshalb genau dann
  weiter, wenn das Rate-Limit ausfällt.
  - Das CAPTCHA ist eine Rechenaufgabe, deren Lösung ausschließlich
    serverseitig in der Session liegt. Single-Use (auch ein Fehlversuch
    verbraucht die Aufgabe), 15 Minuten gültig, mindestens 3 Sekunden
    Ausfüllzeit, und **in Worten gestellt** („sieben plus fünf"), damit sie
    nicht per Zahlen-Regex aus dem HTML lösbar ist. Zahlwörter in allen zwölf
    Sprachen.
  - **Bewusst kein Drittanbieter als Standard:** Ausgerechnet auf dem Formular,
    mit dem Betroffene ihre Rechte aus Art. 15/17 DSGVO geltend machen, wäre
    die Übertragung von IP-Adresse und Browser-Fingerprint an einen weiteren
    Empfänger kaum zu rechtfertigen. Die eingebaute Aufgabe braucht weder
    Schlüssel noch Netzzugang noch eine CSP-Lockerung.

- Neue Erweiterungspunkte `captcha.providers`, `captcha.render` und
  `captcha.verify` (#258). Damit lässt sich ein Fremdanbieter (Cloudflare
  Turnstile, hCaptcha) als **Addon** nachrüsten, wenn ein Betreiber ihn
  ausdrücklich will — die Auswahl trifft der Admin unter Systemeinstellungen.
  Bisher gab es überhaupt keinen Hook auf einem öffentlichen Formular; ein
  CAPTCHA-Addon war schlicht nicht andockbar.
  Antwortet der gewählte Anbieter nicht — Addon deaktiviert, deinstalliert oder
  abgestürzt —, prüft der Kern wieder mit seiner eigenen Aufgabe. Weder
  fail-open (Formular ungeschützt) noch hartes Blockieren (Betroffene kämen
  nicht mehr an ihre Auskunft) wäre hier vertretbar; dass es diesen dritten Weg
  gibt, ist der Grund, den Standard im Kern zu halten statt ihn selbst zum
  Addon zu machen.

### Geändert

- Authentifizierung, Rechteprüfung und Antwortform der JSON-API liegen jetzt
  in `App\Controllers\JsonApiController`, von dem `ApiController` und der
  neue `StatsApiController` erben. Läge das Lesen des Bearer-Headers an zwei
  Stellen, käme eine spätere Härtung irgendwann nur an einer davon an.
  Verhalten von `/api/horses` unverändert.

- Ladeverhalten von Skripten, Stylesheets und Bildern optimiert (#263):
  - **Katalogbilder laden `lazy`**, ebenso die Vorschaubilder der
    Hengstverwaltung. Beide Stellen geben ihre Bildhöhe fest vor, es entsteht
    also kein Layout-Sprung. Das **Hero-Foto der Detailseite bleibt bewusst
    eager** und bekommt stattdessen `fetchpriority="high"`: Es ist dort das
    LCP-Element, `lazy` hätte die wahrgenommene Ladezeit verschlechtert.
  - **Die drei nicht-kritischen Skripte** (Katalogfilter, Pedigree-Zoom,
    Darkmode-Umschalter) liegen jetzt unter `public/js/` und werden mit
    `defer` geladen. Sie standen vorher als Inline-Blöcke im HTML — `defer`
    daran zu schreiben wäre wirkungslos geblieben, das Attribut gilt laut
    HTML-Standard nur für Skripte mit `src`. Nebeneffekt: Die Dateien werden
    jetzt über Seitenaufrufe hinweg vom Browser zwischengespeichert.
  - **Der Darkmode-FOUC-Fix bleibt unverändert inline und synchron im
    `<head>`** (#91) — er muss vor dem ersten Rendern laufen.
  - **Das Schriften-Stylesheet des Fremdhosts blockiert das Rendern nicht
    mehr** (`media="print"` + `onload`, mit `<noscript>`-Rückfall). `style.css`
    bleibt bewusst blockierend: asynchron geladen zeigte die Seite garantiert
    einmal ungestylt.

- `X-Frame-Options` wird nur noch von PHP gesetzt, nicht mehr zusätzlich in
  `public/.htaccess`. Der Header kann keine Allowlist ausdrücken (`ALLOW-FROM`
  ist zurückgezogen), die Freigabe läuft deshalb über CSP `frame-ancestors` —
  und Apache setzt seine `Header`-Direktiven nach PHP, hätte den für eine
  Embed-Antwort entfernten Header also wieder angefügt und die Freigabe still
  aufgehoben.

- Die Content-Security-Policy wird in `App\Security\ContentSecurityPolicy`
  aufgebaut statt als Literal in `config/config.php`. Es gibt jetzt zwei
  Fassungen, die sich in einer Direktive unterscheiden; zwei getrennte Literale
  wären die Bauart, die auseinanderdriftet.

- `public/uploads/.htaccess` setzt CORP und `nosniff` zusätzlich für den
  statischen Restweg (Bestandslinks, Addons, die den rohen Spaltenwert
  rendern). **Nur für Apache** — der eigentliche Schutz liegt bewusst im
  Anwendungscode, weil eine nginx-Installation von `.htaccess` nichts hat und
  die Testsuite (`php -S`) sie nicht auswertet.

- Fußzeile (#257): Betreiber- und Framework-Copyright stehen jetzt in zwei
  getrennten Blöcken, jedes unmittelbar über den Links, die zu ihm gehören —
  Betreiber-© über Impressum/Datenschutz/DSGVO (Angaben zur Instanz),
  Framework-© über Handbuch/Austausch/Fehlermeldung/Lizenz (Angaben zum
  Projekt). Vorher standen beide Copyrights zusammen in einer Zeile und ihre
  Links darunter, ohne erkennbare Zuordnung. Die inhaltliche Trennung war als
  bewusste Entscheidung schon dokumentiert (#199, § 13 UrhG / AGPL-3.0 § 5(d)),
  nur die Darstellung fasste beides zusammen. Die Tagline hängt am
  Framework-Block, weil sie das Projekt beschreibt und nicht die Installation.
  Rein visuell über Blockabstand gelöst, ohne Trennlinie und ohne Spalten —
  die brächten bei schmalen Viewports nur Umbruchprobleme. Keine neuen
  Übersetzungsschlüssel, keine Backend-Änderung.

### Behoben

- Der Testserver der Functional-Suite bricht jetzt ab, wenn sein Port schon
  belegt ist, statt still gegen die fremde Instanz zu testen. Der Port ist
  fest (8767), und die Addons-Suite startet über den vendorierten Kern
  denselben Server: Läuft eine zweite Suite an, während die erste lebt,
  kann `php -S` nicht binden - `proc_open()` lieferte trotzdem eine
  Ressource, und die Bereitschaftsprüfung sah nur, dass *irgendwer* auf dem
  Port antwortet. Die Suite lief dann komplett gegen die fremde Anwendung
  samt deren Datenbank. Das Fehlerbild führte in die Irre: `/login` statt
  `/2fa/setup` bei der Ersteinrichtung, danach "Table users doesn't exist"
  trotz frisch angelegter Datenbank - es sah nach einem Schema-Problem aus
  und war ein Portproblem. Jetzt ein klarer Abbruch mit Hinweis auf die
  Ursache und `ss -ltnp | grep 8767`; zusätzlich wird geprüft, ob der eigene
  Subprozess überhaupt noch läuft. Das zählt besonders für den nächtlichen
  `devhost-tests`-Lauf, der Framework, Addons und E2E binnen Minuten prüft
  und unbeaufsichtigt Issues meldet - "konnte nicht prüfen" und "geprüft,
  ist kaputt" sind verschiedene Aussagen.

- `ApiStatsTest::testRequiresAnApiKey` erzwingt die Ersteinrichtung, bevor es
  die 401-Antwort prüft. Ohne das antwortet eine frische Datenbank auf jede
  Route mit dem Setup-Redirect (302), und der rein anonyme Test war von der
  Ausführungsreihenfolge abhängig - im vollen Lauf grün, allein auf frischer
  Datenbank rot.

- `storage/logs/audit_errors.log` wird nicht mehr versioniert. `AuditLogger`
  fällt bei nicht erreichbarer Datenbank auf eine Datei zurück und schreibt
  dorthin — jeder Testlauf erzeugte dadurch einen Diff im Arbeitsverzeichnis,
  und die Repo-Fassung enthielt bereits 14 Zeilen Fehlermeldungen aus fremden
  Testläufen. Das Verzeichnis bleibt über `.gitkeep` versioniert, sein Inhalt
  nicht (dieselbe Bauart wie `var/` und `plugins/`).

- DSGVO-Formular meldete stille Datenverluste als Erfolg (#258): Ungültige
  Eingaben (fehlerhafte E-Mail-Adresse, unbekannter Anfrage-Typ) wurden
  verworfen, dem Absender aber trotzdem `?success=1` gemeldet — eine echte
  Betroffenen-Anfrage konnte so unbemerkt verlorengehen. Jetzt serverseitige
  Validierung inklusive Feldlängen, mit Fehlermeldung und **ohne Verlust der
  bereits eingegebenen Werte**: Eine lange Anfrage nach einem Rechenfehler noch
  einmal tippen zu müssen, ist der sicherste Weg, dass jemand sein
  Auskunftsrecht am Ende nicht wahrnimmt.

- Dark Mode: Kontrastverstöße auf der öffentlichen Oberfläche behoben
  (Regression seit v0.5.0, #248). `color-scheme` folgt jetzt dem Theme,
  damit Browser-eigene Bedienelement-Farben (UA-Buttontext) im Darkmode
  nicht mehr schwarz auf dunklen Theme-Flächen stehen (betraf Plugin-Buttons
  wie „☆ Merken" mit 1,44:1 und „QR-Code anzeigen" mit 1,26:1). Die
  Footer-Links nutzen wieder `--footer-link-color` statt der globalen
  Inhalts-Linkfarbe (Spezifität, im hellen Theme nur 1,8:1 auf der
  Markenfläche). Der E2E-Kontrast-Audit versteht zusätzlich moderne
  Farb-Serialisierungen (`color(srgb …)`, `oklab(…)` aus `color-mix`) und
  misst ohne CSS-Transitions - der gemeldete Wert 1,52 für
  `btn-nav-abgrenzung` war ein Messfehler des Audits (real ~8,6:1).


## [0.5.0] – 2026-08-11

### Hinzugefügt

- Wartungsmodus im Kern (#232): Werkzeuge (z. B. der Datenbank-Import des
  Addons `datenmigration`) können über `App\Service\Maintenance::enable($grund)`
  / `disable()` eine Marker-Datei `var/wartung.lock` setzen; ein früher Check
  im Bootstrap beantwortet dann jeden Request mit HTTP 503 samt `Retry-After`
  und schlichter, übersetzter Hinweisseite - vor Router und Datenbank, damit
  die Sperre auch bei halb eingespielter Datenbank greift. Admin-Sessions sind
  bewusst nicht ausgenommen (Begründung im Code, siehe `Maintenance::guard()`).
- Schema-Migration als aufrufbare Klasse `App\Service\SchemaMigrator` (#230):
  `run()` liefert die durchgeführten Schritte, `storedVersion()`/`isUpToDate()`
  machen den Stand abfragbar; `database/migrate.php` ist nur noch ein dünner
  CLI-Wrapper. Restore-/Import-Wege können Dumps älterer Kern-Versionen damit
  ohne `shell_exec` auf den aktuellen Stand heben.
- Streamender Datenbank-Dump `DatabaseDumper::dumpTo(callable)` (#231) mit
  konstantem Speicherbedarf; `dump(): string` bleibt als kompatibler Wrapper.
- Externes Backup kann Uploads mitsichern (#233): Opt-in-Einstellung
  "Hochgeladene Dateien mitsichern", tar(.gz)-Archiv von `public/uploads`
  über den neuen streamenden ustar-Schreiber `App\Service\TarArchive`,
  getrennte Aufbewahrungsrotation für `backup-*`/`uploads-*`.
- Streamender Datei-Upload in allen drei Backup-Zielen (#237):
  `BackupTarget::putObjectFromFile()` lädt Dump- und Uploads-Archive direkt
  aus der Datei (S3 Single-PUT mit `hash_file`-SHA256, FTPS `ftp_fput`,
  WebDAV über rohen TLS-Socket) - der Speicherbedarf der gesamten
  Backup-Kette ist damit unabhängig von der Instanzgröße.
- Kastrationsdatum als eigenes Sachdatum `horses.castration_date` (#239)
  samt Migrationsschritt, Formularfeld (nur bei Wallach eingeblendet) und
  Anzeige auf der Detailseite.
- Länderflaggen (#240): `App\Helper\CountryFlag` mappt Ländernamen
  (deutsch/englisch, tolerant) und ISO-Codes auf Flaggen-Emoji; Anzeige mit
  Tooltip neben Züchter/Besitzer/Halter auf der Pferde-Detailseite und in
  der Personenverwaltung.

### Geändert

- Öffentliche Pferde-Detailseite neu gegliedert (#242): Hero-Karte mit Foto
  (Platzhalter statt Layout-Sprung), Identitätszeile und zweispaltigem
  Steckbrief; danach thematische Karten Abstammung, Leistung & Auszeichnungen
  (Plugin-Sektionen), Zucht & Personen und Beschreibung. Die Deckstation
  steht im Steckbrief nur noch als Link, die Historie im Personen-Verlauf.

### Behoben

- Die CLI-Skripte `database/migrate.php`/`seed.php`/`reset.php` luden keine
  App-Klassen mehr (fehlender Autoloader) und waren damit unbenutzbar (#236).
- Schema-Drift beseitigt: `users.passkeys`, `plugins.dir_stamp`/`source`,
  `api_keys.issued_session_version` und vier `horses`-Indizes fehlten in
  `database/schema.sql`; ein neuer Drift-Test verhindert Wiederholung (#236).

## [0.4.1] – 2026-08-10

### Behoben

- Benutzerverwaltung: Ein (serverseitig korrekt verhinderter) Versuch, das
  eigene Konto zu löschen, meldete trotzdem „Aktion erfolgreich
  ausgeführt" - jetzt eigener Fehler-Redirect mit der Meldung „Das eigene
  Konto kann nicht gelöscht werden." (#228)

### Hinzugefügt

- Functional-Testklasse für die Benutzerverwaltung (sieben Abläufe:
  Anlegen/Validierung, Bearbeiten inkl. Gruppen, Soft-Delete mit
  Session-Ende, Selbstschutz, Zugriffsschutz/CSRF, 2FA-Reset) - die in der
  README dokumentierte Lücke „Noch nicht abgedeckt: Benutzerverwaltung"
  ist damit geschlossen (#227)

### Geändert

- README: Statuszeile zeitlos formuliert (verwies noch auf v0.2.0) und
  Testsuite-Beschreibung aktualisiert (#226, #227)

## [0.4.0] – 2026-08-10

Update-System denkt Addons mit (Übersicht, Warnungen, Autoupdate aus
Release-Tags, Pflicht-Obergrenze im Manifest), Plugin-Seiten rendern im
Haupt-Layout, zehn neue Sprachen samt Dropdown-Umschalter und wählbaren
aktiven Sprachen, kontrastsichere Footer-/Button-Farben, Framework-
Autorenvermerk, DDG-Korrektur und wählbare Digest-Empfängergruppen.
Dazu alle dreizehn Framework-Befunde des wöchentlichen Bug-Scans
(#212–#224): härteres Addon-Autoupdate, versionierte Schemapflege,
API-Schlüssel-Entzug beim Passwort-Reset und eine Reihe von Daten- und
Performance-Korrekturen.
**Enthält einen Breaking Change** (Pflichtfeld `core_supported_max` in
Addon-Manifesten, siehe unten) - die offiziellen Addons ziehen mit ihrem
Release v0.4.0 gleich.

### Hinzugefügt

- Empfängergruppen des E-Mail-Digests sind wählbar: Auf der
  Digest-Einstellungsseite lässt sich je Gruppe anhaken, wer den Bericht
  bekommt (Mehrfachauswahl, mindestens eine; Standard bleibt Admin +
  Editor, Bestandsinstallationen verhalten sich unverändert). Der
  „keine Empfänger"-Fehler nennt jetzt die konfigurierten Gruppen
- Zehn weitere Sprachen für die öffentlichen Seiten: Dänisch, Niederländisch,
  Französisch, Luxemburgisch, Italienisch, Tschechisch, Polnisch, Norwegisch
  (Bokmål), Schwedisch und Finnisch - jeweils der vollständige Schlüsselsatz
  aus `de.php` inkl. `format.date` je Locale; maschinell erstellte
  Erstübersetzungen, im Kopfkommentar als solche gekennzeichnet (#198)
- Sprachumschalter im Footer ist jetzt ein beschriftetes Dropdown (zwölf
  Sprachen mit Eigennamen, aktive Locale vorausgewählt, `<noscript>`-Knopf
  für Besucher ohne JavaScript); Mechanik unverändert `?lang=xx` +
  Session-Übernahme (#198)
- Vollständigkeits-Gate `tests/Unit/I18n/LocaleCompletenessTest.php`: jede
  registrierte Locale muss den kompletten `de.php`-Schlüsselsatz abdecken,
  Platzhaltermengen müssen übereinstimmen, `format.date` muss brauchbar
  sein, maschinelle Übersetzungen müssen gekennzeichnet sein; `lang/` und
  Registrierung dürfen nicht auseinanderlaufen (#198)
- Aktive Sprachen wählbar: In den Systemeinstellungen lässt sich je Sprache
  abschalten, ob sie im Sprachumschalter angeboten und per `?lang=`
  angenommen wird (Standard: alle; Deutsch als Quellsprache und die
  Standardsprache sind immer aktiv; die Sprachdateien bleiben
  installiert) (#198)
- `App\Plugin\PluginPage::render()`: Plugin-Seiten rendern im zentralen
  Haupt-Layout - mit Header, Navigation, Footer, Theme-Umschalter und den
  admin-konfigurierten Markenfarben, statt als eigenständige, unthemebare
  HTML-Dokumente. Das Referenz-Plugin nutzt den Dienst auf allen drei
  Seiten; neuer Doku-Abschnitt „Theming & Darkmode für Plugin-Seiten" in
  docs/plugin-development.md (inkl. Marker-Konvention für bewusste
  Ausnahmen wie Druckansichten); statischer Lint-Test verhindert den
  Rückfall des Demo-Plugins in eigenständige Dokumente (Addons#66)
- Addon-Autoupdate für das offizielle Repo (#197, Stufe 2): Beim
  Kern-Update werden die aus dem offiziellen Repo installierten Addons
  automatisch auf den zur Ziel-Linie passenden Release-Stand mitgezogen
  (Reihenfolge Pflicht-Backup → Kern → Addons; Addon-Fehler brechen das
  Kern-Update nicht ab und stehen im Audit-Log). Auf der Update-Seite
  lässt sich jedes Addon mit offenem Update einzeln innerhalb der
  laufenden Kern-Linie aktualisieren. Fremd-Repos bleiben ausdrücklich
  manuell; die Freigabe-Logik (Re-Approval) ist unverändert
- Addon-Store und Autoupdate lesen für das offizielle Repo den besten
  Release-Tag `vX.Y.z` zur Kern-Linie statt des Branch-HEAD (Fallback auf
  den Branch, solange kein passender Release existiert) - ein halb
  fertiger `main`-Stand kann nicht mehr auf Produktivinstanzen landen
  (#197, Stufe 3; Release-Prozess im Addons-Repo: Addons#65)
- Update-Seite denkt Addons mit (#197, Stufe 1): `/admin/updates` zeigt je
  installiertem Addon die Katalog-Version des offiziellen Repos (aus dem
  Store-Cache, netzwerkfrei) und die Kompatibilität - geprüft gegen die
  laufende UND gegen die Zielversion eines verfügbaren Kern-Updates; vor
  dem Einspielen warnt die Seite, welche aktiven Addons das Update
  deaktivieren würde. Das Dashboard zählt offene Addon-Updates an der
  Update-Kachel
- Neues optionales Manifestfeld `core_supported_max` ("Major.Minor"):
  höchste unterstützte Kern-Linie eines Addons. Läuft ein neuerer Kern,
  gilt das Addon als inkompatibel; die Angabe wird mit dem Addon-Autoupdate
  zur Pflicht (Formatprüfung greift schon jetzt). `core_compatibility`
  bleibt bewusst Ein-Operator-Format - Bereichs-Syntax wäre fail-closed
  inkompatibel (#197)
- Der bisher stumme Kompatibilitäts-Skip beim Plugin-Laden ist erklärbar:
  `/admin/plugins` nennt zum „Inkompatibel"-Badge jetzt die Begründung
  (z. B. „unterstützt höchstens Kern 0.3, geprüft gegen 0.4.0") (#197)
- `App\Helper\ColorContrast`: WCAG-Kontrastrechnung (Hex-Parsing, Ratio,
  `readableTextOn()`) für die admin-konfigurierbaren Markenfarben; von
  `layout.php` zur Laufzeit genutzt und per Unit-Test abgesichert, dass die
  gelieferte Textfarbe für jede Fläche ≥ 4,5:1 erreicht (#196)
- Kontrast-Gates: neuer Unit-Test rechnet die Theme-Defaults aus
  `style.css` nach (inkl. erzwungener Wortgleichheit der beiden
  Darkmode-Zwillingsblöcke), und der E2E-Parcours prüft Footer und
  Nav-Buttons zusätzlich gegen absolute WCAG-Schwellen in beiden Themes -
  der bisherige Dark-Audit war regressions-gescoped und für Farben blind,
  die in beiden Themes gleich sind; die `darkmode`-Phase läuft jetzt auch
  im E2E-Nachtlauf (#196)
- Footer zeigt neben dem Betreiber-Copyright den Autorenvermerk des
  Frameworks („Framework © 2026 Tim Heyne", verlinkt) - sichtbare
  Namensnennung nach § 13 UrhG und Teil der „Appropriate Legal Notices"
  der AGPL-3.0 (§ 5(d)); neuer Sprachschlüssel
  `footer.framework_copyright`, README um Lizenz-/Copyright-Abschnitt
  ergänzt (#199)
- Plugin-`install()`-Hook: Der PluginManager ruft eine öffentliche
  `install()`-Methode des Plugins bei der Aktivierung und nach einem
  Addon-Update auf - einmalige Einrichtung wie Tabellenanlage läuft damit
  nicht mehr in jedem Request (Addons#75)
- API-Schlüssel-Verwaltung im Benutzer-Formular: ausgestellte Schlüssel
  werden mit Label, Anzeige-Präfix, Ausstellungs- und letztem
  Nutzungszeitpunkt gelistet und lassen sich gesammelt widerrufen
  (auditiert) (#217)
- Functional-Absicherung der Katalog-Sichtbarkeitsgrenzen: Züchter-,
  Besitzer- und Deckstations-Filter können keine unveröffentlichten
  Personen/Stationen verraten, unveröffentlichte Pferde erscheinen weder
  im Katalog noch auf der Detailseite (#223)
- SSO-Negativtests: unbekannte, unbestätigte und soft-gelöschte Konten
  werden bei der Anmeldung über den Test-Identitätsanbieter nachweislich
  abgewiesen (#216)

### Geändert

- **Breaking:** `core_supported_max` ist jetzt Pflichtfeld in
  Addon-Manifesten - Manifeste ohne (gültige) Major.Minor-Angabe werden
  abgewiesen: Installation über Store und manuellen Weg verweigert, ein
  bereits installiertes Addon gilt als inkompatibel und wird nicht
  geladen (sichtbar mit Begründung statt still, #197). Die Manifeste des
  offiziellen Addons-Repos werden mit Addons#65 umgestellt
- **Breaking:** Manuell kopierte oder per Branch installierte Plugins
  benötigen nach einem Versionswechsel künftig die erneute Admin-Freigabe
  (bisher galt nur eine Inhaltsänderung bei gleicher Version als
  freigabepflichtig) (#212)
- Schemapflege ist versioniert (#213): Der Verbindungsaufbau vergleicht
  eine persistierte `schema_version` mit dem Code-Stand - stimmen sie
  überein, kostet das eine einzige Abfrage statt sämtlicher
  `ALTER`-/Index-Prüfungen je Request; Migrationen laufen nach einem
  Update genau einmal
- Blutlinien-Vorschlagssuche mit Vorfilter statt Kreuzprodukt (#215):
  Kandidaten kommen gezielt über neue Indizes auf `(deleted_at, sire_id)`
  bzw. `(deleted_at, dam_id)`; ein Test-Orakel belegt identische
  Vorschläge zur alten O(n²)-Logik
- Papierkorb-Leerung löscht chargenweise (ein `DELETE … WHERE id IN (…)`
  je 500er-Charge in einer Transaktion) statt Zeile für Zeile;
  `before_delete`-Hooks feuern unverändert je Pferd (#222)
- Katalog: Die vier Filter-Dropdown-Abfragen laufen nur noch beim vollen
  Seitenaufbau statt bei jedem AJAX-Filterschritt, und die
  `DISTINCT`-Listen für Farbe/Rasse nutzen neue Indizes statt Full Table
  Scans (#221)
- Plugin-Freigabeprüfung je Request über einen billigen
  Verzeichnis-Stempel (max. Änderungszeit, Dateianzahl, Gesamtgröße); der
  volle SHA-256-Fingerabdruck läuft nur noch bei Abweichung oder
  Freigabe - fail-closed wie bisher (#224)

### Behoben

- Impressum-Platzhalter verweist auf § 5 DDG statt auf das zum 14.05.2024
  außer Kraft getretene TMG; der Sprachschlüssel heißt jetzt
  `legal.impressum_section_ddg` (ein vom Betreiber gepflegtes
  `impressum_text` ist nicht berührt) (#200)
- Footer-Kontraste: Text-/Linkfarbe des Footers werden nicht mehr aus den
  beiden frei wählbaren Markenfarben kombiniert (im Extremfall 1,59:1),
  sondern aus abgeleiteten, kontrastsicheren Variablen - im hellen Theme aus
  der neu berechneten Textfarbe `--on-primary` (Weiß oder Schwarz, je nach
  Luminanz der Primärfarbe, garantiert ≥ 4,5:1), im Darkmode aus den
  Theme-Flächenfarben; Footer-Links sind zur Unterscheidung durchgängig
  unterstrichen (#196)
- Admin-Portal-Button: Insel-Stile (`.nav-btn-admin-*` inline in
  `layout.php`) entfernt und auf die gemeinsamen Button-Klassen
  (`.btn`/`.btn-secondary` plus neuer Modifier `.btn-nav`) zurückgeführt;
  Textfarbe kommt aus `--on-primary` statt hartem Weiß, der Rahmen hebt die
  Fläche im Darkmode ≥ 3:1 von der Kopfzeile ab (#196)
- Deckstations-Verknüpfung überlebt Personen-Speicherungen: Ein beim
  Bearbeiten geleerter Personen-Block setzt die verknüpfte Bestandsstation
  nicht mehr still zurück; entfernt wird sie nur noch ausdrücklich (#214)
- Plugin-Seiten respektieren die aktiven Sprachen: `?lang=` auf eine
  deaktivierte Locale wird zentral verworfen (eine Regel für
  `BaseController` und `PluginPage`) und nicht mehr in die Session
  übernommen; eine inaktiv gewordene Session-Locale fällt auf die
  Standardsprache zurück und wird bereinigt (#220)
- Addon-Überschreiben ist atomar: Die neue Fassung wird vollständig in ein
  Staging-Verzeichnis entpackt und erst dann per `rename` getauscht
  (Rollback bei Aktivierungsfehlern) - zuvor konnte ein fehlgeschlagener
  Kopiervorgang das alte Addon gelöscht zurücklassen (#219)

### Sicherheit

- Addon-Autoupdate installiert ausschließlich von Release-Tags des
  offiziellen Repos; der stille Fallback auf den Branch-HEAD entfällt
  (klare Fehlermeldung statt ungeprüftem Zwischenstand), installierte
  Quellen werden auf `owner/repo@tag` gepinnt (#212)
- API-Schlüssel verlieren beim Passwort-Reset ihre Gültigkeit: Jeder
  Schlüssel ist an die `session_version` seines Besitzers gekoppelt -
  dieselbe Entzugs-Kette, die bei einem Reset bestehende Sessions
  beendet (#217)
- Rechte-Kopie auf die öffentliche Gast-Gruppe filtert Schreib- und
  Verwaltungsrechte konsequent heraus - auch bei direkt manipulierten
  POSTs; die Oberfläche bietet das Kopieren für die Gast-Gruppe nicht
  mehr an (#218)

## [0.3.0] – 2026-08-09

Stammdaten-Ausbau für die IGFjordpferd-Migration (fehlende Felder des alten
Verzeichnisses) samt Status-Split, dazu Footer-Projektlinks, UI-/Doku-Pflege
und Supply-Chain-Härtung der E2E-Helfer. **Enthält einen Breaking Change**
(Status-Split, siehe unten) - bestehende Installationen migrieren beim ersten
Request automatisch.

### Hinzugefügt

- **Vollständiges Geburtsdatum, Stockmaß und Todesjahr** für Pferde (#188):
  `horses.birth_date` ist führend, wenn gesetzt - `birth_year` wird daraus
  abgeleitet und bleibt für Filter und Plugins erhalten. `horses.height_cm`
  (Stockmaß in cm, 50–250) und `horses.death_year` neu. Überall verfügbar:
  Admin-Formular, CSV-Import (neue Spalten `birth_date` [ISO oder TT.MM.JJJJ],
  `height_cm`, `deceased`, `death_year`; Alt-Wert `status=deceased` bleibt
  importierbar), JSON-API (`birth_date`, `height_cm`, `is_deceased`,
  `death_year` + neuer Filter `deceased=0|1`) und öffentliche Detailseite.
  Todesjahr vor Geburtsjahr wird serverseitig abgelehnt.
- **Strukturierte Personendaten** (#188): Straße, Hausnummer, PLZ, Ort, Land,
  E-Mail und Verbands-Mitgliedsstatus als eigene Felder; `contact_info`
  bleibt als Freitext-Restfeld. Öffentlich (und im `$horsePersons`-
  Hook-Payload) erscheinen nur Ort, Land und Mitgliedsstatus - E-Mail und
  Adresse bleiben Admin-only. Die DSGVO-Anonymisierung leert alle neuen
  Felder; die DSGVO-Personensuche findet Personen jetzt auch über die
  E-Mail-Spalte.

### Geändert

- Der Zuchtstatus im Pferde-Formular wird jetzt serverseitig gegen eine
  Whitelist geprüft (vorher ungeprüft übernommen).
- **Footer-Projektlinks** (#184, #185, #186, #187): Handbuch (Wiki),
  Diskussionen, Fehler melden (GitHub Issues) und Lizenz (AGPL-3.0) sind
  jetzt aus der laufenden App heraus verlinkt.
- Rasse-Platzhalter in Formular und Katalog-Filter auf „Fjordpferd"/„Fjord
  Horse" geändert (#183).

### Behoben

- **Admin-Menü-Hover ruckelte** (#181): `transition: all` animierte auch
  Layout-Eigenschaften (font-weight/padding) und erzwang
  Layout-Neuberechnung pro Mausbewegung - ersetzt durch explizite
  Property-Listen.
- Interne Issue-Verweise („(#48)" u. ä.) aus den Admin-Hilfetexten entfernt
  (#182) - Systemeinstellungen, Backup-, Digest-, Cron- und
  Gruppen-Verwaltung.
- Doku- und Wiki-Abgleich (#180): `docs/` nennt die kanonische
  `/horse`-Route und das „Verzeichnis"; das GitHub-Wiki wurde komplett auf
  den aktuellen Stand gehoben (inkl. der neuen Felder, Pedigree-Tiefe 3/6
  und der Sichtbarkeit über das Veröffentlichen-Flag).

### Sicherheit

- E2E-Helfer supply-chain-gehärtet (Scorecard Pinned-Dependencies):
  Basisimages per Digest, pip-Pakete inkl. transitiver Abhängigkeiten per
  `--require-hashes`, minio/mailpit im Helper-Compose per Digest gepinnt;
  checkout-Action in codeql.yml auf den überall verwendeten v7.0.1-SHA
  angeglichen.

### Breaking Changes

- **Status-Split** (#188): `horses.status` ist nur noch der **Zuchtstatus**
  (`active`/`inactive`); der Lebensstatus steht getrennt in
  `horses.is_deceased` + `horses.death_year` - ein Tier kann verstorben und
  zu Lebzeiten dennoch aktiv geführt sein. Die Migration überführt
  `status='deceased'` einmalig in `is_deceased=1, status='inactive'` und
  entfernt `deceased` aus dem Enum. Folgen: Das API-Feld `status` liefert
  nie mehr `deceased`; der API-Filter `status=deceased` wird ignoriert
  (neu: `deceased=0|1`). Der Katalogfilter `q_status=deceased` bleibt
  funktional und mappt auf `is_deceased=1`. Plugins, die `deceased` aus
  `status` lesen, müssen auf `is_deceased` umstellen (betroffen:
  katalog-export, statistik-dashboard im Addons-Repo).
- **Hook-Vertrag erweitert** (#188): `$horsePersons` enthält zusätzlich
  `city`, `country`, `membership_status` (bewusst NICHT E-Mail/Adresse);
  die `catalog.card_sections`-Teilmenge zusätzlich `birth_date`,
  `is_deceased`, `death_year`.

## [0.2.0] – 2026-08-09

Erstes stabiles Release. Gegenüber v0.2.0-beta.2 kamen die Kernattribute
Geschlecht und Rasse samt Abstammungs-Validierung, Lösch-Hooks für Plugins,
die kanonische `/horse`-Route, ein nächtlicher End-to-End-Testlauf sowie
Darkmode- und Barrierefreiheits-Korrekturen hinzu. Beide Repos
(Framework und Addons) stehen auf null offenen Issues; die volle Suite
(Unit/Integration/Functional) und der E2E-Parcours sind grün.

### Hinzugefügt

- **Geschlechts- und Rassefeld für Pferde** (#163, #165): `horses.sex`
  (`stallion`/`mare`/`gelding`, `NULL` = unbekannt — der Altbestand bleibt
  uneingeschränkt editierbar) und `horses.breed` (Freitext). Beide Felder im
  Admin-Formular, CSV-Import (Geschlechts-Aliasse `hengst`/`stute`/`wallach`,
  ungültige Angabe = Zeilenfehler), in der JSON-API, auf der öffentlichen
  Detailseite und als Katalogfilter (`q_sex`, `q_breed` inkl. AJAX-Pfad).
- **Abstammungs-Validierung** (#166, #167): Eltern-Auswahlfelder bieten nur
  rollen-passende Tiere an (Vater keine Stuten/Wallache, Mutter keine
  Hengste/Wallache); das Speichern und der Blutlinien-/Match-Assistent
  lehnen geschlechts-widrige Verknüpfungen serverseitig ab. Ein
  Datenqualitäts-Report unter `/admin/matches` listet Altbestand mit
  unpassendem Eltern-Geschlecht. Fehl-Aktionen werden auf
  `/admin/horses` und `/admin/matches` jetzt sichtbar gemeldet (die
  `?error=`-Codes wurden zuvor stumm verworfen).
- **Lösch- und Papierkorb-Hooks** (#164): `horse.before_delete`,
  `horse.trashed`, `horse.restored`, `horse.deleted` — jeweils mit dem
  kompletten Datensatz als Payload; auch das Papierkorb-Leeren feuert je
  Pferd. Damit können Plugins abhängige Daten (z. B. Verkaufs-Inserate)
  nachziehen; dokumentiert in `docs/plugin-development.md`.
- **Kanonische Route `/horse`** (#171): Die Detailseite läuft unter
  `/horse?id=…`; `/hengst` leitet **dauerhaft** per 301 mit
  Query-Passthrough weiter — gedruckte QR-Codes und exportierte PDFs mit
  alten URLs bleiben für immer gültig. Neuer Router-Helfer
  `Router::redirect()`.
- **Nächtlicher End-to-End-Testlauf** (#161, #162): `tests/e2e/` baut die
  App aus dem Dockerfile, fährt Wegwerf-Backup-Ziele (S3/WebDAV/FTPS) und
  mailpit hoch und prüft Store, Stammdaten, Ansichten, Filter, API,
  Backups, Mail, Cron und Update per Browser-Parcours — inklusive
  WCAG-Kontrast-Audit im Dark-Mode.

### Behoben

- **Dark-Mode-Kontrastfehler in den Kern-Ansichten** (#160): hartkodierte
  Farben auf Theme-Variablen umgestellt (`--success-fg`/`--warning-fg`/
  `*-soft-bg`/`--link-color`); öffentlicher Bereich und Admin ohne
  Dark-Regressionen (vorher 10 bzw. 30 Befunde).
- **Barrierefreiheit** (#169): Weißer Text auf der Sekundärfarbe `#18bc9c`
  erreichte nur 2,41:1 (WCAG-AA-Minimum 4,5:1). Flächen unter weißem Text
  (Button-Hover, Admin-Nav-Hover) nutzen jetzt die eigene Variable
  `--secondary-btn-bg` (`#0e7a66`, 5,26:1); die Markenfarbe bleibt überall
  sonst unverändert.
- **Katalog-Beschriftung** (#170): Der öffentliche Katalog heißt jetzt
  „Verzeichnis" (en: „Registry") statt „Hengstkatalog" — er führt alle
  Pferde, und die Trefferzahl „N Hengste gefunden" war bei gemischtem
  Bestand schlicht falsch (jetzt „N Pferde gefunden").
- **Docker-Betrieb** (#157, #158, #159): PHP-FTP-Extension im Image mit
  SSL gebaut (FTPS-Backups funktionieren im Container); In-Place-Update im
  Container bewusst abgeschaltet (`UPDATE_IN_PLACE=0`) — der Code bleibt
  `root`, nur Datenverzeichnisse sind `www-data`-schreibbar, aktualisiert
  wird über ein neues Image.

## [0.2.0-beta.2] – 2026-08-08

### Hinzugefügt

- Der SSO-Login funktioniert jetzt mit **jedem OIDC-Provider** (Authentik,
  Keycloak, …), nicht mehr nur mit Microsoft Entra ID: Neuer generischer
  Modus über `OIDC_ISSUER_URL`/`OIDC_CLIENT_ID`/`OIDC_CLIENT_SECRET`
  (+ optional `OIDC_PROVIDER_LABEL` für den Login-Button) mit
  OIDC-Discovery, Issuer-Gegenprüfung (RFC 8414) und `https://`-Pflicht
  (Loopback ausgenommen) — siehe `App\Security\OidcDiscovery` und
  [docs/security.md](docs/security.md). Die bisherigen `ENTRA_*`-Variablen
  funktionieren unverändert weiter (Microsoft-Kurzform mit festen
  Endpunkten); bei vollständiger `OIDC_*`-Konfiguration hat der generische
  Modus Vorrang. Die Routen bleiben `/auth/entra*`, bestehende
  Redirect-URIs bleiben gültig.

### Behoben

- `X-XSS-Protection` wird jetzt auch PHP-seitig mit `0` gesendet
  (`config/config.php`), identisch zur `public/.htaccess`: Der dortige
  OWASP-konforme Wert überschrieb unter Apache den PHP-Altwert
  `1; mode=block` — unter `php -S` (Tests, Entwicklung) ging der veraltete,
  selbst angreifbare Browser-Filter aber tatsächlich noch raus.
- Schema-Drift behoben: `horse_persons` (NULL-fähiges `person_id`,
  `breeding_station_id`, `breeding_station_text`) und die Tabelle
  `addon_repos` stehen jetzt auch im Ersteinrichtungs-Schema
  `database/schema.sql`, nicht mehr nur in
  `Database::ensureSchemaUpToDate()` — gemäß der eigenen
  Doppelpflege-Regel (docs/development.md). Zugleich den veralteten
  Kommentar im Schema korrigiert, der behauptete, die Gast-Gruppe `public`
  erhalte nie Berechtigungs-Zeilen (der Seed 60 Zeilen weiter unten vergibt
  ihr `horses.view`/`breeding_stations.view`).

### Geändert

- Dokumentation vollständig gegen den Code abgeglichen und auf den Ist-Stand
  gehoben. Die wichtigsten Korrekturen: kein Rollen-System mehr (README nannte
  noch „Rollen (Admin/Editor)"), 2FA ist pro Gruppe konfigurierbar statt
  global verpflichtend, Backup-Codes werden gehasht (nie als Klartext)
  gespeichert, PHP 8.5 statt 8.3, die Gast-Gruppe `public` erhält bewusst
  Leseberechtigungen und steuert darüber die öffentliche Sichtbarkeit
  (architecture.md/security.md behaupteten das Gegenteil), der Katalog
  paginiert serverseitig (der Performance-Hinweis zu `catalog.card_sections`
  war sachlich umgedreht), `PedigreeBuilder::build()` ist inkl. des für
  öffentliche Ausgaben zwingenden `publishedOnly`-Parameters dokumentiert,
  und database.md beschreibt jetzt `is_published`, das Gruppen-/API-Schema
  und die tatsächlichen Rate-Limiter-Typen. Die beiden Planungsdokumente
  (plugin-system-plan.md, user-groups-plan.md) sind als historisch markiert
  und benennen, wo die Umsetzung abweicht — verbindlich sind
  architecture.md/security.md/plugin-development.md.

- Der Datenvertrag der Plugin-Hooks `horse.detail_sections` und
  `catalog.card_sections` ist jetzt dokumentiert und durch einen Functional-Test
  festgenagelt (`tests/Functional/HorseDetailSectionsHookTest.php`): `$horse`,
  `$horsePersons` und `$pedigree` sind **öffentlich gefilterte** Daten, keine
  Roh-Datensätze. Insbesondere sind alle `station_*`-Felder gemeinsam `null`,
  wenn die Deckstation unveröffentlicht oder gelöscht ist oder der Gast-Gruppe
  `breeding_stations.view` fehlt — `$horse['breeding_station_id']` bleibt dabei
  gesetzt und taugt nicht als Indikator.
  - **Für Plugin-Autoren relevant:** Seit der Sichtbarkeitsverschärfung (#121/#122
    zusammen mit `breeding_stations.is_published`, Default `0`) sehen Plugins bei
    unveröffentlichten Stationen keine Stationsdaten mehr. Ein Plugin, das seinen
    Abschnitt daran knüpft, rendert für solche Pferde nichts — das ist die
    beabsichtigte Wirkung und kein Fehler (#151). Bisher war diese Zusicherung
    nirgends beschrieben.
  - Siehe [docs/plugin-development.md](docs/plugin-development.md),
    Abschnitt „Was in `$horse` und `$horsePersons` steht".

- **Breaking:** Die JSON-API (`GET /api/horses`, `GET /api/horses/show`) ist
  nicht mehr anonym erreichbar, sondern verlangt einen API-Schlüssel im
  Header `Authorization: Bearer <Schlüssel>` (ohne gültigen Schlüssel: `401`).
  Bestehende Einbindungen müssen einen Schlüssel hinterlegen. Bewusst kein
  `?api_key=`-Parameter, damit der Schlüssel nicht in Logs, `Referer`-Headern
  und Browser-History landet (analog zum Cron-Secret, #114).
  - Jeder angemeldete Benutzer verwaltet bis zu **5** eigene Schlüssel unter
    `/api-keys`. Der Klartextwert wird genau einmal angezeigt; gespeichert
    wird nur sein SHA-256-Hash. Widerruf wirkt sofort.
  - Ein Schlüssel darf **höchstens das, was sein Besitzer aktuell darf**
    (Schnittmenge aus dessen Rechten und dem Scope des Schlüssels) - verliert
    der Besitzer ein Recht, verliert es der Schlüssel im selben Moment mit.
    Ein Schlüssel kann bewusst auf **weniger** Rechte eingeschränkt werden.
  - Der Wildcard-CORS-Header (`Access-Control-Allow-Origin: *`) entfällt: ein
    Schlüssel gehört nicht in Browser-JavaScript. Serverseitige Aufrufe sind
    davon nicht betroffen. Antworten tragen zusätzlich `Cache-Control: no-store`.
  - Die Gast-Gruppe (`public`) steuert damit weiterhin den öffentlichen
    HTML-Katalog, aber nicht mehr die API.
  - Siehe [docs/api.md](docs/api.md).

### Behoben

- Der **Name** einer unveröffentlichten oder gelöschten Deckstation konnte
  öffentlich weiterhin erscheinen, obwohl ihre Kontaktdaten korrekt ausgeblendet
  waren (#122): `horses.breeding_station` ist bei verknüpfter Station eine
  denormalisierte Kopie des Stationsnamens, und Pferde-Detailseite, Katalogkarte
  und JSON-API zeigten sie als Fallback, sobald `station_name` fehlte. Die Kopie
  wird jetzt in allen drei Pfaden unterdrückt, sobald die Station öffentlich nicht
  sichtbar ist; freie Texteingaben ohne Stations-Datensatz (z. B. aus dem
  CSV-Import) bleiben unverändert erhalten. Ebenfalls geschlossen: Volltext- und
  Deckstations-Filter des Katalogs trafen auf diese Kopie und ließen sich so als
  Existenz-Orakel für Namen unveröffentlichter Stationen nutzen (#151).

- Qualitätssicherung: Zwei Prüfschritte meldeten Funde, ohne den Lauf rot zu
  machen - beide melden jetzt auch tatsächlich einen Fehler:
  - Der Semgrep-Schritt „Gate auf ERROR-Findings" lief mit Exit `0` durch,
    obwohl er Funde als `blocking` auswies (`semgrep scan` braucht dafür
    `--error`). Der als Pflicht-Check konfigurierte Job war deshalb grün,
    während im Security-Tab zwei ERROR-Funde offenstanden.
  - PHPUnit quittierte Deprecations, Notices und Warnings nur mit „OK, but
    there were issues!" - ohne Fundstelle und ohne roten Lauf. `phpunit.xml`
    nennt jetzt Datei und Zeile (`displayDetailsOnAllIssues`) und lässt die
    Suite daran scheitern (`failOnDeprecation` u. a.).
- Vier PHP-8.5-Deprecations in der Testsuite entfernt, die dadurch
  unbemerkt geblieben waren: `ReflectionMethod::setAccessible()` (ohne
  Wirkung seit PHP 8.1) und `curl_close()` (ohne Wirkung seit PHP 8.0).
- Sicherheit:
  - Die beiden vom Semgrep-Gate durchgelassenen Funde
    (`php.lang.security.injection.echoed-request`) sind bereinigt: Die
    Trefferzähler in den Erfolgsmeldungen von `/admin/cron` und
    `/admin/digest` gehen jetzt zusätzlich durch `htmlspecialchars()`, statt
    sich allein auf den `(int)`-Cast zu verlassen - damit folgt jede Ausgabe
    von Request-Daten im Projekt derselben Regel.
  - `persons.is_published` wird jetzt auf allen öffentlichen Routen erzwungen
    (#121): Katalog, Pferde-Detailseite und JSON-API zeigen nur noch
    veröffentlichte, nicht gelöschte Personen (inkl. der Filter
    `q_breeder`/`q_owner`, die zuvor als Existenz-Orakel für unveröffentlichte
    Namen dienten).
  - Kontaktdaten unveröffentlichter oder gelöschter Deckstationen erscheinen
    nicht mehr auf der öffentlichen Pferde-Detailseite; der Stationsblock wird
    zudem nur noch mit `breeding_stations.view`-Leseberechtigung gerendert -
    analog zur Stationsroute (#122).
  - Der erzwungene Passwortwechsel (`must_change_password`) ließ sich durch
    einen beliebigen Query-Parameter wie `?x=/force-password-change` umgehen -
    der Pfad wird jetzt exakt verglichen statt per `strpos()` über die rohe
    REQUEST_URI (#130).
  - `primary_color`/`secondary_color` werden strikt als Hex-Farbcodes
    validiert, statt CSS-Injection in den globalen `<style>`-Block zuzulassen
    (#136); fehlendes `addslashes()` im `confirm()`-Handler von
    `/admin/updates` ergänzt (#137).
- Datenintegrität:
  - Werksreset (`RESET`) leert jetzt auch `user_groups`, und
    `SetupController::needsSetup()` prüft gegen tatsächlich existierende
    Benutzer - zuvor machten verwaiste Gruppenzuordnungen die Installation
    nach einem Reset dauerhaft unbrauchbar (Setup gesperrt, Login unmöglich)
    (#118).
  - CSV-Import: RFC-4180-konforme, in Anführungszeichen eingebettete
    Zeilenumbrüche (Standard-Export von Excel/LibreOffice) werden korrekt als
    EIN Datensatz gelesen statt Geister-Pferde und stillen Datenverlust zu
    erzeugen (`fgetcsv()` über einen Stream statt zeilenweisem
    `str_getcsv()`) (#124). Der eigentliche Import läuft jetzt in einer
    Transaktion (vollständiges Rollback bei Fehlern, Session-Kopie bleibt für
    einen erneuten Versuch erhalten) und `description` hat eine validierte
    Obergrenze (#133).
  - Auto-Verknüpfung der Abstammung kann ein Pferd nicht mehr zu seinem
    eigenen Vater/seiner eigenen Mutter machen (`AND id != ?` in allen
    UPDATE-Statements); `linkMatch()` lehnt Selbst-Verknüpfung jetzt auch
    serverseitig ab, das Match-Tool schlägt direkte Nachkommen nicht mehr als
    Eltern vor, und der `PedigreeBuilder` bricht Zyklen in Altdaten sauber ab
    (#131).
  - Validierungsfehler beim Anlegen/Bearbeiten eines Benutzers löschen nicht
    mehr alle Gruppenzugehörigkeiten (Gruppen-Selectbox blieb im
    Fehler-Rerender leer); zusätzlich kann sich der eingeloggte Admin nicht
    mehr selbst die `admin`-Gruppe entziehen (#123).
  - Leere Absenderadresse in den Mail-Einstellungen bricht den Mailversand
    nicht mehr still (`?:` statt `??`, Fallback auf `smtp_user` greift jetzt)
    (#132).
  - Backup-Code-Login: leerer/kaputter `backup_codes`-Wert wird sauber als
    "keine Codes" behandelt statt `foreach` über `null` (#128).
  - DSGVO-Löschung und -Anonymisierung schreiben jetzt Audit-Log-Einträge
    (#135).
- Darstellung & Barrierefreiheit:
  - Darkmode: Text war über weite Teile der Oberfläche zu dunkel und damit
    kaum lesbar (#139). Ursache waren Farben, die nicht am Themenwechsel
    teilnahmen — Überschriften und der Seitentitel nutzten `--primary-color`
    direkt (1,4:1 auf dunklem Grund), Fließ- und Hinweistext in den Views
    waren als `#666`/`#888` fest verdrahtet (2,7:1), und neutrale
    Info-Kästen behielten ihre helle Fläche, während der geerbte Text hell
    wurde. Neu sind die Variablen `--primary-fg`, `--text-muted`,
    `--text-subtle`, `--surface-muted` und `--danger-fg`; alle Textrollen
    erreichen jetzt in beiden Themen mindestens WCAG 2.1 AA (4,5:1), die
    meisten AAA.
  - Getragen wurde der Fehler überwiegend von **Inline-Styles in den Views**,
    nicht vom Stylesheet: Rund 60 Überschriften setzten `color:
    var(--primary-color)` direkt im `style`-Attribut und übersteuerten damit
    die zentrale `h1, h2, h3`-Regel. Eine Korrektur allein in
    `style.css` hätte den sichtbaren Fehler nicht behoben.
  - `--primary-fg` wird im Darkmode per `color-mix()` aus der im Admin frei
    wählbaren Primärfarbe abgeleitet, statt einen festen Ersatzton zu setzen -
    geprüft für Blau, Grün und Rot (durchgängig über 7:1). Browser ohne
    `color-mix()` fallen auf einen statischen Wert zurück.
  - Die 2FA-Wiederherstellungscodes standen im Darkmode als heller Text auf
    fest weißem Grund (1,2:1) und waren praktisch unsichtbar — ausgerechnet
    die Codes, die man beim Einrichten abschreiben muss (#139).
  - Die zurückhaltenden Grautöne `#777` bis `#aaa` verfehlten das
    AA-Kriterium schon im hellen Theme (2,2:1 bis 4,5:1); ebenso das Warnrot
    `#dc3545` als reine Textfarbe (4,3:1). Die neuen abgestuften Variablen
    beheben das mit. Farbige Statusboxen, die Hintergrund und Text bewusst
    als Paar setzen, bleiben unverändert - ebenso der weiße Grund hinter dem
    2FA-QR-Code, den Authentikator-Apps zum Erkennen brauchen.

### Geändert

- Performance:
  - Indizes für `horses`, `persons`, `breeding_stations`, `horse_persons` und
    `users` ergänzt (`is_published`/`deleted_at`/`name`/`foreign_ueln`) - in
    `database/schema.sql` und als automatische Migration für
    Bestandsinstallationen (#120).
  - `PedigreeBuilder`: Memoisierung je Baum (gemeinsame Ahnen bei Linienzucht
    werden nur einmal abgefragt), kein verschwendeter Eltern-Lookup mehr auf
    der letzten Generation (Platzhalter jenseits `maxDepth` entfallen damit),
    `LOWER()`-Vergleich entfernt (Spalte ist ohnehin case-insensitiv,
    verhinderte aber jede Index-Nutzung) (#119).
  - Öffentlicher Katalog und JSON-API: Züchter/Besitzer werden aggregiert
    geladen statt über multiplizierende JOINs (ein Pferd mit mehreren
    Besitzern erzeugte mehrere identische Katalogkarten), plus echte
    SQL-Pagination (`LIMIT`/`OFFSET` + separate COUNT-Query) statt alles zu
    laden (#125).
  - `GdprController::index()`: Batch-Personensuche in einer Query statt 1+N
    Full Scans, Anfragenliste paginiert (#126).
  - Papierkorb-Badge: die vier `COUNT(*)`-Queries pro Seitenaufruf zu einer
    zusammengefasst (#134).

### Hinzugefügt

- Tests für zuvor ungetestete sicherheitskritische Pfade: Papierkorb-
  Berechtigungen inkl. 30-Tage-Frist und `type=user`-Sperre (#127),
  Backup-Code-Einmalverbrauch samt Rate-Limit (#128), Plugin-Wiederfreigabe
  nach Code-Änderung ohne Versionserhöhung (#129), DSGVO-Löschung/
  Anonymisierung inkl. 403 für Nicht-Admins (#135) sowie Stammbaum-Zyklen
  (#131).

- Weitere Backup-Ziele: FTPS und WebDAV (#93):
  - Neben Amazon S3 (bzw. S3-kompatiblen Diensten) können automatische
    Backups jetzt auch auf ein FTPS-Ziel (klassischer Hoster-Zugang) oder
    einen WebDAV-Server (z. B. eine vereinseigene Nextcloud-/ownCloud-
    Instanz) hochgeladen werden. Die Auswahl erfolgt in den
    Backup-Einstellungen (`/admin/backup`) über ein neues Dropdown, das die
    passenden Zugangsdaten-Felder einblendet.
  - `App\Service\WebDavClient` nutzt wie `App\Service\S3Client` reine
    PHP-Streams (kein curl nötig). `App\Service\FtpsClient` benötigt die
    PHP-`ftp`-Extension (jetzt im mitgelieferten `Dockerfile` enthalten) und
    verschlüsselt die Verbindung immer per TLS (`ftp_ssl_connect()`) - reines
    unverschlüsseltes FTP wird bewusst nicht angeboten.
  - Zugangsdaten (FTPS-Passwort, WebDAV-Passwort) werden wie das bestehende
    SMTP-/S3-Secret verschlüsselt in der Datenbank abgelegt.

- Barrierefreiheit (a11y) im öffentlichen Katalog verbessert (#51):
  - Alle Filterfelder in der Katalog-Suche (`/katalog`) haben jetzt über
    `for`/`id` korrekt zugeordnete `<label>`-Elemente statt rein visuell
    danebenstehendem Text - vorher gab es für Screenreader-Nutzer keine
    programmatische Verknüpfung. Die Volltextsuche erhält ein zusätzliches,
    visuell verstecktes Label (neue `.sr-only`-Utility-Klasse) statt sich
    allein auf den verschwindenden `placeholder` zu verlassen.
  - Dynamisch per AJAX aktualisierte Inhalte (Trefferanzahl im Katalog,
    Zoomstufe im Pedigree-Baum) sind jetzt als `aria-live="polite"`-Regionen
    markiert, damit Screenreader die Änderung automatisch ankündigen.
  - Icon-only Zoom-Buttons im Pedigree-Baum haben jetzt zusätzlich zum
    `title` ein `aria-label`, da `title` allein nicht zuverlässig von
    assistiven Technologien vorgelesen wird.
  - Das Vereinslogo im Header hat jetzt `alt=""` statt dem generischen Text
    "Logo" - der Vereinsname steht bereits als sichtbarer Text im selben
    Link, ein zusätzlicher Alt-Text würde doppelt vorgelesen.
  - Ein WCAG-AA-Kontrastverstoß (`#888` auf Weiß, ~3.5:1 statt der
    geforderten 4.5:1) in den Pedigree-Baum-Beschriftungen behoben.

- CSV-Bulk-Import für Pferde (#49, `/admin/import/horses`): Vorschau mit
  Validierung je Zeile (Pflichtfeld, Feldlängen, UELN-Eindeutigkeit
  innerhalb der Datei und gegen bestehende Pferde, gültiger Status,
  plausibles Geburtsjahr) vor dem tatsächlichen Import, fehlerhafte Zeilen
  werden übersprungen statt die gesamte Datei abzulehnen. Erkennt
  Komma/Semikolon als Trennzeichen automatisch und normalisiert
  nicht-UTF-8-Exporte älterer Excel-Versionen. Bewusst nur echtes CSV
  (kein natives .xlsx-Parsing) - konsistent mit der
  "keine externen Abhängigkeiten"-Philosophie des Kerns, jede
  Tabellenkalkulation kann als CSV exportieren. `App\Service\HorseCsvImporter`
  kapselt Parsing/Validierung, damit `preview()` und `commit()` exakt
  dieselbe Logik auf demselben, serverseitig zwischengespeicherten
  Rohinhalt anwenden. Importierte Pferde mit unaufgelöster Vater-/
  Mutter-Angabe lassen sich anschließend wie bei der manuellen Einzelanlage
  über das bestehende Blutlinien-Zusammenführen-Werkzeug verknüpfen. Die
  Veröffentlichung ist - konsistent mit der Entkopplung von Status/Sichtbarkeit
  (siehe unten) - eine bewusste Import-Entscheidung über eine eigene
  Checkbox (nur mit `horses.publish`): ohne Häkchen werden die Pferde
  unveröffentlicht angelegt und können später über die Massen-Veröffentlichung
  freigegeben werden.

- Darkmode (#91): Umschaltbares dunkles Farbschema für den öffentlichen
  Katalog und den Admin-Bereich, zentral über CSS-Variablen in
  `public/css/style.css` (`--bg-color`, `--text-color`, `--card-bg`, `--border-color`,
  `--header-bg`) - bestehende und künftige Views (inkl. Plugin-Views) werden
  automatisch mit umgeschaltet, ohne Doppelpflege. Standardmäßig richtet sich
  die Anzeige nach der Systemeinstellung des Browsers
  (`prefers-color-scheme`), ein manueller Umschalter im Header (🌙/☀️)
  überschreibt das in beide Richtungen und merkt sich die Wahl dauerhaft im
  `localStorage` des Browsers. Ein synchron im `<head>` laufendes Init-Script
  verhindert dabei ein kurzes Aufblitzen des falschen Farbschemas beim
  Seitenaufbau (FOUC). Farbige Status-Badges/Meldungsboxen mit fest
  codierten Inline-Styles (z. B. grüne "Erfolg"-Hinweise) bleiben bewusst
  unangetastet, da sie Hintergrund- und Textfarbe bereits als
  zusammengehöriges, unabhängig vom Seitenthema lesbares Paar setzen.

- Leseberechtigung (`view`) und generalisierte Veröffentlichen-Berechtigung
  (`publish`) als **Standard-Aktionen** für jedes Modul - Website- **und**
  Plugin-Funktionen (`App\Permission\PermissionRegistry::STANDARD_ACTIONS`).
  Die Leseberechtigung steuert, ob eine Gruppe einen Bereich sehen darf
  (Backend-Listen sowie - über die Gast-Gruppe - die öffentlichen Seiten).
  Admin erhält beide Rechte automatisch, Editor per Seed.
- Gast-Gruppe: die eingebaute Gruppe `public` gilt automatisch für nicht
  angemeldete Besucher und ist jetzt **normal editierbar**. Über ihre
  Lese-Rechte steuert ein Admin, welche Bereiche öffentlich sichtbar sind
  (z. B. `horses.view` entziehen → Katalog und `/api/horses` zeigen keine
  Pferde mehr). Neue/Plugin-Bereiche sind für Gäste fail-closed unsichtbar,
  bis sie bewusst freigeschaltet werden.
- Eigenständiges Veröffentlicht-Flag (`horses.is_published`), **entkoppelt**
  vom Lebenszyklus-Status (`active`/`inactive`/`deceased`): nur veröffentlichte
  Pferde erscheinen im öffentlichen Katalog/API, der Status ist rein informativ
  und beeinflusst die Sichtbarkeit nicht mehr. Bestehende `status='active'`-
  Pferde werden beim Upgrade automatisch auf veröffentlicht migriert.
- Veröffentlichung jetzt auch für **Personen** und **Deckstationen** (neues
  `is_published`-Flag je Tabelle, analog zu Pferden): unveröffentlichte
  Stationen sind öffentlich nicht mehr erreichbar (`/station?id=` → 404) und
  unveröffentlichte Personen/Stationen erscheinen nicht in den Katalog-Filter-
  listen. Bestehende Datensätze werden beim Upgrade auf veröffentlicht migriert,
  neu angelegte starten unveröffentlicht. Personen-/Stations-Formular erhalten
  dafür - wie das Pferde-Formular - eine „Öffentlich sichtbar“-Checkbox (nur mit
  `publish`-Recht).
- Massen-Veröffentlichung in den Admin-Listen für Pferde, Personen und
  Deckstationen: Filter „Alle / Veröffentlicht / Nicht veröffentlicht“,
  Zeilen-Auswahl per Checkbox (inkl. „alle auswählen“) sowie die Aktionen
  „Veröffentlichen“ und „Veröffentlichung zurücknehmen“ für die Auswahl (nur
  mit `publish`-Recht, CSRF-geschützt). Neue Endpunkte
  `POST /admin/horses|persons|breeding-stations/publish`.

- Öffentliche Read-only-JSON-API für Katalogdaten (#47): `GET /api/horses`
  (Liste, filterbar/paginierbar) und `GET /api/horses/show?ueln=...`
  (Einzelpferd). Liefert ausschließlich Felder, die bereits über den
  öffentlichen HTML-Katalog einsehbar sind, bewusst ohne
  Authentifizierung/API-Key. Siehe [docs/api.md](docs/api.md).
- Neuer Plugin-Filter-Hook `catalog.card_sections` (#97): Erweiterungspunkt
  für zusätzlichen Inhalt je Karte im öffentlichen Katalog (z. B. ein
  "Merken"-Button), analog zu `horse.detail_sections` auf der Detailseite.
  Läuft für beide Rendering-Pfade des Katalogs (normal + AJAX-Filterung), da
  beide dieselbe `public_catalog_cards.php` nutzen. Siehe
  [docs/plugin-development.md](docs/plugin-development.md).

- Addon-Store (`/admin/plugins/store`, siehe
  [docs/plugin-system-plan.md](docs/plugin-system-plan.md), Abschnitt 2.7):
  listet Plugins aus dem offiziellen
  [Hengstverzeichnis_Addons](https://github.com/Celestial0579/Hengstverzeichnis_Addons)-Repo
  sowie beliebigen, per GitHub-Link hinzugefügten weiteren Repos
  (`App\Service\GithubAddonRepository`, `App\Controllers\AddonStoreController`)
  und installiert eine gewählte Version direkt nach `plugins/<slug>/` -
  entspricht dem bisherigen manuellen `cp -r`-Workflow, nur automatisiert.
  Installieren aktiviert ein Plugin dabei **nie** automatisch, das bleibt
  weiterhin ein separater, bewusster Schritt unter „Plugins verwalten“.
- i18n-Gerüst für Mehrsprachigkeit (#48): `App\I18n\Translator` mit
  Array-basierten Sprachdateien (`lang/de.php`, `lang/en.php`) statt
  `gettext`, dynamisches `<html lang>`, admin-konfigurierbare
  Standardsprache (`/admin/system-settings`) sowie Session-basierter
  Sprachumschalter im Footer für einzelne Besucher. Plugins können über ein
  eigenes `lang/<locale>.php`-Verzeichnis automatisch eine eigene,
  kollisionsfreie Übersetzungs-Domain registrieren (siehe
  [docs/plugin-development.md](docs/plugin-development.md), Abschnitt
  „Mehrsprachigkeit“) - demonstriert im Referenz-Plugin unter
  `docs/examples/demo-plugin/`.
- Vollständige Übersetzung (DE/EN) aller öffentlich erreichbaren Seiten
  (#48): Startseite, Hengstkatalog samt Filtern und asynchroner
  Ergebnisliste, Pferde- und Deckstation-Detailseiten inkl. interaktivem
  Stammbaum, Impressum/Datenschutz/DSGVO-Anfrageformular sowie der gesamte
  nicht angemeldete Auth-Flow (Login, 2FA-Verifikation, Passwort vergessen/
  zurücksetzen) und die Fehlerseiten 403/404/500. Der Admin-Bereich bleibt
  bewusst deutsch (siehe [docs/architecture.md](docs/architecture.md),
  Abschnitt „Mehrsprachigkeit / i18n“).
- `App\Service\PedigreeBuilder`: Pedigree-Baum-Aufbau (bisher private Logik
  in `PublicController::horseDetail()`) als eigenständiger, statischer
  Dienst extrahiert und für Plugins direkt aufrufbar gemacht — u. a.
  Voraussetzung für einen Inzuchtkoeffizienten-Rechner mit größerer
  Generationstiefe als die öffentliche Detailseite. Der `horse.detail_sections`-
  Filter erhält zusätzlich den bereits berechneten Baum als vierten,
  rückwärtskompatiblen Parameter (siehe
  [docs/plugin-development.md](docs/plugin-development.md)).
- Erweiterte Generationstiefe im Stammbaum der öffentlichen Pferde-
  Detailseite (#53): der interaktive Umschalter reicht jetzt bis zur
  6. Generation (Urururgroßeltern) statt bisher maximal 4
  (Urgroßeltern), aufbauend auf `App\Service\PedigreeBuilder`.
- Grundlegende Cron-/Scheduler-Infrastruktur (#67): `App\Service\Scheduler`
  als Registry für periodisch auszuführende Aufgaben, Voraussetzung für
  künftige Kern-Features wie automatisierte externe Backups (#59) und einen
  E-Mail-Digest für Admins/Editoren (#52). Zwei Auslösewege - ein durch ein
  admin-generiertes Secret geschützter externer Endpunkt (`/cron/run`, für
  System-Cron) sowie ein manueller Auslöser im neuen Admin-Bereich
  `/admin/cron` für Betreiber ohne Zugriff auf einen System-Cron. Aktuell
  ohne konkret registrierte Aufgaben (siehe
  [docs/architecture.md](docs/architecture.md), Abschnitt
  „Cron-/Scheduler-Infrastruktur“).
- Automatisierte externe Backups (#59): periodische Sicherung der Datenbank
  an einen S3-kompatiblen Speicher (AWS S3, MinIO, Hetzner Object Storage
  o. Ä.) als Kernfunktion, aufbauend auf der neuen Cron-/Scheduler-
  Infrastruktur (#67). `App\Service\DatabaseDumper` erzeugt den Dump als
  reine PHP-Alternative zu `mysqldump`, `App\Service\S3Client` signiert den
  Upload selbst mit AWS Signature Version 4 ohne AWS-SDK/Composer-
  Laufzeitabhängigkeit. Konfigurierbar unter `/admin/backups`
  (Zugangsdaten, Intervall, Aufbewahrungsanzahl/Rotation, manueller
  Testlauf) - siehe [docs/architecture.md](docs/architecture.md), Abschnitt
  „Automatisierte externe Backups“. Enthält bewusst nur die Datenbank, keine
  hochgeladenen Dateien.
- Optionaler E-Mail-Digest für Admins/Editoren (#52): periodische
  Zusammenfassung offener Blutlinien-Match-/Merge-Vorschläge und bald
  ablaufender Papierkorb-Fristen, aufbauend auf der Cron-/Scheduler-
  Infrastruktur (#67). Wird nur versendet, wenn tatsächlich etwas zu
  berichten ist. `App\Service\MatchSuggestionFinder` (aus
  `HorseController` extrahiert, unverändertes Verhalten) sorgt dafür, dass
  Digest und Admin-Match-Seite dieselbe Anzahl sehen. Konfigurierbar unter
  `/admin/digest` (Aktivierung, Intervall, manueller Testlauf) - siehe
  [docs/architecture.md](docs/architecture.md), Abschnitt „E-Mail-Digest
  für Admins/Editoren“.

### Behoben

- Functional-Test-Suite hängte sich reproduzierbar in einer späten Testklasse
  auf (#102): Der `php -S`-Testserver (`tests/Support/PhpBuiltInServer.php`,
  ebenso `FakeS3Server.php`) leitete stdout/stderr in Pipes um, die nie
  ausgelesen wurden. Über die volle Suite lief deren Kernel-Buffer durch die
  Access-Logs voll, woraufhin der Single-Worker-Server beim nächsten Schreiben
  blockierte und alle folgenden Requests in den Timeout liefen. Die Ausgabe
  geht jetzt in eine Logdatei (blockiert nie). Der zuvor als Workaround
  entfernte Regressionstest (`ApiHorsesTest::testDeletedHorseIsNeverExposed`)
  ist damit wieder aktiv und prüft zusätzlich, dass ein gelöschtes Pferd auch
  über `GET /api/horses/show` nicht mehr sichtbar ist.

## [0.2.0-beta.1] – 2026-08-05

### Hinzugefügt

- Plugin-/Erweiterungssystem (#56): Zusatzfunktionen lassen sich jetzt über
  lokal in `plugins/` abgelegte Plugins ergänzen, ohne Kern-Dateien zu
  ändern. Manifest-Validierung samt Kompatibilitätsprüfung, Hook-/Filter-
  System mit try/catch-Isolation je Aufruf, Admin-UI zum Aktivieren/
  Deaktivieren (`/admin/plugins`), erste Erweiterungspunkte (`horse.before_save`/
  `horse.after_save`, `horse.detail_sections`, `admin.dashboard_tiles`) sowie
  optionale, zwingend unter `/plugin/<slug>/...` laufende Plugin-Routen.
  Siehe [docs/plugin-development.md](docs/plugin-development.md).
- Gruppen-/Berechtigungssystem (#66): Admin-konfigurierbare Rechtevergabe je
  Modul × Aktion (Erstellen/Bearbeiten/Löschen/Veröffentlichen) für Pferde,
  Personen und Deckstationen. Drei feste Gruppen (Admin mit stets allen
  Rechten, Editor standardmäßig wie bisher, Öffentlich/Gäste ohne
  Möglichkeit für schreibende Rechte) sowie beliebig viele eigene Gruppen,
  denen Benutzer im Benutzer-Formular zugeordnet werden können. Verwaltung
  unter `/admin/groups`. Siehe [docs/user-groups-plan.md](docs/user-groups-plan.md).
- Plugins können jetzt eigene Berechtigungen im Gruppen-/Berechtigungssystem
  registrieren (`permissions()`-Methode): entweder eine neue Aktion an einem
  bestehenden Modul (z. B. eine "Exportieren"-Berechtigung für Pferde) oder
  ein komplett neues eigenes Modul. Siehe
  [docs/plugin-development.md](docs/plugin-development.md), Abschnitt
  „Berechtigungen“.
- Plugins erhalten jetzt eine eindeutige, versionsgebundene Kennung
  (Manifest-Version + SHA-256-Fingerabdruck über den Plugin-Ordner) statt
  sich allein über den Verzeichnisnamen zu identifizieren. Verhindert, dass
  unter demselben Slug ausgetauschter Code stillschweigend unter einer alten
  Freigabe weiterläuft. Reguläre Updates (neue Versionsnummer im Manifest)
  werden automatisch akzeptiert und unterbrechen den Betrieb nicht; bleibt
  die Version gleich, weicht aber der Code ab, wird das Plugin nicht
  geladen, bis ein Admin es unter `/admin/plugins` mit einem Klick erneut
  freigibt - nicht-destruktiv, es geht dabei nie Konfiguration verloren.
  Siehe [docs/plugin-development.md](docs/plugin-development.md), Abschnitt
  „Update-Erkennung“.

### Geändert

- Rollensystem entfernt (#66): `users.role` (früher `admin`/`editor`) gibt es
  nicht mehr - Gruppen (`groups`/`user_groups`/`group_permissions`) sind jetzt
  das EINZIGE Rechtesystem. Administrator-Status ergibt sich ausschließlich
  aus Mitgliedschaft in der eingebauten Gruppe `admin` (bisher hart über
  `users.role` codiert), die dafür jetzt wie jede andere Gruppe im
  Benutzer-Formular zuweisbar ist. Die "Rolle"-Spalte in der
  Benutzerverwaltung wurde durch eine "Gruppen"-Spalte ersetzt. Bestehende
  Installationen: Beim ersten Verbindungsaufbau nach dem Update übernimmt
  eine automatische, einmalige Migration alle bisherigen `role='admin'`- und
  `role='editor'`-Benutzer unverändert in die entsprechende Gruppe, bevor die
  Spalte entfernt wird - keine manuelle Aktion nötig.
- Gruppen-/Berechtigungssystem (#66): Gruppenmitgliedschaft ist jetzt für
  jede Gruppe außer `admin` ausschließlich explizit (Security-by-Design) -
  auch die eingebaute `editor`-Gruppe wird nicht mehr automatisch anhand der
  Benutzerrolle zugewiesen, sondern muss wie jede eigene Gruppe im
  Benutzer-Formular bewusst angehakt werden. Neue Gruppen und neue Benutzer
  starten dadurch standardmäßig ohne jede Berechtigung (wie `public`) statt
  implizit mit den Editor-Standardrechten. `editor` bleibt als Komfort-Gruppe
  mit denselben Rechten wie bisher bestehen, ist aber kein automatischer
  Standard mehr. Bestehende Installationen: Editoren behalten beim Update
  automatisch ihre bisherigen Rechte (einmalige, dauerhaft abgesicherte
  Migration). Siehe [docs/user-groups-plan.md](docs/user-groups-plan.md),
  Abschnitt 10.

## [0.1.0-beta.1] – 2026-08-04

Erstes öffentliches Beta-Release. Nach internem Testdurchlauf (inkl.
frischem Docker-Setup-Smoke-Test: Auto-Provisionierung, erzwungenes
2FA-Setup, Pferd anlegen, öffentlicher Katalog, Audit-Log) freigegeben.

### Enthalten

- Öffentlicher Hengstkatalog mit Suche, Filtern und Blutlinien-/Pedigree-Ansicht
- Pferde-, Personen- und Deckstationsverwaltung (CRUD) mit Soft-Delete/Papierkorb
  (Aufbewahrungsfrist für Editoren, Admins können sofort endgültig löschen)
- Automatische Blutlinien-Verknüpfung inkl. Match-/Merge-Vorschlagswerkzeug
  (auch für fast identische Namen und Cross-UELN-Fälle)
- Multiuserfähige Benutzerverwaltung mit Rollen (Admin/Editor)
- Verpflichtende 2FA (TOTP, lokal generierter QR-Code, 10 Backup-Codes)
- Session-Hardening (Anti-Hijacking, Inaktivitäts-Timeout, ID-Rotation),
  CSRF-Schutz auf allen zustandsändernden Routen
- Datenbankgestütztes Rate-Limiting (Login/2FA/Backup-Codes)
- Verschlüsselung sensibler Werte (AES-256-GCM: SMTP-Passwort, TOTP-Secrets)
- Revisionssicheres, unlöschbares Audit-Log (dauerhaft gespeichert, Standardansicht zeigt die letzten 30 Tage)
- DSGVO-Kontaktformular inkl. Verwaltung (Anonymisierung/Löschung) im Admin-Bereich
- Impressum & Datenschutzinformationen
- Branding-Einstellungen (Site-Name, Farben, Logo), SMTP-Konfiguration mit
  Testversand, System-Reset mit Erhalt des Audit-Logs
- Security-Header inkl. Content-Security-Policy
- Trusted-Proxy-Konfiguration für korrekte Client-IP-/HTTPS-Erkennung hinter
  Reverse Proxies
- Zwei unterstützte Deployment-Wege: Docker/Docker Compose (Apache + PHP 8.3
  + MariaDB) und klassisches Shared-Hosting über Setup-Wizard
- Vollautomatische Ersteinrichtung per Umgebungsvariablen (optional, ohne Wizard)
- Entwicklerdokumentation (`docs/`) und Benutzer-/Admin-Dokumentation (Wiki)

### Bekannte Einschränkungen

- EntraID SSO ist noch nicht implementiert
- Trackingfähigkeit für Weblinks ist noch nicht implementiert
- Keine automatisierte Testsuite, keine CI-Pipeline
- CSP erlaubt aktuell noch `'unsafe-inline'` für Skripte/Styles (siehe
  [docs/security.md](docs/security.md))

[0.2.0]: ../../releases/tag/v0.2.0
[0.2.0-beta.1]: ../../releases/tag/v0.2.0-beta.1
[0.1.0-beta.1]: ../../releases/tag/v0.1.0-beta.1
