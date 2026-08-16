# psql 路线图（Roadmap）

记录已完成能力、下一轮迭代候选、以及距离"一个完整的 SQL 数据库"的差距。勾选状态随版本推进更新。

## 现状基线（v1.3 已交付）

- OOP 链式查询 API（无 SQL 文本解析）
- 类型系统：TINYINT~BIGINT（UNSIGNED）、DECIMAL(M,D)、FLOAT/DOUBLE、BOOLEAN、CHAR/VARCHAR/TEXT、ENUM、DATE/DATETIME/TIMESTAMP
- 约束：主键（单列与复合）、自增、NOT NULL、DEFAULT/CURRENT_TIMESTAMP、单列与联合唯一、外键（DELETE/UPDATE 各四策略：RESTRICT/CASCADE/SET NULL/SET DEFAULT）、CHECK
- 二级索引（哈希语义）：显式索引 + 主键/唯一约束自动可用，等值查询预过滤（候选行完整求值 WHERE 兜底），writeVersion 失效重建
- DML：SELECT（WHERE 嵌套分组 / 三种 JOIN（等值自动 hash join）/ GROUP BY+HAVING / 聚合 / ORDER BY / LIMIT/OFFSET / DISTINCT / LIKE）、INSERT（批内原子）、UPSERT / INSERT IGNORE、UPDATE、DELETE（BFS 级联）、TRUNCATE、chunk / cursor
- 子查询（非相关）：`whereIn`/`whereNotIn`/`whereExists`/`whereNotExists`，子查询独立完整执行、支持多层嵌套；写路径（UPDATE/DELETE where）同样支持
- UNION / UNION ALL：多方链式，子方完整执行后合并，UNION 去重保首见序
- 表达式与函数库：CASE WHEN、`Func::` 18 个标量函数（字符串/数学/日期/控制）+ 列引用，任意嵌套、NULL 传播
- 列级 collation：`ci()` 修饰符——比较/排序折叠大小写，约束保持区分大小写
- NULL 三值逻辑；事务（引擎快照，支持回滚 DDL）
- 四存储引擎：Memory / JsonFile / PhpSerialize / PagedJson（分页增量写 + 墓碑删除页槽复用）
- 683 项测试；异常驱动，全库 strict_types

## 一、近期迭代（高价值 / 低风险）

- [x] **UPSERT**：`Table::upsert(row)` / `Table::insertIgnore(row)`（对应 `INSERT ... ON DUPLICATE KEY UPDATE` / `INSERT IGNORE`）
- [x] **LIKE 转义辅助**：`Query\Like::escape()`，把用户输入按字面量匹配
- [x] **复合主键**：`Blueprint::primary('a', 'b')` 多列联合主键（也接受单列）；主键列隐含 NOT NULL、不可 dropColumn；自增列存在时主键必须恰为该单列；复合主键自动可作等值索引（v1.3 交付）
- [x] **外键策略补全**：`ON DELETE` / `ON UPDATE` 四策略（RESTRICT/CASCADE/SET NULL/SET DEFAULT）全覆盖，含 DDL 前置校验
- [x] **CHECK 约束**：`$t->check(name, condition)`，写入时求值
- [x] **orWhereGroup**：`whereGroup` 目前只有 AND 语义，补 OR 挂载
- [ ] **UPDATE/DELETE 带 ORDER BY + LIMIT**（MySQL 语义，危险但常用）
- [x] **PagedJson 页槽复用**：`deleteRows` 墓碑删除——被删槽位置为页内 null 只重写所在页；死槽 ≥ 40% 且 ≥ 100 自动压实，读取恒返回稠密序列（5 万行删中间 1 行 ~3.4ms vs 62.8ms）
- [x] **多进程防护（最低限度）**：数据目录加锁文件（`Storage\DirectoryLock`），第二个进程打开时抛 `StorageException` 而非静默数据竞争
- [x] **大表迭代读取**：`chunk(size, handler)`（LIMIT/OFFSET 分批、返回 false 提前终止、返回已处理总行数）+ `cursor(): \Generator`（惰性游标，首次迭代才执行查询）
- [ ] **API 收紧**：`Table`/`SelectBuilder` 构造器 `?Connection`（历史测试便利）改回非空；`Psql::connect` 增加 `engine` 选项参数，省去手写 `new Connection(...)`

