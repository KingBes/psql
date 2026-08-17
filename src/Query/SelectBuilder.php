<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Execution\Executor;
use Kingbes\Psql\Execution\Writer;
use Kingbes\Psql\Query\Condition\Condition;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Query\Condition\ExistsCheck;
use Kingbes\Psql\Query\Condition\SubqueryIn;
use Kingbes\Psql\Result\ResultSet;

/**
 * 链式查询构建器：终结方法委托 Executor/Writer 执行
 */
final class SelectBuilder
{
    /** 表引用语法：'user' / 'user as u' / 'user u'（as 大小写不敏感） */
    private const TABLE_REF_PATTERN = '/^([A-Za-z_][A-Za-z0-9_]*)(?:\s+(?:as\s+)?([A-Za-z_][A-Za-z0-9_]*))?$/i';

    /** @var list<string> */
    private array $columns = [];

    /** @var list<JoinClause> */
    private array $joins = [];

    /** @var list<AggregateExpression> */
    private array $aggregates = [];

    /** @var list<ProjectionExpression> 投影表达式（函数/CASE） */
    private array $expressions = [];

    /** @var list<string> */
    private array $groupBy = [];

    private ?Condition $where = null;

    private ?HavingClause $having = null;

    /** @var list<array{column: string, direction: 'ASC'|'DESC'}> */
    private array $orderBy = [];

    private bool $distinct = false;

    private ?int $limit = null;

    private ?int $offset = null;

    /** @var list<UnionClause> 联合子句（UNION/UNION ALL，按声明顺序累加） */
    private array $unions = [];

    /**
     * 构造期仅保存连接实例，不触碰其任何方法
     */
    public function __construct(
        private ?Connection $connection,
        private string $table,
        private ?string $alias = null,
    ) {
    }

    /**
     * 从视图定义水化为可继续链式的构建器（每次调用独立实例，链式操作不影响存储定义）
     */
    public static function fromDefinition(Connection $connection, ViewDefinition $definition): self
    {
        $query = $definition->toQuery();
        $builder = new self($connection, $query->table, $query->alias);
        $builder->columns = $query->columns;
        $builder->joins = $query->joins;
        $builder->aggregates = $query->aggregates;
        $builder->expressions = $query->expressions;
        $builder->groupBy = $query->groupBy;
        $builder->where = $query->where;
        $builder->having = $query->having;
        $builder->orderBy = $query->orderBy;
        $builder->distinct = $query->distinct;
        $builder->limit = $query->limit;
        $builder->offset = $query->offset;
        $builder->unions = $query->unions;

        return $builder;
    }

    /**
     * 追加输出列（字符串列名/聚合表达式/投影表达式混用）
     */
    public function select(string|AggregateExpression|ProjectionExpression ...$columns): static
    {
        foreach ($columns as $column) {
            if ($column instanceof AggregateExpression) {
                $this->aggregates[] = $column;
            } elseif ($column instanceof ProjectionExpression) {
                $this->expressions[] = $column;
            } else {
                $this->columns[] = $column;
            }
        }

        return $this;
    }

    public function distinct(): static
    {
        $this->distinct = true;

        return $this;
    }

    /**
     * AND 条件；首条件连接符任意；参数形式同 ConditionGroup::where
     */
    public function where(string $column, mixed ...$args): static
    {
        $this->group()->where($column, ...$args);

        return $this;
    }

    /**
     * AND 条件，与 where 同义
     */
    public function andWhere(string $column, mixed ...$args): static
    {
        $this->group()->where($column, ...$args);

        return $this;
    }

    /**
     * 挂载嵌套条件组（AND 语义）：where(...) AND ( 组内条件 )
     */
    public function whereGroup(ConditionGroup $group): static
    {
        if ($this->where === null) {
            $this->where = $group;
        } else {
            $outer = new ConditionGroup();
            $outer->add($this->where, 'AND')->add($group, 'AND');
            $this->where = $outer;
        }

        return $this;
    }

