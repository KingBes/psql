<?php

declare(strict_types=1);

namespace Kingbes\Psql\Storage;

use Kingbes\Psql\Exception\StorageException;

/**
 * 落盘编解码器：可选压缩（gzip）+ 可选加密（AES-256-CBC + HMAC-SHA256），
 * magic 头版本化；未配置任何选项时直通明文（旧格式兼容）
 *
 * 读侧始终按 magic 自适应分派（加密优先检测），compress/key 配置只影响 encode 方向：
 * - 明文（无 magic）：原样返回（旧明文库迁移期兼容；带选项连接读旧库 OK）
 * - PGZ：gzdecode 解压（失败抛 StorageException，数据损坏）
 * - PENC：本实例须配置 key 才能解，否则抛"文件已加密，需要密钥"；
 *   HMAC 不符/解密失败抛"密钥错误或数据损坏"
 *
 * 布局：加密 = MAGIC_ENC + hmac(16) + iv(16) + ciphertext（先压缩后加密，
 * 压缩+加密时解密后的明文为 PGZ 载荷，decode 自动继续解压）
 *
 * magic 歧义防护：明文内容恰好以 PGZ/PENC 开头的天然概率由"加密库永远自写 magic、
 * 解码严格校验 HMAC/gz 完整性"兜底——decode 对声称 PGZ 但 gzdecode 失败、
 * 声称 PENC 但 HMAC 不符均抛 StorageException
 */
final class Codec
{
    public const MAGIC_PLAIN = '';

    /** 压缩载荷 magic（4 字节） */
    public const MAGIC_GZ = "PGZ\x01";

    /** 加密载荷 magic（4 字节） */
    public const MAGIC_ENC = "PENC\x01";

    /** HMAC-SHA256 截断长度（字节） */
    private const HMAC_LENGTH = 16;

    /** AES-CBC IV 长度（字节） */
    private const IV_LENGTH = 16;

    /** 加密密钥派生（sha256 原始字节）；未配置为 null */
    private ?string $keyHash;

    /**
     * @param bool $compress 是否启用 gzip 压缩
     * @param ?string $key 加密口令（配置后 openssl 扩展缺失抛 StorageException）
     */
    public function __construct(private bool $compress = false, private ?string $key = null)
    {
        if ($this->key !== null && !extension_loaded('openssl')) {
            throw new StorageException('openssl 扩展不可用，无法启用加密');
        }
        $this->keyHash = $this->key === null ? null : hash('sha256', $this->key, true);
    }

    /**
     * 编码（先压缩后加密）；两选项均关时原样返回
     */
    public function encode(string $data): string
    {
        if (!$this->compress && $this->key === null) {
            return $data;
        }
        if ($this->compress) {
            $gz = @gzencode($data, 6);
            if ($gz === false) {
                throw new StorageException('数据压缩失败');
            }
            $data = self::MAGIC_GZ . $gz;
        }
        if ($this->keyHash !== null) {
            $data = $this->encrypt($data);
        }

        return $data;
    }

    /**
     * 解码：按 magic 分派（加密优先检测）；明文（无 magic）原样返回；
     * 解密失败/HMAC 不符/gz 完整性失败抛 StorageException
     */
    public function decode(string $data): string
    {
        // 加密 magic 必须最先检测：加密外层的密文可以是任意字节（包括恰好构成 PGZ 头的串）
        if (str_starts_with($data, self::MAGIC_ENC)) {
            $data = $this->decrypt($data);
        }
        if (str_starts_with($data, self::MAGIC_GZ)) {
            $out = @gzdecode(substr($data, strlen(self::MAGIC_GZ)));
            if ($out === false) {
                throw new StorageException('数据解压失败：数据损坏');
            }

            return $out;
        }

        return $data;
    }

    /**
     * 加密：AES-256-CBC，随机 16 字节 IV 前置于密文，
     * HMAC-SHA256(密钥派生, IV.ciphertext) 截断 16 字节更前置
     */
    private function encrypt(string $plain): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $this->keyHash, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new StorageException('数据加密失败');
        }
        $hmac = substr(hash_hmac('sha256', $iv . $cipher, $this->keyHash, true), 0, self::HMAC_LENGTH);

        return self::MAGIC_ENC . $hmac . $iv . $cipher;
    }

    /**
     * 解密：先校验 HMAC，再解密；任何失败抛 StorageException（消息区分缺 key 与密钥错误/损坏）
     */
    private function decrypt(string $data): string
    {
        if ($this->keyHash === null) {
            throw new StorageException('文件已加密，需要密钥');
        }
        $body = substr($data, strlen(self::MAGIC_ENC));
        // CBC + PKCS7 最短密文为一个块（16 字节）
        if (strlen($body) < self::HMAC_LENGTH + self::IV_LENGTH + 16) {
            throw new StorageException('密文长度非法：密钥错误或数据损坏');
        }
        $hmac = substr($body, 0, self::HMAC_LENGTH);
        $iv = substr($body, self::HMAC_LENGTH, self::IV_LENGTH);
        $cipher = substr($body, self::HMAC_LENGTH + self::IV_LENGTH);
        $expected = substr(hash_hmac('sha256', $iv . $cipher, $this->keyHash, true), 0, self::HMAC_LENGTH);
        if (!hash_equals($expected, $hmac)) {
            throw new StorageException('HMAC 校验失败：密钥错误或数据损坏');
        }
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $this->keyHash, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new StorageException('解密失败：密钥错误或数据损坏');
        }

        return $plain;
    }
}
