(() => {
  'use strict';
  // ?reset=1 清空本地进度（保留登录 token），用于异常恢复/重新开始
  if (new URLSearchParams(location.search).has('reset')) {
    try { localStorage.removeItem('quiet-sudoku'); } catch { /* 忽略 */ }
    location.replace(location.pathname + '?v=' + Date.now());
    return;
  }
  const DATA = window.SUDOKU_DATA || [];
  const N = DATA.length || 100;
  const labels = ['晨间练习', '轻松起步', '数字漫步', '午后片刻', '静心推理', '思路交汇', '专注时分', '层层深入', '终极挑战', '大师之路'];

  const store = {
    read() { try { return JSON.parse(localStorage.getItem('quiet-sudoku') || '{}'); } catch { return {}; } },
    write(d) { try { localStorage.setItem('quiet-sudoku', JSON.stringify(d)); } catch { /* 禁用时不保存 */ } }
  };
  let saved = store.read();
  const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
  let unlocked = clamp(saved.unlocked || 1, 1, N);
  let completed = new Set(Array.isArray(saved.completed) ? saved.completed.filter(x => x >= 1 && x <= N) : []);
  let level = clamp(saved.level || 1, 1, unlocked);
  let best = (saved.best && typeof saved.best === 'object') ? saved.best : {};
  let challenge = !!saved.challenge;

  let board = [], given = [], notes = [], selected = -1, noteMode = false, history = [], seconds = 0, timerId = null, sayTimer = null, playAcc = 0, playStart = 0;
  let solution = [];

  // ===== 每日挑战（dailyMode 复用棋盘，不写 saved.boards / 不占 completed） =====
  const DAILY_KEY = 'quiet-sudoku-daily';
  const dailyStore = { read() { try { return JSON.parse(localStorage.getItem(DAILY_KEY) || '{}'); } catch { return {}; } }, write(d) { try { localStorage.setItem(DAILY_KEY, JSON.stringify(d)); } catch { /* 禁用时不保存 */ } } };
  let dailyMode = false, dailyData = null, dailyDone = false, dailySavedDate = '';

  const $ = id => document.getElementById(id);
  const boardEl = $('board');

  // ===== 账号 & 云同步（阶段二） =====
  const API_BASE = '/api/index.php?r=';
  const TOKEN_KEY = 'sudoku_token';
  const tokenGet = () => { try { return localStorage.getItem(TOKEN_KEY) || ''; } catch { return ''; } };
  const tokenSet = t => { try { t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY); } catch { /* 隐私模式忽略 */ } };
  let currentUser = null;
  const cellsToStr = a => a.map(x => x | 0).join('');
  const notesToObj = n => { const o = {}; for (let i = 0; i < n.length; i++) { const s = n[i]; const arr = (s instanceof Set) ? [...s] : (Array.isArray(s) ? s : []); if (arr.length) o[i] = arr; } return o; };
  const strToCells = s => String(s || '').split('').map(x => { x = +x; return (x >= 0 && x <= 9) ? x : 0; });
  const objToNotes = o => { const a = Array.from({ length: 81 }, () => []); if (o && typeof o === 'object') for (const k in o) if (Array.isArray(o[k])) a[k] = o[k].filter(x => x >= 1 && x <= 9); return a; };
  async function apiCall(route, method, data) {
    const headers = { 'Content-Type': 'application/json' };
    const t = tokenGet();
    if (t) headers.Authorization = 'Bearer ' + t;
    const opts = { method: method || 'GET', headers };
    if (data !== undefined && data !== null) opts.body = JSON.stringify(data);
    const res = await fetch(API_BASE + route, opts);
    let json = null;
    try { json = await res.json(); } catch { /* 空响应 */ }
    if (!json) { const e = new Error('网络异常或服务器无响应'); e.status = 0; throw e; }
    if (!json.ok) { const e = new Error(json.error || '请求失败'); e.status = res.status; throw e; }
    return json.data;
  }

  const fmt = s => String((s / 60) | 0).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
  const difficulty = l => ['入门', '入门', '简单', '简单', '中等', '中等', '困难', '困难', '挑战', '挑战'][Math.min(9, Math.floor((l - 1) / 10))];

  function persist() {
    store.write({ unlocked, completed: [...completed], level, boards: saved.boards || {}, challenge, best });
  }
  function saveGame() {
    if (dailyMode) { saveDailyLocal(); return; }
    saved.boards = saved.boards || {};
    saved.boards[level] = { cells: board.slice(), notes: notes.map(s => [...s]), seconds, ts: Date.now() };
    persist();
    queuePush();
  }

  function loadLevel(l) {
    dailyMode = false; dailyData = null; dailyDone = false; dailySavedDate = '';
    $('modal-title').textContent = '恭喜完成本关！';
    $('modal-replay').style.display = '';
    $('modal-share').style.display = 'none';
    $('modal-next').textContent = '下一关 ›';
    level = clamp(l, 1, N);
    const d = DATA[level - 1];
    const clue = d ? d.c.split('').map(Number) : [];
    solution = d ? d.a.split('').map(Number) : [];
    const st = saved.boards ? saved.boards[level] : null;
    const cb = st && st.cells;
    board = (Array.isArray(cb) && cb.length === 81 && cb.every(x => x >= 0 && x <= 9)) ? cb.slice() : clue.slice();
    given = clue.map(x => x > 0);
    const nb = st && st.notes;
    notes = Array.from({ length: 81 }, (_, i) => Array.isArray(nb && nb[i]) ? new Set(nb[i].filter(x => x >= 1 && x <= 9)) : new Set());
    history = []; selected = -1;
    seconds = Math.min(1e5, Math.max(0, (st && st.seconds) | 0));
    noteMode = false;
    $('notes').classList.remove('active');
    persist(); render();
    // 进入关卡不立即计时，首次操作（填数）才开始
    playAcc = seconds * 1000;
    playStart = 0;
    updateTimer();
    loadLeaderboard(level);
  }

  function startTimer() {
    clearInterval(timerId);
    playAcc = seconds * 1000;
    playStart = Date.now();
    updateTimer();
    timerId = setInterval(() => { updateTimer(); if (seconds > 0 && seconds % 5 === 0) saveGame(); }, 1000);
  }
  function updateTimer() {
    seconds = Math.min(1e5, Math.floor((playAcc + (playStart ? Date.now() - playStart : 0)) / 1000));
    $('timer').textContent = fmt(seconds);
  }
  function pauseTimer() {
    if (!timerId || !playStart) return;
    playAcc += Date.now() - playStart;
    playStart = 0;
    updateTimer();
  }
  function resumeTimer() {
    if (!timerId || playStart) return;
    playStart = Date.now();
    updateTimer();
  }

  function render() {
    if (dailyMode && dailyData) {
      $('level-top').textContent = '每日';
      $('title').textContent = '今日挑战';
      $('difficulty').textContent = '每日一题';
    } else {
      $('level-top').textContent = String(level).padStart(2, '0');
      $('title').textContent = labels[Math.min(9, Math.floor((level - 1) / 10))];
      $('difficulty').textContent = difficulty(level);
    }
    $('completed').textContent = completed.size;
    $('progress-text').textContent = completed.size + ' / ' + N;
    $('progress-bar').style.width = completed.size + '%';
    $('best').textContent = best[level] ? ('本关最佳 ' + fmt(best[level])) : '';

    paintBoard();

    renderNumbers();
    renderLevels();
    renderLeaderStats();
    renderChapters();
  }

  function renderLeaderStats() {
    const box = $('leader-stats');
    if (!box) return;
    $('ls-completed').textContent = completed.size;
    $('ls-best').textContent = best[level] ? fmt(best[level]) : '--';
    $('ls-unlocked').textContent = unlocked;
  }

  function renderChapters() {
    const root = $('chapter-list');
    if (!root) return;
    root.innerHTML = '';
    for (let c = 0; c < 10; c++) {
      const start = c * 10 + 1;
      let done = 0;
      for (let i = start; i < start + 10; i++) if (completed.has(i)) done++;
      const name = document.createElement('span');
      name.className = 'ch-name';
      name.textContent = labels[c];
      const bar = document.createElement('span');
      bar.className = 'ch-bar';
      const fill = document.createElement('i');
      fill.style.width = (done * 10) + '%';
      bar.append(fill);
      const cnt = document.createElement('span');
      cnt.className = 'ch-count';
      cnt.textContent = done + '/10';
      const row = document.createElement('div');
      row.className = 'ch-row';
      row.append(name, bar, cnt);
      root.append(row);
    }
  }

  let cellEls = [];
  let boardBuilt = false;
  let numEls = [], levelEls = [];
  function buildBoard() {
    boardEl.innerHTML = '';
    cellEls = [];
    for (let i = 0; i < 81; i++) {
      const b = document.createElement('button');
      b.className = 'cell';
      b.setAttribute('role', 'gridcell');
      b.onclick = () => { selected = i; paintBoard(); };
      boardEl.append(b);
      cellEls[i] = b;
    }
    boardBuilt = true;
  }
  function paintCell(i) {
    const b = cellEls[i], r = (i / 9) | 0, c = i % 9, v = board[i];
    b.className = 'cell';
    b.setAttribute('aria-label', `第${r + 1}行第${c + 1}列${v ? '，' + v : ''}`);
    if (given[i]) b.classList.add('given');
    if (selected >= 0) {
      const sr = (selected / 9) | 0, sc = selected % 9;
      if (i === selected) b.classList.add('selected');
      else if (r === sr || c === sc || ((r / 3 | 0) === (sr / 3 | 0) && (c / 3 | 0) === (sc / 3 | 0))) b.classList.add('related');
      const selVal = board[selected];
      if (selVal && v === selVal && i !== selected) b.classList.add('same');
    }
    b.textContent = v || '';
    if (!v && notes[i] && notes[i].size) {
      const box = document.createElement('span');
      box.className = 'notes';
      for (let d = 1; d <= 9; d++) { const s = document.createElement('span'); s.textContent = notes[i].has(d) ? d : ''; box.append(s); }
      b.append(box);
    } else if (v) {
      if (!challenge && !given[i] && v !== solution[i]) b.classList.add('error');
      if (!challenge && !given[i] && duplicate(i, v)) b.classList.add('conflict');
    }
  }
  function paintBoard() {
    if (!boardBuilt) buildBoard();
    for (let i = 0; i < 81; i++) paintCell(i);
  }

  function renderNumbers() {
    const nums = $('numbers');
    if (!numEls.length) {
      nums.innerHTML = '';
      numEls = [];
      for (let d = 1; d <= 9; d++) {
        const btn = document.createElement('button');
        btn.className = 'num'; btn.textContent = d;
        btn.onclick = () => enter(d);
        nums.append(btn);
        numEls[d] = btn;
      }
    }
    for (let d = 1; d <= 9; d++) {
      numEls[d].disabled = 81 - board.filter(x => x === d).length === 0;
    }
  }

  function duplicate(i, v) {
    const r = (i / 9) | 0, c = i % 9;
    for (let k = 0; k < 9; k++) if ((k !== c && board[r * 9 + k] === v) || (k !== r && board[k * 9 + c] === v)) return true;
    const br = (r / 3) | 0, bc = (c / 3) | 0;
    for (let y = br * 3; y < br * 3 + 3; y++) for (let x = bc * 3; x < bc * 3 + 3; x++) if ((y !== r || x !== c) && board[y * 9 + x] === v) return true;
    return false;
  }

  function enter(d) {
    if (selected < 0 || given[selected]) return;
    if (!playStart) startTimer(); // 首次操作（填数/笔记）才开始计时
    history.push({ board: board.slice(), notes: notes.map(s => new Set(s)) });
    if (noteMode) {
      if (notes[selected].has(d)) notes[selected].delete(d); else notes[selected].add(d);
    } else {
      board[selected] = d; notes[selected].clear();
      if (board.every((x, i) => x === solution[i])) { win(); return; }
      const left = board.filter(x => !x).length;
      const correct = d === solution[selected];
      if (left === 0) say(challenge ? '已填满，请检查是否正确' : '已填满，但有错误，请检查标红的格子');
      else if (correct) say('正确，还剩 ' + left + ' 格');
      else say(challenge ? '已填入 ' + d : '再检查一下这个数字');
    }
    saveGame(); render();
  }

  function erase() {
    if (selected < 0 || given[selected]) return;
    history.push({ board: board.slice(), notes: notes.map(s => new Set(s)) });
    board[selected] = 0; notes[selected].clear();
    saveGame(); render();
  }

  function win() {
    if (dailyMode) { dailyWin(); return; }
    updateTimer();
    completed.add(level);
    unlocked = Math.max(unlocked, Math.min(N, level + 1));
    const t = seconds, prev = best[level], isRecord = !prev || t < prev;
    if (isRecord) best[level] = t;
    saveGame();
    clearInterval(timerId); timerId = null;
    playStart = 0; // 完成停表
    $('modal-time').textContent = fmt(t);
    $('modal-best').textContent = isRecord ? '新纪录！' : fmt(prev);
    $('modal-record').style.display = isRecord ? 'inline-block' : 'none';
    $('modal-gain').style.display = 'none';
    shareCtx = { mode: 'level', level, seconds: t, isRecord };
    $('modal-share').style.display = '';
    $('overlay').classList.add('show');
    render();
    if (currentUser) settleComplete(t);
  }

  function say(text) {
    $('message').textContent = text;
    clearTimeout(sayTimer);
    sayTimer = setTimeout(() => { if ($('message').textContent === text) $('message').textContent = ''; }, 6000);
  }

  function renderLevels() {
    const root = $('levels');
    if (!levelEls.length) {
      root.innerHTML = '';
      levelEls = [];
      for (let i = 1; i <= N; i++) {
        const b = document.createElement('button');
        const num = document.createElement('span');
        num.className = 'lvl-num';
        num.textContent = i;
        b.append(num);
        b.onclick = () => loadLevel(i);
        root.append(b);
        levelEls[i] = b;
      }
    }
    for (let i = 1; i <= N; i++) {
      const b = levelEls[i];
      b.className = 'level' + (i === level ? ' current' : '') + (completed.has(i) ? ' done' : '') + (i > unlocked ? ' locked' : '');
      let mini = b.querySelector('.best-mini');
      if (best[i]) {
        if (!mini) { mini = document.createElement('small'); mini.className = 'best-mini'; b.append(mini); }
        mini.textContent = fmt(best[i]);
      } else if (mini) {
        mini.remove();
      }
      b.title = `第 ${i} 关 · ${difficulty(i)}${best[i] ? ' · 最佳 ' + fmt(best[i]) : ''}`;
      b.disabled = i > unlocked;
    }
  }

  // 事件绑定
  $('undo').onclick = () => {
    const h = history.pop();
    if (h) { board = h.board; notes = h.notes; saveGame(); render(); } else say('暂无可撤销操作');
  };
  $('restart').onclick = () => {
    if (dailyMode) { dailyStore.write({}); loadDaily(); say('今日挑战已重新开始'); return; }
    if (saved.boards) delete saved.boards[level];
    clearInterval(timerId); timerId = null; playAcc = 0; playStart = 0;
    loadLevel(level);
    say('已重新开始第 ' + level + ' 关');
  };
  $('erase').onclick = erase;
  $('notes').onclick = () => { noteMode = !noteMode; $('notes').classList.toggle('active', noteMode); say(noteMode ? '笔记模式已开启' : '笔记模式已关闭'); };
  $('hint').onclick = () => {
    if (selected < 0 || given[selected]) { say('先选中一个空格'); return; }
    if (board[selected] === solution[selected]) { say('这个格子已经填对了'); return; }
    history.push({ board: board.slice(), notes: notes.map(s => new Set(s)) });
    board[selected] = solution[selected]; notes[selected].clear();
    saveGame(); render(); say('已填入一个正确数字');
  };
  $('continue').onclick = () => loadLevel(level);
  $('daily-btn').onclick = () => loadDaily();

  // 挑战模式开关
  const chk = $('challenge');
  if (chk) {
    chk.checked = challenge;
    chk.addEventListener('change', e => { challenge = e.target.checked; persist(); render(); say(challenge ? '挑战模式已开启：不提示对错' : '挑战模式已关闭'); });
  }

  // 弹窗按钮
  const overlay = $('overlay');
  $('modal-next').onclick = () => { overlay.classList.remove('show'); if (dailyMode) { loadLevel(level); return; } if (level < N) loadLevel(level + 1); else say('已是最后一关'); };
  $('modal-replay').onclick = () => { overlay.classList.remove('show'); if (dailyMode) { loadLevel(level); return; } if (saved.boards) delete saved.boards[level]; loadLevel(level); };
  overlay.addEventListener('click', e => { if (e.target === overlay) overlay.classList.remove('show'); });

  // 键盘
  document.addEventListener('keydown', e => {
    if (overlay.classList.contains('show')) { if (e.key === 'Escape') overlay.classList.remove('show'); return; }
    if (e.key >= '1' && e.key <= '9') { enter(+e.key); return; }
    if (e.key === 'Backspace' || e.key === 'Delete') { erase(); return; }
    if (selected >= 0 && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
      e.preventDefault();
      let r = (selected / 9) | 0, c = selected % 9;
      if (e.key === 'ArrowUp') r = (r + 8) % 9;
      if (e.key === 'ArrowDown') r = (r + 1) % 9;
      if (e.key === 'ArrowLeft') c = (c + 8) % 9;
      if (e.key === 'ArrowRight') c = (c + 1) % 9;
      selected = r * 9 + c; render();
    }
  });

  // 页面隐藏/卸载：暂停计时并保存；页面可见：恢复计时
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { pauseTimer(); saveGame(); }
    else resumeTimer();
  });
  window.addEventListener('pagehide', saveGame);

  // 深浅色主题
  const THEME_KEY = 'quiet-sudoku-theme';
  const mqDark = window.matchMedia('(prefers-color-scheme: dark)');
  const themeBtn = $('theme');
  // 移动端部分浏览器（iOS 隐私模式/WebView）localStorage 可能抛错，须隔离，避免中断主题切换
  const themeGet = k => { try { return localStorage.getItem(k); } catch { return null; } };
  const themeSet = (k, v) => { try { localStorage.setItem(k, v); } catch { /* 仅不持久化，本次仍可切换 */ } };
  function applyTheme() {
    const saved = themeGet(THEME_KEY);
    const dark = saved ? saved === 'dark' : mqDark.matches;
    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.classList.toggle('light', saved === 'light');
    if (themeBtn) {
      themeBtn.textContent = dark ? '☀️' : '🌙';
      themeBtn.setAttribute('aria-pressed', dark);
    }
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', dark ? '#1c211d' : '#f5f6f3');
  }
  if (themeBtn) {
    themeBtn.onclick = () => {
      const cur = themeGet(THEME_KEY);
      const next = cur ? (cur === 'dark' ? 'light' : 'dark') : (mqDark.matches ? 'light' : 'dark');
      themeSet(THEME_KEY, next);
      applyTheme();
    };
    const onSchemeChange = () => { if (!themeGet(THEME_KEY)) applyTheme(); };
    if (mqDark.addEventListener) mqDark.addEventListener('change', onSchemeChange);
    else if (mqDark.addListener) mqDark.addListener(onSchemeChange); // 旧 iOS Safari 兼容
  }
  applyTheme();

  // ===== 账号 & 云同步：UI 绑定与初始化（阶段二） =====
  let pushTimer = null;

  // ===== 等级 & 勋章（阶段三/四） =====
  const TITLES = ['晨间新手', '轻松起步', '数字漫步', '午后片刻', '静心推理', '思路交汇', '专注时分', '层层深入', '终极挑战', '大师之路'];
  const titleFor = lv => TITLES[Math.min(9, Math.floor(((lv || 1) - 1) / 10))];
  const ACH_ICON = { progress: '🏆', speed: '⚡', persistence: '⏳', challenge: '⛰️', daily: '📅' };
  const ACH_CODE_ICON = { prog_1: '🏆', prog_10: '🥉', prog_25: '🥈', prog_50: '🥇', prog_100: '💎', speed_60: '⚡', speed_30: '💨', persist_1h: '⏳', persist_10h: '⏰', chal_hard: '⛰️', daily_7: '📅' };
  const achIcon = a => a.icon || ACH_CODE_ICON[a.code] || ACH_ICON[a.category] || '🎖️';
  let achList = [];

  function toast(text) {
    const box = document.createElement('div');
    box.className = 'toast';
    box.textContent = text;
    $('toasts').append(box);
    setTimeout(() => box.classList.add('show'), 10);
    setTimeout(() => { box.classList.remove('show'); setTimeout(() => box.remove(), 300); }, 3200);
  }

  function renderLevelCard(user) {
    if (!user) { $('level-card').style.display = 'none'; return; }
    $('level-card').style.display = '';
    const lv = user.level || 1;
    $('lv-num').textContent = lv;
    $('lv-title').textContent = titleFor(lv);
    const expNext = user.expNext || 100;
    const levelExp = (user.levelExp != null) ? user.levelExp : (user.exp || 0);
    const pct = expNext ? Math.min(100, Math.round(levelExp / expNext * 100)) : 0;
    $('lv-bar').style.width = pct + '%';
    $('lv-exp').textContent = levelExp + ' / ' + expNext;
  }

  function fmtSec(s) {
    s = Math.max(0, Math.floor(s || 0));
    if (s < 60) return s + 's';
    const m = Math.floor(s / 60), h = Math.floor(m / 60);
    if (h > 0) return h + 'h' + (m % 60 ? (m % 60) + 'm' : '');
    return m + 'm';
  }
  function achProgText(a) {
    const p = a.progress || 0, t = a.target || 0;
    if (a.category === 'progress') return '完成 ' + p + ' / ' + t + ' 关';
    if (a.category === 'speed') return '最快 ' + (p ? p + 's' : '--') + ' / ' + t + 's';
    if (a.category === 'persistence') return '累计 ' + fmtSec(p) + ' / ' + fmtSec(t);
    if (a.category === 'challenge') return '第 ' + p + ' 关 / 第 ' + t + ' 关';
    if (a.category === 'daily') return '连续 ' + p + ' / ' + t + ' 天';
    return p + ' / ' + t;
  }
  function achProgPct(a) {
    const p = a.progress || 0, t = a.target || 0;
    if (!t) return 0;
    if (a.category === 'speed') return Math.min(100, Math.max(0, Math.round((1 - p / t) * 100)));
    return Math.min(100, Math.round(p / t * 100));
  }
  function renderAchievements(list) {
    const head = $('ach-head'), wall = $('ach-wall');
    if (!currentUser || !list || !list.length) { head.style.display = 'none'; wall.style.display = 'none'; return; }
    head.style.display = '';
    wall.style.display = '';
    const unlockedCount = list.filter(a => a.unlocked).length;
    $('ach-count').textContent = unlockedCount + ' / ' + list.length;
    wall.innerHTML = '';
    list.forEach(a => {
      const b = document.createElement('button');
      b.className = 'ach' + (a.unlocked ? ' unlocked' : '');
      b.setAttribute('data-cat', a.category || '');
      b.title = a.name + (a.unlocked ? '（已解锁）' : '（未解锁）') + '\n' + a.description + (a.points ? ' · +' + a.points + ' 经验' : '');
      const progHtml = a.unlocked
        ? '<span class="ach-tick">✓</span>'
        : '<span class="ach-bar"><i style="width:' + achProgPct(a) + '%"></i></span>'
          + '<span class="ach-prog">' + achProgText(a) + '</span>';
      b.innerHTML = '<span class="ach-icon">' + achIcon(a) + '</span>'
        + '<span class="ach-name">' + esc(a.name) + '</span>'
        + progHtml;
      wall.append(b);
    });
  }

  async function loadAchievements() {
    if (!currentUser) { renderAchievements([]); return; }
    try {
      achList = (await apiCall('achievements', 'GET')).achievements || [];
      renderAchievements(achList);
    } catch (e) { /* 网络忽略 */ }
  }

  // ===== 排行榜（阶段六） =====
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m])); }
  function fmtClock(s) {
    if (s == null) return '--';
    const m = Math.floor(s / 60), sec = s % 60;
    return (m < 10 ? '0' + m : '' + m) + ':' + (sec < 10 ? '0' + sec : '' + sec);
  }
  async function loadLeaderboard(lv) {
    const el = $('leader-list');
    if (!el) return;
    const cur = lv || level || 1;
    const intro = $('leader-intro');
    if (intro) intro.textContent = '第 ' + cur + ' 关 · 用时越短越靠前';
    try {
      const d = await apiCall('leaderboard&level=' + cur + '&limit=15', 'GET');
      const list = d.ranking || [];
      if (!list.length) { el.innerHTML = '<div class="leader-loading">暂无上榜用户</div>'; return; }
      const uid = currentUser ? currentUser.id : -1;
      const rowHtml = (u, i, extraTag) => {
        const me = u.id === uid;
        const ava = avatarHtml(u);
        return '<div class="lb-row' + (me ? ' me' : '') + '">'
          + '<span class="lb-rank">' + (i + 1) + '</span>'
          + '<span class="lb-ava">' + ava + '</span>'
          + '<span class="lb-info"><span class="lb-name">' + esc(u.nickname || u.username) + (extraTag || '') + '</span>'
          + '<span class="lb-meta"><span>⏱ ' + fmtClock(u.best) + '</span></span></span>'
          + '</div>';
      };
      let html = list.map((u, i) => rowHtml(u, i)).join('');
      // 「我的排名」固定底行：当前登录用户不在 top 时显示
      if (d.my && d.my.rank) {
        html += '<div class="lb-sep">···</div>'
          + rowHtml(d.my, d.my.rank - 1, '<small class="lb-mytag">我的排名</small>');
      }
      el.innerHTML = html;
    } catch (e) {
      el.innerHTML = '<div class="leader-loading">排行榜加载失败</div>';
    }
  }

  // ===== 每日挑战（阶段七） =====
  function saveDailyLocal() {
    if (!dailyMode || !dailySavedDate) return;
    const ls = dailyStore.read();
    ls.date = dailySavedDate;
    ls.board = board.slice();
    ls.notes = notes.map(s => [...s]);
    ls.seconds = seconds;
    dailyStore.write(ls);
  }

  function updateDailyCard() {
    const badge = $('daily-badge'), dateEl = $('daily-date'), streakEl = $('daily-streak'), btn = $('daily-btn');
    if (!dailyData) return;
    if (dateEl) dateEl.textContent = dailyData.date;
    const solved = !!(dailyData.solved || dailyDone);
    if (badge) { badge.textContent = solved ? '已完成' : '未完成'; badge.classList.toggle('done', solved); }
    if (btn) btn.textContent = solved ? '今日已完成 · 重玩不计分' : '开始今日挑战';
    if (streakEl) streakEl.textContent = '🔥 连胜 ' + (dailyData.streak || 0) + ' 天';
  }

  // 轻量拉取：仅刷新侧边栏卡片状态（不进棋盘）
  async function loadDailyCard() {
    if (!currentUser) return;
    try {
      dailyData = await apiCall('daily', 'GET');
      dailyDone = !!dailyData.solved;
      updateDailyCard();
    } catch (e) { /* 网络忽略，卡片保持默认 */ }
  }

  // 进入今日挑战：复用棋盘，题面来自每日接口，不写 saved.boards
  async function loadDaily() {
    if (!currentUser) { say('请先登录后参加今日挑战'); openAuth('login'); return; }
    try {
      const d = await apiCall('daily', 'GET');
      dailyData = d;
      dailyMode = true;
      dailyDone = !!d.solved;
      dailySavedDate = d.date;
      solution = (d.answer || '').split('').map(Number);
      const clue = (d.cells || '').split('').map(Number);
      given = clue.map(x => x > 0);
      const ls = dailyStore.read();
      const sd = (ls && ls.date === d.date && Array.isArray(ls.board) && ls.board.length === 81) ? ls : null;
      board = sd ? sd.board.slice() : clue.slice();
      notes = Array.from({ length: 81 }, (_, i) => new Set(sd && Array.isArray(sd.notes && sd.notes[i]) ? sd.notes[i].filter(x => x >= 1 && x <= 9) : []));
      seconds = Math.min(1e5, Math.max(0, (sd && sd.seconds) | 0));
      history = []; selected = -1; noteMode = false;
      $('notes').classList.remove('active');
      playAcc = seconds * 1000; playStart = 0;
      updateTimer();
      render();
      updateDailyCard();
      if (d.solved) say('今日挑战已完成，用时 ' + fmt(d.solved.seconds));
      else say('今日挑战：每天全球同题，完成后计经验');
    } catch (e) {
      say('今日挑战加载失败：' + (e.message || '网络异常'));
    }
  }

  async function dailyWin() {
    updateTimer();
    const t = seconds;
    dailyDone = true;
    saveDailyLocal();
    clearInterval(timerId); timerId = null; playStart = 0; // 完成停表
    $('modal-title').textContent = '今日挑战完成！';
    $('modal-replay').style.display = 'none';
    $('modal-next').textContent = '返回闯关 ›';
    $('modal-time').textContent = fmt(t);
    $('modal-best').textContent = '每日限定';
    $('modal-record').style.display = 'none';
    $('modal-gain').style.display = 'none';
    shareCtx = { mode: 'daily', seconds: t, isRecord: false };
    $('modal-share').style.display = '';
    $('overlay').classList.add('show');
    render();
    updateDailyCard();
    if (currentUser) settleDaily(t);
  }

  async function settleDaily(seconds) {
    try {
      const d = await apiCall('daily/complete', 'POST', { seconds });
      if (currentUser) {
        currentUser.exp = d.exp;
        currentUser.level = d.level;
        currentUser.expNext = d.expNext;
        currentUser.levelExp = d.levelExp;
        updateUserUI();
        renderLevelCard(currentUser);
      }
      renderModalGain({ expGain: d.expGain, first: d.firstToday, newAchievements: d.newAchievements });
      if (d.newAchievements && d.newAchievements.length) {
        d.newAchievements.forEach(a => toast('🏅 解锁勋章：' + a.name));
      }
      if (d.levelUp) toast('🎉 升级到 Lv.' + d.level + '！');
      if (d.streak != null && dailyData) dailyData.streak = d.streak;
      loadAchievements();
      loadDailyCard();
    } catch (e) { /* 结算失败不影响本地完成 */ }
  }

  // ===== 成绩分享卡片（阶段③） =====
  let shareCtx = null;

  function drawMiniGrid(g, x, y, size) {
    const cell = size / 9;
    g.strokeStyle = 'rgba(255,255,255,.35)'; g.lineWidth = 1;
    for (let i = 0; i <= 9; i++) {
      g.beginPath(); g.moveTo(x + i * cell, y); g.lineTo(x + i * cell, y + size); g.stroke();
      g.beginPath(); g.moveTo(x, y + i * cell); g.lineTo(x + size, y + i * cell); g.stroke();
    }
    g.lineWidth = 2.5;
    for (let i = 0; i <= 3; i++) {
      g.beginPath(); g.moveTo(x + i * 3 * cell, y); g.lineTo(x + i * 3 * cell, y + size); g.stroke();
      g.beginPath(); g.moveTo(x, y + i * 3 * cell); g.lineTo(x + size, y + i * 3 * cell); g.stroke();
    }
  }

  function buildShareCard() {
    if (!shareCtx) return null;
    const W = 600, H = 800, P = 40;
    const c = document.createElement('canvas');
    c.width = W; c.height = H;
    const g = c.getContext('2d');
    const isDaily = shareCtx.mode === 'daily';

    // 背景渐变
    const grad = g.createLinearGradient(0, 0, W, H);
    grad.addColorStop(0, '#63bb5f'); grad.addColorStop(1, '#2f7a44');
    g.fillStyle = grad; g.fillRect(0, 0, W, H);
    // 装饰网格
    drawMiniGrid(g, W - 170, 54, 96);

    // 品牌
    g.fillStyle = '#ffffff'; g.textBaseline = 'top';
    g.font = '700 30px system-ui, sans-serif';
    g.fillText('数独 · 静心一刻', P, 58);
    g.font = '400 17px system-ui, sans-serif'; g.globalAlpha = .82;
    g.fillText('一格一数，慢慢来就好', P, 98);
    g.globalAlpha = 1;

    // 标题（关卡/今日挑战）
    const title = isDaily ? '今日挑战' : ('第 ' + shareCtx.level + ' 关');
    g.font = '700 42px system-ui, sans-serif';
    g.fillText(title, P, 240);

    // 用时
    g.font = '700 78px system-ui, sans-serif';
    g.fillText(fmt(shareCtx.seconds), P, 312);
    g.font = '400 18px system-ui, sans-serif'; g.globalAlpha = .85;
    g.fillText('本关用时', P, 418); g.globalAlpha = 1;

    // 排名 / 纪录
    let sub = '🎖 已完成';
    if (shareCtx.isRecord) sub = '🎉 新纪录！';
    else if (shareCtx.rank) sub = '🏆 第 ' + shareCtx.rank + ' 名';
    g.font = '600 27px system-ui, sans-serif';
    g.fillText(sub, P, 470);

    // 底部：用户名 + 日期
    const name = currentUser ? (currentUser.nickname || currentUser.username) : '游客';
    g.font = '500 21px system-ui, sans-serif'; g.globalAlpha = .92;
    g.fillText(name, P, H - 138);
    g.fillText(new Date().toLocaleDateString('zh-CN'), P, H - 106);
    g.globalAlpha = 1;

    // 底部条
    g.fillStyle = 'rgba(255,255,255,.3)'; g.fillRect(P, H - 64, W - 2 * P, 2);
    g.font = '400 15px system-ui, sans-serif'; g.globalAlpha = .7;
    g.fillText('sudoku.southerly.top', P, H - 48); g.globalAlpha = 1;
    return c;
  }

  async function openShare() {
    try {
      // 关卡模式：拉当前关排名（如未上榜则显示「已完成」）
      if (shareCtx && !shareCtx.rank && !shareCtx.isRecord && currentUser && shareCtx.mode !== 'daily') {
        try {
          const d = await apiCall('leaderboard&level=' + shareCtx.level + '&limit=15', 'GET');
          if (d.my && d.my.rank) shareCtx.rank = d.my.rank;
        } catch (e) { /* 排名失败不影响卡片 */ }
      }
      const c = buildShareCard();
      if (!c) { say('暂无可分享的成绩'); return; }
      $('share-img').src = c.toDataURL('image/png');
      $('share-overlay').classList.add('show');
      $('share-system').style.display = (navigator.share && navigator.canShare) ? '' : 'none';
    } catch (e) { say('生成分享卡片失败'); }
  }

  function bindShare() {
    $('modal-share').onclick = openShare;
    $('share-save').onclick = () => {
      const a = document.createElement('a');
      a.href = $('share-img').src;
      a.download = 'sudoku-成绩.png';
      a.click();
    };
    $('share-copy').onclick = async () => {
      try {
        const blob = await (await fetch($('share-img').src)).blob();
        if (!navigator.clipboard || !window.ClipboardItem) { say('当前浏览器不支持复制图片，可保存图片'); return; }
        await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
        toast('已复制到剪贴板');
      } catch (e) { say('复制失败，可保存图片'); }
    };
    $('share-system').onclick = async () => {
      try {
        const blob = await (await fetch($('share-img').src)).blob();
        const file = new File([blob], 'sudoku.png', { type: 'image/png' });
        await navigator.share({ files: [file], title: '数独成绩' });
      } catch (e) { /* 用户取消或失败忽略 */ }
    };
    $('share-close').onclick = () => $('share-overlay').classList.remove('show');
    $('share-overlay').addEventListener('click', e => { if (e.target === $('share-overlay')) $('share-overlay').classList.remove('show'); });
  }

  function renderModalGain(d) {
    const el = $('modal-gain');
    if (!d || !d.expGain) { el.style.display = 'none'; return; }
    let html = '<div class="mg-exp">+' + d.expGain + ' 经验</div>';
    const tags = [];
    if (d.first) tags.push('首次完成');
    if (d.isRecord) tags.push('新纪录');
    if (tags.length) html += '<div class="mg-tags">' + tags.map(t => '<span>' + t + '</span>').join('') + '</div>';
    if (d.newAchievements && d.newAchievements.length) {
      html += '<div class="mg-ach">' + d.newAchievements.map(a =>
        '<span class="mg-ach-item">' + achIcon(a) + ' ' + a.name + (a.points ? ' +' + a.points : '') + '</span>'
      ).join('') + '</div>';
    }
    el.innerHTML = html;
    el.style.display = '';
  }

  async function settleComplete(seconds) {
    try {
      const d = await apiCall('game/complete', 'POST', { level, seconds });
      if (currentUser) {
        currentUser.exp = d.exp;
        currentUser.level = d.level;
        currentUser.expNext = d.expNext;
        currentUser.levelExp = d.levelExp;
        updateUserUI();
        renderLevelCard(currentUser);
      }
      renderModalGain(d);
      if (d.newAchievements && d.newAchievements.length) {
        d.newAchievements.forEach(a => toast('🏅 解锁勋章：' + a.name));
      }
      if (d.levelUp) toast('🎉 升级到 Lv.' + d.level + '！');
      loadAchievements();
    } catch (e) { /* 结算失败不影响本地通关 */ }
  }

  function queuePush() {
    if (!currentUser) return;
    clearTimeout(pushTimer);
    pushTimer = setTimeout(() => {
      if (!currentUser) return;
      apiCall('progress/' + level, 'PUT', { cells: cellsToStr(board), notes: notesToObj(notes), seconds })
        .catch(() => { /* 网络波动忽略，下次保存重试 */ });
    }, 800);
  }

  // ===== 个人主页 + 头像（阶段④） =====
  const AVATARS = ['🦊','🐱','🐼','🐸','🦁','🐯','🐰','🦉','🐨','🐶','🦄','🐙','🌿','⭐','🔥','🌈'];

  // 统一头像渲染：URL 用 <img>，emoji/短文本用文本，否则首字母
  function avatarHtml(u) {
    const a = u && u.avatar;
    if (a) {
      if (/^(https?:|data:image)/i.test(a)) return '<img src="' + esc(a) + '" alt="">';
      return '<span class="ava-emoji">' + esc(a) + '</span>';
    }
    return '<span class="ava-letter">' + esc((u.nickname || u.username || '?').charAt(0).toUpperCase()) + '</span>';
  }

  let profileData = null;

  async function openProfile() {
    if (!currentUser) return;
    $('profile-overlay').classList.add('show');
    try {
      const d = await apiCall('profile', 'GET');
      profileData = d;
      renderProfile(d);
    } catch (e) { say('加载主页失败：' + e.message); }
  }
  function closeProfile() { $('profile-overlay').classList.remove('show'); }

  function renderProfile(d) {
    const u = d.user;
    $('pf-avatar').innerHTML = avatarHtml(u);
    $('pf-nick').textContent = u.nickname || u.username;
    $('pf-username').textContent = '@' + (u.username || '');
    $('pf-level').textContent = 'Lv.' + (u.level || 1);
    const need = d.expNext || 100, into = d.levelExp || 0;
    $('pf-exp-fill').style.width = Math.min(100, Math.round(into / need * 100)) + '%';
    $('pf-exp-text').textContent = into + ' / ' + need;
    const s = d.stats || {};
    $('pf-completed').textContent = s.complete_count || 0;
    $('pf-total').textContent = fmtClock(s.total_seconds || 0);
    $('pf-best').textContent = s.best_min ? fmtClock(s.best_min) : '--';
    $('pf-max').textContent = s.max_level || 0;
    const dl = d.daily || {};
    $('pf-daily-txt').textContent = '今日挑战：' + (dl.todayDone ? '✅ 已完成' : '未完成');
    $('pf-streak').textContent = '连胜 ' + (dl.streak || 0) + ' 天';
    const ach = d.achievements || [];
    $('pf-ach-count').textContent = ach.length + '/' + (d.achievementTotal || ach.length);
    $('pf-ach').innerHTML = ach.length ? ach.map(a =>
      '<div class="pf-ach-item" title="' + esc(a.description || '') + '">'
      + '<span class="pf-ach-icon">' + esc(a.icon || '🏅') + '</span>'
      + '<span class="pf-ach-name">' + esc(a.name || '') + '</span></div>').join('')
      : '<span class="pf-empty">暂无勋章，完成关卡解锁成就</span>';
    const rec = d.recent || [];
    $('pf-recent').innerHTML = rec.length ? rec.map(r =>
      '<div class="pf-rec"><span class="pf-rec-lv">第' + r.level + '关</span>'
      + '<span class="pf-rec-time">' + fmtClock(r.seconds) + '</span>'
      + (r.is_record ? '<span class="pf-rec-badge">纪录</span>' : '')
      + '</div>').join('')
      : '<span class="pf-empty">暂无完成记录</span>';
  }

  function openAvatarPicker() {
    $('avatar-grid').innerHTML = AVATARS.map(a =>
      '<button class="avatar-opt" data-ava="' + a + '" type="button">' + a + '</button>').join('');
    $('avatar-overlay').classList.add('show');
  }

  async function pickAvatar(ava) {
    try {
      const d = await apiCall('me/avatar', 'PUT', { avatar: ava });
      if (d.user) {
        currentUser.avatar = d.user.avatar;
        if (profileData) profileData.user = d.user;
      }
      $('avatar-overlay').classList.remove('show');
      updateUserUI();
      loadLeaderboard();
      if ($('profile-overlay').classList.contains('show') && profileData) renderProfile(profileData);
      toast('头像已更新');
    } catch (e) { say('头像更新失败：' + e.message); }
  }

  function bindProfile() {
    $('user-chip').onclick = e => { e.stopPropagation(); openProfile(); };
    $('profile-close').onclick = closeProfile;
    $('profile-overlay').addEventListener('click', e => { if (e.target === $('profile-overlay')) closeProfile(); });
    $('pf-avatar').onclick = openAvatarPicker;
    $('avatar-close').onclick = () => $('avatar-overlay').classList.remove('show');
    $('avatar-overlay').addEventListener('click', e => { if (e.target === $('avatar-overlay')) $('avatar-overlay').classList.remove('show'); });
    $('avatar-grid').onclick = e => {
      const btn = e.target.closest ? e.target.closest('.avatar-opt') : null;
      if (btn) pickAvatar(btn.getAttribute('data-ava'));
    };
    $('profile-logout').onclick = () => {
      tokenSet(''); currentUser = null;
      updateUserUI(); loadLeaderboard(); closeProfile();
      say('已退出登录，进度将仅保存在本地');
    };
  }

  function updateUserUI() {
    const loginBtn = $('login-btn'), chip = $('user-chip');
    if (currentUser) {
      loginBtn.style.display = 'none';
      chip.style.display = 'inline-flex';
      const name = currentUser.nickname || currentUser.username || '?';
      $('avatar').innerHTML = avatarHtml(currentUser);
      $('uname').textContent = name;
      $('foot').textContent = '已开启云端同步，闯关进度会自动备份到账号。';
    } else {
      loginBtn.style.display = '';
      chip.style.display = 'none';
      $('foot').textContent = '你的进度仅保存在此设备的浏览器中。';
      renderLevelCard(null);
      renderAchievements([]);
    }
  }

  function switchAuthTab(tab) {
    const isLogin = tab === 'login';
    $('tab-login').classList.toggle('active', isLogin);
    $('tab-register').classList.toggle('active', !isLogin);
    $('login-form').style.display = isLogin ? '' : 'none';
    $('register-form').style.display = isLogin ? 'none' : '';
    $('login-msg').textContent = ''; $('reg-msg').textContent = '';
  }
  function openAuth(tab) {
    switchAuthTab(tab || 'login');
    $('auth-overlay').classList.add('show');
  }
  function closeAuth() { $('auth-overlay').classList.remove('show'); }

  // 云同步：云端为准拉取，合并本地独有关卡并上传备份
  async function syncProgress() {
    if (!currentUser) return;
    let cloud = {};
    try { cloud = (await apiCall('progress', 'GET')).progress || {}; } catch { return; }
    // 方案一：同步完成状态/最佳成绩/解锁进度，保证同一账号跨设备一致
    let statChanged = false;
    try {
      const st = await apiCall('me/stats', 'GET');
      const cloudBest = st.bestByLevel || {};
      Object.keys(cloudBest).forEach(k => {
        const lv = +k;
        if (!lv || lv < 1 || lv > N) return;
        const cb = Math.max(1, Math.floor(cloudBest[k] | 0));
        if (!completed.has(lv)) { completed.add(lv); statChanged = true; }
        if (!best[lv] || cb < best[lv]) { best[lv] = cb; statChanged = true; }
      });
      let maxC = 0; completed.forEach(v => { if (v > maxC) maxC = v; });
      const nu = clamp(maxC + 1, 1, N);
      if (nu !== unlocked) { unlocked = nu; statChanged = true; }
    } catch (e) { /* me/stats 失败不影响棋盘同步 */ }
    saved.boards = saved.boards || {};
    let changed = false;
    Object.keys(cloud).forEach(k => {
      const lv = +k;
      if (!lv || lv < 1 || lv > N) return;
      const c = cloud[lv];
      saved.boards[lv] = { cells: strToCells(c.cells), notes: objToNotes(c.notes), seconds: (c.seconds | 0), ts: Date.now() };
      changed = true;
    });
    Object.keys(saved.boards).forEach(k => {
      const lv = +k;
      if (!lv || lv < 1 || lv > N || cloud[lv]) return;
      const b = saved.boards[lv];
      if (!b || !Array.isArray(b.cells)) return;
      apiCall('progress/' + lv, 'PUT', { cells: cellsToStr(b.cells), notes: notesToObj(b.notes || []), seconds: (b.seconds | 0) }).catch(() => {});
    });
    if (changed || statChanged) persist();
    if (statChanged) render();
    return changed;
  }

  async function initAuth() {
    if (!tokenGet()) { updateUserUI(); return; }
    try {
      const d = await apiCall('auth/me', 'GET');
      currentUser = d.user;
      currentUser.expNext = d.expNext || 100;
      currentUser.levelExp = d.levelExp || 0;
      updateUserUI();
      renderLevelCard(currentUser);
      loadAchievements();
      loadDailyCard();
      const changed = await syncProgress();
      if (changed) loadLevel(level);
    } catch (err) {
      if (err.status === 401) tokenSet('');
      currentUser = null;
      updateUserUI();
    }
  }

  function bindAuth() {
    $('login-btn').onclick = () => openAuth('login');
    $('tab-login').onclick = () => switchAuthTab('login');
    $('tab-register').onclick = () => switchAuthTab('register');
    $('auth-close').onclick = closeAuth;
    $('auth-overlay').addEventListener('click', e => { if (e.target === $('auth-overlay')) closeAuth(); });

    $('login-form').addEventListener('submit', async e => {
      e.preventDefault();
      const username = $('login-username').value.trim();
      const password = $('login-password').value;
      const msg = $('login-msg');
      msg.textContent = '';
      if (!username || !password) { msg.textContent = '请输入用户名和密码'; return; }
      const btn = e.target.querySelector('.auth-submit');
      btn.disabled = true; msg.style.color = 'var(--muted)'; msg.textContent = '登录中…';
      try {
        const d = await apiCall('auth/login', 'POST', { username, password });
        tokenSet(d.token); currentUser = d.user;
        currentUser.expNext = d.expNext || 100;
        currentUser.levelExp = d.levelExp || 0;
        updateUserUI(); closeAuth();
        renderLevelCard(currentUser);
        loadAchievements();
        loadLeaderboard();
        loadDailyCard();
        say('欢迎回来，' + (currentUser.nickname || currentUser.username));
        const changed = await syncProgress();
        if (changed) loadLevel(level);
      } catch (err) {
        msg.style.color = 'var(--red)'; msg.textContent = err.message;
      } finally { btn.disabled = false; }
    });

    $('register-form').addEventListener('submit', async e => {
      e.preventDefault();
      const username = $('reg-username').value.trim();
      const password = $('reg-password').value;
      const nickname = $('reg-nickname').value.trim();
      const msg = $('reg-msg');
      msg.textContent = '';
      if (!username || !password) { msg.textContent = '请输入用户名和密码'; return; }
      const btn = e.target.querySelector('.auth-submit');
      btn.disabled = true; msg.style.color = 'var(--muted)'; msg.textContent = '注册中…';
      try {
        const d = await apiCall('auth/register', 'POST', { username, password, nickname });
        tokenSet(d.token); currentUser = d.user;
        currentUser.expNext = d.expNext || 100;
        currentUser.levelExp = d.levelExp || 0;
        updateUserUI(); closeAuth();
        renderLevelCard(currentUser);
        loadAchievements();
        loadLeaderboard();
        loadDailyCard();
        say('注册成功，欢迎加入！');
        const changed = await syncProgress();
        if (changed) loadLevel(level);
      } catch (err) {
        msg.style.color = 'var(--red)'; msg.textContent = err.message;
      } finally { btn.disabled = false; }
    });

    $('user-chip').onclick = e => { e.stopPropagation(); openProfile(); };
    // 弹窗 Escape 关闭
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && $('auth-overlay').classList.contains('show')) closeAuth();
    });
  }

  bindAuth();
  bindShare();
  bindProfile();
  initAuth();
  loadLeaderboard();
  loadLevel(level);

  // ===== PWA：注册 Service Worker（离线缓存，断网可玩） =====
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/sw.js').catch(() => { /* 不支持或失败时忽略 */ });
    });
  }
})();
