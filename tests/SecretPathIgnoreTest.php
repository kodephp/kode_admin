<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Framework\Testing\TestCase;

/**
 * 密钥目录必须在忽略面上。
 *
 * `LicenseService` 把 RSA-2048 的**私钥**落在 `storage/keys/lowcode-license/license.private.pem`
 * （运行时生成、0600）。那个目录此前既没被 `.gitignore` 覆盖、也没被跟踪 —— 也就是说
 * 「顺手 `git add -A`」一次就能把签发低代码授权码的私钥推进仓库（远端一旦公开，
 * 全部已签与将来签发的 license 都等于没签名，而换密钥意味着所有已发放的授权要重签）。
 * 同类风险在这一圈是隐形的：文件不报错、不告警，只是躺在 untracked 列表里。
 *
 * 判据问的是 git 自己（`check-ignore`），不是正则扫 `.gitignore` —— 后者会在
 * 「规则写错了但看着像对的」时候全绿。四条一起看：机制通不通（`.env` 必须被判定忽略）、
 * 密钥目录在不在忽略面、仓库里有没有已经跟踪上的密钥文件、以及**不能把整仓忽略掉**
 * （`AGENTS.md` 必须判「未忽略」，否则加一条 `/` 也能骗过这条门禁）。
 */
final class SecretPathIgnoreTest extends TestCase
{
    private const KEY_PROBE = 'storage/keys/lowcode-license/zz-probe.pem';

    public function test_gitignore_covers_the_license_key_directory(): void
    {
        $root = dirname(__DIR__);

        self::assertSame(
            0,
            $this->checkIgnore($root, '.env'),
            'check-ignore 连 .env 都没判成忽略：这条门禁的机制不通（git 不可用？仓库没初始化？）'
        );

        self::assertSame(
            0,
            $this->checkIgnore($root, self::KEY_PROBE),
            'license 私钥所在目录不在忽略面上：一次 git add -A 就能把签发私钥推进仓库'
        );

        self::assertSame(
            1,
            $this->checkIgnore($root, 'AGENTS.md'),
            '普通源码文件被判成忽略：忽略规则太宽，等于把仓库清空'
        );
    }

    /** 仓库里今天不许已经有跟踪中的密钥文件（历史遗留要单独处理，不许静默放过）。 */
    public function test_no_key_material_is_tracked(): void
    {
        $root = dirname(__DIR__);
        $out = [];
        exec('git -C ' . escapeshellarg($root) . ' ls-files -- storage/keys 2>/dev/null', $out, $code);

        self::assertSame(0, $code, 'git ls-files 没跑起来：断言不能建立在「读不到」上');
        self::assertSame([], $out, 'storage/keys 下有文件已被跟踪：私钥已经进过仓库，需要换密钥并清历史');
    }

    /** git 的退出码：0 = 被忽略，1 = 未被忽略。 */
    private function checkIgnore(string $root, string $path): int
    {
        $out = [];
        exec(
            'git -C ' . escapeshellarg($root) . ' check-ignore -q -- ' . escapeshellarg($path),
            $out,
            $code
        );

        return (int) $code;
    }
}
