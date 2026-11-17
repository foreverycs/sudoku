<?php
/**
 * 数独·静心一刻 API 入口（路由）
 * 兼容 PHP 5.4（无 ?? / Throwable / password_hash，见 compat.php）
 *
 * 路由方式（宝塔 nginx 无需伪静态，直接访问 index.php）：
 *   POST /api/index.php?r=auth/register
 *   POST /api/index.php?r=auth/login
 *   GET  /api/index.php?r=auth/me
 * 也兼容路径式（需 nginx/Apache 重写 /api/xxx → 本文件）。
 */
require_once __DIR__ . '/resp.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/handlers/auth.php';
require_once __DIR__ . '/handlers/progress.php';
require_once __DIR__ . '/handlers/game.php';
require_once __DIR__ . '/handlers/daily.php';
require_once __DIR__ . '/handlers/profile.php';
require_once __DIR__ . '/handlers/admin.php';

// CORS
header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'OPTIONS') { http_response_code(204); exit; }

// 解析路由：优先 ?r=，其次路径 /api/xxx
$r = isset($_GET['r']) ? trim((string)$_GET['r'], '/') : '';
if ($r === '') {
    $path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
    $path = $path ? $path : '/';
    $r = trim((string)preg_replace('#^/api#', '', $path), '/');
}
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

try {
    if ($method === 'POST' && $r === 'auth/register') {
        if (!rate_limit_check('register', @$_SERVER['REMOTE_ADDR'], 5, 3600)) fail('注册频率过高', 429);
        return auth_register();
    }
    if ($method === 'POST' && $r === 'auth/login') {
        if (!rate_limit_check('login', @$_SERVER['REMOTE_ADDR'], 10, 60)) fail('登录频率过高', 429);
        return auth_login();
    }
    if ($method === 'GET'  && $r === 'auth/me')       return auth_me();
    if ($method === 'GET'  && $r === 'progress')      return progress_all();
    if ($method === 'PUT'  && preg_match('#^progress/(\d{1,3})$#', $r, $m)) {
        $rateUser = current_user();
        if (!rate_limit_check('progress', 'uid:' . (int)$rateUser['uid'], 120, 60)) fail('保存频率过高', 429);
        return progress_save((int)$m[1]);
    }
    if ($method === 'GET' && preg_match('#^progress/(\d{1,3})/history$#', $r, $m)) {
        $rateUser = current_user();
        if (!rate_limit_check('progress_history', 'uid:' . (int)$rateUser['uid'], 60, 60)) fail('历史查询过于频繁', 429);
        return progress_history((int)$m[1]);
    }
    if ($method === 'POST' && preg_match('#^progress/(\d{1,3})/restore$#', $r, $m)) {
        $rateUser = current_user();
        if (!rate_limit_check('progress_restore', 'uid:' . (int)$rateUser['uid'], 10, 60)) fail('恢复操作过于频繁', 429);
        return progress_restore((int)$m[1]);
    }
    if ($method === 'POST' && $r === 'game/start') {
        $rateUser = current_user();
        if (!rate_limit_check('game_start', 'uid:' . (int)$rateUser['uid'], 30, 60)) fail('开新局频率过高', 429);
        return game_start();
    }
    if ($method === 'POST' && $r === 'daily/start') {
        $rateUser = current_user();
        if (!rate_limit_check('daily_start', 'uid:' . (int)$rateUser['uid'], 30, 3600)) fail('每日挑战开局频率过高', 429);
        return daily_start();
    }
    if ($method === 'POST' && $r === 'game/complete') {
        $rateUser = current_user();
        if (!rate_limit_check('game_complete', 'uid:' . (int)$rateUser['uid'], 10, 60)) fail('完成频率过高', 429);
        return game_complete();
    }
    if ($method === 'GET'  && $r === 'achievements')  return achievements_list();
    if ($method === 'GET'  && $r === 'me/stats')      return me_stats();
    if ($method === 'GET'  && $r === 'profile')       return profile_get();
    if ($method === 'PUT'  && $r === 'me/avatar')     return me_avatar_put();
    if ($method === 'GET'  && $r === 'leaderboard')   return leaderboard();
    if ($method === 'GET'  && $r === 'daily')         return daily_get();
    if ($method === 'POST' && $r === 'daily/complete') {
        $rateUser = current_user();
        if (!rate_limit_check('daily_complete', 'uid:' . (int)$rateUser['uid'], 2, 86400)) fail('每日挑战完成频率过高', 429);
        return daily_complete();
    }
    // 后台管理（阶段五）
    if ($method === 'GET'  && $r === 'admin/overview')        return admin_overview();
    if ($method === 'GET'  && $r === 'admin/users')           return admin_users();
    if ($method === 'GET'  && preg_match('#^admin/users/(\d+)$#', $r, $m)) return admin_user_detail((int)$m[1]);
    if ($method === 'POST' && preg_match('#^admin/users/(\d+)/status$#', $r, $m)) return admin_user_status((int)$m[1]);
    if ($method === 'POST' && preg_match('#^admin/users/(\d+)/role$#', $r, $m)) return admin_user_role((int)$m[1]);
    if ($method === 'POST' && preg_match('#^admin/users/(\d+)/reset$#', $r, $m)) return admin_user_reset((int)$m[1]);
    if ($method === 'POST' && preg_match('#^admin/users/(\d+)/delete$#', $r, $m)) return admin_user_delete((int)$m[1]);
    if ($method === 'GET'  && $r === 'admin/achievements')    return admin_achievements();
    if ($method === 'POST' && preg_match('#^admin/achievements/(\d+)/toggle$#', $r, $m)) return admin_achievement_toggle((int)$m[1]);
    if ($method === 'GET'  && $r === 'admin/logs')            return admin_logs();
    fail('接口不存在', 404);
} catch (Exception $e) {
    fail('服务器错误：' . $e->getMessage(), 500);
}
