<?php

declare(strict_types=1);

namespace app\tests;

use app\support\PluginMarket;
use Kode\Framework\Plugin\PluginDiscoverer;
use Kode\Framework\Testing\TestCase;

/**
 * 静态门禁：插件「查的表 / 建的表 / 声明的表」三者必须是同一份。
 *
 * 为什么单独立一道闸：`tables()` 是卸载 purge 的删表清单、备份导出与 pg_dump 提示的
 * 表名 SoT（AGENTS §3「插件表名前缀 + tables() 声明」）。它一旦和真实表结构漂移，
 * 坏法有两种且都无声：
 *   - 建了却没声明 → 卸载删不掉、备份抓不到，表变成孤儿；
 *   - 查了却没人建 → 全新环境装完插件，第一次读就是 `relation does not exist` 500，
 *     而开发库因为有人手工 \dt 过永远复现不出来。
 * 实测翻过车的正是第二种：frontend 插件 5 张 workflow 运维表（budgets/alerts/
 * alert_rules/sla_targets/step_regressions）被几十条路由读写，install() 却只建到
 * webhook_logs；另有 versions/webhook_logs 建了没声明、gitee 两张表只在已停用的
 * GiteeReleasePlugin 里建。
 *
 * 纯解析源码，不连库：断言的是「代码口径自洽」，跑在真实表存在与否之前。
 */
