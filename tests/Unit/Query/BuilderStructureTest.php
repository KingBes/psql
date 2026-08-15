<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Query\AggregateExpression;
use Kingbes\Psql\Query\Condition\Between;
use Kingbes\Psql\Query\Condition\Comparison;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Query\Condition\InList;
use Kingbes\Psql\Query\Condition\LikeCondition;
use Kingbes\Psql\Query\Condition\NullCheck;
use Kingbes\Psql\Query\Agg;
use Kingbes\Psql\Query\HavingClause;
use Kingbes\Psql\Query\JoinClause;
use Kingbes\Psql\Query\SelectBuilder;
use Kingbes\Psql\Query\SelectQuery;
use Kingbes\Psql\Query\Table;
use PHPUnit\Framework\TestCase;

/**
 * SelectBuilder/Table/DTO 构建结构测试（不触发连接与执行器加载）
 */
final class BuilderStructureTest extends TestCase
{
    private function builder(): SelectBuilder
    {
        return new SelectBuilder(null, 'users', 'u');
    }

    public function testFreshBuilderDefaults(): void
    {
        $query = $this->builder()->toQuery();

        $this->assertSame('users', $query->table);
        $this->assertSame('u', $query->alias);
        $this->assertSame([], $query->columns);
        $this->assertSame([], $query->joins);
        $this->assertNull($query->where);
        $this->assertSame([], $query->aggregates);
        $this->assertSame([], $query->groupBy);
        $this->assertNull($query->having);
        $this->assertSame([], $query->orderBy);
        $this->assertFalse($query->distinct);
        $this->assertNull($query->limit);
        $this->assertNull($query->offset);
    }

    public function testFullChainBuildsQueryDto(): void
    {
        $query = $this->builder()
            ->select('id', 'name', Agg::count('*')->as('cnt'), 'email')
            ->join('orders', 'u.id', '=', 'o.user_id')
            ->leftJoin('profiles', 'u.id', '=', 'p.user_id')
            ->rightJoin('logs', 'u.id', '=', 'l.user_id')
            ->where('u.age', '>', 18)
            ->orWhere('u.vip', 1)
            ->whereIn('u.id', [1, 2, 3])
            ->whereNotIn('u.status', [9])
            ->whereBetween('u.age', 10, 20)
            ->whereNull('p.deleted_at')
            ->whereNotNull('p.activated_at')
            ->whereLike('u.name', 'A%')
            ->groupBy('u.city', 'u.country')
            ->having('cnt', '>', 0)
            ->orderBy('u.age', 'desc')
            ->orderByDesc('u.id')
            ->limit(10)
            ->offset(5)
            ->distinct()
            ->toQuery();

        $this->assertInstanceOf(SelectQuery::class, $query);

        // 输出列与聚合
        $this->assertSame(['id', 'name', 'email'], $query->columns);
        $this->assertCount(1, $query->aggregates);
        $this->assertSame('COUNT', $query->aggregates[0]->function);
        $this->assertSame('*', $query->aggregates[0]->column);
        $this->assertSame('cnt', $query->aggregates[0]->alias);

        // 连接
        $this->assertCount(3, $query->joins);
        [$join, $left, $right] = $query->joins;
        $this->assertSame('INNER', $join->type);
        $this->assertSame('orders', $join->table);
        $this->assertNull($join->alias);
        $this->assertSame('u.id', $join->leftColumn);
        $this->assertSame('=', $join->operator);
        $this->assertSame('o.user_id', $join->rightColumn);
        $this->assertSame('LEFT', $left->type);
        $this->assertSame('profiles', $left->table);
        $this->assertSame('RIGHT', $right->type);
        $this->assertSame('logs', $right->table);

        // where 结构
        $this->assertInstanceOf(ConditionGroup::class, $query->where);
        $where = $query->where;
        $this->assertCount(8, $where->conditions);
        $this->assertSame(['OR', 'AND', 'AND', 'AND', 'AND', 'AND', 'AND'], $where->connectors);

        $first = $where->conditions[0];
        $this->assertInstanceOf(Comparison::class, $first);
        $this->assertSame('u.age', $first->column);
        $this->assertSame('>', $first->operator);
        $this->assertSame(18, $first->value);

        $second = $where->conditions[1];
        $this->assertInstanceOf(Comparison::class, $second);
        $this->assertSame('=', $second->operator);
        $this->assertSame(1, $second->value);

        $this->assertInstanceOf(InList::class, $where->conditions[2]);
        $this->assertSame([1, 2, 3], $where->conditions[2]->values);
        $this->assertFalse($where->conditions[2]->negate);
        $this->assertInstanceOf(InList::class, $where->conditions[3]);
        $this->assertTrue($where->conditions[3]->negate);
        $this->assertInstanceOf(Between::class, $where->conditions[4]);
        $this->assertSame(10, $where->conditions[4]->min);
        $this->assertSame(20, $where->conditions[4]->max);
        $this->assertFalse($where->conditions[4]->negate);
        $this->assertInstanceOf(NullCheck::class, $where->conditions[5]);
        $this->assertFalse($where->conditions[5]->negate);
        $this->assertInstanceOf(NullCheck::class, $where->conditions[6]);
        $this->assertTrue($where->conditions[6]->negate);
        $this->assertInstanceOf(LikeCondition::class, $where->conditions[7]);
        $this->assertSame('A%', $where->conditions[7]->pattern);

        // 分组/HAVING/排序/分页
        $this->assertSame(['u.city', 'u.country'], $query->groupBy);
        $this->assertNotNull($query->having);
        $this->assertSame('cnt', $query->having->alias);
        $this->assertSame('>', $query->having->operator);
        $this->assertSame(0, $query->having->value);
        $this->assertSame(
            [
                ['column' => 'u.age', 'direction' => 'DESC'],
                ['column' => 'u.id', 'direction' => 'DESC'],
            ],
            $query->orderBy,
        );
        $this->assertTrue($query->distinct);
        $this->assertSame(10, $query->limit);
        $this->assertSame(5, $query->offset);
    }

