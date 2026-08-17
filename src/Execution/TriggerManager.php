<?php

declare(strict_types=1);

namespace Kingbes\Psql\Execution;

use Kingbes\Psql\Exception\QueryException;

/**
 * 触发器管理器：连接级实例（Connection 懒创建持有），
 * 按 (表, 时机, 事件) 分桶存储，注册顺序即执行顺序
 *
 * 分发方法首行判空直通：无注册触发器时对既有写路径零行为影响
 */
final class TriggerManager
{
    /**
     * 触发器桶："表|时机|事件" => 注册序列表（元素为 [句柄, handler] 二元组）
     *
     * @var array<string, list<array{0: Trigger, 1: callable}>>
     */
    private array $buckets = [];

    private int $nextId = 1;

    /**
     * 注册触发器并返回句柄（同一 handler 可多次注册为独立触发器）
     */
    public function register(string $table, string $timing, string $event, callable $handler): Trigger
    {
        $trigger = new Trigger($table, $timing, $event, $this->nextId++);
        $this->buckets[self::key($table, $timing, $event)][] = [$trigger, $handler];

        return $trigger;
    }

    /**
     * 移除触发器；句柄未注册或已移除抛 QueryException
     */
    public function remove(Trigger $trigger): void
    {
        $key = self::key($trigger->table, $trigger->timing, $trigger->event);
        foreach ($this->buckets[$key] ?? [] as $index => $entry) {
            if ($entry[0]->id === $trigger->id) {
                unset($this->buckets[$key][$index]);
                $rest = array_values($this->buckets[$key]);
                if ($rest === []) {
                    unset($this->buckets[$key]);
                } else {
                    $this->buckets[$key] = $rest;
                }

                return;
            }
        }

        throw new QueryException(
            "触发器未注册或已移除: {$trigger->table} {$trigger->timing} {$trigger->event}"
        );
    }

    /**
     * 指定 (表, 时机, 事件) 是否存在注册触发器
     */
    public function has(string $table, string $timing, string $event): bool
    {
        return isset($this->buckets[self::key($table, $timing, $event)]);
    }

    // ---- 分发（Writer 挂钩点调用；before 类 handler 返回值校验为数组，用户异常原样上抛） ----

    /**
     * BEFORE INSERT：handler(array $row): array —— 返回新行参与后续校验/写入
     * 多个触发器链式处理（前一个输出为后一个输入）
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function beforeInsert(string $table, array $row): array
    {
        $list = $this->buckets[self::key($table, 'before', 'insert')] ?? null;
        if ($list === null) {
            return $row;
        }
        foreach ($list as [, $handler]) {
            $result = $handler($row);
            if (!is_array($result)) {
                throw new QueryException("表 {$table} BEFORE INSERT 触发器必须返回数组");
            }
            $row = $result;
        }

        return $row;
    }

    /**
     * AFTER INSERT：handler(array $row): void（行含最终形态：自增 id 已分配、默认值已填）
     *
     * @param array<string,mixed> $row
     */
    public function afterInsert(string $table, array $row): void
    {
        $list = $this->buckets[self::key($table, 'after', 'insert')] ?? null;
        if ($list === null) {
            return;
        }
        foreach ($list as [, $handler]) {
            $handler($row);
        }
    }

    /**
     * BEFORE UPDATE：handler(array $old, array $new): array —— 返回值作为最终新行
     *
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     * @return array<string,mixed>
     */
    public function beforeUpdate(string $table, array $old, array $new): array
    {
        $list = $this->buckets[self::key($table, 'before', 'update')] ?? null;
        if ($list === null) {
            return $new;
        }
        foreach ($list as [, $handler]) {
            $result = $handler($old, $new);
            if (!is_array($result)) {
                throw new QueryException("表 {$table} BEFORE UPDATE 触发器必须返回数组");
            }
            $new = $result;
        }

        return $new;
    }

    /**
     * AFTER UPDATE：handler(array $old, array $new): void（old 原行，new 落盘新行）
     *
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     */
    public function afterUpdate(string $table, array $old, array $new): void
    {
        $list = $this->buckets[self::key($table, 'after', 'update')] ?? null;
        if ($list === null) {
            return;
        }
        foreach ($list as [, $handler]) {
            $handler($old, $new);
        }
    }

    /**
     * BEFORE DELETE：handler(array $row): void —— 抛异常则拦截删除（整批随之失败）
     *
     * @param array<string,mixed> $row
     */
    public function beforeDelete(string $table, array $row): void
    {
        $list = $this->buckets[self::key($table, 'before', 'delete')] ?? null;
        if ($list === null) {
            return;
        }
        foreach ($list as [, $handler]) {
            $handler($row);
        }
    }

    /**
     * AFTER DELETE：handler(array $row): void（收到被删行）
     *
     * @param array<string,mixed> $row
     */
    public function afterDelete(string $table, array $row): void
    {
        $list = $this->buckets[self::key($table, 'after', 'delete')] ?? null;
        if ($list === null) {
            return;
        }
        foreach ($list as [, $handler]) {
            $handler($row);
        }
    }

    /**
     * 桶键：表名不含 '|'（标识符规则限制），三元组安全拼接
     */
    private static function key(string $table, string $timing, string $event): string
    {
        return $table . '|' . $timing . '|' . $event;
    }
}
