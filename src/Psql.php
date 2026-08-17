<?php

declare(strict_types=1);

namespace Kingbes\Psql;

use InvalidArgumentException;
use Kingbes\Psql\Storage\Codec;
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
     *
     * 选项：
     * - 'compress' => bool：落盘载荷 gzip 压缩（PGZ magic 头）
     * - 'key' => string：落盘载荷 AES-256-CBC 加密（PENC magic 头，需 openssl 扩展）
     *
     * 读侧按 magic 自适应：带/不带选项均可打开旧明文库；读加密库须提供原 key，
     * 读压缩库无需配置（decode 始终按 magic 分派，配置只影响写方向——渐进加密/压缩）
     *
     * @param array{compress?: bool, key?: ?string} $options
     */
    public static function connect(string $path, array $options = []): Connection
    {
        self::assertKnownOptions($options);
        $codec = new Codec(
            (bool) ($options['compress'] ?? false),
            self::optionKey($options),
        );

        return new Connection(new JsonFileEngine($path, $codec));
    }

    /**
     * 纯内存连接（无落盘，codec 选项不可用——传入抛 InvalidArgumentException）
     *
     * @param array{compress?: bool, key?: ?string} $options
     */
    public static function memory(array $options = []): Connection
    {
        self::assertKnownOptions($options);
        if (array_key_exists('compress', $options) || array_key_exists('key', $options)) {
            throw new InvalidArgumentException('内存引擎不支持 codec 选项');
        }

        return new Connection(new MemoryEngine());
    }

    /**
     * 仅接受已定义的连接选项键，未知键抛 InvalidArgumentException（显式优于静默）
     *
     * @param array<mixed> $options
     */
    private static function assertKnownOptions(array $options): void
    {
        $unknown = array_diff(array_keys($options), ['compress', 'key']);
        if ($unknown !== []) {
            throw new InvalidArgumentException('未知连接选项: ' . implode(', ', $unknown));
        }
    }

    /**
     * 提取加密口令选项：null 或缺省视为未配置，其余非字符串抛 InvalidArgumentException
     *
     * @param array{compress?: bool, key?: ?string} $options
     */
    private static function optionKey(array $options): ?string
    {
        if (!array_key_exists('key', $options) || $options['key'] === null) {
            return null;
        }
        if (!is_string($options['key'])) {
            throw new InvalidArgumentException('连接选项 key 必须为字符串');
        }

        return $options['key'];
    }
}
