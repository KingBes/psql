<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\TransactionException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 事务 begin/commit/rollBack 双引擎（memory / json 文件）行为测试
 */
final class TransactionTest extends TestCase
{
    private ?string $tempDir = null;

    /** @return list<array{string}> */
    public static function engineProvider(): array
    {
        return [
            ['memory'],
            ['file'],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            $this->removeDirRecursive($this->tempDir);
            $this->tempDir = null;
        }
    }

    // ---- commit ----

    #[DataProvider('engineProvider')]
    public function testCommitPersistsData(string $engine): void
    {
        $connection = $this->makeConnection($engine);
        $connection->createTable('users', self::usersDefinition());

        $connection->begin();
        $this->assertTrue($connection->inTransaction());

        $connection->table('users')->insert(['name' => '张三']);
        $connection->commit();

        $this->assertFalse($connection->inTransaction());
        $this->assertSame(
            [['id' => 1, 'name' => '张三']],
            $connection->engine()->readRows('main', 'users')
        );
    }

    public function testCommitPersistsAcrossReconnectOnFileEngine(): void
    {
        $connection = $this->makeConnection('file');
        $connection->createTable('users', self::usersDefinition());

        $connection->begin();
        $connection->table('users')->insertMany([
            ['name' => 'a'],
            ['name' => 'b'],
        ]);
        $connection->commit();
        unset($connection);

        $reopened = Psql::connect($this->tempDir);
        $this->assertTrue($reopened->hasTable('users'));
        $this->assertSame(
            [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']],
            $reopened->engine()->readRows('main', 'users')
        );
        $this->assertSame(2, $reopened->engine()->autoIncrement('main', 'users'));
    }

    // ---- rollBack ----

    #[DataProvider('engineProvider')]
    public function testRollBackRestoresRowsContentAndAutoIncrement(string $engine): void
    {
        $connection = $this->makeConnection($engine);
        $connection->createTable('users', self::usersDefinition());
        $connection->table('users')->insert(['name' => 'a']);

        $connection->begin();
        $connection->table('users')->insertMany([
            ['name' => 'b'],
            ['name' => 'c'],
        ]);
        $connection->table('users')->where('id', 2)->update(['name' => 'b2']);

        // 事务中：行数与自增均已推进
        $this->assertCount(3, $connection->engine()->readRows('main', 'users'));
        $this->assertSame(3, $connection->engine()->autoIncrement('main', 'users'));

        $connection->rollBack();

        $this->assertFalse($connection->inTransaction());
        $this->assertSame(
            [['id' => 1, 'name' => 'a']],
            $connection->engine()->readRows('main', 'users')
        );
        $this->assertSame(1, $connection->engine()->autoIncrement('main', 'users'));

        // 回滚后再插入：自增从恢复后的值继续，不与现存 id 冲突
        $result = $connection->table('users')->insert(['name' => 'd']);
        $this->assertSame(2, $result->lastInsertId());
    }

    #[DataProvider('engineProvider')]
    public function testRollBackRestoresDdl(string $engine): void
    {
        $connection = $this->makeConnection($engine);

        $connection->begin();
        $connection->createTable('users', self::usersDefinition());
        $connection->table('users')->insert(['name' => 'a']);
        $this->assertTrue($connection->hasTable('users'));
        $connection->rollBack();

        $this->assertFalse($connection->hasTable('users'));

        // 再建同名表不报错（结构与数据均回滚干净）
        $connection->createTable('users', self::usersDefinition());
        $this->assertTrue($connection->hasTable('users'));
        $this->assertSame([], $connection->engine()->readRows('main', 'users'));
        $this->assertSame(0, $connection->engine()->autoIncrement('main', 'users'));
    }

    #[DataProvider('engineProvider')]
    public function testRollBackRestoresDeletedRowsOnRestrictTable(string $engine): void
    {
        $connection = $this->makeConnection($engine);
        $this->createUsersAndOrders($connection);

        $connection->begin();
        // user 2 未被 orders 引用，RESTRICT 下允许删除
        $deleted = $connection->table('users')->where('id', 2)->delete();
        $this->assertSame(1, $deleted);
        $this->assertCount(1, $connection->engine()->readRows('main', 'users'));

        $connection->rollBack();

        $this->assertSame(
            [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']],
            $connection->engine()->readRows('main', 'users')
        );
        $this->assertSame(
            [['id' => 1, 'user_id' => 1, 'memo' => 'o1']],
            $connection->engine()->readRows('main', 'orders')
        );
    }

    #[DataProvider('engineProvider')]
    public function testRollBackRestoresCascadeDeletedReferenceData(string $engine): void
    {
        $connection = $this->makeConnection($engine);
        $connection->createTable('users', self::usersDefinition());
        $connection->createTable('orders', static function (Blueprint $table): void {
            $table->id();
            $table->bigint('user_id')->notNull();
            $table->foreignKey('user_id')->references('users', 'id')->onDeleteCascade();
            $table->varchar('memo', 30)->notNull();
        });
        $connection->table('users')->insertMany([['name' => 'a'], ['name' => 'b']]);
        $connection->table('orders')->insertMany([
            ['user_id' => 1, 'memo' => 'o1'],
            ['user_id' => 1, 'memo' => 'o2'],
            ['user_id' => 2, 'memo' => 'o3'],
        ]);

        $connection->begin();
        $deleted = $connection->table('users')->where('id', 1)->delete();
        $this->assertSame(1, $deleted);
        // 级联删除已发生：users 剩 1 行、orders 剩 1 行
        $this->assertCount(1, $connection->engine()->readRows('main', 'users'));
        $this->assertSame(
            [['id' => 3, 'user_id' => 2, 'memo' => 'o3']],
            $connection->engine()->readRows('main', 'orders')
        );

        $connection->rollBack();

        // 引用关系数据完整恢复
        $this->assertSame(
            [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']],
            $connection->engine()->readRows('main', 'users')
        );
        $this->assertSame(
            [
                ['id' => 1, 'user_id' => 1, 'memo' => 'o1'],
                ['id' => 2, 'user_id' => 1, 'memo' => 'o2'],
                ['id' => 3, 'user_id' => 2, 'memo' => 'o3'],
            ],
            $connection->engine()->readRows('main', 'orders')
        );
        $this->assertSame(3, $connection->engine()->autoIncrement('main', 'orders'));
    }

    // ---- 误用 ----

    #[DataProvider('engineProvider')]
    public function testCommitWithoutBeginThrows(string $engine): void
    {
        $connection = $this->makeConnection($engine);

        $this->expectException(TransactionException::class);
        $connection->commit();
    }

    #[DataProvider('engineProvider')]
    public function testRollBackWithoutBeginThrows(string $engine): void
    {
        $connection = $this->makeConnection($engine);

        $this->expectException(TransactionException::class);
        $connection->rollBack();
    }

    #[DataProvider('engineProvider')]
    public function testDoubleBeginThrows(string $engine): void
    {
        $connection = $this->makeConnection($engine);

        $connection->begin();
        try {
            $connection->begin();
            $this->fail('重复 begin 应抛 TransactionException');
        } catch (TransactionException $e) {
            $this->addToAssertionCount(1);
        }
        // 事务状态未被破坏，仍可正常回滚
        $this->assertTrue($connection->inTransaction());
        $connection->rollBack();
        $this->assertFalse($connection->inTransaction());
    }

    #[DataProvider('engineProvider')]
    public function testBeginAgainAfterRollBack(string $engine): void
    {
        $connection = $this->makeConnection($engine);
        $connection->createTable('users', self::usersDefinition());

        $connection->begin();
        $connection->table('users')->insert(['name' => 'a']);
        $connection->rollBack();

        // 快照已清空，可重新开启
        $this->assertFalse($connection->inTransaction());
        $connection->begin();
        $connection->table('users')->insert(['name' => 'b']);
        $connection->commit();

        $this->assertSame(
            [['id' => 1, 'name' => 'b']],
            $connection->engine()->readRows('main', 'users')
        );
    }

    #[DataProvider('engineProvider')]
    public function testBeginNewTransactionAfterCommit(string $engine): void
    {
        $connection = $this->makeConnection($engine);
        $connection->createTable('users', self::usersDefinition());

        $connection->begin();
        $connection->table('users')->insert(['name' => 'a']);
        $connection->commit();

        // 提交后可再开新事务，且新事务回滚不影响已提交数据
        $connection->begin();
        $connection->table('users')->insert(['name' => 'b']);
        $connection->rollBack();

        $this->assertSame(
            [['id' => 1, 'name' => 'a']],
            $connection->engine()->readRows('main', 'users')
        );
    }

    // ---- 辅助 ----

    private function makeConnection(string $engine): Connection
    {
        if ($engine === 'memory') {
            return Psql::memory();
        }
        $this->tempDir = sys_get_temp_dir() . '/psql-txn-' . uniqid('', true);

        return Psql::connect($this->tempDir);
    }

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
     * users(1=a 被引用, 2=b 未引用) + orders(RESTRICT 引用 user 1)
     */
    private function createUsersAndOrders(Connection $connection): void
    {
        $connection->createTable('users', self::usersDefinition());
        $connection->createTable('orders', static function (Blueprint $table): void {
            $table->id();
            $table->bigint('user_id')->notNull();
            $table->foreignKey('user_id')->references('users', 'id');
            $table->varchar('memo', 30)->notNull();
        });
        $connection->table('users')->insertMany([['name' => 'a'], ['name' => 'b']]);
        $connection->table('orders')->insert(['user_id' => 1, 'memo' => 'o1']);
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
