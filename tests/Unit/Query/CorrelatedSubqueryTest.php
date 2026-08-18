<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 相关子查询测试：SELECT WHERE 中引用外层列的 IN/EXISTS（外层行逐行绑定后执行）
 */
final class CorrelatedSubqueryTest extends TestCase
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

    private function names(array $rows): array
    {
        return array_column($rows, 'name');
    }

    public function testCorrelatedExists(): void
    {
        $rows = $this->conn->table('users as u')
            ->whereExists($this->conn->table('orders as o')->whereColumn('o.user_id', '=', 'u.id'))
            ->select('u.name')
            ->orderBy('u.id')
            ->get()
            ->rows();

        $this->assertSame(['alice', 'carol'], $this->names($rows));
    }

    public function testCorrelatedNotExists(): void
    {
        $rows = $this->conn->table('users as u')
            ->whereNotExists($this->conn->table('orders as o')->whereColumn('o.user_id', '=', 'u.id'))
            ->select('u.name')
            ->orderBy('u.id')
            ->get()
            ->rows();

        $this->assertSame(['bob'], $this->names($rows));
    }

    public function testCorrelatedIn(): void
    {
        $rows = $this->conn->table('users as u')
            ->whereIn('u.id', $this->conn->table('orders as o')
                ->whereColumn('o.user_id', '=', 'u.id')
                ->select('o.user_id'))
            ->select('u.name')
            ->orderBy('u.id')
            ->get()
            ->rows();

        $this->assertSame(['alice', 'carol'], $this->names($rows));
    }

    public function testCorrelatedOuterRefOnColumnSide(): void
    {
        // 外层列在列侧：u.id = o.user_id（换侧 + 对称）
        $rows = $this->conn->table('users as u')
            ->whereExists($this->conn->table('orders as o')->whereColumn('u.id', '=', 'o.user_id'))
            ->select('u.name')
            ->orderBy('u.id')
            ->get()
            ->rows();

        $this->assertSame(['alice', 'carol'], $this->names($rows));
    }

    public function testCorrelatedMixedWithPlainCondition(): void
    {
        // 外层 id>1 且存在其大额订单
        $rows = $this->conn->table('users as u')
            ->where('u.id', '>', 1)
            ->whereExists($this->conn->table('orders as o')
                ->whereColumn('o.user_id', '=', 'u.id')
                ->where('o.amount', '>', 60))
            ->select('u.name')
            ->orderBy('u.id')
            ->get()
            ->rows();

        $this->assertSame(['carol'], $this->names($rows));
    }

    public function testCorrelatedInOrGroup(): void
    {
        // 相关子查询与其他条件 OR 组合
        $rows = $this->conn->table('users as u')
            ->where('u.name', 'bob')
            ->orWhereExists($this->conn->table('orders as o')
                ->whereColumn('o.user_id', '=', 'u.id')
                ->where('o.amount', '>', 150))
            ->select('u.name')
            ->orderBy('u.id')
            ->get()
            ->rows();

        $this->assertSame(['bob', 'carol'], $this->names($rows));
    }

    public function testUncorrelatedStillWorks(): void
    {
        $rows = $this->conn->table('users as u')
            ->whereIn('u.id', $this->conn->table('orders')->select('user_id'))
            ->select('u.name')
            ->orderBy('u.id')
            ->get()
            ->rows();

        $this->assertSame(['alice', 'carol'], $this->names($rows));
    }

    public function testCorrelatedInUpdateWhereThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('相关子查询');
        $this->conn->table('users as u')
            ->whereExists($this->conn->table('orders as o')->whereColumn('o.user_id', '=', 'u.id'))
            ->update(['name' => 'x']);
    }
}
