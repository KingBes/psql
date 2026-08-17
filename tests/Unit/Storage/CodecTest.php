<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit\Storage;

use InvalidArgumentException;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Psql;
use Kingbes\Psql\Schema\ColumnSchema;
use Kingbes\Psql\Schema\DataType;
use Kingbes\Psql\Schema\TableSchema;
use Kingbes\Psql\Storage\Codec;
use Kingbes\Psql\Storage\JsonFileEngine;
use Kingbes\Psql\Storage\PagedJsonEngine;
use Kingbes\Psql\Storage\PhpSerializeEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Codec 编解码器测试：往返矩阵 / magic 检测优先级 / 篡改与损坏 / 引擎落盘集成 / 门面选项
 */
final class CodecTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/psql-codec-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->removeDirRecursive($this->root);
        }
    }

    /**
     * 构造两张列的示例表结构
     */
    private function makeSchema(string $name): TableSchema
    {
        return new TableSchema($name, [
            new ColumnSchema(name: 'id', type: DataType::BIGINT, unsigned: true, primaryKey: true, autoIncrement: true),
            new ColumnSchema(name: 'name', type: DataType::VARCHAR, length: 50, notNull: true),
        ]);
    }

    /**
     * 断言回调抛出 StorageException 且消息含指定子串
     */
    private function assertThrows(callable $fn, string $needle, string $failMessage): void
    {
        try {
            $fn();
            $this->fail($failMessage);
        } catch (StorageException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    // ---- Codec 单元：往返矩阵 ----

    /**
     * 明文/gzip/加密/gzip+加密 × 短文本/空串/二进制/1MB 大文本
     *
     * @return array<string, array{bool, ?string, string}>
     */
    public static function roundTripProvider(): array
    {
        $modes = [
            '明文' => [false, null],
            'gzip' => [true, null],
            '加密' => [false, 'secret-key'],
            'gzip+加密' => [true, 'secret-key'],
        ];
        $payloads = [
            '短文本' => 'hello psql 你好',
            '空串' => '',
            '二进制' => "\x00\x01\xfe\xff" . str_repeat("\x80\x7f", 128),
            '1MB 文本' => str_repeat("abcdefgh 中文行\n", 45000),
        ];
        $cases = [];
        foreach ($modes as $modeLabel => $mode) {
            foreach ($payloads as $payloadLabel => $payload) {
                $cases["{$modeLabel} × {$payloadLabel}"] = [$mode[0], $mode[1], $payload];
            }
        }

        return $cases;
    }

    #[DataProvider('roundTripProvider')]
    public function testRoundTrip(bool $compress, ?string $key, string $payload): void
    {
        if ($key !== null && !extension_loaded('openssl')) {
            $this->markTestSkipped('openssl 扩展不可用');
        }
        $codec = new Codec($compress, $key);

        $this->assertSame($payload, $codec->decode($codec->encode($payload)));
    }

    public function testPlainCodecIsPassthrough(): void
    {
        $codec = new Codec();

        $this->assertSame('hello', $codec->encode('hello'));
        $this->assertSame('', $codec->encode(''));
        $this->assertSame('hello', $codec->decode('hello'));
        $this->assertSame('', $codec->decode(''));
    }

    public function testMagicHeadersAndRandomIv(): void
    {
        $gz = (new Codec(true))->encode('hello world payload');
        $this->assertStringStartsWith(Codec::MAGIC_GZ, $gz);
        $this->assertNotSame('hello world payload', $gz);

        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl 扩展不可用');
        }
        $codec = new Codec(false, 'k');
        $first = $codec->encode('hello');
        $this->assertStringStartsWith(Codec::MAGIC_ENC, $first);
        // 随机 IV：两次密文不同，均可解回原文
        $this->assertNotSame($first, $codec->encode('hello'));
        $this->assertSame('hello', $codec->decode($first));
    }

    // ---- Codec 单元：错误场景 ----

    public function testWrongKeyThrows(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl 扩展不可用');
        }
        $encoded = (new Codec(false, 'right'))->encode('secret data');

        $this->assertThrows(
            fn () => (new Codec(false, 'wrong'))->decode($encoded),
            '密钥',
            '错 key 解密未抛异常'
        );
    }

    public function testEncryptedPayloadWithoutKeyThrows(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl 扩展不可用');
        }
        $encoded = (new Codec(false, 'k'))->encode('secret data');

        $this->assertThrows(
            fn () => (new Codec())->decode($encoded),
            '需要密钥',
            '无 key 解密未抛异常'
        );
    }

    public function testHmacTamperThrows(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl 扩展不可用');
        }
        $encoded = (new Codec(false, 'k'))->encode(str_repeat('x', 100));

        // 分别篡改 hmac 区（offset 5 起）/ iv 区 / 密文尾字节
        foreach ([10, 25, strlen($encoded) - 1] as $position) {
            $bad = $encoded;
            $bad[$position] = $bad[$position] === 'A' ? 'B' : 'A';
            $this->assertThrows(
                fn () => (new Codec(false, 'k'))->decode($bad),
                '密钥错误或数据损坏',
                "篡改位置 {$position} 未抛异常"
            );
        }

        // 截断载荷（短于 magic+hmac+iv+一个块）
        $this->assertThrows(
            fn () => (new Codec(false, 'k'))->decode(substr($encoded, 0, 30)),
            '密钥错误或数据损坏',
            '截断密文未抛异常'
        );
    }

    public function testGzCorruptionThrows(): void
    {
        $codec = new Codec(true);
        $encoded = $codec->encode(str_repeat('payload-', 1000));

        // 破坏尾部 CRC/长度区
        $bad = $encoded;
        $last = strlen($bad) - 1;
        $bad[$last] = $bad[$last] === 'A' ? 'B' : 'A';
        $this->assertThrows(
            fn () => $codec->decode($bad),
            '解压失败',
            '损坏 gzip 尾部未抛异常'
        );

        // 截断载荷
        $this->assertThrows(
            fn () => $codec->decode(substr($encoded, 0, strlen($encoded) - 20)),
            '解压失败',
            '截断 gzip 未抛异常'
        );
    }

    public function testPlaintextWithMagicPrefixThrows(): void
    {
        // 明文恰好以 PGZ 开头：decode 严格校验 gz 完整性，损坏即抛（天然歧义防护）
        $this->assertThrows(
            fn () => (new Codec())->decode(Codec::MAGIC_GZ . 'not-a-gzip-stream'),
            '解压失败',
            '伪 PGZ 明文未抛异常'
        );
        // 伪 PENC 明文：无 key 的 codec 先报需要密钥
        $this->assertThrows(
            fn () => (new Codec())->decode(Codec::MAGIC_ENC . 'short'),
            '需要密钥',
            '伪 PENC 明文未抛异常'
        );
    }

    public function testEncMagicDetectedBeforeGz(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl 扩展不可用');
        }
        // 压缩+加密叠加：内层含 PGZ 载荷、外层恒为 PENC（加密 magic 优先检测）
        $encoded = (new Codec(true, 'k'))->encode('compress then encrypt');

        $this->assertStringStartsWith(Codec::MAGIC_ENC, $encoded);

        // 读侧 magic 自适应：仅配置 key 的 codec 也能完整解开 gz+enc 载荷（自动继续解压）
        $this->assertSame('compress then encrypt', (new Codec(false, 'k'))->decode($encoded));

        // 无 key codec 无法解开加密载荷
        $this->assertThrows(
            fn () => (new Codec())->decode($encoded),
            '需要密钥',
            '无 key 解密未抛异常'
        );
    }

    public function testConstructWithoutOpensslThrows(): void
    {
        if (extension_loaded('openssl')) {
            $this->markTestSkipped('openssl 扩展存在，无法验证缺失场景');
        }
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('openssl 扩展不可用');
        new Codec(false, 'k');
    }

    // ---- 引擎落盘集成：FileEngine 家族 ----

    public function testJsonEngineCompressedPayloadOnDisk(): void
    {
        $engine = new JsonFileEngine($this->root, new Codec(true));
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [['id' => 1, 'name' => '张三'], ['id' => 2, 'name' => '李四']];
        $engine->writeRows('db', 't', $rows);
        $engine->saveViewDefinitions('db', ['v' => ['name' => 'v', 'query' => ['table' => 't']]]);

        // 表数据文件与 .views.json 均为压缩载荷
        $this->assertStringStartsWith(Codec::MAGIC_GZ, (string) file_get_contents($this->root . '/db/t.json'));
        $this->assertStringStartsWith(Codec::MAGIC_GZ, (string) file_get_contents($this->root . '/db/.views.json'));

        // 同 codec 重开：数据与视图往返一致
        $fresh = new JsonFileEngine($this->root, new Codec(true));
        $this->assertSame($rows, $fresh->readRows('db', 't'));
        $this->assertSame(['v'], array_keys($fresh->loadViewDefinitions('db')));

        // 无选项连接读侧自适应：可读压缩库（decode 按 magic 分派，配置只影响写方向）
        $plain = new JsonFileEngine($this->root);
        $this->assertSame($rows, $plain->readRows('db', 't'));
    }

    public function testJsonEngineEncryptedOnDiskAndWrongKeyReopen(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl 扩展不可用');
        }
        $engine = new JsonFileEngine($this->root, new Codec(false, 'k1'));
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [['id' => 1, 'name' => 'a']];
        $engine->writeRows('db', 't', $rows);

        $this->assertStringStartsWith(Codec::MAGIC_ENC, (string) file_get_contents($this->root . '/db/t.json'));

        $this->assertSame($rows, (new JsonFileEngine($this->root, new Codec(false, 'k1')))->readRows('db', 't'));

        // 错 key 打开：读时抛
        $this->assertThrows(
            fn () => (new JsonFileEngine($this->root, new Codec(false, 'k2')))->readRows('db', 't'),
            '密钥',
            '错 key 打开加密库未抛异常'
        );

        // 无 key 打开：读时明确报需要密钥
        $this->assertThrows(
            fn () => (new JsonFileEngine($this->root))->readRows('db', 't'),
            '需要密钥',
            '无 key 打开加密库未抛异常'
        );
    }

    public function testPhpSerializeEngineEncryptedOnDisk(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl 扩展不可用');
        }
        $engine = new PhpSerializeEngine($this->root, new Codec(false, 'k'));
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']];
        $engine->writeRows('db', 't', $rows);

        $this->assertStringStartsWith(Codec::MAGIC_ENC, (string) file_get_contents($this->root . '/db/t.bin'));
        $this->assertSame(
            $rows,
            (new PhpSerializeEngine($this->root, new Codec(false, 'k')))->readRows('db', 't')
        );
        $this->assertThrows(
            fn () => (new PhpSerializeEngine($this->root, new Codec(false, 'bad')))->readRows('db', 't'),
            '密钥',
            '错 key 打开加密 bin 未抛异常'
        );
    }

    public function testLegacyPlaintextUpgradeToEncryption(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl 扩展不可用');
        }
        // 无选项写明文库
        $plain = new JsonFileEngine($this->root);
        $plain->createDatabase('db');
        $plain->createTable('db', $this->makeSchema('t'));
        $rows = [['id' => 1, 'name' => 'a']];
        $plain->writeRows('db', 't', $rows);
        $this->assertStringStartsWith('{', (string) file_get_contents($this->root . '/db/t.json'));

        // 带 key 连接读旧明文库 OK（明文无 magic 原样通过）
        $encrypted = new JsonFileEngine($this->root, new Codec(false, 'k'));
        $this->assertSame($rows, $encrypted->readRows('db', 't'));

        // 写回 → 加密落盘（渐进加密）
        $encrypted->writeRows('db', 't', [['id' => 2, 'name' => 'b']]);
        $this->assertStringStartsWith(Codec::MAGIC_ENC, (string) file_get_contents($this->root . '/db/t.json'));

        // 无 key 再开：读时抛需要密钥
        $this->assertThrows(
            fn () => (new JsonFileEngine($this->root))->readRows('db', 't'),
            '需要密钥',
            '无 key 读加密化后的旧库未抛异常'
        );
    }

    public function testLegacyPlaintextUpgradeToCompression(): void
    {
        $plain = new JsonFileEngine($this->root);
        $plain->createDatabase('db');
        $plain->createTable('db', $this->makeSchema('t'));
        $rows = [['id' => 1, 'name' => 'a']];
        $plain->writeRows('db', 't', $rows);

        // 压缩连接读旧明文库 OK；写回 → 压缩落盘
        $compressed = new JsonFileEngine($this->root, new Codec(true));
        $this->assertSame($rows, $compressed->readRows('db', 't'));
        $compressed->writeRows('db', 't', [['id' => 2, 'name' => 'b']]);
        $this->assertStringStartsWith(Codec::MAGIC_GZ, (string) file_get_contents($this->root . '/db/t.json'));

        // 无选项连接读侧自适应读压缩库 OK
        $this->assertSame(
            [['id' => 2, 'name' => 'b']],
            (new JsonFileEngine($this->root))->readRows('db', 't')
        );
    }

    // ---- 引擎落盘集成：PagedJsonEngine ----

    public function testPagedJsonCodecPagingAndTombstoneDelete(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl 扩展不可用');
        }
        $codec = new Codec(true, 'k');
        $engine = new PagedJsonEngine($this->root, 2, $codec);
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $rows[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $engine->writeRows('db', 't', $rows);

        // meta 与页文件均经 codec 落盘
        $this->assertStringStartsWith(Codec::MAGIC_ENC, (string) file_get_contents($this->root . '/db/t.meta.json'));
        $this->assertStringStartsWith(Codec::MAGIC_ENC, (string) file_get_contents($this->root . '/db/t.0.0.page.json'));
        $this->assertStringStartsWith(Codec::MAGIC_ENC, (string) file_get_contents($this->root . '/db/t.2.0.page.json'));

        // 墓碑删除：页重写路径经 codec，稠密视图正确
        $engine->deleteRows('db', 't', [2]);
        $expected = [$rows[0], $rows[1], $rows[3], $rows[4], $rows[5]];
        $this->assertSame($expected, $engine->readRows('db', 't'));

        // 新实例重开：页文件解密解压后数据一致，且可继续删除
        $fresh = new PagedJsonEngine($this->root, 2, new Codec(true, 'k'));
        $this->assertSame($expected, $fresh->readRows('db', 't'));
        $fresh->deleteRows('db', 't', [0]);
        $this->assertSame([$rows[1], $rows[3], $rows[4], $rows[5]], $fresh->readRows('db', 't'));
    }

    public function testPagedJsonCodecCompaction(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl 扩展不可用');
        }
        $engine = new PagedJsonEngine($this->root, 1, new Codec(true, 'k'));
        $engine->createDatabase('db');
        $engine->createTable('db', $this->makeSchema('t'));
        $rows = [];
        for ($i = 1; $i <= 250; $i++) {
            $rows[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $engine->writeRows('db', 't', $rows);

        // dead=99 未达阈值：不压实
        $engine->deleteRows('db', 't', range(0, 98));
        $this->assertCount(151, $engine->readRows('db', 't'));

        // 再删 1 行触发压实：全部页重写路径经 codec
        $engine->deleteRows('db', 't', [0]);

        $expected = [];
        for ($i = 101; $i <= 250; $i++) {
            $expected[] = ['id' => $i, 'name' => 'n' . $i];
        }
        $this->assertSame($expected, $engine->readRows('db', 't'));

        // 新实例重开：压实后状态完整
        $fresh = new PagedJsonEngine($this->root, 1, new Codec(true, 'k'));
        $this->assertSame($expected, $fresh->readRows('db', 't'));
    }

    // ---- 门面选项 ----

    public function testPsqlConnectCompressOption(): void
    {
        $engine = Psql::connect($this->root, ['compress' => true])->engine();
        $engine->createTable('main', $this->makeSchema('t'));
        $rows = [['id' => 1, 'name' => 'a']];
        $engine->writeRows('main', 't', $rows);

        $this->assertStringStartsWith(Codec::MAGIC_GZ, (string) file_get_contents($this->root . '/main/t.json'));

        // 带压缩重开与无选项重开（读侧自适应）均可读
        $this->assertSame($rows, Psql::connect($this->root, ['compress' => true])->engine()->readRows('main', 't'));
        $this->assertSame($rows, Psql::connect($this->root)->engine()->readRows('main', 't'));
    }

    public function testPsqlConnectKeyOption(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('openssl 扩展不可用');
        }
        $engine = Psql::connect($this->root, ['key' => 'k'])->engine();
        $engine->createTable('main', $this->makeSchema('t'));
        $rows = [['id' => 1, 'name' => 'a']];
        $engine->writeRows('main', 't', $rows);

        $this->assertStringStartsWith(Codec::MAGIC_ENC, (string) file_get_contents($this->root . '/main/t.json'));
        $this->assertSame($rows, Psql::connect($this->root, ['key' => 'k'])->engine()->readRows('main', 't'));
        $this->assertThrows(
            fn () => Psql::connect($this->root)->engine()->readRows('main', 't'),
            '需要密钥',
            '无 key 读加密库未抛异常'
        );
    }

    public function testPsqlMemoryRejectsCodecOptions(): void
    {
        try {
            Psql::memory(['compress' => true]);
            $this->fail('memory 传 compress 未抛异常');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('不支持', $e->getMessage());
        }
        try {
            Psql::memory(['key' => 'x']);
            $this->fail('memory 传 key 未抛异常');
        } catch (InvalidArgumentException) {
            // 预期
        }

        $this->assertSame('main', Psql::memory()->currentDatabase());
    }

    public function testPsqlConnectRejectsBadOptions(): void
    {
        try {
            Psql::connect($this->root, ['unknown' => 1]);
            $this->fail('未知选项未抛异常');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('未知连接选项', $e->getMessage());
        }
        try {
            Psql::connect($this->root, ['key' => 123]);
            $this->fail('key 非字符串未抛异常');
        } catch (InvalidArgumentException) {
            // 预期
        }
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
