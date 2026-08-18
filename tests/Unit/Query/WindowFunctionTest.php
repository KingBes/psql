<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Agg;
use Kingbes\Psql\Query\Func;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 窗口函数测试：ROW_NUMBER/RANK/DENSE_RANK + 聚合窗口（COUNT/SUM/AVG/MIN/MAX）
 * OVER (PARTITION BY ... ORDER BY ...)
 */
final class WindowFunctionTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('employees', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 30)->notNull();
            $b->varchar('dept', 10)->notNull();
            $b->decimal('salary', 8, 2)->notNull();
        });
        $this->conn->table('employees')->insertMany([
            ['name' => 'alice', 'dept' => 'eng', 'salary' => 100],
            ['name' => 'bob', 'dept' => 'eng', 'salary' => 200],
            ['name' => 'carol', 'dept' => 'eng', 'salary' => 200],
            ['name' => 'dave', 'dept' => 'sales', 'salary' => 150],
            ['name' => 'eve', 'dept' => 'sales', 'salary' => 80],
        ]);
    }

    public function testRowNumberPerPartition(): void
    {
        $rows = $this->conn->table('employees as e')
            ->select('e.name', 'e.dept', 'e.salary',
                Func::rowNumber()->partitionBy('e.dept')->orderBy('e.salary', 'DESC')->as('rn'))
            ->orderBy('e.dept')
            ->orderBy('rn')
            ->get()
            ->rows();

        $this->assertSame(
            [
                ['name' => 'bob', 'dept' => 'eng', 'salary' => '200.00', 'rn' => 1],
                ['name' => 'carol', 'dept' => 'eng', 'salary' => '200.00', 'rn' => 2],
                ['name' => 'alice', 'dept' => 'eng', 'salary' => '100.00', 'rn' => 3],
                ['name' => 'dave', 'dept' => 'sales', 'salary' => '150.00', 'rn' => 1],
                ['name' => 'eve', 'dept' => 'sales', 'salary' => '80.00', 'rn' => 2],
            ],
            $rows,
        );
    }

    public function testRankAndDenseRankWithTies(): void
    {
        $rows = $this->conn->table('employees as e')
            ->select('e.name', 'e.dept', 'e.salary',
                Func::rank()->partitionBy('e.dept')->orderBy('e.salary', 'DESC')->as('rk'),
                Func::denseRank()->partitionBy('e.dept')->orderBy('e.salary', 'DESC')->as('dr'))
            ->orderBy('e.dept')
            ->orderBy('e.salary', 'DESC')
            ->get()
            ->rows();

        // eng：bob/carol 并列第 1（RANK=1，DENSE=1）；alice RANK=3（跳档）/ DENSE=2（不跳档）
        $this->assertSame('1', (string) $rows[0]['rk']);
        $this->assertSame('1', (string) $rows[0]['dr']);
        $this->assertSame('1', (string) $rows[1]['rk']);
        $this->assertSame('3', (string) $rows[2]['rk']);
        $this->assertSame('2', (string) $rows[2]['dr']);
        // sales：dave 1、eve 2
        $this->assertSame('1', (string) $rows[3]['rk']);
        $this->assertSame('2', (string) $rows[4]['rk']);
    }

    public function testSumWindowPerPartition(): void
    {
        $rows = $this->conn->table('employees as e')
            ->select('e.name', 'e.dept', 'e.salary',
                Agg::sum('e.salary')->over()->partitionBy('e.dept')->as('dept_total'))
            ->orderBy('e.dept')
            ->orderBy('e.salary')
            ->get()
            ->rows();

        // 整分区聚合：eng 总额 500、sales 总额 230（每行同值）
        foreach ($rows as $row) {
            $this->assertSame($row['dept'] === 'eng' ? 500.0 : 230.0, $row['dept_total']);
        }
    }

    public function testCountAndAvgWindow(): void
    {
        $rows = $this->conn->table('employees as e')
            ->select('e.dept', 'e.salary',
                Agg::count('*')->over()->partitionBy('e.dept')->as('cnt'),
                Agg::avg('e.salary')->over()->partitionBy('e.dept')->as('avg_sal'))
            ->orderBy('e.dept')
            ->orderBy('e.salary')
            ->get()
            ->rows();

        $eng = array_values(array_filter($rows, static fn (array $r): bool => $r['dept'] === 'eng'));
        $this->assertSame(3, $eng[0]['cnt']);
        $this->assertSame(500.0 / 3, $eng[0]['avg_sal']);
        $sales = array_values(array_filter($rows, static fn (array $r): bool => $r['dept'] === 'sales'));
        $this->assertSame(2, $sales[0]['cnt']);
        $this->assertSame(115.0, $sales[0]['avg_sal']);
    }

    public function testOrderByWindowAlias(): void
    {
        $rows = $this->conn->table('employees as e')
            ->select('e.name',
                Func::rowNumber()->partitionBy('e.dept')->orderBy('e.salary', 'DESC')->as('rn'))
            ->orderBy('e.dept')
            ->orderBy('rn')
            ->get()
            ->rows();

        $this->assertSame(['bob', 'carol', 'alice', 'dave', 'eve'], array_column($rows, 'name'));
    }

    public function testRankingWithoutOrderByThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('必须指定 ORDER BY');
        $this->conn->table('employees')->select(Func::rowNumber()->partitionBy('dept')->as('rn'))->get();
    }

    public function testWindowExplainStep(): void
    {
        $plan = $this->conn->table('employees as e')
            ->select('e.name', Func::rowNumber()->partitionBy('e.dept')->orderBy('e.salary')->as('rn'))
            ->explain();

        $steps = array_column($plan, 'step');
        $this->assertContains('WINDOW', $steps);
    }
}
