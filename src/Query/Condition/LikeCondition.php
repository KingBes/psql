<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query\Condition;

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
}
