# psql 路线图（Roadmap）

记录已完成能力、下一轮迭代候选、以及距离"一个完整的 SQL 数据库"的差距。勾选状态随版本推进更新。

## 现状基线（v2.1 已交付）

- OOP 链式查询 API（无 SQL 文本解析）
- 类型系统：TINYINT~BIGINT（UNSIGNED）、DECIMAL(M,D)、FLOAT/DOUBLE、BOOLEAN、CHAR/VARCHAR/TEXT、ENUM、DATE/DATETIME/TIMESTAMP
- 约束：主键（单列与复合）、自增、NOT NULL、DEFAULT/CURRENT_TIMESTAMP、单列与联合唯一、外键（DELETE/UPDATE 各四策略：RESTRICT/CASCADE/SET NULL/SET DEFAULT）、CHECK
- 二级索引（哈希语义）：显式索引 + 主键/唯一约束自动可用，等值查询预过滤（候选行完整求值 WHERE 兜底），writeVersion 失效重建
- DML：SELECT（WHERE 嵌套分组 / 三种 JOIN（等值自动 hash join）/ GROUP BY+HAVING / 聚合 / ORDER BY / LIMIT/OFFSET / DISTINCT / LIKE）、INSERT（批内原子）、UPSERT / INSERT IGNORE、REPLACE INTO（冲突删旧插新，v2.1）、INSERT ... SELECT（任意 SelectBuilder 源，v2.1）、UPDATE、DELETE（BFS 级联）、UPDATE/DELETE 带 ORDER BY + LIMIT（v2.1）、TRUNCATE、chunk / cursor
- 子查询（非相关）：`whereIn`/`whereNotIn`/`whereExists`/`whereNotExists`，子查询独立完整执行、支持多层嵌套；写路径（UPDATE/DELETE where）同样支持
- UNION / UNION ALL：多方链式，子方完整执行后合并，UNION 去重保首见序
- 表达式与函数库：CASE WHEN、`Func::` 18 个标量函数（字符串/数学/日期/控制）+ 列引用，任意嵌套、NULL 传播
- 列级 collation：`ci()` 修饰符——比较/排序折叠大小写，约束保持区分大小写
- NULL 三值逻辑；事务（引擎快照，支持回滚 DDL）+ savepoint（命名部分回滚，同名覆盖、内层丢弃、可重复回滚）
- 视图 VIEW：`createView` 固化命名查询（结构化 JSON 持久化、跨重开恢复、事务快照含视图目录），`view()` 水化为 SelectBuilder 可继续链式，只读、与表共用命名空间
- 触发器：`createTrigger` PHP 闭包钩子（BEFORE/AFTER × INSERT/UPDATE/DELETE 六钩子，注册序执行、级联删除触发、连接级注册）
- EXPLAIN：`explain()` 静态计划分析（SCAN/JOIN/SUBQUERY/UNION/AGGREGATE/SORT/DISTINCT/LIMIT 步骤，与 Executor 分派镜像同步，不执行查询）
- 四存储引擎：Memory / JsonFile / PhpSerialize / PagedJson（分页增量写 + 墓碑删除页槽复用）
- 静态压缩（v2.1）：`Psql::connect($dir, ['compress' => true])`——落盘载荷 gzip 压缩（`PGZ\x01` magic）；读侧按 magic 自适应，配置只影响写方向，旧明文库无选项照常读、写回渐进入压缩格式
- 静态加密（v2.1）：`['key' => '...']`——AES-256-CBC + HMAC-SHA256（截断 16 字节恒时校验，`PENC\x01` magic）；错 key/篡改抛 StorageException；可与压缩叠加（先压缩后加密）
- 备份 backup()（v2.1）：`$db->backup($targetDir)` 完整快照导出（tmp+rename 原子、目标须不存在或为空、排除锁文件）；备份目录即合法库目录，`Psql::connect` 可直接打开
- 999 项测试（1 项环境相关跳过）；异常驱动，全库 strict_types

## 一、近期迭代（高价值 / 低风险）

