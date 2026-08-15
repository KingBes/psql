<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query\Condition;

use Kingbes\Psql\Exception\QueryException;

/**
 * 比较条件：列 运算符 值
 */
final class Comparison extends Condition
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>='];

    /**
     * @param string $operator 比较运算符，限 = != <> < <= > >=
     */
    public function __construct(
        public string $column,
        public string $operator,
        public mixed $value,
    ) {
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new QueryException("非法比较运算符: {$operator}");
        }
    }
}