    /**
     * 挂载嵌套条件组（OR 语义）：where(...) OR ( 组内条件 )
     */
    public function orWhereGroup(ConditionGroup $group): static
    {
        if ($this->where === null) {
            $this->where = $group;
        } else {
            $outer = new ConditionGroup();
            $outer->add($this->where, 'AND')->add($group, 'OR');
            $this->where = $outer;
        }

        return $this;
    }

    /**
     * OR 条件
     */
    public function orWhere(string $column, mixed ...$args): static
    {
        $this->group()->orWhere($column, ...$args);

        return $this;
    }

    /**
     * IN 条件；值为数组或子查询构建器（后者立即 toQuery 固化为子查询条件）
     */
    public function whereIn(string $column, array|self $values): static
    {
        if ($values instanceof self) {
            $this->group()->add(new SubqueryIn($column, $values->toQuery()), 'AND');

            return $this;
        }

        $this->group()->whereIn($column, $values);

        return $this;
    }

    /**
     * NOT IN 条件；值为数组或子查询构建器
     */
    public function whereNotIn(string $column, array|self $values): static
    {
        if ($values instanceof self) {
            $this->group()->add(new SubqueryIn($column, $values->toQuery(), true), 'AND');

            return $this;
        }

        $this->group()->whereNotIn($column, $values);

        return $this;
    }

    /**
     * EXISTS (子查询) 条件（AND 语义）
     */
    public function whereExists(self $sub): static
    {
        $this->group()->add(new ExistsCheck($sub->toQuery()), 'AND');

        return $this;
    }

    /**
     * NOT EXISTS (子查询) 条件（AND 语义）
     */
    public function whereNotExists(self $sub): static
    {
        $this->group()->add(new ExistsCheck($sub->toQuery(), true), 'AND');

        return $this;
    }

    public function whereBetween(string $column, mixed $min, mixed $max): static
    {
        $this->group()->whereBetween($column, $min, $max);

        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->group()->whereNull($column);

        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->group()->whereNotNull($column);

        return $this;
    }

    public function whereLike(string $column, string $pattern): static
    {
        $this->group()->whereLike($column, $pattern);

        return $this;
    }

    /**
     * 追加分组列
     */
    public function groupBy(string ...$columns): static
    {
        foreach ($columns as $column) {
            $this->groupBy[] = $column;
        }

        return $this;
    }

    /**
     * 覆盖式设置 HAVING
     */
    public function having(string $alias, string $operator, mixed $value): static
    {
        $this->having = new HavingClause($alias, $operator, $value);

        return $this;
    }

    /**
     * INNER JOIN
     */
    public function join(string $table, string $leftColumn, string $operator, string $rightColumn): static
    {
        return $this->addJoin('INNER', $table, $leftColumn, $operator, $rightColumn);
    }

    /**
     * LEFT JOIN
     */
    public function leftJoin(string $table, string $leftColumn, string $operator, string $rightColumn): static
    {
        return $this->addJoin('LEFT', $table, $leftColumn, $operator, $rightColumn);
    }

    /**
     * RIGHT JOIN
     */
    public function rightJoin(string $table, string $leftColumn, string $operator, string $rightColumn): static
    {
        return $this->addJoin('RIGHT', $table, $leftColumn, $operator, $rightColumn);
    }

    /**
     * 追加排序；direction 不区分大小写，限 ASC/DESC
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $normalized = strtoupper($direction);
        if ($normalized !== 'ASC' && $normalized !== 'DESC') {
            throw new QueryException("非法排序方向，仅允许 ASC/DESC: {$direction}");
        }
        $this->orderBy[] = ['column' => $column, 'direction' => $normalized];

        return $this;
    }

    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'DESC');
    }

    public function limit(int $limit): static
    {
        if ($limit < 0) {
            throw new QueryException("limit 不允许为负数: {$limit}");
        }
        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): static
    {
        if ($offset < 0) {
            throw new QueryException("offset 不允许为负数: {$offset}");
        }
        $this->offset = $offset;

        return $this;
    }

    /**
     * 追加 UNION（去重联合）；重复调用按声明顺序累加，可链 3 个以上；
     * 传入构建器自身携带的 unions 随其 toQuery 保留，执行时递归展开
     */
    public function union(self $query): static
    {
        $this->unions[] = new UnionClause('UNION', $query->toQuery());

        return $this;
    }

