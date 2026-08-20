<?php

declare(strict_types=1);

namespace Kingbes\Psql\Bridge;

use Closure;
use InvalidArgumentException;
use Kingbes\Psql\Connection as PsqlConnection;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\ColumnRef;
use Kingbes\Psql\Query\Condition\Between;
use Kingbes\Psql\Query\Condition\Comparison;
use Kingbes\Psql\Query\Condition\Condition;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Query\Condition\InList;
use Kingbes\Psql\Query\Condition\LikeCondition;
use Kingbes\Psql\Query\Condition\NullCheck;
use Kingbes\Psql\Query\SelectBuilder;
use think\db\BaseQuery;
use think\db\Connection as ThinkOrmConnection;
use think\db\Raw;

/**
 * ThinkPHP / webman（think-orm）的连接驱动：把 think\db\BaseQuery 的查询状态翻译为 psql 链式调用执行。
 *
 * 两个框架都建立在 topthink/think-orm 之上，共用同一个扩展点 think\db\ConnectionInterface；
 * 因此在 config 的连接配置里把 type 指向本类（完整类名见下文）即可同时为两者提供驱动，无需改框架代码。
 *
 * 用法 —— ThinkPHP config/database.php：
 *     'connections' => [
 *         'psql' => [
 *             'type'     => \Kingbes\Psql\Bridge\PsqlOrm::class,
 *             'database' => root_path() . 'database' . DIRECTORY_SEPARATOR . 'psql', // 数据目录
 *             // 可选 psql 连接参数（也可用顶层同名键）：
 *             'psql'     => ['concurrency' => false, 'wal' => false],
 *         ],
 *     ],
 *
 * 用法 —— webman：作为标准 webman 插件发布到 config/plugin/kingbes/psql/，由
 *   \Kingbes\Psql\Bridge\Webman\Bootstrap 读取并设为本连接驱动（见 docs/integration.md）。
 *   webman 是 Workerman 协程模型：本驱动按单进程串行使用，建议关闭 concurrency(flock)。
 *
 * 支持范围与限制：
 *  - 覆盖 find/select/value/column/insert/insertAll/update/delete/事务/分页/聚合/表结构读取/join/order/limit/group。
 *  - 不支持而明确抛异常的构造：原始 Raw 表达式、whereExp/whereRaw、EXISTS 子查询、
 *    NOT BETWEEN、NOT LIKE、HAVING、多条件 JOIN、自增批量指定等。异常优于静默错数据。
 */
class PsqlOrm extends ThinkOrmConnection
{
    /** think-orm 事务嵌套时使用的保存点前缀 */
    private const SAVEPOINT_PREFIX = 'think_psql_';

    private ?PsqlConnection $psql = null;

    /** @var array<string, mixed> */
    private array $psqlOptions = [];

    private mixed $lastInsertId = null;

    private string $lastSql = '';

    /** @var array<string, array> schema 元信息缓存（table => info） */
    private array $schemaInfoCache = [];

    /**
     * 返回查询对象类（沿用 think-orm 标准 Query）。
     */
    public function getQueryClass(): string
    {
        return \think\db\Query::class;
    }

    /**
     * 提供 Builder 类以满足框架装配（本驱动不把查询编译为 SQL 执行，Builder 仅在少见的下沉路径被引用）。
     */
    public function getBuilderClass(): string
    {
        return \think\db\builder\Mysql::class;
    }

    /**
     * 打开 psql 库目录（config['database'] 为数据目录；可选 psql 连接参数见类注释）。
     */
    public function connect(array $config = [], $linkNum = 0)
    {
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
        }

        if ($this->psql !== null) {
            return $this->psql;
        }

        $path = $this->config['database'] ?? null;
        if (!is_string($path) || $path === '') {
            throw new InvalidArgumentException('psql 连接必须配置 database 作为本地数据目录路径');
        }

        $this->psqlOptions = $this->parsePsqlOptions($this->config);
        $this->psql = Psql::connect($path, $this->psqlOptions);

