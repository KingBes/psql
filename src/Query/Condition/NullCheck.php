<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query\Condition;

use Kingbes\Psql\Exception\StorageException;

/**
 * IS NULL / IS NOT NULL 条件
 */
final class NullCheck extends Condition
{
    public function __construct(
        public string $column,
        public bool $negate = false,
    ) {
    }

    /**
     * 序列化为数组
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'null',
            'column' => $this->column,
            'negate' => $this->negate,
        ];
    }

    /**
     * 从数组还原；缺键抛 StorageException
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $column = $data['column'] ?? null;
        if (!is_string($column)) {
            throw new StorageException('NULL 条件缺少合法的 column 字段');
        }

        return new self($column, (bool) ($data['negate'] ?? false));
    }

    /**
     * 列名精确匹配替换后的新实例
     */
    public function withColumnRenamed(string $from, string $to): self
    {
        return $this->column === $from ? new self($to, $this->negate) : $this;
    }

    /**
     * 无比较值，无需校验
     */
    public function assertScalarValues(): void
    {
    }
}
