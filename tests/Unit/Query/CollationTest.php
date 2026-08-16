<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\ConstraintException;
use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Agg;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Query\Func;
use Kingbes\Psql\Schema\Blueprint;
use Kingbes\Psql\Schema\ColumnSchema;
use Kingbes\Psql\Schema\DataType;
use PHPUnit\Framework\TestCase;

/**
 * 列级 collation（ci）测试：DSL 校验、持久化往返、查询比较/排序折叠、
 * 索引预过滤与 hash join 跳过（结果与扫描路径一致）、约束保持区分大小写
 */
final class CollationTest extends TestCase
{
    private Connection $conn;

    /** 持久化往返用临时目录 */
    private string $root;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull()->ci();
            $b->varchar('code', 32)->notNull();
        });
        $this->conn->table('users')->insertMany([
            ['name' => 'Alice', 'code' => 'A1'],
            ['name' => 'BOB', 'code' => 'B2'],
            ['name' => 'alice', 'code' => 'A2'],
            ['name' => 'Carol', 'code' => 'C3'],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $this->removeDirRecursive($this->root);
        }
    }

    // ---- DSL 与序列化 ----

    public function testCiFlagExposedInSchema(): void
    {
        $columns = $this->conn->engine()->loadSchema('main', 'users')->columns;
        $this->assertTrue($columns[1]->ci);   // name 为 CI
        $this->assertFalse($columns[2]->ci);  // code 默认 CS
    }

    public function testCiRejectedOnNonStringType(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('列 age 类型 INT 不支持 ci');
        $this->conn->createTable('bad', static function (Blueprint $b): void {
            $b->id();
            $b->int('age')->ci();
        });
    }

    public function testCiAllowedOnEnumAndText(): void
    {
        // ENUM / CHAR / TEXT 均属字符串族，允许 ci
        $this->conn->createTable('kinds', static function (Blueprint $b): void {
            $b->id();
            $b->enum('gender', ['male', 'female'])->ci();
            $b->char('tag', 8)->ci();
            $b->text('note')->ci();
        });
        $columns = $this->conn->engine()->loadSchema('main', 'kinds')->columns;
        $this->assertTrue($columns[1]->ci);
        $this->assertTrue($columns[2]->ci);
        $this->assertTrue($columns[3]->ci);
    }

    public function testColumnSchemaArrayRoundTrip(): void
    {
        $ciColumn = new ColumnSchema('name', DataType::VARCHAR, length: 32, ci: true);
        $this->assertSame(true, $ciColumn->toArray()['ci']);
        $this->assertTrue(ColumnSchema::fromArray($ciColumn->toArray())->ci);

        // 旧数据缺 ci 键 → false；显式 false → false
        $legacy = ['name' => 'name', 'type' => 'VARCHAR', 'length' => 32];
        $this->assertFalse(ColumnSchema::fromArray($legacy)->ci);
        $this->assertFalse(ColumnSchema::fromArray(['name' => 'name', 'type' => 'VARCHAR', 'ci' => false])->ci);
    }

    public function testCiFlagSurvivesPersistenceReopen(): void
    {
        $this->root = sys_get_temp_dir() . '/psql-ci-' . uniqid('', true);
        $conn = Psql::connect($this->root);
        $conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull()->ci();
        });
        $conn->table('users')->insert(['name' => 'Alice']);
        unset($conn);

        $reopened = Psql::connect($this->root);
        $this->assertTrue($reopened->engine()->loadSchema('main', 'users')->columns[1]->ci);
        // 重开后行为级验证：小写查询命中大写存储行
        $this->assertSame([['id' => 1, 'name' => 'Alice']], $reopened->table('users')->where('name', 'alice')->get()->rows());
    }

    // ---- WHERE 比较折叠 ----

    public function testWhereEqualityCiMatchesAcrossCase(): void
    {
        // CI 列：'alice' 命中 Alice/alice（保持插入序）
        $rows = $this->conn->table('users')->where('name', '=', 'alice')->get()->rows();
        $this->assertSame(
            [['id' => 1, 'name' => 'Alice', 'code' => 'A1'], ['id' => 3, 'name' => 'alice', 'code' => 'A2']],
            $rows,
        );
    }

    public function testWhereEqualityCsByDefault(): void
    {
        // CS 列（默认）：大小写不混
        $this->assertSame([], $this->conn->table('users')->where('code', '=', 'a1')->get()->rows());
        $this->assertSame([['id' => 1, 'name' => 'Alice', 'code' => 'A1']], $this->conn->table('users')->where('code', '=', 'A1')->get()->rows());
    }

    public function testWhereNotEqualCi(): void
    {
        $rows = $this->conn->table('users')->where('name', '!=', 'ALICE')->orderBy('id')->get()->rows();
        $this->assertSame([2, 4], array_column($rows, 'id'));
    }

    public function testWhereInAndNotInCi(): void
    {
        $in = $this->conn->table('users')->whereIn('name', ['alice', 'CAROL'])->orderBy('id')->get()->rows();
        $this->assertSame([1, 3, 4], array_column($in, 'id'));

        $notIn = $this->conn->table('users')->whereNotIn('name', ['alice', 'CAROL'])->orderBy('id')->get()->rows();
        $this->assertSame([2], array_column($notIn, 'id'));
    }

    public function testWhereBetweenCiFoldsStringRange(): void
    {
        // 字符串范围折叠后比较：'BOB' <= x <= 'CAROL' 折叠为 'bob' <= x <= 'carol'
        $rows = $this->conn->table('users')->whereBetween('name', 'BOB', 'CAROL')->orderBy('id')->get()->rows();
        $this->assertSame([2, 4], array_column($rows, 'id')); // BOB/Carol 命中（大小写不同边界仍命中）
    }

    public function testWhereLikeCi(): void
    {
        // '%li%' 折叠后命中 'Alice'/'alice'
        $rows = $this->conn->table('users')->whereLike('name', '%LI%')->orderBy('id')->get()->rows();
        $this->assertSame([1, 3], array_column($rows, 'id'));

        // CS 列 LIKE 保持大小写敏感
        $this->assertSame([], $this->conn->table('users')->whereLike('code', '%a%')->get()->rows());
    }

    public function testOrWhereCiCombination(): void
    {
        $rows = $this->conn->table('users')
            ->where('name', '=', 'alice')
            ->orWhere('name', '=', 'BOB')
            ->orderBy('id')
            ->get()->rows();
        $this->assertSame([1, 2, 3], array_column($rows, 'id'));
    }

    public function testNestedConditionGroupCiTransparentlyPassed(): void
    {
        $group = new ConditionGroup();
        $group->where('name', '=', 'ALICE')->orWhere('name', '=', 'cArOl');

        $rows = $this->conn->table('users')->select('id')->whereGroup($group)->orderBy('id')->get()->rows();
        $this->assertSame([1, 3, 4], array_column($rows, 'id'));
    }

    // ---- ORDER BY ----

    public function testOrderByCiFoldsStringComparison(): void
    {
        $this->conn->createTable('fruits', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull()->ci();
        });
        $this->conn->table('fruits')->insertMany([
            ['name' => 'delta'], ['name' => 'Charlie'], ['name' => 'echo'], ['name' => 'bravo'],
        ]);

        // CI：按折叠后字典序 bravo < charlie < delta < echo
        $rows = $this->conn->table('fruits')->orderBy('name')->get()->rows();
        $this->assertSame(['bravo', 'Charlie', 'delta', 'echo'], array_column($rows, 'name'));
    }

    public function testOrderByCsKeepsByteOrder(): void
    {
        $this->conn->createTable('fruits_cs', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull();
        });
        $this->conn->table('fruits_cs')->insertMany([
            ['name' => 'delta'], ['name' => 'Charlie'], ['name' => 'echo'], ['name' => 'bravo'],
        ]);

        // CS：原始字节序（大写 67 < 小写 98..101）
        $rows = $this->conn->table('fruits_cs')->orderBy('name')->get()->rows();
        $this->assertSame(['Charlie', 'bravo', 'delta', 'echo'], array_column($rows, 'name'));
    }

    // ---- JOIN ----

    public function testJoinOnCiMatchesAcrossCase(): void
    {
        $this->conn->createTable('profiles', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('username', 32)->notNull()->ci();
        });
        $this->conn->table('profiles')->insertMany([
            ['id' => 1, 'username' => 'ALICE'],
            ['id' => 2, 'username' => 'bob'],
        ]);

        // 左 'Alice'/'alice'（users）与右 'ALICE'（profiles）、'BOB' 与 'bob' 均 CI 等值命中
        $rows = $this->conn->table('users as u')
            ->select('u.id', 'u.name', 'profiles.username')
            ->join('profiles', 'u.name', '=', 'profiles.username')
            ->orderBy('u.id')
            ->get()->rows();
        $this->assertSame(
            [
                ['id' => 1, 'name' => 'Alice', 'username' => 'ALICE'],
                ['id' => 2, 'name' => 'BOB', 'username' => 'bob'],
                ['id' => 3, 'name' => 'alice', 'username' => 'ALICE'],
            ],
            $rows,
        );
    }

    public function testJoinOnCsDoesNotMatchAcrossCase(): void
    {
        $this->conn->createTable('codes', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('code', 32)->notNull();
        });
        $this->conn->table('codes')->insertMany([['code' => 'a1']]);

        // CS join：'A1'（users.code）与 'a1'（codes.code）不命中
        $rows = $this->conn->table('users as u')
            ->select('u.id')
            ->join('codes', 'u.code', '=', 'codes.code')
            ->get()->rows();
        $this->assertSame([], $rows);
    }

    // ---- 索引预过滤跳过 ----

    public function testIndexPrefilterSkippedOnCiColumn(): void
    {
        $this->conn->createTable('mixed', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 64)->notNull()->ci();
        });
        $rows = [];
        for ($i = 1; $i <= 200; $i++) {
            $rows[] = ['name' => ($i % 2 === 0 ? 'USER' : 'User') . $i];
        }
        $this->conn->table('mixed')->insertMany($rows);

        $this->conn->createIndex('mixed', 'idx_name', 'name');

        // 小写探针值命中大写存储行：索引若未跳过，CS 哈希会静默漏行
        $withIndexOdd = $this->conn->table('mixed')->where('name', '=', 'user3')->get()->rows();
        $withIndexEven = $this->conn->table('mixed')->where('name', '=', 'user100')->get()->rows();

        $this->conn->dropIndex('mixed', 'idx_name');
        $scanOdd = $this->conn->table('mixed')->where('name', '=', 'user3')->get()->rows();
        $scanEven = $this->conn->table('mixed')->where('name', '=', 'user100')->get()->rows();

        // 与扫描路径逐字节一致（含顺序）—— v1.2 键序 bug 同类防线
        $this->assertSame($scanOdd, $withIndexOdd);
        $this->assertSame($scanEven, $withIndexEven);
        $this->assertSame([['id' => 3, 'name' => 'User3']], $withIndexOdd);
        $this->assertSame([['id' => 100, 'name' => 'USER100']], $withIndexEven);
    }

    // ---- hash join 跳过 ----

    public function testHashJoinSkippedOnCiColumn(): void
    {
        // CI 两表各 50 行混大小写：'User%d' join 'user%d'
        $this->conn->createTable('ci_l', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('k', 32)->notNull()->ci();
        });
        $this->conn->createTable('ci_r', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('k', 32)->notNull()->ci();
        });
        $lRows = [];
        $rRows = [];
        for ($i = 1; $i <= 50; $i++) {
            $lRows[] = ['k' => 'User' . $i];
            $rRows[] = ['k' => 'user' . $i];
        }
        $this->conn->table('ci_l')->insertMany($lRows);
        $this->conn->table('ci_r')->insertMany($rRows);

        // 影子对照：同为小写值的 CS 两表 join（必然 50 行全命中）
        $this->conn->createTable('cs_l', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('k', 32)->notNull();
        });
        $this->conn->createTable('cs_r', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('k', 32)->notNull();
        });
        $this->conn->table('cs_l')->insertMany(array_map(
            static fn (int $i): array => ['k' => 'user' . $i],
            range(1, 50),
        ));
        $this->conn->table('cs_r')->insertMany(array_map(
            static fn (int $i): array => ['k' => 'user' . $i],
            range(1, 50),
        ));

        $ciJoin = $this->conn->table('ci_l')
            ->select('ci_l.k', 'ci_r.id')
            ->join('ci_r', 'ci_l.k', '=', 'ci_r.k')
            ->get()->rows();
        $csJoin = $this->conn->table('cs_l')
            ->select('cs_l.k', 'cs_r.id')
            ->join('cs_r', 'cs_l.k', '=', 'cs_r.k')
            ->get()->rows();

        // CI 回退嵌套循环后结果与等价 CS join 逐字节一致（左序，每左行恰一匹配）
        $expected = array_map(
            static fn (int $i): array => ['k' => 'user' . $i, 'id' => $i],
            range(1, 50),
        );
        $this->assertSame($expected, $csJoin);
        $this->assertSame(
            array_map(static fn (array $row): array => ['k' => ucfirst($row['k']), 'id' => $row['id']], $expected),
            $ciJoin,
        );
    }

    // ---- 写路径 where 的 CI 语义（与 SELECT 一致） ----

    public function testUpdateWhereFoldsCaseOnCiColumn(): void
    {
        $this->conn->createTable('ci_upd', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('code', 32)->notNull()->ci();
            $b->int('hits')->notNull()->default(0);
        });
        $this->conn->table('ci_upd')->insertMany([
            ['code' => 'Alpha', 'hits' => 1],
            ['code' => 'BETA', 'hits' => 2],
        ]);

        // 小写条件命中大写数据行
        $affected = $this->conn->table('ci_upd')->where('code', '=', 'beta')->update(['hits' => 99]);
        $this->assertSame(1, $affected);
        $this->assertSame(99, $this->conn->table('ci_upd')->where('code', '=', 'BETA')->first()['hits']);
    }

    public function testDeleteWhereFoldsCaseOnCiColumn(): void
    {
        $this->conn->createTable('ci_del', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('code', 32)->notNull()->ci();
        });
        $this->conn->table('ci_del')->insertMany([
            ['code' => 'Alpha'],
            ['code' => 'BETA'],
        ]);

        $affected = $this->conn->table('ci_del')->where('code', '=', 'alpha')->delete();
        $this->assertSame(1, $affected);
        $this->assertSame([['id' => 2, 'code' => 'BETA']], $this->conn->table('ci_del')->orderBy('id')->get()->rows());
    }

    // ---- 约束保持区分大小写 ----

    public function testUniqueConstraintStaysCaseSensitiveOnCiColumn(): void
    {
        $this->conn->createTable('tags', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('label', 32)->notNull()->unique()->ci();
        });
        $this->conn->table('tags')->insert(['label' => 'a']);
        // 'a' 与 'A' 字节不同：CI 列的 unique 约束不折叠，两行合法共存
        $this->conn->table('tags')->insert(['label' => 'A']);

        $rows = $this->conn->table('tags')->orderBy('id')->get()->rows();
        $this->assertSame([['id' => 1, 'label' => 'a'], ['id' => 2, 'label' => 'A']], $rows);
    }

    public function testForeignKeyStaysCaseSensitiveOnCiColumn(): void
    {
        $this->conn->createTable('parents', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('key', 32)->notNull()->unique();
        });
        $this->conn->table('parents')->insert(['key' => 'Root']);

        $this->conn->createTable('children', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('ref', 32)->notNull()->ci();
            $b->foreignKey('ref')->references('parents', 'key');
        });

        // 约束不折叠：引用值大小写不同仍找不到等值行 → ConstraintException
        $this->expectException(ConstraintException::class);
        $this->conn->table('children')->insert(['ref' => 'root']);
    }

    // ---- 与表达式/聚合共存 ----

    public function testCiColumnWithExpressionAndAggregate(): void
    {
        $rows = $this->conn->table('users')
            ->select(Func::upper(Func::col('name'))->as('uname'))
            ->where('name', '=', 'alice')
            ->orderBy('id')
            ->get()->rows();
        $this->assertSame([['uname' => 'ALICE'], ['uname' => 'ALICE']], $rows);

        $count = $this->conn->table('users')
            ->select(Agg::count('id')->as('cnt'))
            ->where('name', '=', 'alice')
            ->get()->rows();
        $this->assertSame([['cnt' => 2]], $count);
    }

    /**
     * 递归删除临时目录
     */
    private function removeDirRecursive(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
