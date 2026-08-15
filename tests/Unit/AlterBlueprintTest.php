<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit;

use Kingbes\Psql\Schema\AlterBlueprint;
use PHPUnit\Framework\TestCase;

/**
 * AlterBlueprint 记录 drop/rename 操作测试
 */
final class AlterBlueprintTest extends TestCase
{
    public function testRecordsDroppedColumns(): void
    {
        $blueprint = new AlterBlueprint();
        $this->assertSame([], $blueprint->droppedColumns());

        $blueprint->dropColumn('a');
        $blueprint->dropColumn('b');

        $this->assertSame(['a', 'b'], $blueprint->droppedColumns());
    }

    public function testRecordsRenamedColumns(): void
    {
        $blueprint = new AlterBlueprint();
        $this->assertSame([], $blueprint->renamedColumns());

        $blueprint->renameColumn('a', 'alpha');
        $blueprint->renameColumn('b', 'beta');

        $this->assertSame(['a' => 'alpha', 'b' => 'beta'], $blueprint->renamedColumns());
    }

    public function testLaterRenameOverwritesEarlierOne(): void
    {
        $blueprint = new AlterBlueprint();
        $blueprint->renameColumn('a', 'tmp');
        $blueprint->renameColumn('a', 'alpha');

        $this->assertSame(['a' => 'alpha'], $blueprint->renamedColumns());
    }

    public function testMixedOperationsRecordedIndependently(): void
    {
        $blueprint = new AlterBlueprint();
        $blueprint->dropColumn('legacy');
        $blueprint->renameColumn('old_name', 'new_name');
        // 同名列不冲突：drop 记录 drop，rename 记录 rename
        $blueprint->dropColumn('old_name');

        $this->assertSame(['legacy', 'old_name'], $blueprint->droppedColumns());
        $this->assertSame(['old_name' => 'new_name'], $blueprint->renamedColumns());
    }

    public function testInheritsColumnDefinitionFromBlueprint(): void
    {
        $blueprint = new AlterBlueprint();
        $blueprint->varchar('extra', 50)->notNull();

        $schema = $blueprint->toSchema('t');

        $this->assertTrue($schema->hasColumn('extra'));
        $this->assertTrue($schema->columnOrFail('extra')->notNull);
    }
}
