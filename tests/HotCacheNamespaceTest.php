<?php

declare(strict_types=1);

namespace app\tests;

use app\support\HotCache;
use Kode\Framework\Testing\TestCase;

/**
 * 热缓存命名空间的对账：代码里真实用到的前缀 ⟺ `HotCache::NAMESPACES` 登记的前缀。
 *
 * 挡的是这一类：新写一个 `HotCache::remember("widget:{$id}", 300, …)` 却没登记进 NAMESPACES，
 * 于是「清理热缓存 / 一键修复」两个口都不会碰它 —— 超管点了清理，插件仍按 5 分钟 TTL 读旧值，
 * 而接口回一句成功。此前 cacheClear 只清 `menus:`/`config:`，
 * 插件侧的 `plugin:config:*`、`plugin:status:*` 就是这么漏在清理面外面的。
 *
 * 扫描去掉注释再匹配：否则一句注释里写 `HotCache::forget("legacy:")` 就算命中了。
 */
final class HotCacheNamespaceTest extends TestCase
{
    protected bool $independentApp = true;

    /** @var list<string> 本次跑写下的探针键（按命名空间清不掉时兜底逐个删） */
    private array $probeKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->probeKeys as $key) {
            HotCache::forget($key);
        }
        $this->probeKeys = [];

        parent::tearDown();
    }

    /**
     * 扫 app/ 与 plugins/ 的**非注释**代码，取出 `HotCache::…` 调用里的字面量前缀。
     *
     * @return list<string>
     */
    private function usedNamespaces(): array
    {
        $found = [];
        $files = [];
        foreach (['app', 'plugins'] as $dir) {
            $root = dirname(__DIR__) . '/' . $dir;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f instanceof \SplFileInfo && $f->isFile() && $f->getExtension() === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }
        self::assertGreaterThanOrEqual(50, count($files), '扫描面失效：只找到 ' . count($files) . ' 个文件');

        foreach ($files as $file) {
            // 唯一派生口自己不在射程内：它的 NAMESPACES 常量里就写着这些前缀
            if (basename($file) === 'HotCache.php') {
                continue;
            }
            $code = '';
            foreach (token_get_all((string) file_get_contents($file)) as $tok) {
                if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= "\n";
                    continue;
                }
                $code .= is_array($tok) ? $tok[1] : $tok;
            }
            if (preg_match_all('/HotCache::(?:remember|forget|get|set|clear)\(\s*[\'"]([a-z][a-z0-9_-]*:)/', $code, $m)) {
                foreach ($m[1] as $prefix) {
                    $found[$prefix] = true;
                }
            }
        }

        return array_keys($found);
    }

    public function test_every_namespace_used_in_code_is_registered(): void
    {
        $used = $this->usedNamespaces();
        // 防空转：扫不到任何前缀说明正则/扫描面坏了，下面的比对会是真命题。
        self::assertGreaterThanOrEqual(3, count($used), '一个 HotCache 前缀都没扫到：' . implode(',', $used));

        $uncovered = [];
        foreach ($used as $prefix) {
            $covered = false;
            foreach (HotCache::NAMESPACES as $ns) {
                if (str_starts_with($prefix, $ns)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $uncovered[] = $prefix;
            }
        }

        self::assertSame(
            [],
            $uncovered,
            '这些热缓存前缀有人在写、却不在 HotCache::NAMESPACES 里：清理口永远碰不到它们，'
            . '「清理热缓存/一键修复」之后仍会读到旧值 ——' . implode('、', $uncovered)
        );
    }

    public function test_registered_namespaces_are_cleared_by_clear_all(): void
    {
        if (HotCache::connection() === null) {
            // 跳过必须写明原因：否则「没跑」和「跑过且绿」在输出上长得一样。
            $this->markTestSkipped('redis 不可用，无法验证 clearAll() 真的按命名空间删键');
        }

        $token = bin2hex(random_bytes(6));
        foreach (HotCache::NAMESPACES as $ns) {
            $key = $ns . 'probe_' . $token;
            $this->probeKeys[] = $key;
            HotCache::set($key, 'x', 60);
            self::assertSame('x', HotCache::get($key), "写入即读不到：{$key}");
        }

        HotCache::clearAll();

        foreach (HotCache::NAMESPACES as $ns) {
            $key = $ns . 'probe_' . $token;
            self::assertNull(
                HotCache::get($key),
                "clearAll() 没清掉 {$ns} 下的键：这个命名空间写在 NAMESPACES 里却没被清理面覆盖"
            );
        }
    }
}
