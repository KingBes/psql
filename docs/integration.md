# ThinkPHP / webman 集成

think-orm 是 PHP 里最常见的 ORM 之一，ThinkPHP 内置、webman 经本包自带的引导类接入——
两者共用同一套 `topthink/think-orm` 连接层。因此本引擎为它提供一个通用驱动
`Kingbes\Psql\Bridge\PsqlOrm`，**一份驱动，两个框架通用**，无需修改框架代码。

## 原理

ThinkPHP 与 webman 的 `think\DbManager` 在创建连接时，若 `connections[连接名]['type']`
为**完整类名**（含 `\`），会直接 `new` 该类；本驱动实现 `think\db\ConnectionInterface`，
把 think-orm 的 `BaseQuery` 查询状态翻译为 psql 链式调用执行。两者共用同一个扩展点，
所以只要在各自 `config` 里把 `type` 指向驱动类即可。

```php
use Kingbes\Psql\Bridge\PsqlOrm;
// 用法：config 里 type 填 \Kingbes\Psql\Bridge\PsqlOrm::class
```

## 安装

```bash
composer require kingbes/psql
```

- ThinkPHP 已自带 `topthink/think-orm`，无需其它；**webman 需额外装 think-orm**：
  ```bash
  composer require topthink/think-orm
  ```
- 安装即用：本包按两种框架各自的插件规范**自动生成配置文件并注册启动服务**，无需手动建文件
  （ThinkPHP 参考 `annotation` 的 `extra.think` 机制、webman 参考 `Attribute` 的 `WEBMAN_PLUGIN`
  `Install.php` 机制）。
- think-orm 仅作为本包的 `suggest` 出现，Bridge 是惰性加载的。
- 若使用 psql 的加密选项 `key` 需启用 openssl 扩展。

## ThinkPHP 配置

安装后自动生成 `config/psql.php`（由 `Kingbes\Psql\Service` 在启动时把其中的连接注入
`config/database.php` 的 `connections.psql`）。只需按需改这个生成的文件：

```php
// config/psql.php（安装自动生成）
return [
    'database'    => '',   // 本地数据目录；留空默认 runtime/psql
    'default'     => false,// true 则把 psql 设为默认数据库连接
    'concurrency' => false,// 单 writer 多 reader 并发
    'wal'         => false,// WAL / 崩溃恢复
    'compress'    => false,
    'key'         => null,
];
```

若想手动接管，也可直接在 `config/database.php` 加一条连接（与生成内容等价）：

```php
'connections' => [
    // ... 原有的 mysql 等连接 ...
    'psql' => [
        'type'     => \Kingbes\Psql\Bridge\PsqlOrm::class,
        'database' => runtime_path() . 'psql',
        'psql'     => ['concurrency' => false, 'wal' => false],
    ],
],
```

## webman 配置

psql 是标准的 **webman 插件**（`Install.php`，`WEBMAN_PLUGIN`），安装时自动把插件目录发布到
主项目 `config/plugin/kingbes/psql/`，遵循 webman 插件规范，与 `webman/think-orm` 无关：

- `config/plugin/kingbes/psql/app.php` —— 连接配置，webman 自动加载为
  `config('plugin.kingbes.psql.app')`
- `config/plugin/kingbes/psql/bootstrap.php` —— 启动引导，webman 启动时自动遍历并调用其中的
  `\Kingbes\Psql\Bridge\Webman\Bootstrap::start()`

`composer require kingbes/psql` 后无需任何手动配置，只需按需修改生成的 `app.php`：

```php
// config/plugin/kingbes/psql/app.php（安装自动生成）
return [
    'default' => 'psql',
    'connections' => [
        'psql' => [
            'type'     => \Kingbes\Psql\Bridge\PsqlOrm::class,
            'database' => runtime_path() . '/psql',
            'psql'     => [
                'concurrency' => false, // webman 建议关闭 flock——见下方说明
                'wal'         => false,
            ],
        ],
    ],
];
```

`Bootstrap` 用这份配置构造一个 topthink/think-orm 的 `DbManager`，把 `PsqlOrm` 连接驱动设为
default，并绑定到 think 容器，使 `think\facade\Db`、Model、分页在 webman 里即可用。

### webman 专属注意事项

webman 是 Workerman 协程模型，与 psql 的进程级并发模型有一处差异需规避：

**协程 vs flock**：psql 的 `concurrency` 用 `flock`（OS 级、阻塞），在协程中持锁期间若发生
协程切换可能死锁。webman 下保持 `concurrency => false`，依赖单进程内串行 + 快照事务即可。

## 使用示例

建表请先用 psql 原生 API（think-orm 只做 DML，不做 DDL），再通过 `Db` 使用：

```php
use think\facade\Db;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\Blueprint;

