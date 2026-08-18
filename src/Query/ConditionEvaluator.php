<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Query\Condition\Between;
use Kingbes\Psql\Query\Condition\BooleanConst;
use Kingbes\Psql\Query\Condition\Comparison;
use Kingbes\Psql\Query\Condition\Condition;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Query\Condition\ExistsCheck;
use Kingbes\Psql\Query\Condition\InList;
use Kingbes\Psql\Query\Condition\LikeCondition;
use Kingbes\Psql\Query\Condition\NullCheck;
use Kingbes\Psql\Query\Condition\ScalarSubquery;
use Kingbes\Psql\Query\Condition\SubqueryIn;

/**
 * 条件求值器：对关联数组行按条件语义过滤
 */
final class ConditionEvaluator
{
    /** 纯数字形式：可选符号 + 整数/小数 */
    private const NUMERIC_PATTERN = '/^[+-]?(\d+(\.\d*)?|\.\d+)$/';

    /**
     * 求值入口：按条件类型分派
     *
     * @param array<string, true>|null $collations CI 列映射（裸列名 / 'alias.列名' => true），null 全区分大小写
     */
    public static function evaluate(array $row, Condition $condition, ?array $collations = null): bool
    {
        if ($condition instanceof ConditionGroup) {
            return self::evaluateGroup($row, $condition, $collations);
        }
        if ($condition instanceof Comparison) {
            return self::evaluateComparison($row, $condition, $collations);
        }
        if ($condition instanceof InList) {
            return self::evaluateInList($row, $condition, $collations);
        }
        if ($condition instanceof Between) {
            return self::evaluateBetween($row, $condition, $collations);
        }
        if ($condition instanceof NullCheck) {
            return self::evaluateNullCheck($row, $condition);
        }
        if ($condition instanceof LikeCondition) {
            return self::evaluateLike($row, $condition, $collations);
        }
        if ($condition instanceof BooleanConst) {
            // 解析后的常量真值（EXISTS 化简产物）
            return $condition->value;
        }
        if ($condition instanceof SubqueryIn || $condition instanceof ExistsCheck || $condition instanceof ScalarSubquery) {
            // 原始子查询条件禁止直接求值（必须先经 SubqueryResolver 解析，绝不静默求值）
            throw new QueryException('子查询条件必须先经 SubqueryResolver 解析');
        }

        throw new QueryException('不支持的条件类型: ' . $condition::class);
    }

    /**
     * 通用值比较：双侧均为数值性（int/float/纯数字字符串）按数值比较，否则按字符串；任一为 null 恒 false
     *
     * 供 JOIN/聚合/HAVING/外键存在性检查复用；ci=true 时字符串侧折叠后比较（数值性判定不受影响）
     */
    public static function compareValues(mixed $left, string $operator, mixed $right, bool $ci = false): bool
    {
        if ($left === null || $right === null) {
            return false;
        }

        return match ($operator) {
            '=' => self::compare($left, $right, $ci) === 0,
            '!=', '<>' => self::compare($left, $right, $ci) !== 0,
            '<' => self::compare($left, $right, $ci) < 0,
            '<=' => self::compare($left, $right, $ci) <= 0,
            '>' => self::compare($left, $right, $ci) > 0,
            '>=' => self::compare($left, $right, $ci) >= 0,
            default => throw new QueryException("非法比较运算符: {$operator}"),
        };
    }

