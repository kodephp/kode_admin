<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Testing\TestCase;

/**
 * .env.example 与代码读取点的对齐测试。
 *
 * 为什么需要：配置哑火通常是静默的——代码里写了 env('SHARD_DB_COUNT', 2)，
 * 模板没登记这个键，运维只能去翻 config 源码；反过来在模板里写 `KEY=` 空值更阴险：
 * 框架 env() 只在变量「缺失」时才回落默认（见 Kode\Framework\Support\EnvLoader，
 * `KEY=` 会实打实写进 $_ENV），于是空串把代码默认值顶掉，
 * 例如 `PHP_BINARY=` 会让子进程命令变成 escapeshellarg('')。
 * 本测试锁两条：读取点必登记、空值登记不得遮蔽非空默认。
 */
final class EnvDeclarationTest extends TestCase
{
    private const SCAN_DIRS = ['config', 'app', 'plugins'];

    /** 模板里显式判空的键：空串对它们等价于「未配置」，消费端写了 `$x === ''` 分支。 */
    private const EMPTY_OK = [
        'CRYPTO_KEY',           // app\support\Crypto 用 `?:` 回落 JWT_SECRET
        'UNI3_PRESET_DIR',      // config/uni3.php → FrontendPlugin::presetDir() 判空回落
        'UNI3_LICENSE_KEY_DIR', // LicenseService::keyDir() 判空回落 storage
    ];

    public function test_every_env_key_read_by_code_is_declared_in_example(): void
    {
        $read = $this->envKeysRead();
        self::assertNotEmpty($read, '扫描逻辑失效：未在任何读取点找到 env() 调用');

        $declared = $this->envKeysDeclared();
        $missing = array_values(array_diff($read, $declared));

        self::assertSame(
            [],
            $missing,
            '以下 env 键被代码读取但未在 .env.example 登记（运维无从发现该开关）：' . implode(', ', $missing)
        );
    }

    /**
     * 以空值登记的键（`KEY=`），其读取点的默认值必须也是空值或无默认，
     * 否则复制模板生成的 .env 会把代码默认值静默顶成空串。
     */
    public function test_empty_declared_keys_do_not_shadow_code_defaults(): void
    {
        $empty = $this->envKeysDeclaredEmpty();
        self::assertNotEmpty($empty, '扫描逻辑失效：模板中一个空值键都没有');

        $shadowed = [];
        foreach ($this->envReadSites() as [$key, $default]) {
            if (!in_array($key, $empty, true) || in_array($key, self::EMPTY_OK, true)) {
                continue;
            }
            if (!$this->isEmptyishDefault($default)) {
                $shadowed[] = "{$key}（默认 {$default}）";
            }
        }

        self::assertSame(
            [],
            $shadowed,
            '模板以空值登记却遮蔽了非空默认值，改成注释行 #KEY=示例值：' . implode(', ', $shadowed)
        );
    }

    /** @return list<string> 代码读取到的 env 键名（去重排序） */
    private function envKeysRead(): array
    {
        $keys = [];
        foreach ($this->envReadSites() as [$key, $_]) {
            $keys[$key] = true;
        }
        $out = array_keys($keys);
        sort($out);

        return $out;
    }

    /** @return list<array{0:string,1:string}> 每个 env('KEY' 读取点及其默认值原文（无默认则空串） */
    private function envReadSites(): array
    {
        $root = dirname(__DIR__);
        $sites = [];

        foreach (self::SCAN_DIRS as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$dir}"));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                // 键名单独取（无默认的读取点也要计入登记检查），默认值再从其后面解析：
                // 只到下一个分隔符，嵌套 env('A', env('B', 'x')) 会停在 A 那层，非空即视为有默认。
                if (!preg_match_all('/env\(\s*[\'"]([A-Z0-9_]+)[\'"](.*)/', $src, $m, PREG_SET_ORDER)) {
                    continue;
                }
                foreach ($m as $one) {
                    $default = '';
                    if (preg_match('/^\s*,\s*(\'[^\']*\'|"[^"]*"|[^,;)\n]+)/', $one[2], $d)) {
                        $default = trim($d[1]);
                    }
                    $sites[] = [$one[1], $default];
                }
            }
        }

        return $sites;
    }

    /** @return list<string> 模板中出现的键名（含注释行 #KEY=，注释同样算「已告知」） */
    private function envKeysDeclared(): array
    {
        $keys = [];
        foreach ($this->templateLines() as [$key, $_]) {
            $keys[$key] = true;
        }
        $out = array_keys($keys);
        sort($out);

        return $out;
    }

    /** @return list<string> 模板中以空值登记的键名（注释行不生效，不计入） */
    private function envKeysDeclaredEmpty(): array
    {
        $out = [];
        foreach ($this->templateLines() as [$key, $value, $commented]) {
            if (!$commented && $value === '') {
                $out[] = $key;
            }
        }
        sort($out);

        return $out;
    }

    /** @return iterable<array{0:string,1:string,2:bool}> [键名, 值原文, 是否注释行] */
    private function templateLines(): iterable
    {
        $lines = file(dirname(__DIR__) . '/.env.example', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            if (!preg_match('/^\s*(#?)\s*([A-Z0-9_]+)=(.*)$/', $line, $m)) {
                continue;
            }
            yield [$m[2], trim($m[3]), $m[1] === '#'];
        }
    }

    /** 无默认 / '' / null / [] 视为空值默认，与空串登记行为一致。 */
    private function isEmptyishDefault(string $default): bool
    {
        return in_array($default, ['', 'null', '[]', "''"], true);
    }
}
