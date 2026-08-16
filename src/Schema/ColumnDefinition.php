<?php

declare(strict_types=1);

namespace Kingbes\Psql\Schema;

use Kingbes\Psql\Exception\SchemaException;

/**
 * 可变流畅列构建器
 */
final class ColumnDefinition
{
    private bool $unsigned = false;
    private bool $notNull = false;
    private bool $hasDefault = false;
    private mixed $default = null;
    private bool $defaultNow = false;
    private bool $unique = false;
    private bool $primaryKey = false;
    private bool $autoIncrement = false;
    private bool $ci = false;

    /**
     * @param list<string>|null $enumValues
     */
    public function __construct(
        private string $name,
        private DataType $type,
        private ?int $length = null,
        private ?int $precision = null,
        private ?int $scale = null,
        private ?array $enumValues = null,
    ) {
    }

    /**
     * 无符号（仅数值类型允许）
     */
    public function unsigned(): static
    {
        $isNumeric = $this->type->isInteger()
            || $this->type->isFloat()
            || $this->type === DataType::DECIMAL;
        if (!$isNumeric) {
            throw new SchemaException("列 {$this->name} 类型 {$this->type->value} 不支持 unsigned");
        }
        $this->unsigned = true;

        return $this;
    }

    /**
     * 非空约束
     */
    public function notNull(): static
    {
        $this->notNull = true;

        return $this;
    }

    /**
     * 默认值
     */
    public function default(mixed $value): static
    {
        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    /**
     * DEFAULT CURRENT_TIMESTAMP（仅时间类型允许）
     */
    public function defaultNow(): static
    {
        if (!$this->type->isTemporal()) {
            throw new SchemaException("列 {$this->name} 类型 {$this->type->value} 不支持 defaultNow");
        }
        $this->defaultNow = true;

        return $this;
    }

    /**
     * 单列唯一
     */
    public function unique(): static
    {
        $this->unique = true;

        return $this;
    }

    /**
     * 主键
     */
    public function primaryKey(): static
    {
        $this->primaryKey = true;

        return $this;
    }

    /**
     * 自增（仅整数类型允许）
     */
    public function autoIncrement(): static
    {
        if (!$this->type->isInteger()) {
            throw new SchemaException("列 {$this->name} 类型 {$this->type->value} 不支持 autoIncrement");
        }
        $this->autoIncrement = true;

        return $this;
    }

    /**
     * 大小写不敏感 collation（仅影响查询比较与排序；约束/唯一/外键/索引构建/CHECK 保持区分大小写）
     */
    public function ci(): static
    {
        if (!$this->type->isString() && $this->type !== DataType::ENUM) {
            throw new SchemaException("列 {$this->name} 类型 {$this->type->value} 不支持 ci");
        }
        $this->ci = true;

        return $this;
    }

    /**
     * 列名
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * 产出不可变 ColumnSchema
     */
    public function toSchema(): ColumnSchema
    {
        return new ColumnSchema(
            $this->name,
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
}
