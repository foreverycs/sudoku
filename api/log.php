<?php
/** 轻量 API 错误日志：只记录接口元数据和错误摘要，不记录凭证、请求体或棋盘。 */
function api_log_error($message, $code = 500) {
    $dir = defined('PRIVATE_DATA_DIR') ? rtrim(PRIVATE_DATA_DIR, '/\\') . '/logs' : rtrim(sys_get_temp_dir(), '/\\') . '/sudoku-logs-' . substr(sha1(__DIR__), 0, 12);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (!is_dir($dir) || !is_writable($dir)) return;
    @chmod($dir, 0700);
    $route = isset($_GET['r']) ? (string)$_GET['r'] : (string)@parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
    $uid = isset($GLOBALS['api_log_uid']) ? (int)$GLOBALS['api_log_uid'] : 0;
    $line = json_encode(array('at' => date('c'), 'code' => (int)$code, 'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '', 'route' => substr($route, 0, 120), 'uid' => $uid, 'message' => substr(preg_replace('/[\r\n]+/', ' ', (string)$message), 0, 500)), JSON_UNESCAPED_UNICODE);
    @file_put_contents($dir . '/api-' . date('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
}
