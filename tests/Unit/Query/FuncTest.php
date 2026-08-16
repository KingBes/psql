<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Agg;
use Kingbes\Psql\Query\CaseWhen;
use Kingbes\Psql\Query\ColumnRef;
use Kingbes\Psql\Query\Func;
use Kingbes\Psql\Query\FuncExpression;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 标量函数表达式测试：求值矩阵（正常/null 传播/嵌套/标量参数）、outputName、构造校验与端到端投影
 */
final class FuncTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull();
            $b->varchar('nickname', 32);
            $b->bigint('age');
            $b->varchar('dept', 32);
            $b->varchar('created', 32);
        });
        $this->conn->table('users')->insertMany([
            ['name' => 'alice', 'nickname' => null, 'age' => 35, 'dept' => 'eng', 'created' => '2024-03-05 10:20:30'],
            ['name' => 'bob', 'nickname' => 'BOBBY', 'age' => 17, 'dept' => 'eng', 'created' => '2023-12-31'],
            ['name' => '张伟', 'nickname' => null, 'age' => 45, 'dept' => 'sales', 'created' => '2024-01-01 00:00:00'],
        ]);
    }

    // ---- 字符串函数 ----

    public function testUpperAndLower(): void
    {
        $row = ['u.title' => 'Hello'];

        $this->assertSame('HELLO', Func::upper(Func::col('title'))->evaluate($row));
        $this->assertSame('hello', Func::lower(Func::col('title'))->evaluate($row));
        // 标量参数直通
        $this->assertSame('WORLD', Func::upper('world')->evaluate($row));
    }

    public function testUpperLowerChineseWithMb(): void
    {
        if (!function_exists('mb_strlen')) {
            self::markTestSkipped('mbstring 扩展不可用');
        }
        // 中文无大小写变化（字节级 strtoupper 会破坏 UTF-8，mb 正确保持）
        $row = ['u.name' => '张伟'];

        $this->assertSame('张伟', Func::upper(Func::col('name'))->evaluate($row));
        $this->assertSame('张伟', Func::lower(Func::col('name'))->evaluate($row));
    }

    public function testUpperNullPropagation(): void
    {
        $expr = Func::upper(Func::col('nickname'));
        $row = ['u.nickname' => null];

        $this->assertNull($expr->evaluate($row));
    }

    public function testLengthCountsCharacters(): void
    {
        if (!function_exists('mb_strlen')) {
            self::markTestSkipped('mbstring 扩展不可用');
        }

        $this->assertSame(4, Func::length('世界你好')->evaluate([]));
        $this->assertNull(Func::length(Func::col('x'))->evaluate(['u.x' => null]));
    }

    public function testTrimFamily(): void
    {
        $row = ['u.v' => '  pad  '];

        $this->assertSame('pad', Func::trim(Func::col('v'))->evaluate($row));
        $this->assertSame('pad  ', Func::ltrim(Func::col('v'))->evaluate($row));
        $this->assertSame('  pad', Func::rtrim(Func::col('v'))->evaluate($row));
    }

    public function testSubstrIsOneBased(): void
    {
        $this->assertSame('BCD', Func::substr('ABCDEF', 2, 3)->evaluate([]));
        // len 缺省截到串尾
        $this->assertSame('BCDEF', Func::substr('ABCDEF', 2)->evaluate([]));
        $this->assertSame('', Func::substr('ABCDEF', 2, 0)->evaluate([]));
        $this->assertNull(Func::substr(Func::col('s'), 1, 2)->evaluate(['u.s' => null]));
    }

    public function testSubstrChineseWithMb(): void
    {
        if (!function_exists('mb_strlen')) {
            self::markTestSkipped('mbstring 扩展不可用');
        }

        $this->assertSame('界你', Func::substr('世界你好', 2, 2)->evaluate([]));
        $this->assertSame('好', Func::substr('世界你好', 4)->evaluate([]));
    }

    public function testSubstrZeroPositionThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('SUBSTR');

        Func::substr('ABC', 0, 1)->evaluate([]);
    }

    public function testSubstrNegativeLengthThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('SUBSTR');

        Func::substr('ABC', 1, -1)->evaluate([]);
    }

    public function testConcatScalarCoercion(): void
    {
        // bool→'1'/'0'，int/float 常规强转
        $this->assertSame('a1102.5', Func::concat('a', 1, true, false, 2.5)->evaluate([]));
    }

    public function testConcatNullPropagation(): void
    {
        $row = ['u.a' => 'x'];

        $this->assertNull(Func::concat(Func::col('a'), Func::col('missing'))->evaluate($row + ['u.missing' => null]));
    }

    public function testConcatNestedExpressions(): void
    {
        $row = ['u.a' => 'x', 'u.b' => 'y'];

        $this->assertSame(
            'X_y',
            Func::concat(Func::upper(Func::col('a')), '_', Func::col('b'))->evaluate($row),
        );
    }

    public function testConcatRequiresAtLeastOneArgument(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('CONCAT');

        Func::concat(...[]);
    }

    public function testReplace(): void
    {
        $this->assertSame('a+b+c', Func::replace('a-b-c', '-', '+')->evaluate([]));
        $this->assertSame('张-伟', Func::replace(Func::col('n'), '张伟', '张-伟')->evaluate(['u.n' => '张伟']));
        $this->assertNull(Func::replace(Func::col('s'), 'a', 'b')->evaluate(['u.s' => null]));
    }

    // ---- 数值函数 ----

    public function testAbsIntFloatAndNumericString(): void
    {
        $this->assertSame(5, Func::abs(-5)->evaluate([]));
        $this->assertSame(2.5, Func::abs(-2.5)->evaluate([]));
        $this->assertSame(3, Func::abs('-3')->evaluate([]));
        $this->assertNull(Func::abs(Func::col('x'))->evaluate(['u.x' => null]));
    }

    public function testAbsNonNumericThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ABS');

        Func::abs('abc')->evaluate([]);
    }

    public function testRoundReturnsFloat(): void
    {
        $this->assertSame(4.0, Func::round(3.6)->evaluate([]));
        $this->assertSame(3.0, Func::round('3.4')->evaluate([]));
        $this->assertEqualsWithDelta(3.57, Func::round(3.567, 2)->evaluate([]), 1e-9);
        $this->assertNull(Func::round(Func::col('x'), 2)->evaluate(['u.x' => null]));
    }

    public function testFloorCeilNumericString(): void
    {
        $this->assertSame(3.0, Func::floor('3.9')->evaluate([]));
        $this->assertSame(-3.0, Func::ceil('-3.1')->evaluate([]));
        $this->assertNull(Func::floor(Func::col('x'))->evaluate(['u.x' => null]));
        $this->assertNull(Func::ceil(Func::col('x'))->evaluate(['u.x' => null]));
    }

    // ---- 日期函数 ----

    public function testYearMonthDay(): void
    {
        $full = Func::year(Func::col('created'));
        $row = ['u.created' => '2024-03-05 10:20:30'];

        $this->assertSame(2024, $full->evaluate($row));
        $this->assertSame(3, Func::month(Func::col('created'))->evaluate($row));
        $this->assertSame(5, Func::day(Func::col('created'))->evaluate($row));

        $dateOnly = ['u.created' => '2023-12-31'];
        $this->assertSame(2023, Func::year(Func::col('created'))->evaluate($dateOnly));
        $this->assertSame(12, Func::month(Func::col('created'))->evaluate($dateOnly));
        $this->assertSame(31, Func::day(Func::col('created'))->evaluate($dateOnly));
    }

    public function testDateFunctionsNullPropagation(): void
    {
        $row = ['u.created' => null];

        $this->assertNull(Func::year(Func::col('created'))->evaluate($row));
        $this->assertNull(Func::month(Func::col('created'))->evaluate($row));
        $this->assertNull(Func::day(Func::col('created'))->evaluate($row));
    }

    public function testYearInvalidFormatThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('YEAR');

        Func::year('20240305')->evaluate([]);
    }

    public function testMonthOutOfRangeThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('MONTH');

        Func::month('2024-13-01')->evaluate([]);
    }

    // ---- NULL 处理函数 ----

    public function testCoalesceTakesFirstNonNull(): void
    {
        $row = ['u.a' => null, 'u.b' => 'x', 'u.c' => 'y'];

        $this->assertSame('x', Func::coalesce(Func::col('a'), Func::col('b'), Func::col('c'))->evaluate($row));
        $this->assertSame('dft', Func::coalesce(null, 'dft')->evaluate($row));
    }

    public function testCoalesceAllNullReturnsNull(): void
    {
        $this->assertNull(Func::coalesce(null, null)->evaluate([]));
    }

    public function testCoalesceRequiresAtLeastOneArgument(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('COALESCE');

        Func::coalesce(...[]);
    }

    public function testNullif(): void
    {
        $this->assertSame('a', Func::nullif('a', 'b')->evaluate([]));
        $this->assertNull(Func::nullif('a', 'a')->evaluate([]));
        // a 为 null → null；b 为 null 比较永不成立 → 返回 a
        $this->assertNull(Func::nullif(null, 'x')->evaluate([]));
        $this->assertSame('a', Func::nullif('a', null)->evaluate([]));
    }

    // ---- outputName / 别名 / 构造校验 ----

    public function testOutputNameStyle(): void
    {
        $this->assertSame('UPPER(name)', Func::upper(Func::col('name'))->outputName());
        $this->assertSame(
            "CONCAT(first, ' ', last)",
            Func::concat(Func::col('first'), ' ', Func::col('last'))->outputName(),
        );
        // 标量 var_export 风格（字符串带引号）
        $this->assertSame("REPLACE('subject', '-', '_')", Func::replace('subject', '-', '_')->outputName());
        $this->assertSame('SUBSTR(name, 2, 3)', Func::substr(Func::col('name'), 2, 3)->outputName());
        $this->assertSame('ABS(NULL)', Func::abs(null)->outputName());
        $this->assertSame("ROUND('x', 2)", Func::round('x', 2)->outputName());
        // 嵌套表达式用其 outputName
        $this->assertSame(
            "CONCAT(UPPER(a), '_')",
            Func::concat(Func::upper(Func::col('a')), '_')->outputName(),
        );
    }

    public function testAsReturnsNewInstanceWithIndependentAlias(): void
    {
        $original = Func::upper(Func::col('name'));
        $aliased = $original->as('uname');

        $this->assertNotSame($original, $aliased);
        $this->assertNull($original->alias());
        $this->assertSame('uname', $aliased->alias());
        // outputName 不受别名影响（别名由 Executor 决定输出键）
        $this->assertSame('UPPER(name)', $aliased->outputName());
    }

    public function testConstructorInvalidFunctionThrows(): void
    {
        $this->expectException(QueryException::class);

        new FuncExpression('MEDIAN', [1]);
    }

    public function testConstructorInvalidArgTypeThrows(): void
    {
        $this->expectException(QueryException::class);

        new FuncExpression('UPPER', [[1, 2]]);
    }

    public function testConstructorAcceptsLowercaseFunctionName(): void
    {
        $expr = new FuncExpression('upper', ['a']);

        $this->assertSame("UPPER('a')", $expr->outputName());
        $this->assertSame('A', $expr->evaluate([]));
    }

    // ---- ColumnRef ----

    public function testColumnRefExactKeyAndSuffixMatch(): void
    {
        $ref = Func::col('name');
        $this->assertInstanceOf(ColumnRef::class, $ref);
        $this->assertSame('name', $ref->outputName());
        $this->assertNull($ref->alias());

        // 后缀唯一匹配
        $this->assertSame('alice', $ref->evaluate(['u.name' => 'alice']));
        // 精确键优先
        $this->assertSame('bob', Func::col('u.name')->evaluate(['u.name' => 'bob']));
    }

    public function testColumnRefUnknownColumnThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('未知列');

        Func::col('ghost')->evaluate(['u.name' => 'x']);
    }

    public function testColumnRefAmbiguousColumnThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('歧义');

        Func::col('id')->evaluate(['a.id' => 1, 'b.id' => 2]);
    }

    // ---- 端到端 ----

    public function testEndToEndProjectionWithAlias(): void
    {
        $rows = $this->conn->table('users')
            ->select(Func::upper(Func::col('name'))->as('uname'))
            ->orderBy('id')
            ->get()
            ->rows();

        // 显式投影仅输出表达式列
        $this->assertSame([
            ['uname' => 'ALICE'],
            ['uname' => 'BOB'],
            ['uname' => '张伟'],
        ], $rows);
    }

    public function testEndToEndDefaultOutputKey(): void
    {
        $rows = $this->conn->table('users')
            ->select('name', Func::length(Func::col('name')))
            ->where('id', 1)
            ->get()
            ->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('alice', $rows[0]['name']);
        $this->assertSame(5, $rows[0]['LENGTH(name)']);
    }

    public function testEndToEndWhereAndOrderByAlias(): void
    {
        $rows = $this->conn->table('users')
            ->select('name', Func::year(Func::col('created'))->as('y'))
            ->where('created', '>=', '2024-01-01')
            ->orderBy('name')
            ->get()
            ->rows();

        $this->assertSame([
            ['name' => 'alice', 'y' => 2024],
            ['name' => '张伟', 'y' => 2024],
        ], $rows);
    }

    public function testEndToEndWithAggregateGroupByExpressionKey(): void
    {
        $rows = $this->conn->table('users')
            ->select(
                'dept',
                Func::year(Func::col('created'))->as('y'),
                Agg::count('*')->as('cnt'),
            )
            ->groupBy('dept', 'y')
            ->orderBy('dept')
            ->orderBy('y')
            ->get()
            ->rows();

        // 按部门 + 年份分组：eng 2023 一行、eng 2024 一行、sales 2024 一行
        $this->assertSame([
            ['dept' => 'eng', 'y' => 2023, 'cnt' => 1],
            ['dept' => 'eng', 'y' => 2024, 'cnt' => 1],
            ['dept' => 'sales', 'y' => 2024, 'cnt' => 1],
        ], $rows);
    }

    public function testEndToEndOutputKeyConflictThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('冲突');

        $this->conn->table('users')
            ->select('name', Func::upper(Func::col('name'))->as('name'))
            ->get();
    }

    public function testEndToEndDistinctOnExpressionOutput(): void
    {
        $rows = $this->conn->table('users')
            ->select(Func::year(Func::col('created'))->as('y'))
            ->distinct()
            ->orderBy('y')
            ->get()
            ->rows();

        $this->assertSame([['y' => 2023], ['y' => 2024]], $rows);
    }

    public function testEndToEndCaseAndFuncCoexist(): void
    {
        $label = CaseWhen::make()
            ->when('age', '>=', 40)->then(Func::upper(Func::col('dept')))
            ->else(Func::lower(Func::col('dept')));

        $rows = $this->conn->table('users')
            ->select('name', $label->as('band'))
            ->orderBy('id')
            ->get()
            ->rows();

        $this->assertSame([
            ['name' => 'alice', 'band' => 'eng'],
            ['name' => 'bob', 'band' => 'eng'],
            ['name' => '张伟', 'band' => 'SALES'],
        ], $rows);
    }
}
