<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Storage;

use Kingbes\Psql\Storage\MemoryEngine;
use Kingbes\Psql\Storage\StorageEngine;

require_once __DIR__ . '/StorageEngineContractTestCase.php';

/**
 * MemoryEngine 契约测试
 */
final class MemoryEngineTest extends StorageEngineContractTestCase
{
    protected function createEngine(): StorageEngine
    {
        return new MemoryEngine();
    }
}
