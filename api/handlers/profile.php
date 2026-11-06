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

/** GET profile：个人主页聚合数据（用户/统计/各关最佳/最近成绩/每日/勋章） */
function profile_get() {
    $c = current_user();
    $uid = (int)$c['uid'];
    $pdo = db();
    $user = user_pub($uid);
    if (!$user) fail('用户不存在', 404);
    $stats = user_stats($uid);

    // 各关最佳（level -> best seconds）
    $st = $pdo->prepare('SELECT level, MIN(seconds) AS best FROM game_records WHERE user_id = ? GROUP BY level ORDER BY level');
    $st->execute([$uid]);
    $bestByLevel = array();
    foreach ($st->fetchAll() as $r) $bestByLevel[(int)$r['level']] = (int)$r['best'];

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
        'bestByLevel' => $bestByLevel,
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
