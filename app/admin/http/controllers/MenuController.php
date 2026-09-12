<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;
use app\http\concerns\ApiResponse;
use Kode\Framework\Plugin\PluginManager;

/**
 * 菜单聚合：DB 核心菜单 + 各插件声明的菜单（`menus()` 约定），供前端动态路由。
 */
final class MenuController extends Controller
{
    use ApiResponse;
    public function tree()
    {
        $items = \app\support\HotCache::remember('menus:tree', 300, function (): array {
            return Db::select('SELECT id, title, path, icon, plugin, sort, parent_id, auth_code, status, name, component, meta FROM kode_menus ORDER BY sort, id');
        });

        try {
            /** @var PluginManager $manager */
            $manager = resolve(PluginManager::class);
            foreach ($manager->all() as $plugin) {
                if (method_exists($plugin, 'menus')) {
                    foreach ((array) $plugin->menus() as $menu) {
                        $items[] = $menu + ['plugin' => $plugin->name(), 'auth_code' => (string) ($menu['auth_code'] ?? ''), 'parent_id' => 0];
                    }
                }
            }
        } catch (\Throwable) {
            // 插件系统未就绪时仅返回核心菜单。
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
            'title' => 'required|min:1|max:64',
            'path' => 'required|min:1|max:255',
            'icon' => 'nullable|max:64',
            'plugin' => 'nullable|max:64',
            'sort' => 'nullable|integer',
            'auth_code' => 'nullable|max:128',
            'parent_id' => 'nullable|string',
            'status' => 'nullable|integer',
            'name' => 'nullable|max:64',
            'component' => 'nullable|max:255',
            'meta' => 'nullable|array',
        ]);
        $parentId = $this->resolveParent($data['parent_id'] ?? null);
        if ($parentId === null) {
            return $this->fail('上级菜单不存在', 404);
        }

        $id = Db::insert(
            'INSERT INTO kode_menus (title, path, icon, plugin, sort, parent_id, auth_code, status, name, component, meta) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['title'], $data['path'], $data['icon'] ?? '',
                $data['plugin'] ?? 'system', (int) ($data['sort'] ?? 0), $parentId,
                $this->authCode($data['auth_code'] ?? ''),
                array_key_exists('status', $data) ? ((int) $data['status'] ? 1 : 0) : 1,
                substr((string) ($data['name'] ?? ''), 0, 64),
                substr((string) ($data['component'] ?? ''), 0, 255),
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

    /** 上级菜单 eid → 内部 id（空=顶级 0；非法/插件菜单返回 null）。 */
    private function resolveParent(mixed $eid): ?int
    {
        if ($eid === null || $eid === '') {
            return 0;
        }
        $id = \app\support\HashId::dec((string) $eid);
        if ($id === null) {
            return null;
        }
        $exists = Db::select('SELECT id FROM kode_menus WHERE id = ? LIMIT 1', [$id]);

        return $exists === [] ? null : $id;
    }

    /** 环检测：$pid 是否 $id 的后代（是则挂接成环，拒绝）。 */
    private function isDescendant(int $pid, int $id): bool
    {
        $seen = 0;
        while ($pid > 0 && $seen++ < 100) {
            if ($pid === $id) {
                return true;
            }
            $row = Db::select('SELECT parent_id FROM kode_menus WHERE id = ? LIMIT 1', [$pid]);
            if ($row === []) {
                return false;
            }
            $pid = (int) ($row[0]['parent_id'] ?? 0);
        }

        return $pid > 0;
    }

    public function update()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        $data = $this->validate(Request::all(), [
            'title' => 'required|min:1|max:64',
            'path' => 'required|min:1|max:255',
            'icon' => 'nullable|max:64',
            'plugin' => 'nullable|max:64',
            'sort' => 'nullable|integer',
            'auth_code' => 'nullable|max:128',
            'parent_id' => 'nullable|string',
        ]);

        $exists = Db::select('SELECT id FROM kode_menus WHERE id = ? LIMIT 1', [$id]);
        if ($exists === []) {
            return $this->fail('不存在', 404);
        }
        $parentId = $this->resolveParent($data['parent_id'] ?? null);
        if ($parentId === null || $parentId === $id || $this->isDescendant($parentId, $id)) {
            return $this->fail('上级菜单不存在', 404);
        }
        Db::update(
            'UPDATE kode_menus SET title = ?, path = ?, icon = ?, plugin = ?, sort = ?, parent_id = ?, auth_code = ?, status = ?, name = ?, component = ?, meta = ? WHERE id = ?',
            [
                $data['title'], $data['path'], $data['icon'] ?? '',
                $data['plugin'] ?? 'system', (int) ($data['sort'] ?? 0), $parentId,
                $this->authCode($data['auth_code'] ?? ''),
                array_key_exists('status', $data) ? ((int) $data['status'] ? 1 : 0) : 1,
                substr((string) ($data['name'] ?? ''), 0, 64),
                substr((string) ($data['component'] ?? ''), 0, 255),
                json_encode($this->meta($data['meta'] ?? [])), $id,
            ]
        );
        \app\support\HotCache::forget('menus:tree');

        return $this->ok(['id' => \app\support\HashId::enc($id)]);
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
            $out[$k] = is_bool($v) ? $v : substr((string) $v, 0, 255);
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
