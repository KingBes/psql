<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query\Condition;

use Kingbes\Psql\Exception\StorageException;

/**
 * BETWEEN / NOT BETWEEN 条件（闭区间）
 */
final class Between extends Condition
{
    public function __construct(
        public string $column,
        public mixed $min,
        public mixed $max,
        public bool $negate = false,
    ) {
    }

    /**
     * 序列化为数组；min/max 非标量/null 抛 StorageException
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        self::assertScalarValue($this->min, 'min');
        self::assertScalarValue($this->max, 'max');

        return [
            'type' => 'between',
            'column' => $this->column,
            'min' => $this->min,
            'max' => $this->max,
            'negate' => $this->negate,
        ];
    }

    /**
     * 从数组还原；缺键/非标量值抛 StorageException
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $column = $data['column'] ?? null;
        if (!is_string($column)) {
            throw new StorageException('BETWEEN 条件缺少合法的 column 字段');
        }
        if (!array_key_exists('min', $data) || !array_key_exists('max', $data)) {
            throw new StorageException('BETWEEN 条件缺少 min/max 字段');
        }
        self::assertScalarValue($data['min'], 'min');
        self::assertScalarValue($data['max'], 'max');

        return new self($column, $data['min'], $data['max'], (bool) ($data['negate'] ?? false));
    }

    /**
     * 列名精确匹配替换后的新实例
     */
    public function withColumnRenamed(string $from, string $to): self
    {
        return $this->column === $from
            ? new self($to, $this->min, $this->max, $this->negate)
            : $this;
    }

    /**
     * 校验区间两端仅允许标量或 null；违规抛 SchemaException
     */
    public function assertScalarValues(): void
    {
        self::assertCheckScalarValue($this->min, $this->column);
        self::assertCheckScalarValue($this->max, $this->column);
    }
}