- [x] **UPSERT**：`Table::upsert(row)` / `Table::insertIgnore(row)`（对应 `INSERT ... ON DUPLICATE KEY UPDATE` / `INSERT IGNORE`）
- [x] **LIKE 转义辅助**：`Query\Like::escape()`，把用户输入按字面量匹配
- [x] **复合主键**：`Blueprint::primary('a', 'b')` 多列联合主键（也接受单列）；主键列隐含 NOT NULL、不可 dropColumn；自增列存在时主键必须恰为该单列；复合主键自动可作等值索引（v1.3 交付）
- [x] **外键策略补全**：`ON DELETE` / `ON UPDATE` 四策略（RESTRICT/CASCADE/SET NULL/SET DEFAULT）全覆盖，含 DDL 前置校验
- [x] **CHECK 约束**：`$t->check(name, condition)`，写入时求值
- [x] **orWhereGroup**：`whereGroup` 目前只有 AND 语义，补 OR 挂载
- [x] **UPDATE/DELETE 带 ORDER BY + LIMIT**（MySQL 语义，危险但常用）（v2.1 交付：链式仅 where+orderBy+limit——聚合/表达式投影/join/groupBy/having/distinct/union 抛 QueryException；offset 不支持；不带 orderBy/limit 时既有路径不变）
- [x] **PagedJson 页槽复用**：`deleteRows` 墓碑删除——被删槽位置为页内 null 只重写所在页；死槽 ≥ 40% 且 ≥ 100 自动压实，读取恒返回稠密序列（5 万行删中间 1 行 ~3.4ms vs 62.8ms）
- [x] **多进程防护（最低限度）**：数据目录加锁文件（`Storage\DirectoryLock`），第二个进程打开时抛 `StorageException` 而非静默数据竞争
- [x] **大表迭代读取**：`chunk(size, handler)`（LIMIT/OFFSET 分批、返回 false 提前终止、返回已处理总行数）+ `cursor(): \Generator`（惰性游标，首次迭代才执行查询）
- [x] **API 收紧**：`Table`/`SelectBuilder` 构造器 `?Connection`（历史测试便利）改回非空 `Connection`；`Psql::connect` 增加 `engine` 选项参数（接受 `StorageEngine` 实例，path 忽略、与 compress/key 互斥），省去手写 `new Connection(...)`（v2.2 交付）
- [x] **工程化地基**：CI（GitHub Actions，PHP 8.2/8.3/8.4 矩阵：PHPUnit + PHPStan + bench smoke）、PHPStan level 5 全绿（phpstan.neon，`treatPhpDocTypesAsCertain: false` 对齐防御式边界校验风格）、`composer analyse`/`composer bench` 脚本、bench.php 增 `--smoke` 档（v2.2）；仍缺大数据量 fuzz（随机 DML + 不变量断言）
- [x] **单 writer 多 reader 并发**：`Psql::connect($dir, ['concurrency' => true])`——DirectoryLock 升级读写锁（共享 LOCK_SH 多进程并存 / 排他 LOCK_EX 互斥，非阻塞 acquire 兼容既有 + 阻塞 acquireBlocking 供操作级）、`LockingEngine` 装饰器按 StorageEngine 方法分类加操作级读写锁、`<root>/.wv` 写版本递增 + 读前校验实现跨进程缓存失效（长驻 reader 见 writer 写入）、事务 begin..commit/rollBack 全程持写锁、SELECT 经 `readLocked` 整语句持共享锁（v2.2 交付）；访问同一库目录的进程应一致使用并发模式；多 writer 写写冲突检测与隔离级别仍缺

## 二、SQL 能力补全（中期）

