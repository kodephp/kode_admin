<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use app\http\concerns\ApiResponse;
use app\services\UserService;
use app\support\HashId;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;

/**
 * 用户管理（四层架构 Controller 层）：只收参/校验/调服务/包信封，零业务规则。
 */
final class UserController extends Controller
{
    use ApiResponse;

    private function service(): UserService
    {
        return resolve(UserService::class);
    }

    public function index()
    {
        $page = max(1, (int) $this->query('page', 1));
        $size = min(100, max(1, (int) $this->query('size', 20)));
        $search = substr((string) $this->query('search', ''), 0, 64);
        $deptId = HashId::dec((string) ($this->query('dept_id', '') ?? '')) ?? 0;
        $statusRaw = $this->query('status', null);
        $status = $statusRaw === null || $statusRaw === '' ? null : ((int) $statusRaw ? 1 : 0);
        $start = substr((string) $this->query('start_time', ''), 0, 19);
        $end = substr((string) $this->query('end_time', ''), 0, 19);
        $byId = HashId::dec((string) ($this->query('id', '') ?? '')) ?? 0;
        $remark = substr((string) $this->query('remark', ''), 0, 64);
        $svc = $this->service();

        return $this->page(
            $svc->list($page, $size, $search, $deptId, $status, $start, $end, $byId, $remark),
            $svc->count($search, $deptId, $status, $start, $end, $byId, $remark),
            $page,
            $size
        );
    }

    public function store()
    {
        $data = $this->validate(Request::all(), [
            'name' => 'required|min:2|max:64',
            'email' => 'required|email',
            'password' => 'required|min:' . \app\support\SysConfig::getInt('auth.password_min_length', 6, 4, 64),
            'dept_id' => 'nullable|string',
            'remark' => 'nullable|max:255',
        ]);

        try {
            $user = $this->service()->create(
                $data['name'],
                $data['email'],
                $data['password'],
                $this->deptId($data['dept_id'] ?? null),
                substr((string) ($data['remark'] ?? ''), 0, 255)
            );
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 400);
        }

