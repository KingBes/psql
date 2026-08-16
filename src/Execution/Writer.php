<?php

declare(strict_types=1);

namespace Kingbes\Psql\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\ConstraintException;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Query\Condition\Condition;
use Kingbes\Psql\Query\ConditionEvaluator;
use Kingbes\Psql\Result\InsertResult;
use Kingbes\Psql\Schema\ColumnSchema;
use Kingbes\Psql\Schema\ForeignKey;
use Kingbes\Psql\Schema\ForeignKeyAction;
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

            // CHECK 约束（AI 分配后可引用自增列；先于唯一检查）
            $this->assertChecks($table, $schema, $newRow);

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
        $this->connection->recordWrite();

        $lastRow = $accepted[count($accepted) - 1];

        return new InsertResult(count($accepted), $aiName === null ? null : (int) $lastRow[$aiName]);
    }

    // ---- UPSERT / INSERT IGNORE ----

    /**
     * 无冲突插入返回 1；命中唯一约束更新该行返回 2（MySQL 惯例）；
     * 命中多行（歧义）或其余约束违反抛 ConstraintException
     *
     * @param array<string,mixed> $row
     */
    public function upsert(string $table, ?string $alias, array $row): int
    {
        $db = $this->connection->currentDatabase();
        $engine = $this->connection->engine();
        $schema = $engine->loadSchema($db, $table);

        // 候选行：cast + 默认回填 + NOT NULL 校验（自增列未提供则暂不分配）
        $candidate = $this->buildInsertRow($schema, $table, $row);

        $existing = $engine->readRows($db, $table);
        $hitIndexes = $this->findUniqueConflictRows($schema, $candidate, $existing);

        if (count($hitIndexes) > 1) {
            throw new ConstraintException("upsert 唯一冲突命中多行（歧义）: {$table}");
        }
        if ($hitIndexes !== []) {
            return $this->upsertUpdate($db, $table, $schema, $row, $hitIndexes[0], $existing);
        }

        // 无冲突：走完整 insert 管线（AI 分配/唯一/FK/CHECK）
        $this->insert($table, $alias, [$row]);

        return 1;
    }

    /**
     * 无冲突插入返回 1；唯一冲突静默跳过返回 0（自增不消耗）；
     * 类型/NOT NULL/FK/CHECK 等其余约束违反仍抛异常
     *
     * @param array<string,mixed> $row
     */
    public function insertIgnore(string $table, ?string $alias, array $row): int
    {
        $db = $this->connection->currentDatabase();
        $engine = $this->connection->engine();
        $schema = $engine->loadSchema($db, $table);

        // 候选阶段即做类型 cast 与 NOT NULL 校验（其余约束不因唯一冲突而静默）
        $candidate = $this->buildInsertRow($schema, $table, $row);

        $hitIndexes = $this->findUniqueConflictRows(
            $schema,
            $candidate,
            $engine->readRows($db, $table),
        );
        if ($hitIndexes !== []) {
            return 0;
        }

        // 无冲突：走完整 insert 管线（FK/CHECK 照常校验）
        $this->insert($table, $alias, [$row]);

        return 1;
    }

    /**
     * upsert 更新路径：把 $row 提供的列（cast 后）覆盖到命中行，
     * 复用 update 的行级校验（NOT NULL/唯一排除自身/FK/CHECK）；返回 2
     *
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $existing
     */
    private function upsertUpdate(
        string $db,
        string $table,
        TableSchema $schema,
        array $row,
        int $hitIndex,
        array $existing,
    ): int {
        // 逐列 cast 并校验 NOT NULL（主键列隐含 NOT NULL）
        $casted = [];
        foreach ($schema->columns as $column) {
            if (!array_key_exists($column->name, $row)) {
                continue;
            }
            $casted[$column->name] = ValueCaster::cast($row[$column->name], $column);
            if (($column->notNull || $column->primaryKey) && $casted[$column->name] === null) {
                throw new ConstraintException("表 {$table} 列 {$column->name} 不允许为 NULL");
            }
        }

        // 覆盖到命中行（未提供的列保持原值；AI 主键显式提供且与命中行相等为无害覆盖）
        $newRow = array_merge($existing[$hitIndex], $casted);

        // 行级校验：唯一（排除自身）+ FK 存在性 + CHECK
        $this->assertUpdateUnique($schema, $table, $existing, [$hitIndex => $newRow]);
        $this->assertForeignKeys($db, $table, $schema, $newRow);
        $this->assertChecks($table, $schema, $newRow);

        $existing[$hitIndex] = $newRow;
        $this->connection->engine()->writeRows($db, $table, $existing);
        $this->connection->recordWrite();

        return 2;
    }

    /**
     * 冲突扫描：候选行与现存行在每个唯一约束下元组全非 null 且逐值相等
     * （compareValues '=' 语义）时记录命中行索引（去重）
     *
     * @param array<string,mixed> $candidate
     * @param list<array<string,mixed>> $existing
     * @return list<int>
     */
    private function findUniqueConflictRows(TableSchema $schema, array $candidate, array $existing): array
    {
        $hit = [];
        foreach ($existing as $index => $row) {
            foreach ($this->uniqueColumnGroups($schema) as $group) {
                $hasNull = false;
                $matched = true;
                foreach ($group as $name) {
                    $candidateValue = $candidate[$name] ?? null;
                    $rowValue = $row[$name] ?? null;
                    if ($candidateValue === null || $rowValue === null) {
                        $hasNull = true;
                        break;
                    }
                    if (!ConditionEvaluator::compareValues($candidateValue, '=', $rowValue)) {
                        $matched = false;
                        break;
                    }
                }
                if (!$hasNull && $matched) {
                    $hit[$index] = true;
                    break;
                }
            }
        }

        return array_keys($hit);
    }

    // ---- UPDATE ----

    /**
     * 按条件更新，返回受影响（matched）行数；
     * 被引用列变化时按引用方外键的 onUpdate 策略分发（RESTRICT/CASCADE/SET_NULL/SET_DEFAULT）
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

        // 先整体 cast 并校验 NOT NULL（主键列隐含 NOT NULL）
        $casted = [];
        foreach ($schema->columns as $column) {
            if (!array_key_exists($column->name, $values)) {
                continue;
            }
            $casted[$column->name] = ValueCaster::cast($values[$column->name], $column);
            if (($column->notNull || $column->primaryKey) && $casted[$column->name] === null) {
                throw new ConstraintException("表 {$table} 列 {$column->name} 不允许为 NULL");
            }
        }

        $rows = $engine->readRows($db, $table);
        // 子查询条件先经 SubqueryResolver 解析（在约束检查之前，子查询执行失败即在写路径抛出）
        $where = $where === null ? null : (new SubqueryResolver($this->connection))->resolve($where);
        $collations = self::collationsOf($schema);
        $matched = [];
        foreach ($rows as $index => $row) {
            if ($where === null || ConditionEvaluator::evaluate($row, $where, $collations)) {
                $matched[] = $index;
            }
        }

        // matched 行的新行
        $newRows = [];
        foreach ($matched as $index) {
            $newRows[$index] = array_merge($rows[$index], $casted);
        }

        // 引用本表且指向本次更新列的外键；被引用列值实际变化时需按 onUpdate 策略处理
        $referencingFks = $this->referencingForeignKeys($db, $table, array_keys($casted));
        $referencedChanged = false;
        if ($referencingFks !== []) {
            foreach ($referencingFks as $entry) {
                $refColumn = $entry['fk']->refColumn;
                foreach ($matched as $index) {
                    $old = $rows[$index][$refColumn] ?? null;
                    if (!ConditionEvaluator::compareValues($old, '=', $casted[$refColumn])) {
                        $referencedChanged = true;
                        break 2;
                    }
                }
            }
        }

        // 唯一性与 FK 存在性先按既有语义检查（matched 新行 vs 非 matched 原行 / casted 值存在性）
        $this->assertUpdateUnique($schema, $table, $rows, $newRows);
        $this->assertUpdateForeignKeyValues($db, $table, $schema, $casted);

        // CHECK 约束（统一放在唯一检查后；matched 新行应用新值求值）
        foreach ($newRows as $newRow) {
            $this->assertChecks($table, $schema, $newRow);
        }

        // 简单路径：无被引用列变化，直接写回
        if (!$referencedChanged) {
            foreach ($newRows as $index => $newRow) {
                $rows[$index] = $newRow;
            }
            $engine->writeRows($db, $table, $rows);
            $this->connection->recordWrite();

            return count($matched);
        }

        // RESTRICT：v1 兼容——被引用列值变化即拦截（消息含引用方表名）
        foreach ($referencingFks as $entry) {
            if ($entry['fk']->onUpdate !== ForeignKeyAction::RESTRICT) {
                continue;
            }
            $refColumn = $entry['fk']->refColumn;
            foreach ($matched as $index) {
                $old = $rows[$index][$refColumn] ?? null;
                if (!ConditionEvaluator::compareValues($old, '=', $casted[$refColumn])) {
                    throw new ConstraintException(
                        "表 {$table} 列 {$refColumn} 被表 {$entry['table']} 的外键引用，禁止变更 (RESTRICT)"
                    );
                }
            }
        }

        // 全库结构/行缓存（传播需要跨表扫描）
        $schemas = [];
        $allRows = [];
        foreach ($engine->tables($db) as $name) {
            $schemas[$name] = $engine->loadSchema($db, $name);
            $allRows[$name] = $name === $table ? $rows : $engine->readRows($db, $name);
        }

        // 本表 matched 行先应用新值（内存）
        foreach ($newRows as $index => $newRow) {
            $allRows[$table][$index] = $newRow;
        }

        // BFS 传播：初始节点为本表 matched 行的被引用列变化
        $pendingTables = [$table => true];
        $visited = [$table => array_fill_keys($matched, true)];
        $changedColumns = [];
        foreach ($referencingFks as $entry) {
            $changedColumns[$entry['fk']->refColumn] = true;
        }
        $queue = [];
        foreach ($matched as $index) {
            foreach (array_keys($changedColumns) as $column) {
                $old = $rows[$index][$column] ?? null;
                $new = $casted[$column];
                if (!ConditionEvaluator::compareValues($old, '=', $new)) {
                    $queue[] = [$table, $index, $column, $old, $new];
                }
            }
        }

        while ($queue !== []) {
            [$srcTable, $srcIndex, $srcColumn, $oldValue, $newValue] = array_shift($queue);
            foreach ($schemas as $refTableName => $refSchema) {
                foreach ($refSchema->foreignKeys as $fk) {
                    if ($fk->refTable !== $srcTable || $fk->refColumn !== $srcColumn) {
                        continue;
                    }
                    foreach ($allRows[$refTableName] as $refIndex => $refRow) {
                        $refValue = $refRow[$fk->column] ?? null;
                        if ($refValue === null
                            || !ConditionEvaluator::compareValues($refValue, '=', $oldValue)
                        ) {
                            continue;
                        }
                        $action = $fk->onUpdate;
                        if ($action === ForeignKeyAction::CASCADE) {
                            // (表, 行号) visited 防环（自引用 FK 必须）
                            if (isset($visited[$refTableName][$refIndex])) {
                                continue;
                            }
                            $visited[$refTableName][$refIndex] = true;
                            $fkColumn = $refSchema->columnOrFail($fk->column);
                            $castNew = ValueCaster::cast($newValue, $fkColumn);
                            if ($fkColumn->notNull && $castNew === null) {
                                throw new ConstraintException(
                                    "表 {$refTableName} 列 {$fk->column} 不允许为 NULL"
                                );
                            }
                            $allRows[$refTableName][$refIndex][$fk->column] = $castNew;
                            $pendingTables[$refTableName] = true;
                            // 传播链上被引用列再变化则继续入队
                            $queue[] = [$refTableName, $refIndex, $fk->column, $oldValue, $newValue];
                            continue;
                        }
                        if ($action === ForeignKeyAction::SET_NULL) {
                            // DDL 已保证该列可空
                            $allRows[$refTableName][$refIndex][$fk->column] = null;
                        } else {
                            // SET_DEFAULT：置经 cast 规范化的列默认值；存在性由下方行级 FK 复检覆盖
                            $fkColumn = $refSchema->columnOrFail($fk->column);
                            $allRows[$refTableName][$refIndex][$fk->column] = ValueCaster::cast(
                                $fkColumn->default,
                                $fkColumn,
                            );
                        }
                        $pendingTables[$refTableName] = true;
                    }
                }
            }
        }

        // 涉及表统一复检：唯一（全表元组计数）+ 行级 FK 存在性 + CHECK（基于内存最终状态，覆盖传播行）
        foreach (array_keys($pendingTables) as $name) {
            $this->assertTableUniqueInRows($schemas[$name], $name, $allRows[$name]);
            $this->assertRowForeignKeysInRows($allRows, $schemas, $name);
            foreach ($allRows[$name] as $row) {
                $this->assertChecks($name, $schemas[$name], $row);
            }
        }

        foreach (array_keys($pendingTables) as $name) {
            $engine->writeRows($db, $name, $allRows[$name]);
        }
        $this->connection->recordWrite();

        return count($matched);
    }

    // ---- DELETE ----

    /**
     * 按条件删除（BFS 级联），返回初始 matched 行数（级联删除不计入）；
     * 引用方外键按 onDelete 策略分发：RESTRICT 拦截 / CASCADE 级联 / SET_NULL 置空 / SET_DEFAULT 置默认
     */
    public function delete(string $table, ?string $alias, ?Condition $where): int
    {
        $db = $this->connection->currentDatabase();
        $engine = $this->connection->engine();
        $schema = $engine->loadSchema($db, $table);

        $rows = $engine->readRows($db, $table);
        // 子查询条件先经 SubqueryResolver 解析（在约束检查之前，子查询执行失败即在写路径抛出）
        $where = $where === null ? null : (new SubqueryResolver($this->connection))->resolve($where);
        $collations = self::collationsOf($schema);
        $matched = [];
        foreach ($rows as $index => $row) {
            if ($where === null || ConditionEvaluator::evaluate($row, $where, $collations)) {
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
            $schemas[$name] = $engine->loadSchema($db, $name);
            if ($name !== $table) {
                $allRows[$name] = $engine->readRows($db, $name);
            }
        }

        // 以 (表名, 行索引) 为节点 BFS 收集级联删除集合；SET_NULL/SET_DEFAULT 就地修改引用行
        $deleteSet = [$table => array_fill_keys($matched, true)];
        $updatedTables = [];
        $defaultChecks = [];
        $queue = [];
        foreach ($matched as $index) {
            $queue[] = [$table, $index];
        }

        while ($queue !== []) {
            [$currentTable, $currentIndex] = array_shift($queue);
            $currentRow = $allRows[$currentTable][$currentIndex];

            foreach ($schemas as $refTableName => $refSchema) {
                foreach ($refSchema->foreignKeys as $fk) {
                    if ($fk->refTable !== $currentTable) {
                        continue;
                    }
                    $targetValue = $currentRow[$fk->refColumn] ?? null;
                    if ($targetValue === null) {
                        continue;
                    }
                    foreach ($allRows[$refTableName] as $refIndex => $refRow) {
                        $refValue = $refRow[$fk->column] ?? null;
                        if ($refValue === null
                            || !ConditionEvaluator::compareValues($refValue, '=', $targetValue)
                        ) {
                            continue;
                        }
                        $already = isset($deleteSet[$refTableName][$refIndex]);
                        $action = $fk->onDelete;
                        if ($action === ForeignKeyAction::CASCADE) {
                            if (!$already) {
                                $deleteSet[$refTableName][$refIndex] = true;
                                $queue[] = [$refTableName, $refIndex];
                            }
                            continue;
                        }
                        // 简化规则：引用行已在删除集合中则不视为冲突，避免自引用级联误杀
                        if ($already) {
                            continue;
                        }
                        if ($action === ForeignKeyAction::RESTRICT) {
                            throw new ConstraintException(
                                "表 {$refTableName} 列 {$fk->column} 引用待删除行，禁止删除 (RESTRICT)"
                            );
                        }
                        if ($action === ForeignKeyAction::SET_NULL) {
                            // DDL 已保证该列可空
                            $allRows[$refTableName][$refIndex][$fk->column] = null;
                        } else {
                            // SET_DEFAULT：置经 cast 规范化的列默认值；存在性 BFS 后统一判定
                            $fkColumn = $refSchema->columnOrFail($fk->column);
                            $allRows[$refTableName][$refIndex][$fk->column] = ValueCaster::cast(
                                $fkColumn->default,
                                $fkColumn,
                            );
                            $defaultChecks[] = [$refTableName, $refIndex, $fk];
                        }
                        $updatedTables[$refTableName] = true;
                    }
                }
            }
        }

        // SET_DEFAULT 存在性：默认值非 null 时须在"删除后剩余"的被引用行中存在；剩余集合为空视为满足
        foreach ($defaultChecks as [$checkTable, $checkIndex, $fk]) {
            $defaultValue = $allRows[$checkTable][$checkIndex][$fk->column];
            if ($defaultValue === null) {
                continue;
            }
            $hasRemaining = false;
            $exists = false;
            foreach ($allRows[$fk->refTable] as $refIndex => $refRow) {
                if (isset($deleteSet[$fk->refTable][$refIndex])) {
                    continue;
                }
                $hasRemaining = true;
                $refValue = $refRow[$fk->refColumn] ?? null;
                if ($refValue !== null
                    && ConditionEvaluator::compareValues($refValue, '=', $defaultValue)
                ) {
                    $exists = true;
                    break;
                }
            }
            if ($hasRemaining && !$exists) {
                throw new ConstraintException(
                    "表 {$checkTable} 外键 {$fk->column} 的默认值 {$defaultValue}"
                    . " 在 {$fk->refTable}.{$fk->refColumn} 中不存在"
                );
            }
        }

        // 每张涉及表一次性写回：
        // - 纯删除表（无 SET_NULL/SET_DEFAULT 行修改）走 deleteRows（PagedJson 墓碑增量收益）；
        // - 删除 + 行修改并存的表保持 writeRows 合并路径（正确性优先，墓碑收益放弃该场景）；
        // - 仅被 SET_NULL/SET_DEFAULT 修改的表整体 writeRows
        foreach ($deleteSet as $name => $indexes) {
            if (!isset($updatedTables[$name])) {
                $engine->deleteRows($db, $name, array_keys($indexes));
                continue;
            }
            $remaining = [];
            foreach ($allRows[$name] as $refIndex => $refRow) {
                if (!isset($indexes[$refIndex])) {
                    $remaining[] = $refRow;
                }
            }
            $engine->writeRows($db, $name, $remaining);
        }
        foreach (array_keys($updatedTables) as $name) {
            if (isset($deleteSet[$name])) {
                continue;
            }
            $engine->writeRows($db, $name, $allRows[$name]);
        }
        // deleteRows 与 writeRows 两种落盘路径统一在此记录写版本（索引缓存失效）
        $this->connection->recordWrite();

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
        $this->connection->recordWrite();
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

        // NOT NULL 校验（主键列隐含 NOT NULL；自增列值待分配，跳过）
        foreach ($schema->columns as $column) {
            $impliedNotNull = $column->notNull || $column->primaryKey;
            if ($impliedNotNull && !$column->autoIncrement && $newRow[$column->name] === null) {
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
     * 收集引用指定表且 refColumn 命中给定列名集合的外键
     *
     * @param list<string> $columns
     * @return list<array{table: string, fk: ForeignKey}>
     */
    private function referencingForeignKeys(string $db, string $table, array $columns): array
    {
        if ($columns === []) {
            return [];
        }
        $result = [];
        foreach ($this->connection->engine()->tables($db) as $name) {
            foreach ($this->connection->engine()->loadSchema($db, $name)->foreignKeys as $fk) {
                if ($fk->refTable === $table && in_array($fk->refColumn, $columns, true)) {
                    $result[] = ['table' => $name, 'fk' => $fk];
                }
            }
        }

        return $result;
    }

    /**
     * update 唯一性检查：排除自身，与非 matched 原行 + 其他 matched 新行比对
     *
     * @param list<array<string,mixed>> $rows
     * @param array<int, array<string,mixed>> $newRows
     */
    private function assertUpdateUnique(TableSchema $schema, string $table, array $rows, array $newRows): void
    {
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
    }

    /**
     * update 的 values 含 FK 列且新值非 null 时校验存在性（同一 values 对所有 matched 行一致）
     *
     * @param array<string,mixed> $casted
     */
    private function assertUpdateForeignKeyValues(
        string $db,
        string $table,
        TableSchema $schema,
        array $casted,
    ): void {
        foreach ($schema->foreignKeys as $fk) {
            if (!array_key_exists($fk->column, $casted) || $casted[$fk->column] === null) {
                continue;
            }
            if (!$this->connection->engine()->hasTable($db, $fk->refTable)
                || !$this->referenceExists($db, $fk->refTable, $fk->refColumn, $casted[$fk->column])
            ) {
                throw new ConstraintException(
                    "表 {$table} 外键 {$fk->column} 的值 {$casted[$fk->column]}"
                    . " 在 {$fk->refTable}.{$fk->refColumn} 中不存在"
                );
            }
        }
    }

    /**
     * 基于内存行集合的全表唯一检查（元组计数法，用于传播修改后的复检）
     *
     * @param list<array<string,mixed>> $rows
     */
    private function assertTableUniqueInRows(TableSchema $schema, string $table, array $rows): void
    {
        $tuples = [];
        foreach ($rows as $row) {
            foreach ($this->uniqueEntries($schema, $row) as $entry) {
                $tuples[$entry['tuple']]['count'] = ($tuples[$entry['tuple']]['count'] ?? 0) + 1;
                $tuples[$entry['tuple']]['columns'] = $entry['columns'];
            }
        }
        foreach ($tuples as $tuple) {
            if ($tuple['count'] > 1) {
                throw new ConstraintException(
                    "表 {$table} 唯一约束冲突，列: " . implode(', ', $tuple['columns'])
                );
            }
        }
    }

    /**
     * 基于内存行集合的行级 FK 存在性检查（null 跳过；覆盖传播/置空/置默认后的行）
     *
     * @param array<string, list<array<string,mixed>>> $allRows
     * @param array<string, TableSchema> $schemas
     */
    private function assertRowForeignKeysInRows(array $allRows, array $schemas, string $table): void
    {
        foreach ($allRows[$table] as $row) {
            foreach ($schemas[$table]->foreignKeys as $fk) {
                $value = $row[$fk->column] ?? null;
                if ($value === null) {
                    continue;
                }
                $exists = false;
                foreach ($allRows[$fk->refTable] ?? [] as $refRow) {
                    $refValue = $refRow[$fk->refColumn] ?? null;
                    if ($refValue !== null && ConditionEvaluator::compareValues($refValue, '=', $value)) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    throw new ConstraintException(
                        "表 {$table} 外键 {$fk->column} 的值 {$value}"
                        . " 在 {$fk->refTable}.{$fk->refColumn} 中不存在"
                    );
                }
            }
        }
    }

    /**
     * 目标表的 CI 列映射（裸列名 => true），供 where 求值传入 collations（约束/唯一/外键仍保持 CS）
     *
     * @return array<string, true>
     */
    private static function collationsOf(TableSchema $schema): array
    {
        $map = [];
        foreach ($schema->columns as $column) {
            if ($column->ci) {
                $map[$column->name] = true;
            }
        }

        return $map;
    }

    /**
     * CHECK 约束校验：任一 check 对行求值为假抛 ConstraintException（消息含表名与 check 名）
     *
     * @param array<string,mixed> $row
     */
    private function assertChecks(string $table, TableSchema $schema, array $row): void
    {
        foreach ($schema->checks as $check) {
            if (!ConditionEvaluator::evaluate($row, $check->condition)) {
                throw new ConstraintException("表 {$table} 违反 CHECK 约束 {$check->name}");
            }
        }
    }

    /**
     * 唯一约束列组集合：主键元组（复合主键整组）、单列 unique 标志列、联合 uniqueKeys
     *
     * @return list<list<string>>
     */
    private function uniqueColumnGroups(TableSchema $schema): array
    {
        $groups = [];
        $primaryKeyColumns = array_map(
            static fn (ColumnSchema $column): string => $column->name,
            $schema->primaryKeyColumns(),
        );
        if ($primaryKeyColumns !== []) {
            $groups[] = $primaryKeyColumns;
        }
        foreach ($schema->columns as $column) {
            if ($column->unique) {
                $groups[] = [$column->name];
            }
        }
        foreach ($schema->uniqueKeys as $key) {
            $groups[] = $key;
        }

        return $groups;
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
        $entries = [];
        foreach ($this->uniqueColumnGroups($schema) as $group) {
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
