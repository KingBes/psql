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
    ->where('status', 'active')   // status = 'active'
    ->whereGroup($group)          // AND ( age < 18 OR vip = 1 )
    ->get();
```

`ConditionGroup` 拥有与构建器一致的 where 系列 API，可任意嵌套实现 `(...) AND (...)` 等复杂逻辑，经 `whereGroup()` 以 AND 语义挂进查询。

### NULL 三值逻辑（与 SQL 一致）

- 任何比较运算中列值为 NULL 或比较值为 NULL → 结果未知 → **行被过滤**
- `whereIn` 的值列表含 null 时该项永不匹配；`whereNotIn` 对 null 列恒为 false
- 只有 `whereNull` / `whereNotNull` 能匹配 NULL

### LIKE 通配

`%` 任意字符串、`_` 单个字符，反斜杠 `\` 转义（`\%`、`\_`、`\\`），大小写敏感。

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

## 比较/排序规则

- 两侧均为"数值性"（int/float/纯数字字符串）→ 按数值比较（`'10' > '9'` 成立）
- 否则按字符串比较，**区分大小写**
