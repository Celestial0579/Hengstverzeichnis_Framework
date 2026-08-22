<?php
// src/Views/partials/admin_pagination.php
/**
 * Blätter-Leiste der Admin-Listen (Pferde, Personen, Deckstationen).
 *
 * Die Links tragen IMMER die aktive Suche und den Veröffentlichungs-Filter mit -
 * ohne das führte ein Klick auf "Weiter" zurück auf die ungefilterte Liste, und
 * genau dann ist Blättern nutzlos.
 *
 * @var string $publishBase              Basis-Pfad der Liste, z. B. '/admin/horses'
 * @var array<string, string> $filters   Aktive Suchparameter (ohne published/page)
 * @var int|null $publishedFilter        Aktiver Veröffentlichungs-Filter
 * @var int $page
 * @var int $totalPages
 */
$page = (int)($page ?? 1);
$totalPages = (int)($totalPages ?? 1);
$filters = $filters ?? [];
$publishedFilter = $publishedFilter ?? null;
if ($totalPages <= 1) {
    return;
}
$seitenLink = static function (int $ziel) use ($publishBase, $filters, $publishedFilter): string {
    $params = $filters;
    if ($publishedFilter !== null) {
        $params['published'] = (string)(int)$publishedFilter;
    }
    $params['page'] = (string)$ziel;
    return htmlspecialchars($publishBase . '?' . http_build_query($params));
};
?>
<nav aria-label="Seiten" class="aktionen" style="justify-content: center; margin-top: 1.5rem;">
    <?php if ($page > 1): ?>
        <a href="<?= $seitenLink(1) ?>" class="btn btn-secondary" style="padding: 0.3rem 0.8rem;">&laquo; Erste</a>
        <a href="<?= $seitenLink($page - 1) ?>" class="btn btn-secondary" style="padding: 0.3rem 0.8rem;">&lsaquo; Zurück</a>
    <?php endif; ?>
    <span style="font-size: 0.9rem; color: var(--text-muted);">Seite <?= $page ?> von <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?>
        <a href="<?= $seitenLink($page + 1) ?>" class="btn btn-secondary" style="padding: 0.3rem 0.8rem;">Weiter &rsaquo;</a>
        <a href="<?= $seitenLink($totalPages) ?>" class="btn btn-secondary" style="padding: 0.3rem 0.8rem;">Letzte &raquo;</a>
    <?php endif; ?>
</nav>
