<?php

declare(strict_types=1);

/*
 * Timed copy of Symfony's AmpSqlTransportTest::testSetupIsIdempotent (8.2), without Symfony.
 *
 * Usage: php setup-idempotent-timed.php [seconds=120] [cpu-burners=0] [io-burners=0]
 *
 * Each iteration uses a fresh file and two pools configured like AmpSqlTransportFactory (busy_timeout 5000, Immediate,
 * max 10 connections). Both pools run the transport setup concurrently (SELECT sqlite_version(), CREATE TABLE IF NOT
 * EXISTS, CREATE INDEX IF NOT EXISTS), then the first pool sets up again, inserts and counts, and both pools close.
 * Every failure is printed with how long the failing setup() ran, to tell a lock held for the whole busy timeout
 * (about 5 s) from an instant error.
 */

use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnectionPool;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;
use function Amp\async;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

$budget = (float) ($argv[1] ?? 120);
$cpuBurners = (int) ($argv[2] ?? 0);
$ioBurners = (int) ($argv[3] ?? 0);

$burners = [];
for ($i = 0; $i < $cpuBurners; ++$i) {
    $burners[] = \proc_open([\PHP_BINARY, '-r', '$e = microtime(true) + 3600; while (microtime(true) < $e) {}'], [], $pipes);
}
for ($i = 0; $i < $ioBurners; ++$i) {
    $burners[] = \proc_open([\PHP_BINARY, '-r', <<<'IO'
        $f = sys_get_temp_dir() . '/amp-sqlite-io-' . getmypid(); $chunk = random_bytes(65536); $e = microtime(true) + 3600;
        while (microtime(true) < $e) {
            $h = fopen($f, 'w'); for ($j = 0; $j < 64; ++$j) { fwrite($h, $chunk); } fflush($h); fsync($h); fclose($h);
            for ($j = 0; $j < 20; ++$j) { file_put_contents("$f-$j", $chunk); @unlink("$f-$j"); }
        }
        IO], [], $pipes);
}

function setupTransport(SqliteConnectionPool $pool): void
{
    $pool->query('SELECT sqlite_version() AS version')->fetchRow();
    $pool->query('CREATE TABLE IF NOT EXISTS messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name TEXT NOT NULL, created_at INTEGER NOT NULL, available_at INTEGER NOT NULL, delivered_at INTEGER DEFAULT NULL)');
    $pool->query('CREATE INDEX IF NOT EXISTS messenger_messages_available_idx ON messenger_messages (queue_name, available_at, delivered_at, id)');
}

/** @return array{float, ?\Throwable} */
function timed(\Closure $fn): array
{
    $start = \hrtime(true);
    try {
        $fn();

        return [(\hrtime(true) - $start) / 1e6, null];
    } catch (\Throwable $e) {
        return [(\hrtime(true) - $start) / 1e6, $e];
    }
}

$directory = \sys_get_temp_dir() . '/amp-sqlite-setup-' . \bin2hex(\random_bytes(4));
@\mkdir($directory);
$config = static fn (string $path) => (new SqliteConfig($path))->withBusyTimeout(5000)->withTransactionMode(SqliteTransactionMode::Immediate);

$iterations = 0;
$failures = 0;
$maxOk = 0.0;
$slowOk = 0;
$start = \microtime(true);

while (\microtime(true) - $start < $budget) {
    $path = "{$directory}/db{$iterations}.sqlite";
    $first = new SqliteConnectionPool($config($path), 10, transactionIsolation: SqliteTransactionMode::Immediate);
    $second = new SqliteConnectionPool($config($path), 10, transactionIsolation: SqliteTransactionMode::Immediate);

    try {
        $a = async(static fn () => timed(static fn () => setupTransport($first)));
        $b = async(static fn () => timed(static fn () => setupTransport($second)));
        foreach (['first' => $a->await(), 'second' => $b->await()] as $which => [$ms, $error]) {
            if ($error !== null) {
                ++$failures;
                $root = $error;
                while ($root->getPrevious() !== null) {
                    $root = $root->getPrevious();
                }
                \printf("FAIL iteration=%d transport=%s after=%.0fms %s: %s | root %s: %s\n", $iterations, $which, $ms, $error::class, $error->getMessage(), $root::class, $root->getMessage());
            } else {
                $maxOk = \max($maxOk, $ms);
                if ($ms >= 1000) {
                    ++$slowOk;
                }
            }
        }
        setupTransport($first);
        $first->execute("INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES ('b', '{}', 'default', 0, 0)");
        $first->execute('SELECT COUNT(id) FROM messenger_messages')->fetchRow();
    } catch (\Throwable $e) {
        \printf("FAIL iteration=%d after-setup %s: %s\n", $iterations, $e::class, $e->getMessage());
        ++$failures;
    } finally {
        $first->close();
        $second->close();
    }

    ++$iterations;
}

foreach ($burners as $process) {
    \proc_terminate($process, 9);
}
foreach (\glob("{$directory}/*") ?: [] as $file) {
    @\unlink($file);
}
@\rmdir($directory);

\printf("RESULT failures=%d iterations=%d setups=%d slowest_ok_setup=%.0fms ok_setups_over_1s=%d cpu=%d io=%d seconds=%.1f\n", $failures, $iterations, 2 * $iterations, $maxOk, $slowOk, $cpuBurners, $ioBurners, \microtime(true) - $start);
