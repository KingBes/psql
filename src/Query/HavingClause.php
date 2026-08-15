<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

use Kingbes\Psql\Exception\QueryException;

/**
 * HAVING 子句：按聚合输出别名过滤
 */
final readonly class HavingClause
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>='];

    /**
     * @param string $operator 比较运算符，限 = != <> < <= > >=
     */
    public function __construct(
        public string $alias,
        public string $operator,
        public mixed $value,
    ) {
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new QueryException("非法 HAVING 运算符: {$operator}");
        }
    }
}
