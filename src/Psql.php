<?php

declare(strict_types=1);

namespace Kingbes\Psql;

use Kingbes\Psql\Storage\JsonFileEngine;
use Kingbes\Psql\Storage\MemoryEngine;

/**
 * 静态门面：连接创建入口
 */
final class Psql
{
    /**
     * 禁止实例化
     */
    private function __construct()
    {
    }

    /**
     * 打开/创建本地持久化连接（JSON 文件引擎）；路径非法/不可写由引擎抛 StorageException
     */
    public static function connect(string $path): Connection
    {
        return new Connection(new JsonFileEngine($path));
    }

    /**
     * 纯内存连接
     */
    public static function memory(): Connection
    {
        return new Connection(new MemoryEngine());
    }
}
