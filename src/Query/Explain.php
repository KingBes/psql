<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Query\Condition\Comparison;
use Kingbes\Psql\Query\Condition\Condition;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Query\Condition\ExistsCheck;
use Kingbes\Psql\Query\Condition\SubqueryIn;
use Kingbes\Psql\Schema\TableSchema;

/**
 * EXPLAIN 计划输出：静态分析 SelectQuery + schema 元数据，产出访问路径步骤列表（不执行查询本体）
 */
final class Explain
{
    /**
     * 入口：分析一个构建好的查询，返回计划步骤列表
     *
     * 表不存在时底层异常（loadSchema/readRows 抛出）直接透传，不做友好包装。
     *
     * @return list<array<string, string|int|list<string>>> 每步 ['step' => ..., 'detail' => ...]（step 英文大写下划线，detail 中文）
     */
    public static function of(Connection $connection, SelectQuery $query): array
    {
        $db = $connection->currentDatabase();
        $engine = $connection->engine();

        // 基表 schema（表不存在时透传底层异常）
        $baseSchema = $engine->loadSchema($db, $query->table);

        $steps = [];

        // 1) 基表访问路径：索引预过滤判定（镜像 Executor 触发条件）；
        //    估算行数为实际存储行数（EXPLAIN 允许读取存储数据但不执行查询本体）
        $estRows = count($engine->readRows($db, $query->table));
        $indexColumns = self::candidateIndexColumns($connection, $query, $baseSchema);
        if ($indexColumns !== null) {
            $name = self::indexName($baseSchema, $indexColumns);
            $steps[] = [
                'step' => 'SCAN',
                'table' => $query->table,
                'via' => "INDEX {$name} (hash, equality)",
                'estRows' => $estRows,
                'detail' => '哈希索引等值预过滤，候选行仍完整求值原 WHERE 兜底；'
                    . '估算行数为实际存储行数，索引预过滤后另行求值（哈希索引无基数统计）',
            ];
        } else {
            $steps[] = [
                'step' => 'SCAN',
                'table' => $query->table,
                'via' => 'FULL SCAN',
                'estRows' => $estRows,
                'detail' => '全表扫描读取全部存储行',
            ];
        }

        // 2) JOIN：按声明顺序逐个分派（镜像 Executor::applyJoin 的 hash/nested 条件）
        $sources = [[
            'table' => $query->table,
            'alias' => $query->alias ?? $query->table,
            'schema' => $baseSchema,
        ]];
        foreach ($query->joins as $join) {
            $joinSchema = $engine->loadSchema($db, $join->table);
            $joinSource = [
                'table' => $join->table,
                'alias' => $join->alias ?? $join->table,
                'schema' => $joinSchema,
            ];

            $leftCI = self::columnIsCI($sources, $join->leftColumn);
            $rightCI = self::columnIsCI([$joinSource], $join->rightColumn);

            if ($join->operator === '=' && !$leftCI && !$rightCI) {
                $steps[] = [
                    'step' => 'JOIN',
                    'type' => 'HASH',
                    'left' => self::resolveSourceTable($sources, $join->leftColumn),
                    'right' => $join->table,
                    'on' => $join->leftColumn . ' ' . $join->operator . ' ' . $join->rightColumn,
                    'detail' => '等值条件且两侧列均区分大小写，采用哈希连接（被 join 表建哈希，已累积行探测）',
                ];
            } else {
                $reason = $join->operator !== '='
                    ? '非等值连接条件'
                    : '等值条件涉及 CI（大小写不敏感）列，区分大小写的哈希键会漏掉跨大小写匹配行';
                $steps[] = [
                    'step' => 'JOIN',
                    'type' => 'NESTED LOOP',
                    'left' => self::resolveSourceTable($sources, $join->leftColumn),
                    'right' => $join->table,
                    'on' => $join->leftColumn . ' ' . $join->operator . ' ' . $join->rightColumn,
                    'detail' => $reason . '，回退嵌套循环连接（ON 条件逐行对求值）',
                ];
            }

            $sources[] = $joinSource;
        }

        // 3) 子查询条件：解析后才进入常规求值（含 union 子方树递归计数）
        $subqueryCount = self::countSubqueries($query);
        if ($subqueryCount > 0) {
            $steps[] = [
                'step' => 'SUBQUERY',
                'count' => $subqueryCount,
                'detail' => '将先解析为常量列表/真值后进入常规求值',
            ];
        }

        // 4) 分组聚合（基础方）
        if ($query->groupBy !== [] || $query->aggregates !== []) {
            $steps[] = [
                'step' => 'AGGREGATE',
                'groupBy' => $query->groupBy,
                'funcs' => array_map(
                    static fn (AggregateExpression $aggregate): string => $aggregate->function,
                    $query->aggregates,
                ),
                'detail' => '内存分组聚合',
            ];
        }

        // 5) UNION 分支：基础方聚合后、收尾步骤前合并（各分支独立完整执行）
        $order = 0;
        foreach ($query->unions as $union) {
            ++$order;
            $steps[] = [
                'step' => 'UNION',
                'type' => $union->type,
                'order' => $order,
                'detail' => '该分支独立完整执行后与基础方合并，合并结果再经后续收尾步骤'
                    . ($union->type === 'UNION' ? '（全集合去重，保持首见顺序）' : '（保留重复行）'),
            ];
        }

        // 6) 收尾：去重 / 排序 / 分页（对齐 Executor::finalizeEntries 的 DISTINCT → ORDER → LIMIT 顺序）
        if ($query->distinct) {
            $steps[] = [
                'step' => 'DISTINCT',
                'detail' => '输出行内存去重（保持首见顺序）',
            ];
        }
        if ($query->orderBy !== []) {
            $keys = [];
            foreach ($query->orderBy as $item) {
                $keys[] = $item['column'] . ' ' . $item['direction'];
            }
            $steps[] = [
                'step' => 'SORT',
                'keys' => implode(', ', $keys),
                'detail' => '内存排序（无外部归并）',
            ];
        }
        if ($query->limit !== null || $query->offset !== null) {
            $limitStep = ['step' => 'LIMIT'];
            if ($query->limit !== null) {
                $limitStep['limit'] = $query->limit;
            }
            if ($query->offset !== null) {
                $limitStep['offset'] = $query->offset;
            }
            $limitStep['detail'] = '分页截断（排序完成后应用）';
            $steps[] = $limitStep;
        }

        return $steps;
    }

