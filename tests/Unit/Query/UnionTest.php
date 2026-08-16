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
 * UNION / UNION ALL 测试：合并去重、列对齐校验、外层收尾、子方完整执行语义
 */
final class UnionTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('a', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull();
        });
        $this->conn->createTable('b', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull();
        });

        $this->conn->table('a')->insertMany([
            ['name' => 'x'],
            ['name' => 'y'],
            ['name' => 'z'],
        ]);
        $this->conn->table('b')->insertMany([
            ['name' => 'y'],
            ['name' => 'w'],
        ]);
    }

    // ---- 基础合并 ----

    public function testUnionDeduplicatesWithFirstSeenOrder(): void
    {
        $rows = $this->conn->table('a')
            ->select('name')
            ->union($this->conn->table('b')->select('name'))
            ->get()
            ->rows();

        // 基础方 x,y,z + 联合方 y(重复丢弃),w → 首见顺序
        $this->assertSame(
            [['name' => 'x'], ['name' => 'y'], ['name' => 'z'], ['name' => 'w']],
            $rows,
        );
    }

    public function testUnionAllKeepsDuplicates(): void
    {
        $rows = $this->conn->table('a')
            ->select('name')
            ->unionAll($this->conn->table('b')->select('name'))
            ->get()
            ->rows();

        $this->assertSame(
            [['name' => 'x'], ['name' => 'y'], ['name' => 'z'], ['name' => 'y'], ['name' => 'w']],
            $rows,
        );
    }

    // ---- 列对齐校验 ----

    public function testUnionColumnCountMismatchThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('UNION 列不一致');
        $this->conn->table('a')
            ->select('id', 'name')
            ->union($this->conn->table('b')->select('id'))
            ->get();
    }

    public function testUnionColumnNameMismatchThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('UNION 列不一致');
        $this->conn->table('a')
            ->select('id')
            ->union($this->conn->table('b')->select('name'))
            ->get();
    }

    // ---- 外层收尾应用于合并结果 ----

    public function testOuterOrderByLimitAppliedToMergedResult(): void
    {
        $rows = $this->conn->table('a')
            ->select('name')
            ->unionAll($this->conn->table('b')->select('name'))
            ->orderByDesc('name')
            ->limit(3)
            ->get()
            ->rows();

        // 合并 x,y,z,y,w → name 倒序 z,y,y → 取前 3
        $this->assertSame(
            [['name' => 'z'], ['name' => 'y'], ['name' => 'y']],
            $rows,
        );
    }

    public function testOuterWhereWithUnion(): void
    {
        $rows = $this->conn->table('a')
            ->select('name')
            ->where('name', '!=', 'y')
            ->union($this->conn->table('b')->select('name'))
            ->get()
            ->rows();

        // 基础方 [x,z] + 联合方 [y,w] → x,z,y,w
        $this->assertSame(
            [['name' => 'x'], ['name' => 'z'], ['name' => 'y'], ['name' => 'w']],
            $rows,
        );
    }

    public function testOuterAggregateAsBaseRow(): void
    {
        $rows = $this->conn->table('a')
            ->select(Agg::count('*')->as('cnt'))
            ->union($this->conn->table('b')->select(Agg::count('*')->as('cnt')))
            ->get()
            ->rows();

        $this->assertSame([['cnt' => 3], ['cnt' => 2]], $rows);
    }

    // ---- 链式与混合 ----

    public function testThreeWayChainedUnionMixed(): void
    {
        $rows = $this->conn->table('a')
            ->select('name')
            ->unionAll($this->conn->table('b')->select('name'))
            ->union($this->conn->table('a')->select('name')->where('name', 'x'))
            ->get()
            ->rows();

        // x,y,z + unionAll y,w → x,y,z,y,w；union 子方 [x] 已存在丢弃
        $this->assertSame(
            [['name' => 'x'], ['name' => 'y'], ['name' => 'z'], ['name' => 'y'], ['name' => 'w']],
            $rows,
        );
    }

    public function testUnionAllAfterUnionDeduplicatesWholeSet(): void
    {
        // 先 UNION 建全集合去重池，再 UNION ALL 追加，最后外层 distinct 全集去重
        $rows = $this->conn->table('a')
            ->select('name')
            ->union($this->conn->table('b')->select('name'))
            ->unionAll($this->conn->table('a')->select('name')->where('name', 'x'))
            ->distinct()
            ->get()
            ->rows();

        $this->assertSame(
            [['name' => 'x'], ['name' => 'y'], ['name' => 'z'], ['name' => 'w']],
            $rows,
        );
    }

    // ---- 子方完整执行语义 ----

    public function testUnionSideWithOwnLimit(): void
    {
        $rows = $this->conn->table('a')
            ->select('name')
            ->union(
                $this->conn->table('b')->select('name')->orderBy('name')->limit(1),
            )
            ->get()
            ->rows();

        // 子方先取排序后前 1 个 ['w'] 再并入
        $this->assertSame(
            [['name' => 'x'], ['name' => 'y'], ['name' => 'z'], ['name' => 'w']],
            $rows,
        );
    }

    public function testUnionSideAggregateQuery(): void
    {
        $rows = $this->conn->table('a')
            ->select('name', Agg::count('*')->as('cnt'))
            ->groupBy('name')
            ->orderBy('name')
            ->union(
                $this->conn->table('b')
                    ->select('name', Agg::count('*')->as('cnt'))
                    ->groupBy('name')
                    ->orderBy('name'),
            )
            ->get()
            ->rows();

        // 基础方 x,y,z 各 1 → 联合方 y(重复丢弃),w；外层按 name 升序（'w'<'x'）
        $this->assertSame(
            [
                ['name' => 'w', 'cnt' => 1],
                ['name' => 'x', 'cnt' => 1],
                ['name' => 'y', 'cnt' => 1],
                ['name' => 'z', 'cnt' => 1],
            ],
            $rows,
        );
    }

    public function testEmptyUnionSideSkipsColumnCheckAndMerge(): void
    {
        $rows = $this->conn->table('a')
            ->select('name')
            ->union($this->conn->table('b')->select('name')->where('name', 'nope'))
            ->get()
            ->rows();

        $this->assertSame([['name' => 'x'], ['name' => 'y'], ['name' => 'z']], $rows);
    }

    public function testEmptyBaseSideAdoptsSubSideKeys(): void
    {
        // 基础方空集无法取键，首个非空联合方成为列基准
        $rows = $this->conn->table('a')
            ->select('name')
            ->where('name', 'nope')
            ->union($this->conn->table('b')->select('name'))
            ->get()
            ->rows();

        $this->assertSame([['name' => 'y'], ['name' => 'w']], $rows);
    }

    public function testNestedUnionSideCarriesItsOwnUnions(): void
    {
        // 联合方自身携带 unions：b.union(a) 先得 [y,w,x,z]，再并入外层 [x,y,z] → x,y,z,w
        $rows = $this->conn->table('a')
            ->select('name')
            ->union(
                $this->conn->table('b')
                    ->select('name')
                    ->union($this->conn->table('a')->select('name')),
            )
            ->get()
            ->rows();

        $this->assertSame(
            [['name' => 'x'], ['name' => 'y'], ['name' => 'z'], ['name' => 'w']],
            $rows,
        );
    }

    // ---- Table 入口端到端 ----

    public function testTableEntryEndToEnd(): void
    {
        $rows = $this->conn->table('a')
            ->union($this->conn->table('b')->where('name', 'w'))
            ->orderBy('name')
            ->get()
            ->rows();

        // 双方均全列输出（id,name）：a 三行 + b 一行 (2,w)，按 name 升序（'w'<'x'）
        $this->assertSame(
            [
                ['id' => 2, 'name' => 'w'],
                ['id' => 1, 'name' => 'x'],
                ['id' => 2, 'name' => 'y'],
                ['id' => 3, 'name' => 'z'],
            ],
            $rows,
        );
    }
}
