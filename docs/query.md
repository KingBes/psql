# 查询（DQL）

所有查询从 `table()` 开始，支持表别名：

```php
$db->table('user');        // 无别名
$db->table('user as u');   // 别名 u（'user u' 也可以；格式非法抛 QueryException）
```

## SELECT 投影

```php
use Kingbes\Psql\Query\Agg;

$rows = $db->table('user as u')
    ->select('u.id', 'u.name', Agg::count('o.id')->as('order_cnt'))
    ->get();
```

- 不调用 `select()` 或传空 = 全部列
- 输出行是 `array<string, mixed>`，键为**去限定列名**（`u.id` 输出键为 `id`）
- 两个不同源的列解析出相同输出键时抛 `QueryException`（用别名限定避免）

## 表达式与函数

`select()` 投影除了列名与 `Agg` 聚合，还接受标量函数表达式与 CASE 表达式（统一实现 `Query\ProjectionExpression` 接口，对源行求值）。

### 标量函数（Func）

```php
use Kingbes\Psql\Query\Func;

$rows = $db->table('user')
    ->select('id', Func::upper(Func::col('name'))->as('name_upper'))
    ->get();
```

`Func::col('name')` 为列引用（裸列名或 `alias.col` 限定名），供函数/CASE 嵌套取行内列值。全部函数：

| 分类 | 函数 | 说明 |
|---|---|---|
| 字符串 | `Func::upper(x)` / `Func::lower(x)` | 转大写 / 转小写 |
| | `Func::length(x)` | 字符串长度（按字符，mb） |
| | `Func::trim(x)` / `Func::ltrim(x)` / `Func::rtrim(x)` | 去两端 / 左 / 右空白 |
| | `Func::substr(x, pos, len?)` | 子串截取，**pos 1 基**；len 缺省截到串尾 |
| | `Func::concat(a, b, ...)` | 拼接（至少 1 个参数） |
| | `Func::replace(s, search, replace)` | search 出现处全部替换 |
| 数学 | `Func::abs(x)` / `Func::round(x, digits?)` / `Func::floor(x)` / `Func::ceil(x)` | round 的 digits 默认 0 |
| 日期 | `Func::year(x)` / `Func::month(x)` / `Func::day(x)` | 入参须合法 `Y-m-d [H:i:s]`（含 checkdate 校验） |
| 控制 | `Func::coalesce(a, b, ...)` | 第一个非 null 参数，全 null 返回 null |
| | `Func::nullif(a, b)` | a = b 返回 null，否则返回 a |

- 参数均接受标量/null 或嵌套表达式，任意组合：`Func::concat(Func::upper(Func::col('a')), '_', Func::col('b'))`
- **NULL 传播**（与 SQL 一致）：任一参数为 null 时函数返回 null，唯一例外是 `coalesce`
- 非数值入参进数学函数、非法日期进日期函数、越界参数（如 substr 的 pos/len）抛 `QueryException`
- 输出键默认为函数形式（如 `UPPER(name)`），`->as('别名')` 自定义

### CASE 表达式（CaseWhen）

```php
use Kingbes\Psql\Query\CaseWhen;

$rows = $db->table('user')
    ->select('name', CaseWhen::make()
        ->when('age', '>=', 18)->then('成年')
        ->when('age', '<', 18)->then('未成年')
        ->else('未知')
        ->as('label'))
    ->get();
```

- `when` 参数形式同 `where`：`(列, 值)` 等值 / `(列, 运算符, 值)` 显式指定
- 分支依序求值，**命中即返回**对应 `then`；全不中取 `else` 值，未设 `else` 返回 null
- `then` / `else` 可嵌套表达式：`->then(Func::upper(Func::col('name')))`
- 连续 `when` 未 `then`（或 `then` 不紧跟 `when`）抛 `QueryException`
- 默认输出键恒为 `CASE`，多个 CASE 并用时建议都起别名

### 投影组合

表达式与聚合一样作为投影参与分组/过滤/排序，别名可被 `orderBy` / `having` / `groupBy` 引用：

