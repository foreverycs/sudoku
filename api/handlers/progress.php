<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../resp.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../security.php';

/** GET progress 拉取当前用户全部云进度（含 revision 乐观锁版本） */
function progress_all() {
    $c = current_user();          // 认证优先（未登录 401），避免未认证请求触发 DDL
    ensure_security_schema();     // 确保 revision 列存在（迁移与顺序无关）
    $st = db()->prepare('SELECT level, cells, notes, seconds, updated_at, COALESCE(revision,1) AS rev FROM game_progress WHERE user_id = ?');
    $st->execute(array($c['uid']));
    $rows = $st->fetchAll();
    $map = array();
    foreach ($rows as $r) {
        $notes = $r['notes'] ? json_decode($r['notes'], true) : array();
        $map[(int)$r['level']] = array(
            'cells'     => (string)$r['cells'],
            'notes'     => is_array($notes) ? $notes : array(),
            'seconds'   => (int)$r['seconds'],
            'updatedAt' => (string)$r['updated_at'],
            'revision'  => (int)$r['rev'],
        );
    }
    ok(array('progress' => $map));
}

/** PUT progress/{level} 上传单关进度（乐观锁：baseRevision 不匹配返回 409 + 最新记录） */
function progress_save($level) {
    $c = current_user();
    $b = body();
    $cells        = isset($b['cells']) ? trim((string)$b['cells']) : '';
    $notes        = isset($b['notes']) ? $b['notes'] : array();
    $seconds      = isset($b['seconds']) ? (int)$b['seconds'] : 0;
    $baseRevision = isset($b['baseRevision']) ? (int)$b['baseRevision'] : 0;

    if ($level < 1 || $level > 100) fail('关卡无效');
    if (strlen($cells) !== 81 || !preg_match('/^[0-9]{81}$/', $cells)) fail('cells 格式错误，需 81 位数字');
    if ($seconds < 0 || $seconds > 100000) fail('用时无效');
    if (!is_array($notes)) fail('notes 格式错误');
    $noteCount = 0;
    foreach ($notes as $k => $v) {
        if (!is_array($v)) continue;
        foreach ($v as $n) {
            if (!is_numeric($n) || (int)$n < 1 || (int)$n > 9) fail('笔记值无效');
        }
        $noteCount += count($v);
    }
    if ($noteCount > 500) fail('笔记过多');
    $notesJson = json_encode($notes);

    ensure_security_schema();
    $pdo = db();
    $uid = (int)$c['uid'];

    // 事务内读取当前 revision，保证"读取-校验-写入"原子性
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT id, cells, notes, seconds, updated_at, COALESCE(revision,1) AS rev FROM game_progress WHERE user_id = ? AND level = ? FOR UPDATE');
        $st->execute(array($uid, $level));
        $existing = $st->fetch();

        if ($existing && $baseRevision > 0 && (int)$existing['rev'] !== $baseRevision) {
            // 冲突：回滚并返回最新云端记录，客户端合并后以新 revision 重推
            $latestNotes = $existing['notes'] ? json_decode($existing['notes'], true) : array();
            $extra = array(
                'cells'     => (string)$existing['cells'],
                'notes'     => is_array($latestNotes) ? $latestNotes : array(),
                'seconds'   => (int)$existing['seconds'],
                'updatedAt' => (string)$existing['updated_at'],
                'revision'  => (int)$existing['rev'],
            );
            $pdo->rollBack();
            fail('进度冲突', 409, $extra);
        }

        // UPSERT 并递增 revision（无冲突直接写）
        $pdo->prepare(
            'INSERT INTO game_progress (user_id, level, cells, notes, seconds, revision)
             VALUES (?,?,?,?,?,1)
             ON DUPLICATE KEY UPDATE cells=VALUES(cells), notes=VALUES(notes), seconds=VALUES(seconds), revision=COALESCE(revision,1)+1'
        )->execute(array($uid, $level, $cells, $notesJson, $seconds));

        $st3 = $pdo->prepare('SELECT COALESCE(revision,1) AS rev FROM game_progress WHERE user_id = ? AND level = ?');
        $st3->execute(array($uid, $level));
        $row = $st3->fetch();
        $pdo->commit();
        ok(array('level' => $level, 'revision' => (int)$row['rev']));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail('保存失败：' . $e->getMessage(), 500);
    }
}