    /**
     * 索引触发判定：与 Executor::candidateRowIndexes 保持同步（镜像实现）——
     * 单表无 join、WHERE 为全 AND 连接的裸列名等值条件、值非 null、列无重复，
     * 且等值列中任一列在基表 schema 中为 CI 则不命中（CS 哈希表会漏跨大小写匹配行），
     * 条件列集与某可用索引列集完全一致时命中，返回排序后的列集；未命中返回 null
     *
     * @return list<string>|null
     */
    private static function candidateIndexColumns(
        Connection $connection,
        SelectQuery $query,
        TableSchema $baseSchema,
    ): ?array {
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

        // 等值条件列中任一列在基表 schema 中 CI → 跳过索引预过滤
        foreach (array_keys($columns) as $column) {
            foreach ($baseSchema->columns as $schemaColumn) {
                if ($schemaColumn->name === $column && $schemaColumn->ci) {
                    return null;
                }
            }
        }

        // 条件列集与某可用索引列集完全一致（顺序不敏感；availableIndexes 已排序去重）
        $wanted = array_keys($columns);
        sort($wanted);
        foreach ($connection->indexManager()->availableIndexes($query->table) as $index) {
            if ($index === $wanted) {
                return $wanted;
            }
        }

        return null;
    }

    /**
     * 命中索引的展示名：显式索引取索引名，主键取 PRIMARY，单列/联合唯一取 unique: 前缀
     *
     * @param list<string> $columns 已排序的命中列集
     */
    private static function indexName(TableSchema $schema, array $columns): string
    {
        foreach ($schema->indexes as $index) {
            $sorted = $index->columns;
            sort($sorted);
            if ($sorted === $columns) {
                return $index->name;
            }
        }

        $primaryKeyColumns = array_map(
            static fn ($column): string => $column->name,
            $schema->primaryKeyColumns(),
        );
        sort($primaryKeyColumns);
        if ($primaryKeyColumns !== [] && $primaryKeyColumns === $columns) {
            return 'PRIMARY';
        }

        foreach ($schema->columns as $column) {
            if ($column->unique && [$column->name] === $columns) {
                return 'unique:' . $column->name;
            }
        }
        foreach ($schema->uniqueKeys as $key) {
            $sorted = $key;
            sort($sorted);
            if ($sorted === $columns) {
                return 'unique:' . implode(',', $key);
            }
        }

        return implode(',', $columns);
    }

