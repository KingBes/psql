<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query\Condition;

use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Query\SelectQuery;

/**
 * 标量子查询条件：列 运算符 (子查询)——子查询须输出 1 列 1 行，取首行首列值与列比较；
 * 空集 → NULL（SQL 语义：col = NULL 为 unknown，行被过滤）
 *
 * 执行期经 SubqueryResolver 解析为 Comparison 后方可求值（相关标量子查询按外层行绑定后执行）
 */
final class ScalarSubquery extends Condition
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>='];

    public function __construct(
        public string $column,
        public string $operator,
        public SelectQuery $query,
    ) {
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new QueryException("非法比较运算符: {$operator}");
        }
    }

    /**
     * 标量子查询条件不支持持久化（CHECK 约束中禁止）
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        throw new StorageException('标量子查询条件不支持持久化（CHECK 约束中禁止）');
    }

    /**
     * 列名精确匹配替换后的新实例（子查询原样携带）
     */
    public function withColumnRenamed(string $from, string $to): self
    {
        return $this->column === $from ? new self($to, $this->operator, $this->query) : $this;
    }

    /**
     * 注册路径校验：CHECK 约束禁止标量子查询（Blueprint::check 注册时触发）
     */
    public function assertScalarValues(): void
    {
        throw new SchemaException('CHECK 约束不支持标量子查询条件（ScalarSubquery）');
    }
}
