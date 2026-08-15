<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query\Condition;

/**
 * BETWEEN / NOT BETWEEN 条件（闭区间）
 */
final class Between extends Condition
{
    public function __construct(
        public string $column,
        public mixed $min,
        public mixed $max,
        public bool $negate = false,
    ) {
    }
}
