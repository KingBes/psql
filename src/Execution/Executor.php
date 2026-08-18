<?php

declare(strict_types=1);

namespace Kingbes\Psql\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Query\AggregateExpression;
use Kingbes\Psql\Query\Condition\Comparison;
use Kingbes\Psql\Query\Condition\Condition;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Query\Condition\ExistsCheck;
use Kingbes\Psql\Query\Condition\ScalarSubquery;
use Kingbes\Psql\Query\Condition\SubqueryIn;
use Kingbes\Psql\Query\ConditionEvaluator;
use Kingbes\Psql\Query\JoinClause;
use Kingbes\Psql\Query\SelectQuery;
use Kingbes\Psql\Query\UnionClause;
use Kingbes\Psql\Query\WindowExpression;
use Kingbes\Psql\Result\ResultSet;
use Kingbes\Psql\Schema\TableSchema;
use Kingbes\Psql\Type\ValueCaster;

/**
 * 查询执行器：限定行构建 → JOIN → WHERE → 分组聚合/HAVING → 投影 → DISTINCT → 排序 → 分页
 */
final class Executor
{
    /** 纯数字形式：可选符号 + 整数/小数 */
    private const NUMERIC_PATTERN = '/^[+-]?(\d+(\.\d*)?|\.\d+)$/';

    /** 外部归并：单块最大行数（超过此值写临时文件分块排序） */
    private const EXTERNAL_SORT_CHUNK_ROWS = 5000;

    public function __construct(private Connection $connection)
    {
    }

    public function execute(SelectQuery $query): ResultSet
    {
        // 并发模式：整语句持共享锁（单 writer 多 reader 语句级一致性）
        return $this->connection->readLocked(fn (): ResultSet => $this->executeLocked($query));
    }

    private function executeLocked(SelectQuery $query): ResultSet
    {
        $db = $this->connection->currentDatabase();
        $engine = $this->connection->engine();

        // 源列表：基表/派生表 + 各 join 表（alias = 显式别名 ?: 表名）
        $sources = [];
        $base = $this->appendSource($sources, $db, $query);

        // 限定行构建：键改为 'alias.列名'（物理表等值索引可命中时仅加载候选行，行号升序保持原序）
        $rows = [];
        if ($base['table'] !== null) {
            $allRows = $engine->readRows($db, $base['table']);
            $candidates = $this->candidateRowIndexes($query, $base['schema']);
            $indexes = $candidates ?? array_keys($allRows);
            foreach ($indexes as $index) {
                $rows[] = $this->qualify($base['alias'], $allRows[$index], $base['columns']);
            }
        } else {
            // 派生表：子查询已完整执行，输出行按子查询输出列名限定
            foreach ($base['rows'] as $subRow) {
                $rows[] = $this->qualify($base['alias'], $subRow, $base['columns']);
            }
        }

        // JOIN 按声明顺序逐个应用（嵌套循环）
        foreach ($query->joins as $join) {
            $source = $this->appendSource($sources, $db, $this->joinSourceQuery($query, $join));
            $rows = $this->applyJoin($rows, $source, $join, $sources);
        }

        // 全部源的 CI 列映射（WHERE 过滤与 ORDER BY 排序共用）
        $collations = $this->collationsOf($sources);

        // WHERE（null → 全保留）；子查询条件先经 SubqueryResolver 解析为可求值条件；
        // 含相关子查询时对外层行逐行绑定求值（resolveCorrelated）
        if ($query->where !== null) {
            $rows = $this->filterWhere($rows, $query->where, $collations);
        }

        // 分组聚合 / 普通投影
        if ($query->groupBy !== [] || $query->aggregates !== []) {
            $entries = $this->aggregate($rows, $query);
        } else {
            $entries = $this->project($rows, $query, $sources);
        }

        // 窗口函数（投影/聚合后整组计算，结果写入输出行别名键）
        if ($query->windows !== []) {
            $entries = $this->applyWindows($entries, $query->windows, $collations);
        }

        // 基础方收尾（DISTINCT → ORDER → LIMIT/OFFSET，语义 = 各方先各自出结果）
        $entries = $this->finalizeEntries($entries, $query, $collations);

        // UNION 合并后外层再次收尾（UNION 已去重则 distinct 幂等；UNION ALL + distinct = 全集去重）
        if ($query->unions !== []) {
            $entries = $this->mergeUnions($entries, $query->unions);
            $entries = $this->finalizeEntries($entries, $query, $collations);
        }

        return new ResultSet(array_map(
            static fn (array $entry): array => $entry['output'],
            $entries,
        ));
    }

    /**
     * 执行到"限定行 + WHERE 过滤"为止的匹配行（多表 UPDATE/DELETE 写入路径用）：
     * 返回按源引入顺序合并的限定行（'alias.列名' 键），不含投影/聚合/排序/分页。
     * 判定逻辑与 execute 的基表构建 + JOIN + WHERE 段镜像同步（含索引预过滤与子查询条件解析）
     *
     * @return list<array<string,mixed>>
     */
    public function matchedRows(SelectQuery $query): array
    {
        // 并发模式：整语句持共享锁（多表写入定位与 SELECT 同级一致性）
        return $this->connection->readLocked(fn (): array => $this->matchedRowsLocked($query));
    }

    private function matchedRowsLocked(SelectQuery $query): array
    {
        $db = $this->connection->currentDatabase();
        $engine = $this->connection->engine();

        $sources = [];
        $base = $this->appendSource($sources, $db, $query);

        $rows = [];
        if ($base['table'] !== null) {
            $allRows = $engine->readRows($db, $base['table']);
            $candidates = $this->candidateRowIndexes($query, $base['schema']);
            $indexes = $candidates ?? array_keys($allRows);
            foreach ($indexes as $index) {
                $rows[] = $this->qualify($base['alias'], $allRows[$index], $base['columns']);
            }
        } else {
            foreach ($base['rows'] as $subRow) {
                $rows[] = $this->qualify($base['alias'], $subRow, $base['columns']);
            }
        }

        foreach ($query->joins as $join) {
            $source = $this->appendSource($sources, $db, $this->joinSourceQuery($query, $join));
            $rows = $this->applyJoin($rows, $source, $join, $sources);
        }

        $collations = $this->collationsOf($sources);
        if ($query->where !== null) {
            $rows = $this->filterWhere($rows, $query->where, $collations);
        }

        return $rows;
    }

