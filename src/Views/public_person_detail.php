<?php
// src/Views/public_person_detail.php
/**
 * Öffentliche Personenseite (#293) - das Gegenstück zu
 * public_station_detail.php.
 *
 * WICHTIG: $person enthält absichtlich nur die öffentlichen Spalten; der
 * Controller wählt sie einzeln aus (siehe PublicController::personDetail()).
 * E-Mail, Telefon, Mobil, Anschrift und das interne Freitextfeld contact_info
 * sind hier gar nicht erst vorhanden und können daher auch nicht versehentlich
 * ausgegeben werden.
 *
 * @var array $person
 * @var array<string, array<int, array<string, mixed>>> $horsesByRole
 * @var array<int, string> $pluginDetailSections
 */
$pluginDetailSections = $pluginDetailSections ?? [];

$statusLabels = [
    'active' => [App\I18n\Translator::t('status.active'), '#d4edda', '#155724'],
    'inactive' => [App\I18n\Translator::t('status.inactive'), '#f8d7da', '#721c24'],
];

// Rollenbeschriftungen wie in der Pferde-Detailseite; unbekannte Rollen
// erscheinen unverändert, statt zu verschwinden.
$roleLabels = [
    'breeder' => App\I18n\Translator::t('field.breeder'),
    'owner' => App\I18n\Translator::t('field.owner'),
];

$placeParts = array_filter([
    $person['city'] ?? '',
    $person['state'] ?? '',
    $person['country'] ?? '',
]);
$personWebsite = App\Helper\ExternalUrl::hrefOrNull($person['website'] ?? null);
?>
<div style="margin-bottom: 1rem;">
    <a href="/katalog" style="color: var(--primary-fg); text-decoration: none; font-weight: 500;"><?= htmlspecialchars(App\I18n\Translator::t('common.back_to_catalog')) ?></a>
</div>

<div class="card" style="margin-bottom: 2rem;">
    <h1 style="border-bottom: 2px solid var(--primary-fg); padding-bottom: 0.5rem; margin-bottom: 1.5rem;">
        👤 <?= htmlspecialchars($person['name']) ?>
        <?php if (!empty($person['is_breeder'])): ?>
            <span style="background: var(--info-soft-bg); color: var(--primary-fg); padding: 0.2rem 0.7rem; border-radius: 12px; font-size: 0.8rem; font-weight: bold; vertical-align: middle;">
                🐴 <?= htmlspecialchars(App\I18n\Translator::t('person.is_breeder')) ?>
            </span>
        <?php endif; ?>
    </h1>

    <table style="width: 100%; border-collapse: collapse; max-width: 500px;">
        <?php if (!empty($placeParts)): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);">📍 <?= htmlspecialchars(App\I18n\Translator::t('field.location')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;">
                    <?= htmlspecialchars(implode(', ', $placeParts)) ?>
                    <?php $flag = App\Helper\CountryFlag::emoji((string)($person['country'] ?? '')); ?>
                    <?php if ($flag !== null): ?>
                        <span title="<?= htmlspecialchars((string)$person['country']) ?>"><?= $flag ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endif; ?>
        <?php if (!empty($person['membership_status'])): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);">🎗 <?= htmlspecialchars(App\I18n\Translator::t('field.membership_status')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;"><?= htmlspecialchars((string)$person['membership_status']) ?></td>
            </tr>
        <?php endif; ?>
        <?php
            // E-Mail/Telefon/Mobil liefert der Controller NUR bei ausdruecklicher
            // Freigabe mit - fehlt sie, sind die Schluessel gar nicht vorhanden.
        ?>
        <?php if (!empty($person['email'])): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);">✉️ <?= htmlspecialchars(App\I18n\Translator::t('field.email')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;"><a href="mailto:<?= htmlspecialchars((string)$person['email']) ?>"><?= htmlspecialchars((string)$person['email']) ?></a></td>
            </tr>
        <?php endif; ?>
        <?php if (!empty($person['phone'])): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);">☎️ <?= htmlspecialchars(App\I18n\Translator::t('field.phone')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;"><?php $tel = App\Helper\TelUrl::hrefOrNull((string)$person['phone']); ?><?php if ($tel !== null): ?><a href="<?= htmlspecialchars($tel) ?>"><?= htmlspecialchars((string)$person['phone']) ?></a><?php else: ?><?= htmlspecialchars((string)$person['phone']) ?><?php endif; ?></td>
            </tr>
        <?php endif; ?>
        <?php if (!empty($person['mobile'])): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);">📱 <?= htmlspecialchars(App\I18n\Translator::t('field.mobile')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;"><?php $tel = App\Helper\TelUrl::hrefOrNull((string)$person['mobile']); ?><?php if ($tel !== null): ?><a href="<?= htmlspecialchars($tel) ?>"><?= htmlspecialchars((string)$person['mobile']) ?></a><?php else: ?><?= htmlspecialchars((string)$person['mobile']) ?><?php endif; ?></td>
            </tr>
        <?php endif; ?>
        <?php if ($personWebsite !== null): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);">🌐 <?= htmlspecialchars(App\I18n\Translator::t('field.website')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;"><a href="<?= htmlspecialchars($personWebsite) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(App\I18n\Translator::t('field.visit_website')) ?></a></td>
            </tr>
        <?php endif; ?>
    </table>

    <?php if (empty($placeParts) && empty($person['membership_status']) && $personWebsite === null && empty($person['is_breeder'])): ?>
        <p style="color: var(--text-subtle);"><?= htmlspecialchars(App\I18n\Translator::t('person.no_details')) ?></p>
    <?php endif; ?>
</div>

<div class="card">
    <h3 style="font-size: 1.2rem; margin-bottom: 1rem; color: var(--text-muted);"><?= htmlspecialchars(App\I18n\Translator::t('person.horses_heading')) ?></h3>

    <?php if (empty($horsesByRole)): ?>
        <p style="color: var(--text-subtle);"><?= htmlspecialchars(App\I18n\Translator::t('person.no_horses')) ?></p>
    <?php else: ?>
        <?php foreach ($horsesByRole as $role => $horses): ?>
            <h4 style="font-size: 1rem; margin: 1rem 0 0.6rem 0;">
                <?= htmlspecialchars($roleLabels[$role] ?? $role) ?>
                <span style="color: var(--text-muted); font-weight: normal;">(<?= count($horses) ?>)</span>
            </h4>
            <div style="display: flex; flex-direction: column; gap: 0.6rem;">
                <?php foreach ($horses as $horse): ?>
                    <?php $statusMeta = $statusLabels[$horse['status']] ?? [App\I18n\Translator::t('status.unknown'), '#e2e3e5', '#383d41']; ?>
                    <a href="/horse?id=<?= $horse['id'] ?>" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; background: var(--surface-muted); border: 1px solid #e0e0e0; border-radius: 6px; padding: 0.7rem 0.9rem; text-decoration: none; color: inherit;">
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
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if (!empty($pluginDetailSections)): ?>
    <?php // Erweiterungspunkt 'person.detail_sections' - z. B. fuer eine
          // Kontaktanfrage, die ohne oeffentliche Adresse auskommt. ?>
    <div class="card" style="margin-top: 2rem;">
        <?php foreach ($pluginDetailSections as $section): ?>
            <div class="horse-plugin-section"><?= $section ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
