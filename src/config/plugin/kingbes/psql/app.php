<?php

// psql 的 webman 插件配置（安装时发布到 config/plugin/kingbes/psql/app.php）。
// webman 自动加载为 config('plugin.kingbes.psql.app')，经本包 Bootstrap 引导类读取。

return [
    'default' => 'psql',
    'connections' => [
        'psql' => [
            'type'     => \Kingbes\Psql\Bridge\PsqlOrm::class,
            'database' => runtime_path() . '/psql',
            'psql'     => [
                // webman 是协程模型，建议关闭 flock 并发（见 docs/integration.md）
                'concurrency' => false,
                'wal'         => false,
            ],
        ],
    ],
];