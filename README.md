# Laravel Queue Inspector

Static analyzer for Laravel queue job configurations. Detects misconfigurations **before they cause production incidents** — duplicate job execution, permanent locks, silent retry storms.

Works without database, Redis, or any running services. Safe to run in CI/CD pipelines.

## The Problem

Laravel queue configuration is spread across three places: `config/queue.php`, `config/horizon.php`, and the job class itself. Laravel does not validate the relationships between these values. Common issues that silently break production:

- Job `timeout` longer than connection `retry_after` → job re-queued while still running → **duplicate execution**
- `ShouldBeUnique` without `uniqueFor()` → cache lock has no TTL → **permanent lock on worker crash**
- `tries > 1` without `backoff` → all retries fire immediately → **hammers downstream services**
- `WithoutOverlapping` without `expireAfter()` → lock never releases on crash → **job stuck forever**

## Installation

```bash
composer require yangusik/laravel-queue-inspector --dev
```

The service provider is auto-discovered. No configuration required.

## Usage

### Artisan command (development)

```bash
php artisan queue:analyze
```

### Standalone binary (CI/CD — no Laravel bootstrap required)

```bash
vendor/bin/queue-inspector
```

The standalone binary only loads `vendor/autoload.php`. It does **not** bootstrap Laravel, connect to a database, or require any running services.

### Composer scripts

Add to your `composer.json`:

```json
{
    "scripts": {
        "queue:analyze": "vendor/bin/queue-inspector",
        "queue:analyze:strict": "vendor/bin/queue-inspector --strict"
    }
}
```

Then run:

```bash
composer queue:analyze
```

## Options

| Option | Short | Description |
|---|---|---|
| `--strict` | | Exit with code 1 if errors found. Use in CI/CD. |
| `--verbose` | `-v` | Show where each value comes from (job class, queue.php, horizon.php) |
| `--format=json` | | JSON output. Useful for piping into `jq` or other tools. |
| `--no-guzzle` | | Skip Guzzle/HTTP timeout check (AST-based, best-effort) |
| `--exclude-ns=` | | Exclude a namespace from discovery. Repeatable. |
| `--path=` | | Path to Laravel project root. Defaults to `getcwd()`. |

## Output

```
ProcessReport [App\Jobs\ProcessReport]
  ✗ timeout (180s) >= retry_after (90s) — job will be re-queued before it finishes
  ⚠ tries=5 but no backoff — all retries execute immediately

BaseClusterJob [App\Jobs\Clusters\BaseClusterJob]
  ✓ timeout (600s) < retry_after (660s) — OK
  ✓ ShouldBeUnique with uniqueFor=3600s — lock expires on crash

SendInvoiceJob [App\Jobs\SendInvoiceJob]
  ⚠ timeout not set — inherits from connection "redis", PCNTL required for termination
  ⚠ WithoutOverlapping without expireAfter() — if worker crashes, lock may never release

Analyzed 12 job(s) — 1 error(s), 3 warning(s)
```

With `--verbose` / `-v`, each result shows where the value came from:

```
ProcessReport [App\Jobs\ProcessReport]
  ✗ timeout (180s) >= retry_after (90s) — job will be re-queued before it finishes
    · timeout from job class, retry_after from queue.php
```

## Checks

### ✗ timeout >= retry_after

`retry_after` is a **crash recovery** mechanism. When a worker picks up a job, it moves it to a `:reserved` sorted set with `score = now + retry_after`. If the job does not finish within `retry_after` seconds, the infrastructure moves it back to the main queue — **regardless of whether it is still running**.

If `timeout >= retry_after`, your job will be re-queued while still executing. Both copies run simultaneously.

**Fix:** set `$timeout` on the job class to be less than `retry_after`.

```php
public int $timeout = 60; // must be less than retry_after in queue.php
```

---

### ⚠ timeout not set

If `$timeout` is not defined on the job, Laravel inherits the value from the queue connection config. Enforcing this timeout requires the PCNTL extension. Without it, the timeout is silently ignored.

