<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Storage;

use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Storage\PhpSerializeEngine;
use Kingbes\Psql\Storage\StorageEngine;

require_once __DIR__ . '/StorageEngineContractTestCase.php';

/**
 * PhpSerializeEngine 契约 + 专属测试：持久化 / 损坏载荷 / 反序列化白名单
 */
final class PhpSerializeEngineTest extends StorageEngineContractTestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psql-ser-test-' . uniqid();
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
        return new PhpSerializeEngine($this->root);
    }

    public function testPersistenceRoundTrip(): void
    {
        $engine = new PhpSerializeEngine($this->root);
        $engine->createDatabase('shop');
        $engine->createTable('shop', $this->makeSchema('users'));
        $engine->writeRows('shop', 'users', [
            ['id' => 1, 'name' => '张三'],
            ['id' => 2, 'name' => '李四'],
        ]);
        $engine->setAutoIncrement('shop', 'users', 2);

        // 表文件扩展名为 .bin
        $this->assertFileExists($this->root . '/shop/users.bin');

        $restored = new PhpSerializeEngine($this->root);
        $this->assertSame(['shop'], $restored->databases());
        $this->assertSame(['users'], $restored->tables('shop'));

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

    public function testCorruptedPayloadThrows(): void
    {
        $engine = new PhpSerializeEngine($this->root);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $file = $this->root . '/db/t.bin';
        file_put_contents($file, 'not-a-serialize-payload');

        $restored = new PhpSerializeEngine($this->root);
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage($file);
        $restored->readRows('db', 't');
    }

    public function testForeignClassPayloadRejected(): void
    {
        $engine = new PhpSerializeEngine($this->root);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        // 恶意载荷：schema 位置放置白名单外的类（stdClass）
        // allowed_classes 使其退化为 __PHP_Incomplete_Class，结构校验拒绝
        $file = $this->root . '/db/t.bin';
        file_put_contents($file, serialize([
            'schema' => new \stdClass(),
            'auto_increment' => 0,
            'rows' => [],
        ]));

        $restored = new PhpSerializeEngine($this->root);
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage($file);
        $restored->readRows('db', 't');
    }

    public function testMissingKeyPayloadThrows(): void
    {
        $engine = new PhpSerializeEngine($this->root);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $file = $this->root . '/db/t.bin';
        file_put_contents($file, serialize(['schema' => $this->makeSchema('t')]));

        $restored = new PhpSerializeEngine($this->root);
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage($file);
        $restored->readRows('db', 't');
    }

    public function testAtomicWriteLeavesNoTmpResidue(): void
    {
        $engine = new PhpSerializeEngine($this->root);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $engine->writeRows('db', 't', [['id' => 1, 'name' => 'a']]);
        $engine->setAutoIncrement('db', 't', 1);

        $this->assertFileExists($this->root . '/db/t.bin');
        $this->assertSame([], glob($this->root . '/db/*.tmp.*') ?: []);
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
