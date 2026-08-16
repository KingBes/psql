<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Condition\LikeCondition;
use Kingbes\Psql\Query\ConditionEvaluator;
use Kingbes\Psql\Query\Like;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * Like::escape 转义矩阵与 whereLike 字面量匹配端到端测试
 */
final class LikeTest extends TestCase
{
    public function testEscapeMatrix(): void
    {
        $this->assertSame('100\\%', Like::escape('100%'));
        $this->assertSame('a\\_b', Like::escape('a_b'));
        $this->assertSame('a\\\\b', Like::escape('a\\b'));
        $this->assertSame('50\\%\\_of\\\\x', Like::escape('50%_of\\x'));
        $this->assertSame('', Like::escape(''));
        $this->assertSame('plain', Like::escape('plain'));
    }

    public function testEscapedPatternMatchesLiteralOnlyViaEvaluator(): void
    {
        // % 字面量：模式含 escape('a%b') 时只命中字面含 a%b 的行
        $percent = new LikeCondition('title', '%' . Like::escape('a%b') . '%');
        $this->assertTrue(ConditionEvaluator::evaluate(['title' => 'a%b'], $percent));
        $this->assertTrue(ConditionEvaluator::evaluate(['title' => 'xa%by'], $percent));
        $this->assertFalse(ConditionEvaluator::evaluate(['title' => 'axb'], $percent));

        // _ 字面量：不转义时 _ 是单字符通配，转义后只匹配字面下划线
        $underscore = new LikeCondition('title', Like::escape('a_b'));
        $this->assertTrue(ConditionEvaluator::evaluate(['title' => 'a_b'], $underscore));
        $this->assertFalse(ConditionEvaluator::evaluate(['title' => 'axb'], $underscore));

        // 反证：未转义模式下通配语义生效（axb 命中 a%b）
        $wildcard = new LikeCondition('title', 'a%b');
        $this->assertTrue(ConditionEvaluator::evaluate(['title' => 'axb'], $wildcard));
    }

    public function testWhereLikeWithEscapedPatternEndToEnd(): void
    {
        $conn = Psql::memory();
        $conn->createTable('posts', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('title', 64)->notNull();
        });
        $conn->table('posts')->insertMany([
            ['title' => 'a%b'],
            ['title' => 'axb'],
            ['title' => 'a_b'],
            ['title' => 'acb'],
            ['title' => 'xa%by'],
        ]);

        // % 字面量：只命中字面含 a%b 的两行，axb 不命中
        $titles = $conn->table('posts')
            ->whereLike('title', '%' . Like::escape('a%b') . '%')
            ->get()
            ->pluck('title');
        $this->assertSame(['a%b', 'xa%by'], $titles);

        // _ 字面量：精确匹配 a_b，acb 不命中
        $exact = $conn->table('posts')
            ->whereLike('title', Like::escape('a_b'))
            ->get()
            ->pluck('title');
        $this->assertSame(['a_b'], $exact);

        // \ 字面量：插入含反斜杠数据并按字面量命中
        $conn->table('posts')->insertMany([
            ['title' => 'a\\b'],
            ['title' => 'a\\%b'],
        ]);
        $backslash = $conn->table('posts')
            ->whereLike('title', Like::escape('a\\b'))
            ->get()
            ->pluck('title');
        $this->assertSame(['a\\b'], $backslash);
    }
}
