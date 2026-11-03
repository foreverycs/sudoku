<?php
/**
 * PHP 5.4 兼容层
 * 5.5+ 才有 password_hash/password_verify，这里用 crypt() Blowfish 实现等价功能
 */

function ph_hash($password) {
    if (function_exists('password_hash')) return password_hash($password, PASSWORD_BCRYPT);
    $salt = '$2y$10$' . ph_salt(22);
    $hash = crypt($password, $salt);
    return $hash !== '' ? $hash : $salt; // 兜底，避免空串
}

function ph_verify($password, $hash) {
    if (function_exists('password_verify')) return password_verify($password, $hash);
    return crypt($password, $hash) === $hash;
}

/** 生成 Blowfish 盐（22 字符，字符集 ./A-Za-z0-9） */
function ph_salt($len) {
    $chars = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $s = '';
    if (function_exists('openssl_random_pseudo_bytes')) {
        $b = openssl_random_pseudo_bytes($len);
        for ($i = 0; $i < $len; $i++) $s .= $chars[ord($b[$i]) & 63];
        return $s;
    }
    for ($i = 0; $i < $len; $i++) $s .= $chars[mt_rand(0, 63)];
    return $s;
}
