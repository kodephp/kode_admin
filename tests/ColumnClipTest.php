<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\AuditController;
use app\admin\controllers\AuthController;
use app\admin\controllers\ConfigController;
use app\admin\controllers\DbController;
use app\admin\controllers\DeptController;
use app\admin\controllers\DictController;
use app\admin\controllers\MenuController;
use app\admin\controllers\RoleController;
use app\admin\controllers\SyslogController;
use app\admin\controllers\TenantController;
use app\admin\controllers\UserController;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * 用户可控文本被**按字节**截到列宽时，pgsql 收到的不再是合法 UTF-8（实测 SQLSTATE[22021]），
 * 一枚查询参数或一次正常保存就能把接口打成 500。
 *
 * 根因不在「截不截」，而在截的**单位**：pgsql 的 `VARCHAR(n)` 数的是字符（AGENTS.md §3
 * 「列宽按字符截」那条只写在 `AuditTrail::clip()` 的注释里，于是全仓只有审计一处是对的）。
 * 一个汉字 3 字节，`substr($v, 0, 64)` 落在第 22 个字中间，尾巴留半个字符 ——
 * 报错因此不是「太长」而是「编码非法」，于是校验放行、落库却炸：
 * `remark => 'nullable|max:255'` 认可 255 个字，紧接着 `substr(..., 0, 255)` 按字节切它。
 *
 * 四种形状各挡一种错法：
 *  - **读侧筛选**（`keyword`/`search`/`type`/`path`）：值绑进 `LIKE ?`，一枚查询参数触发 500；
 *  - **写侧落库**（`remark`/`name`/`title`/`component`）：校验已经限过字符数，这里的截断
 *    既多余又是唯一破坏源 —— 修法是删掉截断、把列宽交给 `length:N`，
 *    而不是换成 `mb_substr` 继续静默吃掉用户写的那几个字；
 *  - **旁路日志**（登录日志）：`catch (\Throwable) {}` 把 INSERT 失败吃成「什么都没发生」，
 *    于是爆破尝试**从日志里消失**，页面与接口都不报错；
 *  - **出参展示**（表数据浏览那一列 `substr($v, 0, 2000)`）：切坏之后整份响应
 *    `json_encode` 失败（`kode/http` 的 `Response::json()` 带 `JSON_THROW_ON_ERROR`），
 *    一列长文本让**整个面板**变 500，而不只是那一格。
 *
 * 每条断言都先证明样本的**字节数**真的越过对应的列宽，否则「255 个字」这种前提会随列宽
 * 定义漂移，用例就在自证的空档里恒绿。
 */
final class ColumnClipTest extends TestCase
{
    protected bool $independentApp = true;

    private const TAG = 'zz-clip';

