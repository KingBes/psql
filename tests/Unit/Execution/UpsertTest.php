<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\ConstraintException;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Exception\TypeException;
use Kingbes\Psql\Execution\Writer;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Condition\Comparison;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * UPSERT / INSERT IGNORE 测试：冲突检测语义、返回值约定、约束校验、Table 入口
 */
final class UpsertTest extends TestCase
{
    private Connection $conn;

    private Writer $writer;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->writer = new Writer($this->conn);
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

    // ---- upsert：插入路径 ----

    public function testUpsertInsertsNewRowReturnsOne(): void
    {
        $affected = $this->writer->upsert('users', null, ['name' => 'a', 'age' => 20]);

        $this->assertSame(1, $affected);
        $this->assertCount(1, $this->rows('users'));
        $this->assertSame('a', $this->rows('users')[0]['name']);
    }

    public function testUpsertWithoutConflictAssignsAutoIncrement(): void
    {
        $this->writer->upsert('users', null, ['name' => 'a']);
        $this->writer->upsert('users', null, ['name' => 'b']);

        $this->assertSame([1, 2], array_column($this->rows('users'), 'id'));
    }

    // ---- upsert：更新路径 ----

    public function testUpsertHitsUniqueColumnReturnsTwoAndOverwrites(): void
    {
        $this->writer->insert('users', null, [['name' => 'a', 'age' => 20]]);

        $affected = $this->writer->upsert('users', null, ['name' => 'a', 'age' => 30]);

        $this->assertSame(2, $affected);
        // 行数不变，覆盖列生效
        $rows = $this->rows('users');
        $this->assertCount(1, $rows);
        $this->assertSame(30, $rows[0]['age']);
        $this->assertSame(1, $rows[0]['id']);
    }

    public function testUpsertHitsPrimaryKey(): void
    {
        $this->writer->insert('users', null, [['id' => 5, 'name' => 'a', 'age' => 20]]);

        $affected = $this->writer->upsert('users', null, ['id' => 5, 'name' => 'b']);

        $this->assertSame(2, $affected);
        $rows = $this->rows('users');
        $this->assertCount(1, $rows);
        $this->assertSame('b', $rows[0]['name']);
    }

    public function testUpsertHitsCompositeUnique(): void
    {
        $this->writer->insert('pairs', null, [['a' => 1, 'b' => 'x', 'memo' => 'old']]);

        $affected = $this->writer->upsert('pairs', null, ['a' => 1, 'b' => 'x', 'memo' => 'new']);

        $this->assertSame(2, $affected);
        $rows = $this->rows('pairs');
        $this->assertCount(1, $rows);
        $this->assertSame('new', $rows[0]['memo']);
    }

    public function testUpsertAutoIncrementPkNotProvidedSkipsPkDetection(): void
    {
        // PK 为 AI 且未提供：候选行 PK 为 null → PK 元组跳过，仅 unique(name) 可命中
        $this->writer->insert('users', null, [['name' => 'a', 'age' => 20]]);

        $affected = $this->writer->upsert('users', null, ['name' => 'a', 'age' => 30]);

        $this->assertSame(2, $affected);
        $rows = $this->rows('users');
        $this->assertCount(1, $rows);
        $this->assertSame(30, $rows[0]['age']);
    }

    public function testUpsertAmbiguousHitsThrows(): void
    {
        $this->writer->insert('users', null, [
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ]);

        // id=2 命中第 2 行（PK），name=a 命中第 1 行（unique）→ 歧义
        try {
            $this->writer->upsert('users', null, ['id' => 2, 'name' => 'a']);
            $this->fail('多行命中未抛歧义异常');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('歧义', $e->getMessage());
            $this->assertStringContainsString('users', $e->getMessage());
        }

        // 表不变
        $this->assertCount(2, $this->rows('users'));
        $this->assertSame('b', $this->rows('users')[1]['name']);
    }

    // ---- upsert：更新路径约束违反 ----

    public function testUpsertUpdateViolatesCheckThrowsAndKeepsTable(): void
    {
        $this->conn->createTable('adults', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull()->unique();
            $b->int('age');
            $b->check('age_adult', new Comparison('age', '>=', 18));
        });
        $this->writer->insert('adults', null, [['name' => 'a', 'age' => 20]]);

        try {
            $this->writer->upsert('adults', null, ['name' => 'a', 'age' => 17]);
            $this->fail('upsert 更新路径违反 CHECK 未抛异常');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('age_adult', $e->getMessage());
        }

