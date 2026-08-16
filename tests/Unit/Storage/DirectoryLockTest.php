<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Storage;

use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Storage\DirectoryLock;
use Kingbes\Psql\Storage\JsonFileEngine;
use Kingbes\Psql\Storage\PagedJsonEngine;
use PHPUnit\Framework\TestCase;

/**
 * DirectoryLock 多进程数据目录锁测试：同进程引用计数 / 跨进程互斥 / 引擎接入与枚举隐藏性
 *
 * 跨进程探测通过 proc_open 启动子 PHP 进程完成（优先真实进程语义，避免同进程双
 * flock 句柄的平台差异）；子进程脚本由本类写入临时文件，参数经 argv 传递以规避
 * Windows 命令行引号转义问题。
 */
final class DirectoryLockTest extends TestCase
{
    private string $root;

    /** 子进程探测脚本路径 */
    private string $helperFile;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psql-lock-test-' . uniqid('', true);
        // DirectoryLock 不负责创建目录（与引擎接入时 root 已存在的语义一致）
        mkdir($this->root, 0777, true);

        // 子进程脚本：mode=hold 持锁 sleep 后释放；mode=try 尝试持锁并上报结果
        $this->helperFile = (string) tempnam(sys_get_temp_dir(), 'psql-lock-helper-');
        file_put_contents($this->helperFile, <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
$root = rtrim((string) $argv[2], '/\\');
$mode = (string) $argv[3];
$hold = (int) ($argv[4] ?? 0);

if ($mode === 'hold') {
    \Kingbes\Psql\Storage\DirectoryLock::acquire($root);
    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);
    sleep($hold);
    \Kingbes\Psql\Storage\DirectoryLock::release($root);
    exit(0);
}

try {
    \Kingbes\Psql\Storage\DirectoryLock::acquire($root);
    fwrite(STDOUT, "ACQUIRED\n");
    \Kingbes\Psql\Storage\DirectoryLock::release($root);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, "BUSY\n");
    exit(1);
}
PHP);
    }

    protected function tearDown(): void
    {
        // 兜底释放本测试可能残留的锁引用（幂等，多次调用安全）
        for ($i = 0; $i < 4; $i++) {
            DirectoryLock::release($this->root);
        }
        if (is_file($this->helperFile)) {
            @unlink($this->helperFile);
        }
        if (is_dir($this->root)) {
            $this->removeDirRecursive($this->root);
        }
    }

    public function testSameProcessAcquireIsReferenceCounted(): void
    {
        DirectoryLock::acquire($this->root);
        $this->assertFileExists($this->root . '/.lock');

        // 同进程重复 acquire 不自我死锁（引用计数共享）
        DirectoryLock::acquire($this->root);

        // 引用计数未归零：子进程仍被本进程占用
        DirectoryLock::release($this->root);
        $this->assertSame('BUSY', $this->childTryAcquire());

        // 引用计数归零：句柄真正释放，子进程可获取
        DirectoryLock::release($this->root);
        $this->assertSame('ACQUIRED', $this->childTryAcquire());
    }

    public function testReleaseWithoutAcquireIsIdempotent(): void
    {
        DirectoryLock::release($this->root);
        DirectoryLock::release($this->root);

        $this->assertSame('ACQUIRED', $this->childTryAcquire());
    }

    public function testAcquireFailsWhileHeldByOtherProcess(): void
    {
        [$process, $pipes] = $this->childHold(2);

        try {
            DirectoryLock::acquire($this->root);
            $this->fail('子进程持锁期间 acquire 未抛异常');
        } catch (StorageException $e) {
            $this->assertStringContainsString($this->root, $e->getMessage());
            $this->assertStringContainsString('其他进程', $e->getMessage());
        }

        // 子进程退出（锁随进程终结释放）后父进程可获取
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        DirectoryLock::acquire($this->root);
        DirectoryLock::release($this->root);
    }

    public function testJsonEngineLockLifecycle(): void
    {
        $a = new JsonFileEngine($this->root);
        $a->createDatabase('db');

        // 构造即持锁：锁文件存在且不出现在枚举中
        $this->assertFileExists($this->root . '/.lock');
        $this->assertSame(['db'], $a->databases());
        $this->assertSame([], $a->tables('db'));

        // 同进程第二实例共享锁（既有双实例测试模式不受影响）
        $b = new JsonFileEngine($this->root);
        $this->assertSame(['db'], $b->databases());

        // 单实例析构后引用计数仍 >0：跨进程仍被占用
        unset($a);
        $this->assertSame('BUSY', $this->childTryAcquire());

        // 全部析构后锁真正释放
        unset($b);
        $this->assertSame('ACQUIRED', $this->childTryAcquire());
    }

    public function testPagedJsonEngineLockLifecycle(): void
    {
        $engine = new PagedJsonEngine($this->root);
        $engine->createDatabase('db');

        $this->assertFileExists($this->root . '/.lock');
        $this->assertSame(['db'], $engine->databases());
        $this->assertSame([], $engine->tables('db'));

        // 析构释放锁，子进程可获取
        unset($engine);
        $this->assertSame('ACQUIRED', $this->childTryAcquire());
    }

    public function testDropDatabaseKeepsLockFileIntact(): void
    {
        $engine = new JsonFileEngine($this->root);
        $engine->createDatabase('db');
        $engine->dropDatabase('db');

        // drop 只删 <root>/<db> 子目录，锁文件与锁状态不受影响
        $this->assertFileExists($this->root . '/.lock');
        $this->assertSame('BUSY', $this->childTryAcquire());
    }

    public function testDifferentRootsLockIndependently(): void
    {
        $other = $this->root . '_other';
        mkdir($other, 0777, true);
        DirectoryLock::acquire($this->root);
        DirectoryLock::acquire($other);

        $this->assertSame('BUSY', $this->childTryAcquire($this->root));
        $this->assertSame('BUSY', $this->childTryAcquire($other));

        // 释放 other 不影响 root 的锁
        DirectoryLock::release($other);
        $this->assertSame('ACQUIRED', $this->childTryAcquire($other));
        $this->assertSame('BUSY', $this->childTryAcquire($this->root));

        DirectoryLock::release($this->root);
        $this->removeDirRecursive($other);
    }

    /**
     * 启动子进程执行锁脚本；脚本参数依次为 <root> <mode> [holdSeconds]，全部加引号规避 Windows 转义问题
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function spawnChild(string $mode, string $root, int $holdSeconds = 0): array
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 不可用，无法执行跨进程锁测试');
        }
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $command = '"' . PHP_BINARY . '" "' . $this->helperFile . '" "' . $autoload . '"'
            . ' "' . $root . '" "' . $mode . '" "' . $holdSeconds . '"';
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $spec, $pipes, null, null, ['bypass_shell' => true]);
        $this->assertIsResource($process, '无法启动子进程');
        fclose($pipes[0]);

        return [$process, $pipes];
    }

    /**
     * 子进程尝试持锁探测：返回 ACQUIRED（可获取）或 BUSY（被占用）
     */
    private function childTryAcquire(?string $root = null): string
    {
        [$process, $pipes] = $this->spawnChild('try', $root ?? $this->root);
        $line = fgets($pipes[1]);
        $output = $line === false ? '' : trim($line);
        fclose($pipes[1]);
        $stderr = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($output === '') {
            $this->fail("子进程探测无输出（exit={$exitCode}）stderr: {$stderr}");
        }

        return $output;
    }

    /**
     * 子进程持锁 sleep 指定秒数后释放；等待其 LOCKED 确认后返回，供父进程在持锁窗口内断言
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function childHold(int $seconds): array
    {
        [$process, $pipes] = $this->spawnChild('hold', $this->root, $seconds);
        $line = fgets($pipes[1]);
        $marker = $line === false ? '' : trim($line);
        if ($marker !== 'LOCKED') {
            $stderr = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $this->fail("子进程未确认持锁（输出: {$marker}）stderr: {$stderr}");
        }

        return [$process, $pipes];
    }

    /**
     * 递归删除测试临时目录
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
