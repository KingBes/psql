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
     */
    public function __construct(public string $column)
    {
    }

    /**
     * 输出名即列名本身
     */
    public function outputName(): string
    {
        return $this->column;
    }

    /**
     * 列引用不单独支持别名（外层函数/CASE 负责命名）
     */
    public function alias(): ?string
    {
        return null;
    }

    /**
     * 取行列值：精确键优先，其后缀唯一匹配；未知/歧义抛 QueryException
     */
    public function evaluate(array $row): mixed
    {
        return ConditionEvaluator::columnValue($row, $this->column);
    }
}
