<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

/**
 * 联合子句：UNION / UNION ALL 及其右侧查询
 */
final readonly class UnionClause
{
    /**
     * @param 'UNION'|'UNION ALL' $type 联合类型（UNION 去重、UNION ALL 保留重复）
     * @param SelectQuery $query 联合方查询（可携带自身 unions，执行时递归展开）
     */
    public function __construct(
        public string $type,
        public SelectQuery $query,
    ) {
    }
}
