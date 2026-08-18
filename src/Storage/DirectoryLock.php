<?php

declare(strict_types=1);

namespace Kingbes\Psql\Storage;

use Kingbes\Psql\Exception\StorageException;

/**
 * 数据目录多进程读写锁：基于 <root>/.lock 文件的 flock 实现
 *
 * 单 writer 多 reader 语义：
 * - 排他（写）锁 LOCK_EX：一次仅一个进程持有；阻塞版等待当前所有 reader 释放
 * - 共享（读）锁 LOCK_SH：多个进程可同时持有；与排他锁互斥
 * - 同一进程内按 root 引用计数共享锁句柄（多次 acquire 不自我死锁）；已有共享锁
 *   升级排他锁时在同一句柄上转换（升级阻塞版等待其他进程的共享锁释放）
 *
 * acquire() 保持非阻塞（LOCK_NB，冲突即抛）以兼容既有调用与测试；
 * acquireBlocking() 提供操作级加锁的阻塞语义（等待冲突进程释放）
 */
final class DirectoryLock
{
    /** 锁文件名（位于数据目录根下，引擎只删 root 的子目录，永不会被删除） */
    private const LOCK_FILE = '.lock';

    /**
     * 进程内共享：root（规范化后）=> 锁句柄、引用计数与当前模式（LOCK_SH/LOCK_EX）
     *
     * @var array<string, array{handle: resource, refs: int, mode: int}>
     */
    private static array $locks = [];

    /**
     * 获取数据目录锁（非阻塞）：冲突（他进程持有冲突模式）抛 StorageException
     *
     * $shared=true 请求共享（读）锁，多进程可并存；$shared=false 请求排他（写）锁
     * （默认，保持既有语义——进程内第二连接直接共享引用计数）
     */
    public static function acquire(string $root, bool $shared = false): void
    {
        $key = rtrim($root, '/\\');
        if (isset(self::$locks[$key])) {
            self::retainOrEscalate($key, $shared, false);

            return;
        }

        $handle = self::openHandle($key);
        $mode = $shared ? LOCK_SH : LOCK_EX;
        if (!@flock($handle, $mode | LOCK_NB)) {
            @fclose($handle);
            throw new StorageException("数据目录已被其他进程占用: {$key}");
        }

        self::$locks[$key] = ['handle' => $handle, 'refs' => 1, 'mode' => $mode];
    }

    /**
     * 获取数据目录锁（阻塞）：等待冲突进程释放；适用于操作级加锁（单 writer 多 reader）
     */
    public static function acquireBlocking(string $root, bool $shared = false): void
    {
        $key = rtrim($root, '/\\');
        if (isset(self::$locks[$key])) {
            self::retainOrEscalate($key, $shared, true);

            return;
        }

        $handle = self::openHandle($key);
        $mode = $shared ? LOCK_SH : LOCK_EX;
        if (!@flock($handle, $mode)) {
            @fclose($handle);
            throw new StorageException("数据目录无法加锁: {$key}");
        }

        self::$locks[$key] = ['handle' => $handle, 'refs' => 1, 'mode' => $mode];
    }

    /**
     * 同进程已持锁：共享满足共享、排他满足一切；共享→排他需在同一句柄升级
     */
    private static function retainOrEscalate(string $key, bool $shared, bool $blocking): void
    {
        $entry = &self::$locks[$key];
        if (!$shared && $entry['mode'] === LOCK_SH) {
            $ok = $blocking
                ? @flock($entry['handle'], LOCK_EX)
                : @flock($entry['handle'], LOCK_EX | LOCK_NB);
            if (!$ok) {
                throw new StorageException("数据目录无法升级为写锁: {$key}");
            }
            $entry['mode'] = LOCK_EX;
        }
        ++$entry['refs'];
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

    /**
     * 打开（必要时创建）锁文件句柄
     *
     * @return resource
     */
    private static function openHandle(string $key)
    {
        $handle = @fopen($key . '/' . self::LOCK_FILE, 'c');
        if ($handle === false) {
            throw new StorageException('无法创建锁文件: ' . $key . '/' . self::LOCK_FILE);
        }

        return $handle;
    }
}
