<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query\Condition;

use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Query\SelectQuery;

/**
 * IN (子查询) 条件：执行期经 SubqueryResolver 解析为 InList 后方可求值
 */
final class SubqueryIn extends Condition
{
    public function __construct(
        public string $column,
        public SelectQuery $query,
        public bool $negate = false,
    ) {
    }

    /**
     * 子查询条件不支持持久化（CHECK 约束中禁止）
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        throw new StorageException('子查询条件不支持持久化（CHECK 约束中禁止）');
    }

    /**
     * 列名精确匹配替换后的新实例（子查询原样携带）
     */
    public function withColumnRenamed(string $from, string $to): self
    {
        return $this->column === $from ? new self($to, $this->query, $this->negate) : $this;
    }

    /**
     * 注册路径校验：CHECK 约束禁止子查询条件（Blueprint::check 注册时触发）
     */
    public function assertScalarValues(): void
    {
        throw new SchemaException('CHECK 约束不支持子查询条件（SubqueryIn）');
    }
}
