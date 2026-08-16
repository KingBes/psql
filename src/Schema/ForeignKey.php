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
        public ForeignKeyAction $onDelete = ForeignKeyAction::RESTRICT,
        public ForeignKeyAction $onUpdate = ForeignKeyAction::RESTRICT,
    ) {
    }

    /**
     * 序列化为数组（onDelete/onUpdate 存 ->value 字符串）
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'column' => $this->column,
            'refTable' => $this->refTable,
            'refColumn' => $this->refColumn,
            'onDelete' => $this->onDelete->value,
            'onUpdate' => $this->onUpdate->value,
        ];
    }

    /**
     * 从数组还原；结构非法/缺键/非法策略值抛 StorageException
     */
    public static function fromArray(array $data): self
    {
        $column = $data['column'] ?? null;
        $refTable = $data['refTable'] ?? null;
        $refColumn = $data['refColumn'] ?? null;
        if (!is_string($column) || !is_string($refTable) || !is_string($refColumn)) {
            throw new StorageException('外键定义缺少合法的 column/refTable/refColumn 字段');
        }

        return new self(
            $column,
            $refTable,
            $refColumn,
            self::actionFrom($data, 'onDelete', $column),
            self::actionFrom($data, 'onUpdate', $column),
        );
    }

    /**
     * 解析 onDelete/onUpdate 字段；缺键或非法值抛 StorageException
     */
    private static function actionFrom(array $data, string $key, string $column): ForeignKeyAction
    {
        $raw = $data[$key] ?? null;
        if (!is_string($raw)) {
            throw new StorageException("外键 {$column} 缺少 {$key} 策略字段");
        }
        $action = ForeignKeyAction::tryFrom($raw);
        if ($action === null) {
            throw new StorageException("外键 {$column} 含未知 {$key} 策略: {$raw}");
        }

        return $action;
    }
}
