<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\ConstraintException;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\AlterBlueprint;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 复合主键（v1.3）端到端测试：DSL 校验、写入唯一性/隐含 NOT NULL、
 * update 元组冲突、PK 自动索引（含键序翻转）、单列/自增兼容、DDL 拦截与持久化
 */
final class CompositePrimaryKeyTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('pairs', static function (Blueprint $b): void {
            $b->int('a');
            $b->varchar('b', 16);
            $b->primary('a', 'b');
        });
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(string $table): array
    {
        return $this->conn->engine()->readRows($this->conn->currentDatabase(), $table);
    }

    // ---- DSL 校验 ----

    public function testPrimaryWithoutColumnsThrows(): void
    {
        $blueprint = new Blueprint();

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('至少需要一列');
        $blueprint->primary();
    }

    public function testPrimaryReferencesUndefinedColumnThrows(): void
    {
        $blueprint = new Blueprint();
        $blueprint->int('a');

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('missing');
        $blueprint->primary('a', 'missing');
    }

    public function testPrimaryDuplicateColumnThrows(): void
    {
        $blueprint = new Blueprint();
        $blueprint->int('a');

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('重复');
        $blueprint->primary('a', 'a');
    }

    // ---- INSERT 唯一性与隐含 NOT NULL ----

    public function testInsertDuplicateTupleThrowsAndKeepsTableUnchanged(): void
    {
        $this->conn->table('pairs')->insert(['a' => 1, 'b' => 'x']);

        try {
            $this->conn->table('pairs')->insert(['a' => 1, 'b' => 'x']);
            $this->fail('重复主键元组未抛异常');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('a', $e->getMessage());
            $this->assertStringContainsString('b', $e->getMessage());
        }

        // 约束失败时表数据不变
        $this->assertCount(1, $this->rows('pairs'));
    }

    public function testInsertSharingSingleColumnIsLegal(): void
    {
        // 同 a 不同 b 合法
        $this->conn->table('pairs')->insertMany([
            ['a' => 1, 'b' => 'x'],
            ['a' => 1, 'b' => 'y'],
        ]);
        // 同 b 不同 a 合法
        $this->conn->table('pairs')->insert(['a' => 2, 'b' => 'x']);

        $this->assertCount(3, $this->rows('pairs'));
    }

    public function testInsertNullPrimaryKeyColumnThrows(): void
    {
        try {
            $this->conn->table('pairs')->insert(['a' => null, 'b' => 'x']);
            $this->fail('主键列 a 为 null 未抛异常');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('pairs', $e->getMessage());
            $this->assertStringContainsString('a', $e->getMessage());
        }

        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('b');
        $this->conn->table('pairs')->insert(['a' => 1, 'b' => null]);
    }

    // ---- UPDATE 元组唯一性 ----

    public function testUpdateToDuplicateTupleThrows(): void
    {
        $this->conn->table('pairs')->insertMany([
            ['a' => 1, 'b' => 'x'],
            ['a' => 2, 'b' => 'y'],
        ]);

        try {
            $this->conn->table('pairs')->where('a', 2)->update(['a' => 1, 'b' => 'x']);
            $this->fail('更新为重复元组未抛异常');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('a', $e->getMessage());
        }

        // 更新失败时表数据不变
        $rows = $this->rows('pairs');
        $this->assertSame(2, $rows[1]['a']);
        $this->assertSame('y', $rows[1]['b']);
    }

    public function testUpdateToFreshTupleSucceeds(): void
    {
        $this->conn->table('pairs')->insertMany([
            ['a' => 1, 'b' => 'x'],
            ['a' => 2, 'b' => 'y'],
        ]);

        $affected = $this->conn->table('pairs')->where('a', 2)->update(['a' => 3, 'b' => 'y']);

        $this->assertSame(1, $affected);
        $rows = $this->rows('pairs');
        $this->assertSame(3, $rows[1]['a']);
        $this->assertSame('y', $rows[1]['b']);
    }

    public function testUpdatePrimaryKeyColumnToNullThrows(): void
    {
        $this->conn->table('pairs')->insert(['a' => 1, 'b' => 'x']);

        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('a');
        $this->conn->table('pairs')->where('a', 1)->update(['a' => null]);
    }

    // ---- UPSERT 命中复合元组 ----

    public function testUpsertHitsCompositeTuple(): void
    {
        $this->conn->table('pairs')->insert(['a' => 1, 'b' => 'x']);

        // 命中既有元组 → 更新该行返回 2
        $this->assertSame(2, $this->conn->table('pairs')->upsert(['a' => 1, 'b' => 'x']));
        $this->assertCount(1, $this->rows('pairs'));

        // 新元组 → 插入返回 1
        $this->assertSame(1, $this->conn->table('pairs')->upsert(['a' => 1, 'b' => 'y']));
        $this->assertCount(2, $this->rows('pairs'));
    }

    // ---- PK 自动索引 ----

    public function testCompositePrimaryKeyEqualityQueryMatchesFullScan(): void
    {
        $this->conn->table('pairs')->insertMany([
            ['a' => 1, 'b' => 'x'],
            ['a' => 1, 'b' => 'y'],
            ['a' => 2, 'b' => 'x'],
            ['a' => 2, 'b' => 'y'],
        ]);

        // 等值双列条件可走 PK 自动索引
        $indexed = $this->conn->table('pairs')
            ->where('a', '=', 2)
            ->where('b', '=', 'y')
            ->first();
        $this->assertNotNull($indexed);
        $this->assertSame(2, $indexed['a']);
        $this->assertSame('y', $indexed['b']);

        // whereNotIn 迫使条件组含非等值条件 → 回退全表扫描，结果必须一致
        $scanned = $this->conn->table('pairs')
            ->where('a', '=', 2)
            ->where('b', '=', 'y')
            ->whereNotIn('a', [999999])
            ->first();
        $this->assertSame($indexed, $scanned);

        // IndexManager 层面：复合 PK 列集自动可作等值索引
        $lookup = $this->conn->indexManager()->lookup('pairs', ['a', 'b'], [2, 'y']);
        $this->assertNotNull($lookup);
        $this->assertSame([3], $lookup);
    }

    public function testIndexManagerLookupWithReversedColumnOrder(): void
    {
        $this->conn->table('pairs')->insertMany([
            ['a' => 1, 'b' => 'x'],
            ['a' => 1, 'b' => 'y'],
            ['a' => 2, 'b' => 'x'],
            ['a' => 2, 'b' => 'y'],
        ]);

        // 条件列序翻转（b,a）也应命中同一行——键序错位会静默漏行（v1.2 键序 bug 教训）
        $this->assertSame([3], $this->conn->indexManager()->lookup('pairs', ['b', 'a'], ['y', 2]));
        $this->assertSame([0], $this->conn->indexManager()->lookup('pairs', ['b', 'a'], ['x', 1]));
        $this->assertSame([1], $this->conn->indexManager()->lookup('pairs', ['b', 'a'], ['y', 1]));

        // 未命中返回空列表（而非 null：列集已匹配索引）
        $this->assertSame([], $this->conn->indexManager()->lookup('pairs', ['b', 'a'], ['z', 9]));
    }

    public function testPrimaryKeyIndexIsBuiltInAndNotDroppable(): void
    {
        // PK 自动索引不注册为显式 TableIndex
        $this->assertFalse($this->conn->hasIndex('pairs', 'idx_a_b'));

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('索引不存在');
        $this->conn->dropIndex('pairs', 'idx_a_b');
    }

    // ---- 单列与自增兼容 ----

    public function testSingleColumnPrimaryViaPrimaryMethod(): void
    {
        $this->conn->createTable('solo', static function (Blueprint $b): void {
            $b->int('a');
            $b->varchar('name', 16);
            $b->primary('a');
        });

        $schema = $this->conn->engine()->loadSchema('main', 'solo');
        // 单列主键：primaryKey() 保持单列语义
        $this->assertSame('a', $schema->primaryKey()?->name);
        $this->assertSame(['a'], array_map(
            static fn ($column): string => $column->name,
            $schema->primaryKeyColumns(),
        ));

        $this->conn->table('solo')->insert(['a' => 1, 'name' => 'n1']);

        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('a');
        $this->conn->table('solo')->insert(['a' => 1, 'name' => 'n2']);
    }

    public function testAutoIncrementSinglePrimaryKeyUnaffected(): void
    {
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 16)->notNull();
        });

        $result = $this->conn->table('users')->insertMany([['name' => 'a'], ['name' => 'b']]);

        $this->assertSame(2, $result->rowCount());
        $this->assertSame(2, $result->lastInsertId());
        $this->assertSame([1, 2], array_column($this->rows('users'), 'id'));

        // find 按单列主键仍可用
        $this->assertSame('a', $this->conn->table('users')->find(1)['name'] ?? null);
    }

    public function testAutoIncrementInsideCompositePrimaryKeyThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('id');
        $this->conn->createTable('bad', static function (Blueprint $b): void {
            $b->id();
            $b->int('b');
            $b->primary('id', 'b');
        });
    }

    public function testAutoIncrementWithForeignPrimaryKeyThrows(): void
    {
        // 自增列存在时主键必须恰好为该单列：主键指向其他列同样非法
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('id');
        $this->conn->createTable('bad', static function (Blueprint $b): void {
            $b->id();
            $b->int('b');
            $b->primary('b');
        });
    }

    // ---- DDL 拦截 ----

    public function testAlterTableDropPrimaryKeyColumnThrows(): void
    {
        $this->conn->table('pairs')->insert(['a' => 1, 'b' => 'x']);

        try {
            $this->conn->alterTable('pairs', static function (AlterBlueprint $b): void {
                $b->dropColumn('a');
            });
            $this->fail('删除复合主键列未抛异常');
        } catch (SchemaException $e) {
            $this->assertStringContainsString('a', $e->getMessage());
        }

        // 结构与数据保持不变
        $schema = $this->conn->engine()->loadSchema('main', 'pairs');
        $this->assertTrue($schema->hasColumn('a'));
        $this->assertCount(1, $this->rows('pairs'));
    }

    // ---- Table::find 无单列主键语义 ----

    public function testFindThrowsOnCompositePrimaryKeyTable(): void
    {
        $this->conn->table('pairs')->insert(['a' => 1, 'b' => 'x']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('无主键');
        $this->conn->table('pairs')->find(1);
    }

    // ---- 持久化 ----

    public function testCompositePrimaryKeySurvivesReopen(): void
    {
        $root = sys_get_temp_dir() . '/psql-cpk-' . uniqid('', true);
        try {
            $connection = Psql::connect($root);
            $connection->createTable('pairs', static function (Blueprint $b): void {
                $b->int('a');
                $b->varchar('b', 16);
                $b->primary('a', 'b');
            });
            $connection->table('pairs')->insert(['a' => 1, 'b' => 'x']);

            // 重开连接：复合主键标志与唯一性完整还原
            $reopened = Psql::connect($root);
            $schema = $reopened->engine()->loadSchema('main', 'pairs');
            $this->assertSame(['a', 'b'], array_map(
                static fn ($column): string => $column->name,
                $schema->primaryKeyColumns(),
            ));
            $this->assertNull($schema->primaryKey());

            try {
                $reopened->table('pairs')->insert(['a' => 1, 'b' => 'x']);
                $this->fail('重开后重复元组未抛异常');
            } catch (ConstraintException) {
                // 复合主键唯一性在持久化后仍生效
            }
            $this->assertCount(1, $reopened->engine()->readRows('main', 'pairs'));

            $reopened->table('pairs')->insert(['a' => 1, 'b' => 'y']);
            $this->assertCount(2, $reopened->engine()->readRows('main', 'pairs'));
        } finally {
            if (is_dir($root)) {
                $this->removeDirRecursive($root);
            }
        }
    }

    /**
     * 递归删除临时目录
     */
    private function removeDirRecursive(string $dir): void
    {
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
