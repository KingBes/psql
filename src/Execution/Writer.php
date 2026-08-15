<?php

declare(strict_types=1);

namespace Kingbes\Psql\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\ConstraintException;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Query\Condition\Condition;
use Kingbes\Psql\Query\ConditionEvaluator;
use Kingbes\Psql\Result\InsertResult;
use Kingbes\Psql\Schema\TableSchema;
use Kingbes\Psql\Type\ValueCaster;

/**
 * 写入约束管线：insert/update/delete/truncate 的约束校验与数据落库
 */
final class Writer
{
    public function __construct(private Connection $connection)
    {
    }

    // ---- INSERT ----

    /**
     * 插入一批行；表不存在透传 StorageException，约束违反抛 ConstraintException
     *
     * @param list<array<string,mixed>> $rows
     */
    public function insert(string $table, ?string $alias, array $rows): InsertResult
    {
        $db = $this->connection->currentDatabase();
        $engine = $this->connection->engine();
        $schema = $engine->loadSchema($db, $table);

        // 空批次：未产生任何插入，lastInsertId 一致语义为 null
        if ($rows === []) {
            return new InsertResult(0, null);
        }

        $existing = $engine->readRows($db, $table);
        $aiColumn = $schema->autoIncrementColumn();
        $aiName = $aiColumn?->name;
        $currentAi = $engine->autoIncrement($db, $table);

        // 唯一元组池（现存行）与自增已用值池（现存行 + 本批次）
        $tuples = [];
        $usedAi = [];
        foreach ($existing as $row) {
            foreach ($this->uniqueEntries($schema, $row) as $entry) {
                $tuples[$entry['tuple']] = true;
            }
            if ($aiName !== null && isset($row[$aiName])) {
                $usedAi[(string) $row[$aiName]] = true;
            }
        }

        $accepted = [];
        $nextAi = $currentAi;
        $maxUsedAi = $currentAi;
        foreach ($rows as $row) {
            $newRow = $this->buildInsertRow($schema, $table, $row);

            // 自增分配：显式提供合法；缺省/null 从当前已分配值 +1 起跳过冲突候选
            if ($aiName !== null) {
                $value = $newRow[$aiName];
                if ($value === null) {
                    do {
                        ++$nextAi;
                    } while (isset($usedAi[(string) $nextAi]));
                    $value = $nextAi;
                    $newRow[$aiName] = $value;
                }
                $usedAi[(string) $value] = true;
                $maxUsedAi = max($maxUsedAi, (int) $value);
            }

            // 唯一性：与现存行 + 本批次已接受行比对
            foreach ($this->uniqueEntries($schema, $newRow) as $entry) {
                if (isset($tuples[$entry['tuple']])) {
                    throw new ConstraintException(
                        "表 {$table} 唯一约束冲突，列: " . implode(', ', $entry['columns'])
                    );
                }
                $tuples[$entry['tuple']] = true;
            }

            // 外键存在性
            $this->assertForeignKeys($db, $table, $schema, $newRow);

            $accepted[] = $newRow;
        }

        $engine->writeRows($db, $table, array_merge($existing, $accepted));
        if ($aiName !== null && $maxUsedAi > $currentAi) {
            $engine->setAutoIncrement($db, $table, $maxUsedAi);
        }

        $lastRow = $accepted[count($accepted) - 1];

        return new InsertResult(count($accepted), $aiName === null ? null : (int) $lastRow[$aiName]);
    }

    // ---- UPDATE ----

