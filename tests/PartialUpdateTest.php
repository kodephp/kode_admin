<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\DeptController;
use app\admin\controllers\DictController;
use app\admin\controllers\MenuButtonController;
use app\tests\Support\SourceScan;
use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * 「PUT 缺键保留原值」这条契约在核心控制器上的落地（AGENTS.md §4）。
 *
 * #130 已经给 `kode_menus` 修过一遍，但同一段代码里的判据当时只写在了
 * `MenuController` 自己身上（一个局部闭包 `$taken`），而 `RoleController` 又各写了
 * 一份 `private static pick()` —— 两份实现的语义还不一样：`$taken` 认「键在不在」，
 * `pick` 认「键在且不为 null」。于是 `auth_code: null` 在菜单那条线上是**清空权限码**
 * （菜单当场变成「登录可见」），在角色那条线上才是「没改」。这正是「判据只写一份」
 * 要挡的分岔，所以这里连同「不许再有第三份实现」一起钉住。
 *
 * 实测后果最直白的是部门：管理端那个编辑框只发 `{name, status, remark}`（`sort` 根本
 * 不在表单里，`pid` 还被前端 `void` 掉了），而 `update()` 无条件把 `sort` 写成
 * `(int) ($data['sort'] ?? 0)` —— 于是**每改一次部门名，全站部门排序归零一次**，
 * 而列表正是按 `ORDER BY sort, id` 排的。页面上没有任何地方显示过 sort，
 * 所以没人知道自己抹掉了什么。
 */
final class PartialUpdateTest extends TestCase
{
    protected bool $independentApp = true;

    private const PROBE = 'zz-pu-probe';

