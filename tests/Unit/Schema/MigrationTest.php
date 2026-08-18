<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Schema;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\Blueprint;
use Kingbes\Psql\Schema\Migration;
use PHPUnit\Framework\TestCase;

/**
 * 迁移工具测试：schema diff 生成计划（建表/删表/加列/删列/改列/索引）+ apply 往返收敛
 */
final class MigrationTest extends TestCase
{
    private function makeTarget(): Connection
    {
        $db = Psql::memory();
        $db->createTable('users', static function (Blueprint $t): void {
            $t->id();
            $t->varchar('name', 50)->notNull()->unique();
            $t->tinyint('age')->unsigned()->default(0);
            $t->varchar('bio', 200);
            $t->index('age');
        });
        $db->createTable('orders', static function (Blueprint $t): void {
            $t->id();
            $t->bigint('user_id');
            $t->decimal('amount', 8, 2)->notNull();
        });

        return $db;
    }

    private function makeCurrent(): Connection
    {
        $db = Psql::memory();
        $db->createTable('users', static function (Blueprint $t): void {
            $t->id();
            $t->varchar('name', 30)->notNull();
            $t->varchar('bio', 80);
            $t->varchar('old_col', 10);
        });
        $db->createTable('extra', static function (Blueprint $t): void {
            $t->id();
        });
        $db->table('users')->insert(['name' => 'alice', 'bio' => 'hi', 'old_col' => 'x']);

        return $db;
    }

    public function testDiffGeneratesApplyablePlan(): void
    {
        $target = $this->makeTarget();
        $current = $this->makeCurrent();

        $plan = Migration::diff($target, $current);
        $ops = array_column($plan, 'op');

        $this->assertContains('createTable', $ops);   // orders
        $this->assertContains('addColumn', $ops);     // age
        $this->assertContains('modifyColumn', $ops);  // bio 80→200
        $this->assertContains('dropColumn', $ops);    // old_col
        $this->assertContains('createIndex', $ops);   // age 索引
        $this->assertContains('dropTable', $ops);     // extra
    }

    public function testApplyThenRediffConverges(): void
    {
        $target = $this->makeTarget();
        $current = $this->makeCurrent();

        Migration::apply($current, Migration::diff($target, $current));

        // 收敛：剩余仅"需手工"的 note 步骤（name NOT NULL 无默认值的结构变化）
        $left = Migration::diff($target, $current);
        foreach ($left as $step) {
            $this->assertSame('note', $step['op'], '未收敛步骤: ' . $step['op'] . ' ' . ($step['detail'] ?? ''));
        }

        // 结构收敛（除 name 长度外）：age 已加、bio 长度 200、old_col 已删、索引就位、extra 已删、orders 已建
        $schema = $current->tableSchema('users');
        $this->assertSame(['id', 'name', 'age', 'bio'], array_map(
            static fn ($c): string => $c->name,
            $schema->columns,
        ));
        $this->assertSame(200, $schema->columnOrFail('bio')->length);
        $this->assertSame(1, count($schema->indexes));
        $tables = $current->tables();
        sort($tables);
        $this->assertSame(['orders', 'users'], $tables);
    }

    public function testDataPreservedAndDefaultsBackfilled(): void
    {
        $target = $this->makeTarget();
        $current = $this->makeCurrent();

        Migration::apply($current, Migration::diff($target, $current));

        $row = $current->table('users')->select('id', 'name', 'age')->get()->rows()[0];
        $this->assertSame('alice', $row['name']);
        $this->assertSame(0, $row['age']); // 新增列回填默认值
    }

    public function testNotNullModifyDowngradedToNote(): void
    {
        $target = Psql::memory();
        $target->createTable('t', static function (Blueprint $t): void {
            $t->id();
            $t->varchar('c', 10)->notNull();
        });
        $current = Psql::memory();
        $current->createTable('t', static function (Blueprint $t): void {
            $t->id();
            $t->varchar('c', 5);
        });

        $plan = Migration::diff($target, $current);
        $ops = array_column($plan, 'op');

        // 变成 NOT NULL 无默认值 → 不自动 apply（note），避免 alterTable 无法回填
        $this->assertNotContains('modifyColumn', $ops);
        $this->assertContains('note', $ops);
    }

    public function testIdenticalSchemasProduceNoSteps(): void
    {
        $a = $this->makeTarget();
        $b = $this->makeTarget();

        $this->assertSame([], Migration::diff($a, $b));
    }
}
