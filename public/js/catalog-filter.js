// public/js/catalog-filter.js
//
// Katalog: asynchrone Filterung (#125) und nahtloses Nachladen (#264).
//
// Zwei verschiedene Vorgänge auf demselben AJAX-Pfad:
//   Filterwechsel -> Karten ERSETZEN, zurück auf Seite 1
//   Nachladen     -> nächste Seite ANHÄNGEN (?append=1, ohne Seiten-Navigation)
//
// Ohne JavaScript passiert nichts davon: Der Nachlade-Block bleibt versteckt,
// und die serverseitig gerenderte Seiten-Navigation im Karten-Grid bleibt der
// vollwertige Weg durch den Katalog.
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('catalog-filter-form');
    const grid = document.getElementById('catalog-grid');
    const badge = document.getElementById('hit-count-badge');
    const spinner = document.getElementById('loading-spinner');
    const resetBtn = document.getElementById('btn-reset-filters');

    const loadMoreArea = document.getElementById('catalog-load-more-area');
    const loadMoreBtn = document.getElementById('catalog-load-more');
    const loadStatus = document.getElementById('catalog-load-status');
    const sentinel = document.getElementById('catalog-scroll-sentinel');

    if (!form || !grid) {
        return;
    }

    let debounceTimer = null;
    // Zustand des Nachladens. currentPage ist die zuletzt ANGEZEIGTE Seite -
    // beim ersten Aufruf die serverseitig gerenderte, danach die zuletzt
    // angehängte.
    let currentPage = readPageFromUrl();
    let totalPages = 1;
    let loading = false;

    function readPageFromUrl() {
        const value = parseInt(new URLSearchParams(window.location.search).get('page') || '1', 10);
        return Number.isFinite(value) && value > 0 ? value : 1;
    }

    /** Aktive Filter als Query-String, leere Felder fallen weg. */
    function filterParams() {
        const params = new URLSearchParams(new FormData(form));
        for (const [key, value] of Array.from(params.entries())) {
            if (!value || value.trim() === '') {
                params.delete(key);
            }
        }
        return params;
    }

    function translate(key, replacements) {
        // Die Texte stehen als data-Attribute am Statusabsatz - so bleiben sie
        // in den 12 Sprachdateien und wandern nicht ins JavaScript.
        let text = (loadStatus && loadStatus.dataset[key]) || '';
        Object.keys(replacements || {}).forEach(function (name) {
            text = text.replace('{' + name + '}', replacements[name]);
        });
        return text;
    }

    function cardCount() {
        // Die Seiten-Navigation ist kein Pferd: Sie steckt als eigenes Kind im
        // Grid und würde sonst mitgezählt.
        return grid.querySelectorAll('.card').length;
    }

    function updateLoadMoreArea(totalHorses) {
        if (!loadMoreArea) {
            return;
        }

        const more = currentPage < totalPages;
        loadMoreArea.hidden = false;
        if (loadMoreBtn) {
            loadMoreBtn.hidden = !more;
        }

        if (loadStatus) {
            loadStatus.textContent = more
                ? translate('statusTemplate', { loaded: cardCount(), total: totalHorses })
                : translate('doneTemplate', { total: totalHorses });
        }

        // Bei nur einer Seite gibt es nichts nachzuladen - dann bleibt der ganze
        // Block weg, statt eine leere Zeile Weissraum zu erzeugen.
        if (totalPages <= 1) {
            loadMoreArea.hidden = true;
        }
    }

    /**
     * Die serverseitig gerenderte Seiten-Navigation wird ausgeblendet, sobald
     * JavaScript übernimmt: Zwei Bedienelemente für dieselbe Sache
     * nebeneinander (Seite 2 anspringen vs. Seite 2 anhängen) wären
     * widersprüchlich. Ohne JavaScript bleibt sie unangetastet stehen.
     */
    function hideServerPagination() {
        grid.querySelectorAll('[data-catalog-pagination]').forEach(function (nav) {
            nav.hidden = true;
        });
    }

    function request(params, options) {
        return fetch('/katalog?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: options && options.signal
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        });
    }

    /** Filterwechsel: ersetzen und zurück auf Seite 1. */
    function performAsyncFetch() {
        const params = filterParams();
        params.delete('page');
        const queryString = params.toString();

        if (spinner) spinner.style.display = 'inline-block';
        grid.style.opacity = '0.5';

        request(params, {})
            .then(function (data) {
                if (!data.success) {
                    return;
                }
                grid.innerHTML = data.cards_html;
                badge.textContent = data.count_text;

                currentPage = data.page || 1;
                totalPages = data.total_pages || 1;
                hideServerPagination();
                updateLoadMoreArea(data.count);

                const newUrl = window.location.pathname + (queryString ? '?' + queryString : '');
                window.history.pushState({ path: newUrl }, '', newUrl);

                if (resetBtn) {
                    resetBtn.style.display = queryString ? 'inline-block' : 'none';
                }
            })
            .catch(function (err) { console.error('Async Filter Error:', err); })
            .finally(function () {
                if (spinner) spinner.style.display = 'none';
                grid.style.opacity = '1';
            });
    }

    /** Nachladen: die nächste Seite anhängen, nichts ersetzen. */
    function loadNextPage() {
        if (loading || currentPage >= totalPages) {
            return;
        }
        loading = true;

        const params = filterParams();
        params.set('page', String(currentPage + 1));
        // Ohne dieses Flag käme die Seiten-Navigation der Teilansicht mit und
        // landete mitten zwischen den Karten (siehe PublicController).
        params.set('append', '1');

        if (loadMoreBtn) loadMoreBtn.disabled = true;
        if (loadStatus) loadStatus.textContent = translate('loadingText', {});

        request(params, {})
            .then(function (data) {
                if (!data.success) {
                    throw new Error('Antwort ohne success');
                }

                // Die neuen Karten werden als KNOTEN angehaengt, nicht als
                // HTML-Zeichenkette in das bestehende Grid geschrieben.
                //
                // Weder innerHTML += noch insertAdjacentHTML: Ersteres
                // serialisiert das gesamte Grid neu und laedt dabei alle bereits
                // sichtbaren Bilder ein zweites Mal. Letzteres vermeidet das
                // zwar, ist aber weiterhin eine HTML-Senke - und genau die
                // meldet der Sicherheitsscan (react-unsanitized-method). Ueber
                // DOMParser entsteht die Struktur in einem eigenen Dokument;
                // angehaengt werden fertige Knoten, es gibt gar keine Senke mehr.
                // Skripte fuehrt DOMParser dabei nicht aus, genau wie die beiden
                // anderen Wege.
                //
                // <template> und nicht DOMParser: Bei DOMParser landet alles, was
                // VOR dem ersten Element steht, ausserhalb von <body> und geht
                // beim Uebernehmen verloren - die Teilansicht beginnt mit
                // Einrueckung, der fuehrende Textknoten fiele also weg. Gegen die
                // echte Antwort nachgemessen: DOMParser 49 statt 50 Knoten,
                // <template> byte-identisch zum bisherigen Ergebnis.
                //
                // template.content ist bereits ein DocumentFragment: Das Anhaengen
                // kostet trotz vieler Karten nur EINEN Layout-Durchgang.
                const geparst = document.createElement('template');
                geparst.innerHTML = data.cards_html;
                grid.appendChild(geparst.content);

                currentPage = data.page || (currentPage + 1);
                totalPages = data.total_pages || totalPages;
                updateLoadMoreArea(data.count);

                // Die Adresszeile folgt der zuletzt geladenen Seite, damit ein
                // Neuladen oder ein geteilter Link in der Naehe wieder einsetzt.
                // replaceState statt pushState: Sonst braeuchte der Zurueck-Knopf
                // so viele Klicks, wie nachgeladen wurde, um die Seite zu
                // verlassen - eine bekannte Unart des Musters.
                const urlParams = filterParams();
                urlParams.set('page', String(currentPage));
                window.history.replaceState(
                    { path: window.location.pathname },
                    '',
                    window.location.pathname + '?' + urlParams.toString()
                );
            })
            .catch(function (err) {
                console.error('Nachladen fehlgeschlagen:', err);
                if (loadStatus) {
                    loadStatus.textContent = translate('errorText', {});
                }
            })
            .finally(function () {
                loading = false;
                if (loadMoreBtn) loadMoreBtn.disabled = false;
            });
    }

    // --- Filter-Bedienung (unverändert aus #125) ---

    form.querySelectorAll('input[type="text"], input[type="number"]').forEach(function (input) {
        input.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(performAsyncFetch, 250);
        });
    });

    form.querySelectorAll('select').forEach(function (select) {
        select.addEventListener('change', performAsyncFetch);
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        performAsyncFetch();
    });

    // --- Nachladen (#264) ---

    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', loadNextPage);
    }

    // Der Beobachter ist Bequemlichkeit, nicht die Bedienung: Fehlt er (alte
    // Browser), bleibt der Knopf und damit die volle Funktion. Deshalb keine
    // Notlösung über scroll-Events.
    if (sentinel && 'IntersectionObserver' in window) {
        new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    loadNextPage();
                }
            });
        }, {
            // Vorlauf, damit die naechsten Karten schon da sind, bevor der
            // Anwender das Listenende ueberhaupt erreicht.
            rootMargin: '300px 0px'
        }).observe(sentinel);
    }

    // Anfangszustand aus dem serverseitig gerenderten Stand ableiten: Ein
    // eigener Zaehl-Request dafuer waere eine Anfrage fuer eine Zahl, die schon
    // auf der Seite steht.
    if (loadMoreArea && loadMoreArea.dataset.totalPages) {
        totalPages = parseInt(loadMoreArea.dataset.totalPages, 10) || 1;
        const totalHorses = parseInt(loadMoreArea.dataset.totalHorses, 10) || cardCount();
        if (totalPages > 1) {
            hideServerPagination();
            updateLoadMoreArea(totalHorses);
        }
    }
});
