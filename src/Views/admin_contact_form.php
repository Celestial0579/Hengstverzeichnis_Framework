<?php
// src/Views/admin_contact_form.php
/**
 * Ein Formular für alle Kontakte (#336) - aus admin_person_form.php und
 * admin_breeding_station_form.php zusammengeführt. Es kann deshalb beides:
 * Ansprechpartner und Freitext-Anschrift (kamen von den Deckstationen),
 * interne Notiz, Mobilnummer, Mitgliedsstatus und das Züchter-Kennzeichen
 * (kamen von den Personen).
 *
 * @var array|null $contact
 * @var array<int, string>|null $errors
 * @var array|null $old
 * @var string $title
 * @var bool|null $canPublish
 * @var bool|null $isDeleted
 * @var array<int, string>|null $pluginEditSections
 */
$canPublish = $canPublish ?? false;
$contact = $contact ?? null;
$old = $old ?? null;
$isEdit = !empty($contact['id']);
$actionUrl = $isEdit ? '/admin/contacts/update' : '/admin/contacts/store';

/**
 * Fertig escapter Feldwert: erst die abgewiesene Eingabe ($old), dann der
 * gespeicherte Stand. Als Funktion statt als Kette in jedem Feld, weil $old
 * roher POST ist - ein `?feld[]=x` lieferte dort ein Array, und
 * htmlspecialchars() liefe in einen TypeError mitten in der Ausgabe.
 */
$feldWert = static function (string $name) use ($old, $contact): string {
    $wert = $old[$name] ?? $contact[$name] ?? '';
    return is_scalar($wert) ? htmlspecialchars((string)$wert) : '';
};

/**
 * Zustand eines Häkchens. Liegt eine abgewiesene Eingabe vor, zählt NUR sie -
 * ein nicht angehaktes Kästchen steht gar nicht im POST, und ein Rückfall auf
 * den gespeicherten Stand würde es wieder anhaken. Bei contact_public hieße
 * das: Der Bearbeiter nimmt die Freigabe zurück, ein Validierungsfehler
 * schiebt sie ihm wieder unter, und er speichert sie ein zweites Mal.
 */
$haken = static function (string $name) use ($old, $contact): bool {
    return $old !== null ? !empty($old[$name]) : !empty($contact[$name]);
};
?>
<div class="card" style="max-width: 650px;">
    <h2><?= htmlspecialchars($title) ?></h2>

    <?php if (!empty($errors)): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-top: 1rem;">
            <ul style="margin-left: 1.2rem;">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars((string)$error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($isDeleted ?? false)): ?>
    <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
        <strong>Dieser Datensatz liegt im Papierkorb.</strong>
        Er wird hier nur angezeigt, damit sich pruefen laesst, welche Daten noch
        gespeichert sind (etwa fuer eine DSGVO-Auskunft). <strong>Speichern ist
        nicht moeglich</strong> - dazu muss er zuerst unter
        <a href="/admin/trash">Papierkorb</a> wiederhergestellt werden.
    </div>
