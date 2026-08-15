<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit;

use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Schema\Blueprint;
use Kingbes\Psql\Schema\ColumnSchema;
use Kingbes\Psql\Schema\DataType;
use Kingbes\Psql\Schema\ForeignKey;
use Kingbes\Psql\Schema\TableSchema;
use PHPUnit\Framework\TestCase;

/**
 * Blueprint / TableSchema 结构层测试
 */
final class BlueprintTest extends TestCase
{
    public function testIdColumnDefaults(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();

        $schema = $blueprint->toSchema('t');
        $id = $schema->columnOrFail('id');

        $this->assertSame(DataType::BIGINT, $id->type);
        $this->assertTrue($id->unsigned);
        $this->assertTrue($id->primaryKey);
        $this->assertTrue($id->autoIncrement);
        $this->assertSame($id, $schema->primaryKey());
        $this->assertSame($id, $schema->autoIncrementColumn());
    }

    public function testVarcharModifierChain(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        $blueprint->varchar('email', 100)->notNull()->default('a@b.c')->unique();

        $schema = $blueprint->toSchema('users');
        $email = $schema->columnOrFail('email');

        $this->assertSame(DataType::VARCHAR, $email->type);
        $this->assertSame(100, $email->length);
        $this->assertTrue($email->notNull);
        $this->assertTrue($email->hasDefault);
        $this->assertSame('a@b.c', $email->default);
        $this->assertTrue($email->unique);
        // 单列唯一列被 uniqueColumns 收录
        $this->assertSame([$email], $schema->uniqueColumns());
    }

    public function testCompositeUnique(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        $blueprint->varchar('a', 10);
        $blueprint->varchar('b', 10);
        $blueprint->unique('a', 'b');

        $schema = $blueprint->toSchema('t');

        $this->assertSame([['a', 'b']], $schema->uniqueKeys);
        $this->assertSame(['a', 'b'], array_map(
            static fn (ColumnSchema $column): string => $column->name,
            $schema->uniqueColumns(),
        ));
    }

    public function testForeignKeyDsl(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        $blueprint->bigint('user_id');
        $blueprint->foreignKey('user_id')->references('users', 'id')->onDeleteCascade();

        $schema = $blueprint->toSchema('orders');

        $this->assertCount(1, $schema->foreignKeys);
        $fk = $schema->foreignKeys[0];
        $this->assertSame('user_id', $fk->column);
        $this->assertSame('users', $fk->refTable);
        $this->assertSame('id', $fk->refColumn);
        $this->assertTrue($fk->onDeleteCascade);
    }

    public function testForeignKeyWithoutReferencesThrows(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        $blueprint->bigint('user_id');
        $blueprint->foreignKey('user_id');

        $this->expectException(SchemaException::class);
        $blueprint->toSchema('orders');
    }

    public function testUniqueReferencesUndefinedColumn(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        $blueprint->varchar('a', 10);

        $this->expectException(SchemaException::class);
        $blueprint->unique('a', 'missing');
    }

    // ---- 非法结构 ----

    public function testDuplicateColumnNameThrows(): void
    {
        $blueprint = new Blueprint();
        $blueprint->varchar('a', 10);
        $blueprint->varchar('a', 20);

        $this->expectException(SchemaException::class);
        $blueprint->toSchema('t');
    }

    public function testVarcharLengthZeroThrowsImmediately(): void
    {
        $blueprint = new Blueprint();

        $this->expectException(SchemaException::class);
        $blueprint->varchar('a', 0);
    }

    public function testCharLengthOverflowThrowsImmediately(): void
    {
        $blueprint = new Blueprint();

        $this->expectException(SchemaException::class);
        $blueprint->char('a', 256);
    }

    public function testVarcharLengthOverflowThrowsImmediately(): void
    {
        $blueprint = new Blueprint();

        $this->expectException(SchemaException::class);
        $blueprint->varchar('a', 65536);
    }

    public function testEnumEmptyThrowsImmediately(): void
    {
        $blueprint = new Blueprint();

        $this->expectException(SchemaException::class);
        $blueprint->enum('status', []);
    }

