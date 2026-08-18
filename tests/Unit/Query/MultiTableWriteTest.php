<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\ConstraintException;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 多表 UPDATE / DELETE（JOIN 写入）测试：JOIN + WHERE 定位匹配行，
 * UPDATE 的 SET 键 'alias.col' 限定目标表、裸键归基表；DELETE 仅删基表匹配行
 */
final class MultiTableWriteTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 30)->notNull();
            $b->tinyint('banned')->notNull()->default(0);
            $b->tinyint('flag')->notNull()->default(0);
        });
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id')->notNull();
            $b->decimal('amount', 8, 2)->notNull();
            $b->varchar('status', 12)->notNull();
        });

        $this->conn->table('users')->insertMany([
            ['name' => 'alice', 'banned' => 1, 'flag' => 0],
            ['name' => 'bob', 'banned' => 0, 'flag' => 0],
            ['name' => 'carol', 'banned' => 1, 'flag' => 0],
        ]);
        $this->conn->table('orders')->insertMany([
            ['user_id' => 1, 'amount' => 100, 'status' => 'paid'],
            ['user_id' => 1, 'amount' => 50, 'status' => 'pending'],
            ['user_id' => 3, 'amount' => 200, 'status' => 'paid'],
        ]);
    }

    private function flags(): array
    {
        return $this->conn->table('users')->orderBy('id')->select('id', 'flag')->get()->rows();
    }

    public function testUpdateBaseTableWithBareKey(): void
    {
        $affected = $this->conn->table('users as u')
            ->join('orders as o', 'u.id', '=', 'o.user_id')
            ->where('o.status', 'paid')
            ->update(['flag' => 1]);

        $this->assertSame(2, $affected);
        $this->assertSame(
            [
                ['id' => 1, 'flag' => 1],
                ['id' => 2, 'flag' => 0],
                ['id' => 3, 'flag' => 1],
            ],
            $this->flags(),
        );
    }

    public function testUpdateJoinedTableWithQualifiedKey(): void
    {
        $affected = $this->conn->table('users as u')
            ->join('orders as o', 'u.id', '=', 'o.user_id')
            ->where('u.banned', 1)
            ->update(['o.status' => 'flagged']);

        $this->assertSame(3, $affected);
        $statuses = $this->conn->table('orders')->orderBy('id')->select('id', 'status')->get()->rows();
        $this->assertSame(
            [
                ['id' => 1, 'status' => 'flagged'],
                ['id' => 2, 'status' => 'flagged'],
                ['id' => 3, 'status' => 'flagged'],
            ],
            $statuses,
        );
    }

    public function testUpdateBothTablesInOneStatement(): void
    {
        $affected = $this->conn->table('users as u')
            ->join('orders as o', 'u.id', '=', 'o.user_id')
            ->where('o.amount', '>', 60)
            ->update(['u.flag' => 7, 'o.status' => 'big']);

        $this->assertSame(4, $affected); // 2 个用户 + 2 个订单
        $this->assertSame(
            [
                ['id' => 1, 'flag' => 7],
                ['id' => 2, 'flag' => 0],
                ['id' => 3, 'flag' => 7],
            ],
            $this->flags(),
        );
        $big = $this->conn->table('orders')->where('status', 'big')->select('id')->get()->rows();
        $this->assertSame([['id' => 1], ['id' => 3]], $big);
    }

    public function testUpdateWithLeftJoinNullMatch(): void
    {
        // 无订单的用户打标
        $affected = $this->conn->table('users as u')
            ->leftJoin('orders as o', 'u.id', '=', 'o.user_id')
            ->whereNull('o.id')
            ->update(['u.flag' => 9]);

        $this->assertSame(1, $affected);
        $this->assertSame(
            [
                ['id' => 1, 'flag' => 0],
                ['id' => 2, 'flag' => 9],
                ['id' => 3, 'flag' => 0],
            ],
            $this->flags(),
        );
    }

    public function testDeleteBaseRowsByJoinMatch(): void
    {
        $affected = $this->conn->table('users as u')
            ->join('orders as o', 'u.id', '=', 'o.user_id')
            ->where('o.status', 'paid')
            ->delete();

        $this->assertSame(2, $affected);
        $names = $this->conn->table('users')->orderBy('id')->select('id', 'name')->get()->rows();
        $this->assertSame([['id' => 2, 'name' => 'bob']], $names);
        // join 表不受影响
        $this->assertSame(3, $this->conn->table('orders')->count());
    }

    public function testDeleteLeftJoinNullMatch(): void
    {
        // 删除无订单的用户
        $affected = $this->conn->table('users as u')
            ->leftJoin('orders as o', 'u.id', '=', 'o.user_id')
            ->whereNull('o.id')
            ->delete();

        $this->assertSame(1, $affected);
        $names = $this->conn->table('users')->orderBy('id')->select('id', 'name')->get()->rows();
        $this->assertSame(
            [
                ['id' => 1, 'name' => 'alice'],
                ['id' => 3, 'name' => 'carol'],
            ],
            $names,
        );
    }

    public function testUpdateUnknownAliasThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('未知表别名');
        $this->conn->table('users as u')
            ->join('orders as o', 'u.id', '=', 'o.user_id')
            ->update(['x.flag' => 1]);
    }

    public function testUpdateUnknownColumnThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('未知列');
        $this->conn->table('users as u')
            ->join('orders as o', 'u.id', '=', 'o.user_id')
            ->update(['u.nope' => 1]);
    }

    public function testUpdateUniqueViolationThrows(): void
    {
        $this->conn->createTable('acct', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('email', 30)->notNull()->unique();
            $b->bigint('team_id');
        });
        $this->conn->createTable('teams', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 20);
        });
        $this->conn->table('teams')->insertMany([['name' => 't1'], ['name' => 't2']]);
        $this->conn->table('acct')->insertMany([
            ['email' => 'a@x', 'team_id' => 1],
            ['email' => 'b@x', 'team_id' => 2],
        ]);

        // 多表 UPDATE 把 team1 的账号 email 改成与 team2 相同 → 撞唯一约束
        $this->expectException(ConstraintException::class);
        $this->conn->table('acct as a')
            ->join('teams as t', 'a.team_id', '=', 't.id')
            ->where('t.id', 1)
            ->update(['a.email' => 'b@x']);
    }

    public function testSelfJoinMultiTableWrite(): void
    {
        $this->conn->createTable('emp', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 30)->notNull();
            $b->bigint('manager_id');
            $b->tinyint('promoted')->notNull()->default(0);
        });
        $this->conn->table('emp')->insertMany([
            ['name' => 'a', 'manager_id' => null, 'promoted' => 0],
            ['name' => 'b', 'manager_id' => 1, 'promoted' => 0],
            ['name' => 'c', 'manager_id' => 1, 'promoted' => 0],
        ]);

        // 晋升所有"管理者"（其名下有人）
        $affected = $this->conn->table('emp as e')
            ->join('emp as m', 'e.id', '=', 'm.manager_id')
            ->update(['e.promoted' => 1]);

        $this->assertSame(1, $affected);
        $promoted = $this->conn->table('emp')->where('promoted', 1)->select('name')->get()->rows();
        $this->assertSame([['name' => 'a']], $promoted);
    }
}
