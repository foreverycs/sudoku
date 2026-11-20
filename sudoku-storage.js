(function (root) {
  'use strict';

  const GUEST_KEY = 'quiet-sudoku';
  const SETTINGS_KEY = 'quiet-sudoku-settings';

  function create(storage) {
    function readKey(key) {
      try { return JSON.parse(storage.getItem(key) || '{}'); } catch (e) { return {}; }
    }

    function writeKey(key, value) {
      try { storage.setItem(key, JSON.stringify(value)); return true; } catch (e) { return false; }
    }

    function userKey(userId) {
      return 'quiet-sudoku-user-' + String(userId);
    }

    function dailyKey(progressKey) {
      return 'quiet-sudoku-daily-' + (progressKey === GUEST_KEY ? 'guest' : progressKey);
    }

    function hasProgress(data) {
      if (!data || typeof data !== 'object') return false;
      if ((data.completed && data.completed.length) || (data.unlocked | 0) > 1) return true;
      return !!(data.boards && Object.keys(data.boards).length);
    }

    function activate(userId, importGuest, confirmImport) {
      const key = userId ? userKey(userId) : GUEST_KEY;
      const guest = readKey(GUEST_KEY);
      let account = readKey(key);
      if (userId && importGuest && !hasProgress(account) && hasProgress(guest)
          && confirmImport && confirmImport()) {
        writeKey(key, guest);
        account = readKey(key);
      }
      return { key: key, data: account };
    }

    return {
      guestKey: GUEST_KEY,
      read: function (key) { return readKey(key || GUEST_KEY); },
      write: function (value, key) { return writeKey(key || GUEST_KEY, value); },
      settings: {
        read: function () { return readKey(SETTINGS_KEY); },
        write: function (value) { return writeKey(SETTINGS_KEY, value); }
      },
      userKey: userKey,
      dailyKey: dailyKey,
      hasProgress: hasProgress,
      activate: activate
    };
  }

  root.SudokuStorage = { create: create };
  if (typeof module !== 'undefined' && module.exports) module.exports = root.SudokuStorage;
})(typeof window !== 'undefined' ? window : globalThis);
