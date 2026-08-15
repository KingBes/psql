<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Storage;

use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Schema\ColumnSchema;
use Kingbes\Psql\Schema\DataType;
use Kingbes\Psql\Schema\TableSchema;
use Kingbes\Psql\Storage\StorageEngine;
use PHPUnit\Framework\TestCase;

/**
 * 存储引擎共享契约测试基类：MemoryEngine 与 JsonFileEngine 各跑一遍
 */
abstract class StorageEngineContractTestCase extends TestCase
{
    abstract protected function createEngine(): StorageEngine;

    /**
     * 构造两张列的示例表结构
     */
    protected function makeSchema(string $name): TableSchema
    {
        return new TableSchema($name, [
            new ColumnSchema(name: 'id', type: DataType::BIGINT, unsigned: true, primaryKey: true, autoIncrement: true),
            new ColumnSchema(name: 'name', type: DataType::VARCHAR, length: 50, notNull: true),
        ]);
    }

    /**
     * 断言回调抛出 StorageException
     */
    protected function assertThrows(callable $fn, string $message): void
    {
        try {
            $fn();
            $this->fail($message);
        } catch (StorageException $e) {
            $this->assertInstanceOf(StorageException::class, $e);
        }
    }

    public function testDatabaseLifecycle(): void
    {
        $engine = $this->createEngine();

        $this->assertSame([], $engine->databases());
        $this->assertFalse($engine->hasDatabase('app'));

        $engine->createDatabase('app');
        $this->assertTrue($engine->hasDatabase('app'));
        $this->assertSame(['app'], $engine->databases());

        $this->assertThrows(fn () => $engine->createDatabase('app'), '重复建库未抛异常');

        $engine->dropDatabase('app');
        $this->assertFalse($engine->hasDatabase('app'));
        $this->assertSame([], $engine->databases());

        $this->assertThrows(fn () => $engine->dropDatabase('app'), '删除不存在的库未抛异常');
    }

    public function testTableLifecycle(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');

        $this->assertSame([], $engine->tables('db'));
        $this->assertFalse($engine->hasTable('db', 'users'));
        $this->assertFalse($engine->hasTable('ghost_db', 'users'));

        $engine->createTable('db', $this->makeSchema('users'));
        $this->assertTrue($engine->hasTable('db', 'users'));
        $this->assertSame(['users'], $engine->tables('db'));
        $this->assertSame('users', $engine->loadSchema('db', 'users')->name);

        $this->assertThrows(fn () => $engine->createTable('db', $this->makeSchema('users')), '重复建表未抛异常');
        $this->assertThrows(fn () => $engine->createTable('ghost_db', $this->makeSchema('t')), '库不存在建表未抛异常');

        $engine->dropTable('db', 'users');
        $this->assertFalse($engine->hasTable('db', 'users'));

        $this->assertThrows(fn () => $engine->dropTable('db', 'users'), '删除不存在的表未抛异常');
    }

    public function testOperationsOnMissingDatabaseThrow(): void
    {
        $engine = $this->createEngine();

        $this->assertThrows(fn () => $engine->tables('missing'), 'tables 缺库未抛异常');
        $this->assertThrows(fn () => $engine->readRows('missing', 't'), 'readRows 缺库未抛异常');
        $this->assertThrows(fn () => $engine->writeRows('missing', 't', []), 'writeRows 缺库未抛异常');
        $this->assertThrows(fn () => $engine->loadSchema('missing', 't'), 'loadSchema 缺库未抛异常');
        $this->assertThrows(fn () => $engine->autoIncrement('missing', 't'), 'autoIncrement 缺库未抛异常');
        $this->assertThrows(fn () => $engine->setAutoIncrement('missing', 't', 1), 'setAutoIncrement 缺库未抛异常');
        $this->assertThrows(fn () => $engine->dropTable('missing', 't'), 'dropTable 缺库未抛异常');
        $this->assertThrows(fn () => $engine->renameTable('missing', 'a', 'b'), 'renameTable 缺库未抛异常');
        $this->assertThrows(fn () => $engine->replaceSchema('missing', 't', $this->makeSchema('t')), 'replaceSchema 缺库未抛异常');
    }

    public function testOperationsOnMissingTableThrow(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');

        $this->assertThrows(fn () => $engine->readRows('db', 'ghost'), 'readRows 缺表未抛异常');
        $this->assertThrows(fn () => $engine->writeRows('db', 'ghost', []), 'writeRows 缺表未抛异常');
        $this->assertThrows(fn () => $engine->loadSchema('db', 'ghost'), 'loadSchema 缺表未抛异常');
        $this->assertThrows(fn () => $engine->autoIncrement('db', 'ghost'), 'autoIncrement 缺表未抛异常');
        $this->assertThrows(fn () => $engine->setAutoIncrement('db', 'ghost', 1), 'setAutoIncrement 缺表未抛异常');
        $this->assertThrows(fn () => $engine->renameTable('db', 'ghost', 'x1'), 'renameTable 缺表未抛异常');
        $this->assertThrows(fn () => $engine->replaceSchema('db', 'ghost', $this->makeSchema('ghost')), 'replaceSchema 缺表未抛异常');
    }

