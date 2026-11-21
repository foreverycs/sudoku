<?php
/**
 * 好友系统：双向好友（user_a < user_b 唯一对）+ 好友榜
 * 兼容 PHP 5.4（无 ?? / Throwable / 标量类型 / 箭头函数）
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../resp.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../compat.php';
require_once __DIR__ . '/game.php';   // leader_sort / leader_has_best

/** 取某用户的好友 ID 列表 */
function friend_ids($uid) {
    $st = db()->prepare('SELECT IF(user_a = ?, user_b, user_a) AS fid FROM friends WHERE status = 1 AND (user_a = ? OR user_b = ?)');
    $st->execute(array($uid, $uid, $uid));
    $ids = array();
    foreach ($st->fetchAll() as $r) $ids[] = (int)$r['fid'];
    return $ids;
}

/** GET friends：好友列表（管理用） */
function friends_list() {
    $c = current_user();
    $uid = (int)$c['uid'];
    $st = db()->prepare(
        'SELECT u.id, u.username, u.nickname, u.avatar, u.level, u.exp'
        . ' FROM friends f JOIN users u ON u.id = CASE WHEN f.user_a = ? THEN f.user_b ELSE f.user_a END'
        . ' WHERE f.status = 1 AND (f.user_a = ? OR f.user_b = ?) AND u.status = 1 AND u.role = "user"'
        . ' ORDER BY u.nickname, u.id'
    );
    $st->execute(array($uid, $uid, $uid));
    $friends = $st->fetchAll();
    $st = db()->prepare('SELECT f.user_a, f.user_b, u.id, f.created_at, u.username, u.nickname, u.avatar, u.level FROM friends f JOIN users u ON u.id = f.requested_by WHERE f.requested_by <> ? AND (f.user_a = ? OR f.user_b = ?) AND f.status = 0 AND u.status = 1 ORDER BY f.created_at');
    $st->execute(array($uid, $uid, $uid));
    $incoming = $st->fetchAll();
    $st = db()->prepare('SELECT f.user_a, f.user_b, u.id, f.created_at, u.username, u.nickname, u.avatar, u.level FROM friends f JOIN users u ON u.id = IF(f.user_a = ?, f.user_b, f.user_a) WHERE f.requested_by = ? AND (f.user_a = ? OR f.user_b = ?) AND f.status = 0 AND u.status = 1 ORDER BY f.created_at');
    $st->execute(array($uid, $uid, $uid, $uid));
    ok(array('friends' => $friends, 'incoming' => $incoming, 'outgoing' => $st->fetchAll()));
}

/** POST friends/add：建立待处理好友申请 */
function friends_add() {
    $c = current_user();
    $b = body();
    $username = trim(isset($b['username']) ? (string)$b['username'] : '');
    if ($username === '' || strlen($username) > 32) fail('用户名无效');
    $pdo = db();
    $uid = (int)$c['uid'];
    $st = $pdo->prepare('SELECT id FROM users WHERE username = ? AND status = 1 AND role = "user"');
    $st->execute(array($username));
    $t = $st->fetch();
    if (!$t) fail('用户不存在', 404);
    $tid = (int)$t['id'];
    if ($tid === $uid) fail('不能添加自己为好友');
    $a = min($uid, $tid); $b2 = max($uid, $tid);
    $st = $pdo->prepare('SELECT status, requested_by FROM friends WHERE user_a = ? AND user_b = ?');
    $st->execute(array($a, $b2));
    $pair = $st->fetch();
    if ($pair && (int)$pair['status'] === 1) fail('已经是好友');
    if ($pair) {
        if ((int)$pair['requested_by'] === $uid) fail('好友申请已发送', 409);
        if ((int)$pair['requested_by'] !== $tid) fail('好友申请状态无效', 409);
        $pdo->prepare('UPDATE friends SET status = 1, updated_at = CURRENT_TIMESTAMP WHERE user_a = ? AND user_b = ? AND status = 0')->execute(array($a, $b2));
    } else {
        $pdo->prepare('INSERT INTO friends (user_a, user_b, status, requested_by) VALUES (?,?,0,?)')->execute(array($a, $b2, $uid));
    }
    friends_list();
}

