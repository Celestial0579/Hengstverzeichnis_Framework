<?php
// src/Views/admin_contact_merge.php
/**
 * Vorschau zum Zusammenführen zweier Kontakte (#297, seit #336 auf `contacts`).
 *
 * Bewusst mit Vorschau statt als Knopf in der Liste: Der Vorgang legt einen
 * Datensatz still und hängt fremde Zuordnungen um. Was danach passiert, soll
 * vorher dastehen.
 *
 * @var array $source
 * @var array<int, array<string, mixed>> $assignments  Zeilen, in denen der Kontakt eine Rolle hat
 * @var array<int, array<string, mixed>> $stationUses  Pferde, die ihn als Deckstation nennen
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
$stationUses = $stationUses ?? [];
?>
<div class="card" style="max-width: 760px;">
    <h2><?= htmlspecialchars($title) ?></h2>

    <p style="color: var(--text-muted);">
        Der unten gewählte Kontakt wird <strong>behalten</strong>. Dieser Datensatz hier
        wird in den Papierkorb gelegt; seine Pferde-Zuordnungen hängen anschließend an
        dem behaltenen Kontakt.
    </p>

    <div style="background: var(--surface-muted); border: 1px solid var(--border-color); border-radius: 6px; padding: 0.9rem; margin-bottom: 1.2rem;">
        <strong>Wird aufgegeben:</strong>
        <?= htmlspecialchars((string)$source['name']) ?>
        <?php if (!empty($source['contact_person'])): ?>
            <span style="color: var(--text-muted);">· <?= htmlspecialchars((string)$source['contact_person']) ?></span>
        <?php endif; ?>
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
        <p style="color: var(--text-subtle);">Diesem Kontakt sind keine Pferde zugeordnet.</p>
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
            Ist beim behaltenen Kontakt bereits dieselbe Zuordnung vorhanden
            (gleiches Pferd, gleiche Rolle, gleicher Zeitraum sowie gleiche Deckstation
            und gleiches Herkunftsland), wird sie nicht doppelt angelegt. Weicht auch nur
            eine dieser Angaben ab, bleiben beide erhalten — sie sind Historie.
        </p>
    <?php endif; ?>

    <?php
        // Der zweite Steckplatz (#336): Ein Kontakt kann zugleich Deckstation
        // sein. Diese Verweise ziehen mit um - stünden sie hier nicht, hielte
        // der Bearbeiter die Liste darüber für vollständig und übersähe, dass
        // er gerade auch die Standortangabe von Pferden verschiebt.
    ?>
    <h3 style="font-size: 1rem;">Als Deckstation genannt (<?= count($stationUses) ?>)</h3>
    <?php if ($stationUses === []): ?>
        <p style="color: var(--text-subtle);">Kein Pferd nennt diesen Kontakt als Deckstation.</p>
    <?php else: ?>
        <ul style="margin: 0.3rem 0 1.2rem 1.2rem; font-size: 0.9rem;">
            <?php foreach ($stationUses as $s): ?>
                <li>
                    <a href="/admin/horses/edit?id=<?= (int)$s['horse_id'] ?>"><?= htmlspecialchars((string)$s['horse_name']) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: -0.8rem;">
            Diese Verweise zeigen nach dem Zusammenführen auf den behaltenen Kontakt.
        </p>
    <?php endif; ?>

    <form action="/admin/contacts/merge" method="GET" style="margin-top: 1.5rem;">
        <input type="hidden" name="id" value="<?= (int)$source['id'] ?>">
        <div class="form-group">
            <label for="q">Ziel-Kontakt suchen</label>
            <div style="display: flex; gap: 0.5rem;">
                <input type="text" id="q" name="q" class="form-control"
                       value="<?= htmlspecialchars((string)$search) ?>"
                       placeholder="Name, Ansprechpartner, Ort oder PLZ">
                <button type="submit" class="btn btn-secondary">Suchen</button>
            </div>
        </div>
    </form>

    <form action="/admin/contacts/merge" method="POST" style="margin-top: 1.5rem;"
          data-confirm="Diesen Kontakt wirklich aufgeben und seine Zuordnungen auf den gewählten Kontakt umhängen?">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        <input type="hidden" name="source_id" value="<?= (int)$source['id'] ?>">

        <div class="form-group">
            <label for="target_id">Diesen Kontakt behalten *</label>
            <select id="target_id" name="target_id" class="form-control" required>
                <option value="">-- Ziel wählen --</option>
                <?php foreach ($candidates as $c): ?>
                    <?php $cOrt = array_filter([$c['postal_code'] ?? '', $c['city'] ?? '']); ?>
                    <option value="<?= (int)$c['id'] ?>">
                        <?= htmlspecialchars((string)$c['name']) ?><?= !empty($c['contact_person']) ? ' (' . htmlspecialchars((string)$c['contact_person']) . ')' : '' ?><?= $cOrt !== [] ? ' — ' . htmlspecialchars(implode(' ', $cOrt)) : '' ?> (ID <?= (int)$c['id'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <small style="color: var(--text-muted);">
                Leere Felder des behaltenen Kontakts werden aus dem aufgegebenen Datensatz
                ergänzt. Bereits gefüllte Felder bleiben unverändert. Die Freigabe der
                Kontaktdaten wird <strong>nicht</strong> übernommen — sie gilt dem Datensatz,
                dem sie erteilt wurde.
                <?php if ($truncated): ?>
                    <br><strong>Die Liste ist auf <?= (int)$candidateLimit ?> Einträge gekürzt</strong> —
                    es gibt weitere Treffer. Bitte oben suchen, um den gewünschten Kontakt einzugrenzen.
                <?php elseif ($candidates === [] && $search !== ''): ?>
                    <br><strong>Kein Treffer für „<?= htmlspecialchars((string)$search) ?>".</strong>
                <?php endif; ?>
            </small>
        </div>

        <button type="submit" class="btn">Zusammenführen</button>
        <a href="/admin/contacts" class="btn btn-secondary">Abbrechen</a>
    </form>
</div>
