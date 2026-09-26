<?php

declare(strict_types=1);

namespace app\support;

/**
 * 服务端出网的取数闸门：任何「按别人给的 URL 去抓东西」都走这里。
 *
 * 三件事缺一不可，早先各自漏过：
 *  1. 逐跳校验 —— curl 自己跟重定向时，第二跳、第三跳没人查，
 *     `302 → http://169.254.169.254/...` 就把内网元数据端点替我们取回来了；
 *  2. 钉住已校验的 IP —— 先 gethostbyname 过闸、再让 curl 自己解析一次，
 *     两次解析之间攻击者把 A 记录改成 127.0.0.1 就是 DNS 重绑定（TOCTOU）；
 *  3. 边下边计预算 —— 只在下载完才 `filesize()` 判超限，等于允许对方先灌满磁盘/内存。
 *
 * 内网放行开关沿用 `MARKET_ALLOW_PRIVATE`（本地联调自建市场时用）。
 *
 * 三个入口：`vet()` 纯规则；`toFile()` 下载产物（市场包/资产包）；
 * `safeOpts()` 给「自己还要发 POST/GET」的调用方（webhook、工作流 api_call 节点）
 * —— 它们要的是选项包而不是字节流，但闸门必须是同一份，别各自写一遍。
 */
final class RemoteFetch
{
    /** 单包体积上限（市场插件包口径）。 */
    public const MAX_BYTES = 20 * 1024 * 1024;

    private const MAX_HOPS = 3;

