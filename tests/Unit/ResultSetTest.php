<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit;

use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Result\ResultSet;
use PHPUnit\Framework\TestCase;

/**
 * ResultSet 全 API 行为测试
 */
final class ResultSetTest extends TestCase
{
    /**
     * @return list<array<string,mixed>>
     */
    private function sampleRows(): array
    {
        return [
            ['id' => 1, 'name' => '张三'],
            ['id' => 2, 'name' => '李四'],
        ];
    }

    public function testRowsReturnsOriginalList(): void
    {
        $resultSet = new ResultSet($rows = $this->sampleRows());

        $this->assertSame($rows, $resultSet->rows());
    }

    public function testAllAndToArrayReturnRows(): void
    {
        $resultSet = new ResultSet($rows = $this->sampleRows());

        $this->assertSame($rows, $resultSet->all());
        $this->assertSame($rows, $resultSet->toArray());
    }

    public function testIterationYieldsEachRow(): void
    {
        $resultSet = new ResultSet($this->sampleRows());

        $names = [];
        foreach ($resultSet as $row) {
            $names[] = $row['name'];
        }

        $this->assertSame(['张三', '李四'], $names);
    }

    public function testCount(): void
    {
        $this->assertSame(2, (new ResultSet($this->sampleRows()))->count());
        $this->assertSame(0, (new ResultSet([]))->count());
    }

    public function testFirst(): void
    {
        $resultSet = new ResultSet($this->sampleRows());

        $this->assertSame(['id' => 1, 'name' => '张三'], $resultSet->first());
        $this->assertNull((new ResultSet([]))->first());
    }

    public function testIsEmptyAndIsNotEmpty(): void
    {
        $nonEmpty = new ResultSet($this->sampleRows());
        $this->assertFalse($nonEmpty->isEmpty());
        $this->assertTrue($nonEmpty->isNotEmpty());

        $empty = new ResultSet([]);
        $this->assertTrue($empty->isEmpty());
        $this->assertFalse($empty->isNotEmpty());
    }

    public function testPluck(): void
    {
        $resultSet = new ResultSet($this->sampleRows());

        $this->assertSame([1, 2], $resultSet->pluck('id'));
        $this->assertSame(['张三', '李四'], $resultSet->pluck('name'));
    }

    public function testPluckUnknownColumnThrows(): void
    {
        $resultSet = new ResultSet($this->sampleRows());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('age');
        $resultSet->pluck('age');
    }

    public function testEmptyPluckReturnsEmptyList(): void
    {
        $this->assertSame([], (new ResultSet([]))->pluck('id'));
    }

    public function testToJsonKeepsUnicodeAndSlashes(): void
    {
        $resultSet = new ResultSet([['path' => 'a/b', 'name' => '张三']]);

        $this->assertSame('[{"path":"a/b","name":"张三"}]', $resultSet->toJson());
    }

    public function testJsonSerializeMatchesToJson(): void
    {
        $resultSet = new ResultSet([['path' => 'a/b', 'name' => '张三']]);

        $this->assertSame($resultSet->rows(), $resultSet->jsonSerialize());
        $this->assertSame(
            '[{"path":"a/b","name":"张三"}]',
            json_encode($resultSet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
