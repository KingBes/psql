<?php

declare(strict_types=1);

namespace Kingbes\Psql\Storage;

use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Schema\TableSchema;

/**
 * WAL / 崩溃恢复装饰器：事务级 undo 快照 + 事务日志（全部文件引擎统一，包在 LockingEngine 内层）
 *
 * 写模型前提：各文件引擎"写穿"（每次写原子落盘，tmp+rename / 页 COW），单文件/单页层面崩溃安全；
 * 崩溃恢复的真正缺口是"未提交事务的部分落盘"（崩溃时内存快照丢失，无法回滚）。
 *
 * 机制：
 * - begin：把事务前全引擎快照（inner->snapshot()）原子写入 <root>/undo.snap + 清空 wal.log 写 begin 标记
 * - 事务内写：照常写穿落盘（无需额外记录，undo.snap 已含事务前完整状态）
 * - commit：写 wal.log commit 标记 + 删除 undo.snap（数据已落盘）
 * - rollback：连接内已由 Connection 用内存快照 restore；本层仅清 wal.log + 删 undo.snap
 * - 崩溃恢复（recoverIfNeeded，Connection 打开时调用）：若 undo.snap 存在 → 说明上次有
 *   未提交事务崩溃 → 从 undo.snap 恢复引擎并落盘，再删除 undo.snap
 *
 * 边界（诚实声明）：进程级崩溃恢复（PHP 进程被杀/fatal）；PHP 无可靠 fsync，断电级不保证；
 * 多表非事务写不原子（建议用事务包裹，事务整体可回滚）
 */
final class WalEngine implements StorageEngine
{
    /** 未提交事务的 undo 快照文件（位于数据目录根下） */
    private const UNDO_FILE = 'undo.snap';

    /** 事务日志（事务生命周期标记，供崩溃诊断与后续增量备份） */
    private const LOG_FILE = 'wal.log';

    private string $root;

    public function __construct(
        private StorageEngine $inner,
        string $root,
    ) {
        $this->root = rtrim($root, '/\\');
    }

    // ---- 事务钩子（由 Connection 调用；须在事务持锁期间） ----

    /**
     * 事务 begin：把事务前全引擎快照原子写入 undo.snap；崩溃时据此回滚未提交事务
     */
    public function beginTransaction(?EngineSnapshot $snapshot = null): void
    {
        $snapshot ??= $this->inner->snapshot();
        $this->atomicWrite(self::UNDO_FILE, $snapshot->payload);
        $this->resetLog(['op' => 'begin']);
    }

    /**
     * 事务 commit：写日志标记 + 删除 undo.snap（数据已写穿落盘）
     */
    public function commitTransaction(): void
    {
        $this->appendLog(['op' => 'commit']);
        @unlink($this->root . '/' . self::UNDO_FILE);
    }

    /**
     * 事务 rollback：写日志标记 + 删除 undo.snap（连接内已用内存快照 restore 并落盘）
     */
    public function rollbackTransaction(): void
    {
        $this->appendLog(['op' => 'rollback']);
        @unlink($this->root . '/' . self::UNDO_FILE);
    }

    /**
     * 崩溃恢复：undo.snap 存在说明上次有未提交事务崩溃 → 恢复引擎到事务前状态并落盘；
     * 排他锁保护（并发模式下打开时无锁，需短促独占）
     */
    public function recoverIfNeeded(): void
    {
        $undo = $this->root . '/' . self::UNDO_FILE;
        if (!is_file($undo)) {
            return;
        }
        DirectoryLock::acquireBlocking($this->root, false);
        try {
            // 等待锁期间可能已被其他进程恢复（文件消失即视为已处理）
            $raw = @file_get_contents($undo);
            if ($raw === false) {
                return;
            }
            $this->inner->restore(new EngineSnapshot($raw));
            $this->inner->persist();
            @unlink($undo);
            $this->appendLog(['op' => 'recovered']);
        } finally {
            DirectoryLock::release($this->root);
        }
    }