    /**
     * 追加 UNION ALL（保留重复）；语义同 union，仅不去重
     */
    public function unionAll(self $query): static
    {
        $this->unions[] = new UnionClause('UNION ALL', $query->toQuery());

        return $this;
    }

    /**
     * 产出查询 DTO（供执行器）
     */
    public function toQuery(): SelectQuery
    {
        return new SelectQuery(
            $this->table,
            $this->alias,
            $this->columns,
            $this->joins,
            $this->where,
            $this->aggregates,
            $this->expressions,
            $this->groupBy,
            $this->having,
            $this->orderBy,
            $this->distinct,
            $this->limit,
            $this->offset,
            $this->unions,
        );
    }

    // ---- 终结方法（触发 Executor/Writer 加载） ----

    public function get(): ResultSet
    {
        return (new Executor($this->requireConnection()))->execute($this->toQuery());
    }

    /**
     * EXPLAIN：静态分析当前查询的访问路径（扫描/索引、JOIN 方式、排序等步骤列表，不执行查询本体）
     *
     * @return list<array<string, string|int>>
     */
    public function explain(): array
    {
        return Explain::of($this->requireConnection(), $this->toQuery());
    }

    /**
     * 取结果集第一行，无结果返回 null
     */
    public function first(): ?array
    {
        $rows = $this->get()->rows();

        return $rows[0] ?? null;
    }

    /**
     * 分批处理查询结果（LIMIT/OFFSET 分页实现）
     *
     * $handler(list<array<string,mixed>> $rows, int $iteration): bool —— 返回 false 提前终止
     * 返回已处理的行数总数
     */
    public function chunk(int $size, callable $handler): int
    {
        if ($size < 1) {
            throw new QueryException("chunk 大小必须 >= 1: {$size}");
        }
        if ($this->limit !== null || $this->offset !== null) {
            throw new QueryException('chunk 与 limit/offset 不可同时使用');
        }

        $offset = 0;
        $iteration = 1;
        $processed = 0;

        while (true) {
            $clone = clone $this;
            $clone->limit = $size;
            $clone->offset = $offset;

            $rows = $clone->get()->rows();
            if ($rows === []) {
                break;
            }

            $processed += count($rows);
            if ($handler($rows, $iteration) === false) {
                break;
            }
            if (count($rows) < $size) {
                break;
            }

            $offset += $size;
            $iteration++;
        }

        return $processed;
    }

    /**
     * 惰性游标：返回生成器，查询在首次迭代时才执行
     *
     * 生成器函数体内的代码在第一次迭代前不会运行——可用于"仅在真正需要时执行查询"
     *
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    public function cursor(): \Generator
    {
        foreach ($this->get() as $row) {
            yield $row;
        }
    }

    /**
     * 按当前 where 条件更新，返回影响行数；
     * 链式带 orderBy/limit 时为 MySQL UPDATE ... ORDER BY ... LIMIT 语义（matched 排序后取前 limit 行更新）
     */
    public function update(array $values): int
    {
        [$orderBy, $limit] = $this->writeOrderLimit();

        return (new Writer($this->requireConnection()))
            ->update($this->table, $this->alias, $this->where, $values, $orderBy, $limit);
    }

    /**
     * 按当前 where 条件删除，返回影响行数；
     * 链式带 orderBy/limit 时为 MySQL DELETE ... ORDER BY ... LIMIT 语义（matched 排序后取前 limit 行删除）
     */
    public function delete(): int
    {
        [$orderBy, $limit] = $this->writeOrderLimit();

        return (new Writer($this->requireConnection()))
            ->delete($this->table, $this->alias, $this->where, $orderBy, $limit);
    }

    public function count(): int
    {
        $value = $this->aggregateValue(Agg::count('*'));

        return (int) ($value ?? 0);
    }

    public function sum(string $column): float
    {
        $value = $this->aggregateValue(Agg::sum($column));

        return $value === null ? 0.0 : (float) $value;
    }

