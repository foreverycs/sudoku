'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const read = file => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
const js = read('sudoku.js');
const section = (start, end) => js.slice(js.indexOf(start), js.indexOf(end, js.indexOf(start)));
const board = (value = 0, revision = 1) => ({ cells: Array(81).fill(value), notes: [], seconds: 10, revision, ts: 100, pending: true });
const cloud = value => ({ cells: String(value).repeat(81), notes: {}, seconds: 10, revision: 1, updatedMs: 90 });

function runtime(local, remote = {}, responder) {
  const calls = [];
  const context = vm.createContext({
    currentUser: { id: 12 }, saveKey: 'user-12', level: 1, N: 100,
    completed: new Set(), best: {}, unlocked: 1, saved: { boards: local },
    cellsToStr: cells => cells.join(''), strToCells: cells => cells.split('').map(Number),
    objToNotes: () => [], notesToObj: notes => Object.fromEntries(notes.map((n, i) => [i, n]).filter(([, n]) => n.length)),
    clamp: (v, min, max) => Math.max(min, Math.min(max, v)),
    persist() {}, render() {}, say() {}, window: { confirm: () => false },
    apiCall: async (route, method, payload) => {
      if (route === 'progress') return { progress: remote };
      if (route === 'me/stats') return { bestByLevel: {} };
      calls.push({ route, payload });
      return responder ? responder(route, payload) : { revision: 2 };
    }
  });
  vm.runInContext(section('  const progressPushes', '  function queuePush()'), context);
  vm.runInContext(section('  async function syncProgress()', '  async function initAuth()'), context);
  return { context, calls, push: lv => context.pushSavedBoard(lv), sync: () => context.syncProgress() };
}

