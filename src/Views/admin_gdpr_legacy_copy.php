<?php
// src/Views/admin_gdpr_legacy_copy.php
/**
 * Altkopie eines Kontakts aus der Kontaktlisten-Umstellung (#336) für die
 * Auskunft nach Art. 15 DSGVO (Audit M23). Jede Angabe läuft durch
 * htmlspecialchars - die Werte stammen aus Altbeständen und sind nie
 * geprüft worden.
 *
 * @var array<string, mixed>|null $kontakt
 * @var int $kontaktId
 * @var array<int, array{tabelle: string, kennung: int, felder: array<string, mixed>}> $altkopien
 */
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2>🗄️ Altkopie (#336) zu Kontakt #<?= (int)$kontaktId ?></h2>
            <p style="color: var(--text-muted); font-size: 0.95rem; margin-top: 0.2rem;">
                Stillgelegte Kopie aus der Umstellung auf die Kontaktliste (Tabellen
                <code>persons_pre_contacts</code> und <code>breeding_stations_pre_contacts</code>).
                Sie gehört zur Auskunft nach Art. 15 DSGVO. Löschung und Anonymisierung
                des Kontakts behandeln sie mit.
            </p>
        </div>
        <a href="/admin/gdpr" class="btn btn-secondary">Zurück zu den DSGVO-Anfragen</a>
    </div>

    <?php if ($kontakt === null): ?>
        <p>Diesen Kontakt gibt es nicht (mehr).</p>
    <?php elseif ($altkopien === []): ?>
        <p>Zu <strong><?= htmlspecialchars((string)$kontakt['name']) ?></strong> gibt es keine Altkopie.</p>
    <?php else: ?>
        <p>Kontakt: <strong><?= htmlspecialchars((string)$kontakt['name']) ?></strong></p>
        <?php foreach ($altkopien as $kopie): ?>
            <h3 style="font-size: 1rem; margin-top: 1.2rem;">
                <?= htmlspecialchars($kopie['tabelle']) ?>, alte Kennung #<?= (int)$kopie['kennung'] ?>
            </h3>
            <div class="tabelle-scroll">
            <table class="table" style="width: 100%; max-width: 48rem;">
                <tbody>
                    <?php foreach ($kopie['felder'] as $feld => $wert): ?>
                        <tr>
                            <th style="text-align: left; width: 14rem; font-weight: normal; color: var(--text-muted);"><?= htmlspecialchars((string)$feld) ?></th>
                            <td style="white-space: pre-wrap;"><?= $wert === null ? '<em style="color: var(--text-subtle);">leer</em>' : htmlspecialchars((string)$wert) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
