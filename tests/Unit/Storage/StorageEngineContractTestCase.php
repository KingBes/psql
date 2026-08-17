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
        $this->assertThrows(fn () => $engine->deleteRows('missing', 't', [0]), 'deleteRows 缺库未抛异常');
        $this->assertThrows(fn () => $engine->deleteRows('missing', 't', []), 'deleteRows 空 indices 缺库未抛异常');
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
        $this->assertThrows(fn () => $engine->deleteRows('db', 'ghost', [0]), 'deleteRows 缺表未抛异常');
        $this->assertThrows(fn () => $engine->deleteRows('db', 'ghost', []), 'deleteRows 空 indices 缺表未抛异常');
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

    public function testDeleteRowsAtHeadMiddleTail(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $engine->writeRows('db', 't', $rows);

        // 删中间行（索引 2）
        $engine->deleteRows('db', 't', [2]);
        $this->assertSame(
            [$rows[0], $rows[1], $rows[3], $rows[4]],
            $engine->readRows('db', 't'),
            '删中间行后剩余行不正确'
        );

        // 删首行（索引 0）
        $engine->deleteRows('db', 't', [0]);
        $this->assertSame(
            [$rows[1], $rows[3], $rows[4]],
            $engine->readRows('db', 't'),
            '删首行后剩余行不正确'
        );

        // 删尾行（当前最后一行索引 2）
        $engine->deleteRows('db', 't', [2]);
        $this->assertSame(
            [$rows[1], $rows[3]],
            $engine->readRows('db', 't'),
            '删尾行后剩余行不正确'
        );
    }

    public function testDeleteRowsMultipleIndicesAtOnce(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $rows[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $engine->writeRows('db', 't', $rows);

        // 乱序多行一次删除
        $engine->deleteRows('db', 't', [4, 0, 2]);
        $this->assertSame(
            [$rows[1], $rows[3], $rows[5]],
            $engine->readRows('db', 't'),
            '多行删除后剩余行不正确'
        );

        // 全删
        $engine->deleteRows('db', 't', [0, 1, 2]);
        $this->assertSame([], $engine->readRows('db', 't'), '全删后表应为空');
    }

    public function testDeleteRowsEmptyIndicesIsNoOp(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']];
        $engine->writeRows('db', 't', $rows);

        $engine->deleteRows('db', 't', []);
        $this->assertSame($rows, $engine->readRows('db', 't'), '空 indices 应为 no-op');

        // 空表上空 indices 同样 no-op
        $engine->createTable('db', $this->makeSchema('empty'));
        $engine->deleteRows('db', 'empty', []);
        $this->assertSame([], $engine->readRows('db', 'empty'));
    }

    public function testDeleteRowsIndexOutOfBoundsThrows(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b'], ['id' => 3, 'name' => 'c']];
        $engine->writeRows('db', 't', $rows);

        $this->assertThrows(fn () => $engine->deleteRows('db', 't', [3]), '序号等于行数未抛异常');
        $this->assertThrows(fn () => $engine->deleteRows('db', 't', [100]), '序号远超行数未抛异常');
        $this->assertThrows(fn () => $engine->deleteRows('db', 't', [1, 3]), '含越界序号的集合未抛异常');
        $this->assertSame($rows, $engine->readRows('db', 't'), '越界删除不得改动数据');

        // 空表上任何序号均越界
        $engine->createTable('db', $this->makeSchema('empty'));
        $this->assertThrows(fn () => $engine->deleteRows('db', 'empty', [0]), '空表删除序号 0 未抛异常');
    }

    public function testDeleteRowsNegativeIndexThrows(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']];
        $engine->writeRows('db', 't', $rows);

        $this->assertThrows(fn () => $engine->deleteRows('db', 't', [-1]), '负数序号未抛异常');
        $this->assertThrows(fn () => $engine->deleteRows('db', 't', [0, -5]), '含负数序号的集合未抛异常');
        $this->assertSame($rows, $engine->readRows('db', 't'), '负数删除不得改动数据');
    }

    public function testDeleteRowsDuplicateIndicesThrow(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b'], ['id' => 3, 'name' => 'c']];
        $engine->writeRows('db', 't', $rows);

        $this->assertThrows(fn () => $engine->deleteRows('db', 't', [1, 1]), '重复序号未抛异常');
        $this->assertThrows(fn () => $engine->deleteRows('db', 't', [2, 0, 2]), '含重复序号的集合未抛异常');
        $this->assertSame($rows, $engine->readRows('db', 't'), '重复删除不得改动数据');
    }

    public function testDeleteRowsKeepsDenseOrderingForSubsequentOperations(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $engine->writeRows('db', 't', $rows);

        // 删除索引 1 后，后续序号必须指向新的稠密序列
        $engine->deleteRows('db', 't', [1]);
        $engine->deleteRows('db', 't', [1]);
        $this->assertSame(
            [$rows[0], $rows[3], $rows[4]],
            $engine->readRows('db', 't'),
            '删除后稠密序号未正确重排'
        );

        // 再删索引 0（原首行）
        $engine->deleteRows('db', 't', [0]);
        $this->assertSame([$rows[3], $rows[4]], $engine->readRows('db', 't'));
    }

    public function testDeleteRowsThenWriteRowsAndAutoIncrement(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b'], ['id' => 3, 'name' => 'c']];
        $engine->writeRows('db', 't', $rows);
        $engine->setAutoIncrement('db', 't', 3);

        $engine->deleteRows('db', 't', [0, 2]);

        // 删除后自增值不受影响，且可继续推进
        $this->assertSame(3, $engine->autoIncrement('db', 't'));
        $engine->setAutoIncrement('db', 't', 4);
        $this->assertSame(4, $engine->autoIncrement('db', 't'));

        // 删除后 writeRows 全量替换照常
        $replacement = [['id' => 9, 'name' => 'x']];
        $engine->writeRows('db', 't', $replacement);
        $this->assertSame($replacement, $engine->readRows('db', 't'));

        // 替换后序号重新基于新数据
        $engine->deleteRows('db', 't', [0]);
        $this->assertSame([], $engine->readRows('db', 't'));
    }

    public function testViewDefinitionsRoundTrip(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');

        // 空读：无视图时返回空数组
        $this->assertSame([], $engine->loadViewDefinitions('db'));

        // 写读往返：定义数组原样往返
        $definitions = [
            'adults' => ['name' => 'adults', 'query' => ['table' => 'users', 'where' => null, 'limit' => 10]],
            'names' => ['name' => 'names', 'query' => ['table' => 'users', 'columns' => ['name'], 'distinct' => true]],
        ];
        $engine->saveViewDefinitions('db', $definitions);
        $this->assertSame($definitions, $engine->loadViewDefinitions('db'));

        // 全量替换语义：旧定义被覆盖
        $engine->saveViewDefinitions('db', []);
        $this->assertSame([], $engine->loadViewDefinitions('db'));

        // 缺库抛 StorageException
        $this->assertThrows(fn () => $engine->loadViewDefinitions('missing'), 'loadViewDefinitions 缺库未抛异常');
        $this->assertThrows(fn () => $engine->saveViewDefinitions('missing', []), 'saveViewDefinitions 缺库未抛异常');
    }

    public function testViewDefinitionsIndependentOfTableData(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $engine->writeRows('db', 't', [['id' => 1, 'name' => 'a']]);
        $definitions = ['v' => ['name' => 'v', 'query' => ['table' => 't']]];
        $engine->saveViewDefinitions('db', $definitions);

        // 表数据/结构变更不影响视图定义；视图定义写入不影响表数据
        $engine->writeRows('db', 't', [['id' => 2, 'name' => 'b']]);
        $this->assertSame($definitions, $engine->loadViewDefinitions('db'));

        $engine->saveViewDefinitions('db', $definitions + ['v2' => ['name' => 'v2', 'query' => ['table' => 't']]]);
        $this->assertSame([['id' => 2, 'name' => 'b']], $engine->readRows('db', 't'));
        $this->assertSame('t', $engine->loadSchema('db', 't')->name);

        // 删库后视图定义随库移除
        $engine->dropDatabase('db');
        $this->assertFalse($engine->hasDatabase('db'));
    }

    public function testViewDefinitionsSnapshotRestore(): void
    {
        $engine = $this->createEngine();
        $engine->createDatabase('db');
        $definitions = ['v1' => ['name' => 'v1', 'query' => ['table' => 't']]];
        $engine->saveViewDefinitions('db', $definitions);

        $snapshot = $engine->snapshot();

        // 快照后变更：新增视图并删除旧视图
        $engine->saveViewDefinitions('db', ['v2' => ['name' => 'v2', 'query' => ['table' => 't']]]);

        $engine->restore($snapshot);
        $this->assertSame($definitions, $engine->loadViewDefinitions('db'));

        // restore 后可继续正常写读
        $engine->saveViewDefinitions('db', []);
        $this->assertSame([], $engine->loadViewDefinitions('db'));
    }
}
