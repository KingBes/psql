<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query\Condition;

/**
 * IN / NOT IN 条件
 */
final class InList extends Condition
{
    public function __construct(
        public string $column,
        public array $values,
        public bool $negate = false,
    ) {
    }
}
