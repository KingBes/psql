# psql

一个纯 PHP 实现的本地 SQL 数据库引擎——不需要 MySQL 服务器，不需要任何扩展依赖，数据存在本地文件或纯内存中。

设计目标：**语义与使用习惯向 MySQL 看齐（数据类型、约束、JOIN、事务），查询语言以 PHP OOP 链式 API 表达**。相当于"PHP 版 SQLite，MySQL 方言设计，OOP 接口"。

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

- **MySQL 风格类型系统**：TINYINT/SMALLINT/INT/BIGINT（含 UNSIGNED）、DECIMAL(M,D)、FLOAT/DOUBLE、BOOLEAN、CHAR/VARCHAR/TEXT、ENUM、DATE/DATETIME/TIMESTAMP，写入时严格校验与规范化
- **完整约束**：主键（单列与复合）、自增、NOT NULL、DEFAULT / CURRENT_TIMESTAMP、单列与联合唯一、外键（DELETE/UPDATE 各四策略）、CHECK 约束；文件引擎带目录级多进程锁
- **链式查询**：WHERE 嵌套分组、子查询（IN/EXISTS）、UNION / UNION ALL、CASE 与函数表达式、列级 collation（ci）、INNER/LEFT/RIGHT JOIN、GROUP BY + HAVING、COUNT/SUM/AVG/MIN/MAX 聚合、ORDER BY、LIMIT/OFFSET、DISTINCT、LIKE 通配
- **二级索引与 hash join 加速**：等值查询走哈希索引预过滤（主键/唯一约束列自动可用作索引），等值 JOIN 走哈希构建+探测；未命中自动回退全扫描，结果一致
- **NULL 三值逻辑**：与 SQL 一致——NULL 参与比较结果为未知（行被过滤），仅 `IS NULL` / `IS NOT NULL` 可匹配
- **事务**：`begin()/commit()/rollBack()`，快照隔离实现，支持回滚 DDL
- **可插拔存储引擎**：MemoryEngine（纯内存）、JsonFileEngine（可读文件）、PhpSerializeEngine（高性能文件）、PagedJsonEngine（分页增量 + 墓碑删除），可实现 `StorageEngine` 接口自定义
- **异常驱动**：任何失败（类型不合法、约束冲突、IO 损坏、误用）一律抛异常，绝不静默吞错；全库 `declare(strict_types=1)`

## 环境要求

- PHP >= 8.2
- Composer

## 安装

```bash
composer require kingbes/psql
```

## 快速开始

```bash
git clone <your-repo-url> psql
cd psql
composer install
```

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
| [查询（DQL）](docs/query.md) | WHERE/JOIN/聚合/排序/结果集 |
| [写入（DML）](docs/write.md) | INSERT/UPDATE/DELETE、约束行为、级联删除 |
| [事务](docs/transactions.md) | 快照语义、DDL 回滚、限制 |
| [架构与扩展](docs/architecture.md) | 分层设计、存储引擎、异常体系、自定义引擎 |
| [路线图](docs/roadmap.md) | 迭代计划、与完整 SQL 的差距矩阵、技术债 |

## 目录结构

```
src/
├── Psql.php                  # 门面：connect()/memory()
├── Connection.php            # 连接：库/表管理、事务、DML 入口
├── Exception/                # 异常体系（六个具体异常）
├── Schema/                   # Blueprint 建表 DSL、TableSchema、外键定义
├── Type/                     # ValueCaster 值校验与规范化
├── Query/                    # Table/SelectBuilder 链式构建、条件树、Agg
├── Execution/                # Writer 约束管线、Executor 查询流水线、IndexManager 索引
├── Result/                   # ResultSet、InsertResult
└── Storage/                  # StorageEngine 接口、Memory/JsonFile/PhpSerialize/PagedJson 引擎、目录锁
```

## 测试

```bash
composer test
```

683 项单元/集成测试，覆盖类型、约束、DDL、DML、事务、持久化、索引、子查询/UNION/表达式、并发锁等体系。

## License

MIT
