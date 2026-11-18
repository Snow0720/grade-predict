const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const code = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/theme.js'), 'utf8');
let checks = 0;
function check(value) { assert.ok(value); checks++; }
function createPage(saved, darkSystem = false, blockedStorage = false, header = true) {
    const events = {}, buttonEvents = {}, systemEvents = {};
    let stored = saved;
    const button = {
        attrs: {}, classList: { add() {} },
        setAttribute(k, v) { this.attrs[k] = v; },
        getAttribute(k) { return this.attrs[k]; },
        querySelector(name) { return name.includes('label') ? this.label : this.icon; },
        addEventListener(name, fn) { buttonEvents[name] = fn; },
        label: {}, icon: {}
    };
    const root = { dataset: {}, style: {} }, meta = {};
    let mounted = false, placement = '';
    const document = {
        documentElement: root, readyState: 'loading',
        querySelectorAll() { return mounted ? [button] : []; },
        querySelector(q) {
            if (q === '[data-theme-toggle]') return mounted ? button : null;
            if (q.includes('theme-color')) return meta;
            if (q === '.top-actions, .toolbar') return header ? { prepend() { mounted = true; placement = 'header'; } } : null;
            return null;
        },
        createElement() { return button; },
        body: { append() { mounted = true; placement = 'floating'; } },
        addEventListener(name, fn) { events[name] = fn; }
    };
    const system = { matches: darkSystem, addEventListener(name, fn) { systemEvents[name] = fn; } };
    const localStorage = {
        getItem() { if (blockedStorage) throw new Error('blocked'); return stored; },
        setItem(k, value) { if (blockedStorage) throw new Error('blocked'); stored = value; }
    };
    const window = { matchMedia() { return system; }, addEventListener(name, fn) { events[name] = fn; } };
    vm.runInNewContext(code, { document, window, localStorage });
    return { root, meta, button, events, buttonEvents, system, systemEvents, mount() { events.DOMContentLoaded(); }, stored() { return stored; }, placement() { return placement; } };
}
const page = createPage(null);
check(page.root.dataset.theme === 'light'); // Before DOM is ready: no wrong-theme first paint.
page.mount();
check(page.placement() === 'header' && page.button.attrs['aria-checked'] === 'false');
page.buttonEvents.click();
check(page.root.dataset.theme === 'dark' && page.stored() === 'dark');
check(page.button.label.textContent === 'Dark mode on' && page.button.attrs['aria-label'] === 'Dark mode');
check(page.meta.content === '#0f172a' && page.root.style.colorScheme === 'dark');
const nextPage = createPage(page.stored());
check(nextPage.root.dataset.theme === 'dark');
page.buttonEvents.click();
check(page.root.dataset.theme === 'light' && page.stored() === 'light');
page.events.storage({ key: 'spcc-color-theme', newValue: 'dark' });
check(page.root.dataset.theme === 'dark');
const auto = createPage(null, true);
check(auto.root.dataset.theme === 'dark');
auto.system.matches = false; auto.systemEvents.change();
check(auto.root.dataset.theme === 'light');
auto.mount(); auto.buttonEvents.click();
auto.system.matches = false; auto.systemEvents.change();
check(auto.root.dataset.theme === 'dark'); // Explicit choice overrides device changes.
const unavailable = createPage(null, false, true, false);
unavailable.mount(); unavailable.buttonEvents.click();
check(unavailable.root.dataset.theme === 'dark' && unavailable.placement() === 'floating');
const invalid = createPage('invalid', true);
check(invalid.root.dataset.theme === 'dark');
page.events.storage({ key: null, newValue: null });
check(page.root.dataset.theme === 'light');
console.log(`Appearance initialization, persistence, toggling, tab sync and fallback: ${checks} checks passed`);
