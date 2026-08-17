<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Execution\Executor;
use Kingbes\Psql\Execution\Writer;
use Kingbes\Psql\Result\InsertResult;
use Kingbes\Psql\Result\ResultSet;

/**
 * 表访问入口：查询构建与写操作全部委托 SelectBuilder/Writer
 */
final class Table
{
    private const NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * 构造期校验表名/别名合法性（Connection 负责解析 'user as u' 后传入）
     */
    public function __construct(
        private ?Connection $connection,
        private string $name,
        private ?string $alias = null,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new QueryException("非法表名: {$name}");
        }
        if ($alias !== null && preg_match(self::NAME_PATTERN, $alias) !== 1) {
            throw new QueryException("非法表别名: {$alias}");
        }
    }

    public function select(string|AggregateExpression|ProjectionExpression ...$columns): SelectBuilder
    {
        return $this->builder()->select(...$columns);
    }

    /**
     * 全表查询（select * 语义：columns 为空）
     */
    public function get(): ResultSet
    {
        return $this->builder()->get();
    }

    public function first(): ?array
    {
        return $this->builder()->first();
    }

    /**
     * 分批处理查询结果（委托构建器）
     */
    public function chunk(int $size, callable $handler): int
    {
        return $this->builder()->chunk($size, $handler);
    }

    /**
     * 惰性游标（委托构建器）
     */
    public function cursor(): \Generator
    {
        return $this->builder()->cursor();
    }

    /**
     * 按主键等值取第一行；无主键抛 QueryException
     */
    public function find(mixed $primaryKey): ?array
    {
        $connection = $this->requireConnection();
        $schema = $connection->engine()->loadSchema($connection->currentDatabase(), $this->name);
        $primaryColumn = $schema->primaryKey();
        if ($primaryColumn === null) {
            throw new QueryException("表 {$this->name} 无主键，无法使用 find");
        }

        return $this->builder()->where($primaryColumn->name, $primaryKey)->first();
    }

    /**
     * 插入单行
     */
    public function insert(array $row): InsertResult
    {
        return (new Writer($this->requireConnection()))->insert($this->name, $this->alias, [$row]);
    }

    /**
     * 插入多行
     */
    public function insertMany(array $rows): InsertResult
    {
        return (new Writer($this->requireConnection()))->insert($this->name, $this->alias, $rows);
    }

    /**
     * 无冲突插入返回 1；命中唯一约束更新该行返回 2（MySQL 惯例）
     */
    public function upsert(array $row): int
    {
        return (new Writer($this->requireConnection()))->upsert($this->name, $this->alias, $row);
    }

    /**
     * 无冲突插入返回 1；唯一冲突静默跳过返回 0（自增不消耗）
     */
    public function insertIgnore(array $row): int
    {
        return (new Writer($this->requireConnection()))->insertIgnore($this->name, $this->alias, $row);
    }

    /**
     * 把一个查询的结果集插入本表（INSERT ... SELECT）：
     * 子查询输出列须与本表列名匹配（子集也可——未覆盖列走 DEFAULT/NOT NULL 校验）；
     * 子查询引用本表自身（基表/join/union 递归）抛 QueryException（自插入语义不明）；
     * 返回插入行数（0 行结果集返回 0，不算错误——空集跳过列校验，无从判断）；
     * 任一行失败整体不写入（复用 insertMany 批内原子：cast/约束/唯一/外键/CHECK/触发器全生效）
     */
    public function insertSelect(SelectBuilder $source): int
    {
        $connection = $this->requireConnection();
        $query = $source->toQuery();
        $this->assertInsertSelectNoSelfReference($query);

        $rows = (new Executor($connection))->execute($query)->rows();
        if ($rows === []) {
            return 0;
        }

        // 列匹配校验：源行键集须为本表列子集（未知列抛 QueryException，消息含差集）
        $schema = $connection->engine()->loadSchema($connection->currentDatabase(), $this->name);
        foreach ($rows as $row) {
            $unknown = [];
            foreach (array_keys($row) as $key) {
                if (!$schema->hasColumn((string) $key)) {
                    $unknown[] = (string) $key;
                }
            }
            if ($unknown !== []) {
                throw new QueryException(
                    'INSERT ... SELECT 源列不存在于目标表 ' . $this->name . ': ' . implode(', ', $unknown)
                );
            }
        }

        return (new Writer($connection))->insert($this->name, $this->alias, $rows)->rowCount();
    }

    /**
     * REPLACE INTO（MySQL 语义）：无唯一冲突直接插入返回 1；
     * 唯一冲突（主键/unique，含复合元组）先删旧行再插入新行，返回删除 + 插入合计（冲突时为 2）
     *
     * @param array<string,mixed> $row
     */
    public function replace(array $row): int
    {
        return (new Writer($this->requireConnection()))->replace($this->name, $this->alias, $row);
    }

    /**
     * 批量 REPLACE：逐行独立处理（非批内原子，MySQL 语义），返回各行删除 + 插入合计
     *
     * @param list<array<string,mixed>> $rows
     */
    public function replaceMany(array $rows): int
    {
        return (new Writer($this->requireConnection()))->replaceMany($this->name, $this->alias, $rows);
    }

    /**
     * 全表更新（无条件）
     */
    public function update(array $values): int
    {
        return (new Writer($this->requireConnection()))->update($this->name, $this->alias, null, $values);
    }

    /**
     * 全表删除（无条件）
     */
    public function delete(): int
    {
        return (new Writer($this->requireConnection()))->delete($this->name, $this->alias, null);
    }

    public function truncate(): void
    {
        (new Writer($this->requireConnection()))->truncate($this->name);
    }

    // ---- 聚合快捷（委托构建器） ----

    public function count(): int
    {
        return $this->builder()->count();
    }

    public function sum(string $column): float
    {
        return $this->builder()->sum($column);
    }

    public function avg(string $column): float
    {
        return $this->builder()->avg($column);
    }

    public function min(string $column): mixed
    {
        return $this->builder()->min($column);
    }

    public function max(string $column): mixed
    {
        return $this->builder()->max($column);
    }

    // ---- 条件/排序等委托（返回 SelectBuilder） ----

    public function where(string $column, mixed ...$args): SelectBuilder
    {
        return $this->builder()->where($column, ...$args);
    }

    public function andWhere(string $column, mixed ...$args): SelectBuilder
    {
        return $this->builder()->andWhere($column, ...$args);
    }

    public function orWhere(string $column, mixed ...$args): SelectBuilder
    {
        return $this->builder()->orWhere($column, ...$args);
    }

    /**
     * IN 条件；值为数组或子查询构建器
     */
    public function whereIn(string $column, array|SelectBuilder $values): SelectBuilder
    {
        return $this->builder()->whereIn($column, $values);
    }

    /**
     * NOT IN 条件；值为数组或子查询构建器
     */
    public function whereNotIn(string $column, array|SelectBuilder $values): SelectBuilder
    {
        return $this->builder()->whereNotIn($column, $values);
    }

    /**
     * EXISTS (子查询) 条件
     */
    public function whereExists(SelectBuilder $sub): SelectBuilder
    {
        return $this->builder()->whereExists($sub);
    }

    /**
     * NOT EXISTS (子查询) 条件
     */
    public function whereNotExists(SelectBuilder $sub): SelectBuilder
    {
        return $this->builder()->whereNotExists($sub);
    }

    public function whereBetween(string $column, mixed $min, mixed $max): SelectBuilder
    {
        return $this->builder()->whereBetween($column, $min, $max);
    }

    public function whereNull(string $column): SelectBuilder
    {
        return $this->builder()->whereNull($column);
    }

    public function whereNotNull(string $column): SelectBuilder
    {
        return $this->builder()->whereNotNull($column);
    }

    public function whereLike(string $column, string $pattern): SelectBuilder
    {
        return $this->builder()->whereLike($column, $pattern);
    }

    public function groupBy(string ...$columns): SelectBuilder
    {
        return $this->builder()->groupBy(...$columns);
    }

    public function orderBy(string $column, string $direction = 'ASC'): SelectBuilder
    {
        return $this->builder()->orderBy($column, $direction);
    }

    public function orderByDesc(string $column): SelectBuilder
    {
        return $this->builder()->orderByDesc($column);
    }

    public function limit(int $limit): SelectBuilder
    {
        return $this->builder()->limit($limit);
    }

    public function offset(int $offset): SelectBuilder
    {
        return $this->builder()->offset($offset);
    }

    public function distinct(): SelectBuilder
    {
        return $this->builder()->distinct();
    }

    /**
     * 追加 UNION（去重联合）
     */
    public function union(SelectBuilder $query): SelectBuilder
    {
        return $this->builder()->union($query);
    }

    /**
     * 追加 UNION ALL（保留重复）
     */
    public function unionAll(SelectBuilder $query): SelectBuilder
    {
        return $this->builder()->unionAll($query);
    }

    /**
     * INSERT ... SELECT 自引用检测：源查询的基表、join 表、union（递归）任一命中本表名即抛
     */
    private function assertInsertSelectNoSelfReference(SelectQuery $query): void
    {
        if ($query->table === $this->name) {
            throw new QueryException('INSERT ... SELECT 不支持引用目标表自身');
        }
        foreach ($query->joins as $join) {
            if ($join->table === $this->name) {
                throw new QueryException('INSERT ... SELECT 不支持引用目标表自身');
            }
        }
        foreach ($query->unions as $union) {
            $this->assertInsertSelectNoSelfReference($union->query);
        }
    }

    private function builder(): SelectBuilder
    {
        return new SelectBuilder($this->connection, $this->name, $this->alias);
    }

    private function requireConnection(): Connection
    {
        if ($this->connection === null) {
            throw new QueryException('未提供数据库连接实例，无法执行该操作');
        }

        return $this->connection;
    }
}
