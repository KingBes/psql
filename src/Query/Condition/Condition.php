<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query\Condition;

use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Exception\StorageException;

/**
 * 条件抽象：where 树的公共基类
 */
abstract class Condition
{
    /**
     * 从数组还原条件树；type 未知或缺键抛 StorageException
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;
        return match ($type) {
            'group' => ConditionGroup::fromArray($data),
            'comparison' => Comparison::fromArray($data),
            'in' => InList::fromArray($data),
            'between' => Between::fromArray($data),
            'null' => NullCheck::fromArray($data),
            'like' => LikeCondition::fromArray($data),
            default => throw new StorageException(
                '条件定义缺少合法的 type 字段: ' . var_export($type, true)
            ),
        };
    }

    /**
     * 序列化为数组（含 type 键）
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * 列名精确匹配替换后的新实例（条件对象不可变风格）
     */
    abstract public function withColumnRenamed(string $from, string $to): self;

    /**
     * 递归校验条件中所有比较值仅允许标量（int/float/string/bool）或 null
     *
     * 约定：本方法面向 DDL 注册路径（Blueprint::check 注册时调用），
     * 违规抛 SchemaException（消息含列名与实际类型）；
     * 序列化路径（toArray/fromArray）维持既有 assertScalarValue 的 StorageException 不变
     */
    abstract public function assertScalarValues(): void;

    /**
     * 值域校验：仅允许标量（int/float/string/bool）或 null；否则抛 StorageException
     */
    protected static function assertScalarValue(mixed $value, string $field): void
    {
        if ($value === null || is_int($value) || is_float($value) || is_string($value) || is_bool($value)) {
            return;
        }

        throw new StorageException("条件 {$field} 字段仅允许标量或 null");
    }

    /**
     * 注册路径值域校验：仅允许标量（int/float/string/bool）或 null；否则抛 SchemaException
     */
    protected static function assertCheckScalarValue(mixed $value, string $column): void
    {
        if ($value === null || is_int($value) || is_float($value) || is_string($value) || is_bool($value)) {
            return;
        }

        throw new SchemaException(
            'CHECK 条件值仅允许标量或 null，列 ' . $column . ' 含 ' . get_debug_type($value)
        );
    }
}
