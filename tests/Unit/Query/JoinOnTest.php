<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * JOIN 增强测试：ON 任意条件组（joinOn/leftJoinOn/rightJoinOn）与 USING(column) 展开
 */
final class JoinOnTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull();
            $b->varchar('team', 8)->notNull();
            $b->tinyint('active')->notNull()->default(0);
        });
        $this->conn->createTable('memberships', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id')->notNull();
            $b->varchar('team', 8)->notNull();
            $b->tinyint('active')->notNull()->default(0);
            $b->varchar('role', 16);
        });

        $this->conn->table('users')->insertMany([
            ['name' => 'alice', 'team' => 'A', 'active' => 1],
            ['name' => 'bob', 'team' => 'B', 'active' => 0],
            ['name' => 'carol', 'team' => 'A', 'active' => 1],
        ]);
        $this->conn->table('memberships')->insertMany([
            ['user_id' => 1, 'team' => 'A', 'active' => 1, 'role' => 'admin'],
            ['user_id' => 1, 'team' => 'B', 'active' => 1, 'role' => 'member'],
            ['user_id' => 3, 'team' => 'A', 'active' => 0, 'role' => 'member'],
        ]);
    }

    public function testJoinOnMultiCondition(): void
    {
        $on = new ConditionGroup();
        $on->whereColumn('users.id', '=', 'memberships.user_id');
        $on->where('memberships.active', '=', 1);

        $rows = $this->conn->table('users')
            ->joinOn('memberships', $on)
            ->select('users.name', 'memberships.id')
            ->orderBy('memberships.id')
            ->get()
            ->rows();

        // alice(user_id=1) 命中 m1/m2（均 active=1）；carol 的 m3 active=0 不命中
        $this->assertSame([
            ['name' => 'alice', 'id' => 1],
            ['name' => 'alice', 'id' => 2],
        ], $rows);
    }

    public function testJoinOnMatchesManualSingleComparison(): void
    {
        $on = new ConditionGroup();
        $on->whereColumn('users.id', '=', 'memberships.user_id');

        $viaOn = $this->conn->table('users')
            ->joinOn('memberships', $on)
            ->select('users.name')
            ->orderBy('memberships.id')
            ->get()
            ->rows();
        $viaJoin = $this->conn->table('users')
            ->join('memberships', 'users.id', '=', 'memberships.user_id')
            ->select('users.name')
            ->orderBy('memberships.id')
            ->get()
            ->rows();

        $this->assertSame($viaJoin, $viaOn);
    }

    public function testJoinOnWithOrCondition(): void
    {
        $on = new ConditionGroup();
        $on->whereColumn('users.id', '=', 'memberships.user_id');
        $on->orWhere('memberships.active', '=', 0);

        $names = $this->conn->table('users')
            ->joinOn('memberships', $on)
            ->select('users.name')
            ->orderBy('users.id', 'ASC')
            ->orderBy('memberships.id', 'ASC')
            ->get()
            ->pluck('name');

        // (user_id match OR active=0)
        // alice(1): m1(id=1✓), m2(id=1✓), m3(active=0✓) → 3 条
        // bob(2): m3(active=0✓) → 1 条
        // carol(3): m3(id=3✓) → 1 条
        $this->assertSame(['alice', 'alice', 'alice', 'bob', 'carol'], $names);
    }

    public function testLeftJoinOnKeepsUnmatchedWithNulls(): void
    {
        $on = new ConditionGroup();
        $on->whereColumn('users.id', '=', 'memberships.user_id');
        $on->where('memberships.active', '=', 1);

        $rows = $this->conn->table('users')
            ->leftJoinOn('memberships', $on)
            ->select('users.name', 'memberships.id')
            ->orderBy('users.name')
            ->get()
            ->rows();

        // alice(1)×m1(active=1), alice(1)×m2(active=1); bob(2) 无匹配→null; carol(3)×m3(active=0) 不命中→null
        $this->assertSame([
            ['name' => 'alice', 'id' => 1],
            ['name' => 'alice', 'id' => 2],
            ['name' => 'bob', 'id' => null],
            ['name' => 'carol', 'id' => null],
        ], $rows);
    }

    public function testRightJoinOnKeepsAllRightRows(): void
    {
        $on = new ConditionGroup();
        $on->whereColumn('users.id', '=', 'memberships.user_id');
        $on->where('memberships.active', '=', 1);

        $rows = $this->conn->table('users')
            ->rightJoinOn('memberships', $on)
            ->select('users.name', 'memberships.id')
            ->orderBy('memberships.id')
            ->get()
            ->rows();

        // 右表全保留：m1/m2 匹配（alice），m3(active=0) 无匹配 → users 侧 null
        $this->assertSame([
            ['name' => 'alice', 'id' => 1],
            ['name' => 'alice', 'id' => 2],
            ['name' => null, 'id' => 3],
        ], $rows);
    }

    public function testJoinUsingSingleColumn(): void
    {
        $names = $this->conn->table('users')
            ->joinUsing('memberships', 'team')
            ->select('users.name')
            ->get()
            ->pluck('name');
        sort($names);

        // team=A: alice, carol；team=B: bob —— 各 × 同 team 成员数：A 组 2 成员(B 组 1)
        $this->assertSame(['alice', 'alice', 'bob', 'carol', 'carol'], $names);
    }

    public function testJoinUsingMultipleColumns(): void
    {
        $rows = $this->conn->table('users')
            ->joinUsing('memberships', ['team', 'active'])
            ->select('users.name', 'memberships.id')
            ->orderBy('users.id')
            ->orderBy('memberships.id')
            ->get()
            ->rows();

        // (team,active)：alice(A,1)×m1(A,1)；bob(B,0) 无；carol(A,1)×m1(A,1)
        $this->assertSame([
            ['name' => 'alice', 'id' => 1],
            ['name' => 'carol', 'id' => 1],
        ], $rows);
    }

    public function testLeftJoinUsingSingleColumn(): void
    {
        $rows = $this->conn->table('users')
            ->leftJoinUsing('memberships', 'team')
            ->select('users.name')
            ->orderBy('users.id')
            ->get()
            ->pluck('name');

        // 每用户至少一个同 team 成员，全部保留
        $this->assertSame(['alice', 'alice', 'bob', 'carol', 'carol'], $rows);
    }

    public function testExplainJoinOnReportsNestedLoop(): void
    {
        $on = new ConditionGroup();
        $on->whereColumn('users.id', '=', 'memberships.user_id');
        $on->where('memberships.active', '=', 1);

        $steps = $this->conn->table('users')
            ->joinOn('memberships', $on)
            ->explain();

        $join = null;
        foreach ($steps as $step) {
            if (($step['step'] ?? '') === 'JOIN') {
                $join = $step;
            }
        }

        $this->assertNotNull($join);
        $this->assertSame('NESTED LOOP', $join['type']);
        $this->assertSame('condition expression', $join['on']);
    }

    public function testViewRejectsColumnColumnOn(): void
    {
        $on = new ConditionGroup();
        $on->whereColumn('users.id', '=', 'memberships.user_id');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ON 条件');

        $this->conn->createView('v', $this->conn->table('users')->joinOn('memberships', $on));
    }

    public function testViewPersistsScalarOn(): void
    {
        $on = new ConditionGroup();
        $on->where('memberships.active', '=', 1);

        // 纯标量 ON 条件组可持久化并跨重开恢复（列-列比较不可序列化，见 testViewRejectsColumnColumnOn）
        $this->conn->createView('v', $this->conn->table('users')->joinOn('memberships', $on));

        $rows = $this->conn->view('v')
            ->select('users.name')
            ->get()
            ->rows();

        // INNER JOIN ON active=1：users(外层序) × {m1, m2}（active=1 的两个 memberships）
        $this->assertSame([
            ['name' => 'alice'],
            ['name' => 'alice'],
            ['name' => 'bob'],
            ['name' => 'bob'],
            ['name' => 'carol'],
            ['name' => 'carol'],
        ], $rows);
    }
}