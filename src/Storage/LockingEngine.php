<?php

declare(strict_types=1);

namespace Kingbes\Psql\Storage;

use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Schema\TableSchema;

/**
 * 存储引擎并发装饰器：单 writer 多 reader（操作级读写锁 + 跨进程缓存失效）
 *
 * - 每个 StorageEngine 方法按读写分类加阻塞锁：读共享（LOCK_SH，多进程并存）、写排他（LOCK_EX）
 * - 写操作后递增 <root>/.wv 版本文件；读操作前校验版本，变化则清空内层引擎进程内缓存
 *   （长驻 reader 进程能看到 writer 的写入）
 * - 语句级：readLocked()/writeLocked() 在一次加锁内执行整段闭包（SELECT 全语句持共享锁）
 * - 事务级：holdExclusive()/releaseExclusive() 从 begin 到 commit/rollback 全程持排他锁
 *
 * 配合引擎以 acquireLock=false 构造（跳过连接级生命周期锁）；所有进程访问同一库目录
 * 应一致使用并发模式（或一致使用默认排他模式），避免混用导致语义不一致
 */
final class LockingEngine implements StorageEngine
{
    /** 跨进程写版本文件（位于数据目录根下，被 .lock 排除枚举） */
    private const VERSION_FILE = '.wv';

    private string $root;

    /** 最近一次读到的写版本（跨进程缓存失效判定） */
    private ?string $lastVersion = null;

    /** 事务持排他锁计数（begin..commit/rollback 期间 >0） */
    private int $hold = 0;

    public function __construct(
        private StorageEngine $inner,
        string $root,
    ) {
        $this->root = rtrim($root, '/\\');
    }

    // ---- 语句级 / 事务级 ----

    /**
     * 一次共享锁内执行读语句（整语句一致性）；事务中已持排他锁则直接执行
     */
    public function readLocked(callable $fn): mixed
    {
        if ($this->hold > 0) {
            return $fn();
        }
        DirectoryLock::acquireBlocking($this->root, true);
        try {
            $this->checkExternalVersion();

            return $fn();
        } finally {
            DirectoryLock::release($this->root);
        }
    }

    /**
     * 一次排他锁内执行写语句并递增写版本；事务中已持排他锁则直接执行
     */
    public function writeLocked(callable $fn): mixed
    {
        if ($this->hold > 0) {
            $result = $fn();
            $this->bumpVersion();

            return $result;
        }
        DirectoryLock::acquireBlocking($this->root, false);
        try {
            $result = $fn();
            $this->bumpVersion();

            return $result;
        } finally {
            DirectoryLock::release($this->root);
        }
    }

    /**
     * 事务 begin 调用：全程持排他锁（阻塞等待当前 reader 释放）；可嵌套（计数）
     */
    public function holdExclusive(): void
    {
        DirectoryLock::acquireBlocking($this->root, false);
        ++$this->hold;
    }

    /**
     * 内层 WAL 装饰器（wal + concurrency 组合时，供 Connection 取事务钩子）
     */
    public function innerWalEngine(): ?WalEngine
    {
        return $this->inner instanceof WalEngine ? $this->inner : null;
    }

    /**
     * 事务 commit/rollback 调用：计数归零才释放排他锁；幂等
     */
    public function releaseExclusive(): void
    {
        if ($this->hold <= 0) {
            return;
        }
        --$this->hold;
        DirectoryLock::release($this->root);
    }

    // ---- StorageEngine：按读写分类加锁委托 ----

    public function databases(): array
    {
        return $this->run(true, fn (): array => $this->inner->databases());
    }

    public function hasDatabase(string $database): bool
    {
        return $this->run(true, fn (): bool => $this->inner->hasDatabase($database));
    }

    public function createDatabase(string $database): void
    {
        $this->run(false, function () use ($database): void {
            $this->inner->createDatabase($database);
        });
    }

    public function dropDatabase(string $database): void
    {
        $this->run(false, function () use ($database): void {
            $this->inner->dropDatabase($database);
        });
    }

    public function tables(string $database): array
    {
        return $this->run(true, fn (): array => $this->inner->tables($database));
    }

    public function hasTable(string $database, string $table): bool
    {
        return $this->run(true, fn (): bool => $this->inner->hasTable($database, $table));
    }

    public function createTable(string $database, TableSchema $schema): void
    {
        $this->run(false, function () use ($database, $schema): void {
            $this->inner->createTable($database, $schema);
        });
    }

    public function dropTable(string $database, string $table): void
    {
        $this->run(false, function () use ($database, $table): void {
            $this->inner->dropTable($database, $table);
        });
    }

    public function renameTable(string $database, string $from, string $to): void
    {
        $this->run(false, function () use ($database, $from, $to): void {
            $this->inner->renameTable($database, $from, $to);
        });
    }

