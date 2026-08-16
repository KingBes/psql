<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

use Kingbes\Psql\Exception\QueryException;

/**
 * 标量函数表达式：UPPER/LOWER/LENGTH/TRIM/LTRIM/RTRIM/SUBSTR/REPLACE/
 * ABS/ROUND/FLOOR/CEIL/YEAR/MONTH/DAY/CONCAT/COALESCE/NULLIF
 *
 * SQL NULL 传播语义：除 COALESCE/NULLIF 外，任一参数为 null 时结果为 null
 */
final class FuncExpression implements ProjectionExpression
{
    /** 函数白名单（构造器归一为大写后精确校验） */
    private const FUNCTIONS = [
        'UPPER', 'LOWER', 'LENGTH', 'TRIM', 'LTRIM', 'RTRIM', 'SUBSTR', 'REPLACE',
        'ABS', 'ROUND', 'FLOOR', 'CEIL', 'YEAR', 'MONTH', 'DAY',
        'CONCAT', 'COALESCE', 'NULLIF',
    ];

    /** 纯数字形式：可选符号 + 整数/小数 */
    private const NUMERIC_PATTERN = '/^[+-]?(\d+(\.\d*)?|\.\d+)$/';

    /** 日期形式：Y-m-d 或 Y-m-d H:i:s */
    private const DATETIME_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})(?: \d{2}:\d{2}:\d{2})?$/';

    private ?string $alias = null;

    /**
     * @param string $function 函数名（大小写不敏感，限白名单）
     * @param list<scalar|null|ProjectionExpression> $args 参数（仅标量/null/投影表达式）
     */
    public function __construct(
        private string $function,
        private array $args = [],
        ?string $alias = null,
    ) {
        $function = strtoupper($function);
        if (!in_array($function, self::FUNCTIONS, true)) {
            throw new QueryException('非法函数，仅支持: ' . implode('/', self::FUNCTIONS));
        }
        foreach ($args as $arg) {
            if ($arg !== null && !is_scalar($arg) && !$arg instanceof ProjectionExpression) {
                throw new QueryException(
                    '函数参数仅允许标量/null/投影表达式: ' . $function,
                );
            }
        }
        $this->function = $function;
        $this->args = $args;
        $this->alias = $alias;
    }

    /**
     * 返回带别名的新实例（不可变，参照 AggregateExpression::as）
     */
    public function as(string $alias): self
    {
        return new self($this->function, $this->args, $alias);
    }

    /**
     * 输出列名：函数名大写 + '(' + 参数表示 + ')'，标量用 var_export、表达式用其 outputName
     */
    public function outputName(): string
    {
        $parts = [];
        foreach ($this->args as $arg) {
            $parts[] = $arg instanceof ProjectionExpression
                ? $arg->outputName()
                : var_export($arg, true);
        }

        return $this->function . '(' . implode(', ', $parts) . ')';
    }

    /**
     * 显式别名，未设置返回 null
     */
    public function alias(): ?string
    {
        return $this->alias;
    }

    /**
     * 递归求值参数（标量直通/表达式递归）后分发函数实现
     */
    public function evaluate(array $row): mixed
    {
        $args = [];
        foreach ($this->args as $arg) {
            $args[] = $arg instanceof ProjectionExpression ? $arg->evaluate($row) : $arg;
        }

        return $this->dispatch($this->function, $args);
    }

    /**
     * 函数实现分发（按 SQL 语义处理 NULL 传播与参数数量）
     *
     * @param list<mixed> $args 已求值参数
     */
    private function dispatch(string $function, array $args): mixed
    {
        // COALESCE / NULLIF 自行处理 null，不参与统一传播
        if ($function === 'COALESCE') {
            $this->assertCount($function, $args, 1, null);
            foreach ($args as $arg) {
                if ($arg !== null) {
                    return $arg;
                }
            }

            return null;
        }
        if ($function === 'NULLIF') {
            $this->assertCount($function, $args, 2, 2);
            [$a, $b] = $args;
            if ($a === null) {
                return null;
            }

            return ConditionEvaluator::compareValues($a, '=', $b) ? null : $a;
        }

        // 统一 NULL 传播：任一参数 null → null
        foreach ($args as $arg) {
            if ($arg === null) {
                return null;
            }
        }

        return match ($function) {
            'UPPER' => $this->upper((string) $this->single($function, $args)),
            'LOWER' => $this->lower((string) $this->single($function, $args)),
            'LENGTH' => $this->length((string) $this->single($function, $args)),
            'TRIM' => trim((string) $this->single($function, $args)),
            'LTRIM' => ltrim((string) $this->single($function, $args)),
            'RTRIM' => rtrim((string) $this->single($function, $args)),
            'SUBSTR' => $this->substr($function, $args),
            'REPLACE' => str_replace(
                (string) $this->at($function, $args, 1),
                (string) $this->at($function, $args, 2),
                (string) $this->at($function, $args, 0),
            ),
            'ABS' => abs($this->toNumber($function, $this->single($function, $args))),
            'ROUND' => round(
                $this->toNumber($function, $this->at($function, $args, 0)),
                (int) $this->toNumber($function, $this->at($function, $args, 1) ?? 0),
            ),
            'FLOOR' => floor($this->toNumber($function, $this->single($function, $args))),
            'CEIL' => ceil($this->toNumber($function, $this->single($function, $args))),
            'YEAR' => $this->datePart($function, (string) $this->single($function, $args), 1),
            'MONTH' => $this->datePart($function, (string) $this->single($function, $args), 2),
            'DAY' => $this->datePart($function, (string) $this->single($function, $args), 3),
            'CONCAT' => $this->concat($function, $args),
            default => throw new QueryException("不支持的函数: {$function}"),
        };
    }

    /**
     * CONCAT：参数转 string 拼接（bool→'1'/'0'，int/float 常规强转）
     *
     * @param list<mixed> $args
     */
    private function concat(string $function, array $args): string
    {
        $this->assertCount($function, $args, 1, null);
        $result = '';
        foreach ($args as $arg) {
            $result .= is_bool($arg) ? ($arg ? '1' : '0') : (string) $arg;
        }

        return $result;
    }

    /**
     * SUBSTR(s, pos, len?)：1 基；pos>=1、len>=0，违反抛 QueryException；len 缺省到串尾
     *
     * @param list<mixed> $args
     */
    private function substr(string $function, array $args): string
    {
        $this->assertCount($function, $args, 2, 3);
        $string = (string) $args[0];
        $pos = (int) $this->toNumber($function, $args[1]);
        $len = array_key_exists(2, $args) ? (int) $this->toNumber($function, $args[2]) : null;
        if ($pos < 1) {
            throw new QueryException("{$function} 起始位置必须 >= 1: {$pos}");
        }
        if ($len !== null && $len < 0) {
            throw new QueryException("{$function} 截取长度必须 >= 0: {$len}");
        }

        return $this->mbAvailable()
            ? ($len === null ? mb_substr($string, $pos - 1) : mb_substr($string, $pos - 1, $len))
            : ($len === null ? substr($string, $pos - 1) : substr($string, $pos - 1, $len));
    }

    /**
     * YEAR/MONTH/DAY：对 'Y-m-d H:i:s' 或 'Y-m-d' 取日期部分；格式不合法抛 QueryException
     *
     * @param int $part 1=年 2=月 3=日
     */
    private function datePart(string $function, string $value, int $part): int
    {
        if (preg_match(self::DATETIME_PATTERN, $value, $match) !== 1) {
            throw new QueryException("{$function} 日期格式非法（应为 Y-m-d 或 Y-m-d H:i:s）: {$value}");
        }
        [$year, $month, $day] = [(int) $match[1], (int) $match[2], (int) $match[3]];
        if (!checkdate($month, $day, $year)) {
            throw new QueryException("{$function} 日期非法: {$value}");
        }

        return [$year, $month, $day][$part - 1];
    }

    /**
     * 数值转换：int/float 直通，纯数字字符串转数值（含小数点转 float 否则 int）；非数值性抛 QueryException
     *
     * @return int|float
     */
    private function toNumber(string $function, mixed $value)
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value) && preg_match(self::NUMERIC_PATTERN, $value) === 1) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        throw new QueryException(
            "{$function} 参数必须为数值: " . var_export($value, true),
        );
    }

    /**
     * 大写转换（mb 优先，缺扩展退化 strtolower 系）
     */
    private function upper(string $value): string
    {
        return $this->mbAvailable() ? mb_strtoupper($value) : strtoupper($value);
    }

    /**
     * 小写转换（mb 优先，缺扩展退化）
     */
    private function lower(string $value): string
    {
        return $this->mbAvailable() ? mb_strtolower($value) : strtolower($value);
    }

    /**
     * 字符串长度（mb 优先，缺扩展退化）
     */
    private function length(string $value): int
    {
        return $this->mbAvailable() ? mb_strlen($value) : strlen($value);
    }

    /**
     * 是否可用 mbstring 扩展
     */
    private function mbAvailable(): bool
    {
        return function_exists('mb_strlen');
    }

    /**
     * 取单参数函数的唯一参数；数量不符抛 QueryException
     *
     * @param list<mixed> $args
     */
    private function single(string $function, array $args): mixed
    {
        $this->assertCount($function, $args, 1, 1);

        return $args[0];
    }

    /**
     * 取指定位置参数（供多参函数）；越界返回 null
     *
     * @param list<mixed> $args
     */
    private function at(string $function, array $args, int $index): mixed
    {
        return $args[$index] ?? null;
    }

    /**
     * 参数数量校验（闭区间，max null 表示无上限）
     *
     * @param list<mixed> $args
     */
    private function assertCount(string $function, array $args, int $min, ?int $max): void
    {
        $count = count($args);
        if ($count < $min || ($max !== null && $count > $max)) {
            throw new QueryException("{$function} 参数数量非法: {$count}");
        }
    }
}
