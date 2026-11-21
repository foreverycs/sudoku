<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../resp.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../security.php';

/** GET progress 拉取当前用户全部云进度（含 revision 乐观锁版本） */
function progress_all() {
    $c = current_user();
    $st = db()->prepare('SELECT level, cells, notes, seconds, updated_at, UNIX_TIMESTAMP(updated_at) AS updated_ts, COALESCE(revision,1) AS rev FROM game_progress WHERE user_id = ?');
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
            'updatedMs' => (int)$r['updated_ts'] * 1000,
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

    $pdo = db();
    $uid = (int)$c['uid'];

    // 事务内读取当前 revision，保证"读取-校验-写入"原子性
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT id, cells, notes, seconds, updated_at, COALESCE(revision,1) AS rev FROM game_progress WHERE user_id = ? AND level = ? FOR UPDATE');
        $st->execute(array($uid, $level));
        $existing = $st->fetch();

        if ($existing && (int)$existing['rev'] !== $baseRevision) {
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

        // 只在内容变化时留快照，避免定时自动保存产生大量重复历史
        $emptyBoard = $existing && str_replace('0', '', (string)$existing['cells']) === '';
        $emptyNotes = $existing && (trim((string)$existing['notes']) === '' || trim((string)$existing['notes']) === '[]' || trim((string)$existing['notes']) === '{}');
        $snapshotNeeded = $existing && ((string)$existing['cells'] !== $cells || (string)$existing['notes'] !== $notesJson || ((int)$existing['seconds'] !== $seconds && !($emptyBoard && $emptyNotes)));
        if ($snapshotNeeded) {
            $pdo->prepare('INSERT INTO game_progress_history (user_id, level, cells, notes, seconds, revision) VALUES (?,?,?,?,?,?)')
                ->execute(array($uid, $level, $existing['cells'], $existing['notes'], (int)$existing['seconds'], (int)$existing['rev']));
            $stOld = $pdo->prepare('SELECT id FROM game_progress_history WHERE user_id = ? AND level = ? ORDER BY id DESC LIMIT 10');
            $stOld->execute(array($uid, $level));
            $keep = array();
            foreach ($stOld->fetchAll() as $oldRow) $keep[] = (int)$oldRow['id'];
            if (count($keep) > 0) {
                $marks = implode(',', array_fill(0, count($keep), '?'));
                $del = $pdo->prepare('DELETE FROM game_progress_history WHERE user_id = ? AND level = ? AND id NOT IN (' . $marks . ')');
                $del->execute(array_merge(array($uid, $level), $keep));
            }
        }
        if (!$existing) {
            $stHist = $pdo->prepare('SELECT id FROM game_progress_history WHERE user_id = ? AND level = ? ORDER BY id DESC LIMIT 10');
            $stHist->execute(array($uid, $level));
            $keepHist = array();
            foreach ($stHist->fetchAll() as $histRow) $keepHist[] = (int)$histRow['id'];
            if (count($keepHist) > 0) {
                $marksHist = implode(',', array_fill(0, count($keepHist), '?'));
                $pdo->prepare('DELETE FROM game_progress_history WHERE user_id = ? AND level = ? AND id NOT IN (' . $marksHist . ')')
                    ->execute(array_merge(array($uid, $level), $keepHist));
            }
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

/** GET progress/{level}/history：最近 10 个自动快照 */
function progress_history($level) {
    $c = current_user();
    if ($level < 1 || $level > 100) fail('关卡无效');
    $st = db()->prepare('SELECT id, seconds, revision, created_at, cells, notes FROM game_progress_history WHERE user_id = ? AND level = ? ORDER BY id DESC LIMIT 10');
    $st->execute(array((int)$c['uid'], $level));
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['seconds'] = (int)$r['seconds'];
        $r['revision'] = (int)$r['revision'];
        $r['notes'] = $r['notes'] ? json_decode($r['notes'], true) : array();
        if (!is_array($r['notes'])) $r['notes'] = array();
    }
    unset($r);
    $cur = db()->prepare('SELECT COALESCE(revision,1) AS revision FROM game_progress WHERE user_id = ? AND level = ?');
    $cur->execute(array((int)$c['uid'], $level));
    $current = $cur->fetch();
    ok(array('history' => $rows, 'currentRevision' => $current ? (int)$current['revision'] : 0));
}

/** POST progress/{level}/restore：恢复快照；仍使用 baseRevision 防止覆盖恢复期间的新改动 */
function progress_restore($level) {
    $c = current_user();
    $b = body();
    $historyId = isset($b['historyId']) ? (int)$b['historyId'] : 0;
    $baseRevision = isset($b['baseRevision']) ? (int)$b['baseRevision'] : 0;
    if ($level < 1 || $level > 100 || $historyId < 1) fail('恢复参数无效');
    $pdo = db();
    $uid = (int)$c['uid'];
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT id, cells, notes, seconds, updated_at, COALESCE(revision,1) AS rev FROM game_progress WHERE user_id = ? AND level = ? FOR UPDATE');
        $st->execute(array($uid, $level));
        $current = $st->fetch();
        if (!$current || (int)$current['rev'] !== $baseRevision) {
            $pdo->rollBack();
            fail('进度已发生变化，请刷新后再恢复', 409);
        }
        $st = $pdo->prepare('SELECT cells, notes, seconds FROM game_progress_history WHERE id = ? AND user_id = ? AND level = ?');
        $st->execute(array($historyId, $uid, $level));
        $snapshot = $st->fetch();
        if (!$snapshot) { $pdo->rollBack(); fail('历史版本不存在或已过期', 404); }

        $pdo->prepare('INSERT INTO game_progress_history (user_id, level, cells, notes, seconds, revision) VALUES (?,?,?,?,?,?)')
            ->execute(array($uid, $level, $current['cells'], $current['notes'], (int)$current['seconds'], (int)$current['rev']));
        $stOld = $pdo->prepare('SELECT id FROM game_progress_history WHERE user_id = ? AND level = ? ORDER BY id DESC LIMIT 10');
        $stOld->execute(array($uid, $level));
        $keep = array();
        foreach ($stOld->fetchAll() as $oldRow) $keep[] = (int)$oldRow['id'];
        $marks = implode(',', array_fill(0, count($keep), '?'));
        $del = $pdo->prepare('DELETE FROM game_progress_history WHERE user_id = ? AND level = ? AND id NOT IN (' . $marks . ')');
        $del->execute(array_merge(array($uid, $level), $keep));
        $pdo->prepare('UPDATE game_progress SET cells = ?, notes = ?, seconds = ?, revision = revision + 1 WHERE user_id = ? AND level = ?')
            ->execute(array($snapshot['cells'], $snapshot['notes'], (int)$snapshot['seconds'], $uid, $level));
        $st = $pdo->prepare('SELECT COALESCE(revision,1) AS rev FROM game_progress WHERE user_id = ? AND level = ?');
        $st->execute(array($uid, $level));
        $row = $st->fetch();
        $pdo->commit();
        $restoredNotes = $snapshot['notes'] ? json_decode($snapshot['notes'], true) : array();
        ok(array('level' => $level, 'cells' => (string)$snapshot['cells'], 'notes' => is_array($restoredNotes) ? $restoredNotes : array(), 'seconds' => (int)$snapshot['seconds'], 'revision' => (int)$row['rev']));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail('恢复失败：' . $e->getMessage(), 500);
    }
}
