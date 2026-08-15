<?php

declare(strict_types=1);

namespace Kingbes\Psql\Tests\Unit;

use DateTimeImmutable;
use Kingbes\Psql\Exception\TypeException;
use Kingbes\Psql\Schema\ColumnSchema;
use Kingbes\Psql\Schema\DataType;
use Kingbes\Psql\Type\ValueCaster;
use PHPUnit\Framework\TestCase;

/**
 * ValueCaster 各类型合法/非法矩阵测试
 */
final class ValueCasterTest extends TestCase
{
    public function testNullPassThrough(): void
    {
        $column = new ColumnSchema('name', DataType::VARCHAR, length: 10);

        $this->assertNull(ValueCaster::cast(null, $column));
    }

    // ---- 整型 ----

    public function testTinyintBounds(): void
    {
        $column = new ColumnSchema('age', DataType::TINYINT);

        $this->assertSame(127, ValueCaster::cast(127, $column));
        $this->assertSame(-127, ValueCaster::cast(-127, $column));
        $this->expectException(TypeException::class);
        ValueCaster::cast(128, $column);
    }

    public function testTinyintSignedNegativeBound(): void
    {
        $column = new ColumnSchema('age', DataType::TINYINT);
        $this->expectException(TypeException::class);
        $this->expectExceptionMessage('age');
        ValueCaster::cast(-128, $column);
    }

    public function testUnsignedTinyintBounds(): void
    {
        $column = new ColumnSchema('age', DataType::TINYINT, unsigned: true);

        $this->assertSame(0, ValueCaster::cast(0, $column));
        $this->assertSame(255, ValueCaster::cast(255, $column));
        $this->expectException(TypeException::class);
        ValueCaster::cast(256, $column);
    }

    public function testUnsignedRejectsNegative(): void
    {
        $column = new ColumnSchema('age', DataType::SMALLINT, unsigned: true);

        $this->expectException(TypeException::class);
        $this->expectExceptionMessage('SMALLINT');
        ValueCaster::cast(-1, $column);
    }

    public function testSmallintBounds(): void
    {
        $column = new ColumnSchema('n', DataType::SMALLINT);

        $this->assertSame(32767, ValueCaster::cast(32767, $column));
        $this->assertSame(-32767, ValueCaster::cast(-32767, $column));
        $this->expectException(TypeException::class);
        ValueCaster::cast(32768, $column);
    }

    public function testIntBounds(): void
    {
        $column = new ColumnSchema('n', DataType::INT);

        $this->assertSame(2147483647, ValueCaster::cast(2147483647, $column));
        $this->assertSame(-2147483647, ValueCaster::cast(-2147483647, $column));
        $this->expectException(TypeException::class);
        ValueCaster::cast(2147483648, $column);
    }

    public function testUnsignedIntUpperBound(): void
    {
        $column = new ColumnSchema('n', DataType::INT, unsigned: true);

        $this->assertSame(4294967295, ValueCaster::cast(4294967295, $column));
        $this->expectException(TypeException::class);
        ValueCaster::cast(4294967296, $column);
    }

    public function testBigintBounds(): void
    {
        $column = new ColumnSchema('n', DataType::BIGINT);
        $unsigned = new ColumnSchema('n', DataType::BIGINT, unsigned: true);

        $this->assertSame(PHP_INT_MAX, ValueCaster::cast(PHP_INT_MAX, $column));
        $this->assertSame(-PHP_INT_MAX, ValueCaster::cast(-PHP_INT_MAX, $column));
        // 文档化限制：unsigned BIGINT 上限同为 PHP_INT_MAX
        $this->assertSame(PHP_INT_MAX, ValueCaster::cast(PHP_INT_MAX, $unsigned));
    }

    public function testIntegerBoolMapping(): void
    {
        $column = new ColumnSchema('n', DataType::INT);

        $this->assertSame(1, ValueCaster::cast(true, $column));
        $this->assertSame(0, ValueCaster::cast(false, $column));
    }

    public function testIntegerFloatOnlyWhenWhole(): void
    {
        $column = new ColumnSchema('n', DataType::INT);

        $this->assertSame(5, ValueCaster::cast(5.0, $column));
        $this->assertSame(-5, ValueCaster::cast(-5.0, $column));
        $this->expectException(TypeException::class);
        ValueCaster::cast(5.5, $column);
    }

    public function testIntegerNumericString(): void
    {
        $column = new ColumnSchema('n', DataType::INT);

        $this->assertSame(42, ValueCaster::cast('42', $column));
        $this->assertSame(42, ValueCaster::cast('+42', $column));
        $this->assertSame(-42, ValueCaster::cast('-42', $column));
        $this->assertSame(42, ValueCaster::cast('42.0', $column));
    }

