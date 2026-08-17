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
     * 按稠密行序号删除行（0 基，序号指 readRows 返回的可见行序列）；
     * indices 为空 no-op；任一序号越界（<0 或 >= 当前行数）抛 StorageException；
     * 重复序号抛 StorageException；库/表不存在照既有约定抛
     *
     * @param list<int> $indices
     */
    public function deleteRows(string $database, string $table, array $indices): void;

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
     * 加载库的全部视图定义（无视图时空数组）；数据库不存在抛 StorageException
     *
     * @return array<string, array<string, mixed>> 视图名 => 定义数组（ViewDefinition::toArray 结构）
     */
    public function loadViewDefinitions(string $database): array;

    /**
     * 全量替换库的视图定义；数据库不存在或视图名非法抛 StorageException
     *
     * @param array<string, array<string, mixed>> $definitions 视图名 => 定义数组
     */
    public function saveViewDefinitions(string $database, array $definitions): void;

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

    /**
     * 将指定数据库导出为可独立打开的备份目录（复制库目录全部文件，排除锁文件与临时残留）；
     * 备份目录即合法库根目录（含同名数据库子目录），可直接用于建立引擎连接；
     * 加密库的备份同为密文（打开需原 key）
     *
     * 目标目录须不存在或为空目录，否则抛 StorageException；
     * 内存引擎抛 StorageException（无落盘，不支持备份）
     */
    public function backupDatabase(string $database, string $targetDir): void;
}
