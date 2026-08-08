# Datenmodell

MySQL/MariaDB, `InnoDB`, durchgängig `utf8mb4_unicode_ci`. Initiales Schema:
[`database/schema.sql`](../database/schema.sql). Laufende Schema-Änderungen
werden zusätzlich idempotent in `Database::ensureSchemaUpToDate()`
([src/Database.php](../src/Database.php)) nachgezogen (siehe
[architecture.md](architecture.md#datenbank-zugriff-srcdatabasephp)).

## Entity-Overview

```
horses ──sire_id──┐
   │  ──dam_id─────┤→ horses (Selbstreferenz: Vater/Mutter)
   │  ──breeding_station_id──→ breeding_stations
   │
   └──< horse_persons >── persons
                              (m:n über horse_persons, mit Rolle)

users            (eigenständig, Admin-Bereich Login)
groups / user_groups / group_permissions
                  (Gruppen-/Berechtigungssystem, einziges Rechtemodell)
api_keys          (benutzergebundene, rechtebegrenzte API-Schlüssel)
plugins           (aktivierte Plugins inkl. Versions-/Inhalts-Fingerabdruck)
addon_repos       (konfigurierte Addon-Store-Quellen; nur in
                   ensureSchemaUpToDate(), siehe Hinweis unten)
settings          (Key/Value: Branding, SMTP, System-Einstellungen,
                   feature_visibility__<key>, Cron-/Backup-/Digest-Status)
password_resets   (Einmal-Tokens für "Passwort vergessen")
login_attempts    (Rate-Limiting: Login, Login je IP, 2FA, Backup-Code,
                   Passwort-Reset, Registrierung, DSGVO-Formular)
gdpr_requests     (öffentliches DSGVO-Kontaktformular)
audit_logs        (Revisionssicheres Protokoll aller sicherheitsrelevanten Aktionen)
```

## Tabellen im Detail

### `horses`
Zentrale Entität: ein Pferd (i. d. R. Hengst, Modell ist aber generisch).

- `ueln` – Unique Equine Life Number, eindeutig (deutsche/Haupt-UELN)
- `foreign_ueln` – UELN im Ursprungsland, falls abweichend
- `sire_id`/`dam_id` – FK auf `horses.id`, `ON DELETE SET NULL`, sobald
  Vater/Mutter als eigener Datensatz existiert und verknüpft ist
- `sire_name`/`sire_ueln`, `dam_name`/`dam_ueln` – "Platzhalter"-Felder für
  noch **nicht verknüpfte** Eltern (nur Name/UELN als Freitext bekannt).
  Das Merge-/Match-Tool (`HorseController::matches()`) schlägt anhand dieser
  Platzhalter mögliche Verknüpfungen mit existierenden Datensätzen vor.
- `breeding_station_id` (FK) und `breeding_station` (doppelt belegt: bei
  gesetzter `breeding_station_id` die denormalisierte Kopie des
  Stationsnamens, sonst Freitext z. B. aus dem CSV-Import)
- `is_published` – **das** Sichtbarkeitsflag: nur `1` erscheint im
  öffentlichen Katalog, auf der Detailseite und in der API. Bewusst
  unabhängig vom Lebenszyklus-`status`; neue Pferde sind per Default
  unveröffentlicht. Veröffentlichen erfordert das `publish`-Recht
  (einzeln im Formular oder als Massen-Aktion in der Admin-Liste).
- `status` – `active` | `inactive` | `deceased`; rein informativ, steuert
  die öffentliche Sichtbarkeit **nicht** (das tat er früher)
- `deleted_at` – Soft-Delete (Papierkorb), `NULL` = aktiv

### `horse_persons`
m:n-Verknüpfung zwischen Pferd und Person mit Rolle (`breeder`, `owner`,
`keeper`) und optionalem Zeitraum (`from_year`/`until_year`). Ein Pferd kann
also z. B. mehrere Besitzer über die Zeit hinweg haben. `person_id` ist
`NULL`-fähig (Zuordnung kann auch nur über eine Deckstation erfolgen,
`breeding_station_id`/`breeding_station_text` auf dieser Tabelle).
`ON DELETE CASCADE` auf beide Richtungen.

> **Hinweis auf eine bekannte Schema-Drift:** `database/schema.sql`
> deklariert `horse_persons` noch ohne `breeding_station_id`/
> `breeding_station_text` und mit `person_id NOT NULL`; ebenso fehlt dort
> `addon_repos`. Beides wird derzeit nur in
> `Database::ensureSchemaUpToDate()` nachgezogen — entgegen der in
> [development.md](development.md) beschriebenen Doppelpflege-Regel. Bei
> einer Neuinstallation gleicht der erste Start das über
> `ensureSchemaUpToDate()` aus; die Beschreibung hier folgt dem
> tatsächlichen Laufzeit-Schema.

### `persons`
Züchter/Besitzer/Halter. `contact_info` als Freitext. `is_published`
(Default `0` = unveröffentlicht): nur veröffentlichte Personen erscheinen
auf öffentlichen Seiten, in Filterlisten und in der API. `deleted_at` für
Soft-Delete. Wird über die DSGVO-Verwaltung ggf. anonymisiert (Name wird
durch `"Anonymisierte Person (#<id>)"` ersetzt, `contact_info = NULL`) statt
zwingend gelöscht, um bestehende `horse_persons`-Zuordnungen (Provenienz der
Zuchtdaten) zu erhalten.

### `breeding_stations`
Deckstationen/Gestüte als eigenständige Entität (Name, Kontakt, Adresse).
`is_published` (Default `0` = unveröffentlicht): unveröffentlichte Stationen
liefern auf `/station?id=` eine 404, und sämtliche `station_*`-Felder auf
der Pferde-Detailseite (inkl. der an Plugins übergebenen) sind dann `null`.
`deleted_at` für Soft-Delete.

### `users`
Admin-Bereich-Accounts. Rechte ergeben sich ausschließlich aus der
Gruppenmitgliedschaft (`groups`/`user_groups`/`group_permissions`, siehe
[security.md](security.md#autorisierung)) – es gibt keine Rollen-Spalte mehr.
2FA (`totp_secret`, `totp_enabled`) ist **pro Gruppe konfigurierbar**
(`groups.require_2fa`); zwingend bleibt sie für `admin`-Mitglieder und
Benutzer ohne Gruppe. `backup_codes` enthält ausschließlich
`password_hash()`-Hashes der Einmal-Codes, nie Klartext. Weitere Spalten:
`session_version` (invalidiert bestehende Sessions bei Passwortänderung),
`last_totp_timeslice` (TOTP-Replay-Schutz),
`email_verification_token`/`-_expires_at` (Selfservice-Registrierung).
`must_change_password` erzwingt eine Passwortänderung beim nächsten Login
(z. B. nach Admin-initiiertem Reset). `deleted_at` für Soft-Delete.

### `password_resets`
Einmal-Token (`token`, `expires_at`) für den "Passwort vergessen"-Flow,
15 Minuten gültig (siehe `AuthController`/`Mailer::sendPasswordResetEmail`).

### `login_attempts`
Fehlversuchs-Log für `RateLimiter` (siehe [security.md](security.md)),
`type` ∈ {`login`, `login_ip`, `2fa`, `backup`, `password_reset`,
`registration`, `dsgvo_request`} — Plugins können eigene `type`-Werte
ergänzen —, mit `identifier` (E-Mail/Username bzw. IP) und `ip_address`.

### `gdpr_requests`
Öffentliches DSGVO-Kontaktformular (`request_type` ∈ {`info`, `deletion`}).
Wird im Admin-Bereich unter „DSGVO“ bearbeitet (`status`: `pending` →
`processed`/`rejected`, `admin_notes` für interne Vermerke).

### `audit_logs`
Append-only Protokoll aller sicherheits-/datenrelevanten Aktionen
(`category` z. B. `horses`, `auth`, `security`, `trash`, `email`, `settings`).
Wird von `AuditLogger::log()` aus praktisch jedem schreibenden Controller
heraus befüllt. Siehe [security.md](security.md#audit-log).

### `settings`
Generisches Key/Value-Store für Branding (`site_name`, `primary_color`,
`secondary_color`, `site_logo` - Pfad zum hochgeladenen Logo, `logo_url` nur
als Legacy-Fallback für ältere Installationen) sowie SMTP-/Mail-Konfiguration
(`mail_driver`, `smtp_host`, `smtp_pass` verschlüsselt via `Crypto`, …) und
sonstige Systemeinstellungen — u. a. `feature_visibility__<key>`
(Sichtbarkeit von Plugin-Zusatzfunktionen), `cron_last_run__<name>`,
Backup-/Digest-Konfiguration (Zugangsdaten verschlüsselt), `update_channel`,
`registration_enabled`/`registration_default_group`, `language`,
`tracking_code` und `base_url`. Wird bei jedem Request in
`BaseController::__construct()` komplett geladen.

## Soft-Delete / Papierkorb

`horses`, `persons`, `breeding_stations` und `users` besitzen alle eine
`deleted_at`-Spalte. Löschen setzt nur den Timestamp; `TrashController`
verwaltet Wiederherstellung und endgültiges Löschen:

- **Admins** können jederzeit endgültig löschen bzw. den Papierkorb leeren.
- **Nicht-Admins mit `delete`-Recht** dürfen nur Objekte endgültig löschen,
  die seit **> 30 Tagen** im Papierkorb liegen (Aufbewahrungsfrist als
  Sicherheitsnetz gegen versehentliches Löschen).
- Benutzerkonten (`users`) können nur von Admins wiederhergestellt/gelöscht
  werden.

Alle regulären `SELECT`-Abfragen in Controllern filtern konsequent
`WHERE deleted_at IS NULL`.
