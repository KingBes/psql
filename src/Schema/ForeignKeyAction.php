<?php

declare(strict_types=1);

namespace Kingbes\Psql\Schema;

/**
 * 外键引用策略：删除/更新被引用行时对引用行的处理方式
 */
enum ForeignKeyAction: string
{
    case RESTRICT = 'RESTRICT';
    case CASCADE = 'CASCADE';
    case SET_NULL = 'SET_NULL';
    case SET_DEFAULT = 'SET_DEFAULT';
}
