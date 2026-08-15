<?php

declare(strict_types=1);

namespace Kingbes\Psql\Storage;

use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Schema\TableSchema;

/**
 * 纯内存存储引擎：状态仅存于进程内，persist 为空操作
 */
final class MemoryEngine implements StorageEngine
{
    /**
     * @var array<string, array<string, array{schema: TableSchema, rows: list<array<string,mixed>>, ai: int}>>
     */
    private array $state = [];

    public function databases(): array
    {
        return array_keys($this->state);
    }

    public function hasDatabase(string $database): bool
    {
        $this->assertValidName($database, '数据库');

        return isset($this->state[$database]);
    }

    public function createDatabase(string $database): void
    {
        $this->assertValidName($database, '数据库');
        if (isset($this->state[$database])) {
            throw new StorageException("数据库已存在: {$database}");
        }
        $this->state[$database] = [];
    }

    public function dropDatabase(string $database): void
    {
        $this->assertValidName($database, '数据库');
        if (!isset($this->state[$database])) {
            throw new StorageException("数据库不存在: {$database}");
        }
        unset($this->state[$database]);
    }

    public function tables(string $database): array
    {
        $this->assertValidName($database, '数据库');

        return array_keys($this->requireDatabase($database));
    }

    public function hasTable(string $database, string $table): bool
    {
        $this->assertValidName($database, '数据库');
        $this->assertValidName($table, '表');
        if (!isset($this->state[$database])) {
            return false;
        }

        return isset($this->state[$database][$table]);
    }

    public function createTable(string $database, TableSchema $schema): void
    {
        $this->assertValidName($database, '数据库');
        $this->assertValidName($schema->name, '表');
        $this->requireDatabase($database);
        if (isset($this->state[$database][$schema->name])) {
            throw new StorageException("表已存在: {$database}.{$schema->name}");
        }
        $this->state[$database][$schema->name] = ['schema' => $schema, 'rows' => [], 'ai' => 0];
    }

    public function dropTable(string $database, string $table): void
    {
        $this->assertValidName($database, '数据库');
        $this->assertValidName($table, '表');
        $this->requireDatabase($database);
        $this->requireTable($database, $table);
        unset($this->state[$database][$table]);
    }

    public function renameTable(string $database, string $from, string $to): void
    {
        $this->assertValidName($database, '数据库');
        $this->assertValidName($from, '表');
        $this->assertValidName($to, '表');
        $this->requireDatabase($database);
        $entry = $this->requireTable($database, $from);
        if (isset($this->state[$database][$to])) {
            throw new StorageException("目标表已存在: {$database}.{$to}");
        }

        unset($this->state[$database][$from]);
        $this->state[$database][$to] = [
            'schema' => $entry['schema']->withName($to),
            'rows' => $entry['rows'],
            'ai' => $entry['ai'],
        ];
    }

    public function replaceSchema(string $database, string $table, TableSchema $schema): void
    {
        $this->assertValidName($database, '数据库');
        $this->assertValidName($table, '表');
        $this->requireDatabase($database);
        $this->requireTable($database, $table);
        // 保持结构表名与表键一致
        $this->state[$database][$table]['schema'] = $schema->withName($table);
    }

    public function loadSchema(string $database, string $table): TableSchema
    {
        $this->assertValidName($database, '数据库');
        $this->assertValidName($table, '表');

        return $this->requireTable($database, $table)['schema'];
    }

    public function readRows(string $database, string $table): array
    {
        $this->assertValidName($database, '数据库');
        $this->assertValidName($table, '表');

        return $this->requireTable($database, $table)['rows'];
    }

    public function writeRows(string $database, string $table, array $rows): void
    {
        $this->assertValidName($database, '数据库');
        $this->assertValidName($table, '表');
        $this->requireDatabase($database);
        $this->requireTable($database, $table);
        $this->state[$database][$table]['rows'] = array_values($rows);
    }

    public function autoIncrement(string $database, string $table): int
    {
        $this->assertValidName($database, '数据库');
        $this->assertValidName($table, '表');

        return $this->requireTable($database, $table)['ai'];
    }

    public function setAutoIncrement(string $database, string $table, int $value): void
    {
        $this->assertValidName($database, '数据库');
        $this->assertValidName($table, '表');
        $entry = $this->requireTable($database, $table);
        if ($value <= $entry['ai']) {
            throw new StorageException("自增值只增不减: {$database}.{$table}");
        }
        $this->state[$database][$table]['ai'] = $value;
    }

    public function resetAutoIncrement(string $database, string $table): void
    {
        $this->assertValidName($database, '数据库');
        $this->assertValidName($table, '表');
        $this->requireDatabase($database);
        $this->requireTable($database, $table);
        // 无条件归零：无自增列的表同样安全（no-op 语义）
        $this->state[$database][$table]['ai'] = 0;
    }

    public function snapshot(): EngineSnapshot
    {
        return new EngineSnapshot(serialize($this->state));
    }

    public function restore(EngineSnapshot $snapshot): void
    {
        $state = @unserialize($snapshot->payload);
        if (!is_array($state)) {
            throw new StorageException('快照数据无法反序列化');
        }
        $this->validateState($state);
        $this->state = $state;
    }

    public function persist(): void
    {
        // 内存引擎无持久化需求
    }

    /**
     * 校验还原后的状态结构，非法抛 StorageException
     *
     * @param array<mixed> $state
     */
    private function validateState(array $state): void
    {
        foreach ($state as $database => $tables) {
            if (!is_string($database) || !is_array($tables)) {
                throw new StorageException('快照数据结构非法');
            }
            foreach ($tables as $table => $entry) {
                if (!is_string($table) || !is_array($entry)
                    || !isset($entry['schema'], $entry['rows'], $entry['ai'])
                    || !$entry['schema'] instanceof TableSchema
                    || !is_array($entry['rows'])
                    || !is_int($entry['ai'])) {
                    throw new StorageException('快照数据结构非法');
                }
            }
        }
    }

    /**
     * 数据库必须存在，返回其表集合
     *
     * @return array<string, array{schema: TableSchema, rows: list<array<string,mixed>>, ai: int}>
     */
    private function requireDatabase(string $database): array
    {
        if (!isset($this->state[$database])) {
            throw new StorageException("数据库不存在: {$database}");
        }

        return $this->state[$database];
    }

    /**
     * 表必须存在，返回其条目
     *
     * @return array{schema: TableSchema, rows: list<array<string,mixed>>, ai: int}
     */
    private function requireTable(string $database, string $table): array
    {
        $tables = $this->requireDatabase($database);
        if (!isset($tables[$table])) {
            throw new StorageException("表不存在: {$database}.{$table}");
        }

        return $tables[$table];
    }

    /**
     * 名称必须匹配 ^[A-Za-z_][A-Za-z0-9_]*$
     */
    private function assertValidName(string $name, string $kind): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new StorageException("非法{$kind}名: {$name}");
        }
    }
}
