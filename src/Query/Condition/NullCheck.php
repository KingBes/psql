<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query\Condition;

/**
 * IS NULL / IS NOT NULL 条件
 */
final class NullCheck extends Condition
{
    public function __construct(
        public string $column,
        public bool $negate = false,
    ) {
    }
}
