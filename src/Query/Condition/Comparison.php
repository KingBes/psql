<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query\Condition;

use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Exception\StorageException;

/**
 * 比较条件：列 运算符 值
 */
final class Comparison extends Condition
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>='];

    /**
     * @param string $operator 比较运算符，限 = != <> < <= > >=
     */
    public function __construct(
        public string $column,
        public string $operator,
        public mixed $value,
    ) {
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new QueryException("非法比较运算符: {$operator}");
        }
    }

    /**
     * 序列化为数组；value 非标量/null 抛 StorageException
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        self::assertScalarValue($this->value, 'value');

        return [
            'type' => 'comparison',
            'column' => $this->column,
            'operator' => $this->operator,
            'value' => $this->value,
        ];
    }

    /**
     * 从数组还原；缺键/非法运算符/非标量值抛 StorageException
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $column = $data['column'] ?? null;
        $operator = $data['operator'] ?? null;
        if (!is_string($column) || !is_string($operator)) {
            throw new StorageException('比较条件缺少合法的 column/operator 字段');
        }
        if (!array_key_exists('value', $data)) {
            throw new StorageException('比较条件缺少 value 字段');
        }
        self::assertScalarValue($data['value'], 'value');
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new StorageException("比较条件含非法运算符: {$operator}");
        }

        return new self($column, $operator, $data['value']);
    }

    /**
     * 列名精确匹配替换后的新实例
     */
    public function withColumnRenamed(string $from, string $to): self
    {
        return $this->column === $from ? new self($to, $this->operator, $this->value) : $this;
    }

    /**
     * 校验比较值仅允许标量或 null；违规抛 SchemaException
     */
    public function assertScalarValues(): void
    {
        self::assertCheckScalarValue($this->value, $this->column);
    }
}
