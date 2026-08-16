<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Agg;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 索引感知执行器测试：等值索引命中、复合/主键/unique 自动索引、写后失效、回退扫描
 */
final class IndexExecutorTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('t', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('email', 64)->notNull();
            $b->int('a')->notNull();
            $b->int('b')->notNull();
        });
        $rows = [];
        for ($i = 1; $i <= 200; $i++) {
            $rows[] = ['email' => "user{$i}@example.com", 'a' => $i % 7, 'b' => $i % 13];
        }
        $this->conn->table('t')->insertMany($rows);
    }

    public function testEqualityLookupMatchesScanExactly(): void
    {
        $this->conn->createIndex('t', 'idx_email', 'email');

        $target = 137;
        $withIndex = $this->conn->table('t')->where('email', '=', "user{$target}@example.com")->get()->rows();

        $this->conn->dropIndex('t', 'idx_email');
        $withoutIndex = $this->conn->table('t')->where('email', '=', "user{$target}@example.com")->get()->rows();

        // 整个数组逐字节一致（含顺序）
        $this->assertSame($withoutIndex, $withIndex);
        $this->assertSame([['id' => $target, 'email' => "user{$target}@example.com", 'a' => $target % 7, 'b' => $target % 13]], $withIndex);
    }

    public function testCompositeIndexHitsOnFullColumnSetOnly(): void
    {
        $this->conn->createIndex('t', 'idx_ab', 'a', 'b');

        // a + b 全列集：走索引
        $full = $this->conn->table('t')->where('a', '=', 3)->where('b', '=', 3)->get()->rows();
        $this->conn->dropIndex('t', 'idx_ab');
        $fullScan = $this->conn->table('t')->where('a', '=', 3)->where('b', '=', 3)->get()->rows();
        $this->assertSame($fullScan, $full);

        // 仅 a=：列集不完整，回退扫描，结果仍正确
        $partial = $this->conn->table('t')->where('a', '=', 5)->get()->rows();
        $this->assertCount(28, $partial); // 200 内 a=i%7==5：i=5,12,...,194
    }

    public function testCompositeIndexColumnOrderInsensitive(): void
    {
        $this->conn->createIndex('t', 'idx_ab', 'a', 'b');

        // 条件顺序与索引列顺序相反（b 在前 a 在后）仍可命中；
        // 取值不同的两列（a=3 与 b=3 在 200 行内 i=16 处同时成立），防止同值数据掩盖键序 bug
        $reversed = $this->conn->table('t')->where('b', '=', 10)->where('a', '=', 3)->get()->rows();
        $this->conn->dropIndex('t', 'idx_ab');
        $scan = $this->conn->table('t')->where('b', '=', 10)->where('a', '=', 3)->get()->rows();
        $this->assertSame($scan, $reversed);
        $this->assertNotSame([], $reversed);
    }

    public function testCompositeUniqueProbeKeyOrderRegression(): void
    {
        // 回归：联合 unique 自动索引 + 条件书写顺序翻转 + 各列值不同——
        // 探测键曾按原始列序拼接导致与构建键（排序列序）错位而静默漏行
        $this->conn->createTable('u', static function (Blueprint $b): void {
            $b->id();
            $b->int('x')->notNull();
            $b->int('y')->notNull();
            $b->unique('x', 'y');
        });
        $this->conn->table('u')->insertMany([
            ['x' => 11, 'y' => 21],
            ['x' => 31, 'y' => 41],
        ]);

        $normal = $this->conn->table('u')->where('x', '=', 11)->where('y', '=', 21)->get()->rows();
        $this->assertSame([['id' => 1, 'x' => 11, 'y' => 21]], $normal);

        $reversed = $this->conn->table('u')->where('y', '=', 21)->where('x', '=', 11)->get()->rows();
        $this->assertSame($normal, $reversed);

        $miss = $this->conn->table('u')->where('y', '=', 21)->where('x', '=', 31)->get()->rows();
        $this->assertSame([], $miss);
    }

    public function testPrimaryKeyAutoIndexed(): void
    {
        $found = $this->conn->table('t')->find(42);
        $where = $this->conn->table('t')->where('id', '=', 42)->first();

        $this->assertSame($where, $found);
        $this->assertSame(42, $found['id']);
    }

    public function testUniqueColumnAutoIndexed(): void
    {
        $this->conn->createTable('u', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('code', 16)->notNull()->unique();
        });
        $this->conn->table('u')->insertMany([
            ['code' => 'A1'], ['code' => 'B2'], ['code' => 'C3'],
        ]);

        // unique 列自动可用作索引：等值查询与扫描路径一致
        $hit = $this->conn->table('u')->where('code', '=', 'B2')->get()->rows();
        $this->assertSame([['id' => 2, 'code' => 'B2']], $hit);

        $miss = $this->conn->table('u')->where('code', '=', 'ZZ')->get()->rows();
        $this->assertSame([], $miss);
    }

    public function testUpdateInvalidatesStaleIndex(): void
    {
        $this->conn->createIndex('t', 'idx_email', 'email');

        $before = $this->conn->table('t')->where('email', '=', 'user50@example.com')->get()->rows();
        $this->assertCount(1, $before);

        // 改动被索引列后再查询，必须反映新值（陈旧缓存 bug 的直接反例）
        $this->conn->table('t')->where('id', '=', 50)->update(['email' => 'renamed@example.com']);

        $this->assertSame([], $this->conn->table('t')->where('email', '=', 'user50@example.com')->get()->rows());
        $renamed = $this->conn->table('t')->where('email', '=', 'renamed@example.com')->get()->rows();
        $this->assertSame([['id' => 50, 'email' => 'renamed@example.com', 'a' => 50 % 7, 'b' => 50 % 13]], $renamed);
    }

    public function testUpsertAndInsertIgnoreInvalidateIndex(): void
    {
        $this->conn->createIndex('t', 'idx_email', 'email');

        // 主键 id=1 冲突 → 静默跳过，不产生新行
        $this->assertSame(0, $this->conn->table('t')->insertIgnore(['id' => 1, 'email' => 'dup@example.com', 'a' => 0, 'b' => 0]));
        $this->assertSame([], $this->conn->table('t')->where('email', '=', 'dup@example.com')->get()->rows());

        // 无冲突 upsert 走 insert 路径：新行立即可见
        $this->assertSame(1, $this->conn->table('t')->upsert(['email' => 'fresh@example.com', 'a' => 0, 'b' => 0]));
        $fresh = $this->conn->table('t')->where('email', '=', 'fresh@example.com')->get()->rows();
        $this->assertSame([[ 'id' => 201, 'email' => 'fresh@example.com', 'a' => 0, 'b' => 0]], $fresh);

        // 命中唯一约束的 upsert 更新路径：email 改动对索引立即可见（NOT NULL 列需完整提供）
        $this->assertSame(2, $this->conn->table('t')->upsert(['id' => 1, 'email' => 'upserted@example.com', 'a' => 1, 'b' => 1]));
        $hit = $this->conn->table('t')->where('email', '=', 'upserted@example.com')->get()->rows();
        $this->assertSame([['id' => 1, 'email' => 'upserted@example.com', 'a' => 1, 'b' => 1]], $hit);
        $this->assertSame([], $this->conn->table('t')->where('email', '=', 'user1@example.com')->get()->rows());
    }

    public function testDeleteInvalidatesIndex(): void
    {
        $this->conn->createIndex('t', 'idx_email', 'email');
        $this->assertCount(1, $this->conn->table('t')->where('email', '=', 'user7@example.com')->get()->rows());

        $this->conn->table('t')->where('id', '=', 7)->delete();

        $this->assertSame([], $this->conn->table('t')->where('email', '=', 'user7@example.com')->get()->rows());
    }

    public function testRollbackInvalidatesIndex(): void
    {
        $this->conn->createIndex('t', 'idx_email', 'email');
        $before = $this->conn->table('t')->where('email', '=', 'user8@example.com')->get()->rows();
        $this->assertCount(1, $before);

        $this->conn->begin();
        $this->conn->table('t')->insert(['email' => 'ghost@example.com', 'a' => 0, 'b' => 0]);
        $this->conn->table('t')->where('id', '=', 8)->delete();
        $this->conn->rollBack();

        // 回滚后查询结果 = 回滚前（防止回滚后使用陈旧索引）
        $after = $this->conn->table('t')->where('email', '=', 'user8@example.com')->get()->rows();
        $this->assertSame($before, $after);
        $this->assertSame([], $this->conn->table('t')->where('email', '=', 'ghost@example.com')->get()->rows());
    }

    public function testIndexHitButNoMatchingRows(): void
    {
        $this->conn->createIndex('t', 'idx_email', 'email');

        // 索引命中（列集匹配）但无匹配行 → 空结果（与扫描路径一致）
        $this->assertSame([], $this->conn->table('t')->where('email', '=', 'nobody@example.com')->get()->rows());
    }

    public function testNumericEqualityAcrossTypes(): void
    {
        // 主键 int 存储，字符串 '42' 数值性合一（compareValues 语义）
        $rows = $this->conn->table('t')->where('id', '=', '42')->get()->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(42, $rows[0]['id']);
    }

    public function testFallbackOnOrCondition(): void
    {
        $this->conn->createIndex('t', 'idx_email', 'email');

        $rows = $this->conn->table('t')
            ->where('email', '=', 'user1@example.com')
            ->orWhere('email', '=', 'user2@example.com')
            ->get()->rows();

        $this->assertSame([1, 2], array_column($rows, 'id'));
    }

    public function testFallbackOnWhereIn(): void
    {
        $this->conn->createIndex('t', 'idx_email', 'email');

        $rows = $this->conn->table('t')
            ->whereIn('email', ['user3@example.com', 'user5@example.com'])
            ->get()->rows();

        $this->assertSame([3, 5], array_column($rows, 'id'));
    }

    public function testFallbackOnNullValue(): void
    {
        $this->conn->createIndex('t', 'idx_email', 'email');

        // null 永不匹配等值条件 → 空结果
        $this->assertSame([], $this->conn->table('t')->where('email', '=', null)->get()->rows());
    }

    public function testFallbackOnQualifiedColumnName(): void
    {
        $this->conn->createIndex('t', 'idx_email', 'email');

        // 限定列名 't.email'：回退扫描，结果不变
        $rows = $this->conn->table('t as t')->where('t.email', '=', 'user9@example.com')->get()->rows();
        $this->assertSame([['id' => 9, 'email' => 'user9@example.com', 'a' => 9 % 7, 'b' => 9 % 13]], $rows);
    }

    public function testFallbackOnRangeOperator(): void
    {
        $this->conn->createIndex('t', 'idx_email', 'email');

        // 非 '=' 运算符：回退扫描
        $rows = $this->conn->table('t')->where('id', '>', 198)->get()->rows();
        $this->assertSame([199, 200], array_column($rows, 'id'));
    }

    public function testEmptyTableWithIndex(): void
    {
        $this->conn->createTable('empty_t', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('email', 64)->notNull();
        });
        $this->conn->createIndex('empty_t', 'idx_email', 'email');

        $this->assertSame([], $this->conn->table('empty_t')->where('email', '=', 'x@example.com')->get()->rows());
    }

    public function testIndexWithAggregateAndOrder(): void
    {
        $this->conn->createIndex('t', 'idx_a', 'a');

        // 索引路径只替换"扫描+过滤"阶段，聚合/分组/排序原样
        $rows = $this->conn->table('t')
            ->select('a', Agg::count('id')->as('cnt'))
            ->where('a', '=', 2)
            ->groupBy('a')
            ->get()->rows();
        $this->assertSame([['a' => 2, 'cnt' => 29]], $rows);

        $ordered = $this->conn->table('t')
            ->select('id')
            ->where('a', '=', 6)
            ->orderByDesc('id')
            ->limit(3)
            ->get()->rows();
        $this->assertSame([['id' => 195], ['id' => 188], ['id' => 181]], $ordered);
    }
}
