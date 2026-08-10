# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei
dokumentiert. Das Format orientiert sich an
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung
an [Semantic Versioning](https://semver.org/lang/de/) (solange `0.y.z`:
Breaking Changes sind jederzeit möglich).

## [Unreleased]

### Hinzugefügt

- Empfängergruppen des E-Mail-Digests sind wählbar: Auf der
  Digest-Einstellungsseite lässt sich je Gruppe anhaken, wer den Bericht
  bekommt (Mehrfachauswahl, mindestens eine; Standard bleibt Admin +
  Editor, Bestandsinstallationen verhalten sich unverändert). Der
  „keine Empfänger"-Fehler nennt jetzt die konfigurierten Gruppen

### Hinzugefügt

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
