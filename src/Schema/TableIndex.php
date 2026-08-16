<?php

declare(strict_types=1);

namespace Kingbes\Psql\Schema;

use Kingbes\Psql\Exception\StorageException;

/**
 * 二级索引（哈希语义，非唯一）：加速等值查询的元数据定义
 */
final readonly class TableIndex
{
    /**
     * @param list<string> $columns 索引列（至少一列，列内不得重复）
     */
    public function __construct(
        public string $name,
        public array $columns,
    ) {
    }

    /**
     * 列集合是否与给定列集合相同（顺序不敏感）
     */
    public function coversColumns(string ...$columns): bool
    {
        if (count($columns) !== count($this->columns)) {
            return false;
        }

        $given = array_values($columns);
        $mine = $this->columns;
        sort($given);
        sort($mine);

        return $given === $mine;
    }

    /**
     * 是否引用了指定列（dropColumn 拦截用）
     */
    public function referencesColumn(string $column): bool
    {
        return in_array($column, $this->columns, true);
    }

    /**
     * 同步列重命名（列名命中则替换，返回新实例）
     */
    public function withColumnRenamed(string $from, string $to): self
    {
        return new self(
            $this->name,
            array_map(
                static fn (string $name): string => $name === $from ? $to : $name,
                $this->columns,
            ),
        );
    }

    /**
     * 序列化为数组
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
        ];
    }

    /**
     * 从数组还原；缺键/类型非法/空列抛 StorageException
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new StorageException('索引缺少合法的 name 字段');
        }
        $columnsRaw = $data['columns'] ?? null;
        if (!is_array($columnsRaw) || $columnsRaw === []) {
            throw new StorageException("索引 {$name} 缺少 columns 字段或列表为空");
        }
        $columns = [];
        foreach ($columnsRaw as $column) {
            if (!is_string($column)) {
                throw new StorageException("索引 {$name} 的列名必须为字符串");
            }
            $columns[] = $column;
        }

        return new self($name, $columns);
    }
}
