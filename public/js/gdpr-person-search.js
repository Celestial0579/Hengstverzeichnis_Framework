// public/js/gdpr-person-search.js
//
// Manuelle Personensuche in der DSGVO-Verwaltung (#266).
//
// Der Automatch vergleicht wörtlich und scheitert an abweichender Schreibweise,
// Tippfehlern oder einem geänderten Namen. Dieses Skript liefert den Rückfallweg:
// Eingabe -> serverseitige Suche mit Trefferdeckel -> Auswahl -> dieselben
// Anonymisieren-/Löschen-Formulare, die bei einem Automatch-Treffer erscheinen.
//
// Die Formulare stehen samt CSRF-Token fertig im HTML; hier wird ausschließlich
// die person_id eingetragen und der Block eingeblendet. Ein Token liesse sich
// clientseitig ohnehin nicht erzeugen.
(function () {
    'use strict';

    var MIN_LENGTH = 2;
    var DEBOUNCE_MS = 250;

    document.querySelectorAll('.gdpr-manual-search').forEach(function (container) {
        var input = container.querySelector('.gdpr-person-query');
        var datalist = container.querySelector('datalist');
        var status = container.querySelector('.gdpr-search-status');
        var selection = container.querySelector('.gdpr-manual-selection');
        var label = container.querySelector('.gdpr-selected-label');
        var link = container.querySelector('.gdpr-selected-link');
        var idFields = container.querySelectorAll('.gdpr-selected-id');

        if (!input || !datalist || !selection) {
            return;
        }

        // Zuletzt geladene Treffer, damit die Auswahl gegen eine echte ID
        // aufgelöst wird statt gegen das, was jemand ins Feld getippt hat.
        var lastResults = [];
        var timer = null;
        var pending = null;

        function optionValue(person) {
            // Der <datalist>-Wert landet im Eingabefeld; die ID muss darin
            // stehen, weil der Browser die gewählte <option> nicht mitteilt.
            return person.name + ' (#' + person.id + ')';
        }

        function clearSelection() {
            selection.hidden = true;
            idFields.forEach(function (field) { field.value = ''; });
        }

        function applySelection(person) {
            var suffix = person.is_deleted ? ' — ACHTUNG: bereits gelöscht markiert' : '';
            label.textContent = person.name + ' (ID #' + person.id + ', '
                + person.horse_count + ' verknüpfte Pferde/Rollen)' + suffix;
            idFields.forEach(function (field) { field.value = String(person.id); });
            if (link) {
                link.href = '/admin/persons/edit?id=' + encodeURIComponent(person.id);
            }
            selection.hidden = false;
        }

        function matchTypedValue() {
            var typed = input.value.trim();
            var found = null;

            var byId = typed.match(/#(\d+)\s*$/);
            if (byId) {
                var wanted = parseInt(byId[1], 10);
                lastResults.forEach(function (person) {
                    if (person.id === wanted) { found = person; }
                });
            }

            if (found) {
                applySelection(found);
            } else {
                clearSelection();
            }
        }

        function render(results) {
            lastResults = results;
            datalist.innerHTML = '';

            results.forEach(function (person) {
                var option = document.createElement('option');
                option.value = optionValue(person);
                // Zweitangabe zur Unterscheidung gleicher Namen - genau der Fall,
                // in dem eine Fehlauswahl die falsche Person löschen würde.
                var extra = person.email || person.contact_info || '';
                option.label = extra !== ''
                    ? extra + ' · ' + person.horse_count + ' Pferde/Rollen'
                    : person.horse_count + ' Pferde/Rollen';
                datalist.appendChild(option);
            });

            if (results.length === 0) {
                status.textContent = 'Keine Treffer.';
            } else if (results.length >= 50) {
                status.textContent = results.length + ' Treffer (Anzeige begrenzt) — Suchbegriff eingrenzen.';
            } else {
                status.textContent = results.length + (results.length === 1 ? ' Treffer.' : ' Treffer.');
            }

            matchTypedValue();
        }

        function search() {
            var q = input.value.trim();

            // Der Browser schreibt beim Auswählen den vollen Wert samt "(#12)"
            // ins Feld; danach noch einmal zu suchen wäre nur Last.
            if (/#(\d+)\s*$/.test(q)) {
                matchTypedValue();
                return;
            }

            clearSelection();

            if (q.length < MIN_LENGTH) {
                datalist.innerHTML = '';
                lastResults = [];
                status.textContent = '';
                return;
            }

            status.textContent = 'Suche läuft …';

            // Laufende Anfrage abbrechen: Sonst kann die Antwort auf einen alten
            // Suchbegriff die auf den neuen überholen und überschreiben.
            if (pending) {
                pending.abort();
            }
            pending = new AbortController();

            fetch('/admin/gdpr/search-persons?q=' + encodeURIComponent(q), {
                signal: pending.signal,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(render)
                .catch(function (error) {
                    if (error.name === 'AbortError') {
                        return;
                    }
                    // Nicht stillschweigend scheitern: Ein leeres Feld sähe aus
                    // wie "keine Treffer" und die Anfrage bliebe unbearbeitet
                    // liegen - bei laufender Frist der schlechteste Ausgang.
                    status.textContent = 'Suche fehlgeschlagen (' + error.message
                        + '). Bitte erneut versuchen oder den Datensatz über die Personenverwaltung suchen.';
                });
        }

        input.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(search, DEBOUNCE_MS);
        });
        input.addEventListener('change', matchTypedValue);
    });
})();
