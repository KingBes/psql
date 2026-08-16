<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

/**
 * 可用于 SELECT 投影的表达式（函数/CASE）：对源限定行求值产出输出值
 */
interface ProjectionExpression
{
    /**
     * 输出列名（无显式别名时的默认名，如 'UPPER(name)'、'CASE'）
     */
    public function outputName(): string;

    /**
     * 显式别名（as 设置），未设置返回 null
     */
    public function alias(): ?string;

    /**
     * 对源限定行求值（裸列名经唯一后缀解析，未知/歧义抛 QueryException）
     *
     * @param array<string, mixed> $row
     */
    public function evaluate(array $row): mixed;
}
