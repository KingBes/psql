<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

use Kingbes\Psql\Exception\QueryException;

/**
 * 标量函数静态工厂（不可实例化，参照 Agg 风格）
 */
final class Func
{
    private function __construct()
    {
    }

    /**
     * 列引用（供函数/CASE 嵌套取行内列值）
     */
    public static function col(string $column): ColumnRef
    {
        return new ColumnRef($column);
    }

    /**
     * 窗口函数工厂：ROW_NUMBER() OVER (...)
     */
    public static function rowNumber(): WindowExpression
    {
        return WindowExpression::rowNumber();
    }

    /**
     * 窗口函数工厂：RANK() OVER (...)
     */
    public static function rank(): WindowExpression
    {
        return WindowExpression::rank();
    }

    /**
     * 窗口函数工厂：DENSE_RANK() OVER (...)
     */
    public static function denseRank(): WindowExpression
    {
        return WindowExpression::denseRank();
    }

    public static function upper(string|int|float|bool|null|ProjectionExpression $arg): FuncExpression
    {
        return new FuncExpression('UPPER', [$arg]);
    }

    public static function lower(string|int|float|bool|null|ProjectionExpression $arg): FuncExpression
    {
        return new FuncExpression('LOWER', [$arg]);
    }

    public static function length(string|int|float|bool|null|ProjectionExpression $arg): FuncExpression
    {
        return new FuncExpression('LENGTH', [$arg]);
    }

    public static function trim(string|int|float|bool|null|ProjectionExpression $arg): FuncExpression
    {
        return new FuncExpression('TRIM', [$arg]);
    }

    public static function ltrim(string|int|float|bool|null|ProjectionExpression $arg): FuncExpression
    {
        return new FuncExpression('LTRIM', [$arg]);
    }

    public static function rtrim(string|int|float|bool|null|ProjectionExpression $arg): FuncExpression
    {
        return new FuncExpression('RTRIM', [$arg]);
    }

    /**
     * 子串截取（1 基）；len 缺省/null 截到串尾
     */
    public static function substr(
        string|int|float|bool|null|ProjectionExpression $arg,
        int $pos,
        ?int $len = null,
    ): FuncExpression {
        return new FuncExpression('SUBSTR', $len === null ? [$arg, $pos] : [$arg, $pos, $len]);
    }

    /**
     * 字符串拼接（至少 1 个参数）
     */
    public static function concat(string|int|float|bool|null|ProjectionExpression ...$args): FuncExpression
    {
        if (func_num_args() < 1) {
            throw new QueryException('CONCAT 至少需要 1 个参数');
        }

        return new FuncExpression('CONCAT', array_values($args));
    }

    /**
     * 子串替换：subject 中出现的 search 全部替换为 replace
     */
    public static function replace(
        string|int|float|bool|null|ProjectionExpression $subject,
        string|int|float|bool|null|ProjectionExpression $search,
        string|int|float|bool|null|ProjectionExpression $replace,
    ): FuncExpression {
        return new FuncExpression('REPLACE', [$subject, $search, $replace]);
    }

    public static function abs(string|int|float|bool|null|ProjectionExpression $arg): FuncExpression
    {
        return new FuncExpression('ABS', [$arg]);
    }

    public static function round(
        string|int|float|bool|null|ProjectionExpression $arg,
        int $digits = 0,
    ): FuncExpression {
        return new FuncExpression('ROUND', [$arg, $digits]);
    }

    public static function floor(string|int|float|bool|null|ProjectionExpression $arg): FuncExpression
    {
        return new FuncExpression('FLOOR', [$arg]);
    }

    public static function ceil(string|int|float|bool|null|ProjectionExpression $arg): FuncExpression
    {
        return new FuncExpression('CEIL', [$arg]);
    }

    public static function year(string|int|float|bool|null|ProjectionExpression $arg): FuncExpression
    {
        return new FuncExpression('YEAR', [$arg]);
    }

    public static function month(string|int|float|bool|null|ProjectionExpression $arg): FuncExpression
    {
        return new FuncExpression('MONTH', [$arg]);
    }

    public static function day(string|int|float|bool|null|ProjectionExpression $arg): FuncExpression
    {
        return new FuncExpression('DAY', [$arg]);
    }

    /**
     * 第一个非 null 参数（至少 1 个参数，全 null 返回 null）
     */
    public static function coalesce(
        string|int|float|bool|null|ProjectionExpression ...$args
    ): FuncExpression {
        if (func_num_args() < 1) {
            throw new QueryException('COALESCE 至少需要 1 个参数');
        }

        return new FuncExpression('COALESCE', array_values($args));
    }

    /**
     * a = b 时返回 null，否则返回 a
     */
    public static function nullif(
        string|int|float|bool|null|ProjectionExpression $a,
        string|int|float|bool|null|ProjectionExpression $b,
    ): FuncExpression {
        return new FuncExpression('NULLIF', [$a, $b]);
    }
}
