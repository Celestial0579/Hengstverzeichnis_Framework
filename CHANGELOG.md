# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei
dokumentiert. Das Format orientiert sich an
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung
an [Semantic Versioning](https://semver.org/lang/de/) (solange `0.y.z`:
Breaking Changes sind jederzeit möglich).

## [Unreleased]

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

[0.1.0-beta.1]: ../../releases/tag/v0.1.0-beta.1
