<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

/**
 * 列引用表达式：在限定行上按名取列值（供函数/CASE 嵌套使用）
 */
final readonly class ColumnRef implements ProjectionExpression
{
    /**
     * @param string $column 列名（裸列名或 'alias.col' 限定名）
     * @param ?string $alias 显式别名（as 设置）
     */
    public function __construct(
        public string $column,
        private ?string $alias = null,
    ) {
    }

    /**
     * 返回带别名的新实例（不可变，参照 FuncExpression::as）
     */
    public function as(string $alias): self
    {
        return new self($this->column, $alias);
    }

    /**
     * 输出名即列名本身（无别名时；有别名时投影以别名作输出键）
     */
    public function outputName(): string
    {
        return $this->column;
    }

    /**
     * 显式别名，未设置返回 null
     */
    public function alias(): ?string
    {
        return $this->alias;
    }

    /**
     * 取行列值：精确键优先，其后缀唯一匹配；未知/歧义抛 QueryException
     */
    public function evaluate(array $row): mixed
    {
        return ConditionEvaluator::columnValue($row, $this->column);
    }
}
