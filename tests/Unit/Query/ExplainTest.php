<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Query;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Agg;
use Kingbes\Psql\Query\Explain;
use Kingbes\Psql\Query\SelectBuilder;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * EXPLAIN 计划输出测试：访问路径（索引命中/回退镜像 Executor 触发条件）、
 * JOIN 分派、子查询计数、UNION 分支、聚合/排序/分页步骤顺序、镜像一致性防线
 *
 * 同步义务：Explain 的索引/J JOIN 判定镜像 Executor::candidateRowIndexes 与
 * Executor::applyJoin 的触发条件——Executor 判定逻辑变更时需同步更新 Explain 及本测试
 */
final class ExplainTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('user', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull();
            $b->varchar('city', 32)->notNull();
            $b->int('age')->notNull();
            $b->varchar('ci_name', 32)->ci()->notNull();
        });
        $this->conn->createTable('order_t', static function (Blueprint $b): void {
            $b->id();
            $b->int('user_id')->notNull();
            $b->int('amount')->notNull();
            $b->varchar('ci_ref', 32)->ci()->notNull();
        });

        $this->conn->table('user')->insertMany([
            ['name' => 'a', 'city' => 'BJ', 'age' => 20, 'ci_name' => 'Foo'],
            ['name' => 'b', 'city' => 'SH', 'age' => 30, 'ci_name' => 'Bar'],
            ['name' => 'c', 'city' => 'BJ', 'age' => 40, 'ci_name' => 'BAZ'],
        ]);
        $this->conn->table('order_t')->insertMany([
            ['user_id' => 1, 'amount' => 100, 'ci_ref' => 'foo'],
            ['user_id' => 2, 'amount' => 200, 'ci_ref' => 'bar'],
        ]);
    }

    /**
     * 构建器固化查询后产出计划
     *
     * @return list<array<string, mixed>>
     */
    private function explain(SelectBuilder $builder): array
    {
        return Explain::of($this->conn, $builder->toQuery());
    }

    // ---- 访问路径 ----

    public function testBuilderExplainDelegatesToExplainOf(): void
    {
        $builder = $this->conn->table('user')->select()->where('email', '=', 'a@example.com');
        $this->assertSame(
            Explain::of($this->conn, $builder->toQuery()),
            $builder->explain(),
        );
        $this->assertSame('SCAN', $builder->explain()[0]['step']);
    }

    public function testFullScanWithoutWhere(): void
    {
        $steps = $this->explain($this->conn->table('user')->select());

        $this->assertSame(['SCAN'], array_column($steps, 'step'));
        $scan = $steps[0];
        $this->assertSame('user', $scan['table']);
        $this->assertSame('FULL SCAN', $scan['via']);
        // estRows 等于实际存储行数
        $this->assertSame(3, $scan['estRows']);
    }

    public function testIndexHitViaExplicitIndex(): void
    {
        $this->conn->createIndex('user', 'idx_city', 'city');

        $steps = $this->explain($this->conn->table('user')->where('city', 'BJ'));

        $this->assertSame('INDEX idx_city (hash, equality)', $steps[0]['via']);
        // 索引命中时 estRows 仍为存储行数（哈希索引无基数统计，预过滤后另行求值）
        $this->assertSame(3, $steps[0]['estRows']);
    }

    public function testFullScanAfterDropIndex(): void
    {
        $this->conn->createIndex('user', 'idx_city', 'city');
        $builder = $this->conn->table('user')->where('city', 'BJ');
        $this->assertStringContainsString('INDEX idx_city', $this->explain($builder)[0]['via']);

        $this->conn->dropIndex('user', 'idx_city');

        $this->assertSame('FULL SCAN', $this->explain($builder)[0]['via']);
    }

    public function testPrimaryKeyAutoIndex(): void
    {
        $steps = $this->explain($this->conn->table('user')->where('id', 2));

        $this->assertStringContainsString('INDEX', $steps[0]['via']);
        $this->assertStringContainsString('PRIMARY', $steps[0]['via']);
    }

    public function testCompositeIndexExactColumnSetHit(): void
    {
        $this->conn->createIndex('user', 'idx_city_age', 'city', 'age');

        // 条件书写顺序与索引列序不同也命中（列集完全一致，顺序不敏感）
        $steps = $this->explain(
            $this->conn->table('user')->where('age', 30)->where('city', 'SH'),
        );

        $this->assertSame('INDEX idx_city_age (hash, equality)', $steps[0]['via']);
    }

    // ---- 触发条件逐项回退（与 Executor::candidateRowIndexes 条件一致） ----

    public function testFallbackOrConnector(): void
    {
        $this->conn->createIndex('user', 'idx_city', 'city');

        $steps = $this->explain(
            $this->conn->table('user')->where('city', 'BJ')->orWhere('name', 'a'),
        );

        $this->assertSame('FULL SCAN', $steps[0]['via']);
    }

    public function testFallbackInListCondition(): void
    {
        $this->conn->createIndex('user', 'idx_city', 'city');

        // 数组 IN 条件非裸列名等值 Comparison → 不命中
        $steps = $this->explain($this->conn->table('user')->whereIn('city', ['BJ', 'SH']));

        $this->assertSame('FULL SCAN', $steps[0]['via']);
    }

    public function testFallbackNullValue(): void
    {
        $this->conn->createIndex('user', 'idx_city', 'city');

        $steps = $this->explain($this->conn->table('user')->where('city', null));

        $this->assertSame('FULL SCAN', $steps[0]['via']);
    }

    public function testFallbackPartialColumnSet(): void
    {
        $this->conn->createIndex('user', 'idx_city_age', 'city', 'age');

        // 联合索引仅覆盖单列，列集不完全一致 → 不命中
        $steps = $this->explain($this->conn->table('user')->where('city', 'BJ'));

        $this->assertSame('FULL SCAN', $steps[0]['via']);
    }

    public function testFallbackQualifiedColumn(): void
    {
        $this->conn->createIndex('user', 'idx_city', 'city');

        // 限定列名 't.city' 含 '.' → 不命中
        $steps = $this->explain($this->conn->table('user as t')->where('t.city', 'BJ'));

        $this->assertSame('FULL SCAN', $steps[0]['via']);
        $this->assertSame('user', $steps[0]['table']);
    }

    // ---- CI 列跳过（镜像 Executor CI 判定） ----

    public function testCIColumnSkipsIndex(): void
    {
        $this->conn->createIndex('user', 'idx_ci_name', 'ci_name');

        // CI 列建索引等值查 → CS 哈希键会漏跨大小写匹配行，跳过索引走全扫描
        $steps = $this->explain($this->conn->table('user')->where('ci_name', 'foo'));

        $this->assertSame('FULL SCAN', $steps[0]['via']);
    }

    // ---- JOIN 分派 ----

    public function testJoinEqualityUsesHash(): void
    {
        $steps = $this->explain(
            $this->conn->table('user')->select()->join('order_t', 'user.id', '=', 'order_t.user_id'),
        );

        $this->assertSame(['SCAN', 'JOIN'], array_column($steps, 'step'));
        $join = $steps[1];
        $this->assertSame('HASH', $join['type']);
        // left/right 用各源基础表名（剥别名）
        $this->assertSame('user', $join['left']);
        $this->assertSame('order_t', $join['right']);
        $this->assertSame('user.id = order_t.user_id', $join['on']);
    }

    public function testJoinNonEqualityUsesNestedLoop(): void
    {
        $steps = $this->explain(
            $this->conn->table('user')->select()->join('order_t', 'user.id', '>', 'order_t.user_id'),
        );

        $join = $steps[1];
        $this->assertSame('NESTED LOOP', $join['type']);
        $this->assertStringContainsString('非等值', $join['detail']);
    }

    public function testJoinCIColumnFallsBackToNestedLoop(): void
    {
        $steps = $this->explain(
            $this->conn->table('user')->select()->join('order_t', 'user.ci_name', '=', 'order_t.ci_ref'),
        );

        $join = $steps[1];
        $this->assertSame('NESTED LOOP', $join['type']);
        $this->assertStringContainsString('CI', $join['detail']);
    }

    // ---- 子查询计数 ----

    public function testSubqueryCount(): void
    {
        $builder = $this->conn->table('user')->select()
            ->whereIn('city', $this->conn->table('user')->select('city'))
            ->whereIn('name', $this->conn->table('user')->select('name'));

        $steps = $this->explain($builder);

        $subquerySteps = array_values(
            array_filter($steps, static fn (array $step): bool => $step['step'] === 'SUBQUERY'),
        );
        $this->assertCount(1, $subquerySteps);
        $this->assertSame(2, $subquerySteps[0]['count']);
    }

    // ---- UNION 分支 ----

    public function testUnionBranchSteps(): void
    {
        $builder = $this->conn->table('user')->select('name')
            ->union($this->conn->table('user')->select('name'))
            ->unionAll($this->conn->table('order_t')->select('ci_ref'));

        $steps = $this->explain($builder);

        $this->assertSame(['SCAN', 'UNION', 'UNION'], array_column($steps, 'step'));
        $this->assertSame('UNION', $steps[1]['type']);
        $this->assertSame(1, $steps[1]['order']);
        $this->assertSame('UNION ALL', $steps[2]['type']);
        $this->assertSame(2, $steps[2]['order']);
    }

    // ---- 聚合 / 排序 / 去重 / 分页步骤顺序 ----

    public function testAggregateSortLimitPipelineOrder(): void
    {
        $builder = $this->conn->table('user')
            ->select('city', Agg::count('*')->as('cnt'))
            ->groupBy('city')
            ->orderBy('city', 'DESC')
            ->limit(2)
            ->offset(1);

        $steps = $this->explain($builder);

        $this->assertSame(['SCAN', 'AGGREGATE', 'SORT', 'LIMIT'], array_column($steps, 'step'));
        $this->assertSame(['city'], $steps[1]['groupBy']);
        $this->assertSame(['COUNT'], $steps[1]['funcs']);
        $this->assertSame('city DESC', $steps[2]['keys']);
        $this->assertSame(2, $steps[3]['limit']);
        $this->assertSame(1, $steps[3]['offset']);
    }

    public function testDistinctStep(): void
    {
        $steps = $this->explain($this->conn->table('user')->select('city')->distinct());

        $this->assertSame(['SCAN', 'DISTINCT'], array_column($steps, 'step'));
    }

    /**
     * 收尾顺序防线：DISTINCT 在 SORT 之前（对齐 Executor::finalizeEntries 的 DISTINCT → ORDER → LIMIT；
     * 曾反序输出误导——计划展示顺序必须与实际执行一致）
     */
    public function testDistinctPrecedesSortInPipeline(): void
    {
        $steps = $this->explain($this->conn->table('user')->select('city')->distinct()->orderBy('city'));

        $this->assertSame(['SCAN', 'DISTINCT', 'SORT'], array_column($steps, 'step'));
    }

    public function testAggregatePrecedesUnionBranches(): void
    {
        $steps = $this->explain(
            $this->conn->table('user')
                ->select('city', Agg::count('*')->as('cnt'))
                ->groupBy('city')
                ->union($this->conn->table('user')->select('city', Agg::count('*')->as('cnt'))->groupBy('city')),
        );

        $this->assertSame(['SCAN', 'AGGREGATE', 'UNION'], array_column($steps, 'step'));
    }

    // ---- 镜像一致性防线 ----

    /**
     * 防漂移防线：Explain 是 Executor 实际触发条件的静态镜像——
     * 计划判定的场景实际执行必须成功返回（防"计划说索引但实际条件不符"的漂移；
     * Executor 触发条件变更时 Explain 需同步，本测试兜底两者不矛盾）
     */
    public function testMirrorConsistencyWithExecution(): void
    {
        $this->conn->createIndex('user', 'idx_city', 'city');

        $scenarios = [
            '索引等值' => $this->conn->table('user')->where('city', 'BJ'),
            '全扫描非等值' => $this->conn->table('user')->where('age', '>', 25),
            '哈希连接' => $this->conn->table('user')->select('user.id')
                ->join('order_t', 'user.id', '=', 'order_t.user_id'),
            '嵌套循环连接（非等值）' => $this->conn->table('user')->select('user.id')
                ->join('order_t', 'user.id', '>', 'order_t.user_id'),
            '嵌套循环连接（CI 列）' => $this->conn->table('user')->select('user.ci_name')
                ->join('order_t', 'user.ci_name', '=', 'order_t.ci_ref'),
        ];

        foreach ($scenarios as $label => $builder) {
            $steps = $this->explain($builder);
            $this->assertNotSame([], $steps, "场景 [{$label}] 计划为空");

            $rows = $builder->get()->rows();
            $this->assertIsArray($rows, "场景 [{$label}] 实际执行失败");
        }
    }

    // ---- estRows 估算 ----

    public function testEstRowsTracksStoredRowCount(): void
    {
        $before = $this->explain($this->conn->table('user')->select())[0]['estRows'];
        $this->assertSame(3, $before);

        $this->conn->table('user')->insert(['name' => 'd', 'city' => 'GZ', 'age' => 50, 'ci_name' => 'Qux']);

        $after = $this->explain($this->conn->table('user')->select())[0]['estRows'];
        $this->assertSame(4, $after);
    }
}
