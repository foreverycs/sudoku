'use strict';
// 数独·静心一刻 管理后台（阶段五）
const API = 'https://sudoku.southerly.top/api/index.php';
const TOKEN_KEY = 'sudoku-admin-token';
const USER_KEY = 'sudoku-admin-user';

const $ = s => document.querySelector(s);
const $$ = s => document.querySelectorAll(s);

let token = localStorage.getItem(TOKEN_KEY) || '';
let adminUser = null;

function setToken(t) { token = t || ''; if (t) localStorage.setItem(TOKEN_KEY, t); else localStorage.removeItem(TOKEN_KEY); }

async function api(route, method, data) {
  const headers = { 'Content-Type': 'application/json' };
  if (token) headers.Authorization = 'Bearer ' + token;
  const opts = { method: method || 'GET', headers };
  if (data !== undefined && data !== null) opts.body = JSON.stringify(data);
  // route 可能带 query（如 admin/users?page=1），r 值须编码，query 单独拼
  let path = route, qs = '';
  const qi = route.indexOf('?');
  if (qi >= 0) { path = route.slice(0, qi); qs = route.slice(qi + 1); }
  const url = API + '?r=' + encodeURIComponent(path) + (qs ? '&' + qs : '');
  const res = await fetch(url, opts);
  let json = null;
  try { json = await res.json(); } catch (e) { /* 空 */ }
  if (!json) throw new Error('网络异常或服务器无响应');
  if (!json.ok) { const e = new Error(json.error || '请求失败'); e.status = res.status; throw e; }
  return json.data;
}

// ===== 登录 =====
$('#login-form').addEventListener('submit', async e => {
  e.preventDefault();
  const msg = $('#login-msg');
  msg.textContent = '';
  const username = $('#username').value.trim();
  const password = $('#password').value;
  try {
    const d = await api('auth/login', 'POST', { username, password });
    if (d.user.role !== 'admin') throw new Error('非管理员账号');
    setToken(d.token); adminUser = d.user;
    localStorage.setItem(USER_KEY, JSON.stringify(d.user));
    showAdmin();
  } catch (err) {
    msg.textContent = err.message || '登录失败';
  }
});

$('#logout').addEventListener('click', () => {
  setToken(''); adminUser = null; localStorage.removeItem(USER_KEY);
  location.reload();
});

// ===== 导航 =====
const TITLES = {
  overview: ['数据概览', '站点运营数据一览'],
  users: ['用户管理', '管理注册用户，支持封禁、角色调整与进度重置'],
  achievements: ['勋章管理', '启用/停用勋章，查看各勋章解锁人数'],
  logs: ['登录日志', '最近登录的用户'],
};
$('#nav').addEventListener('click', e => {
  const b = e.target.closest('button[data-tab]');
  if (!b) return;
  $$('#nav button').forEach(x => x.classList.remove('active'));
  b.classList.add('active');
  const tab = b.dataset.tab;
  $('#page-title').textContent = TITLES[tab][0];
  $('#page-desc').textContent = TITLES[tab][1];
  load(tab);
});

function showAdmin() {
  $('#login-page').classList.add('hidden');
  $('#admin-page').classList.remove('hidden');
  $('#who').textContent = (adminUser ? adminUser.nickname || adminUser.username : '') + '（管理员）';
  load('overview');
}

function load(tab) {
  const c = $('#content');
  if (tab === 'overview') loadOverview(c);
  else if (tab === 'users') loadUsers(c);
  else if (tab === 'achievements') loadAchievements(c);
  else if (tab === 'logs') loadLogs(c);
}

// ===== 概览 =====
async function loadOverview(c) {
  c.innerHTML = '<div class="loading">加载中…</div>';
  try {
    const d = await api('admin/overview');
    const daily = d.daily || [];
    const maxC = Math.max(1, ...daily.map(x => x.count));
    c.innerHTML =
      '<div class="grid">' +
      stat(d.users, '注册用户') + stat(d.records, '完成记录') + stat(d.activeUsers, '活跃用户') +
      stat(d.achievements, '在售勋章') + stat(Math.round(d.totalSeconds / 60) + '分', '累计游玩') +
      stat('Lv.' + d.maxLevel, '最高等级') +
      '</div>' +
      '<div class="card"><h3>近 7 天完成量</h3><div class="daily">' +
      daily.map(x => '<div class="bar-col"><span class="bar-v">' + x.count + '</span><div class="bar" style="height:' + (x.count / maxC * 100) + '%"></div><span class="bar-l">' + x.date.slice(5) + '</span></div>').join('') +
      '</div></div>';
  } catch (e) {
    c.innerHTML = '<div class="loading">加载失败：' + e.message + '</div>';
  }
}
function stat(v, l) { return '<div class="stat"><div class="num">' + v + '</div><div class="lab">' + l + '</div></div>'; }

