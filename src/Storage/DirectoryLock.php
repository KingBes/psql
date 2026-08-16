<?php

declare(strict_types=1);

namespace Kingbes\Psql\Storage;

use Kingbes\Psql\Exception\StorageException;

/**
 * 数据目录多进程排他锁：基于 <root>/.lock 文件的 flock 实现
 *
 * 同一进程内按 root 引用计数共享锁句柄（多次 acquire 不会自我死锁）；
 * 跨进程通过非阻塞排他 flock 互斥，目录被其他进程持有时抛 StorageException。
 */
final class DirectoryLock
{
    /** 锁文件名（位于数据目录根下，引擎只删 root 的子目录，永不会被删除） */
    private const LOCK_FILE = '.lock';

    /**
     * 进程内共享：root（规范化后）=> 锁句柄与引用计数
     *
     * @var array<string, array{handle: resource, refs: int}>
     */
    private static array $locks = [];

    /**
     * 获取数据目录排他锁（跨进程互斥；同进程引用计数共享，不自我死锁）
     *
     * 锁文件为 <root>/.lock；被其他进程持有时抛 StorageException（消息含 root）
     */
    public static function acquire(string $root): void
    {
        // 与引擎构造器一致的规范化：rtrim 后原样作 map 键
        $key = rtrim($root, '/\\');
        if (isset(self::$locks[$key])) {
            ++self::$locks[$key]['refs'];

            return;
        }

        $handle = @fopen($key . '/' . self::LOCK_FILE, 'c');
        if ($handle === false) {
            throw new StorageException('无法创建锁文件: ' . $key . '/' . self::LOCK_FILE);
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);
            throw new StorageException("数据目录已被其他进程占用: {$key}");
        }

        self::$locks[$key] = ['handle' => $handle, 'refs' => 1];
    }

    /**
     * 释放锁：引用计数归零才真正解锁（flock LOCK_UN + fclose）并移除进程内记录；幂等
     */
    public static function release(string $root): void
    {
        $key = rtrim($root, '/\\');
        if (!isset(self::$locks[$key])) {
            return;
        }
        if (--self::$locks[$key]['refs'] > 0) {
            return;
        }
        @flock(self::$locks[$key]['handle'], LOCK_UN);
        @fclose(self::$locks[$key]['handle']);
        unset(self::$locks[$key]);
    }
}
