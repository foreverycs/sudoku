'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const sudokuStorage = require('../sudoku-storage.js');

function memoryStorage() {
  const values = new Map();
  return {
    getItem: key => values.has(key) ? values.get(key) : null,
    setItem: (key, value) => values.set(key, value),
    values
  };
}

test('account saves are isolated and guest progress remains untouched', () => {
  const raw = memoryStorage();
  const store = sudokuStorage.create(raw);
  const guest = { level: 3, completed: [1, 2], boards: { 3: { seconds: 40 } } };
  store.write(guest);

  const a = store.activate(12, false);
  store.write({ level: 5, completed: [1, 2, 3, 4], boards: {} }, a.key);
  const b = store.activate(27, false);

  assert.equal(a.key, 'quiet-sudoku-user-12');
  assert.equal(b.key, 'quiet-sudoku-user-27');
  assert.deepEqual(b.data, {});
  assert.deepEqual(store.read(a.key).completed, [1, 2, 3, 4]);
  assert.deepEqual(store.read(), guest);
});

test('guest import is opt-in and never deletes guest progress', () => {
  const raw = memoryStorage();
  const store = sudokuStorage.create(raw);
  const guest = { level: 2, completed: [1], boards: { 2: { seconds: 15 } } };
  store.write(guest);

  const declined = store.activate(12, true, () => false);
  assert.deepEqual(declined.data, {});
  assert.deepEqual(store.read(), guest);

  const accepted = store.activate(27, true, () => true);
  assert.deepEqual(accepted.data, guest);
  assert.deepEqual(store.read(), guest);
});

test('existing account save is not overwritten by guest import', () => {
  const raw = memoryStorage();
  const store = sudokuStorage.create(raw);
  const guest = { completed: [1], boards: { 2: { seconds: 15 } } };
  const account = { completed: [1, 2], boards: { 3: { seconds: 8 } } };
  store.write(guest);
  store.write(account, store.userKey(12));

  const active = store.activate(12, true, () => {
    assert.fail('Import confirmation should not be shown when account already has progress');
  });
  assert.deepEqual(active.data, account);
  assert.deepEqual(store.read(), guest);
});

test('sound setting storage is separate from all progress saves', () => {
  const raw = memoryStorage();
  const store = sudokuStorage.create(raw);
  store.write({ completed: [1] }, store.userKey(12));
  store.settings.write({ soundOn: false });
  store.write({ completed: [1, 2] }, store.userKey(27));

  assert.deepEqual(store.settings.read(), { soundOn: false });
  assert.deepEqual(store.read(store.userKey(12)), { completed: [1] });
  assert.deepEqual(store.read(store.userKey(27)), { completed: [1, 2] });
});

test('daily puzzle saves are isolated with their matching progress account', () => {
  const store = sudokuStorage.create(memoryStorage());
  assert.equal(store.dailyKey(store.guestKey), 'quiet-sudoku-daily-guest');
  assert.equal(store.dailyKey(store.userKey(12)), 'quiet-sudoku-daily-quiet-sudoku-user-12');
  assert.notEqual(store.dailyKey(store.userKey(12)), store.dailyKey(store.userKey(27)));
});
