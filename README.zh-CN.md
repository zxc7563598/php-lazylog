# hejunjie/lazylog

<div align="center">
  <a href="./README.md">English</a>｜<a href="./README.zh-CN.md">简体中文</a>
  <hr width="50%"/>
</div>

轻量级 PHP 日志库，提供**本地日志安全写入**以及**异常信息远程上报**（同步/异步）。

> 这个库诞生的原因：我在做一个 Go 项目 [oh-shit-logger](https://github.com/zxc7563598/oh-shit-logger) 时，需要集中收集错误信息
>
> 有些朋友一直担心 PHP 错误上报的性能问题，所以我把在项目中常用的方式封装成了一个 Composer 库，方便直接使用。

**本项目已经经由 Zread 解析完成，如果需要快速了解项目，可以点击此处进行查看：[了解本项目](https://zread.ai/zxc7563598/php-lazylog)**

---

## 安装

```bash
composer require hejunjie/lazylog
```

---

## 使用方法

​`lazylog` 提供三个核心方法：

---

### 写本地日志

```php
use Hejunjie\Lazylog\Logger;

/**
 * 写入本地日志（线程安全 + 自动分文件）
 *
 * @param string $basePath 日志基础目录（例如 /var/logs 或 runtime/logs）
 * @param string $fileName 日志文件名（可包含子路径，如 "error/app.log"）
 * @param string $title 日志标题（如 "Error in Task #12"）
 * @param string|array|object $content 日志内容（详细描述，可为数组、对象、字符串）
 * @param int $maxLines 超过多少行自动切分（默认 10_000）
 * @param int $maxSizeKB 超过多少 KB 自动切分（默认 2048，即 2MB）
 *
 * @return void
 */
Logger::write(
    '/var/logs',
    'error/app.log',
    'Task Failed',
    ['message' => 'Something went wrong']
)
```

---

### 异步上报异常

- 使用 `exec()` / `proc_open()` 在后台异步发送 POST 请求
- 主进程不阻塞
- 不推荐在常驻内存框架（Webman/Swoole）中使用，仅适用于传统方式
  常驻内存框架长期保持进程，如果频繁 fork 子进程，可能导致资源累积（如僵尸进程、内存泄漏）

```php
try {
    // 可能抛出异常的代码
} catch (\Throwable $exception) {
    /**
     * 异步上报异常信息到远程服务。
     *
     * @param Throwable $exception 捕获的异常对象，将被格式化后上报
     * @param string $url 远程上报服务的 URL
     * @param string $project 项目标识（默认：unknown-project）
     * @param array $context 额外上下文数据（例如请求信息、环境变量等、默认空数组）
     * @param string $phpBinary PHP 可执行文件路径（用于子进程执行，默认：php）
     *
     * @return void
     */
    Logger::reportAsync($exception, 'https://error.example.com/collect', 'my-project');
}
```

> 对于低频错误上报，异步 fork 子进程的性能开销非常小，足够使用。

---

### 同步上报异常

- 在常驻内存框架或需要立即上报时使用
- 避免 fork 子进程带来的资源累积和潜在僵尸进程问题
  常驻内存框架的 Worker 可以复用，不会像短生命周期的请求那样频繁创建/销毁进程，即使阻塞，也只占用该 Worker，不会影响其他 Worker

```php
try {
    // 可能抛出异常的代码
} catch (\Throwable $exception) {
    /**
     * 同步上报异常信息到远程服务。
     *
     * @param Throwable $exception 捕获的异常对象，将被格式化后上报
     * @param string $url 远程上报服务的 URL
     * @param string $project 项目标识（默认：unknown-project）
     * @param array $context 额外上下文数据（例如请求信息、环境变量等）
     * @param int $timeout 超时时间（秒）
     *
     * @return void
     */
    Logger::reportSync($exception, 'https://error.example.com/collect', 'my-project');
}
```

#### 优化方案

通常情况下，常驻内存框架建议开一条队列来处理异常上报

```php
try {
    // 可能抛出异常的代码
} catch (\Throwable $exception) {
    /**
     * 格式化错误信息
     * 
     * @param Throwable $exception 捕获的异常对象
     * @param string $project 项目名称
     * @param array $context 额外的上下文信息，默认空数组
     * 
     * @return array 
     */
    $formatThrowable = Logger::formatThrowable($exception, 'my-project');
    // 将 formatThrowable 投递到队列中
    // 直接在队列中发送数据
}
```

---

## 制作初衷

- 提供一个轻量级、统一的日志方案，用于**快速将 PHP 项目的异常信息上报到我的 Go 项目** **[oh-shit-logger](https://github.com/zxc7563598/oh-shit-logger)**
- 避免在每个项目里重复实现错误收集和远程上报逻辑
- 支持​**本地安全写入 + 异步/同步远程上报**，方便不同环境（PHP-FPM、CLI、常驻内存框架）使用

---

## 额外说明

- 异步上报采用 fork PHP CLI 子进程实现
- 低频错误上报场景下性能消耗非常小，无需担心系统压力
- 高并发场景（每秒上千次）可能会产生系统开销，可考虑队列 + worker 或协程异步（但应该不会出现错误信息高并发吧？？？）
- 本地日志支持自动切分，保证长期运行不会单文件过大

---

## 欢迎 Issues

有任何问题、建议或改进思路，欢迎在 GitHub 提交 Issues！
