<?php

namespace Hejunjie\Lazylog\Logger;

class AsyncRemoteSender
{
    /**
     * 将日志/异常信息写入临时文件并异步发送到远程服务。
     *
     * 该方法执行以下流程：
     * 1. 在系统临时目录生成唯一文件。
     * 2. 将传入的 $payload 数组编码为 JSON 并写入临时文件。
     * 3. 调用 spawnWorker() 启动后台子进程异步读取临时文件并发送到指定 URL。
     * 4. 子进程读取完成后会删除临时文件，确保不残留垃圾文件。
     *
     * 注意事项：
     * - 如果临时文件创建失败或写入失败，方法会静默返回（no-op）。
     * - 异步发送依赖 spawnWorker() 的实现，主进程不会等待远程发送完成。
     * - 每次调用都会生成单独的临时文件，避免并发写冲突。
     *
     * @param array  $payload 日志或异常信息数组。
     * @param string $url 远程接收日志的 URL。
     * @param string $phpBinary PHP CLI 可执行文件路径，用于启动异步子进程。
     *
     * @return void
     */
    public static function writeTempAndSpawn(array $payload, string $url, string $phpBinary = PHP_BINARY): void
    {
        $tmpDir = sys_get_temp_dir();
        $tmpFile = $tmpDir . DIRECTORY_SEPARATOR . 'lazylog_' . uniqid('', true) . '.json';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            error_log("Lazylog: json_encode failed - " . json_last_error_msg());
            return;
        }
        if (@file_put_contents($tmpFile, $json, LOCK_EX) === false) {
            error_log("Lazylog: failed to write temp file: $tmpFile");
            return;
        }
        self::spawnWorker($tmpFile, $url, $phpBinary);
    }

    /**
     * 使用后台子进程异步发送日志数据到远程 URL。
     *
     * 该方法尝试通过 proc_open 启动一个独立 PHP CLI 进程来执行异步请求。
     * 如果 proc_open 不可用或失败，则会回退到使用 shell 命令执行异步请求的方式。
     *
     * 执行流程：
     * 1. 检测操作系统，并选择合适的空设备 (`/dev/null` 或 `NUL`) 来丢弃子进程输入输出。
     * 2. 构建 PHP 运行代码片段（$workerCode），功能：
     *      - 读取传入的临时文件内容。
     *      - 删除临时文件。
     *      - 使用 stream_context 创建 HTTP POST 请求发送数据到指定 URL。
     *      - 设置请求超时时间（默认 10 秒）。
     * 3. 尝试通过 proc_open 执行异步请求：
     *      - 使用数组形式调用 PHP CLI（PHP >= 7.4，避免 shell 注入）。
     *      - 标准输入/输出/错误都指向空设备。
     *      - 子进程成功启动后，立即关闭父进程的管道，主进程不阻塞。
     * 4. 如果 proc_open 不可用或执行失败，调用 spawnWorkerFallbackShell() 使用 shell 命令异步发送。
     *
     * 注意事项：
     * - 该方法属于“伪异步”，不会等待远程请求完成，主进程立即返回。
     * - 临时文件由子进程负责读取和删除，保证不会残留。
     * - 该方法为 protected，建议通过外部公共方法（如 writeTempAndSpawn）间接调用。
     *
     * @param string $tmpFile   临时文件路径，子进程从中读取要发送的 JSON 数据。
     * @param string $url       远程接收日志的 URL。
     * @param string $phpBinary PHP CLI 可执行文件路径，用于启动异步子进程。
     *
     * @return void
     */
    protected static function spawnWorker(string $tmpFile, string $url, string $phpBinary): void
    {
        $devNull = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';
        $workerCode = <<<'PHP'
$f = $argv[1] ?? '';
$e = $argv[2] ?? '';
$t = isset($argv[3]) ? (int)$argv[3] : 10;
if (!$f || !$e) exit(1);
if (!file_exists($f)) exit(0);
$data = @file_get_contents($f);
@unlink($f);
if ($data === false) exit(0);
$opts = [
  'http' => [
    'method'  => 'POST',
    'header'  => "Content-Type: application/json\r\n",
    'content' => $data,
    'timeout' => $t,
  ]
];
$ctx = stream_context_create($opts);
@file_get_contents($e, false, $ctx);
exit(0);
PHP;
        $useArrayCmd = defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70400;
        if ($useArrayCmd && function_exists('proc_open')) {
            $cmd = [$phpBinary, '-r', $workerCode, $tmpFile, $url, 10];
            $spec = [
                ["file", $devNull, "r"],
                ["file", $devNull, "w"],
                ["file", $devNull, "w"],
            ];
            $proc = @proc_open($cmd, $spec, $pipes);
            if (is_resource($proc)) {
                foreach ($pipes ?? [] as $p) {
                    @fclose($p);
                }
                return;
            }
        }
        self::spawnWorkerFallbackShell($tmpFile, $devNull, $url, $phpBinary);
    }

    /**
     * 回退方案：使用 shell 命令异步执行 PHP CLI 来发送日志数据。
     *
     * 当 proc_open 不可用或执行失败时，会调用此方法。
     * 它通过构造一个完整的 PHP -r 命令，将临时文件中的日志数据
     * 异步 POST 到远程 URL，然后立即返回，不阻塞主进程。
     *
     * 执行流程：
     * 1. 使用 escapeshellcmd 和 escapeshellarg 对 PHP 可执行路径及参数进行安全转义，防止命令注入。
     * 2. 构造 PHP 代码片段（内联 -r 参数）：
     *      - 读取传入的临时文件内容。
     *      - 删除临时文件。
     *      - 使用 stream_context 创建 HTTP POST 请求发送到远程 URL。
     *      - 设置请求超时时间（默认 $this->cliTimeoutSec 秒）。
     *      - 添加 User-Agent 和 Content-Type 头。
     * 3. 使用 shell 命令执行该 PHP 代码，并将输出重定向到空设备（/dev/null 或 NUL）。
     * 4. 命令末尾加 &，实现后台异步执行，主进程不会阻塞。
     *
     * 注意事项：
     * - 此方法仅作为 spawnWorker 的回退方案使用。
     * - 临时文件由子进程负责删除，主进程无需管理。
     * - 方法不会等待远程请求完成，属于“伪异步”执行。
     *
     * @param string $tmpFile 临时文件路径，子进程从中读取 JSON 数据。
     * @param string $devNull 空设备路径（/dev/null 或 NUL），用于丢弃子进程输出。
     * @param string $url 远程接收日志的 URL。
     * @param string $phpBinary PHP CLI 可执行文件路径，用于启动异步子进程。
     *
     * @return void
     */
    protected static function spawnWorkerFallbackShell(string $tmpFile, string $devNull, string $url, string $phpBinary): void
    {
        $php = escapeshellcmd($phpBinary);
        $code = <<<'PHP'
$f = $argv[1] ?? '';
$e = $argv[2] ?? '';
$t = isset($argv[3]) ? (int)$argv[3] : 10;
if (!$f || !$e) exit(1);
if (!file_exists($f)) exit(0);
$data = @file_get_contents($f);
@unlink($f);
if ($data === false) exit(0);
$opts = [
  'http' => [
    'method'  => 'POST',
    'header'  => "Content-Type: application/json\r\n",
    'content' => $data,
    'timeout' => $t
  ]
];
$ctx = stream_context_create($opts);
@file_get_contents($e, false, $ctx);
exit(0);
PHP;
        $codeArg = escapeshellarg($code);
        $cmd = sprintf(
            '%s -r %s %s %s %s > %s 2>&1 %s',
            $php,
            $codeArg,
            escapeshellarg($tmpFile),
            escapeshellarg($url),
            escapeshellarg("10"),
            escapeshellarg($devNull),
            strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? '' : '&'
        );
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            @pclose(@popen("start /B " . $cmd, "r"));
        } else {
            @exec($cmd);
        }
    }
}
