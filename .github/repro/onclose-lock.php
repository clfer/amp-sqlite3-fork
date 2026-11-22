<?php
// Await the pool's onClose, then check whether the old SQLite child still holds the file lock, then reuse the file.
// Usage: php onclose-lock.php <vendor-root> [busy_timeout=5000]; REPRO_CLOSE_HOLD_MS sets the injected hold in the child's close.
require ($argv[1] ?? \dirname(__DIR__, 2)) . '/vendor/autoload.php';
use Amp\DeferredFuture;
use Fabpot\Amp\Sqlite\{SqliteConfig, SqliteConnectionPool, SqliteTransactionMode};
$timeout = (int) ($argv[2] ?? 5000);
$f = sys_get_temp_dir() . '/onclose-' . getmypid() . '.sqlite';
$cfg = (new SqliteConfig($f))->withBusyTimeout($timeout)->withTransactionMode(SqliteTransactionMode::Immediate);
$p1 = new SqliteConnectionPool($cfg);
$p1->execute('CREATE TABLE IF NOT EXISTS m (id INTEGER PRIMARY KEY, body TEXT)');
$p1->execute('INSERT INTO m (body) VALUES (?)', ['first']);
$closed = new DeferredFuture();
$p1->onClose(static fn () => $closed->complete());
$t = hrtime(true);
$p1->close();
$closed->getFuture()->await();
$onCloseMs = (hrtime(true) - $t) / 1e6;
// Lock probe: a plain connection with busy_timeout 0 must read the schema (needs a shared lock)
$probe = new SQLite3($f); $probe->enableExceptions(true); $probe->busyTimeout(0);
try { $probe->querySingle('SELECT count(*) FROM sqlite_schema'); $lock = 'released'; } catch (Throwable $e) { $lock = 'STILL HELD (' . $e->getMessage() . ')'; }
$probe->close();
printf("hold=%sms onClose fired after %.0f ms; file lock at onClose: %s; WAL present: %s\n", getenv('REPRO_CLOSE_HOLD_MS') ?: 0, $onCloseMs, $lock, file_exists("$f-wal") ? 'yes' : 'no');
$t = hrtime(true);
try {
    $p2 = new SqliteConnectionPool($cfg);
    $p2->execute('INSERT INTO m (body) VALUES (?)', ['second']);
    printf("reuse after awaited onClose: OK after %d ms\n", (hrtime(true) - $t) / 1e6);
    $p2->close();
} catch (Throwable $e) {
    printf("reuse after awaited onClose: FAIL after %d ms: %s: %s\n", (hrtime(true) - $t) / 1e6, $e::class, $e->getMessage());
}
foreach (['', '-wal', '-shm'] as $s) @unlink($f . $s);
