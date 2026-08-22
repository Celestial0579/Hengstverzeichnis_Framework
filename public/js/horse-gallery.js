// public/js/horse-gallery.js
//
// Lightbox der Pferde-Galerie (#339).
//
// Ausgelagert und `defer` geladen, wie theme-toggle.js und
// confirm-submit.js: Ein <script>-Block mitten im Seiteninhalt liesse sich
// nur mit einer Ausnahme in der Content-Security-Policy betreiben, und die
// gaebe es dann fuer die ganze Seite.
//
// Keine Bildadresse im Markup ausser der, die ohnehin im <img> steht - die
// Lightbox zeigt genau dieselbe Datei noch einmal gross.
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
        gross.src = bild.currentSrc || bild.src;
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
