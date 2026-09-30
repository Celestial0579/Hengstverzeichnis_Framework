<?php
// src/Views/admin_plugin_uninstall.php
/**
 * Die Rückfrage vor dem Deinstallieren eines Addons (#338).
 *
 * Bewusst eine eigene Seite statt eines Bestätigungsdialogs: Der Betreiber
 * soll sehen, WAS verschwindet, bevor er entscheidet - mit Zahlen, nicht mit
 * Tabellennamen.
 *
 * @var string $slug
 * @var array  $plugin    Eintrag aus PluginManager::getDiscoveredPlugins()
 * @var array  $vorschau  PluginManager::deinstallationsVorschau() - mit
 *      'deklariert' (hat das Addon ein owns-Register?) und 'unregistriert'
 *      (Tabellen/Verzeichnisse mit seinem Namen ausserhalb des Registers,
 *      Audit M30)
 * @var array{laeuft: bool, grund: string} $codePruefung
 *      PluginManager::uninstallHookPruefung() - läuft beim Löschen Code des
 *      Addons? (Audit N15)
 */
$codePruefung = $codePruefung ?? ['laeuft' => false, 'grund' => 'nicht prüfbar'];

$name = $plugin['manifest']['name'] ?? $slug;
$zeilenGesamt = array_sum($vorschau['tables']);
$dateienGesamt = array_sum($vorschau['directories']);
$hatDaten = $vorschau['tables'] !== [] || $vorschau['directories'] !== [] || $vorschau['settings'] !== [];
$deklariert = (bool)($vorschau['deklariert'] ?? false);
$reste = $vorschau['unregistriert'] ?? ['tables' => [], 'directories' => []];
$hatReste = ($reste['tables'] ?? []) !== [] || ($reste['directories'] ?? []) !== [];
?>
<div class="card">
    <h2 style="margin-top: 0;">🗑️ Addon deinstallieren: <?= htmlspecialchars($name) ?></h2>

    <?php if (($_GET['error'] ?? '') === 'bestaetigung'): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            Der eingegebene Name stimmte nicht mit <code><?= htmlspecialchars($slug) ?></code> überein - es wurde nichts gelöscht.
        </div>
    <?php endif; ?>

    <p style="color: var(--text-muted);">
        <strong>Deaktivieren und Deinstallieren sind zwei verschiedene Dinge.</strong>
        Deaktivieren ist jederzeit umkehrbar und lässt alles stehen - man tut es,
        um einen Fehler einzugrenzen. Hier geht es um die Frage danach: Was soll
        mit den Daten geschehen, die dieses Addon angelegt hat?
    </p>

    <?php if (!$hatDaten && !$hatReste): ?>
        <?php /* Audit M30: keine absolute Zusage mehr. Der Kern kennt nur das
                 Register und eine Namensprüfung - abweichend benannte Tabellen
                 sieht er nicht. */ ?>
        <div class="uninstall-ohne-daten" style="background-color: var(--success-soft-bg); color: var(--success-fg); padding: 1rem; border-radius: 4px; margin: 1rem 0;">
            <?php if ($deklariert): ?>
                Das Addon erklärt in seinem Datenregister, keine eigenen Tabellen,
                Verzeichnisse oder Einstellungen anzulegen.
            <?php else: ?>
                Das Addon hat kein Datenregister hinterlegt. Der Kern hat keine Tabellen
                oder Verzeichnisse mit seinem Namen gefunden - was es unter anderem Namen
                angelegt hat, kann er nicht erkennen.
            <?php endif; ?>
        </div>
    <?php elseif ($hatDaten): ?>
        <h3>Was gelöscht würde</h3>
        <div class="tabelle-scroll">
            <table class="table" style="margin-bottom: 1rem;">
                <thead><tr><th>Art</th><th>Bezeichnung</th><th style="text-align:right;">Umfang</th></tr></thead>
                <tbody>
                <?php foreach ($vorschau['tables'] as $tabelle => $anzahl): ?>
                    <tr>
                        <td>Tabelle</td>
                        <td><code><?= htmlspecialchars((string)$tabelle) ?></code></td>
                        <td style="text-align:right;"><strong><?= number_format((int)$anzahl, 0, ',', '.') ?></strong> Datensätze</td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($vorschau['directories'] as $verzeichnis => $anzahl): ?>
                    <tr>
                        <td>Verzeichnis</td>
                        <td><code><?= htmlspecialchars(basename((string)$verzeichnis)) ?></code></td>
                        <td style="text-align:right;"><strong><?= number_format((int)$anzahl, 0, ',', '.') ?></strong> Dateien</td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($vorschau['settings'] as $schluessel): ?>
                    <tr>
                        <td>Einstellung</td>
                        <td><code><?= htmlspecialchars((string)$schluessel) ?></code></td>
                        <td style="text-align:right;">—</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($hatReste): ?>
        <div class="uninstall-unregistriert" style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <strong>Diese Tabellen/Verzeichnisse tragen den Namen des Addons, stehen aber nicht
            in seinem Datenregister. Sie werden auch mit „Daten löschen“ NICHT entfernt.</strong>
            <ul style="margin: 0.5rem 0 0 1.2rem;">
                <?php foreach ($reste['tables'] ?? [] as $tabelle): ?>
                    <li>Tabelle <code><?= htmlspecialchars((string)$tabelle) ?></code></li>
                <?php endforeach; ?>
                <?php foreach ($reste['directories'] ?? [] as $verzeichnis): ?>
                    <li>Verzeichnis <code><?= htmlspecialchars(basename((string)$verzeichnis)) ?></code></li>
                <?php endforeach; ?>
            </ul>
            <p style="margin: 0.5rem 0 0 0; font-size: 0.9rem;">
                Gelöscht wird nur, was das Addon selbst als seins erklärt. Wer diese Daten
                loswerden will, muss sie von Hand prüfen und entfernen - oder den Anbieter
                des Addons bitten, sie im Datenregister nachzutragen.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($vorschau['abgelehnt'] !== []): ?>
        <div style="background-color: var(--danger-soft-bg); color: var(--danger-fg); padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
            <strong>Diese Angaben des Addons wurden nicht anerkannt und bleiben liegen:</strong>
            <ul style="margin: 0.5rem 0 0 1.2rem;">
                <?php foreach ($vorschau['abgelehnt'] as $grund): ?>
                    <li><?= htmlspecialchars((string)$grund) ?></li>
                <?php endforeach; ?>
            </ul>
            <p style="margin: 0.5rem 0 0 0; font-size: 0.9rem;">
                Ein Addon darf nur eigene Tabellen (Präfix <code>plugin_</code>) und
                Verzeichnisse innerhalb der Installation beanspruchen. Was hier steht,
                müsste von Hand geprüft werden.
            </p>
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/plugins/uninstall">
        <input type="hidden" name="csrf_token" value="<?= App\Router::generateCsrfToken() ?>">
        <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">

        <fieldset style="border: 1px solid var(--border); border-radius: 4px; padding: 1rem; margin-bottom: 1rem;">
            <legend style="padding: 0 0.5rem;">Was soll mit den Daten geschehen?</legend>

            <label style="display: block; margin-bottom: 0.8rem;">
                <input type="radio" name="daten" value="behalten" checked>
                <strong>Daten behalten</strong> — das Addon wird deaktiviert und aus der
                Übersicht genommen, Tabellen und Dateien bleiben unverändert stehen.
                Der Addon-Code (<code>plugins/<?= htmlspecialchars($slug) ?></code>) wird entfernt.
                Wird das Addon später erneut installiert, ist alles wieder da.
            </label>

            <label style="display: block;">
                <input type="radio" name="daten" value="loeschen" id="daten-loeschen">
                <strong>Daten löschen</strong> — <?= $zeilenGesamt > 0 || $dateienGesamt > 0
                    ? 'entfernt <strong>' . number_format($zeilenGesamt, 0, ',', '.') . '</strong> Datensätze und <strong>'
                      . number_format($dateienGesamt, 0, ',', '.') . '</strong> Dateien'
                    : ($hatDaten
                        ? 'entfernt die oben aufgeführten Bestandteile'
                        : 'Registrierte Bestandteile: keine') ?>.
                Der Addon-Code (<code>plugins/<?= htmlspecialchars($slug) ?></code>) wird entfernt.
                <span style="color: var(--danger-fg);">Das lässt sich nicht rückgängig machen.</span>
            </label>

            <p class="uninstall-code-hinweis" style="color: var(--text-muted); font-size: 0.9rem; margin: 0.8rem 0 0 0;">
                <?php if ($codePruefung['laeuft']): ?>
                    Beim Löschen läuft zuerst die Aufräumroutine des Addons (<code>uninstall()</code>,
                    falls vorhanden) &ndash; dabei wird Code des Addons ausgeführt (freigegeben und
                    seit der Freigabe unverändert).
                <?php else: ?>
                    Es wird kein Code des Addons ausgeführt (<?= htmlspecialchars($codePruefung['grund']) ?>).
                    Gelöscht wird nur, was das Addon in seiner <code>plugin.json</code> angibt.
                    Soll seine Aufräumroutine laufen, das Addon vorher aktivieren.
                <?php endif; ?>
            </p>

            <div id="bestaetigung-block" style="display: none; margin-top: 0.8rem; padding-top: 0.8rem; border-top: 1px solid var(--border);">
                <p style="margin: 0 0 0.4rem 0;">
                    Zum Bestätigen den Slug <code><?= htmlspecialchars($slug) ?></code> eintippen:
                </p>
                <input type="text" name="bestaetigung" class="form-control" autocomplete="off" style="max-width: 320px;">
                <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0.4rem 0 0 0;">
                    Ein Häkchen setzt man versehentlich, einen Namen tippt man nicht
                    versehentlich ab.
                </p>
            </div>
        </fieldset>

        <p style="color: var(--text-muted); font-size: 0.9rem;">
            Vor dem Löschen wird eine Sicherung ausgeführt, sofern eine eingerichtet ist.
            Ist keine eingerichtet, wird ohne gelöscht — das steht dann im Protokoll.
        </p>

        <button type="submit" class="btn btn-danger">Deinstallieren</button>
        <a href="/admin/plugins" class="btn btn-secondary">Abbrechen</a>
    </form>
</div>

<script>
// Das Bestätigungsfeld erscheint erst, wenn "Daten löschen" gewählt ist -
// sonst liest es niemand und alle tippen es reflexhaft ab.
(function () {
    var block = document.getElementById('bestaetigung-block');
    document.querySelectorAll('input[name="daten"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            block.style.display = document.getElementById('daten-loeschen').checked ? 'block' : 'none';
        });
    });
})();
</script>
