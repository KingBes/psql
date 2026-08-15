<?php

declare(strict_types=1);

namespace Kingbes\Psql\Storage;

/**
 * 引擎全量状态快照：payload 为 serialize 后的引擎状态
 */
final readonly class EngineSnapshot
{
    public function __construct(public string $payload)
    {
    }
}
