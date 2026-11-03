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
    saved.boards = saved.boards || {};
    saved.boards[level] = { cells: board.slice(), notes: notes.map(s => [...s]), seconds, ts: Date.now() };
    persist();
    queuePush();
  }

  function loadLevel(l) {
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
    persist(); render(); startTimer();
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
    $('level-top').textContent = String(level).padStart(2, '0');
    $('title').textContent = labels[Math.min(9, Math.floor((level - 1) / 10))];
    $('difficulty').textContent = difficulty(level);
    $('completed').textContent = completed.size;
    $('progress-text').textContent = completed.size + ' / ' + N;
    $('progress-bar').style.width = completed.size + '%';
    $('best').textContent = best[level] ? ('本关最佳 ' + fmt(best[level])) : '';

    paintBoard();

    renderNumbers();
    renderLevels();
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
    updateTimer();
    completed.add(level);
    unlocked = Math.max(unlocked, Math.min(N, level + 1));
    const t = seconds, prev = best[level], isRecord = !prev || t < prev;
    if (isRecord) best[level] = t;
    saveGame();
    clearInterval(timerId); timerId = null;
    $('modal-time').textContent = fmt(t);
    $('modal-best').textContent = isRecord ? '新纪录！' : fmt(prev);
    $('modal-record').style.display = isRecord ? 'inline-block' : 'none';
    $('modal-gain').style.display = 'none';
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

  // 挑战模式开关
  const chk = $('challenge');
  if (chk) {
    chk.checked = challenge;
    chk.addEventListener('change', e => { challenge = e.target.checked; persist(); render(); say(challenge ? '挑战模式已开启：不提示对错' : '挑战模式已关闭'); });
  }

  // 弹窗按钮
  const overlay = $('overlay');
  $('modal-next').onclick = () => { overlay.classList.remove('show'); if (level < N) loadLevel(level + 1); else say('已是最后一关'); };
  $('modal-replay').onclick = () => { overlay.classList.remove('show'); if (saved.boards) delete saved.boards[level]; loadLevel(level); };
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
      b.title = a.name + (a.unlocked ? '（已解锁）' : '（未解锁）') + '\n' + a.description + (a.points ? ' · +' + a.points + ' 经验' : '');
      b.innerHTML = '<span class="ach-icon">' + (a.icon || ACH_ICON[a.category] || '🎖️') + '</span>';
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
        '<span class="mg-ach-item">' + (a.icon || ACH_ICON[a.category] || '🎖️') + ' ' + a.name + (a.points ? ' +' + a.points : '') + '</span>'
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

  function updateUserUI() {
    const loginBtn = $('login-btn'), chip = $('user-chip'), menu = $('user-menu');
    if (currentUser) {
      loginBtn.style.display = 'none';
      chip.style.display = 'inline-flex';
      const name = currentUser.nickname || currentUser.username || '?';
      $('avatar').textContent = name.charAt(0).toUpperCase();
      $('uname').textContent = name;
      $('um-nick').textContent = name;
      $('um-username').textContent = '@' + (currentUser.username || '');
      $('um-level').textContent = currentUser.level || 1;
      $('um-exp-text').textContent = (currentUser.exp || 0) + ' / ' + (currentUser.expNext || 100);
      $('foot').textContent = '已开启云端同步，闯关进度会自动备份到账号。';
    } else {
      loginBtn.style.display = '';
      chip.style.display = 'none';
      if (menu) menu.style.display = 'none';
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
    if (changed) persist();
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
        say('注册成功，欢迎加入！');
        const changed = await syncProgress();
        if (changed) loadLevel(level);
      } catch (err) {
        msg.style.color = 'var(--red)'; msg.textContent = err.message;
      } finally { btn.disabled = false; }
    });

    $('user-chip').onclick = e => {
      e.stopPropagation();
      const m = $('user-menu');
      m.style.display = (m.style.display === 'none' || !m.style.display) ? 'block' : 'none';
    };
    document.addEventListener('click', e => {
      const m = $('user-menu');
      if (m && m.style.display === 'block' && !m.contains(e.target) && !$('user-chip').contains(e.target)) m.style.display = 'none';
    });
    $('logout-btn').onclick = () => {
      tokenSet(''); currentUser = null;
      updateUserUI();
      say('已退出登录，进度将仅保存在本地');
    };
    // 弹窗 Escape 关闭
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && $('auth-overlay').classList.contains('show')) closeAuth();
    });
  }

  bindAuth();
  initAuth();
  loadLevel(level);
})();
