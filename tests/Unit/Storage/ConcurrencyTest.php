<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Storage;

use Kingbes\Psql\Psql;
use Kingbes\Psql\Storage\DirectoryLock;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 单 writer 多 reader 并发测试（多进程，经 proc_open 真实子进程）：
 * - 多 reader 跨进程并存（共享锁）
 * - 写进程写入后长驻 reader 进程经 .wv 缓存失效看到新数据
 * - 共享/排他锁冲突语义（非阻塞探测）
 */
final class ConcurrencyTest extends TestCase
{
    private string $dir;

    /** 子进程脚本路径 */
    private string $helperFile;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/psql-conc-' . uniqid('', true);
        mkdir($this->dir, 0777, true);

        // 子进程脚本：mode=read 打开并发连接读表并输出行数；mode=write 打开并发连接插入一行
        $this->helperFile = (string) tempnam(sys_get_temp_dir(), 'psql-conc-helper-');
        file_put_contents($this->helperFile, <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
$dir = (string) $argv[2];
$mode = (string) $argv[3];
$name = (string) ($argv[4] ?? 'carol');

try {
    $db = \Kingbes\Psql\Psql::connect($dir, ['concurrency' => true]);
    $db->use('db');
    if ($mode === 'write') {
        $db->table('users')->insert(['name' => $name]);
        fwrite(STDOUT, "WROTE\n");
    } else {
        $n = $db->table('users')->count();
        fwrite(STDOUT, "COUNT=$n\n");
    }
    fflush(STDOUT);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, "ERR:" . $e->getMessage() . "\n");
    exit(1);
}
PHP);
    }

    protected function tearDown(): void
    {
        if (is_file($this->helperFile)) {
            @unlink($this->helperFile);
        }
        if (is_dir($this->dir)) {
            $this->removeDirRecursive($this->dir);
        }
    }

    /**
     * 父进程准备并发连接：建库建表插 2 行，并保持长驻 reader 连接（缓存表数据）
     */
    private function setupDb(): void
    {
        $db = Psql::connect($this->dir, ['concurrency' => true]);
        $db->createDatabase('db');
        $db->use('db');
        $db->createTable('users', static function (Blueprint $b): void {
            $b->id();
            $b->varchar('name', 30)->notNull();
        });
        $db->table('users')->insertMany([
            ['name' => 'alice'],
            ['name' => 'bob'],
        ]);
    }

    public function testTwoReadersCoexistAcrossProcesses(): void
    {
        $this->setupDb();
        $reader = Psql::connect($this->dir, ['concurrency' => true]);
        $reader->use('db');
        $this->assertSame(2, $reader->table('users')->count());

        // 子进程（另一进程）以并发连接读取：多 reader 跨进程并存，不互斥
        $output = $this->childRun('read');
        $this->assertSame('COUNT=2', $output);
        unset($reader);
    }

    public function testReaderSeesWriterWriteViaCacheInvalidation(): void
    {
        $this->setupDb();
        // 长驻 reader：先读一次，缓存 users 表
        $reader = Psql::connect($this->dir, ['concurrency' => true]);
        $reader->use('db');
        $this->assertSame(2, $reader->table('users')->count());

        // 子进程（另一进程）写入一行 → .wv 版本递增
        $this->assertSame('WROTE', $this->childRun('write'));

        // 同一 reader 再次读：应看到新行（.wv 变化触发进程内缓存失效）
        $this->assertSame(3, $reader->table('users')->count());
        $names = array_column($reader->table('users')->orderBy('id')->select('name')->get()->rows(), 'name');
        $this->assertSame(['alice', 'bob', 'carol'], $names);
        unset($reader);
    }

    public function testSharedAndExclusiveLocksConflict(): void
    {
        // 子进程持共享锁（reader 语义）
        $holdHelper = $this->helperFile;
        $lockScript = (string) tempnam(sys_get_temp_dir(), 'psql-lockhold-');
        file_put_contents($lockScript, <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
\Kingbes\Psql\Storage\DirectoryLock::acquireBlocking(rtrim($argv[2], '/\\'), true);
fwrite(STDOUT, "SHARED\n");
fflush(STDOUT);
sleep((int) $argv[3]);
\Kingbes\Psql\Storage\DirectoryLock::release(rtrim($argv[2], '/\\'));
exit(0);
PHP);
        $command = '"' . PHP_BINARY . '" "' . $lockScript . '" "' . dirname(__DIR__, 3) . '/vendor/autoload.php"'
            . ' "' . $this->dir . '" "2"';
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $spec, $pipes, null, null, ['bypass_shell' => true]);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $line = fgets($pipes[1]);
        $this->assertSame('SHARED', trim((string) $line));

        try {
            // 共享锁持有期间，非阻塞排他 acquire 失败（BUSY）
            DirectoryLock::acquire($this->dir, false);
            $this->fail('共享锁持有期间非阻塞排他 acquire 未抛异常');
        } catch (StorageException $e) {
            $this->assertStringContainsString('占用', $e->getMessage());
        }

        // 共享锁共存：非阻塞共享 acquire 成功（多 reader 并存）
        DirectoryLock::acquire($this->dir, true);
        DirectoryLock::release($this->dir);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        @unlink($lockScript);
    }

    private function childRun(string $mode): string
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 不可用，无法执行跨进程测试');
        }
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $command = '"' . PHP_BINARY . '" "' . $this->helperFile . '" "' . $autoload . '"'
            . ' "' . $this->dir . '" "' . $mode . '"';
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $spec, $pipes, null, null, ['bypass_shell' => true]);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $line = fgets($pipes[1]);
        $output = $line === false ? '' : trim($line);
        fclose($pipes[1]);
        $stderr = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($output === '') {
            $this->fail("子进程无输出（exit={$exit}）stderr: {$stderr}");
        }

        return $output;
    }

    private function removeDirRecursive(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
