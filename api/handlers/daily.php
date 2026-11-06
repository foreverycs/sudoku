<?php
/**
 * 每日挑战（Daily Puzzle）—— 每天一道全球同题，按日期做种子确定性生成
 * 兼容 PHP 5.4（无 ?? / Throwable / 标量类型 / 箭头函数）
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';
require_once __DIR__ . '/../resp.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../compat.php';
require_once __DIR__ . '/auth.php';   // exp_needed / calc_level / daily_streak
require_once __DIR__ . '/game.php';   // user_stats / achievement_met

/** 确定性随机（LCG，移植自 gen-data.js） */
class DailyGen {
    private $s;
    function __construct($seed) {
        $this->s = ((int)$seed) % 2147483647;
        if ($this->s <= 0) $this->s += 2147483646;
    }
    function next() {
        $this->s = ($this->s * 16807) % 2147483647;
        return ($this->s - 1) / 2147483646;
    }
    function intn($n) {
        $v = (int)floor($this->next() * $n);
        return ($v >= $n) ? $n - 1 : $v;
    }
}

/** 基础完整解 */
function daily_base() {
    $b = array();
    for ($i = 0; $i < 81; $i++) {
        $r = (int)($i / 9); $c = $i % 9;
        $b[$i] = ($r * 3 + (int)($r / 3) + $c) % 9 + 1;
    }
    return $b;
}

/** 由种子生成随机完整解（数字置换 + 块/行/列交换 + 转置） */
function daily_transform($seed) {
    $g = new DailyGen($seed);
    $base = daily_base();
    $perm = range(1, 9);
    for ($i = 8; $i > 0; $i--) { $j = $g->intn($i + 1); $t = $perm[$i]; $perm[$i] = $perm[$j]; $perm[$j] = $t; }
    $blocks = array(0, 1, 2);
    for ($i = 2; $i > 0; $i--) { $j = $g->intn($i + 1); $t = $blocks[$i]; $blocks[$i] = $blocks[$j]; $blocks[$j] = $t; }
    $rows = array(); $cols = array();
    for ($b = 0; $b < 3; $b++) {
        $i1 = array(0, 1, 2); $i2 = array(0, 1, 2);
        for ($i = 2; $i > 0; $i--) {
            $j = $g->intn($i + 1); $t = $i1[$i]; $i1[$i] = $i1[$j]; $i1[$j] = $t;
            $j = $g->intn($i + 1); $t = $i2[$i]; $i2[$i] = $i2[$j]; $i2[$j] = $t;
        }
        for ($k = 0; $k < 3; $k++) { $rows[$b * 3 + $k] = $blocks[$b] * 3 + $i1[$k]; $cols[$b * 3 + $k] = $blocks[$b] * 3 + $i2[$k]; }
    }
    $trans = $g->next() < 0.5;
    $out = array();
    for ($i = 0; $i < 81; $i++) {
        $rr = (int)($i / 9); $cc = $i % 9;
        if ($trans) { $t = $rr; $rr = $cc; $cc = $t; }
        $out[$i] = $perm[$base[$rows[$rr] * 9 + $cols[$cc]] - 1];
    }
    return $out;
}

/** 解数统计（最多 limit 个） */
function daily_count_rec(&$board, $limit, &$count) {
    if ($count >= $limit) return;
    $best = -1; $opts = null;
    for ($i = 0; $i < 81; $i++) if ($board[$i] === 0) {
        $r = (int)($i / 9); $c = $i % 9; $a = array();
        for ($d = 1; $d <= 9; $d++) {
            $ok = true;
            for ($k = 0; $k < 9; $k++) {
                $br = ((int)($r / 3)) * 3 + (int)($k / 3); $bc = ((int)($c / 3)) * 3 + $k % 3;
                if ($board[$r * 9 + $k] === $d || $board[$k * 9 + $c] === $d || $board[$br * 9 + $bc] === $d) { $ok = false; break; }
            }
            if ($ok) $a[] = $d;
        }
        if (!$a) return;
        if ($opts === null || count($a) < count($opts)) { $best = $i; $opts = $a; if (count($a) === 1) break; }
    }
    if ($best < 0) { $count++; return; }
    foreach ($opts as $d) {
        $board[$best] = $d; daily_count_rec($board, $limit, $count); $board[$best] = 0;
        if ($count >= $limit) return;
    }
}

function daily_count_solutions($board, $limit) {
    $count = 0;
    daily_count_rec($board, $limit, $count);
    return $count;
}

