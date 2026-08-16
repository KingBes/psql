<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query\Condition;

use Kingbes\Psql\Exception\StorageException;

/**
 * LIKE 条件：% 任意串、_ 单字符、\ 转义
 */
final class LikeCondition extends Condition
{
    public function __construct(
        public string $column,
        public string $pattern,
    ) {
    }

    /**
     * 序列化为数组
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'like',
            'column' => $this->column,
            'pattern' => $this->pattern,
        ];
    }

    /**
     * 从数组还原；缺键抛 StorageException
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $column = $data['column'] ?? null;
        $pattern = $data['pattern'] ?? null;
        if (!is_string($column) || !is_string($pattern)) {
            throw new StorageException('LIKE 条件缺少合法的 column/pattern 字段');
        }

        return new self($column, $pattern);
    }

    /**
     * 列名精确匹配替换后的新实例
     */
    public function withColumnRenamed(string $from, string $to): self
    {
        return $this->column === $from ? new self($to, $this->pattern) : $this;
    }

    /**
     * pattern 本身为 string，无比较值需要校验
     */
    public function assertScalarValues(): void
    {
    }
}