        return $this->psql;
    }

    // ------------------------------------------------------------------
    // 查询
    // ------------------------------------------------------------------

    public function find(BaseQuery $query): array
    {
        $row = $this->buildSelect($query)->first();

        return $row ?? [];
    }

    public function select(BaseQuery $query): array
    {
        $rows = $this->buildSelect($query)->get()->rows();
        $this->numRows = count($rows);

        return $rows;
    }

    public function value(BaseQuery $query, string $field, $default = null)
    {
        $row = $this->buildSelect($query)->first();
        if ($row === null) {
            return $default;
        }

        return $row[$this->plainField($field)] ?? $default;
    }

    public function column(BaseQuery $query, string | array $column, string $key = ''): array
    {
        $rows = $this->buildSelect($query)->get()->rows();

        if (is_array($column)) {
            return $rows;
        }

        $field = $this->plainField($column);
        $result = [];
        if ($key !== '') {
            $keyField = $this->plainField($key);
            foreach ($rows as $row) {
                $result[$row[$keyField] ?? null] = $row[$field] ?? null;
            }
        } else {
            foreach ($rows as $row) {
                $result[] = $row[$field] ?? null;
            }
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // 写入
    // ------------------------------------------------------------------

    public function insert(BaseQuery $query, bool $getLastInsID = false)
    {
        $data = $query->getOption('data');
        $data = is_array($data) ? $data : [];

        $result = $this->psql()->table($this->resolveTable($query))->insert($data);
        $this->numRows = $result->rowCount();
        $this->lastInsertId = $result->lastInsertId();
        $this->lastSql = 'INSERT INTO ' . $this->resolveTable($query);

        return $getLastInsID ? $this->lastInsertId : $this->numRows;
    }

    public function insertAll(BaseQuery $query, array $dataSet = []): int
    {
        $result = $this->psql()->table($this->resolveTable($query))->insertMany($dataSet);

        return ($this->numRows = $result->rowCount());
    }

    public function insertAllByKeys(BaseQuery $query, array $keys, array $values): int
    {
        $dataSet = [];
        foreach ($values as $row) {
            $item = [];
            foreach ($keys as $i => $key) {
                $item[$key] = $row[$i] ?? null;
            }
            $dataSet[] = $item;
        }

        return $this->insertAll($query, $dataSet);
    }

    public function selectInsert(BaseQuery $query, array $fields, string $table)
    {
        throw new InvalidArgumentException('psql 驱动暂不支持 selectInsert（INSERT ... SELECT）');
    }

    public function update(BaseQuery $query): int
    {
        $data = $query->getOption('data');
        $data = is_array($data) ? $data : [];

        $affected = $this->applyClauses($this->rootSelect($query), $query)->update($data);
        $this->numRows = $affected;

        return $affected;
    }

    public function delete(BaseQuery $query): int
    {
        $affected = $this->applyClauses($this->rootSelect($query), $query)->delete();
        $this->numRows = $affected;

        return $affected;
    }

    // ------------------------------------------------------------------
    // 聚合
    // ------------------------------------------------------------------

    public function aggregate(BaseQuery $query, string $aggregate, string | Raw $field, bool $force = false)
    {
        $builder = $this->applyClauses($this->rootSelect($query), $query);

        $agg = strtolower(trim($aggregate));
        if ($field instanceof Raw) {
            throw new InvalidArgumentException('psql 驱动聚合暂不支持 Raw 表达式: ' . $aggregate);
        }

        return match ($agg) {
            'count' => $builder->count(),
            'sum' => (float) $builder->sum($this->plainField($field)),
            'avg' => (float) $builder->avg($this->plainField($field)),
            'min' => $builder->min($this->plainField($field)),
            'max' => $builder->max($this->plainField($field)),
            default => throw new InvalidArgumentException("psql 驱动暂不支持的聚合: {$aggregate}"),
        };
    }

    // ------------------------------------------------------------------
    // 事务
    // ------------------------------------------------------------------

    public function transaction(callable $callback)
    {
        $this->startTrans();
        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $throwable) {
            $this->rollback();
            throw $throwable;
        }
    }

    public function startTrans()
    {
        if ($this->transTimes === 0) {
            $this->psql()->begin();
        } else {
            $this->psql()->savepoint(self::SAVEPOINT_PREFIX . $this->transTimes);
        }
        ++$this->transTimes;
    }

    public function commit()
    {
        if ($this->transTimes === 0) {
            return;
        }

        --$this->transTimes;
        if ($this->transTimes === 0) {
            $this->psql()->commit();
        } else {
            $this->psql()->releaseSavepoint(self::SAVEPOINT_PREFIX . $this->transTimes);
        }
    }

    public function rollback()
    {
        if ($this->transTimes === 0) {
            return;
        }

        --$this->transTimes;
        if ($this->transTimes === 0) {
            $this->psql()->rollBack();
        } else {
            $this->psql()->rollBackTo(self::SAVEPOINT_PREFIX . $this->transTimes);
        }
    }

    // ------------------------------------------------------------------
    // 元数据 / 其它
    // ------------------------------------------------------------------

    public function getTableFields(string $tableName): array
    {
        return $this->fieldsInfo($tableName);
    }

    public function getFields(string $tableName = ''): array
    {
        return $this->fieldsInfo($tableName ?: current($this->schemaKeys()) ?: $tableName);
    }

    public function getTables(string $dbName = ''): array
    {
        return $this->psql()->tables();
    }

    public function getTableInfo(array | string $tableName, string $fetch = '')
    {
        if (is_array($tableName)) {
            $tableName = key($tableName) ?: current($tableName);
        }
        if (!is_string($tableName) || str_contains($tableName, ',') || str_contains($tableName, ')')) {
            return [];
        }

        $table = trim(explode(' ', $tableName)[0]);
        $info = $this->schemaInfo($table);

        return $fetch !== '' && array_key_exists($fetch, $info) ? $info[$fetch] : $info;
    }

    public function getPk($tableName)
    {
        return $this->getTableInfo($tableName, 'pk');
    }

    public function getAutoInc($tableName)
    {
        return $this->getTableInfo($tableName, 'autoinc');
    }

    public function getFieldsType($tableName, ?string $field = null)
    {
        $result = $this->getTableInfo($tableName, 'type');
        if (is_array($result) && $field !== null && isset($result[$field])) {
            return $result[$field];
        }

        return $result;
    }

    public function getFieldsBind($tableName): array
    {
        $result = $this->getTableInfo($tableName, 'bind');

        return is_array($result) ? $result : [];
    }

    public function getFieldBindType(string $type): int
    {
        $type = strtoupper($type);
        if (str_contains($type, 'BOOL')) {
            return self::PARAM_BOOL;
        }
        if (str_contains($type, 'INT') || str_contains($type, 'ENUM')) {
            return self::PARAM_INT;
        }
        if (str_contains($type, 'DEC') || str_contains($type, 'FLOAT') || str_contains($type, 'DOUBLE') || str_contains($type, 'REAL')) {
            return 21; // 浮点/小数用数值绑定
        }

        return self::PARAM_STR;
    }

    public function getLastSql(): string
    {
        return $this->lastSql;
    }

    public function getLastInsID(BaseQuery $query, ?string $sequence = null)
    {
        return $this->lastInsertId;
    }

    public function close()
    {
        $this->psql = null;

        return $this;
    }

    /**
     * think-orm 若触发原生 SQL 执行会走到本方法；psql 非 SQL 引擎，静默返回空、不执行任何 SQL。
     */
    public function query(string $sql, array $bind = [], bool $master = false): array
    {
        return [];
    }

    public function execute(string $sql, array $bind = [], bool $fetch = false, bool $master = false): int | array
    {
        return $fetch ? [] : 0;
    }

    // ------------------------------------------------------------------
    // 翻译
    // ------------------------------------------------------------------

    private function psql(): PsqlConnection
    {
        return $this->connect();
    }

    private function parsePsqlOptions(array $config): array
    {
        $options = $config['psql'] ?? [];
        if (!is_array($options)) {
            $options = [];
        }

        foreach (['concurrency', 'wal', 'compress'] as $key) {
            if (array_key_exists($key, $config)) {
                $options[$key] = (bool) $config[$key];
            }
        }
        if (array_key_exists('key', $config)) {
            $options['key'] = $config['key'];
        }

        return $options;
    }

    private function resolveTable(BaseQuery $query): string
    {
        $table = $query->getOption('table');
        if (is_array($table)) {
            $table = $table[0] ?? null;
        }
        if (!is_string($table) || $table === '') {
            $table = $query->getTable();
        }

        return is_string($table) ? $table : (string) $table;
    }

    private function rootSelect(BaseQuery $query): SelectBuilder
    {
        return $this->psql()->table($this->resolveTable($query))->select();
    }

    private function applyClauses(SelectBuilder $builder, BaseQuery $query): SelectBuilder
    {
        $options = $query->getOptions();

        $field = $options['field'] ?? null;
        if ($field !== null && $field !== ['*'] && $field !== []) {
            $fields = $this->normalizeFields($field);
            if ($fields !== []) {
                $builder = $builder->select(...$fields);
            }
        }

        if (!empty($options['distinct'])) {
            $builder = $builder->distinct();
        }

        $where = $options['where'] ?? [];
        if ($where !== []) {
            $builder = $builder->whereGroup($this->translateWhere($where));
        }

        foreach ($options['join'] ?? [] as $join) {
            $builder = $this->applyJoin($builder, $join);
        }

        if (!empty($options['group'])) {
            $builder = $builder->groupBy(...array_map(strval(...), (array) $options['group']));
        }

        if (!empty($options['having'])) {
            throw new InvalidArgumentException('psql 驱动暂不支持 HAVING 条件');
        }

        $this->applyOrder($builder, $options['order'] ?? null);
        $this->applyLimitOffset($builder, $options['limit'] ?? null, $options['page'] ?? null);

        return $builder;
    }

    private function buildSelect(BaseQuery $query): SelectBuilder
    {
        // think-orm 在结果处理里无守卫地遍历 options['filter']，补齐缺省空数组以免告警
        if (!is_array($query->getOption('filter'))) {
            $query->setOption('filter', []);
        }

        return $this->applyClauses($this->rootSelect($query), $query);
    }

    // ---- WHERE ----

    private function translateWhere(array $where): ConditionGroup
    {
        $outer = new ConditionGroup();
        $first = true;
        foreach (['AND', 'OR', 'XOR'] as $logic) {
            if (empty($where[$logic])) {
                continue;
            }
            $group = $this->translateLogicGroup($where[$logic], $logic);
            if ($group->isEmpty()) {
                continue;
            }
            $outer->add($group, $first ? 'AND' : $logic);
            $first = false;
        }

        return $outer;
    }

    private function translateLogicGroup(array $items, string $logic): ConditionGroup
    {
        $group = new ConditionGroup();
        foreach ($items as $item) {
            $condition = $this->translateCondition($item);
            if ($condition !== null) {
                $group->add($condition, $logic);
            }
        }

        return $group;
    }

    private function translateCondition(mixed $item): ?Condition
    {
        if ($item === true) {
            return null;
        }
        if ($item instanceof Raw) {
            throw new InvalidArgumentException('psql 驱动 where 暂不支持 Raw 表达式');
        }
        if ($item instanceof Closure) {
            return $this->translateWhere($this->resolveClosureWhere($item));
        }
        if (!is_array($item)) {
            throw new InvalidArgumentException('psql 驱动无法解析的 where 条件类型');
        }

        [$field, $op, $condition] = array_pad($item, 3, null);

        if ($field instanceof Raw || $field === '' || $field === null) {
            throw new InvalidArgumentException('psql 驱动 where 暂不支持整段表达式条件');
        }

        if (is_array($op)) {
            // 同一字段多条件，如 where('id', [['>',100],['<',200]])
            $sub = new ConditionGroup();
            foreach ($op as $rule) {
                if (!is_array($rule) || count($rule) < 2) {
                    throw new InvalidArgumentException('psql 驱动无法解析的多条件 where');
                }
                $sub->add($this->makeComparison((string) $field, (string) $rule[0], $rule[1]), 'AND');
            }

            return $sub;
        }

        return $this->makeCondition($this->plainField((string) $field), strtoupper((string) $op), $condition);
    }

    private function makeCondition(string $field, string $op, mixed $condition): Condition
    {
        if ($condition instanceof Raw) {
            throw new InvalidArgumentException('psql 驱动 where 值暂不支持 Raw 表达式');
        }

        return match (true) {
            in_array($op, ['NULL', 'IS NULL'], true) => new NullCheck($field),
            in_array($op, ['NOTNULL', 'NOT NULL', 'IS NOT NULL'], true) => new NullCheck($field, true),
            in_array($op, ['IN', 'NOT IN'], true) => new InList($field, $this->toList($condition), str_contains($op, 'NOT')),
            $op === 'BETWEEN' => $this->between($field, $condition),
            $op === 'NOT BETWEEN' => throw new InvalidArgumentException('psql 驱动暂不支持 NOT BETWEEN'),
            $op === 'LIKE' => new LikeCondition($field, (string) $condition),
            $op === 'NOT LIKE' => throw new InvalidArgumentException('psql 驱动暂不支持 NOT LIKE'),
            $op === 'COLUMN' => $this->columnComparison($field, $condition),
            $op === 'EXP' => throw new InvalidArgumentException('psql 驱动暂不支持 EXP 条件'),
            str_contains($op, 'EXISTS') => throw new InvalidArgumentException('psql 驱动暂不支持 EXISTS 子查询'),
            str_contains($op, 'FIND IN SET') => throw new InvalidArgumentException('psql 驱动暂不支持 FIND_IN_SET'),
            default => new Comparison($field, $op, $condition),
        };
    }

    private function makeComparison(string $field, string $op, mixed $value): Comparison
    {
        return new Comparison($field, $op, $value);
    }

    private function columnComparison(string $field, mixed $condition): Comparison
    {
        if (!is_array($condition) || count($condition) < 2) {
            throw new InvalidArgumentException('psql 驱动 whereColumn 参数不合法');
        }

        return new Comparison($field, (string) $condition[0], new ColumnRef($this->plainField((string) $condition[1])));
    }

    private function between(string $field, mixed $condition): Between
    {
        if (!is_array($condition) || count($condition) < 2) {
            throw new InvalidArgumentException('psql 驱动 WHERE BETWEEN 需数组 [min, max]');
        }

        return new Between($field, $condition[0], $condition[1]);
    }

    private function toList(mixed $condition): array
    {
        if (is_array($condition)) {
            return $condition;
        }

        return array_map('trim', explode(',', (string) $condition));
    }

    private function resolveClosureWhere(Closure $closure): array
    {
        $nested = $this->newQuery();
        $result = $closure($nested);

        return is_array($result) ? $result : $nested->getOption('where', []);
    }

    // ---- JOIN ----

    private function applyJoin(SelectBuilder $builder, mixed $join): SelectBuilder
    {
        if (!is_array($join) || count($join) < 2) {
            throw new InvalidArgumentException('psql 驱动无法解析的 JOIN 配置');
        }

        $table = (string) $join[0];
        $type = strtoupper((string) ($join[2] ?? 'INNER'));
        $condition = $join[1];

        if (is_array($condition)) {
            if (count($condition) !== 1) {
                throw new InvalidArgumentException('psql 驱动 JOIN 暂仅支持单等值条件');
            }
            $condition = $condition[0];
        }

        [$left, $op, $right] = $this->parseJoinCondition((string) $condition);

        return match ($type) {
            'LEFT' => $builder->leftJoin($table, $left, $op, $right),
            'RIGHT' => $builder->rightJoin($table, $left, $op, $right),
            default => $builder->join($table, $left, $op, $right),
        };
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function parseJoinCondition(string $condition): array
    {
        foreach (['>=', '<=', '<>', '!=', '=', '>', '<'] as $operator) {
            $pos = strpos($condition, $operator);
            if ($pos === false) {
                continue;
            }

            return [
                trim(substr($condition, 0, $pos)),
                $operator,
                trim(substr($condition, $pos + strlen($operator))),
            ];
        }

        throw new InvalidArgumentException("psql 驱动无法解析 JOIN 条件: {$condition}");
    }

    // ---- ORDER / LIMIT ----

    private function applyOrder(SelectBuilder $builder, mixed $order): void
    {
        if (empty($order)) {
            return;
        }

        $pairs = [];
        if (is_string($order)) {
            foreach (explode(',', $order) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $bits = preg_split('/\s+/', $part);
                $pairs[] = [trim($bits[0]), strtoupper($bits[1] ?? 'ASC')];
            }
        } elseif (is_array($order)) {
            $isList = array_is_list($order);
            foreach ($order as $key => $value) {
                if ($isList) {
                    $pairs[] = $this->parseOrderExpression((string) $value);
                } else {
                    $direction = is_string($value) ? strtoupper($value) : 'ASC';
                    $pairs[] = [$this->plainField((string) $key), $direction];
                }
            }
        }

        foreach ($pairs as [$column, $direction]) {
            $builder->orderBy($this->plainField($column), $direction);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseOrderExpression(string $expr): array
    {
        $bits = preg_split('/\s+/', trim($expr));
        if ($bits === false || $bits[0] === '') {
            throw new InvalidArgumentException("psql 驱动无法解析 ORDER BY: {$expr}");
        }

        return [$this->plainField($bits[0]), strtoupper($bits[1] ?? 'ASC')];
    }

    private function applyLimitOffset(SelectBuilder $builder, mixed $limit, mixed $page): void
    {
        $offset = null;
        $length = null;

        if (is_array($limit)) {
            if (count($limit) >= 2) {
                [$offset, $length] = array_map('intval', array_slice($limit, 0, 2));
            } elseif (count($limit) === 1) {
                $length = (int) $limit[0];
            }
        } elseif (is_string($limit) && $limit !== '') {
            $parts = array_map('trim', explode(',', $limit));
            if (count($parts) >= 2) {
                [$offset, $length] = array_map('intval', $parts);
            } else {
                $length = (int) $parts[0];
            }
        } elseif (is_numeric($limit)) {
            $length = (int) $limit;
        }

        if (is_array($page) && count($page) >= 2) {
            $pageNum = max((int) $page[0], 1);
            $listRows = (int) $page[1];
            $offset = ($pageNum - 1) * $listRows;
            $length = $listRows;
        }

        if ($offset > 0) {
            $builder->offset($offset);
        }
        if ($length !== null && $length >= 0) {
            $builder->limit($length);
        }
    }

    // ---- 元数据 ----

    /**
     * 读取 psql 表结构为 think-orm 认可的字段信息数组。
     */
    private function fieldsInfo(string $tableName): array
    {
        try {
            $schema = $this->psql()->tableSchema($tableName);
        } catch (StorageException) {
            return [];
        }

        $fields = [];
        foreach ($schema->columns as $column) {
            $fields[$column->name] = [
                'name'    => $column->name,
                'type'    => $column->type->value,
                'length'  => $column->length,
                'notnull' => $column->notNull,
                'default' => $column->hasDefault ? $column->default : null,
                'primary' => $column->primaryKey,
                'autoinc' => $column->autoIncrement,
                'comment' => '',
            ];
        }

        return $fields;
    }

    /**
     * 组装 think-orm 需要的完整元信息并缓存。
     */
    private function schemaInfo(string $table): array
    {
        return $this->schemaInfoCache[$table] ??= $this->buildSchemaInfo($table);
    }

    private function buildSchemaInfo(string $table): array
    {
        $fields = $this->fieldsInfo($table);

        $type = [];
        $bind = [];
        $pk = null;
        $pkList = [];
        $autoinc = null;

        foreach ($fields as $name => $info) {
            $rawType = (string) ($info['type'] ?? '');
            $type[$name] = $this->getFieldBindType($rawType);
            $bind[$name] = $this->getFieldBindType($rawType);
            if (!empty($info['primary'])) {
                $pkList[] = $name;
            }
            if (!empty($info['autoinc'])) {
                $autoinc = $name;
            }
        }

        if ($pkList !== []) {
            $pk = count($pkList) > 1 ? $pkList : $pkList[0];
        }

        return [
            'fields'   => $fields,
            'type'     => $type,
            'bind'     => $bind,
            'pk'       => $pk,
            'autoinc'  => $autoinc,
            'raw'      => $fields,
        ];
    }

    /**
     * @return list<string>
     */
    private function schemaKeys(): array
    {
        return array_keys($this->schemaInfoCache);
    }

    // ---- 列名清理 ----

    private function plainField(string $field): string
    {
        $field = trim(str_replace('`', '', $field));
        $pos = strrpos($field, '.');
        if ($pos !== false) {
            $field = substr($field, $pos + 1);
        }

        // SELECT 别名，如 'id as uid'
        if (preg_match('/^(.*?)\s+(?:as\s+)?([A-Za-z_][A-Za-z0-9_]*)$/i', $field, $m)) {
            return $m[2];
        }

        return $field;
    }

    private function normalizeFields(mixed $field): array
    {
        $fields = [];
        foreach ((array) $field as $key => $value) {
            if (is_int($key)) {
                $name = (string) $value;
                if ($name === '*') {
                    continue;
                }
                $fields[] = $this->plainField($name);
            } else {
                $fields[] = $this->plainField((string) $key);
            }
        }

        return $fields;
    }
}