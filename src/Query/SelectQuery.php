<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

use Kingbes\Psql\Query\Condition\Condition;

/**
 * 查询 DTO：构建器产出的不可变查询描述，供执行器消费
 */
final readonly class SelectQuery
{
    /**
     * @param list<string> $columns 普通输出列
     * @param list<JoinClause> $joins 连接子句
     * @param list<AggregateExpression> $aggregates 聚合表达式
     * @param list<ProjectionExpression> $expressions 投影表达式（函数/CASE）
     * @param list<string> $groupBy 分组列
     * @param list<array{column: string, direction: 'ASC'|'DESC'}> $orderBy 排序
     * @param list<UnionClause> $unions 联合子句（UNION/UNION ALL，按声明顺序）
     */
    public function __construct(
        public string $table,
        public ?string $alias,
        public array $columns = [],
        public array $joins = [],
        public ?Condition $where = null,
        public array $aggregates = [],
        public array $expressions = [],
        public array $groupBy = [],
        public ?HavingClause $having = null,
        public array $orderBy = [],
        public bool $distinct = false,
        public ?int $limit = null,
        public ?int $offset = null,
        public readonly array $unions = [],
    ) {
    }
}
