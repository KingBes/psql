<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Storage;

use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Storage\JsonFileEngine;
use Kingbes\Psql\Storage\StorageEngine;

require_once __DIR__ . '/StorageEngineContractTestCase.php';

/**
 * JsonFileEngine 契约 + 专属测试：持久化 / 损坏文件 / 非法名 / 原子写
 */
final class JsonFileEngineTest extends StorageEngineContractTestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psql-storage-test-' . uniqid();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->removeDirRecursive($this->root);
        }
        parent::tearDown();
    }

    protected function createEngine(): StorageEngine
    {
        return new JsonFileEngine($this->root);
    }

    public function testPersistenceRoundTrip(): void
    {
        $engine = new JsonFileEngine($this->root);
        $engine->createDatabase('shop');
        $engine->createTable('shop', $this->makeSchema('users'));
        $engine->writeRows('shop', 'users', [
            ['id' => 1, 'name' => '张三'],
            ['id' => 2, 'name' => '李四'],
        ]);
        $engine->setAutoIncrement('shop', 'users', 2);

        // 新实例指向同一 root：数据与结构完整
        $restored = new JsonFileEngine($this->root);
        $this->assertSame(['shop'], $restored->databases());
        $this->assertSame(['users'], $restored->tables('shop'));
        $this->assertTrue($restored->hasTable('shop', 'users'));

        $schema = $restored->loadSchema('shop', 'users');
        $this->assertSame('users', $schema->name);
        $this->assertTrue($schema->hasColumn('id'));
        $this->assertTrue($schema->hasColumn('name'));

        $this->assertSame([
            ['id' => 1, 'name' => '张三'],
            ['id' => 2, 'name' => '李四'],
        ], $restored->readRows('shop', 'users'));
        $this->assertSame(2, $restored->autoIncrement('shop', 'users'));
    }

    public function testCorruptedJsonFileThrows(): void
    {
        $engine = new JsonFileEngine($this->root);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $file = $this->root . '/db/t.json';
        file_put_contents($file, '{oops');

        $restored = new JsonFileEngine($this->root);
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage($file);
        $restored->readRows('db', 't');
    }

    public function testMalformedTableFileThrows(): void
    {
        $engine = new JsonFileEngine($this->root);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $file = $this->root . '/db/t.json';
        file_put_contents($file, '{"schema": {"name": "t"}}');

        $restored = new JsonFileEngine($this->root);
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage($file);
        $restored->readRows('db', 't');
    }

    public function testInvalidNamesThrow(): void
    {
        $engine = new JsonFileEngine($this->root);

        foreach (['a/b', '', '1abc', 'a b'] as $bad) {
            $this->assertThrows(fn () => $engine->createDatabase($bad), "非法库名未抛异常: {$bad}");
        }

        $engine->createDatabase('db');
        foreach (['a/b', '', '1abc'] as $bad) {
            $this->assertThrows(fn () => $engine->createTable('db', $this->makeSchema($bad)), "非法表名未抛异常: {$bad}");
        }

        $this->assertThrows(fn () => $engine->dropDatabase('a/b'), '非法库名 drop 未抛异常');
        $this->assertThrows(fn () => $engine->readRows('db', '../etc'), '路径穿越式表名未抛异常');
    }

    public function testAtomicWriteLeavesValidJson(): void
    {
        $engine = new JsonFileEngine($this->root);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $engine->writeRows('db', 't', [['id' => 1, 'name' => '张三']]);
        $engine->setAutoIncrement('db', 't', 1);

        $file = $this->root . '/db/t.json';
        $this->assertFileExists($file);

        // 写后文件为合法 JSON 且结构完整
        $decoded = json_decode((string) file_get_contents($file), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('schema', $decoded);
        $this->assertArrayHasKey('auto_increment', $decoded);
        $this->assertArrayHasKey('rows', $decoded);
        $this->assertSame(1, $decoded['auto_increment']);
        $this->assertSame([['id' => 1, 'name' => '张三']], $decoded['rows']);

        // 无残留临时文件
        $this->assertSame([], glob($this->root . '/db/*.tmp.*') ?: []);
    }

    public function testPersistRewritesMissingTableFile(): void
    {
        $engine = new JsonFileEngine($this->root);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $engine->writeRows('db', 't', [['id' => 1, 'name' => 'a']]);

        $file = $this->root . '/db/t.json';
        unlink($file);
        $this->assertFileDoesNotExist($file);

        $engine->persist();
        $this->assertFileExists($file);

        $again = new JsonFileEngine($this->root);
        $this->assertSame([['id' => 1, 'name' => 'a']], $again->readRows('db', 't'));
    }

    public function testRestoreDeletesFilesNotInSnapshot(): void
    {
        $engine = new JsonFileEngine($this->root);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('keep'));

        $snapshot = $engine->snapshot();

        $engine->createTable('db', $this->makeSchema('extra'));
        $engine->createDatabase('db2');
        $this->assertFileExists($this->root . '/db/extra.json');
        $this->assertDirectoryExists($this->root . '/db2');

        $engine->restore($snapshot);
        $this->assertFileDoesNotExist($this->root . '/db/extra.json');
        $this->assertFileExists($this->root . '/db/keep.json');
        $this->assertDirectoryDoesNotExist($this->root . '/db2');

        // 新实例以磁盘为准
        $again = new JsonFileEngine($this->root);
        $this->assertSame(['db'], $again->databases());
        $this->assertSame(['keep'], $again->tables('db'));
    }

    public function testSnapshotIncludesDiskOnlyTables(): void
    {
        $first = new JsonFileEngine($this->root);
        $first->createDatabase('db');
        $first->createTable('db', $this->makeSchema('t'));
        $first->writeRows('db', 't', [['id' => 1, 'name' => 'a']]);
        $first->setAutoIncrement('db', 't', 1);

        // 全新实例：未加载任何表，快照仍须覆盖磁盘上的表
        $second = new JsonFileEngine($this->root);
        $snapshot = $second->snapshot();

        $state = unserialize($snapshot->payload);
        $this->assertIsArray($state);
        $this->assertArrayHasKey('db', $state);
        $this->assertArrayHasKey('t', $state['db']);
        $this->assertSame([['id' => 1, 'name' => 'a']], $state['db']['t']['rows']);
        $this->assertSame(1, $state['db']['t']['ai']);
    }

    /**
     * 递归删除测试临时目录
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
