# hejunjie/lazylog

[English](./README.md) ｜ 简体中文

轻量级 PHP 日志库，提供本地日志安全写入以及异常信息远程上报（同步/异步）。

> 在做 [oh-shit-logger](https://github.com/zxc7563598/oh-shit-logger)（Go 项目）时，需要一个统一收集 PHP 异常信息的方式，于是把常用的日志方案封装成了 Composer 包。

> 本项目已由 [Zread](https://zread.ai/zxc7563598/php-lazylog) 解析，可以点击链接快速了解项目结构和代码逻辑。

## 特性

- **线程安全的本地写入**：基于文件锁（`flock`）的追加写入，避免并发写冲突
- **自动日志切分**：支持按行数（默认 10000 行）或文件大小（默认 2MB）自动切分
- **异步异常上报**：通过 `proc_open` 启动子进程发送 HTTP POST，不阻塞主进程
- **同步上报模式**：适配 Webman、Swoole 等常驻内存框架，避免频繁 fork 导致的资源累积
- **标准化异常格式**：提供 `formatThrowable()` 输出结构化异常数据，方便对接消息队列
- **零依赖**：仅需 PHP ^7.4 || ^8.0，无第三方依赖

## 环境要求

- PHP ^7.4 || ^8.0

## 安装

```bash
composer require hejunjie/lazylog
```

## 快速开始

```php
use Hejunjie\Lazylog\Logger;

// 写本地日志
Logger::write('/var/logs', 'app/error.log', '支付失败', [
    'order_id' => 12345,
    'reason'   => '余额不足',
]);

// 异步上报异常（适合 PHP-FPM / CLI 脚本）
try {
    // 业务代码
} catch (\Throwable $e) {
    Logger::reportAsync($e, 'https://error.example.com/collect', 'my-project');
}

// 同步上报异常（适合常驻内存框架）
try {
    // 业务代码
} catch (\Throwable $e) {
    Logger::reportSync($e, 'https://error.example.com/collect', 'my-project');
}
```

## API

### Logger::write()

写入本地日志，自动创建目录、支持文件切分、并发安全。

```php
Logger::write(
    string $basePath,   // 日志根目录，如 /var/logs
    string $fileName,   // 日志文件名，可含子路径，如 "error/app.log"
    string $title,      // 日志标题
    mixed  $content,    // 日志内容，支持 string/array/object
    int    $maxLines = 10000,  // 超过此行数自动切分
    int    $maxSizeKB = 2048   // 超过此大小（KB）自动切分
): void
```

切分后的旧文件会重命名为 `原文件名.Ymd_His`，如 `app.log.20260715_143022`。

### Logger::reportAsync()

使用 `proc_open` / `exec` 启动后台 PHP 子进程发送异常数据到远程服务。主进程不阻塞。

```php
Logger::reportAsync(
    \Throwable $exception,                    // 异常对象
    string     $url,                          // 远程上报 URL
    string     $project = 'unknown-project',  // 项目标识
    array      $context = [],                 // 额外上下文（请求参数、环境变量等）
    string     $phpBinary = 'php'             // PHP 可执行文件路径
): void
```

> [!WARNING]
> 不推荐在 Webman、Swoole 等常驻内存框架中使用。频繁 fork 子进程可能导致僵尸进程或内存泄漏。请改用 `reportSync()` 或 `formatThrowable()` + 消息队列。

> [!NOTE]
> 对于低频错误上报（如每分钟几次），异步 fork 的性能开销可以忽略不计。

### Logger::reportSync()

同步发送异常数据到远程服务，通过 `file_get_contents()` + stream context 实现。

```php
Logger::reportSync(
    \Throwable $exception,                    // 异常对象
    string     $url,                          // 远程上报 URL
    string     $project = 'unknown-project',  // 项目标识
    array      $context = [],                 // 额外上下文
    int        $timeout = 5                   // 超时时间（秒）
): bool
```

返回 `true` 表示上报成功，`false` 表示失败。上报失败不会抛出异常，不影响主流程。

### Logger::formatThrowable()

将异常对象格式化为结构化数组，方便自行投递到消息队列或做自定义处理。

```php
$data = Logger::formatThrowable(
    \Throwable $exception,  // 异常对象
    string     $project,    // 项目名称
    array      $context = [ // 额外上下文
): array
```

返回的数组结构：

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

## 场景选择

| 运行环境 | 推荐方式 | 说明 |
| --- | --- | --- |
| PHP-FPM / CLI 脚本 | `reportAsync()` | 请求生命周期短，异步子进程开销可接受 |
| Webman / Swoole | `reportSync()` | 避免频繁 fork，阻塞只影响当前 Worker |
| 高并发 / 需要可靠投递 | `formatThrowable()` + 消息队列 | 异常数据入队，由后台 Worker 消费发送 |

## 注意事项

- **异步上报的子进程退出状态不会被主进程检查**——如果上报失败（网络不通、服务端宕机），主进程不会收到通知。对可靠性要求高的场景，建议使用消息队列方案。
- **本地日志不会自动清理切分后的旧文件**——如需自动清理，可配合 cron 脚本定期删除过期日志。
- 异步上报在高并发错误场景下（每秒上千次），fork 开销不可忽略。不过——错误信息通常不会到那个量级 😅

## 相关项目

- [oh-shit-logger](https://github.com/zxc7563598/oh-shit-logger) —— Go 语言编写的异常日志收集服务，与本库配合使用
