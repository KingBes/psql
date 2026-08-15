<?php

declare(strict_types=1);

namespace Kingbes\Psql\Storage;

use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Schema\ColumnSchema;
use Kingbes\Psql\Schema\DataType;
use Kingbes\Psql\Schema\ForeignKey;
use Kingbes\Psql\Schema\TableSchema;

/**
 * PHP serialize 文件存储引擎：编解码快于 JSON，但文件不可读、仅 PHP 可用（扩展名 .bin）
 *
 * 安全：反序列化通过 allowed_classes 仅放行引擎自身的结构类，
 * 磁盘上的任意类载荷会退化为 __PHP_Incomplete_Class 并在结构校验时被拒绝
 */
final class PhpSerializeEngine extends FileEngine
{
    /** 反序列化白名单：仅引擎自身结构类 */
    private const ALLOWED_CLASSES = [
        TableSchema::class,
        ColumnSchema::class,
        ForeignKey::class,
        DataType::class,
    ];

    protected function tableExtension(): string
    {
        return '.bin';
    }

    protected function encode(TableSchema $schema, int $autoIncrement, array $rows): string
    {
        return serialize([
            'schema' => $schema,
            'auto_increment' => $autoIncrement,
            'rows' => $rows,
        ]);
    }

    protected function decode(string $raw, string $file): array
    {
        $data = @unserialize($raw, ['allowed_classes' => self::ALLOWED_CLASSES]);
        if (!is_array($data)
            || !array_key_exists('schema', $data)
            || !array_key_exists('auto_increment', $data)
            || !array_key_exists('rows', $data)) {
            throw new StorageException("表文件不是合法 serialize 载荷: {$file}");
        }
        if (!$data['schema'] instanceof TableSchema || !is_int($data['auto_increment']) || !is_array($data['rows'])) {
            throw new StorageException("表文件结构类型非法: {$file}");
        }

        $rows = [];
        foreach ($data['rows'] as $row) {
            if (!is_array($row)) {
                throw new StorageException("表文件 rows 结构非法: {$file}");
            }
            $rows[] = $row;
        }

        return ['schema' => $data['schema'], 'rows' => $rows, 'ai' => $data['auto_increment']];
    }
}
