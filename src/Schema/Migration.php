<?php

declare(strict_types=1);

namespace Kingbes\Psql\Schema;

use Kingbes\Psql\Connection;

/**
 * 迁移工具：schema diff（对比两个连接的库结构，生成可预览/可应用的 alter 计划）
 *
 * - diff($target, $current)：返回把 $current 库结构迁移为 $target 库结构的步骤列表（不执行）
 * - apply($target, $plan)：顺序执行计划（建表/删表/改表/索引），依赖 Connection 既有 DDL 校验
 *
 * 计划步骤 op：createTable / dropTable / alterTable（addColumn|dropColumn）/
 * createIndex / dropIndex / modifyColumn（drop+add，有数据丢失风险）/
 * note（唯一/外键/CHECK 差异提示，需手工处理——AlterBlueprint 不支持增删这些约束）
 */
final class Migration
{
    private function __construct()
    {
    }

    /**
     * 生成把 $current 库结构迁移为 $target 库结构的计划步骤（顺序：建表 → 改表 → 删表）
     *
     * @return list<array{op: string, ...}>
     */
    public static function diff(Connection $target, Connection $current): array
    {
        $targetTables = $target->tables();
        $currentTables = $current->tables();

        $plan = [];
        // 1) 目标有、当前无 → 建表
        foreach ($targetTables as $name) {
            if (!in_array($name, $currentTables, true)) {
                $plan[] = ['op' => 'createTable', 'name' => $name, 'schema' => $target->tableSchema($name)];
            }
        }
        // 2) 同名表 → 结构 diff
        foreach ($targetTables as $name) {
            if (!in_array($name, $currentTables, true)) {
                continue;
            }
            foreach (self::diffTable($target->tableSchema($name), $current->tableSchema($name)) as $step) {
                $plan[] = $step;
            }
        }
        // 3) 当前有、目标无 → 删表（最后执行，避免破坏引用）
        foreach ($currentTables as $name) {
            if (!in_array($name, $targetTables, true)) {
                $plan[] = ['op' => 'dropTable', 'name' => $name];
            }
        }

        return $plan;
    }

    /**
     * 单表结构 diff：列（增/删/改）+ 索引（增/删）+ 约束差异提示
     *
     * @return list<array{op: string, ...}>
     */
    public static function diffTable(TableSchema $target, TableSchema $current): array
    {
        $steps = [];
        $targetCols = [];
        foreach ($target->columns as $column) {
            $targetCols[$column->name] = $column;
        }
        $currentCols = [];
        foreach ($current->columns as $column) {
            $currentCols[$column->name] = $column;
        }

        // 新增列
        foreach ($targetCols as $name => $column) {
            if (!isset($currentCols[$name])) {
                $steps[] = ['op' => 'addColumn', 'table' => $target->name, 'column' => $column];
            }
        }
        // 修改列（drop + add，数据丢失风险；NOT NULL 无默认值无法回填 → 降级为手工提示）
        foreach ($targetCols as $name => $column) {
            if (isset($currentCols[$name]) && $column->toArray() !== $currentCols[$name]->toArray()) {
                if ($column->notNull && !$column->hasDefault && !$column->defaultNow) {
                    $steps[] = [
                        'op' => 'note',
                        'table' => $target->name,
                        'detail' => "列 {$name} 结构变化且为 NOT NULL 无默认值，无法自动回填，需手工迁移",
                    ];
                    continue;
                }
                $steps[] = [
                    'op' => 'modifyColumn',
                    'table' => $target->name,
                    'column' => $column,
                    'warning' => true,
                ];
            }
        }
        // 删除列
        foreach ($currentCols as $name => $column) {
            if (!isset($targetCols[$name])) {
                $steps[] = ['op' => 'dropColumn', 'table' => $target->name, 'column' => $name];
            }
        }

        // 索引：按 (名字, 列序) 匹配，差异 → 删/建
        $targetIndexes = [];
        foreach ($target->indexes as $index) {
            $targetIndexes[$index->name] = $index->columns;
        }
        $currentIndexes = [];
        foreach ($current->indexes as $index) {
            $currentIndexes[$index->name] = $index->columns;
        }
        foreach ($currentIndexes as $name => $columns) {
            if (!isset($targetIndexes[$name]) || $targetIndexes[$name] !== $columns) {
                $steps[] = ['op' => 'dropIndex', 'table' => $target->name, 'index' => $name];
            }
        }
        foreach ($targetIndexes as $name => $columns) {
            if (!isset($currentIndexes[$name]) || $currentIndexes[$name] !== $columns) {
                $steps[] = ['op' => 'createIndex', 'table' => $target->name, 'index' => $name, 'columns' => $columns];
            }
        }

        // 联合唯一 / 外键 / CHECK 差异提示（AlterBlueprint 不支持增删，需手工）
        if ($target->uniqueKeys != $current->uniqueKeys) {
            $steps[] = ['op' => 'note', 'table' => $target->name, 'detail' => '联合唯一约束存在差异，需手工处理'];
        }
        if (self::foreignKeysEqual($target->foreignKeys, $current->foreignKeys) === false) {
            $steps[] = ['op' => 'note', 'table' => $target->name, 'detail' => '外键约束存在差异，需手工处理'];
        }
        if (self::checksEqual($target->checks, $current->checks) === false) {
            $steps[] = ['op' => 'note', 'table' => $target->name, 'detail' => 'CHECK 约束存在差异，需手工处理'];
        }

        return $steps;
    }

