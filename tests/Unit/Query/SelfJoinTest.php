<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Agg;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Query\Func;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 自连接测试：同一表以不同别名多次出现（INNER/LEFT/joinOn/USING），
 * 列引用按 '别名.列名' 消歧，输出同名列用 Func::col(...)->as() 区分
 */
final class SelfJoinTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('employees', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 30)->notNull();
            $b->bigint('manager_id');
        });
        $this->conn->table('employees')->insertMany([
            ['name' => 'alice', 'manager_id' => null],
            ['name' => 'bob', 'manager_id' => 1],
            ['name' => 'carol', 'manager_id' => 1],
            ['name' => 'dave', 'manager_id' => 2],
        ]);
    }

    public function testInnerSelfJoinWithAliasedColumns(): void
    {
        $rows = $this->conn->table('employees as e')
            ->join('employees as m', 'e.manager_id', '=', 'm.id')
            ->select(Func::col('e.name')->as('emp'), Func::col('m.name')->as('mgr'))
            ->orderBy('e.id')
            ->get()
            ->rows();

        $this->assertSame(
            [
                ['emp' => 'bob', 'mgr' => 'alice'],
                ['emp' => 'carol', 'mgr' => 'alice'],
                ['emp' => 'dave', 'mgr' => 'bob'],
            ],
            $rows,
        );
    }

    public function testLeftSelfJoinKeepsUnmatched(): void
    {
        // 无下属的经理（alice、bob 出现在右侧但左侧无匹配）也保留
        $rows = $this->conn->table('employees as m')
            ->leftJoin('employees as e', 'm.id', '=', 'e.manager_id')
            ->select(Func::col('m.name')->as('mgr'), Func::col('e.name')->as('sub'))
            ->orderBy('m.id')
            ->get()
            ->rows();

        $this->assertSame(
            [
                ['mgr' => 'alice', 'sub' => 'bob'],
                ['mgr' => 'alice', 'sub' => 'carol'],
                ['mgr' => 'bob', 'sub' => 'dave'],
                ['mgr' => 'carol', 'sub' => null],
                ['mgr' => 'dave', 'sub' => null],
            ],
            $rows,
        );
    }

    public function testSelfJoinWithWhereOnJoinedSide(): void
    {
        $rows = $this->conn->table('employees as e')
            ->join('employees as m', 'e.manager_id', '=', 'm.id')
            ->where('m.name', 'alice')
            ->select('e.name', 'm.id')
            ->orderBy('e.id')
            ->get()
            ->rows();

        $this->assertSame(
            [
                ['name' => 'bob', 'id' => 1],
                ['name' => 'carol', 'id' => 1],
            ],
            $rows,
        );
    }

    public function testSelfJoinOnConditionGroup(): void
    {
        $on = new ConditionGroup();
        $on->whereColumn('e.manager_id', '=', 'm.id');
        $on->where('m.name', '<>', 'alice');

        $rows = $this->conn->table('employees as e')
            ->joinOn('employees as m', $on)
            ->select(Func::col('e.name')->as('emp'), Func::col('m.name')->as('mgr'))
            ->get()
            ->rows();

        $this->assertSame([['emp' => 'dave', 'mgr' => 'bob']], $rows);
    }

    public function testSelfJoinAggregatePerManager(): void
    {
        $rows = $this->conn->table('employees as e')
            ->join('employees as m', 'e.manager_id', '=', 'm.id')
            ->select('m.name', Agg::count('e.id')->as('cnt'))
            ->groupBy('m.name')
            ->orderBy('m.name')
            ->get()
            ->rows();

        $this->assertSame(
            [
                ['name' => 'alice', 'cnt' => 2],
                ['name' => 'bob', 'cnt' => 1],
            ],
            $rows,
        );
    }

    public function testSelfJoinWithoutAliasesThrowsDuplicateAlias(): void
    {
        // 无别名自连接：两侧别名均默认表名 → 重复别名
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('表别名重复');
        $this->conn->table('employees')->join('employees', 'employees.id', '=', 'employees.manager_id')->get();
    }

    public function testColumnRefAlias(): void
    {
        $aliased = Func::col('name')->as('n');
        $this->assertSame('n', $aliased->alias());
        $this->assertSame('name', $aliased->outputName());
        // 不可变：原实例仍无别名
        $this->assertNull(Func::col('name')->alias());
    }
}
