<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Execution\Writer;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Agg;
use Kingbes\Psql\Query\SelectBuilder;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * UPDATE/DELETE 带 ORDER BY + LIMIT 测试（MySQL 方言语义）：
 * 排序截取、limit 0/负数、无 orderBy 存储序、offset 拒绝、where 组合、
 * 触发器范围、CI 列折叠、链式形态限制、不带 limit 的既有行为回归对照
 */
final class UpdateDeleteLimitTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('log', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('level', 16)->notNull();
            $b->varchar('message', 64)->notNull();
        });
        $this->conn->createTable('queue', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('status', 16)->notNull();
            $b->int('priority');
        });
        $this->conn->createTable('words', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull()->ci();
            $b->varchar('tag', 16);
        });
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(string $table): array
    {
        return $this->conn->engine()->readRows($this->conn->currentDatabase(), $table);
    }

    private function seedLogs(int $count): void
    {
        $batch = [];
        for ($i = 1; $i <= $count; $i++) {
            $batch[] = ['level' => 'debug', 'message' => "m{$i}"];
        }
        $this->conn->table('log')->insertMany($batch);
    }

    // ---- DELETE ... ORDER BY ... LIMIT ----

    public function testDeleteOrderByDescLimitRemovesTopN(): void
    {
        $this->seedLogs(10);
        $this->conn->table('log')->insertMany([
            ['level' => 'info', 'message' => 'i1'],
            ['level' => 'info', 'message' => 'i2'],
        ]);

        // 删 id 最大的 3 条 debug（对照手算：id 10, 9, 8）
        $deleted = $this->conn->table('log')
            ->where('level', '=', 'debug')
            ->orderBy('id', 'DESC')
            ->limit(3)
            ->delete();

        $this->assertSame(3, $deleted);
        $remaining = $this->rows('log');
        $this->assertCount(9, $remaining);
        // debug 剩 id 1-7，info 两行不动
        $this->assertSame(
            [1, 2, 3, 4, 5, 6, 7, 11, 12],
            array_column($remaining, 'id'),
        );
    }

    public function testDeleteLimitZeroIsNoop(): void
    {
        $this->seedLogs(3);

        $deleted = $this->conn->table('log')->limit(0)->delete();

        $this->assertSame(0, $deleted);
        $this->assertCount(3, $this->rows('log'));
    }

    public function testDeleteNegativeLimitThrowsAtChainTime(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('limit');
        $this->conn->table('log')->limit(-1);
    }

    public function testDeleteNegativeLimitThrowsAtWriterLevel(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('limit');
        (new Writer($this->conn))->delete('log', null, null, [], -1);
    }

    public function testDeleteLimitWithoutOrderByUsesStorageOrder(): void
    {
        $this->seedLogs(5);

        // 无 orderBy：按存储序取前 N（MySQL 允许无序 LIMIT）
        $deleted = $this->conn->table('log')->limit(2)->delete();

        $this->assertSame(2, $deleted);
        $this->assertSame([3, 4, 5], array_column($this->rows('log'), 'id'));
    }

    public function testDeleteOffsetThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('OFFSET');
        $this->conn->table('log')->offset(1)->delete();
    }

    public function testDeleteOrderByUnknownColumnThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ghost');
        $this->conn->table('log')->orderBy('ghost')->limit(1)->delete();
    }

    public function testDeleteTriggerFiresOnlyForSelectedRows(): void
    {
        $this->seedLogs(5);

        $received = [];
        $this->conn->createTrigger('log', 'after', 'delete', static function (array $row) use (&$received): void {
            $received[] = $row['id'];
        });

        $deleted = $this->conn->table('log')->orderBy('id', 'DESC')->limit(2)->delete();

        $this->assertSame(2, $deleted);
        // 仅被选中的 id 5、4 触发（按删除序）
        $this->assertSame([5, 4], $received);
        $this->assertSame([1, 2, 3], array_column($this->rows('log'), 'id'));
    }

    // ---- UPDATE ... ORDER BY ... LIMIT ----

    public function testUpdateOrderByLimitUpdatesOnlyTopN(): void
    {
        $priorities = [1, 3, 2, 5, 4, 0];
        $batch = [];
        foreach ($priorities as $priority) {
            $batch[] = ['status' => 'new', 'priority' => $priority];
        }
        $this->conn->table('queue')->insertMany($batch);

        // 只改优先级最高的前 2 条（priority 5→id4、4→id5）
        $updated = $this->conn->table('queue')
            ->where('status', '=', 'new')
            ->orderBy('priority', 'DESC')
            ->limit(2)
            ->update(['status' => 'taken']);

        $this->assertSame(2, $updated);
        $rows = $this->rows('queue');
        $this->assertSame('taken', $rows[3]['status']);
        $this->assertSame('taken', $rows[4]['status']);
        $this->assertSame(
            ['new', 'new', 'new', 'taken', 'taken', 'new'],
            array_column($rows, 'status'),
        );
    }

    public function testUpdateLimitZeroIsNoop(): void
    {
        $this->conn->table('queue')->insertMany([
            ['status' => 'new', 'priority' => 1],
            ['status' => 'new', 'priority' => 2],
        ]);

        $updated = $this->conn->table('queue')->limit(0)->update(['status' => 'taken']);

        $this->assertSame(0, $updated);
        $this->assertSame(['new', 'new'], array_column($this->rows('queue'), 'status'));
    }

    public function testUpdateNegativeLimitThrowsAtWriterLevel(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('limit');
        (new Writer($this->conn))->update('queue', null, null, ['status' => 'x'], [], -1);
    }

    public function testUpdateOffsetThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('OFFSET');
        $this->conn->table('queue')->offset(1)->update(['status' => 'x']);
    }

    // ---- 与 where 组合 / matched 为空 ----

    public function testWhereCombinationNarrowsMatchedSet(): void
    {
        $batch = [];
        for ($i = 1; $i <= 6; $i++) {
            $batch[] = ['level' => $i % 2 === 0 ? 'debug' : 'info', 'message' => "m{$i}"];
        }
        $this->conn->table('log')->insertMany($batch);

        // info 行为 id 1,3,5：删 id 最大的 2 条 info（5, 3）
        $deleted = $this->conn->table('log')
            ->where('level', '=', 'info')
            ->orderByDesc('id')
            ->limit(2)
            ->delete();

        $this->assertSame(2, $deleted);
        $this->assertSame([1, 2, 4, 6], array_column($this->rows('log'), 'id'));
    }

    public function testNoMatchedRowsWithLimitReturnsZero(): void
    {
        $this->seedLogs(2);

        $deleted = $this->conn->table('log')
            ->where('level', '=', 'error')
            ->orderByDesc('id')
            ->limit(5)
            ->delete();

        $this->assertSame(0, $deleted);
        $this->assertCount(2, $this->rows('log'));
    }

    // ---- CI 列折叠（v1.3 写路径 collations 传递） ----

    public function testCiWhereFoldingAppliesWithLimitDelete(): void
    {
        $this->conn->table('words')->insertMany([
            ['name' => 'BOB'],
            ['name' => 'alice'],
            ['name' => 'ALICE'],
        ]);

        // CI where 折叠：'alice' 命中 alice + ALICE 两行；limit 1 按存储序删首行
        $deleted = $this->conn->table('words')
            ->where('name', '=', 'alice')
            ->limit(1)
            ->delete();

        $this->assertSame(1, $deleted);
        $this->assertSame(['BOB', 'ALICE'], array_column($this->rows('words'), 'name'));
    }

    public function testCiOrderByFoldingAppliesWithLimitUpdate(): void
    {
        $this->conn->table('words')->insertMany([
            ['name' => 'BOB'],
            ['name' => 'alice'],
            ['name' => 'ALICE'],
        ]);

        // CI 折叠排序 asc：alice(2) / ALICE(3) 折叠相等保持存储序在前，BOB(1) 最后
        $updated = $this->conn->table('words')
            ->orderBy('name')
            ->limit(2)
            ->update(['tag' => 'hit']);

        $this->assertSame(2, $updated);
        $rows = $this->rows('words');
        $this->assertNull($rows[0]['tag']);
        $this->assertSame('hit', $rows[1]['tag']);
        $this->assertSame('hit', $rows[2]['tag']);
    }

    // ---- 链式形态限制 ----

    public function testAggregateChainWithLimitThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('仅支持');
        $this->conn->table('log')
            ->select(Agg::count('*'))
            ->limit(1)
            ->delete();
    }

    public function testJoinChainWithLimitThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('仅支持');
        (new SelectBuilder($this->conn, 'log'))
            ->join('queue', 'log.id', '=', 'queue.id')
            ->limit(1)
            ->delete();
    }

    // ---- 既有行为回归对照（不带 orderBy/limit） ----

    public function testPlainUpdateWithoutLimitBehaviorUnchanged(): void
    {
        $this->seedLogs(4);

        // 无 orderBy/limit：全部 matched 更新（与 v2.0 行为一致）
        $updated = $this->conn->table('log')
            ->where('level', '=', 'debug')
            ->update(['level' => 'trace']);

        $this->assertSame(4, $updated);
        $this->assertSame(
            ['trace', 'trace', 'trace', 'trace'],
            array_column($this->rows('log'), 'level'),
        );
    }

    public function testPlainDeleteWithoutLimitBehaviorUnchanged(): void
    {
        $this->seedLogs(3);

        $deleted = $this->conn->table('log')->where('level', '=', 'debug')->delete();

        $this->assertSame(3, $deleted);
        $this->assertSame([], $this->rows('log'));
    }

    public function testOrderByWithoutLimitStillUpdatesAllMatched(): void
    {
        $this->seedLogs(3);

        // 仅 orderBy 无 limit：排序不改变 matched 全集（MySQL 语义）
        $updated = $this->conn->table('log')
            ->orderByDesc('id')
            ->update(['level' => 'trace']);

        $this->assertSame(3, $updated);
        $this->assertSame(
            ['trace', 'trace', 'trace'],
            array_column($this->rows('log'), 'level'),
        );
    }
}