/** 挖空到目标线索数，保持唯一解 */
function daily_dig($answer, $targetClues) {
    $board = $answer; $current = 81;
    for ($round = 0; $round < 4 && $current > $targetClues; $round++) {
        $g = new DailyGen($current * 1000 + $round * 97 + $board[0] * 31 + 7);
        $order = range(0, 80);
        for ($i = 80; $i > 0; $i--) { $j = $g->intn($i + 1); $t = $order[$i]; $order[$i] = $order[$j]; $order[$j] = $t; }
        $progressed = false;
        foreach ($order as $i) {
            if ($current <= $targetClues) break;
            if ($board[$i]) {
                $v = $board[$i]; $board[$i] = 0;
                if (daily_count_solutions($board, 2) === 1) { $current--; $progressed = true; } else $board[$i] = $v;
            }
        }
        if (!$progressed) break;
    }
    return $board;
}

/** 当日题目：cells（0 空格）/answer 各 81 字符 */
function daily_puzzle($dateStr) {
    $seed = crc32($dateStr);
    $answer = daily_transform($seed);
    $board  = daily_dig($answer, 34); // 目标 34 线索（中等偏易，静心向）
    return array('cells' => implode('', $board), 'answer' => implode('', $answer));
}

/** GET daily：今日题目 + 用户状态（是否完成/连胜/最近记录） */
function daily_get() {
    $c = current_user();
    $uid = (int)$c['uid'];
    $today = date('Y-m-d');
    $p = daily_puzzle($today);
    $pdo = db();
    $st = $pdo->prepare('SELECT seconds, solved_at FROM daily_records WHERE user_id = ? AND date = ?');
    $st->execute(array($uid, $today));
    $rec = $st->fetch();
    $solved = $rec ? array('seconds' => (int)$rec['seconds'], 'solvedAt' => $rec['solved_at']) : null;
    $st = $pdo->prepare('SELECT date, seconds FROM daily_records WHERE user_id = ? ORDER BY date DESC LIMIT 60');
    $st->execute(array($uid));
    $history = array();
    foreach ($st->fetchAll() as $r) $history[$r['date']] = (int)$r['seconds'];
    ok(array(
        'date'    => $today,
        'cells'   => $p['cells'],
        'answer'  => $p['answer'],
        'streak'  => daily_streak($uid),
        'solved'  => $solved,
        'history' => $history,
    ));
}

/** POST daily/complete：结算每日完成（经验 + 勋章判定，一天一次计经验） */
function daily_complete() {
    $c = current_user();
    $b = body();
    $seconds = isset($b['seconds']) ? (int)$b['seconds'] : 0;
    if ($seconds < 0 || $seconds > 100000) fail('用时无效');
    $pdo = db();
    $uid = (int)$c['uid'];
    $today = date('Y-m-d');
    $st = $pdo->prepare('SELECT id, seconds FROM daily_records WHERE user_id = ? AND date = ?');
    $st->execute(array($uid, $today));
    $ex = $st->fetch();
    $firstToday = !$ex;
    if ($firstToday) {
        $pdo->prepare('INSERT INTO daily_records (user_id, date, seconds) VALUES (?,?,?)')
            ->execute(array($uid, $today, $seconds));
    }
    $expGain = $firstToday ? 40 : 0; // 每日挑战基础经验（中等档）
    $stats = user_stats($uid);
    $newAch = array();
    $st = $pdo->prepare('SELECT a.*, (ua.id IS NOT NULL) AS unlocked FROM achievements a LEFT JOIN user_achievements ua ON ua.achievement_id = a.id AND ua.user_id = ? WHERE a.active = 1');
    $st->execute(array($uid));
    foreach ($st->fetchAll() as $a) {
        if ($a['unlocked']) continue;
        if (achievement_met($a['cond_type'], $a['cond_value'], $stats, $uid)) {
            $pdo->prepare('INSERT IGNORE INTO user_achievements (user_id, achievement_id) VALUES (?,?)')
                ->execute(array($uid, $a['id']));
            $expGain += (int)$a['points'];
            $newAch[] = array(
                'code' => $a['code'], 'name' => $a['name'], 'description' => $a['description'],
                'icon' => $a['icon'], 'category' => $a['category'], 'points' => (int)$a['points'],
            );
        }
    }
    $st = $pdo->prepare('SELECT exp, level FROM users WHERE id = ?');
    $st->execute(array($uid));
    $u = $st->fetch();
    $oldLevel = (int)$u['level'];
    $newExp = (int)$u['exp'] + $expGain;
    $newLevel = calc_level($newExp);
    if ($expGain > 0) {
        $pdo->prepare('UPDATE users SET exp = ?, level = ? WHERE id = ?')->execute(array($newExp, $newLevel, $uid));
    }
    ok(array(
        'firstToday' => $firstToday,
        'expGain'    => $expGain,
        'exp'        => $newExp,
        'level'      => $newLevel,
        'levelUp'    => $newLevel > $oldLevel,
        'streak'     => daily_streak($uid),
        'newAchievements' => $newAch,
    ));
}
