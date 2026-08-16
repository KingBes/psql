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
     * @param list<CheckConstraint> $checks CHECK 约束
     * @param list<TableIndex> $indexes 二级索引
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $uniqueKeys = [],
        public array $foreignKeys = [],
        public array $checks = [],
        public array $indexes = [],
    ) {
        $this->assertCheckNamesUnique();
        $this->assertForeignKeyActionColumns();
        $this->assertIndexesValid();
        $this->assertAutoIncrementPrimaryKey();
    }

    /**
     * 校验自增列与主键的关系：自增列存在时主键必须恰好为该单列
     * （v1 限制：复合主键不支持自增列）；违反抛 SchemaException（消息含表/列名）
     */
    private function assertAutoIncrementPrimaryKey(): void
    {
        $autoIncrement = null;
        foreach ($this->columns as $column) {
            if ($column->autoIncrement) {
                $autoIncrement = $column;
                break;
            }
        }
        if ($autoIncrement === null) {
            return;
        }
        $primaryKeyColumns = $this->primaryKeyColumns();
        if (count($primaryKeyColumns) === 1 && $primaryKeyColumns[0] === $autoIncrement) {
            return;
        }

        throw new SchemaException(
            "表 {$this->name} 自增列 {$autoIncrement->name} 必须恰好为唯一的主键列"
            . '（v1 限制：复合主键不支持自增列）'
        );
    }

    /**
     * 校验 CHECK 约束名唯一；重名抛 SchemaException（消息含两个冲突名）
     */
    private function assertCheckNamesUnique(): void
    {
        $names = [];
        foreach ($this->checks as $check) {
            if (in_array($check->name, $names, true)) {
                $existing = array_search($check->name, $names, true);

                throw new SchemaException(
                    "表 {$this->name} CHECK 约束名重复: {$names[$existing]} 与 {$check->name}"
                );
            }
            $names[] = $check->name;
        }
    }

    /**
     * 校验外键策略对列的要求：SET_NULL 需列可空，SET_DEFAULT 需列有默认值；
     * 违反抛 SchemaException（消息含表/列/策略名）。createTable/alterTable/fromArray 路径统一触发。
     */
    private function assertForeignKeyActionColumns(): void
    {
        foreach ($this->foreignKeys as $foreignKey) {
            $column = null;
            foreach ($this->columns as $item) {
                if ($item->name === $foreignKey->column) {
                    $column = $item;
                    break;
                }
            }
            if ($column === null) {
                continue;
            }
            foreach ([$foreignKey->onDelete, $foreignKey->onUpdate] as $action) {
                if ($action === ForeignKeyAction::SET_NULL && $column->notNull) {
                    throw new SchemaException(
                        "表 {$this->name} 列 {$foreignKey->column} 为 NOT NULL，"
                        . '外键策略 SET_NULL 要求该列可空'
                    );
                }
                if ($action === ForeignKeyAction::SET_DEFAULT && !$column->hasDefault) {
                    throw new SchemaException(
                        "表 {$this->name} 列 {$foreignKey->column} 无默认值，"
                        . '外键策略 SET_DEFAULT 要求该列有默认值'
                    );
                }
            }
        }
    }

    /**
     * 校验索引列表：实例类型、索引名唯一、列存在且索引内不重复；
     * 违反抛 SchemaException（消息含索引名/列名）
     */
    private function assertIndexesValid(): void
    {
        $names = [];
        foreach ($this->indexes as $index) {
            if (!$index instanceof TableIndex) {
                throw new SchemaException("表 {$this->name} 的索引实例必须为 TableIndex");
            }
            if (in_array($index->name, $names, true)) {
                $existing = array_search($index->name, $names, true);

                throw new SchemaException(
                    "表 {$this->name} 索引名重复: {$names[$existing]} 与 {$index->name}"
                );
            }
            $names[] = $index->name;

            if ($index->columns === []) {
                throw new SchemaException("表 {$this->name} 索引 {$index->name} 未定义任何列");
            }
            $seen = [];
            foreach ($index->columns as $column) {
                if (in_array($column, $seen, true)) {
                    throw new SchemaException(
                        "表 {$this->name} 索引 {$index->name} 存在重复列: {$column}"
                    );
                }
                $seen[] = $column;
                if (!$this->hasColumn($column)) {
                    throw new SchemaException(
                        "表 {$this->name} 索引 {$index->name} 引用了不存在的列: {$column}"
                    );
                }
            }
        }
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
     * 单列语义的主键列：恰好一个主键列时返回它，0 个或复合主键（>=2 个）返回 null
     */
    public function primaryKey(): ?ColumnSchema
    {
        $found = null;
        foreach ($this->columns as $column) {
            if ($column->primaryKey) {
                if ($found !== null) {
                    return null;
                }
                $found = $column;
            }
        }

        return $found;
    }

    /**
     * 全部主键列（保持列定义顺序）；无主键返回空列表
     *
     * @return list<ColumnSchema>
     */
    public function primaryKeyColumns(): array
    {
        $result = [];
        foreach ($this->columns as $column) {
            if ($column->primaryKey) {
                $result[] = $column;
            }
        }

        return $result;
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
        return new self(
            $name,
            $this->columns,
            $this->uniqueKeys,
            $this->foreignKeys,
            $this->checks,
            $this->indexes,
        );
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

        return new self(
            $this->name,
            $columns,
            $this->uniqueKeys,
            $this->foreignKeys,
            $this->checks,
            $this->indexes,
        );
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
        foreach ($this->checks as $check) {
            if ($check->referencesColumn($name)) {
                throw new SchemaException(
                    "列 {$name} 被 CHECK 约束 {$check->name} 引用，不允许删除"
                );
            }
        }
        foreach ($this->indexes as $index) {
            if ($index->referencesColumn($name)) {
                throw new SchemaException(
                    "列 {$name} 被索引 {$index->name} 引用，不允许删除"
                );
            }
        }

        $columns = array_values(
            array_filter(
                $this->columns,
                static fn (ColumnSchema $column): bool => $column->name !== $name,
            ),
        );

        return new self(
            $this->name,
            $columns,
            $this->uniqueKeys,
            $this->foreignKeys,
            $this->checks,
            $this->indexes,
        );
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
                ? new ForeignKey(
                    $to,
                    $foreignKey->refTable,
                    $foreignKey->refColumn,
                    $foreignKey->onDelete,
                    $foreignKey->onUpdate,
                )
                : $foreignKey,
            $this->foreignKeys,
        );
        $checks = array_map(
            static fn (CheckConstraint $check): CheckConstraint => $check->withColumnRenamed($from, $to),
            $this->checks,
        );
        $indexes = array_map(
            static fn (TableIndex $index): TableIndex => $index->withColumnRenamed($from, $to),
            $this->indexes,
        );

        return new self($this->name, $columns, $uniqueKeys, $foreignKeys, $checks, $indexes);
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
            'checks' => array_map(
                static fn (CheckConstraint $check): array => $check->toArray(),
                $this->checks,
            ),
            'indexes' => array_map(
                static fn (TableIndex $index): array => $index->toArray(),
                $this->indexes,
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

        // 旧数据无 checks 键视为空列表
        $checksRaw = $data['checks'] ?? [];
        if (!is_array($checksRaw)) {
            throw new StorageException("表 {$name} 的 checks 必须为数组");
        }
        $checks = [];
        foreach ($checksRaw as $item) {
            if (!is_array($item)) {
                throw new StorageException("表 {$name} 的 CHECK 约束定义必须为数组");
            }
            $checks[] = CheckConstraint::fromArray($item);
        }

        // 旧数据无 indexes 键或为 null 视为空列表
        $indexesRaw = $data['indexes'] ?? null;
        $indexes = [];
        if ($indexesRaw !== null) {
            if (!is_array($indexesRaw)) {
                throw new StorageException("表 {$name} 的 indexes 必须为数组");
            }
            foreach ($indexesRaw as $item) {
                if (!is_array($item)) {
                    throw new StorageException("表 {$name} 的索引定义必须为数组");
                }
                $indexes[] = TableIndex::fromArray($item);
            }
        }

        return new self($name, $columns, $uniqueKeys, $foreignKeys, $checks, $indexes);
    }
}
