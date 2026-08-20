<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Bridge;

use Kingbes\Psql\Bridge\PsqlOrm;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * think-orm 桥接驱动（Kingbes\Psql\Bridge\PsqlOrm）集成测试：真实 think-orm 的 \think\db\Query 驱动到 psql。
 *
 * 依赖 require-dev 中的 topthink/think-orm；未安装时自动跳过（不影响主测试套件）。
 */
final class ThinkOrmBridgeTest extends TestCase
{
    private string $dir;

    private PsqlOrm $conn;

    protected function setUp(): void
    {
        if (!class_exists(\think\DbManager::class)) {
            $this->markTestSkipped('缺少 topthink/think-orm，跳过桥接集成测试');
        }

        $this->dir = sys_get_temp_dir() . '/psql-thinkorm-' . uniqid('', true);
        Psql::connect($this->dir)->createTable('user', static function (Blueprint $t): void {
            $t->id();
            $t->varchar('name', 50)->notNull();
            $t->tinyint('age')->unsigned()->default(0);
            $t->datetime('created_at')->defaultNow();
        });

        $this->conn = new PsqlOrm([
            'type'     => PsqlOrm::class,
            'database' => $this->dir,
        ]);
        $this->conn->connect();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $this->removeDirRecursive($this->dir);
        }
    }

    public function testCrudAndQuery(): void
    {
        $q = $this->conn->name('user');

        // insert 返回自增主键
        $id = $q->insert(['name' => 'Alice', 'age' => 20], true);
        $this->assertSame(1, $id);

        $this->conn->name('user')->insert(['name' => 'Bob', 'age' => 30]);
        $this->conn->name('user')->insertAll([
            ['name' => 'Carol', 'age' => 18],
            ['name' => 'Dave', 'age' => 40],
            ['name' => 'Eve', 'age' => 25],
        ]);

        $this->assertCount(5, $q->select()->toArray());

        // where + order + limit
        $rows = $q->where('age', '>', 18)->order('age', 'desc')->limit(2)->select()->toArray();
        $this->assertSame(['Dave', 'Bob'], array_column($rows, 'name'));

        // find / value / column
        $this->assertSame(20, $q->where('name', 'Alice')->value('age'));
        $this->assertNull($q->where('name', 'Nobody')->find());
        $this->assertSame(
            ['Carol', 'Alice', 'Eve', 'Bob', 'Dave'],
            $this->conn->name('user')->order('age', 'asc')->column('name'),
        );

        // 聚合与 IN
        $this->assertSame(5, $this->conn->name('user')->count());
        $this->assertEqualsWithDelta(26.6, $this->conn->name('user')->avg('age'), 1e-6);
        $this->assertSame(2, $this->conn->name('user')->where('age', 'IN', [18, 30])->count());

        // 条件写
        $this->assertSame(1, $this->conn->name('user')->where('name', 'Bob')->update(['age' => 31]));
        $this->assertSame(31, $this->conn->name('user')->where('name', 'Bob')->value('age'));
        $this->assertSame(1, $this->conn->name('user')->where('name', 'Eve')->delete());
        $this->assertSame(0, $this->conn->name('user')->where('name', 'Eve')->count());
    }

    public function testTransactionCommitAndRollback(): void
    {
        $this->conn->transaction(function (PsqlOrm $conn): void {
            $conn->name('user')->insert(['name' => 'Tx', 'age' => 1]);
        });
        $this->assertSame(1, $this->conn->name('user')->where('name', 'Tx')->count());

        try {
            $this->conn->transaction(function (PsqlOrm $conn): void {
                $conn->name('user')->insert(['name' => 'Rolled', 'age' => 2]);
                throw new \RuntimeException('rollback me');
            });
            $this->fail('应抛出以触发回滚');
        } catch (\RuntimeException) {
            // 预期
        }

        $this->assertSame(0, $this->conn->name('user')->where('name', 'Rolled')->count());
    }

    public function testTableFieldsMetadata(): void
    {
        $fields = $this->conn->getTableFields('user');
        $this->assertArrayHasKey('name', $fields);
        $this->assertArrayHasKey('primary', $fields['id']);
        $this->assertTrue($fields['id']['primary']);
        $this->assertTrue($fields['id']['autoinc']);
        $this->assertSame(['user'], $this->conn->getTables());
        $this->assertSame('id', $this->conn->getPk('user'));
    }

    /**
     * webman 接入路径：把 topthink/think-orm 的 DbManager 绑定到 think 容器，
     * 使 think\facade\Db 可用（不依赖任何第三方 webman/think-orm 插件）。
     */
    public function testFacadeWiring(): void
    {
        $manager = new \think\DbManager();
        $manager->setConfig([
            'default' => 'psql',
            'connections' => [
                'psql' => [
                    'type'     => PsqlOrm::class,
                    'database' => $this->dir,
                ],
            ],
        ]);
        \think\Container::getInstance()->instance('think\DbManager', $manager);

        \think\facade\Db::name('user')->insert(['name' => 'F1', 'age' => 11]);
        \think\facade\Db::name('user')->insert(['name' => 'F2', 'age' => 22]);
        $rows = \think\facade\Db::name('user')->where('age', '>', 15)->select()->toArray();

        $this->assertCount(1, $rows);
        $this->assertSame('F2', $rows[0]['name']);
        $this->assertSame(2, \think\facade\Db::name('user')->count());
    }

    private function removeDirRecursive(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $item) {
            is_dir($item) ? $this->removeDirRecursive($item) : @unlink($item);
        }
        @rmdir($dir);
    }
}