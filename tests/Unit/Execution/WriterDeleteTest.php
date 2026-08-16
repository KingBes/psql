<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\ConstraintException;
use Kingbes\Psql\Execution\Writer;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Schema\Blueprint;
use Kingbes\Psql\Schema\ForeignKeyAction;
use Kingbes\Psql\Storage\PagedJsonEngine;
use PHPUnit\Framework\TestCase;

/**
 * Writer delete 应用路径端到端测试（PagedJson 墓碑）：
 * 纯删除走 deleteRows（仅受影响页 gen+1）、级联/SET_NULL 混合场景语义回归
 */
final class WriterDeleteTest extends TestCase
{
    private string $root;

    private Connection $conn;

    private Writer $writer;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psql-writer-del-' . uniqid();
        // pageSize=16：页边界与行位置的映射易于断言
        $this->conn = new Connection(new PagedJsonEngine($this->root, 16));
        $this->writer = new Writer($this->conn);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->removeDirRecursive($this->root);
        }
    }

    public function testDeleteMiddleRowOnlyRewritesOnePageFile(): void
    {
        $this->conn->createTable('items', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 16);
        });
        $batch = [];
        for ($i = 1; $i <= 200; $i++) {
            $batch[] = ['name' => 'item' . $i];
        }
        $this->writer->insert('items', null, $batch);

        $dir = $this->root . '/main';
        $before = $this->pageSnapshot($dir);
        // 200 行 / ps=16 → 13 页（页 12 仅 8 行）
        $this->assertCount(13, $before);

        // 删除中间行 id=100（索引 99 → 页 6）：仅 1 个页文件变化（gen+1），其余 12 页逐字节不变
        $affected = $this->writer->delete('items', null, $this->where('id', 100));
        $this->assertSame(1, $affected);

        $after = $this->pageSnapshot($dir);
        $changed = [];
        foreach ($after as $file => $hash) {
            if (!isset($before[$file]) || $before[$file] !== $hash) {
                $changed[] = $file;
            }
        }
        foreach (array_keys($before) as $file) {
            if (!isset($after[$file])) {
                $changed[] = $file . ' (removed)';
            }
        }
        // 变化 = 页 6 新 gen 文件出现 + 旧 gen 文件消失（排序消除遍历顺序影响）
        sort($changed);
        $this->assertSame(['items.6.0.page.json (removed)', 'items.6.1.page.json'], $changed);

        // 查询结果正确：199 行且 id=100 缺失
        $rows = $this->rows('items');
        $this->assertCount(199, $rows);
        $this->assertNotContains(100, array_column($rows, 'id'), '被删行不得残留');
        $this->assertSame(99, $rows[98]['id'], '删除行之后的行序号前移');
        $this->assertSame(101, $rows[99]['id']);

        // 墓碑已记账（证明走的是 deleteRows 而非 writeRows 全量替换）
        $meta = json_decode((string) file_get_contents($dir . '/items.meta.json'), true);
        $this->assertSame(1, $meta['dead']);

        // 自增不受删除影响
        $result = $this->writer->insert('items', null, [['name' => 'tail']]);
        $this->assertSame(201, $result->lastInsertId());
    }

    public function testCascadeDeleteMarksTombstonesOnBothTables(): void
    {
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 16);
        });
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id');
            $b->foreignKey('user_id')->references('users', 'id')->onDeleteCascade();
        });

        $users = [];
        for ($i = 1; $i <= 5; $i++) {
            $users[] = ['name' => 'u' . $i];
        }
        $this->writer->insert('users', null, $users);
        $orders = [];
        for ($i = 1; $i <= 5; $i++) {
            $orders[] = ['user_id' => $i];
            $orders[] = ['user_id' => $i];
        }
        $this->writer->insert('orders', null, $orders);

        // 级联删除：users.id=3 → orders 两行级联删除，两表均走 deleteRows（墓碑记账）
        $affected = $this->writer->delete('users', null, $this->where('id', 3));
        $this->assertSame(1, $affected, '返回值只计初始 matched 行');

        $this->assertCount(4, $this->rows('users'));
        $ordersLeft = $this->rows('orders');
        $this->assertCount(8, $ordersLeft);
        $this->assertNotContains(3, array_column($ordersLeft, 'user_id'), '级联行不得残留');

        $dir = $this->root . '/main';
        $usersMeta = json_decode((string) file_get_contents($dir . '/users.meta.json'), true);
        $ordersMeta = json_decode((string) file_get_contents($dir . '/orders.meta.json'), true);
        $this->assertSame(1, $usersMeta['dead'], '初始删除表应走 deleteRows');
        $this->assertSame(2, $ordersMeta['dead'], '级联删除表应走 deleteRows');
    }

    public function testSetNullDeleteKeepsReferencingRows(): void
    {
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 16);
        });
        $this->conn->createTable('profiles', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id');
            $b->foreignKey('user_id')->references('users', 'id')->onDelete(ForeignKeyAction::SET_NULL);
        });

        $this->writer->insert('users', null, [['name' => 'a'], ['name' => 'b']]);
        $this->writer->insert('profiles', null, [['user_id' => 1], ['user_id' => 2]]);

        $affected = $this->writer->delete('users', null, $this->where('id', 1));
        $this->assertSame(1, $affected);

        $this->assertCount(1, $this->rows('users'));
        $profiles = $this->rows('profiles');
        $this->assertCount(2, $profiles);
        $this->assertNull($profiles[0]['user_id'], 'SET_NULL 应置空引用列');
        $this->assertSame(2, $profiles[1]['user_id']);

        // 纯删除表走 deleteRows（墓碑），仅修改表走 writeRows（无墓碑）
        $dir = $this->root . '/main';
        $usersMeta = json_decode((string) file_get_contents($dir . '/users.meta.json'), true);
        $profilesMeta = json_decode((string) file_get_contents($dir . '/profiles.meta.json'), true);
        $this->assertSame(1, $usersMeta['dead']);
        $this->assertSame(0, $profilesMeta['dead']);
    }

    public function testSetNullOnSelfReferenceUsesMergedWritePath(): void
    {
        $this->conn->createTable('nodes', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('parent_id');
            $b->foreignKey('parent_id')->references('nodes', 'id')->onDelete(ForeignKeyAction::SET_NULL);
        });

        // 逐批插入：insert 的 FK 校验只看已落库行，批次内引用不被解析
        $this->writer->insert('nodes', null, [['id' => 1, 'parent_id' => null]]);
        $this->writer->insert('nodes', null, [['id' => 2, 'parent_id' => 1], ['id' => 3, 'parent_id' => 1]]);
        $this->writer->insert('nodes', null, [['id' => 4, 'parent_id' => 2]]);

        // 同表删除 + SET_NULL 修改并存：走 writeRows 合并路径（正确性优先）
        $affected = $this->writer->delete('nodes', null, $this->where('id', 1));
        $this->assertSame(1, $affected);

        $nodes = $this->rows('nodes');
        $this->assertCount(3, $nodes);
        $this->assertNull($nodes[0]['parent_id']);
        $this->assertNull($nodes[1]['parent_id']);
        $this->assertSame(2, $nodes[2]['parent_id']);

        // 合并路径无墓碑：dead=0
        $meta = json_decode((string) file_get_contents($this->root . '/main/nodes.meta.json'), true);
        $this->assertSame(0, $meta['dead']);
    }

    public function testRestrictStillBlocksDelete(): void
    {
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 16);
        });
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id');
            $b->foreignKey('user_id')->references('users', 'id');
        });

        $this->writer->insert('users', null, [['name' => 'a'], ['name' => 'b']]);
        $this->writer->insert('orders', null, [['user_id' => 1]]);

        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('RESTRICT');
        $this->writer->delete('users', null, $this->where('id', 1));
    }

    public function testDeleteMultipleRowsAcrossPagesOnPagedEngine(): void
    {
        $this->conn->createTable('items', static function (Blueprint $b): void {
            $b->id();
            $b->int('v');
        });
        $batch = [];
        for ($i = 1; $i <= 100; $i++) {
            $batch[] = ['v' => $i];
        }
        $this->writer->insert('items', null, $batch);

        // 跨多页范围批量删除（30 ≤ v ≤ 60：31 行，横跨页 1..3）
        $deleted = $this->writer->delete(
            'items',
            null,
            (new ConditionGroup())->where('v', '>=', 30)->where('v', '<=', 60)
        );
        $this->assertSame(31, $deleted);

        $rows = $this->rows('items');
        $this->assertCount(69, $rows);
        foreach ($rows as $row) {
            $this->assertTrue($row['v'] < 30 || $row['v'] > 60, '范围内行应全部删除');
        }

        // 新实例重开数据一致
        $fresh = new PagedJsonEngine($this->root, 16);
        $this->assertCount(69, $fresh->readRows('main', 'items'));
    }

    private function where(string $column, mixed ...$args): ConditionGroup
    {
        return (new ConditionGroup())->where($column, ...$args);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(string $table): array
    {
        return $this->conn->engine()->readRows($this->conn->currentDatabase(), $table);
    }

    /**
     * 页文件清单快照：文件名 => 内容 md5
     *
     * @return array<string, string>
     */
    private function pageSnapshot(string $dir): array
    {
        $map = [];
        $files = array_values(array_filter(
            scandir($dir) ?: [],
            static fn (string $f): bool => str_ends_with($f, '.page.json')
        ));
        sort($files);
        foreach ($files as $file) {
            $map[$file] = (string) md5_file($dir . '/' . $file);
        }

        return $map;
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
