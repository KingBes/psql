<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\Blueprint;
use Kingbes\Psql\Result\InsertResult;
use Kingbes\Psql\Result\ResultSet;
use PHPUnit\Framework\TestCase;

/**
 * Table/SelectBuilder 终结方法端到端测试：验证执行层与查询层契约吻合
 */
final class ShortcutTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull();
            $b->int('age');
        });
    }

    public function testInsertReturnsInsertResultWithAutoIncrement(): void
    {
        $result = $this->conn->table('users')->insert(['name' => 'a', 'age' => 20]);

        $this->assertInstanceOf(InsertResult::class, $result);
        $this->assertSame(1, $result->rowCount());
        $this->assertSame(1, $result->lastInsertId());

        $second = $this->conn->table('users')->insert(['name' => 'b', 'age' => 30]);
        $this->assertSame(2, $second->lastInsertId());
    }

    public function testInsertManyLastInsertIdIsLastRow(): void
    {
        $result = $this->conn->table('users')->insertMany([
            ['name' => 'a', 'age' => 20],
            ['name' => 'b', 'age' => 30],
        ]);

        $this->assertSame(2, $result->rowCount());
        $this->assertSame(2, $result->lastInsertId());
    }

    public function testInsertLastInsertIdNullWithoutAutoIncrement(): void
    {
        $this->conn->createTable('logs', static function (Blueprint $b): void {
            $b->bigint('id')->primaryKey();
            $b->text('message');
        });

        $result = $this->conn->table('logs')->insert(['id' => 7, 'message' => 'm']);

        $this->assertSame(1, $result->rowCount());
        $this->assertNull($result->lastInsertId());
    }

    public function testGetFirstAndFind(): void
    {
        $this->conn->table('users')->insertMany([
            ['name' => 'a', 'age' => 20],
            ['name' => 'b', 'age' => 30],
        ]);

        $resultSet = $this->conn->table('users')->get();
        $this->assertInstanceOf(ResultSet::class, $resultSet);
        $this->assertCount(2, $resultSet);

        $first = $this->conn->table('users')->first();
        $this->assertSame('a', $first['name']);

        $found = $this->conn->table('users')->find(2);
        $this->assertSame('b', $found['name']);

        $this->assertNull($this->conn->table('users')->find(99));
    }

    public function testFindWithoutPrimaryKeyThrows(): void
    {
        $this->conn->createTable('plain', static function (Blueprint $b): void {
            $b->varchar('code', 8);
        });

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('主键');
        $this->conn->table('plain')->find(1);
    }

    public function testAggregateShortcuts(): void
    {
        $this->conn->table('users')->insertMany([
            ['name' => 'a', 'age' => 20],
            ['name' => 'b', 'age' => 30],
            ['name' => 'c', 'age' => 40],
        ]);

        $this->assertSame(3, $this->conn->table('users')->count());
        $this->assertSame(90.0, $this->conn->table('users')->sum('age'));
        $this->assertSame(30.0, $this->conn->table('users')->avg('age'));
        $this->assertSame(20, $this->conn->table('users')->min('age'));
        $this->assertSame(40, $this->conn->table('users')->max('age'));

        // 条件聚合
        $this->assertSame(2, $this->conn->table('users')->where('age', '>', 20)->count());
        // 空集聚合：count=0、sum/avg=0.0（builder 侧归一）
        $this->assertSame(0, $this->conn->table('users')->where('age', '>', 999)->count());
        $this->assertSame(0.0, $this->conn->table('users')->where('age', '>', 999)->sum('age'));
    }

    public function testBuilderUpdateAndDelete(): void
    {
        $this->conn->table('users')->insertMany([
            ['name' => 'a', 'age' => 20],
            ['name' => 'b', 'age' => 30],
            ['name' => 'c', 'age' => 40],
        ]);

        $this->assertSame(1, $this->conn->table('users')->where('id', 2)->update(['age' => 99]));
        $this->assertSame(99, $this->conn->table('users')->find(2)['age']);

        $this->assertSame(1, $this->conn->table('users')->where('id', 1)->delete());
        $this->assertCount(2, $this->conn->table('users')->get());
    }

    public function testTruncateThroughTable(): void
    {
        $this->conn->table('users')->insertMany([
            ['name' => 'a', 'age' => 20],
            ['name' => 'b', 'age' => 30],
        ]);

        $this->conn->table('users')->truncate();

        $this->assertSame(0, $this->conn->table('users')->count());
        $this->assertSame(1, $this->conn->table('users')->insert(['name' => 'x'])->lastInsertId());
    }

    public function testAliasedTableQuery(): void
    {
        $this->conn->table('users')->insert(['name' => 'alice', 'age' => 20]);
        $this->conn->table('users')->insert(['name' => 'bob', 'age' => 30]);

        $first = $this->conn->table('users as u')
            ->where('u.name', 'alice')
            ->first();

        $this->assertSame('alice', $first['name']);

        $rows = $this->conn->table('users as u')
            ->select('u.name')
            ->where('u.age', '>=', 20)
            ->orderBy('u.name')
            ->get()
            ->rows();

        $this->assertSame([['name' => 'alice'], ['name' => 'bob']], $rows);
    }

    public function testWhereLikeAndBetweenShortcuts(): void
    {
        $this->conn->table('users')->insertMany([
            ['name' => 'alice', 'age' => 20],
            ['name' => 'bob', 'age' => 30],
        ]);

        $this->assertSame(['alice'], $this->conn->table('users')->whereLike('name', 'a%')->get()->pluck('name'));
        $this->assertSame(['bob'], $this->conn->table('users')->whereBetween('age', 25, 35)->get()->pluck('name'));
    }
}
