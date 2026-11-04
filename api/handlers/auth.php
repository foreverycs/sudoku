<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';
require_once __DIR__ . '/../resp.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../compat.php';

/** 每级所需经验 */
function exp_needed($level) {
    return 100 + ((int)$level - 1) * 50;
}

/** 由累计经验推算等级 */
function calc_level($exp) {
    $level = 1; $remain = (int)$exp;
    while (true) {
        $need = exp_needed($level);
        if ($remain >= $need) { $remain -= $need; $level++; }
        else break;
    }
    return $level;
}

/** 当前级内已有经验（用于前端进度条） */
function level_exp_into($exp, $level) {
    $cum = 0;
    for ($i = 1; $i < (int)$level; $i++) $cum += exp_needed($i);
    return max(0, (int)$exp - $cum);
}

/** 注册 */
function auth_register() {
    $b = body();
    $username = trim(isset($b['username']) ? (string)$b['username'] : '');
    $password = isset($b['password']) ? (string)$b['password'] : '';
    $nickname = trim(isset($b['nickname']) ? (string)$b['nickname'] : '');
    if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) fail('用户名需 3-32 位字母、数字或下划线');
    if (strlen($password) < 6 || strlen($password) > 64) fail('密码长度需 6-64 位');
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $st->execute([$username]);
    if ($st->fetch()) fail('用户名已存在', 409);
    $st = $pdo->prepare('INSERT INTO users (username, password_hash, nickname) VALUES (?,?,?)');
    $st->execute([$username, ph_hash($password), $nickname !== '' ? $nickname : $username]);
    $uid = (int)$pdo->lastInsertId();
    ok(['token' => issue_token($uid, $username, 'user'), 'user' => user_pub($uid), 'expNext' => exp_needed(1), 'levelExp' => 0]);
}

/** 登录 */
function auth_login() {
    $b = body();
    $username = trim(isset($b['username']) ? (string)$b['username'] : '');
    $password = isset($b['password']) ? (string)$b['password'] : '';
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    if (!$u || !ph_verify($password, $u['password_hash'])) fail('用户名或密码错误', 401);
    if ((int)$u['status'] !== 1) fail('账号已被封禁', 403);
    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
    ok([
        'token'   => issue_token((int)$u['id'], $u['username'], $u['role']),
        'user'    => user_pub((int)$u['id']),
        'expNext' => exp_needed($u['level']),
        'levelExp' => level_exp_into($u['exp'], $u['level']),
    ]);
}

/** 当前用户信息 */
function auth_me() {
    $c = current_user();
    $st = db()->prepare('SELECT id, username, nickname, avatar, role, status, exp, level, created_at, last_login_at FROM users WHERE id = ?');
    $st->execute([$c['uid']]);
    $row = $st->fetch();
    if (!$row) fail('用户不存在', 404);
    ok(['user' => $row, 'expNext' => exp_needed($row['level']), 'levelExp' => level_exp_into($row['exp'], $row['level'])]);
}

/** 签发 token */
function issue_token($uid, $username, $role) {
    return jwt_encode(['uid' => (int)$uid, 'username' => $username, 'role' => $role, 'exp' => time() + JWT_EXPIRE]);
}

/** 用户公开信息 */
function user_pub($uid) {
    $st = db()->prepare('SELECT id, username, nickname, avatar, role, exp, level, created_at FROM users WHERE id = ?');
    $st->execute([(int)$uid]);
    $r = $st->fetch();
    return $r ? $r : [];
}

// ===== linux.do OAuth2 登录 =====

/** 常量时间字符串比较（PHP 5.4 无 hash_equals） */
function ld_hash_equals($a, $b) {
    if (strlen($a) !== strlen($b)) return false;
    $r = 0;
    for ($i = 0, $n = strlen($a); $i < $n; $i++) $r |= ord($a[$i]) ^ ord($b[$i]);
    return $r === 0;
}

/** 随机 hex 串 */
function ld_rand($len) {
    $b = openssl_random_pseudo_bytes($len);
    return $b !== false ? bin2hex($b) : substr(md5(uniqid('', true)), 0, $len * 2);
}

/** 发 HTTP 请求（服务器 PHP 5.4 带 curl） */
function ld_http($url, $method, $headers, $body) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    if (LINUXDO_RESOLVE !== '' && defined('CURLOPT_RESOLVE')) curl_setopt($ch, CURLOPT_RESOLVE, array(LINUXDO_RESOLVE));
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $out = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($out === false) return array('_error' => $err);
    $j = json_decode($out, true);
    return is_array($j) ? $j : array('_raw' => $out);
}

