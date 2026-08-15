# 架构与扩展

## 分层设计（仿 MySQL Server / 存储引擎分离）

```
                ┌─────────────────────────────────┐
  用户代码 ───▶ │  Psql 门面 / Connection 连接层    │  connect()/memory()、库表管理、事务
                ├─────────────────────────────────┤
                │  Query 查询构建层                 │  Table 代理、SelectBuilder 链式 DSL、
                │                                  │  Condition 条件树、Agg 聚合表达式
                ├─────────────────────────────────┤
                │  Execution 执行层                 │  Writer 约束管线（写入校验）、
                │                                  │  Executor 查询流水线（JOIN/聚合/排序）
                ├─────────────────────────────────┤
                │  Storage 存储引擎层（可插拔）      │  MemoryEngine / JsonFileEngine
                └─────────────────────────────────┘
```

| 目录 | 职责 |
|---|---|
| `src/Exception/` | 异常体系：`PsqlException`（抽象基类）+ 六个具体异常 |
| `src/Schema/` | `Blueprint` 建表 DSL、`ColumnSchema`/`TableSchema`（readonly 不可变结构）、`ForeignKey`、`AlterBlueprint` |
| `src/Type/` | `ValueCaster`：PHP 值 → 列类型的校验与规范化 |
| `src/Query/` | `Table` 表访问入口、`SelectBuilder` 链式构建器、`Condition/` 条件模型、`ConditionEvaluator` 条件求值、`Agg` 聚合工厂、`SelectQuery` 等 DTO |
| `src/Execution/` | `Writer`（INSERT/UPDATE/DELETE 约束管线）、`Executor`（SELECT 流水线） |
| `src/Result/` | `ResultSet` 结果集、`InsertResult` 插入结果 |
| `src/Storage/` | `StorageEngine` 接口、`MemoryEngine`、`JsonFileEngine`、`EngineSnapshot` |

## 执行模型（v1）

- **线性扫描**：查询全表遍历，无二级索引/B-Tree（正确性优先；索引列为后续版本方向）
- **嵌套循环 JOIN**：INNER/LEFT/RIGHT 按声明顺序逐个应用
- WHERE 求值采用 SQL NULL 三值逻辑；比较规则：两侧均数值性按数值、否则按字符串（区分大小写）

## 存储引擎

### MemoryEngine

纯内存实现（仿 MySQL MEMORY 引擎），进程结束数据消失，`persist()` 为空操作。

### JsonFileEngine

- 磁盘布局：`<root>/<数据库>/<表>.json`
- 每个表文件内容：`{"schema": {...}, "auto_increment": int, "rows": [...]}`
- **原子写入**：先写 `<表>.json.tmp.<uniqid>` 临时文件，再 rename 替换；任何 IO 失败抛 `StorageException`
- **写穿缓存**：内存缓存为运行时事实源，每次写操作同步落盘；懒加载首次访问的表；损坏 JSON / 结构缺键在读取时抛 `StorageException`（消息含文件路径）

### PhpSerializeEngine

与 JsonFileEngine 同构（共用 `FileEngine` 基类的目录布局/缓存/原子写逻辑），仅载荷格式不同：

- 载荷为 PHP `serialize` 格式，扩展名 `.bin`；编解码速度约为 JSON 的 2 倍以上，文件小约 15%
- 代价：文件不可读、仅 PHP 可消费
- 安全：反序列化通过 `allowed_classes` 仅放行 `TableSchema`/`ColumnSchema`/`ForeignKey`/`DataType` 四个结构类，磁盘上的任意类载荷退化为 `__PHP_Incomplete_Class` 并被结构校验拒绝

```php
use Kingbes\Psql\Connection;
use Kingbes\Psql\Storage\PhpSerializeEngine;

$db = new Connection(new PhpSerializeEngine('/data/appdb'));
```

### PagedJsonEngine

分页增量存储引擎——解决单文件引擎"改一行重写全表"的写放大问题：