    /**
     * 顺序执行迁移计划；约束校验透传 Connection 既有异常（唯一/外键/CHECK 差异的 note 步骤跳过）
     *
     * @param list<array{op: string, ...}> $plan
     */
    public static function apply(Connection $target, array $plan): void
    {
        foreach ($plan as $step) {
            match ($step['op']) {
                'createTable' => $target->createTable($step['name'], self::blueprintOf($step['schema'])),
                'dropTable' => $target->dropTable($step['name']),
                'addColumn' => $target->alterTable($step['table'], static function (AlterBlueprint $alter) use ($step): void {
                    self::defineColumn($alter, $step['column']);
                }),
                'dropColumn' => $target->alterTable($step['table'], static function (AlterBlueprint $alter) use ($step): void {
                    $alter->dropColumn($step['column']);
                }),
                'modifyColumn' => $target->alterTable($step['table'], static function (AlterBlueprint $alter) use ($step): void {
                    $alter->dropColumn($step['column']->name);
                    self::defineColumn($alter, $step['column']);
                }),
                'createIndex' => $target->createIndex($step['table'], $step['index'], ...$step['columns']),
                'dropIndex' => $target->dropIndex($step['table'], $step['index']),
                default => null, // note：提示步骤，不执行
            };
        }
    }

    // ---- 内部 ----

    /**
     * TableSchema → 建表闭包（供 createTable 应用；含列/唯一/外键/CHECK/索引全量定义）
     */
    private static function blueprintOf(TableSchema $schema): callable
    {
        return static function (Blueprint $b) use ($schema): void {
            foreach ($schema->columns as $column) {
                self::defineColumn($b, $column);
            }
            foreach ($schema->uniqueKeys as $key) {
                $b->unique(...$key);
            }
            foreach ($schema->foreignKeys as $fk) {
                $b->foreignKey($fk->column)
                    ->references($fk->refTable, $fk->refColumn)
                    ->onDelete($fk->onDelete)
                    ->onUpdate($fk->onUpdate);
            }
            foreach ($schema->checks as $check) {
                $b->check($check->name, $check->condition);
            }
            foreach ($schema->indexes as $index) {
                $b->index(...$index->columns);
            }
        };
    }

    /**
     * ColumnSchema → Blueprint 类型方法 + 修饰符（建表与 alterTable 新增共用）
     */
    private static function defineColumn(Blueprint $b, ColumnSchema $column): void
    {
        $definition = match ($column->type) {
            DataType::TINYINT => $b->tinyint($column->name),
            DataType::SMALLINT => $b->smallint($column->name),
            DataType::INT => $b->int($column->name),
            DataType::BIGINT => $b->bigint($column->name),
            DataType::DECIMAL => $b->decimal($column->name, $column->precision ?? 10, $column->scale ?? 2),
            DataType::FLOAT => $b->float($column->name),
            DataType::DOUBLE => $b->double($column->name),
            DataType::BOOLEAN => $b->boolean($column->name),
            DataType::CHAR => $b->char($column->name, $column->length ?? 1),
            DataType::VARCHAR => $b->varchar($column->name, $column->length ?? 255),
            DataType::TEXT => $b->text($column->name),
            DataType::ENUM => $b->enum($column->name, $column->enumValues ?? []),
            DataType::DATE => $b->date($column->name),
            DataType::DATETIME => $b->datetime($column->name),
            DataType::TIMESTAMP => $b->timestamp($column->name),
            DataType::JSON => $b->json($column->name),
            DataType::BLOB => $b->blob($column->name),
            DataType::BINARY => $b->binary($column->name, $column->length ?? 1),
            DataType::SET => $b->set($column->name, $column->enumValues ?? []),
        };

        if ($column->unsigned) {
            $definition->unsigned();
        }
        if ($column->notNull) {
            $definition->notNull();
        }
        if ($column->primaryKey) {
            $definition->primaryKey();
        }
        if ($column->autoIncrement) {
            $definition->autoIncrement();
        }
        if ($column->unique) {
            $definition->unique();
        }
        if ($column->ci) {
            $definition->ci();
        }
        if ($column->defaultNow) {
            $definition->defaultNow();
        } elseif ($column->hasDefault) {
            $definition->default($column->default);
        }
    }

    /**
     * 外键列表是否全等（键序不敏感比较 by ref 元组）
     *
     * @param list<ForeignKey> $a
     * @param list<ForeignKey> $b
     */
    private static function foreignKeysEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        $signature = static fn (ForeignKey $fk): string => json_encode($fk->toArray(), JSON_UNESCAPED_UNICODE);
        $setA = array_map($signature, $a);
        $setB = array_map($signature, $b);
        sort($setA);
        sort($setB);

        return $setA === $setB;
    }

    /**
     * CHECK 列表是否全等（键序不敏感比较 by 定义）
     *
     * @param list<CheckConstraint> $a
     * @param list<CheckConstraint> $b
     */
    private static function checksEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        $signature = static fn (CheckConstraint $check): string => json_encode($check->toArray(), JSON_UNESCAPED_UNICODE);
        $setA = array_map($signature, $a);
        $setB = array_map($signature, $b);
        sort($setA);
        sort($setB);

        return $setA === $setB;
    }
}
