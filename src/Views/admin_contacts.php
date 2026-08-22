<?php
// src/Views/admin_contacts.php
/**
 * Die Kontaktliste der Verwaltung (#336) - Nachfolgerin von admin_persons.php
 * und admin_breeding_stations.php. Beide Listen konnten Dinge, die die andere
 * nicht konnte (Ansprechpartner-Spalte dort, Mitgliedsstatus und
 * Züchter-Filter hier); zusammengelegt wird die Vereinigung, nichts fällt weg.
 *
 * @var array $contacts
 * @var bool $canCreate
 * @var bool $canEdit
 * @var bool $canDelete
 * @var bool $canPublish
 * @var int|null $publishedFilter Aktiver Filter: 1, 0 oder null (alle)
 * @var array<string, string> $filters Geprüfte Suchparameter (ContactController::index)
 * @var bool $hasActiveFilters
 * @var array<int, string> $countries
 * @var int $page
 * @var int $totalPages
 * @var int $totalCount
 * @var array{0:int,1:int,2:int,3:int} $mergeReport Umgehängt, verworfen, ergänzt, Stationsverweise
 */
$canPublish = $canPublish ?? false;
$publishedFilter = $publishedFilter ?? null;
$publishBase = '/admin/contacts';
$publishFormId = 'contactPublishForm';
$filters = $filters ?? [];
$hasActiveFilters = $hasActiveFilters ?? false;
$mergeReport = $mergeReport ?? [0, 0, 0, 0];
$resetHref = '/admin/contacts' . ($publishedFilter !== null ? '?published=' . (int)$publishedFilter : '');
$spalten = $canPublish ? 9 : 8;
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
        <div>
            <h2>📇 Kontakte verwalten</h2>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Züchter, Besitzer, frühere Eigentümer, Deckstationen und Gestüte - seit v0.8 in einer Liste.
            </p>
        </div>
        <?php if ($canCreate): ?>
            <a href="/admin/contacts/create" class="btn">Neuen Kontakt anlegen</a>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem;">
            <?php if (($_GET['success'] ?? '') === 'merged'): ?>
                <?php
                    // Die vier Zahlen kommen als geprüfte Ganzzahlen aus
                    // ContactController::index() (requestInt), nicht aus $_GET -
                    // eine View liest hier grundsätzlich keine Anfrage.
                    [$mUmgehaengt, $mVerworfen, $mErgaenzt, $mStationen] = $mergeReport;
                ?>
                Zusammengeführt:
                <?= $mUmgehaengt ?> Zuordnung<?= $mUmgehaengt === 1 ? '' : 'en' ?> umgehängt,
                <?= $mVerworfen ?> doppelte verworfen,
                <?= $mErgaenzt ?> leere<?= $mErgaenzt === 1 ? 's Feld' : ' Felder' ?> aus dem
                aufgegebenen Datensatz ergänzt<?php if ($mStationen > 0): ?>,
                <?= $mStationen ?> Verweis<?= $mStationen === 1 ? '' : 'e' ?> auf den Kontakt
                als Deckstation umgehängt<?php endif; ?>.
                <?php if ($mErgaenzt >= 3): ?>
                    <br><strong>Zur Kontrolle:</strong> Der aufgegebene Datensatz war deutlich
                    reichhaltiger als der behaltene. Das ist meist ein Zeichen dafür, dass die
                    beiden vertauscht waren - der behaltene trägt dann den falschen Namen.
                    Rückgängig machen lässt es sich über den
                    <a href="/admin/trash">Papierkorb</a>.
                <?php endif; ?>
            <?php elseif (($_GET['success'] ?? '') === 'created'): ?>
                ✓ Kontakt erfolgreich angelegt.
            <?php elseif (($_GET['success'] ?? '') === 'updated'): ?>
                ✓ Kontakt aktualisiert.
            <?php elseif (($_GET['success'] ?? '') === 'deleted'): ?>
                ✓ Kontakt in den Papierkorb verschoben.
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

    <form action="/admin/contacts" method="GET" style="background: var(--surface-muted); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 1rem;">
        <?php if ($publishedFilter !== null): ?><input type="hidden" name="published" value="<?= (int)$publishedFilter ?>"><?php endif; ?>
        <div style="display: flex; gap: 0.8rem; flex-wrap: wrap; align-items: center;">
            <div style="flex: 1; min-width: 240px;">
                <label for="admin-contact-search" class="sr-only">Kontakte durchsuchen</label>
                <input type="text" id="admin-contact-search" name="search" class="form-control" autocomplete="off"
                       placeholder="🔍 Name, Ansprechpartner, Ort, PLZ, Land, E-Mail, Telefon, Notiz …"
                       value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
            </div>
            <button type="submit" class="btn">Suchen</button>
            <?php if ($hasActiveFilters): ?>
                <a href="<?= htmlspecialchars($resetHref) ?>" class="btn btn-secondary">Zurücksetzen</a>
            <?php endif; ?>
            <span style="background: var(--surface-muted); border: 1px solid var(--border-color); padding: 0.3rem 0.8rem; border-radius: 12px; font-size: 0.85rem; color: var(--text-muted);">
                <?= (int)($totalCount ?? count($contacts)) ?> Treffer
            </span>
        </div>

        <details <?= $hasActiveFilters ? 'open' : '' ?> style="margin-top: 1rem; border-top: 1px solid var(--border-color); padding-top: 0.8rem;">
            <summary style="font-weight: bold; color: var(--primary-fg); cursor: pointer; user-select: none;">Erweiterte Suche</summary>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.8rem; margin-top: 1rem;">
                <div class="form-group">
                    <label for="admin-contact-q-name" style="font-size: 0.85rem; font-weight: bold;">Name</label>
                    <input type="text" id="admin-contact-q-name" name="q_name" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <?php // Kam von der Stationsliste: Bei einem Betrieb steht der gesuchte Name oft hier. ?>
                    <label for="admin-contact-q-contact" style="font-size: 0.85rem; font-weight: bold;">Ansprechpartner</label>
                    <input type="text" id="admin-contact-q-contact" name="q_contact" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_contact'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-contact-q-city" style="font-size: 0.85rem; font-weight: bold;">Ort</label>
                    <input type="text" id="admin-contact-q-city" name="q_city" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_city'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-contact-q-plz" style="font-size: 0.85rem; font-weight: bold;">PLZ</label>
                    <input type="text" id="admin-contact-q-plz" name="q_postal_code" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_postal_code'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-contact-q-state" style="font-size: 0.85rem; font-weight: bold;">Bundesland / Kanton</label>
                    <input type="text" id="admin-contact-q-state" name="q_state" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_state'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="admin-contact-q-country" style="font-size: 0.85rem; font-weight: bold;">Land</label>
                    <input type="text" id="admin-contact-q-country" name="q_country" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_country'] ?? '') ?>" list="admin_contact_country_list">
                    <datalist id="admin_contact_country_list">
                        <?php foreach (($countries ?? []) as $countryOption): ?><option value="<?= htmlspecialchars((string)$countryOption) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label for="admin-contact-q-email" style="font-size: 0.85rem; font-weight: bold;">E-Mail</label>
                    <input type="text" id="admin-contact-q-email" name="q_email" class="form-control" style="padding: 0.5rem;" value="<?= htmlspecialchars($filters['q_email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <?php
                        // Herkunft aus contact_id_map: die beiden alten Listen als
                        // Filter. Ein Kontakt ohne Eintrag (nach dem Umbau angelegt)
                        // erscheint nur unter "Alle" - deshalb steht das dabei.
                    ?>
                    <label for="admin-contact-q-origin" style="font-size: 0.85rem; font-weight: bold;">Herkunft</label>
                    <select id="admin-contact-q-origin" name="q_origin" class="form-control" style="padding: 0.5rem;">
                        <option value="">Alle Kontakte</option>
                        <option value="person" <?= ($filters['q_origin'] ?? '') === 'person' ? 'selected' : '' ?>>aus dem Personenbestand</option>
                        <option value="station" <?= ($filters['q_origin'] ?? '') === 'station' ? 'selected' : '' ?>>aus dem Deckstationsbestand</option>
                    </select>
                    <small style="color: var(--text-subtle); font-size: 0.75rem;">Nach v0.8 angelegte Kontakte tragen keine Herkunft.</small>
                </div>
                <div class="form-group">
                    <?php
                        // Der Filter, den es vor v0.8 nicht geben musste: Die Freigabe
                        // ist seit dem Zusammenlegen der einzige Schutz je Datensatz
                        // (#293). Wer prüfen will, wessen Telefonnummer öffentlich
                        // steht, braucht dafür eine Liste - nicht die Datenbank.
                    ?>
                    <label for="admin-contact-q-contact-public" style="font-size: 0.85rem; font-weight: bold;">Kontaktdaten</label>
                    <select id="admin-contact-q-contact-public" name="q_contact_public" class="form-control" style="padding: 0.5rem;">
                        <option value="">Alle</option>
                        <option value="1" <?= ($filters['q_contact_public'] ?? '') === '1' ? 'selected' : '' ?>>öffentlich freigegeben</option>
                        <option value="0" <?= ($filters['q_contact_public'] ?? '') === '0' ? 'selected' : '' ?>>intern</option>
                    </select>
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <?php // contacts.is_breeder ist redaktionell gepflegt und NICHT aus
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

    <div class="tabelle-scroll">
        <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border-color); text-align: left;">
                    <?php if ($canPublish): ?><th style="padding: 0.5rem;"><input type="checkbox" onclick="togglePublishSelection(this)" title="Alle auswählen"></th><?php endif; ?>
                    <th style="padding: 0.5rem;">ID</th>
                    <th style="padding: 0.5rem;">Name</th>
                    <th style="padding: 0.5rem;">Ansprechpartner</th>
                    <th style="padding: 0.5rem;">Kontakt &amp; Ort</th>
                    <th style="padding: 0.5rem;">Zuordnungen</th>
                    <th style="padding: 0.5rem;">Als Deckstation</th>
                    <th style="padding: 0.5rem;">Sichtbarkeit</th>
                    <th style="padding: 0.5rem;">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($contacts)): ?>
                    <tr>
                        <td colspan="<?= $spalten ?>" style="padding: 1rem; text-align: center;">Keine Kontakte gefunden.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($contacts as $k): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <?php if ($canPublish): ?><td style="padding: 0.5rem;"><input type="checkbox" name="ids[]" value="<?= (int)$k['id'] ?>" form="<?= $publishFormId ?>"></td><?php endif; ?>
                            <td style="padding: 0.5rem;"><?= htmlspecialchars((string)$k['id']) ?></td>
                            <td style="padding: 0.5rem;">
                                <strong><?= htmlspecialchars((string)$k['name']) ?></strong>
                                <?php
                                // Länderflagge (#240): Emoji aus contacts.country, Tooltip
                                // trägt den gespeicherten Freitext; unbekannt => keine Flagge.
                                $countryFlag = App\Helper\CountryFlag::emoji($k['country'] ?? null);
                                ?>
                                <?php if ($countryFlag !== null): ?>
                                    <span title="<?= htmlspecialchars((string)$k['country']) ?>"><?= $countryFlag ?></span>
                                <?php endif; ?>
                                <?php if (!empty($k['is_breeder'])): ?>
                                    <span title="Als Züchter gekennzeichnet">🐴</span>
                                <?php endif; ?>
                                <?php // Kam aus der Stationsliste - dort war der Verweis der schnellste Weg zur Prüfung. ?>
                                <?php $website = App\Helper\ExternalUrl::hrefOrNull($k['website'] ?? null); ?>
                                <?php if ($website !== null): ?>
                                    <br><a href="<?= htmlspecialchars($website) ?>" target="_blank" rel="noopener noreferrer" style="font-size: 0.8rem; color: var(--primary-fg);">🌐 Website</a>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 0.5rem; font-size: 0.9rem;"><?= htmlspecialchars((string)($k['contact_person'] ?: '-')) ?></td>
                            <td style="padding: 0.5rem; font-size: 0.9rem; color: var(--text-muted);">
                                <?php
                                    // Strukturierte Felder (#188) zuerst, Freitext-Rest darunter.
                                    $addressLine = trim(implode(' ', array_filter([$k['street'] ?? '', $k['house_number'] ?? ''])));
                                    $placeLine = trim(implode(' ', array_filter([$k['postal_code'] ?? '', $k['city'] ?? ''])));
                                    // Bundesland/Kanton und Land (#256/#188) kommaseparat hinter den Ort.
                                    foreach ([$k['state'] ?? '', $k['country'] ?? ''] as $region) {
                                        if (!empty($region)) { $placeLine = trim($placeLine . ($placeLine !== '' ? ', ' : '') . $region); }
                                    }
                                    // address ist die alte Freitext-Anschrift der Stationen
                                    // (mehrzeilig im Altbestand) - sie steht hinter den
                                    // strukturierten Feldern, damit sichtbar bleibt, was
                                    // noch nicht übertragen ist.
                                    $lines = array_filter([
                                        $addressLine,
                                        $placeLine,
                                        $k['address'] ?? '',
                                        $k['email'] ?? '',
                                        $k['phone'] ?? '',
                                        $k['contact_info'] ?? '',
                                    ]);
                                ?>
                                <?= !empty($lines) ? nl2br(htmlspecialchars(implode("\n", $lines))) : '<em>Keine Angaben</em>' ?>
                            </td>
                            <td style="padding: 0.5rem;">
                                <span style="background: var(--surface-muted); padding: 0.25rem 0.6rem; border-radius: 12px; font-weight: bold; font-size: 0.85rem;">
                                    <?= (int)$k['horse_count'] ?> Zuordnungen
                                </span>
                            </td>
                            <td style="padding: 0.5rem;">
                                <?php // Die Kennzahl der alten Stationsliste: Pferde, die an diesem Kontakt stehen. ?>
                                <span style="background: var(--surface-muted); padding: 0.25rem 0.6rem; border-radius: 12px; font-weight: bold; font-size: 0.85rem;">
                                    <?= (int)($k['station_horse_count'] ?? 0) ?> Pferde
                                </span>
                            </td>
                            <td style="padding: 0.5rem;">
                                <span style="padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; background-color: <?= !empty($k['is_published']) ? '#d4edda' : '#f8d7da' ?>; color: <?= !empty($k['is_published']) ? '#155724' : '#721c24' ?>;">
                                    <?= !empty($k['is_published']) ? '🌐 Veröffentlicht' : 'Nicht veröffentlicht' ?>
                                </span>
                                <?php
                                    // Zweite Zeile, weil es zwei verschiedene Aussagen sind:
                                    // "der Datensatz ist öffentlich erreichbar" und "seine
                                    // Telefonnummer steht dabei". Seit v0.8 hängt die zweite
                                    // an einem einzigen Feld - sie gehört sichtbar in die
                                    // Liste, nicht nur ins Formular (#293).
                                ?>
                                <?php if (!empty($k['contact_public'])): ?>
                                    <br><span style="font-size: 0.75rem; color: var(--text-muted);" title="E-Mail, Telefon, Mobil und Anschrift sind freigegeben">📇 Kontaktdaten öffentlich</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 0.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                <?php if ($canEdit): ?>
                                    <a href="/admin/contacts/edit?id=<?= (int)$k['id'] ?>" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.9rem;">Bearbeiten</a>
                                    <?php if ($canDelete): ?>
                                        <?php // Zusammenfuehren legt einen Datensatz still - deshalb am Loeschrecht (#297). ?>
                                        <a href="/admin/contacts/merge?id=<?= (int)$k['id'] ?>" class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.9rem;" title="Diesen Kontakt mit einem anderen zusammenführen">Zusammenführen</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
                                    <form action="/admin/contacts/delete" method="POST" data-confirm="Diesen Kontakt wirklich löschen? Die Zuordnung zu allen Pferden wird dabei aufgehoben." style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
                                        <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                                        <button type="submit" class="btn" style="padding: 0.25rem 0.5rem; font-size: 0.9rem; background-color: #c62a38;">Löschen</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php require __DIR__ . '/partials/admin_pagination.php'; ?>

    <div style="margin-top: 2rem;">
        <a href="/admin" class="btn btn-secondary">Zurück zum Dashboard</a>
    </div>
</div>
