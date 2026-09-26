<?php

declare(strict_types=1);

namespace app\admin\controllers;

use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Framework\Tenant\Storage\StaticTenantStorageResolver;
use Kode\Http\Request;
use app\concerns\ApiResponse;
use app\concerns\PageWindow;
use app\support\Clip;

/**
 * 租户管理：CRUD + 凭证查看。
 *
 * 租户表：kode_tenants（id, name, status, db_name, created_at, updated_at）
 *
 * 对外身份：开启存储隔离（database/schema 策略）时，请求带的租户标识必须是本表的 name
 * （HeaderTenantResolver 取 X-Tenant-Id / SubdomainTenantResolver 取子域第一段），
 * 内置解析器按 prefix + sanitize(name) 派生库名，与本文件自动生成的 db_name 同源；
 * 显式传 db_name 只是给 map / 自定义解析器留的口子，此时运行时靠映射命中，不再靠拼接。
 */
final class TenantController extends Controller
{
    use ApiResponse;
    use PageWindow;

    public function index()
    {
        $win = $this->pageWindow($this->query('page'), $this->query('size'));
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];
        $status = Clip::chars(trim((string) $this->query('status', '')), 16);
        $keyword = Clip::chars(trim((string) $this->query('keyword', '')), 64);
        $start = Clip::chars(trim((string) $this->query('start_time', '')), 19);
        $end = Clip::chars(trim((string) $this->query('end_time', '')), 19);

        $where = [];
        $params = [];
        if (in_array($status, ['active', 'inactive', 'suspended'], true)) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($keyword !== '') {
            $where[] = '(name LIKE ? OR db_name LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }
        if ($start !== '' && strtotime($start) !== false) {
            $where[] = 'created_at >= ?';
            $params[] = date('Y-m-d H:i:s', strtotime($start));
        }
        if ($end !== '' && strtotime($end) !== false) {
            $where[] = 'created_at <= ?';
            $params[] = date('Y-m-d H:i:s', strtotime($end));
        }
        $cond = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = (int) (Db::select("SELECT COUNT(*) AS c FROM kode_tenants {$cond}", $params)[0]['c'] ?? 0);
        $params[] = $size;
        $params[] = $offset;
        $rows = Db::select(
            "SELECT id, name, status, db_name, created_at, updated_at
             FROM kode_tenants {$cond} ORDER BY created_at ASC LIMIT ? OFFSET ?",
            $params
        );
        foreach ($rows as &$row) {
            foreach ($row as $k => $v) {
                if ($v instanceof \DateTimeInterface) {
                    $row[$k] = $v->format('Y-m-d H:i:s');
                }
            }
        }
        unset($row);

        return $this->page($rows, $total, $page, $size);
    }

    public function store()
    {
        $data = $this->validate(Request::all(), [
            'name' => 'required|min:2|max:64',
            'db_name' => 'nullable|max:128',
        ]);

        $name = (string) $data['name'];
        if (!preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $name)) {
            return $this->fail('租户名非法（仅字母数字下划线短横线）', 400);
        }

        // 检查唯一性
        $exists = Db::select('SELECT id FROM kode_tenants WHERE name = ? LIMIT 1', [$name]);
        if ($exists !== []) {
            return $this->fail('租户名已存在', 400);
        }

        $dbPrefix = (string) config('tenant.storage.prefix', 'tnt_');
        $dbName = (string) ($data['db_name'] ?? '');
        if ($dbName === '') {
            // 必须与运行时解析口径一致：内置解析器（database/schema 策略）派生的库名是
            // prefix + StaticTenantStorageResolver::sanitize(租户标识)，即 name 里的 '-' 会被换成 '_'。
            // 这里若直拼原名，建出来的库永远命中不上，请求会静默回落到默认连接（隔离失效）。
            $dbName = $dbPrefix . StaticTenantStorageResolver::sanitize($name);
        }
        // db_name 会被插值进 CREATE DATABASE DDL（PG 标识符无法绑定参数），必须白名单
        if (!preg_match('/^[A-Za-z0-9_\-]{2,63}$/', $dbName)) {
            return $this->fail('db_name 非法（仅字母数字下划线短横线，2-63）', 400);
        }

