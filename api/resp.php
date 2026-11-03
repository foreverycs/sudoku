<?php
/** JSON 响应助手（PHP 5.4 兼容，无类型提示） */

function json_out($data, $code = 200) {
    http_response_code((int)$code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function ok($data = null) {
    json_out(array('ok' => true, 'data' => $data));
}

function fail($msg, $code = 400) {
    json_out(array('ok' => false, 'error' => $msg), $code);
}

/** 解析请求 JSON body */
function body() {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw ? $raw : '', true);
    return is_array($d) ? $d : array();
}
