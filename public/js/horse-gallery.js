// public/js/horse-gallery.js
//
// Lightbox der Pferde-Galerie (#339).
//
// Ausgelagert und `defer` geladen, wie theme-toggle.js und
// confirm-submit.js: Ein <script>-Block mitten im Seiteninhalt liesse sich
// nur mit einer Ausnahme in der Content-Security-Policy betreiben, und die
// gaebe es dann fuer die ganze Seite.
//
// Die Lightbox zeigt das ORIGINAL, nicht die Kachel (#397). Seit die
// Kachel ein Vorschaubild sein kann, braucht die Grossansicht eine eigene
// Adresse - sonst zeigte sie ein hochskaliertes Vorschaubild, und der
// Schalter fuer die Vorschaubilder haette die Grossansicht mit
// verschlechtert.
//
// Aus dem Markup kommt dafuer NUR die Kennung (`data-medium`), nie eine
// fertige Adresse. Die wird hier aus einem festen Muster und einer
// geprueften Ziffernfolge gebaut. Eine Adresse aus dem DOM in ein
// src-Attribut zu schreiben ist genau das, was CodeQL als
// "DOM text reinterpreted as HTML" meldet - und die Meldung hat recht,
// auch wenn der Wert heute aus der eigenen Vorlage stammt: Der naechste,
// der das Attribut befuellt, weiss davon nichts mehr.
(function () {
    'use strict';

    var overlay = null;

    function overlayHolen() {
        if (overlay) {
            return overlay;
        }
        overlay = document.createElement('div');
        overlay.className = 'horse-lightbox';
        overlay.innerHTML = '<img src="" alt="">';
        overlay.addEventListener('click', schliessen);
        document.body.appendChild(overlay);
        return overlay;
    }

    // Die Adresse der Grossansicht, gebaut statt gelesen.
    function grossAdresse(bild) {
        var kennung = bild.getAttribute('data-medium') || '';
        if (/^[0-9]+$/.test(kennung)) {
            return '/media/horse-media?id=' + kennung;
        }
        // Ohne Kennung bleibt es bei dem, was der Browser ohnehin geladen
        // hat - dieselbe Datei wie vor #397.
        return bild.currentSrc || bild.src;
    }

    function oeffnen(bild) {
        var o = overlayHolen();
        var gross = o.querySelector('img');
        gross.src = grossAdresse(bild);
        gross.alt = bild.alt || '';
        o.setAttribute('data-offen', '1');
    }

    function schliessen() {
        if (overlay) {
            overlay.removeAttribute('data-offen');
            // Quelle leeren: Sonst haelt der Browser das grosse Bild im
            // Speicher, und bei einer Galerie mit dreissig Fotos summiert
            // sich das.
            overlay.querySelector('img').src = '';
        }
    }

    document.addEventListener('click', function (e) {
        var bild = e.target.closest('[data-lightbox]');
        if (bild) {
            oeffnen(bild);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            schliessen();
        }
    });
})();
