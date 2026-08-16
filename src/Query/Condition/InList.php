<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query\Condition;

use Kingbes\Psql\Exception\StorageException;

/**
 * IN / NOT IN 条件
 */
final class InList extends Condition
{
    public function __construct(
        public string $column,
        public array $values,
        public bool $negate = false,
    ) {
    }

    /**
     * 序列化为数组；values 成员非标量/null 抛 StorageException
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        foreach ($this->values as $member) {
            self::assertScalarValue($member, 'values');
        }

        return [
            'type' => 'in',
            'column' => $this->column,
            'values' => array_values($this->values),
            'negate' => $this->negate,
        ];
    }

    /**
     * 从数组还原；缺键/成员非标量抛 StorageException
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $column = $data['column'] ?? null;
        if (!is_string($column)) {
            throw new StorageException('IN 条件缺少合法的 column 字段');
        }
        $values = $data['values'] ?? null;
        if (!is_array($values)) {
            throw new StorageException('IN 条件缺少合法的 values 字段');
        }
        foreach ($values as $member) {
            self::assertScalarValue($member, 'values');
        }

        return new self($column, array_values($values), (bool) ($data['negate'] ?? false));
    }

    /**
     * 列名精确匹配替换后的新实例
     */
    public function withColumnRenamed(string $from, string $to): self
    {
        return $this->column === $from ? new self($to, $this->values, $this->negate) : $this;
    }

    /**
     * 校验列表每个成员仅允许标量或 null；违规抛 SchemaException
     */
    public function assertScalarValues(): void
    {
        foreach ($this->values as $member) {
            self::assertCheckScalarValue($member, $this->column);
        }
    }
}