```php
$rows = $db->table('user')
    ->select(
        Func::substr(Func::col('name'), 1, 1)->as('initial'),
        CaseWhen::make()
            ->when('vip', 1)->then('会员')->else('普通')
            ->as('kind'),
    )
    ->groupBy('initial', 'kind')
    ->having('kind', '会员')
    ->orderBy('initial')
    ->get();
```

## WHERE 条件

### 基本形式

```php
// (列, 值) 默认 '='；(列, 运算符, 值) 显式指定
$db->table('user')->where('age', 18);
$db->table('user')->where('age', '>', 18);

// 多条件 AND；orWhere 为 OR
$db->table('user')
    ->where('age', '>=', 18)
    ->andWhere('gender', '男')
    ->orWhere('vip', 1);
```

运算符：`=  !=  <>  <  <=  >  >=`

### 专用条件

```php
->whereIn('id', [1, 2, 3])
->whereNotIn('id', [1, 2, 3])
->whereBetween('age', 18, 30)
->whereNull('deleted_at')
->whereNotNull('deleted_at')
->whereLike('name', '张%')
```

### 嵌套分组

```php
use Kingbes\Psql\Query\Condition\ConditionGroup;

$group = (new ConditionGroup())
    ->where('age', '<', 18)
    ->orWhere('vip', 1);

$rows = $db->table('user')
    ->where('status', 'active')    // status = 'active'
    ->whereGroup($group)           // AND ( age < 18 OR vip = 1 )
    ->orWhereGroup($group)         // OR  ( age < 18 OR vip = 1 )，与 whereGroup 对称
    ->get();
```

`ConditionGroup` 拥有与构建器一致的 where 系列 API，可任意嵌套实现 `(...) AND (...)` 等复杂逻辑，经 `whereGroup()`（AND 语义）或 `orWhereGroup()`（OR 语义）挂进查询。

### NULL 三值逻辑（与 SQL 一致）

- 任何比较运算中列值为 NULL 或比较值为 NULL → 结果未知 → **行被过滤**
- `whereIn` 的值列表含 null 时该项永不匹配；`whereNotIn` 对 null 列恒为 false
- 只有 `whereNull` / `whereNotNull` 能匹配 NULL

### LIKE 通配

`%` 任意字符串、`_` 单个字符，反斜杠 `\` 转义（`\%`、`\_`、`\\`），大小写敏感。

把用户输入按**字面量**匹配时，用 `Kingbes\Psql\Query\Like::escape()` 转义输入中的 `%`、`_`、`\`：

```php
use Kingbes\Psql\Query\Like;

// 用户输入 "100%" 中的 % 不会被当通配符
$pattern = '%' . Like::escape($input) . '%';
$db->table('post')->whereLike('title', $pattern)->get();
```

## 子查询

`whereIn` / `whereNotIn` 除了数组，还接受另一个查询构建器（传数组时行为不变）：

```php
$sub = $db->table('order')->select('user_id')->where('amount', '>', 100);

$rows = $db->table('user')
    ->whereIn('id', $sub)              // id IN (SELECT user_id FROM order WHERE ...)
    ->get();

