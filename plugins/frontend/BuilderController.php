<?php

declare(strict_types=1);

namespace Kode\Plugins\frontend;

use app\concerns\ApiResponse;
use app\concerns\PageWindow;
use app\support\SysLog;
use Kode\Database\Db\Db;
use Kode\Database\Exception\DatabaseException;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;

/**
 * 低代码 Builder CRUD 控制器
 *
 * 管理 uni3-lowcode 项目 / 页面 / 构建任务 / License。
 * 所有端点挂 JWT 鉴权 + 权限门禁（见 FrontendPlugin::register）。
 *
 * `kode_lowcode_components` 有表有登记（`install()` + `tables()`），但本仓库没有任何读写入口：
 * 只有 `projectDestroy()` 跟着项目一起清它。所以「全新环境装完是空表」「表里 0 行」都是当前代码的正常形态，
 * 别当成数据失踪来查；要接组件库时补 CRUD，并同步本节的路由前缀与 `docs/API.md` 的端点表。
 *
 * 分页窗口口径见 app\concerns\PageWindow（size 夹取、page 越界 400）。
 *
 * 路由前缀：
 *   /api/builder/projects       — 项目 CRUD
 *   /api/builder/projects/{id}/pages  — 页面 CRUD
 *   /api/builder/pages/{pageDbId}     — 页面单项操作
 *   /api/builder/projects/{id}/build  — 构建触发 + 历史
 *   /api/lowcode/licenses     — License CRUD
 */
final class BuilderController extends Controller
{
    use ApiResponse;
    use PageWindow;

    /* ===================== 用户上下文 ===================== */

    /**
     * 出参 id 混淆：内部自增 id → 对外 HashId 不透明串（AGENTS.md §4 防枚举）。
     * 入参一律用 eid()/HashId::dec 解码，服务层保持 int。
     */
    private static function hashOut(array $row, array $fields = ['id', 'project_id']): array
    {
        foreach ($fields as $f) {
            if (array_key_exists($f, $row)) {
                $row[$f] = \app\support\HashId::enc((int) $row[$f]);
            }
        }

        return $row;
    }

    private function owner(): string
    {
        $payload = Request::attr('auth');
        return (string) ($payload->username ?? ($payload->uid ?? ''));
    }

    /**
     * 可写字段的列宽（= 迁移与 `database/sql/schema.sql` 建出的 `VARCHAR(n)`，pgsql 计字符不计字节）。
     *
     * 项目表、页面表、构建任务表共用这一份：同名的 `name`/`title` 都是 128，`version` 都是 32，
     * 而 license 表的 `version` 只有 16 —— 那一列单独走 `LICENSE_WIDTH_RULES`，合进来就只能取宽的。
     *
     * 只写这一份：此前 store 里只挡了 `name`，`appid` / `license_id` / `theme_key` 三个 64 宽列
     * 一个都没判，update 同理，`build_jobs.version`（32 宽）、`pages.title`（128 宽）也没有 ——
     * 超限的值不是「参数错误」而是 pgsql 22001 → 500，页面上只看到「服务器错误」，
     * 谁都不知道是自己写长了。
     *
     * 规则用 `length:` 而不是 `max:`：`max:` 在 framework < 1.11 上对**纯数字串**走数值比较
     * （`appid = '2026'` 会被判成「大于 64」而误拒），`length:` 在任何版本上都是字符数。
     */
    private const WIDTH_RULES = [
        'name' => 'length:128',
        'title' => 'length:128',
        'appid' => 'length:64',
        'license_id' => 'length:64',
        'theme_key' => 'length:64',
        'version' => 'length:32',
    ];

    /**
     * 写前过一遍列宽。`$values` 必须是**即将落库**的那份（已 trim、只含本次要写的键），
     * 否则「校验的」与「写入的」不是同一个字符串，边界上照样能撞 22001。
     *
     * 失败由框架转 422 信封并带 `errors`（AGENTS.md §3），不在此自造格式。
     *
     * @param array<string, mixed> $values
     */
    private function assertWidths(array $values): void
    {
        $this->validate($values, self::WIDTH_RULES);
    }

