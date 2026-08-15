<?php

declare(strict_types=1);

namespace Kingbes\Psql\Storage;

use Kingbes\Psql\Schema\TableSchema;

/**
 * 可插拔存储引擎接口：库/表结构与行数据的统一存取契约
 */
interface StorageEngine
{
    /**
     * @return list<string>
     */
    public function databases(): array;

    public function hasDatabase(string $database): bool;

    /**
     * 已存在或名称非法抛 StorageException
     */
    public function createDatabase(string $database): void;

    /**
     * 不存在或名称非法抛 StorageException
     */
    public function dropDatabase(string $database): void;

    /**
     * 数据库不存在抛 StorageException
     *
     * @return list<string>
     */
    public function tables(string $database): array;

    public function hasTable(string $database, string $table): bool;

    /**
     * 数据库不存在或表已存在抛 StorageException
     */
    public function createTable(string $database, TableSchema $schema): void;

    /**
     * 表不存在抛 StorageException
     */
    public function dropTable(string $database, string $table): void;

    /**
     * from 不存在或 to 已存在抛 StorageException；结构中表名同步更新
     */
    public function renameTable(string $database, string $from, string $to): void;

    /**
     * 表不存在抛 StorageException
     */
    public function replaceSchema(string $database, string $table, TableSchema $schema): void;

    /**
     * 表不存在抛 StorageException
     */
    public function loadSchema(string $database, string $table): TableSchema;

    /**
     * 表不存在抛 StorageException
     *
     * @return list<array<string,mixed>>
     */
    public function readRows(string $database, string $table): array;

    /**
     * 全量替换行数据；表不存在抛 StorageException
     *
     * @param list<array<string,mixed>> $rows
     */
    public function writeRows(string $database, string $table, array $rows): void;

    /**
     * 当前已分配的最大自增值（初始 0）；表不存在抛 StorageException
     */
    public function autoIncrement(string $database, string $table): int;

    /**
     * 只增不减：value <= 当前值时抛 StorageException
     */
    public function setAutoIncrement(string $database, string $table, int $value): void;

    /**
     * 重置自增计数为 0（TRUNCATE 语义）；表不存在抛 StorageException；无自增列的表调用为 no-op
     */
    public function resetAutoIncrement(string $database, string $table): void;

    /**
     * 全引擎状态快照（所有数据库所有表）
     */
    public function snapshot(): EngineSnapshot;

    /**
     * 恢复到快照状态；载荷非法抛 StorageException
     */
    public function restore(EngineSnapshot $snapshot): void;

    /**
     * 确保持久化（内存引擎为空操作）
     */
    public function persist(): void;
}
