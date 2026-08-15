<?php

declare(strict_types=1);

namespace Kingbes\Psql\Exception;

/**
 * Psql 异常基类：所有库内异常的统一父类
 */
abstract class PsqlException extends \Exception
{
    /**
     * @param string $message 异常消息
     * @param int $code 异常码
     * @param \Throwable|null $previous 前置异常
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