// ===== 用户管理 =====
let userPage = 1, userQ = '', userSort = 'id', userOrder = 'desc';
async function loadUsers(c) {
  c.innerHTML = '<div class="toolbar">' +
    '<input id="q" placeholder="搜索用户名/昵称" value="' + esc(userQ) + '">' +
    '<select id="sort"><option value="id"' + sel(userSort, 'id') + '>按注册时间</option>' +
    '<option value="exp"' + sel(userSort, 'exp') + '>按经验</option>' +
    '<option value="level"' + sel(userSort, 'level') + '>按等级</option></select>' +
    '<button class="btn btn-ghost btn-sm" id="search">搜索</button></div>' +
    '<div class="card"><div id="user-table">加载中…</div></div>';
  $('#search').onclick = () => { userQ = $('#q').value.trim(); userPage = 1; loadUsers(c); };
  $('#q').onkeydown = e => { if (e.key === 'Enter') { userQ = $('#q').value.trim(); userPage = 1; loadUsers(c); } };
  $('#sort').onchange = e => { userSort = e.target.value; loadUsers(c); };
  try {
    const d = await api('admin/users?page=' + userPage + '&size=20&q=' + encodeURIComponent(userQ) + '&sort=' + userSort + '&order=' + userOrder, 'GET');
    renderUsers(c, d);
  } catch (e) {
    $('#user-table').innerHTML = '<div class="loading">加载失败：' + e.message + '</div>';
  }
}
function renderUsers(c, d) {
  const rows = (d.users || []).map(u =>
    '<tr><td>' + u.id + '</td><td>' + esc(u.username) + '</td><td>' + esc(u.nickname || '-') + '</td>' +
    '<td>' + (u.role === 'admin' ? '<span class="badge badge-admin">管理员</span>' : '普通用户') + '</td>' +
    '<td>' + (u.status === 1 ? '<span class="badge badge-ok">正常</span>' : '<span class="badge badge-off">封禁</span>') + '</td>' +
    '<td>Lv.' + u.level + '</td><td>' + u.exp + '</td><td>' + (u.last_login_at || '-') + '</td>' +
    '<td><div class="actions">' + userActions(u) + '</div></td></tr>').join('');
  $('#user-table').innerHTML =
    '<div class="table-wrap"><table><thead><tr><th>ID</th><th>用户名</th><th>昵称</th><th>角色</th><th>状态</th><th>等级</th><th>经验</th><th>最近登录</th><th>操作</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
    '<div class="pager"><button id="prev"' + (d.page <= 1 ? ' disabled' : '') + '>上一页</button>' +
    '<span>第 ' + d.page + ' / ' + Math.max(1, Math.ceil(d.total / d.size)) + ' 页（共 ' + d.total + ' 人）</span>' +
    '<button id="next"' + (d.page * d.size >= d.total ? ' disabled' : '') + '>下一页</button></div>';
  $('#prev').onclick = () => { if (userPage > 1) { userPage--; loadUsers(c); } };
  $('#next').onclick = () => { if (d.page * d.size < d.total) { userPage++; loadUsers(c); } };
}
function userActions(u) {
  if (u.role === 'admin') return '<span style="color:var(--muted);font-size:12px">内置</span>';
  const ban = u.status === 1
    ? '<button class="btn btn-danger btn-sm" onclick="adminAction(' + u.id + ',\'status\',' + (u.status === 1 ? 0 : 1) + ')">封禁</button>'
    : '<button class="btn btn-ghost btn-sm" onclick="adminAction(' + u.id + ',\'status\',1)">解封</button>';
  const role = u.role === 'user'
    ? '<button class="btn btn-ghost btn-sm" onclick="adminAction(' + u.id + ',\'role\',\'admin\')">设管理员</button>'
    : '';
  const reset = '<button class="btn btn-ghost btn-sm" onclick="if(confirm(\'确认重置该用户进度？\'))adminAction(' + u.id + ',\'reset\')">重置</button>';
  const del = '<button class="btn btn-danger btn-sm" onclick="deleteUser(' + u.id + ')">删除</button>';
  return ban + role + reset + del;
}
async function adminAction(id, type, val) {
  try {
    if (type === 'status') await api('admin/users/' + id + '/status', 'POST', { status: val });
    else if (type === 'role') await api('admin/users/' + id + '/role', 'POST', { role: val });
    else if (type === 'reset') await api('admin/users/' + id + '/reset', 'POST');
    loadUsers($('#content'));
  } catch (e) { alert('操作失败：' + e.message); }
}
let delTarget = null;
function deleteUser(id) {
  delTarget = id;
  $('#confirm-text').textContent = '将永久删除用户 #' + id + ' 及其全部进度、记录与勋章。此操作不可恢复，确认继续？';
  const m = $('#confirm-modal');
  m.style.display = 'grid';
}
function closeConfirm() { $('#confirm-modal').style.display = 'none'; delTarget = null; }
$('#confirm-cancel').addEventListener('click', closeConfirm);
$('#confirm-ok').addEventListener('click', async () => {
  const id = delTarget;
  closeConfirm();
  if (!id) return;
  try {
    await api('admin/users/' + id + '/delete', 'POST');
    alert('用户已删除');
    loadUsers($('#content'));
  } catch (e) { alert('删除失败：' + e.message); }
});