        return $this->ok(['id' => HashId::enc((int) $user->getId())], 201);
    }

    public function update()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        $data = $this->validate(Request::all(), [
            'name' => 'required|min:2|max:64',
            'dept_id' => 'nullable|string',
            'remark' => 'nullable|max:255',
        ]);

        try {
            $deptId = array_key_exists('dept_id', $data) ? $this->deptId($data['dept_id']) : -1;
            $remark = array_key_exists('remark', $data) ? substr((string) $data['remark'], 0, 255) : null;
            $user = $this->service()->rename($id, $data['name'], $deptId, $remark);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 400);
        }

        return $this->ok(['id' => HashId::enc($id)]);
    }

    public function destroy()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }

        try {
            $this->service()->remove($id);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 400);
        }

        return $this->ok(['deleted' => HashId::enc($id)]);
    }

    /** @return list<int> */
    private function eidList(mixed $ids): array
    {
        $out = [];
        foreach ((array) $ids as $eid) {
            $id = HashId::dec((string) $eid);
            if ($id !== null && $id > 0) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    public function batchDestroy()
    {
        $data = $this->validate(Request::all(), ['ids' => 'required|array']);
        $done = $this->service()->batchRemove($this->eidList($data['ids']));

        return $this->ok(['deleted' => $done]);
    }

    public function batchAssignRoles()
    {
        $data = $this->validate(Request::all(), ['ids' => 'required|array', 'roles' => 'required|array']);
        $done = $this->service()->batchAssignRoles(
            $this->eidList($data['ids']),
            array_values((array) $data['roles'])
        );

        return $this->ok(['updated' => $done]);
    }

    /**
     * 用户 CSV 导入（超管；列：name,email,password,remark?；逐行校验，错误行跳过并汇总）。
     */
    public function import()
    {
        $file = Request::file('file');
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->fail('未收到有效文件', 400);
        }
        if ($file->getSize() > 2 * 1024 * 1024) {
            return $this->fail('文件超过 2MB', 400);
        }
        $ext = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv'], true)) {
            return $this->fail('仅支持 CSV 文件', 400);
        }
        $stream = $file->getStream();
        try {
            $content = (string) $stream;
        } catch (\Throwable) {
            return $this->fail('文件读取失败', 400);
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? '';
        $lines = array_values(array_filter(
            array_map(static fn($l) => trim((string) $l), preg_split('/\r\n|\n|\r/', $content) ?: []),
            static fn($l) => $l !== ''
        ));
        if ($lines === []) {
            return $this->fail('空文件', 400);
        }
        $header = array_map(static fn($c) => strtolower(trim((string) $c)), str_getcsv(array_shift($lines)));
        foreach (['name', 'email', 'password'] as $required) {
            if (!in_array($required, $header, true)) {
                return $this->fail('缺少必填列：name,email,password', 400);
            }
        }
        $created = 0;
        $skipped = [];
        foreach (array_slice($lines, 0, 2000) as $i => $line) {
            $cols = str_getcsv($line);
            $row = [];
            foreach ($header as $j => $key) {
                $row[$key] = trim((string) ($cols[$j] ?? ''));
            }
            if ($row['name'] === '' || $row['email'] === '' || $row['password'] === '') {
                $skipped[] = ['row' => $i + 2, 'reason' => '必填列为空'];
                continue;
            }
            try {
                $this->service()->create(
                    substr($row['name'], 0, 64),
                    $row['email'],
                    $row['password'],
                    0,
                    substr((string) ($row['remark'] ?? ''), 0, 255)
                );
                $created++;
            } catch (\RuntimeException $e) {
                $skipped[] = ['row' => $i + 2, 'reason' => $e->getMessage()];
            }
        }

        return $this->ok(['created' => $created, 'skipped' => $skipped], 201);
    }

    public function assignRoles()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        $data = $this->validate(Request::all(), ['roles' => 'required|array']);

        try {
            $roles = $this->service()->assignRoles($id, array_values((array) $data['roles']));
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 400);
        }

        return $this->ok(['id' => HashId::enc($id), 'roles' => $roles]);
    }

    public function toggleStatus()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }

        try {
            $user = $this->service()->toggleStatus($id);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 400);
        }

        return $this->ok(['id' => HashId::enc($id), 'status' => $user->getStatus()]);
    }

    public function resetPassword()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('不存在', 404);
        }
        $data = $this->validate(Request::all(), [
            'password' => 'required|min:' . \app\support\SysConfig::getInt('auth.password_min_length', 6, 4, 64) . '|max:64',
        ]);

        try {
            $user = $this->service()->resetPassword($id, $data['password']);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 400);
        }

        return $this->ok(['id' => HashId::enc($id)]);
    }

    /** 外部部门 eid → 内部 id（空/非法 → 0 即无部门）。 */
    private function deptId(mixed $eid): int
    {
        if ($eid === null || $eid === '') {
            return 0;
        }

        return HashId::dec((string) $eid) ?? 0;
    }

    /** 用户 CSV 导出（超管，原文邮箱，最多 5000 条，BOM 供 Excel 识别）。 */
    public function export()
    {
        $rows = \Kode\Database\Db\Db::select(
            'SELECT u.id, u.name, u.email, u.status, u.remark, u.created_at, d.name AS dept_name
             FROM kode_users u LEFT JOIN kode_depts d ON d.id = u.dept_id ORDER BY u.id LIMIT 5000'
        );
        $out = "\xEF\xBB\xBFid,name,email,status,dept,remark,created_at\n";
        foreach ($rows as $row) {
            $created = $row['created_at'] instanceof \DateTimeInterface
                ? $row['created_at']->format('Y-m-d H:i:s')
                : (string) ($row['created_at'] ?? '');
            $cells = [
                HashId::enc((int) $row['id']),
                $this->csvCell((string) ($row['name'] ?? '')),
                $this->csvCell((string) ($row['email'] ?? '')),
                (string) (int) ($row['status'] ?? 0),
                $this->csvCell((string) ($row['dept_name'] ?? '')),
                $this->csvCell((string) ($row['remark'] ?? '')),
                $created,
            ];
            $out .= implode(',', $cells) . "\n";
        }

        return \Kode\Framework\Http\Resp::make('', 200, [])
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="kode_users_' . date('Ymd_His') . '.csv"')
            ->withHeader('Content-Length', (string) strlen($out))
            ->withBody(\Kode\Http\Psr7\Stream::create($out));
    }

    private function csvCell(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
