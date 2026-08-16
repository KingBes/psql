<?php

declare(strict_types=1);

namespace Kingbes\Psql\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Query\Condition\BooleanConst;
use Kingbes\Psql\Query\Condition\Condition;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Query\Condition\ExistsCheck;
use Kingbes\Psql\Query\Condition\InList;
use Kingbes\Psql\Query\Condition\SubqueryIn;
use Kingbes\Psql\Query\SelectQuery;

/**
 * 子查询条件解析器：把条件树中的 SubqueryIn/ExistsCheck 替换为可求值的 InList/BooleanConst
 */
final class SubqueryResolver
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * 深拷贝语义：返回解析后的新条件树（原树不可变，重复解析安全）；
     * 无子查询条件时原样返回同一实例
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
        $rows = $this->executeSubquery($condition->query);
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
        $rows = $this->executeSubquery($condition->query);

        return new BooleanConst($condition->negate ? $rows === [] : $rows !== []);
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
}
