<?php
// src/Views/partials/publish_filter_bar.php
/**
 * Wiederverwendbare Filter-Leiste "Alle / Veröffentlicht / Nicht veröffentlicht" für
 * die Admin-Listen (Pferde, Personen, Deckstationen). Erwartet im umgebenden Scope:
 *
 * @var string   $publishBase     Basis-Pfad der Liste, z. B. '/admin/horses'
 * @var int|null $publishedFilter Aktiver Filter: 1, 0 oder null (alle)
 * @var bool     $canPublish      Nur mit Veröffentlichungs-Recht anzeigen
 */
if (empty($canPublish)) {
    return; // Ohne publish-Recht ist der Filter nicht relevant.
}
$pf = $publishedFilter ?? null;
$linkStyle = 'padding: 0.3rem 0.8rem; font-size: 0.85rem;';
?>
<div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem;">
    <span style="font-size: 0.9rem; color: var(--text-muted);">Anzeigen:</span>
    <a href="<?= htmlspecialchars($publishBase) ?>" class="btn <?= $pf === null ? '' : 'btn-secondary' ?>" style="<?= $linkStyle ?>">Alle</a>
    <a href="<?= htmlspecialchars($publishBase) ?>?published=1" class="btn <?= $pf === 1 ? '' : 'btn-secondary' ?>" style="<?= $linkStyle ?>">🌐 Veröffentlicht</a>
    <a href="<?= htmlspecialchars($publishBase) ?>?published=0" class="btn <?= $pf === 0 ? '' : 'btn-secondary' ?>" style="<?= $linkStyle ?>">Nicht veröffentlicht</a>
</div>
