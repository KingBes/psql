<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Storage;

use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Storage\PagedJsonEngine;
use Kingbes\Psql\Storage\StorageEngine;

require_once __DIR__ . '/StorageEngineContractTestCase.php';

/**
 * PagedJsonEngine 契约 + 专属测试：增量写盘 / 崩溃恢复 / 孤儿清理 / 分页边界
 */
final class PagedJsonEngineTest extends StorageEngineContractTestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psql-paged-test-' . uniqid();
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
        return new PagedJsonEngine($this->root);
    }

    public function testPersistenceRoundTrip(): void
    {
        $engine = new PagedJsonEngine($this->root, 2);
        $engine->createDatabase('shop');
        $engine->createTable('shop', $this->makeSchema('users'));
        $rows = [
            ['id' => 1, 'name' => '张三'],
            ['id' => 2, 'name' => '李四'],
            ['id' => 3, 'name' => '王五'],
            ['id' => 4, 'name' => '赵六'],
            ['id' => 5, 'name' => '钱七'],
        ];
        $engine->writeRows('shop', 'users', $rows);
        $engine->setAutoIncrement('shop', 'users', 5);

        // 文件布局断言：meta + 每页一个 .page.json（5 行 / ps=2 → 3 页）
        $this->assertFileExists($this->root . '/shop/users.meta.json');
        $this->assertFileExists($this->root . '/shop/users.0.0.page.json');
        $this->assertFileExists($this->root . '/shop/users.1.0.page.json');
        $this->assertFileExists($this->root . '/shop/users.2.0.page.json');

        // 新实例指向同一 root：数据 / 结构 / 自增值完整
        $restored = new PagedJsonEngine($this->root, 999);
        $this->assertSame(['shop'], $restored->databases());
        $this->assertSame(['users'], $restored->tables('shop'));

        $schema = $restored->loadSchema('shop', 'users');
        $this->assertSame('users', $schema->name);
        $this->assertTrue($schema->hasColumn('id'));
        $this->assertTrue($schema->hasColumn('name'));

        $this->assertSame($rows, $restored->readRows('shop', 'users'));
        $this->assertSame(5, $restored->autoIncrement('shop', 'users'));
    }

    public function testIncrementalSinglePageUpdate(): void
    {
        $engine = new PagedJsonEngine($this->root, 2);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $rows[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $engine->writeRows('db', 't', $rows);

        $dir = $this->root . '/db';
        $before = $this->pageSnapshot($dir);
        $this->assertSame(['t.0.0.page.json', 't.1.0.page.json', 't.2.0.page.json'], array_keys($before));

        // 修改第 5 行（索引 4，属页 2）：仅页 2 出现新 gen
        $rows[4]['name'] = 'changed';
        $engine->writeRows('db', 't', $rows);

        $after = $this->pageSnapshot($dir);
        $this->assertSame(['t.0.0.page.json', 't.1.0.page.json', 't.2.1.page.json'], array_keys($after));
        // 未受影响页内容逐字节不变（未被重写）
        $this->assertSame($before['t.0.0.page.json'], $after['t.0.0.page.json']);
        $this->assertSame($before['t.1.0.page.json'], $after['t.1.0.page.json']);
        // 旧 gen 页文件已删除
        $this->assertFileDoesNotExist($dir . '/t.2.0.page.json');

        // 新实例读回数据一致
        $fresh = new PagedJsonEngine($this->root);
        $this->assertSame($rows, $fresh->readRows('db', 't'));
    }

    public function testUpdateFirstRowOnlyTouchesFirstPage(): void
    {
        $engine = new PagedJsonEngine($this->root, 2);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $rows[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $engine->writeRows('db', 't', $rows);

        $dir = $this->root . '/db';
        $before = $this->pageSnapshot($dir);

        // 修改第 1 行（索引 0，属页 0）：仅页 0 出现新 gen，后续页零重写
        // （suffix diff 在此场景会退化为全表重写；逐页独立 diff 必须与位置无关）
        $rows[0]['name'] = 'changed';
        $engine->writeRows('db', 't', $rows);

        $after = $this->pageSnapshot($dir);
        $this->assertSame(['t.0.1.page.json', 't.1.0.page.json', 't.2.0.page.json'], array_keys($after));
        $this->assertSame($before['t.1.0.page.json'], $after['t.1.0.page.json']);
        $this->assertSame($before['t.2.0.page.json'], $after['t.2.0.page.json']);
        $this->assertFileDoesNotExist($dir . '/t.0.0.page.json');

        // 修改中间行（索引 3，属页 1）：仅页 1 出现新 gen
        $rows[3]['name'] = 'mid-changed';
        $engine->writeRows('db', 't', $rows);
        $this->assertSame(
            ['t.0.1.page.json', 't.1.1.page.json', 't.2.0.page.json'],
            array_keys($this->pageSnapshot($dir))
        );

        $fresh = new PagedJsonEngine($this->root);
        $this->assertSame($rows, $fresh->readRows('db', 't'));
    }

    public function testSetAutoIncrementOnlyRewritesMeta(): void
    {
        $engine = new PagedJsonEngine($this->root, 2);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
            ['id' => 3, 'name' => 'c'],
        ];
        $engine->writeRows('db', 't', $rows);

        $dir = $this->root . '/db';
        $before = $this->pageSnapshot($dir);
        $metaBefore = (string) file_get_contents($dir . '/t.meta.json');

        $engine->setAutoIncrement('db', 't', 100);

        // 页文件零变化（清单与内容完全一致）
        $this->assertSame($before, $this->pageSnapshot($dir));
        // meta 已更新
        $this->assertNotSame($metaBefore, (string) file_get_contents($dir . '/t.meta.json'));

        $fresh = new PagedJsonEngine($this->root);
        $this->assertSame(100, $fresh->autoIncrement('db', 't'));
        $this->assertSame($rows, $fresh->readRows('db', 't'));
    }

    public function testAppendOnlyTouchesLastPageOrAddsNewPage(): void
    {
        $engine = new PagedJsonEngine($this->root, 2);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $rows = [
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
            ['id' => 3, 'name' => 'c'],
        ];
        $engine->writeRows('db', 't', $rows);
        $dir = $this->root . '/db';
        $this->assertSame(['t.0.0.page.json', 't.1.0.page.json'], array_keys($this->pageSnapshot($dir)));

        // 尾部 append 1 行：仍只有 2 页，仅最后一页 gen+1
        $page0Before = md5_file($dir . '/t.0.0.page.json');
        $rows[] = ['id' => 4, 'name' => 'd'];
        $engine->writeRows('db', 't', $rows);
        $this->assertSame(['t.0.0.page.json', 't.1.1.page.json'], array_keys($this->pageSnapshot($dir)));
        $this->assertSame($page0Before, md5_file($dir . '/t.0.0.page.json'));

        // 再 append 2 行：新增页 2，页 0 / 页 1（当前 gen）不动
        $page1Hash = md5_file($dir . '/t.1.1.page.json');
        $rows[] = ['id' => 5, 'name' => 'e'];
        $rows[] = ['id' => 6, 'name' => 'f'];
        $engine->writeRows('db', 't', $rows);
        $this->assertSame(
            ['t.0.0.page.json', 't.1.1.page.json', 't.2.0.page.json'],
            array_keys($this->pageSnapshot($dir))
        );
        $this->assertSame($page0Before, md5_file($dir . '/t.0.0.page.json'));
        $this->assertSame($page1Hash, md5_file($dir . '/t.1.1.page.json'));

        $this->assertSame($rows, (new PagedJsonEngine($this->root))->readRows('db', 't'));
    }

    public function testShrinkRemovesExcessPages(): void
    {
        $engine = new PagedJsonEngine($this->root, 2);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $rows[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $engine->writeRows('db', 't', $rows);

        $dir = $this->root . '/db';
        $page0Before = md5_file($dir . '/t.0.0.page.json');

        // 收缩为 3 行：页 0 不变，页 1 重写（gen+1），页 2 删除
        $engine->writeRows('db', 't', array_slice($rows, 0, 3));

        $this->assertSame(['t.0.0.page.json', 't.1.1.page.json'], array_keys($this->pageSnapshot($dir)));
        $this->assertSame($page0Before, md5_file($dir . '/t.0.0.page.json'));

        $meta = json_decode((string) file_get_contents($dir . '/t.meta.json'), true);
        $this->assertSame([0, 1], $meta['pages']);

        $this->assertSame(array_slice($rows, 0, 3), (new PagedJsonEngine($this->root))->readRows('db', 't'));
    }

    public function testOrphanPageCleanedOnLoad(): void
    {
        $engine = new PagedJsonEngine($this->root, 2);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
            ['id' => 3, 'name' => 'c'],
            ['id' => 4, 'name' => 'd'],
        ];
        $engine->writeRows('db', 't', $rows);

        // 手工放置错误 gen 的合法页文件（孤儿）
        $dir = $this->root . '/db';
        $orphan = $dir . '/t.0.99.page.json';
        file_put_contents($orphan, json_encode(['rows' => [['id' => 999, 'name' => 'hack']]]));
        // 页号越界的孤儿
        $orphanOutOfRange = $dir . '/t.5.0.page.json';
        file_put_contents($orphanOutOfRange, json_encode(['rows' => []]));
        $this->assertFileExists($orphan);

        // 新实例加载成功且数据一致，孤儿被静默清理
        $fresh = new PagedJsonEngine($this->root);
        $this->assertSame($rows, $fresh->readRows('db', 't'));
        $this->assertFileDoesNotExist($orphan);
        $this->assertFileDoesNotExist($orphanOutOfRange);
        $this->assertSame(
            ['t.0.0.page.json', 't.1.0.page.json'],
            array_keys($this->pageSnapshot($dir))
        );
    }

    public function testTmpResidueCleanedOnLoad(): void
    {
        $engine = new PagedJsonEngine($this->root, 2);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']];
        $engine->writeRows('db', 't', $rows);

        // 手工放置写盘中断残留的 .tmp.* 文件
        $dir = $this->root . '/db';
        $tmpMeta = $dir . '/t.meta.json.tmp.666';
        $tmpPage = $dir . '/t.0.0.page.json.tmp.666';
        file_put_contents($tmpMeta, '{incomplete');
        file_put_contents($tmpPage, '{"rows": [');
        $this->assertFileExists($tmpMeta);

        $fresh = new PagedJsonEngine($this->root);
        $this->assertSame($rows, $fresh->readRows('db', 't'));
        $this->assertFileDoesNotExist($tmpMeta);
        $this->assertFileDoesNotExist($tmpPage);
    }

    public function testMissingPageFileThrows(): void
    {
        $engine = new PagedJsonEngine($this->root, 2);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
            ['id' => 3, 'name' => 'c'],
            ['id' => 4, 'name' => 'd'],
        ];
        $engine->writeRows('db', 't', $rows);

        // 删除 meta 指向的页文件 = 表损坏
        $page = $this->root . '/db/t.1.0.page.json';
        unlink($page);

        $fresh = new PagedJsonEngine($this->root);
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage($page);
        $fresh->readRows('db', 't');
    }

    public function testCorruptedMetaThrows(): void
    {
        $engine = new PagedJsonEngine($this->root);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $meta = $this->root . '/db/t.meta.json';
        file_put_contents($meta, '{oops');

        $fresh = new PagedJsonEngine($this->root);
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage($meta);
        $fresh->readRows('db', 't');
    }

    public function testMetaMissingPagesKeyThrows(): void
    {
        $engine = new PagedJsonEngine($this->root);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $meta = $this->root . '/db/t.meta.json';
        file_put_contents($meta, json_encode([
            'schema' => $this->makeSchema('t')->toArray(),
            'auto_increment' => 0,
            'page_size' => 512,
        ]));

        $fresh = new PagedJsonEngine($this->root);
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage($meta);
        $fresh->readRows('db', 't');
    }

    public function testInvalidNamesThrow(): void
    {
        $engine = new PagedJsonEngine($this->root);

        foreach (['a/b', '', '1abc', 'a b', '..'] as $bad) {
            $this->assertThrows(fn () => $engine->createDatabase($bad), "非法库名未抛异常: {$bad}");
        }

        $engine->createDatabase('db');
        foreach (['a/b', '', '1abc'] as $bad) {
            $this->assertThrows(fn () => $engine->createTable('db', $this->makeSchema($bad)), "非法表名未抛异常: {$bad}");
        }

        $this->assertThrows(fn () => $engine->dropDatabase('a/b'), '非法库名 drop 未抛异常');
        $this->assertThrows(fn () => $engine->readRows('db', '../etc'), '路径穿越式表名未抛异常');
    }

    public function testInvalidPageSizeThrows(): void
    {
        foreach ([0, -1] as $bad) {
            $this->assertThrows(fn () => new PagedJsonEngine($this->root, $bad), "非法页大小未抛异常: {$bad}");
        }
    }

    public function testPageSizeOneBoundary(): void
    {
        $engine = new PagedJsonEngine($this->root, 1);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $engine->writeRows('db', 't', $rows);

        $dir = $this->root . '/db';
        $this->assertSame(
            ['t.0.0.page.json', 't.1.0.page.json', 't.2.0.page.json', 't.3.0.page.json', 't.4.0.page.json'],
            array_keys($this->pageSnapshot($dir))
        );

        // 改中间一行（索引 2）：行数不变走逐页独立 diff，仅页 2 重写；页 3/4 内容不变
        $before = $this->pageSnapshot($dir);
        $rows[2]['name'] = 'changed';
        $engine->writeRows('db', 't', $rows);
        $after = $this->pageSnapshot($dir);

        $this->assertSame(
            ['t.0.0.page.json', 't.1.0.page.json', 't.2.1.page.json', 't.3.0.page.json', 't.4.0.page.json'],
            array_keys($after)
        );
        $this->assertSame($before['t.0.0.page.json'], $after['t.0.0.page.json']);
        $this->assertSame($before['t.1.0.page.json'], $after['t.1.0.page.json']);
        $this->assertSame($before['t.3.0.page.json'], $after['t.3.0.page.json']);
        $this->assertSame($before['t.4.0.page.json'], $after['t.4.0.page.json']);

        $this->assertSame($rows, (new PagedJsonEngine($this->root))->readRows('db', 't'));
    }

    public function testRenameTableMovesAllPageFiles(): void
    {
        $engine = new PagedJsonEngine($this->root, 2);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('old'));
        $rows = [
            ['id' => 1, 'name' => 'a'],
            ['id' => 2, 'name' => 'b'],
            ['id' => 3, 'name' => 'c'],
        ];
        $engine->writeRows('db', 'old', $rows);
        $engine->setAutoIncrement('db', 'old', 3);

        $dir = $this->root . '/db';
        $engine->renameTable('db', 'old', 'new');

        // meta 与页文件全部迁移，gen 保持，旧文件清理
        $this->assertFileExists($dir . '/new.meta.json');
        $this->assertFileExists($dir . '/new.0.0.page.json');
        $this->assertFileExists($dir . '/new.1.0.page.json');
        $this->assertFileDoesNotExist($dir . '/old.meta.json');
        $this->assertFileDoesNotExist($dir . '/old.0.0.page.json');

        $fresh = new PagedJsonEngine($this->root);
        $this->assertFalse($fresh->hasTable('db', 'old'));
        $this->assertSame('new', $fresh->loadSchema('db', 'new')->name);
        $this->assertSame($rows, $fresh->readRows('db', 'new'));
        $this->assertSame(3, $fresh->autoIncrement('db', 'new'));
    }

    public function testDeleteRowsTombstoneOnlyRewritesAffectedPage(): void
    {
        $engine = new PagedJsonEngine($this->root, 2);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $rows[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $engine->writeRows('db', 't', $rows);

        $dir = $this->root . '/db';
        $before = $this->pageSnapshot($dir);
        $this->assertSame(['t.0.0.page.json', 't.1.0.page.json', 't.2.0.page.json'], array_keys($before));

        // 删除中间行（稠密索引 2 → 槽位 2 → 页 1）：仅页 1 gen+1，其余页逐字节不变
        $engine->deleteRows('db', 't', [2]);

        $after = $this->pageSnapshot($dir);
        $this->assertSame(['t.0.0.page.json', 't.1.1.page.json', 't.2.0.page.json'], array_keys($after));
        $this->assertSame($before['t.0.0.page.json'], $after['t.0.0.page.json']);
        $this->assertSame($before['t.2.0.page.json'], $after['t.2.0.page.json']);
        $this->assertFileDoesNotExist($dir . '/t.1.0.page.json');

        // 页 1 内容：槽位 0 为墓碑 null，槽位 1 保留原行
        $page = json_decode((string) file_get_contents($dir . '/t.1.1.page.json'), true);
        $this->assertSame([null, ['id' => 4, 'name' => 'n4']], $page['rows']);

        // meta 记录 dead=1
        $meta = json_decode((string) file_get_contents($dir . '/t.meta.json'), true);
        $this->assertSame(1, $meta['dead']);

        // 对外稠密视图：剩余 5 行
        $expected = [$rows[0], $rows[1], $rows[3], $rows[4], $rows[5]];
        $this->assertSame($expected, $engine->readRows('db', 't'));

        // 新实例重开：数据一致（页内 null 反序列化为墓碑）
        $this->assertSame($expected, (new PagedJsonEngine($this->root))->readRows('db', 't'));
    }

    public function testDeleteRowsAcrossPagesRewritesPagesIndependently(): void
    {
        $engine = new PagedJsonEngine($this->root, 2);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $rows[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $engine->writeRows('db', 't', $rows);

        $dir = $this->root . '/db';

        // 删除稠密 0（页 0）与 4（页 2）：页 1 完全不动
        $engine->deleteRows('db', 't', [0, 4]);
        $afterFirst = $this->pageSnapshot($dir);
        $this->assertSame(['t.0.1.page.json', 't.1.0.page.json', 't.2.1.page.json'], array_keys($afterFirst));

        // 再删当前稠密索引 2（原行 id=4，槽位 3 → 页 1）：仅页 1 重写
        $page0Hash = $afterFirst['t.0.1.page.json'];
        $page2Hash = $afterFirst['t.2.1.page.json'];
        $engine->deleteRows('db', 't', [2]);

        $afterSecond = $this->pageSnapshot($dir);
        $this->assertSame(['t.0.1.page.json', 't.1.1.page.json', 't.2.1.page.json'], array_keys($afterSecond));
        $this->assertSame($page0Hash, $afterSecond['t.0.1.page.json'], '未涉及页不得重写');
        $this->assertSame($page2Hash, $afterSecond['t.2.1.page.json'], '未涉及页不得重写');

        // 页槽位总数不变：3 页 × 2 槽 = 6，dead=3
        $meta = json_decode((string) file_get_contents($dir . '/t.meta.json'), true);
        $this->assertSame(3, $meta['dead']);
        $this->assertSame(3, count($meta['pages']));

        $expected = [$rows[1], $rows[2], $rows[5]];
        $this->assertSame($expected, $engine->readRows('db', 't'));
        $this->assertSame($expected, (new PagedJsonEngine($this->root))->readRows('db', 't'));
    }

    public function testCompactionTriggeredAtThreshold(): void
    {
        $engine = new PagedJsonEngine($this->root, 1);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $rows = [];
        for ($i = 1; $i <= 250; $i++) {
            $rows[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $engine->writeRows('db', 't', $rows);

        $dir = $this->root . '/db';

        // 删 99 行：dead=99 < 100（绝对数阈值不满足）→ 不压实，页文件数不变
        $engine->deleteRows('db', 't', range(0, 98));
        $this->assertCount(250, $this->pageSnapshot($dir), '未达阈值不得压实');
        $meta = json_decode((string) file_get_contents($dir . '/t.meta.json'), true);
        $this->assertSame(99, $meta['dead']);
        $this->assertCount(151, $engine->readRows('db', 't'));

        // 再删 1 行：dead=100 ≥ 100 且 ≥ ceil(250*0.4)=100 → 触发压实
        $engine->deleteRows('db', 't', [0]);

        $pages = $this->pageSnapshot($dir);
        $this->assertCount(150, $pages, '压实后页文件数应回到剩余行数（pageSize=1）');

        $meta = json_decode((string) file_get_contents($dir . '/t.meta.json'), true);
        $this->assertSame(0, $meta['dead'], '压实后 dead 归零');
        $this->assertCount(150, $meta['pages']);

        // 数据一致：剩余 id 101..250
        $expected = [];
        for ($i = 101; $i <= 250; $i++) {
            $expected[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $this->assertSame($expected, $engine->readRows('db', 't'));
        $this->assertSame($expected, (new PagedJsonEngine($this->root))->readRows('db', 't'));
    }

    public function testWriteRowsAfterTombstonesResetsDeadAndRebuilds(): void
    {
        $engine = new PagedJsonEngine($this->root, 2);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $rows[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $engine->writeRows('db', 't', $rows);

        $dir = $this->root . '/db';
        $engine->deleteRows('db', 't', [1]);
        $this->assertSame(
            ['t.0.1.page.json', 't.1.0.page.json', 't.2.0.page.json'],
            array_keys($this->pageSnapshot($dir))
        );

        // 墓碑态下 writeRows（修改首行 + 尾部 append）：全量重排，dead 清零
        // （5 稠密行 + 1 追加 = 6 行 → 3 页；重排前 pages=[1,0,0]，全部页 gen+1 → [2,1,1]）
        $rows = array_values(array_filter($rows, static fn (int $k): bool => $k !== 1, ARRAY_FILTER_USE_KEY));
        $rows[0]['name'] = 'changed';
        $rows[] = ['id' => 7, 'name' => 'n7'];
        $engine->writeRows('db', 't', $rows);

        $meta = json_decode((string) file_get_contents($dir . '/t.meta.json'), true);
        $this->assertSame(0, $meta['dead'], 'writeRows 后 dead 恒为 0');
        $this->assertSame([2, 1, 1], $meta['pages'], '全部既有页 gen+1');
        $this->assertSame(
            ['t.0.2.page.json', 't.1.1.page.json', 't.2.1.page.json'],
            array_keys($this->pageSnapshot($dir))
        );

        $this->assertSame($rows, $engine->readRows('db', 't'));
        $this->assertSame($rows, (new PagedJsonEngine($this->root))->readRows('db', 't'));
    }

    public function testWriteRowsNoDiffKeepsIncrementalWhenNoTombstone(): void
    {
        $engine = new PagedJsonEngine($this->root, 2);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $rows = [];
        for ($i = 1; $i <= 4; $i++) {
            $rows[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $engine->writeRows('db', 't', $rows);

        $dir = $this->root . '/db';
        $before = $this->pageSnapshot($dir);

        // 无墓碑时的原地更新仍走逐页 diff：仅页 0 gen+1
        $rows[1]['name'] = 'changed';
        $engine->writeRows('db', 't', $rows);

        $this->assertSame(
            ['t.0.1.page.json', 't.1.0.page.json'],
            array_keys($this->pageSnapshot($dir))
        );
        $this->assertSame($before['t.1.0.page.json'], md5_file($dir . '/t.1.0.page.json'));
    }

    public function testSnapshotRestoreWithTombstoneState(): void
    {
        $engine = new PagedJsonEngine($this->root, 2);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));

        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $rows[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $engine->writeRows('db', 't', $rows);

        // 制造墓碑态：dead=1
        $engine->deleteRows('db', 't', [2]);
        $snapshot = $engine->snapshot();

        // 快照后继续变更：再删 + 全量替换
        $engine->deleteRows('db', 't', [0]);
        $engine->writeRows('db', 't', [['id' => 99, 'name' => 'x']]);

        // 回滚到墓碑态快照
        $engine->restore($snapshot);
        $expected = [$rows[0], $rows[1], $rows[3], $rows[4], $rows[5]];
        $this->assertSame($expected, $engine->readRows('db', 't'));

        // restore 落盘后 meta 与页文件保留墓碑
        $meta = json_decode((string) file_get_contents($this->root . '/db/t.meta.json'), true);
        $this->assertSame(1, $meta['dead']);

        // 新实例重开：墓碑态数据一致，且可继续删除（稠密序号基于过滤后视图）
        $fresh = new PagedJsonEngine($this->root);
        $this->assertSame($expected, $fresh->readRows('db', 't'));
        $fresh->deleteRows('db', 't', [0]);
        $this->assertSame([$rows[1], $rows[3], $rows[4], $rows[5]], $fresh->readRows('db', 't'));
    }

    /**
     * 页文件清单快照：文件名 => 内容 md5（键按 scandir 顺序，调用方自行断言）
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
