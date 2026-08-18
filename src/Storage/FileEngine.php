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
     * 视图定义文件名（库目录下）
     *
     * 注意：带前导点是有意为之——表文件扩展名由子类决定（如 .json），
     * 'views' 是合法表名，字面 views.json 会与同名表文件冲突；'.views' 非法表名可被各扫描自然过滤
     */
    private const VIEWS_FILENAME = '.views.json';

    private const VIEWS_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

    /**
     * @var array<string, array<string, array{schema: TableSchema, rows: list<array<string,mixed>>, ai: int}>>
     */
    private array $cache = [];

    /**
     * 视图定义缓存：库 => 视图名 => 定义数组（写穿 + 懒加载，与表条目一致）
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $viewCache = [];

    protected string $root;

    /** 是否持有连接级生命周期锁（并发模式为 false，改由 LockingEngine 操作级加锁） */
    private bool $lockHeld = false;

    /**
     * @param bool $acquireLock false 时跳过连接级排他锁（供单 writer 多 reader 并发模式，
     *        由 LockingEngine 装饰器按操作加锁）
     */
    public function __construct(string $root, private Codec $codec = new Codec(), bool $acquireLock = true)
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
        if ($acquireLock) {
            DirectoryLock::acquire($this->root);
            $this->lockHeld = true;
        }
    }

    public function __destruct()
    {
        if ($this->lockHeld) {
            DirectoryLock::release($this->root);
        }
    }

    /**
     * 清空进程内缓存（跨进程一致性：另一进程写入后由 LockingEngine 按 .wv 版本触发）
     */
    public function invalidateCaches(): void
    {
        $this->cache = [];
        $this->viewCache = [];
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
        unset($this->cache[$database], $this->viewCache[$database]);
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

    public function deleteRows(string $database, string $table, array $indices): void
    {
        // 本引擎无墓碑：读 readRows → 校验 → 降序 unset → 全量替换写回（语义等同过滤后 writeRows）
        $rows = $this->tableEntry($database, $table)['rows'];
        if ($indices === []) {
            // 空 indices no-op（表已校验存在）
            return;
        }
        $this->assertDeleteIndices($rows, $indices, $database, $table);

        $sorted = array_unique($indices);
        rsort($sorted);
        foreach ($sorted as $index) {
            unset($rows[$index]);
        }
        $this->writeRows($database, $table, array_values($rows));
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

    public function loadViewDefinitions(string $database): array
    {
        $this->assertValidName($database, '数据库');
        $this->requireDatabase($database);
        if (!isset($this->viewCache[$database])) {
            $this->viewCache[$database] = $this->readViewsFile($database);
        }

        return $this->viewCache[$database];
    }

    public function saveViewDefinitions(string $database, array $definitions): void
    {
        $this->assertValidName($database, '数据库');
        $this->requireDatabase($database);
        $this->assertViewDefinitions($definitions, $database);
        $this->viewCache[$database] = $definitions;
        $this->writeViewsFile($database);
    }

    public function snapshot(): EngineSnapshot
    {
        // 先将磁盘上所有表与视图加载进缓存，确保快照覆盖未加载的内容
        foreach ($this->databases() as $database) {
            if (!isset($this->cache[$database])) {
                $this->cache[$database] = [];
            }
            foreach ($this->tables($database) as $table) {
                $this->loadTable($database, $table);
            }
            $this->loadViewDefinitions($database);
        }

        return new EngineSnapshot(serialize(['tables' => $this->cache, 'views' => $this->viewCache]));
    }

    public function restore(EngineSnapshot $snapshot): void
    {
        $payload = @unserialize($snapshot->payload);
        if (!is_array($payload)
            || !isset($payload['tables'], $payload['views'])
            || !is_array($payload['tables'])
            || !is_array($payload['views'])) {
            throw new StorageException('快照数据无法反序列化');
        }
        $this->validateState($payload['tables']);
        $this->validateViews($payload['views']);
        $this->cache = $payload['tables'];
        $this->viewCache = $payload['views'];
        $this->syncDisk();
    }

    public function persist(): void
    {
        $this->syncDisk();
    }

    public function backupDatabase(string $database, string $targetDir): void
    {
        $this->assertValidName($database, '数据库');
        $this->requireDatabase($database);
        $dir = $this->dbDir($database);
        if (!is_dir($dir)) {
            throw new StorageException("数据库不存在: {$database}");
        }
        $this->assertBackupTarget($targetDir);
        // 引擎为写穿模型：任何变更（表数据/schema/视图）即时落盘，无需额外 flush，
        // 直接拷贝库目录即为一致性快照（写盘中断残留的 .tmp.* 文件在拷贝时排除）

        // 拷贝到临时目录后 rename：可见窗口内目标目录要么不存在要么为完整备份
        // （rename 失败时可跨盘的场景不在本实现范围——临时目录与目标同级，必同盘）
        $target = rtrim($targetDir, '/\\');
        $tmp = $target . '.tmp-' . uniqid();
        try {
            if (!mkdir($tmp, 0777, true) && !is_dir($tmp)) {
                throw new StorageException("无法创建备份临时目录: {$tmp}");
            }
            $dest = $tmp . '/' . $database;
            if (!mkdir($dest, 0777, true) && !is_dir($dest)) {
                throw new StorageException("无法创建备份数据库目录: {$dest}");
            }
            $this->copyDir($dir, $dest);
            // 目标为已存在的空目录时先移除（Windows 下目录 rename 要求目标不存在）
            if (is_dir($target) && !@rmdir($target)) {
                throw new StorageException("无法腾空备份目标目录: {$target}");
            }
            if (!@rename($tmp, $target)) {
                throw new StorageException("无法落盘备份目录: {$target}");
            }
        } catch (\Throwable $e) {
            if (is_dir($tmp)) {
                try {
                    $this->removeDirRecursive($tmp);
                } catch (StorageException) {
                    // 清理失败可容忍（残留 .tmp-* 目录由使用者处置）
                }
            }
            throw $e;
        }
    }

    /**
     * 校验备份目标：路径非空、不是文件、目录须为空，违规抛 StorageException
     */
    private function assertBackupTarget(string $targetDir): void
    {
        $target = rtrim($targetDir, '/\\');
        if ($target === '') {
            throw new StorageException('备份目标目录路径非法');
        }
        if (is_file($target)) {
            throw new StorageException("备份目标已存在且不是目录: {$target}");
        }
        if (is_dir($target)) {
            $entries = array_values(array_diff(scandir($target) ?: ['x'], ['.', '..']));
            if ($entries !== []) {
                throw new StorageException("备份目标目录须不存在或为空: {$target}");
            }
        }
    }

    /**
     * 递归复制目录：排除锁文件与写盘中断残留的 .tmp.* 文件
     */
    private function copyDir(string $from, string $to): void
    {
        $entries = scandir($from);
        if ($entries === false) {
            throw new StorageException("无法读取目录: {$from}");
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.lock' || str_contains($entry, '.tmp.')) {
                continue;
            }
            $src = $from . '/' . $entry;
            $dst = $to . '/' . $entry;
            if (is_dir($src)) {
                if (!mkdir($dst, 0777, true) && !is_dir($dst)) {
                    throw new StorageException("无法创建备份目录: {$dst}");
                }
                $this->copyDir($src, $dst);
            } elseif (!@copy($src, $dst)) {
                throw new StorageException("无法复制文件: {$src} -> {$dst}");
            }
        }
    }

    /**
     * 校验删除序号集合：越界（<0 或 >= 当前行数）或重复抛 StorageException
     *
     * @param list<array<string,mixed>> $rows
     * @param list<int> $indices
     */
    private function assertDeleteIndices(array $rows, array $indices, string $database, string $table): void
    {
        $count = count($rows);
        $seen = [];
        foreach ($indices as $index) {
            if ($index < 0 || $index >= $count) {
                throw new StorageException("删除序号越界: {$database}.{$table}#{$index}（当前行数 {$count}）");
            }
            if (isset($seen[$index])) {
                throw new StorageException("删除序号重复: {$database}.{$table}#{$index}");
            }
            $seen[$index] = true;
        }
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

            // 删除缓存外的表文件（视图定义文件跳过，由下方视图镜像写出）
            $files = scandir($dir);
            if ($files === false) {
                throw new StorageException("无法读取数据库目录: {$dir}");
            }
            foreach ($files as $file) {
                if ($file === self::VIEWS_FILENAME) {
                    continue;
                }
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

            // 视图文件镜像缓存状态：未加载的库先从磁盘读入（防止空缓存覆盖既有视图）
            if (!isset($this->viewCache[$database])) {
                $this->viewCache[$database] = $this->readViewsFile($database);
            }
            $this->writeViewsFile($database);
        }
    }

    /**
     * 读取库的视图定义文件；文件缺失返回空数组，任何读取/解析失败抛 StorageException
     *
     * @return array<string, array<string, mixed>>
     */
    private function readViewsFile(string $database): array
    {
        $file = $this->dbDir($database) . '/' . self::VIEWS_FILENAME;
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new StorageException("无法读取视图定义文件: {$file}");
        }
        $raw = $this->codec->decode($raw);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new StorageException("视图定义文件不是合法 JSON: {$file}");
        }
        $definitions = [];
        foreach ($data as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                throw new StorageException("视图定义文件结构非法: {$file}");
            }
            $definitions[$name] = $definition;
        }

        return $definitions;
    }

    /**
     * 原子写出库的视图定义文件（先写临时文件再 rename，与表文件落盘一致）
     */
    private function writeViewsFile(string $database): void
    {
        $file = $this->dbDir($database) . '/' . self::VIEWS_FILENAME;
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new StorageException("无法创建数据库目录: {$dir}");
        }

        $payload = json_encode($this->viewCache[$database] ?? [], self::VIEWS_JSON_FLAGS);
        if ($payload === false) {
            throw new StorageException("视图定义无法编码为 JSON: {$database}");
        }

        $tmp = $file . '.tmp.' . uniqid('', true);
        if (file_put_contents($tmp, $this->codec->encode($payload)) === false) {
            throw new StorageException("无法写入临时文件: {$tmp}");
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new StorageException("无法落盘视图定义文件: {$file}");
        }
    }

    /**
     * 校验视图定义集合：视图名合法且定义为数组，违规抛 StorageException
     *
     * @param array<mixed> $definitions
     */
    private function assertViewDefinitions(array $definitions, string $database): void
    {
        foreach ($definitions as $name => $definition) {
            if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw new StorageException("非法视图名: {$database}." . (is_string($name) ? $name : get_debug_type($name)));
            }
            if (!is_array($definition)) {
                throw new StorageException("视图定义必须为数组: {$database}.{$name}");
            }
        }
    }

    /**
     * 校验还原后快照中的视图结构，非法抛 StorageException
     *
     * @param array<mixed> $views
     */
    private function validateViews(array $views): void
    {
        foreach ($views as $database => $definitions) {
            if (!is_string($database) || !is_array($definitions)) {
                throw new StorageException('快照数据结构非法');
            }
            foreach ($definitions as $name => $definition) {
                if (!is_string($name) || !is_array($definition)) {
                    throw new StorageException('快照数据结构非法');
                }
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
        if (file_put_contents($tmp, $this->codec->encode($payload)) === false) {
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
        $raw = $this->codec->decode($raw);

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
