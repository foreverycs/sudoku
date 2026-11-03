<?php
/**
 * 数独·静心一刻 API 入口（路由）
 * 兼容 PHP 5.4（无 ?? / Throwable / password_hash，见 compat.php）
 *
 * 路由方式（宝塔 nginx 无需伪静态，直接访问 index.php）：
 *   POST /api/index.php?r=auth/register
 *   POST /api/index.php?r=auth/login
 *   GET  /api/index.php?r=auth/me
 * 也兼容路径式（需 nginx/Apache 重写 /api/xxx → 本文件）。
 */
require_once __DIR__ . '/resp.php';
require_once __DIR__ . '/handlers/auth.php';
require_once __DIR__ . '/handlers/progress.php';

// CORS
header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'OPTIONS') { http_response_code(204); exit; }

// 解析路由：优先 ?r=，其次路径 /api/xxx
$r = isset($_GET['r']) ? trim((string)$_GET['r'], '/') : '';
if ($r === '') {
    $path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
    $path = $path ? $path : '/';
    $r = trim((string)preg_replace('#^/api#', '', $path), '/');
}
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

try {
    if ($method === 'POST' && $r === 'auth/register') return auth_register();
    if ($method === 'POST' && $r === 'auth/login')    return auth_login();
    if ($method === 'GET'  && $r === 'auth/me')       return auth_me();
    if ($method === 'GET'  && $r === 'progress')      return progress_all();
    if ($method === 'PUT'  && preg_match('#^progress/(\d{1,3})$#', $r, $m)) return progress_save((int)$m[1]);
    fail('接口不存在', 404);
} catch (Exception $e) {
    fail('服务器错误：' . $e->getMessage(), 500);
}
