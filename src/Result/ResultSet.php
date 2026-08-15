<?php

declare(strict_types=1);

namespace Kingbes\Psql\Result;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Kingbes\Psql\Exception\QueryException;
use Traversable;

/**
 * 查询结果集：行列表的只读视图
 */
final class ResultSet implements IteratorAggregate, Countable, JsonSerializable
{
    /**
     * @param list<array<string,mixed>> $rows
     */
    public function __construct(private array $rows)
    {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->rows;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * 首行；空集返回 null
     *
     * @return array<string,mixed>|null
     */
    public function first(): ?array
    {
        return $this->rows[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->rows !== [];
    }

    /**
     * 抽取单列值列表；任一行内无该键抛 QueryException
     *
     * @return list<mixed>
     */
    public function pluck(string $column): array
    {
        $values = [];
        foreach ($this->rows as $index => $row) {
            if (!array_key_exists($column, $row)) {
                throw new QueryException("第 {$index} 行不存在列: {$column}");
            }
            $values[] = $row[$column];
        }

        return $values;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function toArray(): array
    {
        return $this->rows;
    }

    public function toJson(): string
    {
        return json_encode($this->rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function jsonSerialize(): array
    {
        return $this->rows;
    }
}
