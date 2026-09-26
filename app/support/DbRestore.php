<?php

declare(strict_types=1);

namespace app\support;

/**
 * 数据库恢复：把 `DbBackup` 的产物喂回 psql。
 *
 * 为什么单拆一个派发口：恢复的命令行形状就是这件事的全部风险所在（`ON_ERROR_STOP`
 * 决定退出码可不可信、fd 描述符决定进程到底有没有起来），而它上一轮被抄过一份之后
 * 就坏了 —— 控制器里那份把 fd2 写成 `['stream', 'php://stdout']`，PHP 的 stream 描述符
 * 只认「stream + 已打开的资源」，字符串路径会让 proc_open **直接返回 false**，
 * 于是恢复接口对任何人都回 500「恢复进程启动失败」，而退出码相关的判据全在下游，
 * 没有一个测试能看见「进程根本没启动」。口径只留一处，测试也走这一处。
 *
 * 输出只有一条流：命令以 `exec 2>&1;` 开头，让 shell 自己把 fd2 并进 fd1，
 * 于是 psql 的报错、NOTICE 与 shell 的「command not found」都落在同一根管子里。
 * 分两管读会死锁：子进程 stderr 攒满管道缓冲（64KB 量级）时会卡在写上，而我们卡在
 * 读 stdout —— 一份带几十条 `DROP ... IF EXISTS` 的产物恢复进空库就是几十条 NOTICE。
 *
 * 退出码必须回给调用方：psql 不加 `ON_ERROR_STOP` 时**中间语句报错照样 exit 0**（实测），
 * 「恢复完成」不能建在一个会撒谎的返回值上。产物本身是逐节 `\connect` + BEGIN/COMMIT，
 * 坏一节只回滚那一节，前面的节已经提交的数据不会跟着退。
 */
final class DbRestore
{
    /**
     * 组装恢复命令（纯函数：可见形状便于离线断言）。
     *
     * @param string|null $db         初始连接的库；产物里的 \connect 会各自切库
     * @param bool        $stopOnError 只有「证明不加它会撒谎」的对照会传 false
     */
    public static function command(string $file, ?string $db = null, bool $stopOnError = true): string
    {
        return sprintf(
            'exec 2>&1; psql -h %s -p %d -U %s -d %s%s -f %s',
            escapeshellarg((string) env('DB_HOST', '127.0.0.1')),
            (int) env('DB_PORT', 5432),
            escapeshellarg((string) env('DB_USERNAME', '')),
            escapeshellarg($db ?? (string) env('DB_DATABASE', '')),
            $stopOnError ? ' -v ON_ERROR_STOP=1' : '',
            escapeshellarg($file)
        );
    }

    /**
     * 跑一次恢复。
     *
     * 凭证只走环境变量：`PGPASSWORD=xxx psql ...` 经 /bin/sh -c 拉起时密码会出现在
     * sh 进程的 argv 里，本机任何用户 `ps` 一眼可读（与 DbBackup 同口径）。
     *
     * @return array{code: int, out: string} out = psql 的全部回显（含报错原文）
     */
    public static function run(string $file, ?string $db = null, bool $stopOnError = true): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException("恢复文件不存在：{$file}");
        }

        $proc = proc_open(
            self::command($file, $db, $stopOnError),
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PGPASSWORD' => (string) env('DB_PASSWORD', ''), 'PATH' => '/usr/bin:/bin:/opt/homebrew/bin']
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException('恢复进程启动失败');
        }

        // 先读完再 close：fd2 已经并进 fd1，这一管就是全部输出
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return ['code' => proc_close($proc), 'out' => $out];
    }
}
