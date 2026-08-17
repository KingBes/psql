# 事务

## 基本 API

```php
$db->begin();          // 开启事务；已在事务中抛 TransactionException
$db->commit();         // 提交并持久化；不在事务中抛 TransactionException
$db->rollBack();       // 回滚；不在事务中抛 TransactionException
$db->inTransaction();  // bool
```

典型用法：

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

## 快照语义

psql 的事务采用**引擎全量快照**实现：

- `begin()`：对存储引擎当前全部状态（所有数据库所有表的结构、数据、自增计数）拍照
- `rollBack()`：整体恢复到快照时刻——**包括 DDL**：
  - 事务内 `createTable` 后回滚 → 表消失（磁盘文件同步删除）
  - 事务内 `dropTable` 后回滚 → 表恢复
  - 事务内插入/更新/删除/级联删除后回滚 → 行数据与自增计数完整恢复（回滚后再插入，自增从恢复后的值继续）
- `commit()`：丢弃快照并强制持久化（文件引擎落盘）

```php
$db->createTable('user', fn(Blueprint $t) => $t->id()->varchar('name', 50));
$db->table('user')->insert(['name' => 'Alice']);   // id = 1

$db->begin();
$db->createTable('tmp_log', fn(Blueprint $t) => $t->id()->text('msg'));
$db->table('user')->insert(['name' => 'Bob']);     // id = 2
$db->rollBack();

$db->hasTable('tmp_log');                          // false
$db->table('user')->count();                       // 1
$db->table('user')->insert(['name' => 'Carol']);   // id = 2（计数已随快照恢复）
```

## 保存点 SAVEPOINT（v2.0）

事务内可用命名保存点做部分回滚（详细语义与示例见[写入文档](write.md#事务与-savepoint)）：

```php
$db->begin();
$db->savepoint('sp1');       // 建立保存点（快照压栈）；同名覆盖
$db->rollBackTo('sp1');      // 回滚到该点：撤销其后变更、丢弃更内层保存点；该点保留可重复回滚
$db->releaseSavepoint('sp1');    // 释放保存点（不改数据）；外层释放时内层一并失效
```

- 三方法在事务外调用抛 `TransactionException`；`commit()` / `rollBack()` 清空保存点栈
- 保存点快照同样覆盖 DDL 与视图目录——事务内建/删表、建/删视图均可部分回滚
- `rollBackTo` 后索引缓存自动失效重建

## 限制（v1 文档化）

- **单进程模型**：无并发控制、无锁、无隔离级别配置——psql 运行在单个 PHP 进程内，同一数据目录不应被多个进程同时打开
- **不支持嵌套事务**：重复 `begin()` 抛 `TransactionException`；提交/回滚后可立即开启新事务
- 事务与连接绑定：`use()` 切换数据库不影响事务范围（快照覆盖所有库）