    /**
     * 列名 collation 解析：裸列名直接查映射；限定名 'a.col' 先查全名、未命中剥离前缀查 'col'；
     * 未命中（含映射为 null）一律视为区分大小写
     *
     * @param array<string, true>|null $collations
     */
    public static function resolveCI(?array $collations, string $column): bool
    {
        if ($collations === null) {
            return false;
        }
        if (($collations[$column] ?? false) === true) {
            return true;
        }
        $pos = strrpos($column, '.');
        if ($pos !== false) {
            $short = substr($column, $pos + 1);
            if (($collations[$short] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * CI 折叠：仅字符串值转小写（mbstring 优先，无 mbstring 退化 strtolower），非字符串原样返回
     */
    private static function ciFold(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }

    /**
     * 条件组：自左向右 AND/OR 折叠；空组恒真；collations 递归透传给子条件
     *
     * @param array<string, true>|null $collations
     */
    private static function evaluateGroup(array $row, ConditionGroup $group, ?array $collations = null): bool
    {
        $conditions = $group->conditions;
        if ($conditions === []) {
            return true;
        }

        $result = self::evaluate($row, $conditions[0], $collations);
        $count = count($conditions);
        for ($i = 1; $i < $count; $i++) {
            $next = self::evaluate($row, $conditions[$i], $collations);
            $connector = $group->connectors[$i - 1] ?? 'AND';
            $result = $connector === 'OR' ? ($result || $next) : ($result && $next);
        }

        return $result;
    }

    /**
     * 比较条件：任一侧为 null 视为未知（false）；列 CI 时字符串侧折叠后比较；
     * 值可为 ProjectionExpression（列引用），在行上求值
     *
     * @param array<string, true>|null $collations
     */
    private static function evaluateComparison(array $row, Comparison $condition, ?array $collations = null): bool
    {
        $value = self::resolveValue($row, $condition->column);
        $right = $condition->value instanceof ProjectionExpression
            ? $condition->value->evaluate($row)
            : $condition->value;
        if ($value === null || $right === null) {
            return false;
        }

        $cmp = self::compare($value, $right, self::resolveCI($collations, $condition->column));

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
     * IN / NOT IN：列值为 null 恒 false；null 成员永不匹配；NOT IN 只看非 null 成员；列 CI 折叠比较
     *
     * @param array<string, true>|null $collations
     */
    private static function evaluateInList(array $row, InList $condition, ?array $collations = null): bool
    {
        $value = self::resolveValue($row, $condition->column);
        if ($value === null) {
            return false;
        }

        $ci = self::resolveCI($collations, $condition->column);
        $matched = false;
        foreach ($condition->values as $member) {
            if ($member === null) {
                continue;
            }
            if (self::compare($value, $member, $ci) === 0) {
                $matched = true;
                break;
            }
        }

        return $condition->negate ? !$matched : $matched;
    }

    /**
     * BETWEEN（闭区间）：任一侧为 null 恒 false；列 CI 时字符串范围折叠后比较
     *
     * @param array<string, true>|null $collations
     */
    private static function evaluateBetween(array $row, Between $condition, ?array $collations = null): bool
    {
        $value = self::resolveValue($row, $condition->column);
        if ($value === null || $condition->min === null || $condition->max === null) {
            return false;
        }

        $ci = self::resolveCI($collations, $condition->column);
        $inside = self::compare($value, $condition->min, $ci) >= 0
            && self::compare($value, $condition->max, $ci) <= 0;

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
     * LIKE：列值为 null 恒 false，否则按锚定正则匹配；列 CI 时值与模式都折叠后匹配
     * （通配符 % _ \ 为 ASCII，不受 lower 影响）
     *
     * @param array<string, true>|null $collations
     */
    private static function evaluateLike(array $row, LikeCondition $condition, ?array $collations = null): bool
    {
        $value = self::resolveValue($row, $condition->column);
        if ($value === null) {
            return false;
        }

        $subject = (string) $value;
        $pattern = $condition->pattern;
        if (self::resolveCI($collations, $condition->column)) {
            $subject = (string) self::ciFold($subject);
            $pattern = (string) self::ciFold($pattern);
        }

        return preg_match(self::likeRegex($pattern), $subject) === 1;
    }

    /**
     * 列取值解析：先精确命中，否则按 ".列名" 后缀唯一匹配
     */
    private static function resolveValue(array $row, string $column): mixed
    {
        return self::columnValue($row, $column);
    }

    /**
     * 取行内列值：精确键优先，其后缀唯一匹配；未知/歧义抛 QueryException（供表达式求值复用）
     */
    public static function columnValue(array $row, string $column): mixed
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
     * 比较规则：双侧均为数值性按数值比较（ci 不影响数值性判定），否则按字符串比较；
     * ci=true 时字符串侧先折叠（仅 is_string 值），非字符串值原样强转比较；
     * 超大整数字符串（float 精度不足）走 ValueCaster::compareNumeric 精确比较
     */
    private static function compare(mixed $left, mixed $right, bool $ci = false): int
    {
        if (self::isNumeric($left) && self::isNumeric($right)) {
            return \Kingbes\Psql\Type\ValueCaster::compareNumeric($left, $right);
        }
        if ($ci) {
            return (string) self::ciFold($left) <=> (string) self::ciFold($right);
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
