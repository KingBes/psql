<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\ConstraintException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Condition\Comparison;
use Kingbes\Psql\Schema\Blueprint;
use Kingbes\Psql\Schema\ForeignKeyAction;
use PHPUnit\Framework\TestCase;

/**
 * REPLACE INTO（MySQL 语义）测试：冲突检测、删旧插新、受影响行数计数、
 * 约束先行校验、RESTRICT 外键拦截、触发器时序、自增分配
 */
final class ReplaceTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull()->unique();
            $b->int('age');
        });
        $this->conn->createTable('pairs', static function (Blueprint $b): void {
            $b->id();
            $b->int('a');
            $b->varchar('b', 8);
            $b->varchar('memo', 16);
            $b->unique('a', 'b');
        });
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(string $table): array
    {
        return $this->conn->engine()->readRows($this->conn->currentDatabase(), $table);
    }

    // ---- 无冲突：普通插入 ----

    public function testReplaceWithoutConflictActsAsInsert(): void
    {
        $affected = $this->conn->table('users')->replace(['name' => 'a', 'age' => 20]);

        $this->assertSame(1, $affected);
        $this->assertSame([['id' => 1, 'name' => 'a', 'age' => 20]], $this->rows('users'));
    }

    public function testReplaceWithoutExplicitPkNeverTriggersReplacement(): void
    {
        $this->conn->table('users')->insert(['id' => 5, 'name' => 'a']);

        // 未提供 PK：PK 元组跳过检测，name 也不冲突 → 纯插入，旧行保留
        $affected = $this->conn->table('users')->replace(['name' => 'b']);

        $this->assertSame(1, $affected);
        $this->assertSame(2, count($this->rows('users')));
        $this->assertSame('a', $this->rows('users')[0]['name']);
        $this->assertSame('b', $this->rows('users')[1]['name']);
        // 新行走自增分配（当前计数 5 → 跳过已用值分配 6）
        $this->assertSame(6, $this->rows('users')[1]['id']);
    }

    // ---- 冲突：删旧插新 ----

    public function testReplacePrimaryKeyConflictDeletesAndInserts(): void
    {
        $this->conn->table('users')->insert(['id' => 1, 'name' => 'a', 'age' => 20]);

        $affected = $this->conn->table('users')->replace(['id' => 1, 'name' => 'a2', 'age' => 30]);

        // MySQL：一次 replace 删 1 插 1 计 2
        $this->assertSame(2, $affected);
        $rows = $this->rows('users');
        $this->assertCount(1, $rows);
        // 新行可见、旧行消失（保留显式 PK）
        $this->assertSame(['id' => 1, 'name' => 'a2', 'age' => 30], $rows[0]);
    }

    public function testReplaceUniqueColumnConflictDeletesAndInserts(): void
    {
        $this->conn->table('users')->insert(['name' => 'a', 'age' => 20]);

        // 不带 PK、unique(name) 冲突：删旧插新，新行分配全新自增 id
        $affected = $this->conn->table('users')->replace(['name' => 'a', 'age' => 30]);

        $this->assertSame(2, $affected);
        $rows = $this->rows('users');
        $this->assertCount(1, $rows);
        $this->assertSame(30, $rows[0]['age']);
        $this->assertSame(2, $rows[0]['id']);
    }

    public function testReplaceCompositeUniqueConflict(): void
    {
        $this->conn->table('pairs')->insert(['a' => 1, 'b' => 'x', 'memo' => 'old']);

        $affected = $this->conn->table('pairs')->replace(['a' => 1, 'b' => 'x', 'memo' => 'new']);

        $this->assertSame(2, $affected);
        $rows = $this->rows('pairs');
        $this->assertCount(1, $rows);
        $this->assertSame('new', $rows[0]['memo']);
    }

    public function testReplaceHittingSameRowViaPkAndUniqueCountsOnce(): void
    {
        $this->conn->table('users')->insert(['id' => 1, 'name' => 'a', 'age' => 20]);

        // 新行同时违 PK(id=1) 与 unique(name=a)，但命中同一行：去重后删 1 插 1 计 2
        $affected = $this->conn->table('users')->replace(['id' => 1, 'name' => 'a', 'age' => 99]);

        $this->assertSame(2, $affected);
        $rows = $this->rows('users');
        $this->assertCount(1, $rows);
        $this->assertSame(99, $rows[0]['age']);
    }

    public function testReplaceHittingTwoDistinctRowsDeletesBoth(): void
    {
        $this->conn->table('users')->insertMany([
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ]);

        // PK 命中行 2、unique(name=a) 命中行 1：两行冲突行全部删除后插入
        $affected = $this->conn->table('users')->replace(['id' => 2, 'name' => 'a', 'age' => 1]);

        // 删 2 + 插 1 = 3
        $this->assertSame(3, $affected);
        $rows = $this->rows('users');
        $this->assertCount(1, $rows);
        $this->assertSame(['id' => 2, 'name' => 'a', 'age' => 1], $rows[0]);
    }

    // ---- replaceMany 混合计数 ----

    public function testReplaceManyMixedScenario(): void
    {
        $this->conn->table('users')->insert(['id' => 1, 'name' => 'a']);

        $affected = $this->conn->table('users')->replaceMany([
            ['name' => 'b'],                    // 无冲突：1
            ['id' => 1, 'name' => 'a', 'age' => 5],  // PK+unique 同行冲突：2
            ['name' => 'c'],                    // 无冲突：1
        ]);

        $this->assertSame(4, $affected);
        $rows = $this->rows('users');
        $this->assertCount(3, $rows);
        // 被替换的 id=1 行以新形态存在于表尾（删旧插新，非原地覆盖）
        $replaced = array_values(array_filter($rows, static fn (array $r): bool => $r['id'] === 1));
        $this->assertSame(5, $replaced[0]['age']);
    }

    public function testReplaceManyEmptyBatchReturnsZero(): void
    {
        $this->assertSame(0, $this->conn->table('users')->replaceMany([]));
        $this->assertSame([], $this->rows('users'));
    }

    // ---- 约束先行：失败时旧行保留 ----

    public function testReplaceNotNullFailureKeepsOldRow(): void
    {
        $this->conn->table('users')->insert(['id' => 1, 'name' => 'a', 'age' => 20]);

        try {
            $this->conn->table('users')->replace(['id' => 1, 'name' => null]);
            $this->fail('NOT NULL 违反未抛异常');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('name', $e->getMessage());
        }

        // 校验先于删除：旧行保留
        $this->assertSame([['id' => 1, 'name' => 'a', 'age' => 20]], $this->rows('users'));
    }

    public function testReplaceCheckFailureKeepsOldRow(): void
    {
        $this->conn->createTable('adults', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull()->unique();
            $b->int('age');
            $b->check('age_adult', new Comparison('age', '>=', 18));
        });
        $this->conn->table('adults')->insert(['id' => 1, 'name' => 'a', 'age' => 20]);

        try {
            $this->conn->table('adults')->replace(['id' => 1, 'name' => 'a', 'age' => 17]);
            $this->fail('CHECK 违反未抛异常');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('age_adult', $e->getMessage());
        }

        $this->assertSame([['id' => 1, 'name' => 'a', 'age' => 20]], $this->rows('adults'));
    }

    public function testReplaceForeignKeyViolationKeepsOldRow(): void
    {
        $this->conn->createTable('posts', static function (Blueprint $b): void {
            $b->id();
            $b->int('user_id');
            $b->varchar('title', 32)->notNull();
            $b->foreignKey('user_id')->references('users', 'id');
        });
        $this->conn->table('users')->insert(['id' => 1, 'name' => 'a']);

        // FK 存在性先行校验：新行 user_id 不存在 → 旧行不删
        try {
            $this->conn->table('posts')->replace(['id' => 1, 'user_id' => 999, 'title' => 't']);
            $this->fail('FK 违反未抛异常');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('user_id', $e->getMessage());
        }

        $this->assertSame([], $this->rows('posts'));
        $this->assertCount(1, $this->rows('users'));
    }

    // ---- RESTRICT 外键拦截（删除路径） ----

    public function testReplaceRestrictedByChildForeignKey(): void
    {
        $this->conn->createTable('child', static function (Blueprint $b): void {
            $b->id();
            $b->int('pid');
            $b->foreignKey('pid')->references('users', 'id')->onDelete(ForeignKeyAction::RESTRICT);
        });
        $this->conn->table('users')->insert(['id' => 1, 'name' => 'a', 'age' => 20]);
        $this->conn->table('child')->insert(['pid' => 1]);

        try {
            $this->conn->table('users')->replace(['id' => 1, 'name' => 'a2', 'age' => 30]);
            $this->fail('RESTRICT 外键未拦截 replace');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('RESTRICT', $e->getMessage());
        }

        // 旧行保留（删除在落盘前被拦截）
        $rows = $this->rows('users');
        $this->assertCount(1, $rows);
        $this->assertSame('a', $rows[0]['name']);
        $this->assertCount(1, $this->rows('child'));
    }

    public function testReplaceCascadeDeletesChildren(): void
    {
        $this->conn->createTable('child', static function (Blueprint $b): void {
            $b->id();
            $b->int('pid');
            $b->foreignKey('pid')->references('users', 'id')->onDeleteCascade();
        });
        $this->conn->table('users')->insert(['id' => 1, 'name' => 'a', 'age' => 20]);
        $this->conn->table('child')->insert(['pid' => 1]);

        $affected = $this->conn->table('users')->replace(['id' => 1, 'name' => 'a2', 'age' => 30]);

        $this->assertSame(2, $affected);
        $this->assertSame([['id' => 1, 'name' => 'a2', 'age' => 30]], $this->rows('users'));
        // 级联：子行随旧行删除
        $this->assertSame([], $this->rows('child'));
    }

    // ---- 触发器时序 ----

    public function testReplaceTriggerOrderDeleteThenInsert(): void
    {
        $this->conn->table('users')->insert(['id' => 1, 'name' => 'a']);

        $events = [];
        $this->conn->createTrigger('users', 'before', 'delete', static function (array $row) use (&$events): void {
            $events[] = 'before_delete:' . $row['name'];
        });
        $this->conn->createTrigger('users', 'after', 'delete', static function (array $row) use (&$events): void {
            $events[] = 'after_delete:' . $row['name'];
        });
        $this->conn->createTrigger('users', 'before', 'insert', static function (array $row) use (&$events): array {
            $events[] = 'before_insert:' . ($row['name'] ?? '?');
            return $row;
        });
        $this->conn->createTrigger('users', 'after', 'insert', static function (array $row) use (&$events): void {
            $events[] = 'after_insert:' . $row['name'];
        });

        $this->conn->table('users')->replace(['id' => 1, 'name' => 'a2']);

        // REPLACE 的删除是真实 DELETE：BEFORE DELETE → AFTER DELETE → BEFORE INSERT → AFTER INSERT
        $this->assertSame([
            'before_delete:a',
            'after_delete:a',
            'before_insert:a2',
            'after_insert:a2',
        ], $events);
    }

    // ---- 唯一约束保持 ----

    public function testReplaceKeepsUniqueConstraintEnforced(): void
    {
        $this->conn->table('users')->insertMany([
            ['name' => 'a'],
            ['name' => 'b'],
        ]);

        // 新行 id=2（命中行 2）且 name=a（命中行 1）：两行删除后插入 → 无残留冲突
        $affected = $this->conn->table('users')->replace(['id' => 2, 'name' => 'a']);

        $this->assertSame(3, $affected);
        $rows = $this->rows('users');
        $this->assertCount(1, $rows);
        $this->assertSame('a', $rows[0]['name']);
    }
}
