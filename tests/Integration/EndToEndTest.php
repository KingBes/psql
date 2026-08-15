<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Integration;

use Kingbes\Psql\Exception\ConstraintException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Query\Agg;
use Kingbes\Psql\Query\Condition\Comparison;
use Kingbes\Psql\Query\Condition\ConditionGroup;
use Kingbes\Psql\Query\SelectBuilder;
use Kingbes\Psql\Schema\AlterBlueprint;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 端到端集成测试：临时目录 JsonFileEngine 上的完整业务场景
 */
final class EndToEndTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psql-e2e-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->removeDirRecursive($this->root);
        }
    }

    public function testCompleteBusinessWorkflow(): void
    {
        // 1. 建表：student + score（外键级联删除）
        $connection = Psql::connect($this->root);
        $connection->createTable('student', static function (Blueprint $table): void {
            $table->id();
            $table->varchar('name', 50)->notNull()->unique();
            $table->tinyint('age')->unsigned()->default(0);
            $table->enum('gender', ['male', 'female']);
            $table->decimal('balance', 10, 2)->default(0);
            $table->datetime('created_at')->defaultNow();
        });
        $connection->createTable('score', static function (Blueprint $table): void {
            $table->id();
            $table->int('student_id')->notNull();
            $table->foreignKey('student_id')->references('student', 'id')->onDeleteCascade();
            $table->varchar('subject', 30)->notNull();
            $table->tinyint('mark')->unsigned();
        });
        $this->assertTrue($connection->hasTable('student'));
        $this->assertTrue($connection->hasTable('score'));

        // 2. 事务内批量插入学生与成绩 → commit；lastInsertId 连续正确
        $connection->begin();
        $this->assertTrue($connection->inTransaction());

        $students = [
            ['name' => 'Alice', 'age' => 20, 'gender' => 'female', 'balance' => 100.5],
            ['name' => 'Bob', 'age' => 17, 'gender' => 'male'],
            ['name' => 'Carol', 'age' => 22, 'gender' => 'female', 'balance' => '88.80'],
            ['name' => 'Dave', 'age' => 19, 'gender' => 'male', 'balance' => 30],
        ];
        $expectedStudentId = 0;
        foreach ($students as $student) {
            $result = $connection->table('student')->insert($student);
            $this->assertSame(++$expectedStudentId, $result->lastInsertId());
        }

        $scores = [
            [1, 'math', 85], [1, 'english', 72],
            [2, 'math', 45],
            [3, 'math', 95], [3, 'english', 68],
            [4, 'math', 55], [4, 'english', 60],
        ];
        $expectedScoreId = 0;
        foreach ($scores as [$studentId, $subject, $mark]) {
            $result = $connection->table('score')->insert([
                'student_id' => $studentId,
                'subject' => $subject,
                'mark' => $mark,
            ]);
            $this->assertSame(++$expectedScoreId, $result->lastInsertId());
        }

        $connection->commit();
        $this->assertFalse($connection->inTransaction());
        $this->assertSame(4, $connection->table('student')->count());
        $this->assertSame(7, $connection->table('score')->count());
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $connection->table('student')->first()['created_at']
        );

        // 3. 综合查询：JOIN + 聚合 + 分组 + HAVING + 排序
        $rows = $connection->table('student as s')
            ->select('s.id', 's.name', Agg::avg('sc.mark')->as('avg_mark'))
            ->leftJoin('score as sc', 's.id', '=', 'sc.student_id')
            ->where('s.age', '>=', 18)
            ->groupBy('s.id', 's.name')
            ->having('avg_mark', '>=', 60)
            ->orderBy('avg_mark', 'DESC')
            ->get()
            ->rows();
        // Bob 17 岁被 WHERE 排除；Dave 均分 57.5 被 HAVING 排除；Carol 81.5 > Alice 78.5
        $this->assertSame([
            ['id' => 3, 'name' => 'Carol', 'avg_mark' => 81.5],
            ['id' => 1, 'name' => 'Alice', 'avg_mark' => 78.5],
        ], $rows);

        // 4. 嵌套 where 分组（等价 ConditionGroup 组合，OR 逻辑）：
        //    (gender = male AND age >= 18) OR balance >= 80
        $nested = (new ConditionGroup())->where('gender', 'male')->where('age', '>=', 18);
        $outer = new ConditionGroup();
        $outer->add($nested);
        $outer->add(new Comparison('balance', '>=', 80), 'OR');
        $builder = $connection->table('student')->select('id', 'name');
        $this->injectWhere($builder, $outer);
        $this->assertSame(
            [1, 3, 4],
            array_column($builder->get()->rows(), 'id'),
            '嵌套 OR 条件应命中 Alice(balance)、Carol(balance)、Dave(male&>=18)'
        );

        // 5. 约束：重名学生抛异常；删除被级联引用的学生
        try {
            $connection->table('student')->insert([
                'name' => 'Alice', 'age' => 30, 'gender' => 'female',
            ]);
            $this->fail('重名学生应触发唯一约束冲突');
        } catch (ConstraintException $e) {
            $this->assertStringContainsString('name', $e->getMessage());
        }
        $this->assertSame(4, $connection->table('student')->count());

        $deleted = $connection->table('student')->where('name', 'Alice')->delete();
        $this->assertSame(1, $deleted);
        // Alice 的 2 条成绩被级联删除
        $this->assertSame(5, $connection->table('score')->count());
        $this->assertSame(3, $connection->table('score')->where('subject', 'math')->count());

        // 6. alterTable：加列默认值回填 + 列重命名后旧数据可查
        $connection->alterTable('student', static function (AlterBlueprint $table): void {
            $table->tinyint('credit')->unsigned()->notNull()->default(0);
        });
        $connection->alterTable('score', static function (AlterBlueprint $table): void {
            $table->renameColumn('subject', 'lesson');
        });
        foreach ($connection->table('student')->get()->rows() as $row) {
            $this->assertSame(0, $row['credit']);
        }
        $this->assertSame(
            [45, 55, 60, 68, 95],
            $connection->table('score')->where('lesson', '!=', '')->orderBy('mark')->get()->pluck('mark')
        );

        // 7. use 切换新数据库 → 建表写入 → 切回 main 数据仍在
        $connection->createDatabase('archive');
        $connection->use('archive');
        $this->assertSame('archive', $connection->currentDatabase());
        $connection->createTable('logs', static function (Blueprint $table): void {
            $table->id();
            $table->varchar('message', 100)->notNull();
        });
        $connection->table('logs')->insert(['message' => 'first']);
        $connection->use('main');
        $this->assertSame('main', $connection->currentDatabase());
        $this->assertSame(3, $connection->table('student')->count());
        $this->assertSame(5, $connection->table('score')->count());

        // 8. 重开连接：数据、结构、自增计数完整
        $studentCountBefore = $connection->table('student')->count();
        $scoreCountBefore = $connection->table('score')->count();
        unset($connection);
        $connection = Psql::connect($this->root);

        $this->assertSame($studentCountBefore, $connection->table('student')->count());
        $this->assertSame($scoreCountBefore, $connection->table('score')->count());
        $this->assertSame(['id', 'name', 'age', 'gender', 'balance', 'created_at', 'credit'],
            array_map(
                static fn ($column): string => $column->name,
                $connection->engine()->loadSchema('main', 'student')->columns
            )
        );
        $this->assertSame(['id', 'student_id', 'lesson', 'mark'],
            array_map(
                static fn ($column): string => $column->name,
                $connection->engine()->loadSchema('main', 'score')->columns
            )
        );
        $this->assertTrue($connection->hasDatabase('archive'));
        $this->assertSame(4, $connection->engine()->autoIncrement('main', 'student'));
        // 自增继续推进，不与现存 id 冲突
        $result = $connection->table('student')->insert([
            'name' => 'Eve', 'age' => 21, 'gender' => 'female',
        ]);
        $this->assertSame(5, $result->lastInsertId());

        // 9. truncate 后自增从 1 重新开始
        $connection->table('score')->truncate();
        $this->assertSame(0, $connection->table('score')->count());
        $this->assertSame(0, $connection->engine()->autoIncrement('main', 'score'));
        $result = $connection->table('score')->insert([
            'student_id' => 2, 'lesson' => 'math', 'mark' => 60,
        ]);
        $this->assertSame(1, $result->lastInsertId());

        // 10. update/delete 返回受影响行数
        $affected = $connection->table('student')->where('gender', 'male')->update(['balance' => 50]);
        $this->assertSame(2, $affected, 'Bob 与 Dave 为 male');
        $this->assertSame(0, $connection->table('student')->where('age', '>', 100)->update(['balance' => 1]));
        $this->assertSame('50.00', (string) $connection->table('student')->find(2)['balance']);

        $affected = $connection->table('student')->where('age', '<', 18)->delete();
        $this->assertSame(1, $affected, '删除 17 岁的 Bob');
        // Bob 的成绩（score.student_id 外键 onDeleteCascade）随之级联删除
        $this->assertSame(0, $connection->table('score')->count());
        $this->assertSame(3, $connection->table('student')->count());
    }

    /**
     * 将组合好的条件组注入构建器（SelectBuilder 未公开嵌套分组入口，测试用反射）
     */
    private function injectWhere(SelectBuilder $builder, ConditionGroup $group): void
    {
        $property = new \ReflectionProperty(SelectBuilder::class, 'where');
        $property->setValue($builder, $group);
    }

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
