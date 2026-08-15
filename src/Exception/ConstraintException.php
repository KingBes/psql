<?php

declare(strict_types=1);

namespace Kingbes\Psql\Exception;

/**
 * 约束违反：主键、唯一、外键等约束被破坏时抛出
 */
final class ConstraintException extends PsqlException
{
}
