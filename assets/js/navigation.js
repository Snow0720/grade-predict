(function () {
    'use strict';
    const toggle = document.getElementById('navToggle');
    const sidebar = document.getElementById('sidebar');
    if (!toggle || !sidebar) return;
    const close = document.getElementById('navClose');
    const scrim = document.getElementById('navScrim');
    const workspace = document.querySelector('.workspace');
    const mobile = window.matchMedia('(max-width: 980px)');
    function setOpen(open, restoreFocus) {
        open = mobile.matches && open;
        document.body.classList.toggle('nav-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        sidebar.inert = mobile.matches && !open;
        sidebar.setAttribute('aria-hidden', String(mobile.matches && !open));
        if (workspace) workspace.inert = open;
        if (open) (close || sidebar.querySelector('a')).focus();
        else if (restoreFocus && mobile.matches) toggle.focus();
    }
    toggle.addEventListener('click', function () {
        setOpen(!document.body.classList.contains('nav-open'), true);
    });
    if (close) close.addEventListener('click', function () { setOpen(false, true); });
    if (scrim) scrim.addEventListener('click', function () { setOpen(false, true); });
    sidebar.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () { setOpen(false, false); });
    });
    document.addEventListener('keydown', function (event) {
        if (!document.body.classList.contains('nav-open')) return;
        if (event.key === 'Escape') { event.preventDefault(); setOpen(false, true); return; }
        if (event.key !== 'Tab') return;
        const items = Array.from(sidebar.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),[tabindex="0"]'))
            .filter(function (item) { return item.getClientRects().length > 0; });
        if (!items.length) return;
        const first = items[0], last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    function sync() { setOpen(false, false); }
    if (mobile.addEventListener) mobile.addEventListener('change', sync);
    else mobile.addListener(sync);
    sync();
    const header = document.querySelector('.topbar');
    function sizeHeader() {
        if (header) document.documentElement.style.setProperty('--app-header-height', Math.ceil(header.getBoundingClientRect().height) + 'px');
    }
    if (header && window.ResizeObserver) new ResizeObserver(sizeHeader).observe(header);
    window.addEventListener('resize', sizeHeader);
    sizeHeader();
})();