- [x] **子查询（IN/EXISTS）**：`whereIn('id', $sub)` / `whereNotIn` / `whereExists($sub)` / `whereNotExists`——子查询独立完整执行（含自身 orderBy/limit/union）、恰好 1 输出列、支持多层嵌套；UPDATE/DELETE 的 where 同样支持；CHECK 条件中禁用；支持相关子查询（v2.2：SELECT WHERE 的 IN/EXISTS 引用外层列 `whereColumn('内.col','=','外.col')`，外层行逐行绑定执行；写入路径拒绝清晰报错）
- [x] **相关子查询**：条件树中引用非子查询源别名的限定列视为外层引用——`SubqueryResolver` 静态检测相关 + 外层行绑定（`bindOuterRow` 把外层列替换为行值常量，支持列侧/值侧及运算符换侧翻转）后执行；Executor WHERE 含相关子查询时逐行 `resolveCorrelated`，非相关仍整体解析一次（v2.2）；补充 `whereColumn`/`orWhereExists`/`orWhereNotExists` 构建 API
- [x] **标量子查询**：`whereScalar('col', '=', $sub)`/`orWhereScalar`（SELECT WHERE 收尾）——子查询须输出 1 列、取首行首列值与列比较；非相关独立执行一次、相关按外层行绑定（复用 .wv 机制外的同套绑定）；空集 → NULL（col = NULL 过滤行）；写入路径相关标量拒绝（v2.2）
- [x] **窗口函数**：`Func::rowNumber()/rank()/denseRank()` + `Agg::sum(...)->over()`（COUNT/SUM/AVG/MIN/MAX）——`->partitionBy(...)->orderBy(...)->as(别名)`；投影/聚合后整组计算：排名函数需 ORDER BY（ROW_NUMBER 连续、RANK 并列跳档、DENSE_RANK 并列不跳档），聚合窗口整分区统计（每行同值，不做 ROWS BETWEEN 帧）；结果可用别名继续 orderBy；EXPLAIN 输出 WINDOW 步骤；窗口查询不可持久化为视图（v2.2）
- [x] **FROM 派生表**：`fromSub()`/`Connection::fromSub`——子查询出现在 FROM 位（v2.2 交付）；列按 `别名.输出列` 引用、可 join 物理表、支持嵌套与内部子查询；无静态结构（索引加速/视图持久化不适用，EXPLAIN 输出 DERIVED 步骤）；空源无列信息
- [x] **CTE（WITH 子句，非递归）**：`Connection::with()`/`Table::with()`/`SelectBuilder::withCtes()` 注册命名子查询，FROM 位 `fromCte(name, alias)`、JOIN 位 `joinCte/leftJoinCte/rightJoinCte`（等值走 hash join）引用；后序 CTE 可引用前序 CTE（`Connection::fromCte` 作定义内引用）；同一 CTE 可多次引用、每次独立完整执行（非物化）；CTE 定义可含聚合/排序/联合；EXPLAIN 输出 DERIVED 式 JOIN 步骤；递归 CTE（WITH RECURSIVE）不支持（v2.2）
- [x] **UNION / UNION ALL**：多方链式，每个子方完整执行后合并；UNION 去重保首见顺序、UNION ALL 保留重复；两侧输出列键集须一致；外层 distinct/orderBy/limit/offset 生效
- [x] **JOIN 增强**：`joinOn/leftJoinOn/rightJoinOn`（ON 任意条件表达式，`whereColumn` 列-列比较、`where` 列-值比较、AND/OR 组合）+ `joinUsing/leftJoinUsing/rightJoinUsing`（单列走 hash join、多列走条件组）（v2.2 交付）；条件组 ON 恒走嵌套循环求值，EXPLAIN 输出 NESTED LOOP
- [x] **自连接**：同一表以不同别名多次出现（`table('employees as e')->join('employees as m', ...)`），列按 `'别名.列名'` 消歧、WHERE/ORDER BY/GROUP BY 均可用；输出同名列用 `Func::col(...)->as()` 区分（ColumnRef 增别名，v2.2）；无别名重复默认表名抛"表别名重复"（MySQL 同款）
- [x] **多表 UPDATE / DELETE（JOIN 写入）**：JOIN + WHERE 定位匹配行——UPDATE 的 SET 键 `'alias.col'` 限定目标表（裸键归基表）、逐表走完整约束管线（唯一/FK/CHECK/触发器/onUpdate 传播，v2.2）；DELETE 仅删基表（`table()` 目标）匹配行、join 表只参与匹配、复用 BFS 级联/RESTRICT；LEFT JOIN + IS NULL 匹配无侧行；匹配行按内容哈希反查行号（重复匹配只生效一次）；ORDER BY/LIMIT 与多表写互斥（MySQL 同款，构建器拦截）
- [x] **表达式与函数库**：CASE WHEN、字符串/数学/日期/控制共 18 个标量函数（含 COALESCE/NULLIF）+ 列引用，任意嵌套、NULL 传播（除 coalesce）
- [x] **二级索引**：`createIndex/dropIndex/hasIndex` + `Blueprint::index`，哈希语义——等值查询预过滤（5 万行热查 ~0.02ms vs 全扫描 ~100ms），主键/单列 unique/联合唯一自动可用作索引，writeVersion 失效重建；范围查询仍扫（B-Tree 为后续方向）
- [x] **执行器升级：hash join**：等值 JOIN（INNER/LEFT/RIGHT）从嵌套循环 O(n·m) 升级为哈希构建+探测（2万×2万 0.094s vs 111.5s）；非 '=' 运算符回退嵌套循环，输出顺序一致
- [x] **执行器升级：排序外部归并**：大表 ORDER BY（>5000 行）自动写临时文件分块 + 多路归并，结果与内存排序完全一致（含稳定序）；临时目录 finally 清理；小结果集仍内存排序（v2.2 交付）
- [x] **EXPLAIN**：`$query->explain()` 静态分析输出访问路径步骤（SCAN 的索引/全扫描与实际存储行数、JOIN 的 HASH/NESTED LOOP 及原因、SUBQUERY/UNION/AGGREGATE/SORT/DISTINCT/LIMIT），判定逻辑与 Executor 实际分派镜像同步、不执行查询本体（v2.0 交付）
- [x] **视图 VIEW**：`createView`/`view`/`hasView`/`dropView`/`views`——命名查询 + 定义水化为 SelectBuilder 展开；结构化持久化（子查询条件/投影表达式定义拒绝）；与表命名空间互斥、只读；事务快照含视图目录（v2.0 交付）；DDL 联动：`renameTable`/`dropTable` 拒绝操作被视图引用的基表（递归含 join/子查询/union，v2.2）
- [x] **触发器**：BEFORE/AFTER INSERT/UPDATE/DELETE 六钩子（PHP 闭包）；连接级注册、注册序执行；级联删除触发、SET_NULL/SET_DEFAULT 不触发、upsert 双路径、insertIgnore 忽略行不触发、truncate 不触发；无触发器零开销（v2.0 交付）
- [x] **collation**：列级 `ci()` 大小写不敏感比较/排序（WHERE/JOIN ON/ORDER BY 及 UPDATE/DELETE where 折叠大小写；唯一/外键/CHECK 约束保持区分大小写）；CHAR 空格填充语义仍缺
- [x] **类型补全**：JSON / BLOB / BINARY / SET（v2.2：JSON 存可编码值、BLOB 二进制、BINARY(N) 字节长度校验、SET 逗号字符串 + 成员校验去重）+ UNSIGNED BIGINT 超 PHP_INT_MAX（v2.2：超限以十进制字符串存储，bcmath 精确比较/排序/聚合/唯一索引归一，范围 [0, 2^64-1]；float 输入超精度拒绝）
- [x] **UPSERT 之外的 MySQL 方言**：`REPLACE INTO` 语义（冲突删旧插新、返回删除+插入合计、replaceMany 逐行独立非批内原子，v2.1）、`INSERT ... SELECT`（任意 SelectBuilder 源、按键名匹配、批内原子、自引用检测，v2.1）
- [x] **Savepoint**：`savepoint`/`rollBackTo`/`releaseSavepoint` 事务内命名部分回滚——同名覆盖、rollBackTo 撤销其后变更并丢弃更内层保存点（该点保留可重复回滚）、release 外层释放内层一并失效、索引缓存自动失效、支持回滚 DDL（v2.0 交付）

