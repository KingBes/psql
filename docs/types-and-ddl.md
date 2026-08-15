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
| `primaryKey()` | 主键（至多一个，隐含唯一+非空语义） |
| `autoIncrement()` | 自增（仅整数类型） |

### 联合唯一

```php
$t->unique('student_id', 'subject');   // 列必须已定义，否则抛 SchemaException
```

### 外键

```php
$db->createTable('score', function (Blueprint $t) {
    $t->id();
    $t->int('student_id')->notNull();
    $t->varchar('subject', 30)->notNull();
    $t->tinyint('mark')->unsigned();

    // 默认 RESTRICT； onDeleteCascade() 开启级联删除
    $t->foreignKey('student_id')
        ->references('student', 'id')
        ->onDeleteCascade();

    $t->unique('student_id', 'subject');
});
```

- 插入时引用值必须存在于目标表目标列（null 除外），否则抛 `ConstraintException`
- 删除被引用行时：RESTRICT 抛 `ConstraintException`；CASCADE 级联删除引用行（支持多级链与自引用）

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
- 重命名会同步迁移行数据键名与联合唯一/外键中的引用
- 删除属于主键/联合唯一/外键的列抛 `SchemaException`
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
