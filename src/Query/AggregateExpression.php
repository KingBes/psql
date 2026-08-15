<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

use Kingbes\Psql\Exception\QueryException;

/**
 * 聚合表达式：COUNT/SUM/AVG/MIN/MAX
 */
final readonly class AggregateExpression
{
    private const FUNCTIONS = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];

    /**
     * @param string $function 聚合函数，限 COUNT SUM AVG MIN MAX
     * @param string $column 列名或 '*'
     */
    public function __construct(
        public string $function,
        public string $column,
        public ?string $alias = null,
    ) {
        if (!in_array($function, self::FUNCTIONS, true)) {
            throw new QueryException('非法聚合函数，仅支持: ' . implode('/', self::FUNCTIONS));
        }
    }

    /**
     * 返回带别名的新实例
     */
    public function as(string $alias): self
    {
        return new self($this->function, $this->column, $alias);
    }

    /**
     * 输出键：有别名取别名，否则取规范形式（如 COUNT(*)）
     */
    public function outputName(): string
    {
        return $this->alias ?? ($this->function . '(' . $this->column . ')');
    }
}
