<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\ConstraintException;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Exception\TypeException;
use Kingbes\Psql\Execution\Writer;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 写入约束管线测试：insert 补全/自增/唯一/外键、update 约束、delete 级联、truncate
 */
final class WriterTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull()->unique();
            $b->int('age')->default(18);
            $b->datetime('created_at')->defaultNow();
        });
        $this->conn->createTable('posts', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id');
            $b->varchar('title', 64)->notNull();
            $b->foreignKey('user_id')->references('users', 'id');
        });
        $this->conn->createTable('comments', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('post_id');
            $b->foreignKey('post_id')->references('posts', 'id')->onDeleteCascade();
        });
    }

    private function writer(): Writer
    {
        return new Writer($this->conn);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(string $table): array
    {
        return $this->conn->engine()->readRows($this->conn->currentDatabase(), $table);
    }

    private function where(string $column, mixed $value): ConditionGroup
    {
        return (new ConditionGroup())->where($column, $value);
    }

    // ---- INSERT ----

    public function testInsertFillsDefaultsAndAutoIncrement(): void
    {
        $result = $this->writer()->insert('users', null, [['name' => '张三']]);

        $this->assertSame(1, $result->rowCount());
        $this->assertSame(1, $result->lastInsertId());

        $row = $this->rows('users')[0];
        $this->assertSame(1, $row['id']);
        $this->assertSame('张三', $row['name']);
        // default 列回填
        $this->assertSame(18, $row['age']);
        // defaultNow 列回填为 datetime 字符串
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['created_at']);

        $this->assertSame(2, $this->writer()->insert('users', null, [['name' => '李四']])->lastInsertId());
    }

    public function testInsertEmptyBatchReturnsZeroAndNullId(): void
    {
        $result = $this->writer()->insert('users', null, []);

        $this->assertSame(0, $result->rowCount());
        $this->assertNull($result->lastInsertId());
        $this->assertSame([], $this->rows('users'));
    }

    public function testInsertLastInsertIdNullWithoutAutoIncrement(): void
    {
        $this->conn->createTable('logs', static function (Blueprint $b): void {
            $b->bigint('id')->primaryKey();
            $b->text('message');
        });

        $result = $this->writer()->insert('logs', null, [['id' => 7, 'message' => 'm']]);

        $this->assertSame(1, $result->rowCount());
        $this->assertNull($result->lastInsertId());
    }

    public function testInsertUnknownColumnThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('foo');
        $this->writer()->insert('users', null, [['name' => 'a', 'foo' => 1]]);
    }

    public function testInsertNullViolatesNotNullThrows(): void
    {
        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('name');
        $this->writer()->insert('users', null, [['name' => null]]);
    }

    public function testInsertInvalidTypeThrows(): void
    {
        $this->expectException(TypeException::class);
        $this->writer()->insert('users', null, [['name' => 'a', 'age' => 'abc']]);
    }

    public function testInsertTableMissingThrows(): void
    {
        $this->expectException(StorageException::class);
        $this->writer()->insert('ghost', null, [['name' => 'a']]);
    }

    public function testInsertAutoIncrementSkipsUsedValues(): void
    {
        // 直插数据占据 id 1、2（自增计数仍为 0）
        $db = $this->conn->currentDatabase();
        $this->conn->engine()->writeRows($db, 'users', [
            ['id' => 1, 'name' => 'a', 'age' => null, 'created_at' => null],
            ['id' => 2, 'name' => 'b', 'age' => null, 'created_at' => null],
        ]);

        $this->assertSame(3, $this->writer()->insert('users', null, [['name' => 'c']])->lastInsertId());
    }

    public function testInsertExplicitAutoIncrementAndAdvance(): void
    {
        $result = $this->writer()->insert('users', null, [
            ['name' => 'a'],             // 自动 1
            ['id' => 5, 'name' => 'b'],  // 显式 5
            ['name' => 'c'],             // 自动 2
        ]);

        $this->assertSame([1, 5, 2], array_column($this->rows('users'), 'id'));
        // lastInsertId = 批次最后一行的自增值（2，而非最大值 5）
        $this->assertSame(2, $result->lastInsertId());
        // 批次最大已用值推进引擎计数
        $db = $this->conn->currentDatabase();
        $this->assertSame(5, $this->conn->engine()->autoIncrement($db, 'users'));
        // 后续自动分配从 6 起
        $this->assertSame(6, $this->writer()->insert('users', null, [['name' => 'd']])->lastInsertId());
    }

    public function testInsertUniqueColumnConflictThrows(): void
    {
        $this->writer()->insert('users', null, [['name' => 'a']]);

        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('name');
        $this->writer()->insert('users', null, [['name' => 'a']]);
    }

    public function testInsertBatchConflictKeepsTableUnchanged(): void
    {
        try {
            $this->writer()->insert('users', null, [['name' => 'a'], ['name' => 'a']]);
            $this->fail('批内唯一冲突未抛异常');
        } catch (ConstraintException) {
            // 约束失败时批次不落库（原子性）
        }

        $this->assertSame([], $this->rows('users'));
    }

    public function testInsertPrimaryKeyConflictThrows(): void
    {
        $this->writer()->insert('users', null, [['id' => 1, 'name' => 'a']]);

        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('id');
        $this->writer()->insert('users', null, [['id' => 1, 'name' => 'b']]);
    }

    public function testInsertCompositeUniqueSkipsNullTuple(): void
    {
        $this->conn->createTable('pairs', static function (Blueprint $b): void {
            $b->id();
            $b->int('a');
            $b->varchar('b', 8);
            $b->unique('a', 'b');
        });

        // 含 null 的组合跳过唯一检查（MySQL 多 NULL 语义）
        $result = $this->writer()->insert('pairs', null, [
            ['a' => null, 'b' => 'x'],
            ['a' => null, 'b' => 'x'],
        ]);
        $this->assertSame(2, $result->rowCount());

        $this->expectException(ConstraintException::class);
        $this->writer()->insert('pairs', null, [
            ['a' => 1, 'b' => 'y'],
            ['a' => 1, 'b' => 'y'],
        ]);
    }

    public function testInsertForeignKeyExistence(): void
    {
        $this->writer()->insert('users', null, [['name' => 'u1']]);

        // null 外键合法
        $this->writer()->insert('posts', null, [['user_id' => null, 'title' => 't0']]);
        // 存在的引用值合法
        $this->writer()->insert('posts', null, [['user_id' => 1, 'title' => 't1']]);

        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('user_id');
        $this->writer()->insert('posts', null, [['user_id' => 999, 'title' => 't2']]);
    }

    // ---- UPDATE ----

    public function testUpdateMatchedCount(): void
    {
        $this->writer()->insert('users', null, [
            ['name' => 'a'],
            ['name' => 'b'],
            ['name' => 'c'],
        ]);

        // 全表更新
        $this->assertSame(3, $this->writer()->update('users', null, null, ['age' => 20]));
        $this->assertSame([20, 20, 20], array_column($this->rows('users'), 'age'));

        // 条件更新
        $this->assertSame(1, $this->writer()->update('users', null, $this->where('name', 'a'), ['age' => 21]));
        // 条件不命中
        $this->assertSame(0, $this->writer()->update('users', null, $this->where('name', 'zz'), ['age' => 30]));
    }

    public function testUpdateUnknownColumnThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('foo');
        $this->writer()->update('users', null, null, ['foo' => 1]);
    }

    public function testUpdateNullViolatesNotNullThrows(): void
    {
        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('name');
        $this->writer()->update('users', null, null, ['name' => null]);
    }

    public function testUpdateUniqueExcludesSelf(): void
    {
        $this->writer()->insert('users', null, [['name' => 'a']]);

        // 更新为自身相同值不视为冲突
        $this->assertSame(1, $this->writer()->update('users', null, $this->where('name', 'a'), ['name' => 'a']));
    }

    public function testUpdateUniqueConflictThrows(): void
    {
        $this->writer()->insert('users', null, [['name' => 'a'], ['name' => 'b']]);

        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('name');
        $this->writer()->update('users', null, $this->where('name', 'b'), ['name' => 'a']);
    }

    public function testUpdateForeignKeyValueExistence(): void
    {
        $this->writer()->insert('users', null, [['name' => 'u1'], ['name' => 'u2']]);
        $this->writer()->insert('posts', null, [['user_id' => 1, 'title' => 't']]);

        // 变更为存在的引用值合法
        $this->assertSame(1, $this->writer()->update('posts', null, $this->where('title', 't'), ['user_id' => 2]));

        // 变更为不存在的值抛约束异常
        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('user_id');
        $this->writer()->update('posts', null, $this->where('title', 't'), ['user_id' => 999]);
    }

    public function testUpdateReferencedColumnRestrict(): void
    {
        $this->writer()->insert('users', null, [['name' => 'u1']]);
        $this->writer()->insert('posts', null, [['user_id' => 1, 'title' => 't']]);

        // 被引用列（users.id）变更被 RESTRICT 拦截
        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('RESTRICT');
        $this->writer()->update('users', null, $this->where('id', 1), ['id' => 99]);
    }

    public function testUpdateNonReferencedColumnSucceeds(): void
    {
        $this->writer()->insert('users', null, [['name' => 'u1']]);
        $this->writer()->insert('posts', null, [['user_id' => 1, 'title' => 't']]);

        // 未变更被引用列的更新不受影响
        $this->assertSame(1, $this->writer()->update('users', null, $this->where('id', 1), ['age' => 30]));
    }

    // ---- DELETE ----

    public function testDeleteRestrict(): void
    {
        $this->writer()->insert('users', null, [['name' => 'u1'], ['name' => 'u2']]);
        $this->writer()->insert('posts', null, [['user_id' => 1, 'title' => 't']]);

        // 被引用行禁止删除
        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('RESTRICT');
        $this->writer()->delete('users', null, $this->where('id', 1));
    }

    public function testDeleteUnreferencedRowSucceeds(): void
    {
        $this->writer()->insert('users', null, [['name' => 'u1'], ['name' => 'u2']]);
        $this->writer()->insert('posts', null, [['user_id' => 1, 'title' => 't']]);

        $this->assertSame(1, $this->writer()->delete('users', null, $this->where('id', 2)));
        $this->assertCount(1, $this->rows('users'));
    }

    public function testDeleteCascadeChain(): void
    {
        // 三级链 a <- b <- c（全 CASCADE）
        $this->conn->createTable('a', static function (Blueprint $b): void {
            $b->id();
        });
        $this->conn->createTable('b', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('a_id');
            $b->foreignKey('a_id')->references('a', 'id')->onDeleteCascade();
        });
        $this->conn->createTable('c', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('b_id');
            $b->foreignKey('b_id')->references('b', 'id')->onDeleteCascade();
        });
        $this->writer()->insert('a', null, [['id' => 1]]);
        $this->writer()->insert('b', null, [['id' => 10, 'a_id' => 1]]);
        $this->writer()->insert('c', null, [['id' => 100, 'b_id' => 10]]);

        // 返回初始 matched 数（级联不计入）
        $this->assertSame(1, $this->writer()->delete('a', null, $this->where('id', 1)));
        $this->assertSame([], $this->rows('a'));
        $this->assertSame([], $this->rows('b'));
        $this->assertSame([], $this->rows('c'));
    }

    public function testDeleteTwoLevelCascade(): void
    {
        $this->writer()->insert('users', null, [['name' => 'u1']]);
        $this->writer()->insert('posts', null, [['user_id' => 1, 'title' => 't']]);
        $this->writer()->insert('comments', null, [['post_id' => 1]]);

        // posts <- comments 为 CASCADE
        $this->assertSame(1, $this->writer()->delete('posts', null, $this->where('id', 1)));
        $this->assertSame([], $this->rows('comments'));
        $this->assertCount(1, $this->rows('users'));
    }

    public function testDeleteSelfReferenceCascade(): void
    {
        $this->conn->createTable('categories', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('label', 16);
            $b->bigint('parent_id');
            $b->foreignKey('parent_id')->references('categories', 'id')->onDeleteCascade();
        });
        $this->writer()->insert('categories', null, [['id' => 1, 'label' => 'root', 'parent_id' => null]]);
        $this->writer()->insert('categories', null, [['id' => 2, 'label' => 'mid', 'parent_id' => 1]]);
        $this->writer()->insert('categories', null, [['id' => 3, 'label' => 'leaf', 'parent_id' => 2]]);

        // 删除根节点级联删除整条链
        $this->assertSame(1, $this->writer()->delete('categories', null, $this->where('id', 1)));
        $this->assertSame([], $this->rows('categories'));
    }

    public function testDeleteSelfReferenceRestrictSimplification(): void
    {
        $this->conn->createTable('nodes', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('parent_id');
            $b->foreignKey('parent_id')->references('nodes', 'id');
        });
        $this->writer()->insert('nodes', null, [['id' => 1, 'parent_id' => null]]);
        $this->writer()->insert('nodes', null, [['id' => 2, 'parent_id' => 1]]);

        // 引用行不在删除集合中 → RESTRICT
        try {
            $this->writer()->delete('nodes', null, $this->where('id', 1));
            $this->fail('自引用 RESTRICT 未抛异常');
        } catch (ConstraintException) {
            $this->assertCount(2, $this->rows('nodes'));
        }

        // 引用行同批删除（已在删除集合）→ 简化规则放行
        $where = (new ConditionGroup())->whereIn('id', [1, 2]);
        $this->assertSame(2, $this->writer()->delete('nodes', null, $where));
        $this->assertSame([], $this->rows('nodes'));
    }

    public function testDeleteNoMatchReturnsZero(): void
    {
        $this->writer()->insert('users', null, [['name' => 'a']]);

        $this->assertSame(0, $this->writer()->delete('users', null, $this->where('name', 'zz')));
        $this->assertCount(1, $this->rows('users'));
    }

    // ---- TRUNCATE ----

    public function testTruncateClearsRowsAndResetsAutoIncrement(): void
    {
        // 独立无引用表：truncate 清数据 + 自增归零
        $this->conn->createTable('notes', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('body', 16);
        });
        $this->writer()->insert('notes', null, [['body' => 'a'], ['body' => 'b']]);
        $this->writer()->truncate('notes');

        $this->assertSame([], $this->rows('notes'));
        $db = $this->conn->currentDatabase();
        $this->assertSame(0, $this->conn->engine()->autoIncrement($db, 'notes'));

        // 归零后重新从 1 分配
        $this->assertSame(1, $this->writer()->insert('notes', null, [['body' => 'c']])->lastInsertId());
    }

    public function testTruncateReferencedTableThrows(): void
    {
        // posts.user_id 外键引用 users，truncate 被拦截
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('users');
        $this->writer()->truncate('users');
    }

    public function testTruncateUnreferencedTableSucceeds(): void
    {
        $this->conn->createTable('notes', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('body', 16);
        });
        $this->writer()->insert('notes', null, [['body' => 'a']]);
        $this->writer()->insert('users', null, [['name' => 'a']]);
        $this->writer()->truncate('notes');

        $this->assertSame([], $this->rows('notes'));
        $this->assertCount(1, $this->rows('users'));
    }
}