    public function testWhereDefaultsToEquality(): void
    {
        $query = $this->builder()->where('a', 5)->toQuery();

        $this->assertInstanceOf(ConditionGroup::class, $query->where);
        $condition = $query->where->conditions[0];
        $this->assertInstanceOf(Comparison::class, $condition);
        $this->assertSame('=', $condition->operator);
        $this->assertSame(5, $condition->value);
    }

    public function testSelectAppendsAcrossCalls(): void
    {
        $query = $this->builder()
            ->select('a')
            ->select('b')
            ->select(Agg::sum('x'), 'c')
            ->toQuery();

        $this->assertSame(['a', 'b', 'c'], $query->columns);
        $this->assertCount(1, $query->aggregates);
        $this->assertSame('SUM', $query->aggregates[0]->function);
    }

    public function testHavingOverwrites(): void
    {
        $query = $this->builder()
            ->having('cnt', '>', 1)
            ->having('cnt', '<=', 2)
            ->toQuery();

        $this->assertNotNull($query->having);
        $this->assertSame('<=', $query->having->operator);
        $this->assertSame(2, $query->having->value);
    }

    public function testDirectionCaseInsensitiveAndNormalized(): void
    {
        $query = $this->builder()
            ->orderBy('a', 'desc')
            ->orderBy('b', 'Asc')
            ->toQuery();

        $this->assertSame(
            [
                ['column' => 'a', 'direction' => 'DESC'],
                ['column' => 'b', 'direction' => 'ASC'],
            ],
            $query->orderBy,
        );
    }

    public function testZeroLimitAndOffsetAllowed(): void
    {
        $query = $this->builder()->limit(0)->offset(0)->toQuery();

        $this->assertSame(0, $query->limit);
        $this->assertSame(0, $query->offset);
    }

    public function testInvalidComparisonOperatorThrows(): void
    {
        $this->expectException(QueryException::class);

        $this->builder()->where('a', 'LIKE', 'x');
    }

    public function testInvalidConditionGroupOperatorThrows(): void
    {
        $this->expectException(QueryException::class);

        (new ConditionGroup())->where('a', 'BETWEEN', 1);
    }

    public function testConditionGroupWhereArityMisuseThrows(): void
    {
        $this->expectException(QueryException::class);

        (new ConditionGroup())->where('a');
    }

    public function testInvalidDirectionThrows(): void
    {
        $this->expectException(QueryException::class);

        $this->builder()->orderBy('a', 'RANDOM');
    }

    public function testNegativeLimitThrows(): void
    {
        $this->expectException(QueryException::class);

        $this->builder()->limit(-1);
    }

    public function testNegativeOffsetThrows(): void
    {
        $this->expectException(QueryException::class);

        $this->builder()->offset(-5);
    }

    public function testConditionGroupAddInvalidConnectorThrows(): void
    {
        $this->expectException(QueryException::class);

        (new ConditionGroup())->add(new Comparison('a', '=', 1), 'XOR');
    }

