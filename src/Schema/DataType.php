<?php

declare(strict_types=1);

namespace Kingbes\Psql\Schema;

/**
 * 支持的列数据类型
 */
enum DataType: string
{
    case TINYINT = 'TINYINT';
    case SMALLINT = 'SMALLINT';
    case INT = 'INT';
    case BIGINT = 'BIGINT';
    case DECIMAL = 'DECIMAL';
    case FLOAT = 'FLOAT';
    case DOUBLE = 'DOUBLE';
    case BOOLEAN = 'BOOLEAN';
    case CHAR = 'CHAR';
    case VARCHAR = 'VARCHAR';
    case TEXT = 'TEXT';
    case ENUM = 'ENUM';
    case DATE = 'DATE';
    case DATETIME = 'DATETIME';
    case TIMESTAMP = 'TIMESTAMP';
    case JSON = 'JSON';
    case BLOB = 'BLOB';
    case BINARY = 'BINARY';
    case SET = 'SET';

    /**
     * 是否整型（四种）
     */
    public function isInteger(): bool
    {
        return match ($this) {
            self::TINYINT, self::SMALLINT, self::INT, self::BIGINT => true,
            default => false,
        };
    }

    /**
     * 是否字符串类型（CHAR/VARCHAR/TEXT/BINARY/SET）
     */
    public function isString(): bool
    {
        return match ($this) {
            self::CHAR, self::VARCHAR, self::TEXT, self::BINARY, self::SET => true,
            default => false,
        };
    }

    /**
     * 是否时间类型（DATE/DATETIME/TIMESTAMP）
     */
    public function isTemporal(): bool
    {
        return match ($this) {
            self::DATE, self::DATETIME, self::TIMESTAMP => true,
            default => false,
        };
    }

    /**
     * 是否浮点类型（FLOAT/DOUBLE，不含 DECIMAL）
     */
    public function isFloat(): bool
    {
        return match ($this) {
            self::FLOAT, self::DOUBLE => true,
            default => false,
        };
    }
}