    /**
     * License 侧的列宽（同一张表的两个写入口共用：签发与刷新）。
     *
     * `version` 在项目/构建侧是 32 宽、在 license 表里只有 16，两张表两列同名不同宽，
     * 所以另起一份而不是往 `WIDTH_RULES` 里塞 —— 合成一份就只能取较松的那个，
     * 于是 `2026.01.01-beta.1`（17 字符）照样撞 22001。
     *
     * @var array<string, string>
     */
    private const LICENSE_WIDTH_RULES = [
        'customer_name' => 'length:128',
        'customer_email' => 'length:128',
        'customer_contact' => 'length:64',
        'version' => 'length:16',
    ];

    /**
     * @param array<string, mixed> $values 本次要写入的键（缺键不判：PUT 语义下原值本就合规）
     */
    private function assertLicenseWidths(array $values): void
    {
        $this->validate($values, self::LICENSE_WIDTH_RULES);
    }

    /**
     * 落库失败的统一出口：对外只回通用文案，原文进系统日志。
     *
     * 必须排在那条 `catch (\RuntimeException)` **前面**，而且要 catch 两个类型：
     * pgsql 报错从 `Db::insert()` 出来时是**原生 `PDOException`**（`PdoConnection::retryOnce()`
     * 只对「断链」类做重连，其余原样 rethrow），而连接/连接池失败才是 `DatabaseException`。
     *
     * 为什么这两处不外发原文（与监控/迁移那几个「运维要看报错」的入口相反）：
     * 这里的失败没有一条是调用方能行动的 —— 超长由 `assertLicenseWidths()` 提前挡成 422，
     * 剩下的（断链、唯一键冲突、错型日期）原文只会带出表名、参数位次和 `SQLSTATE[...]`，
     * 而 license 的签发方是分发出去的构建壳，不是坐在后台读 SQL 的人。详情进系统日志，
     * 运维照旧查得到（`kode_system_logs`，模块 `lowcode`）。
     */
    private function dbFail(string $what, \Throwable $e): \Psr\Http\Message\ResponseInterface
    {
        SysLog::error('lowcode', $what . '失败：' . $e->getMessage());

        return $this->fail($what . '失败，请稍后重试（详情见系统日志）', 500);
    }

    /* ===================== 项目 CRUD ===================== */

    /**
     * GET /api/builder/projects
     * 项目列表（支持 status/edition/keyword 过滤 + 分页）。
     */
    public function projectsIndex()
    {
        $win = $this->pageWindow($this->query('page'), $this->query('size'));
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];

        $status = $this->query('status', null);
        $edition = $this->query('edition', null);
        $keyword = $this->query('keyword', null);

