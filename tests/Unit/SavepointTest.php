<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\TransactionException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 事务内命名保存点（savepoint / rollBackTo / releaseSavepoint）行为测试
 */
final class SavepointTest extends TestCase
{
    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            $this->removeDirRecursive($this->tempDir);
            $this->tempDir = null;
        }
    }

    // ---- 基础 ----

    public function testBasicRollbackToSavepoint(): void
    {
        $connection = $this->makeConnection();
        $connection->createTable('users', self::usersDefinition());
        $connection->table('users')->insert(['name' => 'a']);

        $connection->begin();
        $connection->savepoint('sp1');
        $connection->table('users')->insertMany([['name' => 'b'], ['name' => 'c']]);
        $this->assertCount(3, $connection->engine()->readRows('main', 'users'));

        $connection->rollBackTo('sp1');

        // 保存点之后的插入撤销，保存点之前的行仍在
        $this->assertSame(
            [['id' => 1, 'name' => 'a']],
            $connection->engine()->readRows('main', 'users')
        );
        $this->assertTrue($connection->inTransaction());

        $connection->commit();
        $this->assertSame(
            [['id' => 1, 'name' => 'a']],
            $connection->engine()->readRows('main', 'users')
        );
    }

    public function testSavepointReusableAfterRollbackTo(): void
    {
        $connection = $this->makeConnection();
        $connection->createTable('users', self::usersDefinition());
        $connection->table('users')->insert(['name' => 'a']);

        $connection->begin();
        $connection->savepoint('sp');

        // 第一次回滚
        $connection->table('users')->insert(['name' => 'b']);
        $connection->rollBackTo('sp');
        $this->assertSame([['id' => 1, 'name' => 'a']], $connection->engine()->readRows('main', 'users'));

        // 保存点自身保留：再插行、再回滚到同一保存点仍可用（复用同一快照）
        $connection->table('users')->insertMany([['name' => 'c'], ['name' => 'd']]);
        $connection->rollBackTo('sp');
        $this->assertSame([['id' => 1, 'name' => 'a']], $connection->engine()->readRows('main', 'users'));

        // 保存点期间的自增推进同样被撤销（快照恢复）
        $result = $connection->table('users')->insert(['name' => 'e']);
        $this->assertSame(2, $result->lastInsertId());

        $connection->commit();
        $this->assertSame(
            [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'e']],
            $connection->engine()->readRows('main', 'users')
        );
    }

    public function testSameNameSavepointOverridesOlder(): void
    {
        $connection = $this->makeConnection();
        $connection->createTable('users', self::usersDefinition());

        $connection->begin();
        $connection->savepoint('sp');
        $connection->table('users')->insert(['name' => 'a']);

        // 同名重建：覆盖旧条目，回滚回到最近一次同名保存点
        $connection->savepoint('sp');
        $connection->table('users')->insert(['name' => 'b']);

        $connection->rollBackTo('sp');
        $this->assertSame([['id' => 1, 'name' => 'a']], $connection->engine()->readRows('main', 'users'));

        $connection->commit();
        $this->assertSame([['id' => 1, 'name' => 'a']], $connection->engine()->readRows('main', 'users'));
    }

    // ---- 嵌套与释放 ----

    public function testOuterRollbackDiscardsInnerSavepoints(): void
    {
        $connection = $this->makeConnection();
        $connection->createTable('users', self::usersDefinition());
        $connection->table('users')->insert(['name' => 'base']);

        $connection->begin();
        $connection->savepoint('A');
        $connection->table('users')->insert(['name' => 'in-a']);
        $connection->savepoint('B');
        $connection->table('users')->insert(['name' => 'in-b']);
        $this->assertCount(3, $connection->engine()->readRows('main', 'users'));

        // 外层回滚：A 之后（含 B 期间）的变更全部撤销，B 保存点随之失效
        $connection->rollBackTo('A');
        $this->assertSame(
            [['id' => 1, 'name' => 'base']],
            $connection->engine()->readRows('main', 'users')
        );

        try {
            $connection->rollBackTo('B');
            $this->fail('外层回滚后内层保存点 B 应已失效');
        } catch (TransactionException) {
            $this->addToAssertionCount(1);
        }

        // 提交后 A 期间插入的行不存在
        $connection->commit();
        $this->assertSame(
            [['id' => 1, 'name' => 'base']],
            $connection->engine()->readRows('main', 'users')
        );
    }

    public function testInnerRollbackKeepsOuterSavepoint(): void
    {
        $connection = $this->makeConnection();
        $connection->createTable('users', self::usersDefinition());

        $connection->begin();
        $connection->savepoint('A');
        $connection->table('users')->insert(['name' => 'in-a']);
        $connection->savepoint('B');
        $connection->table('users')->insert(['name' => 'in-b']);

        // 内层回滚：只撤销 B 之后的变更，A 期间的行保留
        $connection->rollBackTo('B');
        $this->assertSame(
            [['id' => 1, 'name' => 'in-a']],
            $connection->engine()->readRows('main', 'users')
        );

        $connection->rollBackTo('A');
        $this->assertSame([], $connection->engine()->readRows('main', 'users'));
        $connection->commit();
    }

    public function testReleaseSavepointInvalidatesRollbackToIt(): void
    {
        $connection = $this->makeConnection();
        $connection->createTable('users', self::usersDefinition());

        $connection->begin();
        $connection->savepoint('A');
        $connection->savepoint('B');
        $connection->releaseSavepoint('B');

        try {
            $connection->rollBackTo('B');
            $this->fail('已释放的保存点 rollBackTo 应抛 TransactionException');
        } catch (TransactionException) {
            $this->addToAssertionCount(1);
        }

        // 外层 A 未受影响仍可用
        $connection->table('users')->insert(['name' => 'a']);
        $connection->rollBackTo('A');
        $this->assertSame([], $connection->engine()->readRows('main', 'users'));
        $connection->commit();
    }

    public function testReleaseOuterSavepointDiscardsInner(): void
    {
        $connection = $this->makeConnection();
        $connection->createTable('users', self::usersDefinition());

        $connection->begin();
        $connection->savepoint('A');
        $connection->savepoint('B');
        $connection->savepoint('C');

        // SQL 标准：释放外层 A 时内层 B/C 一并失效
        $connection->releaseSavepoint('A');

        foreach (['A', 'B', 'C'] as $name) {
            try {
                $connection->rollBackTo($name);
                $this->fail("释放外层 A 后内层保存点 {$name} 应已失效");
            } catch (TransactionException) {
                $this->addToAssertionCount(1);
            }
        }

        // 数据不受 release 影响
        $connection->table('users')->insert(['name' => 'a']);
        $connection->commit();
        $this->assertSame(
            [['id' => 1, 'name' => 'a']],
            $connection->engine()->readRows('main', 'users')
        );
    }

    public function testReleaseThenSavepointAgainWithSameName(): void
    {
        $connection = $this->makeConnection();
        $connection->createTable('users', self::usersDefinition());

        $connection->begin();
        $connection->savepoint('sp');
        $connection->releaseSavepoint('sp');

        // 释放后可用同名重建
        $connection->savepoint('sp');
        $connection->table('users')->insert(['name' => 'a']);
        $connection->rollBackTo('sp');
        $this->assertSame([], $connection->engine()->readRows('main', 'users'));
        $connection->commit();
    }

    // ---- DDL 回滚 ----

    public function testRollbackToRestoresDdl(): void
    {
        $connection = $this->makeConnection();
        $connection->createTable('users', self::usersDefinition());
        $connection->table('users')->insert(['name' => 'a']);

        $connection->begin();
        $connection->savepoint('sp');

        $connection->createTable('extra', static function (Blueprint $table): void {
            $table->id();
            $table->varchar('memo', 30)->notNull();
        });
        $connection->table('extra')->insert(['memo' => 'x']);
        $connection->createView('user_names', $connection->table('users')->select('id', 'name'));
        $connection->dropTable('users');

        $this->assertTrue($connection->hasTable('extra'));
        $this->assertTrue($connection->hasView('user_names'));
        $this->assertFalse($connection->hasTable('users'));

        $connection->rollBackTo('sp');

        // 结构变更全部撤销：新表/新视图消失，被删表恢复（含数据）
        $this->assertFalse($connection->hasTable('extra'));
        $this->assertFalse($connection->hasView('user_names'));
        $this->assertTrue($connection->hasTable('users'));
        $this->assertSame(
            [['id' => 1, 'name' => 'a']],
            $connection->engine()->readRows('main', 'users')
        );

        $connection->commit();
        $this->assertTrue($connection->hasTable('users'));
        $this->assertFalse($connection->hasTable('extra'));
    }

    // ---- 索引缓存失效防线 ----

    public function testRollbackToInvalidatesIndexCache(): void
    {
        $connection = $this->makeConnection();
        $connection->createTable('users', self::usersDefinition());
        $connection->createIndex('users', 'idx_name', 'name');
        $connection->table('users')->insert(['name' => 'alice']);

        // 第一次查询建立索引缓存
        $this->assertSame(1, $connection->table('users')->where('name', 'alice')->count());

        $connection->begin();
        $connection->savepoint('sp');
        $connection->table('users')->insert(['name' => 'bob']);

        // 插入后的查询重建缓存（此时缓存与 writeVersion 同步）
        $this->assertSame(1, $connection->table('users')->where('name', 'bob')->count());

        $connection->rollBackTo('sp');

        // 回滚后缓存必须失效：再次查询应反映回滚后的真实数据而非陈旧缓存
        $this->assertSame(0, $connection->table('users')->where('name', 'bob')->count());
        $this->assertSame(1, $connection->table('users')->where('name', 'alice')->count());

        $connection->commit();
        $this->assertSame(
            [['id' => 1, 'name' => 'alice']],
            $connection->engine()->readRows('main', 'users')
        );
    }

    // ---- 误用与生命周期 ----

    public function testSavepointOperationsOutsideTransactionThrow(): void
    {
        $connection = $this->makeConnection();

        try {
            $connection->savepoint('sp');
            $this->fail('事务外 savepoint 应抛 TransactionException');
        } catch (TransactionException) {
            $this->addToAssertionCount(1);
        }
        try {
            $connection->rollBackTo('sp');
            $this->fail('事务外 rollBackTo 应抛 TransactionException');
        } catch (TransactionException) {
            $this->addToAssertionCount(1);
        }
        try {
            $connection->releaseSavepoint('sp');
            $this->fail('事务外 releaseSavepoint 应抛 TransactionException');
        } catch (TransactionException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testEmptySavepointNameThrows(): void
    {
        $connection = $this->makeConnection();
        $connection->begin();

        try {
            $connection->savepoint('');
            $this->fail('空保存点名应抛 TransactionException');
        } catch (TransactionException) {
            $this->addToAssertionCount(1);
        }

        $connection->rollBack();
    }

    public function testRollbackToUnknownSavepointThrows(): void
    {
        $connection = $this->makeConnection();
        $connection->begin();

        try {
            $connection->rollBackTo('nope');
            $this->fail('不存在的保存点 rollBackTo 应抛 TransactionException');
        } catch (TransactionException) {
            $this->addToAssertionCount(1);
        }
        try {
            $connection->releaseSavepoint('nope');
            $this->fail('不存在的保存点 releaseSavepoint 应抛 TransactionException');
        } catch (TransactionException) {
            $this->addToAssertionCount(1);
        }

        // 事务状态未被破坏
        $this->assertTrue($connection->inTransaction());
        $connection->rollBack();
    }

    public function testCommitAndRollBackClearSavepointStack(): void
    {
        // commit 清栈
        $connection = $this->makeConnection();
        $connection->createTable('users', self::usersDefinition());
        $connection->begin();
        $connection->savepoint('sp');
        $connection->commit();
        try {
            $connection->rollBackTo('sp');
            $this->fail('commit 后保存点栈应已清空');
        } catch (TransactionException) {
            $this->addToAssertionCount(1);
        }

        // rollBack 清栈
        $connection->begin();
        $connection->savepoint('sp2');
        $connection->rollBack();
        try {
            $connection->rollBackTo('sp2');
            $this->fail('rollBack 后保存点栈应已清空');
        } catch (TransactionException) {
            $this->addToAssertionCount(1);
        }
    }

    // ---- 文件引擎冒烟 ----

    public function testFileEngineSavepointSmoke(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/psql-sp-' . uniqid('', true);
        $connection = Psql::connect($this->tempDir);
        $connection->createTable('users', self::usersDefinition());

        $connection->begin();
        $connection->table('users')->insert(['name' => 'a']);
        $connection->savepoint('sp');
        $connection->table('users')->insertMany([['name' => 'b'], ['name' => 'c']]);
        $connection->table('users')->where('id', 1)->update(['name' => 'a2']);
        $connection->rollBackTo('sp');
        $this->assertSame(
            [['id' => 1, 'name' => 'a']],
            $connection->engine()->readRows('main', 'users')
        );
        $connection->commit();
        unset($connection);

        // 重开连接验证：仅保存点前的数据被持久化
        $reopened = Psql::connect($this->tempDir);
        $this->assertSame(
            [['id' => 1, 'name' => 'a']],
            $reopened->engine()->readRows('main', 'users')
        );
        $this->assertSame(1, $reopened->engine()->autoIncrement('main', 'users'));
    }

    // ---- 辅助 ----

    private function makeConnection(): Connection
    {
        return Psql::memory();
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
