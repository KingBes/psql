<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Query\Condition\Between;
use Kingbes\Psql\Query\Condition\Comparison;
use Kingbes\Psql\Query\Condition\Condition;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Query\Condition\InList;
use Kingbes\Psql\Query\Condition\LikeCondition;
use Kingbes\Psql\Query\Condition\NullCheck;

/**
 * 条件求值器：对关联数组行按条件语义过滤
 */
final class ConditionEvaluator
{
    /** 纯数字形式：可选符号 + 整数/小数 */
    private const NUMERIC_PATTERN = '/^[+-]?(\d+(\.\d*)?|\.\d+)$/';

    /**
     * 求值入口：按条件类型分派
     */
    public static function evaluate(array $row, Condition $condition): bool
    {
        if ($condition instanceof ConditionGroup) {
            return self::evaluateGroup($row, $condition);
        }
        if ($condition instanceof Comparison) {
            return self::evaluateComparison($row, $condition);
        }
        if ($condition instanceof InList) {
            return self::evaluateInList($row, $condition);
        }
        if ($condition instanceof Between) {
            return self::evaluateBetween($row, $condition);
        }
        if ($condition instanceof NullCheck) {
            return self::evaluateNullCheck($row, $condition);
        }
        if ($condition instanceof LikeCondition) {
            return self::evaluateLike($row, $condition);
        }

        throw new QueryException('不支持的条件类型: ' . $condition::class);
    }

    /**
     * 通用值比较：双侧均为数值性（int/float/纯数字字符串）按数值比较，否则按字符串；任一为 null 恒 false
     *
     * 供 JOIN/聚合/HAVING/外键存在性检查复用
     */
    public static function compareValues(mixed $left, string $operator, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }

        return match ($operator) {
            '=' => self::compare($left, $right) === 0,
            '!=', '<>' => self::compare($left, $right) !== 0,
            '<' => self::compare($left, $right) < 0,
            '<=' => self::compare($left, $right) <= 0,
            '>' => self::compare($left, $right) > 0,
            '>=' => self::compare($left, $right) >= 0,
            default => throw new QueryException("非法比较运算符: {$operator}"),
        };
    }

    /**
     * 条件组：自左向右 AND/OR 折叠；空组恒真
     */
    private static function evaluateGroup(array $row, ConditionGroup $group): bool
    {
        $conditions = $group->conditions;
        if ($conditions === []) {
            return true;
        }

        $result = self::evaluate($row, $conditions[0]);
        $count = count($conditions);
        for ($i = 1; $i < $count; $i++) {
            $next = self::evaluate($row, $conditions[$i]);
            $connector = $group->connectors[$i - 1] ?? 'AND';
            $result = $connector === 'OR' ? ($result || $next) : ($result && $next);
        }

        return $result;
    }

    /**
     * 比较条件：任一侧为 null 视为未知（false）
     */
    private static function evaluateComparison(array $row, Comparison $condition): bool
    {
        $value = self::resolveValue($row, $condition->column);
        if ($value === null || $condition->value === null) {
            return false;
        }

        $cmp = self::compare($value, $condition->value);

        return match ($condition->operator) {
            '=' => $cmp === 0,
            '!=', '<>' => $cmp !== 0,
            '<' => $cmp < 0,
            '<=' => $cmp <= 0,
            '>' => $cmp > 0,
            '>=' => $cmp >= 0,
            default => throw new QueryException("非法比较运算符: {$condition->operator}"),
        };
    }

    /**
     * IN / NOT IN：列值为 null 恒 false；null 成员永不匹配；NOT IN 只看非 null 成员
     */
    private static function evaluateInList(array $row, InList $condition): bool
    {
        $value = self::resolveValue($row, $condition->column);
        if ($value === null) {
            return false;
        }

        $matched = false;
        foreach ($condition->values as $member) {
            if ($member === null) {
                continue;
            }
            if (self::compare($value, $member) === 0) {
                $matched = true;
                break;
            }
        }

        return $condition->negate ? !$matched : $matched;
    }

    /**
     * BETWEEN（闭区间）：任一侧为 null 恒 false
     */
    private static function evaluateBetween(array $row, Between $condition): bool
    {
        $value = self::resolveValue($row, $condition->column);
        if ($value === null || $condition->min === null || $condition->max === null) {
            return false;
        }

        $inside = self::compare($value, $condition->min) >= 0
            && self::compare($value, $condition->max) <= 0;

        return $condition->negate ? !$inside : $inside;
    }

    /**
     * IS NULL / IS NOT NULL
     */
    private static function evaluateNullCheck(array $row, NullCheck $condition): bool
    {
        $value = self::resolveValue($row, $condition->column);

        return $condition->negate ? $value !== null : $value === null;
    }

    /**
     * LIKE：列值为 null 恒 false，否则按锚定正则匹配（大小写敏感）
     */
    private static function evaluateLike(array $row, LikeCondition $condition): bool
    {
        $value = self::resolveValue($row, $condition->column);
        if ($value === null) {
            return false;
        }

        return preg_match(self::likeRegex($condition->pattern), (string) $value) === 1;
    }

    /**
     * 列取值解析：先精确命中，否则按 ".列名" 后缀唯一匹配
     */
    private static function resolveValue(array $row, string $column): mixed
    {
        if (array_key_exists($column, $row)) {
            return $row[$column];
        }

        $candidates = [];
        foreach ($row as $key => $_) {
            if (is_string($key) && str_ends_with($key, '.' . $column)) {
                $candidates[] = $key;
            }
        }

        if ($candidates === []) {
            throw new QueryException("未知列: {$column}");
        }
        if (count($candidates) > 1) {
            throw new QueryException(
                '列名歧义: ' . $column . '，候选: ' . implode(', ', $candidates),
            );
        }

        return $row[$candidates[0]];
    }

    /**
     * 比较规则：双侧均为数值性按数值比较，否则按字符串比较
     */
    private static function compare(mixed $left, mixed $right): int
    {
        if (self::isNumeric($left) && self::isNumeric($right)) {
            return (float) $left <=> (float) $right;
        }

        return (string) $left <=> (string) $right;
    }

    /**
     * 是否数值性：int/float 或纯数字字符串
     */
    private static function isNumeric(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }

        return is_string($value) && preg_match(self::NUMERIC_PATTERN, $value) === 1;
    }

    /**
     * LIKE 模式转锚定正则：%=任意串、_=单字符、\ 转义 % _ \
     */
    private static function likeRegex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $next = $pattern[$i + 1];
                if ($next === '%' || $next === '_' || $next === '\\') {
                    $regex .= preg_quote($next, '/');
                    $i++;
                    continue;
                }
            }
            if ($char === '%') {
                $regex .= '.*';
                continue;
            }
            if ($char === '_') {
                $regex .= '.';
                continue;
            }
            $regex .= preg_quote($char, '/');
        }

        return '/^' . $regex . '$/s';
    }
}