    /**
     * 列在给定源集中是否 CI：与 Executor::columnIsCI 保持同步（镜像实现）——
     * 限定名仅查对应别名源；裸名任一源命中 CI 列即视为 CI（保守回退）
     *
     * @param list<array{table: string, alias: string, schema: TableSchema}> $sources
     */
    private static function columnIsCI(array $sources, string $column): bool
    {
        $pos = strrpos($column, '.');
        if ($pos !== false) {
            $alias = substr($column, 0, $pos);
            $name = substr($column, $pos + 1);
            foreach ($sources as $source) {
                if ($source['alias'] === $alias) {
                    foreach ($source['schema']->columns as $schemaColumn) {
                        if ($schemaColumn->name === $name) {
                            return $schemaColumn->ci;
                        }
                    }

                    return false;
                }
            }

            return false;
        }

        foreach ($sources as $source) {
            foreach ($source['schema']->columns as $schemaColumn) {
                if ($schemaColumn->name === $column && $schemaColumn->ci) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 解析列所属源的基础表名（剥别名）：限定名按别名匹配、裸名按列定义命中，未命中回退最近累积源
     *
     * @param list<array{table: string, alias: string, schema: TableSchema}> $sources
     */
    private static function resolveSourceTable(array $sources, string $column): string
    {
        if ($sources === []) {
            return '';
        }

        $pos = strrpos($column, '.');
        if ($pos !== false) {
            $alias = substr($column, 0, $pos);
            foreach ($sources as $source) {
                if ($source['alias'] === $alias) {
                    return $source['table'];
                }
            }
        } else {
            foreach ($sources as $source) {
                foreach ($source['schema']->columns as $schemaColumn) {
                    if ($schemaColumn->name === $column) {
                        return $source['table'];
                    }
                }
            }
        }

        return $sources[count($sources) - 1]['table'];
    }

    /**
     * 统计查询中的子查询条件数（SubqueryIn + ExistsCheck）：
     * 条件树递归遍历 + union 子方树递归（子查询内部自身的计划不属本层，不展开）
     */
    private static function countSubqueries(SelectQuery $query): int
    {
        $count = $query->where === null ? 0 : self::countInCondition($query->where);
        foreach ($query->unions as $union) {
            $count += self::countSubqueries($union->query);
        }

        return $count;
    }

    /**
     * 条件树遍历计数：ConditionGroup 递归展开，SubqueryIn/ExistsCheck 各计 1
     */
    private static function countInCondition(Condition $condition): int
    {
        if ($condition instanceof SubqueryIn || $condition instanceof ExistsCheck) {
            return 1;
        }
        if ($condition instanceof ConditionGroup) {
            $count = 0;
            foreach ($condition->conditions as $child) {
                $count += self::countInCondition($child);
            }

            return $count;
        }

        return 0;
    }
}