    /**
     * 校验一个服务端要去抓的地址，并给出可钉住的 IP。
     *
     * @return array{ok: bool, msg?: string, host?: string, port?: int, ip?: string}
     */
    public static function vet(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            return ['ok' => false, 'msg' => '地址无法解析'];
        }
        // scheme 先判再说 host：`file:///etc/passwd` 的 host 是空串，
        // 拿 host 缺失先拒会把「协议越界」误报成「地址无法解析」，掩盖真实原因。
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return ['ok' => false, 'msg' => '只允许 http(s) 地址'];
        }
        if (!isset($parts['host']) || $parts['host'] === '') {
            return ['ok' => false, 'msg' => '地址缺少主机名'];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['ok' => false, 'msg' => '地址不允许携带凭证'];
        }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            return ['ok' => false, 'msg' => '端口非法'];
        }

        $ips = self::resolve($host);
        if ($ips === []) {
            return ['ok' => false, 'msg' => '域名解析失败'];
        }
        // 逐条判：只查第一条的话，「公网记录 + 内网记录」轮询的域名就能漏过去
        if (!self::allowPrivate()) {
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    return ['ok' => false, 'msg' => '拒绝内网/保留地址'];
                }
            }
        }

        return ['ok' => true, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /**
     * 抓地址到文件：重定向自己跟（每跳重新过闸），体积边下边算。
     *
     * @return array{ok: bool, msg?: string, bytes?: int}
     */
    public static function toFile(string $url, string $dest, int $maxBytes = self::MAX_BYTES, int $timeout = 30): array
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            $safe = self::vet($current);
            if (!$safe['ok']) {
                @unlink($dest);

                return ['ok' => false, 'msg' => (string) $safe['msg']];
            }

            // 每跳都重写目标文件：跳转页那几百字节不该混进产物
            $fp = @fopen($dest, 'wb');
            if ($fp === false) {
                return ['ok' => false, 'msg' => '写入失败'];
            }
            $location = null;

            $ch = curl_init($current);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                // FOLLOWLOCATION=false 时这条其实用不上（跳转由上面的循环自己跟）；
                // 留着是因为它只在「有人把 FOLLOWLOCATION 翻回 true」的那一刻才起作用——
                // 那道翻转本身有 test_second_hop_is_revalidated 挡着，两层互为备份。
                CURLOPT_REDIR_PROTOCOLS => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => self::pin((string) $safe['host'], (int) $safe['port'], (string) $safe['ip']),
                // 超预算立刻断线，不等对方把整包灌完
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => static function ($ch) use ($fp, $maxBytes): int {
                    $st = fstat($fp);

                    return (int) ($st['size'] ?? 0) > $maxBytes ? 1 : 0;
                },
                CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$location): int {
                    if (preg_match('#^location:\s*(.+?)\s*$#i', (string) $line, $m) === 1) {
                        $location = $m[1];
                    }

                    return strlen((string) $line);
                },
            ]);

            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errNo = (int) curl_errno($ch);
            $errMsg = (string) curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if ($errNo === CURLE_ABORTED_BY_CALLBACK) {
                @unlink($dest);

                return ['ok' => false, 'msg' => '响应超出体积上限'];
            }
            if ($ok === false) {
                @unlink($dest);

                return ['ok' => false, 'msg' => '下载失败：' . ($errMsg === '' ? (string) $errNo : '连接异常')];
            }
            if ($code >= 400) {
                @unlink($dest);

                return ['ok' => false, 'msg' => '下载失败（HTTP ' . $code . '）'];
            }
            if ($code >= 300 && $code < 400) {
                if ($location === null || $hop === self::MAX_HOPS) {
                    @unlink($dest);

                    return ['ok' => false, 'msg' => $location === null ? '重定向缺少目标地址' : '重定向次数过多'];
                }
                $current = self::absolute((string) $location, (string) $current);
                continue;
            }

            $size = is_file($dest) ? (int) filesize($dest) : 0;
            if ($size > $maxBytes || $size === 0) {
                @unlink($dest);

                return ['ok' => false, 'msg' => $size === 0 ? '下载内容为空' : '响应超出体积上限'];
            }

            return ['ok' => true, 'bytes' => $size];
        }

        @unlink($dest);

        return ['ok' => false, 'msg' => '重定向次数过多'];
    }

    /**
     * 给「自己拿 curl 发请求」的调用方出一份安全选项：先过 `vet()`，再把已校验的 IP 钉住。
     *
     * 为什么不能只调 `vet()` 就完事：调用方紧接着还是 `curl_init($userUrl)`，
     * 而 curl 默认自己解释 scheme —— 实测 `file:///etc/passwd` 配 `RETURNTRANSFER`
     * 会把本地文件原样读进响应体（webhook/工作流 api_call 就是这么把 `.env` 带出去的），
     * 内网地址也照样直连（`CURLOPT_NOPROXY` 只管「别走代理」，不是出网闸门）。
     *
     * @return array{ok: bool, msg?: string, opts?: array<int, mixed>}
     *   拒收时 `msg` 是可直接回显给用户的原因；收下时 `opts` 交给 curl_setopt_array 合并。
     */
    public static function safeOpts(string $url): array
    {
        $vet = self::vet($url);
        if ($vet['ok'] !== true) {
            return ['ok' => false, 'msg' => (string) ($vet['msg'] ?? '地址不可用')];
        }

        return ['ok' => true, 'opts' => [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // 调用方若开了 FOLLOWLOCATION，重定向也只能落回 http(s)
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => self::pin((string) $vet['host'], (int) $vet['port'], (string) $vet['ip']),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]];
    }

    /**
     * `CURLOPT_RESOLVE` 的一条映射；主机本身就是 IP 字面量时返回空数组。
     *
     * 钉 IP 治的是「过闸时解析一次、连接时再解析一次」的 DNS 重绑定：主机是 IP 字面量时
     * 不存在两次解析，拼上去只是噪音（curl 对 IP 主机根本不做解析）。IPv6 字面量更不能
     * 直接拼——spec 本身以 `:` 分段，地址里全是冒号，与其赌 curl 怎么切，不如不拼
     * （少一条而不是错一条）。vet() 已经把 `[...]` 方括号剥掉，所以这里按裸地址判。
     *
     * @return list<string>
     */
    private static function pin(string $host, int $port, string $ip): array
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false ? [] : [$host . ':' . $port . ':' . $ip];
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];
        foreach (@dns_get_record($host, DNS_A) ?: [] as $rec) {
            if (isset($rec['ip']) && is_string($rec['ip'])) {
                $ips[] = $rec['ip'];
            }
        }
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $rec) {
            if (isset($rec['ipv6']) && is_string($rec['ipv6'])) {
                $ips[] = $rec['ipv6'];
            }
        }
        if ($ips === []) {
            // gethostbyname 失败时原样返回主机名：交给 filter_var 判掉，别当成 0.0.0.0
            $one = gethostbyname($host);
            if ($one !== $host && filter_var($one, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $one;
            }
        }

        return $ips;
    }

    private static function allowPrivate(): bool
    {
        return (bool) env('MARKET_ALLOW_PRIVATE', false);
    }

    private static function absolute(string $location, string $from): string
    {
        // 带 scheme 就原样交回：把 `file:///etc/passwd` 拼成本机 http 路径的话，
        // vet() 看不到真实意图，拒绝原因也变成莫名其妙的 404——协议门禁必须由 vet() 给。
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($from);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        // 协议相对地址（`//evil/x`）继承当前 scheme，别糊成当前主机的一段路径
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        $base = $scheme . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $base .= ':' . $parts['port'];
        }

        return $base . (str_starts_with($location, '/') ? $location : '/' . $location);
    }
}
