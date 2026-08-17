<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Execution\Trigger;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\Blueprint;
use Kingbes\Psql\Schema\ForeignKeyAction;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 触发器（BEFORE/AFTER × INSERT/UPDATE/DELETE）行为测试
 */
final class TriggerTest extends TestCase
{
    // ---- INSERT ----

    public function testBeforeInsertModifiesRow(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);

        // BEFORE INSERT 在 cast/约束校验之前：可补默认值、清洗输入（如数值字符串规范化交给后续 cast）
        $connection->createTrigger('users', 'before', 'insert', static function (array $row): array {
            $row['name'] = trim((string) $row['name']);
            $row['memo'] = $row['memo'] ?? 'default-memo';

            return $row;
        });

        $connection->table('users')->insert(['name' => '  alice  ']);
        $rows = $connection->engine()->readRows('main', 'users');

        $this->assertSame('alice', $rows[0]['name']);
        $this->assertSame('default-memo', $rows[0]['memo']);
    }

    public function testBeforeInsertThrowsAbortsWholeBatch(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);

        // 第 1 行通过、第 2 行拦截：批内原子——任何行都不写入
        $connection->createTrigger('users', 'before', 'insert', static function (array $row): array {
            if ($row['name'] === 'bad') {
                throw new RuntimeException('禁止插入 bad');
            }

            return $row;
        });

        try {
            $connection->table('users')->insertMany([['name' => 'ok'], ['name' => 'bad']]);
            $this->fail('BEFORE INSERT 抛异常应使整批插入失败');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([], $connection->engine()->readRows('main', 'users'));
    }

    public function testAfterInsertReceivesFinalRow(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);

        $received = [];
        $connection->createTrigger('users', 'after', 'insert', static function (array $row) use (&$received): void {
            $received[] = $row;
        });

        $connection->table('users')->insert(['name' => 'a']);

        // AFTER 收到最终形态：自增 id 已分配、未提供的 memo 列已存在（null）
        $this->assertCount(1, $received);
        $this->assertSame(1, $received[0]['id']);
        $this->assertSame('a', $received[0]['name']);
        $this->assertNull($received[0]['memo']);
    }

    // ---- UPDATE ----

    public function testBeforeUpdateModifiesNewRow(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);
        $connection->table('users')->insert(['name' => 'a', 'memo' => 'old']);

        // BEFORE UPDATE 返回行作为最终新行：可修改用户未提供的列
        $connection->createTrigger('users', 'before', 'update', static function (array $old, array $new): array {
            $new['memo'] = 'by-trigger';

            return $new;
        });

        $connection->table('users')->where('id', 1)->update(['name' => 'a2']);

        $this->assertSame(
            [['id' => 1, 'name' => 'a2', 'memo' => 'by-trigger']],
            $connection->engine()->readRows('main', 'users')
        );
    }

    public function testBeforeUpdateSeesMergedNewRow(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);
        $connection->table('users')->insert(['name' => 'a', 'memo' => 'old']);

        $seen = null;
        $connection->createTrigger('users', 'before', 'update', static function (array $old, array $new) use (&$seen): array {
            $seen = $new;

            return $new;
        });

        // 只更新 name：new 应为合并后整行（memo 保留 old 值）
        $connection->table('users')->where('id', 1)->update(['name' => 'a2']);

        $this->assertSame('old', $seen['memo']);
        $this->assertSame('a2', $seen['name']);
        $this->assertSame(1, $seen['id']);
    }

    public function testAfterUpdateReceivesOldAndNew(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);
        $connection->table('users')->insert(['name' => 'a', 'memo' => 'old']);

        $pairs = [];
        $connection->createTrigger('users', 'after', 'update', static function (array $old, array $new) use (&$pairs): void {
            $pairs[] = [$old, $new];
        });

        $connection->table('users')->where('id', 1)->update(['name' => 'a2']);

        $this->assertCount(1, $pairs);
        [$old, $new] = $pairs[0];
        $this->assertSame('a', $old['name']);
        $this->assertSame('a2', $new['name']);
        $this->assertSame('old', $new['memo']);
    }

    public function testAfterUpdateFiresPerMatchedRow(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);
        $connection->table('users')->insertMany([['name' => 'a'], ['name' => 'b'], ['name' => 'c']]);

        $count = 0;
        $connection->createTrigger('users', 'after', 'update', static function () use (&$count): void {
            ++$count;
        });

        $affected = $connection->table('users')->where('id', '<=', 2)->update(['memo' => 'touched']);
        $this->assertSame(2, $affected);
        $this->assertSame(2, $count);
    }

    // ---- DELETE ----

    public function testBeforeDeleteBlocksDeletion(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);
        $connection->table('users')->insertMany([['name' => 'a'], ['name' => 'b']]);

        $connection->createTrigger('users', 'before', 'delete', static function (array $row): void {
            if ($row['name'] === 'a') {
                throw new RuntimeException('禁止删除 a');
            }
        });

        try {
            $connection->table('users')->delete();
            $this->fail('BEFORE DELETE 抛异常应拦截删除');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        // 整批失败：任何行都未删除
        $this->assertCount(2, $connection->engine()->readRows('main', 'users'));
    }

    public function testAfterDeleteReceivesDeletedRows(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);
        $connection->table('users')->insertMany([['name' => 'a'], ['name' => 'b']]);

        $received = [];
        $connection->createTrigger('users', 'after', 'delete', static function (array $row) use (&$received): void {
            $received[] = $row;
        });

        $connection->table('users')->where('id', 2)->delete();

        $this->assertSame([['id' => 2, 'name' => 'b', 'memo' => null]], $received);
        $this->assertCount(1, $connection->engine()->readRows('main', 'users'));
    }

    // ---- 顺序与移除 ----

    public function testTriggersExecuteInRegistrationOrder(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);

        $order = [];
        $connection->createTrigger('users', 'before', 'insert', static function (array $row) use (&$order): array {
            $order[] = 'first';

            return $row;
        });
        $connection->createTrigger('users', 'before', 'insert', static function (array $row) use (&$order): array {
            $order[] = 'second';

            return $row;
        });

        $connection->table('users')->insert(['name' => 'a']);
        $this->assertSame(['first', 'second'], $order);
    }

    public function testChainedBeforeInsertTriggersTransformRow(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);

        // 前一个触发器的输出是后一个的输入
        $connection->createTrigger('users', 'before', 'insert', static function (array $row): array {
            $row['name'] .= '-1';

            return $row;
        });
        $connection->createTrigger('users', 'before', 'insert', static function (array $row): array {
            $row['name'] .= '-2';

            return $row;
        });

        $connection->table('users')->insert(['name' => 'a']);
        $this->assertSame('a-1-2', $connection->engine()->readRows('main', 'users')[0]['name']);
    }

    public function testDropTriggerStopsFiring(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);

        $count = 0;
        $counter = static function () use (&$count): void {
            ++$count;
        };
        $trigger = $connection->createTrigger('users', 'after', 'insert', $counter);
        $connection->table('users')->insert(['name' => 'a']);
        $this->assertSame(1, $count);

        $connection->dropTrigger($trigger);
        $connection->table('users')->insert(['name' => 'b']);
        $this->assertSame(1, $count);

        // 重复移除抛 QueryException
        try {
            $connection->dropTrigger($trigger);
            $this->fail('重复 dropTrigger 应抛 QueryException');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testSameHandlerRegisteredTwiceAsIndependentTriggers(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);

        $count = 0;
        $counter = static function () use (&$count): void {
            ++$count;
        };
        $first = $connection->createTrigger('users', 'after', 'insert', $counter);
        $second = $connection->createTrigger('users', 'after', 'insert', $counter);

        $connection->table('users')->insert(['name' => 'a']);
        $this->assertSame(2, $count);

        $connection->dropTrigger($first);
        $connection->table('users')->insert(['name' => 'b']);
        $this->assertSame(3, $count);
        $connection->dropTrigger($second);
        $connection->table('users')->insert(['name' => 'c']);
        $this->assertSame(3, $count);
    }

    // ---- 级联删除 ----

    public function testCascadeDeleteFiresChildDeleteTriggers(): void
    {
        $connection = $this->makeConnection();
        $this->createUsersAndOrders($connection, 'cascade');

        $childBefore = [];
        $childAfter = [];
        $parentBefore = [];
        $connection->createTrigger('users', 'before', 'delete', static function (array $row) use (&$parentBefore): void {
            $parentBefore[] = $row['id'];
        });
        $connection->createTrigger('orders', 'before', 'delete', static function (array $row) use (&$childBefore): void {
            $childBefore[] = $row['id'];
        });
        $connection->createTrigger('orders', 'after', 'delete', static function (array $row) use (&$childAfter): void {
            $childAfter[] = $row['id'];
        });

        $deleted = $connection->table('users')->where('id', 1)->delete();
        $this->assertSame(1, $deleted);

        // 级联删除的两条 orders 触发其表的 BEFORE/AFTER DELETE；父表触发自身
        $this->assertSame([1], $parentBefore);
        $this->assertSame([1, 2], $childBefore);
        $this->assertSame([1, 2], $childAfter);
        $this->assertSame([], $connection->engine()->readRows('main', 'orders'));
    }

    public function testCascadeChildBeforeDeleteCanAbortWholeDelete(): void
    {
        $connection = $this->makeConnection();
        $this->createUsersAndOrders($connection, 'cascade');

        $connection->createTrigger('orders', 'before', 'delete', static function (array $row): void {
            if ($row['id'] === 2) {
                throw new RuntimeException('禁止级联删除 order 2');
            }
        });

        try {
            $connection->table('users')->where('id', 1)->delete();
            $this->fail('子表 BEFORE DELETE 拦截应使整次删除失败');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        // 全部回退：users 与 orders 均未删除
        $this->assertCount(2, $connection->engine()->readRows('main', 'users'));
        $this->assertCount(2, $connection->engine()->readRows('main', 'orders'));
    }

    public function testSelfReferencingCascadeFiresDeleteTriggers(): void
    {
        $connection = $this->makeConnection();
        $connection->createTable('nodes', static function (Blueprint $table): void {
            $table->id();
            $table->bigint('parent_id');
            $table->foreignKey('parent_id')->references('nodes', 'id')->onDeleteCascade();
        });
        $connection->table('nodes')->insert(['parent_id' => null]);
        // 逐条插入：批内 insertMany 的外键校验看不到同批先行行
        $connection->table('nodes')->insert(['parent_id' => 1]);
        $connection->table('nodes')->insert(['parent_id' => 2]);

        $deleted = [];
        $connection->createTrigger('nodes', 'after', 'delete', static function (array $row) use (&$deleted): void {
            $deleted[] = $row['id'];
        });

        $connection->table('nodes')->where('id', 1)->delete();

        // 自引用级联：1 → 2 → 3 全链删除，逐行触发
        $this->assertSame([1, 2, 3], $deleted);
        $this->assertSame([], $connection->engine()->readRows('main', 'nodes'));
    }

    public function testSetNullDoesNotFireDeleteTriggerOnReferencingRow(): void
    {
        $connection = $this->makeConnection();
        $this->createUsersAndOrders($connection, 'set_null');

        $orderDeletes = [];
        $userDeletes = [];
        $connection->createTrigger('orders', 'after', 'delete', static function (array $row) use (&$orderDeletes): void {
            $orderDeletes[] = $row['id'];
        });
        $connection->createTrigger('users', 'after', 'delete', static function (array $row) use (&$userDeletes): void {
            $userDeletes[] = $row['id'];
        });

        $connection->table('users')->where('id', 1)->delete();

        // SET_NULL 引用方行不触发 DELETE；被删父行触发
        $this->assertSame([], $orderDeletes);
        $this->assertSame([1], $userDeletes);
        // 两行 orders 均保留，仅引用列置空
        $this->assertSame(
            [
                ['id' => 1, 'user_id' => null, 'memo' => 'o1'],
                ['id' => 2, 'user_id' => null, 'memo' => 'o2'],
            ],
            $connection->engine()->readRows('main', 'orders')
        );
    }

    // ---- upsert / insertIgnore ----

    public function testUpsertInsertPathFiresInsertTriggers(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);

        $events = [];
        $connection->createTrigger('users', 'before', 'insert', static function (array $row) use (&$events): array {
            $events[] = 'before-insert';

            return $row;
        });
        $connection->createTrigger('users', 'after', 'insert', static function () use (&$events): void {
            $events[] = 'after-insert';
        });
        $connection->createTrigger('users', 'before', 'update', static function (array $old, array $new) use (&$events): array {
            $events[] = 'before-update';

            return $new;
        });
        $connection->createTrigger('users', 'after', 'update', static function () use (&$events): void {
            $events[] = 'after-update';
        });

        // 无冲突：走 INSERT 系列
        $connection->table('users')->upsert(['name' => 'a']);
        $this->assertSame(['before-insert', 'after-insert'], $events);
    }

    public function testUpsertConflictPathFiresUpdateTriggers(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);
        $connection->table('users')->insert(['name' => 'a', 'memo' => 'old']);

        $events = [];
        $oldSeen = null;
        $newSeen = null;
        $connection->createTrigger('users', 'before', 'insert', static function (array $row) use (&$events): array {
            $events[] = 'before-insert';

            return $row;
        });
        $connection->createTrigger('users', 'before', 'update', static function (array $old, array $new) use (&$events, &$oldSeen, &$newSeen): array {
            $events[] = 'before-update';
            $oldSeen = $old;
            $newSeen = $new;

            return $new;
        });
        $connection->createTrigger('users', 'after', 'update', static function (array $old, array $new) use (&$events): void {
            $events[] = 'after-update';
        });

        // 命中唯一约束（name 重复）：走 UPDATE 系列，不触发 INSERT
        $result = $connection->table('users')->upsert(['name' => 'a', 'memo' => 'new']);
        $this->assertSame(2, $result);
        $this->assertSame(['before-update', 'after-update'], $events);
        $this->assertSame('old', $oldSeen['memo']);
        $this->assertSame('new', $newSeen['memo']);
    }

    public function testInsertIgnoreSkippedRowFiresNothingWrittenRowFires(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);
        $connection->table('users')->insert(['name' => 'a']);

        $events = [];
        $connection->createTrigger('users', 'before', 'insert', static function (array $row) use (&$events): array {
            $events[] = 'before-insert';

            return $row;
        });
        $connection->createTrigger('users', 'after', 'insert', static function () use (&$events): void {
            $events[] = 'after-insert';
        });

        // 冲突行静默跳过：不触发任何触发器
        $result = $connection->table('users')->insertIgnore(['name' => 'a']);
        $this->assertSame(0, $result);
        $this->assertSame([], $events);

        // 无冲突行正常写入：触发完整 INSERT 系列
        $result = $connection->table('users')->insertIgnore(['name' => 'b']);
        $this->assertSame(1, $result);
        $this->assertSame(['before-insert', 'after-insert'], $events);
    }

    // ---- 异常与校验 ----

    public function testTriggerExceptionPropagatesAsIs(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);

        $marker = new RuntimeException('业务异常');
        $connection->createTrigger('users', 'before', 'insert', static function () use ($marker): array {
            throw $marker;
        });

        try {
            $connection->table('users')->insert(['name' => 'a']);
            $this->fail('触发器内异常应原样上抛');
        } catch (RuntimeException $caught) {
            $this->assertSame($marker, $caught);
        }
    }

    public function testBeforeTriggerReturningNonArrayThrowsQueryException(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);

        $connection->createTrigger('users', 'before', 'insert', static function (array $row) {
            return 'not-an-array';
        });

        try {
            $connection->table('users')->insert(['name' => 'a']);
            $this->fail('BEFORE 触发器返回非数组应抛 QueryException');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([], $connection->engine()->readRows('main', 'users'));
    }

    public function testBeforeUpdateTriggerReturningNonArrayThrowsQueryException(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);
        $connection->table('users')->insert(['name' => 'a']);

        $connection->createTrigger('users', 'before', 'update', static function (array $old, array $new) {
            return null;
        });

        try {
            $connection->table('users')->where('id', 1)->update(['name' => 'b']);
            $this->fail('BEFORE UPDATE 触发器返回非数组应抛 QueryException');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testCreateTriggerValidatesArguments(): void
    {
        $connection = $this->makeConnection();
        $handler = static function (): void {
        };

        // 表不存在
        try {
            $connection->createTrigger('missing', 'before', 'insert', $handler);
            $this->fail('表不存在时 createTrigger 应抛 QueryException');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // 非法 timing / event
        $this->createUsers($connection);
        foreach ([['sometimes', 'insert'], ['before', 'truncate'], ['after', 'select']] as [$timing, $event]) {
            try {
                $connection->createTrigger('users', $timing, $event, $handler);
                $this->fail("非法 timing/event（{$timing}/{$event}）应抛 QueryException");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- 事务与行为不变 ----

    public function testTriggerInsideTransaction(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);

        $afterCount = 0;
        $connection->createTrigger('users', 'after', 'insert', static function () use (&$afterCount): void {
            ++$afterCount;
        });

        $connection->begin();
        $connection->table('users')->insert(['name' => 'a']);
        $this->assertSame(1, $afterCount);
        $connection->rollBack();

        // 行为随事务回滚消失；AFTER 已触发过的事实不撤销（文档化语义）
        $this->assertSame([], $connection->engine()->readRows('main', 'users'));
        $this->assertSame(1, $afterCount);
    }

    public function testNoTriggersKeepsExistingWriteBehavior(): void
    {
        // 无触发器时零行为变化：对照插入/更新/删除结果
        $connection = $this->makeConnection();
        $this->createUsers($connection);

        $result = $connection->table('users')->insertMany([['name' => 'a'], ['name' => 'b']]);
        $this->assertSame(2, $result->rowCount());
        $this->assertSame(2, $result->lastInsertId());
        $this->assertSame(
            [['id' => 1, 'name' => 'a', 'memo' => null], ['id' => 2, 'name' => 'b', 'memo' => null]],
            $connection->engine()->readRows('main', 'users')
        );

        $connection->table('users')->where('id', 1)->update(['name' => 'a2']);
        $this->assertSame(
            [['id' => 1, 'name' => 'a2', 'memo' => null], ['id' => 2, 'name' => 'b', 'memo' => null]],
            $connection->engine()->readRows('main', 'users')
        );

        $connection->table('users')->where('id', 2)->delete();
        $this->assertSame(
            [['id' => 1, 'name' => 'a2', 'memo' => null]],
            $connection->engine()->readRows('main', 'users')
        );

        // 触发器管理器未被显式使用时不影响事务
        $connection->begin();
        $connection->table('users')->insert(['name' => 'c']);
        $connection->commit();
        $this->assertCount(2, $connection->engine()->readRows('main', 'users'));
    }

    public function testTriggerHandleExposesMetadata(): void
    {
        $connection = $this->makeConnection();
        $this->createUsers($connection);

        $trigger = $connection->createTrigger('users', 'BEFORE', 'Insert', static function (array $row): array {
            return $row;
        });

        // timing/event 归一化为小写
        $this->assertInstanceOf(Trigger::class, $trigger);
        $this->assertSame('users', $trigger->table);
        $this->assertSame('before', $trigger->timing);
        $this->assertSame('insert', $trigger->event);
    }

    // ---- 辅助 ----

    private function makeConnection(): Connection
    {
        return Psql::memory();
    }

    private function createUsers(Connection $connection): void
    {
        $connection->createTable('users', static function (Blueprint $table): void {
            $table->id();
            // unique 供 upsert/insertIgnore 冲突检测测试使用
            $table->varchar('name', 50)->notNull()->unique();
            $table->varchar('memo', 50);
        });
    }

    /**
     * users(1=a 被引用, 2=b 未引用) + orders 引用 user 1（onDelete 按参数）
     */
    private function createUsersAndOrders(Connection $connection, string $onDelete): void
    {
        $this->createUsers($connection);
        $connection->createTable('orders', static function (Blueprint $table) use ($onDelete): void {
            $table->id();
            // SET_NULL 要求引用列可空：cascade 时 notNull，否则默认可空
            $userColumn = $table->bigint('user_id');
            if ($onDelete === 'cascade') {
                $userColumn->notNull();
            }
            $definition = $table->foreignKey('user_id')->references('users', 'id');
            if ($onDelete === 'cascade') {
                $definition->onDeleteCascade();
            } else {
                $definition->onDelete(ForeignKeyAction::SET_NULL);
            }
            $table->varchar('memo', 30)->notNull();
        });
        $connection->table('users')->insertMany([['name' => 'a'], ['name' => 'b']]);
        $connection->table('orders')->insertMany([
            ['user_id' => 1, 'memo' => 'o1'],
            ['user_id' => 1, 'memo' => 'o2'],
        ]);
    }
}
