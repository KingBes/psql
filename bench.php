<?php

declare(strict_types=1);

/**
 * 存储格式基准：JsonFileEngine vs PhpSerializeEngine vs PagedJsonEngine（分页增量写盘）
 *
 * 用法：php bench.php
 */

require __DIR__ . '/vendor/autoload.php';

use Kingbes\Psql\Schema\Blueprint;
use Kingbes\Psql\Schema\TableSchema;
use Kingbes\Psql\Storage\FileEngine;
use Kingbes\Psql\Storage\JsonFileEngine;
use Kingbes\Psql\Storage\PagedJsonEngine;
use Kingbes\Psql\Storage\PhpSerializeEngine;

const DB = 'main';
const TABLE = 'bench';

function makeSchema(): TableSchema
{
    $b = new Blueprint();
    $b->id();
    $b->varchar('name', 50);
    $b->varchar('email', 100);
    $b->tinyint('age')->unsigned();
    $b->decimal('balance', 12, 2);
    $b->tinyint('active');
    $b->varchar('city', 40);
    $b->text('note');

    return $b->toSchema(TABLE);
}

function makeRows(int $n): array
{
    mt_srand(42);
    $cities = ['北京', '上海', '广州', '深圳', '杭州', '成都'];
    $rows = [];
    for ($i = 1; $i <= $n; $i++) {
        $rows[] = [
            'id' => $i,
            'name' => 'user_' . substr(md5((string) $i), 0, 8),
            'email' => 'u' . $i . '@example.com',
            'age' => 18 + mt_rand(0, 60),
            'balance' => number_format(mt_rand(0, 1000000) / 100, 2, '.', ''),
            'active' => mt_rand(0, 1),
            'city' => $cities[mt_rand(0, 5)],
            'note' => str_repeat('备注内容测试数据', 5) . $i,
        ];
    }

    return $rows;
}

function ms(callable $fn): float
{
    $t = hrtime(true);
    $fn();

    return (hrtime(true) - $t) / 1e6;
}

function cleanup(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? cleanup($path) : @unlink($path);
        if (is_dir($path)) {
            @rmdir($path);
        }
    }
    @rmdir($dir);
}

function extOf(string $class): string
{
    return match ($class) {
        JsonFileEngine::class => '.json',
        PhpSerializeEngine::class => '.bin',
        default => '.meta.json', // PagedJsonEngine：以 meta 文件为代表
    };
}

/**
 * 表数据占用磁盘大小：单文件引擎取表文件；分页引擎汇总 meta + 全部页文件
 */
function tableSizeOf(string $class, string $dir): int
{
    $file = $dir . '/' . DB . '/' . TABLE . extOf($class);
    if ($class !== PagedJsonEngine::class) {
        return filesize($file) ?: 0;
    }
    $total = 0;
    $prefix = TABLE . '.';
    foreach (scandir(dirname($file)) ?: [] as $entry) {
        if (str_starts_with($entry, $prefix)) {
            $total += filesize(dirname($file) . '/' . $entry) ?: 0;
        }
    }

    return $total;
}

function fmt(float $ms): string
{
    return number_format($ms, 1) . ' ms';
}

/** @return array<string, float|string> */
function runEngine(string $class, int $rowCount, int $updates): array
{
    $dir = sys_get_temp_dir() . '/psql-bench-' . uniqid();
    $schema = makeSchema();
    $rows = makeRows($rowCount);

    $engine = new $class($dir);
    $engine->createDatabase(DB);
    $engine->createTable(DB, $schema);

    // 1. 批量写入（建表 + 全量 rows 落盘一次）
    $tWrite = ms(fn () => $engine->writeRows(DB, TABLE, $rows));

    $size = tableSizeOf($class, $dir);

    // 2. 冷加载：新实例从磁盘解码全表
    $tLoad = 0.0;
    $loaded = ms(function () use ($class, $dir, &$tLoad, $rowCount) {
        $fresh = new $class($dir);
        $tLoad = ms(fn () => $fresh->readRows(DB, TABLE));
        // 触发实际解码（readRows 内部已解码，再取一次行数确认）
        $fresh->readRows(DB, TABLE);
    });
    unset($loaded);

    // 3. 单行更新 × N（热区 = 表头前 10 行，模拟"按小主键更新"的典型负载；
    //    全表重写引擎与更新位置无关；分页增量引擎逐页 diff 后同样只脏所在页）
    $current = $engine->readRows(DB, TABLE);
    $tUpdate = ms(function () use ($engine, &$current, $updates) {
        for ($i = 0; $i < $updates; $i++) {
            $current[$i % 10]['age'] = 20 + $i;
            $engine->writeRows(DB, TABLE, $current);
        }
    });

    cleanup($dir);

    return [
        'write' => $tWrite,
        'load' => $tLoad,
        'updates' => $tUpdate,
        'size' => number_format($size / 1024 / 1024, 2) . ' MB',
    ];
}

printf("PHP %s | 行数据：8 列混合类型（含中文/decimal/时间串风格）\n\n", PHP_VERSION);

// smoke 模式（CI 用）：仅 2000 行、单行更新 20 次，验证脚本可跑通即可，不等全量
$rowCounts = in_array('--smoke', $argv, true) ? [2000] : [10000, 50000];

foreach ($rowCounts as $rowCount) {
    $updates = in_array('--smoke', $argv, true) ? 20 : 100;
    printf("=== %s 行 ===\n", number_format($rowCount));
    printf("%-14s %12s %12s %16s %10s\n", '引擎', '批量写入', '冷加载', "单行更新×{$updates}", '文件大小');
    foreach ([JsonFileEngine::class, PhpSerializeEngine::class, PagedJsonEngine::class] as $class) {
        $r = runEngine($class, $rowCount, $updates);
        printf(
            "%-14s %12s %12s %16s %10s\n",
            basename(str_replace('\\', '/', $class)),
            fmt($r['write']),
            fmt($r['load']),
            fmt($r['updates']),
            $r['size']
        );
    }
    echo "\n";
}
