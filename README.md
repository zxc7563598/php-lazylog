# hejunjie/lazylog

English ｜ [简体中文](./README.zh-CN.md)

A lightweight PHP logging library providing safe local log writing and remote exception reporting (synchronous and asynchronous).

> While working on [oh-shit-logger](https://github.com/zxc7563598/oh-shit-logger) (a Go project), I needed a unified way to collect PHP exception data, so I wrapped my go-to logging approach into a Composer package.

> This project has been parsed by [Zread](https://zread.ai/zxc7563598/php-lazylog) — click the link for a quick overview of the project structure and code logic.

## Features

- **Thread-safe local writes**: Append-based logging with file locking (`flock`) to prevent write conflicts
- **Automatic log rotation**: Rotates by line count (default 10,000) or file size (default 2 MB)
- **Asynchronous exception reporting**: Spawns a background PHP subprocess via `proc_open` to POST errors without blocking the main process
- **Synchronous reporting mode**: Designed for long-running frameworks like Webman and Swoole, avoiding resource buildup from frequent forking
- **Standardized exception format**: `formatThrowable()` outputs structured exception data, ready for message queues
- **Zero dependencies**: Requires only PHP ^7.4 || ^8.0, no third-party packages

## Requirements

- PHP ^7.4 || ^8.0

## Installation

```bash
composer require hejunjie/lazylog
```

## Quick Start

```php
use Hejunjie\Lazylog\Logger;

// Write a local log entry
Logger::write('/var/logs', 'app/error.log', 'Payment Failed', [
    'order_id' => 12345,
    'reason'   => 'Insufficient balance',
]);

// Async exception reporting (for PHP-FPM / CLI scripts)
try {
    // business logic
} catch (\Throwable $e) {
    Logger::reportAsync($e, 'https://error.example.com/collect', 'my-project');
}

// Sync exception reporting (for long-running frameworks)
try {
    // business logic
} catch (\Throwable $e) {
    Logger::reportSync($e, 'https://error.example.com/collect', 'my-project');
}
```

## API

### Logger::write()

Write a log entry to a local file. Directories are created automatically. Supports file rotation and concurrent-safe writes.

```php
Logger::write(
    string $basePath,   // Base log directory, e.g. /var/logs
    string $fileName,   // Log filename, may include subpaths like "error/app.log"
    string $title,      // Log title
    mixed  $content,    // Log content — string, array, or object
    int    $maxLines = 10000,  // Rotate when line count exceeds this
    int    $maxSizeKB = 2048   // Rotate when file size (KB) exceeds this
): void
```

Rotated files are renamed to `filename.Ymd_His`, e.g. `app.log.20260715_143022`.

### Logger::reportAsync()

Sends exception data to a remote endpoint by spawning a background PHP subprocess via `proc_open` / `exec`. The main process is not blocked.

```php
Logger::reportAsync(
    \Throwable $exception,                    // The caught exception
    string     $url,                          // Remote endpoint URL
    string     $project = 'unknown-project',  // Project identifier
    array      $context = [],                 // Additional context (request info, env vars, etc.)
    string     $phpBinary = 'php'             // Path to PHP binary
): void
```

> [!WARNING]
> Not recommended for long-running frameworks like Webman or Swoole. Frequent forking may lead to zombie processes or memory leaks. Use `reportSync()` or `formatThrowable()` + a message queue instead.

> [!NOTE]
> For low-frequency error reporting (e.g., a few times per minute), the overhead of async forking is negligible.

### Logger::reportSync()

Sends exception data synchronously via `file_get_contents()` with a stream context.

```php
Logger::reportSync(
    \Throwable $exception,                    // The caught exception
    string     $url,                          // Remote endpoint URL
    string     $project = 'unknown-project',  // Project identifier
    array      $context = [],                 // Additional context
    int        $timeout = 5                   // Timeout in seconds
): bool
```

Returns `true` on success, `false` on failure. Failures do not throw exceptions and will not interrupt the main flow.

### Logger::formatThrowable()

Formats an exception into a structured array, useful for pushing to a message queue or custom handling.

```php
$data = Logger::formatThrowable(
    \Throwable $exception,  // The caught exception
    string     $project,    // Project name
    array      $context = [ // Additional context
): array
```

Returned array structure:

```json
{
    "uuid":      "a1b2c3d4e5f6g7h8",
    "project":   "my-project",
    "level":     "error",
    "timestamp": "2026-07-15T14:30:22+08:00",
    "message":   "Call to undefined function foo()",
    "code":      0,
    "file":      "/app/src/Service.php",
    "line":      42,
    "trace":     [{ "file": "...", "line": 12, "function": "foo", "class": "Bar" }],
    "context":   {},
    "server": {
        "hostname":    "web-01",
        "ip":          "10.0.0.1",
        "php_version": "8.1.0"
    }
}
```

## Choosing an Approach

| Environment | Recommended | Notes |
| --- | --- | --- |
| PHP-FPM / CLI scripts | `reportAsync()` | Short-lived requests; subprocess overhead is acceptable |
| Webman / Swoole | `reportSync()` | Avoids frequent forking; blocking affects only the current worker |
| High concurrency / reliable delivery | `formatThrowable()` + message queue | Push to a queue and let a background worker handle sending |

## Notes

- **The exit status of the async subprocess is not checked by the main process** — if reporting fails (network issues, server down), the main process won't know. For reliability-sensitive scenarios, consider the message queue approach.
- **Rotated log files are not automatically cleaned up** — set up a cron job to periodically remove old logs if needed.
- Under high-concurrency error scenarios (thousands per second), async forking overhead is non-trivial. But realistically — errors rarely reach that volume 😅

## Related Projects

- [oh-shit-logger](https://github.com/zxc7563598/oh-shit-logger) — A Go-based exception log collector designed to work with this library
