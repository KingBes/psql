<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Agg;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * hash join 测试：等值连接与嵌套循环语义/顺序一致、null 键、数值性键合并、多表与聚合组合
 */
final class HashJoinTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull();
            $b->int('dept_id');
        });
        $this->conn->createTable('depts', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('title', 32)->notNull();
        });
        $this->conn->createTable('roles', static function (Blueprint $b): void {
            $b->id();
            $b->int('dept_id')->notNull();
            $b->varchar('role', 32)->notNull();
        });

        $this->conn->table('users')->insertMany([
            ['name' => 'alice', 'dept_id' => 1],
            ['name' => 'bob', 'dept_id' => 1],
            ['name' => 'carol', 'dept_id' => null],
            ['name' => 'dave', 'dept_id' => 2],
        ]);
        $this->conn->table('depts')->insertMany([
            ['title' => 'eng'],
            ['title' => 'sales'],
            ['title' => 'hr'],
        ]);
    }

    public function testInnerJoinHashMatchesNestedLoopSemantics(): void
    {
        $rows = $this->conn->table('users as u')
            ->select('u.id', 'u.name', 'depts.title')
            ->join('depts', 'u.dept_id', '=', 'depts.id')
            ->orderBy('u.id')
            ->get()
            ->rows();

        $this->assertSame(
            [
                ['id' => 1, 'name' => 'alice', 'title' => 'eng'],
                ['id' => 2, 'name' => 'bob', 'title' => 'eng'],
                ['id' => 4, 'name' => 'dave', 'title' => 'sales'],
            ],
            $rows,
        );
    }

    public function testLeftJoinHashKeepsUnmatchedWithNulls(): void
    {
        $rows = $this->conn->table('users as u')
            ->select('u.name', 'depts.title')
            ->leftJoin('depts', 'u.dept_id', '=', 'depts.id')
            ->orderBy('u.id')
            ->get()
            ->rows();

        $this->assertCount(4, $rows);
        // carol 无匹配 → 右侧列 null；null 键永不匹配
        $this->assertSame(['name' => 'carol', 'title' => null], $rows[2]);
    }

    public function testRightJoinHashKeepsAllRightRows(): void
    {
        $rows = $this->conn->table('users as u')
            ->select('depts.title', 'u.name')
            ->rightJoin('depts', 'u.dept_id', '=', 'depts.id')
            ->orderBy('depts.id')
            ->get()
            ->rows();

        // 右表 3 行全保留：eng 两匹配（桶内左行原序）、sales 一匹配、hr 零匹配（左侧补 null）
        $this->assertCount(4, $rows);
        $this->assertSame(['title' => 'eng', 'name' => 'alice'], $rows[0]);
        $this->assertSame(['title' => 'eng', 'name' => 'bob'], $rows[1]);
        $this->assertSame(['title' => 'sales', 'name' => 'dave'], $rows[2]);
        $this->assertSame(['title' => 'hr', 'name' => null], $rows[3]);
    }

    public function testJoinOutputOrderEqualsNestedLoopOrder(): void
    {
        // 不带排序：输出顺序 = 左行序 × 桶内右行原序（与嵌套循环逐字节一致）
        $rows = $this->conn->table('users as u')
            ->select('u.id', 'depts.title')
            ->join('depts', 'u.dept_id', '=', 'depts.id')
            ->get()
            ->rows();

        $this->assertSame(
            [
                ['id' => 1, 'title' => 'eng'],
                ['id' => 2, 'title' => 'eng'],
                ['id' => 4, 'title' => 'sales'],
            ],
            $rows,
        );
    }

    public function testNullJoinKeyOnRightSideNeverMatches(): void
    {
        // 右表含 null 键行：inner 不出现、left 不因此补 null
        $this->conn->createTable('tags', static function (Blueprint $b): void {
            $b->id();
            $b->int('user_id');
        });
        $this->conn->table('tags')->insertMany([
            ['user_id' => null],
            ['user_id' => 1],
        ]);

        $inner = $this->conn->table('users as u')
            ->select('u.name')
            ->join('tags', 'u.id', '=', 'tags.user_id')
            ->get()
            ->rows();
        $this->assertSame([['name' => 'alice']], $inner);

        // users.id=2/4 侧无匹配（tags 只有 user_id=1 与 null），LEFT 才补 null
        $left = $this->conn->table('users as u')
            ->select('u.name', 'tags.user_id')
            ->leftJoin('tags', 'u.id', '=', 'tags.user_id')
            ->get()
            ->rows();
        $this->assertCount(4, $left);
        $this->assertSame(['name' => 'carol', 'user_id' => null], $left[2]);
    }

    public function testNumericKeyMergesStringAndInt(): void
    {
        // 左 '5'（字符串）join 右 5（int）命中（compareValues 数值性语义）
        $this->conn->createTable('left_t', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('k', 16)->notNull();
        });
        $this->conn->createTable('right_t', static function (Blueprint $b): void {
            $b->id();
            $b->int('k')->notNull();
        });
        $this->conn->table('left_t')->insertMany([
            ['k' => '5'], ['k' => '6'], ['k' => 'x'],
        ]);
        $this->conn->table('right_t')->insertMany([
            ['k' => 5], ['k' => 7],
        ]);

        $rows = $this->conn->table('left_t as l')
            ->select('l.k', 'right_t.id')
            ->join('right_t', 'l.k', '=', 'right_t.k')
            ->get()
            ->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('5', $rows[0]['k']);
    }

    public function testThreeTableChainedJoin(): void
    {
        $this->conn->table('roles')->insertMany([
            ['dept_id' => 1, 'role' => 'dev'],
            ['dept_id' => 1, 'role' => 'qa'],
            ['dept_id' => 3, 'role' => 'recruiter'],
        ]);

        $rows = $this->conn->table('users as u')
            ->select('u.name', 'depts.title', 'roles.role')
            ->join('depts', 'u.dept_id', '=', 'depts.id')
            ->leftJoin('roles', 'depts.id', '=', 'roles.dept_id')
            ->orderBy('u.id')
            ->orderBy('roles.role')
            ->get()
            ->rows();

        $this->assertSame(
            [
                ['name' => 'alice', 'title' => 'eng', 'role' => 'dev'],
                ['name' => 'alice', 'title' => 'eng', 'role' => 'qa'],
                ['name' => 'bob', 'title' => 'eng', 'role' => 'dev'],
                ['name' => 'bob', 'title' => 'eng', 'role' => 'qa'],
                ['name' => 'dave', 'title' => 'sales', 'role' => null],
            ],
            $rows,
        );
    }

    public function testJoinThenWhereAndAggregate(): void
    {
        $rows = $this->conn->table('users as u')
            ->select('depts.title', Agg::count('u.id')->as('cnt'))
            ->join('depts', 'u.dept_id', '=', 'depts.id')
            ->where('u.id', '>', 1)
            ->groupBy('depts.title')
            ->orderBy('depts.title')
            ->get()
            ->rows();

        $this->assertSame(
            [
                ['title' => 'eng', 'cnt' => 1],
                ['title' => 'sales', 'cnt' => 1],
            ],
            $rows,
        );
    }

    public function testManyToManyBucketOrder(): void
    {
        // 1:N:N 桶：输出顺序 = 左序 × 桶内右序
        $this->conn->createTable('a', static function (Blueprint $b): void {
            $b->id();
            $b->int('k')->notNull();
        });
        $this->conn->createTable('b', static function (Blueprint $b): void {
            $b->id();
            $b->int('k')->notNull();
        });
        $this->conn->table('a')->insertMany([
            ['k' => 1], ['k' => 2], ['k' => 1],
        ]);
        $this->conn->table('b')->insertMany([
            ['k' => 1], ['k' => 2], ['k' => 1], ['k' => 3],
        ]);

        $rows = $this->conn->table('a')
            ->select('a.k', 'b.id')
            ->join('b', 'a.k', '=', 'b.k')
            ->get()
            ->rows();

        // 左行序 a1(k1) a2(k2) a3(k1)；桶 k1 内右序 b1 b3、桶 k2 内 b2
        $this->assertSame(
            [
                ['k' => 1, 'id' => 1],
                ['k' => 1, 'id' => 3],
                ['k' => 2, 'id' => 2],
                ['k' => 1, 'id' => 1],
                ['k' => 1, 'id' => 3],
            ],
            $rows,
        );
    }

    public function testNonEqualityOperatorFallsBackToNestedLoop(): void
    {
        // 非 '=' 运算符回退嵌套循环，行为与既有语义一致
        $rows = $this->conn->table('users as u')
            ->select('u.name')
            ->join('depts', 'u.dept_id', '<', 'depts.id')
            ->orderBy('u.id')
            ->orderBy('depts.id')
            ->get()
            ->rows();

        // alice/bob dept_id=1：depts.id ∈ {2,3}；dave dept_id=2：depts.id ∈ {3}；carol null 恒不匹配
        $this->assertSame(
            [
                ['name' => 'alice'],
                ['name' => 'alice'],
                ['name' => 'bob'],
                ['name' => 'bob'],
                ['name' => 'dave'],
            ],
            $rows,
        );
    }
}
