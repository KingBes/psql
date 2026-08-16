<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Query\Condition\SubqueryIn;
use Kingbes\Psql\Query\ConditionEvaluator;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 子查询条件测试：whereIn/whereNotIn/whereExists/whereNotIn 子查询、防呆、写路径接入
 */
final class SubqueryTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull();
            $b->tinyint('vip')->notNull()->default(0);
        });
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id')->notNull();
            $b->bigint('coupon_id');
            $b->int('amount')->notNull();
        });

        $this->conn->table('users')->insertMany([
            ['name' => 'alice', 'vip' => 1],
            ['name' => 'bob', 'vip' => 0],
            ['name' => 'carol', 'vip' => 1],
            ['name' => 'dave', 'vip' => 0],
        ]);
        $this->conn->table('orders')->insertMany([
            ['user_id' => 1, 'coupon_id' => 10, 'amount' => 100],
            ['user_id' => 2, 'coupon_id' => null, 'amount' => 200],
            ['user_id' => 3, 'coupon_id' => 30, 'amount' => 300],
            ['user_id' => 4, 'coupon_id' => 30, 'amount' => 400],
            ['user_id' => 99, 'coupon_id' => 10, 'amount' => 500],
        ]);
    }

    // ---- whereIn / whereNotIn 子查询 ----

    public function testWhereInSubqueryMatchesManualArray(): void
    {
        $sub = $this->conn->table('users')->select('id')->where('vip', 1);

        $rows = $this->conn->table('orders')
            ->whereIn('user_id', $sub)
            ->orderBy('id')
            ->get()
            ->rows();
        $manual = $this->conn->table('orders')
            ->whereIn('user_id', [1, 3])
            ->orderBy('id')
            ->get()
            ->rows();

        $this->assertSame($manual, $rows);
        $this->assertSame([1, 3], array_column($rows, 'user_id'));
    }

    public function testWhereInSubqueryEmptyResultSetMatchesNothing(): void
    {
        $sub = $this->conn->table('users')->select('id')->where('vip', 99);

        $rows = $this->conn->table('orders')
            ->whereIn('user_id', $sub)
            ->get()
            ->rows();

        $this->assertSame([], $rows);
    }

    public function testWhereNotInSubqueryWithNullMembers(): void
    {
        // 子查询产出 coupon_id 列：[10, null]（订单 1/2，可空列含 null）
        $sub = $this->conn->table('orders')->select('coupon_id')->where('amount', '<', 250);

        $rows = $this->conn->table('orders')
            ->whereNotIn('coupon_id', $sub)
            ->orderBy('id')
            ->get()
            ->rows();
        $manual = $this->conn->table('orders')
            ->whereNotIn('coupon_id', [10, null])
            ->orderBy('id')
            ->get()
            ->rows();

        // null 成员语义：null 列的行恒 false（订单 2 排除）、coupon=10 的行匹配后 NOT IN 为假（订单 1/5 排除）
        $this->assertSame($manual, $rows);
        $this->assertSame([3, 4], array_column($rows, 'id'));
    }

    // ---- whereExists / whereNotExists ----

    public function testWhereExistsNonEmptySubqueryKeepsAllRows(): void
    {
        $nonEmpty = $this->conn->table('orders')->select('id')->where('user_id', 1);

        $rows = $this->conn->table('users')
            ->select('id')
            ->whereExists($nonEmpty)
            ->orderBy('id')
            ->get()
            ->rows();

        // 子查询非空 → 常量真 → 全保留
        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]], $rows);
    }

    public function testWhereExistsEmptySubqueryKeepsNothing(): void
    {
        $empty = $this->conn->table('orders')->select('id')->where('user_id', 999);

        $rows = $this->conn->table('users')
            ->select('id')
            ->whereExists($empty)
            ->get()
            ->rows();

        $this->assertSame([], $rows);
    }

    public function testWhereNotExistsEmptySubqueryKeepsAllRows(): void
    {
        $empty = $this->conn->table('orders')->select('id')->where('user_id', 999);

        $rows = $this->conn->table('users')
            ->select('id')
            ->whereNotExists($empty)
            ->orderBy('id')
            ->get()
            ->rows();

        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]], $rows);
    }

    public function testExistsCombinedWithWhereViaAnd(): void
    {
        $nonEmpty = $this->conn->table('orders')->select('id')->where('user_id', 1);
        $empty = $this->conn->table('orders')->select('id')->where('user_id', 999);

        $rows = $this->conn->table('users')
            ->select('id')
            ->where('vip', 1)
            ->whereExists($nonEmpty)
            ->orderBy('id')
            ->get()
            ->rows();
        $this->assertSame([['id' => 1], ['id' => 3]], $rows);

        $rows = $this->conn->table('users')
            ->select('id')
            ->where('vip', 1)
            ->whereNotExists($empty)
            ->orderBy('id')
            ->get()
            ->rows();
        $this->assertSame([['id' => 1], ['id' => 3]], $rows);
    }

    public function testExistsCombinedViaOrWhere(): void
    {
        // (vip=1 AND EXISTS(空)=false) OR vip=0 → 仅 vip=0 的行
        $empty = $this->conn->table('orders')->select('id')->where('user_id', 999);

        $rows = $this->conn->table('users')
            ->select('id')
            ->where('vip', 1)
            ->whereExists($empty)
            ->orWhere('vip', 0)
            ->orderBy('id')
            ->get()
            ->rows();

        $this->assertSame([['id' => 2], ['id' => 4]], $rows);
    }

    // ---- 输出列校验 ----

    public function testSubqueryWithTwoOutputColumnsThrows(): void
    {
        $sub = $this->conn->table('users')->select('id', 'name');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('子查询必须指定输出列');
        $this->conn->table('orders')->whereIn('user_id', $sub)->get();
    }

    public function testSubquerySelectStarMultiColumnThrows(): void
    {
        // 不指定输出列 → 全列展开（users 共 3 列）
        $sub = $this->conn->table('users')->where('id', '>=', 0);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('子查询必须指定输出列');
        $this->conn->table('orders')->whereNotIn('user_id', $sub)->get();
    }

    // ---- 嵌套与子方收尾语义 ----

    public function testNestedSubqueryThreeLevels(): void
    {
        $inner = $this->conn->table('users')->select('id')->where('vip', 1);
        $middle = $this->conn->table('orders')
            ->select('coupon_id')
            ->whereIn('user_id', $inner)
            ->whereNotNull('coupon_id');

        $rows = $this->conn->table('orders')
            ->select('id')
            ->whereIn('coupon_id', $middle)
            ->orderBy('id')
            ->get()
            ->rows();

        // 最内 [1,3] → 中层 coupon [10,30] → 外层命中订单 1/3/4/5
        $this->assertSame([1, 3, 4, 5], array_column($rows, 'id'));
    }

    public function testSubqueryWithOrderByLimit(): void
    {
        // 子方完整执行：vip=1 的用户 id 倒序取 1 个 → [3]
        $sub = $this->conn->table('users')
            ->select('id')
            ->where('vip', 1)
            ->orderByDesc('id')
            ->limit(1);

        $rows = $this->conn->table('orders')
            ->select('id')
            ->whereIn('user_id', $sub)
            ->orderBy('id')
            ->get()
            ->rows();

        $this->assertSame([3], array_column($rows, 'id'));
    }

    // ---- 防呆 ----

    public function testUnresolvedSubqueryConditionThrowsOnEvaluate(): void
    {
        $sub = $this->conn->table('users')->select('id');
        $group = (new ConditionGroup())->add(new SubqueryIn('user_id', $sub->toQuery()), 'AND');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('子查询条件必须先经 SubqueryResolver 解析');
        ConditionEvaluator::evaluate(['user_id' => 1], $group);
    }

    // ---- 写路径 ----

    public function testUpdateWithSubqueryWhere(): void
    {
        $sub = $this->conn->table('users')->select('id')->where('vip', 1);

        $affected = $this->conn->table('orders')
            ->whereIn('user_id', $sub)
            ->update(['amount' => 999]);

        $this->assertSame(2, $affected);
        $this->assertSame(
            [1, 3],
            array_column($this->conn->table('orders')->where('amount', 999)->orderBy('id')->get()->rows(), 'id'),
        );
    }

    public function testDeleteWithSubqueryWhere(): void
    {
        $sub = $this->conn->table('users')->select('id')->where('vip', 0);

        $affected = $this->conn->table('orders')
            ->whereIn('user_id', $sub)
            ->delete();

        // vip=0 用户为 bob(2)/dave(4) → 命中订单 2 与 4
        $this->assertSame(2, $affected);
        $this->assertSame(3, $this->conn->table('orders')->count());
    }

    // ---- CHECK 约束注册拦截 ----

    public function testCheckConstraintRejectsSubqueryCondition(): void
    {
        $sub = $this->conn->table('users')->select('id');
        $group = (new ConditionGroup())->add(new SubqueryIn('user_id', $sub->toQuery()), 'AND');

        $this->expectException(SchemaException::class);
        $this->conn->createTable('t_checked', static function (Blueprint $b) use ($group): void {
            $b->id();
            $b->bigint('user_id')->notNull();
            $b->check('chk_sub', $group);
        });
    }

    public function testCheckConstraintRejectsExistsCondition(): void
    {
        $sub = $this->conn->table('users')->select('id');
        $group = (new ConditionGroup())->add(
            new \Kingbes\Psql\Query\Condition\ExistsCheck($sub->toQuery()),
            'AND',
        );

        $this->expectException(SchemaException::class);
        $this->conn->createTable('t_checked2', static function (Blueprint $b) use ($group): void {
            $b->id();
            $b->bigint('user_id')->notNull();
            $b->check('chk_exists', $group);
        });
    }
}
