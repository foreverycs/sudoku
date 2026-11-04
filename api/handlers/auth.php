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
