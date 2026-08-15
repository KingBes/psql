<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

/**
 * 聚合表达式静态工厂（不可实例化）
 *
 * 注：规范原名 Fn 与 PHP 保留字 fn 冲突（关键字大小写不敏感），故命名 Agg
 */
final class Agg
{
    private function __construct()
    {
    }

    public static function count(string $column = '*'): AggregateExpression
    {
        return new AggregateExpression('COUNT', $column);
    }

    public static function sum(string $column): AggregateExpression
    {
        return new AggregateExpression('SUM', $column);
    }

    public static function avg(string $column): AggregateExpression
    {
        return new AggregateExpression('AVG', $column);
    }

    public static function min(string $column): AggregateExpression
    {
        return new AggregateExpression('MIN', $column);
    }

    public static function max(string $column): AggregateExpression
    {
        return new AggregateExpression('MAX', $column);
    }
}
