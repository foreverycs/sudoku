(() => {
  'use strict';
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

  let board = [], given = [], notes = [], selected = -1, noteMode = false, history = [], seconds = 0, timerId = null, sayTimer = null;
  let solution = [];

  const $ = id => document.getElementById(id);
  const boardEl = $('board');

  const fmt = s => String((s / 60) | 0).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
  const difficulty = l => ['入门', '入门', '简单', '简单', '中等', '中等', '困难', '困难', '挑战', '挑战'][Math.min(9, Math.floor((l - 1) / 10))];

  function persist() {
    store.write({ unlocked, completed: [...completed], level, boards: saved.boards || {}, challenge, best });
  }
  function saveGame() {
    saved.boards = saved.boards || {};
    saved.boards[level] = { cells: board.slice(), notes: notes.map(s => [...s]), seconds, ts: Date.now() };
    persist();
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
    if (st && st.ts) {
      const gap = (Date.now() - st.ts) / 1000;
      if (gap > 1 && gap < 120) seconds = Math.min(1e5, seconds + Math.floor(gap));
    }
    noteMode = false;
    $('notes').classList.remove('active');
    persist(); render(); startTimer();
  }

  function startTimer() {
    clearInterval(timerId);
    updateTimer();
    timerId = setInterval(() => { seconds++; updateTimer(); if (seconds % 5 === 0) saveGame(); }, 1000);
  }
  function updateTimer() { $('timer').textContent = fmt(seconds); }

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
    completed.add(level);
    unlocked = Math.max(unlocked, Math.min(N, level + 1));
    const t = seconds, prev = best[level], isRecord = !prev || t < prev;
    if (isRecord) best[level] = t;
    saveGame();
    clearInterval(timerId);
    $('modal-time').textContent = fmt(t);
    $('modal-best').textContent = isRecord ? '新纪录！' : fmt(prev);
    $('modal-record').style.display = isRecord ? 'inline-block' : 'none';
    $('overlay').classList.add('show');
    render();
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

  // 页面隐藏/卸载时立即保存（配合节流，避免丢失最后几秒进度）
  document.addEventListener('visibilitychange', () => { if (document.hidden) saveGame(); });
  window.addEventListener('pagehide', saveGame);

  // 深浅色主题
  const THEME_KEY = 'quiet-sudoku-theme';
  const mqDark = window.matchMedia('(prefers-color-scheme: dark)');
  const themeBtn = $('theme');
  function applyTheme() {
    const saved = localStorage.getItem(THEME_KEY);
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
      const cur = localStorage.getItem(THEME_KEY);
      const next = cur ? (cur === 'dark' ? 'light' : 'dark') : (mqDark.matches ? 'light' : 'dark');
      localStorage.setItem(THEME_KEY, next);
      applyTheme();
    };
    mqDark.addEventListener('change', () => { if (!localStorage.getItem(THEME_KEY)) applyTheme(); });
  }
  applyTheme();

  loadLevel(level);
})();