    public function testEnumDuplicateMembersThrowsImmediately(): void
    {
        $blueprint = new Blueprint();

        $this->expectException(SchemaException::class);
        $blueprint->enum('status', ['a', 'b', 'a']);
    }

    public function testMultiplePrimaryKeysThrows(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        $blueprint->int('b')->primaryKey();

        $this->expectException(SchemaException::class);
        $blueprint->toSchema('t');
    }

    public function testInvalidColumnNameThrows(): void
    {
        $blueprint = new Blueprint();
        $blueprint->varchar('1abc', 10);

        $this->expectException(SchemaException::class);
        $blueprint->toSchema('t');
    }

    public function testInvalidTableNameThrows(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();

        $this->expectException(SchemaException::class);
        $blueprint->toSchema('1t');
    }

    public function testDecimalScaleGreaterThanPrecisionThrows(): void
    {
        $blueprint = new Blueprint();

        $this->expectException(SchemaException::class);
        $blueprint->decimal('amount', 3, 4);
    }

    public function testDecimalZeroScaleThrows(): void
    {
        $blueprint = new Blueprint();

        $this->expectException(SchemaException::class);
        $blueprint->decimal('amount', 10, 0);
    }

    public function testUnsignedOnTextThrows(): void
    {
        $blueprint = new Blueprint();

        $this->expectException(SchemaException::class);
        $blueprint->text('body')->unsigned();
    }

    public function testDefaultNowOnIntThrows(): void
    {
        $blueprint = new Blueprint();

        $this->expectException(SchemaException::class);
        $blueprint->int('n')->defaultNow();
    }

    public function testAutoIncrementOnVarcharThrows(): void
    {
        $blueprint = new Blueprint();

        $this->expectException(SchemaException::class);
        $blueprint->varchar('s', 10)->autoIncrement();
    }

    // ---- 序列化往返 ----

    public function testTableSchemaArrayRoundTrip(): void
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        $blueprint->varchar('email', 100)->notNull()->default('a@b.c')->unique();
        $blueprint->decimal('amount', 10, 2);
        $blueprint->enum('status', ['active', 'disabled']);
        $blueprint->timestamp('created_at')->defaultNow();
        $blueprint->bigint('user_id');
        $blueprint->unique('email', 'user_id');
        $blueprint->foreignKey('user_id')->references('users', 'id')->onDeleteCascade();

        $schema = $blueprint->toSchema('orders');
        $restored = TableSchema::fromArray($schema->toArray());

