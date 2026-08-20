// public/js/horse-search.js
//
// Wiederverwendbares Suchfeld für Pferde im Adminbereich (#341, Addons#125).
//
// Sieben Addons brachten bis v0.7 je eine eigene Kopie dieses Blocks mit -
// samt eigener Entprellung, eigener Fehlerbehandlung und eigenem Umgang mit
// dem Wettlauf zwischen zwei schnell aufeinanderfolgenden Anfragen. Sechs
// davon kannten den Wettlauf nicht: Tippt jemand zügig, kann die Antwort auf
// "Ro" NACH der Antwort auf "Roga" eintreffen und die Liste wieder
// verschlechtern. Hier wird jede Antwort verworfen, die nicht zur zuletzt
// gestellten Anfrage gehört.
//
// Verwendung in einem Addon:
//
//     <input class="hv-pferdesuche" data-ziel="pferd_id" data-geschlecht="stallion">
//     <select name="pferd_id" id="pferd_id"></select>
//     <script src="/js/horse-search.js"></script>
//
// Alle Felder mit der Klasse `hv-pferdesuche` werden beim Laden verdrahtet;
// später eingefügte Felder erfasst HvPferdesuche.verdrahten(element).

(function () {
    'use strict';

    var ENDPUNKT = '/admin/horses/search';
    var WARTEZEIT = 250; // ms Entprellung - ein Tastendruck ist keine Anfrage

    function verdrahten(feld) {
        if (!feld || feld.dataset.hvVerdrahtet === '1') {
            return;
        }
        feld.dataset.hvVerdrahtet = '1';

        var ziel = document.getElementById(feld.dataset.ziel || '');
        if (!ziel) {
            // Ohne Ziel gibt es nichts zu befüllen. Kein stiller Abbruch:
            // Das ist ein Einbaufehler und soll in der Konsole stehen.
            console.warn('hv-pferdesuche: kein Zielelement "' + feld.dataset.ziel + '" gefunden');
            return;
        }

        var timer = null;
        var laufendeAnfrage = 0;

        feld.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { suchen(feld, ziel); }, WARTEZEIT);
        });

        function suchen(feld, ziel) {
            var begriff = feld.value.trim();
            if (begriff.length < 2) {
                leeren(ziel);
                return;
            }

            var params = new URLSearchParams({ q: begriff });
            // data-geschlecht: stallion | mare | gelding - der Filter, den der
            // Verpaarungsrechner braucht (#54).
            if (feld.dataset.geschlecht) {
                params.set('geschlecht', feld.dataset.geschlecht);
            }
            if (feld.dataset.rolle) {
                params.set('rolle', feld.dataset.rolle);
            }
            if (feld.dataset.nurMitFarbe === '1') {
                params.set('nur_mit_farbe', '1');
            }

            var meine = ++laufendeAnfrage;
            fetch(ENDPUNKT + '?' + params.toString(), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(function (antwort) {
                    if (!antwort.ok) {
                        throw new Error('HTTP ' + antwort.status);
                    }
                    return antwort.json();
                })
                .then(function (treffer) {
                    // Veraltete Antwort verwerfen - siehe Kopfkommentar.
                    if (meine !== laufendeAnfrage) {
                        return;
                    }
                    fuellen(ziel, treffer);
                })
                .catch(function (fehler) {
                    if (meine !== laufendeAnfrage) {
                        return;
                    }
                    // Die Liste NICHT stehen lassen: Eine Auswahl, die zu einem
                    // alten Suchbegriff gehört, sieht aus wie ein Ergebnis.
                    leeren(ziel);
                    console.warn('hv-pferdesuche: ' + fehler.message);
                });
        }
    }

    function leeren(ziel) {
        ziel.innerHTML = '';
        var leer = document.createElement('option');
        leer.value = '';
        leer.textContent = '-- kein Treffer --';
        ziel.appendChild(leer);
    }

    function fuellen(ziel, treffer) {
        ziel.innerHTML = '';
        var leer = document.createElement('option');
        leer.value = '';
        leer.textContent = treffer.length ? '-- bitte wählen --' : '-- kein Treffer --';
        ziel.appendChild(leer);

        treffer.forEach(function (t) {
            var option = document.createElement('option');
            option.value = t.id;
            // textContent, nicht innerHTML: Der Pferdename kommt aus der
            // Datenbank und darf kein Markup einschleusen.
            option.textContent = t.label;
            ziel.appendChild(option);
        });
    }

    window.HvPferdesuche = { verdrahten: verdrahten };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.hv-pferdesuche').forEach(verdrahten);
    });
})();
