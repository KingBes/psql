<?php

declare(strict_types=1);

namespace Kingbes\Psql\Exception;

/**
 * 事务状态误用：在非法的事务状态下执行操作时抛出
 */
final class TransactionException extends PsqlException
{
}
