<?php
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/resp.php';

/** 从请求头取 Bearer token（PHP 5.4 无 ??，用 isset 判断） */
function bearer_token() {
    $hdr = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if ($hdr === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    if ($hdr === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') { $hdr = $v; break; }
        }
    }
    if (preg_match('/Bearer\s+(.+)/i', $hdr, $m)) return trim($m[1]);
    return null;
}

/** 当前登录用户（JWT payload），未登录则 401 */
function current_user() {
    $token = bearer_token();
    if ($token) {
        $p = jwt_decode($token);
        if ($p && isset($p['uid'])) return $p;
    }
    fail('未登录', 401);
}

/** 当前管理员，非 admin 则 403 */
function require_admin() {
    $u = current_user();
    if (!isset($u['role']) || $u['role'] !== 'admin') fail('无权限', 403);
    return $u;
}
