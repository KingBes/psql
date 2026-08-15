# 写入（DML）

## INSERT

```php
use Kingbes\Psql\Result\InsertResult;

// 单行
$result = $db->table('user')->insert(['name' => 'Alice', 'age' => 20]);

// 多行（一批）
$result = $db->table('user')->insertMany([
    ['name' => 'Bob',   'age' => 25],
    ['name' => 'Carol', 'age' => 30],
]);

$result->rowCount();       // int：实际插入行数
$result->lastInsertId();   // ?int：批次最后一行的自增值；无自增列返回 null
```

写入管线按顺序执行：

1. **类型校验与规范化**：显式值逐列转换（见[类型文档](types-and-ddl.md)），非法抛 `TypeException`
2. **缺列补全**：`defaultNow()` → 当前时间；`default()` → 默认值；否则 null
3. **NOT NULL 检查**：无默认且值为 null 抛 `ConstraintException`
4. **自增分配**：自增列缺省时从计数器 +1 递增（跳过与现存行冲突的候选）；显式提供自增值也合法
5. **唯一性检查**：主键、单列 unique、联合 unique——与现存行及**同批次已接受行**比对，冲突抛 `ConstraintException`
6. **外键存在性**：引用值非 null 时必须在目标表目标列中存在

**批内原子**：多行插入中任一行违反约束，整批都不写入，表数据不变。

未知列名抛 `QueryException`。

## UPDATE

```php
// 有条件（经 where 链）
$affected = $db->table('user')
    ->where('age', '<', 18)
    ->update(['vip' => 0]);

// 全表（无条件）
$affected = $db->table('user')->update(['status' => 'active']);
```

- 返回**受影响行数**（int，0 表示无匹配）
- 值同样走类型校验；NOT NULL 列置 null 抛 `ConstraintException`
- 唯一性检查**排除自身**（本行保持原值不算冲突）
- 外键列变更为不存在的值抛 `ConstraintException`
- 被其他表外键引用的列发生值变化时抛 `ConstraintException`（RESTRICT，v1 简化语义）

## DELETE

```php
$affected = $db->table('user')->where('banned', 1)->delete();
$affected = $db->table('user')->delete();   // 全表删除
```

- 返回**受影响行数**（仅统计 where 匹配行，级联删除不计入）
- 被引用行为按外键策略处理：

```php
$t->foreignKey('student_id')->references('student', 'id');                       // RESTRICT（默认）
$t->foreignKey('student_id')->references('student', 'id')->onDeleteCascade();    // CASCADE
```

- **RESTRICT**：删除被引用行抛 `ConstraintException`
- **CASCADE**：级联删除引用行，支持多级链（a ← b ← c）与自引用表；级联过程按广度优先展开

## TRUNCATE

```php
$db->table('user')->truncate();
```

清空全部数据、保留表结构、**自增计数归零**（下次插入从 1 开始）。表被其他表外键引用时抛 `SchemaException`。

## 错误处理总表

| 场景 | 异常 |
|---|---|
| 值类型非法、越界、超长、枚举外、日期格式错误 | `TypeException` |
| 主键/唯一冲突、NOT NULL 违反、外键值不存在、RESTRICT 拦截 | `ConstraintException` |
| 未知列、非法运算符/方向、负 limit、空表 MIN/MAX | `QueryException` |
| 表不存在、JSON 文件损坏、IO 失败 | `StorageException` |
| 表/库已存在或不存在（结构层）、非法表名列名 | `SchemaException` |
| 事务误用 | `TransactionException` |
