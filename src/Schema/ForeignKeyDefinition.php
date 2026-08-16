<?php

declare(strict_types=1);

namespace Kingbes\Psql\Schema;

use Kingbes\Psql\Exception\SchemaException;

/**
 * 外键 DSL：foreignKey('col')->references('t','c')->onDelete(CASCADE)->onUpdate(SET_NULL)
 */
final class ForeignKeyDefinition
{
    private ?string $refTable = null;
    private ?string $refColumn = null;
    private ?ForeignKeyAction $onDelete = null;
    private ?ForeignKeyAction $onUpdate = null;

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
     * 设置删除策略
     */
    public function onDelete(ForeignKeyAction $action): static
    {
        $this->onDelete = $action;

        return $this;
    }

    /**
     * 设置更新策略
     */
    public function onUpdate(ForeignKeyAction $action): static
    {
        $this->onUpdate = $action;

        return $this;
    }

    /**
     * 设置级联删除（onDelete(CASCADE) 别名，兼容既有用法）
     */
    public function onDeleteCascade(): static
    {
        return $this->onDelete(ForeignKeyAction::CASCADE);
    }

    /**
     * 产出 ForeignKey；未 references 时抛 SchemaException
     */
    public function toForeignKey(): ForeignKey
    {
        if ($this->refTable === null || $this->refColumn === null) {
            throw new SchemaException("外键 {$this->column} 未调用 references() 定义引用目标");
        }

        return new ForeignKey(
            $this->column,
            $this->refTable,
            $this->refColumn,
            $this->onDelete ?? ForeignKeyAction::RESTRICT,
            $this->onUpdate ?? ForeignKeyAction::RESTRICT,
        );
    }
}
