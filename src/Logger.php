<?php

namespace Hejunjie\Lazylog;

use Hejunjie\Lazylog\Logger\AsyncRemoteSender;
use Throwable;

class Logger
{
    /**
     * 写入本地日志（线程安全 + 自动分文件）
     *
     * @param string $basePath 日志基础目录（例如 /var/logs 或 runtime/logs）
     * @param string $fileName 日志文件名（可包含子路径，如 "error/app.log"）
     * @param string $title 日志标题（如 "Error in Task #12"）
     * @param string|array|object $content 日志内容（详细描述，可为数组、对象、字符串）
     * @param int $maxLines 超过多少行自动切分（默认 10_000）
     * @param int $maxSizeKB 超过多少 KB 自动切分（默认 2048，即 2MB）
     */
    public static function write(
        string $basePath,
        string $fileName,
        string $title,
        mixed $content,
        int $maxLines = 10000,
        int $maxSizeKB = 2048
    ): void {
        $filePath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        // 格式化日志内容
        $now = date('Y-m-d H:i:s');
        if (is_array($content) || is_object($content)) {
            $content = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $log = sprintf("[%s] %s\n%s\n\n", $now, $title, $content);

        // 切分逻辑：按文件大小或行数
        if (file_exists($filePath)) {
            $sizeKB = filesize($filePath) / 1024;
            if ($sizeKB > $maxSizeKB || self::countLines($filePath) > $maxLines) {
                $rotated = $filePath . '.' . date('Ymd_His');
                @rename($filePath, $rotated);
            }
        }

        // 并发写安全：文件锁 + 追加写
        $fp = @fopen($filePath, 'a');
        if (!$fp) {
            return;
        }
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $log);
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    /**
     * 异步上报异常信息到远程服务。
     *
     * ⚠️ 注意：
     * - 本方法通过 `proc_open` / `exec` 等方式在后台异步执行，
     *   适用于 **PHP-FPM、CLI 脚本、短生命周期请求** 等环境；
     * - 不推荐在 **常驻内存框架（如 Webman、Swoole）** 中使用，
     *   因为这些环境会长期保持进程，异步子进程的方式可能带来资源泄漏风险；
     * - 在常驻内存环境中，请使用 {@see self::reportSync()} 或带超时的同步上报版本。
     *
     * @param Throwable $exception 捕获的异常对象，将被格式化后上报
     * @param string $url 远程上报服务的 URL
     * @param string $project 项目标识（默认：unknown-project）
     * @param array $context 额外上下文数据（例如请求信息、环境变量等）
     * @param string $phpBinary PHP 可执行文件路径（用于子进程执行，默认：php）
     *
     * @return void
     */
    public static function reportAsync(Throwable $exception, string $url, string $project = 'unknown-project', array $context = [], string $phpBinary = 'php'): void
    {
        $payload = self::formatThrowable($exception, $project, $context);
        AsyncRemoteSender::writeTempAndSpawn($payload, $url, $phpBinary);
    }

    /**
     * 同步上报异常信息到远程服务。
     *
     * ✅ 适用场景：
     * - 常驻内存框架（如 Webman、Swoole、RoadRunner）；
     * - 或在请求结束前需要立即上报的场景；
     *
     * ⚙️ 实现说明：
     * - 使用 `file_get_contents()` + `stream_context_create()` 发送 JSON POST；
     * - 请求设置了 5 秒超时，防止长时间阻塞；
     * - 未抛出异常（使用 `@` 抑制错误），即便上报失败也不会影响主流程；
     * - 不依赖外部进程，线程安全，适合协程/事件循环环境。
     *
     * ⚠️ 注意：
     * - 此方法为“同步”上报，会在执行期间占用当前 Worker；
     * - 若上报接口延迟较高，可适当降低 `timeout` 或使用异步上报方法 {@see self::reportAsync()}。
     *
     * @param Throwable $exception 捕获的异常对象，将被格式化后上报
     * @param string $url 远程上报服务的 URL
     * @param string $project 项目标识（默认：unknown-project）
     * @param array $context 额外上下文数据（例如请求信息、环境变量等）
     * @param int $timeout 超时时间（秒）
     *
     * @return void
     */
    public static function reportSync(Throwable $exception, string $url, string $project = 'unknown-project', array $context = [], int $timeout = 5): void
    {
        $payload = self::formatThrowable($exception, $project, $context);
        $data = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $data,
                'timeout' => $timeout
            ]
        ];
        $ctx = stream_context_create($opts);
        @file_get_contents($url, false, $ctx);
    }

    /**
     * 格式化错误信息
     * 
     * @param Throwable $e 捕获的异常对象
     * @param string $project 项目名称
     * @param array $context 额外的上下文信息
     * 
     * @return array 
     */
    public static function formatThrowable(Throwable $e, string $project, array $context = []): array
    {
        // 清理并标准化 trace 结构
        $trace = array_map(static function ($t) {
            return [
                'file'     => isset($t['file']) ? (string)$t['file'] : null,
                'line'     => isset($t['line']) ? (int)$t['line'] : null,
                'function' => isset($t['function']) ? (string)$t['function'] : null,
                'class'    => isset($t['class']) ? (string)$t['class'] : null,
            ];
        }, $e->getTrace() ?? []);
        // 构造完整结构
        return [
            'uuid'        => bin2hex(random_bytes(8)),
            'project'     => $project,
            'level'       => 'error',
            'timestamp'   => date('c'),
            'message'     => (string)$e->getMessage(),
            'code'        => (int)$e->getCode(),
            'file'        => (string)$e->getFile(),
            'line'        => (int)$e->getLine(),
            'trace'       => $trace,
            'context'     => (object)$context,
            'server'      => [
                'hostname'    => gethostname() ?: 'unknown',
                'ip'          => '/',
                'php_version' => PHP_VERSION,
            ],
        ];
    }

    /**
     * 统计文件行数（用于判断是否切分）
     */
    private static function countLines(string $file): int
    {
        $count = 0;
        $handle = @fopen($file, 'r');
        if (!$handle) {
            return 0;
        }
        while (!feof($handle)) {
            $line = fgets($handle);
            $count++;
            if ($count > 20000) break;
        }
        fclose($handle);
        return $count;
    }
}
