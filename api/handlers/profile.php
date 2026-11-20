<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';
require_once __DIR__ . '/../resp.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../compat.php';
require_once __DIR__ . '/auth.php';   // exp_needed / level_exp_into / daily_streak / user_pub
require_once __DIR__ . '/game.php';   // user_stats

function profile_daily_signature($uid, $rows) {
    $canonical = array();
    foreach ($rows as $row) $canonical[] = array((string)$row['date'], (int)$row['seconds']);
    usort($canonical, function ($a, $b) { return strcmp($a[0], $b[0]); });
    return hash_hmac('sha256', (int)$uid . ':' . json_encode($canonical), JWT_SECRET);
}

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

    $st = $pdo->prepare('SELECT level, cells, notes, seconds, revision, created_at FROM game_progress_history WHERE user_id = ? ORDER BY level, id');
    $st->execute([$uid]);
    $history = $st->fetchAll();

    $st = $pdo->prepare('SELECT level, attempt_key, started_at, completed_at, is_valid, result_json FROM game_attempts WHERE user_id = ? ORDER BY id');
    $st->execute([$uid]);
    $attempts = $st->fetchAll();
    foreach ($attempts as &$a) { $a['is_valid'] = (bool)$a['is_valid']; }
    unset($a);

    ok(array(
        'version'      => 2,
        'exportedAt'   => date('c'),
        'user'         => user_pub($uid),
        'stats'        => user_stats($uid),
        'progress'     => $progress,
        'records'      => $records,
        'daily'        => $daily,
        'dailySignature' => profile_daily_signature($uid, $daily),
        'achievements' => $achievements,
        'history'      => $history,
        'attempts'     => $attempts,
        'historyCount' => $historyCount,
    ));
}

/** POST me/import: restore only user-editable progress and daily completion history. */
function me_import() {
    $c = current_user();
    $b = body();
    if (!isset($b['version']) || (int)$b['version'] < 1 || (int)$b['version'] > 2) fail('备份版本不支持');
    if (!isset($b['progress']) || !is_array($b['progress']) || count($b['progress']) > 100) fail('关卡进度格式无效');
    $progress = array();
    foreach ($b['progress'] as $row) {
        if (!is_array($row)) fail('关卡进度格式无效');
        $level = isset($row['level']) ? (int)$row['level'] : 0;
        $cells = isset($row['cells']) ? (string)$row['cells'] : '';
        $seconds = isset($row['seconds']) ? (int)$row['seconds'] : -1;
        $notes = isset($row['notes']) ? $row['notes'] : array();
        if (is_string($notes)) $notes = json_decode($notes, true);
        if ($level < 1 || $level > 100 || isset($progress[$level])) fail('关卡编号无效或重复');
        if (strlen($cells) !== 81 || !preg_match('/^[0-9]{81}$/', $cells)) fail('棋盘数据格式无效');
        if ($seconds < 0 || $seconds > 100000 || !is_array($notes)) fail('用时或笔记格式无效');
        $noteCount = 0;
        foreach ($notes as $k => $values) {
            if (!preg_match('/^(?:[0-7][0-9]|80)$/', (string)$k) || !is_array($values)) fail('笔记格式无效');
            foreach ($values as $n) if (!is_numeric($n) || (int)$n < 1 || (int)$n > 9) fail('笔记值无效');
            $noteCount += count($values);
        }
        if ($noteCount > 500) fail('笔记过多');
        $progress[$level] = array($cells, json_encode($notes), $seconds);
    }

    $daily = isset($b['daily']) && is_array($b['daily']) ? $b['daily'] : array();
    if (count($daily) > 3650) fail('每日记录过多');
    foreach ($daily as $row) {
        if (!is_array($row) || !isset($row['date'], $row['seconds'])) fail('每日记录格式无效');
        $date = (string)$row['date'];
        $seconds = (int)$row['seconds'];
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date || $seconds < 0 || $seconds > 100000) fail('每日记录格式无效');
    }
    $dailyTrusted = isset($b['dailySignature']) && is_string($b['dailySignature'])
        && profile_daily_signature((int)$c['uid'], $daily) === $b['dailySignature'];
    if (!$dailyTrusted) $daily = array();

    $pdo = db();
    $uid = (int)$c['uid'];
    try {
        $pdo->beginTransaction();
        $save = $pdo->prepare('INSERT INTO game_progress (user_id, level, cells, notes, seconds, revision) VALUES (?,?,?,?,?,1) ON DUPLICATE KEY UPDATE cells=VALUES(cells), notes=VALUES(notes), seconds=VALUES(seconds), revision=COALESCE(revision,1)+1');
        foreach ($progress as $level => $row) {
            $st = $pdo->prepare('SELECT cells, notes, seconds, COALESCE(revision,1) AS revision FROM game_progress WHERE user_id = ? AND level = ? FOR UPDATE');
            $st->execute(array($uid, $level));
            $old = $st->fetch();
            if ($old && ((string)$old['cells'] !== $row[0] || (string)$old['notes'] !== $row[1] || (int)$old['seconds'] !== $row[2])) {
                $pdo->prepare('INSERT INTO game_progress_history (user_id, level, cells, notes, seconds, revision) VALUES (?,?,?,?,?,?)')
                    ->execute(array($uid, $level, $old['cells'], $old['notes'], (int)$old['seconds'], (int)$old['revision']));
            }
            $save->execute(array($uid, $level, $row[0], $row[1], $row[2]));
        }
        $dailySave = $pdo->prepare('INSERT IGNORE INTO daily_records (user_id, date, seconds) VALUES (?,?,?)');
        foreach ($daily as $row) $dailySave->execute(array($uid, $row['date'], (int)$row['seconds']));
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail('恢复失败：' . $e->getMessage(), 500);
    }
    ok(array('restoredLevels' => count($progress), 'restoredDaily' => $dailyTrusted ? count($daily) : 0, 'skippedUntrustedDaily' => !$dailyTrusted, 'preservedAwards' => true));
}

/** POST me/storage/clear: remove editable cloud boards and daily completion history only. */
function me_storage_clear() {
    $c = current_user();
    $pdo = db();
    $uid = (int)$c['uid'];
    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM game_progress WHERE user_id = ?')->execute(array($uid));
        $daily = $pdo->prepare('DELETE FROM daily_records WHERE user_id = ?');
        $daily->execute(array($uid));
        $pdo->commit();
        ok(array('clearedBoards' => true, 'clearedDailyRecords' => $daily->rowCount(), 'keptHistory' => true));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail('清理失败：' . $e->getMessage(), 500);
    }
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
