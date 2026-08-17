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
| `src/Query/` | `Table` 表访问入口、`SelectBuilder` 链式构建器、`Condition/` 条件模型、`ConditionEvaluator` 条件求值、`Agg` 聚合工厂、`Func`/`CaseWhen`/`ColumnRef` 投影表达式、`ViewDefinition`（视图定义 DTO 与结构化序列化）、`Explain`（静态计划分析）、`SelectQuery` 等 DTO |
| `src/Execution/` | `Writer`（INSERT/UPDATE/DELETE 约束管线）、`Executor`（SELECT 流水线）、`IndexManager`（哈希索引缓存与预过滤）、`SubqueryResolver`（子查询解析）、`TriggerManager`/`Trigger`（触发器分发与句柄） |
| `src/Result/` | `ResultSet` 结果集、`InsertResult` 插入结果 |
| `src/Storage/` | `StorageEngine` 接口、`MemoryEngine`、`JsonFileEngine`、`PagedJsonEngine`、`Codec`（压缩/加密编解码层）、`DirectoryLock`、`EngineSnapshot` |

## 执行模型

- **哈希索引加速的等值查询**：WHERE 顶层条件全部为 AND 连接的等值比较且列集与某可用索引完全一致时，走 `IndexManager` 哈希预过滤（见下节）；未命中触发条件则回退全表线性扫描
- **hash join**：等值 JOIN（INNER/LEFT/RIGHT）先构建哈希表再探测，复杂度 O(n+m)；非 '=' 运算符自动回退嵌套循环，输出顺序与嵌套循环实现一致
- WHERE 求值采用 SQL NULL 三值逻辑；比较规则：两侧均数值性按数值、否则按字符串（区分大小写）
- **子查询**：`whereIn`/`whereNotIn`/`whereExists`/`whereNotExists` 传入的子构建器经 `Execution\SubqueryResolver` 解析——作为独立查询完整执行（含自身 orderBy/limit/union）后，结果化为值列表或存在性判定进入常规条件求值；不支持相关子查询（引用外层列即未知列异常）
- **UNION / UNION ALL**：各子方作为独立查询完整执行（保留各自排序/limit 语义）后由 `Executor` 按声明序合并——UNION 全集去重保首见顺序、UNION ALL 保留重复；外层收尾子句（distinct/orderBy/limit/offset）作用于合并结果
- **表达式投影**：`Query\ProjectionExpression` 接口（`Func`/`CaseWhen`/`ColumnRef` 实现）在投影阶段对源限定行求值，支持任意嵌套（NULL 传播、coalesce 除外）；别名进入输出行，可被 orderBy/having/groupBy 引用
- **视图**：视图查询即把存储的结构化定义（`Query\ViewDefinition`）**水化**为 `SelectBuilder`，走与手写查询完全相同的常规流水线——没有独立的视图执行器
- **EXPLAIN**：`Query\Explain` 为**静态镜像分析**——只读 schema 与存储行数做访问路径判定，不执行查询本体；索引触发条件、CI 列跳过索引与 hash join 的判定逻辑与 `Executor` 实际分派保持镜像同步
- 写入路径的外键策略分发（DELETE/UPDATE 四策略）与 CHECK 约束求值均在 `Writer` 约束管线内完成；触发器分发（`Execution\TriggerManager`）同样挂在 `Writer` 管线的关键位置——BEFORE INSERT/UPDATE 在 cast 前取行（返回值进入管线）、AFTER 在约束全部通过后收到最终行、DELETE 前后各一档；分发点首行判空直通，**无注册触发器时写路径零开销**

### IndexManager（等值索引预过滤）

`Execution\IndexManager` 维护连接级哈希索引缓存，为等值查询提供候选稠密行号预过滤：

- **可用索引来源**：显式二级索引（`createIndex` / `Blueprint::index`）之外，主键列、单列 UNIQUE 约束列、联合唯一组**自动可用作索引**（无需显式建）
- **触发条件**：WHERE 全部顶层条件为 AND 连接的等值比较（裸列名、值非 null），且列集与某可用索引完全一致（顺序不敏感）；范围查询、OR、`whereIn` 等自动回退全表扫描
- **正确性保证**：哈希命中仅产出候选行号，`Executor` 对候选行仍完整求值原 WHERE 兜底——索引加速不改变查询结果，与全表扫描完全一致
- **缓存失效**：以 `Connection::writeVersion`（任何数据/结构变更、事务回滚自增）为版本依据，版本不一致时该表索引缓存自动重建，无需手动管理
- 性能参考：5 万行等值查询热查 ~0.02ms vs 全扫描 ~100ms

### collation 与索引的正确性取舍