    public function testIntegerRejectsNonWholeAndNonNumeric(): void
    {
        $column = new ColumnSchema('n', DataType::INT);

        $this->expectException(TypeException::class);
        ValueCaster::cast('42.5', $column);
    }

    public function testIntegerRejectsAlphaString(): void
    {
        $column = new ColumnSchema('n', DataType::INT);

        $this->expectException(TypeException::class);
        $this->expectExceptionMessage('INT');
        ValueCaster::cast('abc', $column);
    }

    public function testIntegerRejectsArray(): void
    {
        $column = new ColumnSchema('n', DataType::INT);

        $this->expectException(TypeException::class);
        ValueCaster::cast([1], $column);
    }

    // ---- 浮点 ----

    public function testFloatAccepts(): void
    {
        $column = new ColumnSchema('f', DataType::FLOAT);

        $this->assertSame(1.5, ValueCaster::cast(1.5, $column));
        $this->assertSame(2.0, ValueCaster::cast(2, $column));
        $this->assertSame(1.0, ValueCaster::cast(true, $column));
        $this->assertSame(0.0, ValueCaster::cast(false, $column));
        $this->assertSame(-0.25, ValueCaster::cast('-0.25', $column));
        $this->assertSame(0.5, ValueCaster::cast('.5', $column));
    }

    public function testFloatRejectsNonNumeric(): void
    {
        $column = new ColumnSchema('f', DataType::DOUBLE);

        $this->expectException(TypeException::class);
        $this->expectExceptionMessage('DOUBLE');
        ValueCaster::cast('1e5', $column);
    }

    // ---- DECIMAL ----

    public function testDecimalNormalization(): void
    {
        $column = new ColumnSchema('price', DataType::DECIMAL, precision: 5, scale: 2);

        $this->assertSame('1.50', ValueCaster::cast(1.5, $column));
        $this->assertSame('1.50', ValueCaster::cast('1.5', $column));
        $this->assertSame('1.50', ValueCaster::cast('001.50', $column));
        $this->assertSame('5.00', ValueCaster::cast(5, $column));
        $this->assertSame('-1.50', ValueCaster::cast('-1.5', $column));
        $this->assertSame('0.50', ValueCaster::cast('0.5', $column));
        $this->assertSame('0.50', ValueCaster::cast('.5', $column));
        $this->assertSame('123.45', ValueCaster::cast('123.45', $column));
    }

    public function testDecimalPrecisionOverflow(): void
    {
        $column = new ColumnSchema('price', DataType::DECIMAL, precision: 5, scale: 2);

        $this->expectException(TypeException::class);
        $this->expectExceptionMessage('精度');
        ValueCaster::cast('1234.56', $column);
    }

    public function testDecimalScaleOverflow(): void
    {
        $column = new ColumnSchema('price', DataType::DECIMAL, precision: 5, scale: 2);

        $this->expectException(TypeException::class);
        $this->expectExceptionMessage('标度');
        ValueCaster::cast('1.234', $column);
    }

    public function testDecimalUnsignedRejectsNegative(): void
    {
        $column = new ColumnSchema('price', DataType::DECIMAL, unsigned: true, precision: 5, scale: 2);

        $this->expectException(TypeException::class);
        $this->expectExceptionMessage('unsigned');
        ValueCaster::cast('-1.5', $column);
    }

    public function testDecimalRejectsAlphaString(): void
    {
        $column = new ColumnSchema('price', DataType::DECIMAL, precision: 5, scale: 2);

        $this->expectException(TypeException::class);
        ValueCaster::cast('abc', $column);
    }

    // ---- BOOLEAN ----

    public function testBooleanMappings(): void
    {
        $column = new ColumnSchema('flag', DataType::BOOLEAN);

        $this->assertSame(1, ValueCaster::cast(true, $column));
        $this->assertSame(0, ValueCaster::cast(false, $column));
        $this->assertSame(1, ValueCaster::cast(1, $column));
        $this->assertSame(0, ValueCaster::cast(0, $column));
        $this->assertSame(1, ValueCaster::cast('1', $column));
        $this->assertSame(0, ValueCaster::cast('0', $column));
    }

    public function testBooleanRejectsOthers(): void
    {
        $column = new ColumnSchema('flag', DataType::BOOLEAN);

        try {
            ValueCaster::cast(2, $column);
            $this->fail('2 应被拒绝');
        } catch (TypeException) {
        }
        try {
            ValueCaster::cast('true', $column);
            $this->fail("'true' 应被拒绝");
        } catch (TypeException) {
        }
        $this->expectException(TypeException::class);
        ValueCaster::cast(1.0, $column);
    }

    // ---- 字符串 ----

    public function testVarcharLengthLimit(): void
    {
        $column = new ColumnSchema('name', DataType::VARCHAR, length: 5);

        $this->assertSame('hello', ValueCaster::cast('hello', $column));
        $this->assertSame('', ValueCaster::cast('', $column));
        $this->expectException(TypeException::class);
        ValueCaster::cast('hello world', $column);
    }