    public function testConditionGroupAddValidConnectorCounting(): void
    {
        $group = new ConditionGroup();
        $group->add(new Comparison('a', '=', 1));
        $group->add(new Comparison('b', '=', 2), 'OR');

        $this->assertCount(2, $group->conditions);
        $this->assertSame(['OR'], $group->connectors);
    }

    public function testInvalidComparisonConstructionThrows(): void
    {
        $this->expectException(QueryException::class);

        new Comparison('a', '~', 1);
    }

    public function testInvalidJoinTypeThrows(): void
    {
        $this->expectException(QueryException::class);

        new JoinClause('OUTER', 't', null, 'a', '=', 'b');
    }

    public function testInvalidJoinOperatorThrows(): void
    {
        $this->expectException(QueryException::class);

        new JoinClause('INNER', 't', null, 'a', 'LIKE', 'b');
    }

    public function testInvalidHavingOperatorThrows(): void
    {
        $this->expectException(QueryException::class);

        new HavingClause('cnt', '~', 1);
    }

    public function testTableRejectsInvalidName(): void
    {
        $this->expectException(QueryException::class);

        new Table(null, 'bad-name');
    }

    public function testTableRejectsNameStartingWithDigit(): void
    {
        $this->expectException(QueryException::class);

        new Table(null, '1users');
    }

    public function testTableRejectsInvalidAlias(): void
    {
        $this->expectException(QueryException::class);

        new Table(null, 'users', 'u!');
    }

    public function testTableAcceptsValidNameAndAlias(): void
    {
        $table = new Table(null, '_tmp1', 't1');

        $this->assertInstanceOf(Table::class, $table);
    }

    public function testTableDelegationBuildsQuery(): void
    {
        $table = new Table(null, 'users', 'u');

        $query = $table->select('id')
            ->where('id', 1)
            ->orderBy('id')
            ->limit(3)
            ->toQuery();

        $this->assertSame('users', $query->table);
        $this->assertSame('u', $query->alias);
        $this->assertSame(['id'], $query->columns);
        $this->assertInstanceOf(ConditionGroup::class, $query->where);
        $this->assertSame(3, $query->limit);

        $query2 = $table->whereIn('id', [1, 2])->distinct()->toQuery();
        $this->assertTrue($query2->distinct);
        $this->assertInstanceOf(InList::class, $query2->where->conditions[0]);
    }

    public function testAggregateExpressionOutputNames(): void
    {
        $this->assertSame('COUNT(*)', (new AggregateExpression('COUNT', '*'))->outputName());
        $this->assertSame('SUM(salary)', Agg::sum('salary')->outputName());
        $this->assertSame('AVG(score)', Agg::avg('score')->outputName());
        $this->assertSame('MIN(age)', Agg::min('age')->outputName());
        $this->assertSame('MAX(age)', Agg::max('age')->outputName());
        $this->assertSame('COUNT(*)', Agg::count()->outputName());
    }

    public function testAggregateExpressionAsReturnsNewInstance(): void
    {
        $original = Agg::max('age');
        $aliased = $original->as('m');

        $this->assertNotSame($original, $aliased);
        $this->assertSame('MAX(age)', $original->outputName());
        $this->assertSame('m', $aliased->outputName());
        $this->assertNull($original->alias);
    }

    public function testInvalidAggregateFunctionThrows(): void
    {
        $this->expectException(QueryException::class);

        new AggregateExpression('MEDIAN', 'x');
    }

    public function testWhereGroupAttachesGroupDirectlyWhenNoWhere(): void
    {
        $group = (new ConditionGroup())->where('age', '<', 18)->orWhere('vip', 1);

        $query = $this->builder()->whereGroup($group)->toQuery();

        $this->assertSame($group, $query->where);
    }

    public function testWhereGroupMergesExistingWhereIntoOuterGroup(): void
    {
        $group = (new ConditionGroup())->where('age', '<', 18)->orWhere('vip', 1);

        $query = $this->builder()
            ->where('status', 'active')
            ->whereGroup($group)
            ->toQuery();

        $outer = $query->where;
        $this->assertInstanceOf(ConditionGroup::class, $outer);
        $this->assertCount(2, $outer->conditions);
        $this->assertInstanceOf(ConditionGroup::class, $outer->conditions[0]);
        $this->assertSame($group, $outer->conditions[1]);
        $this->assertSame(['AND'], $outer->connectors);
    }
}
