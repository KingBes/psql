# psql

一个纯 PHP 实现的本地 SQL 数据库引擎——不需要 MySQL 服务器，不需要任何扩展依赖，数据存在本地文件或纯内存中。

设计目标：**语义与使用习惯向 MySQL 看齐（数据类型、约束、JOIN、事务），查询语言以 PHP OOP 链式 API 表达**。
相当于"PHP 版 SQLite，MySQL 方言设计，OOP 接口"。

```php
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Agg;
use Kingbes\Psql\Schema\Blueprint;

$db = Psql::connect('/data/appdb');   // 持久化（JSON 文件存储）
// $db = Psql::memory();              // 或纯内存

// 建表（仿 MySQL 类型与约束）
$db->createTable('user', function (Blueprint $t) {
    $t->id();
    $t->varchar('name', 50)->notNull()->unique();
    $t->tinyint('age')->unsigned()->default(0);
    $t->decimal('balance', 10, 2)->default(0);
    $t->enum('gender', ['男', '女', '未知'])->default('未知');
    $t->datetime('created_at')->defaultNow();
});

// 写入
$db->table('user')->insert(['name' => 'Alice', 'age' => 20]);

// 查询
$rows = $db->table('user')
    ->select('id', 'name')
    ->where('age', '>', 18)
    ->orderBy('age', 'DESC')
    ->limit(10)
    ->get();

foreach ($rows as $row) {
    echo $row['name'], PHP_EOL;
}
```

## 特性

- **MySQL 风格类型系统**：TINYINT/SMALLINT/INT/BIGINT（含 UNSIGNED，超 PHP_INT_MAX 用十进制字符串 + bcmath 精确比较）、DECIMAL(M,D)、FLOAT/DOUBLE、BOOLEAN、CHAR/VARCHAR/TEXT、ENUM、JSON/BLOB/BINARY/SET、DATE/DATETIME/TIMESTAMP，写入时严格校验与规范化
- **完整约束**：主键（单列与复合）、自增、NOT NULL、DEFAULT / CURRENT_TIMESTAMP、单列与联合唯一、外键（DELETE/UPDATE 各四策略）、CHECK 约束；文件引擎带目录级多进程锁（默认独占，可开单 writer 多 reader 读写锁）
- **链式查询**：WHERE 嵌套分组、子查询（IN/EXISTS + 标量，含相关）、CTE（WITH 非递归命名子查询，FROM/JOIN 位引用）、UNION / UNION ALL、CASE 与函数表达式、窗口函数（ROW_NUMBER/RANK/DENSE_RANK + 聚合 OVER PARTITION BY）、列级 collation（ci）、INNER/LEFT/RIGHT JOIN（含自连接、ON 条件组、USING）、GROUP BY + HAVING、COUNT/SUM/AVG/MIN/MAX 聚合、ORDER BY、LIMIT/OFFSET、DISTINCT、LIKE 通配
- **二级索引与 hash join 加速**：等值查询走哈希索引预过滤（主键/唯一约束列自动可用作索引），等值 JOIN 走哈希构建+探测；未命中自动回退全扫描，结果一致；大结果集 ORDER BY 自动外部归并（写临时文件分块 + 多路归并）
- **NULL 三值逻辑**：与 SQL 一致——NULL 参与比较结果为未知（行被过滤），仅 `IS NULL` / `IS NOT NULL` 可匹配
- **MySQL 风格写入**：INSERT（批内原子）、UPSERT / INSERT IGNORE、REPLACE INTO（冲突删旧插新）、INSERT ... SELECT（任意查询作源）、UPDATE / DELETE 带 ORDER BY + LIMIT、多表 UPDATE / DELETE（JOIN 定位匹配行，SET 键 `别名.列` 限定目标表）
- **事务**：`begin()/commit()/rollBack()`，快照隔离实现，支持回滚 DDL；savepoint 命名部分回滚
- **单 writer 多 reader 并发**：`Psql::connect($dir, ['concurrency' => true])`——操作级读写锁（读共享/写排他）+ `.wv` 写版本跨进程缓存失效 + 事务全程持写锁；多进程可同时读、写进程间互斥
- **WAL / 崩溃恢复**：`Psql::connect($dir, ['wal' => true])`——事务级 undo 快照（崩溃后重新打开自动回滚未提交事务）+ 事务日志；进程级崩溃恢复，可与并发模式组合
- **数据库编程**：视图（`createView`/`view`，结构化持久化、事务可回滚）、PHP 闭包触发器（BEFORE/AFTER × INSERT/UPDATE/DELETE 六钩子）、savepoint、`explain()` 静态计划分析
- **迁移工具**：`Migration::diff($target, $current)` 生成 schema 迁移计划、`Migration::apply` 顺序执行（建表/删表/加删改列/索引；NOT NULL 改动降级为手工提示）
- **可插拔存储引擎**：MemoryEngine（纯内存）、JsonFileEngine（可读文件）、PhpSerializeEngine（高性能文件）、PagedJsonEngine（分页增量 + 墓碑删除），可实现 `StorageEngine` 接口自定义；支持静态压缩与加密（gzip / AES-256-CBC + HMAC）及 `backup()` 完整备份
- **MVC 框架集成**：通过 think-orm 驱动 `Kingbes\Psql\Bridge\PsqlOrm` 在 ThinkPHP / webman 中使用（`Db::name` / Model）。ThinkPHP 经 composer `extra.think` 自动注入连接、webman 经自带 `Install`/`Bootstrap` 安装即用，详见 [集成文档](docs/integration.md)
- **异常驱动**：任何失败（类型不合法、约束冲突、IO 损坏、误用）一律抛异常，绝不静默吞错；全库 `declare(strict_types=1)`