**Fix:** explicitly set `$timeout` on each job class.

---

### ⚠ tries without backoff

If `$tries > 1` and no `backoff` property or `backoff()` method is defined, all retry attempts fire immediately one after another. This hammers the downstream service on failure.

**Fix:** define a backoff strategy.

```php
public function backoff(): array
{
    return [30, 60, 120]; // seconds between retries
}
```

---

### ⚠ WithoutOverlapping without expireAfter()

`WithoutOverlapping` uses a cache atomic lock. If the worker is killed (OOM, SIGKILL, deployment restart), the lock is not released. Without `expireAfter()`, the lock has no TTL and the job cannot run again until the lock is manually cleared.

This is **not a Horizon-specific issue** — it affects all queue drivers.

**Fix:**

```php
public function middleware(): array
{
    return [
        (new WithoutOverlapping($this->jobKey))
            ->expireAfter(300), // lock TTL in seconds
    ];
}
```

---

### ⚠ ShouldBeUnique without uniqueFor()

From `Illuminate\Bus\UniqueLock::acquire()`:

```php
$cache->lock($key, $uniqueFor)->get();
```

When `uniqueFor` is `0` (the default when not defined), the Redis lock is created with no expiration (`TTL = 0 = permanent`). If the worker crashes before the job completes, the lock remains forever. The job can never be dispatched again until the lock is manually deleted from the cache.

**Fix:**

```php
public function uniqueFor(): int
{
    return 3600; // lock expires after 1 hour even on crash
}
```

---

### ⚠ Guzzle/HTTP client without timeout (best-effort)

Detects direct instantiation of `GuzzleHttp\Client` or `new Client()` inside the job class without a `timeout` option. An HTTP call without a timeout can cause the job to hang indefinitely, blocking the worker.

**Note:** this check only detects direct instantiation. Clients injected via the service container are not detected. Use `--no-guzzle` to disable.

**Fix:**

```php
$client = new Client(['timeout' => 30]);
```

## Ignoring jobs

Mark a job to be skipped by the analyzer using any of the following:

```php
// PHPDoc annotation
/** @deprecated */
class OldJob implements ShouldQueue { ... }

/** @queue-inspector-ignore */
class SpecialJob implements ShouldQueue { ... }

// PHP attribute (JetBrains PhpStorm / native PHP 8.4)
#[\Deprecated]
class OldJob implements ShouldQueue { ... }

// Custom attribute (no import needed, resolved by name)
#[QueueInspectorIgnore]
class SpecialJob implements ShouldQueue { ... }
```

## Excluding namespaces

To exclude entire namespaces (e.g. notifications, events):

```bash
# CLI
vendor/bin/queue-inspector --exclude-ns="App\Notifications" --exclude-ns="App\Events"

# Artisan
php artisan queue:analyze --exclude-ns="App\Notifications"
```

## CI/CD Integration

### GitHub Actions

```yaml
- name: Analyze queue jobs
  run: vendor/bin/queue-inspector --strict
```

### GitLab CI

```yaml
queue-inspector:
  script:
    - vendor/bin/queue-inspector --strict
```

### JSON output for custom reporting

```bash
vendor/bin/queue-inspector --format=json | jq '.[] | select(.hasErrors == true)'
```

## How it works

- Reads `vendor/composer/autoload_classmap.php` to discover all classes in `app/`
- Filters classes that implement `ShouldQueue`
- Parses each file with [nikic/php-parser](https://github.com/nikic/PHP-Parser) (AST) — no `require`, no reflection
- Reads `config/queue.php` and `config/horizon.php` directly (plain PHP arrays, no Laravel bootstrap)
- Resolves the effective value of each setting and its source across the config chain
- Follows class inheritance to resolve settings from parent job classes

No database connection. No Redis connection. No `.env` loading. Safe to run anywhere PHP is available.

## Requirements

- PHP 8.1+
- Laravel 10+
- `nikic/php-parser` ^5.0 (installed automatically)

## License

MIT
