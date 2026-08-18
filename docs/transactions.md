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

## 并发与崩溃恢复（v2.2 / v2.3）

默认（无选项）仍是**单进程独占**模型。需要多进程访问或崩溃恢复时，用连接选项开启：

### 单 writer 多 reader（v2.2）

```php
$db = Psql::connect('/data/appdb', ['concurrency' => true]);
```

- 操作级读写锁（`Storage\DirectoryLock` + `LockingEngine` 装饰器）：读共享 `LOCK_SH`（多进程并存）、写排他 `LOCK_EX`（互斥）
- **事务全程持写锁**：`begin()` 起阻塞等待当前 reader 释放，`commit()` / `rollBack()` 释放——事务对并发 reader 原子可见
- 跨进程缓存失效：`<root>/.wv` 写版本，读前校验变化即清空进程内缓存（长驻 reader 能看到 writer 的新写入）
- SELECT 经 `readLocked` 整语句持共享锁（语句级一致性）
- 访问同一库目录的进程应**一致**使用本选项（或一致用默认独占模式），避免混用
- 仍无隔离级别配置与多 writer 写写冲突检测（见下"限制"）

### WAL / 崩溃恢复（v2.3）

```php
$db = Psql::connect('/data/appdb', ['wal' => true]);                       // 崩溃恢复
$db = Psql::connect('/data/appdb', ['wal' => true, 'concurrency' => true]); // 两者组合
```

- `begin()` 把事务前全引擎快照**原子写入** `<root>/undo.snap`；事务内写照常落盘
- 进程崩溃（未 commit）后重新打开：自动检测 `undo.snap` → 恢复引擎到事务前状态并落盘 → 清理
- `commit()` / `rollBack()` 正常清理 `undo.snap`；`wal.log` 记录事务生命周期（供崩溃诊断）
- **进程级恢复**：PHP 无可靠 fsync，断电级（OS 缓存丢失）不保证；已提交事务写穿即落盘无需重放

## 限制

- **隔离级别 / MVCC**：快照模型提供可重复读语义（单连接内），但无隔离级别配置；多 writer 写写冲突检测未实现（并发模式预期单 writer）
- **不支持嵌套事务**：重复 `begin()` 抛 `TransactionException`；提交/回滚后可立即开启新事务
- 事务与连接绑定：`use()` 切换数据库不影响事务范围（快照覆盖所有库）
