<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit;

use InvalidArgumentException;
use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Table;
use Kingbes\Psql\Schema\AlterBlueprint;
use Kingbes\Psql\Schema\Blueprint;
use Kingbes\Psql\Storage\MemoryEngine;
use PHPUnit\Framework\TestCase;

/**
 * Connection/Psql 连接与门面层测试（内存引擎 + JSON 文件引擎）
 */
final class ConnectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psql-conn-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->removeDirRecursive($this->root);
        }
    }

    // ---- 连接与数据库 ----

    public function testMemoryConnectionOpensDefaultDatabase(): void
    {
        $connection = Psql::memory();

        $this->assertInstanceOf(MemoryEngine::class, $connection->engine());
        $this->assertSame('main', $connection->currentDatabase());
        $this->assertTrue($connection->hasDatabase('main'));
        $this->assertContains('main', $connection->databases());
    }

    public function testConnectOpensPersistentDefaultDatabase(): void
    {
        $connection = Psql::connect($this->root);

        $this->assertSame('main', $connection->currentDatabase());
        $this->assertTrue($connection->hasDatabase('main'));
        $this->assertContains('main', $connection->databases());
    }

    public function testConnectWithCustomEngineOption(): void
    {
        $engine = new MemoryEngine();

        $connection = Psql::connect('/ignored-path', ['engine' => $engine]);

        $this->assertSame($engine, $connection->engine());
        $this->assertSame('main', $connection->currentDatabase());
        $this->assertTrue($connection->hasDatabase('main'));
    }

    public function testConnectWithEngineAndCodecOptionsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('互斥');

        Psql::connect('/ignored-path', ['engine' => new MemoryEngine(), 'compress' => true]);
    }

    public function testConnectWithNonEngineValueThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('StorageEngine');

        Psql::connect('/ignored-path', ['engine' => 'not-an-engine']);
    }

    public function testUseSwitchesDatabaseAndThrowsWhenMissing(): void
    {
        $connection = Psql::memory();
        $connection->createDatabase('shop');
        $connection->use('shop');
        $this->assertSame('shop', $connection->currentDatabase());

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('missing');
        $connection->use('missing');
    }

    public function testCreateAndDropDatabase(): void
    {
        $connection = Psql::memory();
        $connection->createDatabase('extra');
        $this->assertTrue($connection->hasDatabase('extra'));
        $this->assertContains('extra', $connection->databases());

        $connection->dropDatabase('extra');
        $this->assertFalse($connection->hasDatabase('extra'));
    }

    public function testDropCurrentDatabaseFallsBackToMain(): void
    {
        $connection = Psql::memory();
        $connection->createDatabase('temp');
        $connection->use('temp');
        $connection->dropDatabase('temp');

        $this->assertSame('main', $connection->currentDatabase());
        $this->assertTrue($connection->hasDatabase('main'));
    }

    public function testDropCurrentMainDatabaseRecreatesMain(): void
    {
        $connection = Psql::memory();
        $connection->dropDatabase('main');

        $this->assertSame('main', $connection->currentDatabase());
        $this->assertTrue($connection->hasDatabase('main'));
    }

    // ---- 建表/删表/重命名 ----

    public function testCreateTableRegistersSchema(): void
    {
        $connection = Psql::memory();
        $this->assertFalse($connection->hasTable('users'));

        $connection->createTable('users', self::usersDefinition());

        $this->assertTrue($connection->hasTable('users'));
        $this->assertSame(['users'], $connection->tables());
        $this->assertSame(['id', 'name'], self::columnNames($connection, 'users'));
    }

    public function testCreateExistingTableThrows(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('users');
        $connection->createTable('users', self::usersDefinition());
    }

    public function testCreateTableIfNotExistsSkipsExistingTable(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());
        $connection->engine()->writeRows('main', 'users', [
            ['id' => 1, 'name' => '张三'],
        ]);

        $connection->createTableIfNotExists('users', self::usersDefinition());

        // 既有表未被重建，数据保留
        $this->assertSame(
            [['id' => 1, 'name' => '张三']],
            $connection->engine()->readRows('main', 'users')
        );
    }

    public function testCreateTableIfNotExistsCreatesMissingTable(): void
    {
        $connection = Psql::memory();
        $connection->createTableIfNotExists('users', self::usersDefinition());
        $this->assertTrue($connection->hasTable('users'));
    }

    public function testDropMissingTableThrows(): void
    {
        $connection = Psql::memory();

        $this->expectException(SchemaException::class);
        $connection->dropTable('ghost');
    }

    public function testDropTableBlockedByForeignKeyReference(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());
        $connection->createTable('orders', static function (Blueprint $table): void {
            $table->id();
            $table->bigint('user_id');
            $table->foreignKey('user_id')->references('users', 'id');
        });

        try {
            $connection->dropTable('users');
            $this->fail('被外键引用的表应抛 SchemaException');
        } catch (SchemaException $e) {
            $this->assertStringContainsString('orders', $e->getMessage());
        }
        $this->assertTrue($connection->hasTable('users'));

        // 先删除引用方后即可删除
        $connection->dropTable('orders');
        $connection->dropTable('users');
        $this->assertFalse($connection->hasTable('users'));
    }

    public function testDropTableRemovesTable(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());

        $connection->dropTable('users');

        $this->assertFalse($connection->hasTable('users'));
        $this->assertSame([], $connection->tables());
    }

    public function testRenameTableSyncsSchemaName(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());

        $connection->renameTable('users', 'members');

        $this->assertSame(['members'], $connection->tables());
        $this->assertFalse($connection->hasTable('users'));
        $this->assertSame('members', $connection->engine()->loadSchema('main', 'members')->name);
    }

    // ---- alterTable ----

    public function testAlterTableAddsColumnWithDefaultBackfill(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());
        $connection->engine()->writeRows('main', 'users', [
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
        ]);

        $connection->alterTable('users', static function (AlterBlueprint $table): void {
            $table->int('age')->notNull()->default(18);
        });

        $this->assertSame(['id', 'name', 'age'], self::columnNames($connection, 'users'));
        $this->assertSame(
            [
                ['id' => 1, 'name' => 'a', 'age' => 18],
                ['id' => 2, 'name' => 'b', 'age' => 18],
            ],
            $connection->engine()->readRows('main', 'users')
        );
    }

    public function testAlterTableAddsColumnWithDefaultNowBackfill(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());
        $connection->engine()->writeRows('main', 'users', [
            ['id' => 1, 'name' => 'a'],
        ]);

        $connection->alterTable('users', static function (AlterBlueprint $table): void {
            $table->datetime('created')->defaultNow();
        });

        $rows = $connection->engine()->readRows('main', 'users');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $rows[0]['created']
        );
    }

    public function testAlterTableNotNullColumnWithoutDefaultThrows(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());
        $connection->engine()->writeRows('main', 'users', [
            ['id' => 1, 'name' => 'a'],
        ]);

        try {
            $connection->alterTable('users', static function (AlterBlueprint $table): void {
                $table->int('age')->notNull();
            });
            $this->fail('NOT NULL 无默认值的新增列应抛 SchemaException');
        } catch (SchemaException $e) {
            $this->assertStringContainsString('age', $e->getMessage());
        }

        // 结构与数据保持不变
        $this->assertSame(['id', 'name'], self::columnNames($connection, 'users'));
        $this->assertSame(
            [['id' => 1, 'name' => 'a']],
            $connection->engine()->readRows('main', 'users')
        );
    }

    public function testAlterTableRenameColumnMigratesRowKeys(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());
        $connection->engine()->writeRows('main', 'users', [
            ['id' => 1, 'name' => '张三'],
        ]);

        $connection->alterTable('users', static function (AlterBlueprint $table): void {
            $table->renameColumn('name', 'username');
        });

        $this->assertSame(['id', 'username'], self::columnNames($connection, 'users'));
        $this->assertSame(
            [['id' => 1, 'username' => '张三']],
            $connection->engine()->readRows('main', 'users')
        );
    }

    public function testAlterTableDropColumnRemovesRowKeys(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', static function (Blueprint $table): void {
            $table->id();
            $table->varchar('name', 50)->notNull();
            $table->int('age');
        });
        $connection->engine()->writeRows('main', 'users', [
            ['id' => 1, 'name' => 'a', 'age' => 20],
        ]);

        $connection->alterTable('users', static function (AlterBlueprint $table): void {
            $table->dropColumn('age');
        });

        $this->assertSame(['id', 'name'], self::columnNames($connection, 'users'));
        $this->assertSame(
            [['id' => 1, 'name' => 'a']],
            $connection->engine()->readRows('main', 'users')
        );
    }

    public function testAlterTableDropConstrainedColumnThrows(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());
        $connection->createTable('orders', static function (Blueprint $table): void {
            $table->id();
            $table->bigint('user_id');
            $table->foreignKey('user_id')->references('users', 'id');
        });

        // 主键列不允许删除
        try {
            $connection->alterTable('users', static function (AlterBlueprint $table): void {
                $table->dropColumn('id');
            });
            $this->fail('删除主键列应抛 SchemaException');
        } catch (SchemaException $e) {
            $this->assertStringContainsString('id', $e->getMessage());
        }

        // 外键约束列不允许删除
        try {
            $connection->alterTable('orders', static function (AlterBlueprint $table): void {
                $table->dropColumn('user_id');
            });
            $this->fail('删除外键约束列应抛 SchemaException');
        } catch (SchemaException $e) {
            $this->assertStringContainsString('user_id', $e->getMessage());
        }
    }

    public function testAlterTableDropMissingColumnThrows(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());

        $this->expectException(SchemaException::class);
        $connection->alterTable('users', static function (AlterBlueprint $table): void {
            $table->dropColumn('ghost');
        });
    }

    public function testAlterTableRenameToExistingColumnThrows(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());

        $this->expectException(SchemaException::class);
        $connection->alterTable('users', static function (AlterBlueprint $table): void {
            $table->renameColumn('name', 'id');
        });
    }

    // ---- table() ----

    public function testTableWithoutAlias(): void
    {
        $connection = Psql::memory();

        [$name, $alias] = self::tableParts($connection->table('user'));

        $this->assertSame('user', $name);
        $this->assertNull($alias);
    }

    public function testTableWithAlias(): void
    {
        $connection = Psql::memory();

        [$name, $alias] = self::tableParts($connection->table('user as u'));
        $this->assertSame('user', $name);
        $this->assertSame('u', $alias);

        [$name, $alias] = self::tableParts($connection->table('user AS u'));
        $this->assertSame('user', $name);
        $this->assertSame('u', $alias);

        [$name, $alias] = self::tableParts($connection->table('user u'));
        $this->assertSame('user', $name);
        $this->assertSame('u', $alias);
    }

    public function testTableInvalidReferenceThrows(): void
    {
        $connection = Psql::memory();

        foreach (['user as', 'user 1u', '', '1user', 'user as as u'] as $reference) {
            try {
                $connection->table($reference);
                $this->fail("表引用 {$reference} 应抛 QueryException");
            } catch (QueryException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- 持久化 ----

    public function testPersistenceAcrossConnections(): void
    {
        $connection = Psql::connect($this->root);
        $connection->createTable('users', self::usersDefinition());
        $connection->engine()->writeRows('main', 'users', [
            ['id' => 1, 'name' => '张三'],
        ]);
        $connection->engine()->persist();

        $reopened = Psql::connect($this->root);
        $this->assertTrue($reopened->hasTable('users'));
        $this->assertSame(['id', 'name'], self::columnNames($reopened, 'users'));
        $this->assertSame(
            [['id' => 1, 'name' => '张三']],
            $reopened->engine()->readRows('main', 'users')
        );
    }

    // ---- 索引 DDL ----

    public function testIndexLifecycle(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', static function (Blueprint $table): void {
            $table->id();
            $table->varchar('email', 100);
            $table->varchar('name', 50);
        });

        $this->assertFalse($connection->hasIndex('users', 'idx_email'));

        $connection->createIndex('users', 'idx_email', 'email');

        $this->assertTrue($connection->hasIndex('users', 'idx_email'));
        $schema = $connection->engine()->loadSchema('main', 'users');
        $this->assertCount(1, $schema->indexes);
        $this->assertSame('idx_email', $schema->indexes[0]->name);
        $this->assertSame(['email'], $schema->indexes[0]->columns);

        $connection->dropIndex('users', 'idx_email');

        $this->assertFalse($connection->hasIndex('users', 'idx_email'));
        $this->assertSame([], $connection->engine()->loadSchema('main', 'users')->indexes);
    }

    public function testCreateTableWithBlueprintIndexes(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', static function (Blueprint $table): void {
            $table->id();
            $table->varchar('email', 100);
            $table->index('email');
        });

        $schema = $connection->engine()->loadSchema('main', 'users');

        $this->assertCount(1, $schema->indexes);
        $this->assertSame('idx_email', $schema->indexes[0]->name);
        $this->assertSame(['email'], $schema->indexes[0]->columns);
        $this->assertTrue($connection->hasIndex('users', 'idx_email'));
    }

    public function testCreateIndexDuplicateNameThrows(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());
        $connection->createIndex('users', 'idx_name', 'name');

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('idx_name');
        $connection->createIndex('users', 'idx_name', 'name');
    }

    public function testCreateIndexDuplicateColumnsThrows(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());

        $this->expectException(SchemaException::class);
        $connection->createIndex('users', 'idx_dup', 'name', 'name');
    }

    public function testCreateIndexEmptyColumnsThrows(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());

        $this->expectException(SchemaException::class);
        $connection->createIndex('users', 'idx_empty');
    }

    public function testCreateIndexMissingColumnThrows(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('ghost');
        $connection->createIndex('users', 'idx_ghost', 'ghost');
    }

    public function testCreateIndexInvalidNameThrows(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());

        foreach (['1bad', 'bad-name', 'bad name'] as $name) {
            try {
                $connection->createIndex('users', $name, 'name');
                $this->fail("非法索引名 {$name} 应抛 SchemaException");
            } catch (SchemaException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDropIndexMissingThrows(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', self::usersDefinition());

        $this->expectException(SchemaException::class);
        $connection->dropIndex('users', 'idx_missing');
    }

    public function testIndexDdlOnMissingTableThrowsStorageException(): void
    {
        $connection = Psql::memory();

        try {
            $connection->createIndex('ghost', 'idx_a', 'a');
            $this->fail('表不存在 createIndex 应抛 StorageException');
        } catch (StorageException $e) {
            $this->addToAssertionCount(1);
        }
        try {
            $connection->dropIndex('ghost', 'idx_a');
            $this->fail('表不存在 dropIndex 应抛 StorageException');
        } catch (StorageException $e) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(StorageException::class);
        $connection->hasIndex('ghost', 'idx_a');
    }

    public function testIndexPersistenceAcrossConnections(): void
    {
        $connection = Psql::connect($this->root);
        $connection->createTable('users', static function (Blueprint $table): void {
            $table->id();
            $table->varchar('email', 100);
            $table->varchar('name', 50);
        });
        $connection->createIndex('users', 'idx_email_name', 'email', 'name');
        $connection->engine()->persist();

        // 重开后索引完整
        $reopened = Psql::connect($this->root);
        $this->assertTrue($reopened->hasIndex('users', 'idx_email_name'));
        $index = $reopened->engine()->loadSchema('main', 'users')->indexes[0];
        $this->assertSame('idx_email_name', $index->name);
        $this->assertSame(['email', 'name'], $index->columns);

        $reopened->dropIndex('users', 'idx_email_name');
        $reopened->engine()->persist();

        // 再次重开确认消失
        $again = Psql::connect($this->root);
        $this->assertFalse($again->hasIndex('users', 'idx_email_name'));
        $this->assertSame([], $again->engine()->loadSchema('main', 'users')->indexes);
    }

    public function testAlterTablePreservesIndexesAfterAddColumn(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', static function (Blueprint $table): void {
            $table->id();
            $table->varchar('name', 50)->notNull();
            $table->index('name');
        });

        $connection->alterTable('users', static function (AlterBlueprint $table): void {
            $table->int('age');
        });

        $this->assertTrue($connection->hasIndex('users', 'idx_name'));
        $this->assertSame(['name'], $connection->engine()->loadSchema('main', 'users')->indexes[0]->columns);
    }

    public function testAlterTableRenameColumnSyncsIndexColumns(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', static function (Blueprint $table): void {
            $table->id();
            $table->varchar('name', 50)->notNull();
            $table->index('name');
        });

        $connection->alterTable('users', static function (AlterBlueprint $table): void {
            $table->renameColumn('name', 'username');
        });

        $index = $connection->engine()->loadSchema('main', 'users')->indexes[0];
        $this->assertSame('idx_name', $index->name);
        $this->assertSame(['username'], $index->columns);
    }

    public function testAlterTableDropIndexedColumnThrows(): void
    {
        $connection = Psql::memory();
        $connection->createTable('users', static function (Blueprint $table): void {
            $table->id();
            $table->varchar('name', 50)->notNull();
            $table->index('name');
        });

        try {
            $connection->alterTable('users', static function (AlterBlueprint $table): void {
                $table->dropColumn('name');
            });
            $this->fail('删除被索引引用的列应抛 SchemaException');
        } catch (SchemaException $e) {
            $this->assertStringContainsString('idx_name', $e->getMessage());
        }
        // 结构未变，索引保留
        $this->assertTrue($connection->hasIndex('users', 'idx_name'));
    }

    // ---- 辅助 ----

    /**
     * @return callable(Blueprint): void
     */
    private static function usersDefinition(): callable
    {
        return static function (Blueprint $table): void {
            $table->id();
            $table->varchar('name', 50)->notNull();
        };
    }

    /**
     * @return list<string>
     */
    private static function columnNames(Connection $connection, string $table): array
    {
        return array_map(
            static fn ($column): string => $column->name,
            $connection->engine()->loadSchema($connection->currentDatabase(), $table)->columns,
        );
    }

    /**
     * 读取 Table 实例的 name/alias（无公开访问器，测试用反射）
     *
     * @return array{0: string, 1: ?string}
     */
    private static function tableParts(Table $table): array
    {
        $name = new \ReflectionProperty(Table::class, 'name');
        $alias = new \ReflectionProperty(Table::class, 'alias');

        return [$name->getValue($table), $alias->getValue($table)];
    }

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
