<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Storage;

use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\Blueprint;
use Kingbes\Psql\Schema\ColumnSchema;
use Kingbes\Psql\Schema\DataType;
use Kingbes\Psql\Schema\TableSchema;
use Kingbes\Psql\Storage\JsonFileEngine;
use Kingbes\Psql\Storage\MemoryEngine;
use Kingbes\Psql\Storage\StorageEngine;
use PHPUnit\Framework\TestCase;

/**
 * resetAutoIncrement 双引擎行为测试
 */
final class AutoIncrementResetTest extends TestCase
{
    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            $this->removeDir($this->tempDir);
            $this->tempDir = null;
        }
    }

    public function testMemoryEngineReset(): void
    {
        $this->exerciseReset(new MemoryEngine());
    }

    public function testJsonFileEngineResetAndPersistence(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/psql_reset_' . uniqid('', true);
        $engine = new JsonFileEngine($this->tempDir);
        $this->exerciseReset($engine);

        // 归零后落盘：新引擎实例从磁盘读到 ai 为 0
        $fresh = new JsonFileEngine($this->tempDir);
        $this->assertSame(0, $fresh->autoIncrement('db', 't'));
    }

    public function testResetMissingTableThrows(): void
    {
        $engine = new MemoryEngine();
        $engine->createDatabase('db');

        $this->expectException(StorageException::class);
        $engine->resetAutoIncrement('db', 'ghost');
    }

    public function testTruncateResetsAndReassignsFromOne(): void
    {
        $conn = Psql::memory();
        $conn->createTable('t', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 8);
        });
        $conn->table('t')->insertMany([['name' => 'a'], ['name' => 'b'], ['name' => 'c']]);
        $this->assertSame(3, $conn->table('t')->count());

        $conn->table('t')->truncate();

        $this->assertSame(0, $conn->table('t')->count());
        // 归零后重新从 1 分配
        $this->assertSame(1, $conn->table('t')->insert(['name' => 'x'])->lastInsertId());
    }

    /**
     * 双引擎共用的 reset 行为：归零 → 可从 1 重新递增 → 无自增列表 no-op
     */
    private function exerciseReset(StorageEngine $engine): void
    {
        $engine->createDatabase('db');
        $engine->createTable('db', $this->aiSchema('t'));
        $engine->setAutoIncrement('db', 't', 5);
        $this->assertSame(5, $engine->autoIncrement('db', 't'));

        $engine->resetAutoIncrement('db', 't');
        $this->assertSame(0, $engine->autoIncrement('db', 't'));

        // 只增不减规则不变：归零后可重新设为 1
        $engine->setAutoIncrement('db', 't', 1);
        $this->assertSame(1, $engine->autoIncrement('db', 't'));

        // 无自增列的表调用为 no-op
        $engine->createTable('db', $this->plainSchema('p'));
        $engine->resetAutoIncrement('db', 'p');
        $this->assertSame(0, $engine->autoIncrement('db', 'p'));

        // 重新归零（供持久化验证）
        $engine->resetAutoIncrement('db', 't');
        $this->assertSame(0, $engine->autoIncrement('db', 't'));
    }

    private function aiSchema(string $name): TableSchema
    {
        return new TableSchema($name, [
            new ColumnSchema(name: 'id', type: DataType::BIGINT, unsigned: true, primaryKey: true, autoIncrement: true),
            new ColumnSchema(name: 'v', type: DataType::INT),
        ]);
    }

    private function plainSchema(string $name): TableSchema
    {
        return new TableSchema($name, [
            new ColumnSchema(name: 'id', type: DataType::BIGINT, unsigned: true, primaryKey: true),
            new ColumnSchema(name: 'v', type: DataType::INT),
        ]);
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
