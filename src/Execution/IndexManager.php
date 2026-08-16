<?php

declare(strict_types=1);

namespace Kingbes\Psql\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Schema\ColumnSchema;

/**
 * 索引管理器：基于 writeVersion 的连接级哈希索引缓存，
 * 为等值查找提供候选稠密行号预过滤（Executor 负责对候选行完整求值原 WHERE 兜底）
 */
final class IndexManager
{
    /** 纯数字形式：可选符号 + 整数/小数（与 ConditionEvaluator 数值性判定一致） */
    private const NUMERIC_PATTERN = '/^[+-]?(\d+(\.\d*)?|\.\d+)$/';

    /**
     * 哈希表缓存：库表 => 列集键 => { version, map }
     * map 为 值键 => 稠密行号列表（行号升序，对应 readRows 返回序列）
     *
     * @var array<string, array<string, array{version: int, map: array<string, list<int>>}>>
     */
    private array $cache = [];

    public function __construct(private Connection $connection)
    {
    }

    /**
     * 返回可用索引的列组合集合（显式索引 + 主键元组 + 单列 unique 标志列 + uniqueKeys 组合），
     * 每项为排序后的列名列表（去重）；复合主键整组自动可作等值索引
     *
     * @return list<list<string>>
     */
    public function availableIndexes(string $table): array
    {
        $db = $this->connection->currentDatabase();
        $schema = $this->connection->engine()->loadSchema($db, $table);

        $sets = [];
        foreach ($schema->indexes as $index) {
            $sets[] = $this->sortedColumns($index->columns);
        }
        $primaryKeyColumns = array_map(
            static fn (ColumnSchema $column): string => $column->name,
            $schema->primaryKeyColumns(),
        );
        if ($primaryKeyColumns !== []) {
            $sets[] = $this->sortedColumns($primaryKeyColumns);
        }
        foreach ($schema->columns as $column) {
            if ($column->unique) {
                $sets[] = [$column->name];
            }
        }
        foreach ($schema->uniqueKeys as $key) {
            $sets[] = $this->sortedColumns($key);
        }

        $unique = [];
        foreach ($sets as $set) {
            $unique[implode("\x1F", $set)] = $set;
        }

        return array_values($unique);
    }

    /**
     * 等值查找：columns 与某索引列集完全一致（顺序不敏感）时返回候选稠密行号，否则 null（未命中索引）；
     * $values 与 $columns 一一对应，任一为 null 直接返回 []（null 永不匹配等值索引）；
     * 返回行号升序（对应 readRows 返回序列的原始行序）
     *
     * @param list<string> $columns
     * @param list<mixed> $values
     * @return list<int>|null
     */
    public function lookup(string $table, array $columns, array $values): ?array
    {
        $wanted = $this->sortedColumns($columns);
        $wantedKey = implode("\x1F", $wanted);
        $matched = false;
        foreach ($this->availableIndexes($table) as $index) {
            if (implode("\x1F", $index) === $wantedKey) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            return null;
        }

        foreach ($values as $value) {
            if ($value === null) {
                return [];
            }
        }

        $map = $this->mapFor($table, $wantedKey, $wanted);

        // 查找键必须按索引构建侧的排序列序取值——否则条件书写顺序与索引列序不同时键错位（静默漏行）
        $valueByColumn = array_combine($columns, $values);
        $probe = [];
        foreach ($wanted as $column) {
            $probe[] = self::normalizeKey($valueByColumn[$column]);
        }
        $key = count($probe) === 1 ? $probe[0] : "\x1F" . implode("\x1F", $probe);

        return $map[$key] ?? [];
    }

    /**
     * 键规范化：与 ConditionEvaluator::compare 的等值语义对齐——
     * 数值性（int/float/bool/纯数字字符串）按 (float) 强转归一（'5'、5、'5.0'、5.0 合一，
     * 超大整数/浮点字符串亦按 float 精度归一，与 compareValues 完全一致）；
     * 其余按 (string) 强转归一
     */
    public static function normalizeKey(mixed $value): string
    {
        if (is_bool($value)) {
            return 'n:' . var_export((float) ($value ? 1 : 0), true);
        }
        if (is_int($value) || is_float($value)) {
            return 'n:' . var_export((float) $value, true);
        }
        if (is_string($value) && preg_match(self::NUMERIC_PATTERN, $value) === 1) {
            return 'n:' . var_export((float) $value, true);
        }

        return 's:' . (string) $value;
    }

    /**
     * 取（或构建）指定表的指定列集哈希表；writeVersion 不一致时重建该表全部索引缓存
     *
     * @param list<string> $sortedColumns
     * @return array<string, list<int>>
     */
    private function mapFor(string $table, string $columnsKey, array $sortedColumns): array
    {
        $cacheKey = $this->connection->currentDatabase() . "\x00" . $table;
        $version = $this->connection->writeVersion();

        if (isset($this->cache[$cacheKey])) {
            $stale = false;
            foreach ($this->cache[$cacheKey] as $entry) {
                if ($entry['version'] !== $version) {
                    $stale = true;
                    break;
                }
            }
            if ($stale) {
                // 数据/结构已变更（含事务回滚），该表缓存整体作废
                unset($this->cache[$cacheKey]);
            }
        }
        if (isset($this->cache[$cacheKey][$columnsKey])) {
            return $this->cache[$cacheKey][$columnsKey]['map'];
        }

        $db = $this->connection->currentDatabase();
        $map = [];
        foreach ($this->connection->engine()->readRows($db, $table) as $rowIndex => $row) {
            $parts = [];
            $hasNull = false;
            foreach ($sortedColumns as $column) {
                $value = $row[$column] ?? null;
                if ($value === null) {
                    $hasNull = true;
                    break;
                }
                $parts[] = self::normalizeKey($value);
            }
            if ($hasNull) {
                // null 永不匹配等值索引，跳过该行
                continue;
            }
            $key = count($parts) === 1 ? $parts[0] : "\x1F" . implode("\x1F", $parts);
            $map[$key][] = $rowIndex;
        }

        $this->cache[$cacheKey][$columnsKey] = ['version' => $version, 'map' => $map];

        return $map;
    }

    /**
     * 列名排序（键序去重用）
     *
     * @param list<string> $columns
     * @return list<string>
     */
    private function sortedColumns(array $columns): array
    {
        $sorted = $columns;
        sort($sorted);

        return $sorted;
    }
}
