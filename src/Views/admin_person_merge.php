<?php
// src/Views/admin_person_merge.php
/**
 * Vorschau zum Zusammenführen zweier Personen (#297).
 *
 * Bewusst mit Vorschau statt als Knopf in der Liste: Der Vorgang legt einen
 * Datensatz still und hängt fremde Zuordnungen um. Was danach passiert, soll
 * vorher dastehen.
 *
 * @var array $source
 * @var array<int, array<string, mixed>> $assignments
 * @var array<int, array<string, mixed>> $candidates
 * @var string $search
 * @var bool $truncated
 * @var int $candidateLimit
 */
$roleLabels = [
    'breeder' => 'Züchter',
    'owner' => 'Besitzer',
    'keeper' => 'Halter',
];
?>
<div class="card" style="max-width: 760px;">
    <h2><?= htmlspecialchars($title) ?></h2>

    <p style="color: var(--text-muted);">
        Die unten gewählte Person wird <strong>behalten</strong>. Dieser Datensatz hier
        wird in den Papierkorb gelegt; seine Pferde-Zuordnungen hängen anschließend an
        der behaltenen Person.
    </p>

    <div style="background: var(--surface-muted); border: 1px solid var(--border-color); border-radius: 6px; padding: 0.9rem; margin-bottom: 1.2rem;">
        <strong>Wird aufgegeben:</strong>
        <?= htmlspecialchars((string)$source['name']) ?>
        <?php
        $ort = array_filter([$source['postal_code'] ?? '', $source['city'] ?? '']);
        ?>
        <?php if ($ort !== []): ?>
            <span style="color: var(--text-muted);">· <?= htmlspecialchars(implode(' ', $ort)) ?></span>
        <?php endif; ?>
        <span style="color: var(--text-muted);">· ID <?= (int)$source['id'] ?></span>
    </div>

    <h3 style="font-size: 1rem;">Betroffene Zuordnungen (<?= count($assignments) ?>)</h3>
    <?php if ($assignments === []): ?>
        <p style="color: var(--text-subtle);">Dieser Person sind keine Pferde zugeordnet.</p>
    <?php else: ?>
        <ul style="margin: 0.3rem 0 1.2rem 1.2rem; font-size: 0.9rem;">
            <?php foreach ($assignments as $a): ?>
                <li>
                    <a href="/admin/horses/edit?id=<?= (int)$a['horse_id'] ?>"><?= htmlspecialchars((string)$a['horse_name']) ?></a>
                    — <?= htmlspecialchars($roleLabels[$a['role']] ?? (string)$a['role']) ?>
                    <?php if (!empty($a['from_year']) || !empty($a['until_year'])): ?>
                        (<?= htmlspecialchars((string)($a['from_year'] ?: '?')) ?>–<?= htmlspecialchars((string)($a['until_year'] ?: 'heute')) ?>)
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: -0.8rem;">
            Ist bei der behaltenen Person bereits dieselbe Zuordnung vorhanden
            (gleiches Pferd, gleiche Rolle, gleicher Zeitraum sowie gleiche Deckstation
            und gleiches Herkunftsland), wird sie nicht doppelt angelegt. Weicht auch nur
            eine dieser Angaben ab, bleiben beide erhalten — sie sind Historie.
        </p>
    <?php endif; ?>

    <form action="/admin/persons/merge" method="GET" style="margin-top: 1.5rem;">
        <input type="hidden" name="id" value="<?= (int)$source['id'] ?>">
        <div class="form-group">
            <label for="q">Ziel-Person suchen</label>
            <div style="display: flex; gap: 0.5rem;">
                <input type="text" id="q" name="q" class="form-control"
                       value="<?= htmlspecialchars((string)$search) ?>"
                       placeholder="Name, Ort oder PLZ">
                <button type="submit" class="btn btn-secondary">Suchen</button>
            </div>
        </div>
    </form>

    <form action="/admin/persons/merge" method="POST" style="margin-top: 1.5rem;"
          data-confirm="Diese Person wirklich aufgeben und ihre Zuordnungen auf die gewählte Person umhängen?">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        <input type="hidden" name="source_id" value="<?= (int)$source['id'] ?>">

        <div class="form-group">
            <label for="target_id">Diese Person behalten *</label>
            <select id="target_id" name="target_id" class="form-control" required>
                <option value="">-- Ziel wählen --</option>
                <?php foreach ($candidates as $c): ?>
                    <?php $cOrt = array_filter([$c['postal_code'] ?? '', $c['city'] ?? '']); ?>
                    <option value="<?= (int)$c['id'] ?>">
                        <?= htmlspecialchars((string)$c['name']) ?><?= $cOrt !== [] ? ' — ' . htmlspecialchars(implode(' ', $cOrt)) : '' ?> (ID <?= (int)$c['id'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <small style="color: var(--text-muted);">
                Leere Felder der behaltenen Person werden aus dem aufgegebenen Datensatz
                ergänzt. Bereits gefüllte Felder bleiben unverändert.
                <?php if ($truncated): ?>
                    <br><strong>Die Liste ist auf <?= (int)$candidateLimit ?> Einträge gekürzt</strong> —
                    es gibt weitere Treffer. Bitte oben suchen, um die gewünschte Person einzugrenzen.
                <?php elseif ($candidates === [] && $search !== ''): ?>
                    <br><strong>Kein Treffer für „<?= htmlspecialchars((string)$search) ?>".</strong>
                <?php endif; ?>
            </small>
        </div>

        <button type="submit" class="btn">Zusammenführen</button>
        <a href="/admin/persons" class="btn btn-secondary">Abbrechen</a>
    </form>
</div>
