<?php
/** 部署时执行一次；允许重复运行，失败返回非零退出码。 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/security.php';
try {
    ensure_security_schema();
    echo "数据库迁移完成\n";
} catch (Exception $e) {
    fwrite(STDERR, '数据库迁移失败：' . $e->getMessage() . "\n");
    exit(1);
}
