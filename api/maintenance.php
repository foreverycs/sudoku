<?php
/** CLI maintenance only. Defaults to dry-run; pass --apply to delete old completed attempts. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

$apply = in_array('--apply', isset($argv) ? $argv : array(), true);
$pdo = db();
ensure_security_schema();
$cutoff = date('Y-m-d H:i:s', time() - 180 * 86400);
$st = $pdo->prepare('SELECT COUNT(*) FROM game_attempts WHERE completed_at IS NOT NULL AND completed_at < ?');
$st->execute(array($cutoff));
$count = (int)$st->fetchColumn();
if (!$apply) {
    echo 'dry-run: ' . $count . " completed attempts older than 180 days would be removed\n";
    exit(0);
}
$del = $pdo->prepare('DELETE FROM game_attempts WHERE completed_at IS NOT NULL AND completed_at < ?');
$del->execute(array($cutoff));
echo 'deleted: ' . (int)$del->rowCount() . " completed attempts older than 180 days\n";
