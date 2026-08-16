<?php

declare(strict_types=1);

namespace Kingbes\Psql\Schema;

use Kingbes\Psql\Exception\StorageException;

/**
 * 不可变列结构
 */
final readonly class ColumnSchema
{
    /**
     * @param list<string>|null $enumValues ENUM 成员
     * @param mixed $default 默认值（hasDefault 为 true 时有效）
     * @param bool $ci 大小写不敏感 collation（仅影响查询比较与排序；约束/唯一/外键/索引构建/CHECK 保持区分大小写）
     */
    public function __construct(
        public string $name,
        public DataType $type,
        public bool $unsigned = false,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public bool $notNull = false,
        public bool $primaryKey = false,
        public bool $autoIncrement = false,
        public bool $unique = false,
        public bool $hasDefault = false,
        public mixed $default = null,
        public bool $defaultNow = false,
        public ?array $enumValues = null,
        public bool $ci = false,
    ) {
    }

    /**
     * 重命名后的新实例
     */
    public function withName(string $name): self
    {
        return new self(
            $name,
            $this->type,
            $this->unsigned,
            $this->length,
            $this->precision,
            $this->scale,
            $this->notNull,
            $this->primaryKey,
            $this->autoIncrement,
            $this->unique,
            $this->hasDefault,
            $this->default,
            $this->defaultNow,
            $this->enumValues,
            $this->ci,
        );
    }

    /**
     * 序列化为数组（enum 用 ->value）
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type->value,
            'unsigned' => $this->unsigned,
            'length' => $this->length,
            'precision' => $this->precision,
            'scale' => $this->scale,
            'notNull' => $this->notNull,
            'primaryKey' => $this->primaryKey,
            'autoIncrement' => $this->autoIncrement,
            'unique' => $this->unique,
            'hasDefault' => $this->hasDefault,
            'default' => $this->default,
            'defaultNow' => $this->defaultNow,
            'enumValues' => $this->enumValues,
            'ci' => $this->ci,
        ];
    }

    /**
     * 从数组还原；缺字段/非法枚举抛 StorageException
     */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new StorageException('列定义缺少合法的 name 字段');
        }

        $typeRaw = $data['type'] ?? null;
        if (!is_string($typeRaw)) {
            throw new StorageException("列 {$name} 缺少 type 字段");
        }
        $type = DataType::tryFrom($typeRaw);
        if ($type === null) {
            throw new StorageException("列 {$name} 含未知类型: {$typeRaw}");
        }

        $enumValues = $data['enumValues'] ?? null;
        if ($enumValues !== null) {
            if (!is_array($enumValues)) {
                throw new StorageException("列 {$name} 的 enumValues 必须为数组");
            }
            foreach ($enumValues as $member) {
                if (!is_string($member)) {
                    throw new StorageException("列 {$name} 的 enumValues 成员必须为字符串");
                }
            }
        }

        return new self(
            name: $name,
            type: $type,
            unsigned: (bool) ($data['unsigned'] ?? false),
            length: self::optionalInt($data, 'length', $name),
            precision: self::optionalInt($data, 'precision', $name),
            scale: self::optionalInt($data, 'scale', $name),
            notNull: (bool) ($data['notNull'] ?? false),
            primaryKey: (bool) ($data['primaryKey'] ?? false),
            autoIncrement: (bool) ($data['autoIncrement'] ?? false),
            unique: (bool) ($data['unique'] ?? false),
            hasDefault: (bool) ($data['hasDefault'] ?? false),
            default: $data['default'] ?? null,
            defaultNow: (bool) ($data['defaultNow'] ?? false),
            enumValues: $enumValues === null ? null : array_values($enumValues),
            ci: (bool) ($data['ci'] ?? false),
        );
    }

    /**
     * 提取可选整型字段，类型不符抛 StorageException
     */
    private static function optionalInt(array $data, string $key, string $column): ?int
    {
        $value = $data[$key] ?? null;
        if ($value === null || is_int($value)) {
            return $value;
        }

        throw new StorageException("列 {$column} 的 {$key} 字段必须为整数");
    }
}