    /**
     * WHERE 过滤：非相关子查询整体解析一次批量求值；含相关子查询时逐行
     * resolveCorrelated（把外层列引用替换为行值后执行）再求值
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string, true> $collations
     * @return list<array<string,mixed>>
     */
    private function filterWhere(array $rows, Condition $where, array $collations): array
    {
        if (!$this->whereNeedsRowBinding($where)) {
            $resolved = (new SubqueryResolver($this->connection))->resolve($where);

            return array_values(array_filter(
                $rows,
                static fn (array $row): bool => ConditionEvaluator::evaluate($row, $resolved, $collations),
            ));
        }

        $resolver = new SubqueryResolver($this->connection);

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => ConditionEvaluator::evaluate(
                $row,
                $resolver->resolveCorrelated($where, $row),
                $collations,
            ),
        ));
    }

    /**
     * 条件树是否含相关子查询（需逐行绑定）
     */
    private function whereNeedsRowBinding(Condition $where): bool
    {
        if ($where instanceof ConditionGroup) {
            foreach ($where->conditions as $child) {
                if ($this->whereNeedsRowBinding($child)) {
                    return true;
                }
            }

            return false;
        }
        if ($where instanceof SubqueryIn || $where instanceof ExistsCheck || $where instanceof ScalarSubquery) {
            return SubqueryResolver::isCorrelated($where->query);
        }

        return false;
    }

    // ---- 源与限定行 ----

    /**
     * 连接源查询：物理表直接构造表查询；CTE 源从查询的 ctes 注册表解析定义为派生表
     * （table 作别名占位、fromSub 为 CTE 定义；未知 CTE 抛 QueryException）
     */
    private function joinSourceQuery(SelectQuery $query, JoinClause $join): SelectQuery
    {
        if ($join->cte === null) {
            return new SelectQuery($join->table, $join->alias);
        }
        if (!isset($query->ctes[$join->cte])) {
            throw new QueryException("未知 CTE: {$join->cte}");
        }
        $alias = $join->alias ?? $join->table;

        return new SelectQuery($alias, $alias, fromSub: $query->ctes[$join->cte]);
    }

    /**
     * 连接源行：派生表/CTE 源取已执行结果（rows），物理表源按表名实时读取
     *
     * @param array{alias: string, table: ?string, columns: list<string>, ci: array<string, true>, schema: ?TableSchema, rows?: list<array<string,mixed>>} $source
     * @return list<array<string,mixed>>
     */
    private function joinSourceRows(array $source): array
    {
        if (isset($source['rows'])) {
            return $source['rows'];
        }

        return $this->connection->engine()->readRows($this->connection->currentDatabase(), $source['table']);
    }

    /**
     * 追加查询源；物理表加载结构（不存在抛 QueryException），派生表执行子查询取输出列；
     * 别名重复抛 QueryException
     *
     * @param list<array{alias: string, table: ?string, columns: list<string>, ci: array<string, true>, schema: ?TableSchema, rows?: list<array<string,mixed>>}> $sources
     * @return array{alias: string, table: ?string, columns: list<string>, ci: array<string, true>, schema: ?TableSchema, rows?: list<array<string,mixed>>}
     */
    private function appendSource(array &$sources, string $db, SelectQuery $query): array
    {
        if ($query->fromSub !== null) {
            // 派生表：子查询完整执行，输出列即源列（无 CI、无结构 schema、无索引加速）
            $alias = $query->table;
            $this->assertAliasUnique($sources, $alias);
            $rows = (new self($this->connection))->execute($query->fromSub)->rows();
            $columns = $rows === [] ? [] : array_keys($rows[0]);
            $source = ['alias' => $alias, 'table' => null, 'columns' => $columns, 'ci' => [], 'schema' => null, 'rows' => $rows];
            $sources[] = $source;

            return $source;
        }

        $alias = $query->alias ?? $query->table;
        $this->assertAliasUnique($sources, $alias);
        try {
            $schema = $this->connection->engine()->loadSchema($db, $query->table);
        } catch (StorageException) {
            throw new QueryException("表不存在: {$db}.{$query->table}");
        }

        $columns = [];
        $ci = [];
        foreach ($schema->columns as $column) {
            $columns[] = $column->name;
            if ($column->ci) {
                $ci[$column->name] = true;
            }
        }

        $source = ['alias' => $alias, 'table' => $query->table, 'columns' => $columns, 'ci' => $ci, 'schema' => $schema];
        $sources[] = $source;

        return $source;
    }

    /**
     * 别名重复校验（派生表/物理表统一）
     *
     * @param list<array{alias: string}> $sources
     */
    private function assertAliasUnique(array $sources, string $alias): void
    {
        foreach ($sources as $source) {
            if ($source['alias'] === $alias) {
                throw new QueryException("表别名重复: {$alias}");
            }
        }
    }

    /**
     * 行键加 'alias.' 前缀（按列名列表全展开；物理表=结构列序，派生表=子查询输出列序）
     *
     * @param list<string> $columns
     * @return array<string,mixed>
     */
    private function qualify(string $alias, array $row, array $columns): array
    {
        $qualified = [];
        foreach ($columns as $name) {
            $qualified[$alias . '.' . $name] = $row[$name] ?? null;
        }

        return $qualified;
    }

    /**
     * 收集全部源（基表/派生表 + join 表）的 CI 列映射：限定名 'alias.列名' 与裸列名均注册 true；
     * 裸名多源同名列一 CI 一 CS 时取"任一 CI 即 CI"（保守：需要消歧请使用限定列名比较）
     *
     * @param list<array{alias: string, table: ?string, columns: list<string>, ci: array<string, true>, schema: ?TableSchema, rows?: list<array<string,mixed>>}> $sources
     * @return array<string, true>
     */
    private function collationsOf(array $sources): array
    {
        $collations = [];
        foreach ($sources as $source) {
            foreach (array_keys($source['ci']) as $name) {
                $collations[$source['alias'] . '.' . $name] = true;
                $collations[$name] = true;
            }
        }

        return $collations;
    }

    // ---- 索引加速 ----

    /**
     * 索引候选行号：单表 + WHERE 为全 AND 连接的裸列名等值条件、
     * 且条件列集与某可用索引列集完全一致时，返回候选稠密行号（升序 = 原行序）；
     * 条件列含基表 CI 列时返回 null（索引哈希按区分大小写键构建，会漏掉跨大小写匹配行——
     * v1.2 键序 bug 同类教训，直接放弃预过滤走全扫描）；
     * 触发条件不满足或未命中索引返回 null（走全扫描，行为不变）。
     * 索引仅做预过滤，候选行仍需完整求值原 WHERE 兜底
     *
     * @return list<int>|null
     */
    private function candidateRowIndexes(SelectQuery $query, TableSchema $baseSchema): ?array
    {
        if ($query->joins !== []) {
            return null;
        }
        $where = $query->where;
        if (!$where instanceof ConditionGroup) {
            return null;
        }

        $columns = [];
        foreach ($where->conditions as $i => $condition) {
            // 除首个条件外，连接符必须全为 AND（OR 语义无法用等值索引交集表达）
            if ($i > 0 && ($where->connectors[$i - 1] ?? 'AND') !== 'AND') {
                return null;
            }
            if (!$condition instanceof Comparison) {
                return null;
            }
            if ($condition->operator !== '=') {
                return null;
            }
            if ($condition->value === null || str_contains($condition->column, '.')) {
                return null;
            }
            if (array_key_exists($condition->column, $columns)) {
                // 同列重复条件：放弃索引，交由扫描路径按原语义求值
                return null;
            }
            $columns[$condition->column] = $condition->value;
        }
        if ($columns === []) {
            return null;
        }

        // 等值条件列中任一列在基表 schema 中 CI → 跳过索引预过滤（CS 哈希表会漏跨大小写匹配行）
        foreach (array_keys($columns) as $column) {
            foreach ($baseSchema->columns as $schemaColumn) {
                if ($schemaColumn->name === $column && $schemaColumn->ci) {
                    return null;
                }
            }
        }

        return $this->connection->indexManager()->lookup(
            $query->table,
            array_keys($columns),
            array_values($columns),
        );
    }

    // ---- JOIN ----

    /**
     * 连接分派：等值连接且两侧列均非 CI 时走 hash join（构建侧被 join 表、探测侧已累积行），
     * ON 涉及 CI 列时跳过 hash（CS 哈希键会漏掉跨大小写匹配行）回退嵌套循环按 CI 折叠求值；
     * 输出顺序与嵌套循环语义一致（外层行序 × 桶内原序）
     *
     * @param list<array<string,mixed>> $rows 已累积限定行
     * @param array{alias: string, table: ?string, columns: list<string>, ci: array<string, true>, schema: ?TableSchema, rows?: list<array<string,mixed>>} $source 本次 join 引入的源
     * @param list<array{alias: string, table: ?string, columns: list<string>, ci: array<string, true>, schema: ?TableSchema, rows?: list<array<string,mixed>>}> $sources 全部源（本次源在末尾）
     * @return list<array<string,mixed>>
     */
    private function applyJoin(array $rows, array $source, JoinClause $join, array $sources): array
    {
        if ($join->on !== null) {
            // 任意 ON 条件表达式：嵌套循环在合并行上按条件组求值（正确性优先，不做 hash 加速）
            return $this->nestedLoopJoin($rows, $source, $join, $sources, false, $this->collationsOf($sources));
        }

        if ($join->operator === '=') {
            if (!$this->joinUsesCI($sources, $source, $join)) {
                return $join->type === 'RIGHT'
                    ? $this->hashRightJoin($rows, $source, $join, $sources)
                    : $this->hashLeftJoin($rows, $source, $join);
            }

            // CI 列等值：回退嵌套循环，ON 按 CI 折叠求值
            return $this->nestedLoopJoin($rows, $source, $join, $sources, true);
        }

        return $this->nestedLoopJoin($rows, $source, $join, $sources);
    }

    /**
     * ON 等值两侧是否涉及 CI 列：左列在既有累积源中解析、右列在被 join 表 schema 中解析
     *
     * @param list<array{alias: string, table: ?string, columns: list<string>, ci: array<string, true>, schema: ?TableSchema, rows?: list<array<string,mixed>>}> $sources
     * @param array{alias: string, table: ?string, columns: list<string>, ci: array<string, true>, schema: ?TableSchema, rows?: list<array<string,mixed>>} $source
     */
    private function joinUsesCI(array $sources, array $source, JoinClause $join): bool
    {
        return $this->columnIsCI($sources, $join->leftColumn)
            || $this->columnIsCI([$source], $join->rightColumn);
    }

    /**
     * 列在给定源集中是否 CI：限定名仅查对应别名源；裸名任一源命中 CI 列即视为 CI（保守回退）
     *
     * @param list<array{alias: string, table: ?string, columns: list<string>, ci: array<string, true>, schema: ?TableSchema, rows?: list<array<string,mixed>>}> $sources
     */
    private function columnIsCI(array $sources, string $column): bool
    {
        $pos = strrpos($column, '.');
        if ($pos !== false) {
            $alias = substr($column, 0, $pos);
            $name = substr($column, $pos + 1);
            foreach ($sources as $item) {
                if ($item['alias'] === $alias) {
                    return isset($item['ci'][$name]);
                }
            }

            return false;
        }

        foreach ($sources as $item) {
            if (isset($item['ci'][$column])) {
                return true;
            }
        }

        return false;
    }

    /**
     * hash join（INNER / LEFT）：被 join 表建哈希，已累积行探测；
     * 键用 IndexManager::normalizeKey（数值性归一，与 compareValues '=' 语义一致）；
     * 桶内按右行原序追加，输出顺序 = 左行序 × 桶内右序（与嵌套循环一致）
     *
     * @param list<array<string,mixed>> $rows
     * @param array{alias: string, table: ?string, columns: list<string>, ci: array<string, true>, schema: ?TableSchema, rows?: list<array<string,mixed>>} $source
     * @return list<array<string,mixed>>
     */
    private function hashLeftJoin(array $rows, array $source, JoinClause $join): array
    {
        $buckets = [];
        foreach ($this->joinSourceRows($source) as $row) {
            $joinRow = $this->qualify($source['alias'], $row, $source['columns']);
            $key = $this->resolveColumn($joinRow, $join->rightColumn);
            // JOIN on null 永不匹配，null 键行不进桶
            if ($key !== null) {
                $buckets[IndexManager::normalizeKey($key)][] = $joinRow;
            }
        }

        // LEFT 无匹配时右侧各列 null
        $nullRight = [];
        foreach ($source['columns'] as $name) {
            $nullRight[$source['alias'] . '.' . $name] = null;
        }

        $result = [];
        foreach ($rows as $row) {
            $left = $this->resolveColumn($row, $join->leftColumn);
            $matched = false;
            if ($left !== null) {
                foreach ($buckets[IndexManager::normalizeKey($left)] ?? [] as $joinRow) {
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
     * hash join（RIGHT）：已累积行建哈希（桶内保持左行原序），右表探测；
     * 输出顺序 = 右行序 × 桶内左序（与嵌套循环右外层实现一致）
     *
     * @param list<array<string,mixed>> $rows
     * @param array{alias: string, table: ?string, columns: list<string>, ci: array<string, true>, schema: ?TableSchema, rows?: list<array<string,mixed>>} $source
     * @param list<array{alias: string, table: ?string, columns: list<string>, ci: array<string, true>, schema: ?TableSchema, rows?: list<array<string,mixed>>}> $sources
     * @return list<array<string,mixed>>
     */
    private function hashRightJoin(array $rows, array $source, JoinClause $join, array $sources): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $key = $this->resolveColumn($row, $join->leftColumn);
            if ($key !== null) {
                $buckets[IndexManager::normalizeKey($key)][] = $row;
            }
        }

        // 左无匹配时左侧各列 null（全部既有源的列展开，兼容派生表/CTE 源）
        $leftKeys = [];
        $count = count($sources) - 1;
        for ($i = 0; $i < $count; $i++) {
            foreach ($sources[$i]['columns'] as $name) {
                $leftKeys[] = $sources[$i]['alias'] . '.' . $name;
            }
        }
        $nullLeft = array_fill_keys($leftKeys, null);

        $result = [];
        foreach ($this->joinSourceRows($source) as $row) {
            $joinRow = $this->qualify($source['alias'], $row, $source['columns']);
            $right = $this->resolveColumn($joinRow, $join->rightColumn);
            $matched = false;
            if ($right !== null) {
                foreach ($buckets[IndexManager::normalizeKey($right)] ?? [] as $leftRow) {
                    $result[] = array_merge($leftRow, $joinRow);
                    $matched = true;
                }
            }
            if (!$matched) {
                $result[] = array_merge($nullLeft, $joinRow);
            }
        }

        return $result;
    }

    /**
     * 嵌套循环连接（非等值运算符回退路径 / CI 列等值路径 / 任意 ON 条件表达式）；
     * 简单比较 ON 用 compareValues(leftVal, op, rightVal, ci) 求值；
     * 任意条件表达式 ON 用 ConditionEvaluator::evaluate 在合并行上求值
     *
     * @param list<array<string,mixed>> $rows 已累积限定行
     * @param array{alias: string, table: ?string, columns: list<string>, ci: array<string, true>, schema: ?TableSchema, rows?: list<array<string,mixed>>} $source 本次 join 引入的源
     * @param list<array{alias: string, table: ?string, columns: list<string>, ci: array<string, true>, schema: ?TableSchema, rows?: list<array<string,mixed>>}> $sources 全部源（本次源在末尾）
     * @param array<string, true>|null $collations ON 条件表达式求值用的 CI 映射
     * @return list<array<string,mixed>>
     */
    private function nestedLoopJoin(array $rows, array $source, JoinClause $join, array $sources, bool $ci = false, ?array $collations = null): array
    {
        $joinRows = [];
        foreach ($this->joinSourceRows($source) as $row) {
            $joinRows[] = $this->qualify($source['alias'], $row, $source['columns']);
        }

        $result = [];
        if ($join->type === 'RIGHT') {
            // 右表为外层，保证右行全保留；左无匹配时左侧各列 null
            $leftKeys = [];
            $count = count($sources) - 1;
            for ($i = 0; $i < $count; $i++) {
                foreach ($sources[$i]['columns'] as $name) {
                    $leftKeys[] = $sources[$i]['alias'] . '.' . $name;
                }
            }
            $nullLeft = array_fill_keys($leftKeys, null);
            foreach ($joinRows as $joinRow) {
                $matched = false;
                foreach ($rows as $row) {
                    if ($this->joinMatch($row, $joinRow, $join, $ci, $collations)) {
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
        foreach ($source['columns'] as $name) {
            $nullRight[$source['alias'] . '.' . $name] = null;
        }
        foreach ($rows as $row) {
            $matched = false;
            foreach ($joinRows as $joinRow) {
                if ($this->joinMatch($row, $joinRow, $join, $ci, $collations)) {
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
     * ON 条件求值：简单比较解析两侧列值后 compareValues；任意条件表达式在合并行上 evaluate
     */
    private function joinMatch(array $row, array $joinRow, JoinClause $join, bool $ci = false, ?array $collations = null): bool
    {
        if ($join->on !== null) {
            return ConditionEvaluator::evaluate(array_merge($row, $joinRow), $join->on, $collations);
        }

        $left = $this->resolveColumn($row, $join->leftColumn);
        $right = $this->resolveColumn($joinRow, $join->rightColumn);

        return ConditionEvaluator::compareValues($left, $join->operator, $right, $ci);
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
        // 投影表达式输出键索引（groupBy 可按表达式输出键分组，如 year(created) AS y 后 GROUP BY y）
        $expressionByKey = [];
        foreach ($query->expressions as $expression) {
            $expressionByKey[$expression->alias() ?? $expression->outputName()] = $expression;
        }

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
                    // 表达式输出键优先命中则按行求值，否则按表列解析
                    $keyParts[] = array_key_exists($column, $expressionByKey)
                        ? $expressionByKey[$column]->evaluate($row)
                        : $this->resolveColumn($row, $column);
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
            // groupBy 列值（表列键为去限定名，表达式键保持表达式输出键）
            foreach ($query->groupBy as $index => $column) {
                $output[array_key_exists($column, $expressionByKey)
                    ? $column
                    : $this->shortName($column)] = $keyParts[$index];
            }
            // 非聚合普通 select 列取组内首行值
            foreach ($query->columns as $column) {
                $output[$this->shortName($column)] = $first === null
                    ? null
                    : $this->resolveColumn($first, $column);
            }
            // 投影表达式取组内首行求值（已作为分组键输出的跳过，值即组键）
            foreach ($query->expressions as $expression) {
                $name = $expression->alias() ?? $expression->outputName();
                if (array_key_exists($name, $output)) {
                    continue;
                }
                $output[$name] = $first === null ? null : $expression->evaluate($first);
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
                    ? ValueCaster::compareNumeric($candidate, $best)
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
     * @param list<array{alias: string, table: ?string, columns: list<string>, ci: array<string, true>, schema: ?TableSchema, rows?: list<array<string,mixed>>}> $sources
     * @return list<array{output: array<string,mixed>, source: array<string,mixed>}>
     */
    private function project(array $rows, SelectQuery $query, array $sources): array
    {
        // 空列且无投影表达式 = 全部源列（按源引入顺序 + 列定义顺序）
        $columns = $query->columns;
        if ($columns === [] && $query->expressions === []) {
            foreach ($sources as $source) {
                foreach ($source['columns'] as $name) {
                    $columns[] = $source['alias'] . '.' . $name;
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

        // 投影表达式：输出键 = alias ?? outputName()，与既有输出键冲突/重复抛 QueryException
        $expressionColumns = [];
        foreach ($query->expressions as $expression) {
            $name = $expression->alias() ?? $expression->outputName();
            if (isset($occupied[$name]) || isset($expressionColumns[$name])) {
                throw new QueryException("输出列名冲突: {$name}");
            }
            $expressionColumns[$name] = $expression;
        }

        $entries = [];
        foreach ($rows as $row) {
            $output = [];
            foreach ($prepared as $short) {
                $output[$short] = $row[$occupied[$short]];
            }
            foreach ($expressionColumns as $name => $expression) {
                $output[$name] = $expression->evaluate($row);
            }
            $entries[] = ['output' => $output, 'source' => $row];
        }

        return $entries;
    }

    // ---- DISTINCT / 排序 ----

    /**
     * 窗口函数计算：按 partitionBy 分组、orderBy 排序后，为每行计算窗口函数值并写入输出行。
     * 排名函数（ROW_NUMBER/RANK/DENSE_RANK）需 ORDER BY（缺省抛 QueryException）；
     * 聚合窗口（COUNT/SUM/AVG/MIN/MAX）无 ORDER BY 时整分区统计算值（不做 ROWS BETWEEN 帧）
     *
     * @param list<array{output: array<string,mixed>, source: array<string,mixed>}> $entries
     * @param list<WindowExpression> $windows
     * @param array<string, true> $collations
     * @return list<array{output: array<string,mixed>, source: array<string,mixed>}>
     */
    private function applyWindows(array $entries, array $windows, array $collations): array
    {
        $outputKeys = $entries === [] ? [] : array_keys($entries[0]['output']);

        foreach ($windows as $window) {
            $name = $window->alias() ?? $window->function;
            if (in_array($name, $outputKeys, true)) {
                throw new QueryException("输出列名冲突: {$name}");
            }
            if ($window->isRanking() && $window->orderBy === []) {
                throw new QueryException("排名函数 {$window->function} 必须指定 ORDER BY");
            }

            // 分区索引
            $partitions = [];
            foreach ($entries as $i => $entry) {
                if ($window->partitionBy === []) {
                    $key = '';
                } else {
                    $parts = [];
                    foreach ($window->partitionBy as $col) {
                        $parts[] = self::resolveSortValue($entry, $col, $outputKeys);
                    }
                    $key = json_encode($parts, JSON_UNESCAPED_UNICODE);
                }
                $partitions[$key][] = $i;
            }

            // 各分区排序后计算
            $results = [];
            foreach ($partitions as $key => $indices) {
                $sorted = $indices;
                if ($window->orderBy !== []) {
                    $specs = self::resolveSortSpecs($window->orderBy, $outputKeys, $collations);
                    usort($sorted, function (int $a, int $b) use ($entries, $specs): int {
                        foreach ($specs as $spec) {
                            $cmp = $this->compare(
                                $this->sortValue($entries[$a], $spec),
                                $this->sortValue($entries[$b], $spec),
                                $spec['ci'],
                            );
                            if ($cmp !== 0) {
                                return $spec['direction'] === 'DESC' ? -$cmp : $cmp;
                            }
                        }

                        return $a <=> $b;
                    });
                }

                if ($window->isRanking()) {
                    $this->computeRanking($window, $sorted, $entries, $results);
                } else {
                    $this->computeAggregateWindow($window, $sorted, $entries, $results);
                }
            }

            // 按原序写入输出
            foreach ($results as $i => $value) {
                $entries[$i]['output'][$name] = $value;
            }
            $outputKeys[] = $name;
        }

        return $entries;
    }

    /**
     * 排名函数计算（ROW_NUMBER → 连续序号；RANK → 同值并列留空档；DENSE_RANK → 同值并列不留空档）
     *
     * @param list<int> $sorted 分区内排序后的行号
     * @param list<array{output: array<string,mixed>, source: array<string,mixed>}> $entries
     * @param array<int, mixed> $results
     */
    private function computeRanking(WindowExpression $window, array $sorted, array $entries, array &$results): void
    {
        $rowNumber = 0;
        $rank = 1;
        $denseRank = 1;
        $prevKey = null;

        foreach ($sorted as $i) {
            ++$rowNumber;
            if ($window->function === 'ROW_NUMBER') {
                $results[$i] = $rowNumber;
                continue;
            }

            // RANK / DENSE_RANK：比较排序键（全 null 视为相等，null 语义同 SQL——最小值视为并列）
            $currentKey = $this->windowSortKey($entries[$i], $window->orderBy);
            if ($prevKey !== null && $currentKey !== $prevKey) {
                $rank = $rowNumber;
                ++$denseRank;
            }
            $prevKey = $currentKey;
            $results[$i] = $window->function === 'RANK' ? $rank : $denseRank;
        }
    }

    /**
     * 聚合窗口函数计算：整分区（无 ORDER BY）或分区内逐行前缀（有 ORDER BY，
     * 但当前简化：同排序值整组计算后每行相同值——不做 ROWS BETWEEN 帧）
     *
     * @param list<int> $sorted 分区内排序后的行号
     * @param list<array{output: array<string,mixed>, source: array<string,mixed>}> $entries
     * @param array<int, mixed> $results
     */
    private function computeAggregateWindow(WindowExpression $window, array $sorted, array $entries, array &$results): void
    {
        // COUNT(*)：统计分区行数（含 null）
        if ($window->column === '*' && $window->function === 'COUNT') {
            $single = count($sorted);
            foreach ($sorted as $i) {
                $results[$i] = $single;
            }

            return;
        }

        // 收集全分区非 null 值
        $values = [];
        foreach ($sorted as $i) {
            $value = $this->resolveColumn($entries[$i]['source'], $window->column);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        $single = $this->aggregateWindowValue($window->function, $values);

        // 每行写入相同值（当前简化：不做 ROWS BETWEEN 帧）
        foreach ($sorted as $i) {
            $results[$i] = $single;
        }
    }

    /**
     * 排序键序列化（分区排序键比较用）
     *
     * @param list<array{column: string, direction: 'ASC'|'DESC'}> $orderBy
     */
    private function windowSortKey(array $entry, array $orderBy): string
    {
        $parts = [];
        foreach ($orderBy as $order) {
            $parts[] = $this->resolveColumn($entry['source'], $order['column']);
        }

        return json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * 聚合窗口单值：COUNT/SUM/AVG/MIN/MAX 对值列表统一计算（与 aggregateValue 逻辑一致）
     *
     * @param list<mixed> $values 非 null 值列表
     */
    private function aggregateWindowValue(string $function, array $values): mixed
    {
        if ($function === 'COUNT') {
            return count($values);
        }
        if ($values === []) {
            return null;
        }
        if ($function === 'MIN' || $function === 'MAX') {
            $allNumeric = true;
            foreach ($values as $value) {
                if (!$this->isNumericValue($value)) {
                    $allNumeric = false;
                    break;
                }
            }
            $best = $values[0];
            for ($i = 1, $c = count($values); $i < $c; $i++) {
                $cmp = $allNumeric
                    ? ValueCaster::compareNumeric($values[$i], $best)
                    : (string) $values[$i] <=> (string) $best;
                if (($function === 'MIN' && $cmp < 0) || ($function === 'MAX' && $cmp > 0)) {
                    $best = $values[$i];
                }
            }

            return $best;
        }

        // SUM / AVG
        $sum = 0;
        foreach ($values as $value) {
            if (!$this->isNumericValue($value)) {
                throw new QueryException("聚合窗口 {$function} 含非数值值: " . var_export($value, true));
            }
            $sum += $value;
        }

        return $function === 'AVG' ? $sum / count($values) : $sum;
    }

    private static function resolveSortValue(array $entry, string $column, array $outputKeys): mixed
    {
        if (in_array($column, $outputKeys, true)) {
            return $entry['output'][$column] ?? null;
        }

        return $entry['source'][$column] ?? null;
    }

    /**
     * 收尾三段：DISTINCT → ORDER BY → LIMIT/OFFSET（builder 已校验非负）
     *
     * @param list<array{output: array<string,mixed>, source: array<string,mixed>}> $entries
     * @param array<string, true> $collations CI 列映射（排序键命中时字符串值折叠后比较）
     * @return list<array{output: array<string,mixed>, source: array<string,mixed>}>
     */
    private function finalizeEntries(array $entries, SelectQuery $query, array $collations): array
    {
        // DISTINCT
        if ($query->distinct) {
            $entries = $this->distinct($entries);
        }

        // ORDER BY
        if ($query->orderBy !== []) {
            $entries = $this->sort($entries, $query->orderBy, $collations);
        }

        // LIMIT/OFFSET
        if ($query->offset !== null) {
            $entries = array_slice($entries, $query->offset);
        }
        if ($query->limit !== null) {
            $entries = array_slice($entries, 0, $query->limit);
        }

        return $entries;
    }

    /**
     * UNION 合并：每个联合子句完整执行（含其自身 DISTINCT/ORDER/LIMIT 与嵌套 unions，
     * 递归展开）后并入基础行；
     * - UNION：追加时对全集合去重（含基础行与已并入行，键序不敏感，保持首见顺序）
     * - UNION ALL：直接追加，重复保留
     * - 空结果方无法取键，跳过列校验与合并（SQL 空集无列冲突概念）
     * - 列对齐：两侧输出键集合排序后不一致抛 QueryException（消息含两侧键列表）
     *
     * @param list<array{output: array<string,mixed>, source: array<string,mixed>}> $entries
     * @param list<UnionClause> $unions
     * @return list<array{output: array<string,mixed>, source: array<string,mixed>}>
     */
    private function mergeUnions(array $entries, array $unions): array
    {
        $rows = array_map(
            static fn (array $entry): array => $entry['output'],
            $entries,
        );

        $seen = null;
        foreach ($unions as $clause) {
            $subRows = (new self($this->connection))->execute($clause->query)->rows();
            if ($subRows === []) {
                // 空结果方跳过列校验与合并
                continue;
            }
            if ($rows !== []) {
                $this->assertUnionColumns($rows[0], $subRows[0]);
            }

            if ($clause->type === 'UNION ALL') {
                foreach ($subRows as $row) {
                    $rows[] = $row;
                    if ($seen !== null) {
                        // 已启用去重的后续 UNION 语义上视为全集去重，追加行同步入池
                        $seen[$this->rowKey($row)] = true;
                    }
                }
                continue;
            }

            // UNION：全集合去重（首次启用时以当前已合并全集建池）
            if ($seen === null) {
                $seen = [];
                foreach ($rows as $row) {
                    $seen[$this->rowKey($row)] = true;
                }
            }
            foreach ($subRows as $row) {
                $key = $this->rowKey($row);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $rows[] = $row;
                }
            }
        }

        // 合并行无源行上下文，统一包装空 source（外层 ORDER 仅按输出键解析）
        return array_map(
            static fn (array $row): array => ['output' => $row, 'source' => []],
            $rows,
        );
    }

    /**
     * 列对齐校验：两侧输出键集合排序后不一致抛 QueryException
     *
     * @param array<string,mixed> $baseRow
     * @param array<string,mixed> $subRow
     */
    private function assertUnionColumns(array $baseRow, array $subRow): void
    {
        $baseKeys = array_keys($baseRow);
        $subKeys = array_keys($subRow);
        $sortedBase = $baseKeys;
        $sortedSub = $subKeys;
        sort($sortedBase);
        sort($sortedSub);
        if ($sortedBase !== $sortedSub) {
            throw new QueryException(
                'UNION 列不一致: 基础方 [' . implode(', ', $baseKeys)
                . '] vs 联合方 [' . implode(', ', $subKeys) . ']'
            );
        }
    }

    /**
     * 去重键：键序不敏感（ksort 后 json_encode），保证两侧同内容行判定相同
     *
     * @param array<string,mixed> $row
     */
    private function rowKey(array $row): string
    {
        ksort($row);

        return json_encode($row, JSON_UNESCAPED_UNICODE);
    }

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
     * 多列稳定排序：先看输出行键（精确或去限定名匹配），否则回退源限定行（含歧义检查）；
     * 排序列命中 collations 时字符串值折叠后比较（null 语义不变）；
     * 大结果集（> EXTERNAL_SORT_CHUNK_ROWS）自动走外部归并——写临时文件分块 + 多路归并
     *
     * @param list<array{output: array<string,mixed>, source: array<string,mixed>}> $entries
     * @param list<array{column: string, direction: 'ASC'|'DESC'}> $orderBy
     * @param array<string, true> $collations
     * @return list<array{output: array<string,mixed>, source: array<string,mixed>}>
     */
    private function sort(array $entries, array $orderBy, array $collations): array
    {
        if ($entries === []) {
            return [];
        }

        // 预解析排序规格
        $outputKeys = array_keys($entries[0]['output']);
        $specs = self::resolveSortSpecs($orderBy, $outputKeys, $collations);

        // 外部归并阈值
        if (count($entries) > self::EXTERNAL_SORT_CHUNK_ROWS) {
            return $this->externalSort($entries, $specs);
        }

        return $this->memorySort($entries, $specs);
    }

    /**
     * 内存排序（usort 稳定排序，保留原始索引）
     *
     * @param list<array{output: array<string,mixed>, source: array<string,mixed>}> $entries
     * @param list<array> $specs
     * @return list<array{output: array<string,mixed>, source: array<string,mixed>}>
     */
    private function memorySort(array $entries, array $specs): array
    {
        $indexed = [];
        foreach ($entries as $i => $entry) {
            $indexed[] = ['index' => $i, 'entry' => $entry];
        }

        usort($indexed, function (array $a, array $b) use ($specs): int {
            foreach ($specs as $spec) {
                $cmp = $this->compare(
                    $this->sortValue($a['entry'], $spec),
                    $this->sortValue($b['entry'], $spec),
                    $spec['ci'],
                );
                if ($cmp !== 0) {
                    return $spec['direction'] === 'DESC' ? -$cmp : $cmp;
                }
            }

            return $a['index'] <=> $b['index'];
        });

        return array_map(static fn (array $item): array => $item['entry'], $indexed);
    }

    /**
     * 外部归并排序：超阈值时写临时文件分块后多路归并
     *
     * @param list<array{output: array<string,mixed>, source: array<string,mixed>}> $entries
     * @param list<array> $specs
     * @return list<array{output: array<string,mixed>, source: array<string,mixed>}>
     */
    private function externalSort(array $entries, array $specs): array
    {
        $tempDir = sys_get_temp_dir() . '/psql-sort-' . uniqid();
        if (!mkdir($tempDir, 0700) && !is_dir($tempDir)) {
            // 退化为内存排序
            return $this->memorySort($entries, $specs);
        }

        try {
            $chunks = [];
            $chunk = [];
            foreach ($entries as $entry) {
                $chunk[] = $entry;
                if (count($chunk) >= self::EXTERNAL_SORT_CHUNK_ROWS) {
                    $chunks[] = $this->writeSortedChunk($tempDir, $chunk, $specs, count($chunks));
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                $chunks[] = $this->writeSortedChunk($tempDir, $chunk, $specs, count($chunks));
            }

            return $this->mergeChunks($chunks, $specs);
        } finally {
            $this->cleanTempDir($tempDir);
        }
    }

    /**
     * 写排序分块到临时文件（内存排序后 serialize）
     *
     * @param list<array{output: array<string,mixed>, source: array<string,mixed>}> $chunk
     * @param list<array> $specs
     * @return string 临时文件路径
     */
    private function writeSortedChunk(string $tempDir, array $chunk, array $specs, int $index): string
    {
        $sorted = $this->memorySort($chunk, $specs);
        $path = $tempDir . '/' . $index . '.sort';
        file_put_contents($path, serialize($sorted), LOCK_EX);

        return $path;
    }

    /**
     * 多路归并：从各分块文件逐一读取，用最小堆按规格排序合并
     *
     * @param list<string> $chunkFiles
     * @param list<array> $specs
     * @return list<array{output: array<string,mixed>, source: array<string,mixed>}>
     */
    private function mergeChunks(array $chunkFiles, array $specs): array
    {
        /** @var list<list<array{output: array<string,mixed>, source: array<string,mixed>}>> 每个分块已加载的行缓冲 */
        $buffers = [];
        /** @var list<int> 每个分块的当前读取偏移 */
        $offsets = array_fill(0, count($chunkFiles), 0);

        // 加载所有分块到内存（每个分块已 ≤ EXTERNAL_SORT_CHUNK_ROWS 行，内存可接受）
        foreach ($chunkFiles as $path) {
            $data = unserialize(file_get_contents($path));
            $buffers[] = is_array($data) ? $data : [];
        }

        $result = [];
        while (true) {
            // 找当前最小（按排序规格比较）
            $best = null;
            $bestIdx = -1;
            $bestEntry = null;
            foreach ($buffers as $i => $buf) {
                if ($offsets[$i] < count($buf)) {
                    $entry = $buf[$offsets[$i]];
                    if ($best === null || $this->compareEntries($entry, $bestEntry, $specs) < 0) {
                        $best = $entry;
                        $bestIdx = $i;
                        $bestEntry = $entry;
                    }
                }
            }
            if ($bestIdx === -1) {
                break;
            }
            $result[] = $best;
            $offsets[$bestIdx]++;
        }

        return $result;
    }

    /**
     * 两条目按排序规格比较（稳定：同值时先出者优先，由分块内序 + 扫描序保证）
     */
    private function compareEntries(
        array $a,
        array $b,
        array $specs,
    ): int {
        foreach ($specs as $spec) {
            $cmp = $this->compare(
                $this->sortValue($a, $spec),
                $this->sortValue($b, $spec),
                $spec['ci'],
            );
            if ($cmp !== 0) {
                return $spec['direction'] === 'DESC' ? -$cmp : $cmp;
            }
        }

        return 0;
    }

    /**
     * 清理临时目录
     */
    private function cleanTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            @unlink($dir . '/' . $item);
        }
        @rmdir($dir);
    }

    /**
     * 解析排序规格（公共逻辑，内存/外部归并共用）
     *
     * @param list<array{column: string, direction: 'ASC'|'DESC'}> $orderBy
     * @param list<string> $outputKeys
     * @param array<string, true> $collations
     * @return list<array{column: string, direction: string, outputKey: ?string, ci: bool}>
     */
    private static function resolveSortSpecs(array $orderBy, array $outputKeys, array $collations): array
    {
        $specs = [];
        foreach ($orderBy as $order) {
            $outputKey = null;
            if (in_array($order['column'], $outputKeys, true)) {
                $outputKey = $order['column'];
            } else {
                $pos = strrpos($order['column'], '.');
                $short = $pos === false ? $order['column'] : substr($order['column'], $pos + 1);
                if (in_array($short, $outputKeys, true)) {
                    $outputKey = $short;
                }
            }
            $specs[] = [
                'column' => $order['column'],
                'direction' => $order['direction'],
                'outputKey' => $outputKey,
                'ci' => ConditionEvaluator::resolveCI($collations, $order['column']),
            ];
        }

        return $specs;
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
     * 排序比较：null 视为最小（null 语义不因 ci 改变）；双侧数值性按数值，否则按字符串；
     * ci=true 时字符串侧先折叠（仅 is_string 值）
     */
    private function compare(mixed $left, mixed $right, bool $ci = false): int
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
            return ValueCaster::compareNumeric($left, $right);
        }
        if ($ci) {
            return (string) $this->ciFoldValue($left) <=> (string) $this->ciFoldValue($right);
        }

        return (string) $left <=> (string) $right;
    }

    /**
     * CI 折叠：仅字符串值转小写（mbstring 优先，无 mbstring 退化 strtolower），非字符串原样返回
     */
    private function ciFoldValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
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
     * @param list<array{alias: string, table: ?string, columns: list<string>, ci: array<string, true>, schema: ?TableSchema, rows?: list<array<string,mixed>>}> $sources
     */
    private function resolveKeyName(array $sources, string $column): string
    {
        $pos = strrpos($column, '.');
        if ($pos !== false) {
            $alias = substr($column, 0, $pos);
            $name = substr($column, $pos + 1);
            foreach ($sources as $source) {
                if ($source['alias'] === $alias && in_array($name, $source['columns'], true)) {
                    return $column;
                }
            }

            throw new QueryException("未知列: {$column}");
        }

        $candidates = [];
        foreach ($sources as $source) {
            if (in_array($column, $source['columns'], true)) {
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
