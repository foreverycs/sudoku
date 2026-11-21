<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';
require_once __DIR__ . '/../resp.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../compat.php';
require_once __DIR__ . '/../security.php';
require_once __DIR__ . '/auth.php'; // exp_needed / calc_level / level_exp_into

/** 加载权威答案（PHP 数组，81 字符 x 100，由 gen-answers.js 从 sudoku-data.js 生成） */
function load_sudoku_answers() {
    static $ans = null;
    if ($ans === null) {
        $file = __DIR__ . '/../sudoku-answers.php';
        $ans = is_file($file) ? require $file : array();
        if (!is_array($ans)) $ans = array();
    }
    return $ans;
}

/** 验证提交棋盘是否为权威解（81 位数字，逐位比较） */
function validate_solution($submittedCells, $level) {
    $ans = load_sudoku_answers();
    if (!isset($ans[$level - 1])) return false;
    $answer = (string)$ans[$level - 1];
    if (strlen($submittedCells) !== 81 || strlen($answer) !== 81) return false;
    if (!preg_match('/^[0-9]{81}$/', $submittedCells)) return false;
    return $submittedCells === $answer;
}

/** 生成 UUID v4（简易版，PHP 5.4 兼容） */
function gen_uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 65535), mt_rand(0, 65535),
        mt_rand(0, 65535),
        mt_rand(16384, 20479), // version 4
        mt_rand(32768, 49151), // variant 10
        mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535)
    );
}

/** POST game/start：开始一次服务端尝试，返回幂等 attempt_key */
function game_start() {
    $c = current_user();
    $b = body();
    $level = isset($b['level']) ? (int)$b['level'] : 0;
    if ($level < 1 || $level > 100) fail('关卡无效');
    ensure_security_schema();
    $pdo = db();
    $uid = (int)$c['uid'];
    $attemptKey = gen_uuid();
    try {
    $pdo->prepare('INSERT INTO game_attempts (user_id, level, attempt_key, started_at) VALUES (?,?,?,NOW())')
            ->execute(array($uid, $level, $attemptKey));
    } catch (Exception $e) {
        // 唯一键冲突极低概率，重试一次
        $attemptKey = gen_uuid();
        $pdo->prepare('INSERT INTO game_attempts (user_id, level, attempt_key, started_at) VALUES (?,?,?,NOW())')
            ->execute(array($uid, $level, $attemptKey));
    }
    ok(array('attempt_key' => $attemptKey, 'started_at' => time()));
}

/** POST daily/start：开始当日挑战的服务端计时 */
function daily_start() {
    $c = current_user();
    ensure_security_schema();
    $pdo = db();
    $key = gen_uuid();
    $pdo->prepare('INSERT INTO game_attempts (user_id, level, attempt_key, started_at) VALUES (?,0,?,NOW())')
        ->execute(array((int)$c['uid'], $key));
    ok(array('attempt_key' => $key, 'started_at' => time()));
}

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
    $st = db()->prepare('SELECT COUNT(DISTINCT level) AS cc, COUNT(*) AS attempts, COALESCE(SUM(seconds),0) AS ts, COALESCE(AVG(seconds),0) AS av, COALESCE(MIN(seconds),0) AS bm, COALESCE(MAX(level),0) AS ml FROM game_records WHERE user_id = ?');
    $st->execute([(int)$uid]);
    $r = $st->fetch();
    return array(
        'complete_count' => (int)$r['cc'],
        'attempt_count'  => (int)$r['attempts'],
        'total_seconds'  => (int)$r['ts'],
        'average_seconds'=> (int)round((float)$r['av']),
        'best_min'       => (int)$r['bm'],
        'max_level'      => (int)$r['ml'],
    );
}

/** 勋章条件判定（daily_streak_ge 依据每日挑战连胜） */
function achievement_met($condType, $condValue, $stats, $uid) {
    switch ($condType) {
        case 'complete_count':   return $stats['complete_count'] >= (int)$condValue;
        case 'best_seconds_lt':  return $stats['best_min'] > 0 && $stats['best_min'] < (int)$condValue;
        case 'total_seconds_ge': return $stats['total_seconds'] >= (int)$condValue;
        case 'level_ge':         return $stats['max_level'] >= (int)$condValue;
        case 'daily_streak_ge':  return daily_streak($uid) >= (int)$condValue;
    }
    return false;
}