final class PluginSchemaCoverageTest extends TestCase
{
    /** 从源码里抽 CREATE TABLE 目标表名。 */
    private const CREATE_RE = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["\']?(kode_[a-z0-9_]+)/i';

    /** 从源码里抽被查询引用的表名（FROM/JOIN/INSERT INTO/UPDATE 之后的 kode_ 表）。 */
    private const QUERY_RE = '/\b(?:FROM|JOIN|INTO|UPDATE)\s+(kode_[a-z0-9_]+)/i';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    public function test_installed_tables_are_all_declared_in_tables(): void
    {
        foreach ($this->pluginSources() as $name => $sources) {
            $declared = PluginMarket::discover()[$name] ?? null;
            if ($declared === null) {
                continue;
            }
            $tables = $this->declaredTables($declared);
            if ($tables === null) {
                continue; // 插件未提供 tables()/install()，不在本门禁范围
            }

            $created = $this->matchAll($sources, self::CREATE_RE);
            $undeclared = array_values(array_diff($created, $tables));
            self::assertSame(
                [],
                $undeclared,
                $name . '：install() 建了这些表却没写进 tables()，卸载 purge 删不掉、备份也抓不到：'
                . implode('、', $undeclared)
            );
        }
    }

    public function test_queried_tables_are_all_created_by_the_plugin(): void
    {
        foreach ($this->pluginSources() as $name => $sources) {
            $class = PluginMarket::discover()[$name] ?? null;
            if ($class === null) {
                continue;
            }
            $tables = $this->declaredTables($class);
            if ($tables === null) {
                continue;
            }

            $queried = $this->matchAll($sources, self::QUERY_RE);
            // 「查得到但没人建」= 全新环境装完就 500；声明了不代表建了，
            // 所以要拿 install() 真正 CREATE 的集合比，而不是比 tables()。
            $created = $this->matchAll($sources, self::CREATE_RE);
            $missing = array_values(array_diff($queried, $created));
            self::assertSame(
                [],
                $missing,
                $name . '：这些表被 SQL 查询引用，但本插件源码里没有任何 CREATE TABLE，'
                . '全新环境装上即报表不存在：' . implode('、', $missing)
            );
        }
    }

    public function test_declared_tables_are_all_backed_by_real_sql(): void
    {
        foreach ($this->pluginSources() as $name => $sources) {
            $class = PluginMarket::discover()[$name] ?? null;
            if ($class === null) {
                continue;
            }
            $tables = $this->declaredTables($class);
            if ($tables === null) {
                continue;
            }

            $created = $this->matchAll($sources, self::CREATE_RE);
            $ghost = array_values(array_diff($tables, $created));
            self::assertSame(
                [],
                $ghost,
                $name . '：tables() 声明了这些表，但插件源码里没人 CREATE 它们——'
                . 'purge 会 DROP 一张不属于本插件的表，备份也会点名一个本插件造不出的表：'
                . implode('、', $ghost)
            );
        }
    }

    /**
     * 插件不得把「自己声明的表名去掉 kode_ 前缀」的那个裸名字当成表来引用。
     *
     * 这一条管的是上面两条看不见的那类漂移：`tables()` 声明 `kode_notices`、install() 建的也是它，
     * 但 `locate()` 的未分片分支返回了裸 `'notices'` —— 详情/改/删三条按 id 寻址的路径全部 500，
     * 而列表读的是另一支、照样能看（回归见 tests/NoticeSingleTableTest.php）。
     * 前两条闸都比的是 `kode_` 开头的表名，少一个前缀就对它们完全隐形。
     *
     * 两种形状都要挡，因为事故是第二种：
     *  a) 字面 SQL 里出现 `FROM notices`；
     *  b) 字符串字面量恰好是 `'notices'`（表名以变量形式拼进 SQL 时，源码里只剩这一处能看出来）。
     * b 只在 `plugins/<name>/` 下的文件里查：主类里的 `'notices'` 是 `name()`/路由前缀，
     * 那是本仓库的正当约定（AGENTS §3「插件名恒等于 URL 前缀」），不是表名。
     *
     * 黑名单只取「本插件声明的表去掉前缀」，不泛泛地扫所有裸标识符：
     * 前者零误报（那是唯一一种「差一个前缀就是一张不存在的表」的形状），
     * 后者会把 `'hello from demo plugin'` 这类散文一起捞出来。
     */
    public function test_no_plugin_references_its_own_table_without_the_prefix(): void
    {
        $caught = [];
        $scanned = 0;
        $bareSeen = [];
        foreach ($this->pluginSources() as $name => $sources) {
            $class = PluginMarket::discover()[$name] ?? null;
            if ($class === null) {
                continue;
            }
            $tables = $this->declaredTables($class);
            if ($tables === null) {
                continue;
            }
            $bare = [];
            foreach ($tables as $t) {
                if (str_starts_with($t, 'kode_')) {
                    $bare[] = substr($t, strlen('kode_'));
                }
            }
            $mainFile = (new \ReflectionClass($class))->getFileName();
            foreach ($sources as $file) {
                $src = file_get_contents($file);
                if ($src === false || $bare === []) {
                    continue;
                }
                $isMain = is_string($mainFile) && realpath($file) === realpath($mainFile);
                if (!$isMain) {
                    $scanned++;
                    foreach ($bare as $b) {
                        $bareSeen[$b] = true;
                    }
                }
                $code = $this->codeOnly($src);
                foreach ($this->matchBareTable($code, $bare, $isMain) as $hit) {
                    $caught[] = $name . ' → ' . basename($file) . ': ' . $hit;
                }
            }
        }

        // 射程自证：0 命中也可能是「根本没扫到文件」或「bare 集合全空」，那两种情况下
        // 本用例会恒绿成一条什么都没做的闸。把射程本身钉住。
        self::assertGreaterThanOrEqual(
            8,
            $scanned,
            '只扫到 ' . $scanned . " 个「非主类且所属插件声明了 kode_ 表」的插件文件：射程塌了，0 命中不代表没问题"
        );
        self::assertGreaterThanOrEqual(
            3,
            count($bareSeen),
            '裸表名黑名单只有 ' . count($bareSeen) . " 项：多半是前缀剥离写错，而不是插件真的都没声明表"
        );

        // 正对照：同一套匹配器跑在两种已知坏形状上都必须命中，否则「全绿」什么都不证明
        // （前缀剥离写错、bare 集合为空、正则退化、b 形状漏掉都能造成恒绿）。
        self::assertNotSame(
            [],
            $this->matchBareTable("SELECT * FROM notices WHERE id = 1", ['notices'], true),
            '字面 SQL 的裸表名没被匹配器命中：下面的断言是恒绿的'
        );
        self::assertNotSame(
            [],
            $this->matchBareTable("return [null, 'notices', \$id];", ['notices'], false),
            '字符串字面量形态（事故的那一种）没被命中：控制器里的表名变量根本没有判据'
        );
        // 主类豁免只对 b 生效：主类里写真SQL照样算命中。
        self::assertNotSame(
            [],
            $this->matchBareTable("SELECT * FROM notices", ['notices'], true),
            '主类连字面 SQL 都豁免了：豁免范围写错'
        );
        // 反向对照：注释里复述这个坏形状不该算命中（门禁不许吃掉自己的解释）。
        self::assertSame(
            [],
            $this->matchBareTable($this->codeOnly("<?php\n// 曾经写成 'notices'\n\$x = 1;\n"), ['notices'], false),
            '注释没被剥掉：这条门禁会把文档里的历史说明读成缺陷'
        );
        // 反向对照：主类里的 name() 不算表名。
        self::assertSame(
            [],
            $this->matchBareTable("return 'notices';", ['notices'], true),
            'name()/路由前缀被判成表名：AGENTS §3 的约定被自家门禁打断'
        );

        self::assertSame(
            [],
            $caught,
            '这些位置引用了「本插件声明表去掉 kode_ 前缀」的名字，那张表并不存在（全新环境即 500）：'
            . implode('、', $caught)
        );
    }

    /**
     * 每个已发现插件的源码文件集合（主类 + `plugins/<name>/` 下全部 .php）。
     *
     * @return array<string, list<string>> name => 文件绝对路径列表
     */
    private function pluginSources(): array
    {
        $base = PluginDiscoverer::baseDir();
        $out = [];
        foreach (PluginMarket::discover() as $name => $class) {
            $files = [];
            $main = (new \ReflectionClass($class))->getFileName();
            if (is_string($main)) {
                $files[] = $main;
            }
            $dir = $base . '/' . $name;
            if (is_dir($dir)) {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $f) {
                    if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                        $files[] = $f->getPathname();
                    }
                }
            }
            $out[$name] = $files;
        }

