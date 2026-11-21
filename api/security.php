<?php
/**
 * 安全与迁移助手（兼容 PHP 5.4）
 * - ensure_security_schema()：仅供命令行显式迁移使用
 * - rate_limit_check()：文件 + flock 简易速率限制
 */
require_once __DIR__ . '/db.php';

/**
 * 确保安全相关表结构存在。部署时通过 php api/migrate.php 显式运行：
 *   1) game_attempts 表（幂等结算）
 *   2) game_progress.revision 列（乐观锁）
 * 仅供部署脚本调用，脚本内重复调用最多执行一次。
 */
function ensure_security_schema() {
    if (PHP_SAPI !== 'cli') throw new Exception('数据库迁移仅允许在命令行执行');
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    $pdo->exec('CREATE TABLE IF NOT EXISTS game_attempts (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id         INT UNSIGNED NOT NULL,
        level           SMALLINT UNSIGNED NOT NULL,
        attempt_key     VARCHAR(64) NOT NULL,
        started_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at    DATETIME NULL,
        submitted_cells VARCHAR(81) NULL,
        is_valid        TINYINT(1) NOT NULL DEFAULT 0,
        result_json     TEXT NULL,
        UNIQUE KEY uq_user_level_key (user_id, level, attempt_key),
        INDEX idx_attempt_user (user_id, level),
        CONSTRAINT fk_attempt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // 云进度历史快照（恢复时作为新版本写回当前进度）
    $pdo->exec('CREATE TABLE IF NOT EXISTS game_progress_history (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id    INT UNSIGNED NOT NULL,
        level      SMALLINT UNSIGNED NOT NULL,
        cells      VARCHAR(81) NOT NULL,
        notes      TEXT NULL,
        seconds    INT UNSIGNED NOT NULL DEFAULT 0,
        revision   BIGINT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_history_user_level (user_id, level, id),
        CONSTRAINT fk_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // 双向好友（user_a < user_b 唯一对）
    $pdo->exec('CREATE TABLE IF NOT EXISTS friends (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_a     INT UNSIGNED NOT NULL,
        user_b     INT UNSIGNED NOT NULL,
        status     TINYINT UNSIGNED NOT NULL DEFAULT 1,
        requested_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pair (user_a, user_b),
        CONSTRAINT fk_friend_a FOREIGN KEY (user_a) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_friend_b FOREIGN KEY (user_b) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "friends" AND COLUMN_NAME = "status"');
        $st->execute();
        if ((int)$st->fetchColumn() === 0) $pdo->exec('ALTER TABLE friends ADD COLUMN status TINYINT UNSIGNED NOT NULL DEFAULT 1');
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "friends" AND COLUMN_NAME = "updated_at"');
        $st->execute();
        if ((int)$st->fetchColumn() === 0) $pdo->exec('ALTER TABLE friends ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
    } catch (Exception $e) {
        throw $e;
    }
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "friends" AND COLUMN_NAME = "requested_by"');
        $st->execute();
        if ((int)$st->fetchColumn() === 0) $pdo->exec('ALTER TABLE friends ADD COLUMN requested_by INT UNSIGNED NULL');
        $pdo->exec('UPDATE friends SET requested_by = user_a WHERE status = 0 AND requested_by IS NULL');
    } catch (Exception $e) {
        throw $e;
    }

    // Optional index for per-level best-time aggregation in leaderboards.
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "game_records" AND INDEX_NAME = "idx_records_level_user_seconds"');
        $st->execute();
        if ((int)$st->fetchColumn() === 0) $pdo->exec('CREATE INDEX idx_records_level_user_seconds ON game_records (level, user_id, seconds)');
    } catch (Exception $e) {
        throw $e;
    }

    // revision 列（MySQL/MariaDB 通用：先查 information_schema 再 ALTER）
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "game_progress" AND COLUMN_NAME = "revision"');
        $st->execute();
        if ((int)$st->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE game_progress ADD COLUMN revision BIGINT UNSIGNED NOT NULL DEFAULT 1');
        }
    } catch (Exception $e) {
        throw $e;
    }

    // token_version 列（改密码后使旧 JWT 即时失效）
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "users" AND COLUMN_NAME = "token_version"');
        $st->execute();
        if ((int)$st->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE users ADD COLUMN token_version INT UNSIGNED NOT NULL DEFAULT 0');
        }
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * 简易速率限制（基于本地文件 + flock，PHP 5.4 兼容）。
 * 目录 api/cache/rate/（运行时创建，已在 .gitignore）。
 * 返回 true=放行，false=超限。
 */
function rate_limit_check($action, $identifier, $maxCount = 5, $windowSeconds = 3600) {
    $dir = defined('PRIVATE_DATA_DIR') ? rtrim(PRIVATE_DATA_DIR, '/\\') . '/rate' : '';
    if ($dir === '') $dir = rtrim(sys_get_temp_dir(), '/\\') . '/sudoku-rate-' . substr(sha1(__DIR__), 0, 12);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    @chmod($dir, 0700);
    $key = md5((string)$action . ':' . (string)$identifier);
    $file = $dir . '/' . $key . '.lock';
    $now = time();
    $lockFile = @fopen($file, 'c+');
    if (!$lockFile) return true; // 无法加锁则放行（不阻断业务）
    if (!flock($lockFile, LOCK_EX)) { fclose($lockFile); return true; }

    $content = (string)stream_get_contents($lockFile);
    $count = 0;
    $newLines = array();
    foreach (explode("\n", trim($content)) as $line) {
        $parts = explode(' ', trim($line), 2);
        if (count($parts) === 2 && (int)$parts[0] > ($now - $windowSeconds)) {
            $newLines[] = $line;
            $count++;
        }
    }
    $count++;
    $newLines[] = $now . ' ' . getmypid();

    ftruncate($lockFile, 0);
    rewind($lockFile);
    fwrite($lockFile, implode("\n", $newLines) . "\n");
    flock($lockFile, LOCK_UN);
    fclose($lockFile);

    return $count <= $maxCount;
}