    public function replaceSchema(string $database, string $table, TableSchema $schema): void
    {
        $this->run(false, function () use ($database, $table, $schema): void {
            $this->inner->replaceSchema($database, $table, $schema);
        });
    }

    public function loadSchema(string $database, string $table): TableSchema
    {
        return $this->run(true, fn (): TableSchema => $this->inner->loadSchema($database, $table));
    }

    public function readRows(string $database, string $table): array
    {
        return $this->run(true, fn (): array => $this->inner->readRows($database, $table));
    }

    public function writeRows(string $database, string $table, array $rows): void
    {
        $this->run(false, function () use ($database, $table, $rows): void {
            $this->inner->writeRows($database, $table, $rows);
        });
    }

    public function deleteRows(string $database, string $table, array $indices): void
    {
        $this->run(false, function () use ($database, $table, $indices): void {
            $this->inner->deleteRows($database, $table, $indices);
        });
    }

    public function autoIncrement(string $database, string $table): int
    {
        return $this->run(true, fn (): int => $this->inner->autoIncrement($database, $table));
    }

    public function setAutoIncrement(string $database, string $table, int $value): void
    {
        $this->run(false, function () use ($database, $table, $value): void {
            $this->inner->setAutoIncrement($database, $table, $value);
        });
    }

    public function resetAutoIncrement(string $database, string $table): void
    {
        $this->run(false, function () use ($database, $table): void {
            $this->inner->resetAutoIncrement($database, $table);
        });
    }

    public function loadViewDefinitions(string $database): array
    {
        return $this->run(true, fn (): array => $this->inner->loadViewDefinitions($database));
    }

    public function saveViewDefinitions(string $database, array $definitions): void
    {
        $this->run(false, function () use ($database, $definitions): void {
            $this->inner->saveViewDefinitions($database, $definitions);
        });
    }

    public function snapshot(): EngineSnapshot
    {
        return $this->run(true, fn (): EngineSnapshot => $this->inner->snapshot());
    }

    public function restore(EngineSnapshot $snapshot): void
    {
        $this->run(false, function () use ($snapshot): void {
            $this->inner->restore($snapshot);
        });
    }

    public function persist(): void
    {
        $this->run(false, function (): void {
            $this->inner->persist();
        });
    }

    public function backupDatabase(string $database, string $targetDir): void
    {
        $this->run(true, function () use ($database, $targetDir): void {
            $this->inner->backupDatabase($database, $targetDir);
        });
    }

    /**
     * 操作级执行：读加共享锁并校验外部写版本、写加排他锁并递增写版本；
     * 事务持锁期间直接执行（同进程锁引用计数已满足）
     */
    private function run(bool $shared, callable $fn): mixed
    {
        if ($this->hold > 0) {
            // 事务已持排他锁：直接执行；写操作仍递增写版本（供外部 reader 缓存失效）
            $result = $fn();
            if (!$shared) {
                $this->bumpVersion();
            }

            return $result;
        }
        DirectoryLock::acquireBlocking($this->root, $shared);
        try {
            $result = $shared ? $this->checkExternalVersionAnd($fn) : $fn();
            if (!$shared) {
                $this->bumpVersion();
            }

            return $result;
        } finally {
            DirectoryLock::release($this->root);
        }
    }

    /**
     * 读操作：先校验外部写版本（变化则清空内层缓存），再执行
     */
    private function checkExternalVersionAnd(callable $fn): mixed
    {
        $this->checkExternalVersion();

        return $fn();
    }

    /**
     * 校验 <root>/.wv 写版本：与上次读取不同则清空内层引擎进程内缓存
     */
    private function checkExternalVersion(): void
    {
        $file = $this->root . '/' . self::VERSION_FILE;
        $version = is_file($file) ? (string) @file_get_contents($file) : '';
        if ($version === $this->lastVersion) {
            return;
        }
        $this->lastVersion = $version;
        if (method_exists($this->inner, 'invalidateCaches')) {
            $this->inner->invalidateCaches();
        }
    }

    /**
     * 递增 <root>/.wv 写版本（写操作持排他锁期间原子更新；供其他进程缓存失效）
     */
    private function bumpVersion(): void
    {
        $file = $this->root . '/' . self::VERSION_FILE;
        $version = is_file($file) ? (int) @file_get_contents($file) : 0;
        $next = $version + 1;
        $tmp = $file . '.tmp.' . uniqid('', true);
        if (@file_put_contents($tmp, (string) $next) === false) {
            throw new StorageException("无法写入写版本文件: {$tmp}");
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new StorageException("无法落盘写版本文件: {$file}");
        }
        $this->lastVersion = (string) $next;
    }
}
