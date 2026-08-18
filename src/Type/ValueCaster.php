<?php

declare(strict_types=1);

namespace Kingbes\Psql\Type;

use DateTime;
use DateTimeInterface;
use Kingbes\Psql\Exception\TypeException;
use Kingbes\Psql\Schema\ColumnSchema;
use Kingbes\Psql\Schema\DataType;

/**
 * 值类型转换：按列类型校验并规范化 PHP 值
 */
final class ValueCaster
{
    /** 纯数字字符串判定 */
    private const NUMERIC_PATTERN = '/^[+-]?(\d+(\.\d*)?|\.\d+)$/';

    /**
     * 将 PHP 值按列类型校验并规范化。
     * null 直接返回 null（NOT NULL 由约束层负责）；失败抛 TypeException（消息含列名与类型）。
     */
    public static function cast(mixed $value, ColumnSchema $column): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($column->type) {
            DataType::TINYINT,
            DataType::SMALLINT,
            DataType::INT,
            DataType::BIGINT => self::castInteger($value, $column),
            DataType::FLOAT,
            DataType::DOUBLE => self::castFloat($value, $column),
            DataType::DECIMAL => self::castDecimal($value, $column),
            DataType::BOOLEAN => self::castBoolean($value, $column),
            DataType::CHAR,
            DataType::VARCHAR => self::castString($value, $column, true),
            DataType::TEXT,
            DataType::BLOB => self::castString($value, $column, false),
            DataType::BINARY => self::castBinary($value, $column),
            DataType::JSON => self::castJson($value, $column),
            DataType::ENUM => self::castEnum($value, $column),
            DataType::SET => self::castSet($value, $column),
            DataType::DATE => self::castDate($value, $column),
            DataType::DATETIME,
            DataType::TIMESTAMP => self::castDateTime($value, $column),
        };
    }

    // ---- 整型 ----

    /**
     * 整型转换：bool→0/1；int 直接；float 仅整数值；纯数字字符串；随后范围校验。
     * UNSIGNED BIGINT 超 PHP_INT_MAX 的值以十进制字符串表示（bcmath 校验范围）
     *
     * @return int|string
     */
    private static function castInteger(mixed $value, ColumnSchema $column): int|string
    {
        if ($column->type === DataType::BIGINT && $column->unsigned) {
            return self::castUnsignedBigInt($value, $column);
        }

        $int = self::coerceInteger($value, $column);
        [$min, $max] = self::integerRange($column);
        if ($int < $min || $int > $max) {
            throw new TypeException(self::fail($column, "值 {$int} 超出范围 [{$min}, {$max}]"));
        }

        return $int;
    }

    /**
     * UNSIGNED BIGINT：范围 [0, 2^64-1]；值 ≤ PHP_INT_MAX 仍为 int，
     * 超 PHP_INT_MAX 以十进制字符串存储（float 已丢精度，超大数须以字符串传入）
     *
     * @return int|string
     */
    private static function castUnsignedBigInt(mixed $value, ColumnSchema $column): int|string
    {
        $max = '18446744073709551615';
        $maxInt = '9223372036854775807';

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value)) {
            if ($value < 0) {
                throw new TypeException(self::fail($column, "unsigned 列不接受负数: {$value}"));
            }

            return $value;
        }
        if (is_float($value)) {
            if (fmod($value, 1.0) !== 0.0 || $value < 0) {
                throw new TypeException(self::fail($column, "非负整数值 {$value} 不能转换为 UNSIGNED BIGINT"));
            }
            if ($value > (float) PHP_INT_MAX) {
                throw new TypeException(
                    self::fail($column, "float 无法精确表示超大整数 {$value}，请使用十进制字符串传入")
                );
            }

            return (int) $value;
        }
        if (is_string($value) && preg_match(self::NUMERIC_PATTERN, $value) === 1) {
            if (str_contains($value, '.')) {
                throw new TypeException(self::fail($column, "UNSIGNED BIGINT 不接受小数: {$value}"));
            }
            $negative = str_starts_with(ltrim($value, ' '), '-');
            if ($negative) {
                throw new TypeException(self::fail($column, "unsigned 列不接受负数: {$value}"));
            }

            $digits = self::normalizeIntegerString($value);
            if ($digits === '0') {
                return 0;
            }
            // ≤ PHP_INT_MAX → int；否则精确比较是否 ≤ 2^64-1
            if (strlen($digits) < 19 || (strlen($digits) === 19 && $digits <= $maxInt)) {
                return (int) $digits;
            }
            if (self::compareIntegerStrings($digits, $max) > 0) {
                throw new TypeException(self::fail($column, "值 {$value} 超出 UNSIGNED BIGINT 范围 [0, {$max}]"));
            }

            return $digits;
        }

        throw new TypeException(self::fail($column, '无法转换为 UNSIGNED BIGINT: ' . self::describe($value)));
    }

    /**
     * 精确数值比较：双侧均为十进制整数字符串时按整数精确比较（bcmath 优先），
     * 否则回退 (float) 比较。供条件/排序/聚合/索引归一跨模块复用
     */
    public static function compareNumeric(mixed $left, mixed $right): int
    {
        if (self::isLargeIntegerString($left) || self::isLargeIntegerString($right)) {
            return self::compareIntegerStrings((string) $left, (string) $right);
        }

        return (float) $left <=> (float) $right;
    }

    /**
     * 是否超过 float 精确范围的十进制整数字符串（位数 >= 16，含符号）
     */
    public static function isLargeIntegerString(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return preg_match('/^[+-]?\d+$/', $value) === 1 && strlen(ltrim($value, '+-')) >= 16;
    }

    /**
     * 十进制整数字符串精确比较（可含正负号与前导零）；无 bcmath 时按符号+长度+字典序退化
     */
    public static function compareIntegerStrings(string $a, string $b): int
    {
        if (function_exists('bccomp')) {
            return bccomp($a, $b);
        }

        $an = self::absDigits($a);
        $bn = self::absDigits($b);
        $aneg = str_starts_with(ltrim($a, ' '), '-');
        $bneg = str_starts_with(ltrim($b, ' '), '-');
        if ($aneg !== $bneg) {
            return $aneg ? -1 : 1;
        }
        $cmp = (strlen($an) <=> strlen($bn)) ?: ($an <=> $bn);

        return $aneg ? -$cmp : $cmp;
    }

    /**
     * 十进制整数字符串规范化：去正负号与前导零，返回纯数字（'0' 归一为 '0'）
     */
    public static function normalizeIntegerString(string $value): string
    {
        $digits = ltrim(ltrim($value, '+-0'), '0');

        return $digits === '' ? '0' : $digits;
    }

    /**
     * 取整数字符串的绝对值数字（去符号与前导零，空为 '0'）
     */
    private static function absDigits(string $value): string
    {
        return self::normalizeIntegerString($value);
    }

    /**
     * 将任意可接受输入归一为 int（不做范围校验）
     */
    private static function coerceInteger(mixed $value, ColumnSchema $column): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return self::floatToInt($value, $column);
        }
        if (is_string($value) && preg_match(self::NUMERIC_PATTERN, $value) === 1) {
            if (str_contains($value, '.')) {
                return self::floatToInt((float) $value, $column);
            }
            $int = (int) $value;
            // 与原串比对检测溢出（超出 PHP 整型范围的字符串强转会饱和）
            $digits = ltrim(ltrim($value, '+-'), '0');
            if ($digits === '') {
                $digits = '0';
            }
            if ($digits !== (string) abs($int)) {
                throw new TypeException(self::fail($column, "值 {$value} 超出整型表示范围"));
            }

            return $int;
        }

        throw new TypeException(self::fail($column, '无法转换为整型: ' . self::describe($value)));
    }

    /**
     * 浮点数仅接受整数值（fmod==0），并防溢出
     */
    private static function floatToInt(float $value, ColumnSchema $column): int
    {
        if (fmod($value, 1.0) !== 0.0) {
            throw new TypeException(self::fail($column, "非整数值 {$value} 不能转换为整型"));
        }
        if ($value > (float) PHP_INT_MAX || $value < (float) (-PHP_INT_MAX)) {
            throw new TypeException(self::fail($column, "值 {$value} 超出整型表示范围"));
        }

        return (int) $value;
    }

    /**
     * 各整型取值范围（按契约：INT 为 ±2147483647；BIGINT unsigned 上限同为 PHP_INT_MAX，文档化限制）
     *
     * @return array{0: int, 1: int}
     */
    private static function integerRange(ColumnSchema $column): array
    {
        return match ($column->type) {
            DataType::TINYINT => $column->unsigned ? [0, 255] : [-127, 127],
            DataType::SMALLINT => $column->unsigned ? [0, 65535] : [-32767, 32767],
            DataType::INT => $column->unsigned ? [0, 4294967295] : [-2147483647, 2147483647],
            DataType::BIGINT => $column->unsigned ? [0, PHP_INT_MAX] : [-PHP_INT_MAX, PHP_INT_MAX],
            default => [PHP_INT_MIN, PHP_INT_MAX],
        };
    }

    // ---- 浮点 ----

    /**
     * FLOAT/DOUBLE：bool/int/float → float；纯数字字符串 → float
     */
    private static function castFloat(mixed $value, ColumnSchema $column): float
    {
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_float($value)) {
            return $value;
        }
        if (is_string($value) && preg_match(self::NUMERIC_PATTERN, $value) === 1) {
            return (float) $value;
        }

        throw new TypeException(self::fail($column, '无法转换为浮点数: ' . self::describe($value)));
    }

    // ---- DECIMAL ----

    /**
     * DECIMAL(P,S)：规范化为恰好 S 位小数的字符串；有效数字 > P 或小数位 > S 抛异常
     */
    private static function castDecimal(mixed $value, ColumnSchema $column): string
    {
        if (is_int($value)) {
            $number = (string) $value;
        } elseif (is_float($value)) {
            $number = self::floatToDecimalString($value);
        } elseif (is_string($value) && preg_match(self::NUMERIC_PATTERN, $value) === 1) {
            $number = $value;
        } else {
            throw new TypeException(self::fail($column, '无法转换为 DECIMAL: ' . self::describe($value)));
        }

        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $number, $match) !== 1) {
            throw new TypeException(self::fail($column, "无法解析的数值: {$number}"));
        }
        $sign = $match[1] === '-' ? '-' : '';
        $intPart = $match[2];
        $decPart = $match[3] ?? '';

        if ($column->unsigned && $sign === '-') {
            throw new TypeException(self::fail($column, "unsigned 列不接受负数: {$value}"));
        }

        $precision = $column->precision ?? 65;
        $scale = $column->scale ?? 0;

        $intDigits = ltrim($intPart, '0');
        $significant = strlen($intDigits) + strlen($decPart);
        if ($significant > $precision) {
            throw new TypeException(
                self::fail($column, "有效数字位数 {$significant} 超过精度 P={$precision}")
            );
        }
        if (strlen($decPart) > $scale) {
            throw new TypeException(self::fail($column, "小数位数 " . strlen($decPart) . " 超过标度 S={$scale}"));
        }

        $intNorm = $intDigits === '' ? '0' : $intDigits;
        if ($scale === 0) {
            return $sign . $intNorm;
        }

        return $sign . $intNorm . '.' . str_pad($decPart, $scale, '0', STR_PAD_RIGHT);
    }

    /**
     * 浮点转十进制字符串，展开科学计数法（如 1.0E+25）
     */
    private static function floatToDecimalString(float $value): string
    {
        $string = (string) $value;
        if (!str_contains($string, 'E') && !str_contains($string, 'e')) {
            return $string;
        }

        $parts = preg_split('/[eE]/', $string);
        $mantissa = $parts[0];
        $exponent = (int) $parts[1];
        $negative = str_starts_with($mantissa, '-');
        if ($negative || str_starts_with($mantissa, '+')) {
            $mantissa = substr($mantissa, 1);
        }
        [$intPart, $decPart] = str_contains($mantissa, '.')
            ? explode('.', $mantissa, 2)
            : [$mantissa, ''];

        $digits = $intPart . $decPart;
        $point = strlen($intPart) + $exponent;
        if ($point <= 0) {
            $digits = '0.' . str_repeat('0', -$point) . $digits;
        } elseif ($point >= strlen($digits)) {
            $digits .= str_repeat('0', $point - strlen($digits));
        } else {
            $digits = substr($digits, 0, $point) . '.' . substr($digits, $point);
        }

        return ($negative ? '-' : '') . $digits;
    }

    // ---- 布尔 ----

    /**
     * BOOLEAN：true/false→1/0；int 0/1；字符串 "0"/"1"
     */
    private static function castBoolean(mixed $value, ColumnSchema $column): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value;
        }
        if (is_string($value) && ($value === '0' || $value === '1')) {
            return (int) $value;
        }

        throw new TypeException(self::fail($column, '无法转换为 BOOLEAN: ' . self::describe($value)));
    }

    // ---- 字符串 ----

    /**
     * CHAR/VARCHAR/TEXT：string 直接；int/float/bool 按 MySQL 风格强转；CHAR 不做空格填充（v1 文档化）
     */
    private static function castString(mixed $value, ColumnSchema $column, bool $enforceLength): string
    {
        if (is_string($value)) {
            $string = $value;
        } elseif (is_bool($value)) {
            $string = $value ? '1' : '0';
        } elseif (is_int($value)) {
            $string = (string) $value;
        } elseif (is_float($value)) {
            $string = (string) $value;
        } else {
            throw new TypeException(self::fail($column, '无法转换为字符串: ' . self::describe($value)));
        }

        if ($enforceLength && $column->length !== null) {
            $length = function_exists('mb_strlen') ? mb_strlen($string) : strlen($string);
            if ($length > $column->length) {
                throw new TypeException(
                    self::fail($column, "长度 {$length} 超过上限 {$column->length}")
                );
            }
        }

        return $string;
    }

    // ---- 枚举 ----

    /**
     * ENUM：标量转 string 后必须严格等于某成员
     */
    private static function castEnum(mixed $value, ColumnSchema $column): string
    {
        $members = $column->enumValues;
        if ($members === null) {
            throw new TypeException(self::fail($column, '缺少枚举成员定义'));
        }
        if (!is_scalar($value)) {
            throw new TypeException(self::fail($column, '枚举值必须为标量: ' . self::describe($value)));
        }
        $string = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        if (!in_array($string, $members, true)) {
            throw new TypeException(self::fail($column, "值 {$string} 不是合法枚举成员"));
        }

        return $string;
    }

    // ---- 二进制 / JSON / SET ----

    /**
     * BINARY(N)：按字节长度校验（不做 \0 填充，文档化限制；与 CHAR 空格填充缺失一致）
     */
    private static function castBinary(mixed $value, ColumnSchema $column): string
    {
        $string = self::castString($value, $column, false);
        if ($column->length !== null && strlen($string) > $column->length) {
            throw new TypeException(
                self::fail($column, "字节长度 " . strlen($string) . " 超过上限 {$column->length}")
            );
        }

        return $string;
    }

    /**
     * JSON：数组/标量原样存储（校验可 JSON 编码），JSON 字符串解码后存储；
     * 解码失败抛 TypeException（JSON 列禁止存非 JSON 文本）
     */
    private static function castJson(mixed $value, ColumnSchema $column): mixed
    {
        if (is_array($value)) {
            if (json_encode($value, JSON_UNESCAPED_UNICODE) === false) {
                throw new TypeException(self::fail($column, '数组无法 JSON 编码: ' . json_last_error_msg()));
            }

            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new TypeException(self::fail($column, '非法的 JSON 字符串: ' . json_last_error_msg()));
            }

            return $decoded;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        throw new TypeException(self::fail($column, '无法转换为 JSON: ' . self::describe($value)));
    }

    /**
     * SET：逗号分隔字符串或成员数组；每项必须是合法成员，去重保持首见顺序；
     * 空数组/空串表示空集（存 ''）
     */
    private static function castSet(mixed $value, ColumnSchema $column): string
    {
        $members = $column->enumValues;
        if ($members === null) {
            throw new TypeException(self::fail($column, '缺少 SET 成员定义'));
        }
        if ($value === '') {
            return '';
        }
        if (is_array($value)) {
            $items = array_values($value);
            if ($items === []) {
                return '';
            }
        } elseif (is_string($value)) {
            $items = explode(',', $value);
        } else {
            throw new TypeException(self::fail($column, 'SET 值必须为数组或逗号分隔字符串: ' . self::describe($value)));
        }

        foreach ($items as $item) {
            if (!is_string($item) || !in_array($item, $members, true)) {
                throw new TypeException(
                    self::fail($column, "值 " . var_export($item, true) . " 不是合法 SET 成员")
                );
            }
        }

        $unique = [];
        foreach ($items as $item) {
            if (!in_array($item, $unique, true)) {
                $unique[] = $item;
            }
        }

        return implode(',', $unique);
    }

    // ---- 时间 ----

    /**
     * DATE：'Y-m-d' 严格往返校验；DateTimeInterface → format('Y-m-d')
     */
    private static function castDate(mixed $value, ColumnSchema $column): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value)) {
            $datetime = DateTime::createFromFormat('!Y-m-d', $value);
            if ($datetime !== false && $datetime->format('Y-m-d') === $value) {
                return $value;
            }
        }

        throw new TypeException(self::fail($column, "需要 'Y-m-d' 格式日期，得到: " . self::describe($value)));
    }

    /**
     * DATETIME/TIMESTAMP：'Y-m-d H:i:s' 严格往返校验
     */
    private static function castDateTime(mixed $value, ColumnSchema $column): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_string($value)) {
            $datetime = DateTime::createFromFormat('!Y-m-d H:i:s', $value);
            if ($datetime !== false && $datetime->format('Y-m-d H:i:s') === $value) {
                return $value;
            }
        }

        throw new TypeException(
            self::fail($column, "需要 'Y-m-d H:i:s' 格式时间，得到: " . self::describe($value))
        );
    }

    // ---- 辅助 ----

    /**
     * 构造含列名与类型的异常消息
     */
    private static function fail(ColumnSchema $column, string $reason): string
    {
        return "列 {$column->name} ({$column->type->value}): {$reason}";
    }

    /**
     * 描述输入值（用于异常消息）
     */
    private static function describe(mixed $value): string
    {
        if (is_scalar($value)) {
            return var_export($value, true) . ' (' . get_debug_type($value) . ')';
        }

        return get_debug_type($value);
    }
}
