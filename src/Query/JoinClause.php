<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Query\Condition\Condition;

/**
 * 连接子句：表连接类型与连接条件
 *
 * 连接条件可以是简单比较（leftColumn + operator + rightColumn，走 hash join 优化）
 * 或任意条件表达式（$on，走嵌套循环求值桥）；两者互斥，$on 优先。
 * $cte 非 null 时本连接源为 WITH CTE（$table 为别名/占位），数据源在执行期从查询的 ctes 注册表解析
 */
final readonly class JoinClause
{
    private const TYPES = ['INNER', 'LEFT', 'RIGHT'];
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>='];

    /**
     * @param 'INNER'|'LEFT'|'RIGHT' $type 连接类型
     * @param ?Condition $on ON 条件表达式（任意条件组），优先级高于 leftColumn/operator/rightColumn
     * @param ?string $cte 非 null 时数据源为命名 CTE（$table 作别名/占位）
     */
    public function __construct(
        public string $type,
        public string $table,
        public ?string $alias,
        public string $leftColumn = '',
        public string $operator = '',
        public string $rightColumn = '',
        public ?Condition $on = null,
        public readonly ?string $cte = null,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new QueryException("非法连接类型，仅允许 INNER/LEFT/RIGHT: {$type}");
        }
        if ($on === null && !in_array($operator, self::OPERATORS, true)) {
            throw new QueryException("非法连接条件运算符: {$operator}");
        }
    }
}