test('每日题面不返回答案，客户端只在服务端确认后完成', () => {
  const daily = read('api/handlers/daily.php');
  const getter = daily.slice(daily.indexOf('function daily_get()'), daily.indexOf('function daily_history()'));
  assert.doesNotMatch(getter, /\['answer'\]/);
  assert.doesNotMatch(js, /d\.answer/);
  const submit = section('  async function settleDaily(', '  // ===== 成绩分享');
  assert.ok(submit.indexOf("await apiCall('daily/complete'") < submit.indexOf('dailyWin();'));
  assert.match(submit, /catch \(e\).*每日挑战未确认完成/);
  assert.match(read('api/index.php'), /daily_complete'.*20, 60/);
});

test('开局和结算都依据服务端通关记录检查关卡解锁', () => {
  const game = read('api/handlers/game.php');
  assert.match(game, /SELECT COALESCE\(MAX\(level\),0\) FROM game_records WHERE user_id = \?/);
  for (const [start, end] of [['function game_start()', 'function daily_start()'], ['function game_complete()', 'function achievements_list()']]) {
    assert.match(game.slice(game.indexOf(start), game.indexOf(end)), /require_unlocked_level\(\$uid, \$level\)/);
  }
});

test('普通 API 不调用迁移，迁移入口仅命令行且失败返回非零', () => {
  for (const file of ['api/index.php', 'api/handlers/game.php', 'api/handlers/progress.php', 'api/maintenance.php']) {
    assert.doesNotMatch(read(file), /ensure_security_schema\(/);
  }
  const migrate = read('api/migrate.php');
  assert.match(migrate, /PHP_SAPI !== 'cli'/);
  assert.match(migrate, /ensure_security_schema\(\)/);
  assert.match(migrate, /exit\(1\)/);
  assert.match(read('api/security.php'), /PHP_SAPI !== 'cli'/);
});

test('缓存检查项目与站点真实路径，无安全目录时不落盘', () => {
  const source = read('api/handlers/daily.php');
  const cache = source.slice(source.indexOf('function daily_cache_dir()'), source.indexOf('function daily_get()'));
  assert.match(cache, /realpath\(__DIR__ \. '\/\.\.\/\.\.'\)/);
  assert.match(cache, /realpath\(\$_SERVER\['DOCUMENT_ROOT'\]\)/);
  assert.match(cache, /if \(!\$safe\) continue/);
  assert.match(cache, /return ''/);
  assert.match(cache, /if \(\$dir !== ''\)/);
  assert.match(cache, /tempnam\(/);
  assert.match(cache, /rename\(/);
});

test('拉取旧云端不会覆盖离线填写和擦除，上传后清除待同步标记', async () => {
  for (const value of [0, 7]) {
    const local = board(value);
    const rt = runtime({ 1: local }, { 1: cloud(3) });
    await rt.sync();
    await Promise.all(vm.runInContext('Array.from(progressPushes.values())', rt.context));
    assert.equal(rt.context.saved.boards[1].cells[0], value);
    assert.equal(rt.calls[0].payload.cells, String(value).repeat(81));
    assert.equal(rt.context.saved.boards[1].pending, false);
    assert.equal(rt.context.saved.boards[1].revision, 2);
  }
});

test('干净本地存档可接受云端新版本', async () => {
  const local = board(1);
  local.pending = false;
  const remote = cloud(2); remote.revision = 3;
  const rt = runtime({ 1: local }, { 1: remote });
  await rt.sync();
  assert.equal(rt.context.saved.boards[1].cells[0], 2);
  assert.equal(rt.calls.length, 0);
});

test('旧版没有 pending 标记的离线存档按更新时间保护', async () => {
  const local = board(7);
  delete local.pending;
  const remote = cloud(3); remote.revision = 2;
  const rt = runtime({ 1: local }, { 1: remote });
  await rt.sync();
  await Promise.all(vm.runInContext('Array.from(progressPushes.values())', rt.context));
  assert.equal(rt.context.saved.boards[1].cells[0], 7);
  assert.equal(rt.calls[0].payload.cells, '7'.repeat(81));
});

test('失败上传保留 pending 和本地笔记删除', async () => {
  const local = board(0);
  const rt = runtime({ 1: local }, {}, () => { throw new Error('离线'); });
  await assert.rejects(rt.push(1));
  assert.equal(local.pending, true);
  assert.equal(local.notes.length, 0);
});

test('上传期间继续编辑会串行补传，响应只更新原关卡', async () => {
  let resolve;
  const rt = runtime({ 1: board(1), 2: board(2) }, {}, () => new Promise(r => { resolve = r; }));
  const request = rt.push(1);
  assert.equal(rt.push(1), request);
  rt.context.saved.boards[1] = { ...board(3), ts: 101 };
  rt.context.level = 2;
  resolve({ revision: 2 });
  await new Promise(r => setImmediate(r));
  assert.equal(rt.calls.length, 2);
  assert.equal(rt.calls[1].route, 'progress/1');
  assert.equal(rt.calls[1].payload.baseRevision, 2);
  resolve({ revision: 3 });
  await request;
  assert.equal(rt.context.saved.boards[1].pending, false);
  assert.equal(rt.context.saved.boards[2].revision, 1);
});

test('账号切换后旧请求响应不修改新账号存档', async () => {
  let resolve;
  const rt = runtime({ 1: board(1) }, {}, () => new Promise(r => { resolve = r; }));
  const request = rt.push(1);
  const next = board(5);
  rt.context.saveKey = 'user-27'; rt.context.saved = { boards: { 1: next } };
  resolve({ revision: 20 });
  await request;
  assert.equal(next.revision, 1);
  assert.equal(next.pending, true);
});

test('取消冲突确认不覆盖两端，也不会重复弹窗', async () => {
  const local = board(0);
  const rt = runtime({ 1: local }, {}, () => { throw { status: 409, extra: { revision: 4 } }; });
  let prompts = 0;
  rt.context.window.confirm = () => { prompts++; return false; };
  await assert.rejects(rt.push(1));
  await rt.push(1);
  assert.equal(local.cells[0], 0);
  assert.equal(local.pending, true);
  assert.equal(prompts, 1);
  assert.equal(rt.calls.length, 1);
});

test('确认冲突后使用新 revision 上传，保留本地擦除而非补回云端数字', async () => {
  const local = board(0);
  let attempts = 0;
  const rt = runtime({ 1: local }, {}, () => {
    if (++attempts === 1) throw { status: 409, extra: { revision: 4, cells: '9'.repeat(81) } };
    return { revision: 5 };
  });
  rt.context.window.confirm = () => true;
  await rt.push(1);
  assert.equal(rt.calls[1].payload.baseRevision, 4);
  assert.equal(rt.calls[1].payload.cells, '0'.repeat(81));
  assert.equal(local.pending, false);
});

function dailyRuntime(responder) {
  let wins = 0;
  const messages = [];
  const context = vm.createContext({
    dailyReview: false, dailyMode: true, dailySavedDate: '2026-11-21', saveKey: 'user-12',
    board: Array(81).fill(1), seconds: 10, dailyAttemptKey: 'attempt', dailyAttemptStartP: null,
    dailyData: {}, currentUser: { id: 12 }, shareCtx: null, playAcc: 10000, playStart: 1,
    cellsToStr: cells => cells.join(''), apiCall: responder,
    ensureAttemptStarted: async () => 'attempt',
    $: () => ({}), fmt: String, say: msg => messages.push(msg), saveDailyLocal() {},
    sfx() {}, dailyWin() { wins++; }, updateUserUI() {}, renderLevelCard() {},
    renderModalGain() {}, loadAchievements() {}, loadDailyCard() {}, toast() {}
  });
  vm.runInContext(section('  let dailySubmitting = false;', '  // ===== 成绩分享'), context);
  return { context, messages, wins: () => wins };
}

test('每日验证失败不会弹出成功或停止计时，且允许修正后重试', async () => {
  let attempts = 0;
  const rt = dailyRuntime(async () => {
    if (++attempts === 1) throw new Error('棋盘验证失败');
    return { seconds: 20, exp: 40, level: 1 };
  });
  await rt.context.settleDaily(10);
  assert.equal(rt.wins(), 0);
  assert.equal(rt.context.playStart, 1);
  assert.match(rt.messages[0], /棋盘验证失败/);
  await rt.context.settleDaily(10);
  assert.equal(rt.wins(), 1);
  assert.equal(rt.context.seconds, 20);
  assert.equal(rt.context.dailyAttemptKey, '');
});

test('每日提交期间禁止重复请求，切换关卡后忽略迟到成功响应', async () => {
  let resolve, calls = 0;
  const rt = dailyRuntime(() => { calls++; return new Promise(r => { resolve = r; }); });
  const first = rt.context.settleDaily(10);
  await rt.context.settleDaily(10);
  assert.equal(calls, 1);
  rt.context.dailyMode = false;
  resolve({ seconds: 20 });
  await first;
  assert.equal(rt.wins(), 0);
});