    // ---- StorageEngine：纯委托（操作级锁由外层 LockingEngine 提供） ----

    public function databases(): array
    {
        return $this->inner->databases();
    }

    public function hasDatabase(string $database): bool
    {
        return $this->inner->hasDatabase($database);
    }

    public function createDatabase(string $database): void
    {
        $this->inner->createDatabase($database);
    }

    public function dropDatabase(string $database): void
    {
        $this->inner->dropDatabase($database);
    }

    public function tables(string $database): array
    {
        return $this->inner->tables($database);
    }

    public function hasTable(string $database, string $table): bool
    {
        return $this->inner->hasTable($database, $table);
    }

    public function createTable(string $database, TableSchema $schema): void
    {
        $this->inner->createTable($database, $schema);
    }

    public function dropTable(string $database, string $table): void
    {
        $this->inner->dropTable($database, $table);
    }

    public function renameTable(string $database, string $from, string $to): void
    {
        $this->inner->renameTable($database, $from, $to);
    }

    public function replaceSchema(string $database, string $table, TableSchema $schema): void
    {
        $this->inner->replaceSchema($database, $table, $schema);
    }

    public function loadSchema(string $database, string $table): TableSchema
    {
        return $this->inner->loadSchema($database, $table);
    }

    public function readRows(string $database, string $table): array
    {
        return $this->inner->readRows($database, $table);
    }

    public function writeRows(string $database, string $table, array $rows): void
    {
        $this->inner->writeRows($database, $table, $rows);
    }

    public function deleteRows(string $database, string $table, array $indices): void
    {
        $this->inner->deleteRows($database, $table, $indices);
    }

    public function autoIncrement(string $database, string $table): int
    {
        return $this->inner->autoIncrement($database, $table);
    }

    public function setAutoIncrement(string $database, string $table, int $value): void
    {
        $this->inner->setAutoIncrement($database, $table, $value);
    }

    public function resetAutoIncrement(string $database, string $table): void
    {
        $this->inner->resetAutoIncrement($database, $table);
    }

    public function loadViewDefinitions(string $database): array
    {
        return $this->inner->loadViewDefinitions($database);
    }

    public function saveViewDefinitions(string $database, array $definitions): void
    {
        $this->inner->saveViewDefinitions($database, $definitions);
    }

    public function snapshot(): EngineSnapshot
    {
        return $this->inner->snapshot();
    }

    public function restore(EngineSnapshot $snapshot): void
    {
        $this->inner->restore($snapshot);
    }

    public function persist(): void
    {
        $this->inner->persist();
    }

    public function backupDatabase(string $database, string $targetDir): void
    {
        $this->inner->backupDatabase($database, $targetDir);
    }

    // ---- 内部 ----

    /**
     * 原子写文件（tmp + rename；崩溃时要么全写要么保留旧文件）
     */
    private function atomicWrite(string $name, string $payload): void
    {
        $file = $this->root . '/' . $name;
        $tmp = $file . '.tmp.' . uniqid('', true);
        if (@file_put_contents($tmp, $payload) === false) {
            throw new StorageException("无法写入事务快照: {$tmp}");
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new StorageException("无法落盘事务快照: {$file}");
        }
    }

    /**
     * 清空日志并写入首行（事务 begin 时）
     *
     * @param array<string, mixed> $record
     */
    private function resetLog(array $record): void
    {
        $this->appendLog($record, true);
    }

    /**
     * 追加一行事务日志（JSON 行式）
     *
     * @param array<string, mixed> $record
     */
    private function appendLog(array $record, bool $truncate = false): void
    {
        $file = $this->root . '/' . self::LOG_FILE;
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        if ($truncate || !is_file($file)) {
            @file_put_contents($file, $line, LOCK_EX);
        } else {
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        }
    }
}
