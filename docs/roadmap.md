# psql 路线图（Roadmap）

记录已完成能力、下一轮迭代候选、以及距离"一个完整的 SQL 数据库"的差距。勾选状态随版本推进更新。

## 现状基线（v1 已交付）

- OOP 链式查询 API（无 SQL 文本解析）
- 类型系统：TINYINT~BIGINT（UNSIGNED）、DECIMAL(M,D)、FLOAT/DOUBLE、BOOLEAN、CHAR/VARCHAR/TEXT、ENUM、DATE/DATETIME/TIMESTAMP
- 约束：主键（单列）、自增、NOT NULL、DEFAULT/CURRENT_TIMESTAMP、单列与联合唯一、外键（RESTRICT / ON DELETE CASCADE）
- DML：SELECT（WHERE 嵌套分组 / 三种 JOIN / GROUP BY+HAVING / 聚合 / ORDER BY / LIMIT/OFFSET / DISTINCT / LIKE）、INSERT（批内原子）、UPDATE、DELETE（BFS 级联）、TRUNCATE
- NULL 三值逻辑；事务（引擎快照，支持回滚 DDL）
- 四存储引擎：Memory / JsonFile / PhpSerialize / PagedJson（分页增量写）
- 330 项测试；异常驱动，全库 strict_types

## 一、近期迭代（高价值 / 低风险）

- [ ] **UPSERT**：`upsert(row, uniqueColumns)` / `insertIgnore()`（对应 `INSERT ... ON DUPLICATE KEY UPDATE` / `INSERT IGNORE`）
- [ ] **LIKE 转义辅助**：`Str::likeEscape('100%')` 之类工具，把用户输入按字面量匹配
- [ ] **复合主键**：目前至多一个主键列，放开为多列联合主键（schema/Writer/唯一性检查联动）
- [ ] **外键策略补全**：`ON UPDATE CASCADE`、`SET NULL`、`SET DEFAULT`（现在只有 DELETE 的 RESTRICT/CASCADE）
- [ ] **CHECK 约束**：`$t->check(fn/expr)`，写入时求值
- [ ] **orWhereGroup**：`whereGroup` 目前只有 AND 语义，补 OR 挂载
- [ ] **UPDATE/DELETE 带 ORDER BY + LIMIT**（MySQL 语义，危险但常用）
- [ ] **PagedJson 页槽复用**：删除中间行目前走 suffix 全量重写（Writer 全量替换语义 + 引擎 diff 的固有限制）；引入删除标记位图 + 惰性压实
- [ ] **多进程防护（最低限度）**：数据目录加锁文件，第二个进程打开时抛 `StorageException` 而非静默数据竞争
- [ ] **大表迭代读取**：`getCursor()` / `chunk(size, callback)`，避免一次性实例化全部行
- [ ] **API 收紧**：`Table`/`SelectBuilder` 构造器 `?Connection`（历史测试便利）改回非空；`Psql::connect` 增加 `engine` 选项参数，省去手写 `new Connection(...)`

## 二、SQL 能力补全（中期）

- [ ] **SQL 字符串解析器**：Lexer + Parser + AST，`$db->query("SELECT ... WHERE ...")` 与 OOP API 双轨。v1 明确推迟的最大项；建议复用 phpMyAdmin sql-parser 生成初始 AST 再映射到内部 `SelectQuery`，或自研精简方言
- [ ] **子查询**：`whereIn('id', $subSelect)`、`whereExists($subSelect)`、FROM 派生表
- [ ] **UNION / UNION ALL**
- [ ] **JOIN 增强**：ON 支持任意条件组（现在仅单比较）、`USING(col)`
- [ ] **表达式与函数库**：CASE WHEN、字符串函数（CONCAT/SUBSTR/...）、日期函数（NOW/DATE_ADD/...）、数学函数、COALESCE/NULLIF
- [ ] **二级索引**：`createIndex/dropIndex`，v1 从哈希索引起步（等值查询 O(1)，范围查询仍扫），执行器按索引可用性选择访问路径
- [ ] **执行器升级**：JOIN 从嵌套循环 O(n·m) 到 hash join；排序外部归并（大表）
- [ ] **EXPLAIN**：`$query->explain()` 输出访问路径（扫描/索引、估算行数），配合索引做调优入口
- [ ] **视图 VIEW**：命名查询 + 查询展开
- [ ] **触发器**：BEFORE/AFTER INSERT/UPDATE/DELETE 钩子（PHP 闭包）
- [ ] **collation**：列级大小写不敏感比较/排序规则（现在全程区分大小写）；CHAR 空格填充语义
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
| 约束 | PK/UNIQUE/FK/CHECK/DEFERRABLE | 除 CHECK 外均有 | 无 CHECK；单列主键；FK 无 UPDATE 策略 |
| SELECT | 子查询/UNION/窗口/CTE/表达式 | 单层查询 + JOIN + 聚合 | 无子查询、UNION、CTE、窗口函数、CASE、函数库 |
| 写入 | UPSERT/REPLACE/INSERT...SELECT | 纯 INSERT/UPDATE/DELETE | 无冲突处理语句 |
| 事务 | 隔离级别/savepoint/并发 | 快照回滚（单进程） | 无并发控制、无 savepoint |
| 索引 | B-Tree/哈希/全文/空间 | 无（线性扫描） | 最大性能差距项 |
| 视图/过程/触发器 | 均有 | 无 | 全缺 |
| SQL 文本接口 | 原生 | 无（纯 OOP） | 定位差异：OOP 是特色，文本接口是兼容层 |
| 优化器 | 代价优化 + EXPLAIN | 固定执行计划 | 无 |
| 并发/服务 | 多连接网络服务 | 单进程库 | 定位差异 |

> 定位说明：psql 的目标是"MySQL 语义 + PHP OOP 接口的本地库"，对标 SQLite 而非 MySQL Server。矩阵中"定位差异"行是否补齐取决于是否向 server 形态演进。

## 五、里程碑建议

| 版本 | 主题 | 内容 |
|---|---|---|
| v1.1 | 写入体验 | UPSERT、LIKE 转义、orWhereGroup、外键 UPDATE 策略、CHECK、多进程锁 |
| v1.2 | 索引与性能 | 二级索引（哈希起步）、hash join、chunk/cursor、页槽复用 |
| v1.3 | 语法表达力 | 复合主键、子查询、UNION、CASE、函数库、collation |
| v2.0 | SQL 文本接口 | 解析器 + `$db->query()` 双轨、CLI shell |
| v2.x | 数据库编程 | 视图、触发器、savepoint、EXPLAIN |
| v3.0 | 并发与服务（可选） | WAL、并发访问、server 模式 |

## 六、已知技术债（实现层）

- `Query/Table`、`Query/SelectBuilder` 构造器接受 `?Connection`（当时为绕开未实现的 Executor 做结构测试），应收紧
- `SelectBuilder::select(string|AggregateExpression ...)` 是全库唯一 union 参数类型
- Executor 对重复 JOIN 源别名抛异常，自连接需 SQL 文本接口引入后才好支持（别名重复语义）
- `Table::min/max` 空表抛 `QueryException` 而 `sum/avg` 空表返回 0.0，语义不统一（MySQL 均返回 NULL）
- LIKE 大小写敏感固定，无 collation 概念（与矩阵 collation 项同源）
- restore/persist 对 PagedJson 走全量重写（低频路径，正确性优先，可接受）
