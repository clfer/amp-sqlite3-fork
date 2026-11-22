<?php
// Symfony testTheTransportCanBeUsedAgainAfterBeingClosed shape: use a pool, close() it, use a new pool on the same file
require \dirname(__DIR__, 2) . '/vendor/autoload.php';
use Fabpot\Amp\Sqlite\{SqliteConfig, SqliteConnectionPool, SqliteTransactionMode};
$timeout = (int)($argv[1] ?? 5000);
$f = sys_get_temp_dir() . '/reuse-' . getmypid() . '.sqlite';
$cfg = (new SqliteConfig($f))->withBusyTimeout($timeout)->withTransactionMode(SqliteTransactionMode::Immediate);
$p1 = new SqliteConnectionPool($cfg);
$p1->execute('CREATE TABLE IF NOT EXISTS m (id INTEGER PRIMARY KEY, body TEXT)');
$p1->execute('INSERT INTO m (body) VALUES (?)', ['first']);
$p1->close();
$t = hrtime(true);
try {
    $p2 = new SqliteConnectionPool($cfg);
    $p2->execute('INSERT INTO m (body) VALUES (?)', ['second']);
    printf("OK second use after %d ms\n", (hrtime(true)-$t)/1e6);
    $p2->close();
} catch (Throwable $e) {
    printf("FAIL after %d ms: %s: %s\n", (hrtime(true)-$t)/1e6, get_class($e), $e->getMessage());
}
foreach (['', '-wal', '-shm'] as $s) @unlink($f . $s);