`ci()` 列的比较折叠大小写（`mb_strtolower`），而哈希索引按**原始值**建键——`'A'` 与 `'a'` 哈希不同，若让 ci 查询走索引预过滤会把本该匹配的行错误过滤掉。因此**涉及 ci 列的查询自动跳过索引预过滤与 hash join**，回退全表扫描 / 嵌套循环，以性能换取结果正确性。约束判定（唯一/外键/CHECK）保持区分大小写、与索引行为一致，不受影响；未声明 `ci()` 的列行为与此前完全一致。

## 存储引擎

### MemoryEngine

纯内存实现（仿 MySQL MEMORY 引擎），进程结束数据消失，`persist()` 为空操作。

### JsonFileEngine

- 磁盘布局：`<root>/<数据库>/<表>.json`；视图定义存库目录 `.views.json`（带前导点避免与名为 `views` 的表文件冲突，同样原子写）
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
- **增量写**：`writeRows` 在引擎内部做 diff——行数不变（原地更新）时逐页独立比较，只重写有差异的页（与行的位置无关）；行数变化（插入）时从首个差异行保守重写到表尾。`setAutoIncrement` 只重写 meta
- **墓碑删除（页槽复用）**：`deleteRows(db, table, indices)` 按稠密行号删除——被删槽位置为页内 null（墓碑），**只重写所在页**；死槽 ≥ 40% 且 ≥ 100 行时自动压实（全量重排、墓碑清零），任何 `writeRows` 全量替换后墓碑清零；读取恒返回压缩后的稠密行序列，外部视角不变
- **崩溃安全**：写入顺序为"新代数页文件（各自原子写）→ meta 原子替换（提交点）→ 清理旧代数页"。meta 是唯一事实源：崩溃在任何点，要么 meta 未变（新页成孤儿，加载时清理），要么 meta 已变（新页必已全部落盘）
- 代价：批量全量写入比单文件略慢（多页文件系统调用）；页间无压缩

基准（5 万行、按主键更新单行、100 次）：单文件 JSON 引擎 ~8.2s、serialize 引擎 ~3.5s、PagedJsonEngine ~0.4s（约 4ms/次）。
基准（5 万行、删除中间 1 行）：墓碑路径 ~3.4ms vs 原 suffix 重写 ~62.8ms（约 18×）。

```php
use Kingbes\Psql\Connection;
use Kingbes\Psql\Storage\PagedJsonEngine;

$db = new Connection(new PagedJsonEngine('/data/appdb'));      // 默认 512 行/页
$db = new Connection(new PagedJsonEngine('/data/appdb', 1024)); // 自定义页大小
```

### Codec 层（静态压缩与加密）

`Storage\Codec` 是文件引擎的载荷编解码横切层，由 `Psql::connect` 选项驱动：

```php
use Kingbes\Psql\Psql;

$db = Psql::connect('/data/appdb');                                        // 明文（默认）
$db = Psql::connect('/data/appdb', ['compress' => true]);                  // gzip 压缩落盘
$db = Psql::connect('/data/appdb', ['key' => 'secret']);                   // AES-256-CBC 加密落盘
$db = Psql::connect('/data/appdb', ['compress' => true, 'key' => 'secret']); // 先压缩后加密
```

| 连接选项 | 类型 | 作用 |
|---|---|---|
| `compress` | bool | 落盘载荷经 `gzencode` 压缩，magic 头 `PGZ\x01` |
| `key` | ?string | 落盘载荷 AES-256-CBC 加密，magic 头 `PENC\x01`（需 openssl 扩展） |

- **magic 头版本化**：每份落盘载荷带 4 字节 magic 标识格式（明文为空 magic），格式演进有据可依
- **读侧自适应**：decode 始终按 magic 自动分派解压/解密，**配置只影响写方向**——旧明文库不带选项照常可读；开启压缩/加密后写回**渐进**迁移到新格式（同库明文/压缩/加密文件混存完全合法）。读加密库须提供原 key
- **加密布局**：`PENC\x01 + hmac(16B) + iv(16B) + ciphertext`——key 经 sha256 派生 AES-256 密钥，IV 每次随机生成；HMAC-SHA256 截断 16 字节做完整性校验（`hash_equals` 恒时比较）。错 key 或 HMAC 篡改抛 `StorageException`（"密钥错误或数据损坏"）；无 key 打开加密库抛"文件已加密，需要密钥"
- **叠加顺序**：两项同时开启时**先压缩后加密**（压缩先消除明文冗余，再加密保障静态安全）
- **openssl 前置检查**：配置 key 且 openssl 扩展不可用时，引擎构造即抛 `StorageException`（"openssl 扩展不可用"）
- **Memory 引擎**：无落盘无 codec，`Psql::memory()` 传入 `compress` / `key` 选项抛 `InvalidArgumentException`
- **引擎接入点**：`FileEngine` 的表文件 / 视图目录读写共 5 个 I/O 点统一过 codec；`PagedJsonEngine` 全部落盘收敛在 atomicWrite 单点 encode、各加载路径（meta / 页 / 视图）按 magic decode
- **锁文件排除**：`.lock` 是运行时协调文件，不经 codec（不压缩不加密）

