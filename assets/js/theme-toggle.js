(function () {
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
    }

    function setStoredTheme(theme) {
        try { localStorage.setItem('dental_theme', theme); } catch (e) {}
    }

    function currentTheme() {
        return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    function toggleTheme() {
        var next = currentTheme() === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        setStoredTheme(next);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.theme-toggle-btn').forEach(function (btn) {
            btn.setAttribute('aria-label', 'Toggle dark mode');
            btn.addEventListener('click', toggleTheme);
        });
    });
})();