<?php endif; ?>
<form action="<?= $actionUrl ?>" method="POST" style="margin-top: 1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">

        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= (int)$contact['id'] ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="name">Name *</label>
            <input type="text" id="name" name="name" class="form-control" value="<?= $feldWert('name') ?>" placeholder="z. B. Max Mustermann oder Gestüt Fjordblick" required>
            <small style="color: var(--text-muted);">
                Personenname oder Betriebsname - beides steht seit v0.8 in derselben Liste.
            </small>
        </div>

        <div class="form-group">
            <?php // Kam von den Deckstationen. Bei einem Kontakt, der eine Privatperson IST, bleibt das Feld leer. ?>
            <label for="contact_person">Ansprechpartner / Leiter</label>
            <input type="text" id="contact_person" name="contact_person" class="form-control" value="<?= $feldWert('contact_person') ?>" placeholder="nur bei Betrieben, z. B. Max Mustermann">
        </div>

        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 3;">
                <label for="street">Straße</label>
                <input type="text" id="street" name="street" class="form-control" value="<?= $feldWert('street') ?>" placeholder="z. B. Musterstraße">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="house_number">Hausnr.</label>
                <input type="text" id="house_number" name="house_number" class="form-control" value="<?= $feldWert('house_number') ?>" placeholder="12">
            </div>
        </div>

        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 1;">
                <label for="postal_code">PLZ</label>
                <input type="text" id="postal_code" name="postal_code" class="form-control" value="<?= $feldWert('postal_code') ?>" placeholder="12345">
            </div>
            <div class="form-group" style="flex: 2;">
                <label for="city">Ort</label>
                <input type="text" id="city" name="city" class="form-control" value="<?= $feldWert('city') ?>" placeholder="Musterstadt">
            </div>
        </div>

        <?php
            // Bundesland/Kanton (#256) in einer eigenen Zeile mit dem Land: Die
            // Zeile darüber ist ein display:flex OHNE wrap - ein viertes Feld
            // hätte PLZ und Ort auf schmalen Bildschirmen zusammengequetscht.
        ?>
        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 2;">
                <label for="state">Bundesland / Kanton</label>
                <input type="text" id="state" name="state" class="form-control" value="<?= $feldWert('state') ?>" placeholder="z. B. Schleswig-Holstein, Bern, Tirol">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="country">Land</label>
                <input type="text" id="country" name="country" class="form-control" value="<?= $feldWert('country') ?>" placeholder="z. B. DE, NO">
            </div>
        </div>

        <?php
            // Das alte Freitextfeld der Deckstationen bleibt bestehen und
            // bearbeitbar: Der Altbestand steht dort mehrzeilig drin und wird
            // bewusst NICHT automatisch zerlegt (das wäre geraten, siehe
            // database/schema.sql). Wer die Angaben oben eingetragen hat, leert
            // es hier von Hand.
            $rohAdresse = $old['address'] ?? $contact['address'] ?? '';
            $hasLegacyAddress = is_scalar($rohAdresse) && trim((string)$rohAdresse) !== '';
        ?>
        <div class="form-group">
            <label for="address">Anschrift als Freitext<?= $hasLegacyAddress ? ' (Altbestand)' : '' ?></label>
            <textarea id="address" name="address" class="form-control" rows="2" placeholder="Nur noch für Altbestand - bitte die Felder oben verwenden"><?= $feldWert('address') ?></textarea>
            <?php if ($hasLegacyAddress): ?>
                <p style="color: var(--text-subtle); font-size: 0.8rem; margin: 0.3rem 0 0 0;">
                    Diese Angabe stammt aus der Zeit vor den Einzelfeldern. Bitte oben eintragen und
                    dieses Feld danach leeren - solange hier etwas steht, wird es (mit freigegebenen
                    Kontaktdaten) öffentlich angezeigt.
                </p>
            <?php endif; ?>
        </div>

        <?php // Das Freitextfeld "Mitgliedsstatus beim Verband" stand hier bis
              // v0.8 neben der E-Mail und ist mit #349 entfallen. Es fuehrte
              // dieselbe Aussage von Hand nach, die die Mitgliederverwaltung
              // ohnehin haelt - und war damit ab dem ersten Tag falsch. Das
              // Addon `mitgliedsstatus` haengt sein Feld ueber
              // `contact.edit_sections` unten an dieses Formular. ?>
        <div class="form-group">
            <label for="email">E-Mail</label>
            <input type="email" id="email" name="email" class="form-control" value="<?= $feldWert('email') ?>" placeholder="kontakt@example.de">
        </div>

        <div style="display: flex; gap: 1rem;">
            <div class="form-group" style="flex: 1;">
                <label for="phone">Telefon</label>
                <input type="text" id="phone" name="phone" class="form-control" value="<?= $feldWert('phone') ?>" placeholder="z. B. 01234 56789">
            </div>
            <div class="form-group" style="flex: 1;">
                <label for="mobile">Mobil</label>
                <input type="text" id="mobile" name="mobile" class="form-control" value="<?= $feldWert('mobile') ?>" placeholder="z. B. 0170 1234567">
            </div>
        </div>

        <div class="form-group">
            <label for="website">Website</label>
            <input type="text" id="website" name="website" class="form-control" value="<?= $feldWert('website') ?>" placeholder="https://www.example.de">
            <small style="color: var(--text-muted);">
                Wird öffentlich als Verweis angezeigt - unabhängig von der Kontaktfreigabe unten.
                Nur Adressen mit <code>http://</code> oder <code>https://</code> werden angenommen.
            </small>
        </div>

        <?php
            // Die Aufstellung steht bewusst VOR den Häkchen und nennt jedes Feld
            // beim Namen: Seit dem Zusammenlegen der Tabellen (#336) entscheidet
            // ein einziges Feld darüber, was von einem Datensatz nach draußen
            // geht - vorher tat es die Tabellentrennung. Wer hier klickt, soll
            // vorher gelesen haben, was er freigibt (Lehre aus #293).
        ?>
        <div style="background: var(--surface-muted); border: 1px solid var(--border-color); border-radius: 6px; padding: 0.8rem; margin-bottom: 1rem; font-size: 0.85rem; color: var(--text-muted);">
            <strong>Was von diesem Kontakt öffentlich wird:</strong>
            <ul style="margin: 0.4rem 0 0 1.1rem;">
                <li><strong>Immer</strong> (sobald der Kontakt veröffentlicht ist): Name, Ort,
                    Bundesland/Kanton, Land, Mitgliedsstatus, Website, Züchter-Kennzeichen.</li>
                <li><strong>Nur mit dem Häkchen unten:</strong> E-Mail, Telefon, Mobil, Straße,
                    Hausnummer, PLZ, Anschrift-Freitext, Ansprechpartner.</li>
                <li><strong>Nie:</strong> die interne Notiz.</li>
            </ul>
        </div>

        <div class="form-group">
            <label for="contact_info">Interne Notiz zum Kontakt</label>
            <textarea id="contact_info" name="contact_info" class="form-control" rows="3" placeholder="Nur intern sichtbar"><?= $feldWert('contact_info') ?></textarea>
            <small style="color: var(--text-muted);">
                Restfeld für alles ohne eigene Spalte. <strong>Nicht öffentlich.</strong>
                Telefon, Mobil und Website haben seit #293 eigene Felder - dieses Feld lud
                zuvor zu Telefonnummern ein und wurde zugleich öffentlich angezeigt.
            </small>
        </div>

        <div class="form-group">
            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                <input type="checkbox" name="contact_public" value="1" <?= $haken('contact_public') ? 'checked' : '' ?>>
                <span>📇 Kontaktdaten öffentlich zeigen</span>
            </label>
            <small style="color: var(--text-muted);">
                Mit Häkchen erscheinen <strong>E-Mail, Telefon, Mobil, die vollständige Anschrift
                und der Ansprechpartner</strong> auf der öffentlichen Kontaktseite - für jeden
                lesbar, auch für Suchmaschinen. Ohne Häkchen bleiben sie intern; das ist die
                Vorgabe, auch für Deckstationen.
                <br>Bei einem Betrieb ist die Freigabe üblich, es ist eine Geschäftsadresse. Bei
                einer Privatperson braucht es ihr Einverständnis - das Häkchen ist die Stelle, an
                der es dokumentiert wird.
            </small>
        </div>

        <div class="form-group">
            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                <input type="checkbox" name="is_breeder" value="1" <?= $haken('is_breeder') ? 'checked' : '' ?>>
                <span>🐴 Dieser Kontakt züchtet</span>
            </label>
            <small style="color: var(--text-muted);">
                Kennzeichnet den Kontakt als Züchter - unabhängig davon, ob ihm schon Pferde
                zugeordnet sind. Grundlage für die Zucht-Suche; wird öffentlich angezeigt.
            </small>
        </div>

        <?php if ($canPublish): ?>
        <div class="form-group">
            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                <input type="checkbox" name="is_published" value="1" <?= $haken('is_published') ? 'checked' : '' ?>>
                <span>🌐 Öffentlich sichtbar (Detailseite &amp; Katalog-Filter)</span>
            </label>
            <small style="color: var(--text-muted);">Ohne Häkchen bleibt der Kontakt unveröffentlicht, ist öffentlich nicht erreichbar und erscheint nicht in den Filtern.</small>
        </div>
        <?php endif; ?>

        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
            <button type="submit" class="btn">Speichern</button>
            <a href="/admin/contacts" class="btn btn-secondary">Abbrechen</a>
        </div>
    </form>
</div>

<?php
// Plugin-Abschnitte (Hook contact.edit_sections, dazu die Aliasse
// person.edit_sections und station.edit_sections bis v0.9.0 - siehe
// ContactController::edit()). Bewusst AUSSERHALB des Formulars oben:
// Verschachtelte <form> sind ungueltiges HTML, und die Abnehmer brauchen
// eigene Formulare. So bleibt jeder Schreibvorgang beim Plugin-Controller mit
// dessen eigener Berechtigungspruefung - dieselbe Begruendung wie bei
// horse.edit_sections.
foreach (($pluginEditSections ?? []) as $section): ?>
    <div class="card" style="max-width: 650px; margin-top: 1.5rem;"><?= $section ?></div>
<?php endforeach; ?>
