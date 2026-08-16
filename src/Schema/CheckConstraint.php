<?php

declare(strict_types=1);

namespace Kingbes\Psql\Schema;

use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Query\Condition\Between;
use Kingbes\Psql\Query\Condition\Comparison;
use Kingbes\Psql\Query\Condition\Condition;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Query\Condition\InList;
use Kingbes\Psql\Query\Condition\LikeCondition;
use Kingbes\Psql\Query\Condition\NullCheck;

/**
 * 不可变 CHECK 约束：condition 求值为假的行禁止写入
 */
final readonly class CheckConstraint
{
    public function __construct(
        public string $name,
        public Condition $condition,
    ) {
    }

    /**
     * 条件树中列名精确匹配替换后的新实例
     */
    public function withColumnRenamed(string $from, string $to): self
    {
        return new self($this->name, $this->condition->withColumnRenamed($from, $to));
    }

    /**
     * 条件树是否引用指定列（裸列名精确匹配；用于 dropColumn 拦截）
     */
    public function referencesColumn(string $column): bool
    {
        return self::conditionReferences($this->condition, $column);
    }

    /**
     * 序列化为数组
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'condition' => $this->condition->toArray(),
        ];
    }

    /**
     * 从数组还原；缺键/条件结构非法抛 StorageException
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new StorageException('CHECK 约束缺少合法的 name 字段');
        }
        $conditionRaw = $data['condition'] ?? null;
        if (!is_array($conditionRaw)) {
            throw new StorageException("CHECK 约束 {$name} 缺少 condition 字段");
        }

        return new self($name, Condition::fromArray($conditionRaw));
    }

    /**
     * 递归判断条件树是否引用裸列名
     */
    private static function conditionReferences(Condition $condition, string $column): bool
    {
        if ($condition instanceof ConditionGroup) {
            foreach ($condition->conditions as $child) {
                if (self::conditionReferences($child, $column)) {
                    return true;
                }
            }

            return false;
        }
        if ($condition instanceof Comparison) {
            return $condition->column === $column;
        }
        if ($condition instanceof InList) {
            return $condition->column === $column;
        }
        if ($condition instanceof Between) {
            return $condition->column === $column;
        }
        if ($condition instanceof NullCheck) {
            return $condition->column === $column;
        }
        if ($condition instanceof LikeCondition) {
            return $condition->column === $column;
        }

        return false;
    }
}
