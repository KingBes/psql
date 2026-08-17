<?php

declare(strict_types=1);

namespace Kingbes\Psql\Execution;

/**
 * 触发器句柄：由 Connection::createTrigger 返回，可用于 dropTrigger 移除
 *
 * 触发器为连接级运行时注册（handler 为 PHP 闭包/可调用，不可持久化，
 * 重建连接后需重新注册）；同一 handler 可多次注册为相互独立的触发器
 */
final readonly class Trigger
{
    public function __construct(
        /** 目标表名 */
        public string $table,
        /** 触发时机：before | after */
        public string $timing,
        /** 触发事件：insert | update | delete */
        public string $event,
        /** 内部唯一标识（注册序自增，仅用于移除时的身份判定） */
        public int $id,
    ) {
    }
}
