<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

/**
 * LIKE 模式辅助工具（不可实例化）
 */
final class Like
{
    private function __construct() {}

    /**
     * 转义 LIKE 通配符（% _ \），使字符串在 whereLike 中按字面量匹配
     *
     * 用法：->whereLike('title', '%' . Like::escape($userInput) . '%')
     */
    public static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