// ===== 勋章管理 =====
async function loadAchievements(c) {
  c.innerHTML = '<div class="loading">加载中…</div>';
  try {
    const d = await api('admin/achievements');
    const rows = (d.achievements || []).map(a =>
      '<tr><td>' + a.id + '</td><td><code>' + esc(a.code) + '</code></td><td>' + esc(a.name) + '</td>' +
      '<td>' + esc(a.description || '-') + '</td><td>' + catName(a.category) + '</td>' +
      '<td>' + a.cond_type + '=' + a.cond_value + '</td><td>+' + a.points + '</td>' +
      '<td>' + a.unlocked_count + '</td>' +
      '<td>' + (a.active ? '<span class="badge badge-ok">启用</span>' : '<span class="badge badge-off">停用</span>') + '</td>' +
      '<td><button class="btn ' + (a.active ? 'btn-danger' : 'btn-ghost') + ' btn-sm" onclick="toggleAch(' + a.id + ')">' + (a.active ? '停用' : '启用') + '</button></td></tr>').join('');
    c.innerHTML = '<div class="card"><div class="table-wrap"><table><thead><tr><th>ID</th><th>代码</th><th>名称</th><th>说明</th><th>分类</th><th>条件</th><th>经验</th><th>解锁数</th><th>状态</th><th>操作</th></tr></thead><tbody>' + rows + '</tbody></table></div></div>';
  } catch (e) {
    c.innerHTML = '<div class="loading">加载失败：' + e.message + '</div>';
  }
}
function catName(c) {
  return { progress: '进度', speed: '速度', persistence: '坚持', challenge: '挑战', daily: '每日' }[c] || c;
}
async function toggleAch(id) {
  try { await api('admin/achievements/' + id + '/toggle', 'POST'); loadAchievements($('#content')); }
  catch (e) { alert('操作失败：' + e.message); }
}

// ===== 日志 =====
async function loadLogs(c) {
  c.innerHTML = '<div class="loading">加载中…</div>';
  try {
    const d = await api('admin/logs');
    const rows = (d.logs || []).map(l =>
      '<tr><td>' + l.id + '</td><td>' + esc(l.username) + '</td><td>' + esc(l.nickname || '-') + '</td><td>' + l.last_login_at + '</td></tr>').join('');
    c.innerHTML = '<div class="card"><div class="table-wrap"><table><thead><tr><th>ID</th><th>用户名</th><th>昵称</th><th>最近登录</th></tr></thead><tbody>' + (rows || '<tr><td colspan="4" style="color:var(--muted)">暂无登录记录</td></tr>') + '</tbody></table></div></div>';
  } catch (e) {
    c.innerHTML = '<div class="loading">加载失败：' + e.message + '</div>';
  }
}

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m])); }
function sel(a, b) { return a === b ? ' selected' : ''; }

// 初始化：有 token 则直接进后台
(function init() {
  const saved = localStorage.getItem(USER_KEY);
  if (saved) { try { adminUser = JSON.parse(saved); } catch (e) { adminUser = null; } }
  if (token && adminUser && adminUser.role === 'admin') { showAdmin(); return; }
  $('#login-page').classList.remove('hidden');
})();
