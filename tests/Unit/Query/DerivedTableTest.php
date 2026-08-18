<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Agg;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * FROM 派生表测试：子查询作为 FROM 源（别名列引用、聚合、JOIN、嵌套、防呆、EXPLAIN）
 */
final class DerivedTableTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull();
            $b->tinyint('vip')->notNull()->default(0);
            $b->tinyint('age')->notNull()->default(0);
        });
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id')->notNull();
            $b->int('amount')->notNull();
        });

        $this->conn->table('users')->insertMany([
            ['name' => 'alice', 'vip' => 1, 'age' => 20],
            ['name' => 'bob', 'vip' => 0, 'age' => 30],
            ['name' => 'carol', 'vip' => 1, 'age' => 40],
            ['name' => 'dave', 'vip' => 0, 'age' => 50],
        ]);
        $this->conn->table('orders')->insertMany([
            ['user_id' => 1, 'amount' => 100],
            ['user_id' => 2, 'amount' => 200],
            ['user_id' => 3, 'amount' => 300],
            ['user_id' => 4, 'amount' => 400],
            ['user_id' => 99, 'amount' => 500],
        ]);
    }

    public function testDerivedTableBasicQuery(): void
    {
        $sub = $this->conn->table('users')->select('id', 'name')->where('vip', 1);

        $rows = $this->conn->fromSub($sub, 't')
            ->select('t.id')
            ->where('t.name', 'alice')
            ->get()
            ->rows();

        $this->assertSame([['id' => 1]], $rows);
    }

    public function testDerivedTableAllColumns(): void
    {
        $sub = $this->conn->table('users')->select('id', 'name')->where('vip', 1);

        $rows = $this->conn->fromSub($sub, 't')->get()->rows();

        $this->assertSame([
            ['id' => 1, 'name' => 'alice'],
            ['id' => 3, 'name' => 'carol'],
        ], $rows);
    }

    public function testDerivedTableOrderByLimit(): void
    {
        $sub = $this->conn->table('users')->select('id', 'age');

        $rows = $this->conn->fromSub($sub, 't')
            ->select('t.id')
            ->orderBy('t.age', 'DESC')
            ->limit(2)
            ->get()
            ->rows();

        $this->assertSame([['id' => 4], ['id' => 3]], $rows);
    }

    public function testDerivedTableGroupByAggregate(): void
    {
        $sub = $this->conn->table('users')->select('vip', 'age');

        $rows = $this->conn->fromSub($sub, 't')
            ->select('t.vip', Agg::sum('t.age')->as('total'))
            ->groupBy('t.vip')
            ->orderBy('t.vip')
            ->get()
            ->rows();

        $this->assertSame([
            ['vip' => 0, 'total' => 80],
            ['vip' => 1, 'total' => 60],
        ], $rows);
    }

    public function testDerivedTableJoinPhysicalTable(): void
    {
        $sub = $this->conn->table('users')->select('id', 'name');

        $rows = $this->conn->fromSub($sub, 'u')
            ->join('orders', 'u.id', '=', 'orders.user_id')
            ->select('u.name', 'orders.amount')
            ->where('orders.amount', '>', 100)
            ->orderBy('orders.amount')
            ->get()
            ->rows();

        $this->assertSame([
            ['name' => 'bob', 'amount' => 200],
            ['name' => 'carol', 'amount' => 300],
            ['name' => 'dave', 'amount' => 400],
        ], $rows);
    }

    public function testNestedDerivedTable(): void
    {
        $inner = $this->conn->table('users')->select('id', 'age')->where('vip', 1);
        $sub = $this->conn->fromSub($inner, 'm')->select('m.id', 'm.age')->where('m.age', '>', 25);

        $rows = $this->conn->fromSub($sub, 't')->select('t.id')->get()->rows();

        $this->assertSame([['id' => 3]], $rows);
    }

    public function testDerivedTableWhereInSubqueryInside(): void
    {
        // 派生表子查询内部仍支持子查询条件（递归执行）
        $orderIds = $this->conn->table('orders')->select('user_id')->where('amount', '>', 250);
        $sub = $this->conn->table('users')->select('id', 'name')->whereIn('id', $orderIds);

        $rows = $this->conn->fromSub($sub, 't')
            ->select('t.name')
            ->orderBy('t.id')
            ->get()
            ->rows();

        $this->assertSame([['name' => 'carol'], ['name' => 'dave']], $rows);
    }

    public function testDerivedTableEmptySource(): void
    {
        $sub = $this->conn->table('users')->select('id')->where('id', 999);

        // 空源无列信息：全列查询（不引用具体列）返回空结果集
        $rows = $this->conn->fromSub($sub, 't')->get()->rows();

        $this->assertSame([], $rows);
    }

    public function testDerivedTableUnknownColumnThrows(): void
    {
        $sub = $this->conn->table('users')->select('id', 'name');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('未知列');

        $this->conn->fromSub($sub, 't')->where('t.nope', 1)->get();
    }

    public function testDerivedTableAmbiguousBareColumnThrows(): void
    {
        $sub = $this->conn->table('users')->select('id', 'name');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('歧义');

        // 裸列名 name 在派生表 t 和物理表 users 中都存在 → 歧义
        $this->conn->fromSub($sub, 't')
            ->join('users as u', 't.id', '=', 'u.id')
            ->select('name')
            ->get();
    }

    public function testDerivedTableUpdateThrows(): void
    {
        $sub = $this->conn->table('users')->select('id', 'name');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('派生表');

        $this->conn->fromSub($sub, 't')->where('t.id', 1)->update(['name' => 'x']);
    }

    public function testDerivedTableDeleteThrows(): void
    {
        $sub = $this->conn->table('users')->select('id', 'name');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('派生表');

        $this->conn->fromSub($sub, 't')->where('t.id', 1)->delete();
    }

    public function testExplainReportsDerivedStep(): void
    {
        $sub = $this->conn->table('users')->select('id', 'name')->where('vip', 1);

        $steps = $this->conn->fromSub($sub, 't')
            ->select('t.id')
            ->where('t.name', 'alice')
            ->explain();

        $this->assertSame('DERIVED', $steps[0]['step']);
        $this->assertSame('t', $steps[0]['table']);
    }

    public function testViewRejectsDerivedTable(): void
    {
        $sub = $this->conn->table('users')->select('id', 'name');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FROM 子查询');

        $this->conn->createView('v', $this->conn->fromSub($sub, 't'));
    }
}