    public function testVarcharLengthCountsCharactersNotBytes(): void
    {
        $column = new ColumnSchema('name', DataType::VARCHAR, length: 2);

        $this->assertSame('中文', ValueCaster::cast('中文', $column));
        $this->expectException(TypeException::class);
        ValueCaster::cast('中文啊', $column);
    }

    public function testStringCoercions(): void
    {
        $column = new ColumnSchema('name', DataType::VARCHAR, length: 10);

        $this->assertSame('123', ValueCaster::cast(123, $column));
        $this->assertSame('1', ValueCaster::cast(true, $column));
        $this->assertSame('0', ValueCaster::cast(false, $column));
        $this->assertSame('1.5', ValueCaster::cast(1.5, $column));
    }

    public function testStringRejectsArray(): void
    {
        $column = new ColumnSchema('name', DataType::VARCHAR, length: 10);

        $this->expectException(TypeException::class);
        ValueCaster::cast(['a'], $column);
    }

    public function testTextHasNoLengthLimit(): void
    {
        $column = new ColumnSchema('body', DataType::TEXT);

        $this->assertSame(str_repeat('a', 100000), ValueCaster::cast(str_repeat('a', 100000), $column));
    }

    public function testCharDoesNotPad(): void
    {
        $column = new ColumnSchema('code', DataType::CHAR, length: 5);

        // v1 文档化：CHAR 不做空格填充
        $this->assertSame('ab', ValueCaster::cast('ab', $column));
    }

    // ---- ENUM ----

    public function testEnumAcceptsMember(): void
    {
        $column = new ColumnSchema('status', DataType::ENUM, enumValues: ['active', 'disabled']);

        $this->assertSame('active', ValueCaster::cast('active', $column));
    }

    public function testEnumScalarCoercedToString(): void
    {
        $column = new ColumnSchema('level', DataType::ENUM, enumValues: ['1', '2']);

        $this->assertSame('1', ValueCaster::cast(1, $column));
    }

    public function testEnumRejectsUnknownValue(): void
    {
        $column = new ColumnSchema('status', DataType::ENUM, enumValues: ['active', 'disabled']);

        $this->expectException(TypeException::class);
        $this->expectExceptionMessage('status');
        ValueCaster::cast('pending', $column);
    }

    public function testEnumRejectsNonScalar(): void
    {
        $column = new ColumnSchema('status', DataType::ENUM, enumValues: ['active']);

        $this->expectException(TypeException::class);
        ValueCaster::cast(['active'], $column);
    }

    // ---- DATE / DATETIME / TIMESTAMP ----

    public function testDateAcceptsValidAndDateTimeInterface(): void
    {
        $column = new ColumnSchema('d', DataType::DATE);

        $this->assertSame('2026-08-15', ValueCaster::cast('2026-08-15', $column));
        $this->assertSame(
            '2026-08-15',
            ValueCaster::cast(new DateTimeImmutable('2026-08-15 23:59:59'), $column)
        );
    }

    public function testDateRejectsInvalidFormats(): void
    {
        $column = new ColumnSchema('d', DataType::DATE);

        try {
            ValueCaster::cast('2026-13-01', $column);
            $this->fail("'2026-13-01' 应被拒绝");
        } catch (TypeException) {
        }
        try {
            ValueCaster::cast('2026-1-1', $column);
            $this->fail("'2026-1-1' 应被拒绝");
        } catch (TypeException) {
        }
        try {
            ValueCaster::cast('2026-02-30', $column);
            $this->fail("'2026-02-30' 应被拒绝（日期回卷）");
        } catch (TypeException) {
        }
        $this->expectException(TypeException::class);
        ValueCaster::cast(123, $column);
    }

    public function testDateTimeAcceptsValidAndRejectsInvalid(): void
    {
        $column = new ColumnSchema('dt', DataType::DATETIME);

        $this->assertSame('2026-08-15 12:30:45', ValueCaster::cast('2026-08-15 12:30:45', $column));
        $this->assertSame(
            '2026-08-15 12:30:45',
            ValueCaster::cast(new DateTimeImmutable('2026-08-15 12:30:45'), $column)
        );
        $this->expectException(TypeException::class);
        ValueCaster::cast('2026-08-15', $column);
    }

    public function testTimestampSameRulesAsDatetime(): void
    {
        $column = new ColumnSchema('ts', DataType::TIMESTAMP);

        $this->assertSame('2026-08-15 00:00:00', ValueCaster::cast('2026-08-15 00:00:00', $column));
        $this->expectException(TypeException::class);
        ValueCaster::cast('2026-08-15 12:30', $column);
    }
}
