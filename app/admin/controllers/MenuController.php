<?php

declare(strict_types=1);

namespace app\admin\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;
use app\concerns\ApiResponse;
use app\concerns\PartialUpdate;
use app\support\Clip;
use app\support\TreeParent;

/**
 * 菜单聚合：DB 核心菜单 + 各插件声明的菜单（`menus()` 约定），供前端动态路由。
 *
 * PUT 是局部更新：可改列一律「缺键保留原值」，判据来自 `app\concerns\PartialUpdate`
 * （这里原本是一份局部闭包 `$taken`，与 `RoleController` 那份 `pick()` 语义相反，
 * 详见 `tests/PartialUpdateTest` 的类注释）。
 */
final class MenuController extends Controller
{
    use ApiResponse;
    use PartialUpdate;
    public function tree()
    {
        $items = \app\support\HotCache::remember('menus:tree', 300, function (): array {
            return Db::select('SELECT id, title, path, icon, plugin, sort, parent_id, auth_code, status, name, component, meta FROM kode_menus ORDER BY sort, id');
        });

        foreach (\app\support\PluginMenus::declared() as $menu) {
            $items[] = $menu;
        }

        $byEid = [];
        $parentOf = [];
        $synthetic = 0;
        foreach ($items as $row) {
            // 插件菜单无内部 id：分配负数合成 id，避免与库内 id/顶级 pid(0) 碰撞。
            $id = isset($row['id']) ? (int) $row['id'] : --$synthetic;
            $eid = $id > 0 ? \app\support\HashId::enc($id) : "plug_{$id}";
            $row['id'] = $eid;
            $row['auth_code'] = (string) ($row['auth_code'] ?? '');
            $row['name'] = (string) ($row['name'] ?? '');
            $row['component'] = (string) ($row['component'] ?? '');
            $row['meta'] = json_decode((string) ($row['meta'] ?? '{}'), true) ?: new \stdClass();
            $byEid[$eid] = $row;
            $parentOf[$id] = (int) ($row['parent_id'] ?? 0);
        }
        unset($row);
        $eidOf = [];
        foreach ($parentOf as $id => $pid) {
            $eidOf[$id] = $id > 0 ? \app\support\HashId::enc($id) : "plug_{$id}";
        }
        $grouped = [];
        foreach ($parentOf as $id => $pid) {
            $grouped[$pid][] = $id;
        }
        $walk = static function (int $pid, int $depth = 0) use (&$walk, $grouped, $byEid, $eidOf): array {
            if ($depth > 32) {
                return [];
            }
            $out = [];
            foreach ($grouped[$pid] ?? [] as $id) {
                $node = $byEid[$eidOf[$id]] ?? null;
                if ($node === null) {
                    continue;
                }
                // 保留 parent_id（转 eid，顶级为 null），供编辑表单预选上级。
                $node['parent_id'] = $pid > 0 ? $eidOf[$pid] : null;
                $children = $walk($id, $depth + 1);
                if ($children !== []) {
                    $node['children'] = $children;
                }
                $out[] = $node;
            }

            return $out;
        };
        $items = $walk(0);

        // 插件叶子归属：menus() 可声明 parent（目录 path），挂到对应目录下；
        // 无声明的插件叶子保持顶级（前端收进「扩展」）。
        $byPath = [];
        foreach ($items as $i => $node) {
            $byPath[(string) ($node['path'] ?? '')] = $i;
        }
        $drop = [];
        foreach ($items as $i => $node) {
            $parent = $node['parent'] ?? null;
            unset($items[$i]['parent']);
            if (!is_string($parent) || !isset($byPath[$parent])) {
                continue;
            }
            $child = $node;
            unset($child['parent']);
            if (!isset($items[$byPath[$parent]]['children'])) {
                $items[$byPath[$parent]]['children'] = [];
            }
            $items[$byPath[$parent]]['children'][] = $child;
            $drop[] = $i;
        }
        foreach ($drop as $i) {
            unset($items[$i]);
        }
        $items = array_values($items);

        // 管理端按需附带按钮（?buttons=1，前端菜单管理树用；侧边栏不用，避免臃肿）。
        if ((string) $this->query('buttons', '') === '1') {
            $btns = [];
            foreach (Db::select('SELECT id, menu_id, code, title, sort FROM kode_menu_buttons ORDER BY menu_id, sort, id') as $b) {
                $btns[(int) $b['menu_id']][] = [
                    'id' => \app\support\HashId::enc((int) $b['id']),
                    'code' => (string) $b['code'],
                    'title' => (string) $b['title'],
                    'sort' => (int) ($b['sort'] ?? 0),
                ];
            }
            $attach = static function (array $nodes) use (&$attach, $btns): array {
                foreach ($nodes as &$node) {
                    $inner = \app\support\HashId::dec((string) ($node['id'] ?? '')) ?? 0;
                    if ($inner > 0 && isset($btns[$inner])) {
                        $node['buttons'] = $btns[$inner];
                    }
                    if (isset($node['children'])) {
                        $node['children'] = $attach($node['children']);
                    }
                }
                unset($node);

                return $nodes;
            };
            $items = $attach($items);
        }

        // 按身份过滤：超管全量；其余仅保留「启用 + 空码（登录可见）+ 角色 perms 持有码」
        // （父无权但子有权时保留父作目录）。
        $payload = Request::attr('auth');
        $rp = \app\support\RolePerms::forUser((int) ($payload->uid ?? 0));
        if (!$rp['is_admin']) {
            $owned = array_flip($rp['perms']);
            $filter = static function (array $nodes) use (&$filter, $owned): array {
                $out = [];
                foreach ($nodes as $node) {
                    if ((int) ($node['status'] ?? 1) !== 1) {
                        continue;
                    }
                    $children = isset($node['children']) ? $filter($node['children']) : [];
                    $self = ($node['auth_code'] ?? '') === '' || isset($owned[$node['auth_code']]);
                    if ($self || $children !== []) {
                        if ($children !== []) {
                            $node['children'] = $children;
                        } else {
                            unset($node['children']);
                        }
                        $out[] = $node;
                    }
                }

                return $out;
            };
            $items = $filter($items);
        }

        return $this->ok($items);
    }

