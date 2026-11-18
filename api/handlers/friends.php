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
    $st = db()->prepare('SELECT IF(user_a = ?, user_b, user_a) AS fid FROM friends WHERE user_a = ? OR user_b = ?');
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
        . ' WHERE (f.user_a = ? OR f.user_b = ?) AND u.status = 1 AND u.role = "user"'
        . ' ORDER BY u.nickname, u.id'
    );
    $st->execute(array($uid, $uid, $uid));
    ok(array('friends' => $st->fetchAll()));
}

/** POST friends/add：按用户名添加（双向好友，幂等） */
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
    $pdo->prepare('INSERT IGNORE INTO friends (user_a, user_b) VALUES (?,?)')->execute(array($a, $b2));
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
    $pdo->prepare('DELETE FROM friends WHERE user_a = ? AND user_b = ?')->execute(array($a, $b2));
    friends_list();
}

/** GET leaderboard/friends：好友（含我）在某关的最佳用时排行 */
function leaderboard_friends() {
    $c = current_user();
    $uid = (int)$c['uid'];
    $limit = isset($_GET['limit']) ? min(50, max(5, (int)$_GET['limit'])) : 10;
    $level = isset($_GET['level']) ? (int)$_GET['level'] : 1;
    if ($level < 1) $level = 1;
    if ($level > 100) $level = 100;
    $pdo = db();

    $ids = friend_ids($uid);
    if (!$ids) {
        ok(array('ranking' => array(), 'count' => 0, 'my' => null, 'level' => $level));
        return;
    }
    $ids[] = $uid; // 含自己
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        'SELECT u.id, u.username, u.nickname, u.avatar, MIN(gr.seconds) AS best'
        . ' FROM users u LEFT JOIN game_records gr ON gr.user_id = u.id AND gr.level = ?'
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
    $rows = array_filter($rows, 'leader_has_best');
    usort($rows, 'leader_sort');
    $rows = array_slice($rows, 0, $limit);
    ok(array('ranking' => $rows, 'count' => count($rows), 'my' => null, 'level' => $level));
}
