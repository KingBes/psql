<?php

declare(strict_types=1);

namespace Kingbes\Psql\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Query\AggregateExpression;
use Kingbes\Psql\Query\ConditionEvaluator;
use Kingbes\Psql\Query\JoinClause;
use Kingbes\Psql\Query\SelectQuery;
use Kingbes\Psql\Result\ResultSet;
use Kingbes\Psql\Schema\TableSchema;

/**
 * 查询执行器：限定行构建 → JOIN → WHERE → 分组聚合/HAVING → 投影 → DISTINCT → 排序 → 分页
 */
final class Executor
{
    /** 纯数字形式：可选符号 + 整数/小数 */
    private const NUMERIC_PATTERN = '/^[+-]?(\d+(\.\d*)?|\.\d+)$/';

    public function __construct(private Connection $connection)
    {
    }

    public function execute(SelectQuery $query): ResultSet
    {
        $db = $this->connection->currentDatabase();
        $engine = $this->connection->engine();

        // 源列表：基表 + 各 join 表（alias = 显式别名 ?: 表名）
        $sources = [];
        $base = $this->appendSource($sources, $db, $query->table, $query->alias);

        // 限定行构建：键改为 'alias.列名'
        $rows = [];
        foreach ($engine->readRows($db, $query->table) as $row) {
            $rows[] = $this->qualify($base['alias'], $row, $base['schema']);
        }

        // JOIN 按声明顺序逐个应用（嵌套循环）
        foreach ($query->joins as $join) {
            $source = $this->appendSource($sources, $db, $join->table, $join->alias);
            $rows = $this->applyJoin($rows, $source, $join, $sources);
        }

        // WHERE（null → 全保留）
        if ($query->where !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => ConditionEvaluator::evaluate($row, $query->where),
            ));
        }

        // 分组聚合 / 普通投影
        if ($query->groupBy !== [] || $query->aggregates !== []) {
            $entries = $this->aggregate($rows, $query);
        } else {
            $entries = $this->project($rows, $query, $sources);
        }

        // DISTINCT
        if ($query->distinct) {
            $entries = $this->distinct($entries);
        }

        // ORDER BY
        if ($query->orderBy !== []) {
            $entries = $this->sort($entries, $query->orderBy);
        }

        // LIMIT/OFFSET（builder 已校验非负）
        if ($query->offset !== null) {
            $entries = array_slice($entries, $query->offset);
        }
        if ($query->limit !== null) {
            $entries = array_slice($entries, 0, $query->limit);
        }

        return new ResultSet(array_map(
            static fn (array $entry): array => $entry['output'],
            $entries,
        ));
    }

    // ---- 源与限定行 ----

    /**
     * 追加查询源；表不存在转抛 QueryException，别名重复抛 QueryException
     *
     * @param list<array{alias: string, schema: TableSchema}> $sources
     * @return array{alias: string, schema: TableSchema}
     */
    private function appendSource(array &$sources, string $db, string $table, ?string $alias): array
    {
        $alias ??= $table;
        foreach ($sources as $source) {
            if ($source['alias'] === $alias) {
                throw new QueryException("表别名重复: {$alias}");
            }
        }
        try {
            $schema = $this->connection->engine()->loadSchema($db, $table);
        } catch (StorageException) {
            throw new QueryException("表不存在: {$db}.{$table}");
        }

        $source = ['alias' => $alias, 'schema' => $schema];
        $sources[] = $source;

        return $source;
    }

    /**
     * 行键加 'alias.' 前缀（按结构列序全展开）
     *
     * @return array<string,mixed>
     */
    private function qualify(string $alias, array $row, TableSchema $schema): array
    {
        $qualified = [];
        foreach ($schema->columns as $column) {
            $qualified[$alias . '.' . $column->name] = $row[$column->name] ?? null;
        }

        return $qualified;
    }

    // ---- JOIN ----

    /**
     * 嵌套循环连接；ON 条件用 compareValues(leftVal, op, rightVal) 求值
     *
     * @param list<array<string,mixed>> $rows 已累积限定行
     * @param array{alias: string, schema: TableSchema} $source 本次 join 引入的源
     * @param list<array{alias: string, schema: TableSchema}> $sources 全部源（本次源在末尾）
     * @return list<array<string,mixed>>
     */
    private function applyJoin(array $rows, array $source, JoinClause $join, array $sources): array
    {
        $db = $this->connection->currentDatabase();
        $joinRows = [];
        foreach ($this->connection->engine()->readRows($db, $join->table) as $row) {
            $joinRows[] = $this->qualify($source['alias'], $row, $source['schema']);
        }

        $result = [];
        if ($join->type === 'RIGHT') {
            // 右表为外层，保证右行全保留；左无匹配时左侧各列 null
            $leftKeys = [];
            $count = count($sources) - 1;
            for ($i = 0; $i < $count; $i++) {
                foreach ($sources[$i]['schema']->columns as $column) {
                    $leftKeys[] = $sources[$i]['alias'] . '.' . $column->name;
                }
            }
            $nullLeft = array_fill_keys($leftKeys, null);
            foreach ($joinRows as $joinRow) {
                $matched = false;
                foreach ($rows as $row) {
                    if ($this->joinMatch($row, $joinRow, $join)) {
                        $result[] = array_merge($row, $joinRow);
                        $matched = true;
                    }
                }
                if (!$matched) {
                    $result[] = array_merge($nullLeft, $joinRow);
                }
            }

            return $result;
        }

        // INNER / LEFT：左为外层；LEFT 无匹配时右侧各列 null
        $nullRight = [];
        foreach ($source['schema']->columns as $column) {
            $nullRight[$source['alias'] . '.' . $column->name] = null;
        }
        foreach ($rows as $row) {
            $matched = false;
            foreach ($joinRows as $joinRow) {
                if ($this->joinMatch($row, $joinRow, $join)) {
                    $result[] = array_merge($row, $joinRow);
                    $matched = true;
                }
            }
            if ($join->type === 'LEFT' && !$matched) {
                $result[] = array_merge($row, $nullRight);
            }
        }

        return $result;
    }

    /**
     * ON 条件求值：left 在已累积行中解析，right 在被 join 表行中解析
     */
    private function joinMatch(array $row, array $joinRow, JoinClause $join): bool
    {
        $left = $this->resolveColumn($row, $join->leftColumn);
        $right = $this->resolveColumn($joinRow, $join->rightColumn);

        return ConditionEvaluator::compareValues($left, $join->operator, $right);
    }

    // ---- 分组聚合 ----

    /**
     * 分组聚合 + HAVING
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{output: array<string,mixed>, source: array<string,mixed>}>
     */
    private function aggregate(array $rows, SelectQuery $query): array
    {
        // 分组（保持首见顺序）；无 groupBy 时全体为单组（含空表）
        $groups = [];
        $groupRows = [];
        if ($query->groupBy === []) {
            $groups[''] = [];
            $groupRows[''] = $rows;
        } else {
            foreach ($rows as $row) {
                $keyParts = [];
                foreach ($query->groupBy as $column) {
                    $keyParts[] = $this->resolveColumn($row, $column);
                }
                $key = json_encode($keyParts, JSON_UNESCAPED_UNICODE);
                if (!isset($groups[$key])) {
                    $groups[$key] = $keyParts;
                    $groupRows[$key] = [];
                }
                $groupRows[$key][] = $row;
            }
        }

        $entries = [];
        foreach ($groups as $key => $keyParts) {
            $members = $groupRows[$key];
            $first = $members[0] ?? null;

            $output = [];
            // groupBy 列值（键为去限定名）
            foreach ($query->groupBy as $index => $column) {
                $output[$this->shortName($column)] = $keyParts[$index];
            }
            // 非聚合普通 select 列取组内首行值
            foreach ($query->columns as $column) {
                $output[$this->shortName($column)] = $first === null
                    ? null
                    : $this->resolveColumn($first, $column);
            }
            // 聚合表达式结果（键 = alias ?? outputName()）
            foreach ($query->aggregates as $expression) {
                $output[$expression->outputName()] = $this->aggregateValue($expression, $members);
            }

            // HAVING：对输出行求值（alias 必须是输出键）
            if ($query->having !== null) {
                $having = $query->having;
                if (!array_key_exists($having->alias, $output)) {
                    throw new QueryException("HAVING 引用了未知的输出列: {$having->alias}");
                }
                if (!ConditionEvaluator::compareValues($output[$having->alias], $having->operator, $having->value)) {
                    continue;
                }
            }

            $entries[] = ['output' => $output, 'source' => $first ?? []];
        }

        return $entries;
    }

    /**
     * 单聚合表达式求值；空组 COUNT→0、SUM/AVG/MIN/MAX→null
     *
     * @param list<array<string,mixed>> $members
     */
    private function aggregateValue(AggregateExpression $expression, array $members): mixed
    {
        $function = $expression->function;
        if ($function === 'COUNT' && $expression->column === '*') {
            return count($members);
        }

        // 非 null 值收集
        $values = [];
        foreach ($members as $row) {
            $value = $this->resolveColumn($row, $expression->column);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        if ($function === 'COUNT') {
            return count($values);
        }

        if ($function === 'MIN' || $function === 'MAX') {
            if ($values === []) {
                return null;
            }
            $allNumeric = true;
            foreach ($values as $value) {
                if (!$this->isNumericValue($value)) {
                    $allNumeric = false;
                    break;
                }
            }
            $best = $values[0];
            for ($i = 1, $count = count($values); $i < $count; $i++) {
                $candidate = $values[$i];
                $cmp = $allNumeric
                    ? (float) $candidate <=> (float) $best
                    : (string) $candidate <=> (string) $best;
                if (($function === 'MIN' && $cmp < 0) || ($function === 'MAX' && $cmp > 0)) {
                    $best = $candidate;
                }
            }

            return $best;
        }

        // SUM / AVG
        $sum = 0;
        foreach ($values as $value) {
            if (!$this->isNumericValue($value)) {
                throw new QueryException(
                    "聚合 {$function}({$expression->column}) 含非数值值: " . var_export($value, true)
                );
            }
            $sum += $value;
        }
        if ($values === []) {
            return null;
        }

        return $function === 'AVG' ? $sum / count($values) : $sum;
    }

    // ---- 普通投影 ----

    /**
     * 普通投影（无聚合无分组）
     *
     * @param list<array<string,mixed>> $rows
     * @param list<array{alias: string, schema: TableSchema}> $sources
     * @return list<array{output: array<string,mixed>, source: array<string,mixed>}>
     */
    private function project(array $rows, SelectQuery $query, array $sources): array
    {
        // 空列 = 全部源列（按源引入顺序 + 列定义顺序）
        $columns = $query->columns;
        if ($columns === []) {
            foreach ($sources as $source) {
                foreach ($source['schema']->columns as $column) {
                    $columns[] = $source['alias'] . '.' . $column->name;
                }
            }
        }

        // 解析到限定键并检查输出键冲突（两个不同源列解析出相同去限定名）
        $occupied = [];
        $prepared = [];
        foreach ($columns as $column) {
            $resolved = $this->resolveKeyName($sources, $column);
            $short = $this->shortName($resolved);
            if (isset($occupied[$short]) && $occupied[$short] !== $resolved) {
                throw new QueryException("输出列名冲突: {$short}");
            }
            $occupied[$short] = $resolved;
            $prepared[] = $short;
        }

        $entries = [];
        foreach ($rows as $row) {
            $output = [];
            foreach ($prepared as $short) {
                $output[$short] = $row[$occupied[$short]];
            }
            $entries[] = ['output' => $output, 'source' => $row];
        }

        return $entries;
    }

    // ---- DISTINCT / 排序 ----

    /**
     * 按输出行 json_encode 去重（保留首见）
     *
     * @param list<array{output: array<string,mixed>, source: array<string,mixed>}> $entries
     * @return list<array{output: array<string,mixed>, source: array<string,mixed>}>
     */
    private function distinct(array $entries): array
    {
        $seen = [];
        $result = [];
        foreach ($entries as $entry) {
            $key = json_encode($entry['output'], JSON_UNESCAPED_UNICODE);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * 多列稳定排序：先看输出行键（精确或去限定名匹配），否则回退源限定行（含歧义检查）
     *
     * @param list<array{output: array<string,mixed>, source: array<string,mixed>}> $entries
     * @param list<array{column: string, direction: 'ASC'|'DESC'}> $orderBy
     * @return list<array{output: array<string,mixed>, source: array<string,mixed>}>
     */
    private function sort(array $entries, array $orderBy): array
    {
        // 预解析：每条排序规格尝试匹配输出键，未匹配则回退源行解析
        $outputKeys = $entries === [] ? [] : array_keys($entries[0]['output']);
        $specs = [];
        foreach ($orderBy as $order) {
            $outputKey = null;
            if (in_array($order['column'], $outputKeys, true)) {
                $outputKey = $order['column'];
            } else {
                $short = $this->shortName($order['column']);
                if (in_array($short, $outputKeys, true)) {
                    $outputKey = $short;
                }
            }
            $specs[] = ['column' => $order['column'], 'direction' => $order['direction'], 'outputKey' => $outputKey];
        }

        // 稳定排序：usort 前标记原始索引
        $decorated = [];
        foreach ($entries as $index => $entry) {
            $decorated[] = ['index' => $index, 'entry' => $entry];
        }

        usort($decorated, function (array $a, array $b) use ($specs): int {
            foreach ($specs as $spec) {
                $cmp = $this->compare(
                    $this->sortValue($a['entry'], $spec),
                    $this->sortValue($b['entry'], $spec),
                );
                if ($cmp !== 0) {
                    return $spec['direction'] === 'DESC' ? -$cmp : $cmp;
                }
            }

            return $a['index'] <=> $b['index'];
        });

        return array_map(static fn (array $item): array => $item['entry'], $decorated);
    }

    /**
     * 取某条目在指定排序规格下的值
     *
     * @param array{output: array<string,mixed>, source: array<string,mixed>} $entry
     * @param array{column: string, direction: 'ASC'|'DESC', outputKey: ?string} $spec
     */
    private function sortValue(array $entry, array $spec): mixed
    {
        if ($spec['outputKey'] !== null && array_key_exists($spec['outputKey'], $entry['output'])) {
            return $entry['output'][$spec['outputKey']];
        }

        return $this->resolveColumn($entry['source'], $spec['column']);
    }

    /**
     * 排序比较：null 视为最小；双侧数值性按数值，否则按字符串
     */
    private function compare(mixed $left, mixed $right): int
    {
        if ($left === null && $right === null) {
            return 0;
        }
        if ($left === null) {
            return -1;
        }
        if ($right === null) {
            return 1;
        }
        if ($this->isNumericValue($left) && $this->isNumericValue($right)) {
            return (float) $left <=> (float) $right;
        }

        return (string) $left <=> (string) $right;
    }

    // ---- 列解析 ----

    /**
     * 限定行 列解析：'a.col' 精确键；'col' 后缀匹配——0 个抛未知列、≥2 个抛歧义
     *
     * @param array<string,mixed> $row
     */
    private function resolveColumn(array $row, string $column): mixed
    {
        if (array_key_exists($column, $row)) {
            return $row[$column];
        }

        $candidates = [];
        foreach ($row as $key => $_) {
            if (is_string($key) && str_ends_with($key, '.' . $column)) {
                $candidates[] = $key;
            }
        }
        if ($candidates === []) {
            throw new QueryException("未知列: {$column}");
        }
        if (count($candidates) > 1) {
            throw new QueryException(
                '列名歧义: ' . $column . '，候选: ' . implode(', ', $candidates),
            );
        }

        return $row[$candidates[0]];
    }

    /**
     * 基于源结构解析列名到限定键（空表也可校验）；'a.col' 精确，'col' 唯一匹配
     *
     * @param list<array{alias: string, schema: TableSchema}> $sources
     */
    private function resolveKeyName(array $sources, string $column): string
    {
        $pos = strrpos($column, '.');
        if ($pos !== false) {
            $alias = substr($column, 0, $pos);
            $name = substr($column, $pos + 1);
            foreach ($sources as $source) {
                if ($source['alias'] === $alias && $source['schema']->hasColumn($name)) {
                    return $column;
                }
            }

            throw new QueryException("未知列: {$column}");
        }

        $candidates = [];
        foreach ($sources as $source) {
            if ($source['schema']->hasColumn($column)) {
                $candidates[] = $source['alias'] . '.' . $column;
            }
        }
        if ($candidates === []) {
            throw new QueryException("未知列: {$column}");
        }
        if (count($candidates) > 1) {
            throw new QueryException(
                '列名歧义: ' . $column . '，候选: ' . implode(', ', $candidates),
            );
        }

        return $candidates[0];
    }

    /**
     * 去限定名：最后一个 '.' 之后
     */
    private function shortName(string $column): string
    {
        $pos = strrpos($column, '.');
        if ($pos === false) {
            return $column;
        }

        return substr($column, $pos + 1);
    }

    /**
     * 是否数值性：int/float 或纯数字字符串
     */
    private function isNumericValue(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }

        return is_string($value) && preg_match(self::NUMERIC_PATTERN, $value) === 1;
    }
}
