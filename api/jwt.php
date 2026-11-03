<?php
require_once __DIR__ . '/config.php';

/** base64url 编码 */
function b64url($s) {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** base64url 解码 */
function b64url_decode($s) {
    return base64_decode(strtr($s, '-_', '+/'), true);
}

/** 常量时间比较（hash_equals 为 PHP 5.6+，此处兼容 5.4） */
function ph_hash_equals($a, $b) {
    if (function_exists('hash_equals')) return hash_equals($a, $b);
    if (strlen($a) !== strlen($b)) return false;
    $r = 0;
    for ($i = 0; $i < strlen($a); $i++) $r |= ord($a[$i]) ^ ord($b[$i]);
    return $r === 0;
}

/** 生成 JWT（HS256） */
function jwt_encode($payload) {
    $header = b64url(json_encode(array('alg' => 'HS256', 'typ' => 'JWT')));
    $body   = b64url(json_encode($payload));
    $sig    = b64url(hash_hmac('sha256', $header . '.' . $body, JWT_SECRET, true));
    return $header . '.' . $body . '.' . $sig;
}

/** 校验并解析 JWT，失败返回 null */
function jwt_decode($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    $h = $parts[0]; $b = $parts[1]; $s = $parts[2];
    $expected = b64url(hash_hmac('sha256', $h . '.' . $b, JWT_SECRET, true));
    if (!ph_hash_equals($expected, $s)) return null;
    $payload = json_decode(b64url_decode($b), true);
    if (!is_array($payload)) return null;
    if (isset($payload['exp']) && time() >= (int)$payload['exp']) return null;
    return $payload;
}
