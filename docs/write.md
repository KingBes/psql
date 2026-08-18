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
7. **CHECK 求值**：在默认回填与自增分配之后求值，false 抛 `ConstraintException`

**批内原子**：多行插入中任一行违反约束，整批都不写入，表数据不变。

未知列名抛 `QueryException`。

## INSERT ... SELECT

把任意 SELECT 查询的结果集批量插入目标表：

```php
// 归档旧日志
$inserted = $db->table('archive')->insertSelect(
    $db->table('log')->where('created', '<', '2024-01-01')
);

// 源可以是任意 SelectBuilder：聚合 / JOIN / UNION / 子查询条件均可
$inserted = $db->table('stat')->insertSelect(
    $db->table('log')
        ->select('level', Agg::count('*')->as('cnt'))
        ->groupBy('level')
);
```

- **列按键名匹配**：源行输出键须为目标表列名的**子集**；未覆盖的列走缺省补全（`default()` / `defaultNow()` / null），自增列缺省时照常分配
- **未知列抛**：源行键集含目标表不存在的列抛 `QueryException`（消息含差集）；**空结果集返回 0 并跳过列校验**
- **自引用禁止**：源查询的基表、JOIN 源或 UNION 子方出现目标表本身（递归读写）抛 `QueryException`
- **批内原子**：任一行违反约束整批不写入；类型校验、NOT NULL、唯一、外键、CHECK、自增、触发器（INSERT 系）全部照常生效

## UPSERT / INSERT IGNORE

```php
// 无冲突：插入新行，返回 1
$affected = $db->table('user')->upsert(['name' => 'Alice', 'age' => 20]);

// name 撞唯一约束：更新命中的那一行，返回 2（MySQL 惯例）
$affected = $db->table('user')->upsert(['name' => 'Alice', 'age' => 21]);

// 唯一冲突：静默跳过，返回 0（自增计数不消耗）
$affected = $db->table('user')->insertIgnore(['name' => 'Alice', 'age' => 20]);
```

- **upsert(row): int**：与现存行比对全部唯一约束（主键、单列 unique、联合唯一——联合唯一元组全非 null 才参与比对）；无冲突走正常插入返回 1；命中任一约束则更新该命中行返回 2
- **歧义异常**：多个唯一约束分别命中**不同行**时无法确定更新目标，抛 `ConstraintException`
- **insertIgnore(row): int**：仅对唯一冲突静默跳过（返回 0，自增不消耗）；类型非法、NOT NULL、外键、CHECK 违反仍照常抛异常——绝不吞掉非冲突错误

### 冲突写入口对比

| 入口 | 唯一冲突时 | 返回值 | 触发器 |
|---|---|---|---|
| `insert` | 抛 `ConstraintException` | — | — |
| `insertIgnore` | 静默跳过该行 | 0 | 被忽略行不触发 |
| `upsert` | 更新命中的那一行 | 1（插入）/ 2（更新） | 冲突路径触发 UPDATE 系 |
| `replace` | 删旧行、再插新行 | 删除 + 插入合计（1 或 2） | 冲突路径 DELETE 系 → INSERT 系 |

## REPLACE INTO

MySQL 语义：唯一冲突时**先删旧行、再插新行**：

```php
// 无冲突：纯插入，返回 1
$affected = $db->table('user')->replace(['id' => 1, 'name' => 'Alice', 'age' => 20]);

// id=1 已存在：删旧插新，返回 2（删除 1 + 插入 1）
$affected = $db->table('user')->replace(['id' => 1, 'name' => 'Alice', 'age' => 21]);

// 批量：逐行独立处理
$affected = $db->table('user')->replaceMany([
    ['id' => 1, 'name' => 'Alice'],
    ['id' => 2, 'name' => 'Bob'],
]);
```