    public function avg(string $column): float
    {
        $value = $this->aggregateValue(Agg::avg($column));

        return $value === null ? 0.0 : (float) $value;
    }

    public function min(string $column): mixed
    {
        return $this->aggregateValueOrThrow(Agg::min($column));
    }

    public function max(string $column): mixed
    {
        return $this->aggregateValueOrThrow(Agg::max($column));
    }

    /**
     * 写链式（UPDATE/DELETE 终结）的排序/限量状态提取：
     * - 链式带 offset 抛 QueryException（MySQL UPDATE/DELETE 不支持 OFFSET）
     * - 带 orderBy/limit 时仅允许 where/orderBy/limit 链式——聚合/投影表达式/join/group/having/distinct/union
     *   场景输出行与基表行号无法一一对应，抛 QueryException
     * - 无 orderBy/limit 时返回 [null, null]（走既有写路径，行为与之前完全一致）
     *
     * @return array{0: list<array{column: string, direction: 'ASC'|'DESC'}>|null, 1: int|null}
     */
    private function writeOrderLimit(): array
    {
        if ($this->offset !== null) {
            throw new QueryException('UPDATE/DELETE 不支持 OFFSET');
        }
        if ($this->orderBy === [] && $this->limit === null) {
            return [null, null];
        }
        if ($this->aggregates !== [] || $this->expressions !== [] || $this->joins !== []
            || $this->groupBy !== [] || $this->having !== null || $this->distinct || $this->unions !== []
        ) {
            throw new QueryException('UPDATE/DELETE 带 ORDER BY/LIMIT 时仅支持 where/orderBy/limit 链式');
        }

        return [$this->orderBy === [] ? null : $this->orderBy, $this->limit];
    }

    /**
     * 惰性获取/创建条件组
     */
    private function group(): ConditionGroup
    {
        return $this->where ??= new ConditionGroup();
    }

    private function addJoin(string $type, string $table, string $leftColumn, string $operator, string $rightColumn): static
    {
        [$name, $alias] = $this->parseTableRef($table);
        $this->joins[] = new JoinClause($type, $name, $alias, $leftColumn, $operator, $rightColumn);

        return $this;
    }

    /**
     * 解析 join 表引用：'user' / 'user as u' / 'user u'；格式非法抛 QueryException
     *
     * @return array{0: string, 1: ?string}
     */
    private function parseTableRef(string $reference): array
    {
        if (preg_match(self::TABLE_REF_PATTERN, $reference, $match) !== 1) {
            throw new QueryException("非法表引用: {$reference}");
        }
        $alias = $match[2] ?? null;
        // 捕获组 2 命中 'as' 本身说明输入残缺（如 'user as'）
        if ($alias !== null && strcasecmp($alias, 'as') === 0) {
            throw new QueryException("非法表引用: {$reference}");
        }

        return [$match[1], $alias];
    }

    /**
     * 在状态副本上仅保留指定聚合并取首行值；空结果返回 null
     */
    private function aggregateValue(AggregateExpression $expression): mixed
    {
        $row = $this->aggregateRow($expression);
        if ($row === null || $row === []) {
            return null;
        }

        return $row[$expression->outputName()] ?? reset($row);
    }

    /**
     * 同 aggregateValue，但空结果抛 QueryException（MIN/MAX 语义）
     */
    private function aggregateValueOrThrow(AggregateExpression $expression): mixed
    {
        $row = $this->aggregateRow($expression);
        if ($row === null || $row === []) {
            throw new QueryException('空表无法求 MIN/MAX');
        }

        return $row[$expression->outputName()] ?? reset($row);
    }

    /**
     * clone 构建器状态、清空普通列、放入聚合表达式后执行取首行
     *
     * @return array<string, mixed>|null
     */
    private function aggregateRow(AggregateExpression $expression): ?array
    {
        $clone = clone $this;
        $clone->columns = [];
        $clone->aggregates = [$expression];

        $rows = $clone->get()->rows();

        return $rows[0] ?? null;
    }

    private function requireConnection(): Connection
    {
        if ($this->connection === null) {
            throw new QueryException('未提供数据库连接实例，无法执行该操作');
        }

        return $this->connection;
    }
}
