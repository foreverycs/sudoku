<?php
/**
 * linux.do OAuth2 回调入口
 * 使用独立 .php 文件作为 redirect_uri（不含查询参数），
 * 兼容 linux.do Connect 后台对回调地址的要求，且无需 nginx 伪静态。
 * 实际逻辑在 auth_linuxdo_callback()（api/handlers/auth.php）。
 */
require_once __DIR__ . '/handlers/auth.php';
auth_linuxdo_callback();
