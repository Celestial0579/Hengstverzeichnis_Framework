# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei
dokumentiert. Das Format orientiert sich an
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung
an [Semantic Versioning](https://semver.org/lang/de/) (solange `0.y.z`:
Breaking Changes sind jederzeit möglich).

## [Unreleased]

### Hinzugefügt

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
  über das bestehende Blutlinien-Zusammenführen-Werkzeug verknüpfen.

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

[0.2.0-beta.1]: ../../releases/tag/v0.2.0-beta.1
[0.1.0-beta.1]: ../../releases/tag/v0.1.0-beta.1
