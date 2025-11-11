# hejunjie/lazylog

<div align="center">
  <a href="./README.md">English</a>｜<a href="./README.zh-CN.md">简体中文</a>
  <hr width="50%"/>
</div>

A lightweight PHP logging library providing **safe local log writing** and **remote exception reporting** (both synchronous and asynchronous).

> Why this library exists:  
> While working on my Go project [oh-shit-logger](https://github.com/zxc7563598/oh-shit-logger), I needed a centralized way to collect error information.  
> Some friends were concerned about the performance overhead of PHP error reporting, so I wrapped my usual approach into a Composer package for easier reuse.

**This project has been parsed and summarized by Zread. For a quick overview, check here:**  [Project Overview](https://zread.ai/zxc7563598/php-milvus)

---

## Installation

```bash
composer require hejunjie/lazylog
```

---

## Usage

​`lazylog` provides three core methods:

---

### Write Local Logs

```php
use Hejunjie\Lazylog\Logger;

/**
 * Write logs safely to local files (thread-safe + auto file rotation)
 *
 * @param string $basePath Base log directory (e.g. /var/logs or runtime/logs)
 * @param string $fileName Log filename (can include subpath like "error/app.log")
 * @param string $title Log title (e.g. "Error in Task #12")
 * @param string|array|object $content Log content (array, object, or string)
 * @param int $maxLines Max lines per file before rotation (default: 10,000)
 * @param int $maxSizeKB Max file size in KB before rotation (default: 2048 = 2MB)
 *
 * @return void
 */
Logger::write(
    '/var/logs',
    'error/app.log',
    'Task Failed',
    ['message' => 'Something went wrong']
);
```

---

### Asynchronous Exception Reporting

- Uses `exec()` / `proc_open()` to spawn a background PHP process that sends a POST request
- Non-blocking for the main process
- **Not recommended** in long-running frameworks (Webman / Swoole)  
  since they keep workers alive for a long time — frequent forking may accumulate resources (zombie processes, memory leaks, etc.)

```php
try {
    // Code that may throw
} catch (\Throwable $exception) {
    /**
     * Report exception asynchronously to a remote endpoint.
     *
     * @param Throwable $exception The captured exception
     * @param string $url Remote endpoint URL
     * @param string $project Project identifier (default: unknown-project)
     * @param array $context Additional context data (e.g. request info, env vars)
     * @param string $phpBinary PHP binary path for subprocess (default: php)
     *
     * @return void
     */
    Logger::reportAsync($exception, 'https://error.example.com/collect', 'my-project');
}
```

> For low-frequency error reporting, the performance cost of forking a PHP subprocess is negligible.

---

### Synchronous Exception Reporting

- Recommended for long-running frameworks or when immediate reporting is needed
- Avoids creating subprocesses, preventing potential zombie or leaked processes
  Long-running workers in frameworks like Webman/Swoole are reused between requests —  even if synchronous calls block, they only affect the current worker, not others.

```php
try {
    // Code that may throw
} catch (\Throwable $exception) {
    /**
     * Report exception synchronously to a remote endpoint.
     *
     * @param Throwable $exception The captured exception
     * @param string $url Remote endpoint URL
     * @param string $project Project identifier (default: unknown-project)
     * @param array $context Additional context data
     * @param int $timeout Timeout in seconds
     *
     * @return void
     */
    Logger::reportSync($exception, 'https://error.example.com/collect', 'my-project');
}
```

#### Optimization Suggestion

For long-running frameworks, it’s often better to enqueue exceptions and handle reporting via a background worker:

```php
try {
    // Code that may throw
} catch (\Throwable $exception) {
    /**
     * Format exception data before sending
     *
     * @param Throwable $exception The captured exception
     * @param string $project Project name
     * @param array $context Additional context info (default: empty)
     *
     * @return array
     */
    $formatted = Logger::formatThrowable($exception, 'my-project');
    // Push $formatted into a queue
    // Then let your worker consume and send it
}
```

---

## Motivation

- To provide a **lightweight, unified logging solution** for quickly sending PHP exception data to my Go project **[oh-shit-logger](https://github.com/zxc7563598/oh-shit-logger)**
- To avoid writing repetitive error reporting logic across multiple projects
- Supports **safe local logging + async/sync remote reporting**, suitable for PHP-FPM, CLI, and long-running frameworks

---

## Additional Notes

- Asynchronous reporting works by forking a PHP CLI subprocess
- For low-frequency errors, overhead is minimal and generally safe
- Under high concurrency (thousands per second), consider using a queue + worker or coroutine async (Though realistically, error reporting shouldn’t reach that volume 😅)
- Local logging includes automatic rotation to prevent oversized files during long-term use

---

## Issues & Contributions

Have ideas, feedback, or bug reports?  
Feel free to open an Issue or Pull Request on GitHub! 🚀
