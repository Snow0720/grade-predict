(function () {
    'use strict';
    const key = 'spcc-color-theme';
    const root = document.documentElement;
    const system = window.matchMedia('(prefers-color-scheme: dark)');
    let preference = null;
    try { preference = localStorage.getItem(key); } catch (_) {}
    if (preference !== 'light' && preference !== 'dark') preference = null;
    function apply(theme) {
        root.dataset.theme = theme;
        root.style.colorScheme = theme;
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            const dark = theme === 'dark';
            button.setAttribute('aria-checked', String(dark));
            button.setAttribute('aria-label', 'Dark mode');
            button.title = 'Switch to ' + (dark ? 'light' : 'dark') + ' mode';
            button.querySelector('.theme-toggle-label').textContent = dark ? 'Dark mode on' : 'Dark mode off';
        });
        const meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.content = theme === 'dark' ? '#0f172a' : '#000058';
    }
    // Runs in the head before the first paint, including on login and public pages.
    apply(preference || (system.matches ? 'dark' : 'light'));
    function mount() {
        if (document.querySelector('[data-theme-toggle]')) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'theme-toggle';
        button.setAttribute('data-theme-toggle', '');
        button.setAttribute('role', 'switch');
        button.innerHTML = '<span class="theme-switch-stars" aria-hidden="true"></span><span class="theme-switch-thumb" aria-hidden="true"></span><span class="theme-switch-cloud" aria-hidden="true"></span><span class="theme-toggle-label"></span>';
        const actions = document.querySelector('.top-actions, .toolbar');
        if (actions) actions.prepend(button);
        else { button.classList.add('theme-toggle-floating'); document.body.append(button); }
        button.addEventListener('click', function () {
            preference = root.dataset.theme === 'dark' ? 'light' : 'dark';
            try { localStorage.setItem(key, preference); } catch (_) {}
            apply(preference);
        });
        apply(root.dataset.theme);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
    else mount();
    window.addEventListener('storage', function (event) {
        if (event.key !== key && event.key !== null) return;
        preference = event.newValue === 'dark' || event.newValue === 'light' ? event.newValue : null;
        apply(preference || (system.matches ? 'dark' : 'light'));
    });
    function followSystem() { if (!preference) apply(system.matches ? 'dark' : 'light'); }
    if (system.addEventListener) system.addEventListener('change', followSystem);
    else if (system.addListener) system.addListener(followSystem);
})();
