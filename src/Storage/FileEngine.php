<?php

declare(strict_types=1);

namespace Kingbes\Psql\Storage;

use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Schema\TableSchema;

/**
 * 文件存储引擎基类：<root>/<数据库>/<表><扩展名>，写穿缓存 + 原子落盘 + 懒加载
 *
 * 载荷编解码格式由子类实现（JSON / PHP serialize 等）
 */
abstract class FileEngine implements StorageEngine
{
    /**
     * @var array<string, array<string, array{schema: TableSchema, rows: list<array<string,mixed>>, ai: int}>>
     */
    private array $cache = [];

    protected string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
        if ($this->root === '') {
            throw new StorageException('根目录路径非法');
        }
        if (!is_dir($this->root) && !mkdir($this->root, 0777, true) && !is_dir($this->root)) {
            throw new StorageException("无法创建根目录: {$this->root}");
        }
        if (!is_writable($this->root)) {
            throw new StorageException("根目录不可写: {$this->root}");
        }
    }

    /**
     * 表文件扩展名（含点）
     */
    abstract protected function tableExtension(): string;

    /**
     * 将表条目编码为文件载荷；失败抛 StorageException
     *
     * @param list<array<string,mixed>> $rows
     */
    abstract protected function encode(TableSchema $schema, int $autoIncrement, array $rows): string;

    /**
     * 解码文件载荷为表条目；任何失败抛 StorageException（消息含文件路径）
     *
     * @return array{schema: TableSchema, rows: list<array<string,mixed>>, ai: int}
     */
    abstract protected function decode(string $raw, string $file): array;

    public function databases(): array
    {
        $databases = array_keys($this->cache);
        $entries = scandir($this->root);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..'
                    && is_dir($this->root . '/' . $entry)
                    && $this->isValidName($entry)) {
                    $databases[] = $entry;
                }
            }
        }
        $databases = array_values(array_unique($databases));
        sort($databases);

        return $databases;
    }

    public function hasDatabase(string $database): bool
    {
        $this->assertValidName($database, '数据库');

        return isset($this->cache[$database]) || is_dir($this->dbDir($database));
    }

    public function createDatabase(string $database): void
    {
        $this->assertValidName($database, '数据库');
        if ($this->hasDatabase($database)) {
            throw new StorageException("数据库已存在: {$database}");
        }
        $dir = $this->dbDir($database);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new StorageException("无法创建数据库目录: {$dir}");
        }
        $this->cache[$database] = [];
    }

    public function dropDatabase(string $database): void
    {
        $this->assertValidName($database, '数据库');
        $dir = $this->dbDir($database);
        if (!isset($this->cache[$database]) && !is_dir($dir)) {
            throw new StorageException("数据库不存在: {$database}");
        }
        if (is_dir($dir)) {
            $this->removeDirRecursive($dir);
        }
        unset($this->cache[$database]);
    }

    public function tables(string $database): array
    {
        $this->assertValidName($database, '数据库');
        if (!$this->hasDatabase($database)) {
            throw new StorageException("数据库不存在: {$database}");
        }

        // 缓存 ∪ 磁盘
        $tables = isset($this->cache[$database]) ? array_keys($this->cache[$database]) : [];
        $ext = $this->tableExtension();
        $extLen = strlen($ext);
        $dir = $this->dbDir($database);
        if (is_dir($dir)) {
            $entries = scandir($dir);
            if ($entries !== false) {
                foreach ($entries as $entry) {
                    if (str_ends_with($entry, $ext)) {
                        $name = substr($entry, 0, -$extLen);
                        if ($this->isValidName($name)) {
                            $tables[] = $name;
                        }
                    }
                }
            }
        }
        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }

    public function hasTable(string $database, string $table): bool
    {
        $this->assertValidName($database, '数据库');
        $this->assertValidName($table, '表');
        if (!$this->hasDatabase($database)) {
            return false;
        }

        return isset($this->cache[$database][$table]) || is_file($this->tableFile($database, $table));
    }

    public function createTable(string $database, TableSchema $schema): void
    {
        $this->assertValidName($database, '数据库');
        $this->assertValidName($schema->name, '表');
        if (!$this->hasDatabase($database)) {
            throw new StorageException("数据库不存在: {$database}");
        }
        if ($this->hasTable($database, $schema->name)) {
            throw new StorageException("表已存在: {$database}.{$schema->name}");
        }

        $this->cache[$database][$schema->name] = ['schema' => $schema, 'rows' => [], 'ai' => 0];
        $this->writeTableFile($database, $schema->name);
    }

    public function dropTable(string $database, string $table): void
    {
        $this->assertValidName($database, '数据库');
        $this->assertValidName($table, '表');
        if (!$this->hasDatabase($database)) {
            throw new StorageException("数据库不存在: {$database}");
        }
        $file = $this->tableFile($database, $table);
        if (!isset($this->cache[$database][$table]) && !is_file($file)) {
            throw new StorageException("表不存在: {$database}.{$table}");
        }

        unset($this->cache[$database][$table]);
        if (is_file($file) && !@unlink($file)) {
            throw new StorageException("无法删除表文件: {$file}");
        }
    }

    public function renameTable(string $database, string $from, string $to): void
    {
        $this->assertValidName($database, '数据库');
        $this->assertValidName($from, '表');
        $this->assertValidName($to, '表');
        $this->requireDatabase($database);
        $entry = $this->loadTable($database, $from);
        if ($this->hasTable($database, $to)) {
            throw new StorageException("目标表已存在: {$database}.{$to}");
        }

        $file = $this->tableFile($database, $from);
        $target = $this->tableFile($database, $to);
        if (is_file($file)) {
            if (!@rename($file, $target)) {
                throw new StorageException("无法重命名表文件: {$file} -> {$target}");
            }
        }

        unset($this->cache[$database][$from]);
        $this->cache[$database][$to] = [
            'schema' => $entry['schema']->withName($to),
            'rows' => $entry['rows'],
            'ai' => $entry['ai'],
        ];
        if (!is_file($target)) {
            // 源文件缺失时直接全量写盘兜底
            $this->writeTableFile($database, $to);
        }
    }

    public function replaceSchema(string $database, string $table, TableSchema $schema): void
    {
        $entry = $this->tableEntry($database, $table);
        // 保持结构表名与表键一致
        $entry['schema'] = $schema->withName($table);
        $this->cache[$database][$table] = $entry;
        $this->writeTableFile($database, $table);
    }

    public function loadSchema(string $database, string $table): TableSchema
    {
        return $this->tableEntry($database, $table)['schema'];
    }

    public function readRows(string $database, string $table): array
    {
        return $this->tableEntry($database, $table)['rows'];
    }

    public function writeRows(string $database, string $table, array $rows): void
    {
        $entry = $this->tableEntry($database, $table);
        $entry['rows'] = array_values($rows);
        $this->cache[$database][$table] = $entry;
        $this->writeTableFile($database, $table);
    }

    public function autoIncrement(string $database, string $table): int
    {
        return $this->tableEntry($database, $table)['ai'];
    }

    public function setAutoIncrement(string $database, string $table, int $value): void
    {
        $entry = $this->tableEntry($database, $table);
        if ($value <= $entry['ai']) {
            throw new StorageException("自增值只增不减: {$database}.{$table}");
        }
        $entry['ai'] = $value;
        $this->cache[$database][$table] = $entry;
        $this->writeTableFile($database, $table);
    }

    public function resetAutoIncrement(string $database, string $table): void
    {
        $entry = $this->tableEntry($database, $table);
        // 无条件归零：无自增列的表同样安全（no-op 语义）
        $entry['ai'] = 0;
        $this->cache[$database][$table] = $entry;
        $this->writeTableFile($database, $table);
    }

    public function snapshot(): EngineSnapshot
    {
        // 先将磁盘上所有表加载进缓存，确保快照覆盖未加载的表
        foreach ($this->databases() as $database) {
            if (!isset($this->cache[$database])) {
                $this->cache[$database] = [];
            }
            foreach ($this->tables($database) as $table) {
                $this->loadTable($database, $table);
            }
        }

        return new EngineSnapshot(serialize($this->cache));
    }

    public function restore(EngineSnapshot $snapshot): void
    {
        $state = @unserialize($snapshot->payload);
        if (!is_array($state)) {
            throw new StorageException('快照数据无法反序列化');
        }
        $this->validateState($state);
        $this->cache = $state;
        $this->syncDisk();
    }

    public function persist(): void
    {
        $this->syncDisk();
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
     * 将磁盘同步为缓存状态：建目录、删多余库目录/表文件、全量写盘
     */
    private function syncDisk(): void
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0777, true) && !is_dir($this->root)) {
            throw new StorageException("无法创建根目录: {$this->root}");
        }

        // 删除缓存中不存在的库目录
        $entries = scandir($this->root);
        if ($entries === false) {
            throw new StorageException("无法读取根目录: {$this->root}");
        }
        foreach ($entries as $entry) {
            $path = $this->root . '/' . $entry;
            if ($entry !== '.' && $entry !== '..'
                && is_dir($path)
                && $this->isValidName($entry)
                && !isset($this->cache[$entry])) {
                $this->removeDirRecursive($path);
            }
        }

        $ext = $this->tableExtension();
        $extLen = strlen($ext);
        foreach ($this->cache as $database => $tables) {
            $dir = $this->dbDir($database);
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new StorageException("无法创建数据库目录: {$dir}");
            }

            // 删除缓存外的表文件
            $files = scandir($dir);
            if ($files === false) {
                throw new StorageException("无法读取数据库目录: {$dir}");
            }
            foreach ($files as $file) {
                if (str_ends_with($file, $ext) && !isset($tables[substr($file, 0, -$extLen)])) {
                    $path = $dir . '/' . $file;
                    if (!@unlink($path)) {
                        throw new StorageException("无法删除表文件: {$path}");
                    }
                }
            }

            foreach ($tables as $table => $_entry) {
                $this->writeTableFile($database, $table);
            }
        }
    }

    /**
     * 原子写盘：先写临时文件再 rename
     */
    private function writeTableFile(string $database, string $table): void
    {
        $entry = $this->cache[$database][$table];
        $file = $this->tableFile($database, $table);
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new StorageException("无法创建数据库目录: {$dir}");
        }

        $payload = $this->encode($entry['schema'], $entry['ai'], $entry['rows']);

        $tmp = $file . '.tmp.' . uniqid('', true);
        if (file_put_contents($tmp, $payload) === false) {
            throw new StorageException("无法写入临时文件: {$tmp}");
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new StorageException("无法落盘表文件: {$file}");
        }
    }

    /**
     * 读取并解析表文件，任何失败抛 StorageException（消息含文件路径）
     *
     * @return array{schema: TableSchema, rows: list<array<string,mixed>>, ai: int}
     */
    private function readTableFile(string $file): array
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new StorageException("无法读取表文件: {$file}");
        }

        return $this->decode($raw, $file);
    }

    /**
     * 懒加载表条目：缓存优先，其次磁盘文件；不存在抛 StorageException
     *
     * @return array{schema: TableSchema, rows: list<array<string,mixed>>, ai: int}
     */
    private function loadTable(string $database, string $table): array
    {
        if (isset($this->cache[$database][$table])) {
            return $this->cache[$database][$table];
        }
        $file = $this->tableFile($database, $table);
        if (!is_file($file)) {
            throw new StorageException("表不存在: {$database}.{$table}");
        }

        $entry = $this->readTableFile($file);
        $this->cache[$database][$table] = $entry;

        return $entry;
    }

    /**
     * 校验名称并加载表条目
     *
     * @return array{schema: TableSchema, rows: list<array<string,mixed>>, ai: int}
     */
    private function tableEntry(string $database, string $table): array
    {
        $this->assertValidName($database, '数据库');
        $this->assertValidName($table, '表');
        $this->requireDatabase($database);

        return $this->loadTable($database, $table);
    }

    /**
     * 数据库必须存在，并初始化其缓存槽位
     */
    private function requireDatabase(string $database): void
    {
        if (!$this->hasDatabase($database)) {
            throw new StorageException("数据库不存在: {$database}");
        }
        if (!isset($this->cache[$database])) {
            $this->cache[$database] = [];
        }
    }

    /**
     * 递归删除目录，失败抛 StorageException
     */
    private function removeDirRecursive(string $dir): void
    {
        $entries = scandir($dir);
        if ($entries === false) {
            throw new StorageException("无法读取目录: {$dir}");
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } elseif (!@unlink($path)) {
                throw new StorageException("无法删除文件: {$path}");
            }
        }
        if (!@rmdir($dir)) {
            throw new StorageException("无法删除目录: {$dir}");
        }
    }

    private function dbDir(string $database): string
    {
        return $this->root . '/' . $database;
    }

    private function tableFile(string $database, string $table): string
    {
        return $this->dbDir($database) . '/' . $table . $this->tableExtension();
    }

    private function isValidName(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
    }

    /**
     * 名称必须匹配 ^[A-Za-z_][A-Za-z0-9_]*$（防路径穿越）
     */
    private function assertValidName(string $name, string $kind): void
    {
        if (!$this->isValidName($name)) {
            throw new StorageException("非法{$kind}名: {$name}");
        }
    }
}
