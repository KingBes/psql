<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Query\Condition\Condition;

/**
 * 视图定义 DTO：命名查询的结构化持久化载体
 *
 * 查询体以结构化数组存储（与引擎 schema 的 JSON 持久化风格一致，禁止二进制 serialize），
 * 不含连接对象，可跨连接重开恢复。已知不可持久化并在构造时拒绝的部分：
 * - 子查询条件（SubqueryIn/ExistsCheck，其 toArray 抛 StorageException）
 * - 投影表达式（函数/CASE，无结构化序列化入口）
 */
final readonly class ViewDefinition
{
    /**
     * @param array<string, mixed> $query 结构化查询数组（queryToArray 产出）
     */
    public function __construct(
        public string $name,
        private array $query,
    ) {
    }

    /**
     * 从查询 DTO 构造：立即尝试序列化，不可持久化的部分转抛 QueryException
     */
    public static function fromQuery(string $name, SelectQuery $query): self
    {
        return new self($name, self::queryToArray($query));
    }

    /**
     * 序列化为数组（引擎持久化的载荷格式）
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'query' => $this->query];
    }

    /**
     * 从数组还原；结构非法抛 StorageException
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? null;
        $query = $data['query'] ?? null;
        if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new StorageException('视图定义缺少合法的 name 字段');
        }
        if (!is_array($query)) {
            throw new StorageException("视图定义 {$name} 缺少合法的 query 字段");
        }

        return new self($name, $query);
    }

    /**
     * 还原为查询 DTO（供 SelectBuilder 水化与执行）
     */
    public function toQuery(): SelectQuery
    {
        return self::queryFromArray($this->query);
    }

    /**
     * 视图是否直接或间接引用指定表（基表 / join 表 / 嵌套子查询与 union 递归）
     *
     * 供 DDL 联动校验：dropTable/renameTable 拒绝破坏被视图引用的基表
     */
    public function usesTable(string $table): bool
    {
        return self::queryUsesTable($this->toQuery(), $table);
    }

    /**
     * SelectQuery 递归检测表引用
     */
    private static function queryUsesTable(SelectQuery $query, string $table): bool
    {
        if ($query->fromSub !== null) {
            if (self::queryUsesTable($query->fromSub, $table)) {
                return true;
            }
        } elseif ($query->table === $table) {
            return true;
        }
        foreach ($query->joins as $join) {
            if ($join->table === $table) {
                return true;
            }
        }
        foreach ($query->unions as $union) {
            if (self::queryUsesTable($union->query, $table)) {
                return true;
            }
        }

        return false;
    }

    /**
     * SelectQuery → 结构化数组：逐字段展开，joins 元素与 unions 递归展开
     *
     * @return array<string, mixed>
     */
    private static function queryToArray(SelectQuery $query): array
    {
        if ($query->expressions !== []) {
            throw new QueryException('视图定义包含不可持久化的投影表达式（函数/CASE）');
        }
        if ($query->windows !== []) {
            throw new QueryException('视图定义包含不可持久化的窗口函数');
        }
        if ($query->fromSub !== null) {
            throw new QueryException('视图定义包含不可持久化的 FROM 子查询（派生表）');
        }

        $where = null;
        if ($query->where !== null) {
            try {
                $where = $query->where->toArray();
            } catch (StorageException) {
                throw new QueryException('视图定义包含不可持久化的子查询条件');
            }
        }

        $having = null;
        if ($query->having !== null) {
            if ($query->having->value !== null && !is_scalar($query->having->value)) {
                throw new QueryException('视图定义包含不可持久化的 HAVING 条件值');
            }
            $having = [
                'alias' => $query->having->alias,
                'operator' => $query->having->operator,
                'value' => $query->having->value,
            ];
        }

        $joins = [];
        foreach ($query->joins as $join) {
            try {
                $joins[] = [
                    'type' => $join->type,
                    'table' => $join->table,
                    'alias' => $join->alias,
                    'left_column' => $join->leftColumn,
                    'operator' => $join->operator,
                    'right_column' => $join->rightColumn,
                    'on' => $join->on?->toArray(),
                ];
            } catch (StorageException) {
                // 列-列比较（ColumnRef 值）不可序列化，拒绝持久化
                throw new QueryException('视图定义包含不可持久化的 ON 条件（列-列比较）');
            }
        }

        return [
            'table' => $query->table,
            'alias' => $query->alias,
            'columns' => $query->columns,
            'joins' => $joins,
            'where' => $where,
            'aggregates' => array_map(
                static fn (AggregateExpression $aggregate): array => [
                    'function' => $aggregate->function,
                    'column' => $aggregate->column,
                    'alias' => $aggregate->alias,
                ],
                $query->aggregates,
            ),
            'group_by' => $query->groupBy,
            'having' => $having,
            'order_by' => $query->orderBy,
            'distinct' => $query->distinct,
            'limit' => $query->limit,
            'offset' => $query->offset,
            'unions' => array_map(
                static fn (UnionClause $union): array => [
                    'type' => $union->type,
                    'query' => self::queryToArray($union->query),
                ],
                $query->unions,
            ),
        ];
    }

    /**
     * 结构化数组 → SelectQuery；结构非法抛 StorageException
     *
     * @param array<string, mixed> $data
     */
    private static function queryFromArray(array $data): SelectQuery
    {
        $table = $data['table'] ?? null;
        if (!is_string($table) || $table === '') {
            throw new StorageException('视图查询定义缺少合法的 table 字段');
        }

        try {
            return self::rebuildQuery($data, $table);
        } catch (QueryException $e) {
            throw new StorageException('视图查询定义非法: ' . $e->getMessage());
        }
    }

    /**
     * 逐字段重建查询 DTO（QueryException 统一由调用方转 StorageException）
     *
     * @param array<string, mixed> $data
     */
    private static function rebuildQuery(array $data, string $table): SelectQuery
    {
        $alias = $data['alias'] ?? null;
        if ($alias !== null && !is_string($alias)) {
            throw new StorageException('视图查询定义的 alias 字段非法');
        }

        $where = null;
        if (isset($data['where'])) {
            if (!is_array($data['where'])) {
                throw new StorageException('视图查询定义的 where 字段非法');
            }
            $where = Condition::fromArray($data['where']);
        }

        $joins = [];
        foreach ((array) ($data['joins'] ?? []) as $join) {
            if (!is_array($join)) {
                throw new StorageException('视图查询定义的 joins 结构非法');
            }
            $joins[] = new JoinClause(
                self::stringField($join, 'type'),
                self::stringField($join, 'table'),
                $join['alias'] ?? null,
                $join['left_column'] ?? '',
                $join['operator'] ?? '',
                $join['right_column'] ?? '',
                isset($join['on']) && is_array($join['on']) ? Condition::fromArray($join['on']) : null,
            );
        }

        $aggregates = [];
        foreach ((array) ($data['aggregates'] ?? []) as $aggregate) {
            if (!is_array($aggregate)) {
                throw new StorageException('视图查询定义的 aggregates 结构非法');
            }
            $aggregates[] = new AggregateExpression(
                self::stringField($aggregate, 'function'),
                self::stringField($aggregate, 'column'),
                $aggregate['alias'] ?? null,
            );
        }

        $having = null;
        if (isset($data['having'])) {
            if (!is_array($data['having'])) {
                throw new StorageException('视图查询定义的 having 字段非法');
            }
            $having = new HavingClause(
                self::stringField($data['having'], 'alias'),
                self::stringField($data['having'], 'operator'),
                $data['having']['value'] ?? null,
            );
        }

        $unions = [];
        foreach ((array) ($data['unions'] ?? []) as $union) {
            if (!is_array($union) || !isset($union['query']) || !is_array($union['query'])) {
                throw new StorageException('视图查询定义的 unions 结构非法');
            }
            $unions[] = new UnionClause(self::stringField($union, 'type'), self::queryFromArray($union['query']));
        }

        return new SelectQuery(
            $table,
            $alias,
            array_values((array) ($data['columns'] ?? [])),
            $joins,
            $where,
            $aggregates,
            [],
            array_values((array) ($data['group_by'] ?? [])),
            $having,
            array_values((array) ($data['order_by'] ?? [])),
            (bool) ($data['distinct'] ?? false),
            isset($data['limit']) && is_int($data['limit']) ? $data['limit'] : null,
            isset($data['offset']) && is_int($data['offset']) ? $data['offset'] : null,
            $unions,
        );
    }

    /**
     * 取必须存在的字符串字段
     *
     * @param array<string, mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new StorageException("视图查询定义缺少合法的 {$key} 字段");
        }

        return $value;
    }
}