## 二、SQL 能力补全（中期）

- [x] **子查询（IN/EXISTS）**：`whereIn('id', $sub)` / `whereNotIn` / `whereExists($sub)` / `whereNotExists`——子查询独立完整执行（含自身 orderBy/limit/union）、恰好 1 输出列、支持多层嵌套；UPDATE/DELETE 的 where 同样支持；CHECK 条件中禁用；不支持相关子查询
- [ ] **FROM 派生表**：子查询出现在 FROM 位（当前仅 WHERE 位 IN/EXISTS）
- [x] **UNION / UNION ALL**：多方链式，每个子方完整执行后合并；UNION 去重保首见顺序、UNION ALL 保留重复；两侧输出列键集须一致；外层 distinct/orderBy/limit/offset 生效
- [ ] **JOIN 增强**：ON 支持任意条件组（现在仅单比较）、`USING(col)`
- [x] **表达式与函数库**：CASE WHEN、字符串/数学/日期/控制共 18 个标量函数（含 COALESCE/NULLIF）+ 列引用，任意嵌套、NULL 传播（除 coalesce）
- [x] **二级索引**：`createIndex/dropIndex/hasIndex` + `Blueprint::index`，哈希语义——等值查询预过滤（5 万行热查 ~0.02ms vs 全扫描 ~100ms），主键/单列 unique/联合唯一自动可用作索引，writeVersion 失效重建；范围查询仍扫（B-Tree 为后续方向）
- [x] **执行器升级：hash join**：等值 JOIN（INNER/LEFT/RIGHT）从嵌套循环 O(n·m) 升级为哈希构建+探测（2万×2万 0.094s vs 111.5s）；非 '=' 运算符回退嵌套循环，输出顺序一致
- [ ] **执行器升级：排序外部归并**：大表 ORDER BY 仍为内存排序，外部归并待做
- [ ] **EXPLAIN**：`$query->explain()` 输出访问路径（扫描/索引、估算行数），配合索引做调优入口
- [ ] **视图 VIEW**：命名查询 + 查询展开
- [ ] **触发器**：BEFORE/AFTER INSERT/UPDATE/DELETE 钩子（PHP 闭包）
- [x] **collation**：列级 `ci()` 大小写不敏感比较/排序（WHERE/JOIN ON/ORDER BY 及 UPDATE/DELETE where 折叠大小写；唯一/外键/CHECK 约束保持区分大小写）；CHAR 空格填充语义仍缺
- [ ] **类型补全**：UNSIGNED BIGINT 超 PHP_INT_MAX 范围（bcmath/GMP）、JSON 列类型、BLOB/BINARY、SET
- [ ] **UPSERT 之外的 MySQL 方言**：`REPLACE INTO` 语义、`INSERT ... SELECT`
- [ ] **Savepoint**：事务内命名部分回滚

## 三、完整数据库特性（长期）

- [ ] **查询优化器**：基于统计信息的代价估算、连接顺序选择、谓词下推
- [ ] **事务升级**：MVCC 或行锁、隔离级别（READ COMMITTED / REPEATABLE READ）、写写冲突检测
- [ ] **WAL / 崩溃恢复**：redo log（PagedJson 的 gen 模型是雏形但无按页 redo）；增量备份 / `backup()` API
- [ ] **多连接并发**：文件锁 + 进程间缓存一致性，或单 writer 多 reader 模型
- [ ] **静态压缩 / 静态加密**：页级压缩（zstd）、at-rest 加密（openssl）
- [ ] **CLI shell**：`bin/psql` 交互式终端（依赖 SQL 解析器），`.dump` / `.schema` / `.import` 命令
- [ ] **迁移工具**：schema diff（`migrate(from, to)` 生成 alter 计划）
- [ ] **网络 server 模式**：监听端口接受连接（真正意义的数据库服务；是否属于"本地化"目标待定）
- [ ] **权限系统**：用户/GRANT（单库场景价值有限，优先级最低）
- [ ] **基准体系化**：bench.php 进 CI、大数据量 fuzz（随机 DML 序列 + 不变量断言）

