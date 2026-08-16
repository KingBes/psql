<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query\Condition;

use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Query\SelectQuery;

/**
 * EXISTS (子查询) 条件：执行期经 SubqueryResolver 化简为 BooleanConst 后方可求值
 */
final class ExistsCheck extends Condition
{
    public function __construct(
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
     * 无列可替换，原样返回
     */
    public function withColumnRenamed(string $from, string $to): self
    {
        return $this;
    }

    /**
     * 注册路径校验：CHECK 约束禁止子查询条件（Blueprint::check 注册时触发）
     */
    public function assertScalarValues(): void
    {
        throw new SchemaException('CHECK 约束不支持子查询条件（ExistsCheck）');
    }
}
