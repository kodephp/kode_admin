<?php

declare(strict_types=1);

namespace app\tests\Support;

/**
 * 测试用的本地 HTTP 服务（`php -S`），只为本机回环，跑完即停。
 *
 * 为什么值得起一个真服务：`RemoteFetch::toFile()` 要证明的是「重定向的每一跳都重新过闸」
 * 「超预算当场断线」「失败不留半截文件」——这些都不在纯函数里，用 stub 测等于测我自己写的假对象。
 */
final class LocalFetchServer
{
    private const ROUTER = <<<'PHP'
<?php
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$root = (string) __DIR__;

if ($path === '/redirect') {
    header('Location: ' . (string) ($_GET['to'] ?? '/notfound'), true, 302);
    echo 'jump';
    exit;
}
if ($path === '/loop') {
    // 自指跳转：测「跳数预算」只能用这种恒真的环，`/redirect?to=/redirect` 第二跳会掉进 to 缺省的 404
    @file_put_contents($root . '/loop-count', '1', FILE_APPEND);
    header('Location: /loop', true, 302);
    echo 'jump';
    exit;
}
if ($path === '/status') {
    http_response_code((int) ($_GET['code'] ?? 500));
    echo 'body';
    exit;
}
if ($path === '/slow') {
    // 故意拖慢：测「客户端侧超时」只能用真慢响应，`/status` 恒秒回，超时分支永远走不到。
    // 分片写 + flush 是为了让客户端提前断线时这边能看见（内置服务器是单进程的，
    // 死等到 ms 会把后续用例一起堵上）。
    $deadline = microtime(true) + ((int) ($_GET['ms'] ?? 1000)) / 1000;
    http_response_code(200);
    while (microtime(true) < $deadline) {
        if (connection_aborted() > 0) {
            break;
        }
        echo 'z';
        @flush();
        usleep(50000);
    }
    exit;
}
if ($path === '/empty') {
    http_response_code(200);
    exit;
}
if ($path === '/stream') {
    // 不声明 Content-Length：让「边下边算」的预算判定只能看已落盘字节数。
    // 每片把累计发出字节数记进文件：客户端「当场断线」的话这个数字会停在预算附近，
    // 等整包传完才判超限的话它就是完整的 bytes —— 这是唯一能看见早停发生过的地方。
    $bytes = (int) ($_GET['bytes'] ?? 1024);
    http_response_code(200);
    $chunk = str_repeat('a', 4096);
    $sent = 0;
    while ($bytes > 0) {
        $part = substr($chunk, 0, min(4096, $bytes));
        echo $part;
        $sent += strlen($part);
        $bytes -= 4096;
        @file_put_contents($root . '/stream-sent', (string) $sent);
        flush();
    }
    exit;
}

$file = realpath($root . $path);
if ($file !== false && is_file($file) && str_starts_with($file, $root)) {
    return false; // 交给内置服务器按静态文件发（带 Content-Length）
}

http_response_code(404);
echo 'nope';
PHP;

    /** @var resource|\Shmop|object|null */
    private mixed $proc = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private int $port = 0;

    private string $root = '';

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        file_put_contents($this->root . '/router.php', self::ROUTER);
    }

    public function start(): void
    {
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            throw new \RuntimeException('无法分配本地端口：' . $errstr);
        }
        $name = (string) stream_socket_get_name($probe, false);
        $this->port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);

        $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $this->port
            . ' -t ' . escapeshellarg($this->root) . ' ' . escapeshellarg($this->root . '/router.php');
        $proc = @proc_open($cmd, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $this->pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('无法启动 php -S（proc_open 被禁用？）');
        }
        $this->proc = $proc;

        // 等服务就绪：最多 3 秒，连上即算起
        for ($i = 0; $i < 60; $i++) {
            $sock = @fsockopen('127.0.0.1', $this->port, $e1, $e2, 0.05);
            if ($sock !== false) {
                fclose($sock);

                return;
            }
            usleep(50000);
        }

        $this->stop();
        throw new \RuntimeException('php -S 未在 3 秒内就绪');
    }

    public function url(string $path = '/'): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    /** 本服务自己的地址（用来证明「内网默认被拒」这条线）。 */
    public function host(): string
    {
        return '127.0.0.1:' . $this->port;
    }

    public function put(string $name, string $content): string
    {
        $path = $this->root . '/' . ltrim($name, '/');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $content);

        return $path;
    }

    /** `/stream` 实际发出了多少字节（证明「超预算当场断线」真的断在了中途）。 */
    public function streamSent(bool $reset = false): int
    {
        $file = $this->root . '/stream-sent';
        $n = is_file($file) ? (int) file_get_contents($file) : 0;
        if ($reset) {
            @unlink($file);
        }

        return $n;
    }

    /** 自指跳转被打了几次（用于证明「跳数预算」是真的把循环掐断了，而不是靠超时）。 */
    public function loopHits(bool $reset = false): int
    {
        $file = $this->root . '/loop-count';
        $n = is_file($file) ? strlen((string) file_get_contents($file)) : 0;
        if ($reset) {
            @unlink($file);
        }

        return $n;
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
        $this->proc = null;
    }
}
