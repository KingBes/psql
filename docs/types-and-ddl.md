# 类型与建表（DDL）

## 数据类型

| 类别 | Blueprint 方法 | PHP 存储 | 说明 |
|---|---|---|---|
| 整数 | `tinyint` / `smallint` / `int` / `bigint` | int | 越界抛 `TypeException`；UNSIGNED 拒绝负数 |
| 精确小数 | `decimal(name, M, D)` | string | 规范化为恰好 D 位小数的字符串；`1 <= D <= M <= 65` |
| 浮点 | `float` / `double` | float | |
| 布尔 | `boolean` | int | 存 0/1；接受 bool/int/"0"/"1" |
| 定长串 | `char(name, M)` | string | M 1..255；不做空格填充 |
| 变长串 | `varchar(name, M)` | string | M 1..65535；按字符数计（mb），超长抛异常 |
| 长文本 | `text` | string | 无长度限制 |
| 枚举 | `enum(name, ['a','b'])` | string | 成员非空且唯一；非法值抛异常 |
| 日期 | `date` | string | `Y-m-d` 严格校验 |
| 日期时间 | `datetime` / `timestamp` | string | `Y-m-d H:i:s` 严格校验；支持 `defaultNow()` |

### 值转换规则（写入时）

- 整型接受：int、bool（→0/1）、整数值的 float、纯数字字符串；越界/UNSIGNED 收负数抛 `TypeException`
- 时间类型接受：合法格式字符串、`DateTimeInterface` 实例（自动 format）
- 非法输入（如 `2026-13-01`、枚举外成员、60 字符进 VARCHAR(50)）一律抛 `TypeException`，**行不会被写入**
- `null` 直接透传（是否违反 NOT NULL 由约束层判定）

## 建表

```php
use Kingbes\Psql\Schema\Blueprint;

$db->createTable('student', function (Blueprint $t) {
    // $t->id() 等价于：BIGINT + UNSIGNED + PRIMARY KEY + AUTO_INCREMENT
    $t->id();

    $t->varchar('name', 50)->notNull()->unique();
    $t->tinyint('age')->unsigned()->default(0);
    $t->decimal('balance', 10, 2)->default(0);
    $t->enum('gender', ['男', '女', '未知'])->default('未知');
    $t->char('phone', 11)->unique();
    $t->text('remark');
    $t->datetime('created_at')->defaultNow();
});
```

### 列修饰符

链在类型方法之后调用，顺序任意：

