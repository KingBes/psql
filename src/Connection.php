<?php

declare(strict_types=1);

namespace Kingbes\Psql;

use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Exception\TransactionException;
use Kingbes\Psql\Execution\IndexManager;
use Kingbes\Psql\Execution\Trigger;
use Kingbes\Psql\Execution\TriggerManager;
use Kingbes\Psql\Query\SelectBuilder;
use Kingbes\Psql\Query\Table;
use Kingbes\Psql\Query\ViewDefinition;
use Kingbes\Psql\Schema\AlterBlueprint;
use Kingbes\Psql\Schema\Blueprint;
use Kingbes\Psql\Schema\ColumnSchema;
use Kingbes\Psql\Schema\TableIndex;
use Kingbes\Psql\Schema\TableSchema;
use Kingbes\Psql\Storage\EngineSnapshot;
use Kingbes\Psql\Storage\StorageEngine;
use Kingbes\Psql\Type\ValueCaster;

/**
 * 数据库连接：库/表/结构变更与 DML 入口的统一门面
 */
final class Connection
{
    /** 表引用语法：'user' / 'user as u' / 'user u'（as 大小写不敏感） */
    private const TABLE_REF_PATTERN = '/^([A-Za-z_][A-Za-z0-9_]*)(?:\s+(?:as\s+)?([A-Za-z_][A-Za-z0-9_]*))?$/i';

    private string $database;

    private ?EngineSnapshot $transactionSnapshot = null;

    /** 事务内保存点栈（栈序即建立序；元素含名字与建立时的引擎快照）
     *
     * @var list<array{name: string, snapshot: EngineSnapshot}>
     */
    private array $savepoints = [];

    /** 数据版本号：任何数据/结构变更后自增（索引缓存失效依据） */
    private int $writeVersion = 0;

    private ?IndexManager $indexManager = null;

    private ?TriggerManager $triggerManager = null;

    public function __construct(private StorageEngine $engine, string $database = 'main')
    {
        // 数据库不存在则自动创建（保证连接后即可用）
        if (!$engine->hasDatabase($database)) {
            $engine->createDatabase($database);
        }
        $this->database = $database;
    }

    public function engine(): StorageEngine
    {
        return $this->engine;
    }

    public function currentDatabase(): string
    {
        return $this->database;
    }

    // ---- 数据库操作 ----

    /**
     * @return list<string>
     */
    public function databases(): array
    {
        return $this->engine->databases();
    }

    public function hasDatabase(string $name): bool
    {
        return $this->engine->hasDatabase($name);
    }

    /**
     * 委托引擎创建（重名/非法名由引擎抛 StorageException）
     */
    public function createDatabase(string $name): void
    {
        $this->engine->createDatabase($name);
    }

    /**
     * 删除数据库；删除当前库时自动切回 main（不存在则创建）
     */
    public function dropDatabase(string $name): void
    {
        $this->engine->dropDatabase($name);
        if ($name === $this->database) {
            $this->switchToMain();
        }
        $this->recordWrite();
    }

    /**
     * 切换当前数据库；不存在抛 SchemaException
     */
    public function use(string $name): void
    {
        if (!$this->engine->hasDatabase($name)) {
            throw new SchemaException("数据库不存在: {$name}");
        }
        $this->database = $name;
    }

    // ---- 备份 ----

    /**
     * 全库备份：当前库的完整一致性快照导出到目标目录（文件引擎：复制库目录全部文件）
     *
     * 目标目录须不存在或为空目录，否则抛 StorageException；
     * 活动事务中调用抛 TransactionException；内存引擎抛 StorageException
     * 备份目录即合法库目录：可用 Psql::connect(备份目录) 直接打开（加密库的备份同为密文，需原 key）
     */
    public function backup(string $targetDir): void
    {
        if ($this->transactionSnapshot !== null) {
            throw new TransactionException('事务中无法备份');
        }
        $this->engine->backupDatabase($this->database, $targetDir);
    }

    // ---- 表操作 ----

    /**
     * @param callable(Blueprint): void $definition
     */
    public function createTable(string $name, callable $definition): void
    {
        $schema = $this->buildSchema($name, $definition);
        if ($this->engine->hasTable($this->database, $name)) {
            throw new SchemaException("表已存在: {$this->database}.{$name}");
        }
        $this->engine->createTable($this->database, $schema);
        $this->recordWrite();
    }

