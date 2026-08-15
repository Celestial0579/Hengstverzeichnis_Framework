// Darkmode-Umschalter (#91): Klick-Handler + Icon-Sync. Getrennt vom
// FOUC-Präventions-Script im <head> (siehe dort) - dieses hier braucht
// ein bereits vorhandenes DOM (Button), jenes muss vor dem ersten
// Rendern laufen.
(function () {
    var toggleBtn = document.getElementById('theme-toggle');
    if (!toggleBtn) return;

    function isDarkActive() {
        var explicit = document.documentElement.getAttribute('data-theme');
        if (explicit === 'dark') return true;
        if (explicit === 'light') return false;
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    function syncIcon() {
        toggleBtn.textContent = isDarkActive() ? '☀️' : '🌙';
    }

    window.__toggleTheme = function () {
        var next = isDarkActive() ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('theme', next);
        syncIcon();
    };

    syncIcon();
})();