    public function store()
    {
        $data = $this->validate(Request::all(), [
            'title' => 'required|min:1|length:64',
            'path' => 'required|min:1|length:255',
            'icon' => 'nullable|length:64',
            'plugin' => 'nullable|length:64',
            'sort' => 'nullable|integer',
            'auth_code' => 'nullable|length:128',
            'parent_id' => 'nullable|string',
            'status' => 'nullable|integer',
            'name' => 'nullable|length:64',
            'component' => 'nullable|length:255',
            'meta' => 'nullable|array',
        ]);
        $resolved = TreeParent::resolve('kode_menus', $data['parent_id'] ?? null);
        $failure = TreeParent::failureFor($resolved, '菜单');
        if ($failure !== null) {
            return $this->fail($failure['msg'], $failure['status']);
        }
        $parentId = $resolved['parent_id'];
        $collision = $this->pathFailure((string) $data['path']);
        if ($collision !== null) {
            return $collision;
        }

        $id = Db::insert(
            'INSERT INTO kode_menus (title, path, icon, plugin, sort, parent_id, auth_code, status, name, component, meta) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['title'], $data['path'], $data['icon'] ?? '',
                $data['plugin'] ?? 'system', (int) ($data['sort'] ?? 0), $parentId,
                $this->authCode($data['auth_code'] ?? ''),
                array_key_exists('status', $data) ? ((int) $data['status'] ? 1 : 0) : 1,
                (string) ($data['name'] ?? ''),
                (string) ($data['component'] ?? ''),
                json_encode($this->meta($data['meta'] ?? [])),
            ]
        );
        \app\support\HotCache::forget('menus:tree');

