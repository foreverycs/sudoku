<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';
require_once __DIR__ . '/../resp.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../compat.php';
require_once __DIR__ . '/auth.php'; // issue_token / exp_needed / calc_level

/** GET admin/overview：站点统计概览 */
function admin_overview() {
    require_admin();
    $pdo = db();
    $st = $pdo->query('SELECT COUNT(*) AS c FROM users WHERE role = "user"');
    $u = $st->fetch();
    $st = $pdo->query('SELECT COUNT(*) AS c FROM game_records');
    $g = $st->fetch();
    $st = $pdo->query('SELECT COUNT(DISTINCT user_id) AS c FROM game_records');
    $g2 = $st->fetch();
    $st = $pdo->query('SELECT COUNT(*) AS c FROM achievements WHERE active = 1');
    $a = $st->fetch();
    $st = $pdo->query('SELECT COALESCE(SUM(seconds),0) AS s FROM game_records');
    $s = $st->fetch();
    $st = $pdo->query('SELECT COALESCE(MAX(exp),0) AS e FROM users');
    $mx = $st->fetch();
    $st = $pdo->query('SELECT COALESCE(MAX(level),0) AS l FROM users');
    $ml = $st->fetch();
    // 最近7天完成数
    $daily = array();
    for ($i = 6; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime('-' . $i . ' day'));
        $st = $pdo->prepare('SELECT COUNT(*) AS c FROM game_records WHERE DATE(played_at) = ?');
        $st->execute([$day]);
        $daily[] = array('date' => $day, 'count' => (int)$st->fetch()['c']);
    }
    // 最近7天每日挑战完成数
    $dailyChallenge = array();
    for ($i = 6; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime('-' . $i . ' day'));
        $st = $pdo->prepare('SELECT COUNT(*) AS c FROM daily_records WHERE date = ?');
        $st->execute([$day]);
        $dailyChallenge[] = array('date' => $day, 'count' => (int)$st->fetch()['c']);
    }
    // 最近7天注册数
    $registrations = array();
    for ($i = 6; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime('-' . $i . ' day'));
        $st = $pdo->prepare('SELECT COUNT(*) AS c FROM users WHERE DATE(created_at) = ?');
        $st->execute([$day]);
        $registrations[] = array('date' => $day, 'count' => (int)$st->fetch()['c']);
    }
    // 勋章解锁人数（active，按解锁数排序）
    $st = $pdo->query(
        'SELECT a.code, a.name, a.icon, COUNT(ua.user_id) AS cnt'
        . ' FROM achievements a LEFT JOIN user_achievements ua ON ua.achievement_id = a.id'
        . ' WHERE a.active = 1 GROUP BY a.id ORDER BY cnt DESC LIMIT 11'
    );
    $achStats = array();
    foreach ($st->fetchAll() as $r) {
        $achStats[] = array('code' => $r['code'], 'name' => $r['name'], 'icon' => $r['icon'], 'count' => (int)$r['cnt']);
    }
    // 难度分布（完成记录按关卡分档）
    $diff = array('入门' => 0, '简单' => 0, '中等' => 0, '困难' => 0, '挑战' => 0);
    $st = $pdo->query('SELECT level, COUNT(*) AS c FROM game_records GROUP BY level');
    foreach ($st->fetchAll() as $r) {
        $lv = (int)$r['level'];
        if ($lv <= 20) $diff['入门'] += (int)$r['c'];
        elseif ($lv <= 40) $diff['简单'] += (int)$r['c'];
        elseif ($lv <= 60) $diff['中等'] += (int)$r['c'];
        elseif ($lv <= 80) $diff['困难'] += (int)$r['c'];
        else $diff['挑战'] += (int)$r['c'];
    }
    ok(array(
        'users'      => (int)$u['c'],
        'records'    => (int)$g['c'],
        'activeUsers'=> (int)$g2['c'],
        'achievements'=> (int)$a['c'],
        'totalSeconds'=> (int)$s['s'],
        'maxExp'     => (int)$mx['e'],
        'maxLevel'   => (int)$ml['l'],
        'daily'      => $daily,
        'dailyChallenge' => $dailyChallenge,
        'registrations'  => $registrations,
        'achievementStats' => $achStats,
        'difficultyDist'   => $diff,
    ));
}

/** GET admin/users：用户列表（分页 + 搜索 + 排序，query 参数） */
function admin_users() {
    require_admin();
    $page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $size  = isset($_GET['size']) ? min(100, max(5, (int)$_GET['size'])) : 20;
    $q     = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $sort  = isset($_GET['sort']) ? (string)$_GET['sort'] : 'id';
    $order = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'ASC' : 'DESC';
    $allow = array('id','username','exp','level','created_at','last_login_at');
    if (!in_array($sort, $allow, true)) $sort = 'id';
    $pdo = db();
    $where = ''; $params = array();
    if ($q !== '') {
        $where = ' WHERE (username LIKE ? OR nickname LIKE ?)';
        $params = array('%' . $q . '%', '%' . $q . '%');
    }
    $st = $pdo->prepare('SELECT COUNT(*) AS c FROM users' . $where);
    $st->execute($params);
    $total = (int)$st->fetch()['c'];
    $offset = ($page - 1) * $size;
    $sql = 'SELECT id, username, nickname, avatar, role, status, exp, level, last_login_at, created_at FROM users'
         . $where . ' ORDER BY ' . $sort . ' ' . $order . ' LIMIT ' . $size . ' OFFSET ' . $offset;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    ok(array('total' => $total, 'page' => $page, 'size' => $size, 'users' => $st->fetchAll()));
}

