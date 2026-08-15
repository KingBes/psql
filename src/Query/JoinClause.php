<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

use Kingbes\Psql\Exception\QueryException;

/**
 * 连接子句：表连接类型与连接条件
 */
final readonly class JoinClause
{
    private const TYPES = ['INNER', 'LEFT', 'RIGHT'];
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>='];

    /**
     * @param 'INNER'|'LEFT'|'RIGHT' $type 连接类型
     */
    public function __construct(
        public string $type,
        public string $table,
        public ?string $alias,
        public string $leftColumn,
        public string $operator,
        public string $rightColumn,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new QueryException("非法连接类型，仅允许 INNER/LEFT/RIGHT: {$type}");
        }
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new QueryException("非法连接条件运算符: {$operator}");
        }
    }
}