$db->table('user')->whereExists($sub);        // EXISTS (SELECT ...)
$db->table('user')->whereNotExists($sub);     // NOT EXISTS (SELECT ...)
```

- 子查询必须**恰好 1 个输出列**（多了/少了抛 `QueryException`）
- 子查询作为独立查询**完整执行**（含自身的 orderBy / limit / union），结果集参与 IN / EXISTS 判定
- 支持多层嵌套——子查询里再套子查询
- UPDATE / DELETE 的 where 同样支持子查询（见[写入文档](write.md)）
- CHECK 约束条件中**禁止子查询**（注册时抛 `SchemaException`）
- 不支持相关子查询——引用外层别名的列会按未知列抛 `QueryException`

## 索引加速

等值查询可自动走哈希二级索引预过滤——无需改写查询代码，命中即加速，未命中自动回退全表扫描，结果完全一致。

### 建立与删除索引

```php
$db->createIndex('user', 'idx_email', 'email');              // 单列
$db->createIndex('user', 'idx_dept_role', 'dept', 'role');   // 多列复合
$db->hasIndex('user', 'idx_email');                          // bool
$db->dropIndex('user', 'idx_email');
```

建表时也可用 Blueprint DSL（自动命名 `idx_<列连接>`，详见[类型文档](types-and-ddl.md#二级索引)）：

```php
$t->index('email');            // idx_email
$t->index('dept', 'role');     // idx_dept_role
```

### 自动可用的索引（无需显式建）

**主键列（含复合主键的全部列）、单列 UNIQUE 约束列、联合唯一组**自动可用作索引。`Table::find()` 走主键等值查找，天然受益。

### 触发条件与回退

同时满足以下条件时走哈希预过滤：

- WHERE 顶层条件全部为 **AND 连接的等值比较**（裸列名、值非 null）
- 参与比较的**列集与某可用索引完全一致**（顺序不敏感；如索引 `(dept, role)` 需要 dept、role 两列都有等值条件）

范围比较（`>` `<` `between` 等）、OR、`whereIn`、`whereLike`、嵌套分组等自动回退全表扫描。索引命中仅做候选行预过滤，候选行仍完整求值原 WHERE——**结果与全表扫描完全一致**。

### 缓存失效

索引哈希缓存在连接级维护；任何写操作、DDL、事务回滚后自动失效重建，无需手动管理。性能参考：5 万行等值查询热查 ~0.02ms vs 全扫描 ~100ms。

## JOIN

```php
$rows = $db->table('student as s')
    ->select('s.name', 'sc.subject', 'sc.mark')
    ->join('score as sc', 'sc.student_id', '=', 's.id')       // INNER
    // ->leftJoin('score as sc', 'sc.student_id', '=', 's.id') // LEFT：右表无匹配补 null
    // ->rightJoin('score as sc', 'sc.student_id', '=', 's.id')// RIGHT：左表无匹配补 null
    ->get();
```

- JOIN 表同样支持 `'score as sc'` / `'score sc'` 别名
- ON 条件支持全部比较运算符
- 每个源（基表 + 各 join 表）的列在行内以 `别名.列名` 为键；短列名在多个源中重复时必须用限定名，否则抛 `QueryException`（歧义）

## GROUP BY / HAVING / 聚合

```php
$rows = $db->table('student as s')
    ->select('s.name', Agg::avg('sc.mark')->as('avg_mark'))
    ->leftJoin('score as sc', 'sc.student_id', '=', 's.id')
    ->groupBy('s.id', 's.name')
    ->having('avg_mark', '>=', 60)
    ->orderBy('avg_mark', 'DESC')
    ->get();
```

- 聚合工厂：`Agg::count('col'|'*')`、`Agg::sum/avg/min/max('col')`，`->as('别名')` 设置输出键（无别名时输出键如 `COUNT(*)`、`SUM(salary)`）
- `having` 按输出别名过滤，引用不存在的别名抛 `QueryException`
- 空组语义：COUNT → 0；SUM/AVG/MIN/MAX → null
- SUM/AVG 遇非数值（且非纯数字字符串）值抛 `QueryException`

## ORDER BY / LIMIT / OFFSET / DISTINCT

```php
$rows = $db->table('user')
    ->distinct()
    ->orderBy('age', 'DESC')       // 或 orderByDesc('age')；多列可链式追加
    ->orderBy('name')
    ->limit(10)
    ->offset(20)
    ->get();
```

- 排序方向仅 ASC/DESC（不区分大小写），非法值抛 `QueryException`
- 排序键先在输出列中找，找不到回退源行限定列；null 视为最小
- limit/offset 不允许负数

## UNION / UNION ALL

```php
$rows = $db->table('student')->select('name', 'age')->where('age', '>=', 18)
    ->union($db->table('teacher')->select('name', 'age'))
    ->unionAll($db->table('staff')->select('name', 'age'))
    ->orderBy('age', 'DESC')
    ->limit(10)
    ->get();
