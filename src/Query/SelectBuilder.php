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

    /** @var list<WindowExpression> 窗口函数（投影/聚合后整组计算） */
    private array $windows = [];

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

    /** FROM 子查询（派生表）：非 null 时数据源为子查询执行结果，$table 退化为别名占位 */
    private ?SelectQuery $fromSub = null;

    /** WITH CTE 注册表：本构建器可见的命名子查询（构建期固化，供 fromCte/joinCte 解析） */
    private array $ctes = [];

    /** FROM 位引用的 CTE 名（fromCte 设置；toQuery 时从 $ctes 解析为 $fromSub） */
    private ?string $cteName = null;

    /**
     * 构造期仅保存连接实例，不触碰其任何方法
     */
    public function __construct(
        private Connection $connection,
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
        $builder->windows = $query->windows;
        $builder->groupBy = $query->groupBy;
        $builder->where = $query->where;
        $builder->having = $query->having;
        $builder->orderBy = $query->orderBy;
        $builder->distinct = $query->distinct;
        $builder->limit = $query->limit;
        $builder->offset = $query->offset;
        $builder->unions = $query->unions;
        $builder->fromSub = $query->fromSub;

        return $builder;
    }

    /**
     * 以子查询为 FROM 源（派生表）构建查询：`FROM (子查询) AS alias`
     *
     * 子查询立即 toQuery 固化；别名必填（列引用与去歧义依赖它）；
     * 子查询须有输出列（空结果集除外），列引用按 `alias.输出列` 解析
     */
    public static function fromSub(Connection $connection, self $query, string $alias): self
    {
        $builder = new self($connection, $alias, $alias);
        $builder->fromSub = $query->toQuery();

        return $builder;
    }

    /**
     * 以 WITH CTE 注册表开始构建查询（非递归 CTE）；FROM 位用 fromCte、JOIN 位用 joinCte 系列引用。
     *
     * CTE 定义按声明顺序固化，后序 CTE 可引用前序 CTE（MySQL 语义：CTE 只能引用位于其前的 CTE）；
     * 每次引用独立完整执行（非物化，结果不缓存）。递归 CTE（WITH RECURSIVE）不支持
     *
     * @param array<string, self> $ctes 命名 CTE 定义（完整 SelectBuilder，可含聚合/排序/联合等）
     */
    public static function withCtes(Connection $connection, array $ctes): self
    {
        $builder = new self($connection, '');
        $builder->ctes = self::resolveCtes($ctes, []);

        return $builder;
    }

    /**
     * 在当前查询上注册 WITH CTE（JOIN 位用 joinCte 系列引用，FROM 位用 fromCte）；返回自身可继续链式。
     * 与 withCtes 同语义：后序 CTE 可引用前序 CTE 及本构建器既有 CTE
     *
     * @param array<string, self> $ctes 命名 CTE 定义
     */
    public function with(array $ctes): static
    {
        $this->ctes = self::resolveCtes($ctes, $this->ctes);

        return $this;
    }

    /**
     * 依声明顺序固化 CTE 定义：前序（含既有）定义注入当前定义的作用域，供 fromCte/joinCte 引用
     *
     * @param array<string, self> $definitions
     * @param array<string, SelectQuery> $existing 本构建器既有 CTE（先注册者优先可见）
     * @return array<string, SelectQuery>
     */
    private static function resolveCtes(array $definitions, array $existing): array
    {
        $resolved = $existing;
        foreach ($definitions as $name => $query) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name) !== 1) {
                throw new QueryException("非法 CTE 名: {$name}");
            }
            $query->ctes = $resolved;
            $resolved[$name] = $query->toQuery();
        }

        return $resolved;
    }

    /**
     * 以命名 CTE 为 FROM 源（FROM (cte 定义) AS alias）；列按 `alias.输出列` 引用。
     *
     * 解析延迟到 toQuery：本构建器处于 withCtes 注入作用域时可按前序 CTE 名引用
     * （未知 CTE 在 toQuery/执行时报 QueryException）
     */
    public function fromCte(string $name, ?string $alias = null): static
    {
        $alias = $alias ?? $name;
        $this->table = $alias;
        $this->alias = $alias;
        $this->cteName = $name;

        return $this;
    }

    /**
     * INNER JOIN 命名 CTE（等值连接，同 join() 约定：leftColumn 为累积侧、rightColumn 为 CTE 侧）
     */
    public function joinCte(string $name, string $leftColumn, string $operator, string $rightColumn, ?string $alias = null): static
    {
        return $this->addJoinCte('INNER', $name, $leftColumn, $operator, $rightColumn, $alias);
    }

    /**
     * LEFT JOIN 命名 CTE
     */
    public function leftJoinCte(string $name, string $leftColumn, string $operator, string $rightColumn, ?string $alias = null): static
    {
        return $this->addJoinCte('LEFT', $name, $leftColumn, $operator, $rightColumn, $alias);
    }

    /**
     * RIGHT JOIN 命名 CTE
     */
    public function rightJoinCte(string $name, string $leftColumn, string $operator, string $rightColumn, ?string $alias = null): static
    {
        return $this->addJoinCte('RIGHT', $name, $leftColumn, $operator, $rightColumn, $alias);
    }

    /**
     * 追加输出列（字符串列名/聚合表达式/投影表达式/窗口函数混用）
     */
    public function select(string|AggregateExpression|ProjectionExpression|WindowExpression ...$columns): static
    {
        foreach ($columns as $column) {
            if ($column instanceof WindowExpression) {
                $this->windows[] = $column;
            } elseif ($column instanceof AggregateExpression) {
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
     * AND 列-列比较条件：left 运算符 right（两侧均为列引用）
     */
    public function whereColumn(string $left, string $operator, string $right): static
    {
        $this->group()->whereColumn($left, $operator, $right);

        return $this;
    }

    /**
     * AND 标量子查询条件：列 运算符 (子查询)，子查询须输出 1 列 1 行
     */
    public function whereScalar(string $column, string $operator, self $sub): static
    {
        $this->group()->whereScalar($column, $operator, $sub);

        return $this;
    }

    /**
     * OR 标量子查询条件
     */
    public function orWhereScalar(string $column, string $operator, self $sub): static
    {
        $this->group()->orWhereScalar($column, $operator, $sub);

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

    /**
     * EXISTS (子查询) 条件（OR 语义）
     */
    public function orWhereExists(self $sub): static
    {
        $this->group()->add(new ExistsCheck($sub->toQuery()), 'OR');

        return $this;
    }

    /**
     * NOT EXISTS (子查询) 条件（OR 语义）
     */
    public function orWhereNotExists(self $sub): static
    {
        $this->group()->add(new ExistsCheck($sub->toQuery(), true), 'OR');

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
     * INNER JOIN，ON 为任意条件表达式（支持列-列比较用 whereColumn、列-值比较用 where）
     */
    public function joinOn(string $table, ConditionGroup $on): static
    {
        return $this->addJoinOn('INNER', $table, $on);
    }

    /**
     * LEFT JOIN，ON 为任意条件表达式
     */
    public function leftJoinOn(string $table, ConditionGroup $on): static
    {
        return $this->addJoinOn('LEFT', $table, $on);
    }

    /**
     * RIGHT JOIN，ON 为任意条件表达式
     */
    public function rightJoinOn(string $table, ConditionGroup $on): static
    {
        return $this->addJoinOn('RIGHT', $table, $on);
    }

    /**
     * INNER JOIN ... USING(column)：单列展开为等值比较（走 hash join），多列展开为 AND 条件组
     */
    public function joinUsing(string $table, string|array $columns): static
    {
        return $this->addJoinUsing('INNER', $table, $columns);
    }

    /**
     * LEFT JOIN ... USING(column)
     */
    public function leftJoinUsing(string $table, string|array $columns): static
    {
        return $this->addJoinUsing('LEFT', $table, $columns);
    }

    /**
     * RIGHT JOIN ... USING(column)
     */
    public function rightJoinUsing(string $table, string|array $columns): static
    {
        return $this->addJoinUsing('RIGHT', $table, $columns);
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
     * 产出查询 DTO（供执行器）；FROM 位 CTE 引用在此解析为派生表（未知 CTE 抛 QueryException）
     */
    public function toQuery(): SelectQuery
    {
        $fromSub = $this->fromSub;
        if ($this->cteName !== null) {
            if (!isset($this->ctes[$this->cteName])) {
                throw new QueryException("未知 CTE: {$this->cteName}");
            }
            $fromSub = $this->ctes[$this->cteName];
        }

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
            $fromSub,
            $this->ctes,
            $this->windows,
        );
    }

    // ---- 终结方法（触发 Executor/Writer 加载） ----

    public function get(): ResultSet
    {
        return (new Executor($this->connection))->execute($this->toQuery());
    }

    /**
     * EXPLAIN：静态分析当前查询的访问路径（扫描/索引、JOIN 方式、排序等步骤列表，不执行查询本体）
     *
     * @return list<array<string, string|int>>
     */
    public function explain(): array
    {
        return Explain::of($this->connection, $this->toQuery());
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
     * 链式带 orderBy/limit 时为 MySQL UPDATE ... ORDER BY ... LIMIT 语义（matched 排序后取前 limit 行更新）；
     * 链式带 join 时为多表 UPDATE（MySQL 语义）：SET 键 'alias.col' 限定目标表，裸键归基表
     */
    public function update(array $values): int
    {
        [$orderBy, $limit] = $this->writeOrderLimit();

        return (new Writer($this->connection))
            ->update($this->table, $this->alias, $this->where, $values, $orderBy, $limit, $this->joins);
    }

    /**
     * 按当前 where 条件删除，返回影响行数；
     * 链式带 orderBy/limit 时为 MySQL DELETE ... ORDER BY ... LIMIT 语义（matched 排序后取前 limit 行删除）；
     * 链式带 join 时为多表 DELETE（MySQL 语义）：仅删除基表匹配行，join 表只参与匹配
     */
    public function delete(): int
    {
        [$orderBy, $limit] = $this->writeOrderLimit();

        return (new Writer($this->connection))
            ->delete($this->table, $this->alias, $this->where, $orderBy, $limit, $this->joins);
    }

    public function count(): int
    {
        $value = $this->aggregateValue(Agg::count('*'));

        return (int) ($value ?? 0);
    }

    /**
     * SUM：空表/全 null 返回 null（对齐 MySQL）
     */
    public function sum(string $column): ?float
    {
        $value = $this->aggregateValue(Agg::sum($column));

        return $value === null ? null : (float) $value;
    }

    /**
     * AVG：空表/全 null 返回 null（对齐 MySQL）
     */
    public function avg(string $column): ?float
    {
        $value = $this->aggregateValue(Agg::avg($column));

        return $value === null ? null : (float) $value;
    }

    /**
     * MIN：空表/全 null 返回 null（对齐 MySQL）
     */
    public function min(string $column): mixed
    {
        return $this->aggregateValue(Agg::min($column));
    }

    /**
     * MAX：空表/全 null 返回 null（对齐 MySQL）
     */
    public function max(string $column): mixed
    {
        return $this->aggregateValue(Agg::max($column));
    }

    /**
     * 写链式（UPDATE/DELETE 终结）的排序/限量状态提取：
     * - 派生表查询不支持 UPDATE/DELETE
     * - 链式带 offset 抛 QueryException（MySQL UPDATE/DELETE 不支持 OFFSET）
     * - 带 orderBy/limit 时仅允许 where/orderBy/limit 链式——聚合/投影表达式/join/group/having/distinct/union
     *   场景输出行与基表行号无法一一对应，抛 QueryException
     * - 无 orderBy/limit 时返回 [null, null]（走既有写路径，行为与之前完全一致）
     *
     * @return array{0: list<array{column: string, direction: 'ASC'|'DESC'}>|null, 1: int|null}
     */
    private function writeOrderLimit(): array
    {
        if ($this->fromSub !== null) {
            throw new QueryException('派生表查询不支持 UPDATE/DELETE');
        }
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
        if (!$this->where instanceof ConditionGroup) {
            $this->where = new ConditionGroup();
        }

        return $this->where;
    }

    private function addJoin(string $type, string $table, string $leftColumn, string $operator, string $rightColumn): static
    {
        [$name, $alias] = $this->parseTableRef($table);
        $this->joins[] = new JoinClause($type, $name, $alias, $leftColumn, $operator, $rightColumn);

        return $this;
    }

    /**
     * CTE 连接：JoinClause 以 cte 名标记源，table/alias 统一为引用别名（执行期从查询 ctes 解析）
     */
    private function addJoinCte(string $type, string $name, string $leftColumn, string $operator, string $rightColumn, ?string $alias): static
    {
        $alias = $alias ?? $name;
        $this->joins[] = new JoinClause($type, $alias, $alias, $leftColumn, $operator, $rightColumn, cte: $name);

        return $this;
    }

    /**
     * 任意条件表达式 ON：ConditionGroup 作为 ON 条件，回退嵌套循环求值
     */
    private function addJoinOn(string $type, string $table, ConditionGroup $on): static
    {
        [$name, $alias] = $this->parseTableRef($table);
        $this->joins[] = new JoinClause($type, $name, $alias, on: $on);

        return $this;
    }

    /**
     * USING(column) 的展开：单列走简单比较（hash join 优化），多列走 AND 条件组；
     * 左右列名相同时，左侧列以当前已累积源的别名限定，右侧列以 join 表别名限定，
     * 避免两侧同名列在合并行上歧义
     *
     * @param string|list<string> $columns
     */
    private function addJoinUsing(string $type, string $table, string|array $columns): static
    {
        [$name, $alias] = $this->parseTableRef($table);
        $cols = is_string($columns) ? [$columns] : $columns;
        $leftAlias = $this->currentSourceAlias();
        $rightAlias = $alias ?? $name;

        if (count($cols) === 1) {
            $col = $cols[0];
            $this->joins[] = new JoinClause($type, $name, $alias, "$leftAlias.$col", '=', "$rightAlias.$col");

            return $this;
        }

        $on = new ConditionGroup();
        foreach ($cols as $col) {
            $on->whereColumn("$leftAlias.$col", '=', "$rightAlias.$col");
        }
        $this->joins[] = new JoinClause($type, $name, $alias, on: $on);

        return $this;
    }

    /**
     * 当前已累积源的别名（USING 展开左侧限定用）：
     * 最近一次 join 的表别名/表名，无 join 时取基表别名/表名（左结合语义）
     */
    private function currentSourceAlias(): string
    {
        if ($this->joins !== []) {
            $last = $this->joins[count($this->joins) - 1];

            return $last->alias ?? $last->table;
        }

        return $this->alias ?? $this->table;
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
}