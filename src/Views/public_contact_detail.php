<?php
// src/Views/public_contact_detail.php
/**
 * Öffentliche Kontaktseite (#336) - die eine Seite, die
 * public_person_detail.php (#293) und public_station_detail.php ersetzt, seit
 * `persons` und `breeding_stations` zu `contacts` zusammengeführt sind.
 *
 * WICHTIG: $contact enthält absichtlich nur die öffentlichen Spalten; der
 * Controller wählt sie einzeln aus und nimmt die zustellbaren Felder
 * (E-Mail/Telefon/Mobil/Anschrift/Ansprechpartner) nur bei ausdrücklicher
 * Freigabe (contact_public) mit - siehe PublicController::contactDetail().
 * Das interne Freitextfeld contact_info ist hier nie vorhanden und kann daher
 * auch nicht versehentlich ausgegeben werden.
 *
 * Die Freigabe wird deshalb hier NICHT noch einmal abgefragt: Eine zweite
 * Prüfung an dieser Stelle sähe aus wie der eigentliche Schutz und verleitete
 * dazu, ihn beim nächsten neuen Feld hier statt im Controller nachzuziehen.
 * `!empty()` je Feld genügt - ohne Freigabe ist der Schlüssel gar nicht da.
 *
 * @var array $contact
 * @var array<string, array<int, array<string, mixed>>> $horsesByRole
 * @var array<int, array<string, mixed>> $stationHorses
 * @var array<int, string> $pluginDetailSections
 */
$pluginDetailSections = $pluginDetailSections ?? [];
$horsesByRole = $horsesByRole ?? [];
$stationHorses = $stationHorses ?? [];
$horsesGekuerzt = $horsesGekuerzt ?? false;
$stationHorsesGekuerzt = $stationHorsesGekuerzt ?? false;

// Zuchtstatus seit dem Status-Split (#188) zweiwertig; der Lebensstatus
// (is_deceased) bekommt in den Listen unten ein eigenes Badge.
$statusLabels = [
    'active' => [App\I18n\Translator::t('status.active'), '#d4edda', '#155724'],
    'inactive' => [App\I18n\Translator::t('status.inactive'), '#f8d7da', '#721c24'],
];

// Rollenbeschriftungen wie in der Pferde-Detailseite; unbekannte Rollen
// erscheinen unverändert, statt zu verschwinden.
$roleLabels = [
    'breeder' => App\I18n\Translator::t('field.breeder'),
    'owner' => App\I18n\Translator::t('field.owner'),
    'keeper' => App\I18n\Translator::t('field.keeper'),
];

/**
 * Eine Pferdezeile - identisch für beide Blöcke unten. Vorher stand dieselbe
 * Kachel zweimal im Projekt (Personen- und Stationsseite) und lief bei #334
 * und #350 auch prompt auseinander.
 */
$horseRow = static function (array $horse) use ($statusLabels): void {
    $statusMeta = $statusLabels[$horse['status']] ?? [App\I18n\Translator::t('status.unknown'), '#e2e3e5', '#383d41'];
    ?>
    <a href="/horse?id=<?= (int)$horse['id'] ?>" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; background: var(--surface-muted); border: 1px solid #e0e0e0; border-radius: 6px; padding: 0.7rem 0.9rem; text-decoration: none; color: inherit;">
        <div>
            <strong style="color: var(--primary-fg);"><?= htmlspecialchars((string)$horse['name']) ?></strong>
            <span style="color: var(--text-muted); font-size: 0.9rem;">
                <?= htmlspecialchars((string)($horse['birth_year'] ?: '')) ?>
                <?= !empty($horse['ueln']) ? '[' . htmlspecialchars($horse['ueln']) . ']' : '' ?>
            </span>
        </div>
        <span>
            <span style="background: <?= $statusMeta[1] ?>; color: <?= $statusMeta[2] ?>; padding: 0.2rem 0.6rem; border-radius: 12px; font-size: 0.75rem; font-weight: bold;">
                <?= $statusMeta[0] ?>
            </span>
            <?php if (!empty($horse['is_deceased'])): ?>
                <span style="background: var(--surface-muted); color: var(--text-muted); border: 1px solid var(--border-color); padding: 0.2rem 0.6rem; border-radius: 12px; font-size: 0.75rem; font-weight: bold;">
                    ✝ <?= htmlspecialchars(App\I18n\Translator::t('status.deceased')) ?><?= !empty($horse['death_year']) ? ' ' . (int)$horse['death_year'] : '' ?>
                </span>
            <?php endif; ?>
        </span>
    </a>
    <?php
};

