'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const read = file => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
const profile = read('api/handlers/profile.php');
const friends = read('api/handlers/friends.php');
const game = read('api/handlers/game.php');
const daily = read('api/handlers/daily.php');
const maintenance = read('api/maintenance.php');
const router = read('api/index.php');
const html = read('index.html');
const js = read('sudoku.js');

test('restore endpoint writes progress and daily rows but never client awards or scores', () => {
  const importer = profile.slice(profile.indexOf('function me_import()'), profile.indexOf('function me_storage_clear()'));
  assert.match(importer, /INSERT INTO game_progress/);
  assert.match(importer, /INSERT IGNORE INTO daily_records/);
  assert.match(importer, /dailySignature/);
  assert.match(importer, /skippedUntrustedDaily/);
  assert.doesNotMatch(importer, /INSERT[^\n]+game_records/);
  assert.doesNotMatch(importer, /UPDATE users SET exp/);
  assert.doesNotMatch(importer, /user_achievements/);
});

test('friend requests have accept/reject routes and pending state', () => {
  assert.match(friends, /friends\/accept|function friends_accept/);
  assert.match(friends, /function friends_reject/);
  assert.match(friends, /status = 0/);
  assert.match(router, /friends\/accept/);
  assert.match(router, /friends\/reject/);
  assert.match(html, /friend-incoming/);
  assert.match(html, /friend-outgoing/);
  assert.match(friends, /requested_by/);
  assert.match(friends, /requested_by = \?/);
});

test('friend request ownership is independent from sorted pair ids', () => {
  const request = (uid, target) => ({ user_a: Math.min(uid, target), user_b: Math.max(uid, target), requested_by: uid, status: 0 });
  const canAccept = (row, uid) => row.status === 0 && row.requested_by !== uid;
  const canReject = (row, uid) => row.status === 0 && row.requested_by !== uid;
  const canCancel = (row, uid) => row.status === 0 && row.requested_by === uid;
  for (const [sender, receiver] of [[20, 5], [5, 20]]) {
    const row = request(sender, receiver);
    assert.equal(canAccept(row, sender), false);
    assert.equal(canAccept(row, receiver), true);
    assert.equal(canReject(row, sender), false);
    assert.equal(canReject(row, receiver), true);
    assert.equal(canCancel(row, sender), true);
    assert.equal(canCancel(row, receiver), false);
  }
});

test('leaderboards accept only all/week/month periods and return the selected period', () => {
  for (const source of [game, friends]) {
    assert.match(source, /array\('all', 'week', 'month'\)/);
    assert.match(source, /'period'\s*=>\s*\$period/);
  }
  assert.match(html, /data-period="week"/);
  assert.match(html, /data-period="month"/);
  assert.match(js, /period=' \+ leaderPeriod/);
});

test('backup manager requires preview and confirmation before restore or clearing', () => {
  assert.match(html, /id="backup-preview"/);
  assert.match(html, /id="backup-import"[^>]*disabled/);
  assert.match(js, /window\.confirm\('将恢复/);
  assert.match(js, /window\.confirm\('将删除账号的云端棋盘进度/);
  assert.match(router, /me\/storage\/clear/);
});

test('daily history review is completed-only, read-only, and does not expose the answer', () => {
  const review = daily.slice(daily.indexOf('function daily_puzzle_review()'), daily.indexOf('function validate_daily_solution'));
  assert.match(review, /SELECT seconds FROM daily_records/);
  assert.match(review, /puzzle\['cells'\]/);
  assert.match(review, /readOnly/);
  assert.doesNotMatch(review, /puzzle\['answer'\]/);
  assert.match(js, /function enter\(d\) \{\s*if \(dailyReview\) \{ say\('历史回顾为只读模式'\); return; \}/);
  assert.match(js, /function erase\(\) \{\s*if \(dailyReview\) \{ say\('历史回顾为只读模式'\); return; \}/);
  assert.match(js, /daily-review-mode/);
  assert.match(html, /daily-review-action/);
});

test('attempt cleanup is CLI-only, dry-run by default, and retains unfinished attempts', () => {
  assert.match(maintenance, /PHP_SAPI !== 'cli'/);
  assert.match(maintenance, /in_array\('--apply'/);
  assert.match(maintenance, /completed_at IS NOT NULL AND completed_at < \?/);
  assert.match(maintenance, /dry-run/);
});