/** POST friends/accept: only the requested user can accept. */
function friends_accept() {
    $c = current_user(); $b = body(); $fid = isset($b['id']) ? (int)$b['id'] : 0; $uid = (int)$c['uid'];
    if ($fid <= 0 || $fid === $uid) fail('好友申请无效');
    $a = min($uid, $fid); $b2 = max($uid, $fid);
    $st = db()->prepare('UPDATE friends SET status = 1, updated_at = CURRENT_TIMESTAMP WHERE user_a = ? AND user_b = ? AND status = 0 AND requested_by = ?');
    $st->execute(array($a, $b2, $fid));
    if ($st->rowCount() !== 1) fail('好友申请不存在', 404);
    friends_list();
}

/** POST friends/reject: reject an incoming request or cancel one's outgoing request. */
function friends_reject() {
    $c = current_user(); $b = body(); $fid = isset($b['id']) ? (int)$b['id'] : 0; $uid = (int)$c['uid'];
    if ($fid <= 0 || $fid === $uid) fail('好友申请无效');
    $a = min($uid, $fid); $b2 = max($uid, $fid);
    $st = db()->prepare('DELETE FROM friends WHERE user_a = ? AND user_b = ? AND status = 0 AND requested_by IN (?,?)');
    $st->execute(array($a, $b2, $uid, $fid));
    if ($st->rowCount() !== 1) fail('待处理申请不存在', 404);
    friends_list();
}

/** POST friends/remove：移除好友（双向解除） */
function friends_remove() {
    $c = current_user();
    $b = body();
    $fid = isset($b['id']) ? (int)$b['id'] : 0;
    if ($fid <= 0) fail('好友无效');
    $pdo = db();
    $uid = (int)$c['uid'];
    $a = min($uid, $fid); $b2 = max($uid, $fid);
    $pdo->prepare('DELETE FROM friends WHERE user_a = ? AND user_b = ? AND status = 1')->execute(array($a, $b2));
    friends_list();
}

/** GET leaderboard/friends：好友（含我）在某关的最佳用时排行 */
function leaderboard_friends() {
    $c = current_user();
    $uid = (int)$c['uid'];
    $limit = isset($_GET['limit']) ? min(50, max(5, (int)$_GET['limit'])) : 10;
    $level = isset($_GET['level']) ? (int)$_GET['level'] : 1;
    $period = isset($_GET['period']) ? (string)$_GET['period'] : 'all';
    if (!in_array($period, array('all', 'week', 'month'), true)) $period = 'all';
    if ($level < 1) $level = 1;
    if ($level > 100) $level = 100;
    $pdo = db();

    $ids = friend_ids($uid);
    $ids[] = $uid; // 好友榜始终包含本人，即使当前没有好友
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        'SELECT u.id, u.username, u.nickname, u.avatar, MIN(gr.seconds) AS best'
        . ' FROM users u LEFT JOIN game_records gr ON gr.user_id = u.id AND gr.level = ? AND gr.used_hint = 0'
        . ($period === 'week' ? ' AND gr.played_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)' : '')
        . ($period === 'month' ? ' AND gr.played_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")' : '')
        . ' WHERE u.role = "user" AND u.status = 1 AND u.id IN (' . $marks . ')'
        . ' GROUP BY u.id, u.username, u.nickname, u.avatar'
    );
    $params = array_merge(array($level), $ids);
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['best'] = ($r['best'] !== null) ? (int)$r['best'] : null;
    }
    unset($r);
    $rows = array_values(array_filter($rows, 'leader_has_best'));
    usort($rows, 'leader_sort');

    $my = null;
    foreach ($rows as $i => $r) {
        if ((int)$r['id'] === $uid) {
            $my = array(
                'rank' => $i + 1,
                'id' => (int)$r['id'],
                'username' => $r['username'],
                'nickname' => $r['nickname'],
                'avatar' => $r['avatar'],
                'best' => $r['best'],
            );
            break;
        }
    }
    $inTop = false;
    foreach (array_slice($rows, 0, $limit) as $r) {
        if ((int)$r['id'] === $uid) { $inTop = true; break; }
    }
    ok(array(
        'ranking' => array_slice($rows, 0, $limit),
        'count' => min(count($rows), $limit),
        'my' => $inTop ? null : $my,
        'level' => $level, 'period' => $period,
    ));
}
