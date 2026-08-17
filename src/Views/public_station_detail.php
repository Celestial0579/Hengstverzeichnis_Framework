<?php
// src/Views/public_station_detail.php
/**
 * @var array $station
 * @var array $horses
 */

// Zuchtstatus seit dem Status-Split (#188) zweiwertig; der Lebensstatus
// (is_deceased) bekommt unten ein eigenes Badge.
$statusLabels = [
    'active' => [App\I18n\Translator::t('status.active'), '#d4edda', '#155724'],
    'inactive' => [App\I18n\Translator::t('status.inactive'), '#f8d7da', '#721c24'],
];
?>
<div style="margin-bottom: 1rem;">
    <a href="/katalog" style="color: var(--primary-fg); text-decoration: none; font-weight: 500;"><?= htmlspecialchars(App\I18n\Translator::t('common.back_to_catalog')) ?></a>
</div>

<div class="card" style="margin-bottom: 2rem;">
    <h1 style="border-bottom: 2px solid var(--primary-fg); padding-bottom: 0.5rem; margin-bottom: 1.5rem;">
        🏠 <?= htmlspecialchars($station['name']) ?>
    </h1>

    <table style="width: 100%; border-collapse: collapse; max-width: 500px;">
        <?php if (!empty($station['contact_person'])): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);">👤 <?= htmlspecialchars(App\I18n\Translator::t('field.contact_person')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;"><?= htmlspecialchars($station['contact_person']) ?></td>
            </tr>
        <?php endif; ?>
        <?php
            // Strukturierte Adresse (#256) mit Rückfall auf das alte
            // Freitextfeld. Der Rückfall ist kein Übergangsprovisorium: Der
            // Altbestand wird bewusst nicht automatisch zerlegt, solange also
            // niemand die Einzelfelder gepflegt hat, ist der Freitext die
            // einzige vorhandene Angabe.
            //
            // Bewusst ohne eigene Beschriftungen je Feld - die Zeile trägt
            // weiterhin das vorhandene Label 'field.address'. Sechs neue
            // Übersetzungsschlüssel in allen zwölf Sprachdateien wären für eine
            // Anschrift, die jeder Leser auch ohne Feldnamen erfasst, reine Last.
            $addressLines = array_values(array_filter([
                trim(implode(' ', array_filter([$station['street'] ?? '', $station['house_number'] ?? '']))),
                trim(implode(' ', array_filter([$station['postal_code'] ?? '', $station['city'] ?? '']))),
                trim((string)($station['state'] ?? '')),
                trim((string)($station['country'] ?? '')),
            ], fn($line) => $line !== ''));

            $addressText = $addressLines !== [] ? implode("\n", $addressLines) : (string)($station['address'] ?? '');
        ?>
        <?php if (trim($addressText) !== ''): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted); vertical-align: top;">📍 <?= htmlspecialchars(App\I18n\Translator::t('field.address')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;"><?= nl2br(htmlspecialchars($addressText)) ?></td>
            </tr>
        <?php endif; ?>
        <?php if (!empty($station['phone'])): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);">📞 <?= htmlspecialchars(App\I18n\Translator::t('field.phone')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;"><?= htmlspecialchars($station['phone']) ?></td>
            </tr>
        <?php endif; ?>
        <?php if (!empty($station['email'])): ?>
            <tr style="border-bottom: 1px solid var(--border-color);">
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);">✉️ <?= htmlspecialchars(App\I18n\Translator::t('field.email')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;"><a href="mailto:<?= htmlspecialchars($station['email']) ?>"><?= htmlspecialchars($station['email']) ?></a></td>
            </tr>
        <?php endif; ?>
        <?php $stationWebsite = App\Helper\ExternalUrl::hrefOrNull($station['website'] ?? null); ?>
        <?php if ($stationWebsite !== null): ?>
            <tr>
                <th style="text-align: left; padding: 0.6rem 0; color: var(--text-muted);">🌐 <?= htmlspecialchars(App\I18n\Translator::t('field.website')) ?></th>
                <td style="padding: 0.6rem 0; font-weight: 500;"><a href="<?= htmlspecialchars($stationWebsite) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars(App\I18n\Translator::t('field.visit_website')) ?></a></td>
            </tr>
        <?php endif; ?>
    </table>
</div>

<div class="card">
    <h3 style="font-size: 1.2rem; margin-bottom: 1rem; color: var(--text-muted);"><?= htmlspecialchars(App\I18n\Translator::t('station.horses_heading')) ?></h3>

    <?php if (empty($horses)): ?>
        <p style="color: var(--text-subtle);"><?= htmlspecialchars(App\I18n\Translator::t('station.no_horses')) ?></p>
    <?php else: ?>
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
    <?php endif; ?>
</div>