    /** @var list<array{0: string, 1: list<mixed>}> 本文件建的行，tearDown 精确删除（一次红不许留下常驻数据） */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as [$sql, $bind]) {
            try {
                Db::statement($sql, $bind);
            } catch (\Throwable) {
                // 收尾失败不掩盖断言结果；下面的按列删除是兜底
            }
        }
        $this->cleanup = [];

        Db::delete('DELETE FROM kode_roles WHERE slug = ?', [self::TAG . '-role']);
        Db::delete('DELETE FROM kode_depts WHERE name LIKE ?', [self::TAG . '-%']);
        Db::delete('DELETE FROM kode_menus WHERE title LIKE ?', [self::TAG . '-%']);
        Db::delete('DELETE FROM kode_sys_configs WHERE ckey = ?', ['zz.clip.probe']);
        Db::delete('DELETE FROM kode_login_logs WHERE username = ?', [self::loginSubject()]);
        \app\support\HotCache::forget('menus:tree');

        HttpRequest::clear();

        parent::tearDown();
    }

    /* ==================== 读侧筛选：一枚查询参数打成 500 ==================== */

    public function test_syslog_index_survives_a_multibyte_keyword(): void
    {
        // keyword 截到 128：60 个汉字 = 180 字节，正好从中间切开。
        $keyword = self::cn(60);
        self::assertGreaterThan(128, strlen($keyword), '样本没越过 keyword 的字节界，这条用例是自证的');

        $res = $this->ask(new SyslogController(), 'index', ['keyword' => $keyword]);

        self::assertSame(200, $res['status'], '中文关键词被按字节切开后 pgsql 拒收：' . $res['why']);
    }

    public function test_syslog_index_survives_a_multibyte_level_filter(): void
    {
        // level 只截 16 字节 = 5 个汉字有余，短到粘一个中文词就撞上。
        $level = self::cn(8);
        self::assertGreaterThan(16, strlen($level));

        $res = $this->ask(new SyslogController(), 'index', ['level' => $level]);

        self::assertSame(200, $res['status'], $res['why']);
    }

    public function test_dict_items_survive_a_multibyte_type(): void
    {
        $type = self::cn(40);
        self::assertGreaterThan(64, strlen($type));

        $res = $this->ask(new DictController(), 'items', ['type' => $type]);

        self::assertSame(200, $res['status'], '不存在的类型应当是空列表，不是 500：' . $res['why']);
        self::assertSame([], $res['data']);
    }

    public function test_role_index_survives_a_multibyte_search(): void
    {
        $search = self::cn(40);
        self::assertGreaterThan(64, strlen($search));

        $res = $this->ask(new RoleController(), 'index', ['search' => $search]);

        self::assertSame(200, $res['status'], $res['why']);
    }

    public function test_user_index_survives_a_multibyte_search(): void
    {
        // search 与 remark 两个筛选参数各自截到 64 字节，两条腿都得活着过来。
        $search = self::cn(40);
        self::assertGreaterThan(64, strlen($search));

        $res = $this->ask(new UserController(), 'index', ['search' => $search, 'remark' => self::cn(40)]);

        self::assertSame(200, $res['status'], $res['why']);
    }

    public function test_audit_index_survives_a_multibyte_path(): void
    {
        $path = self::cn(120);
        self::assertGreaterThan(255, strlen($path));

        $res = $this->ask(new AuditController(), 'index', ['path' => $path, 'username' => self::cn(40)]);

        self::assertSame(200, $res['status'], $res['why']);
        // 这一族有 `Shard::scan` 兜着，报错不会浮到状态码上：中文条件把某一片读崩之后，
        // 页面看到的是「200 + 一条都没有」，而这台系统明明有流水。缺片口径必须为空。
        self::assertArrayHasKey('unreadable_shards', $res['body'], '恒在键缺失，客户端无法区分新旧后端');
        self::assertSame(
            [],
            $res['body']['unreadable_shards'],
            '筛选条件被按字节切坏，读失败被糊成「这一片没有数据」：'
            . json_encode($res['body']['unreadable_shards'], JSON_UNESCAPED_UNICODE)
        );
    }

    public function test_tenant_index_survives_a_multibyte_keyword(): void
    {
        $keyword = self::cn(40);
        self::assertGreaterThan(64, strlen($keyword));

        $res = $this->ask(new TenantController(), 'index', ['keyword' => $keyword]);

        self::assertSame(200, $res['status'], $res['why']);
    }

    public function test_login_log_list_survives_a_multibyte_username(): void
    {
        $username = self::cn(40);
        self::assertGreaterThan(64, strlen($username));

        $res = $this->ask(new AuthController(), 'loginLogsAll', ['username' => $username]);

        self::assertSame(200, $res['status'], $res['why']);
    }

    /* ==================== 写侧落库：校验放行、落库却炸 ==================== */

    public function test_role_store_keeps_the_full_remark(): void
    {
        // 255 是 3 的倍数，纯三字样的样本在字节界上恰好落进字缝里（不报错，只丢字），
        // 所以这一条要混排：84 个「菜」+ 4 个「é」= 260 字节，切点落在半个 é 上。
        $remark = str_repeat('菜', 84) . str_repeat('é', 4);
        self::assertSame(260, strlen($remark));
        self::assertFalse(mb_check_encoding(substr($remark, 0, 255), 'UTF-8'), '前提不成立：这个样本切不出非法 UTF-8');

        $res = $this->submit(new RoleController(), 'store', [
            'name' => self::TAG . '-role',
            'slug' => self::TAG . '-role',
            'remark' => $remark,
        ]);

        self::assertSame(201, $res['status'], '校验认可 255 个字，按字节截断却让落库变 500：' . $res['why']);
        $stored = Db::select('SELECT remark FROM kode_roles WHERE slug = ?', [self::TAG . '-role']);
        self::assertSame(
            $remark,
            (string) ($stored[0]['remark'] ?? ''),
            '用户写的备注没整条落库（换成 mb_substr 也算吃掉，列宽交给校验）'
        );
    }

    public function test_dept_store_keeps_the_full_remark(): void
    {
        // 255 个字 = 765 字节，而 `substr(…, 0, 255)` 恰好切在字边界上 —— 不报错，
        // 于是这条腿露出的是另一半缺陷：用户写完 255 个字，库里只留下 85 个，页面毫无提示。
        $remark = self::cn(255);
        self::assertSame(0, strlen($remark) % 255, '切点必须落在字缝里，这条测的才是「不报错的丢字」');

        $res = $this->submit(new DeptController(), 'store', [
            'name' => self::TAG . '-dept',
            'remark' => $remark,
        ]);

        self::assertSame(201, $res['status'], $res['why']);
        $stored = Db::select('SELECT remark FROM kode_depts WHERE name = ?', [self::TAG . '-dept']);
        self::assertSame($remark, (string) ($stored[0]['remark'] ?? ''));
    }

    public function test_menu_store_keeps_the_full_name(): void
    {
        $name = self::cn(64);
        self::assertGreaterThan(64, strlen($name));

        $res = $this->submit(new MenuController(), 'store', [
            'title' => self::TAG . '-menu',
            'path' => '/zz-clip-menu',
            'name' => $name,
        ]);

        self::assertSame(201, $res['status'], $res['why']);
        $stored = Db::select('SELECT name FROM kode_menus WHERE title = ?', [self::TAG . '-menu']);
        self::assertSame($name, (string) ($stored[0]['name'] ?? ''));
    }

    public function test_config_set_keeps_the_full_title(): void
    {
        $title = self::cn(64);
        self::assertGreaterThan(64, strlen($title));

        $res = $this->submit(new ConfigController(), 'set', [
            'key' => 'zz.clip.probe',
            'value' => '1',
            'title' => $title,
        ]);

        self::assertSame(200, $res['status'], $res['why']);
        $stored = Db::select('SELECT ctitle FROM kode_sys_configs WHERE ckey = ?', ['zz.clip.probe']);
        self::assertSame($title, (string) ($stored[0]['ctitle'] ?? ''));
    }

    /* ==================== 旁路日志：失败被 catch 吃成「没发生过」 ==================== */

    /**
     * 爆破尝试的用户名是攻击者可控的：按字节切坏之后 INSERT 抛错，而 `logLogin()` 外层
     * 是 `catch (\Throwable) {}`（「失败绝不影响登录」），于是日志里连尝试痕迹都没有。
     *
     * `logLogin()` 是 private static，唯一的公开调用方是 `login()` —— 拿真登录做断言会消耗
     * `AttemptGuard` 的 IP 预算（同 IP 10 分钟 10 次失败即 429，会把后面所有登录用例锁死），
     * 所以这里按反射调用，并把「反射不可用」本身当成失败点。
     */
    public function test_a_multibyte_login_attempt_still_reaches_the_log(): void
    {
        $subject = self::loginSubject();
        self::assertGreaterThan(64, strlen($subject), '样本没越过 username 列的字节界，这条用例是自证的');

        // logLogin() 自己读 User-Agent 头与来路 IP，没有请求上下文就轮不到它落库。
        HttpRequest::setRequest(
            (new ServerRequest('POST', '/api/auth/login'))
                ->withHeader('User-Agent', 'Mozilla/5.0 (zz-clip probe)')
                ->withAttribute('auth', (object) ['uid' => 1, 'username' => self::TAG, 'roles' => ['admin']])
        );

        $before = $this->loginLogCount($subject);
        $method = new \ReflectionMethod(AuthController::class, 'logLogin');
        $method->setAccessible(true);
        $method->invoke(null, 0, $subject, false, '用户名或密码错误');

        self::assertSame(
            $before + 1,
            $this->loginLogCount($subject),
            '中文用户名把登录日志的 INSERT 打成非法 UTF-8，而那个异常被 catch 吃掉了'
        );
    }

    /* ==================== 出参展示：一列长文本让整份响应编码失败 ==================== */

    public function test_table_browse_encodes_a_long_multibyte_cell(): void
    {
        $table = 'zz_clip_probe';
        // 两条腿：A 超过展示宽度（要被截且带省略号），B 字节数超、字符数不超（不许动它）。
        $wide = self::cn(2100);
        $bytesOnly = self::cn(700);
        self::assertGreaterThan(2000, strlen($bytesOnly), 'B 的前提是字节数越过旧的字节预算');
        self::assertLessThanOrEqual(2000, mb_strlen($bytesOnly), 'B 的前提是字符数没越界');

        Db::statement("CREATE TABLE {$table} (id integer, body text)");
        $this->cleanup[] = ["DROP TABLE IF EXISTS {$table}", []];
        // 这张探针表没有序列，走 Db::insert() 会让 lastval() 先报错，测不到想看的那一行。
        Db::statement("INSERT INTO {$table} (id, body) VALUES (?, ?)", [1, $wide]);
        Db::statement("INSERT INTO {$table} (id, body) VALUES (?, ?)", [2, $bytesOnly]);

        $res = $this->ask(new DbController(), 'rows', [], ['table' => $table]);

        self::assertSame(200, $res['status'], '长中文单元格让整份响应 json 编码失败：' . $res['why']);
        $cells = [];
        foreach ((array) ($res['data'] ?? []) as $row) {
            $cells[(int) $row['id']] = (string) ($row['body'] ?? '');
        }
        self::assertArrayHasKey(1, $cells, '表格浏览没返回那一列，前提不成立');

        self::assertTrue(mb_check_encoding($cells[1], 'UTF-8'), '截断后的单元格仍是非法 UTF-8');
        self::assertStringEndsWith('…', $cells[1], '截短了却没说，读起来像全量');
        self::assertSame(2001, mb_strlen($cells[1]), '展示宽度按字符算，不是按字节');
        self::assertSame($cells[2], $bytesOnly, '按字节判长会把 700 个汉字的完整值也标成截断');

        self::assertSame(
            $wide,
            (string) Db::select("SELECT body FROM {$table} WHERE id = 1")[0]['body'],
            '浏览页不该改动落库内容'
        );
    }

    /* ==================== 静态口径：按字节的列宽截断不得复活 ==================== */

    /**
     * 「剪到列宽」这个动作按字节写就是本轮全部缺陷的源头，因此把它钉成静态口径：
     * `app/` 与 `plugins/` 的非注释代码里，`substr($x, <非负整数字面量>, …)` 这种
     * 「从 0/某处剪一段」的形状只允许出现在下面的白名单里（那些是在**解析**结构串：
     * md5 尾巴、hex 前缀、SQL 片段、表名后缀，不是用户文本的列宽截断）。
     *
     * 判据形状刻意不含 `mb_substr`：它按字符数，本来就是对的那一种。
     * 也不含第二参是变量或负数的调用 —— `substr($conn, -2)` 取后缀、
     * `substr($s, $i, 1)` 走字符，都不是「把用户写的东西剪短」。
     */
    public function test_no_byte_sized_clip_survives(): void
    {
        $found = $this->clipSlices();

        self::assertNotEmpty($found, '一个 clip 形状都没扫到 = 扫描面为空，这条门禁在空转');

        $allowed = self::CLIP_PARSE_ALLOWLIST;
        $offenders = [];
        foreach ($found as $rel => $calls) {
            foreach ($calls as $call => $count) {
                if (!in_array($call, $allowed[$rel] ?? [], true)) {
                    $offenders[] = $rel . ': ' . $call;
                }
            }
        }
        self::assertSame([], $offenders, '按字节截列宽会切出非法 UTF-8（22021 / json 编码失败）；'
            . '用户文本请走 Clip::chars，落库宽度交给 length:N 校验');

        // 白名单不许留僵尸：条目还在文件里吗？
        $zombies = [];
        foreach ($allowed as $rel => $calls) {
            foreach ($calls as $call) {
                if (!isset($found[$rel][$call])) {
                    $zombies[] = $rel . ': ' . $call;
                }
            }
        }
        self::assertSame([], $zombies, '白名单里的调用已不存在，条目该删（留着就会掩盖新的同名违规）');
    }

    /**
     * 正对照：把修复前的写法喂给同一个判据，必须被点名。
     * 没有这一条，「扫描面为空」和「全部合规」在输出上长得一样。
     */
    public function test_the_judge_flags_the_pre_fix_shape(): void
    {
        $before = <<<'PHP'
        <?php
        // 注释里的 substr($keyword, 0, 64) 不算违规
        $keyword = substr(trim((string) $this->query('keyword')), 0, 64);
        $short = mb_substr($title, 0, 20);
        $suffix = (int) substr($conn, -2);
        $one = substr($s, $i, 1);
        PHP;

        $calls = $this->clipSlicesIn('inline', $before);

        self::assertSame(
            ["trim((string) \$this->query('keyword')), 0, 64" => 1],
            $calls,
            '判据必须认出旧的按字节截断，且不吃解析型切片和 mb_ 的那一种'
        );
    }

    /** @return array<string, array<string, int>> 相对路径 => [归一化调用 => 次数] */
    private function clipSlices(): array
    {
        $root = dirname(__DIR__);
        $out = [];
        $scanned = [];
        foreach (['app', 'plugins'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($it as $f) {
                if (!$f instanceof \SplFileInfo || $f->getExtension() !== 'php') {
                    continue;
                }
                $path = (string) $f->getRealPath();
                $rel = substr($path, strlen($root) + 1);
                $scanned[] = $rel;
                $calls = $this->clipSlicesIn($rel, (string) file_get_contents($path));
                if ($calls !== []) {
                    $out[$rel] = $calls;
                }
            }
        }
        // 扫描面非空，且真的覆盖了本轮改过的文件 —— 否则「0 违规」可能只是没扫到。
        self::assertGreaterThan(100, count($scanned), '扫描面异常小');
        foreach (['app/admin/controllers/UserController.php', 'app/admin/controllers/DbController.php'] as $must) {
            self::assertContains($must, $scanned, "扫描面没覆盖 {$must}");
        }

        return $out;
    }

    /** @return array<string, int> 归一化调用 => 次数 */
    private function clipSlicesIn(string $rel, string $src): array
    {
        $code = $this->stripComments($src);
        $out = [];
        if (!preg_match_all('/(?<![A-Za-z_0-9])substr\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        foreach ($m[0] as $hit) {
            $args = $this->splitArgs($code, (int) $hit[1] + strlen((string) $hit[0]));
            if (count($args) < 2 || !preg_match('/^\d+$/', $args[1])) {
                continue;
            }
            $normalized = preg_replace('/\s+/', ' ', $args[0] . ', ' . implode(', ', array_slice($args, 1)));
            $out[trim((string) $normalized)] = ($out[trim((string) $normalized)] ?? 0) + 1;
        }

        return $out;
    }

    /** 注释替换成等长空白：偏移与行号都保持，说明文字里的反例因此不会被当成实现。 */
    private function stripComments(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                $out .= preg_replace('/[^\n]/', ' ', (string) $token[1]);
            } else {
                $out .= is_array($token) ? $token[1] : $token;
            }
        }

        return $out;
    }

    /**
     * 从左括号之后扫到配对的右括号，按**顶层逗号**切参数。
     * 必须处理引号与嵌套括号：`substr($a, 0, strpos($b, ','))` 的逗号在字符串和函数里，
     * 直接 split 会把一个参数切成两个，判据因此跟着形状走而不是跟着文本走。
     *
     * @return list<string>
     */
    private function splitArgs(string $code, int $from): array
    {
        $args = [];
        $buf = '';
        $depth = 0;
        $quote = null;
        $len = strlen($code);
        for ($i = $from; $i < $len; $i++) {
            $ch = $code[$i];
            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === '\\' && $quote !== "'") {
                    $buf .= $code[++$i] ?? '';
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $buf .= $ch;
                continue;
            }
            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                if ($depth === 0 && $ch === ')') {
                    $args[] = trim($buf);
                    return $args;
                }
                $depth--;
            }
            if ($ch === ',' && $depth === 0) {
                $args[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }

        return [];
    }

    /**
     * 白名单 = 「在解析结构串」的字节切片，逐条写清它在解析什么。
     * 只能缩短：新增一条要先回答「为什么不能按字符做」。
     *
     * 四类合法形状：
     *  - **二进制/摘要**：密文与 md5 串按字节偏移拆，字符语义根本不存在；
     *  - **tar 头**：POSIX 定长字段就是按字节定义的；
     *  - **剥前缀/后缀**：`u_`、`media:`、`uploads/`、`*.foo`、`v1.2.3` 的 `v` —— 前缀长度是常量；
     *  - ** delimiter 定位**：切到 `.` / `{` 之前，长度来自 strpos 而不是列宽。
     *
     * @var array<string, list<string>>
     */
    private const CLIP_PARSE_ALLOWLIST = [
        // 不透明 uid 拆 `u_` 前缀；密文按 12B magic + 16B IV + tag 拆，全是字节布局
        'app/auth/EncryptedJwtGuard.php' => ['$outerUid, 2', '$raw, 0, 12', '$raw, 12, 16', '$raw, 28'],
        'app/support/Crypto.php' => ['$raw, 0, 12', '$raw, 12, 16', '$raw, 28'],
        // 前缀常量长度：`media:` / `uploads/`
        'app/admin/controllers/FileController.php' => ['$tok, 6', '$rel, 8'],
        'app/support/StorageManager.php' => ['$ref, 6', '$path, 8', '$mediaPath, 8'],
        // tar 头是 POSIX 定长字段（名字 0..99、typeflag 156、checksum 148..）
        'app/support/SafeArchive.php' => ['$header, 0, 100', '$header, 345, 155', '$header, 257, 6', '$header, 124, 12', '$header, 156, 1'],
        // 已按白名单正则洗成纯 ASCII 之后再按字节钳，两种单位等价
        'app/admin/controllers/UploadController.php' => ['(string) preg_replace(\'/[^A-Za-z0-9_-]+/\', \'_\', $base), 0, 48'],
        'app/support/Shard.php' => [
            '(string) preg_replace(\'/[^A-Za-z0-9_]/\', \'\', $name), 0, 46',
            'md5($table . \'|\' . $name), 0, 10',
        ],
        'app/admin/controllers/TenantController.php' => ['md5($dbName), 0, 8'],
        // 摘要/hex 前缀
        'plugins/frontend/WorkflowBridgeController.php' => [
            '$part, 2',
            '$sig, 0, 100',
            '$hash, 0, 16',
            '$paramRef, 0, strpos($paramRef, \'.\')',
        ],
        'plugins/frontend/BuilderController.php' => ['(string) ($license[\'signature\'] ?? \'\'), 0, 16'],
        // 版本号与域名模式：都是 ASCII 形状串，切的是 `.x` / `*.` 这类标记
        'plugins/frontend/LicenseService.php' => ['$allowed, 1', '$domain, 0, -strlen($suffix)', '$pattern, 0, -2'],
        'plugins/frontend/GiteeReleaseController.php' => ['$dirName, 1'],
        'app/admin/controllers/SystemController.php' => ['PHP_VERSION, 0, 3'],
        // 配置键的 `plugin.` 分段、脚手架的类名/括号定位
        'app/support/PluginConfig.php' => ['$rest, 0, $pos'],
        'app/support/PluginScaffolder.php' => ['$value, 0, -strlen($suffix)', '$content, 0, $openBracket + 1'],
    ];

    /* ==================== helpers ==================== */

    /** 一个汉字 3 字节：3 的倍数个汉字，按字节切必然落在字中间。 */
    private static function cn(int $chars): string
    {
        return str_repeat('菜', $chars);
    }

    /** 登录日志用例的主题（带 TAG，删除时能精确认出本文件写的行）。 */
    private static function loginSubject(): string
    {
        return self::TAG . '-' . self::cn(30);
    }

    private function loginLogCount(string $username): int
    {
        return (int) (Db::select('SELECT COUNT(*) AS c FROM kode_login_logs WHERE username = ?', [$username])[0]['c'] ?? 0);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $route
     * @return array{status:int, body:array, data:mixed, why:string}
     */
    private function ask(object $controller, string $action, array $query, array $route = []): array
    {
        $request = (new ServerRequest('GET', '/api/any?' . http_build_query($query)))
            ->withQueryParams($query)
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => self::TAG, 'roles' => ['admin']]);
        if ($route !== []) {
            $request = $request->withAttribute('_route_params', $route);
        }
        HttpRequest::setRequest($request);

        return $this->envelope($controller, $action);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status:int, body:array, data:mixed, why:string}
     */
    private function submit(object $controller, string $action, array $body): array
    {
        $request = (new ServerRequest('POST', '/api/any'))
            ->withParsedBody($body)
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => self::TAG, 'roles' => ['admin']]);
        HttpRequest::setRequest($request);

        return $this->envelope($controller, $action);
    }

    /**
     * 调 handler 并把「抛出来」归一成 status=0 + 原因，断言因此是干净的失败而不是错误：
     * 本轮的缺陷形态恰好就是抛（22021 / JsonException），原因必须印在失败信息里才认得出。
     *
     * @return array{status:int, body:array, data:mixed, why:string}
     */
    private function envelope(object $controller, string $action): array
    {
        try {
            $response = $controller->{$action}();
        } catch (\Throwable $e) {
            return ['status' => 0, 'body' => [], 'data' => null, 'why' => get_class($e) . ': ' . $e->getMessage()];
        }
        self::assertInstanceOf(ResponseInterface::class, $response);

        $raw = (string) $response->getBody();
        $body = json_decode($raw, true);

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) $body,
            'data' => is_array($body) ? ($body['data'] ?? null) : null,
            'why' => $raw === '' ? '空响应' : (string) ($body['msg'] ?? mb_substr($raw, 0, 200)),
        ];
    }
}
