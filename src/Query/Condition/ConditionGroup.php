<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query\Condition;

use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Query\ColumnRef;
use Kingbes\Psql\Query\SelectBuilder;

/**
 * 条件组：多个条件按 AND/OR 自左向右连接
 */
final class ConditionGroup extends Condition
{
    /** @var list<Condition> */
    public array $conditions = [];

    /** @var list<'AND'|'OR'> 数量 = conditions - 1 */
    public array $connectors = [];

    /**
     * 等值/比较条件（AND 连接）；(列, 值) 默认 '='，(列, 运算符, 值)
     */
    public function where(string $column, mixed ...$args): static
    {
        return $this->add(self::comparison($column, $args), 'AND');
    }

    /**
     * OR 连接的比较条件
     */
    public function orWhere(string $column, mixed ...$args): static
    {
        return $this->add(self::comparison($column, $args), 'OR');
    }

    /**
     * 列 vs 列 的比较条件（AND 连接）：右侧以列引用求值，如 whereColumn('a.id', '=', 'b.a_id')
     */
    public function whereColumn(string $left, string $operator, string $right): static
    {
        return $this->add(new Comparison($left, $operator, new ColumnRef($right)), 'AND');
    }

    public function whereIn(string $column, array $values): static
    {
        return $this->add(new InList($column, $values), 'AND');
    }

    public function whereNotIn(string $column, array $values): static
    {
        return $this->add(new InList($column, $values, true), 'AND');
    }

    /**
     * 标量子查询条件（AND 语义）：列 运算符 (子查询)，子查询须输出 1 列
     */
    public function whereScalar(string $column, string $operator, SelectBuilder $sub): static
    {
        return $this->add(new ScalarSubquery($column, $operator, $sub->toQuery()), 'AND');
    }

    /**
     * 标量子查询条件（OR 语义）
     */
    public function orWhereScalar(string $column, string $operator, SelectBuilder $sub): static
    {
        return $this->add(new ScalarSubquery($column, $operator, $sub->toQuery()), 'OR');
    }

    public function whereBetween(string $column, mixed $min, mixed $max): static
    {
        return $this->add(new Between($column, $min, $max), 'AND');
    }

    public function whereNull(string $column): static
    {
        return $this->add(new NullCheck($column), 'AND');
    }

    public function whereNotNull(string $column): static
    {
        return $this->add(new NullCheck($column, true), 'AND');
    }

    /**
     * LIKE 条件（AND 语义）
     */
    public function whereLike(string $column, string $pattern): static
    {
        return $this->add(new LikeCondition($column, $pattern), 'AND');
    }

    /**
     * LIKE 条件（OR 语义）
     */
    public function orWhereLike(string $column, string $pattern): static
    {
        return $this->add(new LikeCondition($column, $pattern), 'OR');
    }

    /**
     * 底层注册条件；连接符仅允许 AND/OR，首个条件时忽略连接符
     */
    public function add(Condition $condition, string $connector = 'AND'): static
    {
        if ($connector !== 'AND' && $connector !== 'OR') {
            throw new QueryException("非法连接符，仅允许 AND/OR: {$connector}");
        }
        if ($this->conditions !== []) {
            $this->connectors[] = $connector;
        }
        $this->conditions[] = $condition;

        return $this;
    }

    /**
     * 是否为空条件组
     */
    public function isEmpty(): bool
    {
        return $this->conditions === [];
    }

    /**
     * 序列化为数组；connectors 原样保留（空组为空数组）
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'group',
            'conditions' => array_map(
                static fn (Condition $condition): array => $condition->toArray(),
                $this->conditions,
            ),
            'connectors' => $this->connectors,
        ];
    }

    /**
     * 从数组还原；结构非法/连接符非法抛 StorageException
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $conditionsRaw = $data['conditions'] ?? null;
        if (!is_array($conditionsRaw)) {
            throw new StorageException('条件组缺少合法的 conditions 字段');
        }
        $connectorsRaw = $data['connectors'] ?? [];
        if (!is_array($connectorsRaw)) {
            throw new StorageException('条件组的 connectors 必须为数组');
        }

        $group = new self();
        $group->conditions = [];
        $group->connectors = [];
        foreach ($conditionsRaw as $item) {
            if (!is_array($item)) {
                throw new StorageException('条件组的子条件必须为数组');
            }
            $group->conditions[] = Condition::fromArray($item);
        }
        foreach ($connectorsRaw as $connector) {
            if (!is_string($connector) || ($connector !== 'AND' && $connector !== 'OR')) {
                throw new StorageException('条件组的 connectors 仅允许 AND/OR');
            }
            $group->connectors[] = $connector;
        }

        return $group;
    }

    /**
     * 列名精确匹配替换后的新实例；子条件递归重建，connectors 原样拷贝
     */
    public function withColumnRenamed(string $from, string $to): self
    {
        $group = new self();
        $group->conditions = array_map(
            static fn (Condition $condition): Condition => $condition->withColumnRenamed($from, $to),
            $this->conditions,
        );
        $group->connectors = $this->connectors;

        return $group;
    }

    /**
     * 递归校验每个子条件的比较值；违规抛 SchemaException
     */
    public function assertScalarValues(): void
    {
        foreach ($this->conditions as $condition) {
            $condition->assertScalarValues();
        }
    }

    /**
     * 解析 where 变长参数为 Comparison
     *
     * @param list<mixed> $args
     */
    private static function comparison(string $column, array $args): Comparison
    {
        $count = count($args);
        if ($count === 1) {
            return new Comparison($column, '=', $args[0]);
        }
        if ($count === 2 && is_string($args[0])) {
            return new Comparison($column, $args[0], $args[1]);
        }

        throw new QueryException('where 参数形式非法，应为 (列, 值) 或 (列, 运算符, 值)');
    }
}
