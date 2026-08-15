<?php

declare(strict_types=1);

namespace Kingbes\Psql\Schema;

/**
 * ALTER 表构建器：记录列的删除/重命名操作（存在性由调用方 apply 时校验）
 */
final class AlterBlueprint extends Blueprint
{
    /** @var list<string> */
    private array $dropped = [];

    /** @var array<string, string> from => to */
    private array $renamed = [];

    /**
     * 已记录的待删除列名
     *
     * @return list<string>
     */
    public function droppedColumns(): array
    {
        return $this->dropped;
    }

    /**
     * 已记录的重命名映射（from => to）
     *
     * @return array<string, string>
     */
    public function renamedColumns(): array
    {
        return $this->renamed;
    }

    /**
     * 记录删除列（不立即校验存在性）
     */
    public function dropColumn(string $name): void
    {
        $this->dropped[] = $name;
    }

    /**
     * 记录重命名列（不立即校验存在性）
     */
    public function renameColumn(string $from, string $to): void
    {
        $this->renamed[$from] = $to;
    }
}
