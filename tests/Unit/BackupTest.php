<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Exception\TransactionException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\ColumnSchema;
use Kingbes\Psql\Schema\DataType;
use Kingbes\Psql\Schema\TableSchema;
use Kingbes\Psql\Storage\Codec;
use Kingbes\Psql\Storage\JsonFileEngine;
use Kingbes\Psql\Storage\PagedJsonEngine;
use PHPUnit\Framework\TestCase;

/**
 * backup() API 测试：全库备份一致性 / 目标目录规则 / 事务禁止 / 加密库备份 / 引擎覆盖
 */
final class BackupTest extends TestCase
{
    private string $root;

    private string $target;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psql-backup-src-' . uniqid();
        $this->target = sys_get_temp_dir() . '/psql-backup-dst-' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach ([$this->root, $this->target] as $dir) {
            if (is_dir($dir)) {
                $this->removeDirRecursive($dir);
            }
            // 失败清理残留的同级临时目录
            foreach (glob($dir . '.tmp-*') ?: [] as $residue) {
                $this->removeDirRecursive($residue);
            }
        }
    }

    /**
     * 构造两张列的示例表结构
     */
    private function makeSchema(string $name): TableSchema
    {
        return new TableSchema($name, [
            new ColumnSchema(name: 'id', type: DataType::BIGINT, unsigned: true, primaryKey: true, autoIncrement: true),
            new ColumnSchema(name: 'name', type: DataType::VARCHAR, length: 50, notNull: true),
        ]);
    }

    public function testBackupRoundTripViaConnect(): void
    {
        $connection = Psql::connect($this->root);
        $engine = $connection->engine();
        $schema = $this->makeSchema('users');
        $engine->createTable('main', $schema);
        $rows = [
            ['id' => 1, 'name' => '张三'],
            ['id' => 2, 'name' => '李四'],
        ];
        $engine->writeRows('main', 'users', $rows);
        $engine->setAutoIncrement('main', 'users', 2);
        $views = ['adults' => ['name' => 'adults', 'query' => ['table' => 'users', 'limit' => 10]]];
        $engine->saveViewDefinitions('main', $views);

        $connection->backup($this->target);

        // 备份目录即合法库根目录：仅含 main 子目录，锁文件与 tmp 残留不进入备份
        $this->assertDirectoryExists($this->target . '/main');
        $this->assertFileDoesNotExist($this->target . '/.lock');
        $this->assertSame([], glob($this->target . '.tmp-*') ?: [], '不得残留备份临时目录');

        // connect 直接打开：表/schema/数据/自增/视图全一致
        $restored = Psql::connect($this->target);
        $this->assertSame(['main'], $restored->engine()->databases());
        $this->assertSame(['users'], $restored->engine()->tables('main'));
        $this->assertSame($schema->toArray(), $restored->engine()->loadSchema('main', 'users')->toArray());
        $this->assertSame($rows, $restored->engine()->readRows('main', 'users'));
        $this->assertSame(2, $restored->engine()->autoIncrement('main', 'users'));
        $this->assertSame($views, $restored->engine()->loadViewDefinitions('main'));
    }

    public function testBackupTargetMustBeMissingOrEmpty(): void
    {
        $connection = Psql::connect($this->root);

        // 非空目录 → 拒绝
        mkdir($this->target . '/occupied', 0777, true);
        try {
            $connection->backup($this->target);
            $this->fail('备份到非空目录未抛异常');
        } catch (StorageException $e) {
            $this->assertStringContainsString('须不存在或为空', $e->getMessage());
        }
        // 失败后不得残留临时目录
        $this->assertSame([], glob($this->target . '.tmp-*') ?: []);

        // 已存在文件 → 拒绝
        $fileTarget = $this->target . '-file';
        file_put_contents($fileTarget, 'x');
        try {
            $connection->backup($fileTarget);
            $this->fail('备份到文件路径未抛异常');
        } catch (StorageException $e) {
            $this->assertStringContainsString('不是目录', $e->getMessage());
        }
        unlink($fileTarget);

        // 空目录 → 允许
        $emptyTarget = $this->target . '-empty';
        mkdir($emptyTarget, 0777, true);
        $connection->backup($emptyTarget);
        $this->assertDirectoryExists($emptyTarget . '/main');
    }

    public function testBackupDuringTransactionThrows(): void
    {
        $connection = Psql::connect($this->root);
        $connection->engine()->createTable('main', $this->makeSchema('t'));

        $connection->begin();
        try {
            $connection->backup($this->target);
            $this->fail('事务中备份未抛异常');
        } catch (TransactionException $e) {
            $this->assertStringContainsString('备份', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->target);

        // 回滚后可正常备份
        $connection->rollBack();
        $connection->backup($this->target);
        $this->assertDirectoryExists($this->target . '/main');
    }

    public function testEncryptedLibraryBackupStaysEncrypted(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl 扩展不可用');
        }
        $connection = Psql::connect($this->root, ['key' => 'k']);
        $engine = $connection->engine();
        $engine->createTable('main', $this->makeSchema('t'));
        $rows = [['id' => 1, 'name' => '机密']];
        $engine->writeRows('main', 't', $rows);

        $connection->backup($this->target);

        // 备份文件同为密文
        $this->assertStringStartsWith(Codec::MAGIC_ENC, (string) file_get_contents($this->target . '/main/t.json'));

        // 原 key 打开：数据一致
        $restored = Psql::connect($this->target, ['key' => 'k']);
        $this->assertSame($rows, $restored->engine()->readRows('main', 't'));

        // 无 key / 错 key 打开：读时抛
        try {
            Psql::connect($this->target)->engine()->readRows('main', 't');
            $this->fail('无 key 读加密备份未抛异常');
        } catch (StorageException $e) {
            $this->assertStringContainsString('密钥', $e->getMessage());
        }
        try {
            Psql::connect($this->target, ['key' => 'wrong'])->engine()->readRows('main', 't');
            $this->fail('错 key 读加密备份未抛异常');
        } catch (StorageException $e) {
            $this->assertStringContainsString('密钥', $e->getMessage());
        }
    }

    public function testMemoryEngineBackupThrows(): void
    {
        $connection = Psql::memory();
        try {
            $connection->backup($this->target);
            $this->fail('内存引擎备份未抛异常');
        } catch (StorageException $e) {
            $this->assertStringContainsString('内存引擎不支持备份', $e->getMessage());
        }
    }

    public function testBackupMissingDatabaseThrows(): void
    {
        $engine = new JsonFileEngine($this->root);
        try {
            $engine->backupDatabase('ghost', $this->target);
            $this->fail('备份不存在的库未抛异常');
        } catch (StorageException $e) {
            $this->assertStringContainsString('数据库不存在', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->target);
    }

    public function testBackupCustomDatabaseName(): void
    {
        $connection = Psql::connect($this->root);
        $connection->createDatabase('shop');
        $connection->use('shop');
        $engine = $connection->engine();
        $engine->createTable('shop', $this->makeSchema('goods'));
        $rows = [['id' => 1, 'name' => 'apple']];
        $engine->writeRows('shop', 'goods', $rows);

        // 备份当前库（shop）
        $connection->backup($this->target);
        $this->assertDirectoryExists($this->target . '/shop');

        // 备份目录仅含 shop（连接层尚未打开过，不会自动建 main）
        $this->assertSame(['shop'], (new JsonFileEngine($this->target))->databases());
        $this->assertSame($rows, (new JsonFileEngine($this->target))->readRows('shop', 'goods'));

        // Psql::connect 打开备份：自动创建 main 后 shop 完整可用
        $restored = Psql::connect($this->target);
        $restored->use('shop');
        $this->assertSame($rows, $restored->engine()->readRows('shop', 'goods'));
    }

    public function testPagedJsonEngineBackupRoundTrip(): void
    {
        $engine = new PagedJsonEngine($this->root, 2);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $engine->writeRows('db', 't', $rows);
        $engine->deleteRows('db', 't', [2]);
        $views = ['v' => ['name' => 'v', 'query' => ['table' => 't']]];
        $engine->saveViewDefinitions('db', $views);
        $expected = [$rows[0], $rows[1], $rows[3], $rows[4]];

        // 引擎级备份
        $engine->backupDatabase('db', $this->target);
        $this->assertFileExists($this->target . '/db/t.meta.json');

        $fresh = new PagedJsonEngine($this->target);
        $this->assertSame(['db'], $fresh->databases());
        $this->assertSame($expected, $fresh->readRows('db', 't'));
        $this->assertSame($views, $fresh->loadViewDefinitions('db'));

        // Connection 层（当前库 db）备份到另一目标
        $connection = new Connection($engine, 'db');
        $target2 = $this->target . '-2';
        $connection->backup($target2);
        $this->assertSame($expected, (new PagedJsonEngine($target2))->readRows('db', 't'));
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
