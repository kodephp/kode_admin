<?php

declare(strict_types=1);

namespace app\tests;

use PHPUnit\Framework\TestCase;

/**
 * 静态门禁：一方代码（app/ 与 plugins/）不得出现 eval，也不得建全局可写路径。
 *
 * 两处历史 eval 都不是「临时调试代码」，而是带着安全外衣长期存在的：
 *  - ScheduleController::create() 把请求里的 handler 字符串拼进 eval（黑名单可绕）；
 *  - WorkflowBridge 的 safeEvaluateCondition 注释写着「替代 eval」，两条分支却都以 eval 收尾。
 * 它们的共同点是：把外部输入当代码执行，再用一层字符串过滤自证安全。
 *
 * 用 token_get_all 而不是正则：注释里的 "eval(...)" 字样（本仓文档就有）不该被算作违规，
 * 而真正的 T_EVAL 记号无法靠注释躲过这道门。
 */
final class DynamicCodeGateTest extends TestCase
{
    /** @var list<int> 语言构造：把数据当代码执行 */
    private const BANNED_TOKENS = [T_EVAL];

    public function test_first_party_code_has_no_eval(): void
    {
        $offenders = [];

        foreach (self::firstPartyFiles() as $file) {
            $tokens = token_get_all((string) file_get_contents($file));

            foreach ($tokens as $token) {
                if (is_array($token) && in_array($token[0], self::BANNED_TOKENS, true)) {
                    $relative = str_replace(dirname(__DIR__) . '/', '', $file);
                    $offenders[] = $relative . ':' . $token[2] . ' ' . $this->lineOf($file, $token[2]);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "发现 eval：外部输入一旦被当作代码执行，任何字符串黑名单都只是装饰" .
            "（\$f='sys'.'tem'; \$f('id') 即可绕过）。请改为白名单查表或自建解释器：\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * 静态门禁：mkdir/chmod 不得给「其他用户」留写位（0777 一类）。
     *
     * 本仓 6 处 `mkdir($dir, 0777, true)` 建的是上传目录、插件解包目录与 Doctrine 代理目录。
     * PHP 的 mode 会被 umask 削一刀（通常 0777→0755），所以多数机器上"看着没事"；
     * 但 umask=0 的容器/守护进程里这些目录真的全局可写 —— 别的账号往里塞一个 .php，
     * 就是任意文件落地。写死 0775 让行为不再依赖运行环境的 umask。
     */
    public function test_first_party_code_never_creates_a_world_writable_path(): void
    {
        $offenders = [];

        foreach (self::firstPartyFiles() as $file) {
            foreach (file($file) ?: [] as $no => $line) {
                if (!preg_match('/\b(mkdir|chmod)\s*\([^)]*\b0[o]?([0-7]{3})\b/i', $line, $m)) {
                    continue;
                }
                if (octdec($m[2]) & 0o002) {
                    $offenders[] = str_replace(dirname(__DIR__) . '/', '', $file) . ':' . ($no + 1)
                        . ' ' . trim($line);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "发现全局可写的 mkdir/chmod 模式：umask=0 时别的账号能往这些目录里写文件"
            . "（上传目录尤其致命）。请显式用 0775：\n  " . implode("\n  ", $offenders)
        );
    }

    /** 顺手看一眼 eval 附近的源码，便于定位（只读文本，不求精确） */
    private function lineOf(string $file, int $line): string
    {
        $lines = file($file);

        return trim((string) ($lines[$line - 1] ?? ''));
    }

    /**
     * @return list<string>
     */
    private static function firstPartyFiles(): array
    {
        $root = dirname(__DIR__);
        $files = [];

        foreach (['app', 'plugins'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $entry) {
                if ($entry->isFile() && $entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }

        sort($files);

        self::assertNotEmpty($files, '扫描目录为空说明门禁本身失效');

        return $files;
    }
}
