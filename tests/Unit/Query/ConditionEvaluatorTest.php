<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Query\Condition\Between;
use Kingbes\Psql\Query\Condition\Comparison;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Query\Condition\InList;
use Kingbes\Psql\Query\Condition\LikeCondition;
use Kingbes\Psql\Query\Condition\NullCheck;
use Kingbes\Psql\Query\ConditionEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * ConditionEvaluator 真值矩阵测试
 */
final class ConditionEvaluatorTest extends TestCase
{
    public function testEmptyGroupAlwaysTrue(): void
    {
        $group = new ConditionGroup();

        $this->assertTrue($group->isEmpty());
        $this->assertTrue(ConditionEvaluator::evaluate(['id' => 1], $group));
    }

    public function testComparisonOperatorMatrix(): void
    {
        $row = ['n' => 10];
        $matrix = [
            '=' => [10, true],
            '!=' => [10, false],
            '<>' => [10, false],
            '<' => [11, true],
            '<=' => [10, true],
            '>' => [9, true],
            '>=' => [10, true],
        ];

        foreach ($matrix as $operator => [$operand, $expected]) {
            $condition = new Comparison('n', $operator, $operand);

            $this->assertSame(
                $expected,
                ConditionEvaluator::evaluate($row, $condition),
                "运算符 {$operator} 与 {$operand}",
            );
        }
    }

    public function testNumericLikeValuesCompareNumerically(): void
    {
        $row = ['a' => '10'];

        // '10' 与 9 数值比较
        $this->assertTrue(ConditionEvaluator::evaluate($row, new Comparison('a', '>', 9)));
        $this->assertTrue(ConditionEvaluator::evaluate($row, new Comparison('a', '<=', '10.0')));
        $this->assertFalse(ConditionEvaluator::evaluate($row, new Comparison('a', '>', 11)));
    }

    public function testNonNumericValuesCompareAsString(): void
    {
        $row = ['a' => 'abc'];

        // 'abc' 与 1 按字符串比较：'abc' > '1'
        $this->assertTrue(ConditionEvaluator::evaluate($row, new Comparison('a', '>', 1)));
        $this->assertFalse(ConditionEvaluator::evaluate($row, new Comparison('a', '=', 1)));
    }

    public function testNullColumnValueYieldsFalseEverywhere(): void
    {
        $row = ['a' => null];
        $conditions = [
            new Comparison('a', '=', 1),
            new Comparison('a', '!=', 1),
            new InList('a', [1, 2]),
            new InList('a', [1, 2], true),
            new Between('a', 1, 2),
            new Between('a', 1, 2, true),
            new LikeCondition('a', '%'),
        ];

        foreach ($conditions as $condition) {
            $this->assertFalse(ConditionEvaluator::evaluate($row, $condition), $condition::class);
        }
    }

    public function testNullComparisonValueYieldsFalse(): void
    {
        $row = ['a' => 1];

        $this->assertFalse(ConditionEvaluator::evaluate($row, new Comparison('a', '=', null)));
        $this->assertFalse(ConditionEvaluator::evaluate($row, new Comparison('a', '!=', null)));
    }

    public function testInListSemantics(): void
    {
        $row = ['a' => 2];

        $this->assertTrue(ConditionEvaluator::evaluate($row, new InList('a', [1, 2, 3])));
        // 数值性字符串按数值匹配
        $this->assertTrue(ConditionEvaluator::evaluate($row, new InList('a', ['2'])));
        $this->assertFalse(ConditionEvaluator::evaluate($row, new InList('a', [4])));
        $this->assertTrue(ConditionEvaluator::evaluate($row, new InList('a', [4], true)));
    }

    public function testNullMemberNeverMatches(): void
    {
        // IN：null 成员永不匹配
        $this->assertFalse(ConditionEvaluator::evaluate(['a' => 1], new InList('a', [null, 2])));
        // NOT IN：只看非 null 成员，1 不在 [2] 中 → true
        $this->assertTrue(ConditionEvaluator::evaluate(['a' => 1], new InList('a', [2, null], true)));
    }

    public function testBetweenInclusiveBounds(): void
    {
        $this->assertTrue(ConditionEvaluator::evaluate(['a' => 15], new Between('a', 10, 20)));
        $this->assertTrue(ConditionEvaluator::evaluate(['a' => 10], new Between('a', 10, 20)));
        $this->assertTrue(ConditionEvaluator::evaluate(['a' => 20], new Between('a', 10, 20)));
        $this->assertFalse(ConditionEvaluator::evaluate(['a' => 25], new Between('a', 10, 20)));
        $this->assertTrue(ConditionEvaluator::evaluate(['a' => 25], new Between('a', 10, 20, true)));
        $this->assertFalse(ConditionEvaluator::evaluate(['a' => 15], new Between('a', 10, 20, true)));
    }

