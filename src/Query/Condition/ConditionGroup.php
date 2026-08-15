<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query\Condition;

use Kingbes\Psql\Exception\QueryException;

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

    public function whereIn(string $column, array $values): static
    {
        return $this->add(new InList($column, $values), 'AND');
    }

    public function whereNotIn(string $column, array $values): static
    {
        return $this->add(new InList($column, $values, true), 'AND');
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