// 1) 建表（幂等）
Psql::connect(runtime_path() . 'psql')->createTableIfNotExists('user', function (Blueprint $t) {
    $t->id();
    $t->varchar('name', 50)->notNull();
    $t->tinyint('age')->unsigned()->default(0);
    $t->datetime('created_at')->defaultNow();
});

// 2) 通过 think-orm 读写
Db::connect('psql')->name('user')->insert([
    'name' => 'Alice', 'age' => 20,
]);

$list = Db::connect('psql')->name('user')
    ->where('age', '>', 18)
    ->order('age', 'desc')
    ->limit(10)
    ->select();

foreach ($list as $row) {
    echo $row['name'], PHP_EOL;
}
```

webman 中取库入口与 ThinkPHP 一致，用 `think\facade\Db`（由本包引导类把 topthink/think-orm
的 `DbManager` 绑定到 think 容器后可直接使用）：

```php
use think\facade\Db;

// 建表同样用 psql 原生 API（见上方 ThinkPHP 示例），随后即可：
$list = Db::name('user')->where('age', '>', 18)->select(); // default 已是 psql 连接
```

同样支持条件写、事务、分页、聚合：

```php
Db::connect('psql')->name('user')->where('name', 'Bob')->update(['age' => 30]);
Db::connect('psql')->name('user')->where('name', 'Bob')->delete();

Db::connect('psql')->transaction(function ($conn) {
    $conn->name('user')->insert(['name' => 'Tx', 'age' => 1]);
});

$page = Db::connect('psql')->name('user')->paginate(15); // 分页由 think-orm 翻译
$cnt  = Db::connect('psql')->name('user')->where('age', '>', 18)->count();
```

## 模型（Model）

ThinkPHP 与 webman 都用 think-orm 的 `think\Model`。建立模型后，只需在工作在 psql 连接上的
模型里声明 `protected $connection = 'psql';`（若该连接已是 default 则可省略）：

```php
namespace app\model;

use think\Model;

class User extends Model
{
    // 指定使用 psql 连接（对应 config 里注入的 psql 连接）
    protected $connection = 'psql';
    protected $table      = 'user';   // 表名（psql 中已用原生 API 建好的表）
    protected $pk         = 'id';     // 主键（psql id() 自增列）
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime         = 'created_at'; // 对应建表里的 created_at
    protected $updateTime         = false;        // 表里没有 update_time 列就关闭
}
```

> 注意：`$connection` 是指向 config 注入的 `psql` 连接，而非 psql 的库目录；
> 库目录在 `config/psql.php`（ThinkPHP）或 `config/plugin/kingbes/psql/app.php`（webman）里配置。

使用：

```php
use app\model\User;

// 按主键取
$user = User::find(1);
// 条件查询
$users = User::where('age', '>', 18)->order('id', 'desc')->limit(10)->select();
// 新增（自动时间戳由 think-orm 写入 created_at）
User::create(['name' => 'Alice', 'age' => 20]);
// 更新 / 删除
User::where('name', 'Bob')->update(['age' => 30]);
User::where('name', 'Bob')->delete();
// 关联、分页、事务等用法与普通 think-orm 模型一致
```

webman 下模型命名空间与路径略有不同（`app\model\...`、文件在 `app/model/`），但类定义
与用法完全一致。若 webman 的 psql 连接已作为 default，模型可省略 `$connection` 声明，自动走 psql。

## 支持范围与限制

驱动覆盖：`find/select/value/column/insert/insertAll/update/delete`、嵌套 AND/OR 条件、
比较/IN/BETWEEN/LIKE/IS NULL、JOIN（单等值）、ORDER BY、LIMIT/OFFSET/分页、聚合(count/sum/
avg/min/max)、事务与嵌套事务（内部映射为 psql savepoint）、表字段/表清单读取。

仍未支持而**显式抛异常**（不会静默产生错误数据）：原始 `Raw`/`whereRaw`/`whereExp`、
`EXISTS` 子查询、`NOT BETWEEN`、`NOT LIKE`、`HAVING`、多条件 JOIN、`selectInsert`、自增批量指定。
DDL（建表/改表/迁移）请继续使用 psql 原生 API 或 `Migration` 工具。

> 设计取舍：psql 是"向 MySQL 看齐的 OOP 查询引擎"，非 SQL 解析器。桥接层负责把 think-orm
> 的查询翻译过去；翻译不了的构造宁可抛异常，也不返回错误结果。