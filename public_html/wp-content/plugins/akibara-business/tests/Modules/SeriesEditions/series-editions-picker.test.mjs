import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import { test } from 'node:test';
import vm from 'node:vm';

// Execute the actual inline script in both HTML parser phases. This catches the
// regression where PHP prints assets before the picker and binds zero inputs.
const source = readFileSync(new URL('../../../src/Modules/SeriesEditions/SeriesEditions.php', import.meta.url), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];

class Element extends EventTarget {
  hidden = false;
  checked = false;
  disabled = false;
  value = '';
  textContent = '';
  attributes = {};
  events = {};
  classList = { toggle() {} };
  addEventListener(name, listener, options) {
    this.events[name] = (this.events[name] || 0) + 1;
    super.addEventListener(name, listener, options);
  }
  getAttribute(name) { return this.attributes[name] ?? null; }
  setAttribute(name, value) { this.attributes[name] = value; }
  closest() { return this.item; }
}

function picker() {
  const root = new Element();
  root.attributes['data-max'] = '8';
  root.boxes = ['From Hell', 'Naruto', 'Naruto Shippuden', 'Tókyō Ghoul'].map((name, index) => {
    const box = new Element();
    box.item = new Element();
    box.item.textContent = name;
    box.checked = index === 0;
    return box;
  });
  root.filter = new Element();
  root.counter = new Element();
  root.empty = new Element();
  root.groups = [new Element(), new Element()];
  root.querySelectorAll = selector => selector === 'input[type="checkbox"]' ? root.boxes : root.groups;
  root.querySelector = selector => ({
    '[data-akb-editions-count]': root.counter,
    '[data-akb-editions-empty]': root.empty,
    '.akb-editions-picker__filter': root.filter,
  })[selector];
  return root;
}

function documentAt(readyState) {
  const document = new Element();
  document.readyState = readyState;
  document.roots = [];
  document.querySelectorAll = () => document.roots;
  return document;
}

function filter(root, query) {
  root.filter.value = query;
  root.filter.dispatchEvent(new Event('input'));
  return root.boxes.filter(box => !box.item.hidden).map(box => box.item.textContent);
}

test('parser waits for markup, matches Naruto only, preserves hidden checked choices', () => {
  const document = documentAt('loading');
  vm.runInNewContext(script, { document });
  const root = picker();
  document.roots.push(root);
  document.readyState = 'interactive';
  document.dispatchEvent(new Event('DOMContentLoaded'));
  assert.deepEqual(filter(root, 'nArUtO'), ['Naruto', 'Naruto Shippuden']);
  assert.equal(root.boxes[0].checked, true, 'Filtering must not clear an existing relationship');
  assert.equal(root.boxes[0].disabled, false, 'A hidden selected input must still submit');
  assert.equal(root.counter.textContent, '1 de 8 seleccionadas');
  assert.deepEqual(filter(root, 'tokyo'), ['Tókyō Ghoul']);
  assert.deepEqual(filter(root, 'sin coincidencias'), []);
  assert.equal(root.empty.hidden, false);
  assert.deepEqual(filter(root, ''), ['From Hell', 'Naruto', 'Naruto Shippuden', 'Tókyō Ghoul']);
  assert.equal(root.empty.hidden, true);
  assert.equal(root.boxes[0].checked, true);
});

test('already loaded document initializes immediately and repeat execution never duplicates listeners', () => {
  const document = documentAt('complete');
  const root = picker();
  document.roots.push(root);
  vm.runInNewContext(script, { document });
  vm.runInNewContext(script, { document });
  assert.deepEqual(filter(root, 'Naruto'), ['Naruto', 'Naruto Shippuden']);
  assert.equal(root.filter.events.input, 1);
  for (const box of root.boxes) assert.equal(box.events.change, 1);
  root.boxes[1].checked = true;
  root.boxes[1].dispatchEvent(new Event('change'));
  assert.equal(root.counter.textContent, '2 de 8 seleccionadas');
  filter(root, 'From Hell');
  assert.equal(root.boxes[1].checked, true);
});