$placeParts = array_filter([
    $contact['city'] ?? '',
    $contact['state'] ?? '',
    $contact['country'] ?? '',
]);

// Strukturierte Adresse (#256) mit Rückfall auf das alte Freitextfeld
// `address` aus `breeding_stations`. Der Rückfall ist kein
// Übergangsprovisorium: Der Altbestand wird bewusst nicht automatisch
// zerlegt, solange also niemand die Einzelfelder gepflegt hat, ist der
// Freitext die einzige vorhandene Angabe (siehe database/schema.sql).
//
// Bewusst ohne eigene Beschriftungen je Feld - die Zeile trägt weiterhin das
// vorhandene Label 'field.address'.
//
// Ort/Bundesland/Land stehen NICHT in diesem Block: Sie sind immer öffentlich
// und haben oben mit 'field.location' ihre eigene Zeile. Hier erscheinen nur
// die Teile, die eine Sendung zustellbar machen und deshalb an der Freigabe
// hängen - Straße, Hausnummer, PLZ. Ohne Freigabe sind diese Schlüssel gar
// nicht vorhanden, der Block bleibt dann leer.
//
// Die PLZ-Zeile entsteht NUR, wenn die PLZ tatsächlich vorliegt. Ohne Freigabe
// fehlt der Schlüssel `postal_code` ganz - die Zeile bestünde dann allein aus
// dem Ort, und der steht oben schon in der Verortungszeile. Das Ergebnis wäre
// derselbe Ort zweimal untereinander, einmal als Verortung und einmal als
// vermeintliche Anschrift.
$plzOrt = trim((string)($contact['postal_code'] ?? ''));
$addressLines = array_values(array_filter([
    trim(implode(' ', array_filter([$contact['street'] ?? '', $contact['house_number'] ?? '']))),
    $plzOrt === '' ? '' : trim($plzOrt . ' ' . (string)($contact['city'] ?? '')),
], fn($line) => $line !== ''));

$addressText = $addressLines !== [] ? implode("\n", $addressLines) : trim((string)($contact['address'] ?? ''));

$contactWebsite = App\Helper\ExternalUrl::hrefOrNull($contact['website'] ?? null);

// Ob überhaupt eine Angabe unter dem Namen steht - sonst erscheint der
// Hinweis, dass keine öffentlichen Angaben hinterlegt sind.
$hatAngaben = !empty($placeParts)
    || !empty($contact['membership_status'])
    || !empty($contact['is_breeder'])
    || !empty($contact['contact_person'])
    || $addressText !== ''
    || !empty($contact['email'])
    || !empty($contact['phone'])
    || !empty($contact['mobile'])
    || $contactWebsite !== null;
?>
<div style="margin-bottom: 1rem;">
    <a href="/katalog" style="color: var(--primary-fg); text-decoration: none; font-weight: 500;"><?= htmlspecialchars(App\I18n\Translator::t('common.back_to_catalog')) ?></a>
</div>