    /**
     * @param callable(Blueprint): void $definition
     */
    public function createTableIfNotExists(string $name, callable $definition): void
    {
        if ($this->engine->hasTable($this->database, $name)) {
            return;
        }
        $this->engine->createTable($this->database, $this->buildSchema($name, $definition));
        $this->recordWrite();
    }

    /**
     * 删除表；不存在或被外键引用抛 SchemaException
     */
    public function dropTable(string $name): void
    {
        if (!$this->engine->hasTable($this->database, $name)) {
            throw new SchemaException("表不存在: {$this->database}.{$name}");
        }
        $this->assertTableNotReferenced($name);
        $this->engine->dropTable($this->database, $name);
        $this->recordWrite();
    }

    public function hasTable(string $name): bool
    {
        return $this->engine->hasTable($this->database, $name);
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return $this->engine->tables($this->database);
    }

    /**
     * 委托引擎重命名（from 不存在/to 已存在由引擎抛 StorageException）
     */
    public function renameTable(string $from, string $to): void
    {
        $this->engine->renameTable($this->database, $from, $to);
        $this->recordWrite();
    }

    /**
     * 变更表结构并迁移既有行数据
     *
     * @param callable(AlterBlueprint): void $definition
     */
    public function alterTable(string $name, callable $definition): void
    {
        $alter = new AlterBlueprint();
        $definition($alter);
        // 表不存在时 loadSchema 抛 StorageException，直接透传
        $old = $this->engine->loadSchema($this->database, $name);

        $dropped = $alter->droppedColumns();
        $renamed = $alter->renamedColumns();

        foreach ($dropped as $column) {
            if (!$old->hasColumn($column)) {
                throw new SchemaException("待删除的列不存在: {$name}.{$column}");
            }
        }
        foreach ($renamed as $from => $to) {
            if (!$old->hasColumn($from)) {
                throw new SchemaException("待重命名的列不存在: {$name}.{$from}");
            }
        }

        // 新增列经类型方法定义，toSchema 顺带完成列名/结构合法性校验
        $addedColumns = $alter->toSchema($name)->columns;

        // 剩余列 = 旧列去除待删除与待重命名来源
        $remaining = [];
        foreach ($old->columns as $column) {
            if (!in_array($column->name, $dropped, true) && !array_key_exists($column->name, $renamed)) {
                $remaining[] = $column->name;
            }
        }

        // 新增列不得与剩余列/重命名目标重名；NOT NULL 且无默认值无法回填既有行
        $occupied = array_merge($remaining, array_values($renamed));
        $addedNames = [];
        foreach ($addedColumns as $column) {
            if (in_array($column->name, $occupied, true)) {
                throw new SchemaException("新增列与现有列重名: {$column->name}");
            }
            if ($column->notNull && !$column->hasDefault && !$column->defaultNow) {
                throw new SchemaException("新增列 {$column->name} 为 NOT NULL 且无默认值，无法回填既有行");
            }
            $addedNames[] = $column->name;
        }

        // 重命名目标不得与剩余列/新增列冲突
        $occupiedByAdd = array_merge($remaining, $addedNames);
        foreach ($renamed as $to) {
            if (in_array($to, $occupiedByAdd, true)) {
                throw new SchemaException("重命名目标列名冲突: {$to}");
            }
        }

        // 计算新结构：先 rename（同步约束引用），再 drop（约束列自动抛），最后追加新增列
        $schema = $old;
        foreach ($renamed as $from => $to) {
            $schema = $schema->renameColumn($from, $to);
        }
        foreach ($dropped as $column) {
            $schema = $schema->dropColumn($column);
        }
        $schema = new TableSchema(
            $schema->name,
            array_merge($schema->columns, $addedColumns),
            $schema->uniqueKeys,
            $schema->foreignKeys,
            $schema->checks,
            $schema->indexes,
        );

        // 数据迁移：rename 键、删除 dropped 键、新增列回填默认值
        $rows = [];
        foreach ($this->engine->readRows($this->database, $name) as $row) {
            foreach ($renamed as $from => $to) {
                if (array_key_exists($from, $row)) {
                    $row[$to] = $row[$from];
                    unset($row[$from]);
                }
            }
            foreach ($dropped as $column) {
                unset($row[$column]);
            }
            foreach ($addedColumns as $column) {
                $row[$column->name] = $this->backfill($column);
            }
            $rows[] = $row;
        }

        $this->engine->replaceSchema($this->database, $name, $schema);
        $this->engine->writeRows($this->database, $name, $rows);
        $this->recordWrite();
    }