```

- 多方可链式追加，`union` / `unionAll` 可交替混用
- 每个 SELECT **完整独立执行**（含自身排序 / limit）后按声明顺序合并
- `union` 对合并全集去重并保持首见顺序；`unionAll` 保留重复行
- 各方输出列**键集必须一致**（不一致抛 `QueryException`；某方结果为空时跳过该方校验）
- 合并后外层的 `distinct()` / `orderBy` / `limit` / `offset` 作用于合并结果

## 视图 VIEW

把一个查询固化为命名视图，之后按名取用：

```php
// 创建：把"user 表成年人 id+name"固化为视图 adults
$db->createView('adults', $db->table('user')->select('id', 'name')->where('age', '>=', 18));

// 查询：view() 返回可继续链式的副本
$rows = $db->view('adults')
    ->where('name', 'like', 'A%')     // 在视图定义之上追加条件
    ->orderBy('id', 'DESC')
    ->get();

// 管理
$db->hasView('adults');               // bool
$db->views();                         // list<string>：当前库全部视图名（字典序）
$db->dropView('adults');              // 不存在抛 SchemaException
```

- **命名空间与表互斥**：视图与表共用命名空间——名称与现存表或其他视图冲突时 `createView()` 抛 `SchemaException`（名称规则同表名）
- **只读**：`view()` 返回 `SelectBuilder`，只有查询链式方法（where / orderBy / get 等），没有任何写入口
- **链式副本**：每次 `view()` 调用都从存储定义重建构建器副本，后续链式条件**不影响**存储的视图定义
- **持久化**：定义以结构化 JSON 存储——文件引擎存库目录 `.views.json`，Memory 引擎存内存；跨连接重开自动恢复
- **事务覆盖**：事务快照包含视图目录，事务内建/删视图可随 `rollBack()` 回滚（同 DDL 语义，详见[事务文档](transactions.md)）
- **限制**：
  - 视图定义包含**子查询条件**（`whereIn` 子查询等）或**投影表达式**（`Func` / `CaseWhen`）时，`createView()` 抛 `QueryException`（此类定义无法结构化持久化）
  - `renameTable()` / `dropTable()` **不联动**视图：基表删除后视图定义仍在，但 `view()->get()` 抛"表不存在"

## EXPLAIN

`explain()` 静态分析查询计划，返回步骤数组——**不执行查询本体**：

```php
$steps = $db->table('user')->where('email', '=', $e)->explain();

// 典型输出（哈希索引命中时）：
// [
//     [
//         'step'    => 'SCAN',
//         'table'   => 'user',
//         'via'     => 'INDEX idx_email (hash, equality)',
//         'estRows' => 50000,            // 实际存储行数
//         'detail'  => '哈希索引等值预过滤，候选行仍完整求值原 WHERE 兜底；...',
//     ],
//     ...                                // 视查询可能还有 JOIN/SORT/LIMIT 等步骤
// ]
```

### 步骤说明

| step | 含义 | 关键字段 |
|---|---|---|
| `SCAN` | 基表访问路径 | `via`：`INDEX <名> (hash, equality)`（命中哈希索引预过滤）或 `FULL SCAN`（全表扫描）；`estRows`：**实际存储行数** |
| `JOIN` | 连接分派（按声明顺序逐个输出） | `type`：`HASH`（等值且两侧列均区分大小写）或 `NESTED LOOP`（非等值条件，或等值条件涉及 CI 列时回退，detail 注明原因） |
| `SUBQUERY` | 子查询条件（`whereIn` 子查询 / `whereExists` 等）计数 | `count`：先解析为常量列表/真值再进入常规求值 |
| `UNION` | UNION 分支 | `type`（UNION / UNION ALL）与 `order`，每方独立完整执行后合并 |
| `AGGREGATE` | 分组聚合 | `groupBy` 分组列、`funcs` 聚合函数列表，内存分组聚合 |
| `SORT` | 排序 | `keys` 排序键，内存排序（无外部归并） |
| `DISTINCT` | 输出去重 | 内存去重，保持首见顺序 |
| `LIMIT` | 分页截断 | `limit` / `offset`，排序完成后应用 |

步骤按查询流水线顺序输出（SCAN → JOIN → SUBQUERY → UNION → AGGREGATE → SORT → DISTINCT → LIMIT）。

### 口径与一致性保证

- `estRows` 为**实际存储行数**——EXPLAIN 允许读取存储数据但不执行查询本体；哈希索引无基数统计，索引命中时 detail 注明"估算行数为实际存储行数，索引预过滤后另行求值"
- 命中索引的展示名：显式索引取索引名、主键取 `PRIMARY`、唯一约束取 `unique:<列>` / `unique:<列,列>` 前缀
- **与实际执行镜像同步**：索引触发条件、CI 列跳过索引预过滤与 hash join 的判定逻辑与 `Executor` 实际分派保持镜像实现——EXPLAIN 展示的访问路径即真实执行路径，无独立的"估算器偏差"
- 基表不存在时透传底层存储异常；表存在但 WHERE 引用未知列等执行期错误不在静态分析范围内

## 结果集 ResultSet

`get()` 返回 `Kingbes\Psql\Result\ResultSet`：

```php
$rs = $db->table('user')->get();

