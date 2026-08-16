<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\ConstraintException;
use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Exception\TypeException;
use Kingbes\Psql\Execution\Writer;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Condition\Between;
use Kingbes\Psql\Query\Condition\Comparison;
use Kingbes\Psql\Query\Condition\Condition;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Query\Condition\InList;
use Kingbes\Psql\Schema\Blueprint;
use Kingbes\Psql\Schema\CheckConstraint;
use PHPUnit\Framework\TestCase;

/**
 * CHECK 约束测试：注册/序列化、insert/update 求值拦截、持久化、列变更同步、级联传播
 */
final class CheckConstraintTest extends TestCase
{
    private Connection $conn;

    private Writer $writer;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->writer = new Writer($this->conn);
        $this->conn->createTable('members', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32)->notNull();
            $b->int('age')->default(18);
            $b->check('age_adult', new Comparison('age', '>=', 18));
        });
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(string $table): array
    {
        return $this->conn->engine()->readRows($this->conn->currentDatabase(), $table);
    }

    private function where(string $column, mixed $value): ConditionGroup
    {
        return (new ConditionGroup())->where($column, $value);
    }

    // ---- 注册 ----

    public function testRegisterSingleComparisonAndNestedGroup(): void
    {
        $this->conn->createTable('goods', static function (Blueprint $b): void {
            $b->id();
            $b->int('price');
            $b->int('stock');
            $b->check('price_positive', new Comparison('price', '>', 0));
            $b->check('stock_rule', (new ConditionGroup())
                ->where('stock', '>=', 0)
                ->where('price', '<=', 10000));
        });

        $schema = $this->conn->engine()->loadSchema($this->conn->currentDatabase(), 'goods');

        $this->assertCount(2, $schema->checks);
        $this->assertSame('price_positive', $schema->checks[0]->name);
        $this->assertInstanceOf(Comparison::class, $schema->checks[0]->condition);
        $this->assertSame('stock_rule', $schema->checks[1]->name);
        $this->assertInstanceOf(ConditionGroup::class, $schema->checks[1]->condition);
    }

    public function testRegisterDuplicateNameThrows(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('age_adult');
        $this->conn->createTable('dup_checks', static function (Blueprint $b): void {
            $b->id();
            $b->int('n');
            $b->check('age_adult', new Comparison('n', '>', 0));
            $b->check('age_adult', new Comparison('n', '<', 100));
        });
    }

    public function testEmptyGroupAllowed(): void
    {
        // 空 ConditionGroup 恒真，注册不禁止
        $this->conn->createTable('trivial', static function (Blueprint $b): void {
            $b->id();
            $b->int('n');
            $b->check('always_true', new ConditionGroup());
        });

        $result = $this->writer->insert('trivial', null, [['n' => -5]]);
        $this->assertSame(1, $result->rowCount());
    }

    public function testRegisterArrayValueThrowsAtRegistration(): void
    {
        // 注册时（check 调用本身）即拦截数组值，无需等到 toSchema/序列化
        $blueprint = new Blueprint();

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('列 n 含 array');
        $blueprint->check('c', new Comparison('n', '=', [1, 2]));
    }

    public function testRegisterNonScalarInNestedGroupThrows(): void
    {
        // group > between(min 为数组)：深层非标量也会在注册时抛出
        $group = (new ConditionGroup())
            ->where('n', '>', 0)
            ->add(
                (new ConditionGroup())->whereBetween('n', [1, 2], 100),
                'AND',
            );

        $blueprint = new Blueprint();

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('列 n 含 array');
        $blueprint->check('c', $group);
    }

    public function testRegisterInListWithArrayMemberThrows(): void
    {
        $blueprint = new Blueprint();

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('列 n 含 array');
        $blueprint->check('c', new InList('n', [1, [2, 3]]));
    }

    public function testRegisterScalarNullBoolValuesPass(): void
    {
        // 合法的标量/null/bool 不被误伤，注册成功
        $blueprint = new Blueprint();
        $blueprint->id();
        $blueprint->int('n');

        $blueprint->check('c_int', new Comparison('n', '=', 1));
        $blueprint->check('c_float', new Comparison('n', '>=', 0.5));
        $blueprint->check('c_string', new Comparison('n', '=', 'x'));
        $blueprint->check('c_bool', new Comparison('n', '=', true));
        $blueprint->check('c_null', new Comparison('n', '=', null));
        $blueprint->check('c_in', new InList('n', [1, 2.5, 'x', false, null]));
        $blueprint->check('c_between', new Between('n', 0, 100));

        $this->assertCount(7, $blueprint->toSchema('scalars')->checks);
    }

    public function testMemoryCreateTableWithArrayCheckValueThrows(): void
    {
        // memory 引擎下建表即抛，而非留到落盘序列化才暴露
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('列 n 含 array');
        $this->conn->createTable('bad_checks', static function (Blueprint $b): void {
            $b->id();
            $b->int('n');
            $b->check('c', new Comparison('n', '=', [1, 2]));
        });
    }

    // ---- 序列化 ----

    public function testConditionAndCheckRoundTripWithNestedGroup(): void
    {
        $group = (new ConditionGroup())
            ->where('age', '>=', 18)
            ->orWhere('name', 'Tom');
        $check = new CheckConstraint('c1', $group);

        $restored = CheckConstraint::fromArray($check->toArray());

        $this->assertSame('c1', $restored->name);
        $this->assertEquals($check->toArray(), $restored->toArray());
    }

    public function testNestedGroupRoundTripViaConditionFromArray(): void
    {
        $nested = (new ConditionGroup())
            ->whereBetween('age', 18, 65)
            ->whereNotNull('name');
        $outer = (new ConditionGroup())
            ->where('age', '>', 0)
            ->orWhereLike('name', 'A%');
        $outer->add($nested, 'AND');

        $restored = Condition::fromArray($outer->toArray());

        $this->assertInstanceOf(ConditionGroup::class, $restored);
        $this->assertEquals($outer->toArray(), $restored->toArray());
        // 嵌套子条件还原后可正常求值
        $this->assertTrue(
            \Kingbes\Psql\Query\ConditionEvaluator::evaluate(
                ['age' => 20, 'name' => 'Bob'],
                $restored,
            ),
        );
    }

    public function testFromArrayUnknownTypeThrows(): void
    {
        $this->expectException(StorageException::class);
        Condition::fromArray(['type' => 'bogus', 'column' => 'a']);
    }

    public function testFromArrayMissingTypeThrows(): void
    {
        $this->expectException(StorageException::class);
        Condition::fromArray(['column' => 'a', 'operator' => '=', 'value' => 1]);
    }

    public function testFromArrayNonScalarValueThrows(): void
    {
        $this->expectException(StorageException::class);
        Condition::fromArray([
            'type' => 'comparison',
            'column' => 'a',
            'operator' => '=',
            'value' => ['nested' => 'array'],
        ]);
    }

    public function testToArrayNonScalarValueThrows(): void
    {
        $comparison = new Comparison('a', '=', ['nested' => 'array']);

        $this->expectException(StorageException::class);
        $comparison->toArray();
    }

    public function testTableSchemaRoundTripKeepsChecks(): void
    {
        $schema = $this->conn->engine()->loadSchema($this->conn->currentDatabase(), 'members');
        $restored = \Kingbes\Psql\Schema\TableSchema::fromArray($schema->toArray());

        $this->assertCount(1, $restored->checks);
        $this->assertSame('age_adult', $restored->checks[0]->name);
        $this->assertEquals($schema->checks[0]->condition->toArray(), $restored->checks[0]->condition->toArray());
    }

    // ---- insert 求值 ----

    public function testInsertViolatingCheckThrowsAndKeepsTableUnchanged(): void
    {
        try {
            $this->writer->insert('members', null, [['name' => 'a', 'age' => 17]]);
            $this->fail('违反 CHECK 未抛异常');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('age_adult', $e->getMessage());
            $this->assertStringContainsString('members', $e->getMessage());
        }

        // 违规行不落库
        $this->assertSame([], $this->rows('members'));
    }

    public function testInsertSatisfyingCheckPasses(): void
    {
        $this->writer->insert('members', null, [['name' => 'a', 'age' => 18]]);

        $this->assertCount(1, $this->rows('members'));
    }

    public function testInsertDefaultBackfillParticipatesInCheckEvaluation(): void
    {
        // age 缺省回填 18，check(age>=18) 求值通过
        $result = $this->writer->insert('members', null, [['name' => 'a']]);

        $this->assertSame(1, $result->rowCount());
        $this->assertSame(18, $this->rows('members')[0]['age']);
    }

    public function testInsertNullAgeFailsCheck(): void
    {
        // age 为 null 时比较条件恒假 → check 拦截
        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('age_adult');
        $this->writer->insert('members', null, [['name' => 'a', 'age' => null]]);
    }

    public function testInsertTypeViolationStillThrowsBeforeCheck(): void
    {
        $this->expectException(TypeException::class);
        $this->writer->insert('members', null, [['name' => 'a', 'age' => 'abc']]);
    }

    // ---- update 求值 ----

    public function testUpdateViolatingCheckThrows(): void
    {
        $this->writer->insert('members', null, [['name' => 'a', 'age' => 20]]);

        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('age_adult');
        $this->writer->update('members', null, $this->where('name', 'a'), ['age' => 17]);
    }

    public function testUpdateSatisfyingCheckPasses(): void
    {
        $this->writer->insert('members', null, [['name' => 'a', 'age' => 20]]);

        $this->assertSame(1, $this->writer->update('members', null, $this->where('name', 'a'), ['age' => 25]));
        $this->assertSame(25, $this->rows('members')[0]['age']);
    }

    // ---- 持久化 ----

    public function testCheckSurvivesReconnectAndStillEnforces(): void
    {
        $root = sys_get_temp_dir() . '/psql-check-' . uniqid('', true);
        try {
            $connection = Psql::connect($root);
            $connection->createTable('members', static function (Blueprint $b): void {
                $b->id();
                $b->varchar('name', 32)->notNull();
                $b->int('age')->default(18);
                $b->check('age_adult', new Comparison('age', '>=', 18));
            });
            $connection->table('members')->insert(['name' => 'ok', 'age' => 30]);

            // 重开连接：check 完整还原
            $reopened = Psql::connect($root);
            $schema = $reopened->engine()->loadSchema('main', 'members');
            $this->assertCount(1, $schema->checks);
            $this->assertSame('age_adult', $schema->checks[0]->name);

            // 违规插入仍抛
            try {
                $reopened->table('members')->insert(['name' => 'bad', 'age' => 17]);
                $this->fail('重连后 CHECK 未生效');
            } catch (ConstraintException $e) {
                $this->assertStringContainsString('age_adult', $e->getMessage());
            }
            $this->assertCount(1, $reopened->engine()->readRows('main', 'members'));
        } finally {
            if (is_dir($root)) {
                $this->removeDirRecursive($root);
            }
        }
    }

    // ---- 列变更 ----

    public function testRenameColumnSyncsCheckReference(): void
    {
        $this->conn->alterTable('members', static function (Blueprint $b): void {
            $b->renameColumn('age', 'years');
        });

        // check 引用同步为 years：违规插入仍抛
        try {
            $this->writer->insert('members', null, [['name' => 'a', 'years' => 17]]);
            $this->fail('改名后 CHECK 未拦截');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('age_adult', $e->getMessage());
        }

        // 结构中条件列已替换
        $schema = $this->conn->engine()->loadSchema($this->conn->currentDatabase(), 'members');
        $this->assertSame('years', $schema->checks[0]->condition->toArray()['column']);

        // 满足约束的行正常写入
        $this->writer->insert('members', null, [['name' => 'b', 'years' => 20]]);
        $this->assertCount(1, $this->rows('members'));
    }

    public function testRenameColumnSyncsNestedGroupCheckReference(): void
    {
        $this->conn->createTable('goods', static function (Blueprint $b): void {
            $b->id();
            $b->int('price');
            $b->check('price_rule', (new ConditionGroup())
                ->where('price', '>', 0)
                ->orWhere('price', '=', 999));
        });

        $this->conn->alterTable('goods', static function (Blueprint $b): void {
            $b->renameColumn('price', 'cost');
        });

        // 嵌套条件中列引用同步：cost=0 不满足 (cost > 0 OR cost = 999)
        try {
            $this->writer->insert('goods', null, [['cost' => 0]]);
            $this->fail('改名后嵌套 CHECK 未拦截');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('price_rule', $e->getMessage());
        }

        // 豁免值仍放行
        $this->writer->insert('goods', null, [['cost' => 999]]);
        $this->assertCount(1, $this->rows('goods'));
    }

    public function testDropColumnReferencedByCheckThrows(): void
    {
        try {
            $this->conn->alterTable('members', static function (Blueprint $b): void {
                $b->dropColumn('age');
            });
            $this->fail('删除被 CHECK 引用的列未抛异常');
        } catch (SchemaException $e) {
            $this->assertStringContainsString('age_adult', $e->getMessage());
        }

        // 列仍在
        $schema = $this->conn->engine()->loadSchema($this->conn->currentDatabase(), 'members');
        $this->assertTrue($schema->hasColumn('age'));
    }

    // ---- 级联传播 ----

    public function testCascadePropagationRowEnforcesCheck(): void
    {
        // parent.id 被 CASCADE 引用；child.pid 带 CHECK(pid >= 100)
        $this->conn->createTable('parent', static function (Blueprint $b): void {
            $b->id();
        });
        $this->conn->createTable('child', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('pid');
            $b->check('pid_min', new Comparison('pid', '>=', 100));
            $b->foreignKey('pid')->references('parent', 'id')
                ->onUpdate(\Kingbes\Psql\Schema\ForeignKeyAction::CASCADE);
        });
        $this->writer->insert('parent', null, [['id' => 100]]);
        $this->writer->insert('child', null, [['id' => 1, 'pid' => 100]]);

        try {
            // parent.id 100 → 50 经 CASCADE 传播 child.pid=50，违反 pid_min
            $this->writer->update('parent', null, $this->where('id', 100), ['id' => 50]);
            $this->fail('级联传播行违反 CHECK 未拦截');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('pid_min', $e->getMessage());
        }

        // 异常时两表数据不变
        $this->assertSame(100, $this->rows('parent')[0]['id']);
        $this->assertSame(100, $this->rows('child')[0]['pid']);
    }

    /**
     * 递归删除临时目录
     */
    private function removeDirRecursive(string $dir): void
    {
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
