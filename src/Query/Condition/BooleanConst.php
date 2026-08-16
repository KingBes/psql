<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query\Condition;

use Kingbes\Psql\Exception\StorageException;

/**
 * 常量真值条件：EXISTS 子查询经 SubqueryResolver 化简后的产物
 */
final class BooleanConst extends Condition
{
    public function __construct(public bool $value)
    {
    }

    /**
     * 序列化为数组（可持久化的标量条件）
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['type' => 'bool', 'value' => $this->value];
    }

    /**
     * 从数组还原；缺 value 字段或非 bool 抛 StorageException
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!array_key_exists('value', $data) || !is_bool($data['value'])) {
            throw new StorageException('布尔常量条件缺少合法的 value 字段');
        }

        return new self($data['value']);
    }

    /**
     * 无列可替换，原样返回
     */
    public function withColumnRenamed(string $from, string $to): self
    {
        return $this;
    }

    /**
     * 常量真值无比较值需要校验
     */
    public function assertScalarValues(): void
    {
    }
}
