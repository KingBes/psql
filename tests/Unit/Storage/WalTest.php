<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Storage;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * WAL / 崩溃恢复测试（'wal' => true）：
 * - 未提交事务崩溃后重新打开自动回滚（undo.snap）
 * - 已提交事务 / 非事务写不受影响
 * - 与单 writer 多 reader 并发组合可用
 */
final class WalTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/psql-wal-' . uniqid('', true);
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $this->removeDirRecursive($this->dir);
        }
    }

    private function open(): Connection
    {
        $db = Psql::connect($this->dir, ['wal' => true]);
        if (!in_array('db', $db->databases(), true)) {
            $db->createDatabase('db');
        }
        $db->use('db');

        return $db;
    }

    private function names(Connection $db): array
    {
        return array_column($db->table('users')->orderBy('id')->select('name')->get()->rows(), 'name');
    }

    private function setupUsers(Connection $db): void
    {
        $db->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 30)->notNull();
        });
        $db->table('users')->insert(['name' => 'alice']);
    }

    public function testReopenPreservesData(): void
    {
        $db = $this->open();
        $this->setupUsers($db);
        unset($db);

        $db = $this->open();
        $this->assertSame(['alice'], $this->names($db));
        $this->assertFalse(is_file($this->dir . '/undo.snap'));
    }

    public function testCommittedTransactionPersists(): void
    {
        $db = $this->open();
        $this->setupUsers($db);
        $db->begin();
        $db->table('users')->insert(['name' => 'bob']);
        $db->commit();
        unset($db);

        $db = $this->open();
        $this->assertSame(['alice', 'bob'], $this->names($db));
        $this->assertFalse(is_file($this->dir . '/undo.snap'));
    }

    public function testUncommittedTransactionRollsBackOnReopen(): void
    {
        $db = $this->open();
        $this->setupUsers($db);
        $db->begin();
        $db->table('users')->insert(['name' => 'carol']);
        $db->table('users')->insert(['name' => 'dave']);
        // 模拟崩溃：不 commit/rollback 直接断开连接 → undo.snap 残留
        unset($db);

        $this->assertTrue(is_file($this->dir . '/undo.snap'));

        // 重新打开：自动恢复 → 未提交事务回滚
        $db = $this->open();
        $this->assertSame(['alice'], $this->names($db));
        $this->assertFalse(is_file($this->dir . '/undo.snap'), '恢复后应清理 undo.snap');
    }

    public function testNonTransactionWriteUnaffected(): void
    {
        $db = $this->open();
        $this->setupUsers($db);
        $db->table('users')->insert(['name' => 'eve']);
        unset($db);

        $this->assertFalse(is_file($this->dir . '/undo.snap'));
        $db = $this->open();
        $this->assertSame(['alice', 'eve'], $this->names($db));
    }

    public function testWalWithConcurrencyCombo(): void
    {
        $db = Psql::connect($this->dir, ['wal' => true, 'concurrency' => true]);
        $db->createDatabase('db');
        $db->use('db');
        $this->setupUsers($db);
        $db->begin();
        $db->table('users')->insert(['name' => 'frank']);
        $db->commit();
        unset($db);

        $db2 = Psql::connect($this->dir, ['wal' => true, 'concurrency' => true]);
        $db2->use('db');
        $this->assertSame(['alice', 'frank'], $this->names($db2));
    }

    public function testRollbackCleansUndoSnapshot(): void
    {
        $db = $this->open();
        $this->setupUsers($db);
        $db->begin();
        $db->table('users')->insert(['name' => 'gina']);
        $db->rollBack();
        unset($db);

        $this->assertFalse(is_file($this->dir . '/undo.snap'));
        $db = $this->open();
        $this->assertSame(['alice'], $this->names($db));
    }

    private function removeDirRecursive(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
