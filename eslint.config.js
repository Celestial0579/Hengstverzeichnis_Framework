// ESLint-Konfiguration (Flat Config).
//
// WARUM ES SIE VORHER NICHT GAB - UND WARUM DAS EIN BEFUND IST: Der
// eslint-Hook steht seit Langem in .pre-commit-config.yaml, gepinnt auf v10.
// Ab ESLint v9 ist eslint.config.js die einzige gesuchte Konfigurationsdatei;
// ohne sie bricht eslint mit "couldn't find an eslint.config file" ab. Der
// Hook ist also nie durchgelaufen, sondern immer gescheitert - und weil
// pre-commit nirgends in der CI lief, hat das niemand gesehen. Ein Prüfschritt,
// der immer rot ist, den aber niemand ausführt, ist von einem, der nicht
// existiert, nicht zu unterscheiden.
//
// Der Umfang ist bewusst klein: Die Anwendung liefert fünf handgeschriebene
// Skripte in public/js aus, kein Build, keine Module, keine Abhängigkeiten.
// Geprüft wird, was in dieser Lage tatsächlich Fehler findet - undefinierte
// Bezeichner, offensichtlich Kaputtes -, nicht der Stil.

'use strict';

module.exports = [
    {
        // Mitgelieferte Fremdbibliothek ("QRCode for Javascript library",
        // siehe Dateikopf). Sie wird unverändert ausgeliefert und nicht hier
        // gepflegt - sie zu linten hiesse, Befunde zu erzeugen, die niemand
        // beheben darf, und den Prüfschritt damit dauerhaft rot zu halten.
        // Genau daran ist der Hook vorher schon gescheitert.
        ignores: ['public/js/qrcode.js'],
    },
    {
        files: ['public/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'script',
            globals: {
                // Browser-Umgebung. Bewusst als ausdrückliche Liste statt über
                // ein globals-Paket: Das wäre eine Abhängigkeit mehr, nur um
                // fünf Dateien zu prüfen.
                window: 'readonly',
                document: 'readonly',
                navigator: 'readonly',
                location: 'readonly',
                localStorage: 'readonly',
                sessionStorage: 'readonly',
                console: 'readonly',
                fetch: 'readonly',
                FormData: 'readonly',
                URL: 'readonly',
                URLSearchParams: 'readonly',
                setTimeout: 'readonly',
                clearTimeout: 'readonly',
                setInterval: 'readonly',
                clearInterval: 'readonly',
                requestAnimationFrame: 'readonly',
                CustomEvent: 'readonly',
                Event: 'readonly',
                HTMLFormElement: 'readonly',
                HTMLElement: 'readonly',
                Image: 'readonly',
                MutationObserver: 'readonly',
                IntersectionObserver: 'readonly',
                AbortController: 'readonly',
                matchMedia: 'readonly',
                alert: 'readonly',
                confirm: 'readonly',
                getComputedStyle: 'readonly',
                // qrcode.js bringt seine eigene globale Klasse mit.
                QRCode: 'writable',
            },
        },
        linterOptions: {
            reportUnusedDisableDirectives: true,
        },
        rules: {
            // Findet Tippfehler in Bezeichnern - der eigentliche Nutzen hier.
            'no-undef': 'error',
            // vars: 'local' - die Skripte definieren globale Funktionen, die
            // aus onclick-Attributen der Views heraus aufgerufen werden
            // (zoomPedigree, setGenerations ...). eslint sieht diese Aufrufe
            // nicht und hielte sie fuer tot. Innerhalb einer Funktion wird
            // weiterhin auf ungenutzte Variablen geprueft - dort ist der
            // Befund aussagekraeftig.
            'no-unused-vars': ['error', { args: 'none', vars: 'local' }],
            'no-redeclare': 'error',
            'no-dupe-keys': 'error',
            'no-dupe-args': 'error',
            'no-unreachable': 'error',
            'no-cond-assign': 'error',
            'no-constant-condition': 'error',
            'no-empty': ['error', { allowEmptyCatch: true }],
            'no-fallthrough': 'error',
            'valid-typeof': 'error',
            'use-isnan': 'error',
            // Kein eval, kein Function-Konstruktor, kein document.write - in
            // einer Anwendung mit CSP-Ambitionen ist das keine Stilfrage.
            'no-eval': 'error',
            'no-implied-eval': 'error',
            'no-new-func': 'error',
            'no-script-url': 'error',
        },
    },
];