    /**
     * 按条件更新，返回受影响（matched）行数
     *
     * @param array<string,mixed> $values
     */
    public function update(string $table, ?string $alias, ?Condition $where, array $values): int
    {
        $db = $this->connection->currentDatabase();
        $engine = $this->connection->engine();
        $schema = $engine->loadSchema($db, $table);

        foreach (array_keys($values) as $key) {
            if (!$schema->hasColumn((string) $key)) {
                throw new QueryException("未知列: {$table}.{$key}");
            }
        }

        // 先整体 cast 并校验 NOT NULL
        $casted = [];
        foreach ($schema->columns as $column) {
            if (!array_key_exists($column->name, $values)) {
                continue;
            }
            $casted[$column->name] = ValueCaster::cast($values[$column->name], $column);
            if ($column->notNull && $casted[$column->name] === null) {
                throw new ConstraintException("表 {$table} 列 {$column->name} 不允许为 NULL");
            }
        }

        $rows = $engine->readRows($db, $table);
        $matched = [];
        foreach ($rows as $index => $row) {
            if ($where === null || ConditionEvaluator::evaluate($row, $where)) {
                $matched[] = $index;
            }
        }

        // matched 行的新行
        $newRows = [];
        foreach ($matched as $index) {
            $newRows[$index] = array_merge($rows[$index], $casted);
        }

        // 唯一性：排除自身，与"非 matched 原行 + 其他 matched 新行"比对
        $tuples = [];
        foreach ($rows as $index => $row) {
            if (!array_key_exists($index, $newRows)) {
                foreach ($this->uniqueEntries($schema, $row) as $entry) {
                    $tuples[$entry['tuple']] = true;
                }
            }
        }
        foreach ($newRows as $newRow) {
            foreach ($this->uniqueEntries($schema, $newRow) as $entry) {
                if (isset($tuples[$entry['tuple']])) {
                    throw new ConstraintException(
                        "表 {$table} 唯一约束冲突，列: " . implode(', ', $entry['columns'])
                    );
                }
                $tuples[$entry['tuple']] = true;
            }
        }

        // 外键：values 含 FK 列且新值非 null 时校验存在性（同一 values 对所有 matched 行一致）
        foreach ($schema->foreignKeys as $fk) {
            if (!array_key_exists($fk->column, $casted) || $casted[$fk->column] === null) {
                continue;
            }
            if (!$engine->hasTable($db, $fk->refTable)
                || !$this->referenceExists($db, $fk->refTable, $fk->refColumn, $casted[$fk->column])
            ) {
                throw new ConstraintException(
                    "表 {$table} 外键 {$fk->column} 的值 {$casted[$fk->column]}"
                    . " 在 {$fk->refTable}.{$fk->refColumn} 中不存在"
                );
            }
        }

        // 被引用列变更 RESTRICT（v1 简化：不做被引用行区分，任何 matched 行变更即拦截）
        foreach ($engine->tables($db) as $refTableName) {
            foreach ($engine->loadSchema($db, $refTableName)->foreignKeys as $fk) {
                if ($fk->refTable !== $table || !array_key_exists($fk->refColumn, $casted)) {
                    continue;
                }
                foreach ($matched as $index) {
                    $old = $rows[$index][$fk->refColumn] ?? null;
                    if (!ConditionEvaluator::compareValues($old, '=', $casted[$fk->refColumn])) {
                        throw new ConstraintException(
                            "表 {$table} 列 {$fk->refColumn} 被表 {$refTableName} 的外键引用，禁止变更 (RESTRICT)"
                        );
                    }
                }
            }
        }

        foreach ($newRows as $index => $newRow) {
            $rows[$index] = $newRow;
        }
        $engine->writeRows($db, $table, $rows);

        return count($matched);
    }

    // ---- DELETE ----

    /**
     * 按条件删除（BFS 级联），返回初始 matched 行数（级联删除不计入）
     */
    public function delete(string $table, ?string $alias, ?Condition $where): int
    {
        $db = $this->connection->currentDatabase();
        $engine = $this->connection->engine();
        $engine->loadSchema($db, $table);

        $rows = $engine->readRows($db, $table);
        $matched = [];
        foreach ($rows as $index => $row) {
            if ($where === null || ConditionEvaluator::evaluate($row, $where)) {
                $matched[] = $index;
            }
        }
        if ($matched === []) {
            return 0;
        }

        // 全库结构/行缓存（BFS 需要跨表扫描）
        $schemas = [];
        $allRows = [$table => $rows];
        foreach ($engine->tables($db) as $name) {
            if ($name === $table) {
                $schemas[$name] = $engine->loadSchema($db, $name);
                continue;
            }
            $schemas[$name] = $engine->loadSchema($db, $name);
            $allRows[$name] = $engine->readRows($db, $name);
        }

        // 以 (表名, 行索引) 为节点 BFS 收集级联删除集合
        $deleteSet = [$table => array_fill_keys($matched, true)];
        $queue = [];
        foreach ($matched as $index) {
            $queue[] = [$table, $index];
        }

        while ($queue !== []) {
            [$currentTable, $currentIndex] = array_shift($queue);
            $currentRow = $allRows[$currentTable][$currentIndex];

            foreach ($schemas as $refTable => $refSchema) {
                foreach ($refSchema->foreignKeys as $fk) {
                    if ($fk->refTable !== $currentTable) {
                        continue;
                    }
                    $targetValue = $currentRow[$fk->refColumn] ?? null;
                    if ($targetValue === null) {
                        continue;
                    }
                    foreach ($allRows[$refTable] as $refIndex => $refRow) {
                        $refValue = $refRow[$fk->column] ?? null;
                        if ($refValue === null
                            || !ConditionEvaluator::compareValues($refValue, '=', $targetValue)
                        ) {
                            continue;
                        }
                        $already = isset($deleteSet[$refTable][$refIndex]);
                        if ($fk->onDeleteCascade) {
                            if (!$already) {
                                $deleteSet[$refTable][$refIndex] = true;
                                $queue[] = [$refTable, $refIndex];
                            }
                        } elseif (!$already) {
                            // 简化规则：引用行已在删除集合中则不视为冲突，避免自引用级联误杀
                            throw new ConstraintException(
                                "表 {$refTable} 列 {$fk->column} 引用待删除行，禁止删除 (RESTRICT)"
                            );
                        }
                    }
                }
            }
        }

        // 每张涉及表一次性过滤写回
        foreach ($deleteSet as $name => $indexes) {
            $remaining = [];
            foreach ($allRows[$name] as $refIndex => $refRow) {
                if (!isset($indexes[$refIndex])) {
                    $remaining[] = $refRow;
                }
            }
            $engine->writeRows($db, $name, $remaining);
        }

        return count($matched);
    }

