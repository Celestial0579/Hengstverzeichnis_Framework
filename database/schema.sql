-- database/schema.sql

-- Settings for Branding / Theming
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(50) NOT NULL UNIQUE,
    `setting_value` TEXT,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial Settings
-- Kein 'logo_url'-Default: ohne konfiguriertes Logo zeigt der Header nur den
-- Vereinsnamen als Text (layout.php prüft auf !empty($logoUrl)), das vermeidet
-- ein kaputtes Bild-Icon bei Neuinstallationen ohne eigenen Logo-Upload.
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('site_name', 'Hengstverzeichnis Framework'),
('primary_color', '#2c3e50'),
('secondary_color', '#18bc9c');

-- Users Table
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `totp_secret` VARCHAR(255) NULL,
    `totp_enabled` TINYINT(1) DEFAULT 0,
    `backup_codes` TEXT NULL,
    `passkeys` TEXT NULL,
    `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
    `session_version` INT NOT NULL DEFAULT 1,
    `last_totp_timeslice` BIGINT NULL DEFAULT NULL,
    -- Selfservice-Registrierung (#83): gesetzter Token = E-Mail noch nicht
    -- verifiziert, Login gesperrt. Admin-angelegte Konten: immer NULL.
    `email_verification_token` VARCHAR(64) NULL DEFAULT NULL,
    `email_verification_expires_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    INDEX `idx_users_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password Resets
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(100) NOT NULL,
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kontakte (Personen, Deckstationen, Höfe - ein Datensatz je Gegenüber, #336)
--
-- Bis v0.7 gab es dafür ZWEI Tabellen, `persons` und `breeding_stations`.
-- Die Trennung erzeugte laufend Fälle, die niemand entscheiden kann: Ein Hof,
-- den zwei Privatleute betreiben, ist beides. Beim Aufräumen der
-- Deckstationsdaten blieben 134 Freitexte übrig, bei denen die Frage
-- "Deckstation oder Person?" nicht beantwortbar war - und sie ist es auch
-- nicht. Sie verschwindet nicht durch eine bessere Heuristik, sondern
-- dadurch, dass man sie nicht mehr stellen muss.
--
-- Was ein Kontakt für ein bestimmtes Pferd IST - Züchter, Halter, Besitzer,
-- Deckstation - steht seitdem ausschließlich an der Zuordnung
-- (`horse_persons`.`role`), nicht mehr in der Wahl der Tabelle.
--
-- KEIN Typ-Feld. Sichtbarkeit ist eine Entscheidung je Datensatz, keine
-- Eigenschaft einer Gattung - genau das war der Fehler der alten
-- Modellierung. Wer hier ein `type` ergänzt, baut sie wieder ein.
--
-- DATENSCHUTZ-GRENZE: Bis v0.7 schützte die Trennung selbst - `persons`
-- lieferte nur eine Positivliste von Spalten an die öffentliche Seite,
-- `breeding_stations` ein `SELECT *`. Fällt die Trennung, ist der Schutz nur
-- noch ein Feld, und ein falsch gesetztes Feld stellt die Privatadresse eines
-- Menschen ins Netz. Deshalb gilt ab hier für ALLE Kontakte die strengere
-- der beiden Regeln:
--   * `contact_public` DEFAULT 0 (persons-Vorgabe, nicht die 1 der Stationen)
--   * `is_published`   DEFAULT 0
--   * Die öffentliche Seite wählt weiterhin EINZELNE Spalten aus, nie
--     `SELECT *` - siehe PublicController::contactDetail() und die Lehre
--     aus #293. Was gar nicht erst ankommt, kann der nächste nicht
--     versehentlich ausgeben.
-- Öffentlich sind nur grobe geografische Verortung (city/state/country),
-- membership_status, is_breeder und website; zustellbare Angaben
-- (street/house_number/postal_code/email/phone/mobile/contact_info/address)
-- nur bei contact_public = 1.
CREATE TABLE IF NOT EXISTS `contacts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    -- 150 statt der 100 aus `persons`: die weitere der beiden bisherigen
    -- Definitionen gewinnt, sonst schnitte die Migration Stationsnamen ab.
    `name` VARCHAR(150) NOT NULL,
    -- Ansprechpartner eines Betriebs (kam aus `breeding_stations`). Für einen
    -- Kontakt, der eine Privatperson IST, bleibt das Feld leer.
    `contact_person` VARCHAR(100) NULL DEFAULT NULL,
    -- Freitext-Restfeld aus `persons`. Intern (siehe Datenschutz-Grenze oben).
    `contact_info` TEXT NULL,
    -- Strukturierte Adresse (#188, state seit #256).
    `street` VARCHAR(150) NULL DEFAULT NULL,
    `house_number` VARCHAR(20) NULL DEFAULT NULL,
    `postal_code` VARCHAR(20) NULL DEFAULT NULL,
    `city` VARCHAR(100) NULL DEFAULT NULL,
    -- Bundesland/Kanton (#256), Freitext wie country - bewusst ohne
    -- ISO-3166-2-Validierung.
    `state` VARCHAR(100) NULL DEFAULT NULL,
    -- Freitext, auch Länderkürzel wie 'NO' (Altsystem-Konvention).
    `country` VARCHAR(100) NULL DEFAULT NULL,
    -- Alte Freitext-Adresse aus `breeding_stations`. Bleibt bestehen und wird
    -- NICHT automatisch zerlegt: Der Bestand ist real mehrzeilig
    -- ("Weideweg 1\n24000 Kiel"), ein Parse-Backfill wäre geraten - dieselbe
    -- Begründung wie bei #188. Wird angezeigt, solange die strukturierten
    -- Felder leer sind, und bleibt als station_address Teil des
    -- dokumentierten Plugin-Payloads.
    `address` TEXT NULL,
    `email` VARCHAR(100) NULL DEFAULT NULL,
    `phone` VARCHAR(50) NULL DEFAULT NULL,
    `mobile` VARCHAR(50) NULL DEFAULT NULL,
    `website` VARCHAR(255) NULL DEFAULT NULL,
    -- Mitgliedsstatus beim Verband (#188), Freitext analog breed
    -- (z. B. 'Mitglied', 'Nichtmitglied NO'). Wandert in v0.9.0 in das
    -- Addon aus Addons#132 - bis dahin bleibt die Spalte hier (#349).
    `membership_status` VARCHAR(100) NULL DEFAULT NULL,
    -- Kennzeichen "dieser Kontakt züchtet" - redaktionell gepflegt und
    -- ausdrücklich NICHT aus horse_persons.role='breeder' abgeleitet.
    -- Beide Richtungen der Ableitung wären falsch: Wer noch kein Pferd im
    -- Verzeichnis hat, wäre nicht auffindbar, obwohl er züchtet. Und wer
    -- früher gezüchtet hat, bliebe dauerhaft markiert - die alten Zuordnungen
    -- verschwinden ja nicht, sie sind Historie (mit from_year/until_year).
    -- Das Kennzeichen sagt "züchtet heute", die Zuordnungen sagen "hat dieses
    -- Pferd gezüchtet".
    `is_breeder` TINYINT(1) NOT NULL DEFAULT 0,
    -- Ausdrückliche Freigabe der zustellbaren Kontaktdaten. DEFAULT 0 - siehe
    -- die Datenschutz-Grenze im Tabellenkommentar. Die Migration aus
    -- `breeding_stations` (dort DEFAULT 1) übernimmt den BESTANDSWERT je
    -- Zeile, damit sie nichts wegnimmt, was vorher da war; der DEFAULT für
    -- NEUE Datensätze ist trotzdem der sichere.
    `contact_public` TINYINT(1) NOT NULL DEFAULT 0,
    -- Öffentliche Sichtbarkeit, unabhängig vom Datensatz-Status. Neu
    -- angelegte Kontakte sind unveröffentlicht und werden über die
    -- Admin-Verwaltung freigegeben.
    `is_published` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    INDEX `idx_contacts_deleted_name` (`deleted_at`, `name`),
    -- Für die Zucht-Suche (#293): Züchter je Ort/Land filtern.
    INDEX `idx_contacts_is_breeder` (`is_breeder`, `is_published`, `deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zuordnung alte Kennung -> Kontakt-Kennung (#336).
--
-- BLEIBT DAUERHAFT STEHEN. Das ist keine Migrationshilfe, die danach
-- wegfällt, sondern ein Bestandteil des Schemas.
--
-- Der Grund ist ein konkreter Datenschutz-Schaden, den es sonst gäbe: Addons
-- speichern Verweise auf Kontakte, und mindestens eines tut das ohne
-- Fremdschlüssel - `plugin_kontaktanfrage_requests` und
-- `plugin_kontaktanfrage_optout` halten `(target_type, target_id)` als
-- Zeichenkette plus Zahl. Person 5 und Station 5 gab es beide. Ohne diese
-- Tabelle zeigt nach der Zusammenführung JEDE gespeicherte Zeile auf einen
-- falschen Kontakt - beim Opt-out heißt das: Wer Kontaktanfragen abbestellt
-- hat, ist wieder erreichbar, und jemand anderes ist stumm geschaltet.
--
-- Ein Addon, das erst in einem halben Jahr nachzieht, muss seine Verweise
-- dann noch umrechnen können. Deshalb dauerhaft.
--
-- Zweiter Zweck: die öffentlichen Adressen /station?id= und /person?id=
-- stehen in Suchmaschinen und werden über diese Tabelle dauerhaft auf
-- /kontakt?id= weitergeleitet.
CREATE TABLE IF NOT EXISTS `contact_id_map` (
    -- 'person' oder 'station' - die Tabelle, aus der die alte Kennung stammt.
    `old_type` ENUM('person', 'station') NOT NULL,
    `old_id` INT NOT NULL,
    `contact_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`old_type`, `old_id`),
    INDEX `idx_contact_id_map_contact` (`contact_id`),
    FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Horses
CREATE TABLE IF NOT EXISTS `horses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `ueln` VARCHAR(50) UNIQUE, -- Unique Equine Life Number (Deutschland / Haupt-UELN)
    `foreign_ueln` VARCHAR(50) NULL DEFAULT NULL, -- Lebensnummer Ursprungsland / Ausländische UELN
    `sire_id` INT NULL, -- Father (Stallion FK)
    `sire_name` VARCHAR(100) NULL, -- Unlinked Father Name
    `sire_ueln` VARCHAR(15) NULL, -- Unlinked Father UELN
    `dam_id` INT NULL, -- Mother (Mare FK)
    `dam_name` VARCHAR(100) NULL, -- Unlinked Mother Name
    `dam_ueln` VARCHAR(15) NULL, -- Unlinked Mother UELN
    `birth_year` SMALLINT UNSIGNED NULL,
    -- Vollständiges Geburtsdatum (#188): führend, wenn gesetzt - birth_year
    -- wird dann serverseitig daraus abgeleitet. birth_year bleibt eigenständig
    -- befüllbar (Altbestand kennt oft nur das Jahr) und ist die Filter-/
    -- Plugin-Schnittstelle.
    `birth_date` DATE NULL DEFAULT NULL,
    `color` VARCHAR(50),
    -- Geschlecht (#165): NULL = unbekannt (Altbestand). Werte englisch wie beim
    -- Zuchtstatus `status`; Wallache sind als Vater ausgeschlossen (#166).
    `sex` ENUM('stallion', 'mare', 'gelding') NULL DEFAULT NULL,
    -- Kastrationsdatum (#239): echtes Sachdatum (die Deckeinsatz-Historie
    -- endet dort), fachlich nur bei Wallachen (sex='gelding') sinnvoll. Das
    -- Formular blendet das Feld entsprechend ein/aus, gespeichert wird
    -- serverseitig tolerant auch bei anderem Geschlecht. NULL = nicht erfasst.
    `castration_date` DATE NULL DEFAULT NULL,
    -- Rasse (#163): bewusst Freitext, keine normierte Rasseliste.
    `breed` VARCHAR(100) NULL DEFAULT NULL,
    -- Stockmaß in cm (#188), plausibler Bereich 50-250 (Formular + CSV-Import).
    `height_cm` SMALLINT UNSIGNED NULL DEFAULT NULL,
    `breeding_station_id` INT NULL,
    `breeding_station` VARCHAR(255) NULL,
    `description` TEXT,
    -- Zuchtstatus (#188): seit dem Status-Split NUR noch aktiv/inaktiv im
    -- Zuchtbuch. Der Lebensstatus steht getrennt in is_deceased/death_year -
    -- ein Tier kann verstorben und zu Lebzeiten dennoch "aktiv" geführt sein.
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    -- Lebensstatus (#188): unabhängig vom Zuchtstatus. Ein gesetztes
    -- death_year impliziert is_deceased = 1 (serverseitig normalisiert).
    `is_deceased` TINYINT(1) NOT NULL DEFAULT 0,
    `death_year` SMALLINT UNSIGNED NULL DEFAULT NULL,
    -- Öffentliche Sichtbarkeit, bewusst UNABHÄNGIG von Zucht-/Lebensstatus
    -- (#66-Folge): nur is_published = 1 erscheint im öffentlichen Katalog/API,
    -- gesteuert durch die Berechtigung horses.publish. `status` ist rein
    -- informativ (Gekört/Inaktiv) und beeinflusst die Sichtbarkeit nicht.
    `is_published` TINYINT(1) NOT NULL DEFAULT 0,
    `image_url` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL DEFAULT NULL,
    FOREIGN KEY (`sire_id`) REFERENCES `horses`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`dam_id`) REFERENCES `horses`(`id`) ON DELETE SET NULL,
    -- Zeigt seit #336 auf `contacts`. Der Spaltenname bleibt: Die Aussage
    -- ("welcher Kontakt ist die Deckstation dieses Pferds") hat sich nicht
    -- geaendert, nur die Zieltabelle - und ein Umbenennen haette jedes Addon
    -- getroffen, das den Spiegel liest, ohne irgendetwas zu verbessern.
    FOREIGN KEY (`breeding_station_id`) REFERENCES `contacts`(`id`) ON DELETE SET NULL,
    -- Indizes für die Standardfilter/-sortierung der öffentlichen Abfragen
    -- (is_published + deleted_at + ORDER BY name) sowie Namens-/UELN-Lookups
    -- des PedigreeBuilder (#120)
    INDEX `idx_horses_published_name` (`is_published`, `deleted_at`, `name`),
    INDEX `idx_horses_deleted_name` (`deleted_at`, `name`),
    INDEX `idx_horses_name` (`name`),
    INDEX `idx_horses_foreign_ueln` (`foreign_ueln`),
    -- Katalog-Filteroptionen (#221): SELECT DISTINCT color/breed als
    -- Index-Only-Scan statt Full Table Scan
    INDEX `idx_horses_color` (`color`, `deleted_at`),
    INDEX `idx_horses_breed` (`breed`, `deleted_at`),
    -- Blutlinien-Vorfilter des MatchSuggestionFinder (#215)
    INDEX `idx_horses_sire_unlinked` (`deleted_at`, `sire_id`),
    INDEX `idx_horses_dam_unlinked` (`deleted_at`, `dam_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zuordnung Pferd <-> Kontakt (Besitzhistorie & Rollen)
--
-- ZWEI Steckplaetze, beide auf `contacts` (#336). Das ist Absicht und
-- ausdruecklich NICHT der in #336 skizzierte Weg ("person_id und
-- breeding_station_id werden ein Feld"):
--
-- Eine Zeile sagt zwei Dinge gleichzeitig - WER (in welcher Rolle) und WO
-- (an welcher Deckstation). Das Formular rendert je Zeile beide Auswahlen
-- nebeneinander (src/Views/admin_horse_form.php), und `role` kennt nur
-- breeder/owner/keeper, also keinen Stationswert. "Besitzer P, an Station S,
-- von 2010 bis 2015" liesse sich in einem einzigen Feld nicht mehr
-- ausdruecken - die Station fiele ersatzlos weg.
--
-- Zusammengefuehrt wurden also die TABELLEN (persons + breeding_stations ->
-- contacts), nicht die Steckplaetze. Damit ist das Ziel von #336 erreicht:
-- Beim Anlegen eines Kontakts muss niemand mehr entscheiden, ob ein Hof eine
-- Person oder eine Deckstation "ist".
--
-- contact_id ist NULL-faehig: Eine Zeile kann auch NUR eine Deckstation
-- zuordnen (station_contact_id fuer verknuepfte Kontakte,
-- breeding_station_text fuer freien Text ohne Kontakt-Datensatz).
CREATE TABLE IF NOT EXISTS `horse_persons` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `horse_id` INT NOT NULL,
    -- Frueher `person_id`. Der Kontakt in der Rolle aus `role`.
    `contact_id` INT NULL DEFAULT NULL,
    `role` ENUM('breeder', 'owner', 'keeper') NOT NULL DEFAULT 'owner',
    -- Frueher `breeding_station_id`. Der Kontakt, der fuer diese Zeile die
    -- Deckstation IST - eine Ortsangabe, keine Rolle im Sinne von `role`.
    `station_contact_id` INT NULL,
    `breeding_station_text` VARCHAR(255) NULL,
    -- Herkunftsland einer Zuordnung OHNE bekannte Person (#294). Das
    -- Altsystem kannte die Aussage "der Zuechter ist nicht bekannt, aber er
    -- kam aus Norwegen"; ohne dieses Feld muss dafuer eine Platzhalter-Person
    -- in der PII-Tabelle persons angelegt werden, die dann als echter
    -- Zuechtername im Katalog erscheint und durch DSGVO- und
    -- Papierkorb-Mechanik laeuft.
    --
    -- Gehoert zur ZEILE, nicht zur Person: kein personenbezogenes Datum,
    -- deshalb oeffentlich. Freitext wie persons.country, auch Kuerzel wie 'NO'.
    `origin_country` VARCHAR(100) NULL DEFAULT NULL,
    `from_year` SMALLINT UNSIGNED NULL,
    `until_year` SMALLINT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`id`) ON DELETE CASCADE,
    -- SET NULL statt CASCADE: Wird der Kontakt einer Deckstation geloescht,
    -- bleibt die Aussage "dieses Pferd stand bei jemandem" erhalten (der
    -- Freitext in breeding_station_text traegt sie oft), waehrend ein
    -- geloeschter Personen-Kontakt die ganze Zuordnung gegenstandslos macht.
    FOREIGN KEY (`station_contact_id`) REFERENCES `contacts`(`id`) ON DELETE SET NULL,
    INDEX `idx_horse_persons_horse_role` (`horse_id`, `role`),
    -- Fuer den Rueckweg "welche Pferde haengen an diesem Kontakt" (Kontaktseite,
    -- Deduplizierer, DSGVO-Loeschung) - beide Steckplaetze einzeln.
    INDEX `idx_horse_persons_contact` (`contact_id`),
    INDEX `idx_horse_persons_station_contact` (`station_contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Weitere Lebensnummern / Registriernummern je Pferd (#246): Pferde können
-- durch Doppel-/Mehrfachregistrierung in mehreren Zuchtbüchern (plus
-- Altbestands-Kennungen) mehr als zwei Nummern tragen - das frühere Verketten
-- mit ' / ' in horses.foreign_ueln (varchar(50)) schnitt real Daten ab.
-- horses.ueln bleibt die Primärnummer und wird hier NICHT dupliziert;
-- horses.foreign_ueln bleibt aus Abwärtskompatibilität bestehen (CSV-Import,
-- API-Ausgabe, Anzeige-Fallback für Bestand ohne Zeilen hier), wird vom
-- Admin-Formular aber nicht mehr befüllt.
CREATE TABLE IF NOT EXISTS `horse_registrations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `horse_id` INT NOT NULL,
    `registration_number` VARCHAR(50) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`horse_id`) REFERENCES `horses`(`id`) ON DELETE CASCADE,
    INDEX `idx_horse_registrations_horse` (`horse_id`, `sort_order`),
    INDEX `idx_horse_registrations_number` (`registration_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dauerhafte Entscheidungen über Dubletten-Vorschläge (#355).
--
-- Bis v0.7 konnte man einen Vorschlag nur ANNEHMEN (verknüpfen). Die Aussage
-- "das sind zwei verschiedene Pferde" wurde nirgends gespeichert - also
-- erschien dasselbe Paar bei jedem Aufruf wieder, und der E-Mail-Digest
-- zählte es dauerhaft als offen. Wer einmal geprüft und verworfen hatte,
-- prüfte beim nächsten Mal erneut.
--
-- Ein Label ist KEINE Verknüpfung: 'different' ändert nichts am Bestand, es
-- legt nur den Vorschlag still - und ist widerrufbar, indem die Zeile
-- gelöscht wird. Zusammenführen bleibt dagegen eine Einbahnstraße.
--
-- Die kleinere ID steht immer links. Ohne diese Regel stünden (7,12) und
-- (12,7) nebeneinander, und ein unter der einen Fassung gesetztes Label
-- griffe unter der anderen nicht - der Vorschlag käme wieder, obwohl er
-- entschieden ist. Der Primärschlüssel erzwingt die Eindeutigkeit, die
-- Reihenfolge stellt App\Service\MatchLabel her.
CREATE TABLE IF NOT EXISTS `match_labels` (
    -- 'horse' oder 'contact' - beide Bestände nutzen dieselbe Mechanik.
    `kind` ENUM('horse', 'contact') NOT NULL,
    `left_id` INT NOT NULL,
    `right_id` INT NOT NULL,
    -- 'merged' = erledigt · 'different' = dauerhaft ausblenden ·
    -- 'unclear' = angesehen, noch nicht entschieden (blendet NICHT aus)
    `label` ENUM('merged', 'different', 'unclear') NOT NULL,
    -- Kurzer Beleg, warum. Freiwillig, aber das Feld, das die Entscheidung
    -- ein Jahr später noch nachvollziehbar macht.
    `note` VARCHAR(255) NULL DEFAULT NULL,
    `user_id` INT NULL DEFAULT NULL,
    `username` VARCHAR(50) NOT NULL DEFAULT 'SYSTEM',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`kind`, `left_id`, `right_id`),
    -- Für den Filterlauf beim Anzeigen der Vorschläge.
    INDEX `idx_match_labels_kind_label` (`kind`, `label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GDPR Requests
CREATE TABLE IF NOT EXISTS `gdpr_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NULL,
    `email` VARCHAR(100) NOT NULL,
    `request_type` ENUM('info', 'deletion') NOT NULL,
    `message` TEXT NULL,
    `status` ENUM('pending', 'processed', 'rejected') DEFAULT 'pending',
    `admin_notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login Attempts (Brute-Force-Schutz für Login, 2FA, Backup-Codes)
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `identifier` VARCHAR(255) NOT NULL,
    `type` VARCHAR(20) NOT NULL DEFAULT 'login',
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (`identifier`, `type`),
    INDEX (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plugins (Aktivierungsstatus, siehe src/Plugin/PluginManager.php, #56)
-- content_hash: Inhalts-Fingerabdruck der bei Aktivierung freigegebenen Version,
-- verhindert stillschweigendes Weiterlaufen nachträglich ausgetauschten Codes.
-- dir_stamp (#224): billiger Verzeichnis-Stempel, spart den SHA-256-Vergleich
-- beim Bootstrap, solange sich der Plugin-Ordner nicht geändert hat.
-- source (#212): Store-Herkunft ('owner/repo@ref'), NULL bei manueller Installation.
CREATE TABLE IF NOT EXISTS `plugins` (
    `slug` VARCHAR(100) NOT NULL PRIMARY KEY,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `installed_version` VARCHAR(20) NOT NULL DEFAULT '0.0.0',
    `content_hash` VARCHAR(64) NULL DEFAULT NULL,
    `dir_stamp` VARCHAR(64) NULL DEFAULT NULL,
    `source` VARCHAR(150) NULL DEFAULT NULL,
    `activated_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Addon-Store-Quellen (GitHub-Repos, aus denen /admin/plugins/store Addons
-- anbietet) samt Katalog-Cache. Das offizielle Repo wird von der
-- Schema-Migration (App\Service\SchemaMigrator) per INSERT IGNORE eingetragen.
CREATE TABLE IF NOT EXISTS `addon_repos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `owner` VARCHAR(100) NOT NULL,
    `repo` VARCHAR(100) NOT NULL,
    `ref` VARCHAR(100) NULL DEFAULT NULL,
    `is_official` TINYINT(1) NOT NULL DEFAULT 0,
    `added_by` INT NULL DEFAULT NULL,
    `cached_catalog_json` MEDIUMTEXT NULL DEFAULT NULL,
    `cached_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `owner_repo` (`owner`, `repo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gruppen-/Berechtigungssystem (#66, siehe docs/user-groups-plan.md und
-- BaseController::hasPermission()) - EINZIGES Rechtesystem der App (das
-- frühere users.role wurde vollständig entfernt, siehe
-- App\Service\SchemaMigrator). Security-by-Design: Für angemeldete
-- Benutzer ist Mitgliedschaft ausschließlich explizit über `user_groups`;
-- einzig nicht angemeldete Besucher gehören automatisch der Gast-Gruppe
-- `public` an (GroupMembership::groupIds(null)). `admin` hat systemseitig
-- immer implizit ALLE Rechte, unabhängig vom Inhalt von `group_permissions`
-- (siehe hasPermission()) - ihre eigene Berechtigungs-Matrix bleibt deshalb
-- bewusst leer und nicht editierbar. `public` erhält per Seed unten
-- Leseberechtigungen (horses.view, contacts.view) und ist über die
-- Matrix editierbar - sie ist der Steuerungspunkt der öffentlichen
-- Sichtbarkeit (#121/#122).
CREATE TABLE IF NOT EXISTS `groups` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NULL,
    `is_builtin` TINYINT(1) NOT NULL DEFAULT 0,
    -- 2FA-Pflicht pro Gruppe (#84): 1 = Mitglieder müssen TOTP-2FA einrichten.
    -- Für `admin` fest verdrahtet immer verpflichtend, unabhängig von dieser
    -- Spalte (siehe AuthController::userRequires2fa()).
    `require_2fa` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `groups` (`slug`, `name`, `description`, `is_builtin`) VALUES
('admin', 'Administrator', 'Hat systemseitig immer uneingeschränkt alle Berechtigungen.', 1),
('editor', 'Editor', 'Vorlage für Bearbeiter mit Verwaltungszugriff - muss Benutzern wie jede andere Gruppe bewusst zugewiesen werden, kein automatischer Standard.', 1),
('public', 'Gast (Öffentlich)', 'Gilt automatisch für nicht angemeldete Besucher. Über ihre Lese-Rechte steuert ein Admin, welche Bereiche im öffentlichen Teil der Website sichtbar sind. Backend-Zugriff (/admin/...) bleibt stets ausgeschlossen (siehe BaseController::checkAuth()).', 1);

CREATE TABLE IF NOT EXISTS `user_groups` (
    `user_id` INT NOT NULL,
    `group_id` INT NOT NULL,
    PRIMARY KEY (`user_id`, `group_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `group_permissions` (
    `group_id` INT NOT NULL,
    `module` VARCHAR(50) NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    PRIMARY KEY (`group_id`, `module`, `action`),
    FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Editor-GRUPPE (nicht: automatische Mitgliedschaft) behält bei einer frischen
-- Installation vollen Verwaltungszugriff auf alle Inhaltsmodule - inklusive der
-- Standard-Aktionen 'view' (Lesen) und 'publish' (Veröffentlichen), siehe
-- App\Permission\PermissionRegistry::STANDARD_ACTIONS und docs/user-groups-plan.md.
-- Wer tatsächlich Mitglied dieser Gruppe wird, entscheidet der Admin bewusst je
-- Benutzer (siehe UserController).
INSERT IGNORE INTO `group_permissions` (`group_id`, `module`, `action`)
SELECT `id`, `module`, `action` FROM `groups`
CROSS JOIN (
    SELECT 'horses' AS `module`, 'view' AS `action` UNION ALL
    SELECT 'horses', 'create' UNION ALL
    SELECT 'horses', 'edit' UNION ALL
    SELECT 'horses', 'delete' UNION ALL
    SELECT 'horses', 'publish' UNION ALL
    SELECT 'contacts', 'view' UNION ALL
    SELECT 'contacts', 'create' UNION ALL
    SELECT 'contacts', 'edit' UNION ALL
    SELECT 'contacts', 'delete' UNION ALL
    SELECT 'contacts', 'publish'
) AS `defaults`
WHERE `groups`.`slug` = 'editor';

-- Gast-GRUPPE (`public`): erhält standardmäßig ausschließlich die Lese-Rechte für
-- die heute öffentlich sichtbare Fläche (Katalog + Deckstationsdetail). Bewusst
-- KEINE weiteren Rechte: neue/Plugin-Bereiche sind für nicht angemeldete Besucher
-- damit fail-closed unsichtbar, bis ein Admin sie bewusst freischaltet
-- (Datenleck-Schutz, siehe docs/user-groups-plan.md).
INSERT IGNORE INTO `group_permissions` (`group_id`, `module`, `action`)
SELECT `id`, `module`, `action` FROM `groups`
CROSS JOIN (
    SELECT 'horses' AS `module`, 'view' AS `action` UNION ALL
    -- contacts.view (seit #336, vorher getrennt als breeding_stations.view und
    -- persons.view) - Grundlage der öffentlichen Kontaktseite (/kontakt), auf
    -- die die Pferde-Detailseite verweist. Neue Daten entstehen dadurch nicht:
    -- Gezeigt werden ausschließlich die Felder, die auf der Pferdeseite ohnehin
    -- schon öffentlich sind (Ort, Bundesland, Land, Mitgliedsstatus) plus die
    -- dafür vorgesehene Website; zustellbare Angaben (E-Mail, Telefon, Mobil,
    -- Straße, PLZ) nur bei contact_public = 1 je Datensatz. Wer die Seite nicht
    -- will, nimmt der Gruppe `public` das Recht wieder weg.
    SELECT 'contacts', 'view'
) AS `guest_defaults`
WHERE `groups`.`slug` = 'public';

-- Audit Logs Table
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `username` VARCHAR(50) NOT NULL DEFAULT 'SYSTEM',
    `action` VARCHAR(100) NOT NULL,
    `category` VARCHAR(50) NOT NULL DEFAULT 'general',
    `details` TEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (`created_at`),
    INDEX (`category`),
    INDEX (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API-Schlüssel für die JSON-API (`/api/...`, siehe docs/api.md und
-- App\Security\ApiKey). Die API ist ausschließlich mit einem gültigen Schlüssel
-- erreichbar; jeder Schlüssel gehört einem Benutzer und darf höchstens das, was
-- dieser Benutzer aktuell selbst darf (Schnittmenge aus seinen Gruppenrechten
-- und `scope_permissions`).
--
-- `token_hash` ist ein SHA-256-Hash - der Klartext-Schlüssel wird nie
-- gespeichert und ist nach dem Anlegen nicht wieder abrufbar (wie die
-- 2FA-Backup-Codes). `scope_permissions` = NULL bedeutet "alle Rechte des
-- Besitzers" (dynamisch), sonst eine JSON-Liste erlaubter "modul.aktion"-Paare.
CREATE TABLE IF NOT EXISTS `api_keys` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `label` VARCHAR(100) NOT NULL,
    `token_hash` CHAR(64) NOT NULL UNIQUE,
    `token_prefix` VARCHAR(20) NOT NULL,
    `scope_permissions` TEXT NULL DEFAULT NULL,
    -- Kopplung an users.session_version (#217): Ein Passwort-Reset des
    -- Besitzers entzieht auch allen zuvor ausgestellten Schlüsseln die
    -- Gültigkeit (siehe App\Security\ApiKey).
    `issued_session_version` INT NOT NULL DEFAULT 1,
    `last_used_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Pflicht-Ablaufdatum (#340), hoechstens zwei Jahre ab Ausstellung.
    -- Die Frist beginnt bei der Ausstellung und wird durch Benutzung NICHT
    -- verlaengert: Sonst hielte gerade der vergessene, aber noch laufende
    -- Schluessel sich selbst am Leben. Der Vorgabewert ist bewusst die
    -- aktuelle Zeit, also "sofort abgelaufen" - wer die Spalte beim INSERT
    -- vergisst, bekommt einen unbrauchbaren Schluessel statt eines ewigen.
    `expires_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `revoked_at` DATETIME NULL DEFAULT NULL,
    INDEX `idx_api_keys_user` (`user_id`, `revoked_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