        return $this->ok(['id' => \app\support\HashId::enc((int) $id)], 201);
    }

    public function destroy()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        Db::delete('DELETE FROM kode_menus WHERE id = ?', [(string) $id]);
        Db::delete('DELETE FROM kode_menu_buttons WHERE menu_id = ?', [(string) $id]);
        // 子菜单上移顶级（避免整枝消失）。
        Db::update('UPDATE kode_menus SET parent_id = 0 WHERE parent_id = ?', [$id]);
        \app\support\HotCache::forget('menus:tree');

        return $this->ok(['deleted' => \app\support\HashId::enc($id)]);
    }

    public function update()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        $data = $this->validate(Request::all(), [
            'title' => 'required|min:1|length:64',
            'path' => 'required|min:1|length:255',
            'icon' => 'nullable|length:64',
            'plugin' => 'nullable|length:64',
            'sort' => 'nullable|integer',
            'auth_code' => 'nullable|length:128',
            'parent_id' => 'nullable|string',
            'status' => 'nullable|integer',
            'name' => 'nullable|length:64',
            'component' => 'nullable|length:255',
            'meta' => 'nullable|array',
        ]);

        $rows = Db::select(
            'SELECT path, icon, plugin, sort, parent_id, auth_code, status, name, component, meta FROM kode_menus WHERE id = ? LIMIT 1',
            [$id]
        );
        if ($rows === []) {
            return $this->fail('不存在', 404);
        }
        // 撞车判定只管「这次写入占了新 path」：路径没动的编辑不参与，否则库里那条
        // 与插件声明撞车的历史行（见 PluginMenus 的注释）连改个标题都改不动了 ——
        // 它要么被删除、要么被改去别的路径，两种出口都要求判定只盯新路径。
        if ((string) $data['path'] !== (string) $rows[0]['path']) {
            $collision = $this->pathFailure((string) $data['path'], $id);
            if ($collision !== null) {
                return $collision;
            }
        }
        // 局部更新：可改字段缺键保留原值（列表页的状态开关只发 {status}，
        // 无条件 11 列覆盖会把兄弟列抹成默认值，并把禁用中的菜单静默重新启用）。
        $cur = $rows[0];
        $parentId = (int) ($cur['parent_id'] ?? 0);
        if (self::supplied($data, 'parent_id')) {
            $resolved = TreeParent::resolve('kode_menus', $data['parent_id'], $id);
            $failure = TreeParent::failureFor($resolved, '菜单');
            if ($failure !== null) {
                return $this->fail($failure['msg'], $failure['status']);
            }
            $parentId = $resolved['parent_id'];
        }
        Db::update(
            'UPDATE kode_menus SET title = ?, path = ?, icon = ?, plugin = ?, sort = ?, parent_id = ?, auth_code = ?, status = ?, name = ?, component = ?, meta = ? WHERE id = ?',
            [
                $data['title'], $data['path'],
                self::pick($data, 'icon', (string) ($cur['icon'] ?? ''), static fn(mixed $v): string => (string) $v),
                self::pick($data, 'plugin', (string) ($cur['plugin'] ?? 'system'), static fn(mixed $v): string => (string) $v),
                self::pick($data, 'sort', (int) ($cur['sort'] ?? 0), static fn(mixed $v): int => (int) $v),
                $parentId,
                self::pick($data, 'auth_code', (string) ($cur['auth_code'] ?? ''), fn(mixed $v): string => $this->authCode($v)),
                self::pick($data, 'status', (int) ($cur['status'] ?? 1), static fn(mixed $v): int => ((int) $v ? 1 : 0)),
                self::pick($data, 'name', (string) ($cur['name'] ?? ''), static fn(mixed $v): string => (string) $v),
                self::pick($data, 'component', (string) ($cur['component'] ?? ''), static fn(mixed $v): string => (string) $v),
                self::pick($data, 'meta', (string) ($cur['meta'] ?? '{}'), fn(mixed $v): string => (string) json_encode($this->meta($v))),
                $id,
            ]
        );
        \app\support\HotCache::forget('menus:tree');

        return $this->ok(['id' => \app\support\HashId::enc($id)]);
    }

    /**
     * path 撞车判定（新建与改路径共用）：撞了返回响应，没撞返回 null。
     *
     * 两种撞法：① 插件 `menus()` 声明过的 path —— 它不入库，读取时才和库内菜单合并，
     * 所以写侧此前完全看不见，建一条同 path 的记录就能让侧边栏出两个同名条目，
     * 而库里那条和插件那条谁都改不动谁；② 库内别的菜单已用同 path（同样是两条）。
     */
    private function pathFailure(string $path, ?int $selfId = null)
    {
        $plugin = \app\support\PluginMenus::paths()[$path] ?? null;
        if ($plugin !== null) {
            return $this->fail("路径 {$path} 属于 {$plugin} 插件声明的菜单，不能再建为独立菜单", 409);
        }

        $sql = 'SELECT id FROM kode_menus WHERE path = ?';
        $bind = [$path];
        if ($selfId !== null) {
            $sql .= ' AND id <> ?';
            $bind[] = $selfId;
        }
        if (Db::select($sql . ' LIMIT 1', $bind) !== []) {
            return $this->fail('菜单路径已存在', 409);
        }

        return null;
    }

    /** 路由元信息白名单（徽标/显隐/缓存/钉死/内嵌/外联，其余丢弃）。 */
    private function meta(mixed $value): array
    {
        $allow = [
            'keepAlive', 'affixTab', 'hideInMenu', 'hideChildrenInMenu',
            'hideInBreadcrumb', 'hideInTab',
            'badge', 'badgeType', 'badgeVariants',
            'iframeSrc', 'link', 'activeIcon',
        ];
        $out = [];
        foreach ((array) $value as $k => $v) {
            if (!in_array($k, $allow, true)) {
                continue;
            }
            // meta 各值限长是**体积**守卫（这列是 JSON 文本，没有列宽要防），
            // 但单位必须是字符：按字节切会把中文徽标文案切成非法 UTF-8，
            // 而这份数组马上要 json_encode 落库并在读取时再 encode 一次。
            $out[$k] = is_bool($v) ? $v : Clip::chars((string) $v, 255);
        }

        return $out;
    }

    /** 权限码归一化（小写+点冒分段，仅允许 [a-z0-9:._-]，空=登录可见）。 */
    private function authCode(mixed $value): string
    {
        $code = strtolower(trim((string) $value));
        if ($code === '') {
            return '';
        }

        return preg_match('/^[a-z0-9:._-]{2,128}$/', $code) === 1 ? $code : '';
    }
}
