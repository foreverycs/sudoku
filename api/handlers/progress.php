<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../resp.php';
require_once __DIR__ . '/../auth.php';

/** GET progress 拉取当前用户全部云进度 */
function progress_all() {
    $c = current_user();
    $st = db()->prepare('SELECT level, cells, notes, seconds, updated_at FROM game_progress WHERE user_id = ?');
    $st->execute([$c['uid']]);
    $rows = $st->fetchAll();
    $map = array();
    foreach ($rows as $r) {
        $notes = $r['notes'] ? json_decode($r['notes'], true) : array();
        $map[(int)$r['level']] = array(
            'cells'     => (string)$r['cells'],
            'notes'     => is_array($notes) ? $notes : array(),
            'seconds'   => (int)$r['seconds'],
            'updatedAt' => (string)$r['updated_at'],
        );
    }
    ok(array('progress' => $map));
}

/** PUT progress/{level} 上传单关进度 */
function progress_save($level) {
    $c = current_user();
    $b = body();
    $cells   = isset($b['cells']) ? (string)$b['cells'] : '';
    $notes   = isset($b['notes']) ? $b['notes'] : array();
    $seconds = isset($b['seconds']) ? (int)$b['seconds'] : 0;
    if ($level < 1 || $level > 100) fail('关卡无效');
    if (strlen($cells) !== 81) fail('cells 需 81 字符');
    $seconds = max(0, min(100000, $seconds));
    $notesJson = is_array($notes) ? json_encode($notes) : '[]';
    $pdo = db();
    $st = $pdo->prepare(
        'INSERT INTO game_progress (user_id, level, cells, notes, seconds)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE cells=VALUES(cells), notes=VALUES(notes), seconds=VALUES(seconds)'
    );
    $st->execute([$c['uid'], $level, $cells, $notesJson, $seconds]);
    ok(array('level' => $level));
}
