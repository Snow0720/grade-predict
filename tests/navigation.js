const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const code = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/navigation.js'), 'utf8');
const events = {}, mediaEvents = {}, classes = new Set();
let checks = 0;
function check(ok) { assert.ok(ok); checks++; }
const document = { body: { classList: {
    toggle(name, on) { if (on) classes.add(name); else classes.delete(name); },
    contains(name) { return classes.has(name); }
}}, documentElement: { style: { setProperty(k,v) { this[k] = v; } } },
addEventListener(k,fn) { events[k] = fn; } };
function element() { return {
    events: {}, attrs: {}, inert: false,
    addEventListener(k,fn) { this.events[k] = fn; },
    setAttribute(k,v) { this.attrs[k] = v; },
    focus() { document.activeElement = this; },
    getClientRects() { return [{}]; }
}; }
const toggle = element(), close = element(), link = element(), sidebar = element(), scrim = element(), workspace = element();
sidebar.querySelector = () => link;
sidebar.querySelectorAll = selector => selector === 'a' ? [link] : [close, link];
const header = { getBoundingClientRect() { return { height: 118.5 }; } };
document.getElementById = id => ({ navToggle: toggle, sidebar, navClose: close, navScrim: scrim }[id]);
document.querySelector = selector => selector === '.workspace' ? workspace : header;
const media = { matches: true, addEventListener(k,fn) { mediaEvents[k] = fn; } };
const window = { matchMedia() { return media; }, addEventListener() {} };
vm.runInNewContext(code, { document, window });
check(sidebar.inert && !workspace.inert && toggle.attrs['aria-expanded'] === 'false');
check(document.documentElement.style['--app-header-height'] === '119px');
toggle.events.click();
check(classes.has('nav-open') && !sidebar.inert && workspace.inert);
check(toggle.attrs['aria-expanded'] === 'true' && document.activeElement === close);
let prevented = false;
events.keydown({ key: 'Tab', shiftKey: true, preventDefault() { prevented = true; } });
check(prevented && document.activeElement === link);
events.keydown({ key: 'Tab', shiftKey: false, preventDefault() {} });
check(document.activeElement === close);
events.keydown({ key: 'Escape', preventDefault() {} });
check(!classes.has('nav-open') && !workspace.inert && document.activeElement === toggle);
toggle.events.click(); scrim.events.click();
check(!classes.has('nav-open') && sidebar.inert);
toggle.events.click(); close.events.click();
check(!classes.has('nav-open') && document.activeElement === toggle);
toggle.events.click(); link.events.click();
check(!classes.has('nav-open') && !workspace.inert);
toggle.events.click(); media.matches = false; mediaEvents.change();
check(!classes.has('nav-open') && !sidebar.inert && !workspace.inert);
media.matches = true; mediaEvents.change();
check(sidebar.inert && toggle.attrs['aria-expanded'] === 'false');
console.log('Mobile menu, focus handling, resizing and header measurement: ' + checks + ' checks passed');
