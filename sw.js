/* Service Worker —— 数独 · 静心一刻（PWA 离线缓存）
 * 缓存应用外壳：首屏资源离线可用；API 不缓存（动态数据），断网时游戏核心本地可玩。
 */
const CACHE = 'sudoku-shell-v4';
const ASSETS = [
  '/',
  '/index.html',
  '/sudoku.css?v=25',
  '/sudoku.js?v=25',
  '/sudoku-data.js?v=17',
  '/manifest.json',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/icons/apple-touch-icon.png'
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (url.origin !== location.origin) return;               // 跨域（如第三方字体）不接管
  if (url.pathname.startsWith('/api/')) return;             // API 走网络，不缓存动态数据
  if (e.request.method !== 'GET') return;

  // 导航：网络优先，离线回退缓存外壳
  if (e.request.mode === 'navigate') {
    e.respondWith(
      fetch(e.request)
        .then(res => {
          const cp = res.clone();
          caches.open(CACHE).then(c => c.put('/', cp));
          return res;
        })
        .catch(() => caches.match('/'))
    );
    return;
  }

  // 静态资源：缓存优先，未命中则网络并回填缓存
  e.respondWith(
    caches.match(e.request).then(hit => hit || fetch(e.request).then(res => {
      if (res && res.ok) {
        const cp = res.clone();
        caches.open(CACHE).then(c => c.put(e.request, cp));
      }
      return res;
    }))
  );
});