| 修饰符 | 说明 |
|---|---|
| `unsigned()` | 无符号（仅数值类型，负数写入抛 `TypeException`） |
| `notNull()` | 非空约束 |
| `default($value)` | 默认值（插入缺列时填充并做类型转换） |
| `defaultNow()` | `DEFAULT CURRENT_TIMESTAMP`（仅时间类型） |
| `unique()` | 单列唯一 |
| `primaryKey()` | 主键（单列，隐含唯一+非空语义）；多列联合主键用 `$t->primary(...)`（见[复合主键](#复合主键)） |
| `autoIncrement()` | 自增（仅整数类型） |
| `ci()` | 大小写不敏感比较（仅字符串类型，见 [collation](#collation列级-ci)） |

### 复合主键

```php
$db->createTable('order_item', function (Blueprint $t) {
    $t->bigint('order_id')->unsigned();   // 主键列隐含 NOT NULL，无需再写 notNull()
    $t->int('item_id')->unsigned();

    $t->primary('order_id', 'item_id');   // 复合主键；也接受单列 $t->primary('id')
});
```

- 列必须已定义且不重复，空参数抛 `SchemaException`
- 主键元组重复插入抛 `ConstraintException`；主键列隐含 NOT NULL
- 主键列不可 `dropColumn`（抛 `SchemaException`）
- **自增限制**：表内存在自增列时，主键必须恰为该自增单列——复合主键包含自增列抛 `SchemaException`
- 复合主键自动可用作等值索引（与单列主键一致，见[查询文档](query.md#索引加速)）
- `Table::find()` 仅适用单列主键表——复合主键表调用抛"无主键"`QueryException`

### 联合唯一

```php
$t->unique('student_id', 'subject');   // 列必须已定义，否则抛 SchemaException
```

### 二级索引

```php
// 建表时：Blueprint DSL，索引名自动生成 idx_<列连接>
$db->createTable('user', function (Blueprint $t) {
    $t->id();
    $t->varchar('email', 100);
    $t->int('dept')->unsigned();
    $t->varchar('role', 30);

    $t->index('email');            // idx_email（单列）
    $t->index('dept', 'role');     // idx_dept_role（复合）
});

// 独立 DDL：库名.表名 → 索引名 → 列（可变多列）
$db->createIndex('user', 'idx_email', 'email');
$db->createIndex('user', 'idx_dept_role', 'dept', 'role');
$db->hasIndex('user', 'idx_email');        // bool
$db->dropIndex('user', 'idx_email');
```

- 索引名表内唯一、须匹配 `^[A-Za-z_][A-Za-z0-9_]*$`；索引列必须已定义且不重复——违反抛 `SchemaException`
- 索引元数据随 `TableSchema` 持久化
- `renameColumn` 同步更新索引中的列引用；`dropColumn` 拦截被索引引用的列（抛 `SchemaException`）
- 等值查询自动走哈希预过滤，主键/单列 unique/联合唯一也自动可用作索引，详见[查询文档](query.md#索引加速)

### 外键

```php
use Kingbes\Psql\Schema\ForeignKeyAction;

$db->createTable('score', function (Blueprint $t) {
    $t->id();
    $t->int('student_id')->notNull();
    $t->varchar('subject', 30)->notNull();
    $t->tinyint('mark')->unsigned();

    // onDelete / onUpdate 各自独立设置，默认均 RESTRICT
    $t->foreignKey('student_id')
        ->references('student', 'id')
        ->onDelete(ForeignKeyAction::CASCADE)
        ->onUpdate(ForeignKeyAction::CASCADE);

    $t->unique('student_id', 'subject');
});
```

`Kingbes\Psql\Schema\ForeignKeyAction` 枚举提供四种策略：

| 策略 | ON DELETE（删除被引用行） | ON UPDATE（被引用列值变化） |
|---|---|---|
| `RESTRICT`（默认） | 抛 `ConstraintException` | 抛 `ConstraintException` |
| `CASCADE` | 级联删除引用行（BFS，支持多级链与自引用） | 将新值 BFS 传播到引用行（支持多级链与自引用、防环） |
| `SET_NULL` | 引用行保留，外键列置 null | 引用行保留，外键列置 null |
| `SET_DEFAULT` | 引用行保留，外键列置默认值 | 引用行保留，外键列置默认值 |

- `onDeleteCascade()` 保留为 `->onDelete(ForeignKeyAction::CASCADE)` 的别名
- **DDL 前置条件**（建表时校验，违反抛 `SchemaException`）：`SET_NULL` 要求外键列可空（不带 `notNull()`）；`SET_DEFAULT` 要求外键列带 `default()`
- 插入时引用值必须存在于目标表目标列（null 除外），否则抛 `ConstraintException`
- DELETE/UPDATE 各策略的写入行为详见[写入文档](write.md)

### CHECK 约束

```php
use Kingbes\Psql\Query\Condition\ConditionGroup;

$db->createTable('student', function (Blueprint $t) {
    $t->id();
    $t->varchar('name', 50)->notNull();
    $t->tinyint('age')->unsigned()->default(0);

    // age >= 18 才允许写入
    $t->check('adult', (new ConditionGroup())->where('age', '>=', 18));
});
```

- 条件用 `Query\Condition` 体系表达（`ConditionGroup`/`Comparison`/`InList`/`Between`/`NullCheck`/`LikeCondition`），与 WHERE 条件同构；条件值仅接受标量/null
- **求值时机**：INSERT 在默认回填与自增分配之后、UPDATE 在应用新值之后求值；结果为 false 抛 `ConstraintException`（消息含表名与 check 名）
- check 名表内唯一，重复抛 `SchemaException`
- 随 `TableSchema` 持久化；`renameColumn` 同步更新条件中的列引用，`dropColumn` 拦截被条件引用的列

### collation（列级 ci）

```php
$t->varchar('code', 32)->ci();   // 链在类型方法之后，同其他修饰符
```

`ci()` 仅字符串类型（CHAR/VARCHAR/TEXT/ENUM）允许，其他类型抛 `SchemaException`。默认（不加 `ci()`）全程区分大小写，行为不变。

| 范围 | 行为 |
|---|---|
| WHERE 比较（`=` `!=` `IN` `BETWEEN` `LIKE`）、JOIN ON、ORDER BY | 折叠大小写（`mb_strtolower`）后比较/排序 |
| UPDATE / DELETE 的 where | 同样折叠大小写匹配 |
| 唯一约束、外键、索引构建、CHECK | **不受影响**，一律保持区分大小写（`'a'` 与 `'A'` 在 ci 列 unique 下可共存） |

## 改表（ALTER）

```php
$db->alterTable('student', function (\Kingbes\Psql\Schema\AlterBlueprint $t) {
    $t->varchar('nickname', 30);          // 新增列（类型方法）
    $t->renameColumn('remark', 'note');   // 重命名（数据同步迁移）
    $t->dropColumn('phone');              // 删除列
});
```

规则：

- 新增列若 `notNull()` 则必须带 `default()` 或 `defaultNow()`（否则无法回填既有行，抛 `SchemaException`）；既有行按默认值回填，无默认回填 null
- 重命名会同步迁移行数据键名与联合唯一/外键/CHECK 条件/二级索引中的引用
- 删除属于主键/联合唯一/外键的列、或被 CHECK 条件/二级索引引用的列抛 `SchemaException`
- 待删除/重命名的列不存在抛 `SchemaException`

## 其他表操作

```php
$db->hasTable('student');       // bool
$db->tables();                  // list<string>
$db->renameTable('a', 'b');     // 结构与数据同步迁移；b 已存在抛异常
$db->dropTable('student');      // 被外键引用时抛 SchemaException
$db->table('student')->truncate();  // 清空数据、保留结构、自增归零
```

## 命名规则

表名、列名、库名必须匹配 `^[A-Za-z_][A-Za-z0-9_]*$`（字母/下划线开头），非法名称在建表/建库时抛异常。
