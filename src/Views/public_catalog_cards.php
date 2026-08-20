<?php
// src/Views/public_catalog_cards.php
/**
 * @var array $horses
 * @var array<int|string, array<int, string>> $cardSections Plugin-Hook-Ergebnisse je
 *   Pferde-ID (#97, siehe PublicController::catalog() für die Berechnung via
 *   catalog.card_sections)
 * @var array{page:int, totalPages:int, query:string}|null $catalogPagination
 *   Seiten-Navigation der SQL-Pagination (#125) - wird mit ins Karten-Grid
 *   gerendert, damit auch der AJAX-Pfad sie automatisch aktualisiert.
 */
$cardSections = $cardSections ?? [];
$catalogPagination = $catalogPagination ?? null;
?>
<?php if (empty($horses)): ?>
    <div style="grid-column: 1 / -1; padding: 2rem; text-align: center; color: var(--text-subtle); background: var(--surface-muted); border-radius: 6px; border: 1px dashed var(--border-color);">
        <?= htmlspecialchars(App\I18n\Translator::t('catalog.no_results')) ?>
    </div>
<?php else: ?>
    <?php foreach ($horses as $horse): ?>
        <div class="card" style="border: 1px solid var(--border-color); margin-bottom: 0; padding: 0; overflow: hidden; display: flex; flex-direction: column; transition: transform 0.2s, box-shadow 0.2s;">
            <?php if (!empty($horse['image_url'])): ?>
                <div style="width: 100%; height: 180px; overflow: hidden; background: var(--surface-muted);">
                    <?php // Lazy-Loading (#263): Bei CATALOG_PER_PAGE = 24 Karten liegt der
                          // weit überwiegende Teil außerhalb des Viewports. Der Container gibt
                          // die Höhe fest vor (180px), es entsteht also kein Layout-Sprung beim
                          // Nachladen. decoding="async" hält zusätzlich das Dekodieren aus dem
                          // Haupt-Thread. Gilt auch für den AJAX-Pfad, der diese Datei erneut
                          // rendert - dort zählt es beim Nachladen umso mehr. ?>
                    <img src="<?= htmlspecialchars(App\Helper\MediaUrl::horseImage($horse) ?? '') ?>" alt="<?= htmlspecialchars((string)$horse['name']) ?>" loading="lazy" decoding="async" style="width: 100%; height: 100%; object-fit: cover;">
                </div>
            <?php else: ?>
                <?php // Dieselbe Höhe wie der Bildfall (#350). Der Platzhalter war
                      // 60px flacher, wodurch Name, UELN, Geburtsjahr und Farbe einer
                      // bildlosen Kachel auf einer anderen Höhe standen als bei den
                      // Nachbarkacheln - das Raster wirkte verrutscht. Unten glich sich
                      // der Knopf über flex wieder an, die Zeilen dazwischen nicht.
                      // Die Überlegung aus #263 (Container gibt die Höhe fest vor, damit
                      // beim Nachladen kein Layout-Sprung entsteht) gilt hier genauso. ?>
                <div style="width: 100%; height: 180px; background: var(--surface-muted); display: flex; align-items: center; justify-content: center; font-size: 3rem; opacity: 0.4;">
                    🐴
                </div>
            <?php endif; ?>

            <div style="padding: 1.2rem; flex: 1; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <h3 style="margin-bottom: 0.5rem; color: var(--primary-fg);"><?= htmlspecialchars((string)$horse['name']) ?></h3>
                    <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem; display: flex; flex-direction: column; gap: 0.3rem;">
                        <p style="margin:0;">
                            <strong>UELN:</strong> <?= htmlspecialchars((string)($horse['ueln'] ?: '-')) ?>
                            <?php // Weitere Lebensnummern (#246) aus horse_registrations,
                                  // aggregiert im Controller; foreign_ueln bleibt der
                                  // Fallback für Bestand ohne Zeilen in der Kindtabelle. ?>
                            <?php $inlineNumbers = $horse['registration_numbers'] ?? null ?: ($horse['foreign_ueln'] ?? null); ?>
                            <?php if (!empty($inlineNumbers)): ?>
                                <span style="font-size: 0.8rem; color: var(--text-muted);">(<?= htmlspecialchars(App\I18n\Translator::t('field.foreign_ueln_inline')) ?>: <?= htmlspecialchars($inlineNumbers) ?>)</span>
                            <?php endif; ?>
                        </p>
                        <p style="margin:0;"><strong><?= htmlspecialchars(App\I18n\Translator::t('field.birth_year')) ?>:</strong> <?= htmlspecialchars((string)($horse['birth_year'] ?: '-')) ?></p>
                        <p style="margin:0;"><strong><?= htmlspecialchars(App\I18n\Translator::t('field.color')) ?>:</strong> <?= htmlspecialchars((string)($horse['color'] ?: '-')) ?></p>

                        <?php if (!empty($horse['station_name']) || !empty($horse['breeding_station'])): ?>
                            <p style="margin:0;"><strong><?= htmlspecialchars(App\I18n\Translator::t('catalog.breeding_station_inline')) ?></strong> <?= htmlspecialchars($horse['station_name'] ?: $horse['breeding_station']) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($horse['breeder_name'])): ?>
                            <p style="margin:0;"><strong><?= htmlspecialchars(App\I18n\Translator::t('catalog.breeder_inline')) ?></strong> <?= htmlspecialchars($horse['breeder_name']) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($horse['owner_name'])): ?>
                            <p style="margin:0;"><strong><?= htmlspecialchars(App\I18n\Translator::t('catalog.owner_inline')) ?></strong> <?= htmlspecialchars($horse['owner_name']) ?></p>
                        <?php endif; ?>
                    </div>

                    <?php foreach ($cardSections[$horse['id']] ?? [] as $extraSection): ?>
                        <?= $extraSection ?>
                    <?php endforeach; ?>
                </div>
                <a href="/horse?id=<?= $horse['id'] ?>" class="btn btn-secondary" style="display: block; text-align: center; margin-top: 0.5rem;"><?= htmlspecialchars(App\I18n\Translator::t('catalog.view_profile')) ?></a>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($catalogPagination !== null && $catalogPagination['totalPages'] > 1): ?>
        <?php $pgQuery = $catalogPagination['query'] !== '' ? $catalogPagination['query'] . '&' : ''; ?>
        <?php // data-catalog-pagination (#264): Sobald JavaScript das Nachladen
              // übernimmt, blendet es diesen Block aus - zwei Bedienelemente für
              // dieselbe Sache (Seite anspringen vs. Seite anhängen) wären
              // widersprüchlich. Ohne JavaScript bleibt er stehen und ist der
              // vollwertige Weg durch den Katalog. ?>
        <div data-catalog-pagination style="grid-column: 1 / -1; display: flex; justify-content: center; align-items: center; gap: 1rem; padding: 1rem 0;">
            <?php if ($catalogPagination['page'] > 1): ?>
                <a href="/katalog?<?= htmlspecialchars($pgQuery . 'page=' . ($catalogPagination['page'] - 1)) ?>" class="btn btn-secondary" style="padding: 0.4rem 0.9rem;">&laquo;</a>
            <?php endif; ?>
            <span style="font-size: 0.9rem; color: var(--text-muted);"><?= (int)$catalogPagination['page'] ?> / <?= (int)$catalogPagination['totalPages'] ?></span>
            <?php if ($catalogPagination['page'] < $catalogPagination['totalPages']): ?>
                <a href="/katalog?<?= htmlspecialchars($pgQuery . 'page=' . ($catalogPagination['page'] + 1)) ?>" class="btn btn-secondary" style="padding: 0.4rem 0.9rem;">&raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