## 三、完整数据库特性（长期）

- [ ] **查询优化器**：基于统计信息的代价估算、连接顺序选择、谓词下推
- [x] **单 writer 多 reader 并发**：`Psql::connect($dir, ['concurrency' => true])`——操作级读写锁（读共享/写排他）+ .wv 跨进程缓存失效 + 事务全程持写锁（v2.2，见"一、近期迭代"条目）；多 writer 写写冲突检测仍缺
- [ ] **事务升级**：MVCC 或行锁、隔离级别（READ COMMITTED / REPEATABLE READ）、写写冲突检测（并发模型已就位，隔离级别与行版本化为后续方向）
- [ ] **WAL / 崩溃恢复**：redo log（PagedJson 的 gen 模型是雏形但无按页 redo）；增量备份——`backup()` API 已交付（v2.1：完整快照、tmp+rename 原子、备份目录即库目录），增量备份与 redo log 仍缺
- [x] **静态压缩 / 静态加密**：已交付（v2.1）——gzip 落盘压缩（`PGZ\x01`）+ AES-256-CBC + HMAC-SHA256 静态加密（`PENC\x01`，需 openssl），统一收敛在 `Storage\Codec` 层（读侧 magic 自适应、写侧按 connect 选项、先压缩后加密、锁文件排除）
- [ ] **CLI shell**：`bin/psql` 交互式终端（依赖 SQL 解析器），`.dump` / `.schema` / `.import` 命令
- [x] **迁移工具**：`Migration::diff($target, $current)` 生成 schema 迁移计划（建表/删表/加列/删列/改列/索引增删，顺序=建表→改表→删表）+ `Migration::apply` 顺序执行（复用 Connection 既有 DDL 校验与数据回填/索引缓存）；列改 NOT NULL 无默认值自动降级为 note 步骤（需手工）；联合唯一/外键/CHECK 差异输出 note（AlterBlueprint 不支持增删，v2.2）
- [ ] **网络 server 模式**：监听端口接受连接（真正意义的数据库服务；是否属于"本地化"目标待定）
- [ ] **权限系统**：用户/GRANT（单库场景价值有限，优先级最低）
- [ ] **基准体系化**：bench.php 进 CI、大数据量 fuzz（随机 DML 序列 + 不变量断言）

