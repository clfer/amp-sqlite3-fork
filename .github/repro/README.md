# Startup "database is locked" repro (not for upstream)

- `close-window.php [n]`: after `SqliteConnectionPool::close()` returns, the SQLite child still has the file open
  (amphp/sql-common v2.0.5 `ConnectionPool::close()` only starts `async(fn () => $connection->close())->ignore()`).
- `reuse-after-close.php [busy_timeout]`: use a pool, close it, use a new pool on the same file at once. With
  `slow-close-v1.1.0.patch` applied and `REPRO_CLOSE_HOLD_MS=6000`, the old child holds the exclusive file lock while it
  closes and the new pool fails after ~5 s with `Could not start the SQLite child process: Unable to prepare statement:
  database is locked`. The patch is a test-only injection that simulates a slow last-connection checkpoint.
- `reuse-after-awaited-close.php`: same, but waits for the old child to finish closing first: succeeds.
- `setup-idempotent-timed.php [seconds] [cpu] [io]`: Symfony's `testSetupIsIdempotent` shape without Symfony; prints
  how long each failing setup ran.

Workflow: `.github/workflows/windows-repro.yml` (windows-2022, PHP 8.4.1 x86, v1.1.0 vs candidate patch).
