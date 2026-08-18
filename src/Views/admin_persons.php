<?php
// src/Views/admin_persons.php
/**
 * @var array $persons
 * @var bool $canCreate
 * @var bool $canEdit
 * @var bool $canDelete
 * @var bool $canPublish
 * @var int|null $publishedFilter Aktiver Filter: 1, 0 oder null (alle)
 * @var array<string, string> $filters Geprüfte Suchparameter (PersonController::index)
 * @var bool $hasActiveFilters
 * @var array<int, string> $countries
 * @var array<int, string> $memberships
 * @var int $page
 * @var int $totalPages
 * @var int $totalCount
 * @var array{0:int,1:int,2:int} $mergeReport Umgehängt, verworfen, ergänzt (nach dem Zusammenführen)
 */
$canPublish = $canPublish ?? false;
$publishedFilter = $publishedFilter ?? null;
$publishBase = '/admin/persons';
$publishFormId = 'personPublishForm';
$filters = $filters ?? [];
$hasActiveFilters = $hasActiveFilters ?? false;
$mergeReport = $mergeReport ?? [0, 0, 0];
$resetHref = '/admin/persons' . ($publishedFilter !== null ? '?published=' . (int)$publishedFilter : '');
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
        <div>
            <h2>👤 Personen verwalten</h2>
            <p style="color: var(--text-muted); font-size: 0.95rem;">Verwaltung von Züchtern, Besitzern und früheren Eigentümern.</p>
        </div>
        <?php if ($canCreate): ?>
            <a href="/admin/persons/create" class="btn">Neue Person anlegen</a>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            <?php if (($_GET['success'] ?? '') === 'merged'): ?>
                <?php
                    // Die drei Zahlen kommen als geprüfte Ganzzahlen aus
                    // PersonController::index() (requestInt), nicht aus $_GET -
                    // eine View liest hier grundsätzlich keine Anfrage.
                    [$mUmgehaengt, $mVerworfen, $mErgaenzt] = $mergeReport;
                ?>
                Zusammengeführt:
                <?= $mUmgehaengt ?> Zuordnung<?= $mUmgehaengt === 1 ? '' : 'en' ?> umgehängt,
                <?= $mVerworfen ?> doppelte verworfen,
                <?= $mErgaenzt ?> leere<?= $mErgaenzt === 1 ? 's Feld' : ' Felder' ?> aus dem
                aufgegebenen Datensatz ergänzt.
                <?php if ($mErgaenzt >= 3): ?>
                    <br><strong>Zur Kontrolle:</strong> Der aufgegebene Datensatz war deutlich
                    reichhaltiger als der behaltene. Das ist meist ein Zeichen dafür, dass die
                    beiden vertauscht waren - der behaltene trägt dann den falschen Namen.
                    Rückgängig machen lässt es sich über den
                    <a href="/admin/trash">Papierkorb</a>.
                <?php endif; ?>
            <?php else: ?>
                Aktion erfolgreich ausgeführt.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (($_GET['error'] ?? '') === 'merge_invalid'): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            Nicht zusammengeführt: Quelle oder Ziel existiert nicht (mehr) oder beide sind derselbe Datensatz.
        </div>
    <?php elseif (($_GET['error'] ?? '') === 'merge_failed'): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            Zusammenführen fehlgeschlagen - es wurde nichts geändert.
        </div>
    <?php endif; ?>

    <?php if (($_GET['error'] ?? '') === 'deleted'): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            Nicht gespeichert: Der Datensatz liegt im Papierkorb (#296). Zum
            Bearbeiten zuerst unter <a href="/admin/trash">Papierkorb</a>
            wiederherstellen.
        </div>
    <?php endif; ?>

    <form action="/admin/persons" method="GET" style="background: var(--surface-muted); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 1rem;">
        <?php if ($publishedFilter !== null): ?><input type="hidden" name="published" value="<?= (int)$publishedFilter ?>"><?php endif; ?>
        <div style="display: flex; gap: 0.8rem; flex-wrap: wrap; align-items: center;">
            <div style="flex: 1; min-width: 240px;">
                <label for="admin-person-search" class="sr-only">Personen durchsuchen</label>
                <input type="text" id="admin-person-search" name="search" class="form-control" autocomplete="off"
                       placeholder="🔍 Name, Ort, PLZ, Land, E-Mail, Telefon, Notiz …"
                       value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
            </div>
            <button type="submit" class="btn">Suchen</button>
            <?php if ($hasActiveFilters): ?>
                <a href="<?= htmlspecialchars($resetHref) ?>" class="btn btn-secondary">Zurücksetzen</a>
            <?php endif; ?>
            <span style="background: var(--surface-muted); border: 1px solid var(--border-color); padding: 0.3rem 0.8rem; border-radius: 12px; font-size: 0.85rem; color: var(--text-muted);">
                <?= (int)($totalCount ?? count($persons)) ?> Treffer
            </span>
        </div>

        <details <?= $hasActiveFilters ? 'open' : '' ?> style="margin-top: 1rem; border-top: 1px solid var(--border-color); padding-top: 0.8rem;">
            <summary style="font-weight: bold; color: var(--primary-fg); cursor: pointer; user-select: none;">Erweiterte Suche</summary>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.8rem; margin-top: 1rem;">
                <div class="form-group">
                    <label for="admin-person-q-name" style="font-size: 0.85rem; font-weight: bold;">Name</label>
                    <input type="text" id="admin-person-q-name" name="q_name" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-person-q-city" style="font-size: 0.85rem; font-weight: bold;">Ort</label>
                    <input type="text" id="admin-person-q-city" name="q_city" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_city'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-person-q-plz" style="font-size: 0.85rem; font-weight: bold;">PLZ</label>
                    <input type="text" id="admin-person-q-plz" name="q_postal_code" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_postal_code'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-person-q-state" style="font-size: 0.85rem; font-weight: bold;">Bundesland / Kanton</label>
                    <input type="text" id="admin-person-q-state" name="q_state" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_state'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-person-q-country" style="font-size: 0.85rem; font-weight: bold;">Land</label>
                    <input type="text" id="admin-person-q-country" name="q_country" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_country'] ?? '') ?>" list="admin_person_country_list">
                    <datalist id="admin_person_country_list">
                        <?php foreach (($countries ?? []) as $countryOption): ?><option value="<?= htmlspecialchars((string)$countryOption) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label for="admin-person-q-email" style="font-size: 0.85rem; font-weight: bold;">E-Mail</label>
                    <input type="text" id="admin-person-q-email" name="q_email" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-person-q-membership" style="font-size: 0.85rem; font-weight: bold;">Mitgliedsstatus</label>
                    <input type="text" id="admin-person-q-membership" name="q_membership" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_membership'] ?? '') ?>" list="admin_person_membership_list">
                    <datalist id="admin_person_membership_list">
                        <?php foreach (($memberships ?? []) as $membershipOption): ?><option value="<?= htmlspecialchars((string)$membershipOption) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <?php // persons.is_breeder ist redaktionell gepflegt und NICHT aus
                          // horse_persons.role='breeder' abgeleitet - siehe schema.sql. ?>
                    <label style="font-size: 0.9rem; display: flex; align-items: center; gap: 0.4rem;">
                        <input type="checkbox" name="q_breeder_only" value="1" <?= ($filters['q_breeder_only'] ?? '') === '1' ? 'checked' : '' ?>>
                        Nur Züchter
                    </label>
                </div>
            </div>
            <div style="margin-top: 0.8rem; text-align: right;">
                <button type="submit" class="btn" style="padding: 0.5rem 1.2rem;">Filter anwenden</button>
            </div>
        </details>
    </form>

    <?php require __DIR__ . '/partials/publish_filter_bar.php'; ?>
    <?php if ($canPublish): require __DIR__ . '/partials/publish_bulk_bar.php'; endif; ?>

    <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
        <thead>
            <tr style="border-bottom: 2px solid var(--border-color); text-align: left;">
                <?php if ($canPublish): ?><th style="padding: 0.5rem;"><input type="checkbox" onclick="togglePublishSelection(this)" title="Alle auswählen"></th><?php endif; ?>
                <th style="padding: 0.5rem;">ID</th>
                <th style="padding: 0.5rem;">Name</th>
                <th style="padding: 0.5rem;">Kontakt & Ort</th>
                <th style="padding: 0.5rem;">Zugeordnete Pferde</th>
                <th style="padding: 0.5rem;">Sichtbarkeit</th>
                <th style="padding: 0.5rem;">Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($persons)): ?>
                <tr>
                    <td colspan="<?= $canPublish ? 7 : 6 ?>" style="padding: 1rem; text-align: center;">Keine Personen gefunden.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($persons as $p): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <?php if ($canPublish): ?><td style="padding: 0.5rem;"><input type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>" form="<?= $publishFormId ?>"></td><?php endif; ?>
                        <td style="padding: 0.5rem;"><?= htmlspecialchars((string)$p['id']) ?></td>
                        <td style="padding: 0.5rem;">
                            <strong><?= htmlspecialchars((string)$p['name']) ?></strong>
                            <?php
                            // Länderflagge (#240): Emoji aus persons.country, Tooltip
                            // trägt den gespeicherten Freitext; unbekannt => keine Flagge.
                            $countryFlag = App\Helper\CountryFlag::emoji($p['country'] ?? null);
                            ?>
                            <?php if ($countryFlag !== null): ?>
                                <span title="<?= htmlspecialchars((string)$p['country']) ?>"><?= $countryFlag ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.5rem; font-size: 0.9rem; color: var(--text-muted);">
                            <?php
                                // Strukturierte Felder (#188) zuerst, Freitext-Rest darunter.
                                $addressLine = trim(implode(' ', array_filter([$p['street'] ?? '', $p['house_number'] ?? ''])));
                                $placeLine = trim(implode(' ', array_filter([$p['postal_code'] ?? '', $p['city'] ?? ''])));
                                // Bundesland/Kanton und Land (#256/#188) kommaseparat hinter den Ort.
                                foreach ([$p['state'] ?? '', $p['country'] ?? ''] as $region) {
                                    if (!empty($region)) { $placeLine = trim($placeLine . ($placeLine !== '' ? ', ' : '') . $region); }
                                }
                                $lines = array_filter([$addressLine, $placeLine, $p['email'] ?? '', $p['membership_status'] ?? '', $p['contact_info'] ?? '']);
                            ?>
                            <?= !empty($lines) ? nl2br(htmlspecialchars(implode("\n", $lines))) : '<em>Keine Angaben</em>' ?>
                        </td>
                        <td style="padding: 0.5rem;">
                            <span style="background: var(--surface-muted); padding: 0.25rem 0.6rem; border-radius: 12px; font-weight: bold; font-size: 0.85rem;">
                                <?= (int)$p['horse_count'] ?> Zuordnungen
                            </span>
                        </td>
                        <td style="padding: 0.5rem;">
                            <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background-color: <?= !empty($p['is_published']) ? '#d4edda' : '#f8d7da' ?>; color: <?= !empty($p['is_published']) ? '#155724' : '#721c24' ?>;">
                                <?= !empty($p['is_published']) ? '🌐 Veröffentlicht' : 'Nicht veröffentlicht' ?>
                            </span>
                        </td>
                        <td style="padding: 0.5rem; display: flex; gap: 0.5rem;">
                            <?php if ($canEdit): ?>
                                <a href="/admin/persons/edit?id=<?= $p['id'] ?>" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.9rem;">Bearbeiten</a>
                                <?php if ($canDelete): ?>
                                    <?php // Zusammenfuehren legt einen Datensatz still - deshalb am Loeschrecht (#297). ?>
                                    <a href="/admin/persons/merge?id=<?= $p['id'] ?>" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.9rem;" title="Diese Person mit einer anderen zusammenführen">Zusammenführen</a>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form action="/admin/persons/delete" method="POST" data-confirm="Möchten Sie diese Person wirklich löschen? Die Zuordnung zu allen Pferden wird dabei aufgehoben." style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn" style="padding: 0.25rem 0.5rem; font-size: 0.9rem; background-color: #c62a38;">Löschen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php require __DIR__ . '/partials/admin_pagination.php'; ?>

    <div style="margin-top: 2rem;">
        <a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a>
    </div>
</div>
