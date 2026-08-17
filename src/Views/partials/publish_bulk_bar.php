<?php
// src/Views/partials/publish_bulk_bar.php
/**
 * Eigenständiges Formular + Aktionsleiste für die Massen-Veröffentlichung. Bewusst
 * KEIN Wrapper um die Tabelle (die Zeilen enthalten bereits ein Lösch-Formular, und
 * verschachtelte <form> sind ungültiges HTML). Stattdessen steht dieses Formular für
 * sich; die Zeilen-Checkboxen werden per HTML5-`form="<id>"`-Attribut damit verknüpft.
 * Erwartet im umgebenden Scope:
 *
 * @var string   $publishBase     Basis-Pfad, z. B. '/admin/horses' (Action = $publishBase.'/publish')
 * @var string   $publishFormId   Eindeutige Formular-ID, von den Zeilen-Checkboxen referenziert
 * @var int|null $publishedFilter Aktiver Filter (wird über den Redirect erhalten)
 * @var array<string, string> $filters Aktive Suchparameter (reisen als versteckte
 *   Felder mit, damit der Redirect nach der Aktion wieder bei derselben Suche landet)
 * @var int      $page            Aktuelle Seite, ebenso
 */
$formId = $publishFormId ?? 'publishForm';
$filters = $filters ?? [];
$page = $page ?? 1;
?>
<form id="<?= htmlspecialchars($formId) ?>" action="<?= htmlspecialchars($publishBase) ?>/publish" method="POST"
      style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 6px; padding: 0.6rem 0.8rem; margin-bottom: 0.5rem;">
    <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
    <?php if (($publishedFilter ?? null) !== null): ?><input type="hidden" name="published" value="<?= (int)$publishedFilter ?>"><?php endif; ?>
    <?php // Suche und Seite mitschicken - der Controller setzt daraus (gegen eine
          // Weißliste) den Redirect zusammen, siehe BaseController::listFilterQuery(). ?>
    <?php foreach ($filters as $feldName => $feldWert): ?>
        <input type="hidden" name="<?= htmlspecialchars((string)$feldName) ?>" value="<?= htmlspecialchars((string)$feldWert) ?>">
    <?php endforeach; ?>
    <?php if ((int)$page > 1): ?><input type="hidden" name="page" value="<?= (int)$page ?>"><?php endif; ?>
    <span style="font-size: 0.85rem; color: var(--text-muted);">Auswahl:</span>
    <button type="submit" name="publish" value="1" class="btn" style="padding: 0.3rem 0.8rem; font-size: 0.85rem;">🌐 Veröffentlichen</button>
    <button type="submit" name="publish" value="0" class="btn btn-secondary" style="padding: 0.3rem 0.8rem; font-size: 0.85rem;">Veröffentlichung zurücknehmen</button>
    <span style="font-size: 0.8rem; color: var(--text-subtle);">(betrifft nur die angehakten Zeilen)</span>
</form>
<script>
if (!window.__publishSelectionInit) {
    window.__publishSelectionInit = true;
    function togglePublishSelection(source) {
        var scope = source.closest('table') || document;
        scope.querySelectorAll('input[name="ids[]"]').forEach(function (cb) { cb.checked = source.checked; });
    }
}
</script>
