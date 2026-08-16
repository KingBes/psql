<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit;

use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Schema\Blueprint;
use Kingbes\Psql\Schema\ColumnSchema;
use Kingbes\Psql\Schema\DataType;
use Kingbes\Psql\Schema\TableIndex;
use Kingbes\Psql\Schema\TableSchema;
use PHPUnit\Framework\TestCase;

/**
 * TableIndex / TableSchema indexes 集成 / Blueprint::index 测试
 */
final class TableIndexTest extends TestCase
{
    // ---- TableIndex 序列化 ----

    public function testArrayRoundTrip(): void
    {
        $index = new TableIndex('idx_email', ['email']);

        $restored = TableIndex::fromArray($index->toArray());

        $this->assertSame('idx_email', $restored->name);
        $this->assertSame(['email'], $restored->columns);
        $this->assertSame($index->toArray(), $restored->toArray());
    }

    public function testFromArrayMissingNameThrows(): void
    {
        $this->expectException(StorageException::class);
        TableIndex::fromArray(['columns' => ['a']]);
    }

    public function testFromArrayMissingColumnsThrows(): void
    {
        $this->expectException(StorageException::class);
        TableIndex::fromArray(['name' => 'idx_a']);
    }

    public function testFromArrayEmptyColumnsThrows(): void
    {
        $this->expectException(StorageException::class);
        TableIndex::fromArray(['name' => 'idx_a', 'columns' => []]);
    }

    public function testFromArrayInvalidNameTypeThrows(): void
    {
        $this->expectException(StorageException::class);
        TableIndex::fromArray(['name' => 123, 'columns' => ['a']]);
    }

    public function testFromArrayInvalidColumnTypeThrows(): void
    {
        $this->expectException(StorageException::class);
        TableIndex::fromArray(['name' => 'idx_a', 'columns' => ['a', 123]]);
    }

    // ---- TableIndex 行为 ----

    public function testCoversColumnsOrderInsensitive(): void
    {
        $index = new TableIndex('idx_ab', ['a', 'b']);

        $this->assertTrue($index->coversColumns('a', 'b'));
        $this->assertTrue($index->coversColumns('b', 'a'));
        $this->assertFalse($index->coversColumns('a'));
        $this->assertFalse($index->coversColumns('a', 'c'));
        $this->assertFalse($index->coversColumns('a', 'b', 'c'));
        $this->assertFalse($index->coversColumns('a', 'a'));
    }

    public function testReferencesColumn(): void
    {
        $index = new TableIndex('idx_ab', ['a', 'b']);

        $this->assertTrue($index->referencesColumn('a'));
        $this->assertTrue($index->referencesColumn('b'));
        $this->assertFalse($index->referencesColumn('c'));
    }

    public function testWithColumnRenamed(): void
    {
        $index = new TableIndex('idx_ab', ['a', 'b']);

        $renamed = $index->withColumnRenamed('a', 'alpha');

        $this->assertSame('idx_ab', $renamed->name);
        $this->assertSame(['alpha', 'b'], $renamed->columns);
        // 未命中列保持不变
        $this->assertSame(['a', 'b'], $index->withColumnRenamed('x', 'y')->columns);
        // 原实例不可变
        $this->assertSame(['a', 'b'], $index->columns);
    }

    // ---- TableSchema indexes 集成 ----

    /**
     * 带两个索引的表结构（单列 + 联合）
     */
    private function indexedTable(): TableSchema
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        $blueprint->varchar('email', 100);
        $blueprint->varchar('name', 50);
        $blueprint->index('email');
        $blueprint->index('email', 'name');