        $this->assertEquals($schema->toArray(), $restored->toArray());
        $this->assertSame('orders', $restored->name);
        $this->assertCount(6, $restored->columns);
        $this->assertSame([['email', 'user_id']], $restored->uniqueKeys);
        $this->assertCount(1, $restored->foreignKeys);
    }

    public function testColumnSchemaFromArrayInvalidTypeThrows(): void
    {
        $this->expectException(StorageException::class);
        ColumnSchema::fromArray(['name' => 'a', 'type' => 'NOPE']);
    }

    public function testColumnSchemaFromArrayMissingNameThrows(): void
    {
        $this->expectException(StorageException::class);
        ColumnSchema::fromArray(['type' => 'INT']);
    }

    public function testTableSchemaFromArrayInvalidColumnsThrows(): void
    {
        $this->expectException(StorageException::class);
        TableSchema::fromArray(['name' => 't', 'columns' => ['not-an-array']]);
    }

    public function testTableSchemaFromArrayInvalidForeignKeyThrows(): void
    {
        $this->expectException(StorageException::class);
        TableSchema::fromArray([
            'name' => 't',
            'columns' => [],
            'foreignKeys' => [['column' => 'a']],
        ]);
    }

    // ---- TableSchema 变更 ----

    private function ordersTable(): TableSchema
    {
        $blueprint = new Blueprint();
        $blueprint->id();
        $blueprint->varchar('a', 10);
        $blueprint->varchar('b', 10);
        $blueprint->varchar('memo', 10);
        $blueprint->bigint('user_id');
        $blueprint->unique('a', 'b');
        $blueprint->foreignKey('user_id')->references('users', 'id');

        return $blueprint->toSchema('orders');
    }

    public function testHasColumnIsCaseSensitive(): void
    {
        $schema = $this->ordersTable();

        $this->assertTrue($schema->hasColumn('memo'));
        $this->assertFalse($schema->hasColumn('Memo'));
        $this->assertFalse($schema->hasColumn('missing'));
    }

    public function testColumnOrFailMissingThrows(): void
    {
        $schema = $this->ordersTable();

        $this->expectException(SchemaException::class);
        $schema->columnOrFail('missing');
    }

    public function testNoPrimaryKeyReturnsNull(): void
    {
        $blueprint = new Blueprint();
        $blueprint->varchar('a', 10);
        $schema = $blueprint->toSchema('t');

        $this->assertNull($schema->primaryKey());
        $this->assertNull($schema->autoIncrementColumn());
    }

    public function testReplaceColumn(): void
    {
        $schema = $this->ordersTable();
        $new = new ColumnSchema('memo', DataType::TEXT);

        $replaced = $schema->replaceColumn($new);

        $this->assertSame(DataType::TEXT, $replaced->columnOrFail('memo')->type);
        // 原实例不可变
        $this->assertSame(DataType::VARCHAR, $schema->columnOrFail('memo')->type);
        $this->assertSame(10, $schema->columnOrFail('memo')->length);
    }

    public function testReplaceMissingColumnThrows(): void
    {
        $schema = $this->ordersTable();

        $this->expectException(SchemaException::class);
        $schema->replaceColumn(new ColumnSchema('missing', DataType::TEXT));
    }

    public function testDropPlainColumn(): void
    {
        $schema = $this->ordersTable();

        $dropped = $schema->dropColumn('memo');

        $this->assertFalse($dropped->hasColumn('memo'));
        $this->assertTrue($schema->hasColumn('memo'));
    }

    public function testDropPrimaryKeyColumnThrows(): void
    {
        $schema = $this->ordersTable();

        $this->expectException(SchemaException::class);
        $schema->dropColumn('id');
    }

    public function testDropCompositeUniqueColumnThrows(): void
    {
        $schema = $this->ordersTable();

        $this->expectException(SchemaException::class);
        $schema->dropColumn('a');
    }

    public function testDropForeignKeyColumnThrows(): void
    {
        $schema = $this->ordersTable();

        $this->expectException(SchemaException::class);
        $schema->dropColumn('user_id');
    }

    public function testDropMissingColumnThrows(): void
    {
        $schema = $this->ordersTable();

        $this->expectException(SchemaException::class);
        $schema->dropColumn('missing');
    }

    public function testRenameColumnUpdatesUniqueKeys(): void
    {
        $schema = $this->ordersTable();

        $renamed = $schema->renameColumn('a', 'alpha');

        $this->assertTrue($renamed->hasColumn('alpha'));
        $this->assertFalse($renamed->hasColumn('a'));
        $this->assertSame([['alpha', 'b']], $renamed->uniqueKeys);
        // 原实例不变
        $this->assertSame([['a', 'b']], $schema->uniqueKeys);
    }

    public function testRenameColumnUpdatesForeignKeys(): void
    {
        $schema = $this->ordersTable();

        $renamed = $schema->renameColumn('user_id', 'uid');
        $fk = $renamed->foreignKeys[0];

        $this->assertSame('uid', $fk->column);
        $this->assertSame('users', $fk->refTable);
        $this->assertSame('id', $fk->refColumn);
        $this->assertSame('user_id', $schema->foreignKeys[0]->column);
    }

    public function testRenameMissingColumnThrows(): void
    {
        $schema = $this->ordersTable();

        $this->expectException(SchemaException::class);
        $schema->renameColumn('missing', 'x');
    }

    public function testRenameToExistingNameThrows(): void
    {
        $schema = $this->ordersTable();

        $this->expectException(SchemaException::class);
        $schema->renameColumn('a', 'b');
    }

    public function testWithName(): void
    {
        $schema = $this->ordersTable();

        $this->assertSame('users2', $schema->withName('users2')->name);
    }
}
