<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Query\Condition\Comparison;
use Kingbes\Psql\Query\ConditionEvaluator;

/**
 * CASE WHEN 条件表达式：依序求值 when 条件，命中取对应 then 值，全不中取 else 值
 */
final class CaseWhen implements ProjectionExpression
{
    /** @var list<array{condition: Comparison, value: mixed}> when/then 对（按声明序） */
    private array $whens = [];

    private mixed $elseValue = null;

    private bool $hasElse = false;

    private ?string $alias = null;

    /** 最近一次 when 且尚未 then 的条件（状态机标记） */
    private ?Comparison $pending = null;

    /**
     * 起始分支链
     */
    public static function make(): self
    {
        return new self();
    }

    /**
     * 追加 when 条件；参数形式同 where：(列, 值) 等值 / (列, 运算符, 值)
     *
     * 未 then 连续 when 抛 QueryException
     */
    public function when(string $column, mixed ...$args): static
    {
        if ($this->pending !== null) {
            throw new QueryException('when 之后必须先 then 才能再次 when');
        }

        $count = count($args);
        if ($count === 1) {
            $this->pending = new Comparison($column, '=', $args[0]);
        } elseif ($count === 2 && is_string($args[0])) {
            $this->pending = new Comparison($column, $args[0], $args[1]);
        } else {
            throw new QueryException('when 参数形式非法，应为 (列, 值) 或 (列, 运算符, 值)');
        }

        return $this;
    }

    /**
     * 设置最近 when 的结果值（标量或 ProjectionExpression 嵌套表达式）
     */
    public function then(mixed $value): static
    {
        if ($this->pending === null) {
            throw new QueryException('then 必须紧跟 when 之后');
        }
        self::assertValue($value);
        $this->whens[] = ['condition' => $this->pending, 'value' => $value];
        $this->pending = null;

        return $this;
    }

    /**
     * 设置兜底值（全部分支不中时）；未设置时求值得 null
     */
    public function else(mixed $value): static
    {
        self::assertValue($value);
        $this->elseValue = $value;
        $this->hasElse = true;

        return $this;
    }

    /**
     * 返回带别名的新实例（求值结构共享无妨，别名独立）
     */
    public function as(string $alias): self
    {
        $clone = clone $this;
        $clone->alias = $alias;

        return $clone;
    }

    /**
     * 默认输出名恒为 'CASE'（多 CASE 时建议用 as 别名）
     */
    public function outputName(): string
    {
        return 'CASE';
    }

    /**
     * 显式别名，未设置返回 null
     */
    public function alias(): ?string
    {
        return $this->alias;
    }

    /**
     * 依序求值 when 条件（ConditionEvaluator 求值 Comparison）；
     * 命中取 then 值（表达式递归求值）；全不中取 else 值（未设为 null）
     */
    public function evaluate(array $row): mixed
    {
        foreach ($this->whens as $when) {
            if (ConditionEvaluator::evaluate($row, $when['condition'])) {
                return $this->resolve($when['value'], $row);
            }
        }

        return $this->hasElse ? $this->resolve($this->elseValue, $row) : null;
    }

    /**
     * 求结果值：表达式递归求值，标量/null 直通
     */
    private function resolve(mixed $value, array $row): mixed
    {
        return $value instanceof ProjectionExpression ? $value->evaluate($row) : $value;
    }

    /**
     * 结果值仅允许标量/null/投影表达式；违规抛 QueryException
     */
    private static function assertValue(mixed $value): void
    {
        if ($value !== null && !is_scalar($value) && !$value instanceof ProjectionExpression) {
            throw new QueryException(
                'CASE 结果值仅允许标量/null/投影表达式: ' . get_debug_type($value),
            );
        }
    }
}
