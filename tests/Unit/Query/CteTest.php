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
 * WITH CTE 测试：非递归命名子查询——FROM 位 fromCte、JOIN 位 joinCte 系列、
 * 后序 CTE 引用前序 CTE、多次引用、每次引用独立执行（非物化）
 */
final class CteTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 30)->notNull();
            $b->tinyint('active')->notNull()->default(0);
        });
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id')->notNull();
            $b->decimal('amount', 8, 2)->notNull();
        });

        $this->conn->table('users')->insertMany([
            ['name' => 'alice', 'active' => 1],
            ['name' => 'bob', 'active' => 0],
            ['name' => 'carol', 'active' => 1],
        ]);
        $this->conn->table('orders')->insertMany([
            ['user_id' => 1, 'amount' => 100],
            ['user_id' => 1, 'amount' => 50],
            ['user_id' => 3, 'amount' => 200],
        ]);
    }

    public function testFromPositionCte(): void
    {
        $rows = $this->conn->with([
            'active_users' => $this->conn->table('users')->where('active', 1),
        ])
            ->fromCte('active_users', 'au')
            ->select('au.name')
            ->orderBy('au.id')
            ->get()
            ->rows();

        $this->assertSame([['name' => 'alice'], ['name' => 'carol']], $rows);
    }

    public function testJoinCteWithAggregate(): void
    {
        $rows = $this->conn->table('users as u')
            ->with([
                'order_sums' => $this->conn->table('orders')
                    ->select('user_id', Agg::sum('amount')->as('total'))
                    ->groupBy('user_id'),
            ])
            ->joinCte('order_sums', 'u.id', '=', 's.user_id', 's')
            ->select('u.name', 's.total')
            ->orderBy('u.id')
            ->get()
            ->rows();

        $this->assertSame(
            [
                ['name' => 'alice', 'total' => 150.0],
                ['name' => 'carol', 'total' => 200.0],
            ],
            $rows,
        );
    }

    public function testCteReferencingPriorCte(): void
    {
        $rows = $this->conn->with([
            'active' => $this->conn->table('users')->where('active', 1),
            'no_carol' => $this->conn->fromCte('active', 'a')->where('a.name', '<>', 'carol'),
        ])
            ->fromCte('no_carol', 'w')
            ->join('orders as o', 'w.id', '=', 'o.user_id')
            ->select('w.name', 'o.amount')
            ->orderBy('o.id')
            ->get()
            ->rows();

        $this->assertSame(
            [
                ['name' => 'alice', 'amount' => '100.00'],
                ['name' => 'alice', 'amount' => '50.00'],
            ],
            $rows,
        );
    }

    public function testLeftJoinCte(): void
    {
        $rows = $this->conn->table('users as u')
            ->with([
                'big' => $this->conn->table('orders')->where('amount', '>', 60)->select('user_id'),
            ])
            ->leftJoinCte('big', 'u.id', '=', 'b.user_id', 'b')
            ->select('u.name', 'b.user_id')
            ->orderBy('u.id')
            ->get()
            ->rows();

        $this->assertSame(
            [
                ['name' => 'alice', 'user_id' => 1],
                ['name' => 'bob', 'user_id' => null],
                ['name' => 'carol', 'user_id' => 3],
            ],
            $rows,
        );
    }

    public function testSameCteReferencedTwice(): void
    {
        // 同一 CTE 作两个 JOIN 源（o2/o3 各自独立执行）
        $rows = $this->conn->table('orders as o1')
            ->with([
                'o' => $this->conn->table('orders')->select(),
            ])
            ->joinCte('o', 'o1.id', '=', 'o2.id', 'o2')
            ->joinCte('o', 'o2.user_id', '=', 'o3.user_id', 'o3')
            ->where('o3.id', 2)
            ->select('o1.id')
            ->orderBy('o1.id')
            ->get()
            ->rows();

        // o3=订单2（user1）→ o2 需同 user（1、2）→ o1.id=o2.id → o1 ∈ {1,2}
        $this->assertSame([['id' => 1], ['id' => 2]], $rows);
    }

    public function testCteWithOwnOrderLimit(): void
    {
        // CTE 定义自带 orderBy/limit，独立完整执行
        $rows = $this->conn->with([
            'top2' => $this->conn->table('orders')->orderBy('amount', 'DESC')->limit(2),
        ])
            ->fromCte('top2', 't')
            ->select('t.id', 't.amount')
            ->orderBy('t.id')
            ->get()
            ->rows();

        $this->assertSame(
            [
                ['id' => 1, 'amount' => '100.00'],
                ['id' => 3, 'amount' => '200.00'],
            ],
            $rows,
        );
    }

    public function testWithOnTableEntry(): void
    {
        $rows = $this->conn->table('users as u')
            ->with(['s' => $this->conn->table('orders')->select('user_id')])
            ->joinCte('s', 'u.id', '=', 's.user_id')
            ->select('u.name')
            ->orderBy('u.id')
            ->get()
            ->rows();

        // 非聚合 CTE：alice 命中两条订单，carol 命中一条
        $this->assertSame(
            [
                ['name' => 'alice'],
                ['name' => 'alice'],
                ['name' => 'carol'],
            ],
            $rows,
        );
    }

    public function testUnknownCteThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('未知 CTE: ghost');
        $this->conn->with([])->fromCte('ghost', 'g')->get();
    }

    public function testInvalidCteNameThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('非法 CTE 名');
        $this->conn->with(['bad name' => $this->conn->table('users')]);
    }

    public function testExplainReportsCteJoinAsDerived(): void
    {
        $plan = $this->conn->table('users as u')
            ->with(['s' => $this->conn->table('orders')->select('user_id')])
            ->joinCte('s', 'u.id', '=', 's.user_id')
            ->explain();

        $steps = array_map(static fn (array $step): string => $step['step'] . ' ' . ($step['type'] ?? ''), $plan);
        $this->assertContains('JOIN DERIVED', $steps);
    }
}
