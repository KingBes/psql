<?php

declare(strict_types=1);

namespace Kingbes\Psql\Schema;

use Kingbes\Psql\Exception\StorageException;

/**
 * 不可变外键定义
 */
final readonly class ForeignKey
{
    public function __construct(
        public string $column,
        public string $refTable,
        public string $refColumn,
        public bool $onDeleteCascade = false,
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
            'column' => $this->column,
            'refTable' => $this->refTable,
            'refColumn' => $this->refColumn,
            'onDeleteCascade' => $this->onDeleteCascade,
        ];
    }

    /**
     * 从数组还原；结构非法抛 StorageException
     */
    public static function fromArray(array $data): self
    {
        $column = $data['column'] ?? null;
        $refTable = $data['refTable'] ?? null;
        $refColumn = $data['refColumn'] ?? null;
        if (!is_string($column) || !is_string($refTable) || !is_string($refColumn)) {
            throw new StorageException('外键定义缺少合法的 column/refTable/refColumn 字段');
        }
        $cascade = $data['onDeleteCascade'] ?? false;
        if (!is_bool($cascade)) {
            throw new StorageException("外键 {$column} 的 onDeleteCascade 必须为布尔值");
        }

        return new self($column, $refTable, $refColumn, $cascade);
    }
}