        return $blueprint->toSchema('users');
    }

    public function testSchemaArrayRoundTripWithIndexes(): void
    {
        $schema = $this->indexedTable();

        $restored = TableSchema::fromArray($schema->toArray());

        $this->assertCount(2, $restored->indexes);
        $this->assertSame('idx_email', $restored->indexes[0]->name);
        $this->assertSame(['email'], $restored->indexes[0]->columns);
        $this->assertSame('idx_email_name', $restored->indexes[1]->name);
        $this->assertSame(['email', 'name'], $restored->indexes[1]->columns);
    }

    public function testSchemaFromArrayWithoutIndexesKey(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        $data = $blueprint->toSchema('t')->toArray();
        unset($data['indexes']);

        $this->assertSame([], TableSchema::fromArray($data)->indexes);
    }

    public function testSchemaFromArrayIndexesNullTreatedAsEmpty(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        $data = $blueprint->toSchema('t')->toArray();
        $data['indexes'] = null;

        $this->assertSame([], TableSchema::fromArray($data)->indexes);
    }

    public function testSchemaFromArrayInvalidIndexesThrows(): void
    {
        $this->expectException(StorageException::class);
        TableSchema::fromArray(['name' => 't', 'columns' => [], 'indexes' => ['not-an-array']]);
    }

    public function testSchemaDuplicateIndexNamesThrows(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        $blueprint->index('id');
        $schema = $blueprint->toSchema('t');

        try {
            new TableSchema(
                $schema->name,
                $schema->columns,
                indexes: array_merge($schema->indexes, [new TableIndex('idx_id', ['id'])]),
            );
            $this->fail('索引名重复应抛 SchemaException');
        } catch (SchemaException $e) {
            // 消息含两个冲突名
            $this->assertSame(2, substr_count($e->getMessage(), 'idx_id'));
        }
    }

    public function testSchemaIndexReferencesMissingColumnThrows(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        $columns = $blueprint->toSchema('t')->columns;

        try {
            new TableSchema('t', $columns, indexes: [new TableIndex('idx_ghost', ['ghost'])]);
            $this->fail('索引引用不存在的列应抛 SchemaException');
        } catch (SchemaException $e) {
            $this->assertStringContainsString('idx_ghost', $e->getMessage());
            $this->assertStringContainsString('ghost', $e->getMessage());
        }
    }

    public function testSchemaIndexDuplicateColumnThrows(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        $columns = $blueprint->toSchema('t')->columns;

        $this->expectException(SchemaException::class);
        new TableSchema('t', $columns, indexes: [new TableIndex('idx_dup', ['id', 'id'])]);
    }

    public function testSchemaInvalidIndexInstanceThrows(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        $columns = $blueprint->toSchema('t')->columns;

        $this->expectException(SchemaException::class);
        new TableSchema('t', $columns, indexes: ['not-an-index']);
    }

    public function testRenameColumnSyncsIndexes(): void
    {
        $schema = $this->indexedTable();

        $renamed = $schema->renameColumn('email', 'mail');

        $this->assertSame(['mail'], $renamed->indexes[0]->columns);
        $this->assertSame(['mail', 'name'], $renamed->indexes[1]->columns);
        // 原实例不可变
        $this->assertSame(['email'], $schema->indexes[0]->columns);
    }

    public function testDropIndexedColumnThrows(): void
    {
        $schema = $this->indexedTable();

        try {
            $schema->dropColumn('email');
            $this->fail('删除被索引引用的列应抛 SchemaException');
        } catch (SchemaException $e) {
            $this->assertStringContainsString('idx_email', $e->getMessage());
        }
    }

    public function testWithNameAndReplaceColumnKeepIndexes(): void
    {
        $schema = $this->indexedTable();

        $this->assertCount(2, $schema->withName('members')->indexes);

        $replaced = $schema->replaceColumn(new ColumnSchema('name', DataType::TEXT));
        $this->assertCount(2, $replaced->indexes);
        $this->assertSame(['email', 'name'], $replaced->indexes[1]->columns);
    }

    // ---- Blueprint::index ----

    public function testIndexAutoNameAndColumns(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        $blueprint->varchar('email', 100);
        $blueprint->varchar('name', 50);
        $blueprint->index('email');
        $blueprint->index('name', 'email');

        $schema = $blueprint->toSchema('t');

        $this->assertCount(2, $schema->indexes);
        $this->assertSame('idx_email', $schema->indexes[0]->name);
        $this->assertSame(['email'], $schema->indexes[0]->columns);
        $this->assertSame('idx_name_email', $schema->indexes[1]->name);
        $this->assertSame(['name', 'email'], $schema->indexes[1]->columns);
    }

    public function testIndexEmptyColumnsThrowsImmediately(): void
    {
        $blueprint = new Blueprint();

        $this->expectException(SchemaException::class);
        $blueprint->index();
    }

    public function testIndexDuplicateColumnsThrowsImmediately(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();

        $this->expectException(SchemaException::class);
        $blueprint->index('id', 'id');
    }

    public function testIndexDuplicateCombinationThrows(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        $blueprint->varchar('a', 10);
        $blueprint->varchar('b', 10);
        $blueprint->index('a', 'b');

        // 顺序不同仍视为同一列组合
        $this->expectException(SchemaException::class);
        $blueprint->index('b', 'a');
    }

    public function testIndexUndefinedColumnThrowsAtToSchema(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        // 注册时不校验列存在性（列可后定义）
        $blueprint->index('ghost');

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('ghost');
        $blueprint->toSchema('t');
    }

    public function testIndexColumnDefinedAfterRegistrationPasses(): void
    {
        $blueprint = new Blueprint();
        $blueprint->index('late');
        $blueprint->id();
        $blueprint->varchar('late', 10);

        $schema = $blueprint->toSchema('t');

        $this->assertSame(['late'], $schema->indexes[0]->columns);
    }
}
