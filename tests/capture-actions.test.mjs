import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

function setup() {
  let document;
  const element = () => ({
    hidden: false, disabled: false, dataset: {}, style: {}, listeners: {},
    addEventListener(type, handler) { this.listeners[type] = handler; },
    setAttribute() {},
    closest() { return null; },
    focus() { document.activeElement = this; },
    getBoundingClientRect() { return { top: 200, bottom: 230, right: 400, width: 190, height: 160 }; },
  });
  const later = element();
  const archiveButton = element();
  const trashButton = element();
  const form = (button) => ({
    ...element(),
    querySelector: () => button,
    matches: () => false,
  });
  const archive = form(archiveButton);
  const trash = form(trashButton);
  const items = [later, archiveButton, trashButton];
  const menu = {
    ...element(), hidden: true,
    querySelector: (selector) => ({
      '[data-menu-later]': later,
      '[data-menu-archive]': archive,
      '[data-menu-trash]': trash,
    })[selector],
    querySelectorAll: (selector) => selector === '[role="menuitem"]' ? items : [],
    contains: (target) => items.includes(target),
  };
  const trigger = {
    ...element(), dataset: { captureId: 'capture-4', captureStatus: 'inbox' },
    closest: () => trigger,
  };
  document = {
    ...element(), activeElement: {},
    querySelector: () => menu,
  };
  const requests = [];
  const transitions = [];
  const window = {
    ...element(), innerHeight: 900, innerWidth: 1200,
    Catch: {
      captureCollection: { transition: async (...args) => transitions.push(args) },
      notify() {},
    },
  };
  const context = vm.createContext({
    document, window, queueMicrotask,
    FormData: class {},
    fetch: async (url) => {
      requests.push(url);
      return { ok: true, json: async () => ({ capture_status: 'trash' }) };
    },
  });
  const source = readFileSync(new URL('../public/assets/js/capture-actions.js', import.meta.url), 'utf8');
  vm.runInContext(source.replaceAll('export function', 'function') + '\ninitCaptureActions();', context);
  document.listeners.click({ target: trigger });
  return { menu, trash, trashButton, document, requests, transitions };
}

test('pointer blur without a new focus target still submits the selected capture action', async () => {
  const ui = setup();
  assert.equal(ui.menu.hidden, false);
  // Safari/WebKit can leave focus on the body when clicking a menu button.
  ui.document.activeElement = {};
  ui.menu.listeners.focusout({ relatedTarget: null });
  await Promise.resolve();
  ui.trash.listeners.submit({ preventDefault() {} });
  await new Promise(setImmediate);
  assert.deepEqual(ui.requests, ['/captures/capture-4/delete']);
  assert.equal(ui.transitions.length, 1);
  assert.equal(ui.transitions[0][0][0], 'capture-4');
  assert.equal(ui.transitions[0][1].status, 'trash');
});

test('moving focus within the menu keeps it open; moving focus outside closes it', () => {
  const ui = setup();
  ui.menu.listeners.focusout({ relatedTarget: ui.trashButton });
  assert.equal(ui.menu.hidden, false);
  ui.menu.listeners.focusout({ relatedTarget: {} });
  assert.equal(ui.menu.hidden, true);
});