/** POST game/complete：验证提交 == 权威解，事务内幂等结算（经验 + 勋章，防重复发奖） */
function game_complete() {
    $c = current_user();
    $b = body();
    $level   = isset($b['level']) ? (int)$b['level'] : 0;
    $seconds = isset($b['seconds']) ? (int)$b['seconds'] : 0;
    $attemptKey = isset($b['attempt_key']) ? trim((string)$b['attempt_key']) : '';
    $cells   = isset($b['cells']) ? trim((string)$b['cells']) : '';
    if ($level < 1 || $level > 100) fail('关卡无效');
    if ($seconds < 0 || $seconds > 100000) fail('用时无效');
    if ($attemptKey === '' || strlen($attemptKey) > 64) fail('缺少 attempt_key');
    if (!preg_match('/^[0-9]{81}$/', $cells)) fail('棋盘数据无效');

    ensure_security_schema();
    $pdo = db();
    $uid = (int)$c['uid'];

    // 幂等：同 key 已结算则直接返回缓存结果
    $st = $pdo->prepare('SELECT id, started_at, completed_at, result_json FROM game_attempts WHERE user_id = ? AND level = ? AND attempt_key = ?');
    $st->execute(array($uid, $level, $attemptKey));
    $attempt = $st->fetch();
    if ($attempt && $attempt['completed_at']) {
        $cached = json_decode($attempt['result_json'], true);
        if (is_array($cached) && isset($cached['expGain'])) return ok($cached);
    }
    if (!$attempt) fail('服务端尝试不存在，请重新开始本关', 409);

    // 校验提交棋盘 == 权威答案；失败不标记完成（允许同 key 重试修正）
    if (!validate_solution($cells, $level)) {
        $pdo->prepare('UPDATE game_attempts SET submitted_cells = ?, is_valid = 0 WHERE user_id = ? AND level = ? AND attempt_key = ?')
            ->execute(array($cells, $uid, $level, $attemptKey));
        fail('棋盘验证失败', 400);
    }

    // 事务：锁尝试行防并发双发奖 -> 判定首次/最佳 -> 记录 -> 勋章(rowCount==1) -> 更新exp -> 标记完成
    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare('SELECT id, started_at, completed_at FROM game_attempts WHERE user_id = ? AND level = ? AND attempt_key = ? FOR UPDATE');
        $st->execute(array($uid, $level, $attemptKey));
        $lock = $st->fetch();
        if ($lock && $lock['completed_at']) {
            // 并发下已由另一请求结算，回滚并返回缓存
            $st2 = $pdo->prepare('SELECT result_json FROM game_attempts WHERE user_id = ? AND level = ? AND attempt_key = ?');
            $st2->execute(array($uid, $level, $attemptKey));
            $r = $st2->fetch();
            $pdo->rollBack();
            $cached = $r ? json_decode($r['result_json'], true) : null;
            if (is_array($cached) && isset($cached['expGain'])) return ok($cached);
            fail('结算已处理', 409);
        }

        // Serialize reward updates across distinct attempts for the same account.
        $userLock = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $userLock->execute(array($uid));

        // 服务端耗时是下限，客户端只能提交更慢的成绩，不能伪造更快的用时
        // MySQL 5.5 compatible; +1 second makes the lower bound conservative around second boundaries.
        $st = $pdo->prepare('SELECT GREATEST(1, TIMESTAMPDIFF(SECOND, ?, NOW()) + 1) AS elapsed');
        $st->execute(array($lock['started_at']));
        $elapsedRow = $st->fetch();
        $seconds = min(100000, max($seconds, (int)$elapsedRow['elapsed']));

        // 该关历史（首次 / 最佳判定）
        $st = $pdo->prepare('SELECT COUNT(*) AS cnt, COALESCE(MIN(seconds),0) AS best FROM game_records WHERE user_id = ? AND level = ?');
        $st->execute(array($uid, $level));
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
            ->execute(array($uid, $level, $seconds, $isRecord ? 1 : 0));
        $st = $pdo->prepare('SELECT MIN(seconds) AS best_seconds FROM game_records WHERE user_id = ? AND level = ?');
        $st->execute(array($uid, $level));
        $bestRow = $st->fetch();

        // 勋章判定：INSERT IGNORE + rowCount()==1 才计点，防并发重复解锁
        $stats = user_stats($uid);
        $st = $pdo->prepare('SELECT a.*, (ua.id IS NOT NULL) AS unlocked FROM achievements a LEFT JOIN user_achievements ua ON ua.achievement_id = a.id AND ua.user_id = ? WHERE a.active = 1');
        $st->execute(array($uid));
        $newAch = array();
        foreach ($st->fetchAll() as $a) {
            if ($a['unlocked']) continue;
            if (achievement_met($a['cond_type'], $a['cond_value'], $stats, $uid)) {
                $ins = $pdo->prepare('INSERT IGNORE INTO user_achievements (user_id, achievement_id) VALUES (?,?)');
                $ins->execute(array($uid, $a['id']));
                if ($ins->rowCount() > 0) {
                    $expGain += (int)$a['points'];
                    $newAch[] = array(
                        'code' => $a['code'], 'name' => $a['name'], 'description' => $a['description'],
                        'icon' => $a['icon'], 'category' => $a['category'], 'points' => (int)$a['points'],
                    );
                }
            }
        }

        // 更新用户经验/等级
        $st = $pdo->prepare('SELECT exp, level FROM users WHERE id = ?');
        $st->execute(array($uid));
        $u = $st->fetch();
        $oldExp = (int)$u['exp']; $oldLevel = (int)$u['level'];
        $newExp = $oldExp + $expGain;
        $newLevel = calc_level($newExp);
        $pdo->prepare('UPDATE users SET exp = ?, level = ? WHERE id = ?')->execute(array($newExp, $newLevel, $uid));

        $result = array(
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
            'seconds' => $seconds,
            'officialSeconds' => $seconds,
            'bestSeconds' => (int)$bestRow['best_seconds'],
        );

        // 标记尝试完成并缓存结果（必须在 commit 之前，事务内）
        $pdo->prepare('UPDATE game_attempts SET completed_at = NOW(), is_valid = 1, submitted_cells = ?, result_json = ? WHERE user_id = ? AND level = ? AND attempt_key = ?')
            ->execute(array($cells, json_encode($result), $uid, $level, $attemptKey));

        $pdo->commit();
        ok($result);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail('结算失败：' . $e->getMessage(), 500);
    }
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
            case 'daily_streak_ge':  $pv = daily_streak($c['uid']);  break;
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
    $period = isset($_GET['period']) ? (string)$_GET['period'] : 'all';
    if (!in_array($period, array('all', 'week', 'month'), true)) $period = 'all';
    if ($level < 1) $level = 1;
    if ($level > 100) $level = 100;
    $pdo = db();
    $periodSql = '';
    if ($period === 'week') $periodSql = ' AND played_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)';
    elseif ($period === 'month') $periodSql = ' AND played_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")';
    $bestSql = 'SELECT user_id, MIN(seconds) AS best FROM game_records WHERE level = ?' . $periodSql . ' GROUP BY user_id';
    $st = $pdo->prepare(
        'SELECT u.id, u.username, u.nickname, u.avatar, b.best'
        . ' FROM (' . $bestSql . ') b JOIN users u ON u.id = b.user_id'
        . ' WHERE u.status = 1'
        . ' ORDER BY b.best ASC, u.id ASC LIMIT ' . $limit
    );
    $st->execute(array($level));
    $rows = $st->fetchAll();
    foreach ($rows as &$r) $r['best'] = (int)$r['best'];
    unset($r);
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
                $meSt = $pdo->prepare(
                    'SELECT u.id, u.username, u.nickname, u.avatar, b.best'
                    . ' FROM (' . $bestSql . ') b JOIN users u ON u.id = b.user_id'
                    . ' WHERE u.id = ? AND u.status = 1'
                );
                $meSt->execute(array($level, $uid));
                $me = $meSt->fetch();
                if ($me) {
                    $me['best'] = (int)$me['best'];
                    $rankSt = $pdo->prepare(
                        'SELECT COUNT(*) + 1 FROM (' . $bestSql . ') b JOIN users u ON u.id = b.user_id'
                        . ' WHERE u.status = 1 AND (b.best < ? OR (b.best = ? AND u.id < ?))'
                    );
                    $rankSt->execute(array($level, $me['best'], $me['best'], $uid));
                    $my = array(
                        'rank' => (int)$rankSt->fetchColumn(),
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
    ok(array('ranking' => $rows, 'count' => count($rows), 'my' => $my, 'level' => $level, 'period' => $period));
}
