/* Service Worker —— 数独 · 静心一刻（PWA 离线缓存）
 * 缓存应用外壳：首屏资源离线可用；API 不缓存（动态数据），断网时游戏核心本地可玩。
 * 版本统一：CACHE 名含版本（发版时 bump 一次），资源 ?v= 从 index.html 动态解析，避免与页面引用不同步。
 */
const CACHE = 'sudoku-shell-v8';

// 从 index.html 解析当前资源版本号（与页面引用保持一致，免去多处硬编码）
function buildAssets() {
  return fetch('/index.html', { cache: 'no-store' })
    .then(res => res.text())
    .then(html => {
      const q = re => { const m = html.match(re); return m ? m[1] : ''; };
      return [
        '/', '/index.html', '/manifest.json',
        '/icons/icon-192.png', '/icons/icon-512.png', '/icons/apple-touch-icon.png',
        '/sudoku.css?v=' + q(/sudoku\.css\?v=([\d]+)/),
        '/sudoku.js?v=' + q(/sudoku\.js\?v=([\d]+)/),
        '/sudoku-data.js?v=' + q(/sudoku-data\.js\?v=([\d]+)/)
      ];
    })
    .catch(() => ['/', '/index.html', '/manifest.json']); // 解析失败回退最小外壳
}

self.addEventListener('install', e => {
  e.waitUntil(
    buildAssets()
      .then(assets => caches.open(CACHE).then(c => c.addAll(assets)))
      .then(() => self.skipWaiting())
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
