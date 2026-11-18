<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';
require_once __DIR__ . '/../resp.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../compat.php';
require_once __DIR__ . '/auth.php';   // exp_needed / level_exp_into / daily_streak / user_pub
require_once __DIR__ . '/game.php';   // user_stats

/** PUT me/avatar：设置头像（emoji 或图片 URL，短文本，无 <> 防 XSS） */
function me_avatar_put() {
    $c = current_user();
    $b = body();
    $avatar = isset($b['avatar']) ? trim((string)$b['avatar']) : '';
    if ($avatar === '') fail('头像不能为空');
    if (strlen($avatar) > 64) fail('头像过长');
    if (preg_match('/[<>]/', $avatar)) fail('头像含非法字符');
    $pdo = db();
    $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$avatar, (int)$c['uid']]);
    ok(['user' => user_pub((int)$c['uid'])]);
}

/** POST me/password：修改密码（校验旧密码，token_version+1 使旧会话失效，返回新 token） */
function me_password_put() {
    $c = current_user();
    $b = body();
    $old = isset($b['old_password']) ? (string)$b['old_password'] : '';
    $new = isset($b['new_password']) ? (string)$b['new_password'] : '';
    if (strlen($new) < 6 || strlen($new) > 64) fail('新密码长度需 6-64 位');
    if ($new === $old) fail('新密码不能与旧密码相同');
    $pdo = db();
    $uid = (int)$c['uid'];
    $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u) fail('用户不存在', 404);
    if (!ph_verify($old, $u['password_hash'])) fail('当前密码不正确', 403);
    $pdo->prepare('UPDATE users SET password_hash = ?, token_version = token_version + 1 WHERE id = ?')
        ->execute([ph_hash($new), $uid]);
    $st = $pdo->prepare('SELECT username, role FROM users WHERE id = ?');
    $st->execute([$uid]);
    $nu = $st->fetch();
    ok(['changed' => true, 'token' => issue_token($uid, $nu['username'], $nu['role']), 'user' => user_pub($uid)]);
}

/** POST me/delete：删除账号（密码确认，级联删除全部数据；管理员请走后台） */
function me_delete() {
    $c = current_user();
    $b = body();
    $pw = isset($b['password']) ? (string)$b['password'] : '';
    $pdo = db();
    $uid = (int)$c['uid'];
    $st = $pdo->prepare('SELECT password_hash, role FROM users WHERE id = ?');
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u) fail('用户不存在', 404);
    if ($u['role'] === 'admin') fail('管理员账号请使用后台管理删除', 403);
    if (!ph_verify($pw, $u['password_hash'])) fail('密码不正确', 403);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
    ok(['deleted' => true]);
}

/** GET me/export：导出个人数据（JSON） */
function me_export() {
    $c = current_user();
    $uid = (int)$c['uid'];
    $pdo = db();

    $st = $pdo->prepare('SELECT level, cells, notes, seconds, revision, updated_at FROM game_progress WHERE user_id = ? ORDER BY level');
    $st->execute([$uid]);
    $progress = $st->fetchAll();

    $st = $pdo->prepare('SELECT level, seconds, is_record, played_at FROM game_records WHERE user_id = ? ORDER BY id');
    $st->execute([$uid]);
    $records = $st->fetchAll();
    foreach ($records as &$r) { $r['is_record'] = (bool)$r['is_record']; }
    unset($r);

    $st = $pdo->prepare('SELECT date, seconds, solved_at FROM daily_records WHERE user_id = ? ORDER BY date');
    $st->execute([$uid]);
    $daily = $st->fetchAll();

    $st = $pdo->prepare(
        'SELECT a.code, a.name, a.description, a.category, a.points, ua.unlocked_at'
        . ' FROM user_achievements ua JOIN achievements a ON a.id = ua.achievement_id'
        . ' WHERE ua.user_id = ? ORDER BY ua.unlocked_at'
    );
    $st->execute([$uid]);
    $achievements = $st->fetchAll();

    $st = $pdo->prepare('SELECT COUNT(*) AS c FROM game_progress_history WHERE user_id = ?');
    $st->execute([$uid]);
    $historyCount = (int)$st->fetch()['c'];

    ok(array(
        'version'      => 1,
        'exportedAt'   => date('c'),
        'user'         => user_pub($uid),
        'stats'        => user_stats($uid),
        'progress'     => $progress,
        'records'      => $records,
        'daily'        => $daily,
        'achievements' => $achievements,
        'historyCount' => $historyCount,
    ));
}

/** GET profile：个人主页聚合数据（用户/统计/各关最佳/最近成绩/每日/勋章） */
function profile_get() {
    $c = current_user();
    $uid = (int)$c['uid'];
    $pdo = db();
    $user = user_pub($uid);
    if (!$user) fail('用户不存在', 404);
    $stats = user_stats($uid);

    // 最近完成记录（前 10 条）
    $st = $pdo->prepare('SELECT level, seconds, is_record, played_at FROM game_records WHERE user_id = ? ORDER BY id DESC LIMIT 10');
    $st->execute([$uid]);
    $recent = $st->fetchAll();
    foreach ($recent as &$r) {
        $r['level'] = (int)$r['level'];
        $r['seconds'] = (int)$r['seconds'];
        $r['is_record'] = (bool)$r['is_record'];
    }
    unset($r);

    // 每日挑战：总数 + 今日是否完成 + 连胜
    $st = $pdo->prepare('SELECT COUNT(*) AS c FROM daily_records WHERE user_id = ?');
    $st->execute([$uid]);
    $row = $st->fetch();
    $dailyTotal = (int)$row['c'];
    $st = $pdo->prepare('SELECT COUNT(*) AS c FROM daily_records WHERE user_id = ? AND date = ?');
    $st->execute([$uid, date('Y-m-d')]);
    $row = $st->fetch();
    $todayDone = ((int)$row['c'] > 0);

    // 已解锁勋章 + 总数
    $st = $pdo->prepare(
        'SELECT a.id, a.code, a.name, a.description, a.icon, a.category, a.points, ua.unlocked_at'
        . ' FROM achievements a JOIN user_achievements ua ON ua.achievement_id = a.id AND ua.user_id = ?'
        . ' WHERE a.active = 1 ORDER BY ua.unlocked_at'
    );
    $st->execute([$uid]);
    $ach = $st->fetchAll();
    $st = $pdo->prepare('SELECT COUNT(*) AS c FROM achievements WHERE active = 1');
    $st->execute(array());
    $row = $st->fetch();
    $achTotal = (int)$row['c'];

    ok(array(
        'user' => $user,
        'expNext' => exp_needed($user['level']),
        'levelExp' => level_exp_into($user['exp'], $user['level']),
        'stats' => $stats,
        'recent' => $recent,
        'daily' => array(
            'todayDone' => $todayDone,
            'streak' => daily_streak($uid),
            'total' => $dailyTotal,
        ),
        'achievements' => $ach,
        'achievementTotal' => $achTotal,
    ));
}
