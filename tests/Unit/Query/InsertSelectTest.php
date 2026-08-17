<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\ConstraintException;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Agg;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * INSERT ... SELECT 测试：列名匹配、部分列默认回填、聚合/join/union/子查询源、
 * 空结果集、未知列差集、自引用检测（直接/join/union 间接）、批内原子、触发器、自增分配
 */
final class InsertSelectTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        // 目标表：列序 id, name, age, memo（memo 带默认值，供部分列插入）
        $this->conn->createTable('dst', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull();
            $b->int('age');
            $b->varchar('memo', 16)->default('none');
        });
        // 源表：列序与目标表刻意不同（age 在 name 前），验证按键名匹配
        $this->conn->createTable('src', static function (Blueprint $b): void {
            $b->id();
            $b->int('age');
            $b->varchar('name', 32);
        });
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull();
        });
        $this->conn->createTable('posts', static function (Blueprint $b): void {
            $b->id();
            $b->int('user_id');
            $b->varchar('title', 32)->notNull();
        });
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(string $table): array
    {
        return $this->conn->engine()->readRows($this->conn->currentDatabase(), $table);
    }

    // ---- 基础语义 ----

    public function testBasicInsertSelectMatchesColumnsByName(): void
    {
        $this->conn->table('src')->insertMany([
            ['age' => 20, 'name' => 'a'],
            ['age' => 30, 'name' => 'b'],
        ]);

        // 源查询输出列序（name 在 age 前）与源表存储列序（age 在 name 前）刻意错开
        $inserted = $this->conn->table('dst')->insertSelect(
            $this->conn->table('src')->select('name', 'age')->where('age', '>=', 20),
        );

        $this->assertSame(2, $inserted);
        $this->assertSame([
            ['id' => 1, 'name' => 'a', 'age' => 20, 'memo' => 'none'],
            ['id' => 2, 'name' => 'b', 'age' => 30, 'memo' => 'none'],
        ], $this->rows('dst'));
    }

    public function testPartialColumnsFillDefaults(): void
    {
        $this->conn->table('src')->insertMany([
            ['name' => 'a'],
            ['name' => 'b'],
        ]);

        // 源仅覆盖 name 子集，age/memo 走 DEFAULT/null
        $inserted = $this->conn->table('dst')->insertSelect(
            $this->conn->table('src')->select('name'),
        );

        $this->assertSame(2, $inserted);
        $rows = $this->rows('dst');
        $this->assertNull($rows[0]['age']);
        $this->assertSame('none', $rows[0]['memo']);
        $this->assertSame('none', $rows[1]['memo']);
    }

    // ---- 源形态：聚合 / join / union / whereIn 子查询 ----

    public function testSourceWithAggregateAndGroupBy(): void
    {
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->int('user_id');
            $b->int('amount');
        });
        $this->conn->createTable('summary', static function (Blueprint $b): void {
            $b->id();
            $b->int('user_id');
            $b->int('total');
        });
        $this->conn->table('orders')->insertMany([
            ['user_id' => 1, 'amount' => 10],
            ['user_id' => 1, 'amount' => 20],
            ['user_id' => 2, 'amount' => 5],
        ]);

        $source = $this->conn->table('orders')
            ->select('user_id', Agg::sum('amount')->as('total'))
            ->groupBy('user_id');

        $inserted = $this->conn->table('summary')->insertSelect($source);

        $this->assertSame(2, $inserted);
        $this->assertSame([
            ['id' => 1, 'user_id' => 1, 'total' => 30],
            ['id' => 2, 'user_id' => 2, 'total' => 5],
        ], $this->rows('summary'));
    }

    public function testSourceWithJoin(): void
    {
        $this->conn->createTable('report', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('title', 32)->notNull();
            $b->varchar('name', 32);
        });
        $this->conn->table('users')->insertMany([
            ['id' => 1, 'name' => 'alice'],
            ['id' => 2, 'name' => 'bob'],
        ]);
        $this->conn->table('posts')->insertMany([
            ['user_id' => 1, 'title' => 't1'],
            ['user_id' => 2, 'title' => 't2'],
        ]);

        $source = $this->conn->table('posts')
            ->select('title', 'name')
            ->join('users', 'posts.user_id', '=', 'users.id');

        $inserted = $this->conn->table('report')->insertSelect($source);

        $this->assertSame(2, $inserted);
        $this->assertSame([
            ['id' => 1, 'title' => 't1', 'name' => 'alice'],
            ['id' => 2, 'title' => 't2', 'name' => 'bob'],
        ], $this->rows('report'));
    }

    public function testSourceWithUnion(): void
    {
        $this->conn->createTable('merged', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('v', 16)->notNull();
        });
        $this->conn->createTable('a', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('v', 16)->notNull();
        });
        $this->conn->createTable('b', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('v', 16)->notNull();
        });
        $this->conn->table('a')->insert(['v' => 'x']);
        $this->conn->table('b')->insertMany([['v' => 'y'], ['v' => 'x']]);

        $source = $this->conn->table('a')->select('v')->union($this->conn->table('b')->select('v'));

        $inserted = $this->conn->table('merged')->insertSelect($source);

        // UNION 去重：x, y
        $this->assertSame(2, $inserted);
        $this->assertSame(['x', 'y'], array_column($this->rows('merged'), 'v'));
    }

    public function testSourceWithWhereInSubquery(): void
    {
        $this->conn->createTable('names', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull();
        });
        $this->conn->table('users')->insertMany([
            ['name' => 'alice'],
            ['name' => 'bob'],
        ]);
        $this->conn->table('posts')->insertMany([
            ['user_id' => 1, 'title' => 't1'],
            ['user_id' => 2, 'title' => 't2'],
            ['user_id' => 99, 'title' => 't3'],
        ]);

        $source = $this->conn->table('users')
            ->select('name')
            ->whereIn('id', $this->conn->table('posts')->select('user_id'));

        $inserted = $this->conn->table('names')->insertSelect($source);

        $this->assertSame(2, $inserted);
        $this->assertSame(['alice', 'bob'], array_column($this->rows('names'), 'name'));
    }

    // ---- 空结果集 / 未知列 / 自引用 ----

    public function testEmptySourceReturnsZero(): void
    {
        $inserted = $this->conn->table('dst')->insertSelect(
            $this->conn->table('src')->where('age', '>', 100),
        );

        $this->assertSame(0, $inserted);
        $this->assertSame([], $this->rows('dst'));
    }

    public function testUnknownColumnThrowsWithDiffInMessage(): void
    {
        // posts 的 title/user_id 在源查询中合法，但目标表 dst 无这些列 → 差集校验拦截
        $this->conn->table('posts')->insert(['user_id' => 1, 'title' => 't']);

        try {
            $this->conn->table('dst')->insertSelect($this->conn->table('posts')->select('title', 'user_id'));
            $this->fail('源含未知列未抛异常');
        } catch (QueryException $e) {
            $this->assertStringContainsString('title', $e->getMessage());
            $this->assertStringContainsString('user_id', $e->getMessage());
            $this->assertStringContainsString('dst', $e->getMessage());
        }

        // 目标表不变
        $this->assertSame([], $this->rows('dst'));
    }

    public function testSelfReferenceBaseTableThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('不支持引用目标表自身');
        $this->conn->table('dst')->insertSelect($this->conn->table('dst')->select('name'));
    }

    public function testSelfReferenceViaJoinThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('不支持引用目标表自身');
        $source = $this->conn->table('src')
            ->select('name')
            ->join('dst', 'src.age', '=', 'dst.id');
        $this->conn->table('dst')->insertSelect($source);
    }

    public function testSelfReferenceViaUnionThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('不支持引用目标表自身');
        $source = $this->conn->table('src')
            ->select('name')
            ->union($this->conn->table('dst')->select('name'));
        $this->conn->table('dst')->insertSelect($source);
    }

    // ---- 约束 / 原子性 / 触发器 / 自增 ----

    public function testConstraintViolationIsBatchAtomic(): void
    {
        // src.name 可空、dst.name NOT NULL：第二行 null 触发约束失败，首行也不得写入
        $this->conn->table('src')->insertMany([
            ['name' => 'ok', 'age' => 1],
            ['name' => null, 'age' => 2],
        ]);

        try {
            $this->conn->table('dst')->insertSelect($this->conn->table('src')->select('name', 'age'));
            $this->fail('NOT NULL 违反未抛异常');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('name', $e->getMessage());
        }

        // 批内原子：目标表整体不变
        $this->assertSame([], $this->rows('dst'));
    }

    public function testUniqueViolationIsBatchAtomic(): void
    {
        $this->conn->createTable('uniq_dst', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull()->unique();
        });
        $this->conn->createTable('uniq_src', static function (Blueprint $b): void {
            $b->varchar('name', 32)->notNull();
        });
        $this->conn->table('uniq_dst')->insert(['name' => 'taken']);
        $this->conn->table('uniq_src')->insertMany([['name' => 'fresh'], ['name' => 'taken']]);

        try {
            $this->conn->table('uniq_dst')->insertSelect(
                $this->conn->table('uniq_src')->select('name'),
            );
            $this->fail('唯一约束冲突未抛异常');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('唯一', $e->getMessage());
        }

        // 'fresh' 同批也不写入（批内原子）
        $rows = $this->rows('uniq_dst');
        $this->assertCount(1, $rows);
        $this->assertSame('taken', $rows[0]['name']);
    }

    public function testAfterInsertTriggerReceivesSourceRows(): void
    {
        $this->conn->table('src')->insertMany([
            ['name' => 'a', 'age' => 20],
            ['name' => 'b', 'age' => 30],
        ]);

        $received = [];
        $this->conn->createTrigger('dst', 'after', 'insert', static function (array $row) use (&$received): void {
            $received[] = $row;
        });

        $this->conn->table('dst')->insertSelect($this->conn->table('src')->select('name', 'age'));

        // AFTER INSERT 收到源数据行（最终形态：自增 id 已分配、默认值已填）
        $this->assertCount(2, $received);
        $this->assertSame(['id' => 1, 'name' => 'a', 'age' => 20, 'memo' => 'none'], $received[0]);
        $this->assertSame(['id' => 2, 'name' => 'b', 'age' => 30, 'memo' => 'none'], $received[1]);
    }

    public function testAutoIncrementAssignedContinuously(): void
    {
        $this->conn->table('dst')->insert(['name' => 'seed']);
        $this->conn->table('src')->insertMany([
            ['name' => 'a'],
            ['name' => 'b'],
            ['name' => 'c'],
        ]);

        $this->conn->table('dst')->insertSelect($this->conn->table('src')->select('name'));

        // 目标表已有 id=1：insertSelect 的自增 id 从 2 起连续分配
        $this->assertSame([1, 2, 3, 4], array_column($this->rows('dst'), 'id'));
    }
}
