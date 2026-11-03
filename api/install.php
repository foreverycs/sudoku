<?php
/**
 * 首次安装：建表 + 创建管理员 + 写入勋章种子
 * 部署后访问一次（如 /api/install.php），建议初始化完成后删除本文件
 * 兼容 PHP 5.4
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/resp.php';
require_once __DIR__ . '/compat.php';

function seed_achievements($pdo) {
    $items = [
        ['prog_1',    '初露锋芒', '完成第 1 关',           'progress',    'complete_count', 1,     10],
        ['prog_10',   '渐入佳境', '完成 10 关',             'progress',    'complete_count', 10,    30],
        ['prog_25',   '小有所成', '完成 25 关',             'progress',    'complete_count', 25,    50],
        ['prog_50',   '半程捷报', '完成 50 关',             'progress',    'complete_count', 50,    80],
        ['prog_100',  '百炼成钢', '完成 100 关',            'progress',    'complete_count', 100,  150],
        ['speed_60',  '电光石火', '单关最佳用时少于 60 秒',  'speed',       'best_seconds_lt', 60,   40],
        ['speed_30',  '一闪而逝', '单关最佳用时少于 30 秒',  'speed',       'best_seconds_lt', 30,   60],
        ['persist_1h','持之以恒', '累计游玩超过 1 小时',     'persistence', 'total_seconds_ge', 3600, 50],
        ['persist_10h','铁杵成针','累计游玩超过 10 小时',    'persistence', 'total_seconds_ge', 36000, 120],
        ['chal_hard', '勇攀高峰', '通关困难及以上难度',      'challenge',   'level_ge',       81,    80],
        ['daily_7',   '七日之约', '连续 7 天登录',           'daily',       'daily_streak_ge', 7,   40],
    ];
    $st = $pdo->prepare('INSERT IGNORE INTO achievements (code,name,description,category,cond_type,cond_value,points) VALUES (?,?,?,?,?,?,?)');
    foreach ($items as $it) $st->execute($it);
}

try {
    $pdo = db();

    // 建表（逐条执行，避免多语句限制）
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    if ($sql === false) fail('无法读取 schema.sql', 500);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if ($stmt !== '') $pdo->exec($stmt);
    }

    // 管理员（已存在则跳过）
    $st = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $st->execute([ADMIN_USER]);
    if (!$st->fetch()) {
        $pdo->prepare('INSERT INTO users (username, password_hash, nickname, role) VALUES (?,?,?,?)')
            ->execute([ADMIN_USER, ph_hash(ADMIN_PASS), '管理员', 'admin']);
    }

    // 勋章种子
    seed_achievements($pdo);

    ok(['message' => '初始化完成，管理员：' . ADMIN_USER]);
} catch (Exception $e) {
    fail('初始化失败：' . $e->getMessage(), 500);
}
