import assert from 'node:assert/strict';
import { test } from 'node:test';
import { smokePassed, stickySnapshotMatches, rectanglesClear, whatsappClearanceObservation } from './qa-post-hygiene-complete-verification.mjs';

const pass = () => ({ env: 'PRODUCCION', home: true, brandsMarquee: true, liveSearch: true, pdp: true,
  seriesBundlesExpand: true, seriesBundlesChip: true, stickyAtcSync: true, whatsAppClearance: true,
  cartDrawer: true, brandsDirectory: true, errors: [] });
test('a console or page error blocks an otherwise successful smoke run', () => {
  const result = pass();
  assert.equal(smokePassed(result), true);
  result.errors.push('[Console Error]: broken checkout');
  assert.equal(smokePassed(result), false);
});
test('missing observations and untested flows cannot pass', () => {
  const result = pass();
  delete result.stickyAtcSync;
  assert.equal(smokePassed(result), false);
  assert.equal(smokePassed({}), false);
});
test('WhatsApp collisions block production; absence is explicitly inapplicable only in local', () => {
  const result = pass();
  result.whatsAppClearance = false;
  assert.equal(smokePassed(result), false);
  result.whatsAppClearance = null;
  assert.equal(smokePassed(result), false);
  result.env = 'LOCAL';
  assert.equal(smokePassed(result), true);
});
const snapshot = () => ({ selected: 3, packMode: true, visible: true, title: 'Pack Dan Da Dan (3 tomos)',
  button: 'Añadir pack (3)', price: '$28.500', expectedPrice: '$28.500' });
test('the sticky bar must reflect the selected quantity and final price', () => {
  assert.equal(stickySnapshotMatches(snapshot()), true);
  assert.equal(stickySnapshotMatches({ ...snapshot(), title: 'Dan Da Dan 1' }), false);
  assert.equal(stickySnapshotMatches({ ...snapshot(), button: 'Añadir pack (2)' }), false);
  assert.equal(stickySnapshotMatches({ ...snapshot(), price: '$30.000' }), false);
  assert.equal(stickySnapshotMatches({ ...snapshot(), expectedPrice: '' }), false);
  assert.equal(stickySnapshotMatches({ ...snapshot(), packMode: false }), false);
  assert.equal(stickySnapshotMatches({ ...snapshot(), visible: false }), false);
  assert.equal(stickySnapshotMatches({ ...snapshot(), selected: 0 }), false);
});
test('WhatsApp clearance measures nonoverlap rather than reading a CSS bottom offset', () => {
  const sticky = { top: 700, bottom: 800, left: 0, right: 1200 };
  assert.equal(rectanglesClear(sticky, { top: 620, bottom: 680, left: 1140, right: 1200 }), true);
  assert.equal(rectanglesClear(sticky, { top: 720, bottom: 780, left: 1140, right: 1200 }), false);
  assert.equal(rectanglesClear(sticky, null), false);
});

test('a hidden WhatsApp widget is unexercised rather than a detected collision', () => {
  const sticky = { top: 700, bottom: 800, left: 0, right: 1200 };
  assert.equal(whatsappClearanceObservation({ whatsapp: null, sticky }), null);
  assert.equal(whatsappClearanceObservation({ whatsapp: { top: 720, bottom: 780, left: 1140, right: 1200 }, sticky }), false);
  assert.equal(whatsappClearanceObservation({ whatsapp: { top: 620, bottom: 680, left: 1140, right: 1200 }, sticky }), true);
});
