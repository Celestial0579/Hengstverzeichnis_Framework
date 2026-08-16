document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('catalog-filter-form');
    const grid = document.getElementById('catalog-grid');
    const badge = document.getElementById('hit-count-badge');
    const spinner = document.getElementById('loading-spinner');
    const resetBtn = document.getElementById('btn-reset-filters');

    let debounceTimer = null;

    function performAsyncFetch() {
        const formData = new FormData(form);
        const params = new URLSearchParams(formData);
        
        // Remove empty values
        for (const [key, value] of Array.from(params.entries())) {
            if (!value || value.trim() === '') {
                params.delete(key);
            }
        }

        const queryString = params.toString();
        const fetchUrl = '/katalog?' + queryString;

        // Show spinner and dim grid slightly
        if (spinner) spinner.style.display = 'inline-block';
        if (grid) grid.style.opacity = '0.5';

        fetch(fetchUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                grid.innerHTML = data.cards_html;
                badge.textContent = data.count_text;

                // Update URL history without page reload
                const newUrl = window.location.pathname + (queryString ? '?' + queryString : '');
                window.history.pushState({ path: newUrl }, '', newUrl);

                // Show/hide reset button
                if (resetBtn) {
                    resetBtn.style.display = queryString ? 'inline-block' : 'none';
                }
            }
        })
        .catch(err => console.error('Async Filter Error:', err))
        .finally(() => {
            if (spinner) spinner.style.display = 'none';
            if (grid) grid.style.opacity = '1';
        });
    }

    // Debounced listener for typing
    form.querySelectorAll('input[type="text"], input[type="number"]').forEach(input => {
        input.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(performAsyncFetch, 250);
        });
    });

    // Immediate listener for select dropdowns
    form.querySelectorAll('select').forEach(select => {
        select.addEventListener('change', performAsyncFetch);
    });

    // Prevent full form submit reload
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        performAsyncFetch();
    });
});
