<?php

declare(strict_types=1);

namespace Kingbes\Psql\Storage;

use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Schema\TableSchema;

/**
 * JSON 文件存储引擎：可读、可跨语言消费的载荷格式（扩展名 .json）
 */
final class JsonFileEngine extends FileEngine
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

    protected function tableExtension(): string
    {
        return '.json';
    }

    protected function encode(TableSchema $schema, int $autoIncrement, array $rows): string
    {
        $payload = json_encode([
            'schema' => $schema->toArray(),
            'auto_increment' => $autoIncrement,
            'rows' => $rows,
        ], self::JSON_FLAGS);
        if ($payload === false) {
            throw new StorageException("表数据无法编码为 JSON: {$schema->name}");
        }

        return $payload;
    }

    protected function decode(string $raw, string $file): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new StorageException("表文件不是合法 JSON: {$file}");
        }
        foreach (['schema', 'auto_increment', 'rows'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new StorageException("表文件缺少 {$key} 键: {$file}");
            }
        }
        if (!is_array($data['schema']) || !is_int($data['auto_increment']) || !is_array($data['rows'])) {
            throw new StorageException("表文件结构类型非法: {$file}");
        }

        try {
            $schema = TableSchema::fromArray($data['schema']);
        } catch (StorageException $e) {
            throw new StorageException("表文件结构非法: {$file} ({$e->getMessage()})");
        }

        $rows = [];
        foreach ($data['rows'] as $row) {
            if (!is_array($row)) {
                throw new StorageException("表文件 rows 结构非法: {$file}");
            }
            $rows[] = $row;
        }

        return ['schema' => $schema, 'rows' => $rows, 'ai' => $data['auto_increment']];
    }
}
