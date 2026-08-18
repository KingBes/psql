<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Agg;
use Kingbes\Psql\Query\Func;
use Kingbes\Psql\Query\SelectBuilder;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 视图 VIEW v2.0 行为测试：创建/查询/链式/克隆性/命名冲突/持久化/事务/切库/基表删除
 */
final class ViewTest extends TestCase
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

    // ---- 创建与查询 ----

    public function testCreateViewAndQuery(): void
    {
        $connection = Psql::memory();
        $this->createUsers($connection);

        $connection->createView('adults', $connection->table('users')->where('age', '>=', 18));

        $this->assertTrue($connection->hasView('adults'));
        $this->assertSame(
            [
                ['id' => 1, 'name' => 'a', 'age' => 20, 'city' => 'bj'],
                ['id' => 3, 'name' => 'c', 'age' => 30, 'city' => 'bj'],
            ],
            $connection->view('adults')->get()->rows()
        );

        // 视图只读（注释级断言）：view() 返回 SelectBuilder 查询构建器，insert 等写入口
        // 挂在 Table 上——视图不在表命名空间（hasTable false），无法经 table() 触达；
        // 引擎层的表 DDL（createIndex/alterTable/dropTable）亦被表存在性校验自然拦截
        $this->assertInstanceOf(SelectBuilder::class, $connection->view('adults'));
        $this->assertFalse($connection->hasTable('adults'));
    }

    public function testViewChainedRefinementAndAggregates(): void
    {
        $connection = Psql::memory();
        $this->createUsers($connection);
        $connection->createView('adults', $connection->table('users')->where('age', '>=', 18));

        // 视图上链式追加条件与排序
        $rows = $connection->view('adults')->where('age', '<', 25)->orderByDesc('age')->get()->rows();
        $this->assertSame([['id' => 1, 'name' => 'a', 'age' => 20, 'city' => 'bj']], $rows);

        // 聚合快捷方法
        $this->assertSame(2, $connection->view('adults')->count());
        $this->assertSame(30, $connection->view('adults')->max('age'));
        $this->assertSame(25.0, $connection->view('adults')->avg('age'));
    }

    public function testViewReturnsIndependentClonePerCall(): void
    {
        $connection = Psql::memory();
        $this->createUsers($connection);
        $connection->createView('adults', $connection->table('users')->where('age', '>=', 18));

        // 链式追加条件缩小结果集
        $refined = $connection->view('adults')->where('age', '>=', 25);
        $this->assertSame(1, $refined->count());

        // 再次取视图：存储定义不受前一次链式操作影响
        $this->assertSame(2, $connection->view('adults')->count());
    }

    // ---- 命名冲突与非法名 ----

    public function testViewNameConflicts(): void
    {
        $connection = Psql::memory();
        $this->createUsers($connection);

        // 与表名冲突
        try {
            $connection->createView('users', $connection->table('users')->select());
            $this->fail('视图名与表名冲突未抛异常');
        } catch (SchemaException $e) {
            $this->assertStringContainsString('users', $e->getMessage());
        }

        // 与其他视图名冲突
        $connection->createView('v', $connection->table('users')->select());
        $this->expectException(SchemaException::class);
        $connection->createView('v', $connection->table('users')->select());
    }

    public function testIllegalViewNameThrows(): void
    {
        $connection = Psql::memory();
        $this->createUsers($connection);

        foreach (['1abc', 'bad-name', 'with space', ''] as $name) {
            try {
                $connection->createView($name, $connection->table('users')->select());
                $this->fail("非法视图名未抛异常: {$name}");
            } catch (SchemaException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame([], $connection->views());
    }

    // ---- 不可持久化查询拒绝 ----

    public function testNonScalarHavingValueViewRejected(): void
    {
        $connection = Psql::memory();
        $this->createUsers($connection);

        // having 值非标量（数组）无法结构化序列化
        $query = $connection->table('users')
            ->select('city', \Kingbes\Psql\Query\Agg::count('*')->as('cnt'))
            ->groupBy('city')
            ->having('cnt', '>', [1, 2]);
        try {
            $connection->createView('vh', $query);
            $this->fail('非标量 having 值视图未被拒绝');
        } catch (QueryException $e) {
            $this->assertStringContainsString('不可持久化', $e->getMessage());
        }
        $this->assertFalse($connection->hasView('vh'));
    }

    public function testSubqueryConditionViewRejected(): void
    {
        $connection = Psql::memory();
        $this->createUsers($connection);

        // IN (子查询)
        $sub = $connection->table('users')->select('id')->where('age', '>=', 18);
        try {
            $connection->createView('v1', $connection->table('users')->whereIn('id', $sub));
            $this->fail('子查询条件视图未被拒绝');
        } catch (QueryException $e) {
            $this->assertStringContainsString('不可持久化', $e->getMessage());
        }

        // EXISTS (子查询)
        try {
            $connection->createView('v2', $connection->table('users')->whereExists($sub));
            $this->fail('EXISTS 子查询条件视图未被拒绝');
        } catch (QueryException $e) {
            $this->assertStringContainsString('不可持久化', $e->getMessage());
        }

        // 拒绝后视图未创建
        $this->assertFalse($connection->hasView('v1'));
        $this->assertFalse($connection->hasView('v2'));
    }

    public function testProjectionExpressionViewRejected(): void
    {
        $connection = Psql::memory();
        $this->createUsers($connection);

        // 函数/CASE 投影表达式无结构化序列化入口（v2.0 已知限制，文档化）
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('投影表达式');
        $connection->createView('upper_names', $connection->table('users')->select(Func::upper('name')));
    }

    // ---- dropView / hasView / views 列表 ----

    public function testDropViewAndListing(): void
    {
        $connection = Psql::memory();
        $this->createUsers($connection);
        foreach (['zebra', 'alpha', 'mid'] as $name) {
            $connection->createView($name, $connection->table('users')->select());
        }

        // 字典序
        $this->assertSame(['alpha', 'mid', 'zebra'], $connection->views());

        $connection->dropView('mid');
        $this->assertFalse($connection->hasView('mid'));
        $this->assertSame(['alpha', 'zebra'], $connection->views());

        // 不存在抛
        $this->expectException(SchemaException::class);
        $connection->dropView('mid');
    }

    public function testViewMissingThrows(): void
    {
        $connection = Psql::memory();

        $this->expectException(SchemaException::class);
        $connection->view('ghost');
    }

    // ---- 聚合/分组/HAVING/去重/UNION 视图 ----

    public function testAggregateGroupByHavingDistinctUnionViews(): void
    {
        $connection = Psql::memory();
        $this->createUsers($connection);

        // groupBy + having
        $connection->createView(
            'city_stats',
            $connection->table('users')->select('city', Agg::count('*')->as('total'))->groupBy('city')->having('total', '>=', 2)
        );
        $this->assertSame([['city' => 'bj', 'total' => 2]], $connection->view('city_stats')->get()->rows());

        // distinct + orderBy
        $connection->createView('cities', $connection->table('users')->select('city')->distinct()->orderBy('city'));
        $this->assertSame([['city' => 'bj'], ['city' => 'sh']], $connection->view('cities')->get()->rows());

        // union（视图定义递归展开联合子句）：成年人 ∪ 未成年（去重联合）
        $connection->createView(
            'picked_names',
            $connection->table('users')->select('name')->where('age', '>=', 18)->orderBy('name')
                ->union($connection->table('users')->select('name')->where('age', '<', 16))
        );
        $this->assertSame(['a', 'b', 'c'], array_column($connection->view('picked_names')->get()->rows(), 'name'));
    }

    // ---- 持久化（文件引擎跨重开） ----

    public function testViewPersistsAcrossReopen(): void
    {
        $connection = $this->makeConnection('file');
        $this->createUsers($connection);
        $connection->createTable('orders', static function (Blueprint $table): void {
            $table->id();
            $table->bigint('user_id')->notNull();
            $table->varchar('memo', 30)->notNull();
        });
        $connection->table('orders')->insertMany([
            ['user_id' => 1, 'memo' => 'o1'],
            ['user_id' => 1, 'memo' => 'o2'],
            ['user_id' => 2, 'memo' => 'o3'],
        ]);

        // 含 where 树 / join / orderBy / limit / offset 的复杂视图
        $connection->createView(
            'user_orders',
            $connection->table('users')
                ->select('users.name', 'orders.memo')
                ->join('orders', 'users.id', '=', 'orders.user_id')
                ->where('users.age', '>=', 18)
                ->orderBy('users.name')
                ->orderBy('orders.memo')
                ->limit(3)
                ->offset(1)
        );

        $expected = $connection->view('user_orders')->get()->rows();
        $this->assertNotSame([], $expected);

        unset($connection);
        $reopened = Psql::connect($this->tempDir);

        $this->assertTrue($reopened->hasView('user_orders'));
        $this->assertSame(['user_orders'], $reopened->views());
        $this->assertSame($expected, $reopened->view('user_orders')->get()->rows());
    }

    // ---- 事务（引擎快照/恢复覆盖视图定义） ----

    #[DataProvider('engineProvider')]
    public function testRollBackCancelsCreateView(string $engine): void
    {
        $connection = $this->makeConnection($engine);
        $this->createUsers($connection);

        $connection->begin();
        $connection->createView('adults', $connection->table('users')->where('age', '>=', 18));
        $this->assertTrue($connection->hasView('adults'));
        $connection->rollBack();

        $this->assertFalse($connection->hasView('adults'));
        $this->assertSame([], $connection->views());

        // 回滚后可重新创建同名视图
        $connection->createView('adults', $connection->table('users')->where('age', '>=', 18));
        $this->assertTrue($connection->hasView('adults'));
    }

    #[DataProvider('engineProvider')]
    public function testRollBackRestoresDroppedView(string $engine): void
    {
        $connection = $this->makeConnection($engine);
        $this->createUsers($connection);
        $connection->createView('adults', $connection->table('users')->where('age', '>=', 18));
        $expected = $connection->view('adults')->get()->rows();

        $connection->begin();
        $connection->dropView('adults');
        $this->assertFalse($connection->hasView('adults'));
        $connection->rollBack();

        $this->assertTrue($connection->hasView('adults'));
        $this->assertSame($expected, $connection->view('adults')->get()->rows());
    }

    #[DataProvider('engineProvider')]
    public function testCommitKeepsView(string $engine): void
    {
        $connection = $this->makeConnection($engine);
        $this->createUsers($connection);

        $connection->begin();
        $connection->createView('adults', $connection->table('users')->where('age', '>=', 18));
        $connection->commit();

        $this->assertTrue($connection->hasView('adults'));
    }

    // ---- use() 切库独立 ----

    public function testViewsArePerDatabase(): void
    {
        $connection = Psql::memory();
        $this->createUsers($connection);
        $connection->createView('adults', $connection->table('users')->where('age', '>=', 18));

        $connection->createDatabase('other');
        $connection->use('other');

        $this->assertFalse($connection->hasView('adults'));
        $this->assertSame([], $connection->views());
        $this->expectException(SchemaException::class);
        $connection->view('adults');
    }

    // ---- 基表删除 / 表 DDL 对视图名拦截 ----

    public function testDropTableReferencedByViewThrows(): void
    {
        $connection = Psql::memory();
        $this->createUsers($connection);
        $connection->createView('adults', $connection->table('users')->where('age', '>=', 18));

        // 拒绝删除被视图引用的基表（MySQL 语义），表与视图均保留
        try {
            $connection->dropTable('users');
            $this->fail('删除被视图引用的基表未抛异常');
        } catch (SchemaException $e) {
            $this->assertStringContainsString('users', $e->getMessage());
            $this->assertStringContainsString('adults', $e->getMessage());
        }
        $this->assertTrue($connection->hasTable('users'));
        $this->assertTrue($connection->hasView('adults'));

        // 删除视图后即可删除基表
        $connection->dropView('adults');
        $connection->dropTable('users');
        $this->assertFalse($connection->hasTable('users'));
    }

    public function testRenameTableReferencedByViewThrows(): void
    {
        $connection = Psql::memory();
        $this->createUsers($connection);
        $connection->createView('adults', $connection->table('users')->where('age', '>=', 18));

        // 拒绝重命名被视图引用的基表（MySQL 语义）
        try {
            $connection->renameTable('users', 'members');
            $this->fail('重命名被视图引用的基表未抛异常');
        } catch (SchemaException $e) {
            $this->assertStringContainsString('users', $e->getMessage());
            $this->assertStringContainsString('adults', $e->getMessage());
        }
        $this->assertTrue($connection->hasTable('users'));
        $this->assertFalse($connection->hasTable('members'));

        // 视图不引用该表时重命名照常
        $connection->createTable('free', static function (Blueprint $table): void {
            $table->id();
            $table->varchar('note', 20);
        });
        $connection->renameTable('free', 'freed');
        $this->assertFalse($connection->hasTable('free'));
        $this->assertTrue($connection->hasTable('freed'));
    }

    public function testCreateIndexOnViewNameThrows(): void
    {
        $connection = Psql::memory();
        $this->createUsers($connection);
        $connection->createView('v', $connection->table('users')->select());

        // 表命名空间互斥：createIndex 对视图名走 loadSchema 的表存在性校验
        $this->expectException(StorageException::class);
        $connection->createIndex('v', 'idx_v', 'id');
    }

    // ---- 辅助 ----

    private function makeConnection(string $engine): Connection
    {
        if ($engine === 'memory') {
            return Psql::memory();
        }
        $this->tempDir = sys_get_temp_dir() . '/psql-view-' . uniqid('', true);

        return Psql::connect($this->tempDir);
    }

    /**
     * users 表 + 3 行（a=20/bj, b=15/sh, c=30/bj）
     */
    private function createUsers(Connection $connection): void
    {
        $connection->createTable('users', static function (Blueprint $table): void {
            $table->id();
            $table->varchar('name', 50)->notNull();
            $table->int('age')->notNull();
            $table->varchar('city', 20)->notNull();
        });
        $connection->table('users')->insertMany([
            ['name' => 'a', 'age' => 20, 'city' => 'bj'],
            ['name' => 'b', 'age' => 15, 'city' => 'sh'],
            ['name' => 'c', 'age' => 30, 'city' => 'bj'],
        ]);
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
