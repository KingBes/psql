<?php

declare(strict_types=1);

namespace Kingbes\Psql;

use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Exception\TransactionException;
use Kingbes\Psql\Execution\IndexManager;
use Kingbes\Psql\Query\Table;
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

    /** 数据版本号：任何数据/结构变更后自增（索引缓存失效依据） */
    private int $writeVersion = 0;

    private ?IndexManager $indexManager = null;

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
     * 提交事务：持久化引擎状态并清空快照；不在事务中抛 TransactionException
     */
    public function commit(): void
    {
        if ($this->transactionSnapshot === null) {
            throw new TransactionException('不在事务中，无法提交');
        }
        $this->engine->persist();
        $this->transactionSnapshot = null;
    }

    /**
     * 回滚事务：恢复引擎到快照状态并清空快照；不在事务中抛 TransactionException
     */
    public function rollBack(): void
    {
        if ($this->transactionSnapshot === null) {
            throw new TransactionException('不在事务中，无法回滚');
        }
        $this->engine->restore($this->transactionSnapshot);
        $this->transactionSnapshot = null;
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