/** 从用户信息里取第一个非空字段 */
function ld_pick($info, $keys) {
    foreach ($keys as $k) {
        if (isset($info[$k]) && trim((string)$info[$k]) !== '') return trim((string)$info[$k]);
    }
    return '';
}

/** 生成唯一用户名（优先 linux.do username，冲突则加后缀） */
function ld_make_username($pdo, $info) {
    $base = ld_pick($info, array('username', 'name'));
    if ($base === '') $base = 'lduser';
    $base = preg_replace('/[^A-Za-z0-9_]/', '_', $base);
    if (strlen($base) < 3) $base = 'ld_' . $base;
    if (strlen($base) > 28) $base = substr($base, 0, 28);
    $cand = $base;
    $i = 1;
    while (true) {
        $st = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $st->execute(array($cand));
        if (!$st->fetch()) return $cand;
        $suffix = '_' . $i;
        $cand = substr($base, 0, 32 - strlen($suffix)) . $suffix;
        $i++;
    }
}

/** 跳回前端并提示错误 */
function ld_redirect_err($msg) {
    header('Location: ' . LINUXDO_FRONT . '?login=linuxdo&err=' . urlencode($msg));
    exit;
}

/** GET auth/linuxdo/start：生成 state 存 cookie，跳转 linux.do 授权页 */
function auth_linuxdo_start() {
    $state = bin2hex(openssl_random_pseudo_bytes(16));
    if ($state === false) $state = substr(md5(uniqid('', true)), 0, 32);
    setcookie('ld_oauth_state', $state, time() + 600, '/', '', false, true);
    $q = http_build_query(array(
        'client_id'     => LINUXDO_CLIENT_ID,
        'redirect_uri'  => LINUXDO_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => LINUXDO_SCOPE,
        'state'         => $state,
    ));
    header('Location: ' . LINUXDO_AUTH_URL . '?' . $q);
    exit;
}

/** GET auth/linuxdo/callback：换 token → 取用户 → 登录/注册 → 发 JWT 跳回前端 */
function auth_linuxdo_callback() {
    $code  = isset($_GET['code']) ? (string)$_GET['code'] : '';
    $state = isset($_GET['state']) ? (string)$_GET['state'] : '';
    $saved = isset($_COOKIE['ld_oauth_state']) ? (string)$_COOKIE['ld_oauth_state'] : '';
    if ($code === '' || $state === '' || $saved === '' || !ld_hash_equals($saved, $state)) {
        ld_redirect_err('登录状态校验失败，请重试');
    }
    // 1) 授权码换令牌（form-urlencoded）
    $tk = ld_http(LINUXDO_TOKEN_URL, 'POST', array(
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ), http_build_query(array(
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => LINUXDO_REDIRECT_URI,
        'client_id'     => LINUXDO_CLIENT_ID,
        'client_secret' => LINUXDO_CLIENT_SECRET,
    )));
    if (!isset($tk['access_token']) || $tk['access_token'] === '') {
        ld_redirect_err('获取授权令牌失败，请重试');
    }
    // 2) 取用户信息
    $info = ld_http(LINUXDO_USER_URL, 'GET', array(
        'Authorization: Bearer ' . $tk['access_token'],
        'Accept: application/json',
    ), null);
    $luid = isset($info['id']) ? (string)$info['id'] : '';
    if ($luid === '') ld_redirect_err('获取用户信息失败，请重试');
    // 3) 登录或自动注册
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM users WHERE oauth_provider = ? AND oauth_uid = ?');
    $st->execute(array('linuxdo', $luid));
    $u = $st->fetch();
    if (!$u) {
        $username = ld_make_username($pdo, $info);
        $nickname = ld_pick($info, array('name', 'nickname', 'username'));
        $avatar   = ld_pick($info, array('avatar_url', 'avatar'));
        $st = $pdo->prepare('INSERT INTO users (username, password_hash, nickname, avatar, role, status, oauth_provider, oauth_uid) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute(array($username, ph_hash(ld_rand(32)), $nickname, $avatar, 'user', 1, 'linuxdo', $luid));
        $uid = (int)$pdo->lastInsertId();
    } else {
        $uid = (int)$u['id'];
        if ((int)$u['status'] !== 1) ld_redirect_err('账号已被封禁');
        $username = $u['username'];
        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute(array($uid));
    }
    $jwt = issue_token($uid, $username, 'user');
    header('Location: ' . LINUXDO_FRONT . '?login=linuxdo&token=' . urlencode($jwt));
    exit;
}
