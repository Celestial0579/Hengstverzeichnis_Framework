# Benutzergruppen-/Berechtigungskonzept — Umsetzungsplanung

**Status:** Phase 1 (siehe Abschnitt 6, nach der Konkretisierung in
Abschnitt 8) umgesetzt.
**Bezug:** [#66](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/66) (Kern-Anforderung, Vorgänger-Issue), [#56](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/56) (Plugin-System, wartet laut Issue-Kommentaren auf dieses Konzept), [#57](https://github.com/Celestial0579/Hengstverzeichnis_Framework/issues/57) (Rolle „Mitglied“, erster konkreter Anwendungsfall)

Dieses Dokument bricht #66 auf eine konkrete, mit der bestehenden
Architektur kompatible Umsetzung herunter — analog zu
[plugin-system-plan.md](plugin-system-plan.md) für #56. Es ist die
Planungsgrundlage für die eigentliche Implementierung, noch kein fertiges
Design.

## 1. Ausgangslage & Ziel

Laut Kommentaren an #56 und #57 (05.08., 09:49–09:57 Uhr) soll dieses
generelle Benutzergruppen-Konzept **vor bzw. zusammen mit** dem
Plugin-System (#56) stehen, statt später nachgerüstet zu werden — Plugins
sollen eigene Funktionen/Hooks von Anfang an granular auf
admin-definierte Benutzergruppen einschränkbar machen können. Gleichzeitig
ist es Grundlage für #57 (Rolle „Mitglied“ mit admin-konfigurierbaren
Premium-Funktionen, erster Anwendungsfall: Verpaarungsrechner aus #55).

**Wichtige Klarstellung aus den Kommentaren:** „Premium“/gruppenbasierte
Einschränkung meint ausschließlich admin-/verbandsseitig steuerbare
**Sichtbarkeit**, keine kostenpflichtige Freischaltung.

**Ziel:** Statt einer festen Rolle `member` oder einer binären
öffentlich/mitgliederexklusiv-Unterscheidung: beliebige, vom Admin selbst
benannte Gruppen, denen Benutzer zugeordnet werden, und auf die einzelne
Funktionen (Kern- **und** Plugin-Funktionen) eingeschränkt werden können.

## 2. Verhältnis zu #56 und #57 — was #66 bewusst NICHT entscheidet

Um den Scope sauber zu halten, entscheidet dieses Konzept bewusst **nicht**:

- **Wie Benutzerkonten für Nicht-Admin/Editor-Nutzer entstehen** (aus #57:
  admin-only Anlage vs. Self-Service-Registrierung) — bleibt offene
  Design-Frage von #57. Das Gruppen-Konzept funktioniert unabhängig davon,
  wie ein Konto entsteht.
- **Ob/welche 2FA-Pflicht für welche Kontoart gilt** — ebenfalls #57.
- **Welche konkreten Gruppen ein Verband anlegt** oder welche Funktion
  welcher Gruppe zugeordnet wird — reine Admin-/Laufzeit-Konfiguration,
  kein Code.

Damit bleibt #66 eine reine **Mechanik** (Datenmodell + Zuordnung +
Prüfung), die #57 und #56 beide nutzen können, ohne deren jeweils eigene
offene Fragen vorwegzunehmen.

**Wichtige Abgrenzung zur bestehenden `role`-Spalte:** Die feste Rolle
(`admin`/`editor`, `users.role`) steuert weiterhin **System-/Backend-
Berechtigungen** (CRUD-Zugriff auf Pferde/Personen/Deckstationen,
Benutzerverwaltung etc., siehe `BaseController::requireAdmin()`) und bleibt
von diesem Konzept **unangetastet**. Gruppen sind eine rein additive,
orthogonale Ebene für die Sichtbarkeit von **optionalen/Premium-/Plugin-
Funktionen**, nicht für administrative Rechte. Ein `admin`- oder
`editor`-Konto kann zusätzlich Mitglied beliebiger Gruppen sein (z. B. um
eine Premium-Funktion selbst zu testen), das ändert aber nichts an seinen
CRUD-Rechten.

## 3. Architektur-Grundentscheidungen

### 3.1 Datenmodell

Drei neue Tabellen über das bestehende `ensureSchemaUpToDate()`-Pattern
(siehe `src/Database.php`), konsistent mit dem Rest des Kerns:

```sql
CREATE TABLE IF NOT EXISTS `groups` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_groups` (
    `user_id` INT NOT NULL,
    `group_id` INT NOT NULL,
    PRIMARY KEY (`user_id`, `group_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin-konfigurierte Sichtbarkeit pro "Feature-Key" (siehe 3.2)
CREATE TABLE IF NOT EXISTS `feature_access` (
    `feature_key` VARCHAR(100) NOT NULL PRIMARY KEY,
    `mode` ENUM('public', 'authenticated', 'groups') NOT NULL DEFAULT 'public',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `feature_access_groups` (
    `feature_key` VARCHAR(100) NOT NULL,
    `group_id` INT NOT NULL,
    PRIMARY KEY (`feature_key`, `group_id`),
    FOREIGN KEY (`feature_key`) REFERENCES `feature_access`(`feature_key`) ON DELETE CASCADE,
    FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Bewusst **kein** neuer Wert in `users.role` (kein `ENUM('admin','editor',
'member')`) — Gruppenzugehörigkeit ist rollenunabhängig (siehe 2.).
Ob/wie ein zukünftiges leichtgewichtiges Konto ohne Backend-Zugriff
(`member` aus #57) aussieht, bleibt dortige Entscheidung; es müsste hier
lediglich als weiterer gültiger `users.role`-Wert ergänzt werden, ändert
aber nichts an `groups`/`user_groups`.

### 3.2 Feature-Keys: die Verbindung zwischen „was wird geschützt“ und „wer darf“

Ein **Feature-Key** ist ein frei gewählter, sprechender String (z. B.
`core.breeding_calculator` für den Verpaarungsrechner aus #55/#57, oder
`plugin.<slug>.<name>` für eine Plugin-Funktion), den Code an genau der
Stelle deklariert, an der der Zugriff geprüft wird — analog zu bestehenden
`AuditLogger`-Kategorien (freier String, keine zentrale Registry-Datei
nötig). `feature_access` wird **lazy** befüllt: Ruft Code erstmals
`requireFeatureAccess('core.breeding_calculator')` auf und existiert dafür
noch kein Eintrag, gilt der sichere Default `mode = 'public'` **nicht**
automatisch — siehe Sicherheits-Hinweis in 3.4 zu Fail-Closed/Fail-Open.

### 3.3 Neue Helfer in `BaseController`

Analog zu `checkAuth()`/`requireAdmin()`:

```php
/**
 * Prüft, ob der aktuelle Benutzer (oder Gast) auf ein per Admin
 * konfigurierbares Feature zugreifen darf, und bricht andernfalls mit
 * einer protokollierten 403-Seite ab - bewusst im selben Stil wie
 * requireAdmin(), damit Kern- und Plugin-Code sie identisch verwenden.
 */
protected function requireFeatureAccess(string $featureKey): void { /* ... */ }

/** Reine Prüfung ohne Seiten-Abbruch, z. B. um einen Link bedingt anzuzeigen. */
protected function hasFeatureAccess(string $featureKey): bool { /* ... */ }

/** Gruppenzugehörigkeit des aktuellen Benutzers (Session-gecacht wie $_SESSION['role']). */
protected function userGroups(): array { /* ... */ }
```

`hasFeatureAccess()`/`requireFeatureAccess()` Logik:
1. `mode = 'public'` → immer erlaubt (auch Gäste).
2. `mode = 'authenticated'` → wie `checkAuth()`: jeder eingeloggte Benutzer,
   unabhängig von Rolle/Gruppe.
3. `mode = 'groups'` → Benutzer muss eingeloggt sein UND mindestens einer
   der in `feature_access_groups` hinterlegten Gruppen angehören (ODER-
   Verknüpfung zwischen mehreren zugelassenen Gruppen). `admin`-Accounts
   haben zusätzlich **immer** Zugriff (siehe offene Frage 5.1) - Editoren
   nur, wenn sie selbst der Gruppe zugeordnet wurden.

### 3.4 Sicherheits-Leitplanke: Fail-Closed statt Fail-Open

Anders als z. B. `RateLimiter` (dokumentiert fail-open bei DB-Ausfall) muss
`requireFeatureAccess()` bei fehlendem `feature_access`-Eintrag oder
DB-Fehler **fail-closed** verhalten (Zugriff verweigern, nicht gewähren) -
eine neu deklarierte, aber vom Admin noch nicht konfigurierte
Premium-/Plugin-Funktion darf nie versehentlich öffentlich sichtbar sein.
Erst eine explizite Admin-Entscheidung (Eintrag in `feature_access`)
schaltet sie frei. Das ist die Umkehrung des Standard-Zustands "öffentlich"
bei den bestehenden statischen Kern-Seiten, aber konsistent mit "Plugin
wird nie automatisch aktiviert" aus dem Sicherheitsmodell von #56.

### 3.5 Admin-UI

- **`/admin/groups`** (neuer `GroupController`, admin-only): Gruppen
  anlegen/umbenennen/löschen (Name, Slug, Beschreibung). Löschen einer
  Gruppe entfernt referenzierende `user_groups`-/`feature_access_groups`-
  Zeilen per `ON DELETE CASCADE` - ein Feature mit `mode = 'groups'`, dem
  dadurch keine Gruppe mehr zugeordnet ist, wird faktisch für alle
  Nicht-Admins gesperrt (fail-closed, siehe 3.4), nicht automatisch
  `public`.
- **Erweiterung `admin_user_form.php`/`UserController`**: Mehrfachauswahl
  der Gruppen für den bearbeiteten Benutzer (Checkbox-Liste, analog zum
  bestehenden Rollen-Radiobutton), gespeichert über `user_groups`.
- **`/admin/feature-access`**: Tabelle aller bisher **bekannten**
  Feature-Keys (aus `feature_access`, befüllt sobald ein Feature zum ersten
  Mal geprüft wurde, siehe 3.2) mit Auswahl `Öffentlich` /
  `Nur angemeldete Benutzer` / `Nur folgende Gruppen: [...]`. Für #56:
  zeigt Feature-Keys aktivierter Plugins über deren Manifest mit an (siehe
  4.2), auch bevor die Funktion das erste Mal aufgerufen wurde.

## 4. Integration mit #57 und #56

### 4.1 #57 (Rolle „Mitglied“, Verpaarungsrechner aus #55)

Der Verpaarungsrechner ruft in seinem Controller
`$this->requireFeatureAccess('core.breeding_calculator')` auf, genau wie
heute `requireAdmin()` aufgerufen wird. Ob dafür eine eigene `member`-Rolle
nötig ist, oder ob z. B. auch `editor`-Accounts einer Gruppe zugeordnet
werden können, entscheidet #57 unabhängig von diesem Konzept (siehe 2.).

### 4.2 #56 (Plugin-System) — umgesetzt

Nachträglich konkret angefragt und umgesetzt (statt des ursprünglich hier
skizzierten, abstrakteren `feature_access`/`requireFeatureAccess()`-Ansatzes
aus Abschnitt 3.1–3.4 — dieser bleibt als generischere Erweiterung für
Nicht-Modul-Fälle denkbar, siehe dortige Einordnung in Abschnitt 8): Ein
Plugin kann über eine optionale `permissions()`-Methode eigene Aktionen im
`App\Permission\PermissionRegistry`-Katalog registrieren, **ohne** das
bestehende Manifest-Format zu brechen:

- Entweder als **neue Aktion an einem bestehenden Modul** (Kern oder ein
  anderes Plugin) - der explizit gewünschte Anwendungsfall: ein Plugin
  ergänzt z. B. eine `horses`/`export`-Berechtigung, die dann in der
  Berechtigungsmatrix unter `/admin/groups` als zusätzliche Checkbox
  "Exportieren" unter "Pferde" erscheint.
- Oder als **komplett neues, eigenes Modul** mit eigenen Aktionen.
- `PermissionRegistry::registerAction()` wird von `PluginManager::loadPlugin()`
  für jeden Eintrag aufgerufen, bevor die Routen registriert werden -
  Sicherheits-Leitplanke "wer zuerst registriert, gewinnt": eine bereits
  existierende Modul×Aktion-Kombination (Kern oder zuvor geladenes Plugin)
  kann von einem Plugin nicht überschrieben/umdefiniert werden.
- Registrierung schaltet für sich genommen nichts frei (die neue Aktion ist
  bis zur expliziten Admin-Zuweisung in `/admin/groups` fail-closed wie jede
  andere Berechtigung) - die eigentliche Durchsetzung bleibt Aufgabe des
  Plugins selbst (`requirePermission()`/`hasPermission()` im eigenen Hook
  oder der eigenen Route aufrufen, analog zur bestehenden Empfehlung für
  `checkAuth()`/`requireAdmin()` in Plugin-Routen). Kein Auto-Enforcement
  durch `PluginManager`, aus demselben Grund wie bei `checkAuth()`: der Kern
  kann nicht wissen, mit welcher Granularität eine Plugin-Route geschützt
  werden soll.
- Referenzimplementierung: `docs/examples/demo-plugin/Plugin.php`
  registriert `horses.export`; die Route `/plugin/demo-plugin/export-preview`
  demonstriert die tatsächliche Durchsetzung.
- Details siehe [plugin-development.md](plugin-development.md), Abschnitt
  "Berechtigungen".

## 5. Offene Punkte für Rücksprache mit dem Repo-Owner

1. **Admin-Bypass:** Sollen `admin`-Accounts grundsätzlich immer Zugriff
   auf gruppenbeschränkte Features haben (zur Kontrolle/zum Testen), auch
   ohne eigene Gruppenzugehörigkeit? (Empfehlung: ja, siehe 3.3 Punkt 3 -
   analog dazu, dass Admins bereits jede Backend-Funktion sehen können.)
   Gilt das auch für `editor`?
2. **UND/ODER-Semantik bei mehreren Gruppen:** Reicht "Benutzer ist in
   mindestens einer der zugelassenen Gruppen" (ODER, wie in 3.3
   vorgeschlagen), oder wird für manche Funktionen "muss in allen
   zugeordneten Gruppen sein" (UND) gebraucht? (Empfehlung: nur ODER für
   Phase 1 - deckt den beschriebenen Anwendungsfall vollständig ab, UND
   wirkt konstruiert für "Sichtbarkeit einer Funktion".)
3. **`authenticated`-Modus:** Sinnvoll schon in Phase 1, obwohl es aktuell
   außer `admin`/`editor` keine weitere Kontoart gibt (Vorgriff auf #57)?
   Oder erst einführen, sobald #57 eine leichtgewichtige Kontoart schafft,
   für die dieser Modus einen Unterschied macht?
4. **Umfang der Erstumsetzung:** Reicht die generische Mechanik
   (Gruppen-CRUD, Benutzer-Zuordnung, `feature_access`-Steuerung,
   `requireFeatureAccess()`) für #66 selbst, oder soll direkt ein erstes
   reales Feature (z. B. der Verpaarungsrechner aus #55/#57) als
   Referenz-/Testfall mit umgesetzt werden? (Empfehlung: #66 liefert nur
   die Mechanik + evtl. einen trivialen Demo-Anwendungsfall, analog zum
   Demo-Plugin bei #56 - der Verpaarungsrechner selbst ist fachlich
   Aufgabe von #55/#57.)

## 6. Phasenplan

**Phase 1 (dieses Issue, #66):**
1. Tabellen `groups`, `user_groups`, `feature_access`,
   `feature_access_groups` (Punkt 3.1) + `database/schema.sql`-Ergänzung
2. `BaseController::requireFeatureAccess()`/`hasFeatureAccess()`/
   `userGroups()` (Punkt 3.3), fail-closed (Punkt 3.4)
3. `GroupController` + Admin-UI für Gruppen-CRUD (`/admin/groups`)
4. Erweiterung `UserController`/`admin_user_form.php` um Gruppenzuweisung
5. `/admin/feature-access`-Übersicht mit Zugriffsmodus-Auswahl pro
   bekanntem Feature-Key
6. Entwickler-Doku (`docs/feature-access.md` o. ä.): Konvention für
   Feature-Keys, Nutzung von `requireFeatureAccess()`, Fail-Closed-Hinweis
7. Ergänzung in `docs/architecture.md`

**Phase 2 (Folge-Arbeit in #56) — umgesetzt:** `permissions()`-Methode für
Plugins (Punkt 4.2), Doku-Ergänzung in `plugin-development.md`.

**Phase 3 (Folge-Arbeit in #57):** eigentliche `member`-Kontoart samt
offener Design-Fragen (Account-Erstellung, 2FA-Pflicht), Verpaarungsrechner
aus #55 als erster produktiver `requireFeatureAccess()`-Anwendungsfall.

## 7. Nächste Schritte

Nach Freigabe dieser Planung (insbesondere der offenen Punkte in Abschnitt
5): Umsetzung gemäß Phase 1, beginnend mit dem Datenmodell und
`requireFeatureAccess()` (Punkte 1–2), da alle weiteren Punkte darauf
aufbauen - analog zum Vorgehen bei #56.

## 8. Konkretisierung durch den Repo-Owner (finales Design für die Umsetzung)

Nach Rücksprache wurden die offenen Punkte aus Abschnitt 5 wie folgt konkret
entschieden - dieser Abschnitt ersetzt das abstraktere `feature_access`-
Konzept aus Abschnitt 3 durch ein konkretes RBAC-Modell (Rollen-/
Gruppen-basierte Rechtevergabe je Modul × Aktion):

- **Drei feste (`is_builtin`), nicht löschbare Gruppen**: `admin`, `editor`,
  `public`. Zusätzliche, frei benennbare Gruppen können angelegt werden.
- **`admin`**: hat serverseitig **hart codiert** immer alle Rechte - keine
  Datenbank-Zeile nötig/möglich, nicht über die Admin-UI einschränkbar.
- **`editor`**: Standardmäßig alle Rechte, die Editoren schon heute haben
  (uneingeschränkter CRUD-Zugriff auf Pferde/Personen/Deckstationen) - beim
  allerersten Anlegen der Tabellen als echte, editierbare
  `group_permissions`-Zeilen geseedet (kein Hardcoding), ein Admin kann sie
  später über die UI granular einschränken.
- **`public`** = die nicht angemeldeten Besucher. Diese dürfen **niemals**
  Zugriff auf das Web-Backend (`/admin/...`) erhalten - explizite Klarstellung
  des Repo-Owners, nicht nur "keine sicherheitsrelevanten Berechtigungen"
  im engeren Sinn. Das ist bereits mehrfach unabhängig abgesichert:
  1. `BaseController::checkAuth()` (unverändert von diesem Feature) versperrt
     nicht angemeldeten Besuchern den gesamten Backend-Bereich, bevor
     überhaupt eine Controller-Methode ausgeführt wird - unabhängig vom
     Gruppensystem.
  2. `BaseController::userGroupIds()` liefert für Gäste (kein
     `$_SESSION['user_id']`) sofort ein leeres Array - die Gruppe `public`
     wird für die Berechtigungsprüfung nie aufgelöst.
  3. `GroupController::updatePermissions()` lehnt zusätzlich serverseitig
     hart ab, der Gruppe `public` überhaupt jemals eine
     `group_permissions`-Zeile zuzuweisen (auch in der Admin-UI deaktiviert) -
     selbst falls (2) sich künftig ändern sollte, gäbe es dort nichts zu finden.
  Diese drei Mechanismen sind unabhängig voneinander und ersetzen sich nicht
  gegenseitig - sie müssen bei künftigen Änderungen alle drei erhalten bleiben.
- **Mitgliedschaft**: `admin`/`editor`-Zugehörigkeit ergibt sich weiterhin
  aus der bestehenden Spalte `users.role` (kein Bruch der bestehenden
  Auth-Logik) - zusätzlich kann ein Benutzer beliebig vielen **eigenen**
  Gruppen zugeordnet werden (`user_groups`, Mehrfachauswahl im
  Benutzer-Formular). `public` hat keine Zeilen in `user_groups` - es
  repräsentiert implizit "nicht angemeldet".
- **Berechtigungen je Modul × Aktion** statt eines einzelnen
  öffentlich/authenticated/groups-Modus pro Feature: Erstumsetzung deckt die
  drei bestehenden CRUD-Bereiche ab, mit dem vom Repo-Owner explizit
  genannten Beispiel Pferde (create/edit/delete/**publish**):

  | Modul | Aktionen |
  |---|---|
  | `horses` | `create`, `edit`, `delete`, `publish` |
  | `persons` | `create`, `edit`, `delete` |
  | `breeding_stations` | `create`, `edit`, `delete` |

  `publish` = darf den Status eines Pferdes auf `active` setzen (damit im
  öffentlichen Katalog sichtbar, siehe `PublicController::index()`/`catalog()`
  Filter `status = 'active'`) - ohne diese Berechtigung wird eine
  übermittelte Statusänderung zu `active` serverseitig ignoriert
  (Neuanlage: erzwungen `inactive`; Bearbeitung: bestehender Status bleibt
  erhalten), alle anderen Statusübergänge (z. B. `inactive` ↔ `deceased`)
  bleiben unabhängig von `publish` möglich, da sie die öffentliche
  Sichtbarkeit nicht erhöhen.
  Benutzerverwaltung, DSGVO, System-/Mail-Einstellungen, Papierkorb und
  Plugin-Aktivierung bleiben in dieser Erstumsetzung bewusst weiterhin
  ausschließlich `admin`-only (unverändert `requireAdmin()`) - das sind
  System-/Konfigurationsbereiche, keine "Einträge in einem Bereich" im vom
  Repo-Owner genannten Sinn, und würden den Scope dieser Umsetzung
  erheblich vergrößern. Kandidat für eine spätere Erweiterung der
  Modul-Tabelle.
- **Fail-closed** (siehe 3.4) bleibt bestehen: fehlt eine Berechtigung oder
  schlägt die DB-Abfrage fehl, wird der Zugriff verweigert, nie gewährt.

Der `feature_access`/`feature_access_groups`-Vorschlag aus Abschnitt 3.1–3.4
bleibt als **spätere, generischere Erweiterung** relevant (z. B. für
Sichtbarkeits-Steuerung jenseits von Modul×Aktion, etwa "öffentlich vs. nur
für angemeldete Benutzer" ohne CRUD-Bezug) - für die Erstumsetzung wurde
stattdessen das oben beschriebene, konkretere Modul×Aktion-Modell umgesetzt,
das die vom Repo-Owner genannten Anforderungen direkter abbildet. Die
Anbindung an #56 (Abschnitt 4.2) ist mittlerweile umgesetzt, allerdings über
das Modul×Aktion-Modell (`PermissionRegistry::registerAction()`) statt über
das hier ursprünglich skizzierte `gated_features`-Manifestfeld.

## 9. UI-Iteration: kompakte Ansicht + Berechtigungen kopieren

Nach Funktionstest der Erstumsetzung Rückmeldung des Repo-Owners: Die
Admin-UI unter `/admin/groups` zeigte ursprünglich die vollständige
Berechtigungsmatrix **aller** Gruppen untereinander (eine Karte pro
Gruppe) - bei mehreren eigenen Gruppen wird das schnell unübersichtlich
lang. Überarbeitet zu:

- **Kompakte Übersichtstabelle** oben (Name, Typ, kurze Zusammenfassung
  z. B. "7 von 10", "Alle (fest)", "Keine", Aktions-Buttons) statt
  ausgeschriebener Matrix pro Gruppe.
- **Eine** Berechtigungsmatrix wird angezeigt, gesteuert über ein
  Dropdown (`<select onchange="this.form.submit()">`, GET-Parameter
  `?group=<id>`, Konvention analog zum bestehenden Kategorie-Filter in
  `admin_logs.php`) - reduziert die Seite von ~30 gleichzeitig
  sichtbaren Checkboxen (3 Gruppen × 10) auf 10.
- **"Berechtigungen kopieren von"**: Für die aktuell ausgewählte
  (nicht-geschützte) Gruppe ein zusätzliches kleines Formular mit
  Quell-Gruppen-Auswahl. `GroupController::copyPermissions()` überschreibt
  die Ziel-Berechtigungen vollständig mit denen der Quelle (mit
  Bestätigungsdialog, da destruktiv für die bisherige Konfiguration der
  Zielgruppe). Sonderfall Quelle `admin`: da diese Gruppe bewusst keine
  eigenen `group_permissions`-Zeilen hat (siehe `hasPermission()`
  Admin-Bypass), wird stattdessen der vollständige
  `PermissionRegistry`-Katalog als "alle Rechte" verwendet - sonst würde
  "von Admin kopieren" fälschlich 0 Berechtigungen übertragen. Ziel bleibt
  weiterhin serverseitig auf `admin`/`public` geschützt (dieselbe
  `PROTECTED_PERMISSION_SLUGS`-Prüfung wie bei `updatePermissions()`),
  unabhängig von der Quelle.
- Funktional getestet (siehe Commit): Kopieren von Admin (→ alle 10
  Rechte), Kopieren von Public (→ 0 Rechte, vollständiges Leeren als
  legitimer Anwendungsfall), sowie dass Admin/Public weiterhin nicht als
  Ziel akzeptiert werden.
