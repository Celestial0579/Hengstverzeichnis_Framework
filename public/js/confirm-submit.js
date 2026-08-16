// Sicherheitsabfrage vor dem Absenden eines Formulars.
//
// Ersetzt die früheren onsubmit="return confirm('...')"-Attribute, in die der
// Text per PHP hineininterpoliert wurde. Die Kombination aus addslashes() und
// htmlspecialchars() sah nach doppelter Absicherung aus, war aber die falsche
// für den Zielkontext: addslashes() maskiert Anführungszeichen und
// Rückwärtsschrägstriche, kennt aber keine Zeilenumbrüche. Ein echter
// Zeilenumbruch im Wert - etwa im Namen eines Plugins - beendete das
// JavaScript-Stringliteral mitten im Aufruf, der Handler war damit
// syntaktisch kaputt und tat gar nichts mehr. Die Sicherheitsabfrage vor dem
// Aktivieren fremden Codes verschwand also genau dann, wenn der Name
// ungewöhnlich war.
//
// Über ein data-Attribut gibt es das Problem nicht: Der Wert durchläuft
// htmlspecialchars() für einen HTML-Attributkontext - dafür ist die Funktion
// gemacht - und der Browser reicht ihn als fertigen String weiter. Kein
// JavaScript wird mehr aus Daten zusammengesetzt.
//
// Nebeneffekt: ein Inline-Handler weniger auf dem Weg zu einer CSP ohne
// 'unsafe-inline'.
(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        var message = form.getAttribute('data-confirm');
        if (!message) {
            return;
        }

        if (!window.confirm(message)) {
            event.preventDefault();
        }
    }, true);
})();
