<?php

declare(strict_types=1);

namespace Kingbes\Psql\Result;

use Kingbes\Psql\Exception\QueryException;

/**
 * 插入结果：影响行数与自增主键
 */
final readonly class InsertResult
{
    public function __construct(
        public int $rowCount,
        public ?int $lastInsertId,
    ) {
        if ($rowCount < 0) {
            throw new QueryException("rowCount 不允许为负数: {$rowCount}");
        }
        if ($lastInsertId !== null && $lastInsertId < 0) {
            throw new QueryException("lastInsertId 不允许为负数: {$lastInsertId}");
        }
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function lastInsertId(): ?int
    {
        return $this->lastInsertId;
    }
}