<div class="card" style="margin-bottom: 2rem;">
    <h1 style="border-bottom: 2px solid var(--primary-fg); padding-bottom: 0.5rem; margin-bottom: 1.5rem;">
        <?php // Ein Symbol für alle Kontakte. Die alten Seiten führten 👤 und 🏠
              // nebeneinander, weil sie aus zwei Tabellen kamen; diese Sorten
              // gibt es nicht mehr, und sie aus einem Feld zu erraten
              // (contact_person gefüllt? Pferde als Deckstation?) hieße, eine
              // Aussage zu treffen, die in den Daten nicht steht - und die je
              // nach Freigabe und Rechten auch noch wechselte. ?>
        📇 <?= htmlspecialchars($contact['name']) ?>
        <?php if (!empty($contact['is_breeder'])): ?>
            <span style="background: var(--info-soft-bg); color: var(--primary-fg); padding: 0.2rem 0.7rem; border-radius: 12px; font-size: 0.8rem; font-weight: bold; vertical-align: middle;">
                🐴 <?= htmlspecialchars(App\I18n\Translator::t('person.is_breeder')) ?>
            </span>
        <?php endif; ?>
    </h1>

    <table style="width: 100%; border-collapse: collapse; max-width: 500px;">
        <?php if (!empty($contact['contact_person'])): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);">👤 <?= htmlspecialchars(App\I18n\Translator::t('field.contact_person')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;"><?= htmlspecialchars((string)$contact['contact_person']) ?></td>
            </tr>
        <?php endif; ?>
        <?php if (!empty($placeParts)): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);">📍 <?= htmlspecialchars(App\I18n\Translator::t('field.location')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;">
                    <?= htmlspecialchars(implode(', ', $placeParts)) ?>
                    <?php $flag = App\Helper\CountryFlag::emoji((string)($contact['country'] ?? '')); ?>
                    <?php if ($flag !== null): ?>
                        <span title="<?= htmlspecialchars((string)$contact['country']) ?>"><?= $flag ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endif; ?>
        <?php if ($addressText !== ''): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted); vertical-align: top;">🏠 <?= htmlspecialchars(App\I18n\Translator::t('field.address')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;"><?= nl2br(htmlspecialchars($addressText)) ?></td>
            </tr>
        <?php endif; ?>
        <?php if (!empty($contact['membership_status'])): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);">🎗 <?= htmlspecialchars(App\I18n\Translator::t('field.membership_status')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;"><?= htmlspecialchars((string)$contact['membership_status']) ?></td>
            </tr>
        <?php endif; ?>
        <?php if (!empty($contact['email'])): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);">✉️ <?= htmlspecialchars(App\I18n\Translator::t('field.email')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;"><a href="mailto:<?= htmlspecialchars((string)$contact['email']) ?>"><?= htmlspecialchars((string)$contact['email']) ?></a></td>
            </tr>
        <?php endif; ?>
        <?php if (!empty($contact['phone'])): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);">☎️ <?= htmlspecialchars(App\I18n\Translator::t('field.phone')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;"><?php $tel = App\Helper\TelUrl::hrefOrNull((string)$contact['phone']); ?><?php if ($tel !== null): ?><a href="<?= htmlspecialchars($tel) ?>"><?= htmlspecialchars((string)$contact['phone']) ?></a><?php else: ?><?= htmlspecialchars((string)$contact['phone']) ?><?php endif; ?></td>
            </tr>
        <?php endif; ?>
        <?php if (!empty($contact['mobile'])): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);">📱 <?= htmlspecialchars(App\I18n\Translator::t('field.mobile')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;"><?php $tel = App\Helper\TelUrl::hrefOrNull((string)$contact['mobile']); ?><?php if ($tel !== null): ?><a href="<?= htmlspecialchars($tel) ?>"><?= htmlspecialchars((string)$contact['mobile']) ?></a><?php else: ?><?= htmlspecialchars((string)$contact['mobile']) ?><?php endif; ?></td>
            </tr>
        <?php endif; ?>
        <?php if ($contactWebsite !== null): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);">🌐 <?= htmlspecialchars(App\I18n\Translator::t('field.website')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;"><a href="<?= htmlspecialchars($contactWebsite) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(App\I18n\Translator::t('field.visit_website')) ?></a></td>
            </tr>
        <?php endif; ?>
    </table>

    <?php if (!$hatAngaben): ?>
        <p style="color: var(--text-subtle);"><?= htmlspecialchars(App\I18n\Translator::t('contact.no_details')) ?></p>
    <?php endif; ?>