- **冲突判定**：主键、单列 unique、联合 unique（复合唯一元组）——命中即删旧行再插新行
- **返回值口径**：**删除 + 插入合计**——无冲突返回 1；冲突删 1 插 1 返回 2
- **校验先行**：新行先走完整写入校验（NOT NULL / CHECK / 外键存在性），**失败抛异常且旧行保留**——不会出现"旧删了新的插不进"的中间态
- **删除走完整 DELETE 管线**：BEFORE / AFTER DELETE 触发器照常触发；被其他表外键引用时 RESTRICT 照常拦截（MySQL 同款）
- **触发器序**：BEFORE DELETE → AFTER DELETE → BEFORE INSERT → AFTER INSERT
- **新行落表尾**：物理上新行追加到表尾，并非原地替换（MySQL 同款）
- **自增列未提供时**：主键组不参与冲突判定（自增值尚未确定，无从比对），照常分配新值
- **replaceMany 非批内原子**：逐行独立处理（MySQL 同款）——某行抛异常时**此前已成功的行不回滚**

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
- CHECK 约束在应用新值后求值，false 抛 `ConstraintException`（见[CHECK 约束](types-and-ddl.md)）
- 外键列变更为不存在的值抛 `ConstraintException`
- 被其他表外键引用的列发生值变化时，按引用方的 `onUpdate` 策略处理（四策略见[外键文档](types-and-ddl.md)）：RESTRICT 抛异常；CASCADE 将新值 BFS 传播到引用行（支持多级链与自引用、防环）；SET_NULL 将引用行外键列置 null；SET_DEFAULT 置默认值
- where 支持子查询：`->whereIn('id', $db->table('log')->select('user_id')->where('type', 'banned'))->update([...])`（语义见[查询文档](query.md#子查询)）
- 列声明 `ci()` 时 where 匹配同样折叠大小写（见[类型文档](types-and-ddl.md#collation列级-ci)）

### 排序与限量（ORDER BY + LIMIT）

MySQL 方言语义——UPDATE / DELETE 链式可带 `orderBy` + `limit`，控制"先动哪些行、最多动几行"：

```php
// 只更新最新的 100 条 debug 日志
$affected = $db->table('log')
    ->where('level', '=', 'debug')
    ->orderBy('id', 'DESC')
    ->limit(100)
    ->update(['status' => 'taken']);

// 只删除最新的 100 条（DELETE 同款链式，终结于 delete()）
$affected = $db->table('log')
    ->where('level', '=', 'debug')
    ->orderBy('id', 'DESC')
    ->limit(100)
    ->delete();
```

- 排序语义与 SELECT 完全一致（compareValues：null 最小、数值性比较、ci 折叠、稳定排序）
- `limit(0)` 合法（返回 0）；负 limit 抛 `QueryException`
- 无 `orderBy` 带 `limit` = 按存储序取前 N 行；仅 `orderBy` 无 `limit` = 全部匹配行
- **offset 不支持**：链式 `offset` 后终结 `update()` / `delete()` 抛 `QueryException`（MySQL 同款）
- **链式限制**：带 orderBy/limit 时链式**仅允许 where + orderBy + limit**——聚合、表达式投影、join、groupBy、having、distinct、union 任一存在抛 `QueryException`（这些子句的输出行与基表行号无法一一对应）
- 不带 orderBy/limit 时走既有写路径，行为与之前完全一致

## DELETE

```php
$affected = $db->table('user')->where('banned', 1)->delete();
$affected = $db->table('user')->delete();   // 全表删除
```

- 返回**受影响行数**（仅统计 where 匹配行，级联删除不计入）
- 纯删除路径（无级联回写）在 PagedJson 引擎走页槽墓碑复用——只重写被删行所在页，对用户完全透明；其余引擎语义等同过滤重写
- 被引用行为按外键 `onDelete` 策略处理（DSL 见[外键文档](types-and-ddl.md)）：

```php
use Kingbes\Psql\Schema\ForeignKeyAction;

$t->foreignKey('student_id')->references('student', 'id');        // RESTRICT（默认）
$t->foreignKey('student_id')->references('student', 'id')
    ->onDelete(ForeignKeyAction::SET_NULL);                       // 显式指定任一策略
$t->foreignKey('student_id')->references('student', 'id')
    ->onDeleteCascade();                                          // 等价 onDelete(CASCADE)
```

- **RESTRICT**：删除被引用行抛 `ConstraintException`
- **CASCADE**：级联删除引用行，支持多级链（a ← b ← c）与自引用表；级联过程按广度优先展开
- **SET_NULL**：引用行保留，外键列置 null（要求该列可空，建表时校验）
- **SET_DEFAULT**：引用行保留，外键列置默认值（要求该列有默认值，建表时校验）
- where 支持子查询：`->whereIn('id', $db->table('log')->select('user_id'))->delete()`（语义见[查询文档](query.md#子查询)）
- 支持 `orderBy` + `limit` 排序限量删除（"最新的 100 条"等场景，见 [UPDATE 排序与限量](#排序与限量order-by--limit)）
- 列声明 `ci()` 时 where 匹配同样折叠大小写（见[类型文档](types-and-ddl.md#collation列级-ci)）

## 多表 UPDATE / DELETE（JOIN 写入）

`update()` / `delete()` 链上先 join 即可限定匹配行，一次操作多表（v2.2，MySQL 语义）：

```php
// 把 2024 年有订单的用户的 vip 置 1（UPDATE 匹配行限定 + 基表更新）
$affected = $db->table('user as u')
    ->join('order as o', 'o.user_id', '=', 'u.id')
    ->where('o.year', '=', 2024)
    ->update(['u.vip' => 1]);

// 同时更新多表：SET 键 '别名.列' 限定目标表，裸键归基表
$affected = $db->table('user as u')
    ->join('profile as p', 'p.user_id', '=', 'u.id')
    ->update(['u.vip' => 1, 'p.note' => 'vip']);

// DELETE：仅删基表匹配行，join 表只参与匹配
$affected = $db->table('user as u')
    ->leftJoin('order as o', 'o.user_id', '=', 'u.id')
    ->whereNull('o.id')                       // LEFT JOIN + IS NULL → 无订单的用户
    ->delete();

// 自连接写入
$affected = $db->table('employee as e')
    ->join('employee as m', 'm.id', '=', 'e.manager_id')
    ->where('m.level', '>', 3)
    ->update(['e.bonus' => 1]);
```

- **匹配行定位**：基表 + JOIN + WHERE 完整限定；同一匹配行只生效一次（重复匹配按内容哈希去重）
- **UPDATE 的 SET 键**：`'别名.列'` 限定目标表，裸键归基表；可同时更新多表
- **DELETE 只删基表**：join 表仅参与匹配，不删除（MySQL `DELETE t1 FROM` 单目标语义）
- JOIN 支持 INNER/LEFT/RIGHT、ON 条件组、USING、CTE/派生表源
- 唯一/外键/CHECK/触发器/onUpdate 传播全部照常生效（复用单表写管线）
- **链式限制**：多表写与 `orderBy` / `limit` 互斥（构建器拦截，MySQL 同款）
- 相关子查询在写入路径被拒绝；多表非事务写不原子（建议事务包裹，v2.3 起事务整体可崩溃回滚）

## TRUNCATE

```php
$db->table('user')->truncate();
```

清空全部数据、保留表结构、**自增计数归零**（下次插入从 1 开始）。表被其他表外键引用时抛 `SchemaException`。

## 触发器

在写入管线的关键位置挂钩 PHP 闭包：

```php
use Kingbes\Psql\Execution\Trigger;

$trigger = $db->createTrigger('user', 'before', 'insert',
    function (array $row): array {
        $row['name'] = trim($row['name']);      // 清洗/补值后返回整行
        return $row;
    }
);

$db->dropTrigger($trigger);     // 移除；句柄未注册或已移除抛 QueryException
```

- `createTrigger(string $table, string $timing, string $event, callable $handler): Trigger`——timing 仅 `before` / `after`、event 仅 `insert` / `update` / `delete`（均大小写不敏感归一），非法值或表不存在抛 `QueryException`
- 返回 `Trigger` 句柄，供 `dropTrigger()` 移除；同一 handler 可多次注册为相互独立的触发器

### 六个钩子签名

| 钩子 | 签名 | 语义 |
|---|---|---|
| BEFORE INSERT | `fn(array $row): array` | 返回行进入 cast / 约束管线，可清洗/补值 |
| AFTER INSERT | `fn(array $row): void` | 收到最终行（自增已分配、默认值已填） |
| BEFORE UPDATE | `fn(array $old, array $new): array` | `$new` = `$old` 与 cast 前用户值合并的整行；返回整行经完整校验后写入 |
| AFTER UPDATE | `fn(array $old, array $new): void` | 更新落库后收到新旧两行 |
| BEFORE DELETE | `fn(array $row): void` | 将删的行 |
| AFTER DELETE | `fn(array $row): void` | 已删的行 |

### 行为语义

- **BEFORE 改行**：BEFORE INSERT / BEFORE UPDATE 的返回值替代原行继续走完整写入管线（类型 cast、约束检查全部照常）；多个触发器按注册序链式处理（前一个的输出是后一个的输入）
- **拦截**：任何钩子抛异常即中止该写操作——多行批次中 BEFORE 类钩子抛异常**拦截整批**（批内原子）
- **注册顺序**：同一 (表, 时机, 事件) 上多个触发器按注册序执行

### 触发范围

| 写路径 | 触发行为 |
|---|---|
| 级联删除（`onDelete` CASCADE） | 级联删除的子表行照常触发子表 BEFORE / AFTER DELETE |
| SET_NULL / SET_DEFAULT（引用行回写） | **不触发**引用表的触发器 |
| upsert | 无冲突插入路径触发 INSERT 系；冲突更新路径触发 UPDATE 系 |
| insertIgnore | 被（唯一冲突）忽略的行**完全不触发**任何钩子 |
| truncate | **不触发**（非逐行删除） |

### 生命周期与开销

- **连接级注册**：触发器是连接级运行时注册，闭包不持久化——连接重开后需重新 `createTrigger`
- **零开销直通**：无任何注册触发器时，写路径分发点判空直通，对既有写入行为零影响
- **递归无防护（已知限制）**：触发器 handler 内再对同表执行写操作会递归触发——若 handler 无条件再写同表将无限递归直至栈溢出，无内置深度上限。递归场景须在 handler 内自设终止条件（如状态标记/计数），v2.x 可能引入连接级递归深度上限

## 事务与 SAVEPOINT

基本事务（`begin()` / `commit()` / `rollBack()`，快照语义、支持回滚 DDL）见[事务文档](transactions.md)。事务内可用**命名保存点**做部分回滚：

```php
$db->begin();
$db->table('user')->insert(['name' => 'A']);

$db->savepoint('sp1');                              // 建立保存点
$db->table('user')->insert(['name' => 'B']);
$db->savepoint('sp2');
$db->table('user')->insert(['name' => 'C']);

$db->rollBackTo('sp1');                             // 回滚到 sp1：B、C 消失，sp2 被丢弃
$db->table('user')->insert(['name' => 'D']);
$db->rollBackTo('sp1');                             // sp1 仍保留，可重复回滚：D 也消失

$db->releaseSavepoint('sp1');                       // 释放（不改变数据）
$db->commit();                                      // 提交；只剩 A
```

- `savepoint(string $name): void`——对引擎全量状态建立快照压栈；**同名覆盖**（旧条目移除、新快照优先）
- `rollBackTo(string $name): void`——恢复到该保存点时刻：撤销其后全部变更（含 DDL——建/删表、建/删视图同样可回滚）、**丢弃更内层保存点**；该保存点自身保留，可**重复回滚**（复用同一快照）；回滚后索引缓存自动失效重建
- `releaseSavepoint(string $name): void`——弹出该保存点，**不改变数据**；释放外层保存点时其内层保存点一并失效
- `commit()` / `rollBack()` 结束事务时清空整个保存点栈
- 三方法在**事务外**调用抛 `TransactionException`；`rollBackTo` / `releaseSavepoint` 引用不存在（或已被内层丢弃波及）的保存点同样抛 `TransactionException`

## 错误处理总表

| 场景 | 异常 |
|---|---|
| 值类型非法、越界、超长、枚举外、日期格式错误 | `TypeException` |
| 主键/唯一冲突、NOT NULL 违反、外键值不存在、FK 策略拦截、CHECK 违反、UPSERT 目标歧义 | `ConstraintException` |
| 未知列、非法运算符/方向、负 limit | `QueryException` |
| 表不存在、JSON 文件损坏、IO 失败、密钥错误或数据损坏（加密） | `StorageException` |
| 表/库已存在或不存在（结构层）、非法表名列名 | `SchemaException` |
| 事务误用 | `TransactionException` |
