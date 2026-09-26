<?php

declare(strict_types=1);

namespace app\admin\controllers;

use app\concerns\ApiResponse;
use app\concerns\PageWindow;
use app\services\UserService;
use app\support\Clip;
use app\support\HashId;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;

/**
 * 用户管理（四层架构 Controller 层）：只收参/校验/调服务/包信封，零业务规则。
 */
final class UserController extends Controller
{
    use ApiResponse;
    use PageWindow;

    private function service(): UserService
    {
        return resolve(UserService::class);
    }

    /**
     * 取标量 query 值：若前端传了 `x[]=a&x[]=b` 之类数组，取首个非空元素，
     * 避免 `(string) array` 触发 "Array to string conversion" warning。
     */
    private function scalarQuery(string $key, mixed $default = null): mixed
    {
        $val = $this->query($key, $default);
        if (is_array($val)) {
            foreach ($val as $item) {
                if ($item !== null && $item !== '' && !is_array($item)) {
                    return $item;
                }
            }
            return '';
        }
        return $val;
    }

    public function index()
    {
        $win = $this->pageWindow($this->query('page'), $this->query('size'));
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];
        $search = Clip::chars($this->scalarQuery('search', ''), 64);
        $deptId = HashId::dec($this->scalarQuery('dept_id', '')) ?? 0;
        $statusRaw = $this->scalarQuery('status', null);
        $status = $statusRaw === null || $statusRaw === '' ? null : ((int) $statusRaw ? 1 : 0);
        $start = Clip::chars($this->scalarQuery('start_time', ''), 19);
        $end = Clip::chars($this->scalarQuery('end_time', ''), 19);
        $byId = HashId::dec($this->scalarQuery('id', '')) ?? 0;
        $remark = Clip::chars($this->scalarQuery('remark', ''), 64);
        $svc = $this->service();

        return $this->page(
            // 具名传参：`offset` 与 `page` 同为 int，写反了不会报错，只会给出「看着对的错数据」。
            $svc->list(
                offset: $offset,
                size: $size,
                search: $search,
                deptId: $deptId,
                status: $status,
                start: $start,
                end: $end,
                id: $byId,
                remark: $remark
            ),
            $svc->count($search, $deptId, $status, $start, $end, $byId, $remark),
            $page,
            $size
        );
    }

    public function store()
    {
        $data = $this->validate(Request::all(), [
            'name' => 'required|min:2|length:64',
            'email' => 'required|email',
            'password' => 'required|min:' . \app\support\SysConfig::getInt('auth.password_min_length', 6, 4, 64),
            'dept_id' => 'nullable|string',
            'remark' => 'nullable|length:255',
        ]);

        try {
            $user = $this->service()->create(
                $data['name'],
                $data['email'],
                $data['password'],
                $this->deptId($data['dept_id'] ?? null),
                (string) ($data['remark'] ?? '')
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
            'name' => 'required|min:2|length:64',
            'dept_id' => 'nullable|string',
            'remark' => 'nullable|length:255',
        ]);

        try {
            $deptId = array_key_exists('dept_id', $data) ? $this->deptId($data['dept_id']) : -1;
            $remark = array_key_exists('remark', $data) ? (string) $data['remark'] : null;
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
        try {
            $done = $this->service()->batchAssignRoles(
                $this->eidList($data['ids']),
                array_values((array) $data['roles'])
            );
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e->getCode() ?: 400);
        }

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
            // 批量导入不静默截断：切掉的部分不会被任何人发现，跳过的行有行号可回头改。
            $over = self::overWide($row, ['name' => 64, 'remark' => 255]);
            if ($over !== null) {
                $skipped[] = ['row' => $i + 2, 'reason' => $over];
                continue;
            }
            try {
                $this->service()->create(
                    $row['name'],
                    $row['email'],
                    $row['password'],
                    0,
                    (string) ($row['remark'] ?? '')
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

    /**
     * 批量导入行的列宽判据：回第一条超限的说明，全部在宽内则回 null。
     *
     * @param array<string, string> $row
     * @param array<string, int>    $widths 列名 → 字符上限（pgsql VARCHAR(n) 计字符不计字节）
     */
    private static function overWide(array $row, array $widths): ?string
    {
        foreach ($widths as $column => $limit) {
            $value = (string) ($row[$column] ?? '');
            if ($value !== '' && mb_strlen($value) > $limit) {
                return $column . ' 超过 ' . $limit . ' 字符';
            }
        }

        return null;
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
