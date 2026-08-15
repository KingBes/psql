<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Agg;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 查询执行器测试：JOIN、WHERE 解析、分组聚合/HAVING、DISTINCT、排序、分页、键冲突
 */
final class ExecutorTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull();
            $b->bigint('dept_id');
        });
        $this->conn->createTable('depts', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('title', 32)->notNull();
        });

        $this->conn->table('users')->insertMany([
            ['name' => 'alice', 'dept_id' => 1],
            ['name' => 'bob', 'dept_id' => 1],
            ['name' => 'carol', 'dept_id' => null],
            ['name' => 'dave', 'dept_id' => 2],
        ]);
        $this->conn->table('depts')->insertMany([
            ['title' => 'eng'],
            ['title' => 'sales'],
            ['title' => 'hr'],
        ]);
    }

    // ---- JOIN ----

    public function testInnerJoin(): void
    {
        $rows = $this->conn->table('users as u')
            ->select('u.id', 'u.name', 'depts.title')
            ->join('depts', 'u.dept_id', '=', 'depts.id')
            ->orderBy('u.id')
            ->get()
            ->rows();

        $this->assertSame(
            [
                ['id' => 1, 'name' => 'alice', 'title' => 'eng'],
                ['id' => 2, 'name' => 'bob', 'title' => 'eng'],
                ['id' => 4, 'name' => 'dave', 'title' => 'sales'],
            ],
            $rows,
        );
    }

    public function testLeftJoinKeepsUnmatchedLeftRow(): void
    {
        $rows = $this->conn->table('users as u')
            ->select('u.name', 'depts.title')
            ->leftJoin('depts', 'u.dept_id', '=', 'depts.id')
            ->orderBy('u.id')
            ->get()
            ->rows();

        $this->assertCount(4, $rows);
        // carol 无匹配 → 右侧列 null
        $this->assertSame(['name' => 'carol', 'title' => null], $rows[2]);
    }

    public function testRightJoinKeepsAllRightRows(): void
    {
        $rows = $this->conn->table('users as u')
            ->select('depts.title', 'u.name')
            ->rightJoin('depts', 'u.dept_id', '=', 'depts.id')
            ->orderBy('depts.id')
            ->get()
            ->rows();

        // 右表 3 行全保留：eng 两匹配、sales 一匹配、hr 零匹配（左侧补 null）
        $this->assertCount(4, $rows);
        $this->assertSame(['title' => 'eng', 'name' => 'alice'], $rows[0]);
        $this->assertSame(['title' => 'eng', 'name' => 'bob'], $rows[1]);
        $this->assertSame(['title' => 'sales', 'name' => 'dave'], $rows[2]);
        $this->assertSame(['title' => 'hr', 'name' => null], $rows[3]);
    }

    public function testJoinMissingTableThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ghost');
        $this->conn->table('users as u')
            ->select('u.name')
            ->join('ghost', 'u.id', '=', 'ghost.id')
            ->get();
    }

    // ---- WHERE ----

    public function testWhereQualifiedColumn(): void
    {
        $rows = $this->conn->table('users as u')
            ->where('u.name', 'alice')
            ->get()
            ->rows();

        $this->assertSame([['id' => 1, 'name' => 'alice', 'dept_id' => 1]], $rows);
    }

    public function testWhereShortColumn(): void
    {
        $rows = $this->conn->table('users')
            ->whereLike('name', 'a%')
            ->get()
            ->rows();

        $this->assertSame(['alice'], array_column($rows, 'name'));
    }

    public function testWhereAmbiguousColumnThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('歧义');
        $this->conn->table('users as u')
            ->select('u.name')
            ->join('depts', 'u.dept_id', '=', 'depts.id')
            ->where('id', 1)
            ->get();
    }

    public function testWhereUnknownColumnThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('nope');
        $this->conn->table('users')->where('nope', 1)->get();
    }

    // ---- 分组聚合 / HAVING ----

    public function testGroupByWithCountAndHaving(): void
    {
        $rows = $this->conn->table('users')
            ->select('dept_id', Agg::count('*')->as('c'))
            ->groupBy('dept_id')
            ->having('c', '>=', 2)
            ->get()
            ->rows();

        $this->assertSame([['dept_id' => 1, 'c' => 2]], $rows);
    }

    public function testGroupByKeepsFirstRowOfGroupForPlainColumns(): void
    {
        $rows = $this->conn->table('users')
            ->select('dept_id', 'name', Agg::count('*')->as('c'))
            ->groupBy('dept_id')
            ->orderBy('dept_id')
            ->get()
            ->rows();

        // null 组在最前（排序 null 最小）；普通列取组内首行值
        $this->assertSame('carol', $rows[0]['name']);
        $this->assertSame('alice', $rows[1]['name']);
        $this->assertSame(2, $rows[1]['c']);
        $this->assertSame('dave', $rows[2]['name']);
        $this->assertSame(1, $rows[2]['c']);
    }

    public function testHavingUnknownAliasThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('x');
        $this->conn->table('users')
            ->groupBy('dept_id')
            ->having('x', '>=', 1)
            ->get();
    }

    public function testAggregatesWithoutGroupBy(): void
    {
        $rows = $this->conn->table('users')
            ->select(Agg::count('*')->as('c'), Agg::sum('dept_id')->as('s'), Agg::avg('dept_id')->as('a'))
            ->get()
            ->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(4, $rows[0]['c']);
        $this->assertSame(4, $rows[0]['s']);
        $this->assertEqualsWithDelta(4 / 3, $rows[0]['a'], 1e-9);
    }

    public function testAggregatesOnEmptyTable(): void
    {
        $this->conn->createTable('empty_t', static function (Blueprint $b): void {
            $b->id();
            $b->int('v');
        });

        $rows = $this->conn->table('empty_t')
            ->select(Agg::count('*')->as('c'), Agg::sum('v')->as('s'), Agg::min('v')->as('m'), Agg::max('v')->as('x'))
            ->get()
            ->rows();

        // 空组语义：COUNT→0，SUM/AVG/MIN/MAX→null
        $this->assertSame([['c' => 0, 's' => null, 'm' => null, 'x' => null]], $rows);
    }

    public function testCountColumnSkipsNull(): void
    {
        $rows = $this->conn->table('users')
            ->select(Agg::count('dept_id')->as('c'))
            ->get()
            ->rows();

        $this->assertSame(3, $rows[0]['c']);
    }

    public function testMinMaxStringComparison(): void
    {
        $rows = $this->conn->table('users')
            ->select(Agg::min('name')->as('lo'), Agg::max('name')->as('hi'))
            ->get()
            ->rows();

        $this->assertSame('alice', $rows[0]['lo']);
        $this->assertSame('dave', $rows[0]['hi']);
    }

    public function testSumNonNumericThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('name');
        $this->conn->table('users')->select(Agg::sum('name'))->get();
    }

    // ---- 投影 / DISTINCT ----

    public function testSelectAllColumnsWithoutSelect(): void
    {
        $rows = $this->conn->table('users')->where('id', 1)->get()->rows();

        $this->assertSame([['id' => 1, 'name' => 'alice', 'dept_id' => 1]], $rows);
    }

    public function testSelectOutputKeyConflictThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('冲突');
        $this->conn->table('users as u')
            ->select('u.id', 'depts.id')
            ->join('depts', 'u.dept_id', '=', 'depts.id')
            ->get();
    }

    public function testDistinct(): void
    {
        $rows = $this->conn->table('users')
            ->select('dept_id')
            ->distinct()
            ->orderBy('dept_id')
            ->get()
            ->rows();

        $this->assertSame([null, 1, 2], array_column($rows, 'dept_id'));
    }

    // ---- 排序 / 分页 ----

    public function testOrderByMultipleColumns(): void
    {
        // dept_id 升序（null 最小），组内 id 降序
        $rows = $this->conn->table('users')
            ->select('name')
            ->orderBy('dept_id')
            ->orderBy('id', 'DESC')
            ->get()
            ->rows();

        $this->assertSame(['carol', 'bob', 'alice', 'dave'], array_column($rows, 'name'));
    }

    public function testOrderBySourceRowFallback(): void
    {
        // id 未投影 → 回退源限定行解析
        $rows = $this->conn->table('users')
            ->select('name')
            ->orderBy('id', 'DESC')
            ->get()
            ->rows();

        $this->assertSame(['dave', 'carol', 'bob', 'alice'], array_column($rows, 'name'));
    }

    public function testOrderByAggregateAlias(): void
    {
        $rows = $this->conn->table('users')
            ->select('dept_id', Agg::count('*')->as('c'))
            ->groupBy('dept_id')
            ->orderBy('c', 'DESC')
            ->get()
            ->rows();

        $this->assertSame(2, $rows[0]['c']);
        $this->assertSame(1, $rows[0]['dept_id']);
    }

    public function testLimitAndOffset(): void
    {
        $rows = $this->conn->table('users')
            ->orderBy('id')
            ->limit(2)
            ->offset(1)
            ->get()
            ->rows();

        $this->assertSame([2, 3], array_column($rows, 'id'));

        // limit 0 → 空结果
        $this->assertSame([], $this->conn->table('users')->limit(0)->get()->rows());
    }

    public function testBaseTableMissingThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ghost');
        $this->conn->table('ghost')->get();
    }
}
