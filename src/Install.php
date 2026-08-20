<?php

declare(strict_types=1);

namespace Kingbes\Psql;

/**
 * webman 插件安装入口（遵循 webman 插件规范，参考 webman/think-orm、kingbes/attribute）。
 *
 * const WEBMAN_PLUGIN 标记该库为 webman 插件；webman 的 Plugin 扫描器在 composer
 * 安装时按 PSR-4 命名空间定位本类，并调用 install()/uninstall()。
 *
 * 安装时把 src/config/plugin/kingbes/psql 整目录发布到主项目 config/plugin/kingbes/psql：
 *   - app.php        -> webman 自动加载为 config('plugin.kingbes.psql.app')
 *   - bootstrap.php  -> webman 自动遍历并调用其中引导类的 start()
 */
class Install
{
    const WEBMAN_PLUGIN = true;

    /**
     * @var array source => dest（相对主项目根目录）
     */
    protected static array $pathRelation = [
        'config/plugin/kingbes/psql' => 'config/plugin/kingbes/psql',
    ];

    public static function install(): void
    {
        static::installByRelation();
    }

    public static function uninstall(): void
    {
        self::uninstallByRelation();
    }

    public static function installByRelation(): void
    {
        foreach (static::$pathRelation as $source => $dest) {
            if ($pos = strrpos($dest, '/')) {
                $parent = base_path() . '/' . substr($dest, 0, $pos);
                if (!is_dir($parent)) {
                    mkdir($parent, 0777, true);
                }
            }
            copy_dir(__DIR__ . "/$source", base_path() . "/$dest");
            echo "Create $dest" . PHP_EOL;
        }
    }

    public static function uninstallByRelation(): void
    {
        foreach (static::$pathRelation as $source => $dest) {
            $path = base_path() . "/$dest";
            if (is_file($path) || is_link($path)) {
                unlink($path);
                continue;
            }
            if (is_dir($path)) {
                remove_dir($path);
            }
        }
    }
}