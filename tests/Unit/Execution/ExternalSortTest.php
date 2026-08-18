<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 外部归并排序测试：超阈值（5000 行）走临时文件分块 + 多路归并，
 * 结果须与内存排序完全一致（含稳定性），临时目录须清理干净
 */
final class ExternalSortTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('items', static function (Blueprint $b): void {
            $b->id();
            $b->int('k')->notNull();
            $b->int('v')->notNull();
        });
    }

    /**
     * 构造 n 行：(k, v)，k 在 0..bound-1 随机、v 唯一递增（作为稳定性锚点）
     *
     * @return list<array{k: int, v: int}>
     */
    private function seed(int $n, int $bound): array
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['k' => $i % $bound, 'v' => $i];
        }
        // 洗牌保证不是天然有序
        shuffle($rows);
        $this->conn->table('items')->insertMany($rows);

        return $rows;
    }

    /**
     * 参考实现：按 (k, v) 稳定升序
     *
     * @param list<array{k: int, v: int}> $rows
     * @return list<int>
     */
    private function referenceV(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [$a['k'], $a['v']] <=> [$b['k'], $b['v']]);

        return array_map(static fn (array $row): int => $row['v'], $rows);
    }

    public function testExternalSortMatchesMemoryReference(): void
    {
        $rows = $this->seed(6500, 50);

        $vs = $this->conn->table('items')
            ->orderBy('k', 'ASC')
            ->orderBy('v', 'ASC')
            ->get()
            ->pluck('v');

        $this->assertSame($this->referenceV($rows), $vs);
    }

    public function testExternalSortStableWithinEqualKeys(): void
    {
        // 大量相同 k，v 唯一且按插入序递增 → 同 k 内必须保持 v 升序（原始序稳定）
        $n = 5200;
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['k' => $i % 3, 'v' => $i];
        }
        $this->conn->table('items')->insertMany($rows);

        $vs = $this->conn->table('items')
            ->orderBy('k', 'ASC')
            ->get()
            ->pluck('v');

        // 稳定序参考：按 (k, v) 排序后取 v（k 同组内 v 自然升序）
        $this->assertSame($this->referenceV($rows), $vs);
    }

    public function testExternalSortDescending(): void
    {
        $rows = $this->seed(5500, 40);

        $vs = $this->conn->table('items')
            ->orderBy('k', 'DESC')
            ->orderBy('v', 'DESC')
            ->get()
            ->pluck('v');

        usort($rows, static fn (array $a, array $b): int => [$b['k'], $b['v']] <=> [$a['k'], $a['v']]);
        $expected = array_map(static fn (array $row): int => $row['v'], $rows);

        $this->assertSame($expected, $vs);
    }

    public function testTempDirCleanedUp(): void
    {
        $this->seed(5100, 25);

        $this->conn->table('items')->orderBy('k')->get();

        $leftovers = glob(sys_get_temp_dir() . '/psql-sort-*') ?: [];
        $this->assertSame([], $leftovers);
    }
}
