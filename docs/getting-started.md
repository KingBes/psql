# 快速上手

## 两种连接方式

```php
use Kingbes\Psql\Psql;

// 1. 文件持久化连接：数据落在磁盘，重开后仍在
$db = Psql::connect('/data/appdb');

// 单 writer 多 reader 并发 / WAL 崩溃恢复（可选，可组合）
// $db = Psql::connect('/data/appdb', ['concurrency' => true]);
// $db = Psql::connect('/data/appdb', ['wal' => true, 'concurrency' => true]);

// 2. 纯内存连接：适合缓存、测试、临时数据
$db = Psql::memory();
```

- `connect(path)`：目录不存在会自动创建；路径不可写、磁盘 IO 失败抛 `StorageException`
- `connect(path, options)`：`concurrency`（单 writer 多 reader 并发）与 `wal`（WAL 崩溃恢复）可选开启，详见[事务文档](transactions.md#并发与崩溃恢复v22--v23)
- `memory()`：数据只存在于当前 PHP 进程，进程结束即消失

文件引擎的磁盘布局为 `<path>/<数据库>/<表>.json`，每个 JSON 文件包含表结构（schema）、自增计数（auto_increment）与全部行数据（rows），写入采用"临时文件 + rename"原子替换，损坏的文件在读取时抛 `StorageException`。

## 多数据库

一个连接内可管理多个数据库，默认使用 `main`：

```php
$db->createDatabase('blog');
$db->use('blog');              // 切换当前库；不存在抛 SchemaException
$db->hasDatabase('blog');      // true
$db->databases();              // ['blog', 'main']

$db->createTable('post', ...); // 建在当前库（blog）
$db->dropDatabase('blog');     // 删除当前库时自动切回 main
```

## 基本流程

```php
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\Blueprint;

$db = Psql::connect('/data/appdb');

// 1. 建表（已存在抛 SchemaException；可用 createTableIfNotExists）
$db->createTable('user', function (Blueprint $t) {
    $t->id();
    $t->varchar('name', 50)->notNull()->unique();
    $t->tinyint('age')->unsigned()->default(0);
    $t->datetime('created_at')->defaultNow();
});

// 2. 插入
$result = $db->table('user')->insert(['name' => 'Alice', 'age' => 20]);
$result->rowCount();       // 1
$result->lastInsertId();   // 1（无自增列的表返回 null）

// 3. 查询
$row = $db->table('user')->where('name', 'Alice')->first();
// ['id' => 1, 'name' => 'Alice', 'age' => 20, 'created_at' => '2026-08-15 10:00:00']

// 4. 更新 / 删除（返回受影响行数）
$affected = $db->table('user')->where('id', '=', 1)->update(['age' => 21]);
$affected = $db->table('user')->where('age', '<', 18)->delete();

// 5. 结构维护
$db->hasTable('user');     // true
$db->tables();             // ['user']
$db->renameTable('user', 'member');
$db->dropTable('member');
```

## 事务

```php
$db->begin();
try {
    $db->table('account')->where('id', 1)->update(['balance' => 90]);
    $db->table('account')->where('id', 2)->update(['balance' => 110]);
    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    throw $e;
}
```

详见[事务文档](transactions.md)。

## 下一步

- [类型与建表](types-and-ddl.md)：完整的类型系统与 DDL DSL
- [查询](query.md)：JOIN、聚合、嵌套条件
- [写入](write.md)：约束行为与级联删除
