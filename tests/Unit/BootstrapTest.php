<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit;

use Kingbes\Psql\Exception\ConstraintException;
use Kingbes\Psql\Exception\PsqlException;
use Kingbes\Psql\Exception\QueryException;
use Kingbes\Psql\Exception\SchemaException;
use Kingbes\Psql\Exception\StorageException;
use Kingbes\Psql\Exception\TransactionException;
use Kingbes\Psql\Exception\TypeException;
use PHPUnit\Framework\TestCase;

/**
 * 工程骨架冒烟测试：验证异常体系自动加载与继承关系
 */
final class BootstrapTest extends TestCase
{
    /**
     * 六个具体异常类均继承自 PsqlException
     */
    public function testExceptionsExtendPsqlException(): void
    {
        $this->assertInstanceOf(PsqlException::class, new SchemaException());
        $this->assertInstanceOf(PsqlException::class, new TypeException());
        $this->assertInstanceOf(PsqlException::class, new ConstraintException());
        $this->assertInstanceOf(PsqlException::class, new QueryException());
        $this->assertInstanceOf(PsqlException::class, new StorageException());
        $this->assertInstanceOf(PsqlException::class, new TransactionException());
    }

    /**
     * 抛出的具体异常可被基类 PsqlException 捕获且消息正确
     */
    public function testThrowAndCatchAsPsqlException(): void
    {
        $this->expectException(PsqlException::class);
        $this->expectExceptionMessage('测试');

        throw new SchemaException('测试');
    }

    /**
     * try/catch 捕获后可校验实际类型与消息
     */
    public function testCatchRetainsConcreteTypeAndMessage(): void
    {
        try {
            throw new QueryException('查询失败');
        } catch (PsqlException $e) {
            $this->assertInstanceOf(QueryException::class, $e);
            $this->assertSame('查询失败', $e->getMessage());

            return;
        }

        $this->fail('未能捕获 QueryException');
    }
}
