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

/** GET achievements：勋章列表 + 用户解锁状态 + 统计（含各勋章进度） */
function achievements_list() {
    $c = current_user();
    $st = db()->prepare('SELECT a.id, a.code, a.name, a.description, a.icon, a.category, a.points, a.cond_type, a.cond_value, (ua.id IS NOT NULL) AS unlocked, ua.unlocked_at FROM achievements a LEFT JOIN user_achievements ua ON ua.achievement_id = a.id AND ua.user_id = ? WHERE a.active = 1 ORDER BY a.category, a.cond_value, a.id');
    $st->execute([$c['uid']]);
    $rows = $st->fetchAll();
    $stats = user_stats($c['uid']);
    foreach ($rows as &$r) {
        $r['unlocked'] = (bool)$r['unlocked'];
        $r['points'] = (int)$r['points'];
        $r['target'] = (int)$r['cond_value'];
        // 当前进度（按条件类型从统计取值）
        $pv = 0;
        switch ($r['cond_type']) {
            case 'complete_count':   $pv = $stats['complete_count']; break;
            case 'best_seconds_lt':  $pv = $stats['best_min'];       break;
            case 'total_seconds_ge': $pv = $stats['total_seconds'];  break;
            case 'level_ge':         $pv = $stats['max_level'];      break;
            case 'daily_streak_ge':  $pv = 0;                        break;
        }
        $r['progress'] = (int)$pv;
    }
    unset($r); // 断开 foreach 引用残留
    ok(array('achievements' => $rows, 'stats' => $stats));
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

/** 排序：该关最佳用时短优先（无用时排后） */
function leader_sort($a, $b) {
    $ab = ($a['best'] === null) ? PHP_INT_MAX : $a['best'];
    $bb = ($b['best'] === null) ? PHP_INT_MAX : $b['best'];
    if ($ab !== $bb) return $ab - $bb;
    return (int)$a['id'] - (int)$b['id'];
}

/** 排行榜过滤：仅保留该关有成绩的用户（无成绩者不上榜） */
function leader_has_best($r) {
    return $r['best'] !== null;
}

/** GET leaderboard：某关卡时间榜（该关最佳用时短优先，公开，前 N 名）+ 当前用户排名 */
function leaderboard() {
    $limit = isset($_GET['limit']) ? min(50, max(5, (int)$_GET['limit'])) : 10;
    $level = isset($_GET['level']) ? (int)$_GET['level'] : 1;
    if ($level < 1) $level = 1;
    if ($level > 100) $level = 100;
    $pdo = db();
    $st = $pdo->prepare(
        'SELECT u.id, u.username, u.nickname, u.avatar, MIN(gr.seconds) AS best'
        . ' FROM users u LEFT JOIN game_records gr ON gr.user_id = u.id AND gr.level = ?'
        . ' WHERE u.role = "user" AND u.status = 1'
        . ' GROUP BY u.id, u.username, u.nickname, u.avatar'
    );
    $st->execute([$level]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['best'] = ($r['best'] !== null) ? (int)$r['best'] : null;
    }
    unset($r);
    $rows = array_filter($rows, 'leader_has_best');
    usort($rows, 'leader_sort');
    $rows = array_slice($rows, 0, $limit);
    // 当前用户排名（可选，未登录则忽略）
    $my = null;
    $token = bearer_token();
    if ($token) {
        $p = jwt_decode($token);
        if ($p && isset($p['uid'])) {
            $uid = (int)$p['uid'];
            $inTop = false;
            foreach ($rows as $r) { if ((int)$r['id'] === $uid) { $inTop = true; break; } }
            if (!$inTop) {
                $all = $pdo->prepare(
                    'SELECT u.id, u.username, u.nickname, u.avatar, MIN(gr.seconds) AS best'
                    . ' FROM users u LEFT JOIN game_records gr ON gr.user_id = u.id AND gr.level = ?'
                    . ' WHERE u.role = "user" AND u.status = 1'
                    . ' GROUP BY u.id, u.username, u.nickname, u.avatar'
                );
                $all->execute([$level]);
                $all = $all->fetchAll();
                foreach ($all as &$a2) { $a2['best'] = ($a2['best'] !== null) ? (int)$a2['best'] : null; }
                unset($a2);
                $all = array_filter($all, 'leader_has_best');
                usort($all, 'leader_sort');
                $rank = 0;
                foreach ($all as $i => $r) {
                    if ((int)$r['id'] === $uid) { $rank = $i + 1; break; }
                }
                if ($rank > 0) {
                    $me = $all[$rank - 1];
                    $my = array(
                        'rank' => $rank,
                        'id' => (int)$me['id'],
                        'username' => $me['username'],
                        'nickname' => $me['nickname'],
                        'avatar' => $me['avatar'],
                        'best' => $me['best'],
                    );
                }
            }
        }
    }
    ok(array('ranking' => $rows, 'count' => count($rows), 'my' => $my, 'level' => $level));
}
