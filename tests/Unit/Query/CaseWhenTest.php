<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Agg;
use Kingbes\Psql\Query\CaseWhen;
use Kingbes\Psql\Query\Func;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * CASE WHEN 表达式测试：分支求值、else 兜底、嵌套表达式、状态机校验与端到端投影/分组
 */
final class CaseWhenTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull();
            $b->bigint('age');
            $b->varchar('dept', 32);
        });
        $this->conn->table('users')->insertMany([
            ['name' => 'alice', 'age' => 35, 'dept' => 'eng'],
            ['name' => 'bob', 'age' => 17, 'dept' => 'eng'],
            ['name' => 'carol', 'age' => 45, 'dept' => 'sales'],
            ['name' => '张伟', 'age' => 28, 'dept' => 'sales'],
        ]);
    }

    /**
     * 年龄分段标签（多分支）：>=40 老年、>=30 中年、>=18 成年、其余未成年
     */
    private function ageBand(): CaseWhen
    {
        return CaseWhen::make()
            ->when('age', '>=', 40)->then('老年')
            ->when('age', '>=', 30)->then('中年')
            ->when('age', '>=', 18)->then('成年')
            ->else('未成年');
    }

    // ---- 分支求值 ----

    public function testFirstMatchingBranchWins(): void
    {
        $band = $this->ageBand();

        $this->assertSame('老年', $band->evaluate(['u.age' => 45]));
        $this->assertSame('中年', $band->evaluate(['u.age' => 30]));
        $this->assertSame('成年', $band->evaluate(['u.age' => 18]));
        $this->assertSame('未成年', $band->evaluate(['u.age' => 17]));
    }

    public function testEqualityFormDefaultsToEqualsOperator(): void
    {
        $expr = CaseWhen::make()
            ->when('dept', 'eng')->then('研发')
            ->else('其他');

        $this->assertSame('研发', $expr->evaluate(['u.dept' => 'eng']));
        $this->assertSame('其他', $expr->evaluate(['u.dept' => 'sales']));
    }

    public function testElseFallback(): void
    {
        $expr = CaseWhen::make()
            ->when('age', '>', 100)->then('异常')
            ->else('正常');

        $this->assertSame('正常', $expr->evaluate(['u.age' => 35]));
    }

    public function testNoElseReturnsNull(): void
    {
        $expr = CaseWhen::make()
            ->when('age', '>', 100)->then('异常');

        $this->assertNull($expr->evaluate(['u.age' => 35]));
    }

    public function testNullComparisonNeverMatches(): void
    {
        // 列值为 null 时任何比较恒不中（复用条件求值语义），走 else
        $expr = CaseWhen::make()
            ->when('age', '>=', 18)->then('成年')
            ->else('未知');

        $this->assertSame('未知', $expr->evaluate(['u.age' => null]));
    }

    // ---- 嵌套表达式 ----

    public function testThenNestedExpression(): void
    {
        $expr = CaseWhen::make()
            ->when('dept', 'eng')->then(Func::upper(Func::col('name')))
            ->else(Func::lower(Func::col('name')));

        $this->assertSame('ALICE', $expr->evaluate(['u.dept' => 'eng', 'u.name' => 'alice']));
        $this->assertSame('carol', $expr->evaluate(['u.dept' => 'sales', 'u.name' => 'CAROL']));
    }

    public function testElseNestedExpression(): void
    {
        $expr = CaseWhen::make()
            ->when('age', '>=', 40)->then('老')
            ->else(Func::concat(Func::col('name'), '-后备'));

        $this->assertSame('bob-后备', $expr->evaluate(['u.age' => 17, 'u.name' => 'bob']));
    }

    public function testNestedCaseAsBranchValue(): void
    {
        $inner = CaseWhen::make()
            ->when('dept', 'sales')->then('销售组')
            ->else('其他组');
        $expr = CaseWhen::make()
            ->when('age', '>=', 18)->then($inner)
            ->else('未成年');

        $this->assertSame('销售组', $expr->evaluate(['u.age' => 45, 'u.dept' => 'sales']));
        $this->assertSame('其他组', $expr->evaluate(['u.age' => 28, 'u.dept' => 'eng']));
        $this->assertSame('未成年', $expr->evaluate(['u.age' => 17, 'u.dept' => 'sales']));
    }

    // ---- 状态机与校验 ----

    public function testWhenWithoutThenThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('then');

        CaseWhen::make()->when('a', 1)->when('b', 2);
    }

    public function testThenBeforeWhenThrows(): void
    {
        $this->expectException(QueryException::class);

        CaseWhen::make()->then(1);
    }

    public function testInvalidOperatorThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('非法比较运算符');

        CaseWhen::make()->when('age', '~', 1);
    }

    public function testWhenArityMisuseThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('when 参数形式非法');

        CaseWhen::make()->when('a');
    }

    public function testThenInvalidValueThrows(): void
    {
        $this->expectException(QueryException::class);

        CaseWhen::make()->when('a', 1)->then([1, 2]);
    }

    public function testElseInvalidValueThrows(): void
    {
        $this->expectException(QueryException::class);

        CaseWhen::make()->else(new \stdClass());
    }

    // ---- outputName / 别名 ----

    public function testOutputNameIsCase(): void
    {
        $this->assertSame('CASE', $this->ageBand()->outputName());
    }

    public function testAsReturnsNewInstanceWithIndependentAlias(): void
    {
        $original = $this->ageBand();
        $aliased = $original->as('band');

        $this->assertNotSame($original, $aliased);
        $this->assertNull($original->alias());
        $this->assertSame('band', $aliased->alias());
        // 求值结构共享：两者求值结果一致
        $row = ['u.age' => 45];
        $this->assertSame($original->evaluate($row), $aliased->evaluate($row));
        $this->assertSame('老年', $aliased->evaluate($row));
        // as 不影响 outputName
        $this->assertSame('CASE', $aliased->outputName());
    }

    // ---- 端到端 ----

    public function testEndToEndAgeBracketLabels(): void
    {
        $rows = $this->conn->table('users')
            ->select('name', $this->ageBand()->as('band'))
            ->orderBy('id')
            ->get()
            ->rows();

        $this->assertSame([
            ['name' => 'alice', 'band' => '中年'],
            ['name' => 'bob', 'band' => '未成年'],
            ['name' => 'carol', 'band' => '老年'],
            ['name' => '张伟', 'band' => '成年'],
        ], $rows);
    }

    public function testEndToEndOrderByAlias(): void
    {
        $rows = $this->conn->table('users')
            ->select('name', $this->ageBand()->as('band'))
            ->orderBy('band')
            ->orderBy('name')
            ->get()
            ->rows();

        // 标签排序（UTF-8 字节序）：中年 < 成年 < 未成年 < 老年
        $this->assertSame([
            ['name' => 'alice', 'band' => '中年'],
            ['name' => '张伟', 'band' => '成年'],
            ['name' => 'bob', 'band' => '未成年'],
            ['name' => 'carol', 'band' => '老年'],
        ], $rows);
    }

    public function testEndToEndGroupedFirstRowEvaluationWithHaving(): void
    {
        // 分组上下文：CASE 表达式取组内首行求值（eng 组首行 alice=35 → 中年）
        $rows = $this->conn->table('users')
            ->select('dept', $this->ageBand()->as('band'), Agg::count('*')->as('cnt'))
            ->groupBy('dept')
            ->having('band', '=', '中年')
            ->orderBy('dept')
            ->get()
            ->rows();

        $this->assertSame([
            ['dept' => 'eng', 'band' => '中年', 'cnt' => 2],
        ], $rows);
    }

    public function testEndToEndChineseDataWithMbFunctions(): void
    {
        if (!function_exists('mb_strlen')) {
            self::markTestSkipped('mbstring 扩展不可用');
        }
        $this->conn->createTable('books', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('title', 64)->notNull();
        });
        $this->conn->table('books')->insertMany([
            ['title' => '三体'],
            ['title' => '流浪地球'],
        ]);

        $label = CaseWhen::make()
            ->when('title', '流浪地球')->then(Func::concat(Func::substr(Func::col('title'), 1, 2), '…'))
            ->else('其他书');

        $rows = $this->conn->table('books')
            ->select('title', Func::upper(Func::col('title'))->as('shout'), $label->as('kind'))
            ->orderBy('id')
            ->get()
            ->rows();

        $this->assertSame([
            ['title' => '三体', 'shout' => '三体', 'kind' => '其他书'],
            ['title' => '流浪地球', 'shout' => '流浪地球', 'kind' => '流浪…'],
        ], $rows);
    }

    public function testEndToEndCaseInAggregateContextOnlyExpression(): void
    {
        // 仅有表达式（无聚合无分组）走普通投影：逐行求值
        $rows = $this->conn->table('users')
            ->select(CaseWhen::make()->when('dept', 'eng')->then('E')->else('S'))
            ->orderBy('id')
            ->get()
            ->rows();

        $this->assertSame([
            ['CASE' => 'E'],
            ['CASE' => 'E'],
            ['CASE' => 'S'],
            ['CASE' => 'S'],
        ], $rows);
    }
}
