<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';
require_once __DIR__ . '/../resp.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../compat.php';
require_once __DIR__ . '/auth.php'; // exp_needed / calc_level / level_exp_into

/** 按难度给基础经验：入门20/简单30/中等40/困难50/挑战60（1-100 关，每20关一档） */
function difficulty_exp($level) {
    $l = (int)$level;
    if ($l <= 20) return 20;
    if ($l <= 40) return 30;
    if ($l <= 60) return 40;
    if ($l <= 80) return 50;
    return 60;
}

/** 用户统计（勋章判定依据） */
function user_stats($uid) {
    $st = db()->prepare('SELECT COUNT(DISTINCT level) AS cc, COALESCE(SUM(seconds),0) AS ts, COALESCE(MIN(seconds),0) AS bm, COALESCE(MAX(level),0) AS ml FROM game_records WHERE user_id = ?');
    $st->execute([(int)$uid]);
    $r = $st->fetch();
    return array(
        'complete_count' => (int)$r['cc'],
        'total_seconds'  => (int)$r['ts'],
        'best_min'       => (int)$r['bm'],
        'max_level'      => (int)$r['ml'],
    );
}

/** 勋章条件判定（daily_streak_ge 需登录日志表，暂不判定） */
function achievement_met($condType, $condValue, $stats) {
    switch ($condType) {
        case 'complete_count':   return $stats['complete_count'] >= (int)$condValue;
        case 'best_seconds_lt':  return $stats['best_min'] > 0 && $stats['best_min'] < (int)$condValue;
        case 'total_seconds_ge': return $stats['total_seconds'] >= (int)$condValue;
        case 'level_ge':         return $stats['max_level'] >= (int)$condValue;
        case 'daily_streak_ge':  return false;
    }
    return false;
}

/** POST game/complete：结算关卡完成（经验 + 勋章） */
function game_complete() {
    $c = current_user();
    $b = body();
    $level   = isset($b['level']) ? (int)$b['level'] : 0;
    $seconds = isset($b['seconds']) ? (int)$b['seconds'] : 0;
    if ($level < 1 || $level > 100) fail('关卡无效');
    if ($seconds < 0 || $seconds > 100000) fail('用时无效');
    $pdo = db();
    $uid = (int)$c['uid'];

    // 该关历史（首次 / 最佳判定）
    $st = $pdo->prepare('SELECT COUNT(*) AS cnt, COALESCE(MIN(seconds),0) AS best FROM game_records WHERE user_id = ? AND level = ?');
    $st->execute([$uid, $level]);
    $rec = $st->fetch();
    $first    = ((int)$rec['cnt'] === 0);
    $isRecord = $first || $seconds < (int)$rec['best'];

    // 经验：基础 + 首次×1.5 + 刷新最佳+20
    $base = difficulty_exp($level);
    $expGain = $base;
    if ($first) $expGain += (int)floor($base * 0.5);
    if ($isRecord) $expGain += 20;

    // 记录通关
    $pdo->prepare('INSERT INTO game_records (user_id, level, seconds, is_record) VALUES (?,?,?,?)')
        ->execute([$uid, $level, $seconds, $isRecord ? 1 : 0]);

    // 勋章判定（仅未解锁）
    $stats = user_stats($uid);
    $st = $pdo->prepare('SELECT a.*, (ua.id IS NOT NULL) AS unlocked FROM achievements a LEFT JOIN user_achievements ua ON ua.achievement_id = a.id AND ua.user_id = ? WHERE a.active = 1');
    $st->execute([$uid]);
    $newAch = array();
    foreach ($st->fetchAll() as $a) {
        if ($a['unlocked']) continue;
        if (achievement_met($a['cond_type'], $a['cond_value'], $stats)) {
            $pdo->prepare('INSERT IGNORE INTO user_achievements (user_id, achievement_id) VALUES (?,?)')->execute([$uid, $a['id']]);
            $expGain += (int)$a['points'];
            $newAch[] = array(
                'code' => $a['code'], 'name' => $a['name'], 'description' => $a['description'],
                'icon' => $a['icon'], 'category' => $a['category'], 'points' => (int)$a['points'],
            );
        }
    }

    // 更新用户经验/等级
    $st = $pdo->prepare('SELECT exp, level FROM users WHERE id = ?');
    $st->execute([$uid]);
    $u = $st->fetch();
    $oldExp = (int)$u['exp']; $oldLevel = (int)$u['level'];
    $newExp = $oldExp + $expGain;
    $newLevel = calc_level($newExp);
    $pdo->prepare('UPDATE users SET exp = ?, level = ? WHERE id = ?')->execute([$newExp, $newLevel, $uid]);

    ok(array(
        'expGain'  => $expGain,
        'exp'      => $newExp,
        'level'    => $newLevel,
        'oldLevel' => $oldLevel,
        'levelUp'  => $newLevel > $oldLevel,
        'levelExp' => level_exp_into($newExp, $newLevel),
        'expNext'  => exp_needed($newLevel),
        'isRecord' => $isRecord,
        'first'    => $first,
        'newAchievements' => $newAch,
    ));
}

/** GET achievements：勋章列表 + 用户解锁状态 + 统计 */
function achievements_list() {
    $c = current_user();
    $st = db()->prepare('SELECT a.id, a.code, a.name, a.description, a.icon, a.category, a.points, (ua.id IS NOT NULL) AS unlocked, ua.unlocked_at FROM achievements a LEFT JOIN user_achievements ua ON ua.achievement_id = a.id AND ua.user_id = ? WHERE a.active = 1 ORDER BY a.category, a.cond_value, a.id');
    $st->execute([$c['uid']]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['unlocked'] = (bool)$r['unlocked']; $r['points'] = (int)$r['points']; }
    ok(array('achievements' => $rows, 'stats' => user_stats($c['uid'])));
}

/** GET me/stats：我的统计（完成数/总时长/最佳各关） */
function me_stats() {
    $c = current_user();
    $stats = user_stats($c['uid']);
    $st = db()->prepare('SELECT level, MIN(seconds) AS best FROM game_records WHERE user_id = ? GROUP BY level ORDER BY level');
    $st->execute([$c['uid']]);
    $bestByLevel = array();
    foreach ($st->fetchAll() as $r) $bestByLevel[(int)$r['level']] = (int)$r['best'];
    ok(array('stats' => $stats, 'bestByLevel' => $bestByLevel));
}