- 磁盘布局：每表一个 meta（`<表>.meta.json`：结构/自增值/页大小/每页代数）+ 若干页文件（`<表>.<页号>.<代数>.page.json`，默认 512 行/页，可构造参数调整）
- **增量写**：`writeRows` 在引擎内部做 diff——行数不变（原地更新）时逐页独立比较，只重写有差异的页（与行的位置无关）；行数变化（插入/删除）时从首个差异行保守重写到表尾。`setAutoIncrement` 只重写 meta
- **崩溃安全**：写入顺序为"新代数页文件（各自原子写）→ meta 原子替换（提交点）→ 清理旧代数页"。meta 是唯一事实源：崩溃在任何点，要么 meta 未变（新页成孤儿，加载时清理），要么 meta 已变（新页必已全部落盘）
- 代价：批量全量写入比单文件略慢（多页文件系统调用）；页间无压缩

基准（5 万行、按主键更新单行、100 次）：单文件 JSON 引擎 ~8.2s、serialize 引擎 ~3.5s、PagedJsonEngine ~0.4s（约 4ms/次）。

```php
use Kingbes\Psql\Connection;
use Kingbes\Psql\Storage\PagedJsonEngine;

$db = new Connection(new PagedJsonEngine('/data/appdb'));      // 默认 512 行/页
$db = new Connection(new PagedJsonEngine('/data/appdb', 1024)); // 自定义页大小
```

### 事务与快照

`StorageEngine::snapshot(): EngineSnapshot` 对引擎全量状态序列化（文件引擎快照前会加载磁盘上尚未访问的表），`restore()` 恢复并同步磁盘（删除快照外文件），供 `Connection::begin()/rollBack()` 使用。

## 异常体系

所有失败路径一律抛异常，**没有任何"返回 false/null 表示错误"的静默路径**：

```
PsqlException (abstract)
├── SchemaException       结构/DDL 错误（表已存在、非法列名、alter 非法…）
├── TypeException         类型校验失败（越界、超长、枚举外、日期格式…）
├── ConstraintException   约束违反（唯一冲突、NOT NULL、外键、RESTRICT…）
├── QueryException        查询错误（未知列、歧义列、非法运算符/方向…）
├── StorageException      存储引擎 IO 错误（路径不可写、JSON 损坏…）
└── TransactionException  事务状态误用
```

捕获建议：

```php
use Kingbes\Psql\Exception\PsqlException;

try {
    $db->table('user')->insert($data);
} catch (PsqlException $e) {
    // 统一入口；需要细分时按具体异常类分别 catch
}
```

## 自定义存储引擎

实现 `Kingbes\Psql\Storage\StorageEngine` 接口即可接入（例如 SQLite 后端、加密存储、Redis 缓存）。

文件型引擎的捷径：继承 `FileEngine` 基类，只需实现三个钩子——`tableExtension()`（扩展名）、`encode()`（编码）、`decode()`（解码 + 校验），目录布局、缓存、原子写、快照同步全部继承：

```php
use Kingbes\Psql\Connection;
use Kingbes\Psql\Storage\StorageEngine;

final class MyEngine implements StorageEngine
{
    // 实现全部接口方法：数据库/表的 CRUD、readRows/writeRows、
    // autoIncrement/setAutoIncrement/resetAutoIncrement、
    // snapshot/restore/persist
}

$connection = new Connection(new MyEngine());
```

要点：

- `readRows`/`writeRows` 为全量读写语义（v1 无行级游标）
- `snapshot`/`restore` 必须覆盖引擎全部状态（事务依赖它回滚 DDL）
- 名称校验（库名/表名 `^[A-Za-z_][A-Za-z0-9_]*$`）防止路径穿越，自定义引擎应保留同类校验
- 测试上可复用 `tests/Unit/Storage/StorageEngineContractTestCase.php` 契约基类验证实现正确性

## v1 范围外（路线图）

- SQL 字符串解析器（`$db->query("SELECT ...")`）
- 二级索引 / B-Tree（当前线性扫描）
- 视图、存储过程、触发器、MVCC 并发、连接池
- 排序规则（collation）配置、JSON 列类型