## 四、距离"完整 SQL"的差距矩阵

| 能力 | SQL 标准 / MySQL | psql 现状 | 主要差距 |
|---|---|---|---|
| 类型系统 | 完整 | 核心关系类型 | 缺 JSON/空间类型/SET/BLOB；UNSIGNED BIGINT 上限截断 |
| 约束 | PK/UNIQUE/FK/CHECK/DEFERRABLE | 除 DEFERRABLE 外均有（复合主键、CHECK 与 FK 四策略已支持） | 复合主键已支持；缺 DEFERRABLE |
| SELECT | 子查询/UNION/窗口/CTE/表达式 | JOIN + 聚合 + 子查询（IN/EXISTS）+ UNION + CASE + 函数库 | 子查询（IN/EXISTS）、UNION、CASE、函数库已支持；无 CTE、窗口函数、FROM 派生表 |
| 写入 | UPSERT/REPLACE/INSERT...SELECT | INSERT/UPDATE/DELETE + UPSERT/INSERT IGNORE | 无 REPLACE INTO、INSERT ... SELECT |
| 事务 | 隔离级别/savepoint/并发 | 快照回滚（单进程） | 无并发控制、无 savepoint |
| 索引 | B-Tree/哈希/全文/空间 | 哈希二级索引 + hash join（等值加速，主键/唯一约束自动可用） | 范围查询与 ORDER BY 仍扫描/内存排序；无 B-Tree、无外部归并 |
| 视图/过程/触发器 | 均有 | 无 | 全缺 |
| SQL 文本接口 | 原生 | 无（纯 OOP） | 明确不做：OOP API 即查询语言 |
| 优化器 | 代价优化 + EXPLAIN | 固定执行计划 | 无 |
| 并发/服务 | 多连接网络服务 | 单进程库 | 明确不做：单进程本地库形态 |

> 定位说明：psql 的目标是"MySQL 语义 + PHP OOP 接口的本地库"，对标 SQLite 而非 MySQL Server。矩阵中"明确不做"行是产品边界，不纳入迭代规划（SQL 文本接口、网络服务、权限系统均不做）。

## 五、里程碑建议

| 版本 | 主题 | 内容 |
|---|---|---|
| v1.1 | 写入体验（已完成） | UPSERT、LIKE 转义、orWhereGroup、外键 UPDATE 策略、CHECK、多进程锁 |
| v1.2 | 索引与性能（已完成） | 二级索引（哈希起步）、hash join、chunk/cursor、页槽复用 |
| v1.3 | 语法表达力（已完成） | 复合主键、子查询（IN/EXISTS）、UNION、CASE、函数库、列级 collation |
| v2.0 | 数据库编程 | 视图、触发器、savepoint、EXPLAIN |
| v2.x | 并发与恢复（可选） | WAL、并发访问、静态压缩/加密 |

## 六、已知技术债（实现层）

- `Query/Table`、`Query/SelectBuilder` 构造器接受 `?Connection`（当时为绕开未实现的 Executor 做结构测试），应收紧
- `SelectBuilder::select(string|AggregateExpression ...)` 是全库唯一 union 参数类型
- Executor 对重复 JOIN 源别名抛异常，自连接暂不支持（别名重复语义待另行设计）
- `Table::min/max` 空表抛 `QueryException` 而 `sum/avg` 空表返回 0.0，语义不统一（MySQL 均返回 NULL）
- collation 仅列级 ci（比较/排序折叠大小写），无完整 collation 体系（表达式级 collation、显式 CS 声明等）
- restore/persist 对 PagedJson 走全量重写（低频路径，正确性优先，可接受）