</div>

<?php
// ZWEI Blöcke, nicht einer (#336): "hat dieses Pferd gezüchtet/besessen" und
// "dieses Pferd stand hier" sind verschiedene Aussagen. Sie zusammenzuwerfen
// ergäbe eine Liste, aus der niemand mehr lesen kann, in welcher Eigenschaft
// der Kontakt an dem Pferd hängt.
?>
<div class="card">
    <h3 style="font-size: 1.2rem; margin-bottom: 1rem; color: var(--text-muted);"><?= htmlspecialchars(App\I18n\Translator::t('contact.horses_heading')) ?></h3>

    <?php if (empty($horsesByRole)): ?>
        <p style="color: var(--text-subtle);"><?= htmlspecialchars(App\I18n\Translator::t('contact.no_horses')) ?></p>
    <?php else: ?>
        <?php foreach ($horsesByRole as $role => $horses): ?>
            <h4 style="font-size: 1rem; margin: 1rem 0 0.6rem 0;">
                <?= htmlspecialchars($roleLabels[$role] ?? $role) ?>
                <span style="color: var(--text-muted); font-weight: normal;">(<?= count($horses) ?>)</span>
            </h4>
            <div style="display: flex; flex-direction: column; gap: 0.6rem;">
                <?php foreach ($horses as $horse): ?>
                    <?php $horseRow($horse); ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!empty($horsesGekuerzt)): ?>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 0.8rem;">
                <?php // Nie stillschweigend kürzen (#372): Eine Liste, die einen Teil
                      // verschweigt, behauptet Vollständigkeit. ?>
                <?= htmlspecialchars(App\I18n\Translator::t('contact.horses_truncated', ['count' => count($horsesByRole, COUNT_RECURSIVE) - count($horsesByRole)])) ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
// Der Deckstations-Block erscheint nur, wenn es ihn zu zeigen gibt. Der
// Personen-Block darüber steht dagegen immer: Er ist die Hauptaussage der
// Seite, und "keine Pferde hinterlegt" ist dort eine Auskunft. Ein leerer
// Stationsblock unter jedem Privatkontakt wäre dagegen nur Rauschen - die
// allermeisten Kontakte sind keine Deckstation.
?>
<?php if (!empty($stationHorses)): ?>
    <div class="card" style="margin-top: 2rem;">
        <h3 style="font-size: 1.2rem; margin-bottom: 1rem; color: var(--text-muted);"><?= htmlspecialchars(App\I18n\Translator::t('station.horses_heading')) ?></h3>
        <div style="display: flex; flex-direction: column; gap: 0.6rem;">
            <?php foreach ($stationHorses as $horse): ?>
                <?php $horseRow($horse); ?>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($stationHorsesGekuerzt)): ?>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 0.8rem;">
                <?php // Nie stillschweigend kürzen (#372): Eine Liste, die einen Teil
                      // verschweigt, behauptet Vollständigkeit. ?>
                <?= htmlspecialchars(App\I18n\Translator::t('contact.horses_truncated', ['count' => count($stationHorses)])) ?>
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!empty($pluginDetailSections)): ?>
    <?php // Erweiterungspunkt 'contact.detail_sections' - z. B. fuer eine
          // Kontaktanfrage, die ohne oeffentliche Adresse auskommt. Die alten
          // Namen 'person.detail_sections' und 'station.detail_sections'
          // laufen als Alias mit (siehe PublicController::contactDetail()) und
          // landen im selben Block. ?>
    <div class="card" style="margin-top: 2rem;">
        <?php foreach ($pluginDetailSections as $section): ?>
            <div class="horse-plugin-section"><?= $section ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
