<?php

declare(strict_types=1);

namespace Kingbes\Psql\Schema;

use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Query\Condition\Condition;

/**
 * 表结构构建器：流畅定义列与约束
 *
 * 注：契约要求 AlterBlueprint 继承本类，故不可声明为 final
 */
class Blueprint
{
    /** 标识符（列名/表名）合法字符 */
    private const NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var list<ColumnDefinition> */
    private array $columns = [];

    /** @var list<ForeignKeyDefinition> */
    private array $foreignKeyDefinitions = [];

    /** @var list<list<string>> */
    private array $uniqueKeys = [];

    /** @var list<CheckConstraint> */
    private array $checks = [];

    /** @var list<TableIndex> */
    private array $indexes = [];

    // ---- 类型方法 ----

    /**
     * BIGINT + unsigned + 主键 + 自增
     */
    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->addColumn($name, DataType::BIGINT)
            ->unsigned()
            ->primaryKey()
            ->autoIncrement();
    }

    public function tinyint(string $name): ColumnDefinition
    {
        return $this->addColumn($name, DataType::TINYINT);
    }

    public function smallint(string $name): ColumnDefinition
    {
        return $this->addColumn($name, DataType::SMALLINT);
    }

    public function int(string $name): ColumnDefinition
    {
        return $this->addColumn($name, DataType::INT);
    }

    public function bigint(string $name): ColumnDefinition
    {
        return $this->addColumn($name, DataType::BIGINT);
    }

    /**
     * 定长字符串，长度 1..255
     */
    public function char(string $name, int $length): ColumnDefinition
    {
        if ($length < 1 || $length > 255) {
            throw new SchemaException("CHAR 长度须在 1..255 之间，当前: {$length}");
        }

        return $this->addColumn($name, DataType::CHAR, length: $length);
    }

    /**
     * 变长字符串，长度 1..65535
     */
    public function varchar(string $name, int $length): ColumnDefinition
    {
        if ($length < 1 || $length > 65535) {
            throw new SchemaException("VARCHAR 长度须在 1..65535 之间，当前: {$length}");
        }

        return $this->addColumn($name, DataType::VARCHAR, length: $length);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn($name, DataType::TEXT);
    }

    /**
     * 精确小数，1 <= scale <= precision <= 65
     */
    public function decimal(string $name, int $precision, int $scale): ColumnDefinition
    {
        if ($precision < 1 || $precision > 65 || $scale < 1 || $scale > $precision) {
            throw new SchemaException(
                "DECIMAL 要求 1 <= scale({$scale}) <= precision({$precision}) <= 65"
            );
        }

        return $this->addColumn($name, DataType::DECIMAL, precision: $precision, scale: $scale);
    }

    public function float(string $name): ColumnDefinition
    {
        return $this->addColumn($name, DataType::FLOAT);
    }

    public function double(string $name): ColumnDefinition
    {
        return $this->addColumn($name, DataType::DOUBLE);
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn($name, DataType::BOOLEAN);
    }

    /**
     * 枚举，成员非空且唯一
     *
     * @param list<string> $values
     */
    public function enum(string $name, array $values): ColumnDefinition
    {
        if ($values === []) {
            throw new SchemaException("枚举列 {$name} 的成员列表不能为空");
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new SchemaException("枚举列 {$name} 的成员必须为字符串");
            }
        }
        if (count(array_unique($values)) !== count($values)) {
            throw new SchemaException("枚举列 {$name} 的成员必须唯一");
        }

        return $this->addColumn($name, DataType::ENUM, enumValues: array_values($values));
    }

    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn($name, DataType::DATE);
    }

    public function datetime(string $name): ColumnDefinition
    {
        return $this->addColumn($name, DataType::DATETIME);
    }

    public function timestamp(string $name): ColumnDefinition
    {
        return $this->addColumn($name, DataType::TIMESTAMP);
    }

    // ---- 约束 DSL ----

    /**
     * 联合唯一（也接受单列）；列必须已定义
     */
    public function unique(string ...$columns): void
    {
        if ($columns === []) {
            throw new SchemaException('唯一约束至少需要一列');
        }
        $defined = [];
        foreach ($this->columns as $definition) {
            $defined[] = $definition->name();
        }
        foreach ($columns as $column) {
            if (!in_array($column, $defined, true)) {
                throw new SchemaException("唯一约束引用了未定义的列: {$column}");
            }
        }
        $this->uniqueKeys[] = array_values($columns);
    }

    /**
     * 复合主键（也接受单列）；列必须已定义，列内重复抛 SchemaException
     */
    public function primary(string ...$columns): void
    {
        if ($columns === []) {
            throw new SchemaException('复合主键至少需要一列');
        }
        $defined = [];
        foreach ($this->columns as $definition) {
            $defined[] = $definition->name();
        }
        $seen = [];
        foreach ($columns as $column) {
            if (!in_array($column, $defined, true)) {
                throw new SchemaException("复合主键引用了未定义的列: {$column}");
            }
            if (in_array($column, $seen, true)) {
                throw new SchemaException("复合主键列存在重复: {$column}");
            }
            $seen[] = $column;
        }
        foreach ($this->columns as $definition) {
            if (in_array($definition->name(), $seen, true)) {
                $definition->primaryKey();
            }
        }
    }

    /**
     * 外键 DSL 入口（构造时即注册进内部列表）
     */
    public function foreignKey(string $column): ForeignKeyDefinition
    {
        return new ForeignKeyDefinition($this, $column);
    }

    /**
     * 注册外键定义（由 ForeignKeyDefinition 构造器调用）
     *
     * @internal
     */
    public function registerForeignKey(ForeignKeyDefinition $definition): void
    {
        $this->foreignKeyDefinitions[] = $definition;
    }

    /**
     * CHECK 约束：condition 求值为假的行禁止写入（insert 默认回填后 / update 应用新值后求值）；
     * 名字重复抛 SchemaException；条件值非标量/null 注册时即抛 SchemaException；
     * 空 ConditionGroup 允许（恒真）
     */
    public function check(string $name, Condition $condition): void
    {
        foreach ($this->checks as $existing) {
            if ($existing->name === $name) {
                throw new SchemaException("CHECK 约束名重复: {$name}");
            }
        }
        $condition->assertScalarValues();
        $this->checks[] = new CheckConstraint($name, $condition);
    }

    /**
     * 建表时定义二级索引；索引名自动生成 idx_<col1>_<col2>...；
     * 空参数/列内重复/同一列组合重复定义抛 SchemaException；
     * 列存在性不在此校验（列可能后定义），由 TableSchema 构造器统一校验
     */
    public function index(string ...$columns): void
    {
        if ($columns === []) {
            throw new SchemaException('索引至少需要一列');
        }
        $seen = [];
        foreach ($columns as $column) {
            if (in_array($column, $seen, true)) {
                throw new SchemaException("索引列存在重复: {$column}");
            }
            $seen[] = $column;
        }
        foreach ($this->indexes as $existing) {
            if ($existing->coversColumns(...$columns)) {
                throw new SchemaException(
                    '索引列组合重复定义: ' . implode(',', $columns)
                );
            }
        }

        $this->indexes[] = new TableIndex('idx_' . implode('_', $columns), array_values($columns));
    }

    // ---- 产出 ----

    /**
     * 产出 TableSchema；结构非法抛 SchemaException
     */
    public function toSchema(string $tableName): TableSchema
    {
        if (preg_match(self::NAME_PATTERN, $tableName) !== 1) {
            throw new SchemaException("非法表名: {$tableName}");
        }

        $columns = [];
        $names = [];
        foreach ($this->columns as $definition) {
            $column = $definition->toSchema();
            if (preg_match(self::NAME_PATTERN, $column->name) !== 1) {
                throw new SchemaException("非法列名: {$column->name}");
            }
            if (in_array($column->name, $names, true)) {
                throw new SchemaException("重复列名: {$column->name}");
            }
            $names[] = $column->name;
            $columns[] = $column;
        }

        $foreignKeys = [];
        foreach ($this->foreignKeyDefinitions as $definition) {
            $foreignKeys[] = $definition->toForeignKey();
        }

        return new TableSchema(
            $tableName,
            $columns,
            $this->uniqueKeys,
            $foreignKeys,
            $this->checks,
            $this->indexes,
        );
    }

    /**
     * 注册列定义并返回
     */
    private function addColumn(
        string $name,
        DataType $type,
        ?int $length = null,
        ?int $precision = null,
        ?int $scale = null,
        ?array $enumValues = null,
    ): ColumnDefinition {
        $definition = new ColumnDefinition($name, $type, $length, $precision, $scale, $enumValues);
        $this->columns[] = $definition;

        return $definition;
    }
}