## 四、距离"完整 SQL"的差距矩阵

| 能力 | SQL 标准 / MySQL | psql 现状 | 主要差距 |
|---|---|---|---|
| 类型系统 | 完整 | 核心关系类型 | JSON/BLOB/BINARY/SET、UNSIGNED BIGINT 超限（bcmath）均已支持（v2.2）；缺空间类型 |
| 约束 | PK/UNIQUE/FK/CHECK/DEFERRABLE | 除 DEFERRABLE 外均有（复合主键、CHECK 与 FK 四策略已支持） | 复合主键已支持；缺 DEFERRABLE |
| SELECT | 子查询/UNION/窗口/CTE/表达式 | JOIN + 聚合 + 子查询（IN/EXISTS + 标量，含相关）+ UNION + CASE + 函数库 + FROM 派生表 + 自连接 + CTE + 窗口函数 | 子查询、UNION、CASE、函数库、派生表、自连接、CTE、窗口函数、标量子查询均已支持；SELECT 表达力基本齐备 |
| 写入 | UPSERT/REPLACE/INSERT...SELECT | INSERT/UPDATE/DELETE + UPSERT/INSERT IGNORE + REPLACE INTO + INSERT ... SELECT（均含 v2.1）+ UPDATE/DELETE ORDER BY+LIMIT + 多表 UPDATE/DELETE（JOIN 写入，v2.2） | REPLACE INTO、INSERT ... SELECT、多表 UPDATE/DELETE 均已支持；缺多表 DELETE 多目标（DELETE t1, t2 FROM，当前仅删基表） |
| 事务 | 隔离级别/savepoint/并发 | 快照回滚 + savepoint（v2.0）+ 单 writer 多 reader 并发（v2.2） | savepoint、单 writer 多 reader 已支持；无隔离级别/MVCC、多 writer 写写冲突检测 |
| 索引 | B-Tree/哈希/全文/空间 | 哈希二级索引 + hash join（等值加速，主键/唯一约束自动可用）+ 大结果集外部归并排序 | 范围查询与 ORDER BY 仍扫描/内存排序；无 B-Tree |
| 视图/过程/触发器 | 均有 | 视图与触发器已有（PHP 闭包触发器、连接级） | 无存储过程；触发器闭包不持久化（连接级注册） |
| SQL 文本接口 | 原生 | 无（纯 OOP） | 明确不做：OOP API 即查询语言 |
| 优化器 | 代价优化 + EXPLAIN | 固定执行计划 + 静态 EXPLAIN（v2.0） | 静态 EXPLAIN 已有（无代价优化）；无统计信息驱动的计划选择 |
| 并发/服务 | 多连接网络服务 | 单进程库 | 明确不做：单进程本地库形态 |