    public function testNullCheck(): void
    {
        $this->assertTrue(ConditionEvaluator::evaluate(['a' => null], new NullCheck('a')));
        $this->assertFalse(ConditionEvaluator::evaluate(['a' => null], new NullCheck('a', true)));
        $this->assertFalse(ConditionEvaluator::evaluate(['a' => 1], new NullCheck('a')));
        $this->assertTrue(ConditionEvaluator::evaluate(['a' => 1], new NullCheck('a', true)));
    }

    public function testLikeWildcardsAndEscapes(): void
    {
        $match = static fn (string $pattern, ?string $value): bool =>
            ConditionEvaluator::evaluate(['v' => $value], new LikeCondition('v', $pattern));

        // % 任意串
        $this->assertTrue($match('Hello%', 'Hello World'));
        // 大小写敏感
        $this->assertFalse($match('Hello%', 'hello world'));
        // _ 单字符
        $this->assertTrue($match('H_llo', 'Hello'));
        $this->assertFalse($match('H_llo', 'Helloo'));
        // 夹层通配
        $this->assertTrue($match('%o%ld%', 'World'));
        // \% 转义为字面 %
        $this->assertTrue($match('100\%', '100%'));
        $this->assertFalse($match('100\%', '1000'));
        // \_ 转义为字面 _
        $this->assertTrue($match('a\_b', 'a_b'));
        $this->assertFalse($match('a\_b', 'axb'));
        // \\ 转义为字面反斜杠
        $this->assertTrue($match('a\\b', 'a\b'));
        // 空模式只匹配空串
        $this->assertTrue($match('', ''));
        $this->assertFalse($match('', 'x'));
    }

    public function testQualifiedColumnExactMatchWins(): void
    {
        $row = ['u.age' => 5, 'name' => 'x'];

        // 精确命中
        $this->assertTrue(ConditionEvaluator::evaluate($row, new Comparison('u.age', '=', 5)));
        // 同时存在 age 与 u.age 时精确优先
        $both = ['age' => 1, 'u.age' => 5];
        $this->assertTrue(ConditionEvaluator::evaluate($both, new Comparison('age', '=', 1)));
    }

    public function testUnqualifiedColumnResolvesByUniqueSuffix(): void
    {
        $row = ['u.age' => 5, 'name' => 'x'];

        $this->assertTrue(ConditionEvaluator::evaluate($row, new Comparison('age', '=', 5)));
    }

    public function testAmbiguousColumnThrowsWithCandidates(): void
    {
        $row = ['u.age' => 1, 'o.age' => 2];

        try {
            ConditionEvaluator::evaluate($row, new Comparison('age', '=', 1));
            $this->fail('应抛出列名歧义异常');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('age', $exception->getMessage());
            $this->assertStringContainsString('u.age', $exception->getMessage());
            $this->assertStringContainsString('o.age', $exception->getMessage());
        }
    }

    public function testUnknownColumnThrows(): void
    {
        $this->expectException(QueryException::class);

        ConditionEvaluator::evaluate(['id' => 1], new Comparison('missing', '=', 1));
    }

    public function testWhereDefaultsToEquality(): void
    {
        $group = new ConditionGroup();
        $group->where('id', 7);

        $this->assertTrue(ConditionEvaluator::evaluate(['id' => 7], $group));
        $this->assertFalse(ConditionEvaluator::evaluate(['id' => 8], $group));
    }

    public function testLeftToRightAndOrMix(): void
    {
        // ((a = 1 OR b = 2) AND c = 3)
        $group = new ConditionGroup();
        $group->where('a', 1)->orWhere('b', 2)->where('c', 3);

        $this->assertTrue(ConditionEvaluator::evaluate(['a' => 1, 'b' => 9, 'c' => 3], $group));
        $this->assertTrue(ConditionEvaluator::evaluate(['a' => 9, 'b' => 2, 'c' => 3], $group));
        $this->assertFalse(ConditionEvaluator::evaluate(['a' => 1, 'b' => 9, 'c' => 0], $group));
        $this->assertFalse(ConditionEvaluator::evaluate(['a' => 9, 'b' => 9, 'c' => 3], $group));
    }

    public function testNestedGroupComposition(): void
    {
        $inner = new ConditionGroup();
        $inner->where('a', '=', 1)->orWhere('b', '=', 2);

        $outer = new ConditionGroup();
        $outer->where('c', '=', 3)->add($inner, 'AND');

        $this->assertTrue(ConditionEvaluator::evaluate(['a' => 1, 'b' => 9, 'c' => 3], $outer));
        $this->assertTrue(ConditionEvaluator::evaluate(['a' => 9, 'b' => 2, 'c' => 3], $outer));
        $this->assertFalse(ConditionEvaluator::evaluate(['a' => 9, 'b' => 9, 'c' => 3], $outer));
        $this->assertFalse(ConditionEvaluator::evaluate(['a' => 1, 'b' => 9, 'c' => 0], $outer));
    }
}
