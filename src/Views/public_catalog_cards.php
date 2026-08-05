<?php
// src/Views/public_catalog_cards.php
/**
 * @var array $horses
 */
?>
<?php if (empty($horses)): ?>
    <div style="grid-column: 1 / -1; padding: 2rem; text-align: center; color: #777; background: #fafafa; border-radius: 6px; border: 1px dashed #ccc;">
        <?= htmlspecialchars(App\I18n\Translator::t('catalog.no_results')) ?>
    </div>
<?php else: ?>
    <?php foreach ($horses as $horse): ?>
        <div class="card" style="border: 1px solid #eee; margin-bottom: 0; padding: 0; overflow: hidden; display: flex; flex-direction: column; transition: transform 0.2s, box-shadow 0.2s;">
            <?php if (!empty($horse['image_url'])): ?>
                <div style="width: 100%; height: 180px; overflow: hidden; background: #f0f0f0;">
                    <img src="<?= htmlspecialchars($horse['image_url']) ?>" alt="<?= htmlspecialchars((string)$horse['name']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                </div>
            <?php else: ?>
                <div style="width: 100%; height: 120px; background: #eef2f5; display: flex; align-items: center; justify-content: center; font-size: 3rem; opacity: 0.4;">
                    🐴
                </div>
            <?php endif; ?>

            <div style="padding: 1.2rem; flex: 1; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <h3 style="margin-bottom: 0.5rem; color: var(--primary-color);"><?= htmlspecialchars((string)$horse['name']) ?></h3>
                    <div style="font-size: 0.85rem; color: #555; margin-bottom: 1rem; display: flex; flex-direction: column; gap: 0.3rem;">
                        <p style="margin:0;">
                            <strong>UELN:</strong> <?= htmlspecialchars((string)($horse['ueln'] ?: '-')) ?>
                            <?php if (!empty($horse['foreign_ueln'])): ?>
                                <span style="font-size: 0.8rem; color: #666;">(<?= htmlspecialchars(App\I18n\Translator::t('field.foreign_ueln_inline')) ?>: <?= htmlspecialchars($horse['foreign_ueln']) ?>)</span>
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
                </div>
                <a href="/hengst?id=<?= $horse['id'] ?>" class="btn btn-secondary" style="display: block; text-align: center; margin-top: 0.5rem;"><?= htmlspecialchars(App\I18n\Translator::t('catalog.view_profile')) ?></a>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
