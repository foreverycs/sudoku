<?php
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/resp.php';
require_once __DIR__ . '/db.php';

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
        if ($p && isset($p['uid']) && (int)$p['uid'] > 0) {
            // 每次请求校验用户状态和角色，实现 JWT 即时失效（封禁/降权立即生效）
            $st = db()->prepare('SELECT id, role, status, token_version FROM users WHERE id = ?');
            $st->execute([(int)$p['uid']]);
            $u = $st->fetch();
            if (!$u) fail('用户不存在', 404);
            if ((int)$u['status'] !== 1) fail('账号已被封禁', 403);
            // token_version 不一致（如改密码）→ 旧 token 失效
            $tv = isset($p['tv']) ? (int)$p['tv'] : 0;
            if ($tv !== (int)$u['token_version']) fail('登录已失效，请重新登录', 401);
            $p['role'] = $u['role']; // 刷新角色
            return $p;
        }
    }
    fail('未登录', 401);
}

/** 当前管理员，非 admin 则 403 */
function require_admin() {
    $u = current_user();
    if (!isset($u['role']) || $u['role'] !== 'admin') fail('无权限', 403);
    return $u;
}