    /** @var list<string> 本用例删得掉的清理语句（精确按 id / 前缀） */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $sql) {
            Db::delete($sql);
        }
        HttpRequest::clear();

        parent::tearDown();
    }

    /* ==================== 1. 部门：sort 是真实行为 ==================== */

    public function test_dept_put_without_sort_keeps_the_stored_sort(): void
    {
        $id = $this->seedDept(['sort' => 7]);

        // 形状照抄管理端那个编辑框：只有 name/status/remark 三个键。
        $result = $this->sendPut(DeptController::class, 'update', $id, [
            'name' => self::PROBE . '-renamed',
            'status' => 1,
            'remark' => '改个名',
        ]);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(
            7,
            (int) (Db::select('SELECT sort FROM kode_depts WHERE id = ?', [$id])[0]['sort'] ?? -1),
            '缺键的 sort 被写成了 0：改一次名字就把这一行的排序抹一次'
        );
        self::assertSame(
            self::PROBE . '-renamed',
            (string) (Db::select('SELECT name FROM kode_depts WHERE id = ?', [$id])[0]['name'] ?? ''),
            '发了的 name 反而没落'
        );
    }

    /** 「保留原值」不等于「改不动」：显式发了 sort（含显式 0）就必须落。 */
    public function test_dept_put_with_explicit_sort_still_writes_it(): void
    {
        $id = $this->seedDept(['sort' => 7]);

        $result = $this->sendPut(DeptController::class, 'update', $id, [
            'name' => self::PROBE,
            'sort' => 3,
        ]);
        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(3, (int) (Db::select('SELECT sort FROM kode_depts WHERE id = ?', [$id])[0]['sort'] ?? -1));

        $zero = $this->sendPut(DeptController::class, 'update', $id, [
            'name' => self::PROBE,
            'sort' => 0,
        ]);
        self::assertSame(200, $zero['status'], json_encode($zero['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(
            0,
            (int) (Db::select('SELECT sort FROM kode_depts WHERE id = ?', [$id])[0]['sort'] ?? -1),
            '显式传 0 被当成缺键了：那这一列就永远改不回 0'
        );
    }

    /* ==================== 2. 部门：改上级是表单上真实存在的字段 ==================== */

    /**
     * 表单里那个「上级部门」在 store 是生效的、在 update 完全没人读：
     * `update()` 的 SQL 里没有 parent_id，而前端 api.ts 里写着 `void pid`（把值丢掉以免
     * 白传）。于是编辑部门时那个选择框既回填不了（tree() 把 parent_id unset 了）、
     * 改了也不落地，还回 200。这里把两侧一起接上。
     */
    public function test_dept_put_can_move_a_dept_under_another(): void
    {
        $from = $this->seedDept();
        $to = $this->seedDept();
        // 上级必须换一个**别的**部门：原地不动的话「parent_id 没被读」也能把断言跑绿。
        $child = $this->seedDept(['parent' => $from]);

        $result = $this->sendPut(DeptController::class, 'update', $child, [
            'name' => self::PROBE . '-moved',
            'parent_id' => \app\support\HashId::enc($to),
        ]);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(
            $to,
            (int) (Db::select('SELECT parent_id FROM kode_depts WHERE id = ?', [$child])[0]['parent_id'] ?? -1),
            '发了 parent_id 却没挪：那个选择框是个摆设'
        );
        // 两个新上级必须是不同的部门，否则上面那条断言「同值也绿」，正好让「parent_id 没被读」蒙过去。
        self::assertNotSame($from, $to, '本次种的两个部门 id 相同：挪动断言失去比对对象');
    }

    /** 挪到自己或自己的子孙下面 = 成环，树形列表会当场消失（walk 只从 parent_id=0 出发）。 */
    public function test_a_dept_cannot_be_moved_into_itself_or_its_own_descendant(): void
    {
        $root = $this->seedDept();
        $child = $this->seedDept(['parent' => $root]);
        $grand = $this->seedDept(['parent' => $child]);

        $self = $this->sendPut(DeptController::class, 'update', $root, [
            'name' => self::PROBE,
            'parent_id' => \app\support\HashId::enc($root),
        ]);
        self::assertSame(400, $self['status'], json_encode($self['body'], JSON_UNESCAPED_UNICODE));

        $cycle = $this->sendPut(DeptController::class, 'update', $root, [
            'name' => self::PROBE,
            'parent_id' => \app\support\HashId::enc($grand),
        ]);
        self::assertSame(400, $cycle['status'], json_encode($cycle['body'], JSON_UNESCAPED_UNICODE));

        foreach ([$root => 0, $child => $root, $grand => $child] as $id => $want) {
            self::assertSame(
                $want,
                (int) (Db::select('SELECT parent_id FROM kode_depts WHERE id = ?', [$id])[0]['parent_id'] ?? -1),
                "被拒的 PUT 仍然挪动了 #{$id}"
            );
        }
    }

    /** 被拒的上级（不存在 / 非法串）不许当成「挪到顶级」写进去。 */
    public function test_an_unknown_parent_is_refused_instead_of_flattening_the_dept(): void
    {
        $id = $this->seedDept(['parent' => $this->seedDept()]);
        $before = (int) (Db::select('SELECT parent_id FROM kode_depts WHERE id = ?', [$id])[0]['parent_id'] ?? 0);

        $bogus = $this->sendPut(DeptController::class, 'update', $id, [
            'name' => self::PROBE,
            'parent_id' => 'not-a-hash-id',
        ]);
        self::assertSame(404, $bogus['status'], json_encode($bogus['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(
            $before,
            (int) (Db::select('SELECT parent_id FROM kode_depts WHERE id = ?', [$id])[0]['parent_id'] ?? -1),
            '非法的 parent_id 被拍平成顶级了'
        );

        $gone = $this->sendPut(DeptController::class, 'update', $id, [
            'name' => self::PROBE,
            'parent_id' => \app\support\HashId::enc(999999),
        ]);
        self::assertSame(404, $gone['status'], json_encode($gone['body'], JSON_UNESCAPED_UNICODE));
    }

    /** 显式挪回顶级必须是可表达的：`parent_id: 0`（表单里清空那个选择框就是这个值）。 */
    public function test_a_dept_can_be_moved_back_to_top_level(): void
    {
        $root = $this->seedDept();
        $child = $this->seedDept(['parent' => $root]);

        $result = $this->sendPut(DeptController::class, 'update', $child, [
            'name' => self::PROBE,
            'parent_id' => 0,
        ]);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(
            0,
            (int) (Db::select('SELECT parent_id FROM kode_depts WHERE id = ?', [$child])[0]['parent_id'] ?? -1),
            '「挪回顶级」这个动作没有入口'
        );
    }

    /**
     * 编辑框回填要读得到上级，而 `tree()` 原先 `unset($row['parent_id'])`，
     * 且对外 id 是 HashId 串 —— 上级也得是同一个形状，否则 ApiTreeSelect 的
     * valueField 永远匹配不上，编辑时那个框恒空。
     */
    public function test_the_dept_tree_exposes_an_encodable_parent_id(): void
    {
        $root = $this->seedDept();
        $child = $this->seedDept(['parent' => $root]);

        $response = (new DeptController())->tree();
        self::assertInstanceOf(ResponseInterface::class, $response);
        $body = (array) json_decode((string) $response->getBody(), true);

        $flat = [];
        $walk = static function (array $nodes) use (&$walk, &$flat): void {
            foreach ($nodes as $node) {
                $flat[(string) ($node['id'] ?? '')] = $node;
                $walk((array) ($node['children'] ?? []));
            }
        };
        $walk((array) ($body['data'] ?? []));

        $encRoot = \app\support\HashId::enc($root);
        self::assertArrayHasKey($encRoot, $flat, '部门树读不到本次种的行');
        self::assertSame(
            $encRoot,
            (string) ($flat[\app\support\HashId::enc($child)]['parent_id'] ?? ''),
            '上级 id 不是可回填的对外形状（缺键、或是裸整数都对不上 ApiTreeSelect）'
        );
    }

    /* ==================== 3. 字典 / 按钮：同一份判据 ==================== */

    public function test_dict_type_put_without_remark_keeps_the_stored_remark(): void
    {
        $id = $this->seedDictType();

        $result = $this->sendPut(DictController::class, 'updateType', $id, ['name' => self::PROBE . '-改名']);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(
            '原有备注',
            (string) (Db::select('SELECT remark FROM kode_dict_types WHERE id = ?', [$id])[0]['remark'] ?? ''),
            '缺键的 remark 被抹成空串了'
        );
    }

    public function test_dict_item_put_without_sort_and_remark_keeps_both(): void
    {
        $type = $this->seedDictType();
        $id = $this->seedDictItem((string) Db::select('SELECT code FROM kode_dict_types WHERE id = ?', [$type])[0]['code']);

        $result = $this->sendPut(DictController::class, 'updateItem', $id, [
            'label' => self::PROBE . '-标签',
            'value' => 'v2',
        ]);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $row = (array) (Db::select('SELECT sort, remark FROM kode_dict_data WHERE id = ?', [$id])[0] ?? []);
        self::assertSame('9', (string) ($row['sort'] ?? ''), '缺键的 sort 被写成 0');
        self::assertSame('原有项备注', (string) ($row['remark'] ?? ''), '缺键的 remark 被抹成空串');
    }

    public function test_menu_button_put_without_sort_keeps_the_stored_sort(): void
    {
        $menu = $this->seedMenu();
        $id = $this->seedMenuButton($menu);

        $result = $this->sendPut(MenuButtonController::class, 'update', $id, ['title' => self::PROBE . '-按钮改名']);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(
            5,
            (int) (Db::select('SELECT sort FROM kode_menu_buttons WHERE id = ?', [$id])[0]['sort'] ?? -1),
            '缺键的 sort 被写成 0：按钮顺序又回到默认'
        );
    }

    /* ==================== 4. 判据只有一份 ==================== */

    /**
     * 静态门禁：update 类方法里，`$data['k'] ?? 零值` 直接进 UPDATE 绑定 = 缺键被抹成默认值。
     *
     * 判定前先剥掉「合法形态」：`self::pick(...)` / `array_key_exists(...)` 这些子表达式按
     * 括号配对整段删掉，剩下的才是「没有判据兜着的零值回落」。豁免清单见
     * `ZERO_DEFAULT_ALLOWLIST`，且每一项必须仍然命中（否则就是僵尸登记）。
     */
    public function test_no_update_handler_defaults_a_missing_key_to_zero(): void
    {
        $findings = $this->zeroDefaultFindings();

        $offenders = [];
        foreach ($findings as $file => $columns) {
            $allowed = self::ZERO_DEFAULT_ALLOWLIST[$file] ?? [];
            $rest = array_values(array_diff($columns, $allowed));
            if ($rest !== []) {
                $offenders[$file] = $rest;
            }
        }

        self::assertSame(
            [],
            $offenders,
            '这些地方把「请求没带这个键」写成了「把这个列清零」：' . json_encode($offenders, JSON_UNESCAPED_UNICODE)
        );

        // 反空转：豁免清单里的每一项必须仍然命中（僵尸登记要当场变红）。
        foreach (self::ZERO_DEFAULT_ALLOWLIST as $file => $columns) {
            self::assertArrayHasKey($file, $findings, "豁免清单里的 {$file} 已经没有命中项了：清单该删，或删除逻辑失效了");
            self::assertSame(
                $columns,
                array_values(array_intersect($findings[$file], $columns)),
                "豁免清单与 {$file} 的实际命中不一致（僵尸豁免或多出未登记的回零点）"
            );
        }
    }

    /** 正对照：修复前的那一行必须被判定抓出来，否则「门禁绿」只说明它什么都看不见。 */
    public function test_the_gate_flags_the_pre_fix_shape(): void
    {
        $before = <<<'PHP'
        Db::update(
            'UPDATE kode_depts SET name = ?, sort = ? WHERE id = ?',
            [$data['name'], (int) ($data['sort'] ?? 0), $id]
        );
        PHP;

        self::assertSame(['sort'], $this->zeroDefaultsIn($before));
    }

    /** 反例：有判据兜着的写法不得报（报了就没人肯留注释，只会把门禁调松）。 */
    public function test_the_gate_accepts_the_guarded_shapes(): void
    {
        $guarded = <<<'PHP'
        $name = self::pick($data, 'name', (string) $row['name'], static fn(mixed $v): string => (string) $v);
        $sort = self::pick($data, 'sort', (int) $row['sort'], static fn(mixed $v): int => (int) $v);
        Db::update(
            'UPDATE kode_depts SET name = ?, sort = ?, status = ? WHERE id = ?',
            [$name, $sort, array_key_exists('status', $data) ? (int) $data['status'] : (int) ($row['status'] ?? 1), $id]
        );
        PHP;

        self::assertSame([], $this->zeroDefaultsIn($guarded));
    }

    /**
     * 第三份实现不许出现：`pick` 判据只能来自 `app\concerns\PartialUpdate`。
     *
     * 两条线各自实现时的语义差就是缺陷本身（null 在一边是「没发」、在另一边是「清空」），
     * 所以这里不只禁止再写 `private static function pick`，也禁止再写局部闭包 `$taken =`。
     */
    public function test_the_judge_has_exactly_one_implementation(): void
    {
        $local = [];
        foreach ($this->controllerFiles() as $file) {
            $src = SourceScan::stripComments(file_get_contents($file) ?: '');
            $hasUpdate = $this->updateMethods($src) !== [];
            if (!$hasUpdate) {
                continue;
            }
            if (preg_match('/function\s+pick\s*\(/', $src) === 1 || preg_match('/\$taken\s*=\s*static\s+function/', $src) === 1) {
                $local[] = str_replace(dirname(__DIR__) . '/', '', $file);
            }
        }

        self::assertSame([], $local, '又写了一份局部更新判据：' . implode(', ', $local));

        // 判据本身得真的有人用（否则这条门禁只是给一个孤儿类看门）。
        $users = 0;
        foreach ($this->controllerFiles() as $file) {
            $src = file_get_contents($file) ?: '';
            if (strpos($src, 'use app\\concerns\\PartialUpdate;') !== false) {
                ++$users;
            }
        }
        self::assertGreaterThanOrEqual(5, $users, 'PartialUpdate 没有被至少 5 个控制器 use：判据又散回去了');
    }

    /* ==================== 工具 ==================== */

    /** 已知豁免：整表单 PUT（其余键全 required），缺键先 422，不存在「只改一列」的 PUT。 */
    private const ZERO_DEFAULT_ALLOWLIST = [
        'app/admin/controllers/ShardController.php' => ['db_hosts'],
    ];

    /** @return array<string, list<string>> 相对路径 => 命中的列名 */
    private function zeroDefaultFindings(): array
    {
        $out = [];
        $methods = 0;
        foreach ($this->controllerFiles() as $file) {
            $rel = str_replace(dirname(__DIR__) . '/', '', $file);
            $columns = [];
            foreach ($this->updateMethods(file_get_contents($file) ?: '') as $body) {
                ++$methods;
                foreach ($this->unguardedZeroDefaults($body) as $col) {
                    $columns[] = $col;
                }
            }
            if ($columns !== []) {
                $out[$rel] = array_values(array_unique($columns));
            }
        }
        // 反空转：提取必须真的跑在足够多的 update 方法上，否则「零命中」是扫描器死了。
        self::assertGreaterThanOrEqual(10, $methods, '只提取到 ' . $methods . ' 个 update 类方法：口径变了');

        return $out;
    }

    /**
     * 把一段代码片段按「update 方法」跑一遍完整判定（提取 → 挖判据 → 找零值回落）。
     *
     * 两条对照都用它，所以它们验的是同一条管道而不是各自手写一遍正则。
     *
     * @return list<string>
     */
    private function zeroDefaultsIn(string $snippet): array
    {
        $methods = $this->updateMethods(
            "<?php\nfinal class Probe\n{\n    public function update()\n    {\n" . $snippet . "\n    }\n}\n"
        );
        self::assertCount(1, $methods, '片段没被当成 update 方法提取出来：判定管道在片段上是死的');

        $cols = [];
        foreach ($methods as $body) {
            foreach ($this->unguardedZeroDefaults($body) as $col) {
                $cols[] = $col;
            }
        }

        return array_values(array_unique($cols));
    }

    /**
     * 「没人兜着的零值回落」= 请求没带这个键时，代码把这一列写成 0 或空串。
     *
     * @return list<string>
     */
    private function unguardedZeroDefaults(string $body): array
    {
        $cols = [];
        preg_match_all(
            "/\\\$data\\[\\s*['\"]([a-z_]+)['\"]\\s*\\]\\s*\\?\\?\\s*(?:0|'')/",
            $body,
            $hits
        );
        foreach ($hits[1] as $col) {
            if (!in_array($col, $cols, true)) {
                $cols[] = $col;
            }
        }

        return $cols;
    }

    /** @return list<string> 控制器文件绝对路径（核心两个子应用 + 插件） */
    private function controllerFiles(): array
    {
        $root = dirname(__DIR__);
        $files = [];
        foreach (['app/admin/controllers', 'app/api/controllers', 'app/controllers', 'plugins'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile() && strtolower((string) $f->getExtension()) === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }
        self::assertGreaterThanOrEqual(40, count($files), '扫描面只有 ' . count($files) . ' 个文件：目录口径变了');

        return $files;
    }

    /**
     * 只保留 update/save/edit 类方法体，并把「合法判据」整段挖掉。
     *
     * 挖法是括号配平地删掉 `pick(` / `array_key_exists(` 这几个调用本身，剩下的
     * `$data['x'] ?? 0` 才是真的没人兜着。
     *
     * @return array<string, string> 方法名 => 已挖掉判据的方法体
     */
    private function updateMethods(string $src): array
    {
        $out = [];
        foreach (SourceScan::publicMethodBodies($src, '/^(?:update|save|edit)\w*$/i') as $name => $body) {
            $out[$name] = SourceScan::dropCalls($body, [
                'self::pick(',
                'static::pick(',
                '$this->pick(',
                'array_key_exists(',
                '$taken(',
            ]);
        }

        return $out;
    }

    private function seedDept(array $over = []): int
    {
        $row = array_merge(['name' => self::PROBE, 'parent' => 0, 'sort' => 0, 'status' => 1, 'remark' => ''], $over);
        $id = (int) Db::insert(
            'INSERT INTO kode_depts (name, parent_id, sort, status, remark, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [$row['name'] . '-' . $row['sort'] . '-' . $row['parent'], (int) $row['parent'], (int) $row['sort'], (int) $row['status'], (string) $row['remark']]
        );
        $this->track('DELETE FROM kode_depts WHERE id = ' . $id);

        return $id;
    }

    private function seedDictType(): int
    {
        $code = self::PROBE . '-type-' . substr(md5((string) mt_rand()), 0, 6);
        $id = (int) Db::insert(
            'INSERT INTO kode_dict_types (name, code, remark, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())',
            [self::PROBE, $code, '原有备注']
        );
        $this->track('DELETE FROM kode_dict_types WHERE id = ' . $id);
        $this->track('DELETE FROM kode_dict_data WHERE type_code = ' . $this->quote($code));

        return $id;
    }

    private function seedDictItem(string $typeCode): int
    {
        $id = (int) Db::insert(
            'INSERT INTO kode_dict_data (type_code, label, value, sort, remark, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [$typeCode, self::PROBE . '-标签', 'v1', 9, '原有项备注']
        );
        $this->track('DELETE FROM kode_dict_data WHERE id = ' . $id);

        return $id;
    }

    private function seedMenu(): int
    {
        $id = (int) Db::insert(
            'INSERT INTO kode_menus (title, path, icon, plugin, sort, parent_id, auth_code, status, name, component, meta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [self::PROBE, '/' . self::PROBE . '-' . substr(md5((string) mt_rand()), 0, 6), '', 'system', 0, 0, '', 1, '', '', '{}']
        );
        $this->track('DELETE FROM kode_menus WHERE id = ' . $id);
        $this->track('DELETE FROM kode_menu_buttons WHERE menu_id = ' . $id);
        \app\support\HotCache::forget('menus:tree');

        return $id;
    }

    private function seedMenuButton(int $menuId): int
    {
        $code = 'zz-pu-probe:edit-' . substr(md5((string) mt_rand()), 0, 6);
        $id = (int) Db::insert(
            'INSERT INTO kode_menu_buttons (menu_id, code, title, sort, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
            [$menuId, $code, self::PROBE . '-按钮', 5]
        );
        $this->track('DELETE FROM kode_menu_buttons WHERE id = ' . $id);

        return $id;
    }

    private function quote(string $v): string
    {
        return "'" . str_replace("'", "''", $v) . "'";
    }

    private function track(string $sql): void
    {
        $this->cleanup[] = $sql;
    }

    /**
     * 直连控制器（中间件在测试外面，本文件测的是写侧语义而不是门禁）。
     *
     * @param array<string, mixed> $body
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function sendPut(string $controller, string $handler, int $id, array $body): array
    {
        $request = (new ServerRequest('PUT', '/api/probe/' . $id))
            ->withParsedBody($body)
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => self::PROBE, 'roles' => ['admin']])
            ->withAttribute('_route_params', ['id' => \app\support\HashId::enc($id)]);
        HttpRequest::setRequest($request);

        $response = (new $controller())->{$handler}();
        self::assertInstanceOf(ResponseInterface::class, $response);

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) json_decode((string) $response->getBody(), true),
        ];
    }
}