        $where = [];
        $bindings = [];
        if ($status !== null && $status !== '') {
            $where[] = 'status = ?';
            $bindings[] = (int) $status;
        }
        if ($edition !== null && $edition !== '') {
            $where[] = 'edition = ?';
            $bindings[] = $edition;
        }
        if ($keyword !== null && $keyword !== '') {
            $where[] = '(name ILIKE ? OR appid ILIKE ? OR description ILIKE ?)';
            $kw = '%' . $keyword . '%';
            $bindings[] = $kw;
            $bindings[] = $kw;
            $bindings[] = $kw;
        }
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) Db::select("SELECT COUNT(*) as cnt FROM kode_lowcode_projects{$whereSql}", $bindings)[0]['cnt'];
        $items = Db::select(
            "SELECT id, name, appid, description, edition, license_id, theme_key, status, created_by, created_at, updated_at
             FROM kode_lowcode_projects{$whereSql}
             ORDER BY id DESC LIMIT ? OFFSET ?",
            [...$bindings, $size, $offset]
        );
        $items = array_map(static fn (array $r): array => self::hashOut($r, ['id']), $items);

        return $this->page($items, $total, $page, $size);
    }

    /**
     * GET /api/builder/projects/{id}
     * 项目详情（含统计：页面数/构建数）。
     */
    public function projectShow()
    {
        $dbId = $this->eid();
        if ($dbId === null) {
            return $this->fail('project not found', 404);
        }
        $row = Db::select("SELECT * FROM kode_lowcode_projects WHERE id = ?", [$dbId]);
        if (empty($row)) {
            return $this->fail('project not found', 404);
        }

        $project = $row[0];
        $pageCount = (int) Db::select("SELECT COUNT(*) as cnt FROM kode_lowcode_pages WHERE project_id = ?", [$dbId])[0]['cnt'];
        $buildCount = (int) Db::select("SELECT COUNT(*) as cnt FROM kode_lowcode_build_jobs WHERE project_id = ?", [$dbId])[0]['cnt'];
        $succeededBuilds = (int) Db::select("SELECT COUNT(*) as cnt FROM kode_lowcode_build_jobs WHERE project_id = ? AND status = 'succeeded'", [$dbId])[0]['cnt'];

        $project['page_count'] = $pageCount;
        $project['build_count'] = $buildCount;
        $project['succeeded_builds'] = $succeededBuilds;

        return $this->ok(self::hashOut($project));
    }

    /**
     * POST /api/builder/projects
     * 创建项目。
     */
    public function projectStore()
    {
        $name = trim((string) $this->post('name', ''));
        if ($name === '') {
            return $this->fail('name 必填');
        }
        // 长度不再在这里判第二遍：`assertWidths()` 是 128 这一数的唯一出处。

        $appid = trim((string) $this->post('appid', ''));
        $description = trim((string) $this->post('description', ''));
        $edition = trim((string) $this->post('edition', 'standard'));
        $licenseId = trim((string) $this->post('license_id', ''));
        $themeKey = trim((string) $this->post('theme_key', 'light-clean'));

        // edition 白名单
        $allowedEditions = ['standard', 'professional', 'enterprise', 'oem', 'starter', 'growth'];
        if (!in_array($edition, $allowedEditions, true)) {
            return $this->fail('edition 非法，允许值：' . implode(', ', $allowedEditions));
        }

        $this->assertWidths([
            'name' => $name,
            'appid' => $appid,
            'license_id' => $licenseId,
            'theme_key' => $themeKey,
        ]);

        $owner = $this->owner();
        $lastId = Db::insert(
            "INSERT INTO kode_lowcode_projects (name, appid, description, edition, license_id, theme_key, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            [$name, $appid, $description, $edition, $licenseId, $themeKey, $owner]
        );

        if (!is_numeric($lastId) || (int) $lastId <= 0) {
            return $this->fail('创建失败', 500);
        }

        $row = Db::select("SELECT * FROM kode_lowcode_projects WHERE id = ?", [(int) $lastId]);

        return $this->ok($row === [] ? null : self::hashOut($row[0]), 201);
    }

    /**
     * PUT /api/builder/projects/{id}
     * 更新项目。
     */
    public function projectUpdate()
    {
        $dbId = $this->eid();
        if ($dbId === null) {
            return $this->fail('project not found', 404);
        }
        $existing = Db::select("SELECT id FROM kode_lowcode_projects WHERE id = ?", [$dbId]);
        if (empty($existing)) {
            return $this->fail('project not found', 404);
        }

        $fields = [];
        $bindings = [];
        // 与 $bindings 同步攒一份「本次真正要写的值」，交给 assertWidths 判列宽（键名 = 列名）
        $pending = [];

        $name = $this->post('name', null);
        if ($name !== null) {
            $name = trim((string) $name);
            if ($name === '') {
                return $this->fail('name 不能为空');
            }
            // 128 这一数只由 `assertWidths($pending)` 判，不在这里再量一遍。
            $fields[] = 'name = ?';
            $bindings[] = $name;
            $pending['name'] = $name;
        }

        $appid = $this->post('appid', null);
        if ($appid !== null) {
            $appid = trim((string) $appid);
            $fields[] = 'appid = ?';
            $bindings[] = $appid;
            $pending['appid'] = $appid;
        }

        $description = $this->post('description', null);
        if ($description !== null) {
            $fields[] = 'description = ?';
            $bindings[] = trim((string) $description);
        }

        $edition = $this->post('edition', null);
        if ($edition !== null) {
            $allowedEditions = ['standard', 'professional', 'enterprise', 'oem', 'starter', 'growth'];
            if (!in_array($edition, $allowedEditions, true)) {
                return $this->fail('edition 非法');
            }
            $fields[] = 'edition = ?';
            $bindings[] = $edition;
            $pending['edition'] = $edition;
        }

        $licenseId = $this->post('license_id', null);
        if ($licenseId !== null) {
            $licenseId = trim((string) $licenseId);
            $fields[] = 'license_id = ?';
            $bindings[] = $licenseId;
            $pending['license_id'] = $licenseId;
        }

        $themeKey = $this->post('theme_key', null);
        if ($themeKey !== null) {
            $themeKey = trim((string) $themeKey);
            $fields[] = 'theme_key = ?';
            $bindings[] = $themeKey;
            $pending['theme_key'] = $themeKey;
        }

        $status = $this->post('status', null);
        if ($status !== null) {
            $fields[] = 'status = ?';
            $bindings[] = (int) $status;
        }

        if ($fields === []) {
            return $this->fail('无字段可更新');
        }

        $this->assertWidths($pending);

        $fields[] = 'updated_at = NOW()';
        $bindings[] = $dbId;

        Db::update("UPDATE kode_lowcode_projects SET " . implode(', ', $fields) . " WHERE id = ?", $bindings);

        $row = Db::select("SELECT * FROM kode_lowcode_projects WHERE id = ?", [$dbId]);

        return $this->ok($row === [] ? null : self::hashOut($row[0]));
    }

    /**
     * DELETE /api/builder/projects/{id}
     * 删除项目（级联删除页面/组件/构建任务）。
     */
    public function projectDestroy()
    {
        $dbId = $this->eid();
        if ($dbId === null) {
            return $this->fail('project not found', 404);
        }
        $existing = Db::select("SELECT id FROM kode_lowcode_projects WHERE id = ?", [$dbId]);
        if (empty($existing)) {
            return $this->fail('project not found', 404);
        }

        // 级联删除
        Db::delete("DELETE FROM kode_lowcode_pages WHERE project_id = ?", [$dbId]);
        Db::delete("DELETE FROM kode_lowcode_components WHERE project_id = ?", [$dbId]);
        Db::delete("DELETE FROM kode_lowcode_build_jobs WHERE project_id = ?", [$dbId]);
        Db::delete("DELETE FROM kode_lowcode_projects WHERE id = ?", [$dbId]);

        return $this->ok(['deleted' => true, 'id' => $this->param('id')]);
    }

    /* ===================== 页面 CRUD ===================== */

    /**
     * GET /api/builder/projects/{id}/pages
     * 项目下的页面列表（分页；一个项目的页面数没有产品上限，不能不带 LIMIT 全量出）。
     */
    public function pagesIndex()
    {
        $projectId = $this->eid();
        if ($projectId === null) {
            return $this->fail('project not found', 404);
        }
        $win = $this->pageWindow($this->query('page'), $this->query('size'), 100);
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];
        $status = $this->query('status', null);
        $keyword = $this->query('keyword', null);

        $where = ['project_id = ?'];
        $bindings = [$projectId];

        if ($status !== null && $status !== '') {
            $where[] = 'status = ?';
            $bindings[] = (int) $status;
        }
        if ($keyword !== null && $keyword !== '') {
            $where[] = '(title ILIKE ? OR page_id ILIKE ?)';
            $kw = '%' . $keyword . '%';
            $bindings[] = $kw;
            $bindings[] = $kw;
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total = (int) (Db::select("SELECT COUNT(*) AS cnt FROM kode_lowcode_pages{$whereSql}", $bindings)[0]['cnt'] ?? 0);
        $items = Db::select(
            "SELECT id, project_id, page_id, title, status, sort, created_by, created_at, updated_at
             FROM kode_lowcode_pages{$whereSql}
             ORDER BY sort ASC, id ASC LIMIT ? OFFSET ?",
            [...$bindings, $size, $offset]
        );
        $items = array_map(static fn (array $r): array => self::hashOut($r), $items);

        return $this->page($items, $total, $page, $size);
    }

    /**
     * GET /api/builder/pages/{pageDbId}
     * 页面详情（含完整 schema_json）。
     */
    public function pageShow()
    {
        $id = $this->eid('pageDbId');
        if ($id === null) {
            return $this->fail('page not found', 404);
        }
        $row = Db::select("SELECT * FROM kode_lowcode_pages WHERE id = ?", [$id]);
        if (empty($row)) {
            return $this->fail('page not found', 404);
        }

        $page = $row[0];
        $page['schema'] = json_decode($page['schema_json'] ?? '{}', true) ?? [];
        unset($page['schema_json']);

        return $this->ok(self::hashOut($page));
    }

    /**
     * POST /api/builder/projects/{id}/pages
     * 创建页面（schema_json 从 preset 或请求体读取）。
     */
    public function pageStore()
    {
        $projectId = $this->eid();
        if ($projectId === null) {
            return $this->fail('project not found', 404);
        }
        if (Db::select('SELECT id FROM kode_lowcode_projects WHERE id = ? LIMIT 1', [$projectId]) === []) {
            return $this->fail('project not found', 404);
        }
        $pageId = trim((string) $this->post('page_id', ''));
        if ($pageId === '' || !preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $pageId)) {
            return $this->fail('page_id 必填，白名单字符集 [a-zA-Z0-9_-]，长度 2-64');
        }

        $title = trim((string) $this->post('title', ''));
        $this->assertWidths(['title' => $title]);
        $sort = (int) $this->post('sort', 0);
        $schema = $this->post('schema', null);
        $schemaJson = $schema !== null ? json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';

        // 幂等：已存在则返回现有
        $existing = Db::select("SELECT id FROM kode_lowcode_pages WHERE project_id = ? AND page_id = ?", [$projectId, $pageId]);
        if (!empty($existing)) {
            return $this->fail('page 已存在：' . $pageId, 409);
        }

        $owner = $this->owner();
        $lastId = Db::insert(
            "INSERT INTO kode_lowcode_pages (project_id, page_id, title, schema_json, status, sort, created_by)
             VALUES (?, ?, ?, ?, 1, ?, ?)",
            [$projectId, $pageId, $title, $schemaJson, $sort, $owner]
        );

        if (!is_numeric($lastId) || (int) $lastId <= 0) {
            return $this->fail('创建失败', 500);
        }

        $row = Db::select("SELECT id, project_id, page_id, title, status, sort, created_by, created_at FROM kode_lowcode_pages WHERE id = ?", [(int) $lastId]);

        return $this->ok($row === [] ? null : self::hashOut($row[0]), 201);
    }

    /**
     * PUT /api/builder/pages/{pageDbId}
     * 更新页面（title/sort/schema/status）。
     */
    public function pageUpdate()
    {
        $id = $this->eid('pageDbId');
        if ($id === null) {
            return $this->fail('page not found', 404);
        }
        $existing = Db::select("SELECT id FROM kode_lowcode_pages WHERE id = ?", [$id]);
        if (empty($existing)) {
            return $this->fail('page not found', 404);
        }

        $fields = [];
        $bindings = [];
        $pending = [];

        $title = $this->post('title', null);
        if ($title !== null) {
            $title = trim((string) $title);
            $fields[] = 'title = ?';
            $bindings[] = $title;
            $pending['title'] = $title;
        }

        $sort = $this->post('sort', null);
        if ($sort !== null) {
            $fields[] = 'sort = ?';
            $bindings[] = (int) $sort;
        }

        $status = $this->post('status', null);
        if ($status !== null) {
            $fields[] = 'status = ?';
            $bindings[] = (int) $status;
        }

        $schema = $this->post('schema', null);
        if ($schema !== null) {
            $fields[] = 'schema_json = ?';
            $bindings[] = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($fields === []) {
            return $this->fail('无字段可更新');
        }

        $this->assertWidths($pending);

        $fields[] = 'updated_at = NOW()';
        $bindings[] = $id;

        Db::update("UPDATE kode_lowcode_pages SET " . implode(', ', $fields) . " WHERE id = ?", $bindings);

        $row = Db::select("SELECT id, project_id, page_id, title, status, sort, created_by, created_at, updated_at FROM kode_lowcode_pages WHERE id = ?", [$id]);

        return $this->ok($row === [] ? null : self::hashOut($row[0]));
    }

    /**
     * DELETE /api/builder/pages/{pageDbId}
     * 删除页面。
     */
    public function pageDestroy()
    {
        $id = $this->eid('pageDbId');
        if ($id === null) {
            return $this->fail('page not found', 404);
        }
        $existing = Db::select("SELECT id FROM kode_lowcode_pages WHERE id = ?", [$id]);
        if (empty($existing)) {
            return $this->fail('page not found', 404);
        }

        Db::delete("DELETE FROM kode_lowcode_pages WHERE id = ?", [$id]);

        return $this->ok(['deleted' => true, 'id' => $this->param('pageDbId')]);
    }

    /* ===================== 构建任务 ===================== */

    /**
     * GET /api/builder/projects/{id}/builds
     * 项目构建历史（分页；此前的 `LIMIT 100` 是「只给了最近 100 次」而不报告，
     * 翻不到第 101 次之前的构建，页面上却没有任何一处说历史被截断了）。
     */
    public function buildsIndex()
    {
        $projectId = $this->eid();
        if ($projectId === null) {
            return $this->fail('project not found', 404);
        }
        $win = $this->pageWindow($this->query('page'), $this->query('size'), 100);
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];

        $total = (int) (Db::select(
            'SELECT COUNT(*) AS cnt FROM kode_lowcode_build_jobs WHERE project_id = ?',
            [$projectId]
        )[0]['cnt'] ?? 0);
        $items = Db::select(
            "SELECT id, project_id, version, status, started_at, finished_at, artifact_url, error, created_by, created_at
             FROM kode_lowcode_build_jobs WHERE project_id = ?
             ORDER BY id DESC LIMIT ? OFFSET ?",
            [$projectId, $size, $offset]
        );
        $items = array_map(static fn (array $r): array => self::hashOut($r), $items);

        return $this->page($items, $total, $page, $size);
    }

    /**
     * POST /api/builder/projects/{id}/build
     * 触发构建（当前实现：创建任务 + 异步标记 succeeded）。
     * 未来版本：调用 uni3 仓库的 pnpm build:h5 生成产物。
     */
    public function buildStore()
    {
        $projectId = $this->eid();
        if ($projectId === null) {
            return $this->fail('project not found', 404);
        }
        $existing = Db::select("SELECT id, name, appid FROM kode_lowcode_projects WHERE id = ?", [$projectId]);
        if (empty($existing)) {
            return $this->fail('project not found', 404);
        }

        $version = trim((string) $this->post('version', '2.0.0'));
        $this->assertWidths(['version' => $version]);
        $owner = $this->owner();

        $lastId = Db::insert(
            "INSERT INTO kode_lowcode_build_jobs (project_id, version, status, started_at, created_by)
             VALUES (?, ?, 'running', NOW(), ?)",
            [$projectId, $version, $owner]
        );

        $jobId = (int) $lastId;

        // 同步标记为 succeeded（当前为占位实现，未来接入真实构建流水线）
        $artifactUrl = '/uni3-preview/index.html';
        Db::update(
            "UPDATE kode_lowcode_build_jobs SET status = 'succeeded', finished_at = NOW(), artifact_url = ? WHERE id = ?",
            [$artifactUrl, $jobId]
        );

        $row = Db::select("SELECT * FROM kode_lowcode_build_jobs WHERE id = ?", [$jobId]);

        return $this->ok($row === [] ? null : self::hashOut($row[0]), 201);
    }

    /* ===================== License CRUD ===================== */

    /**
     * GET /api/lowcode/licenses
     * License 列表（支持 status/type/edition 过滤 + 分页，另带筛选集上的分布 `stats`）。
     */
    public function licensesIndex()
    {
        $win = $this->pageWindow($this->query('page'), $this->query('size'));
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];

        $status = $this->query('status', null);
        $type = $this->query('type', null);
        $edition = $this->query('edition', null);
        $keyword = $this->query('keyword', null);

        $where = [];
        $bindings = [];
        if ($status !== null && $status !== '') {
            $where[] = 'status = ?';
            $bindings[] = $status;
        }
        if ($type !== null && $type !== '') {
            $where[] = 'type = ?';
            $bindings[] = $type;
        }
        if ($edition !== null && $edition !== '') {
            $where[] = 'edition = ?';
            $bindings[] = $edition;
        }
        // 前端搜索框按「客户 / 授权 ID」过滤（此前该参数被静默忽略，搜索无效果）
        if ($keyword !== null && $keyword !== '') {
            $where[] = '(customer_name ILIKE ? OR license_id ILIKE ? OR customer_email ILIKE ?)';
            $kw = '%' . $keyword . '%';
            $bindings[] = $kw;
            $bindings[] = $kw;
            $bindings[] = $kw;
        }
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        // 卡片的四份分布算在 SQL 侧、跟着筛选条件走，不让前端数「这一页有几条」：
        // 分页之前它数的是被 LIMIT 200 截断的整表，分页之后会数成 20 条的一页 ——
        // 两种都是静默偏小（口径同 §4「跨分片聚合不许按行抽样」）。
        // `expired` 与列表页的行内标记同源：显式 expired，或 active 但 end_at 已过当天。
        $agg = Db::select(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status = 'active' AND (end_at IS NULL OR end_at >= CURRENT_DATE)) AS active,
                    COUNT(*) FILTER (WHERE status = 'expired' OR (status = 'active' AND end_at < CURRENT_DATE)) AS expired,
                    COUNT(*) FILTER (WHERE status = 'revoked') AS revoked
             FROM kode_lowcode_licenses{$whereSql}",
            $bindings
        );
        $stats = [
            'total' => (int) ($agg[0]['total'] ?? 0),
            'active' => (int) ($agg[0]['active'] ?? 0),
            'expired' => (int) ($agg[0]['expired'] ?? 0),
            'revoked' => (int) ($agg[0]['revoked'] ?? 0),
        ];
        $items = Db::select(
            "SELECT id, license_id, type, edition, customer_name, customer_email, start_at, end_at, version, status, issued_at, updated_at
             FROM kode_lowcode_licenses{$whereSql}
             ORDER BY id DESC LIMIT ? OFFSET ?",
            [...$bindings, $size, $offset]
        );
        $items = array_map(static fn (array $r): array => self::hashOut($r, ['id']), $items);

        return $this->page($items, $stats['total'], $page, $size, ['stats' => $stats]);
    }

    /**
     * GET /api/lowcode/licenses/{id}
     * License 详情（含 appids/domains/bundles/features/signature）。
     */
    public function licenseShow()
    {
        $dbId = $this->eid();
        if ($dbId === null) {
            return $this->fail('license not found', 404);
        }
        $row = Db::select("SELECT * FROM kode_lowcode_licenses WHERE id = ?", [$dbId]);
        if (empty($row)) {
            return $this->fail('license not found', 404);
        }

        $license = $row[0];
        // 解码 JSON 数组字段
        foreach (['appids', 'domains', 'bundles', 'features'] as $field) {
            $license[$field] = json_decode($license[$field] ?? '[]', true) ?? [];
        }

        return $this->ok(self::hashOut($license, ['id']));
    }

    /**
     * POST /api/lowcode/licenses
     * 签发 License（RSA-2048 签名 + 8 维授权元数据）。
     */
    public function licenseStore()
    {
        $data = [
            'customer_name' => $this->post('customer_name', ''),
            'customer_email' => $this->post('customer_email', ''),
            'customer_contact' => $this->post('customer_contact', ''),
            'type' => $this->post('type', 'commercial'),
            'edition' => $this->post('edition', 'standard'),
            'start_at' => $this->post('start_at', ''),
            'end_at' => $this->post('end_at', ''),
            'version' => $this->post('version', '2.x'),
            'appids' => (array) $this->post('appids', []),
            'domains' => (array) $this->post('domains', []),
            'bundles' => (array) $this->post('bundles', []),
            'features' => (array) $this->post('features', []),
        ];

        // 必须在 try 之外：ValidationException 继承自 RuntimeException，
        // 放进来会被下面「落库失败 → 500」那条 catch 吃掉，422 变成 500。
        $this->assertLicenseWidths($data);

        try {
            $result = (new LicenseService())->issue($data);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\PDOException | DatabaseException $e) {
            return $this->dbFail('License 签发', $e);
        } catch (\RuntimeException $e) {
            // 服务自己写的文案（openssl/密钥文件/落库返回 0），面向运维、不含 SQL 原文
            return $this->fail($e->getMessage(), 500);
        }

        $lic = $result['license'] ?? null;

        return $this->ok($lic === null ? null : self::hashOut($lic, ['id']), 201);
    }

    /**
     * POST /api/lowcode/licenses/{id}/revoke
     * 吊销 License（签名保持不变，便于审计追溯）。
     */
    public function licenseRevoke()
    {
        $dbId = $this->eid();
        if ($dbId === null) {
            return $this->fail('license not found', 404);
        }
        $service = new LicenseService();
        $cur = $service->get($dbId);
        if ($cur === null) {
            return $this->fail('license not found', 404);
        }
        $service->revoke($dbId);
        $lic = $service->get($dbId);

        return $this->ok($lic === null ? null : self::hashOut($lic, ['id']));
    }

    /**
     * POST /api/lowcode/licenses/{id}/activate
     * 激活 License（恢复 active 状态）。
     */
    public function licenseActivate()
    {
        $dbId = $this->eid();
        if ($dbId === null) {
            return $this->fail('license not found', 404);
        }
        $service = new LicenseService();
        $cur = $service->get($dbId);
        if ($cur === null) {
            return $this->fail('license not found', 404);
        }
        $service->activate($dbId);
        $lic = $service->get($dbId);

        return $this->ok($lic === null ? null : self::hashOut($lic, ['id']));
    }

    /**
     * PUT /api/lowcode/licenses/{id}
     * 刷新 License（改字段 + 重签）。
     *
     * 用于延期 / 扩容 / 版本升级，不改变 license_id 与 status。
     */
    public function licenseUpdate()
    {
        $dbId = $this->eid();
        if ($dbId === null) {
            return $this->fail('license not found', 404);
        }
        $service = new LicenseService();
        $cur = $service->get($dbId);
        if ($cur === null) {
            return $this->fail('license not found', 404);
        }

        $allowedScalar = ['type', 'edition', 'customer_name', 'customer_email', 'customer_contact', 'version', 'start_at', 'end_at'];
        $allowedArray = ['appids', 'domains', 'bundles', 'features'];
        $data = [];
        foreach ($allowedScalar as $field) {
            $v = $this->post($field, null);
            if ($v !== null) {
                $data[$field] = $v;
            }
        }
        foreach ($allowedArray as $field) {
            $v = $this->post($field, null);
            if ($v !== null) {
                $data[$field] = (array) $v;
            }
        }
        if ($data === []) {
            return $this->fail('无字段可更新');
        }

        // 只判本次提交的键（缺键保留原值 = §4 的 PUT 语义）；同 licenseStore，必须在 try 之外。
        $this->assertLicenseWidths($data);

        try {
            $row = $service->refresh($dbId, $data);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\PDOException | DatabaseException $e) {
            return $this->dbFail('License 刷新', $e);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 500);
        }

        return $this->ok($row === null ? null : self::hashOut($row, ['id']));
    }

    /**
     * POST /api/lowcode/licenses/verify
     * 前端 shell bootstrap 校验入口。
     *
     * 请求体：{license_id, claims: {appid, domain, bundle, version, features[]}}
     * 响应：{valid, reasons[], details{}}
     */
    public function licenseVerify()
    {
        $licenseId = trim((string) $this->post('license_id', ''));
        $claims = (array) $this->post('claims', []);
        if ($licenseId === '') {
            return $this->fail('license_id 必填', 422);
        }
        $service = new LicenseService();
        $license = $service->getByLicenseId($licenseId);
        if ($license === null) {
            return $this->fail('license not found', 404);
        }
        $result = $service->verify($license, $claims);
        // 附带 license 元信息（不含 signature 全文，仅返回摘要，避免前端持有完整签名串）
        $result['license_meta'] = [
            'license_id' => $license['license_id'],
            'type' => $license['type'],
            'edition' => $license['edition'],
            'customer_name' => $license['customer_name'],
            'start_at' => $license['start_at'],
            'end_at' => $license['end_at'],
            'version' => $license['version'],
            'status' => $license['status'],
            'signature_prefix' => substr((string) ($license['signature'] ?? ''), 0, 16) . '...',
        ];
        return $this->ok($result);
    }

    /**
     * GET /api/lowcode/licenses/_keys/fingerprints
     * 密钥指纹（供前端展示 + 轮换确认）。
     */
    public function licenseKeysFingerprints()
    {
        return $this->ok((new LicenseService())->fingerprints());
    }

    /**
     * POST /api/lowcode/licenses/_keys/rotate
     * 轮转密钥对（会备份旧密钥，旧 License 签名将失效）。
     *
     * 危险操作，需要 confirm 字段 = "I_UNDERSTAND_LICENSES_WILL_BREAK"。
     */
    public function licenseKeysRotate()
    {
        $confirm = (string) $this->post('confirm', '');
        if ($confirm !== 'I_UNDERSTAND_LICENSES_WILL_BREAK') {
            return $this->fail('危险操作：请确认 confirm = "I_UNDERSTAND_LICENSES_WILL_BREAK"', 422);
        }
        try {
            $result = (new LicenseService())->rotateKeys();
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 500);
        }
        return $this->ok($result);
    }
}
