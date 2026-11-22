<?php
require \dirname(__DIR__, 2) . '/vendor/autoload.php';
use Fabpot\Amp\Sqlite\{SqliteConfig, SqliteConnectionPool, SqliteTransactionMode};
use function Amp\delay;
$dir = sys_get_temp_dir() . '/cw-' . getmypid(); @mkdir($dir);
$stillOpen = 0; $n = (int)($argv[1] ?? 20); $waits = [];
for ($i = 0; $i < $n; $i++) {
    $f = "$dir/db$i.sqlite";
    $pool = new SqliteConnectionPool((new SqliteConfig($f))->withBusyTimeout(5000)->withTransactionMode(SqliteTransactionMode::Immediate));
    $pool->execute('CREATE TABLE IF NOT EXISTS m (id INTEGER PRIMARY KEY, body TEXT)');
    $pool->execute('INSERT INTO m (body) VALUES (?)', ['x']);
    $pool->close();
    $t = hrtime(true);
    if (file_exists("$f-wal")) { $stillOpen++; }
    while (file_exists("$f-wal")) { delay(0.001); if ((hrtime(true)-$t) > 10e9) break; }
    $waits[] = (hrtime(true)-$t)/1e6;
}
printf("after pool->close() returned: WAL still present (child not closed yet) in %d/%d; time until child closed: median %.1f ms, max %.1f ms\n", $stillOpen, $n, (sort($waits) ?: 0) + $waits[intdiv($n,2)], max($waits));