> 定位说明：psql 的目标是"MySQL 语义 + PHP OOP 接口的本地库"，对标 SQLite 而非 MySQL Server。矩阵中"明确不做"行是产品边界，不纳入迭代规划（SQL 文本接口、网络服务、权限系统均不做）。

## 五、里程碑建议

| 版本 | 主题 | 内容 |
|---|---|---|
| v1.1 | 写入体验（已完成） | UPSERT、LIKE 转义、orWhereGroup、外键 UPDATE 策略、CHECK、多进程锁 |
| v1.2 | 索引与性能（已完成） | 二级索引（哈希起步）、hash join、chunk/cursor、页槽复用 |
| v1.3 | 语法表达力（已完成） | 复合主键、子查询（IN/EXISTS）、UNION、CASE、函数库、列级 collation |
| v2.0 | 数据库编程（已完成） | 视图、触发器、savepoint、EXPLAIN |
| v2.1 | 存储增强与写补全（已完成） | 静态压缩/加密（Codec 层）、backup() API、REPLACE INTO、INSERT ... SELECT、UPDATE/DELETE ORDER BY+LIMIT |
| v2.x | 并发与恢复（可选） | WAL、并发访问 |

## 六、已知技术债（实现层）

- ~~`Query/Table`、`Query/SelectBuilder` 构造器接受 `?Connection`（当时为绕开未实现的 Executor 做结构测试），应收紧~~ → 已收紧（v2.2）：构造器改回非空 `Connection`，`Psql::connect` 增 `engine` 选项参数
- ~~`SelectBuilder::select(string|AggregateExpression ...)` 是全库唯一 union 参数类型~~ → 维持现状（v2.2 评估）：union 是 select 语义所需——列名字符串 + 聚合表达式 + 投影表达式（含 ColumnRef 别名），无单一类型可覆盖，拆分方法反而劣化 API
- ~~Executor 对重复 JOIN 源别名抛异常，自连接暂不支持（别名重复语义待另行设计）~~ → 已支持（v2.2）：不同别名同名表可多次出现（自连接），无别名重复仍抛"表别名重复"
- ~~`Table::min/max` 空表抛 `QueryException` 而 `sum/avg` 空表返回 0.0，语义不统一（MySQL 均返回 NULL）~~ → 已统一（v2.2）：min/max/sum/avg 空表均返回 NULL，sum/avg 签名改 `?float`
- collation 仅列级 ci（比较/排序折叠大小写），无完整 collation 体系（表达式级 collation、显式 CS 声明等）
- restore/persist 对 PagedJson 走全量重写（低频路径，正确性优先，可接受）
- ~~`renameTable`/`dropTable` 不联动视图：基表改名/删除后视图定义仍指向旧表名，`view()->get()` 抛"表不存在"~~ → 已解决（v2.2）：拒绝删除/重命名被视图引用的基表（`assertTableNotUsedByView` 递归检测 join/子查询/union），需先删视图再操作基表
- 触发器闭包不持久化（连接级注册，重开连接需重新 `createTrigger`）——PHP 闭包无法序列化，属产品形态约束而非缺陷；如需持久语义可考虑事件名注册表 + 用户侧解析
- `StorageEngine` 接口两轮迭代各加方法（v2.0 `loadViewDefinitions`/`saveViewDefinitions`、v2.1 `backupDatabase`）——对第三方引擎是 BC 断点；长期考虑能力子接口拆分或在 CHANGELOG 维护迁移说明（replaceMany 逐行非批内原子是 MySQL 语义对齐，非技术债）
