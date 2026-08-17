<?php
// src/Views/partials/publish_filter_bar.php
/**
 * Wiederverwendbare Filter-Leiste "Alle / Veröffentlicht / Nicht veröffentlicht" für
 * die Admin-Listen (Pferde, Personen, Deckstationen). Erwartet im umgebenden Scope:
 *
 * @var string   $publishBase     Basis-Pfad der Liste, z. B. '/admin/horses'
 * @var int|null $publishedFilter Aktiver Filter: 1, 0 oder null (alle)
 * @var bool     $canPublish      Nur mit Veröffentlichungs-Recht anzeigen
 * @var array<string, string> $filters Aktive Suchparameter der Liste (ohne
 *   published/page) - sie müssen mit in die Links, sonst wirft ein Klick hier
 *   die Suche des Benutzers weg.
 */
if (empty($canPublish)) {
    return; // Ohne publish-Recht ist der Filter nicht relevant.
}
$pf = $publishedFilter ?? null;
$filters = $filters ?? [];
$linkStyle = 'padding: 0.3rem 0.8rem; font-size: 0.85rem;';

/**
 * Baut den Link einer Filter-Schaltfläche: aktive Suche behalten, Seitenzahl
 * bewusst NICHT - eine andere Treffermenge hat andere Seiten, und Seite 7 der
 * alten Menge ist in der neuen oft leer.
 */
$publishLink = static function (?int $ziel) use ($publishBase, $filters): string {
    $params = $filters;
    if ($ziel !== null) {
        $params['published'] = (string)$ziel;
    }
    return htmlspecialchars($publishBase . ($params === [] ? '' : '?' . http_build_query($params)));
};
?>
<div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem;">
    <span style="font-size: 0.9rem; color: var(--text-muted);">Anzeigen:</span>
    <a href="<?= $publishLink(null) ?>" class="btn <?= $pf === null ? '' : 'btn-secondary' ?>" style="<?= $linkStyle ?>">Alle</a>
    <a href="<?= $publishLink(1) ?>" class="btn <?= $pf === 1 ? '' : 'btn-secondary' ?>" style="<?= $linkStyle ?>">🌐 Veröffentlicht</a>
    <a href="<?= $publishLink(0) ?>" class="btn <?= $pf === 0 ? '' : 'btn-secondary' ?>" style="<?= $linkStyle ?>">Nicht veröffentlicht</a>
</div>