        return $out;
    }

    /**
     * @param class-string $class
     * @return list<string>|null  声明的表名；插件缺 install()/tables() 时返回 null（跳过）
     */
    private function declaredTables(string $class): ?array
    {
        $ok = method_exists($class, 'install') && method_exists($class, 'tables');
        if (!$ok) {
            return null;
        }
        /** @var list<string> $tables */
        $tables = array_values(array_map('strval', (array) (new $class())->tables()));

        return $tables;
    }

    /**
     * 在「已剥注释」的源码里找两种坏形状。
     *
     * @param list<string> $bare    本插件声明表去掉 kode_ 前缀后的名字
     * @param bool         $isMain  该文件是否插件主类（主类里的 `'notices'` 是 name()/路由前缀，豁免 b）
     * @return list<string> 命中的原文（去重排序）
     */
    private function matchBareTable(string $src, array $bare, bool $isMain = true): array
    {
        $hits = [];
        foreach ($bare as $name) {
            if ($name === '') {
                continue;
            }
            $quoted = preg_quote($name, '/');
            // a) 字面 SQL 直接查裸表名 —— 主类也不例外
            if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+' . $quoted . '\b/i', $src, $m) > 0) {
                foreach ($m[0] as $h) {
                    $hits[$h] = true;
                }
            }
            // b) 字符串字面量恰好是裸表名（表名以变量拼进 SQL 时，源码里只剩这一处看得见）。
            //    两侧引号都要咬住：只咬右引号会把 `..._notices'` 这类后缀读成命中。
            if (!$isMain && preg_match_all('/[\x27"]' . $quoted . '[\x27"]/', $src, $m2) > 0) {
                foreach ($m2[0] as $h) {
                    $hits[$h] = true;
                }
            }
        }
        $list = array_keys($hits);
        sort($list);

        return $list;
    }

    /** 剥掉注释后的源码：门禁不许吃掉自己写给自己的解释。 */
    private function codeOnly(string $src): string
    {
        $out = '';
        foreach (\token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    /**
     * 对一批文件跑同一条正则，返回去重排序后的捕获组。
     *
     * @param list<string> $files
     * @return list<string>
     */
    private function matchAll(array $files, string $regex): array
    {
        $hits = [];
        foreach ($files as $file) {
            $src = file_get_contents($file);
            if ($src === false) {
                continue;
            }
            if (preg_match_all($regex, $src, $m) > 0) {
                foreach ($m[1] as $t) {
                    $hits[strtolower($t)] = true;
                }
            }
        }
        $list = array_keys($hits);
        sort($list);

        return $list;
    }
}
