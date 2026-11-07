<?php

/*
 * 跨來源讀取（CORS）：本站不提供。
 *
 * 框架預設對 api/* 開放所有來源（allowed_origins *）；公開唯讀 API 只給前端容器經內部專用網路由伺服器端呼叫，
 * 瀏覽器不直接呼叫 API（v1 §1.3），也不放寬跨來源讀取（定案執行書 C-12、規劃書 v5）。paths 留空即全站不送任何 CORS 標頭。
 */
return [
    'paths' => [],
    'allowed_methods' => [],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
