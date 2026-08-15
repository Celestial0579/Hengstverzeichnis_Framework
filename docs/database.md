# Datenmodell

MySQL/MariaDB, `InnoDB`, durchgängig `utf8mb4_unicode_ci`. Initiales Schema:
[`database/schema.sql`](../database/schema.sql). Laufende Schema-Änderungen
werden zusätzlich idempotent in `App\Service\SchemaMigrator`
([src/Service/SchemaMigrator.php](../src/Service/SchemaMigrator.php))
nachgezogen — automatisch beim Verbindungsaufbau über
`Database::ensureSchemaUpToDate()` oder explizit per
`SchemaMigrator::run()`, siehe
[Schema-Migration](#schema-migration-versioniert-idempotent) und
[architecture.md](architecture.md#datenbank-zugriff-srcdatabasephp).

## Entity-Overview

```
horses ──sire_id──┐
   │  ──dam_id─────┤→ horses (Selbstreferenz: Vater/Mutter)
   │  ──breeding_station_id──→ breeding_stations
   │
   ├──< horse_registrations   (weitere Lebensnummern je Pferd, #246)
   │
   └──< horse_persons >── persons
                              (m:n über horse_persons, mit Rolle)

users            (eigenständig, Admin-Bereich Login)
groups / user_groups / group_permissions
                  (Gruppen-/Berechtigungssystem, einziges Rechtemodell)
api_keys          (benutzergebundene, rechtebegrenzte API-Schlüssel)
plugins           (aktivierte Plugins inkl. Versions-/Inhalts-Fingerabdruck)
addon_repos       (konfigurierte Addon-Store-Quellen samt Katalog-Cache)
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
- `foreign_ueln` – UELN im Ursprungsland, falls abweichend.
  **Kompatibilitätsfeld seit #246**: weitere Nummern leben in
  `horse_registrations` (siehe unten); das Admin-Formular befüllt
  `foreign_ueln` nicht mehr, CSV-Import und API-Ausgabe nutzen es weiterhin,
  die Anzeige fällt darauf zurück, solange die Kindtabelle für ein Pferd
  leer ist
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
- `sex` – `stallion` | `mare` | `gelding` | `NULL` (= unbekannt, Altbestand).
  Grundlage der Geschlechts-Validierung der Abstammung (#166): `sire_id`
  darf auf keine Stute zeigen, `dam_id` auf keinen Hengst/Wallach; Pferde
  ohne Geschlechtsangabe bestehen jede Prüfung (#165)
- `breed` – Rasse als Freitext, bewusst keine normierte Rasseliste (#163)
- `birth_date` – vollständiges Geburtsdatum (#188), führend wenn gesetzt:
  `birth_year` wird dann serverseitig daraus abgeleitet. `birth_year` bleibt
  eigenständig befüllbar (Altbestand kennt oft nur das Jahr) und ist die
  Filter-/Plugin-Schnittstelle
- `height_cm` – Stockmaß in cm (#188), plausibler Bereich 50–250
- `status` – `active` | `inactive`; seit dem Status-Split (#188) **nur noch
  der Zuchtstatus**, rein informativ, steuert die öffentliche Sichtbarkeit
  **nicht**. Der frühere Enum-Wert `deceased` wurde per Migration in
  `is_deceased = 1` + `status = 'inactive'` überführt
- `is_deceased`/`death_year` – Lebensstatus (#188), unabhängig vom
  Zuchtstatus: ein Tier kann verstorben und zu Lebzeiten dennoch `active`
  geführt sein. Ein gesetztes `death_year` impliziert `is_deceased = 1`
  (serverseitig normalisiert); `death_year < birth_year` wird abgelehnt
- `deleted_at` – Soft-Delete (Papierkorb), `NULL` = aktiv

### `horse_registrations`
Weitere Lebensnummern / Registriernummern je Pferd (#246):
Doppel-/Mehrfachregistrierung in mehreren Zuchtbüchern plus
Altbestands-Kennungen — mehr als zwei Nummern pro Pferd sind real
(die frühere `' / '`-Verkettung im 50-Zeichen-Feld `foreign_ueln` hat
Nummern abgeschnitten). `horse_id` (FK, `ON DELETE CASCADE`),
`registration_number` (max. 50 Zeichen, indiziert für die Suche),
`sort_order` für stabile Reihenfolge. `horses.ueln` bleibt die
Primärnummer und wird hier nicht dupliziert. Die Migration zerlegt
bestehende `foreign_ueln`-Verkettungen einmalig in Einzelzeilen
(`foreign_ueln` selbst bleibt unangetastet). Öffentliche Suche, API-Suche
und die Platzhalter-Auflösung des `PedigreeBuilder` finden Pferde auch
über diese Nummern.

### `horse_persons`
m:n-Verknüpfung zwischen Pferd und Person mit Rolle (`breeder`, `owner`,
`keeper`) und optionalem Zeitraum (`from_year`/`until_year`). Ein Pferd kann
also z. B. mehrere Besitzer über die Zeit hinweg haben. `person_id` ist
`NULL`-fähig (Zuordnung kann auch nur über eine Deckstation erfolgen,
`breeding_station_id`/`breeding_station_text` auf dieser Tabelle).
`ON DELETE CASCADE` auf beide Richtungen.

Wie alle Schema-Änderungen doppelt gepflegt: im Ersteinrichtungs-Schema
`database/schema.sql` **und** idempotent in
`App\Service\SchemaMigrator` für bestehende Installationen.

### `persons`
Züchter/Besitzer/Halter. Strukturierte Felder seit #188: `street`,
`house_number`, `postal_code`, `city`, `state` (Bundesland/Kanton, seit #256,
Freitext), `country` (Freitext, auch Kürzel wie
`NO`), `email` und `membership_status` (Mitgliedsstatus beim Verband,
Freitext); `contact_info` bleibt als Freitext-Restfeld (z. B. Telefon).
**Öffentlich (und im Plugin-Hook-Payload) erscheinen nur `city`, `state`,
`country` und `membership_status`** – Straße, Hausnummer, PLZ und E-Mail sind
Admin-only. Die Trennlinie ist nicht die Feldanzahl, sondern: zustellbare
Angaben bleiben intern, grobe geografische Verortung ist öffentlich. `is_published`
(Default `0` = unveröffentlicht): nur veröffentlichte Personen erscheinen
auf öffentlichen Seiten, in Filterlisten und in der API. `deleted_at` für
Soft-Delete. Wird über die DSGVO-Verwaltung ggf. anonymisiert (Name wird
durch `"Anonymisierte Person (#<id>)"` ersetzt, `contact_info` und **alle**
strukturierten PII-Felder werden `NULL`) statt
zwingend gelöscht, um bestehende `horse_persons`-Zuordnungen (Provenienz der
Zuchtdaten) zu erhalten. Die DSGVO-Personensuche findet Personen auch über
die `email`-Spalte.

### `breeding_stations`
Deckstationen/Gestüte als eigenständige Entität (Name, Kontakt, Adresse).
Strukturierte Anschrift seit #256: `street`, `house_number`, `postal_code`,
`city`, `state`, `country`. Das alte Freitextfeld `address` bleibt bestehen und
wird **nicht** automatisch zerlegt – der Bestand ist real mehrzeilig
(`"Weideweg 1\n24000 Kiel"`), eine Zerlegung wäre geraten (dieselbe
Entscheidung wie bei den Personendaten in #188). Angezeigt wird `address`
deshalb weiterhin, solange die Einzelfelder leer sind; das Admin-Formular hält
es bearbeitbar, damit Altbestand von Hand übertragen und danach geleert werden
kann. Anders als bei Personen ist hier die **gesamte** Anschrift öffentlich –
eine Deckstation ist eine Geschäftsadresse, keine Privatperson.
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
`BaseController::__construct()` komplett geladen. Außerdem liegt hier
`schema_version` — der zuletzt vollständig migrierte Schema-Stand (#213,
siehe [Schema-Migration](#schema-migration-versioniert-idempotent)).

## Schema-Migration (versioniert, idempotent)

Es gibt **kein klassisches Migrationssystem** (kein `up()`/`down()` pro
Version). Stattdessen leben alle Migrationsschritte — einzeln idempotent
per `SHOW COLUMNS`/`SHOW TABLES`/`SHOW INDEX` bzw.
`CREATE TABLE IF NOT EXISTS` abgesichert — gesammelt in
`App\Service\SchemaMigrator` (#230). Zwei Aufrufwege, EINE Quelle:

- **Automatisch:** `Database::ensureSchemaUpToDate()` delegiert beim ersten
  Verbindungsaufbau pro Request an `SchemaMigrator::run()`. Über den in
  `settings.schema_version` persistierten Stand (#213) kostet das im
  Normalfall genau eine Abfrage; die Schritte laufen nur nach einem Update
  mit erhöhter `SchemaMigrator::SCHEMA_VERSION` (bzw. beim Setup) einmal.
- **Explizit:** `SchemaMigrator::run($pdo): array` für Restore-/Import-Wege
  (z. B. ein Datenmigrations-Addon), die nach dem Einspielen eines Dumps
  einer **älteren** Kern-Version das Schema ohne `shell_exec` heben müssen.
  Rückgabe ist die Liste der tatsächlich durchgeführten Schritte (leer =
  nichts zu tun), z. B. für ein Import-Protokoll. `storedVersion($pdo)` /
  `isUpToDate($pdo)` beantworten vorab, ob Migrationen ausstehen.
  `php database/migrate.php` ist nur ein dünner CLI-Wrapper um diese Klasse.

**Reihenfolge bei Restore/Instanz-Umzug: Restore → Migration → App.** Erst
den Dump einspielen, dann `SchemaMigrator::run()` (bzw.
`php database/migrate.php`) laufen lassen, erst danach die App wieder
bedienen — der eingespielte Dump bringt seinen alten
`settings.schema_version`-Stand mit, der Lauf hebt das Schema und stempelt
den neuen Stand. Wer den Schritt auslässt, verliert nichts Dauerhaftes: Der
nächste Verbindungsaufbau der App holt die Migration automatisch nach — aber
erst dann, und bis dahin liefe die App gegen das alte Schema.

**Disziplin bei Schema-Änderungen** (siehe Kommentar an
`SchemaMigrator::SCHEMA_VERSION`): Jede Änderung wird doppelt gepflegt — in
`database/schema.sql` (Ersteinrichtung) **und** als idempotenter Schritt in
`SchemaMigrator::migrate()` (Bestandsinstallationen) — und erhöht zwingend
`SCHEMA_VERSION`, sonst sehen Bestandsinstallationen sie nie.
`tests/Integration/SchemaMigratorTest.php` prüft die Drift-Freiheit beider
Quellen: Auf einem frisch importierten `schema.sql` darf ein Lauf nur noch
den Versionsstempel setzen.

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
