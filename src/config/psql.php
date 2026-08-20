<?php

// 本文件由 psql 的 ThinkPHP 插件在安装时自动生成到 config/psql.php，可按需修改。
// Kingbes\Psql\Service 会在启动时把下面配置注入到 config/database.php 的 connections.psql。

return [
    // 本地数据目录；留空则默认使用 runtime 目录下的 psql
    'database'    => '',
    // 是否把 psql 设为 ThinkPHP 默认数据库连接
    'default'     => false,
    // 可选 psql 连接参数
    'concurrency' => false,
    'wal'         => false,
    'compress'    => false,
    'key'         => null,
];