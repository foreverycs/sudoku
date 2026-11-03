<?php
/**
 * 数独·静心一刻 后端配置
 * 部署时按服务器修改；JWT_SECRET 必须改为随机长串；勿提交真实凭据到 git
 */
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'sudoku');
define('DB_USER', 'sudoku');
define('DB_PASS', 'CHANGE_ME');
define('DB_CHARSET', 'utf8mb4');

// JWT
define('JWT_SECRET', 'CHANGE_ME_TO_LONG_RANDOM_STRING');
define('JWT_EXPIRE', 60 * 60 * 24 * 7);   // 7 天

// 跨域（线上保持同源或填具体域名）
define('CORS_ORIGIN', '*');

// 初始管理员（install.php 首次初始化用，安装后请修改密码）
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'admin123456');

date_default_timezone_set('Asia/Shanghai');