$rs->all();               // list<array<string,mixed>>
$rs->first();             // ?array，空集返回 null
$rs->count();             // int（实现 Countable）
$rs->isEmpty();           // bool
$rs->pluck('name');       // list<mixed>，行内无该键抛 QueryException
$rs->toArray();           // 同 all()
$rs->toJson();            // JSON 字符串
foreach ($rs as $row) {}  // 实现 IteratorAggregate
json_encode($rs);         // 实现 JsonSerializable
```

## 聚合快捷方法

```php
$db->table('user')->count();          // int（空表 0）
$db->table('user')->sum('balance');   // float（空集 0.0）
$db->table('user')->avg('age');       // float（空集 0.0）
$db->table('user')->min('age');       // mixed（空表抛 QueryException）
$db->table('user')->max('age');       // mixed（空表抛 QueryException）
```

也可链在条件后：`$db->table('user')->where('vip', 1)->count();`

## 主键查找

```php
$db->table('user')->find(1);   // ?array；表无主键抛 QueryException
```

仅适用单列主键表——复合主键表调用同样抛"无主键"`QueryException`。

## 分批与惰性

### chunk 分批处理

```php
$processed = $db->table('user')
    ->where('age', '>', 18)
    ->orderBy('id')
    ->chunk(100, function (array $rows, int $iteration): bool {
        // $rows：本批行（list<array<string,mixed>>），至多 100 行
        // $iteration：当前批次序号（从 1 起）
        foreach ($rows as $row) {
            // 逐行处理
        }
        return true;    // 返回 false 提前终止后续批次
    });
// $processed：已处理的总行数（int）
```

- 内部用 LIMIT/OFFSET 分页实现，与 `where` / `orderBy` / 聚合等链式条件自然组合
- `size < 1`、或与 `limit`/`offset` 同用抛 `QueryException`

### cursor 惰性游标

```php
foreach ($db->table('user')->where('vip', 1)->cursor() as $row) {
    // ...
}
```

`cursor(): \Generator`——生成器函数体在**首次迭代时**才执行查询（定义时不查）。注意：数据仍会被引擎整体读入内存，惰性的是查询执行时机而非内存占用。

## 比较/排序规则

- 两侧均为"数值性"（int/float/纯数字字符串）→ 按数值比较（`'10' > '9'` 成立）
- 否则按字符串比较，**区分大小写**
- 列声明 `ci()` 修饰符后，涉该列的比较与排序（含 JOIN ON、UPDATE/DELETE 的 where）折叠大小写，详见[类型文档的 collation 说明](types-and-ddl.md#collation列级-ci)
