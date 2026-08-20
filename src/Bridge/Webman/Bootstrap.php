<?php

declare(strict_types=1);

namespace Kingbes\Psql\Bridge\Webman;

use think\Container;
use think\DbManager;
use think\Paginator;
use Webman\Bootstrap as WebmanBootstrap;
use Workerman\Worker;

/**
 * webman 启动引导：让 psql 通过 topthink/think-orm 在 webman 里可用，入口为 think\facade\Db。
 *
 * 仅依赖本引擎 + topthink/think-orm，无需 webman/think-orm 插件。
 * 作为 webman 插件启动类，由插件配置文件 config/plugin/kingbes/psql/bootstrap.php 注册，
 * webman 启动时自动调用（见 docs/integration.md 的 webman 一节），无需手动改任何配置。
 *
 * 用插件配置 config('plugin.kingbes.psql.app') 构造一个 think\DbManager，
 * 以我们的 PsqlOrm 连接驱动作为 default，并绑定到 think 容器，使 Db::name()/Model 可用。
 * webman 是协程模型：建议 concurrency(flock) 关闭，依赖单进程串行 + 快照事务。
 */
class Bootstrap implements WebmanBootstrap
{
    private static bool $initialized = false;

    public static function start(?Worker $worker): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        $config = config('plugin.kingbes.psql.app');
        if (!is_array($config) || !class_exists(\think\DbManager::class)) {
            return;
        }

        $manager = new DbManager();
        $manager->setConfig($config);
        Container::getInstance()->instance('think\DbManager', $manager);

        Paginator::currentPageResolver(static function ($pageName = 'page') {
            $request = request();
            if (!$request) {
                return 1;
            }
            $page = $request->input($pageName, 1);

            return filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1 ? (int) $page : 1;
        });

        Paginator::currentPathResolver(static function () {
            $request = request();

            return $request ? $request->path() : '/';
        });
    }
}