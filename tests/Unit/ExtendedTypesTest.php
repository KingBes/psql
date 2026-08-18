<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit;

use Kingbes\Psql\Connection;
use Kingbes\Psql\Exception\TypeException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 扩展类型测试：JSON / BLOB / BINARY / SET 的建表、写入规范化、读取与校验失败
 */
final class ExtendedTypesTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $this->conn = Psql::memory();
        $this->conn->createTable('assets', static function (Blueprint $b): void {
            $b->id();
            $b->json('meta');
            $b->blob('payload');
            $b->binary('signature', 16);
            $b->set('tags', ['a', 'b', 'c']);
        });
    }

    // ---- JSON ----

    public function testJsonStoresAndReadsArray(): void
    {
        $this->conn->table('assets')->insert([
            'meta' => ['name' => 'doc', 'size' => 1024, 'ok' => true],
        ]);

        $row = $this->conn->table('assets')->first();

        $this->assertSame(['name' => 'doc', 'size' => 1024, 'ok' => true], $row['meta']);
    }

    public function testJsonAcceptsValidJsonString(): void
    {
        $this->conn->table('assets')->insert([
            'meta' => '{"name":"doc","size":1024}',
        ]);

        $this->assertSame(['name' => 'doc', 'size' => 1024], $this->conn->table('assets')->first()['meta']);
    }

    public function testJsonRejectsInvalidString(): void
    {
        $this->expectException(TypeException::class);
        $this->expectExceptionMessage('JSON');

        $this->conn->table('assets')->insert(['meta' => 'not-json{']);
    }

    public function testJsonRejectsUnencodableValue(): void
    {
        $this->expectException(TypeException::class);
        $this->expectExceptionMessage('JSON');

        $this->conn->table('assets')->insert(['meta' => [fopen('php://memory', 'r')]]);
    }

    public function testJsonPersistsAcrossFileConnections(): void
    {
        $root = sys_get_temp_dir() . '/psql-exttypes-' . uniqid();
        try {
            $file = Psql::connect($root);
            $file->createTable('t', static function (Blueprint $b): void {
                $b->id();
                $b->json('meta');
            });
            $file->table('t')->insert(['meta' => ['a' => 1, 'b' => [2, 3]]]);

            $reopened = Psql::connect($root);

            $this->assertSame(['a' => 1, 'b' => [2, 3]], $reopened->table('t')->first()['meta']);
        } finally {
            $this->removeDir($root);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    // ---- BLOB / BINARY ----

    public function testBlobStoresBinaryData(): void
    {
        $bytes = "\x00\x01\x02\xFF" . random_bytes(64);

        $this->conn->table('assets')->insert(['payload' => $bytes]);

        $this->assertSame($bytes, $this->conn->table('assets')->first()['payload']);
    }

    public function testBinaryLengthEnforced(): void
    {
        $this->expectException(TypeException::class);
        $this->expectExceptionMessage('字节长度');

        $this->conn->table('assets')->insert(['signature' => str_repeat('x', 17)]);
    }

    public function testBinaryAcceptsExactLength(): void
    {
        $this->conn->table('assets')->insert(['signature' => str_repeat('x', 16)]);

        $this->assertSame(str_repeat('x', 16), $this->conn->table('assets')->first()['signature']);
    }

    // ---- SET ----

    public function testSetAcceptsArray(): void
    {
        $this->conn->table('assets')->insert(['tags' => ['a', 'c']]);

        $this->assertSame('a,c', $this->conn->table('assets')->first()['tags']);
    }

    public function testSetAcceptsCsvStringAndDeduplicates(): void
    {
        $this->conn->table('assets')->insert(['tags' => 'b,a,b']);

        $this->assertSame('b,a', $this->conn->table('assets')->first()['tags']);
    }

    public function testSetEmptyMeansEmptySet(): void
    {
        $this->conn->table('assets')->insert(['tags' => []]);

        $this->assertSame('', $this->conn->table('assets')->first()['tags']);
    }

    public function testSetRejectsUnknownMember(): void
    {
        $this->expectException(TypeException::class);
        $this->expectExceptionMessage('SET 成员');

        $this->conn->table('assets')->insert(['tags' => ['a', 'zzz']]);
    }

    // ---- ci 支持面 ----

    public function testCiAllowedForSetAndBinaryRejectedForJson(): void
    {
        $this->conn->createTable('ci_t', static function (Blueprint $b): void {
            $b->set('s', ['x', 'y'])->ci();
            $b->binary('bin', 8)->ci();
        });

        $this->expectException(\Kingbes\Psql\Exception\SchemaException::class);
        $this->conn->createTable('ci_t2', static function (Blueprint $b): void {
            $b->json('j')->ci();
        });
    }
}