    // ---- TRUNCATE ----

    /**
     * 清空表数据并重置自增；被外键引用抛 SchemaException
     */
    public function truncate(string $table): void
    {
        $db = $this->connection->currentDatabase();
        $engine = $this->connection->engine();
        $this->connection->assertTableNotReferenced($table);
        $engine->writeRows($db, $table, []);
        $engine->resetAutoIncrement($db, $table);
    }

    // ---- 内部 ----

    /**
     * 按结构逐列补全/cast 行值并做 NOT NULL 校验
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function buildInsertRow(TableSchema $schema, string $table, array $row): array
    {
        foreach (array_keys($row) as $key) {
            if (!$schema->hasColumn((string) $key)) {
                throw new QueryException("未知列: {$table}.{$key}");
            }
        }

        $newRow = [];
        foreach ($schema->columns as $column) {
            if (array_key_exists($column->name, $row)) {
                $newRow[$column->name] = ValueCaster::cast($row[$column->name], $column);
                continue;
            }
            if ($column->defaultNow && $column->type->isTemporal()) {
                $newRow[$column->name] = date('Y-m-d H:i:s');
                continue;
            }
            if ($column->hasDefault) {
                $newRow[$column->name] = ValueCaster::cast($column->default, $column);
                continue;
            }
            // 自增列缺省留待分配步骤；其余补 null
            $newRow[$column->name] = null;
        }

        // NOT NULL 校验（自增列值待分配，跳过）
        foreach ($schema->columns as $column) {
            if ($column->notNull && !$column->autoIncrement && $newRow[$column->name] === null) {
                throw new ConstraintException("表 {$table} 列 {$column->name} 不允许为 NULL");
            }
        }

        return $newRow;
    }

    /**
     * 行外键存在性校验；违反抛 ConstraintException
     *
     * @param array<string,mixed> $row
     */
    private function assertForeignKeys(string $db, string $table, TableSchema $schema, array $row): void
    {
        foreach ($schema->foreignKeys as $fk) {
            $value = $row[$fk->column] ?? null;
            if ($value === null) {
                continue;
            }
            if (!$this->connection->engine()->hasTable($db, $fk->refTable)) {
                throw new ConstraintException(
                    "表 {$table} 外键 {$fk->column} 引用的表不存在: {$fk->refTable}"
                );
            }
            if (!$this->referenceExists($db, $fk->refTable, $fk->refColumn, $value)) {
                throw new ConstraintException(
                    "表 {$table} 外键 {$fk->column} 的值 {$value}"
                    . " 在 {$fk->refTable}.{$fk->refColumn} 中不存在"
                );
            }
        }
    }

    /**
     * 引用表中是否存在等值行（compareValues 语义比对）
     */
    private function referenceExists(string $db, string $refTable, string $refColumn, mixed $value): bool
    {
        $refSchema = $this->connection->engine()->loadSchema($db, $refTable);
        if (!$refSchema->hasColumn($refColumn)) {
            return false;
        }
        foreach ($this->connection->engine()->readRows($db, $refTable) as $refRow) {
            $refValue = $refRow[$refColumn] ?? null;
            if ($refValue !== null && ConditionEvaluator::compareValues($refValue, '=', $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 计算行在各唯一约束（主键、单列 unique、联合 uniqueKeys）下的元组；
     * 任一值为 null 的约束整体跳过（MySQL UNIQUE 允许多个 NULL）
     *
     * @param array<string,mixed> $row
     * @return list<array{tuple: string, columns: list<string>}>
     */
    private function uniqueEntries(TableSchema $schema, array $row): array
    {
        $groups = [];
        $primaryKey = $schema->primaryKey();
        if ($primaryKey !== null) {
            $groups[] = [$primaryKey->name];
        }
        foreach ($schema->columns as $column) {
            if ($column->unique) {
                $groups[] = [$column->name];
            }
        }
        foreach ($schema->uniqueKeys as $key) {
            $groups[] = $key;
        }

        $entries = [];
        foreach ($groups as $group) {
            $values = [];
            $hasNull = false;
            foreach ($group as $name) {
                $value = $row[$name] ?? null;
                if ($value === null) {
                    $hasNull = true;
                    break;
                }
                $values[] = $value;
            }
            if (!$hasNull) {
                $entries[] = [
                    'tuple' => json_encode($values, JSON_UNESCAPED_UNICODE),
                    'columns' => $group,
                ];
            }
        }

        return $entries;
    }
}
