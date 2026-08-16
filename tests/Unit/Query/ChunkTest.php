<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Generator;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * chunk 分批处理与 cursor 惰性游标端到端测试
 */
final class ChunkTest extends TestCase
{
    /**
     * 建表并插入 n 行自增数据，返回连接
     */
    private function seededConnection(int $count): \Kingbes\Psql\Connection
    {
        $conn = Psql::memory();
        $conn->createTable('items', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('label', 64)->notNull();
        });
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['label' => "row-{$i}"];
        }
        $conn->table('items')->insertMany($rows);

        return $conn;
    }

    /**
     * 抽取一批中所有行的 label
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<mixed>
     */
    private function labels(array $rows): array
    {
        return array_map(static fn (array $row): mixed => $row['label'], $rows);
    }

    public function testBasicChunkPagination(): void
    {
        $conn = $this->seededConnection(35);

        $batches = [];
        $iterations = [];
        $processed = $conn->table('items')->orderBy('id')->chunk(10, function (array $rows, int $iteration) use (&$batches, &$iterations): bool {
            $batches[] = count($rows);
            $iterations[] = $iteration;

            return true;
        });

        $this->assertSame(35, $processed);
        $this->assertSame([10, 10, 10, 5], $batches);
        $this->assertSame([1, 2, 3, 4], $iterations);
    }

    public function testChunkWithWhereAndOrderByPreservesOrder(): void
    {
        $conn = $this->seededConnection(25);

        $batches = [];
        $conn->table('items')
            ->where('id', '>', 5)
            ->where('id', '<=', 20)
            ->orderByDesc('id')
            ->chunk(5, function (array $rows) use (&$batches): bool {
                $batches[] = $this->labels($rows);

                return true;
            });

        $this->assertSame(
            [
                ['row-20', 'row-19', 'row-18', 'row-17', 'row-16'],
                ['row-15', 'row-14', 'row-13', 'row-12', 'row-11'],
                ['row-10', 'row-9', 'row-8', 'row-7', 'row-6'],
            ],
            $batches,
        );
    }

    public function testChunkStopsWhenHandlerReturnsFalse(): void
    {
        $conn = $this->seededConnection(35);

        $calls = 0;
        $processed = $conn->table('items')->orderBy('id')->chunk(10, function (array $rows, int $iteration) use (&$calls): bool {
            $calls++;

            return $iteration < 2;
        });

        $this->assertSame(20, $processed);
        $this->assertSame(2, $calls);
    }

    public function testChunkWithNonBoolHandlerReturnDoesNotStop(): void
    {
        $conn = $this->seededConnection(15);

        $calls = 0;
        $processed = $conn->table('items')->orderBy('id')->chunk(5, function () use (&$calls): int {
            $calls++;

            return 0;
        });

        $this->assertSame(15, $processed);
        $this->assertSame(3, $calls);
    }

    public function testChunkWithSizeBelowOneThrows(): void
    {
        $conn = $this->seededConnection(3);

        $this->expectException(QueryException::class);

        $conn->table('items')->chunk(0, static function (): bool {
            return true;
        });
    }

    public function testChunkWithNegativeSizeThrows(): void
    {
        $conn = $this->seededConnection(3);

        $this->expectException(QueryException::class);

        $conn->table('items')->chunk(-2, static function (): bool {
            return true;
        });
    }

    public function testChunkAfterLimitThrows(): void
    {
        $conn = $this->seededConnection(10);

        $this->expectException(QueryException::class);

        $conn->table('items')->limit(5)->chunk(2, static function (): bool {
            return true;
        });
    }

    public function testChunkAfterOffsetThrows(): void
    {
        $conn = $this->seededConnection(10);

        $this->expectException(QueryException::class);

        $conn->table('items')->offset(3)->chunk(2, static function (): bool {
            return true;
        });
    }

    public function testChunkExactDivisionRunsNoExtraQuery(): void
    {
        $conn = $this->seededConnection(30);

        $calls = 0;
        $processed = $conn->table('items')->orderBy('id')->chunk(10, function (array $rows) use (&$calls): bool {
            $calls++;

            return true;
        });

        $this->assertSame(30, $processed);
        $this->assertSame(3, $calls);
    }

    public function testChunkOnEmptyTableNeverInvokesHandler(): void
    {
        $conn = Psql::memory();
        $conn->createTable('items', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('label', 64)->notNull();
        });

        $calls = 0;
        $processed = $conn->table('items')->chunk(10, function () use (&$calls): bool {
            $calls++;

            return true;
        });

        $this->assertSame(0, $processed);
        $this->assertSame(0, $calls);
    }

    public function testChunkOfSizeOneYieldsSingleRowBatches(): void
    {
        $conn = $this->seededConnection(3);

        $batches = [];
        $processed = $conn->table('items')->orderBy('id')->chunk(1, function (array $rows) use (&$batches): bool {
            $batches[] = $this->labels($rows);

            return true;
        });

        $this->assertSame(3, $processed);
        $this->assertSame([['row-1'], ['row-2'], ['row-3']], $batches);
    }

    public function testCursorYieldsAllRows(): void
    {
        $conn = $this->seededConnection(5);

        $cursor = $conn->table('items')->orderBy('id')->cursor();

        $labels = [];
        foreach ($cursor as $row) {
            $labels[] = $row['label'];
        }

        $this->assertSame(['row-1', 'row-2', 'row-3', 'row-4', 'row-5'], $labels);
    }

    public function testCursorOnEmptyTableYieldsNothing(): void
    {
        $conn = Psql::memory();
        $conn->createTable('items', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('label', 64)->notNull();
        });

        $cursor = $conn->table('items')->cursor();
        $this->assertInstanceOf(Generator::class, $cursor);

        $count = 0;
        foreach ($cursor as $row) {
            $count++;
        }

        $this->assertSame(0, $count);
    }

    public function testCursorDefersQueryUntilFirstIteration(): void
    {
        $conn = Psql::memory();

        // 未建表：cursor() 本身不执行查询、不抛异常
        $cursor = $conn->table('missing')->cursor();
        $this->assertInstanceOf(Generator::class, $cursor);

        // 首次迭代才触发查询，此时表不存在才抛 QueryException
        $this->expectException(QueryException::class);

        foreach ($cursor as $row) {
        }
    }

    public function testChunkDelegatesFromTableEntry(): void
    {
        $conn = $this->seededConnection(12);

        // 直接在 Table 入口调用（全表分批，插入顺序）
        $batches = [];
        $processed = $conn->table('items')->chunk(5, function (array $rows, int $iteration) use (&$batches): bool {
            $batches[] = [$iteration, count($rows)];

            return true;
        });

        $this->assertSame(12, $processed);
        $this->assertSame([[1, 5], [2, 5], [3, 2]], $batches);
    }

    public function testCursorDelegatesFromTableEntry(): void
    {
        $conn = $this->seededConnection(3);

        // 直接在 Table 入口调用（不经过返回 SelectBuilder 的链式方法）
        $cursor = $conn->table('items')->cursor();

        $labels = [];
        foreach ($cursor as $row) {
            $labels[] = $row['label'];
        }

        $this->assertSame(['row-1', 'row-2', 'row-3'], $labels);
    }

    public function testCursorKeysAreZeroBasedIntegers(): void
    {
        $conn = $this->seededConnection(3);

        $keys = [];
        foreach ($conn->table('items')->orderBy('id')->cursor() as $key => $row) {
            $keys[] = $key;
        }

        $this->assertSame([0, 1, 2], $keys);
    }

    public function testChunkPaginatesGroupedOutputRows(): void
    {
        $conn = Psql::memory();
        $conn->createTable('items', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('grp', 8)->notNull();
        });
        $conn->table('items')->insertMany([
            ['grp' => 'a'], ['grp' => 'a'], ['grp' => 'a'],
            ['grp' => 'b'], ['grp' => 'b'],
            ['grp' => 'c'],
        ]);

        $batches = [];
        $processed = $conn->table('items')
            ->select('grp')
            ->groupBy('grp')
            ->orderBy('grp')
            ->chunk(2, function (array $rows) use (&$batches): bool {
                $batches[] = array_map(static fn (array $row): mixed => $row['grp'], $rows);

                return true;
            });

        // groupBy 输出 3 行（a/b/c），按输出行分页：2 + 1
        $this->assertSame(3, $processed);
        $this->assertSame([['a', 'b'], ['c']], $batches);
    }

    public function testChunkDoesNotMutateOriginalBuilder(): void
    {
        $conn = $this->seededConnection(8);

        $builder = $conn->table('items')->orderBy('id');
        $processed = $builder->chunk(3, static function (): bool {
            return true;
        });

        $this->assertSame(8, $processed);

        // chunk 后原 builder 仍可正常使用（limit/offset 未被污染）
        $this->assertSame(8, $builder->count());
        $this->assertSame(8, $builder->get()->count());
    }
}