/** GET admin/users/{id}：单个用户详情（含统计/勋章/最近通关） */
function admin_user_detail($id) {
    require_admin();
    $pdo = db();
    $st = $pdo->prepare('SELECT id, username, nickname, avatar, role, status, exp, level, last_login_at, created_at FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) fail('用户不存在', 404);
    $st = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(seconds),0) AS ts, COALESCE(MIN(seconds),0) AS bm FROM game_records WHERE user_id = ?');
    $st->execute([$id]);
    $stats = $st->fetch();
    $st = $pdo->prepare('SELECT a.code, a.name, a.icon, a.points, ua.unlocked_at FROM user_achievements ua JOIN achievements a ON a.id = ua.achievement_id WHERE ua.user_id = ? ORDER BY ua.unlocked_at DESC');
    $st->execute([$id]);
    $ach = $st->fetchAll();
    $st = $pdo->prepare('SELECT level, MIN(seconds) AS best FROM game_records WHERE user_id = ? GROUP BY level ORDER BY level LIMIT 10');
    $st->execute([$id]);
    $best = $st->fetchAll();
    ok(array(
        'user'   => $u,
        'stats'  => array('complete_count' => (int)$stats['c'], 'total_seconds' => (int)$stats['ts'], 'best_min' => (int)$stats['bm']),
        'achievements' => $ach,
        'bestByLevel'  => $best,
    ));
}

/** POST admin/users/{id}/status：封禁/解封 */
function admin_user_status($id) {
    require_admin();
    $b = body();
    $status = isset($b['status']) ? (int)$b['status'] : 1;
    if ($status !== 0 && $status !== 1) fail('状态无效');
    if ((int)$id === 1) fail('不能修改内置管理员');
    $pdo = db();
    $st = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) fail('用户不存在', 404);
    if ($u['role'] === 'admin') fail('不能封禁管理员');
    $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $id]);
    ok(array('id' => (int)$id, 'status' => $status));
}

/** POST admin/users/{id}/role：调整角色 */
function admin_user_role($id) {
    require_admin();
    $b = body();
    $role = isset($b['role']) ? (string)$b['role'] : 'user';
    if ($role !== 'user' && $role !== 'admin') fail('角色无效');
    if ((int)$id === 1) fail('不能修改内置管理员');
    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $st->execute([$id]);
    if (!$st->fetch()) fail('用户不存在', 404);
    $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
    ok(array('id' => (int)$id, 'role' => $role));
}

/** POST admin/users/{id}/reset：重置用户进度（清经验/等级/记录/进度/勋章） */
function admin_user_reset($id) {
    require_admin();
    if ((int)$id === 1) fail('不能重置内置管理员');
    $pdo = db();
    $st = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) fail('用户不存在', 404);
    if ($u['role'] === 'admin') fail('不能重置管理员');
    $pdo->prepare('DELETE FROM game_progress WHERE user_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM game_records WHERE user_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM user_achievements WHERE user_id = ?')->execute([$id]);
    $pdo->prepare('UPDATE users SET exp = 0, level = 1 WHERE id = ?')->execute([$id]);
    ok(array('id' => (int)$id, 'reset' => true));
}

/** POST admin/users/{id}/delete：彻底删除用户（含全部进度/记录/勋章，外键级联） */
function admin_user_delete($id) {
    require_admin();
    if ((int)$id === 1) fail('不能删除内置管理员');
    $pdo = db();
    $st = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) fail('用户不存在', 404);
    if ($u['role'] === 'admin') fail('不能删除管理员');
    // 外键 ON DELETE CASCADE 会自动清 game_progress / game_records / user_achievements
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    ok(array('id' => (int)$id, 'deleted' => true));
}

/** GET admin/achievements：勋章管理列表（含各勋章解锁人数） */
function admin_achievements() {
    require_admin();
    $st = db()->query(
        'SELECT a.id, a.code, a.name, a.description, a.icon, a.category, a.cond_type, a.cond_value, a.points, a.active, a.created_at,'
        . ' (SELECT COUNT(*) FROM user_achievements ua WHERE ua.achievement_id = a.id) AS unlocked_count'
        . ' FROM achievements a ORDER BY a.category, a.cond_value, a.id'
    );
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['points'] = (int)$r['points']; $r['unlocked_count'] = (int)$r['unlocked_count']; $r['active'] = (bool)$r['active']; }
    ok(array('achievements' => $rows));
}

/** POST admin/achievements/{id}/toggle：启用/停用勋章 */
function admin_achievement_toggle($id) {
    require_admin();
    $pdo = db();
    $st = $pdo->prepare('SELECT active FROM achievements WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) fail('勋章不存在', 404);
    $nv = $a['active'] ? 0 : 1;
    $pdo->prepare('UPDATE achievements SET active = ? WHERE id = ?')->execute([$nv, $id]);
    ok(array('id' => (int)$id, 'active' => (bool)$nv));
}

/** GET admin/logs：最近登录日志（简版，仅展示最近登录用户） */
function admin_logs() {
    require_admin();
    $st = db()->query(
        'SELECT u.id, u.username, u.nickname, u.last_login_at FROM users u WHERE u.last_login_at IS NOT NULL'
        . ' ORDER BY u.last_login_at DESC LIMIT 30'
    );
    ok(array('logs' => $st->fetchAll()));
}