## 环境要求

- PHP >= 8.2
- Composer
- openssl 扩展（可选：仅在使用连接选项 `key` 做静态加密时需要）

## 安装

```bash
composer require kingbes/psql
```

> 在 ThinkPHP / webman（think-orm）里开箱即用：ThinkPHP 装完自动注入 `config/psql.php`
> 里的连接、webman 作为标准插件装完自动发布 `config/plugin/kingbes/psql/`（配置 + 启动引导），
> 详见 [ThinkPHP / webman 集成](docs/integration.md)。

## 快速开始

```php
require __DIR__ . '/vendor/autoload.php';

$db = Kingbes\Psql\Psql::connect(__DIR__ . '/data');  // 目录不存在会自动创建

$db->createTable('post', function (Kingbes\Psql\Schema\Blueprint $t) {
    $t->id();
    $t->varchar('title', 100)->notNull();
    $t->text('content');
    $t->datetime('created_at')->defaultNow();
});

$result = $db->table('post')->insert([
    'title' => '第一篇文章',
    'content' => 'Hello psql!',
]);

echo $result->lastInsertId();   // 1
```

## 文档

| 文档 | 内容 |
|---|---|
| [快速上手](docs/getting-started.md) | 连接方式、多数据库、基本流程 |
| [类型与建表（DDL）](docs/types-and-ddl.md) | 数据类型、值转换规则、建表/改表 DSL |
| [查询（DQL）](docs/query.md) | WHERE/JOIN/聚合/排序/子查询/UNION/视图/EXPLAIN/结果集 |
| [写入（DML）](docs/write.md) | INSERT/INSERT...SELECT/REPLACE/UPDATE/DELETE、约束行为、级联删除、触发器、savepoint |
| [事务](docs/transactions.md) | 快照语义、DDL 回滚、savepoint、限制 |
| [ThinkPHP / webman 集成](docs/integration.md) | 通过本包自带驱动的 think-orm 接入（ThinkPHP 直连、webman 用自带引导类，Db::name / Model） |
| [架构与扩展](docs/architecture.md) | 分层设计、存储引擎、异常体系、自定义引擎 |
| [路线图](docs/roadmap.md) | 迭代计划、与完整 SQL 的差距矩阵、技术债 |

## 目录结构

```
src/
├── Psql.php                  # 门面：connect()/memory()
├── Connection.php            # 连接：库/表管理、事务、DML 入口
├── Install.php               # webman 插件安装（发布 config/plugin/kingbes/psql 插件目录）
├── Service.php               # ThinkPHP 服务（自动把 psql 连接注入 config/database.php）
├── Exception/                # 异常体系（六个具体异常）
├── Schema/                   # Blueprint 建表 DSL、TableSchema、外键定义
├── Type/                     # ValueCaster 值校验与规范化
├── Query/                    # Table/SelectBuilder 链式构建、条件树、Agg、视图定义、EXPLAIN
├── Execution/                # Writer 约束管线、Executor 查询流水线、IndexManager 索引、触发器
├── Result/                   # ResultSet、InsertResult
├── Bridge/                   # 框架桥接：PsqlOrm（think-orm 驱动）+ Webman\Bootstrap（webman 引导）
├── config/                   # 插件配置模板（ThinkPHP psql.php；webman plugin/kingbes/psql/）
└── Storage/                  # StorageEngine 接口、Memory/JsonFile/PhpSerialize/PagedJson 引擎、目录锁
```

## 测试与质量

```bash
composer test        # PHPUnit（999 项）
composer analyse     # PHPStan（level 5）
composer bench       # 存储引擎基准（php bench.php；CI 用 php bench.php --smoke）
```

999 项单元/集成测试，覆盖类型、约束、DDL、DML、事务、持久化、索引、子查询/UNION/CTE/窗口函数/表达式、视图/触发器/savepoint/EXPLAIN、迁移工具、存储压缩/加密/备份、单 writer 多 reader 并发、WAL 崩溃恢复等体系；CI（GitHub Actions，PHP 8.2/8.3/8.4）自动跑 PHPUnit + PHPStan + bench smoke。

## License

MIT
