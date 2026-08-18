<?php

declare(strict_types=1);

namespace Kingbes\Psql\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Query\ColumnRef;
use Kingbes\Psql\Query\Condition\BooleanConst;
use Kingbes\Psql\Query\Condition\Comparison;
use Kingbes\Psql\Query\Condition\Condition;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Query\Condition\ExistsCheck;
use Kingbes\Psql\Query\Condition\InList;
use Kingbes\Psql\Query\Condition\ScalarSubquery;
use Kingbes\Psql\Query\Condition\SubqueryIn;
use Kingbes\Psql\Query\ConditionEvaluator;
use Kingbes\Psql\Query\SelectQuery;

/**
 * 子查询条件解析器：把条件树中的 SubqueryIn/ExistsCheck 替换为可求值的 InList/BooleanConst
 *
 * 非相关子查询独立完整执行一次；相关子查询（条件中引用外层列）由 Executor 对外层行
 * 逐行调用 resolveCorrelated：把外层列引用替换为行值后执行（仅 SELECT WHERE 支持）
 */
final class SubqueryResolver
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * 深拷贝语义：返回解析后的新条件树（原树不可变，重复解析安全）；
     * 无子查询条件时原样返回同一实例；相关子查询在此抛 QueryException（需外层行上下文）
     */
    public function resolve(Condition $condition): Condition
    {
        if ($condition instanceof ConditionGroup) {
            return $this->resolveGroup($condition);
        }
        if ($condition instanceof SubqueryIn) {
            return $this->resolveIn($condition);
        }
        if ($condition instanceof ExistsCheck) {
            return $this->resolveExists($condition);
        }
        if ($condition instanceof ScalarSubquery) {
            return $this->resolveScalar($condition);
        }

        return $condition;
    }

    /**
     * 相关子查询逐行求值：把条件树中的相关子查询按外层行绑定后执行
     * （子查询内部嵌套的相关子查询在其自身 execute 时按同样机制递归处理）
     */
    public function resolveCorrelated(Condition $condition, array $outerRow): Condition
    {
        if ($condition instanceof ConditionGroup) {
            return $this->resolveGroupCorrelated($condition, $outerRow);
        }
        if ($condition instanceof SubqueryIn) {
            return $this->resolveInCorrelated($condition, $outerRow);
        }
        if ($condition instanceof ExistsCheck) {
            return $this->resolveExistsCorrelated($condition, $outerRow);
        }
        if ($condition instanceof ScalarSubquery) {
            return $this->resolveScalarCorrelated($condition, $outerRow);
        }

        return $condition;
    }

    /**
     * 条件组重建：子条件递归 resolve，connectors 保持；全部未变时返回原组实例
     */
    private function resolveGroup(ConditionGroup $group): Condition
    {
        $resolved = [];
        $changed = false;
        foreach ($group->conditions as $child) {
            $new = $this->resolve($child);
            $resolved[] = $new;
            if ($new !== $child) {
                $changed = true;
            }
        }
        if (!$changed) {
            return $group;
        }

        return self::rebuildGroup($group, $resolved);
    }

    /**
     * 条件组逐行重建：子条件均按外层行绑定解析
     */
    private function resolveGroupCorrelated(ConditionGroup $group, array $outerRow): Condition
    {
        $resolved = [];
        foreach ($group->conditions as $child) {
            $resolved[] = $this->resolveCorrelated($child, $outerRow);
        }

        return self::rebuildGroup($group, $resolved);
    }

    /**
     * @param list<Condition> $resolved
     */
    private static function rebuildGroup(ConditionGroup $group, array $resolved): ConditionGroup
    {
        $rebuilt = new ConditionGroup();
        foreach ($resolved as $i => $item) {
            $rebuilt->add($item, $group->connectors[$i - 1] ?? 'AND');
        }

        return $rebuilt;
    }

    /**
     * 执行子查询取单列值列表 → InList；null 成员语义由既有 InList 求值处理
     */
    private function resolveIn(SubqueryIn $condition): InList
    {
        $this->assertNotCorrelated($condition->query);
        $rows = $this->executeSubquery($condition->query);
        if ($rows !== [] && count($rows[0]) !== 1) {
            throw new QueryException('子查询必须指定输出列（输出列数须为 1，实际: ' . count($rows[0]) . '）');
        }

        $values = array_map(static fn (array $row): mixed => reset($row), $rows);

        return new InList($condition->column, $values, $condition->negate);
    }

    /**
     * 相关 IN：把子查询按外层行绑定后执行（子查询须输出 1 列）
     */
    private function resolveInCorrelated(SubqueryIn $condition, array $outerRow): InList
    {
        $rows = $this->executeSubquery($this->bindOuterRow($condition->query, $outerRow));
        if ($rows !== [] && count($rows[0]) !== 1) {
            throw new QueryException('子查询必须指定输出列（输出列数须为 1，实际: ' . count($rows[0]) . '）');
        }

        $values = array_map(static fn (array $row): mixed => reset($row), $rows);

        return new InList($condition->column, $values, $condition->negate);
    }

    /**
     * 执行子查询：非空行集即存在 → EXISTS 空为 false / NOT EXISTS 空为 true
     */
    private function resolveExists(ExistsCheck $condition): BooleanConst
    {
        $this->assertNotCorrelated($condition->query);
        $rows = $this->executeSubquery($condition->query);

        return new BooleanConst($condition->negate ? $rows === [] : $rows !== []);
    }

    /**
     * 相关 EXISTS：按外层行绑定后执行，非空行集即存在
     */
    private function resolveExistsCorrelated(ExistsCheck $condition, array $outerRow): BooleanConst
    {
        $rows = $this->executeSubquery($this->bindOuterRow($condition->query, $outerRow));

        return new BooleanConst($condition->negate ? $rows === [] : $rows !== []);
    }

    /**
     * 标量子查询：独立执行取首行首列值 → Comparison（空集 → null，col = NULL 过滤行）
     */
    private function resolveScalar(ScalarSubquery $condition): Comparison
    {
        $this->assertNotCorrelated($condition->query);

        return new Comparison($condition->column, $condition->operator, $this->scalarValue($condition->query));
    }

    /**
     * 相关标量子查询：按外层行绑定后执行取首行首列值 → Comparison
     */
    private function resolveScalarCorrelated(ScalarSubquery $condition, array $outerRow): Comparison
    {
        return new Comparison(
            $condition->column,
            $condition->operator,
            $this->scalarValue($this->bindOuterRow($condition->query, $outerRow)),
        );
    }

    /**
     * 执行子查询取首行首列值；空集返回 null（SQL 标量子查询语义）；输出列数须为 1
     */
    private function scalarValue(SelectQuery $query): mixed
    {
        $rows = $this->executeSubquery($query);
        if ($rows === []) {
            return null;
        }
        $first = $rows[0];
        if (count($first) !== 1) {
            throw new QueryException('标量子查询必须输出 1 列，实际: ' . count($first));
        }

        return reset($first);
    }

    /**
     * 子查询完整执行（含其自身 DISTINCT/ORDER/LIMIT 及嵌套 unions；
     * 子查询自身的 where 树在本次 execute 中同样经 resolver 解析，天然支持递归嵌套）
     *
     * @return list<array<string,mixed>>
     */
    private function executeSubquery(SelectQuery $query): array
    {
        return (new Executor($this->connection))->execute($query)->rows();
    }

    // ---- 相关子查询：外层列绑定 ----

    /**
     * 子查询是否相关（条件树引用非自身源的外层列）：仅扫描当前层 where（
     * 子查询内部嵌套的相关子查询在其自身执行时递归判定）
     */
    public static function isCorrelated(SelectQuery $query): bool
    {
        if ($query->where === null) {
            return false;
        }

        return self::treeHasOuterRef($query->where, self::sourceAliases($query));
    }

    /**
     * 条件树中是否存在引用给定源集之外别名的限定列（裸列名视为本地列，不触发相关）
     *
     * @param list<string> $localAliases
     */
    private static function treeHasOuterRef(Condition $condition, array $localAliases): bool
    {
        if ($condition instanceof ConditionGroup) {
            foreach ($condition->conditions as $child) {
                if (self::treeHasOuterRef($child, $localAliases)) {
                    return true;
                }
            }

            return false;
        }
        if ($condition instanceof Comparison) {
            if (self::isOuterRef($condition->column, $localAliases)) {
                return true;
            }

            return $condition->value instanceof ColumnRef
                && self::isOuterRef($condition->value->column, $localAliases);
        }

        return false;
    }

    /**
     * 限定列是否为外层引用：别名不在本地源集（限定名形如 'alias.col'；裸名恒为本地）
     *
     * @param list<string> $localAliases
     */
    private static function isOuterRef(string $column, array $localAliases): bool
    {
        $pos = strrpos($column, '.');
        if ($pos === false) {
            return false;
        }
        $alias = substr($column, 0, $pos);

        return !in_array($alias, $localAliases, true);
    }

    /**
     * 子查询的源别名集合（基表/派生表 + 各 join 表；别名 = 显式别名 ?: 表名）
     *
     * @return list<string>
     */
    public static function sourceAliases(SelectQuery $query): array
    {
        $aliases = [];
        if ($query->fromSub !== null) {
            $aliases[] = $query->table;
        } else {
            $aliases[] = $query->alias ?? $query->table;
        }
        foreach ($query->joins as $join) {
            $aliases[] = $join->alias ?? $join->table;
        }

        return $aliases;
    }

    /**
     * 把子查询按外层行绑定：where 树中引用外层列的限定列替换为行值常量；
     * 生成的新 SelectQuery 已与当前行无关，可独立执行（嵌套相关由后续 execute 递归处理）
     */
    private function bindOuterRow(SelectQuery $sub, array $outerRow): SelectQuery
    {
        $where = $sub->where;
        if ($where !== null && self::isCorrelated($sub)) {
            $where = $this->bindCondition($where, self::sourceAliases($sub), $outerRow);
        }

        return new SelectQuery(
            $sub->table,
            $sub->alias,
            $sub->columns,
            $sub->joins,
            $where,
            $sub->aggregates,
            $sub->expressions,
            $sub->groupBy,
            $sub->having,
            $sub->orderBy,
            $sub->distinct,
            $sub->limit,
            $sub->offset,
            $sub->unions,
            $sub->fromSub,
            $sub->ctes,
        );
    }

    /**
     * 递归绑定条件树中的外层列引用（子查询条件原样保留，待其自身执行时递归绑定）
     *
     * @param list<string> $localAliases
     */
    private function bindCondition(Condition $condition, array $localAliases, array $outerRow): Condition
    {
        if ($condition instanceof ConditionGroup) {
            $children = [];
            foreach ($condition->conditions as $child) {
                $children[] = $this->bindCondition($child, $localAliases, $outerRow);
            }

            return self::rebuildGroup($condition, $children);
        }
        if ($condition instanceof Comparison) {
            return $this->bindComparison($condition, $localAliases, $outerRow);
        }

        return $condition;
    }

    /**
     * 比较条件绑定：外层列在列侧（外 外 op 内）或值侧（内 op 外）时替换为行值常量；
     * 列侧为外层且值侧为标量 → 直接化简为常量真值
     *
     * @param list<string> $localAliases
     */
    private function bindComparison(Comparison $comparison, array $localAliases, array $outerRow): Condition
    {
        $columnIsOuter = self::isOuterRef($comparison->column, $localAliases);
        $valueIsRef = $comparison->value instanceof ColumnRef;
        $valueIsOuter = $valueIsRef && self::isOuterRef($comparison->value->column, $localAliases);

        if ($valueIsOuter) {
            // 内.col op 外.col → 内.col op <外层值>
            return new Comparison(
                $comparison->column,
                $comparison->operator,
                $outerRow[$comparison->value->column] ?? null,
            );
        }
        if ($columnIsOuter && $valueIsRef) {
            // 外.col op 内.col → 内.col <翻转 op> <外层值>
            return new Comparison(
                $comparison->value->column,
                self::flipOperator($comparison->operator),
                $outerRow[$comparison->column] ?? null,
            );
        }
        if ($columnIsOuter && !$valueIsRef) {
            // 外.col op <标量>：整条件与当前行无关 → 常量真值
            $left = $outerRow[$comparison->column] ?? null;

            return new BooleanConst(ConditionEvaluator::compareValues($left, $comparison->operator, $comparison->value));
        }

        return $comparison;
    }

    /**
     * 运算符换侧翻转（'=' / '!=' / '<>' 对称，不等号对偶翻转）
     */
    private static function flipOperator(string $operator): string
    {
        return match ($operator) {
            '<' => '>',
            '<=' => '>=',
            '>' => '<',
            '>=' => '<=',
            default => $operator,
        };
    }

    /**
     * 非相关路径拒绝相关子查询（写路径/不支持上下文）：抛 QueryException
     */
    private function assertNotCorrelated(SelectQuery $query): void
    {
        if (self::isCorrelated($query)) {
            throw new QueryException('相关子查询需要外层行上下文，仅 SELECT 的 WHERE 支持（写入路径不支持）');
        }
    }
}
