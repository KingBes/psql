<?php

declare(strict_types=1);

namespace Kingbes\Psql\Query;

use Kingbes\Psql\Exception\QueryException;

/**
 * 窗口函数表达式：排名函数（ROW_NUMBER/RANK/DENSE_RANK）或聚合函数（COUNT/SUM/AVG/MIN/MAX）
 * OVER (PARTITION BY ... ORDER BY ...)
 *
 * 由 Func::rowNumber()/rank()/denseRank() 或 Agg::sum(...)->over() 构造；
 * 不可变风格，partitionBy/orderBy/as 返回新实例。执行期在投影/聚合后整组计算
 * （聚合窗口为整分区聚合，不做 ROWS BETWEEN 帧）
 */
final readonly class WindowExpression
{
    private const FUNCTIONS = ['ROW_NUMBER', 'RANK', 'DENSE_RANK', 'COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];

    /**
     * @param string $function 窗口函数（排名函数 column 为 null；聚合函数 column 为列名或 '*'）
     * @param list<string> $partitionBy 分组列
     * @param list<array{column: string, direction: 'ASC'|'DESC'}> $orderBy 分区内排序
     */
    public function __construct(
        public string $function,
        public ?string $column = null,
        public array $partitionBy = [],
        public array $orderBy = [],
        public ?string $alias = null,
    ) {
        if (!in_array($function, self::FUNCTIONS, true)) {
            throw new QueryException('非法窗口函数，仅支持: ' . implode('/', self::FUNCTIONS));
        }
        $isRanking = in_array($function, ['ROW_NUMBER', 'RANK', 'DENSE_RANK'], true);
        if ($isRanking && $column !== null) {
            throw new QueryException("排名函数 {$function} 不接受列参数");
        }
        if (!$isRanking && $column === null) {
            throw new QueryException("聚合窗口函数 {$function} 必须指定列");
        }
    }

    // ---- 排名函数工厂 ----

    public static function rowNumber(): self
    {
        return new self('ROW_NUMBER');
    }

    public static function rank(): self
    {
        return new self('RANK');
    }

    public static function denseRank(): self
    {
        return new self('DENSE_RANK');
    }

    /**
     * 从聚合表达式升级为窗口（Agg::sum('x')->over()）
     */
    public static function fromAggregate(AggregateExpression $aggregate): self
    {
        return new self($aggregate->function, $aggregate->column);
    }

    // ---- 规格链 ----

    public function partitionBy(string ...$columns): self
    {
        return new self($this->function, $this->column, $columns, $this->orderBy, $this->alias);
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $normalized = strtoupper($direction);
        if ($normalized !== 'ASC' && $normalized !== 'DESC') {
            throw new QueryException("非法排序方向，仅允许 ASC/DESC: {$direction}");
        }

        return new self(
            $this->function,
            $this->column,
            $this->partitionBy,
            [...$this->orderBy, ['column' => $column, 'direction' => $normalized]],
            $this->alias,
        );
    }

    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'DESC');
    }

    public function as(string $alias): self
    {
        return new self($this->function, $this->column, $this->partitionBy, $this->orderBy, $alias);
    }

    public function alias(): ?string
    {
        return $this->alias;
    }

    /**
     * 排名函数是否需 ORDER BY（ROW_NUMBER/RANK/DENSE_RANK 均要求）
     */
    public function isRanking(): bool
    {
        return in_array($this->function, ['ROW_NUMBER', 'RANK', 'DENSE_RANK'], true);
    }
}
