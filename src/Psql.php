<?php

declare(strict_types=1);

namespace Kingbes\Psql;

use InvalidArgumentException;
use Kingbes\Psql\Storage\Codec;
use Kingbes\Psql\Storage\JsonFileEngine;
use Kingbes\Psql\Storage\LockingEngine;
use Kingbes\Psql\Storage\MemoryEngine;
use Kingbes\Psql\Storage\StorageEngine;
use Kingbes\Psql\Storage\WalEngine;

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
     * - 'engine' => StorageEngine：自定义存储引擎实例，提供时直接使用，path 参数被忽略；
     *   与 compress/key 互斥（codec 由引擎内部配置，不可在 connect 层叠加）
     * - 'compress' => bool：落盘载荷 gzip 压缩（PGZ magic 头）
     * - 'key' => string：落盘载荷 AES-256-CBC 加密（PENC magic 头，需 openssl 扩展）
     * - 'concurrency' => bool：单 writer 多 reader 并发模式（v2.2）——操作级读写锁 +
     *   跨进程缓存失效，多个进程可同时读、写进程间互斥；所有进程访问同一库目录应一致
     *   使用本选项（或一致使用默认排他模式）；事务自动全程持写锁
     * - 'wal' => bool：WAL / 崩溃恢复（v2.3）——事务级 undo 快照（begin 原子写 undo.snap，
     *   崩溃后打开自动回滚未提交事务）+ 事务日志；进程级崩溃恢复（PHP 无可靠 fsync，断电级不保证）
     *
     * 读侧按 magic 自适应：带/不带选项均可打开旧明文库；读加密库须提供原 key，
     * 读压缩库无需配置（decode 始终按 magic 分派，配置只影响写方向——渐进加密/压缩）
     *
     * @param array{engine?: StorageEngine, compress?: bool, key?: ?string, concurrency?: bool, wal?: bool} $options
     */
    public static function connect(string $path, array $options = []): Connection
    {
        self::assertKnownOptions($options);
        $concurrency = (bool) ($options['concurrency'] ?? false);
        $wal = (bool) ($options['wal'] ?? false);

        // 自定义引擎：优先于内置 JSON 文件引擎，path 参数不再参与引擎构造
        if (array_key_exists('engine', $options)) {
            $engine = $options['engine'];
            if (!$engine instanceof StorageEngine) {
                throw new InvalidArgumentException('连接选项 engine 必须为 StorageEngine 实例');
            }
            if (array_key_exists('compress', $options) || array_key_exists('key', $options)) {
                throw new InvalidArgumentException('engine 与 compress/key 选项互斥（codec 由引擎内部配置）');
            }
            if ($wal) {
                $engine = new WalEngine($engine, $path);
            }
            if ($concurrency) {
                if (!method_exists($engine, 'invalidateCaches')) {
                    throw new InvalidArgumentException('并发模式要求引擎实现 invalidateCaches()（内建文件引擎以 acquireLock=false 构造）');
                }
                $engine = new LockingEngine($engine, $path);
            }

            return new Connection($engine);
        }

        $codec = new Codec(
            (bool) ($options['compress'] ?? false),
            self::optionKey($options),
        );

        // 内建 JSON 引擎：并发模式跳过连接级排他锁（交由 LockingEngine 操作级加锁）
        $engine = new JsonFileEngine($path, $codec, !$concurrency);
        if ($wal) {
            $engine = new WalEngine($engine, $path);
        }
        if ($concurrency) {
            $engine = new LockingEngine($engine, $path);
        }

        return new Connection($engine);
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
        $unknown = array_diff(array_keys($options), ['compress', 'key', 'engine', 'concurrency', 'wal']);
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
