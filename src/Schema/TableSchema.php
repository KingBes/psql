<?php

declare(strict_types=1);

namespace Kingbes\Psql\Schema;

use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Exception\StorageException;

/**
 * 不可变表结构
 */
final readonly class TableSchema
{
    /**
     * @param list<ColumnSchema> $columns
     * @param list<list<string>> $uniqueKeys 联合唯一
     * @param list<ForeignKey> $foreignKeys
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $uniqueKeys = [],
        public array $foreignKeys = [],
    ) {
    }

    /**
     * 是否存在指定列（大小写敏感精确匹配）
     */
    public function hasColumn(string $name): bool
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * 取指定列，不存在抛 SchemaException
     */
    public function columnOrFail(string $name): ColumnSchema
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        throw new SchemaException("列不存在: {$name}");
    }

    /**
     * 主键列，无主键返回 null（合法）
     */
    public function primaryKey(): ?ColumnSchema
    {
        foreach ($this->columns as $column) {
            if ($column->primaryKey) {
                return $column;
            }
        }

        return null;
    }

    /**
     * 自增列，无则返回 null
     */
    public function autoIncrementColumn(): ?ColumnSchema
    {
        foreach ($this->columns as $column) {
            if ($column->autoIncrement) {
                return $column;
            }
        }

        return null;
    }

    /**
     * 参与单列唯一或联合唯一的列（去重，保持列定义顺序）
     *
     * @return list<ColumnSchema>
     */
    public function uniqueColumns(): array
    {
        $result = [];
        foreach ($this->columns as $column) {
            $inUniqueKey = false;
            foreach ($this->uniqueKeys as $key) {
                if (in_array($column->name, $key, true)) {
                    $inUniqueKey = true;
                    break;
                }
            }
            if ($column->unique || $inUniqueKey) {
                $result[] = $column;
            }
        }

        return $result;
    }

    /**
     * 重命名后的新实例
     */
    public function withName(string $name): self
    {
        return new self($name, $this->columns, $this->uniqueKeys, $this->foreignKeys);
    }

    /**
     * 按名匹配替换某列，列不存在抛 SchemaException
     */
    public function replaceColumn(ColumnSchema $new): self
    {
        $found = false;
        $columns = array_map(
            function (ColumnSchema $column) use ($new, &$found): ColumnSchema {
                if ($column->name === $new->name) {
                    $found = true;

                    return $new;
                }

                return $column;
            },
            $this->columns,
        );
        if (!$found) {
            throw new SchemaException("待替换的列不存在: {$new->name}");
        }

        return new self($this->name, $columns, $this->uniqueKeys, $this->foreignKeys);
    }

    /**
     * 删除某列；该列属于主键/联合唯一/外键则抛 SchemaException
     */
    public function dropColumn(string $name): self
    {
        $target = null;
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                $target = $column;
                break;
            }
        }
        if ($target === null) {
            throw new SchemaException("列不存在: {$name}");
        }
        if ($target->primaryKey) {
            throw new SchemaException("主键列不允许删除: {$name}");
        }
        foreach ($this->uniqueKeys as $key) {
            if (in_array($name, $key, true)) {
                throw new SchemaException("列 {$name} 参与联合唯一约束，不允许删除");
            }
        }
        foreach ($this->foreignKeys as $foreignKey) {
            if ($foreignKey->column === $name) {
                throw new SchemaException("列 {$name} 参与外键约束，不允许删除");
            }
        }

        $columns = array_values(
            array_filter(
                $this->columns,
                static fn (ColumnSchema $column): bool => $column->name !== $name,
            ),
        );

        return new self($this->name, $columns, $this->uniqueKeys, $this->foreignKeys);
    }

    /**
     * 重命名某列，同步更新 uniqueKeys 与 foreignKeys 中的列名引用
     */
    public function renameColumn(string $from, string $to): self
    {
        if (!$this->hasColumn($from)) {
            throw new SchemaException("列不存在: {$from}");
        }
        if ($from !== $to && $this->hasColumn($to)) {
            throw new SchemaException("目标列名已存在: {$to}");
        }

        $columns = array_map(
            static fn (ColumnSchema $column): ColumnSchema => $column->name === $from
                ? $column->withName($to)
                : $column,
            $this->columns,
        );
        $uniqueKeys = array_map(
            static fn (array $key): array => array_map(
                static fn (string $name): string => $name === $from ? $to : $name,
                $key,
            ),
            $this->uniqueKeys,
        );
        $foreignKeys = array_map(
            static fn (ForeignKey $foreignKey): ForeignKey => $foreignKey->column === $from
                ? new ForeignKey($to, $foreignKey->refTable, $foreignKey->refColumn, $foreignKey->onDeleteCascade)
                : $foreignKey,
            $this->foreignKeys,
        );

        return new self($this->name, $columns, $uniqueKeys, $foreignKeys);
    }

    /**
     * 序列化为数组
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => array_map(
                static fn (ColumnSchema $column): array => $column->toArray(),
                $this->columns,
            ),
            'uniqueKeys' => $this->uniqueKeys,
            'foreignKeys' => array_map(
                static fn (ForeignKey $foreignKey): array => $foreignKey->toArray(),
                $this->foreignKeys,
            ),
        ];
    }

    /**
     * 从数组还原；结构非法抛 StorageException
     */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new StorageException('表定义缺少合法的 name 字段');
        }

        $columnsRaw = $data['columns'] ?? [];
        if (!is_array($columnsRaw)) {
            throw new StorageException("表 {$name} 的 columns 必须为数组");
        }
        $columns = [];
        foreach ($columnsRaw as $item) {
            if (!is_array($item)) {
                throw new StorageException("表 {$name} 的列定义必须为数组");
            }
            $columns[] = ColumnSchema::fromArray($item);
        }

        $uniqueKeysRaw = $data['uniqueKeys'] ?? [];
        if (!is_array($uniqueKeysRaw)) {
            throw new StorageException("表 {$name} 的 uniqueKeys 必须为数组");
        }
        $uniqueKeys = [];
        foreach ($uniqueKeysRaw as $key) {
            if (!is_array($key) || $key === []) {
                throw new StorageException("表 {$name} 的联合唯一定义必须为非空数组");
            }
            foreach ($key as $columnName) {
                if (!is_string($columnName)) {
                    throw new StorageException("表 {$name} 的联合唯一列名必须为字符串");
                }
            }
            $uniqueKeys[] = array_values($key);
        }

        $foreignKeysRaw = $data['foreignKeys'] ?? [];
        if (!is_array($foreignKeysRaw)) {
            throw new StorageException("表 {$name} 的 foreignKeys 必须为数组");
        }
        $foreignKeys = [];
        foreach ($foreignKeysRaw as $item) {
            if (!is_array($item)) {
                throw new StorageException("表 {$name} 的外键定义必须为数组");
            }
            $foreignKeys[] = ForeignKey::fromArray($item);
        }

        return new self($name, $columns, $uniqueKeys, $foreignKeys);
    }
}
