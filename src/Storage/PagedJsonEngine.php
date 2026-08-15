<?php

declare(strict_types=1);

namespace Kingbes\Psql\Storage;

use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Schema\TableSchema;

/**
 * 分页增量 JSON 存储引擎：表数据按 pageSize 切页存储，单次写盘只重写受影响页
 *
 * 磁盘布局：
 * - meta：<root>/<数据库>/<表>.meta.json —— 结构 / 自增值 / 页大小 / 每页代数列表（唯一事实源）
 * - 页  ：<root>/<数据库>/<表>.<页号>.<代数>.page.json —— 页内行数组（尾部页可不满）
 *
 * 崩溃安全模型：先写新代数页文件（各自 tmp+rename），再原子提交 meta，最后清理旧代数页与孤儿文件。
 * meta 未变时新页皆为孤儿（加载时清理）；meta 已变时新页必已全部落盘。
 */
final class PagedJsonEngine implements StorageEngine
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

    private const META_EXT = '.meta.json';

    /**
     * 运行时事实源：库 => 表 => 条目
     *
     * @var array<string, array<string, array{schema: TableSchema, rows: list<array<string,mixed>>, ai: int, pages: list<int>, page_size: int, loaded: bool}>>
     */
    private array $cache = [];

    public function __construct(private string $root, private int $pageSize = 512)
    {
        if ($this->pageSize < 1) {
            throw new StorageException("页大小必须为正整数: {$this->pageSize}");
        }
        $this->root = rtrim($this->root, '/\\');
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

        // 缓存 ∪ 磁盘（扫 .meta.json 后缀）
        $tables = isset($this->cache[$database]) ? array_keys($this->cache[$database]) : [];
        $extLen = strlen(self::META_EXT);
        $dir = $this->dbDir($database);
        if (is_dir($dir)) {
            $entries = scandir($dir);
            if ($entries !== false) {
                foreach ($entries as $entry) {
                    if (str_ends_with($entry, self::META_EXT)) {
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

        return isset($this->cache[$database][$table]) || is_file($this->metaFile($database, $table));
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

        $entry = [
            'schema' => $schema,
            'rows' => [],
            'ai' => 0,
            'pages' => [],
            'page_size' => $this->pageSize,
            'loaded' => true,
        ];
        $this->cache[$database][$schema->name] = $entry;
        $this->writeMeta($database, $schema->name, $entry);
    }

    public function dropTable(string $database, string $table): void
    {
        $this->assertValidName($database, '数据库');
        $this->assertValidName($table, '表');
        if (!$this->hasDatabase($database)) {
            throw new StorageException("数据库不存在: {$database}");
        }
        // 缓存或磁盘存在才可删
        if (!isset($this->cache[$database][$table]) && !is_file($this->metaFile($database, $table))) {
            throw new StorageException("表不存在: {$database}.{$table}");
        }

        unset($this->cache[$database][$table]);
        // 删除 meta 与该表全部页文件 / 临时残留（统一按表前缀清理，无需解析 meta 内容）
        $dir = $this->dbDir($database);
        $entries = is_dir($dir) ? scandir($dir) : false;
        if ($entries !== false) {
            $prefix = $table . '.';
            foreach ($entries as $file) {
                if (!str_starts_with($file, $prefix)) {
                    continue;
                }
                $path = $dir . '/' . $file;
                if (is_file($path) && !@unlink($path)) {
                    throw new StorageException("无法删除表文件: {$path}");
                }
            }
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

        // 页文件逐个 rename（gen 保持），随后提交新 meta（schema 同步改名）
        foreach ($entry['pages'] as $p => $gen) {
            $src = $this->pageFile($database, $from, $p, $gen);
            if (is_file($src) && !@rename($src, $this->pageFile($database, $to, $p, $gen))) {
                throw new StorageException("无法重命名页文件: {$src}");
            }
        }

        $entry['schema'] = $entry['schema']->withName($to);
        unset($this->cache[$database][$from]);
        $this->cache[$database][$to] = $entry;
        $this->writeMeta($database, $to, $entry);

        $oldMeta = $this->metaFile($database, $from);
        if (is_file($oldMeta) && !@unlink($oldMeta)) {
            throw new StorageException("无法删除旧 meta 文件: {$oldMeta}");
        }
    }

    public function replaceSchema(string $database, string $table, TableSchema $schema): void
    {
        $entry = $this->tableEntry($database, $table);
        // 保持结构表名与表键一致；只重写 meta，不触碰页
        $entry['schema'] = $schema->withName($table);
        $this->cache[$database][$table] = $entry;
        $this->writeMeta($database, $table, $entry);
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
        $rows = array_values($rows);
        $oldRows = $entry['rows'];
        $oldCount = count($oldRows);
        $newCount = count($rows);

        $ps = $entry['page_size'];
        $oldPages = $entry['pages'];
        $oldPageCount = count($oldPages);
        $newPageCount = $newCount > 0 ? intdiv($newCount + $ps - 1, $ps) : 0;

        if ($oldCount === $newCount) {
            // 行数不变（原地更新）：逐页独立 diff——脏页集合与行的位置无关
            $dirtyPages = [];
            for ($p = 0; $p < $newPageCount; $p++) {
                for ($i = $p * $ps, $stop = min(($p + 1) * $ps, $newCount); $i < $stop; $i++) {
                    if ($oldRows[$i] !== $rows[$i]) {
                        $dirtyPages[] = $p;
                        break;
                    }
                }
            }
            if ($dirtyPages === []) {
                // 完全 no-op（自增值由 setAutoIncrement 独立落盘）
                return;
            }
            $stalePages = $dirtyPages;
        } else {
            // 行数变化（插入/删除导致后续行移位）：suffix diff——首个差异行到表尾保守重写
            $min = min($oldCount, $newCount);
            $firstDiff = $min;
            for ($i = 0; $i < $min; $i++) {
                if ($oldRows[$i] !== $rows[$i]) {
                    $firstDiff = $i;
                    break;
                }
            }
            $end = max($oldCount, $newCount);
            $dirtyStart = intdiv($firstDiff, $ps);
            $dirtyEnd = $end > 0 ? intdiv($end - 1, $ps) : -1;

            $dirtyPages = [];
            for ($p = $dirtyStart; $p <= $dirtyEnd && $p < $newPageCount; $p++) {
                $dirtyPages[] = $p;
            }
            // 待删旧文件：脏区间内的旧 gen + 收缩产生的越界旧页
            $stalePages = [];
            for ($p = $dirtyStart; $p <= $dirtyEnd && $p < $oldPageCount; $p++) {
                $stalePages[] = $p;
            }
        }

        // 新 pages：长度对齐新页数，未受影响页的 gen 原样保留
        $newPages = [];
        for ($p = 0; $p < $newPageCount; $p++) {
            $newPages[$p] = $oldPages[$p] ?? 0;
        }

        // a. 每个脏页 gen+1 写新文件（新页首代为 0）
        foreach ($dirtyPages as $p) {
            $gen = ($oldPages[$p] ?? -1) + 1;
            $this->writePage($database, $table, $p, $gen, array_slice($rows, $p * $ps, $ps));
            $newPages[$p] = $gen;
        }

        // b. meta 提交（提交点）：行数据以 meta.pages 指引的页文件拼接为准
        $entry['rows'] = $rows;
        $entry['pages'] = $newPages;
        $this->writeMeta($database, $table, $entry);

        // c. 孤儿清理：旧 gen 页文件与收缩产生的多余页（删除失败可容忍，加载时兜底清理）
        foreach ($stalePages as $p) {
            $file = $this->pageFile($database, $table, $p, $oldPages[$p]);
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->cache[$database][$table] = $entry;
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
        // 只重写 meta，不触碰页——增量收益之一
        $this->writeMeta($database, $table, $entry);
    }

    public function resetAutoIncrement(string $database, string $table): void
    {
        $entry = $this->tableEntry($database, $table);
        // 无条件归零：无自增列的表同样安全（no-op 语义）
        $entry['ai'] = 0;
        $this->cache[$database][$table] = $entry;
        $this->writeMeta($database, $table, $entry);
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
        // restore 后缓存即事实源，全部视为已加载
        foreach ($state as $database => $tables) {
            foreach ($tables as $table => $_entry) {
                $state[$database][$table]['loaded'] = true;
            }
        }
        $this->cache = $state;
        $this->syncDisk();
    }

    public function persist(): void
    {
        $this->syncDisk();
    }

    /**
     * 将磁盘同步为缓存状态：建目录、删缓存外库目录/表文件与临时残留、逐表全量重写
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

        foreach ($this->cache as $database => $tables) {
            $dir = $this->dbDir($database);
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new StorageException("无法创建数据库目录: {$dir}");
            }

            // 清理临时残留与缓存外表文件
            $files = scandir($dir);
            if ($files === false) {
                throw new StorageException("无法读取数据库目录: {$dir}");
            }
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $path = $dir . '/' . $file;
                if (!is_file($path)) {
                    continue;
                }
                if (str_contains($file, '.tmp.')) {
                    @unlink($path);
                    continue;
                }
                $table = $this->tableOfFileName($file);
                if ($table !== null && !isset($tables[$table])) {
                    if (!@unlink($path)) {
                        throw new StorageException("无法删除表文件: {$path}");
                    }
                }
            }

            foreach ($tables as $table => $_entry) {
                $this->rewriteTable($database, $table);
            }
        }
    }

    /**
     * 全量重写一张表：所有页按缓存 page_size 重新切片、gen 尽量保持，meta 重写
     */
    private function rewriteTable(string $database, string $table): void
    {
        $entry = $this->cache[$database][$table];
        $ps = $entry['page_size'];
        $count = count($entry['rows']);
        $newPageCount = intdiv($count + $ps - 1, $ps);

        $newPages = [];
        for ($p = 0; $p < $newPageCount; $p++) {
            $newPages[$p] = $entry['pages'][$p] ?? 0;
            $this->writePage($database, $table, $p, $newPages[$p], array_slice($entry['rows'], $p * $ps, $ps));
        }

        $entry['pages'] = $newPages;
        $this->writeMeta($database, $table, $entry);
        $this->cache[$database][$table] = $entry;
        // 清理截断旧页与不匹配 gen 的孤儿文件
        $this->cleanupOrphans($database, $table, $newPages);
    }

    /**
     * 原子写 meta：结构 / 自增值 / 页大小 / 每页代数
     *
     * @param array{schema: TableSchema, rows: list<array<string,mixed>>, ai: int, pages: list<int>, page_size: int, loaded: bool} $entry
     */
    private function writeMeta(string $database, string $table, array $entry): void
    {
        $payload = json_encode([
            'schema' => $entry['schema']->toArray(),
            'auto_increment' => $entry['ai'],
            'page_size' => $entry['page_size'],
            'pages' => array_values($entry['pages']),
        ], self::JSON_FLAGS);
        if ($payload === false) {
            throw new StorageException("表 meta 无法编码为 JSON: {$database}.{$table}");
        }
        $this->atomicWrite($this->metaFile($database, $table), $payload);
    }

    /**
     * 写单个页文件（尾部页行数可不满）
     *
     * @param list<array<string,mixed>> $rows
     */
    private function writePage(string $database, string $table, int $pageNo, int $gen, array $rows): void
    {
        $payload = json_encode(['rows' => $rows], self::JSON_FLAGS);
        if ($payload === false) {
            throw new StorageException("页数据无法编码为 JSON: {$database}.{$table}#{$pageNo}");
        }
        $this->atomicWrite($this->pageFile($database, $table, $pageNo, $gen), $payload);
    }

    /**
     * 原子写盘：先写临时文件再 rename
     */
    private function atomicWrite(string $file, string $payload): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new StorageException("无法创建数据库目录: {$dir}");
        }
        $tmp = $file . '.tmp.' . uniqid('', true);
        if (file_put_contents($tmp, $payload) === false) {
            throw new StorageException("无法写入临时文件: {$tmp}");
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new StorageException("无法落盘文件: {$file}");
        }
    }

    /**
     * 读取并校验 meta；任何失败抛 StorageException（消息含文件路径）
     *
     * @return array{schema: TableSchema, ai: int, page_size: int, pages: list<int>}
     */
    private function readMeta(string $database, string $table): array
    {
        $file = $this->metaFile($database, $table);
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new StorageException("无法读取表 meta 文件: {$file}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new StorageException("表 meta 文件不是合法 JSON: {$file}");
        }
        foreach (['schema', 'auto_increment', 'page_size', 'pages'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new StorageException("表 meta 文件缺少 {$key} 键: {$file}");
            }
        }
        if (!is_array($data['schema']) || !is_int($data['auto_increment'])
            || !is_int($data['page_size']) || $data['page_size'] < 1
            || !is_array($data['pages'])) {
            throw new StorageException("表 meta 文件结构类型非法: {$file}");
        }
        $pages = [];
        foreach ($data['pages'] as $gen) {
            if (!is_int($gen) || $gen < 0) {
                throw new StorageException("表 meta 文件 pages 结构非法: {$file}");
            }
            $pages[] = $gen;
        }
        try {
            $schema = TableSchema::fromArray($data['schema']);
        } catch (StorageException $e) {
            throw new StorageException("表 meta 文件结构非法: {$file} ({$e->getMessage()})");
        }

        return ['schema' => $schema, 'ai' => $data['auto_increment'], 'page_size' => $data['page_size'], 'pages' => $pages];
    }

    /**
     * 读取单个页文件；缺失或结构非法抛 StorageException（消息含文件路径）
     *
     * @return list<array<string,mixed>>
     */
    private function readPage(string $database, string $table, int $pageNo, int $gen): array
    {
        $file = $this->pageFile($database, $table, $pageNo, $gen);
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new StorageException("页文件缺失: {$file}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !array_key_exists('rows', $data) || !is_array($data['rows'])) {
            throw new StorageException("页文件结构非法: {$file}");
        }
        $rows = [];
        foreach ($data['rows'] as $row) {
            if (!is_array($row)) {
                throw new StorageException("页文件 rows 结构非法: {$file}");
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * 懒加载表条目：缓存优先，其次磁盘（readMeta → 按页号+gen 逐页读 → 拼接 rows）；不存在抛 StorageException
     *
     * @return array{schema: TableSchema, rows: list<array<string,mixed>>, ai: int, pages: list<int>, page_size: int, loaded: bool}
     */
    private function loadTable(string $database, string $table): array
    {
        if (isset($this->cache[$database][$table])) {
            return $this->cache[$database][$table];
        }
        if (!is_file($this->metaFile($database, $table))) {
            throw new StorageException("表不存在: {$database}.{$table}");
        }

        $meta = $this->readMeta($database, $table);
        $rows = [];
        foreach ($meta['pages'] as $p => $gen) {
            foreach ($this->readPage($database, $table, $p, $gen) as $row) {
                $rows[] = $row;
            }
        }

        $entry = [
            'schema' => $meta['schema'],
            'rows' => $rows,
            'ai' => $meta['ai'],
            'pages' => $meta['pages'],
            'page_size' => $meta['page_size'],
            'loaded' => true,
        ];
        $this->cache[$database][$table] = $entry;
        // 加载完成后清理孤儿：页号越界 / gen 不符的页文件与临时残留
        $this->cleanupOrphans($database, $table, $meta['pages']);

        return $entry;
    }

    /**
     * 孤儿清理：删除目录中同表前缀但不匹配 meta 记录的 .page.json 与 .tmp.* 残留（静默）
     *
     * @param list<int> $pages meta 记录的每页 gen
     */
    private function cleanupOrphans(string $database, string $table, array $pages): void
    {
        $dir = $this->dbDir($database);
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        $pattern = '/^' . preg_quote($table, '/') . '\.(\d+)\.(\d+)\.page\.json$/';
        foreach ($entries as $file) {
            if (!str_starts_with($file, $table . '.')) {
                continue;
            }
            if (str_contains($file, '.tmp.')) {
                @unlink($dir . '/' . $file);
                continue;
            }
            if (preg_match($pattern, $file, $m) === 1) {
                $pageNo = (int) $m[1];
                $gen = (int) $m[2];
                if ($pageNo >= count($pages) || $pages[$pageNo] !== $gen) {
                    @unlink($dir . '/' . $file);
                }
            }
        }
    }

    /**
     * 从文件名提取所属表名；非引擎管理的文件返回 null
     */
    private function tableOfFileName(string $file): ?string
    {
        if (str_ends_with($file, self::META_EXT)) {
            $name = substr($file, 0, -strlen(self::META_EXT));

            return $this->isValidName($name) ? $name : null;
        }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\.\d+\.\d+\.page\.json$/', $file, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * 校验还原后的状态结构（含 loaded/pages 字段检查），非法抛 StorageException
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
                    || !isset($entry['schema'], $entry['rows'], $entry['ai'], $entry['pages'], $entry['page_size'], $entry['loaded'])
                    || !$entry['schema'] instanceof TableSchema
                    || !is_array($entry['rows'])
                    || !is_int($entry['ai'])
                    || !is_array($entry['pages'])
                    || !is_int($entry['page_size']) || $entry['page_size'] < 1
                    || !is_bool($entry['loaded'])) {
                    throw new StorageException('快照数据结构非法');
                }
                foreach ($entry['pages'] as $gen) {
                    if (!is_int($gen) || $gen < 0) {
                        throw new StorageException('快照数据结构非法');
                    }
                }
            }
        }
    }

    /**
     * 校验名称并加载表条目
     *
     * @return array{schema: TableSchema, rows: list<array<string,mixed>>, ai: int, pages: list<int>, page_size: int, loaded: bool}
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

    private function metaFile(string $database, string $table): string
    {
        return $this->dbDir($database) . '/' . $table . self::META_EXT;
    }

    private function pageFile(string $database, string $table, int $pageNo, int $gen): string
    {
        return $this->dbDir($database) . '/' . $table . '.' . $pageNo . '.' . $gen . '.page.json';
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