        $rows = $this->rows('adults');
        $this->assertCount(1, $rows);
        $this->assertSame(20, $rows[0]['age']);
    }

    public function testUpsertUpdateViolatesNotNullThrows(): void
    {
        $this->writer->insert('users', null, [['name' => 'a', 'age' => 20]]);

        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('name');
        $this->writer->upsert('users', null, ['name' => null]);
    }

    public function testUpsertUpdateViolatesTypeThrowsAndKeepsTable(): void
    {
        $this->writer->insert('users', null, [['name' => 'a', 'age' => 20]]);

        try {
            $this->writer->upsert('users', null, ['name' => 'a', 'age' => 'abc']);
            $this->fail('upsert 类型错误未抛异常');
        } catch (TypeException) {
            // 候选行 cast 阶段拦截
        }

        $rows = $this->rows('users');
        $this->assertCount(1, $rows);
        $this->assertSame(20, $rows[0]['age']);
    }

    public function testUpsertMultipleConstraintsHittingSameRowIsNotAmbiguous(): void
    {
        $this->writer->insert('users', null, [
            ['name' => 'a'],
            ['name' => 'b'],
        ]);

        // PK 与 unique(name) 同时命中第 1 行（同一行去重）→ 更新路径；
        // 显式提供与命中行相等的 AI 主键为无害覆盖
        $affected = $this->writer->upsert('users', null, ['id' => 1, 'name' => 'a', 'age' => 40]);

        $this->assertSame(2, $affected);
        $rows = $this->rows('users');
        $this->assertCount(2, $rows);
        $this->assertSame(40, $rows[0]['age']);
        $this->assertSame(1, $rows[0]['id']);
        $this->assertNull($rows[1]['age']);
    }

    public function testUpsertTableMissingThrows(): void
    {
        $this->expectException(StorageException::class);
        $this->writer->upsert('ghost', null, ['name' => 'a']);
    }

    public function testUpsertUnknownColumnThrows(): void
    {
        $this->expectException(\Kingbes\Psql\Exception\QueryException::class);
        $this->expectExceptionMessage('foo');
        $this->writer->upsert('users', null, ['name' => 'a', 'foo' => 1]);
    }

    // ---- insertIgnore ----

    public function testInsertIgnoreConflictReturnsZeroAndKeepsAutoIncrement(): void
    {
        $this->writer->insert('users', null, [['name' => 'a']]);
        $db = $this->conn->currentDatabase();
        $aiBefore = $this->conn->engine()->autoIncrement($db, 'users');

        $affected = $this->writer->insertIgnore('users', null, ['name' => 'a', 'age' => 99]);

        $this->assertSame(0, $affected);
        // 表数据与 AI 计数不变
        $this->assertCount(1, $this->rows('users'));
        $this->assertNull($this->rows('users')[0]['age']);
        $this->assertSame($aiBefore, $this->conn->engine()->autoIncrement($db, 'users'));

        // 再插新行 AI 连续
        $result = $this->writer->insert('users', null, [['name' => 'b']]);
        $this->assertSame(2, $result->lastInsertId());
    }

    public function testInsertIgnoreNoConflictReturnsOne(): void
    {
        $affected = $this->writer->insertIgnore('users', null, ['name' => 'a', 'age' => 20]);

        $this->assertSame(1, $affected);
        $rows = $this->rows('users');
        $this->assertCount(1, $rows);
        $this->assertSame(20, $rows[0]['age']);
    }

    public function testInsertIgnorePrimaryKeyConflictReturnsZero(): void
    {
        $this->writer->insert('users', null, [['id' => 7, 'name' => 'a']]);

        $this->assertSame(0, $this->writer->insertIgnore('users', null, ['id' => 7, 'name' => 'b']));
        $this->assertCount(1, $this->rows('users'));
        $this->assertSame('a', $this->rows('users')[0]['name']);
    }

    public function testInsertIgnoreStillThrowsOnTypeViolation(): void
    {
        $this->expectException(TypeException::class);
        $this->writer->insertIgnore('users', null, ['name' => 'a', 'age' => 'abc']);
    }

    public function testInsertIgnoreStillThrowsOnNotNull(): void
    {
        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('name');
        $this->writer->insertIgnore('users', null, ['name' => null]);
    }

    public function testInsertIgnoreStillThrowsOnForeignKeyViolation(): void
    {
        $this->conn->createTable('posts', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id');
            $b->varchar('title', 32)->notNull();
            $b->foreignKey('user_id')->references('users', 'id');
        });
        $this->writer->insert('users', null, [['name' => 'u1']]);

        // 无唯一冲突，FK 违反照常抛（不是静默）
        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('user_id');
        $this->writer->insertIgnore('posts', null, ['user_id' => 999, 'title' => 't']);
    }

    public function testInsertIgnoreStillThrowsOnCheckViolation(): void
    {
        $this->conn->createTable('adults', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull()->unique();
            $b->int('age');
            $b->check('age_adult', new Comparison('age', '>=', 18));
        });

        // 无唯一冲突，CHECK 违反照常抛
        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('age_adult');
        $this->writer->insertIgnore('adults', null, ['name' => 'a', 'age' => 17]);
    }

    // ---- Table 入口端到端 ----

    public function testTableEntryUpsertEndToEnd(): void
    {
        $table = $this->conn->table('users');

        $this->assertSame(1, $table->upsert(['name' => 'a', 'age' => 20]));
        $this->assertSame(2, $table->upsert(['name' => 'a', 'age' => 30]));
        $this->assertSame(1, $table->upsert(['name' => 'b']));

        $rows = $this->rows('users');
        $this->assertCount(2, $rows);
        $this->assertSame(30, $rows[0]['age']);
        $this->assertSame(2, $rows[1]['id']);
    }

    public function testTableEntryInsertIgnoreEndToEnd(): void
    {
        $table = $this->conn->table('users');

        $this->assertSame(1, $table->insertIgnore(['name' => 'a']));
        $this->assertSame(0, $table->insertIgnore(['name' => 'a']));
        $this->assertCount(1, $this->rows('users'));
    }
}
