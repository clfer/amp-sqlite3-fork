<?php

declare(strict_types=1);

/*
 * Stress test for concurrent worker startup on a fresh database file.
 *
 * Usage: php startup-stress.php [iterations=50] [concurrency=8] [cpu-burners=0] [io-burners=0]
 *
 * Each iteration starts <concurrency> connections at once (like two Symfony AmpSql transports calling setup()
 * concurrently) on a new file, runs one statement on each, and closes them. Failures are grouped by message and by the
 * child-side frame that threw, then a "RESULT failures=X attempts=Y" line is printed.
 */

use Amp\Parallel\Context\ContextPanicError;
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnector;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;
use function Amp\async;
use function Amp\Future\awaitAll;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

$iterations = (int) ($argv[1] ?? 50);
$concurrency = (int) ($argv[2] ?? 8);
$burners = (int) ($argv[3] ?? 0);
$ioBurners = (int) ($argv[4] ?? 0);

$burnerProcesses = [];
for ($i = 0; $i < $burners; ++$i) {
    $burnerProcesses[] = \proc_open([\PHP_BINARY, '-r', '$e = microtime(true) + 3600; while (microtime(true) < $e) {}'], [], $pipes);
}
// Heavy file I/O: each burner rewrites and fsyncs a 4 MiB file in the temp directory, and churns small files
for ($i = 0; $i < $ioBurners; ++$i) {
    $burnerProcesses[] = \proc_open([\PHP_BINARY, '-r', <<<'IO'
        $f = sys_get_temp_dir() . '/amp-sqlite-io-' . getmypid(); $chunk = random_bytes(65536); $e = microtime(true) + 3600;
        while (microtime(true) < $e) {
            $h = fopen($f, 'w'); for ($j = 0; $j < 64; ++$j) { fwrite($h, $chunk); } fflush($h); fsync($h); fclose($h);
            for ($j = 0; $j < 20; ++$j) { file_put_contents("$f-$j", $chunk); @unlink("$f-$j"); }
        }
        IO], [], $pipes);
}

$directory = \sys_get_temp_dir() . '/amp-sqlite-stress-' . \bin2hex(\random_bytes(4));
@\mkdir($directory);
$connector = new SqliteConnector();
$failures = [];
$failureCount = 0;
$attempts = 0;
$start = \microtime(true);

for ($iteration = 0; $iteration < $iterations; ++$iteration) {
    $path = "{$directory}/db{$iteration}.sqlite";
    $config = (new SqliteConfig($path))->withBusyTimeout(5000)->withTransactionMode(SqliteTransactionMode::Immediate);

    $futures = [];
    for ($i = 0; $i < $concurrency; ++$i) {
        $futures[] = async(static function () use ($connector, $config) {
            $connection = $connector->connect($config);
            try {
                $connection->query('SELECT 1');
            } finally {
                $connection->close();
            }
        });
    }

    [$errors] = awaitAll($futures);
    $attempts += $concurrency;
    foreach ($errors as $error) {
        ++$failureCount;
        $where = '';
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof ContextPanicError) {
                \preg_match_all('/WorkerProcess->(\w+)\(/', $e->getOriginalTraceAsString(), $frames);
                $where = ' [' . $e->getOriginalClassName() . ' code=' . $e->getOriginalCode() . ' frames=' . \implode('<', \array_unique($frames[1])) . ']';
                break;
            }
        }
        $key = \get_class($error) . ': ' . $error->getMessage() . $where;
        $failures[$key] = ($failures[$key] ?? 0) + 1;
    }

    foreach (\glob($path . '*') ?: [] as $file) {
        @\unlink($file);
    }
}

foreach ($burnerProcesses as $process) {
    \proc_terminate($process, 9);
}
@\rmdir($directory);

\arsort($failures);
foreach ($failures as $message => $count) {
    echo "{$count}x {$message}\n";
}
\printf("RESULT failures=%d attempts=%d iterations=%d concurrency=%d burners=%d io=%d seconds=%.1f\n", $failureCount, $attempts, $iterations, $concurrency, $burners, $ioBurners, \microtime(true) - $start);