        $id = bin2hex(random_bytes(8));
        Db::insert(
            'INSERT INTO kode_tenants (id, name, status, db_name, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
            [$id, $name, 'active', $dbName]
        );

        // 如果是 database 隔离策略，自动创建租户数据库
        $dbCreated = false;
        if (in_array((string) config('tenant.storage.strategy', 'shared'), ['database', 'schema'], true)) {
            $dbCreated = $this->createTenantDatabase($dbName);
        }

        return $this->ok([
            'id' => $id,
            'name' => $name,
            'db_name' => $dbName,
            'db_created' => $dbCreated,
        ]);
    }

    public function update()
    {
        $id = (string) $this->param('id');
        $data = $this->validate(Request::all(), [
            'name' => 'nullable|min:2|max:64',
            'status' => 'nullable|in:active,inactive,suspended',
            'db_name' => 'nullable|max:128',
        ]);

        $exists = Db::select('SELECT id FROM kode_tenants WHERE id = ? LIMIT 1', [$id]);
        if ($exists === []) {
            return $this->fail('租户不存在', 404);
        }

        $set = [];
        $params = [];
        if (isset($data['name'])) {
            $name = (string) $data['name'];
            if (!preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $name)) {
                return $this->fail('租户名非法', 400);
            }
            $set[] = 'name = ?';
            $params[] = $name;
        }
        if (isset($data['status'])) {
            $set[] = 'status = ?';
            $params[] = (string) $data['status'];
        }
        if (isset($data['db_name'])) {
            $dbName = (string) $data['db_name'];
            if (!preg_match('/^[A-Za-z0-9_\-]{2,63}$/', $dbName)) {
                return $this->fail('db_name 非法（仅字母数字下划线短横线，2-63）', 400);
            }
            $set[] = 'db_name = ?';
            $params[] = $dbName;
        }

        if ($set === []) {
            return $this->fail('无可更新字段', 400);
        }

        $set[] = 'updated_at = NOW()';
        $params[] = $id;
        Db::update(
            'UPDATE kode_tenants SET ' . implode(', ', $set) . ' WHERE id = ?',
            $params
        );

        return $this->ok(['id' => $id, 'updated' => true]);
    }

    public function destroy()
    {
        $id = (string) $this->param('id');
        $exists = Db::select('SELECT id FROM kode_tenants WHERE id = ? LIMIT 1', [$id]);
        if ($exists === []) {
            return $this->fail('租户不存在', 404);
        }

        Db::delete('DELETE FROM kode_tenants WHERE id = ?', [$id]);
        return $this->ok(['id' => $id, 'deleted' => true]);
    }

    /**
     * 创建租户数据库（database 隔离策略专用）。
     *
     * PostgreSQL 中 CREATE DATABASE 不能在事务内执行，需连接 postgres 维护库。
     */
    private function createTenantDatabase(string $dbName): bool
    {
        try {
            $port = (int) env('DB_PORT', 5432);
            $username = env('DB_USERNAME', 'root');
            $password = env('DB_PASSWORD', '');
            $host = env('DB_HOST', '127.0.0.1');

            $connCfg = [
                'driver' => 'pgsql',
                'host' => $host,
                'port' => $port,
                'database' => 'postgres',
                'username' => $username,
                'password' => $password,
                'charset' => 'utf8',
                'prefix' => '',
            ];
            $connName = 'tenant_create_' . substr(md5($dbName), 0, 8);
            Db::addConnection($connName, $connCfg);
            try {
                $db = Db::connection($connName);
                $db->statement('SELECT 1'); // 测试连接
                $exists = $db->select(
                    'SELECT 1 FROM pg_database WHERE datname = ?',
                    [$dbName]
                );
                $created = false;
                if ($exists === []) {
                    $db->statement("CREATE DATABASE {$dbName}");
                    $created = true;
                }
                return $created;
            } finally {
                // 抛错也必须摘掉：常驻 worker 里残留的连接注册表会把带密码的配置一直留在内存，
                // 且同名残留会让下次 addConnection 静默复用旧参数（改过 .env 后连的还是旧库）。
                Db::removeConnection($connName);
            }
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * GET /api/tenants/export — 导出租户列表（JSON）。
     */
    public function export()
    {
        $rows = Db::select('SELECT id, name, status, db_name, created_at FROM kode_tenants ORDER BY created_at ASC');
        foreach ($rows as &$row) {
            foreach ($row as $k => $v) {
                if ($v instanceof \DateTimeInterface) {
                    $row[$k] = $v->format('Y-m-d H:i:s');
                }
            }
        }
        unset($row);

        return $this->ok($rows);
    }

    /**
     * GET /api/tenants/stats — 租户概览统计。
     *
     * 返回：总数 / 按状态分档 / 最近创建 / 隔离策略 / storage 前缀 / 每租户关联计数（如可查）。
     */
    public function stats()
    {
        $total = (int) (Db::select('SELECT COUNT(*) AS c FROM kode_tenants')[0]['c'] ?? 0);
        $byStatus = ['active' => 0, 'inactive' => 0, 'suspended' => 0];
        foreach (Db::select('SELECT status, COUNT(*) AS c FROM kode_tenants GROUP BY status') as $row) {
            $s = (string) ($row['status'] ?? '');
            if (isset($byStatus[$s])) {
                $byStatus[$s] = (int) $row['c'];
            }
        }
        $recentRows = Db::select(
            'SELECT id, name, status, db_name, created_at FROM kode_tenants ORDER BY created_at DESC LIMIT 10'
        );
        $recent = [];
        foreach ($recentRows as $row) {
            $row['created_at'] = $row['created_at'] instanceof \DateTimeInterface
                ? $row['created_at']->format('Y-m-d H:i:s')
                : (string) ($row['created_at'] ?? '');
            $recent[] = $row;
        }

        // 每租户关联计数（wallets / media / sessions）— 表不存在则跳过
        $perTenant = [];
        foreach (Db::select('SELECT id, name FROM kode_tenants ORDER BY created_at ASC LIMIT 500') as $t) {
            $perTenant[$t['id']] = [
                'id' => $t['id'],
                'name' => $t['name'],
                'wallets' => 0,
                'media' => 0,
                'sessions' => 0,
            ];
        }
        try {
            foreach (Db::select('SELECT tenant_id, COUNT(*) AS c FROM kode_wallets GROUP BY tenant_id') as $r) {
                $tid = (string) ($r['tenant_id'] ?? '');
                if ($tid !== '' && isset($perTenant[$tid])) {
                    $perTenant[$tid]['wallets'] = (int) $r['c'];
                }
            }
        } catch (\Throwable) {
            // 表可能未创建
        }
        try {
            foreach (Db::select('SELECT tenant_id, COUNT(*) AS c FROM kode_media GROUP BY tenant_id') as $r) {
                $tid = (string) ($r['tenant_id'] ?? '');
                if ($tid !== '' && isset($perTenant[$tid])) {
                    $perTenant[$tid]['media'] = (int) $r['c'];
                }
            }
        } catch (\Throwable) {
        }
        try {
            foreach (Db::select('SELECT tenant_id, COUNT(*) AS c FROM kode_sessions GROUP BY tenant_id') as $r) {
                $tid = (string) ($r['tenant_id'] ?? '');
                if ($tid !== '' && isset($perTenant[$tid])) {
                    $perTenant[$tid]['sessions'] = (int) $r['c'];
                }
            }
        } catch (\Throwable) {
        }

        return $this->ok([
            'total' => $total,
            'by_status' => $byStatus,
            'recent' => $recent,
            'storage_strategy' => (string) config('tenant.storage.strategy', 'shared'),
            'storage_prefix' => (string) config('tenant.storage.prefix', 'tnt_'),
            'per_tenant' => array_values($perTenant),
        ]);
    }
}
