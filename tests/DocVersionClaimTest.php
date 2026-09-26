<?php

declare(strict_types=1);

namespace app\tests;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;

/**
 * 静态门禁：README-ADMIN 首屏写出的依赖版本号，必须等于 vendor 里**实际装着**的版本。
 *
 * 为什么单独一条：`README-ADMIN.md` 的「> 框架 v1.x.y」是全仓唯一一处人写的框架版本自述，
 * 而它每次 `composer update kode/framework` 之后都会静静变老 —— 升级动作在 lock 文件里、
 * 在这行散文里，两处没人会对账。报旧版本的代价不是难看：排查时会按旧版本的行为去读
 * 框架源码（v1.10.2 刚把 `/health` 的 `status` 从恒 `ok` 改成随探针，按 v1.10.1 读就会
 * 得出「探针坏了也回 ok」的相反结论）。
 *
 * 门禁自己的两头：
 *   - 必须**真的解析到**版本号（正则失效时会得到空断言集，等于空转）；
 *   - 装过的包才比对（未安装的包直接报，避免「键名写错→静默跳过」）。
 */
final class DocVersionClaimTest extends TestCase
{
    /**
     * 文档里的写法 => vendor 包名。
     *
     * @var array<string, string>
     */
    private const CLAIMS = [
        '框架' => 'kode/framework',
        'http' => 'kode/http',
    ];

    public function test_readme_version_claims_match_installed_versions(): void
    {
        $line = $this->claimLine();

        $stale = [];
        $checked = 0;

        foreach (self::CLAIMS as $word => $package) {
            if (!preg_match('/' . preg_quote($word, '/') . '\s*v?([0-9]+(?:\.[0-9]+)*)/u', $line, $m)) {
                $stale[] = "{$package}：README-ADMIN 首屏没有「{$word} vX.Y.Z」这一段（写丢了就等于不声明）";
                continue;
            }
            $claimed = $m[1];

            if (!InstalledVersions::isInstalled($package)) {
                $stale[] = "{$package}：文档声明了版本但 vendor 里查无此包（包名写错会让本门禁空转）";
                continue;
            }

            $actual = (string) InstalledVersions::getPrettyVersion($package);
            $checked++;

            if (ltrim($actual, 'v') !== $claimed) {
                $stale[] = "{$package}：文档写 v{$claimed}，实际装着 {$actual}";
            }
        }

        self::assertGreaterThanOrEqual(
            count(self::CLAIMS),
            $checked,
            '只对了 ' . $checked . ' 个版本号，说明解析或包名出了问题'
        );

        self::assertSame([], $stale, "依赖版本自述已过期。升级包后请同步 README-ADMIN.md 首屏：\n  "
            . implode("\n  ", $stale));
    }

    /** 取 README-ADMIN 里同时含「框架」与版本号的那一行。 */
    private function claimLine(): string
    {
        $file = dirname(__DIR__) . '/README-ADMIN.md';
        self::assertFileExists($file);

        foreach (file($file) ?: [] as $no => $text) {
            if ($no > 15) {
                break; // 首屏声明只在前十几行；扫全文会让别的段落误命中
            }
            if (preg_match('/框架\s*v?\d/u', $text) === 1) {
                return rtrim($text);
            }
        }

        self::fail('README-ADMIN.md 首屏找不到「> 框架 vX.Y.Z」这一段（被删了或挪走了，本门禁随之失去射程）');

        return ''; // @codeCoverageIgnore
    }
}
