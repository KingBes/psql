<?php

declare(strict_types=1);

namespace Kingbes\Psql\Schema;

use Kingbes\Psql\Exception\SchemaException;

/**
 * 外键 DSL：foreignKey('col')->references('t','c')->onDeleteCascade()
 */
final class ForeignKeyDefinition
{
    private ?string $refTable = null;
    private ?string $refColumn = null;
    private bool $onDeleteCascade = false;

    /**
     * 构造时即注册进 Blueprint 内部列表
     */
    public function __construct(private Blueprint $blueprint, private string $column)
    {
        $this->blueprint->registerForeignKey($this);
    }

    /**
     * 记录引用目标
     */
    public function references(string $table, string $column): static
    {
        $this->refTable = $table;
        $this->refColumn = $column;

        return $this;
    }

    /**
     * 设置级联删除
     */
    public function onDeleteCascade(): static
    {
        $this->onDeleteCascade = true;

        return $this;
    }

    /**
     * 产出 ForeignKey；未 references 时抛 SchemaException
     */
    public function toForeignKey(): ForeignKey
    {
        if ($this->refTable === null || $this->refColumn === null) {
            throw new SchemaException("外键 {$this->column} 未调用 references() 定义引用目标");
        }

        return new ForeignKey($this->column, $this->refTable, $this->refColumn, $this->onDeleteCascade);
    }
}
