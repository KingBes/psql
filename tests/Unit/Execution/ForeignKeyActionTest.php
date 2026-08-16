<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Execution;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\ConstraintException;
use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Execution\Writer;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Schema\Blueprint;
use Kingbes\Psql\Schema\ForeignKey;
use Kingbes\Psql\Schema\ForeignKeyAction;
use PHPUnit\Framework\TestCase;

/**
 * 外键策略测试：onDelete/onUpdate 四种策略分发、DDL 校验、序列化与持久化
 */
final class ForeignKeyActionTest extends TestCase
{
    private Connection $conn;

    private Writer $writer;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->writer = new Writer($this->conn);
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

    private function createUsers(): void
    {
        $this->conn->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32);
        });
    }

    // ---- onDelete ----

    public function testOnDeleteRestrictThrows(): void
    {
        $this->createUsers();
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id');
            $b->foreignKey('user_id')->references('users', 'id'); // 默认 RESTRICT
        });
        $this->writer->insert('users', null, [['id' => 1], ['id' => 2]]);
        $this->writer->insert('orders', null, [['id' => 10, 'user_id' => 1]]);

        $this->expectException(ConstraintException::class);
        $this->expectExceptionMessage('RESTRICT');
        $this->writer->delete('users', null, $this->where('id', 1));
    }

    public function testOnDeleteCascade(): void
    {
        $this->createUsers();
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id');
            $b->foreignKey('user_id')->references('users', 'id')->onDelete(ForeignKeyAction::CASCADE);
        });
        $this->writer->insert('users', null, [['id' => 1], ['id' => 2]]);
        $this->writer->insert('orders', null, [['id' => 10, 'user_id' => 1]]);

        $this->assertSame(1, $this->writer->delete('users', null, $this->where('id', 1)));
        $this->assertSame([2], array_column($this->rows('users'), 'id'));
        $this->assertSame([], $this->rows('orders'));
    }

    public function testOnDeleteSetNullKeepsReferencingRows(): void
    {
        $this->createUsers();
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id'); // 可空
            $b->foreignKey('user_id')->references('users', 'id')->onDelete(ForeignKeyAction::SET_NULL);
        });
        $this->writer->insert('users', null, [['id' => 1], ['id' => 2]]);
        $this->writer->insert('orders', null, [['id' => 10, 'user_id' => 1]]);

        $this->assertSame(1, $this->writer->delete('users', null, $this->where('id', 1)));
        // 引用行保留，FK 列置 null
        $this->assertSame([2], array_column($this->rows('users'), 'id'));
        $orders = $this->rows('orders');
        $this->assertCount(1, $orders);
        $this->assertSame(10, $orders[0]['id']);
        $this->assertNull($orders[0]['user_id']);
    }

    public function testOnDeleteSetDefaultAppliesColumnDefault(): void
    {
        $this->createUsers();
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id')->default(7);
            $b->foreignKey('user_id')->references('users', 'id')->onDelete(ForeignKeyAction::SET_DEFAULT);
        });
        $this->writer->insert('users', null, [['id' => 1], ['id' => 7]]);
        $this->writer->insert('orders', null, [['id' => 10, 'user_id' => 1]]);

        $this->assertSame(1, $this->writer->delete('users', null, $this->where('id', 1)));
        // 置列默认值 7（剩余被引用行中存在）
        $orders = $this->rows('orders');
        $this->assertCount(1, $orders);
        $this->assertSame(7, $orders[0]['user_id']);
        $this->assertSame([7], array_column($this->rows('users'), 'id'));
    }

    public function testOnDeleteSetDefaultMissingTargetThrows(): void
    {
        $this->createUsers();
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id')->default(99); // 99 不在剩余被引用行中
            $b->foreignKey('user_id')->references('users', 'id')->onDelete(ForeignKeyAction::SET_DEFAULT);
        });
        $this->writer->insert('users', null, [['id' => 1], ['id' => 2]]);
        $this->writer->insert('orders', null, [['id' => 10, 'user_id' => 1]]);

        try {
            $this->writer->delete('users', null, $this->where('id', 1));
            $this->fail('默认值不存在应抛约束异常');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('99', $e->getMessage());
        }
        // 异常时数据不落库
        $this->assertCount(2, $this->rows('users'));
        $this->assertSame(1, $this->rows('orders')[0]['user_id']);
    }

    public function testOnDeleteSetDefaultAllTargetsDeletedIsSatisfied(): void
    {
        $this->createUsers();
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id')->default(5);
            $b->foreignKey('user_id')->references('users', 'id')->onDelete(ForeignKeyAction::SET_DEFAULT);
        });
        $this->writer->insert('users', null, [['id' => 5]]);
        $this->writer->insert('orders', null, [['id' => 10, 'user_id' => 5]]);

        // 被引用行全删光时视为满足（无引用可违反）
        $this->assertSame(1, $this->writer->delete('users', null, $this->where('id', 5)));
        $this->assertSame([], $this->rows('users'));
        $this->assertSame(5, $this->rows('orders')[0]['user_id']);
    }

    // ---- onUpdate ----

    public function testOnUpdateRestrictThrows(): void
    {
        $this->createUsers();
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id');
            $b->foreignKey('user_id')->references('users', 'id'); // 默认 RESTRICT
        });
        $this->writer->insert('users', null, [['id' => 1]]);
        $this->writer->insert('orders', null, [['id' => 10, 'user_id' => 1]]);

        try {
            $this->writer->update('users', null, $this->where('id', 1), ['id' => 99]);
            $this->fail('被引用列变更应被 RESTRICT 拦截');
        } catch (ConstraintException $e) {
            // 消息含引用方表名与 RESTRICT（与 v1 行为兼容）
            $this->assertStringContainsString('orders', $e->getMessage());
            $this->assertStringContainsString('RESTRICT', $e->getMessage());
        }
        $this->assertSame(1, $this->rows('users')[0]['id']);
    }

    public function testOnUpdateCascadePropagates(): void
    {
        $this->conn->createTable('student', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32);
        });
        $this->conn->createTable('order', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('student_id');
            $b->foreignKey('student_id')->references('student', 'id')->onUpdate(ForeignKeyAction::CASCADE);
        });
        $this->writer->insert('student', null, [['id' => 1, 'name' => 'Alice']]);
        $this->writer->insert('order', null, [['id' => 10, 'student_id' => 1]]);

        $this->assertSame(1, $this->writer->update('student', null, $this->where('id', 1), ['id' => 100]));
        $this->assertSame(100, $this->rows('student')[0]['id']);
        // 引用列跟着变
        $this->assertSame(100, $this->rows('order')[0]['student_id']);
    }

    public function testOnUpdateCascadeMultiLevelChain(): void
    {
        // a <- b <- c：b.id 既是主键又是引用 a.id 的外键列
        $this->conn->createTable('a', static function (Blueprint $b): void {
            $b->id();
        });
        $this->conn->createTable('b', static function (Blueprint $b): void {
            $b->id();
            $b->foreignKey('id')->references('a', 'id')->onUpdate(ForeignKeyAction::CASCADE);
        });
        $this->conn->createTable('c', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('b_id');
            $b->foreignKey('b_id')->references('b', 'id')->onUpdate(ForeignKeyAction::CASCADE);
        });
        $this->writer->insert('a', null, [['id' => 1]]);
        $this->writer->insert('b', null, [['id' => 1]]);
        $this->writer->insert('c', null, [['id' => 100, 'b_id' => 1]]);

        $this->assertSame(1, $this->writer->update('a', null, $this->where('id', 1), ['id' => 500]));
        // 三级链全部传播
        $this->assertSame(500, $this->rows('a')[0]['id']);
        $this->assertSame(500, $this->rows('b')[0]['id']);
        $this->assertSame(500, $this->rows('c')[0]['b_id']);
    }

    public function testOnUpdateCascadeSelfReference(): void
    {
        // 自引用：employee.manager_id -> employee.id，改根节点 id
        $this->conn->createTable('employee', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 32);
            $b->bigint('manager_id');
            $b->foreignKey('manager_id')->references('employee', 'id')->onUpdate(ForeignKeyAction::CASCADE);
        });
        $this->writer->insert('employee', null, [['id' => 1, 'name' => 'root', 'manager_id' => null]]);
        $this->writer->insert('employee', null, [['id' => 2, 'name' => 'child', 'manager_id' => 1]]);

        $this->assertSame(1, $this->writer->update('employee', null, $this->where('id', 1), ['id' => 100]));
        $rows = $this->rows('employee');
        $this->assertSame(100, $rows[0]['id']);
        $this->assertNull($rows[0]['manager_id']);
        // 子节点引用跟着变
        $this->assertSame(2, $rows[1]['id']);
        $this->assertSame(100, $rows[1]['manager_id']);
    }

    public function testOnUpdateCascadeRolledBackRestoresAllTables(): void
    {
        $this->conn->createTable('student', static function (Blueprint $b): void {
            $b->id();
        });
        $this->conn->createTable('order', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('student_id');
            $b->foreignKey('student_id')->references('student', 'id')->onUpdate(ForeignKeyAction::CASCADE);
        });
        $this->writer->insert('student', null, [['id' => 1]]);
        $this->writer->insert('order', null, [['id' => 10, 'student_id' => 1]]);

        $this->conn->begin();
        $this->writer->update('student', null, $this->where('id', 1), ['id' => 100]);
        $this->assertSame(100, $this->rows('student')[0]['id']);
        $this->assertSame(100, $this->rows('order')[0]['student_id']);
        $this->conn->rollBack();

        // 回滚后两表完全恢复
        $this->assertSame(1, $this->rows('student')[0]['id']);
        $this->assertSame(1, $this->rows('order')[0]['student_id']);
    }

    public function testOnUpdateSetNull(): void
    {
        $this->createUsers();
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id');
            $b->foreignKey('user_id')->references('users', 'id')->onUpdate(ForeignKeyAction::SET_NULL);
        });
        $this->writer->insert('users', null, [['id' => 1]]);
        $this->writer->insert('orders', null, [['id' => 10, 'user_id' => 1]]);

        $this->assertSame(1, $this->writer->update('users', null, $this->where('id', 1), ['id' => 99]));
        $this->assertSame(99, $this->rows('users')[0]['id']);
        $this->assertNull($this->rows('orders')[0]['user_id']);
    }

    public function testOnUpdateSetDefault(): void
    {
        $this->createUsers();
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id')->default(7);
            $b->foreignKey('user_id')->references('users', 'id')->onUpdate(ForeignKeyAction::SET_DEFAULT);
        });
        $this->writer->insert('users', null, [['id' => 1], ['id' => 7]]);
        $this->writer->insert('orders', null, [['id' => 10, 'user_id' => 1]]);

        $this->assertSame(1, $this->writer->update('users', null, $this->where('id', 1), ['id' => 2]));
        $this->assertSame([2, 7], array_column($this->rows('users'), 'id'));
        // 置默认值 7（更新后的引用目标集合中存在）
        $this->assertSame(7, $this->rows('orders')[0]['user_id']);
    }

    public function testOnUpdateSetDefaultMissingTargetThrows(): void
    {
        $this->createUsers();
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id')->default(999);
            $b->foreignKey('user_id')->references('users', 'id')->onUpdate(ForeignKeyAction::SET_DEFAULT);
        });
        $this->writer->insert('users', null, [['id' => 1]]);
        $this->writer->insert('orders', null, [['id' => 10, 'user_id' => 1]]);

        try {
            $this->writer->update('users', null, $this->where('id', 1), ['id' => 2]);
            $this->fail('默认值不存在应抛约束异常');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('999', $e->getMessage());
        }
        // 异常时两表数据不变
        $this->assertSame(1, $this->rows('users')[0]['id']);
        $this->assertSame(1, $this->rows('orders')[0]['user_id']);
    }

    // ---- DDL 校验 ----

    public function testDdlSetNullOnNotNullColumnThrows(): void
    {
        $this->createUsers();

        try {
            $this->conn->createTable('orders', static function (Blueprint $b): void {
                $b->id();
                $b->bigint('user_id')->notNull();
                $b->foreignKey('user_id')->references('users', 'id')->onDelete(ForeignKeyAction::SET_NULL);
            });
            $this->fail('SET_NULL 用于 NOT NULL 列应抛 SchemaException');
        } catch (SchemaException $e) {
            // 消息含表/列/策略名
            $this->assertStringContainsString('orders', $e->getMessage());
            $this->assertStringContainsString('user_id', $e->getMessage());
            $this->assertStringContainsString('SET_NULL', $e->getMessage());
        }
    }

    public function testDdlOnUpdateSetNullOnNotNullColumnThrows(): void
    {
        $this->createUsers();

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessage('SET_NULL');
        $this->conn->createTable('orders', static function (Blueprint $b): void {
            $b->id();
            $b->bigint('user_id')->notNull();
            $b->foreignKey('user_id')->references('users', 'id')->onUpdate(ForeignKeyAction::SET_NULL);
        });
    }

    public function testDdlSetDefaultWithoutDefaultThrows(): void
    {
        $this->createUsers();

        try {
            $this->conn->createTable('orders', static function (Blueprint $b): void {
                $b->id();
                $b->bigint('user_id'); // 无默认值
                $b->foreignKey('user_id')->references('users', 'id')->onDelete(ForeignKeyAction::SET_DEFAULT);
            });
            $this->fail('SET_DEFAULT 用于无默认值列应抛 SchemaException');
        } catch (SchemaException $e) {
            $this->assertStringContainsString('orders', $e->getMessage());
            $this->assertStringContainsString('user_id', $e->getMessage());
            $this->assertStringContainsString('SET_DEFAULT', $e->getMessage());
        }
    }

    public function testOnDeleteCascadeAliasEquivalentToActionEnum(): void
    {
        $aliasBlueprint = new Blueprint();
        $aliasBlueprint->foreignKey('user_id')->references('users', 'id')->onDeleteCascade();
        $aliasFk = $aliasBlueprint->toSchema('t')->foreignKeys[0];

        $enumBlueprint = new Blueprint();
        $enumBlueprint->foreignKey('user_id')->references('users', 'id')->onDelete(ForeignKeyAction::CASCADE);
        $enumFk = $enumBlueprint->toSchema('t')->foreignKeys[0];

        $this->assertSame(ForeignKeyAction::CASCADE, $aliasFk->onDelete);
        $this->assertSame($enumFk->onDelete, $aliasFk->onDelete);
        // 未显式设置时默认 RESTRICT
        $this->assertSame(ForeignKeyAction::RESTRICT, $aliasFk->onUpdate);
    }

    // ---- 序列化 ----

    public function testForeignKeyArrayRoundTrip(): void
    {
        $fk = new ForeignKey(
            'user_id',
            'users',
            'id',
            ForeignKeyAction::SET_NULL,
            ForeignKeyAction::CASCADE,
        );

        $data = $fk->toArray();
        $this->assertSame([
            'column' => 'user_id',
            'refTable' => 'users',
            'refColumn' => 'id',
            'onDelete' => 'SET_NULL',
            'onUpdate' => 'CASCADE',
        ], $data);

        $restored = ForeignKey::fromArray($data);
        $this->assertSame('user_id', $restored->column);
        $this->assertSame('users', $restored->refTable);
        $this->assertSame('id', $restored->refColumn);
        $this->assertSame(ForeignKeyAction::SET_NULL, $restored->onDelete);
        $this->assertSame(ForeignKeyAction::CASCADE, $restored->onUpdate);

        // 默认参数：双 RESTRICT
        $default = ForeignKey::fromArray((new ForeignKey('a', 'b', 'c'))->toArray());
        $this->assertSame(ForeignKeyAction::RESTRICT, $default->onDelete);
        $this->assertSame(ForeignKeyAction::RESTRICT, $default->onUpdate);
    }

    public function testForeignKeyFromArrayInvalidActionThrows(): void
    {
        $this->expectException(StorageException::class);
        ForeignKey::fromArray([
            'column' => 'user_id',
            'refTable' => 'users',
            'refColumn' => 'id',
            'onDelete' => 'BOGUS',
            'onUpdate' => 'CASCADE',
        ]);
    }

    public function testForeignKeyFromArrayMissingActionKeyThrows(): void
    {
        $this->expectException(StorageException::class);
        ForeignKey::fromArray([
            'column' => 'user_id',
            'refTable' => 'users',
            'refColumn' => 'id',
        ]);
    }

    // ---- 持久化 ----

    public function testPersistedSetNullPolicySurvivesReconnect(): void
    {
        $root = sys_get_temp_dir() . '/psql-fk-' . uniqid('', true);
        try {
            $connection = Psql::connect($root);
            $connection->createTable('users', static function (Blueprint $b): void {
                $b->id();
            });
            $connection->createTable('orders', static function (Blueprint $b): void {
                $b->id();
                $b->bigint('user_id');
                $b->foreignKey('user_id')->references('users', 'id')->onDelete(ForeignKeyAction::SET_NULL);
            });
            $connection->table('users')->insertMany([['id' => 1], ['id' => 2]]);
            $connection->table('orders')->insertMany([['id' => 10, 'user_id' => 1]]);

            // 重开连接：策略完整还原
            $reopened = Psql::connect($root);
            $schema = $reopened->engine()->loadSchema('main', 'orders');
            $this->assertSame(ForeignKeyAction::SET_NULL, $schema->foreignKeys[0]->onDelete);
            $this->assertSame(ForeignKeyAction::RESTRICT, $schema->foreignKeys[0]->onUpdate);

            // 删除语义仍正确：引用行保留置 null
            $reopened->table('users')->where('id', 1)->delete();
            $rows = $reopened->engine()->readRows('main', 'orders');
            $this->assertCount(1, $rows);
            $this->assertNull($rows[0]['user_id']);
        } finally {
            if (is_dir($root)) {
                $this->removeDirRecursive($root);
            }
        }
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
