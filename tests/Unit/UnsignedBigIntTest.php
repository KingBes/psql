<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\ConstraintException;
use Kingbes\Psql\Exception\TypeException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Agg;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * UNSIGNED BIGINT 超 PHP_INT_MAX 大数测试：字符串存储、范围校验、
 * 精确比较（WHERE/排序/聚合）、唯一约束与索引归一、持久化
 */
final class UnsignedBigIntTest extends TestCase
{
    private const MAX_UINT64 = '18446744073709551615';
    private const MAX_INT = '9223372036854775807';

    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('nums', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('big')->unsigned();
        });
    }

    private function bigValues(): array
    {
        return [
            '1',
            self::MAX_INT,             // PHP_INT_MAX，存 int
            '9223372036854775808',     // PHP_INT_MAX + 1，存 string
            self::MAX_UINT64,          // 2^64-1，存 string
        ];
    }

    // ---- 存储形态 ----

    public function testStoresIntWithinRangeAndStringBeyond(): void
    {
        $this->conn->table('nums')->insertMany(array_map(
            static fn (string $v): array => ['big' => $v],
            $this->bigValues(),
        ));

        $rows = $this->conn->table('nums')->orderBy('id')->get()->rows();

        $this->assertSame(1, $rows[0]['big']);
        $this->assertSame(PHP_INT_MAX, $rows[1]['big']);
        $this->assertSame('9223372036854775808', $rows[2]['big']);
        $this->assertSame(self::MAX_UINT64, $rows[3]['big']);
    }

    public function testRejectsBeyondUint64(): void
    {
        $this->expectException(TypeException::class);
        $this->expectExceptionMessage('超出');

        $this->conn->table('nums')->insert(['big' => '18446744073709551616']);
    }

    public function testRejectsNegative(): void
    {
        $this->expectException(TypeException::class);
        $this->expectExceptionMessage('负数');

        $this->conn->table('nums')->insert(['big' => '-1']);
    }

    public function testRejectsFloatBeyondPrecision(): void
    {
        $this->expectException(TypeException::class);
        $this->expectExceptionMessage('字符串');

        $this->conn->table('nums')->insert(['big' => 1.0E+19]);
    }

    // ---- 精确比较 ----

    public function testWhereComparisonIsExact(): void
    {
        $this->conn->table('nums')->insertMany(array_map(
            static fn (string $v): array => ['big' => $v],
            $this->bigValues(),
        ));

        $rows = $this->conn->table('nums')
            ->where('big', '>', self::MAX_INT)
            ->orderBy('big')
            ->get()
            ->pluck('big');

        $this->assertSame(['9223372036854775808', self::MAX_UINT64], $rows);
    }

    public function testWhereEqualMatchesStoredString(): void
    {
        $this->conn->table('nums')->insertMany(array_map(
            static fn (string $v): array => ['big' => $v],
            $this->bigValues(),
        ));

        $rows = $this->conn->table('nums')->where('big', '=', self::MAX_UINT64)->get()->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(self::MAX_UINT64, $rows[0]['big']);
    }

    public function testOrderByIsNumericNotLexicographic(): void
    {
        $this->conn->table('nums')->insertMany(array_map(
            static fn (string $v): array => ['big' => $v],
            $this->bigValues(),
        ));

        $rows = $this->conn->table('nums')->orderBy('big', 'ASC')->get()->pluck('big');

        // 字典序会把 '9223372036854775808'（9 开头）排在 '18446744073709551615'（1 开头）之后，
        // 数值序必须按大小：1 < PHP_INT_MAX < PHP_INT_MAX+1 < 2^64-1
        $this->assertSame([1, PHP_INT_MAX, '9223372036854775808', self::MAX_UINT64], $rows);
    }

    // ---- 唯一约束与索引 ----

    public function testUniqueDistinguishesAdjacentBigInts(): void
    {
        $this->conn->dropTable('nums');
        $this->conn->createTable('nums', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('big')->unsigned()->unique();
        });

        $this->conn->table('nums')->insert(['big' => '18446744073709551615']);
        $this->conn->table('nums')->insert(['big' => '18446744073709551614']); // 相邻，不冲突

        $this->assertCount(2, $this->conn->table('nums')->get());
    }

    public function testUniqueRejectsDuplicateBigInt(): void
    {
        $this->conn->dropTable('nums');
        $this->conn->createTable('nums', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('big')->unsigned()->unique();
        });

        $this->conn->table('nums')->insert(['big' => self::MAX_UINT64]);

        $this->expectException(ConstraintException::class);
        $this->conn->table('nums')->insert(['big' => self::MAX_UINT64]);
    }

    public function testIndexEqualityLookupOnBigInt(): void
    {
        $this->conn->createTable('big_index', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('big')->unsigned();
        });
        $this->conn->createIndex('big_index', 'ix_big', 'big');

        $rows = [];
        foreach ($this->bigValues() as $v) {
            $rows[] = ['big' => $v];
        }
        $rows[] = ['big' => '18446744073709551614'];
        $this->conn->table('big_index')->insertMany($rows);

        $hit = $this->conn->table('big_index')->where('big', '=', self::MAX_UINT64)->get()->rows();
        $this->assertCount(1, $hit);
        $this->assertSame(self::MAX_UINT64, $hit[0]['big']);

        $miss = $this->conn->table('big_index')->where('big', '=', '18446744073709551614')->get()->rows();
        $this->assertCount(1, $miss);
    }

    // ---- 聚合 ----

    public function testMinMaxAreExact(): void
    {
        $this->conn->table('nums')->insertMany(array_map(
            static fn (string $v): array => ['big' => $v],
            $this->bigValues(),
        ));

        $this->assertSame(1, $this->conn->table('nums')->min('big'));
        $this->assertSame(self::MAX_UINT64, $this->conn->table('nums')->max('big'));
    }

    public function testGroupAggregateMinMax(): void
    {
        $this->conn->dropTable('nums');
        $this->conn->createTable('nums', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('g', 4);
            $b->bigint('big')->unsigned();
        });
        $this->conn->table('nums')->insertMany([
            ['g' => 'a', 'big' => '18446744073709551615'],
            ['g' => 'a', 'big' => '9223372036854775808'],
            ['g' => 'b', 'big' => '18446744073709551614'],
        ]);

        $rows = $this->conn->table('nums')
            ->select('g', Agg::max('big')->as('mx'))
            ->groupBy('g')
            ->orderBy('g')
            ->get()
            ->rows();

        $this->assertSame([
            ['g' => 'a', 'mx' => self::MAX_UINT64],
            ['g' => 'b', 'mx' => '18446744073709551614'],
        ], $rows);
    }

    // ---- 持久化 ----

    public function testPersistsAcrossFileConnections(): void
    {
        $root = sys_get_temp_dir() . '/psql-bigint-' . uniqid();
        try {
            $file = Psql::connect($root);
            $file->createTable('t', static function (Blueprint $b): void {
                $b->id();
                $b->bigint('big')->unsigned();
            });
            $file->table('t')->insert(['big' => self::MAX_UINT64]);

            $reopened = Psql::connect($root);
            $this->assertSame(self::MAX_UINT64, $reopened->table('t')->first()['big']);
        } finally {
            $this->removeDir($root);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