    public function testRowsRoundTrip(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $this->assertSame([], $engine->readRows('db', 't'));

        $rows = [['id' => 1, 'name' => '张三'], ['id' => 2, 'name' => '李四']];
        $engine->writeRows('db', 't', $rows);
        $this->assertSame($rows, $engine->readRows('db', 't'));

        // 全量替换：旧数据清空
        $engine->writeRows('db', 't', []);
        $this->assertSame([], $engine->readRows('db', 't'));
    }

    public function testAutoIncrementRules(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $this->assertSame(0, $engine->autoIncrement('db', 't'));

        $engine->setAutoIncrement('db', 't', 5);
        $this->assertSame(5, $engine->autoIncrement('db', 't'));

        $this->assertThrows(fn () => $engine->setAutoIncrement('db', 't', 5), '自增值等于当前值未抛异常');
        $this->assertThrows(fn () => $engine->setAutoIncrement('db', 't', 4), '自增值小于当前值未抛异常');

        $engine->setAutoIncrement('db', 't', 6);
        $this->assertSame(6, $engine->autoIncrement('db', 't'));
    }

    public function testRenameTable(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('old'));
        $engine->writeRows('db', 'old', [['id' => 1, 'name' => 'a']]);
        $engine->setAutoIncrement('db', 'old', 3);

        $engine->renameTable('db', 'old', 'new');
        $this->assertFalse($engine->hasTable('db', 'old'));
        $this->assertTrue($engine->hasTable('db', 'new'));
        $this->assertSame('new', $engine->loadSchema('db', 'new')->name);
        $this->assertSame([['id' => 1, 'name' => 'a']], $engine->readRows('db', 'new'));
        $this->assertSame(3, $engine->autoIncrement('db', 'new'));

        $engine->createTable('db', $this->makeSchema('other'));
        $this->assertThrows(fn () => $engine->renameTable('db', 'new', 'other'), '目标表已存在未抛异常');
        $this->assertThrows(fn () => $engine->renameTable('db', 'ghost', 'x1'), '源表不存在未抛异常');
    }

    public function testReplaceSchema(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $engine->writeRows('db', 't', [['id' => 1, 'name' => 'a']]);

        $replacement = new TableSchema('t', [
            new ColumnSchema(name: 'id', type: DataType::BIGINT, unsigned: true, primaryKey: true, autoIncrement: true),
            new ColumnSchema(name: 'name', type: DataType::VARCHAR, length: 50, notNull: true),
            new ColumnSchema(name: 'age', type: DataType::INT),
        ]);
        $engine->replaceSchema('db', 't', $replacement);

        $loaded = $engine->loadSchema('db', 't');
        $this->assertSame('t', $loaded->name);
        $this->assertTrue($loaded->hasColumn('age'));
        // 行数据不受结构替换影响
        $this->assertSame([['id' => 1, 'name' => 'a']], $engine->readRows('db', 't'));

        $this->assertThrows(fn () => $engine->replaceSchema('db', 'ghost', $replacement), '表不存在未抛异常');
    }

    public function testDropTableAndRecreate(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $engine->writeRows('db', 't', [['id' => 1, 'name' => 'a']]);
        $engine->setAutoIncrement('db', 't', 9);

        $engine->dropTable('db', 't');
        $engine->createTable('db', $this->makeSchema('t'));

        $this->assertSame([], $engine->readRows('db', 't'));
        $this->assertSame(0, $engine->autoIncrement('db', 't'));
    }

    public function testSnapshotRestoreRollback(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db1');
        $engine->createTable('db1', $this->makeSchema('kept'));
        $engine->writeRows('db1', 'kept', [['id' => 1, 'name' => 'a']]);
        $engine->setAutoIncrement('db1', 'kept', 1);

        $snapshot = $engine->snapshot();

        // 快照后的变更：新表 + 数据改写 + 自增推进
        $engine->createTable('db1', $this->makeSchema('added'));
        $engine->writeRows('db1', 'kept', [['id' => 2, 'name' => 'b']]);
        $engine->setAutoIncrement('db1', 'kept', 2);

        $engine->restore($snapshot);

        $this->assertFalse($engine->hasTable('db1', 'added'));
        $this->assertTrue($engine->hasTable('db1', 'kept'));
        $this->assertSame([['id' => 1, 'name' => 'a']], $engine->readRows('db1', 'kept'));
        $this->assertSame(1, $engine->autoIncrement('db1', 'kept'));

        // restore 后可继续正常读写
        $engine->writeRows('db1', 'kept', [['id' => 3, 'name' => 'c']]);
        $this->assertSame([['id' => 3, 'name' => 'c']], $engine->readRows('db1', 'kept'));
        $engine->setAutoIncrement('db1', 'kept', 3);
        $this->assertSame(3, $engine->autoIncrement('db1', 'kept'));
    }
}