    /**
     * 检查表是否被当前库中任何表的外键引用；被引用抛 SchemaException（消息含引用方表名）
     */
    public function assertTableNotReferenced(string $table): void
    {
        foreach ($this->engine->tables($this->database) as $name) {
            foreach ($this->engine->loadSchema($this->database, $name)->foreignKeys as $foreignKey) {
                if ($foreignKey->refTable === $table) {
                    throw new SchemaException("表 {$table} 被表 {$name} 的外键引用，无法删除");
                }
            }
        }
    }

    // ---- 索引 DDL ----

    /**
     * 创建二级索引（仅注册元数据，物理构建由执行层负责）；
     * 表不存在透传 StorageException；索引名非法/重名/列不存在或重复抛 SchemaException
     */
    public function createIndex(string $table, string $name, string ...$columns): void
    {
        // 表不存在时 loadSchema 抛 StorageException，直接透传
        $schema = $this->engine->loadSchema($this->database, $table);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new SchemaException("非法索引名: {$name}");
        }
        foreach ($schema->indexes as $existing) {
            if ($existing->name === $name) {
                throw new SchemaException(
                    "表 {$this->database}.{$table} 已存在同名索引: {$name}"
                );
            }
        }

        // 列存在性与列内不重复交给 TableSchema 构造器统一校验
        $newSchema = new TableSchema(
            $schema->name,
            $schema->columns,
            $schema->uniqueKeys,
            $schema->foreignKeys,
            $schema->checks,
            array_merge($schema->indexes, [new TableIndex($name, array_values($columns))]),
        );
        $this->engine->replaceSchema($this->database, $table, $newSchema);
        $this->recordWrite();
    }

    /**
     * 删除二级索引；表不存在透传 StorageException，索引不存在抛 SchemaException
     */
    public function dropIndex(string $table, string $name): void
    {
        // 表不存在时 loadSchema 抛 StorageException，直接透传
        $schema = $this->engine->loadSchema($this->database, $table);
        $indexes = [];
        $found = false;
        foreach ($schema->indexes as $index) {
            if ($index->name === $name) {
                $found = true;
                continue;
            }
            $indexes[] = $index;
        }
        if (!$found) {
            throw new SchemaException("索引不存在: {$this->database}.{$table}.{$name}");
        }

        $this->engine->replaceSchema(
            $this->database,
            $table,
            new TableSchema(
                $schema->name,
                $schema->columns,
                $schema->uniqueKeys,
                $schema->foreignKeys,
                $schema->checks,
                $indexes,
            ),
        );
        $this->recordWrite();
    }

    /**
     * 是否存在指定索引；表不存在透传 StorageException
     */
    public function hasIndex(string $table, string $name): bool
    {
        foreach ($this->engine->loadSchema($this->database, $table)->indexes as $index) {
            if ($index->name === $name) {
                return true;
            }
        }

        return false;
    }

    // ---- 视图操作 ----

    /**
     * 创建视图：把一个 SelectBuilder 的查询固化为命名视图（与表/其他视图同库命名空间互斥）
     *
     * 名称规则同表名；与表名/其他视图名冲突抛 SchemaException；
     * 查询包含不可持久化部分（子查询条件/投影表达式）抛 QueryException
     */
    public function createView(string $name, SelectBuilder $query): void
    {
        $this->assertValidViewName($name);
        if ($this->engine->hasTable($this->database, $name)) {
            throw new SchemaException("名称已被表占用: {$this->database}.{$name}");
        }
        $definitions = $this->engine->loadViewDefinitions($this->database);
        if (isset($definitions[$name])) {
            throw new SchemaException("视图已存在: {$this->database}.{$name}");
        }

        // 立即序列化校验可持久化性（子查询条件等在此时转抛 QueryException，消息清晰）
        $definitions[$name] = ViewDefinition::fromQuery($name, $query->toQuery())->toArray();
        $this->engine->saveViewDefinitions($this->database, $definitions);
        $this->recordWrite();
    }

    /**
     * 删除视图；不存在抛 SchemaException
     */
    public function dropView(string $name): void
    {
        $definitions = $this->engine->loadViewDefinitions($this->database);
        if (!isset($definitions[$name])) {
            throw new SchemaException("视图不存在: {$this->database}.{$name}");
        }
        unset($definitions[$name]);
        $this->engine->saveViewDefinitions($this->database, $definitions);
        $this->recordWrite();
    }

    public function hasView(string $name): bool
    {
        return isset($this->engine->loadViewDefinitions($this->database)[$name]);
    }

    /**
     * 当前库全部视图名（字典序）
     *
     * @return list<string>
     */
    public function views(): array
    {
        $names = array_keys($this->engine->loadViewDefinitions($this->database));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * 取视图的可继续链式副本（where/orderBy/get 等）；
     * 每次调用从存储定义重建，后续链式操作不影响存储定义；不存在抛 SchemaException
     */
    public function view(string $name): SelectBuilder
    {
        $definitions = $this->engine->loadViewDefinitions($this->database);
        if (!isset($definitions[$name])) {
            throw new SchemaException("视图不存在: {$this->database}.{$name}");
        }

        return SelectBuilder::fromDefinition($this, ViewDefinition::fromArray($definitions[$name]));
    }

    // ---- DML 入口 ----

    /**
     * 解析表引用并返回 Table 入口；格式非法抛 QueryException
     */
    public function table(string $name): Table
    {
        if (preg_match(self::TABLE_REF_PATTERN, $name, $match) !== 1) {
            throw new QueryException("非法表引用: {$name}");
        }
        $alias = $match[2] ?? null;
        // 捕获组 2 命中 'as' 本身说明输入残缺（如 'user as'）
        if ($alias !== null && strcasecmp($alias, 'as') === 0) {
            throw new QueryException("非法表引用: {$name}");
        }

        return new Table($this, $match[1], $alias);
    }

    // ---- 事务 ----

    /**
     * 开启事务：快照引擎全量状态；已在事务中抛 TransactionException
     */
    public function begin(): void
    {
        if ($this->transactionSnapshot !== null) {
            throw new TransactionException('已在事务中，无法重复开启');
        }
        $this->transactionSnapshot = $this->engine->snapshot();
    }

    /**
     * 提交事务：持久化引擎状态并清空快照与保存点栈；不在事务中抛 TransactionException
     */
    public function commit(): void
    {
        if ($this->transactionSnapshot === null) {
            throw new TransactionException('不在事务中，无法提交');
        }
        $this->engine->persist();
        $this->transactionSnapshot = null;
        $this->savepoints = [];
    }

    /**
     * 回滚事务：恢复引擎到快照状态并清空快照与保存点栈；不在事务中抛 TransactionException
     */
    public function rollBack(): void
    {
        if ($this->transactionSnapshot === null) {
            throw new TransactionException('不在事务中，无法回滚');
        }
        $this->engine->restore($this->transactionSnapshot);
        $this->transactionSnapshot = null;
        $this->savepoints = [];
        // restore 改写了引擎数据，必须失效索引缓存（最关键的失效点）
        $this->recordWrite();
    }

    /**
     * 是否处于事务中
     */
    public function inTransaction(): bool
    {
        return $this->transactionSnapshot !== null;
    }

    // ---- 保存点 ----

    /**
     * 在当前事务内建立命名保存点（快照引擎全量状态压栈）；
     * 事务外调用或名字为空抛 TransactionException；
     * 同名重复 savepoint 覆盖旧条目（后压优先，回滚回到最近一次同名保存点）
     */
    public function savepoint(string $name): void
    {
        if ($this->transactionSnapshot === null) {
            throw new TransactionException('不在事务中，无法建立保存点');
        }
        if ($name === '') {
            throw new TransactionException('保存点名称不能为空');
        }
        $this->savepoints = array_values(array_filter(
            $this->savepoints,
            static fn (array $savepoint): bool => $savepoint['name'] !== $name,
        ));
        $this->savepoints[] = ['name' => $name, 'snapshot' => $this->engine->snapshot()];
    }

    /**
     * 回滚到保存点：恢复建立该保存点时的引擎状态，弹出其之后压入的全部更内层保存点，
     * 保存点自身保留（可再次回滚，复用同一快照对象）；
     * 事务外调用或保存点不存在（含已被外层回滚/释放波及）抛 TransactionException
     */
    public function rollBackTo(string $name): void
    {
        $position = $this->findSavepoint($name, '回滚到');
        $this->engine->restore($this->savepoints[$position]['snapshot']);
        // 外层回滚丢弃内层：保留该保存点自身及其外层条目
        $this->savepoints = array_slice($this->savepoints, 0, $position + 1);
        // restore 改写了引擎数据，必须失效索引缓存（与 rollBack 同一纪律）
        $this->recordWrite();
    }

    /**
     * 释放保存点：弹出该保存点及其之后压入的全部更内层保存点（SQL 标准：释放外层时内层一并失效）；
     * 不改变数据；事务外调用或保存点不存在抛 TransactionException
     */
    public function releaseSavepoint(string $name): void
    {
        $position = $this->findSavepoint($name, '释放');
        $this->savepoints = array_slice($this->savepoints, 0, $position);
    }

    /**
     * 在保存点栈内查找名字并返回栈内位置（保存点建立时同名旧条目已被移除，名字唯一）；
     * 不存在抛 TransactionException（消息含动作词以区分回滚/释放场景）
     */
    private function findSavepoint(string $name, string $action): int
    {
        if ($this->transactionSnapshot === null) {
            throw new TransactionException("不在事务中，无法{$action}保存点");
        }
        foreach ($this->savepoints as $position => $savepoint) {
            if ($savepoint['name'] === $name) {
                return $position;
            }
        }

        throw new TransactionException("保存点不存在或已失效，无法{$action}: {$name}");
    }

    // ---- 写版本与索引管理 ----

    /**
     * 数据版本号：任何数据/结构变更后自增（索引缓存失效依据）
     */
    public function writeVersion(): int
    {
        return $this->writeVersion;
    }

    /**
     * @internal 记录一次写操作（Writer 与 DDL 变更路径调用）
     */
    public function recordWrite(): void
    {
        ++$this->writeVersion;
    }

    /**
     * 连接级索引管理器单例（跨查询复用，随 writeVersion 自动失效）
     *
     * @internal
     */
    public function indexManager(): IndexManager
    {
        return $this->indexManager ??= new IndexManager($this);
    }

    // ---- 触发器 ----

    /**
     * 注册触发器并返回句柄（可用于 dropTrigger 移除）；
     * $timing: 'before'|'after'（大小写不敏感）；$event: 'insert'|'update'|'delete'（大小写不敏感）；
     * 表不存在或 timing/event 非法抛 QueryException
     *
     * 触发器为连接级运行时注册（handler 为 PHP 可调用，不可持久化，重建连接后需重新注册）
     */
    public function createTrigger(string $table, string $timing, string $event, callable $handler): Trigger
    {
        $timing = strtolower($timing);
        $event = strtolower($event);
        if (!in_array($timing, ['before', 'after'], true)) {
            throw new QueryException("非法触发器时机: {$timing}（仅支持 before/after）");
        }
        if (!in_array($event, ['insert', 'update', 'delete'], true)) {
            throw new QueryException("非法触发器事件: {$event}（仅支持 insert/update/delete）");
        }
        if (!$this->engine->hasTable($this->database, $table)) {
            throw new QueryException("表不存在: {$this->database}.{$table}");
        }

        return $this->triggerManager()->register($table, $timing, $event, $handler);
    }

    /**
     * 移除触发器；句柄未注册或已移除抛 QueryException
     */
    public function dropTrigger(Trigger $trigger): void
    {
        $this->triggerManager()->remove($trigger);
    }

    /**
     * 连接级触发器管理器单例（懒创建）
     *
     * @internal
     */
    public function triggerManager(): TriggerManager
    {
        return $this->triggerManager ??= new TriggerManager();
    }

    // ---- 内部 ----

    /**
     * 执行定义并产出表结构
     *
     * @param callable(Blueprint): void $definition
     */
    private function buildSchema(string $name, callable $definition): TableSchema
    {
        $blueprint = new Blueprint();
        $definition($blueprint);

        return $blueprint->toSchema($name);
    }

    /**
     * 视图名必须匹配 ^[A-Za-z_][A-Za-z0-9_]*$（与表名规则一致）
     */
    private function assertValidViewName(string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new SchemaException("非法视图名: {$name}");
        }
    }

    /**
     * 切回 main 库（不存在则创建）
     */
    private function switchToMain(): void
    {
        if (!$this->engine->hasDatabase('main')) {
            $this->engine->createDatabase('main');
        }
        $this->database = 'main';
    }

    /**
     * 新增列回填值：defaultNow → 当前时间；hasDefault → cast；否则 null
     */
    private function backfill(ColumnSchema $column): mixed
    {
        if ($column->defaultNow) {
            return date('Y-m-d H:i:s');
        }
        if ($column->hasDefault) {
            return ValueCaster::cast($column->default, $column);
        }

        return null;
    }
}