### 备份（backup API）

```php
$db->backup('/data/backup-' . date('Y-m-d'));              // 当前库完整快照导出

$restored = Psql::connect('/data/backup-2026-08-17');      // 备份目录即合法库目录，直接打开
```

- `Connection::backup(string $targetDir): void`——委托引擎接口 `StorageEngine::backupDatabase(string $database, string $targetDir): void`（**v2.1 BC 断点**：自定义引擎须补实现该方法）
- **tmp + rename 原子语义**：每个文件先写目标目录下临时文件再 rename 就位；目标目录须**不存在或为空目录**，否则抛 `StorageException`
- **写穿模型下直接拷贝即一致性快照**：syncDisk 是"缓存即事实源"语义的同步原语（写穿模型下每次写已同步落盘），备份路径不调用 syncDisk——直接拷贝落盘文件即得一致视图
- 活动事务中调用抛 `TransactionException`；`MemoryEngine` 无落盘不支持（抛 `StorageException`）
- **排除锁文件**：`.lock` 不随备份复制
- 加密库的备份同为密文，重新打开需提供原 key

### 目录锁（多进程防护）

`Storage\DirectoryLock` 提供数据目录级排他锁，防止多进程数据竞争：

- 文件引擎（`FileEngine` 基类，即 JsonFile/PhpSerialize）与 `PagedJsonEngine` 构造时对 `<root>/.lock` 取 `flock` 排他锁
- **跨进程互斥**：锁被其他进程持有时构造抛 `StorageException`（消息含 root）
- **同进程引用计数**：同进程多次打开同 root 允许；但两个实例的内存缓存彼此独立，分别写盘可能读到陈旧数据——同进程需要多连接时应避免并行写同一表
- `MemoryEngine` 无锁；`.lock` 不是数据文件，不参与表枚举

### 事务与快照

`StorageEngine::snapshot(): EngineSnapshot` 对引擎全量状态序列化（文件引擎快照前会加载磁盘上尚未访问的表；v2.0 起快照载荷还包含各库**视图目录**，事务内建/删视图可回滚），`restore()` 恢复并同步磁盘（删除快照外文件），供 `Connection::begin()/rollBack()` 与 savepoint（`savepoint`/`rollBackTo`/`releaseSavepoint`）使用。

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
    // 实现全部接口方法：数据库/表的 CRUD、readRows/writeRows/deleteRows、
    // autoIncrement/setAutoIncrement/resetAutoIncrement、
    // loadViewDefinitions/saveViewDefinitions（v2.0 新增）、
    // backupDatabase（v2.1 新增）、
    // snapshot/restore/persist
}

$connection = new Connection(new MyEngine());
```

要点：

- **v2.0 BC 断点**：接口新增 `loadViewDefinitions(string $database): array` / `saveViewDefinitions(string $database, array $definitions): void`——v1.x 的第三方引擎必须补实现这两个方法才能升级（定义载荷为 `ViewDefinition::toArray()` 产出的结构化数组）
- **v2.1 BC 断点**：接口新增 `backupDatabase(string $database, string $targetDir): void`——v2.0 的第三方引擎须补实现才能升级（文件型引擎可继承 `FileEngine` 免费获得 tmp+rename 拷贝实现；纯内存语义可参照 `MemoryEngine` 抛 `StorageException`）
- `readRows`/`writeRows` 为全量读写语义（v1 无行级游标）；`deleteRows` 按稠密行号删除（v1.2 新增）——`FileEngine`/`MemoryEngine` 提供通用默认实现（语义等同过滤后重写），仅 `PagedJsonEngine` 有页槽墓碑收益
- `snapshot`/`restore` 必须覆盖引擎全部状态（事务依赖它回滚 DDL；v2.0 起须含视图目录）
- 名称校验（库名/表名 `^[A-Za-z_][A-Za-z0-9_]*$`）防止路径穿越，自定义引擎应保留同类校验
- 测试上可复用 `tests/Unit/Storage/StorageEngineContractTestCase.php` 契约基类验证实现正确性

## 范围外（路线图）

- SQL 字符串解析器（`$db->query("SELECT ...")`）
- B-Tree / 范围索引（当前哈希二级索引仅覆盖等值查询，范围查询仍扫描）
- 排序外部归并（大表 ORDER BY 仍内存排序）
- 存储过程、MVCC 并发、连接池（视图与触发器已于 v2.0 交付）
- 完整 collation 体系（现仅列级 `ci()`）、JSON 列类型
