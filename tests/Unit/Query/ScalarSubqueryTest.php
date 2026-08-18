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
 * 标量子查询测试：WHERE 列 运算符 (子查询)——非相关独立执行一次、相关按外层行绑定，
 * 空集 → NULL（行被过滤）
 */
final class ScalarSubqueryTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 30)->notNull();
        });
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id')->notNull();
            $b->decimal('amount', 8, 2)->notNull();
        });

        $this->conn->table('users')->insertMany([
            ['name' => 'alice'],
            ['name' => 'bob'],
            ['name' => 'carol'],
        ]);
        $this->conn->table('orders')->insertMany([
            ['user_id' => 1, 'amount' => 100],
            ['user_id' => 1, 'amount' => 50],
            ['user_id' => 3, 'amount' => 200],
        ]);
    }

    public function testNonCorrelatedScalarComparison(): void
    {
        // amount > 全表平均（116.67）→ 仅 200
        $rows = $this->conn->table('orders as o')
            ->whereScalar('o.amount', '>', $this->conn->table('orders')->select(Agg::avg('amount')))
            ->select('o.id', 'o.amount')
            ->orderBy('o.id')
            ->get()
            ->rows();

        $this->assertSame([['id' => 3, 'amount' => '200.00']], $rows);
    }

    public function testCorrelatedScalarPerRow(): void
    {
        // 每行取其 user 的最大订单额
        $rows = $this->conn->table('orders as o')
            ->whereScalar('o.amount', '=', $this->conn->table('orders as om')
                ->whereColumn('om.user_id', '=', 'o.user_id')
                ->select(Agg::max('om.amount')))
            ->select('o.id', 'o.user_id', 'o.amount')
            ->orderBy('o.id')
            ->get()
            ->rows();

        $this->assertSame(
            [
                ['id' => 1, 'user_id' => 1, 'amount' => '100.00'],
                ['id' => 3, 'user_id' => 3, 'amount' => '200.00'],
            ],
            $rows,
        );
    }

    public function testEmptyScalarYieldsNullAndFilters(): void
    {
        // 子查询空集 → null → amount < NULL 恒 unknown → 无匹配
        $rows = $this->conn->table('orders as o')
            ->whereScalar('o.amount', '<', $this->conn->table('orders')->where('id', 999)->select('amount'))
            ->select('o.id')
            ->get()
            ->rows();

        $this->assertSame([], $rows);
    }

    public function testCorrelatedScalarWithEquals(): void
    {
        // 有订单的用户
        $rows = $this->conn->table('users as u')
            ->whereScalar('u.id', '=', $this->conn->table('orders as o2')
                ->whereColumn('o2.user_id', '=', 'u.id')
                ->select('o2.user_id'))
            ->select('u.name')
            ->orderBy('u.id')
            ->get()
            ->rows();

        $this->assertSame(['alice', 'carol'], array_column($rows, 'name'));
    }

    public function testOrWhereScalar(): void
    {
        // bob 或 有最大额订单的用户
        $rows = $this->conn->table('users as u')
            ->where('u.name', 'bob')
            ->orWhereScalar('u.id', '=', $this->conn->table('orders as o2')
                ->whereColumn('o2.user_id', '=', 'u.id')
                ->select(Agg::max('o2.user_id')))
            ->select('u.name')
            ->orderBy('u.id')
            ->get()
            ->rows();

        $this->assertSame(['alice', 'bob', 'carol'], array_column($rows, 'name'));
    }

    public function testMultiColumnScalarThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('必须输出 1 列');
        $this->conn->table('orders')
            ->whereScalar('amount', '=', $this->conn->table('orders')->select('id', 'amount'))
            ->get();
    }

    public function testScalarInUpdateWhereRejected(): void
    {
        // 相关标量子查询在写路径拒绝
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('相关子查询');
        $this->conn->table('orders as o')
            ->whereScalar('o.amount', '=', $this->conn->table('orders as om')
                ->whereColumn('om.user_id', '=', 'o.user_id')
                ->select(Agg::max('om.amount')))
            ->update(['amount' => 0]);
    }
}
