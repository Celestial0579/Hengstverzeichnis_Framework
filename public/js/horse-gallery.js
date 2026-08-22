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
// Kachel ein Vorschaubild sein kann, traegt sie ihre Grossfassung in
// `data-gross`; ohne das zeigte die Lightbox ein hochskaliertes
// Vorschaubild, und der Schalter fuer die Vorschaubilder haette die
// Grossansicht mit verschlechtert. Faellt das Attribut weg, gilt wie
// bisher die Adresse aus dem <img>.
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

    function oeffnen(bild) {
        var o = overlayHolen();
        var gross = o.querySelector('img');
        gross.src = bild.getAttribute('data-gross') || bild.currentSrc || bild.src;
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
