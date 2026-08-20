<?php

declare(strict_types=1);

namespace Kingbes\Psql;

use Kingbes\Psql\Bridge\PsqlOrm;
use think\Service as BaseService;

/**
 * ThinkPHP 服务：安装即用。
 *
 * 依赖 composer.json 的 extra.think 自动注册（services/import），在框架初始化时
 * 把 config/psql.php 中的配置注入到 config/database.php 的 connections.psql，
 * 使 Db::connect('psql')（或设为 default 后直接 Db::name）可用，无需手动改 database.php。
 */
class Service extends BaseService
{
    public function boot(): void
    {
        $config = (array) $this->app->config->get('psql', []);

        $path = $config['database'] ?? '';
        if ($path === '') {
            $path = $this->app->getRuntimePath() . 'psql';
        }

        $connection = [
            'type'     => PsqlOrm::class,
            'database' => $path,
            'psql'     => [
                'concurrency' => (bool) ($config['concurrency'] ?? false),
                'wal'         => (bool) ($config['wal'] ?? false),
                'compress'    => (bool) ($config['compress'] ?? false),
                'key'         => $config['key'] ?? null,
            ],
        ];

        $db = (array) $this->app->config->get('database', []);
        $db['connections']['psql'] = $connection;
        if (!empty($config['default'])) {
            $db['default'] = 'psql';
        }

        $this->app->config->set($db, 'database');
    }
}