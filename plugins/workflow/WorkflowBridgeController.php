<?php

declare(strict_types=1);

namespace Kode\Plugins\workflow;

use app\concerns\ApiResponse;
use app\concerns\PageWindow;
use app\concerns\RetentionGuard;
use app\concerns\ScanBudget;
use app\support\AtomicUpsert;
use app\support\Clip;
use app\support\ClientIp;
use app\support\DbClock;
use app\support\SysLog;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;

/**
 * WorkflowBridge 控制器：工作编排流（Schema events → backend workflow API）。
 *
 * 路由前缀：/api/workflow/*
 *
 * 核心能力：
 *  - 工作流定义 CRUD（admin，挂 JWT 鉴权 + workflow:view/workflow:edit）
 *  - 运行时执行（public，供前端 shell 触发）
 *  - 执行日志（admin 查看）
 *
 * 步骤类型：
 *  - api_call  — 调用内部 API（method + url + payload 支持变量替换）
 *  - transform — 数据转换（JSON Path 映射）
 *  - condition — 条件分支
 *  - log       — 记录日志（调试用）
 *
 * 变量替换语法：{{step_name.result.field}} 或 {{params.field}}
 */
final class WorkflowBridgeController extends Controller
{
    use ApiResponse;
    use PageWindow;
    use RetentionGuard;
    use ScanBudget;

    /**
     * 出参 id 混淆：内部自增 id → 对外 HashId 不透明串（AGENTS.md §4 防枚举）。
     * 入参一律用 eid()/HashId::dec 解码，服务层保持 int；值为 null 的字段保持 null
     * （如 parent_execution_id 非子执行记录为空，不得编码成 0 的 hashid）。
     */
    private static function hashOut(array $row, array $fields = ['id']): array
    {
        foreach ($fields as $f) {
            if (array_key_exists($f, $row) && $row[$f] !== null) {
                $row[$f] = \app\support\HashId::enc((int) $row[$f]);
            }
        }

        return $row;
    }

    /* ===================== 用户上下文 ===================== */

    private function owner(): string
    {
        $payload = Request::attr('auth');
        return (string) ($payload?->username ?? ($payload?->uid ?? ''));
    }

    private function traceId(): string
    {
        $headers = function_exists('getallheaders') ? (getallheaders() ?? []) : [];
        foreach (['X-Trace-Id', 'X-Trace-ID', 'x-trace-id'] as $key) {
            $val = $headers[$key] ?? ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] ?? '');
            if (is_string($val) && $val !== '') {
                // 按字符钳：trace_id 要绑进 VARCHAR(64)，半个汉字就是 22021。
                return Clip::chars($val, 64);
            }
        }
        return bin2hex(random_bytes(8));
    }

    /* ===================== 工作流定义 CRUD ===================== */

    /**
     * 凭证类字段：读侧出掩码、写侧认掩码（掩码 = 「这条没改」，保留库中原值）。
     *
     * 必须成对：admin-web 的工作流编辑表单是「读详情 → 回填 → 整对象 PUT 回去」
     * （views/kode/workflow-bridge/index.vue 把 data.webhook_secret 直接塞进 editForm 再原样提交）。
     * 只掩不认，用户改一次描述就把三条凭证静默抹掉，而下一轮读仍是掩码，页面上看不出凭证已被清空
     * ——AGENTS.md §4「掩码不只管展示，它划出一条写侧边界」。
     *
     * 掩码字面量与 PluginConfig 同源，不再在这个文件里写第二份星号。
     */
    private const SECRET_FIELDS = ['notify_secret', 'webhook_secret', 'alert_secret'];

    /**
     * 投递统计的分组明细最多扫这么多行（头部总数另走 SQL，见 webhookDeliveryStats）。
     */
    private const WEBHOOK_SAMPLE_ROWS = 2000;

    /**
     * 失败模式聚类最多扫这么多行（总数另走 SQL，见 failurePatterns）。
     *
     * 为什么不能像别的入口那样只靠 `limit` 参数放开：这一行读的是 `step_results_json`，
     * 一条长工作流的执行记录就是几十 KB 的 JSON，且模式归一化（`normalizeErrorPattern`）
     * 只在 PHP 里有一份判据 —— 搬进 SQL 等于写第二份（AGENTS.md §3「一个判定只许有一份实现」）。
     * 所以抽样是故意的，代价是抽样规模必须如实报出来。
     */
    private const PATTERN_SAMPLE_ROWS = 500;

    /**
     * 健康评分里「重试率」那一维**每个工作流**最多扫这么多行。
     *
     * 它比整表扫描的那一档（`ScanBudget::STEP_ROWS`）小，是因为这条在按工作流循环的体内：
     * 峰值内存 = 单个工作流的抽样规模（上一轮的 `rows` 在下一轮赋值时释放），
     * 400 行按实测最大行 ~38 KB 也就 ~15 MB，而评分只用到「重试步骤占比」这一个比值。
     */
    private const RETRY_ROWS_PER_WORKFLOW = 400;

    /**
     * 幂等键的「格式合法」判据（POSIX 正则，SQL 侧 `~` / `!~` 用）。
     *
     * 只此一份：这一列的合法形状此前写在 PHP 的 `preg_match` 里，
     * 而检测已经下沉进 SQL —— 两边各留一份时，改一处就是一半的行被算成畸形键。
     * 认的是 UUID（可带 `-数字` 后缀），与写侧 `execute()` 落库的键形状一致。
     */
    private const IDEMPOTENCY_KEY_FORMAT = '^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}(-[0-9]+)?$';

    /**
     * 一个重复组在明细里最多列几条成员。
     *
     * 「组内共几条」与「状态一不一致」由 SQL 在整个组上算（`COUNT(*)` / `COUNT(DISTINCT status)`），
     * 只有 id/时间这类明细清单受这一档约束 —— 否则一个被重放了十万次的键会把清单撑成第二份 OOM。
     */
    private const IDEMPOTENCY_MEMBERS_PER_GROUP = 20;

    /** 详情读侧的列白名单：新增列不会自动出现在响应里（凭证列也必须显式列出才会被掩码盖住）。 */
    private const DEFINITION_COLUMNS = 'id, name, description, steps_json, on_fail_json, step_count, version, status, created_by, created_at, updated_at, timeout_ms, schedule_cron, next_run_at, last_run_at, notify_url, notify_secret, webhook_secret, webhook_ips, max_concurrent, alert_url, alert_secret, notify_template, alert_template, circuit_breaker_threshold, circuit_breaker_count, circuit_breaker_open, circuit_breaker_opened_at, alert_memory_kb, alert_duration_ms, alert_step_failures';

    /** 读侧掩码：有凭证出掩码、没配出空串（空串与掩码可区分，前端据此显示「未设置」）。 */
    private static function maskSecrets(array $row): array
    {
        foreach (self::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = ((string) $row[$field] !== '') ? \app\support\PluginConfig::MASK : '';
            }
        }

        return $row;
    }

    /** 写侧认掩码：null 与掩码都算「未改动」（返回 null 让调用方跳过该列），其余原样落库。 */
    private static function secretPatch(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $value = trim((string) $raw);

        return $value === \app\support\PluginConfig::MASK ? null : $value;
    }

    /**
     * GET /api/workflow/definitions
     * 工作流列表（status/keyword 过滤 + page/size 分页，回统一分页信封）。
     */
    public function definitionsIndex()
    {
        $win = $this->pageWindow($this->query('page'), $this->query('size'));
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];

        $status = $this->query('status', null);
        $keyword = $this->query('keyword', null);

        $where = [];
        $bindings = [];
        if ($status !== null && $status !== '') {
            $where[] = 'status = ?';
            $bindings[] = (int) $status;
        }
        if ($keyword !== null && $keyword !== '') {
            $where[] = '(name ILIKE ? OR description ILIKE ?)';
            $kw = '%' . $keyword . '%';
            $bindings[] = $kw;
            $bindings[] = $kw;
        }
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        // 计数与行由同一份 $where/$bindings 算：此前是 `total = count($items)`，
        // 而 items 带着写死的 `LIMIT 200` —— 定义多于 200 条时列表静默只剩最近 200 条，
        // 且那个 total 数的是「这一批」，看起来像全量（口径同 §4「聚合不许按行抽样」）。
        $agg = Db::select("SELECT COUNT(*) AS total FROM kode_lowcode_workflows{$whereSql}", $bindings);
        $total = (int) ($agg[0]['total'] ?? 0);

        $items = Db::select(
            "SELECT id, name, description, status, step_count, version, created_by, created_at, updated_at,
                    circuit_breaker_threshold, circuit_breaker_count, circuit_breaker_open,
                    alert_memory_kb, alert_duration_ms, alert_step_failures
             FROM kode_lowcode_workflows{$whereSql}
             ORDER BY id DESC LIMIT ? OFFSET ?",
            [...$bindings, $size, $offset]
        );

        // 出参 id 混淆
        $items = array_map(static fn (array $row): array => self::hashOut($row), $items);

        return $this->page($items, $total, $page, $size);
    }

    /**
     * GET /api/workflow/definitions/{id}
     * 工作流详情（含完整 steps 定义）。
     */
    public function definitionShow()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('工作流不存在', 404);
        }
        $rows = Db::select("SELECT " . self::DEFINITION_COLUMNS . " FROM kode_lowcode_workflows WHERE id = ?", [$id]);
        if (empty($rows)) {
            return $this->error('工作流不存在', 404);
        }
        $item = $rows[0];
        $item['steps'] = json_decode($item['steps_json'] ?? '[]', true) ?? [];
        $item['on_fail_steps'] = json_decode($item['on_fail_json'] ?? '[]', true) ?? [];
        unset($item['steps_json'], $item['on_fail_json']);
        // 凭证只出掩码：这一行此前是 SELECT *，把 notify/webhook/alert 三段 HMAC 密钥连同
        // webhook_ips 白名单原样发给任何持 workflow:view 的账号（签名密钥外泄 = 任何人可伪造入站触发）。
        return $this->ok(self::hashOut(self::maskSecrets($item)));
    }

    /**
     * POST /api/workflow/definitions
     * 创建工作流定义。
     * Body: { name, description?, steps: [{ type, name, config: {...} }] }
     */
    public function definitionStore()
    {
        $name = trim((string) Request::input('name', ''));
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $name)) {
            return $this->error('name 必填，白名单字符集 [a-zA-Z0-9_-]，长度 2-64', 422);
        }

        $steps = (array) Request::input('steps', []);
        $validated = $this->validateSteps($steps);
        if ($validated !== null) {
            return $this->error($validated, 422);
        }

        $onFailSteps = (array) Request::input('on_fail_steps', []);
        if (!empty($onFailSteps)) {
            $failValidated = $this->validateSteps($onFailSteps);
            if ($failValidated !== null) {
                return $this->error("on_fail_steps 校验失败: {$failValidated}", 422);
            }
        }

        $description = trim((string) Request::input('description', ''));
        if (mb_strlen($description) > 1000) {
            return $this->error('description 超过 1000 字符限制', 422);
        }
        $timeoutMs = min(600000, max(100, (int) Request::input('timeout_ms', 30000)));
        $scheduleCron = trim((string) Request::input('schedule_cron', ''));
        $owner = $this->owner();

        // 如果设置了 schedule_cron，计算 next_run_at
        // cron 点位只能由进程算（epoch 秒），但落库那一段必须交给数据库解释：
        // 交一枚无时区串会让 next_run_at 随 δ 漂移 —— 建出来的定时任务当场就算「已到期」。
        $nextRun = null;
        if ($scheduleCron !== '') {
            $nextRun = $this->nextCronTime($scheduleCron, time());
        }

        $notifyUrl = trim((string) Request::input('notify_url', ''));
        $notifySecret = trim((string) Request::input('notify_secret', ''));
        $webhookSecret = trim((string) Request::input('webhook_secret', ''));
        $webhookIps = trim((string) Request::input('webhook_ips', ''));
        $maxConcurrent = min(100, max(0, (int) Request::input('max_concurrent', 5)));
        $alertUrl = trim((string) Request::input('alert_url', ''));
        $alertSecret = trim((string) Request::input('alert_secret', ''));
        $notifyTemplate = trim((string) Request::input('notify_template', ''));
        $alertTemplate = trim((string) Request::input('alert_template', ''));
        $cbThreshold = min(20, max(0, (int) Request::input('circuit_breaker_threshold', 3)));
        $alertMemory = min(1000000, max(0, (int) Request::input('alert_memory_kb', 0)));
        $alertDuration = min(3600000, max(0, (int) Request::input('alert_duration_ms', 0)));
        $alertSteps = min(100, max(0, (int) Request::input('alert_step_failures', 0)));

        // 新建没有「原值」可保留：掩码字面量存进去就是一条永远验不过签名的假凭证，
        // 却会在详情页显示成「已配置」。认掩码只属于更新路径（见 secretPatch）。
        foreach (self::SECRET_FIELDS as $secretField) {
            if (trim((string) Request::input($secretField, '')) === \app\support\PluginConfig::MASK) {
                return $this->error("{$secretField} 不能提交掩码值：新建工作流请留空或填入真实凭证", 422);
            }
        }

        // 无 cron 时 next_run_at 落 NULL（列定义允许）；有 cron 时那一枚瞬间交给数据库解释（口径同 definitionUpdate），
        // 所以 SQL 的 cron 那一格在 `NULL` 与 `to_timestamp(?)` 之间切换，绑定名单随之多一枚 ——
        // 占位符与绑定的**个数**必须始终相等，这正是把串直接写进 VALUES 的那版写法做不到的。
        $nextRunSlot = 'NULL';
        $nextRunBind = [];
        if ($nextRun !== null) {
            $instant = DbClock::instant($nextRun);
            $nextRunSlot = $instant['sql'];
            $nextRunBind = [$instant['binding']];
        }
        $lastId = Db::insert(
            "INSERT INTO kode_lowcode_workflows (name, description, steps_json, on_fail_json, step_count, status, timeout_ms, schedule_cron, next_run_at, created_by, notify_url, notify_secret, webhook_secret, webhook_ips, max_concurrent, alert_url, alert_secret, notify_template, alert_template, circuit_breaker_threshold, alert_memory_kb, alert_duration_ms, alert_step_failures)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, {$nextRunSlot}, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            array_merge([$name, $description, json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode($onFailSteps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), count($steps), $timeoutMs, $scheduleCron], $nextRunBind, [$owner, $notifyUrl, $notifySecret, $webhookSecret, $webhookIps, $maxConcurrent, $alertUrl, $alertSecret, $notifyTemplate, $alertTemplate, $cbThreshold, $alertMemory, $alertDuration, $alertSteps])
        );

        if (!is_numeric($lastId) || (int) $lastId <= 0) {
            return $this->error('创建失败', 500);
        }

        // 记录初始版本 1.0.0 快照
        $this->recordVersion((int) $lastId, $name, '1.0.0', $description, json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode($onFailSteps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $timeoutMs, $scheduleCron);

        $row = Db::select("SELECT id, name, description, step_count, status, version, created_by, created_at FROM kode_lowcode_workflows WHERE id = ?", [(int) $lastId]);
        return $this->ok(self::hashOut($row[0] ?? []), 201);
    }

    /**
     * PUT /api/workflow/definitions/{id}
     * 更新工作流定义。
     */
    public function definitionUpdate()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('工作流不存在', 404);
        }
        $rows = Db::select("SELECT id FROM kode_lowcode_workflows WHERE id = ?", [$id]);
        if (empty($rows)) {
            return $this->error('工作流不存在', 404);
        }

        $fields = [];
        $bindings = [];

        $name = Request::input('name', null);
        if ($name !== null) {
            $name = trim((string) $name);
            if ($name === '' || !preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $name)) {
                return $this->error('name 白名单字符集 [a-zA-Z0-9_-]，长度 2-64', 422);
            }
            $fields[] = 'name = ?';
            $bindings[] = $name;
        }

        $description = Request::input('description', null);
        if ($description !== null) {
            $description = trim((string) $description);
            if (mb_strlen($description) > 1000) {
                return $this->error('description 超过 1000 字符限制', 422);
            }
            $fields[] = 'description = ?';
            $bindings[] = $description;
        }

        $steps = Request::input('steps', null);
        if ($steps !== null) {
            $steps = (array) $steps;
            $validated = $this->validateSteps($steps);
            if ($validated !== null) {
                return $this->error($validated, 422);
            }
            $fields[] = 'steps_json = ?';
            $bindings[] = json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $fields[] = 'step_count = ?';
            $bindings[] = count($steps);
        }

        $onFailSteps = Request::input('on_fail_steps', null);
        if ($onFailSteps !== null) {
            $onFailSteps = (array) $onFailSteps;
            if (!empty($onFailSteps)) {
                $failValidated = $this->validateSteps($onFailSteps);
                if ($failValidated !== null) {
                    return $this->error("on_fail_steps 校验失败: {$failValidated}", 422);
                }
            }
            $fields[] = 'on_fail_json = ?';
            $bindings[] = json_encode($onFailSteps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $timeoutMs = Request::input('timeout_ms', null);
        if ($timeoutMs !== null) {
            $timeoutMs = min(600000, max(100, (int) $timeoutMs));
            $fields[] = 'timeout_ms = ?';
            $bindings[] = $timeoutMs;
        }

        $status = Request::input('status', null);
        if ($status !== null) {
            $fields[] = 'status = ?';
            $bindings[] = (int) $status;
        }

        $scheduleCron = Request::input('schedule_cron', null);
        if ($scheduleCron !== null) {
            $scheduleCron = trim((string) $scheduleCron);
            $fields[] = 'schedule_cron = ?';
            $bindings[] = $scheduleCron;
            // 如果设置/清空了 cron，更新 next_run_at（同一枚数据库时钟口径，见 definitionStore）
            if ($scheduleCron !== '') {
                $nextRun = $this->nextCronTime($scheduleCron, time());
                if ($nextRun === null) {
                    $fields[] = 'next_run_at = NULL';
                } else {
                    $instant = DbClock::instant($nextRun);
                    $fields[] = 'next_run_at = ' . $instant['sql'];
                    $bindings[] = $instant['binding'];
                }
            } else {
                $fields[] = 'next_run_at = NULL';
            }
        }

        // 新增字段：通知 + Webhook 配置（放在 empty 检查之前，避免只更新这些字段时被拦）
        $notifyUrl = Request::input('notify_url', null);
        if ($notifyUrl !== null) {
            $fields[] = 'notify_url = ?';
            $bindings[] = trim((string) $notifyUrl);
        }
        $notifySecret = self::secretPatch(Request::input('notify_secret', null));
        if ($notifySecret !== null) {
            $fields[] = 'notify_secret = ?';
            $bindings[] = $notifySecret;
        }
        $webhookSecret = self::secretPatch(Request::input('webhook_secret', null));
        if ($webhookSecret !== null) {
            $fields[] = 'webhook_secret = ?';
            $bindings[] = $webhookSecret;
        }
        $webhookIps = Request::input('webhook_ips', null);
        if ($webhookIps !== null) {
            $fields[] = 'webhook_ips = ?';
            $bindings[] = trim((string) $webhookIps);
        }
        $maxConcurrent = Request::input('max_concurrent', null);
        if ($maxConcurrent !== null) {
            $fields[] = 'max_concurrent = ?';
            $bindings[] = min(100, max(0, (int) $maxConcurrent));
        }
        $alertUrl = Request::input('alert_url', null);
        if ($alertUrl !== null) {
            $fields[] = 'alert_url = ?';
            $bindings[] = trim((string) $alertUrl);
        }
        $alertSecret = self::secretPatch(Request::input('alert_secret', null));
        if ($alertSecret !== null) {
            $fields[] = 'alert_secret = ?';
            $bindings[] = $alertSecret;
        }
        $notifyTemplate = Request::input('notify_template', null);
        if ($notifyTemplate !== null) {
            $fields[] = 'notify_template = ?';
            $bindings[] = trim((string) $notifyTemplate);
        }
        $alertTemplate = Request::input('alert_template', null);
        if ($alertTemplate !== null) {
            $fields[] = 'alert_template = ?';
            $bindings[] = trim((string) $alertTemplate);
        }
        $cbThreshold = Request::input('circuit_breaker_threshold', null);
        if ($cbThreshold !== null) {
            $fields[] = 'circuit_breaker_threshold = ?';
            $bindings[] = min(20, max(0, (int) $cbThreshold));
        }
        $alertMemory = Request::input('alert_memory_kb', null);
        if ($alertMemory !== null) {
            $fields[] = 'alert_memory_kb = ?';
            $bindings[] = min(1000000, max(0, (int) $alertMemory));
        }
        $alertDuration = Request::input('alert_duration_ms', null);
        if ($alertDuration !== null) {
            $fields[] = 'alert_duration_ms = ?';
            $bindings[] = min(3600000, max(0, (int) $alertDuration));
        }
        $alertSteps = Request::input('alert_step_failures', null);
        if ($alertSteps !== null) {
            $fields[] = 'alert_step_failures = ?';
            $bindings[] = min(100, max(0, (int) $alertSteps));
        }

        if (empty($fields)) {
            return $this->error('无更新字段', 422);
        }

        $fields[] = 'updated_at = NOW()';
        $bindings[] = $id;

        // 读-改-写：版本号递增 `SELECT version → explode('.') → minor+1 → UPDATE version = ?`
        // 从 SELECT 出的旧 version 计算新值再写回。READ COMMITTED 下两个并发的 definitionUpdate
        // 都会读到同一 minor、都写同一 minor+1——一个的版本增量被另一个覆盖（lost update）。
        // 用事务 + `FOR UPDATE` 行锁把「字段更新 → 读版本 → 递增 → 记录快照」整包成关键区；
        // 后到的事务等前者提交后再读版本，就拿到最新 minor。
        // 同 `budgetSave` 的加固、同 `app/admin/controllers/ConfigController::set()`
        // （见 tests/ReadWriteConcurrentUpdateGuardTest）。
        $newVersion = null;
        try {
            Db::transaction(function () use ($id, $fields, $bindings, &$newVersion): void {
                // 先锁行，锁内所有后续 SELECT 都是 fresh committed 值
                $locked = Db::select("SELECT id FROM kode_lowcode_workflows WHERE id = ? LIMIT 1 FOR UPDATE", [$id]);
                if ($locked === []) {
                    throw new \RuntimeException("workflow {$id} not found");
                }

                Db::update(
                    "UPDATE kode_lowcode_workflows SET " . implode(', ', $fields) . " WHERE id = ?",
                    $bindings
                );

                // 递增版本号
                $currentVersion = (string) Db::select("SELECT version FROM kode_lowcode_workflows WHERE id = ?", [$id])[0]['version'];
                $parts = explode('.', $currentVersion);
                $minor = isset($parts[1]) ? ((int) $parts[1]) : 0;
                $newVersion = '1.' . ($minor + 1) . '.0';
                Db::update("UPDATE kode_lowcode_workflows SET version = ? WHERE id = ?", [$newVersion, $id]);

                // 记录版本快照
                $latest = Db::select("SELECT name, description, steps_json, on_fail_json, timeout_ms, schedule_cron FROM kode_lowcode_workflows WHERE id = ?", [$id])[0];
                $this->recordVersion($id, $latest['name'], $newVersion, $latest['description'], $latest['steps_json'], $latest['on_fail_json'], (int) $latest['timeout_ms'], $latest['schedule_cron']);
            });
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'workflow ')) {
                return $this->error('工作流不存在', 404);
            }
            throw $e;
        }

        $row = Db::select("SELECT id, name, description, step_count, status, version, created_by, created_at, updated_at, schedule_cron, notify_url, webhook_ips, circuit_breaker_threshold, circuit_breaker_count, circuit_breaker_open, alert_memory_kb, alert_duration_ms, alert_step_failures FROM kode_lowcode_workflows WHERE id = ?", [$id]);
        return $this->ok(self::hashOut($row[0] ?? []));
    }

    /**
     * 主表删除前级联清理「配置类」从表。
     *
     * 配置类（同生命周期，主表删了就没意义，且没有独立 DELETE endpoint 兜底）：
     *   - kode_lowcode_workflow_budgets
     *   - kode_lowcode_workflow_alert_rules
     *   - kode_lowcode_workflow_sla_targets
     *
     * 审计类（有独立追溯价值，保留）：
     *   - kode_lowcode_workflow_versions / _executions / _alerts / _webhook_logs / _step_regressions
     *
     * 走 workflow_name 关联，而非 id——从表列名是 name 字符串（与主表 name 一致），
     * 不是外键 id。若某天加了真正的 FK，这一层可以退化成触发器。
     */
    private function deleteConfigChildrenByName(string $name): void
    {
        if ($name === '') {
            return;
        }
        Db::statement("DELETE FROM kode_lowcode_workflow_budgets WHERE workflow_name = ?", [$name]);
        Db::statement("DELETE FROM kode_lowcode_workflow_alert_rules WHERE workflow_name = ?", [$name]);
        Db::statement("DELETE FROM kode_lowcode_workflow_sla_targets WHERE workflow_name = ?", [$name]);
    }

    /**
     * DELETE /api/workflow/definitions/{id}
     * 删除工作流定义。
     */
    public function definitionDestroy()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('工作流不存在', 404);
        }
        $rows = Db::select("SELECT id, name FROM kode_lowcode_workflows WHERE id = ?", [$id]);
        if (empty($rows)) {
            return $this->error('工作流不存在', 404);
        }
        // 级联+主表包在同一事务里：三步删任何一步失败都必须整体回滚，否则会出现
        // "主表删了但配置类从表漏掉"或"从表删了但主表还在"的部分状态。
        Db::transaction(function () use ($id, $rows): void {
            $this->deleteConfigChildrenByName((string) $rows[0]['name']);
            Db::delete("DELETE FROM kode_lowcode_workflows WHERE id = ?", [$id]);
        });
        // 回显对外不透明串，不外泄内部 int
        return $this->ok(['deleted' => \app\support\HashId::enc($id)]);
    }

    /**
     * POST /api/workflow/definitions/{id}/clone
     * 克隆工作流定义。
     *
     * 复制所有配置，生成新名称（原名_copy 或指定名称）。
     */
    public function definitionClone()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('工作流不存在', 404);
        }
        $rows = Db::select("SELECT * FROM kode_lowcode_workflows WHERE id = ?", [$id]);
        if (empty($rows)) {
            return $this->error('工作流不存在', 404);
        }

        $src = $rows[0];
        $newName = trim((string) Request::input('name', ''));
        if ($newName !== '') {
            if (!preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $newName)) {
                return $this->error('name 白名单字符集 [a-zA-Z0-9_-]，长度 2-64', 422);
            }
            $exists = Db::select("SELECT id FROM kode_lowcode_workflows WHERE name = ?", [$newName]);
            if (!empty($exists)) {
                return $this->error("工作流 '{$newName}' 已存在", 409);
            }
        } else {
            $newName = $src['name'] . '_copy';
            // 如果名称已存在，追加数字后缀
            $existing = Db::select("SELECT id FROM kode_lowcode_workflows WHERE name = ?", [$newName]);
            if (!empty($existing)) {
                $i = 1;
                while (true) {
                    $newName = $src['name'] . "_copy{$i}";
                    $existing = Db::select("SELECT id FROM kode_lowcode_workflows WHERE name = ?", [$newName]);
                    if (empty($existing)) break;
                    $i++;
                }
            }
        }

        $owner = $this->owner();
        $lastId = Db::insert(
            "INSERT INTO kode_lowcode_workflows (name, description, steps_json, on_fail_json, step_count, status, timeout_ms, schedule_cron, next_run_at, created_by, notify_url, notify_secret, webhook_secret, webhook_ips, max_concurrent, alert_url, alert_secret, notify_template, alert_template)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $newName,
                $src['description'] . ' (克隆)',
                $src['steps_json'],
                $src['on_fail_json'],
                $src['step_count'],
                $src['status'],
                $src['timeout_ms'],
                $src['schedule_cron'],
                null, // 新克隆不继承调度
                $owner,
                $src['notify_url'],
                $src['notify_secret'],
                $src['webhook_secret'],
                $src['webhook_ips'],
                $src['max_concurrent'],
                $src['alert_url'],
                $src['alert_secret'],
                $src['notify_template'],
                $src['alert_template'],
            ]
        );

        if (!is_numeric($lastId) || (int) $lastId <= 0) {
            return $this->error('克隆失败', 500);
        }

        // 记录初始版本快照
        $this->recordVersion((int) $lastId, $newName, '1.0.0', $src['description'] . ' (克隆)', $src['steps_json'], $src['on_fail_json'], (int) $src['timeout_ms'], $src['schedule_cron']);

        $row = Db::select("SELECT id, name, description, step_count, status, version, created_by, created_at FROM kode_lowcode_workflows WHERE id = ?", [(int) $lastId]);
        return $this->ok(self::hashOut($row[0] ?? []), 201);
    }

    /**
     * POST /api/workflow/definitions/batch
     * 批量操作：启用/停用/删除多个工作流。
     *
     * body: { ids: [hashid,...], action: 'enable' | 'disable' | 'delete' }
     */
    public function definitionBatch()
    {
        $ids = (array) Request::input('ids', []);
        $action = trim((string) Request::input('action', ''));

        if (empty($ids)) {
            return $this->error('ids 不能为空', 422);
        }
        if (!in_array($action, ['enable', 'disable', 'delete'], true)) {
            return $this->error("action 必须为 enable/disable/delete，当前: {$action}");
        }

        // 入参 ids 为对外 HashId 串（兼容直接传内部 int）；保留 原串+内部int 配对用于回显
        $idMap = [];
        foreach ($ids as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            $decoded = ctype_digit($raw) ? (int) $raw : \app\support\HashId::dec($raw);
            if ($decoded !== null && $decoded > 0 && !isset($idMap[$decoded])) {
                $idMap[$decoded] = $raw;
            }
        }
        if (empty($idMap)) {
            return $this->error('ids 无效', 422);
        }

        $results = [];

        foreach ($idMap as $id => $opaque) {
            try {
                $row = Db::select("SELECT id, name FROM kode_lowcode_workflows WHERE id = ?", [$id]);
                if (empty($row)) {
                    $results[] = ['id' => $opaque, 'name' => '', 'action' => $action, 'status' => 'not_found'];
                    continue;
                }
                $name = $row[0]['name'];

                switch ($action) {
                    case 'enable':
                        Db::update("UPDATE kode_lowcode_workflows SET status = 1, updated_at = NOW() WHERE id = ?", [$id]);
                        $results[] = ['id' => $opaque, 'name' => $name, 'action' => 'enable', 'status' => 'ok'];
                        break;
                    case 'disable':
                        Db::update("UPDATE kode_lowcode_workflows SET status = 0, updated_at = NOW() WHERE id = ?", [$id]);
                        $results[] = ['id' => $opaque, 'name' => $name, 'action' => 'disable', 'status' => 'ok'];
                        break;
                    case 'delete':
                        // 单条删除也走同一事务边界：批量循环里每条自成一段，事务保证
                        // 单条失败不影响同批次其他条（每条一个独立事务）。
                        Db::transaction(function () use ($id, $name): void {
                            $this->deleteConfigChildrenByName((string) $name);
                            Db::delete("DELETE FROM kode_lowcode_workflows WHERE id = ?", [$id]);
                        });
                        $results[] = ['id' => $opaque, 'name' => $name, 'action' => 'delete', 'status' => 'ok'];
                        break;
                }
            } catch (\Throwable $e) {
                $results[] = ['id' => $opaque, 'name' => '', 'action' => $action, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        $okCount = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
        $errorCount = count(array_filter($results, fn($r) => $r['status'] !== 'ok'));

        return $this->ok([
            'action' => $action,
            'total' => count($idMap),
            'succeeded' => $okCount,
            'failed' => $errorCount,
            'results' => $results,
        ]);
    }

    /* ===================== 工作流模板库 ===================== */

    /**
     * 内置工作流模板（5 个常用模式）。
     */
    private function getTemplates(): array
    {
        return [
        'login_flow' => [
            'name' => 'login_flow',
            'description' => '登录流程：验证凭据 → 获取 Token → 记录日志',
            'steps' => [
                ['type' => 'api_call', 'name' => 'verify_credentials', 'config' => ['url' => '{{params.verify_url}}', 'method' => 'POST', 'payload' => ['username' => '{{params.username}}', 'password' => '{{params.password}}'], 'timeout' => 10, 'max_retries' => 1]],
                ['type' => 'transform', 'name' => 'extract_token', 'config' => ['mappings' => [['from' => '{{verify_credentials.data}}', 'to' => 'token'], ['from' => '{{timestamp}}', 'to' => 'login_time']]]],
                ['type' => 'log', 'name' => 'login_log', 'config' => ['message' => '用户登录成功: {{params.username}}, token={{extract_token.token}}']],
            ],
            'on_fail_steps' => [
                ['type' => 'log', 'name' => 'login_fail_log', 'config' => ['message' => '登录失败: {{error}}']],
            ],
        ],
        'order_flow' => [
            'name' => 'order_flow',
            'description' => '订单流程：创建订单 → 扣减库存 → 发送通知（失败时回滚）',
            'steps' => [
                ['type' => 'api_call', 'name' => 'create_order', 'config' => ['url' => '{{params.order_api}}', 'method' => 'POST', 'payload' => ['user_id' => '{{params.user_id}}', 'items' => '{{params.items}}'], 'timeout' => 15, 'max_retries' => 2]],
                ['type' => 'api_call', 'name' => 'deduct_stock', 'config' => ['url' => '{{params.stock_api}}', 'method' => 'POST', 'payload' => ['order_id' => '{{create_order.data.order_id}}'], 'timeout' => 10, 'max_retries' => 1]],
                ['type' => 'api_call', 'name' => 'send_notification', 'config' => ['url' => '{{params.notify_api}}', 'method' => 'POST', 'payload' => ['user_id' => '{{params.user_id}}', 'message' => '订单 {{create_order.data.order_id}} 已创建'], 'timeout' => 5]],
            ],
            'on_fail_steps' => [
                ['type' => 'api_call', 'name' => 'rollback_order', 'config' => ['url' => '{{params.order_api}}/cancel', 'method' => 'POST', 'payload' => ['order_id' => '{{create_order.data.order_id}}'], 'timeout' => 10]],
                ['type' => 'log', 'name' => 'order_fail_log', 'config' => ['message' => '订单流程失败: {{error}}，已执行回滚']],
            ],
        ],
        'payment_flow' => [
            'name' => 'payment_flow',
            'description' => '支付流程：创建支付 → 等待回调 → 确认收款（失败时退款）',
            'steps' => [
                ['type' => 'api_call', 'name' => 'create_payment', 'config' => ['url' => '{{params.pay_api}}', 'method' => 'POST', 'payload' => ['amount' => '{{params.amount}}', 'currency' => 'CNY', 'order_id' => '{{params.order_id}}'], 'timeout' => 15, 'max_retries' => 2]],
                ['type' => 'delay', 'name' => 'wait_callback', 'config' => ['seconds' => 1]],
                ['type' => 'api_call', 'name' => 'confirm_payment', 'config' => ['url' => '{{params.pay_api}}/confirm', 'method' => 'GET', 'timeout' => 10]],
            ],
            'on_fail_steps' => [
                ['type' => 'api_call', 'name' => 'refund_payment', 'config' => ['url' => '{{params.pay_api}}/refund', 'method' => 'POST', 'payload' => ['payment_id' => '{{create_payment.data.id}}'], 'timeout' => 10]],
            ],
        ],
        'notification_flow' => [
            'name' => 'notification_flow',
            'description' => '通知流程：构建消息 → 多渠道发送（loop） → 记录日志',
            'steps' => [
                ['type' => 'transform', 'name' => 'build_message', 'config' => ['mappings' => [['from' => '{{params.title}}', 'to' => 'title'], ['from' => '{{params.content}}', 'to' => 'content'], ['from' => '{{timestamp}}', 'to' => 'sent_at']]]],
                ['type' => 'loop', 'name' => 'send_channels', 'config' => ['source' => '{{params.channels}}', 'item' => 'channel', 'steps' => [['type' => 'api_call', 'name' => 'send_notify', 'config' => ['url' => '{{params.notify_api}}', 'method' => 'POST', 'payload' => ['channel' => '{{channel}}', 'message' => '{{build_message.title}}: {{build_message.content}}'], 'timeout' => 5, 'max_retries' => 1]]]]],
                ['type' => 'log', 'name' => 'notify_log', 'config' => ['message' => '通知发送完成，共 {{send_channels.count}} 个渠道']],
            ],
            'on_fail_steps' => [],
        ],
        'data_sync_flow' => [
            'name' => 'data_sync_flow',
            'description' => '数据同步：拉取远程数据 → loop 批量写入 → 校验结果',
            'steps' => [
                ['type' => 'api_call', 'name' => 'fetch_remote', 'config' => ['url' => '{{params.source_url}}', 'method' => 'GET', 'timeout' => 30, 'max_retries' => 2, 'response_path' => '{{params.response_path}}']],
                ['type' => 'loop', 'name' => 'sync_items', 'config' => ['source' => '{{fetch_remote.data}}', 'item' => 'record', 'steps' => [['type' => 'api_call', 'name' => 'write_local', 'config' => ['url' => '{{params.target_api}}', 'method' => 'POST', 'payload' => '{{record}}', 'timeout' => 5, 'max_retries' => 1]]]]],
                ['type' => 'log', 'name' => 'sync_log', 'config' => ['message' => '数据同步完成，共 {{sync_items.count}} 条记录']],
            ],
            'on_fail_steps' => [
                ['type' => 'log', 'name' => 'sync_fail_log', 'config' => ['message' => '数据同步失败: {{error}}']],
            ],
        ],
    ];
    }

    /**
     * GET /api/workflow/templates
     * 获取工作流模板列表。
     */
    public function templatesList()
    {
        $items = [];
        foreach ($this->getTemplates() as $key => $tpl) {
            $items[] = [
                'key' => $key,
                'name' => $tpl['name'],
                'description' => $tpl['description'],
                'step_count' => count($tpl['steps']),
                'has_on_fail' => !empty($tpl['on_fail_steps']),
            ];
        }
        return $this->ok($items);
    }

    /**
     * GET /api/workflow/templates/{name}
     * 获取模板详情（含完整 steps 定义）。
     */
    public function templateShow()
    {
        $name = (string) $this->param('name', '');
        $tpl = $this->getTemplates()[$name] ?? null;
        if ($tpl === null) {
            return $this->error("模板 '{$name}' 不存在", 404);
        }
        return $this->ok($tpl);
    }

    /**
     * POST /api/workflow/templates/{name}/instantiate
     * 按模板实例化工作流（可指定名称和描述）。
     *
     * Body: { name?: "自定义名称", description?: "说明" }
     */
    public function templateInstantiate()
    {
        $name = (string) $this->param('name', '');
        $tpl = $this->getTemplates()[$name] ?? null;
        if ($tpl === null) {
            return $this->error("模板 '{$name}' 不存在", 404);
        }

        $newName = trim((string) Request::input('name', $tpl['name']));
        if (!preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $newName)) {
            return $this->error('name 白名单字符集 [a-zA-Z0-9_-]，长度 2-64', 422);
        }

        $exists = Db::select("SELECT id FROM kode_lowcode_workflows WHERE name = ?", [$newName]);
        if (!empty($exists)) {
            return $this->error("工作流 '{$newName}' 已存在", 409);
        }

        $description = trim((string) Request::input('description', $tpl['description']));
        if (mb_strlen($description) > 1000) {
            return $this->error('description 超过 1000 字符限制', 422);
        }
        $owner = $this->owner();

        $lastId = Db::insert(
            "INSERT INTO kode_lowcode_workflows (name, description, steps_json, on_fail_json, step_count, status, timeout_ms, created_by)
             VALUES (?, ?, ?, ?, ?, 1, 30000, ?)",
            [
                $newName,
                $description,
                json_encode($tpl['steps'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($tpl['on_fail_steps'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                count($tpl['steps']),
                $owner,
            ]
        );

        if (!is_numeric($lastId) || (int) $lastId <= 0) {
            return $this->error('实例化失败', 500);
        }

        $row = Db::select("SELECT id, name, description, step_count, status, version, created_by, created_at FROM kode_lowcode_workflows WHERE id = ?", [(int) $lastId]);
        return $this->ok(self::hashOut($row[0] ?? []), 201);
    }

    /* ===================== 步骤类型元数据（前端可视化编辑器） ===================== */

    /**
     * GET /api/workflow/step-types
     * 返回步骤类型元数据（含配置 schema），供前端可视化编辑器使用。
     */
    public function stepTypes()
    {
        $types = array_values($this->stepTypeCatalog());

        return $this->ok($types);
    }

    /**
     * 步骤类型目录（纯数据）。
     *
     * 单独成方法是为了让测试能把它和 validateSteps() 对一遍口径：编辑器照着这份 schema
     * 出输入框，校验器照着另一份数字拒收 —— 两边各写一遍，迟早只改对一个
     * （此前 delay.seconds 标着 1..3600，校验器却只放行 0..5）。
     *
     * @return array<string, array<string, mixed>>
     */
    private function stepTypeCatalog(): array
    {
        return [
            'api_call' => [
                'type' => 'api_call',
                'label' => 'API 调用',
                'icon' => '🌐',
                'description' => '调用内部或外部 HTTP API，支持 GET/POST/PUT/PATCH/DELETE，支持变量替换',
                'config_schema' => [
                    'url' => ['type' => 'text', 'required' => true, 'placeholder' => 'https://api.example.com/resource 或 {{params.url}}', 'label' => 'URL'],
                    'method' => ['type' => 'select', 'required' => false, 'default' => 'GET', 'options' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'label' => 'HTTP 方法'],
                    'payload' => ['type' => 'json', 'required' => false, 'placeholder' => '{"key": "{{params.value}}"}', 'label' => '请求体 (JSON)'],
                    'headers' => ['type' => 'json', 'required' => false, 'placeholder' => '{"Authorization": "***"}}"}', 'label' => '自定义头 (JSON)'],
                    'timeout' => ['type' => 'number', 'default' => 30, 'min' => 1, 'max' => 60, 'label' => '超时 (秒)'],
                    'max_retries' => ['type' => 'number', 'default' => 0, 'min' => 0, 'max' => 3, 'label' => '最大重试次数'],
                    'backoff_ms' => ['type' => 'number', 'default' => 1000, 'min' => 100, 'max' => 5000, 'label' => '退避间隔 (ms)'],
                    'response_path' => ['type' => 'text', 'required' => false, 'placeholder' => 'user.name (提取嵌套字段)', 'label' => '响应路径提取'],
                ],
                'default_config' => ['url' => '', 'method' => 'GET', 'timeout' => 30, 'max_retries' => 0],
            ],
            'transform' => [
                'type' => 'transform',
                'label' => '数据转换',
                'icon' => '🔄',
                'description' => '将前序步骤输出映射到新变量，支持变量替换和路径引用',
                'config_schema' => [
                    'mappings' => ['type' => 'mappings', 'label' => '映射列表 (from → to)'],
                ],
                'default_config' => ['mappings' => [['from' => '{{params.key}}', 'to' => 'target_key']]],
            ],
            'condition' => [
                'type' => 'condition',
                'label' => '条件分支',
                'icon' => '🔀',
                'description' => '条件判断：字面量（字符串/数字/true/false/null）+ == != === !== > < >= <= + && || ! 与括号',
                'config_schema' => [
                    'condition' => ['type' => 'text', 'required' => true, 'placeholder' => "{{step.field}} == 'value'", 'label' => '条件表达式'],
                ],
                'default_config' => ['condition' => '{{params.value}} == 1'],
            ],
            'log' => [
                'type' => 'log',
                'label' => '日志',
                'icon' => '📝',
                'description' => '记录执行日志，支持变量替换',
                'config_schema' => [
                    'message' => ['type' => 'text', 'required' => true, 'placeholder' => '操作完成: {{params.name}}', 'label' => '日志消息'],
                ],
                'default_config' => ['message' => '步骤执行完成'],
            ],
            'loop' => [
                'type' => 'loop',
                'label' => '循环',
                'icon' => '🔁',
                'description' => '遍历数组执行子步骤，支持嵌套 api_call / transform / log',
                'config_schema' => [
                    'source' => ['type' => 'text', 'required' => true, 'placeholder' => '{{params.list}} 或 {{step.data}}', 'label' => '数据源'],
                    'item' => ['type' => 'text', 'default' => 'item', 'label' => '循环变量名'],
                    'steps' => ['type' => 'json', 'required' => false, 'label' => '子步骤 (JSON)'],
                ],
                'default_config' => ['source' => '{{params.list}}', 'item' => 'item', 'steps' => []],
            ],
            'delay' => [
                'type' => 'delay',
                'label' => '延迟',
                'icon' => '⏱️',
                'description' => '暂停执行指定秒数，用于等待回调或限流；工作流预算用尽时会提前结束等待',
                'config_schema' => [
                    'seconds' => ['type' => 'number', 'default' => 1, 'min' => 0, 'max' => 5, 'label' => '延迟秒数'],
                ],
                'default_config' => ['seconds' => 1],
            ],
            'sub_workflow' => [
                'type' => 'sub_workflow',
                'label' => '子工作流',
                'icon' => '📦',
                'description' => '调用另一个工作流，传递参数并获取结果',
                'config_schema' => [
                    'workflow' => ['type' => 'text', 'required' => true, 'placeholder' => '子工作流名称', 'label' => '工作流名称'],
                    'params' => ['type' => 'json', 'required' => false, 'label' => '参数 (JSON)'],
                    'timeout_ms' => ['type' => 'number', 'default' => 30000, 'label' => '超时 (ms)'],
                ],
                'default_config' => ['workflow' => '', 'params' => [], 'timeout_ms' => 30000],
            ],
            'switch' => [
                'type' => 'switch',
                'label' => '多路分支',
                'icon' => '🔀',
                'description' => '根据变量值匹配不同分支，类似 switch-case',
                'config_schema' => [
                    'variable' => ['type' => 'text', 'required' => true, 'placeholder' => '{{params.status}}', 'label' => '变量'],
                    'cases' => ['type' => 'json', 'required' => true, 'label' => '分支列表 (JSON)'],
                ],
                'default_config' => ['variable' => '{{params.status}}', 'cases' => []],
            ],
        ];
    }

    /* ===================== 运行时执行（public） ===================== */

    /**
     * POST /api/workflow/execute
     * 工作流执行入口（前端 shell 触发，无登录态）。
     *
     * Body: { workflow, params: {...} }
     *
     * 执行流程：
     *  1) 按 name 查找工作流定义
     *  2) 逐步执行 steps（api_call / transform / condition / log）
     *  3) 记录执行日志到 kode_lowcode_workflow_executions
     *  4) 返回执行结果（含每步输出）
     */
    public function execute()
    {
        $workflowName = trim((string) Request::input('workflow', ''));
        if ($workflowName === '') {
            return $this->error('workflow 不能为空', 422);
        }

        $params = (array) Request::input('params', []);
        $traceId = $this->traceId();
        $idempotencyKey = trim((string) Request::input('idempotency_key', ''));

        // 幂等检查：相同 idempotency_key + workflow_name 的重复请求返回第一次结果
        if ($idempotencyKey !== '') {
            $existing = Db::select(
                "SELECT id, workflow_name, status, step_results_json, input_json, duration_ms, error, trace_id, created_at
                 FROM kode_lowcode_workflow_executions
                 WHERE idempotency_key = ? AND workflow_name = ?
                 ORDER BY id DESC LIMIT 1",
                [$idempotencyKey, $workflowName]
            );
            if (!empty($existing)) {
                $r = $existing[0];
                $stepResults = json_decode($r['step_results_json'] ?? '{}', true) ?? [];
                $input = json_decode($r['input_json'] ?? '{}', true) ?? [];
                return $this->ok([
                    'id' => \app\support\HashId::enc((int) $r['id']),
                    'workflow' => $r['workflow_name'],
                    'status' => $r['status'],
                    'trace_id' => $r['trace_id'],
                    'duration_ms' => (int) $r['duration_ms'],
                    'step_count' => (int) ($r['step_count'] ?? count($stepResults)),
                    'error' => $r['error'],
                    'input' => $input,
                    'steps' => $stepResults,
                    'idempotency_key' => $idempotencyKey,
                    'replayed' => true,
                ]);
            }
        }

        $result = $this->executeWorkflowInternal($workflowName, $params, $traceId, 0, 0);

        // 记录幂等键（如果有的话）
        $execId = (int) ($result['id'] ?? 0);
        if ($idempotencyKey !== '' && $execId > 0) {
            Db::update(
                "UPDATE kode_lowcode_workflow_executions SET idempotency_key = ? WHERE id = ?",
                [$idempotencyKey, $execId]
            );
            $result['idempotency_key'] = $idempotencyKey;
        }

        // 出参执行 id 混淆（内部引擎保持 int，仅在响应边界编码）
        if (array_key_exists('id', $result)) {
            $result['id'] = \app\support\HashId::enc((int) $result['id']);
        }

        return $this->ok($result);
    }

    /**
     * 执行上下文的唯一拼装点：params 之外恒种两枚溯源键。
     *
     * 少了这两枚，log 步骤落进 kode_system_logs 的那一行就只有内容、没有来源
     * （「是谁说的、哪一趟说的」只能躺在 context 里，系统日志页按 workflow 筛就是筛不到），
     * 而重放/单步重试/批量重试/定时四条入口各自拼过一遍上下文 —— 拼装点有两份就会只改对一个。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function stepContext(string $workflowName, array $params, string $traceId): array
    {
        return [
            'params' => $params,
            '__workflow_name' => $workflowName,
            '__trace_id' => $traceId,
        ];
    }

    /**
     * 工作流核心执行逻辑（支持递归调用，供 sub_workflow 步骤复用）。
     *
     * @param string $workflowName  工作流名称
     * @param array  $params        输入参数
     * @param string $traceId       追踪 ID
     * @param int    $depth         递归深度（0 = 顶层，最大 3）
     * @param int    $parentDeadline 父级截止时间（microtime，0 表示无限制）
     * @return array 执行结果
     */
    private function executeWorkflowInternal(
        string $workflowName,
        array $params,
        string $traceId,
        int $depth = 0,
        float $parentDeadline = 0,
        bool $sandbox = false
    ): array {
        // 深度检查
        if ($depth > 3) {
            $this->logExecution($workflowName, 0, $params, null, 'failed', $traceId, 0, "sub_workflow 深度超过限制 (max 3), 当前: {$depth}");
            return [
                'id' => 0,
                'workflow' => $workflowName,
                'status' => 'failed',
                'trace_id' => $traceId,
                'duration_ms' => 0,
                'steps' => [],
                'error' => "sub_workflow 深度超过限制 (max 3)",
            ];
        }

        $startedAt = microtime(true);

        // 查找工作流定义
        $rows = Db::select(
            "SELECT * FROM kode_lowcode_workflows WHERE name = ? AND status = 1",
            [$workflowName]
        );
        if (empty($rows)) {
            $this->logExecution($workflowName, 0, $params, null, 'not_found', $traceId, 0, '工作流不存在或未启用');
            return [
                'id' => 0,
                'workflow' => $workflowName,
                'status' => 'failed',
                'trace_id' => $traceId,
                'duration_ms' => 0,
                'steps' => [],
                'error' => "工作流 '{$workflowName}' 不存在或未启用",
            ];
        }

        $workflow = $rows[0];
        $steps = json_decode($workflow['steps_json'] ?? '[]', true) ?? [];
        $timeoutMs = (int) ($workflow['timeout_ms'] ?? 30000);
        $onFailSteps = json_decode($workflow['on_fail_json'] ?? '[]', true) ?? [];

        // 熔断器检查（仅顶层执行）
        if ($depth === 0 && !empty($workflow['circuit_breaker_open'])) {
            $this->logExecution($workflowName, (int) $workflow['id'], $params, null, 'rejected', $traceId, 0, '熔断器开启中，工作流已自动停用');
            return [
                'id' => 0,
                'workflow' => $workflowName,
                'status' => 'rejected',
                'trace_id' => $traceId,
                'duration_ms' => 0,
                'steps' => [],
                'error' => '熔断器开启中（连续失败达阈值），请手动重置或等待自动恢复',
                'circuit_breaker' => true,
            ];
        }

        // 并发控制检查（仅顶层执行，max_concurrent=0 表示不限制）
        $maxConcurrent = (int) ($workflow['max_concurrent'] ?? 5);
        if ($depth === 0 && $maxConcurrent > 0) {
            $activeCount = (int) (Db::select(
                "SELECT COUNT(*) as cnt FROM kode_lowcode_workflow_executions
                 WHERE workflow_name = ? AND status = 'running'
                   AND created_at > NOW() - INTERVAL '2 minutes'",
                [$workflowName]
            )[0]['cnt'] ?? 0);
            if ($activeCount >= $maxConcurrent) {
                $this->logExecution($workflowName, (int) $workflow['id'], $params, null, 'rejected', $traceId, 0, "并发超限: 当前 {$activeCount}/{$maxConcurrent}");
                return [
                    'id' => 0,
                    'workflow' => $workflowName,
                    'status' => 'rejected',
                    'trace_id' => $traceId,
                    'duration_ms' => 0,
                    'steps' => [],
                    'error' => "并发执行数已达上限 ({$activeCount}/{$maxConcurrent})",
                ];
            }
        }

        // 计算截止时间：取父级 deadline 和自身 timeout 的较小值
        $ownDeadline = $startedAt + ($timeoutMs / 1000);
        $deadline = $parentDeadline > 0 ? min($ownDeadline, $parentDeadline) : $ownDeadline;

        // 上下文：每步的输出存这里，后续步骤通过 {{step_name.field}} 引用
        $context = self::stepContext($workflowName, $params, $traceId);
        $stepResults = [];
        $error = null;
        $timedOut = false;

        // 预插入执行记录（status='running'，仅顶层），用于并发计数
        $executionId = 0;
        if ($depth === 0) {
            $executionId = $this->logExecution($workflowName, (int) $workflow['id'], $params, null, 'running', $traceId, 0, '');
        }

        foreach ($steps as $step) {
            // 超时检查（每个步骤前）
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                $error = "工作流超时 ({$timeoutMs}ms)";
                break;
            }

            $stepName = (string) ($step['name'] ?? '');
            $stepType = (string) ($step['type'] ?? '');
            $stepConfig = (array) ($step['config'] ?? []);

            try {
                $result = $this->executeStep($stepType, $stepConfig, $context, $depth, $deadline, $sandbox);
                $stepResults[$stepName] = $result;

                // 存储到上下文（后续步骤可引用）
                $context[$stepName] = ['result' => $result];

                // 这一步成没成只问那一个判据：HTTP 5xx 的结果压根不带 error 键，
                // `isset($result['error'])` 会把它读成成功，于是「先扣款后通知」的编排里
                // 扣款没成功也照样发了通知，而执行记录写着 succeeded。
                [, $stepFailed] = self::stepOutcome($result);

                // 执行步骤级 on_fail 恢复步骤
                if ($stepFailed && !empty($step['on_fail'])) {
                    $failResults = $this->executeFailSteps((array) $step['on_fail'], $context, $depth, $deadline, $stepName);
                    $stepResults[$stepName]['on_fail_results'] = $failResults;
                }

                // 检查是否需要停止（默认 on_error='stop'）
                if (($step['on_error'] ?? 'stop') === 'stop' && $stepFailed) {
                    $error = self::stepFailureText($result);
                    break;
                }
            } catch (\Throwable $e) {
                $stepResults[$stepName] = ['error' => $e->getMessage()];
                $error = "step '{$stepName}' failed: " . $e->getMessage();

                // 执行步骤级 on_fail 恢复步骤
                if (!empty($step['on_fail'])) {
                    $failResults = $this->executeFailSteps((array) $step['on_fail'], $context, $depth, $deadline, $stepName);
                    $stepResults[$stepName]['on_fail_results'] = $failResults;
                }

                if (($step['on_error'] ?? 'stop') === 'stop') {
                    break;
                }
            }
        }

        // 工作流级 on_fail：整体失败时执行恢复步骤
        if ($error !== null && !empty($onFailSteps)) {
            $failResults = $this->executeFailSteps($onFailSteps, $context, $depth, $deadline, '__workflow_on_fail__');
            $stepResults['__on_fail__'] = $failResults;
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
        $status = $error !== null ? 'failed' : 'succeeded';

        // 执行成本追踪：结果树只有一个走法（stepResultTree()），且一次遍历算齐三格计数。
        // 旧写法在顶层键上 foreach，并问 `$stepResult['type']` —— 而**没有任何一个执行器交出过
        // 顶层 `type` 键**（库里 `step_results_json LIKE '%"type":"%'` 数出 0 行，实测），于是
        // `api_calls` 恒 0；它下面那一圈「子步骤里的 API 调用」问的是 `result` 键，而 `result`
        // 只是**上下文种子的壳**（`$context[$name] = ['result' => $stepResult]`），从来不是
        // `$stepResults` 的成员，所以那一圈结构上恒不进。这一枚 0 沿着
        // `kode_lowcode_workflow_executions.api_calls` 流进 costTrends()、预算面板的 daily_api、
        // 成本分摊与容量预测 —— 七条聚合腿说的是「这个系统没发出过一次外部调用」。
        // 同一处还按 `_retries_exhausted` 与 `_attempt > 1` **各加一次**，而一枚从未重试的失败
        // 步骤也会拿到 `_retries_exhausted = true`（executeStep() 在循环尾无条件补写），
        // 于是 `steps_retried` 把「试了一次没成」算成重试过、一枚 `_attempt: 3` 算两次。
        // 失败步数与这两个计数在同一棵树上，所以合并成一次遍历（走两遍就是两个口径）。
        $stepsRetried = 0;
        $apiCallsCount = 0;
        $stepsFailed = 0;
        foreach ($this->stepResultTree($stepResults, '', $workflowName) as $leaf) {
            $leafResult = $leaf['result'];
            if (self::stepTypeOf($leafResult) === 'api_call'
                // 沙箱里被跳过的那一格压根没发请求，一次都不许记账 —— 而它照样带 `_attempt`
                // 与 `_type`（盖章在 executeStep 那一个返回口之前），所以这一问不能省。
                && ($leafResult['status'] ?? '') !== 'skipped_sandbox') {
                $apiCallsCount += self::stepAttempts($leafResult);
            }
            if (self::stepRetried($leafResult)) {
                $stepsRetried++;
            }
            if (self::stepOutcome($leafResult)[1]) {
                $stepsFailed++;
            }
        }
        $memoryPeakKb = (int) (memory_get_peak_usage(true) / 1024);

        // 更新执行日志状态（预插入记录已在执行开始时创建）
        if ($depth === 0 && $executionId > 0) {
            try {
                Db::update(
                    "UPDATE kode_lowcode_workflow_executions SET status = ?, step_results_json = ?, step_count = ?, duration_ms = ?, error = ?, memory_peak_kb = ?, api_calls = ?, steps_retried = ? WHERE id = ?",
                    [$status, json_encode($stepResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), count($stepResults), $durationMs, $error ?? '', $memoryPeakKb, $apiCallsCount, $stepsRetried, $executionId]
                );
            } catch (\Throwable) {
                // 更新失败不影响返回
            }

            // 熔断器追踪（仅顶层执行）
            // 读-改-写：`circuit_breaker_count` 从执行开始时加载的 $workflow 快照 +1 再写回。
            // 并发执行下两个事务都读到同一 count、都写同一 count+1——一个增量被覆盖（lost update），
            // 熔断器激活被推迟甚至永远不激活。用 SQL 原子自增 `count = count + 1` 替代
            // PHP 侧读-算-写，配合 CASE 表达式在锁内完成条件判断。
            // 同 `definitionUpdate` / `budgetSave` / `versionRollback` 的加固。
            if ($status === 'failed') {
                $cbThreshold = (int) ($workflow['circuit_breaker_threshold'] ?? 3);
                $wasOpen = (bool) ($workflow['circuit_breaker_open'] ?? false);

                // 原子自增：count = count + 1，circuit_breaker_open 用 CASE 条件更新
                // opened_at 仅在"刚触发"时写 NOW()，否则保留原值
                Db::update(
                    "UPDATE kode_lowcode_workflows SET " .
                    "circuit_breaker_count = circuit_breaker_count + 1, " .
                    "circuit_breaker_open = CASE WHEN circuit_breaker_count + 1 >= ? THEN TRUE ELSE circuit_breaker_open END, " .
                    "circuit_breaker_opened_at = CASE WHEN circuit_breaker_open = FALSE AND circuit_breaker_count + 1 >= ? THEN NOW() ELSE circuit_breaker_opened_at END " .
                    "WHERE id = ?",
                    [$cbThreshold, $cbThreshold, (int) $workflow['id']]
                );

                // 读回确认状态（原子写已完成，这里只是为告警判定取最新值）
                $fresh = Db::select(
                    "SELECT circuit_breaker_open, circuit_breaker_count FROM kode_lowcode_workflows WHERE id = ?",
                    [(int) $workflow['id']]
                );
                $nowOpen = !empty($fresh) && (bool) $fresh[0]['circuit_breaker_open'];
                $newCount = !empty($fresh) ? (int) $fresh[0]['circuit_breaker_count'] : 0;

                if ($nowOpen && !$wasOpen) {
                    // 熔断器刚开启，发送告警
                    $this->sendAlert((int) $workflow['id'], [
                        'id' => $executionId,
                        'workflow' => $workflowName,
                        'status' => 'circuit_breaker_opened',
                        'duration_ms' => $durationMs,
                        'error' => "熔断器开启：连续失败 {$newCount} 次（阈值 {$cbThreshold}）",
                    ]);
                }
            } else {
                // 成功时重置熔断器计数（已是原子 SET 0，无读-改-写，无需修复）
                Db::update(
                    "UPDATE kode_lowcode_workflows SET circuit_breaker_count = 0, circuit_breaker_open = FALSE, circuit_breaker_opened_at = NULL WHERE id = ?",
                    [(int) $workflow['id']]
                );
            }

            // 成本告警检查：失败步数与上面两格计数来自同一次遍历、同一个判据，
            // 否则「上游 5xx」这一类失败在执行记录里是 failed、在成本告警里数成 0 步失败
            // （同一件事两种结论）。
            $this->checkCostAlerts($workflow, $executionId, $durationMs, $memoryPeakKb, $stepsFailed);

            // 发送执行完成通知（非阻塞，沙箱模式跳过）
            if (!$sandbox) {
                $this->sendNotification((int) $workflow['id'], [
                    'id' => $executionId,
                    'workflow' => $workflowName,
                    'status' => $status,
                    'duration_ms' => $durationMs,
                    'error' => $error,
                ]);

                // 失败时发送告警（独立于通知）
                if ($status === 'failed') {
                    $this->sendAlert((int) $workflow['id'], [
                        'id' => $executionId,
                        'workflow' => $workflowName,
                        'status' => $status,
                        'duration_ms' => $durationMs,
                        'error' => $error,
                    ]);
                }
            }
        }

        return [
            'id' => $executionId,
            'workflow' => $workflowName,
            'status' => $status,
            'trace_id' => $traceId,
            'duration_ms' => $durationMs,
            'steps' => $stepResults,
            'error' => $error,
            'cost' => [
                'memory_peak_kb' => $memoryPeakKb,
                'api_calls' => $apiCallsCount,
                'steps_retried' => $stepsRetried,
            ],
        ];
    }

    /* ===================== 执行日志 ===================== */

    /**
     * GET /api/workflow/executions?page=&size=&workflow=&status=
     * 执行日志列表（admin）。
     */
    public function executionsList()
    {
        $win = $this->pageWindow($this->query('page'), $this->query('size'), 200);
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];

        $workflow = Request::input('workflow', null);
        $status = Request::input('status', null);

        $where = [];
        $bindings = [];
        if ($workflow !== null && $workflow !== '') {
            $where[] = 'workflow_name = ?';
            $bindings[] = $workflow;
        }
        if ($status !== null && $status !== '') {
            $where[] = 'status = ?';
            $bindings[] = $status;
        }
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $items = Db::select(
            "SELECT id, workflow_name, workflow_id, status, step_count, duration_ms, error, trace_id, created_at
             FROM kode_lowcode_workflow_executions{$whereSql}
             ORDER BY id DESC LIMIT ? OFFSET ?",
            [...$bindings, $size, $offset]
        );

        $total = (int) Db::select("SELECT COUNT(*) as cnt FROM kode_lowcode_workflow_executions{$whereSql}", $bindings)[0]['cnt'];

        // 出参 id / workflow_id 混淆
        $items = array_map(static fn (array $row): array => self::hashOut($row, ['id', 'workflow_id']), $items);

        // 此前 OFFSET 写死 0：`total` 承认有 1234 条，而这条 URL 上根本没有第 2 页，
        // 第 `limit` 条之后的执行流水永久取不到（口径见 AGENTS.md §4 的列表三件套）。
        return $this->page($items, $total, $page, $size);
    }

    /**
     * GET /api/workflow/executions/{id}
     * 执行日志详情（含 input + step_results）。
     */
    public function executionShow()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('执行记录不存在', 404);
        }
        $rows = Db::select("SELECT * FROM kode_lowcode_workflow_executions WHERE id = ?", [$id]);
        if (empty($rows)) {
            return $this->error('执行记录不存在', 404);
        }
        $item = $rows[0];
        $item['input'] = json_decode($item['input_json'] ?? '{}', true) ?? [];
        $item['step_results'] = json_decode($item['step_results_json'] ?? '{}', true) ?? [];
        unset($item['input_json'], $item['step_results_json']);
        return $this->ok(self::hashOut($item, ['id', 'workflow_id', 'parent_execution_id']));
    }

    /* ===================== 执行分析 ===================== */

    /**
     * GET /api/workflow/analytics
     * 执行分析仪表盘：聚合统计 + 步骤级指标。
     */
    public function analytics()
    {
        $days = min(90, max(1, (int) Request::input('days', 7)));
        $sinceSql = " AND created_at >= NOW() - INTERVAL '{$days} days'";

        // 1. 总体统计
        $overall = Db::select(
            "SELECT COUNT(*) as total,
                    COUNT(*) FILTER (WHERE status = 'succeeded') as succeeded,
                    COUNT(*) FILTER (WHERE status = 'failed') as failed,
                    AVG(duration_ms) as avg_duration_ms,
                    MAX(duration_ms) as max_duration_ms,
                    MIN(duration_ms) as min_duration_ms
             FROM kode_lowcode_workflow_executions WHERE 1=1{$sinceSql}",
            []
        )[0] ?? [];

        $total = (int) ($overall['total'] ?? 0);
        $succeeded = (int) ($overall['succeeded'] ?? 0);
        $failed = (int) ($overall['failed'] ?? 0);
        $overall['success_rate'] = $total > 0 ? round($succeeded / $total * 100, 1) : 0;
        $overall['avg_duration_ms'] = round((float) ($overall['avg_duration_ms'] ?? 0), 1);

        // 2. 按工作流分组
        $byWorkflow = Db::select(
            "SELECT workflow_name, COUNT(*) as cnt,
                    COUNT(*) FILTER (WHERE status = 'succeeded') as succeeded,
                    COUNT(*) FILTER (WHERE status = 'failed') as failed,
                    AVG(duration_ms) as avg_ms
             FROM kode_lowcode_workflow_executions WHERE 1=1{$sinceSql}
             GROUP BY workflow_name ORDER BY cnt DESC LIMIT 20",
            []
        );
        foreach ($byWorkflow as &$wf) {
            $wf['cnt'] = (int) $wf['cnt'];
            $wf['succeeded'] = (int) $wf['succeeded'];
            $wf['failed'] = (int) $wf['failed'];
            $wf['avg_ms'] = round((float) ($wf['avg_ms'] ?? 0), 1);
            $wf['success_rate'] = $wf['cnt'] > 0 ? round($wf['succeeded'] / $wf['cnt'] * 100, 1) : 0;
        }
        unset($wf);

        // 3. 错误分布（按错误消息分类）
        $errorDist = Db::select(
            "SELECT COALESCE(LEFT(error, 80), 'unknown') as error_type, COUNT(*) as cnt
             FROM kode_lowcode_workflow_executions WHERE status = 'failed' AND 1=1{$sinceSql}
             GROUP BY error_type ORDER BY cnt DESC LIMIT 10",
            []
        );
        foreach ($errorDist as &$ed) {
            $ed['cnt'] = (int) $ed['cnt'];
        }
        unset($ed);

        // 4. 每日执行趋势（最近 N 天）
        $dailyTrend = Db::select(
            "SELECT DATE(created_at) as date, COUNT(*) as cnt,
                    COUNT(*) FILTER (WHERE status = 'succeeded') as succeeded
             FROM kode_lowcode_workflow_executions WHERE 1=1{$sinceSql}
             GROUP BY DATE(created_at) ORDER BY date DESC",
            []
        );
        foreach ($dailyTrend as &$dt) {
            $dt['cnt'] = (int) $dt['cnt'];
            $dt['succeeded'] = (int) $dt['succeeded'];
        }
        unset($dt);

        return $this->ok([
            'overall' => $overall,
            'by_workflow' => $byWorkflow,
            'error_distribution' => $errorDist,
            'daily_trend' => $dailyTrend,
            'days' => $days,
        ]);
    }

    /* ===================== 执行重放 ===================== */

    /**
     * POST /api/workflow/executions/{id}/replay
     * 用相同参数重放历史执行，返回对比结果。
     */
    public function executionReplay()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('执行记录不存在', 404);
        }
        $rows = Db::select("SELECT * FROM kode_lowcode_workflow_executions WHERE id = ?", [$id]);
        if (empty($rows)) {
            return $this->error('执行记录不存在', 404);
        }
        $record = $rows[0];
        $inputParams = json_decode($record['input_json'] ?? '{}', true) ?? [];

        // 重新执行工作流
        $wfRows = Db::select("SELECT * FROM kode_lowcode_workflows WHERE name = ?", [$record['workflow_name']]);
        if (empty($wfRows)) {
            return $this->error('工作流定义不存在', 404);
        }
        $wf = $wfRows[0];
        $steps = json_decode($wf['steps_json'] ?? '[]', true) ?? [];
        $onFailSteps = json_decode($wf['on_fail_json'] ?? '[]', true) ?? [];
        $timeoutMs = (int) ($wf['timeout_ms'] ?? 30000);

        $start = microtime(true);
        $traceId = $this->traceId();
        $context = self::stepContext((string) $record['workflow_name'], $inputParams, $traceId);
        $deadline = $start + $timeoutMs / 1000.0;
        $stepResults = [];
        $status = 'succeeded';
        $error = '';

        foreach ($steps as $i => $step) {
            if ($deadline > 0 && microtime(true) > $deadline) {
                $status = 'failed';
                $error = "工作流超时 ({$timeoutMs}ms)";
                break;
            }
            $stepStart = microtime(true);
            $type = $step['type'] ?? '';
            $name = $step['name'] ?? "step_{$i}";
            $config = $step['config'] ?? [];
            $config['name'] = $name;
            $config['type'] = $type;

            $stepResult = $this->executeStep($type, $config, $context, 0, $deadline);
            $stepDuration = (int) round((microtime(true) - $stepStart) * 1000);
            $stepResult['duration_ms'] = $stepDuration;
            [$stepOk, $stepFailed] = self::stepOutcome($stepResult);
            $stepResult['success'] = $stepOk;

            // 上下文种的必须是**整份步骤结果**（与编排器同一个形状）：执行器没有任何一个
            // 分支交出过顶层 `result` 键，所以旧写法 `isset($stepResult['result'])` 恒假 ——
            // 重放时前序输出压根进不了上下文，`{{extract.token}}` 插成空串，
            // 而这条重放记录照样落 succeeded。
            $context[$name] = ['result' => $stepResult];
            $stepResults[$name] = $stepResult;

            // 检查步骤级失败（与上面落库的 success 同一个判据，不再各算一遍）
            if ($stepFailed && ($step['on_error'] ?? 'stop') === 'stop') {
                $status = 'failed';
                $error = self::stepFailureText($stepResult);
                $context['__error__'] = ['message' => $error];
                // 执行工作流级 on_fail
                if (!empty($onFailSteps)) {
                    $failResult = $this->executeFailSteps($onFailSteps, $context, 0, $deadline, 'workflow');
                    $stepResults['__on_fail__'] = $failResult;
                }
                break;
            }
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        // 记录重放执行
        $replayId = $this->logExecution(
            $record['workflow_name'],
            (int) $wf['id'],
            $inputParams,
            $stepResults,
            $status,
            $traceId,
            $durationMs,
            $error,
            (int) $record['id'],
            1
        );

        // 对比原始 vs 重放（出参 id 混淆，内部记录仍用 int）
        $originalStatus = $record['status'];
        $originalDuration = (int) $record['duration_ms'];
        $comparison = [
            'original' => [
                'id' => \app\support\HashId::enc((int) $record['id']),
                'status' => $originalStatus,
                'duration_ms' => $originalDuration,
                'error' => $record['error'],
            ],
            'replay' => [
                'id' => \app\support\HashId::enc((int) $replayId),
                'status' => $status,
                'duration_ms' => $durationMs,
                'error' => $error,
            ],
            'status_match' => $originalStatus === $status,
            'duration_diff_ms' => $durationMs - $originalDuration,
        ];

        return $this->ok([
            'replay_id' => \app\support\HashId::enc((int) $replayId),
            'comparison' => $comparison,
            'steps' => $stepResults,
        ]);
    }

    /**
     * POST /api/workflow/executions/{id}/cancel
     * 取消运行中的执行。
     *
     * 标记执行记录为 cancelled，运行中的进程会在下一步检查状态时停止。
     */
    public function executionCancel()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('执行记录不存在', 404);
        }
        $rows = Db::select("SELECT id, workflow_name, status, created_at FROM kode_lowcode_workflow_executions WHERE id = ?", [$id]);
        if (empty($rows)) {
            return $this->error('执行记录不存在', 404);
        }

        $record = $rows[0];
        if (!in_array($record['status'], ['queued', 'running'], true)) {
            return $this->error("只能取消 queued/running 状态的执行，当前: {$record['status']}");
        }

        Db::update(
            "UPDATE kode_lowcode_workflow_executions SET status = 'cancelled', cancelled_at = NOW(), error = '手动取消' WHERE id = ?",
            [$id]
        );

        \Kode\Framework\Facades\Log::info("执行取消：execution_id={$id} workflow={$record['workflow_name']}");

        $fresh = Db::select("SELECT cancelled_at FROM kode_lowcode_workflow_executions WHERE id = ?", [$id]);
        return $this->ok([
            'id' => \app\support\HashId::enc($id),
            'workflow' => $record['workflow_name'],
            'status' => 'cancelled',
            'cancelled_at' => $fresh[0]['cancelled_at'] ?? null,
        ]);
    }

    /**
     * POST /api/workflow/executions/{id}/retry-params
     * 带新参数重试失败执行。
     *
     * Body: { params: {...} }
     *
     * 查找原执行所属的工作流，用新参数重新执行，记录新执行日志
     * （与 executionReplay 的区别：replay 用原参数，retry-params 用新参数）。
     */
    public function executionRetryParams()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('执行记录不存在', 404);
        }
        $rows = Db::select("SELECT id, workflow_name, status, input_json FROM kode_lowcode_workflow_executions WHERE id = ?", [$id]);
        if (empty($rows)) {
            return $this->error('执行记录不存在', 404);
        }

        $record = $rows[0];
        if (!in_array($record['status'], ['failed', 'succeeded', 'cancelled', 'timeout'], true)) {
            return $this->error("只能重试 completed 状态的执行，当前: {$record['status']}");
        }

        $params = Request::input('params', null);
        if ($params === null || !is_array($params)) {
            return $this->error('params 必须为对象', 422);
        }

        $workflowName = (string) $record['workflow_name'];

        // 复用 executeWorkflowInternal（与 execute() 相同的执行路径）
        $result = $this->executeWorkflowInternal($workflowName, $params, "retry-params of #{$id}");

        // 出参执行 id 混淆（内部引擎保持 int，仅在响应边界编码）
        if (isset($result['id'])) {
            $result['id'] = \app\support\HashId::enc((int) $result['id']);
        }

        return $this->ok($result);
    }

    /**
     * POST /api/workflow/executions/{id}/retry-from-step
     * 从指定步骤开始重试执行，前序步骤使用缓存结果。
     *
     * Body: { step_name: "step_to_retry" }
     *
     * 从原执行记录中获取该步骤之前的步骤结果作为 context，
     * 然后从目标步骤开始重新执行后续所有步骤。
     */
    public function executionRetryFromStep()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('执行记录不存在', 404);
        }
        $rows = Db::select("SELECT * FROM kode_lowcode_workflow_executions WHERE id = ?", [$id]);
        if (empty($rows)) {
            return $this->error('执行记录不存在', 404);
        }

        $record = $rows[0];
        $workflowName = (string) $record['workflow_name'];
        $inputParams = json_decode($record['input_json'] ?? '{}', true) ?? [];
        $originalStepResults = json_decode($record['step_results_json'] ?? '{}', true) ?? [];
        $stepName = trim((string) Request::input('step_name', ''));

        if ($stepName === '') {
            return $this->error('step_name 必填', 422);
        }
        if (mb_strlen($stepName) > 128) {
            return $this->error('step_name 超过 128 字符限制', 422);
        }

        // 查找工作流定义
        $wfRows = Db::select("SELECT * FROM kode_lowcode_workflows WHERE name = ?", [$workflowName]);
        if (empty($wfRows)) {
            return $this->error('工作流定义不存在', 404);
        }
        $wf = $wfRows[0];
        $steps = json_decode($wf['steps_json'] ?? '[]', true) ?? [];
        $onFailSteps = json_decode($wf['on_fail_json'] ?? '[]', true) ?? [];
        $timeoutMs = (int) ($wf['timeout_ms'] ?? 30000);

        // 找到目标步骤的索引
        $targetIndex = -1;
        foreach ($steps as $i => $step) {
            if (($step['name'] ?? '') === $stepName) {
                $targetIndex = $i;
                break;
            }
        }
        if ($targetIndex < 0) {
            return $this->error("步骤 '{$stepName}' 在工作流中不存在");
        }

        // 构建 context：params + 前序步骤的缓存结果
        $traceId = $this->traceId();
        $context = self::stepContext($workflowName, $inputParams, $traceId);
        for ($i = 0; $i < $targetIndex; $i++) {
            $name = $steps[$i]['name'] ?? "step_{$i}";
            if (isset($originalStepResults[$name])) {
                $cachedResult = $originalStepResults[$name];
                // 复用上一轮的输出时，「上一轮成没成」只问那一个判据；且种的必须是整份记录
                // （旧写法种 `$cachedResult['result']`，而没有任何执行器交出过顶层 `result` 键，
                // 于是这一整个 if 恒假，重试跑出来的是「前序步骤全部为空」的假数据）。
                if (self::recordedOutcome($cachedResult) === true) {
                    $context[$name] = ['result' => $cachedResult];
                }
            }
        }

        // 从目标步骤开始执行
        $start = microtime(true);
        $deadline = $start + $timeoutMs / 1000.0;
        $stepResults = [];
        $status = 'succeeded';
        $error = '';

        for ($i = $targetIndex; $i < count($steps); $i++) {
            if ($deadline > 0 && microtime(true) > $deadline) {
                $status = 'failed';
                $error = "工作流超时 ({$timeoutMs}ms)";
                break;
            }
            $stepStart = microtime(true);
            $step = $steps[$i];
            $type = $step['type'] ?? '';
            $name = $step['name'] ?? "step_{$i}";
            $config = $step['config'] ?? [];
            $config['name'] = $name;
            $config['type'] = $type;

            $stepResult = $this->executeStep($type, $config, $context, 0, $deadline);
            $stepDuration = (int) round((microtime(true) - $stepStart) * 1000);
            $stepResult['_duration_ms'] = $stepDuration;
            $stepResult['duration_ms'] = $stepDuration;
            [$stepOk, $stepFailed] = self::stepOutcome($stepResult);
            $stepResult['success'] = $stepOk;

            $context[$name] = ['result' => $stepResult];
            $stepResults[$name] = $stepResult;

            // 步骤级失败检查（与上面落库的 success 同一个判据）
            if ($stepFailed && ($step['on_error'] ?? 'stop') === 'stop') {
                $status = 'failed';
                $error = self::stepFailureText($stepResult);
                $context['__error__'] = ['message' => $error];
                if (!empty($onFailSteps)) {
                    $failResult = $this->executeFailSteps($onFailSteps, $context, 0, $deadline, 'workflow');
                    $stepResults['__on_fail__'] = $failResult;
                }
                break;
            }
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        // 合并完整步骤结果：前序步骤用缓存，后续步骤用新结果
        $fullStepResults = [];
        for ($i = 0; $i < $targetIndex; $i++) {
            $name = $steps[$i]['name'] ?? "step_{$i}";
            $fullStepResults[$name] = $originalStepResults[$name] ?? [];
            $fullStepResults[$name]['_cached'] = true;
        }
        foreach ($stepResults as $name => $result) {
            $fullStepResults[$name] = $result;
        }

        // 记录新执行
        $newId = $this->logExecution(
            $workflowName,
            (int) $wf['id'],
            $inputParams,
            $fullStepResults,
            $status,
            $traceId,
            $durationMs,
            $error,
            $id,
            1
        );

        return $this->ok([
            'new_execution_id' => \app\support\HashId::enc((int) $newId),
            'original_execution_id' => \app\support\HashId::enc($id),
            'retry_from_step' => $stepName,
            'retry_from_index' => $targetIndex,
            'total_steps' => count($steps),
            'cached_steps' => $targetIndex,
            'retried_steps' => count($steps) - $targetIndex,
            'status' => $status,
            'duration_ms' => $durationMs,
            'error' => $error,
            'steps' => $stepResults,
        ]);
    }

    /**
     * GET /api/workflow/executions/{id}/retry-preview
     * 重试预览：在执行前展示将被缓存的步骤与将被重跑的步骤。
     *
     * Query: step_name (必填)
     *
     * 返回：
     * - original_execution_id / retry_from_step / retry_from_index
     * - cached_steps: [{name, status, duration_ms}] (前序步骤，将被缓存)
     * - retried_steps: [{name, type, status, duration_ms, has_result}] (将被重跑)
     * - total_steps / cached_count / retried_count
     * - original_status / original_duration_ms
     */
    public function executionRetryPreview()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('执行记录不存在', 404);
        }
        $stepName = trim((string) Request::input('step_name', ''));
        if ($stepName === '') {
            return $this->error('step_name 必填', 422);
        }
        if (mb_strlen($stepName) > 128) {
            return $this->error('step_name 超过 128 字符限制', 422);
        }

        $rows = Db::select("SELECT * FROM kode_lowcode_workflow_executions WHERE id = ?", [$id]);
        if (empty($rows)) {
            return $this->error('执行记录不存在', 404);
        }
        $record = $rows[0];
        $workflowName = (string) $record['workflow_name'];
        $originalStepResults = json_decode($record['step_results_json'] ?? '{}', true) ?? [];

        $wfRows = Db::select("SELECT * FROM kode_lowcode_workflows WHERE name = ?", [$workflowName]);
        if (empty($wfRows)) {
            return $this->error('工作流定义不存在', 404);
        }
        $steps = json_decode($wfRows[0]['steps_json'] ?? '[]', true) ?? [];

        $targetIndex = -1;
        foreach ($steps as $i => $step) {
            if (($step['name'] ?? '') === $stepName) {
                $targetIndex = $i;
                break;
            }
        }
        if ($targetIndex < 0) {
            return $this->error("步骤 '{$stepName}' 在工作流中不存在");
        }

        $cachedSteps = [];
        $retriedSteps = [];
        for ($i = 0; $i < count($steps); $i++) {
            $s = $steps[$i];
            $name = $s['name'] ?? "step_{$i}";
            $origResult = $originalStepResults[$name] ?? null;
            // 这一屏的全部作用就是替人宣布「点下去之后每一步是复用缓存还是重跑」，
            // 所以复用判据必须与上面种进上下文的那一枚是同一个：旧写法 `isset($origResult['error'])`
            // 在 `success: false` 而不带 error 键的记录上给出相反结论，
            // 于是预览说「这步成功了、会复用」而真正重试时它的输出没被种进去。
            $outcome = self::recordedOutcome($origResult);
            $entry = [
                'index' => $i,
                'name' => $name,
                'type' => $s['type'] ?? '',
                'status' => $outcome === null ? 'not_executed' : ($outcome ? 'succeeded' : 'failed'),
                'duration_ms' => (int) ($origResult['duration_ms'] ?? $origResult['_duration_ms'] ?? 0),
                // 「✓ 有结果」这一枚此前结构上永远画不出来（它读 `$origResult['result']`，
                // 而没有任何执行器交出过顶层 `result` 键）。恒键而非缺键：
                // 「这一格没说」与「这一格是没结果」在客户端无从区分。
                'has_result' => $outcome === true,
            ];
            if ($i < $targetIndex) {
                $entry['_cached'] = true;
                $cachedSteps[] = $entry;
            } else {
                $entry['_retried'] = true;
                $retriedSteps[] = $entry;
            }
        }

        return $this->ok([
            'original_execution_id' => \app\support\HashId::enc($id),
            'workflow_name' => $workflowName,
            'original_status' => $record['status'],
            'original_duration_ms' => (int) $record['duration_ms'],
            'retry_from_step' => $stepName,
            'retry_from_index' => $targetIndex,
            'total_steps' => count($steps),
            'cached_count' => $targetIndex,
            'retried_count' => count($steps) - $targetIndex,
            'cached_steps' => $cachedSteps,
            'retried_steps' => $retriedSteps,
        ]);
    }

    /**
     * POST /api/workflow/executions/batch-retry
     * 批量从指定步骤重试多条执行。
     *
     * Body: { ids: [1,2,3], retry_from_step?: "step_name" }
     *
     * - retry_from_step 为空时，对每条执行：若为 failed 且 step_results_json 中存在首个失败步骤，
     *   则从该步骤重试；否则整条重放。
     * - 串行执行（避免并发副作用），逐条记录结果。
     */
    public function batchRetryExecutions()
    {
        $ids = (array) Request::input('ids', []);
        $retryFromStep = trim((string) Request::input('retry_from_step', ''));
        if ($retryFromStep !== '' && mb_strlen($retryFromStep) > 128) {
            return $this->error('retry_from_step 超过 128 字符限制', 422);
        }

        // 入参 ids 为对外 HashId 串（兼容直接传内部 int）；保留 内部int=>原串 映射用于回显
        $idMap = [];
        foreach ($ids as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            $decoded = ctype_digit($raw) ? (int) $raw : \app\support\HashId::dec($raw);
            if ($decoded !== null && $decoded > 0) {
                $idMap[$decoded] = $raw;
            }
        }
        $ids = array_keys($idMap);
        if (empty($ids)) {
            return $this->error('ids 不能为空', 422);
        }
        if (count($ids) > 50) {
            return $this->error('单次批量重试最多 50 条', 422);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::select(
            "SELECT * FROM kode_lowcode_workflow_executions WHERE id IN ({$placeholders})",
            $ids
        );
        $found = array_map('intval', array_column($rows, 'id'));
        $missing = array_values(array_map(
            static fn (int $missed): string => (string) $idMap[$missed],
            array_diff($ids, $found)
        ));

        $results = [];
        $okCount = 0;
        $failCount = 0;

        foreach ($ids as $id) {
            $rec = null;
            foreach ($rows as $r) {
                if ((int) $r['id'] === $id) {
                    $rec = $r;
                    break;
                }
            }
            if (!$rec) {
                $results[] = ['id' => (string) $idMap[$id], 'status' => 'not_found', 'error' => '执行记录不存在'];
                $failCount++;
                continue;
            }

            $wfRows = Db::select("SELECT * FROM kode_lowcode_workflows WHERE name = ?", [$rec['workflow_name']]);
            if (empty($wfRows)) {
                $results[] = ['id' => (string) $idMap[$id], 'status' => 'failed', 'error' => '工作流定义不存在'];
                $failCount++;
                continue;
            }
            $wf = $wfRows[0];
            $steps = json_decode($wf['steps_json'] ?? '[]', true) ?? [];
            $onFailSteps = json_decode($wf['on_fail_json'] ?? '[]', true) ?? [];
            $timeoutMs = (int) ($wf['timeout_ms'] ?? 30000);
            $inputParams = json_decode($rec['input_json'] ?? '{}', true) ?? [];
            $originalStepResults = json_decode($rec['step_results_json'] ?? '{}', true) ?? [];

            // 解析起始索引
            $targetIndex = 0;
            $startStepName = null;
            if ($retryFromStep !== '') {
                $startStepName = $retryFromStep;
                foreach ($steps as $i => $step) {
                    if (($step['name'] ?? '') === $retryFromStep) {
                        $targetIndex = $i;
                        break;
                    }
                }
                if ($targetIndex === 0 && empty(array_filter($steps, fn($s) => ($s['name'] ?? '') === $retryFromStep))) {
                    $results[] = ['id' => (string) $idMap[$id], 'status' => 'failed', 'error' => "步骤 '{$retryFromStep}' 不存在"];
                    $failCount++;
                    continue;
                }
            } else {
                // 自动定位：找第一个失败的步骤
                foreach ($steps as $i => $step) {
                    $name = $step['name'] ?? "step_{$i}";
                    $origResult = $originalStepResults[$name] ?? null;
                    if (self::recordedOutcome($origResult) === false) {
                        $targetIndex = $i;
                        $startStepName = $name;
                        break;
                    }
                }
            }

            // 构建 context：params + 前序步骤缓存结果
            $traceId = $this->traceId();
            $context = self::stepContext((string) $rec['workflow_name'], $inputParams, $traceId);
            for ($i = 0; $i < $targetIndex; $i++) {
                $name = $steps[$i]['name'] ?? "step_{$i}";
                if (isset($originalStepResults[$name])) {
                    $cachedResult = $originalStepResults[$name];
                    if (self::recordedOutcome($cachedResult) === true) {
                        $context[$name] = ['result' => $cachedResult];
                    }
                }
            }

            // 从 targetIndex 开始执行
            $start = microtime(true);
            $deadline = $start + $timeoutMs / 1000.0;
            $stepResults = [];
            $status = 'succeeded';
            $error = '';

            for ($i = $targetIndex; $i < count($steps); $i++) {
                if ($deadline > 0 && microtime(true) > $deadline) {
                    $status = 'failed';
                    $error = "工作流超时 ({$timeoutMs}ms)";
                    break;
                }
                $stepStart = microtime(true);
                $step = $steps[$i];
                $type = $step['type'] ?? '';
                $name = $step['name'] ?? "step_{$i}";
                $config = $step['config'] ?? [];
                $config['name'] = $name;
                $config['type'] = $type;

                $stepResult = $this->executeStep($type, $config, $context, 0, $deadline);
                $stepDuration = (int) round((microtime(true) - $stepStart) * 1000);
                $stepResult['_duration_ms'] = $stepDuration;
                $stepResult['duration_ms'] = $stepDuration;
                [$stepOk, $stepFailed] = self::stepOutcome($stepResult);
                $stepResult['success'] = $stepOk;

                // 步骤的输出就是它自己那份结果（没有任何执行器交出过顶层 `result` 键），
                // 与编排器 :1248 同一个形状；旧写法读 `$stepResult['result']` 恒假，于是
                // 从 api 之后重跑时排在它前面的 extract 压根没进上下文，`{{extract.field}}`
                // 静默插成空串，而这条新执行记录照旧落 succeeded。
                $context[$name] = ['result' => $stepResult];
                $stepResults[$name] = $stepResult;

                // 步骤级失败检查（与上面落库的 success 同一个判据）
                if ($stepFailed && ($step['on_error'] ?? 'stop') === 'stop') {
                    $status = 'failed';
                    $error = self::stepFailureText($stepResult);
                    $context['__error__'] = ['message' => $error];
                    if (!empty($onFailSteps)) {
                        $failResult = $this->executeFailSteps($onFailSteps, $context, 0, $deadline, 'workflow');
                        $stepResults['__on_fail__'] = $failResult;
                    }
                    break;
                }
            }

            $durationMs = (int) round((microtime(true) - $start) * 1000);

            // 合并完整步骤结果
            $fullStepResults = [];
            for ($i = 0; $i < $targetIndex; $i++) {
                $name = $steps[$i]['name'] ?? "step_{$i}";
                $fullStepResults[$name] = $originalStepResults[$name] ?? [];
                $fullStepResults[$name]['_cached'] = true;
            }
            foreach ($stepResults as $name => $result) {
                $fullStepResults[$name] = $result;
            }

            $newId = $this->logExecution(
                $rec['workflow_name'],
                (int) $wf['id'],
                $inputParams,
                $fullStepResults,
                $status,
                $traceId,
                $durationMs,
                $error,
                (int) $rec['id'],
                1
            );

            $entry = [
                'id' => (string) $idMap[$id],
                'new_execution_id' => \app\support\HashId::enc((int) $newId),
                'status' => $status,
                'duration_ms' => $durationMs,
                'retry_from_step' => $startStepName ?? 'start',
                'cached_steps' => $targetIndex,
                'retried_steps' => count($steps) - $targetIndex,
            ];
            if ($error !== '') {
                $entry['error'] = $error;
            }
            $results[] = $entry;
            if ($status === 'succeeded') {
                $okCount++;
            } else {
                $failCount++;
            }
        }

        return $this->ok([
            'total' => count($ids),
            'succeeded' => $okCount,
            'failed' => $failCount,
            'missing_ids' => $missing,
            'retry_from_step' => $retryFromStep !== '' ? $retryFromStep : 'auto_first_failed',
            'results' => $results,
        ]);
    }

    /**
     * GET /api/workflow/cost-trends-by-workflow
     * 按工作流分组的成本趋势（每个工作流独立的时间序列）。
     *
     * Query: days (1-30), limit (按总执行数取 top N，默认 20)
     */
    public function costTrendsByWorkflow()
    {
        $days = min(30, max(1, (int) Request::input('days', 7)));
        $topN = min(50, max(1, (int) Request::input('limit', 20)));

        // 每个工作流的整体统计
        $totalsSql = "SELECT workflow_name,
                             COUNT(*) AS executions,
                             SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succeeded,
                             SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                             AVG(duration_ms) AS avg_duration_ms,
                             SUM(COALESCE(api_calls, 0)) AS total_api_calls,
                             SUM(COALESCE(db_queries, 0)) AS total_db_queries,
                             SUM(COALESCE(steps_retried, 0)) AS total_steps_retried
                      FROM kode_lowcode_workflow_executions
                      WHERE created_at > NOW() - INTERVAL '{$days} days'
                      GROUP BY workflow_name
                      ORDER BY executions DESC
                      LIMIT {$topN}";
        $totals = Db::select($totalsSql);

        // 一次性拉取所有工作流的每小时趋势（消除 N+1 查询：原实现为每个工作流单独查一次）
        $topNames = array_map(static fn (array $r): string => (string) $r['workflow_name'], $totals);
        $hourlyByWorkflow = [];
        if (!empty($topNames)) {
            $placeholders = implode(',', array_fill(0, count($topNames), '?'));
            $hourlyRows = Db::select(
                "SELECT workflow_name,
                        date_trunc('hour', created_at) AS hour_bucket,
                        COUNT(*) AS executions,
                        SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succeeded,
                        AVG(duration_ms) AS avg_duration_ms
                 FROM kode_lowcode_workflow_executions
                 WHERE workflow_name IN ({$placeholders}) AND created_at > NOW() - INTERVAL '{$days} days'
                 GROUP BY workflow_name, hour_bucket
                 ORDER BY workflow_name, hour_bucket",
                $topNames
            );
            foreach ($hourlyRows as $h) {
                $wn = (string) $h['workflow_name'];
                $he = (int) $h['executions'];
                $hs = (int) $h['succeeded'];
                $hourlyByWorkflow[$wn][] = [
                    'hour' => $h['hour_bucket'],
                    'executions' => $he,
                    'success_rate' => $he > 0 ? round($hs / $he * 100, 1) : 0,
                    'avg_duration_ms' => round((float) $h['avg_duration_ms'], 1),
                ];
            }
        }

        $workflows = [];
        foreach ($totals as $row) {
            $name = (string) $row['workflow_name'];
            $exec = (int) $row['executions'];
            $succ = (int) $row['succeeded'];
            $fail = (int) $row['failed'];

            $workflows[] = [
                'workflow_name' => $name,
                'executions' => $exec,
                'succeeded' => $succ,
                'failed' => $fail,
                'success_rate' => $exec > 0 ? round($succ / $exec * 100, 1) : 0,
                'avg_duration_ms' => round((float) $row['avg_duration_ms'], 1),
                'total_api_calls' => (int) $row['total_api_calls'],
                'total_db_queries' => (int) $row['total_db_queries'],
                'total_steps_retried' => (int) $row['total_steps_retried'],
                'buckets' => $hourlyByWorkflow[$name] ?? [],
            ];
        }

        return $this->ok([
            'days' => $days,
            'limit' => $topN,
            'workflows' => $workflows,
        ]);
    }

    /**
     * GET /api/workflow/executions/{id}/retry-chain
     * 追溯重试链：通过 parent_execution_id 向上追溯 + 向下查找重试后代。
     *
     * 返回：
     * - root_execution_id：链首执行（无父节点）
     * - chain：链上所有执行（从根到当前）
     * - current_index：当前执行在链中的位置
     * - children：当前执行的直接重试后代
     * - total_depth：链深度
     */
    public function executionRetryChain()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('执行记录不存在', 404);
        }
        $rows = Db::select(
            "SELECT id, workflow_name, status, duration_ms, error, created_at, parent_execution_id, retry_depth, step_count
             FROM kode_lowcode_workflow_executions WHERE id = ?",
            [$id]
        );
        if (empty($rows)) {
            return $this->error('执行记录不存在', 404);
        }

        $current = $rows[0];

        // 向上追溯：从当前执行向根追溯
        $chain = [];
        $cursor = $id;
        $maxUp = 20; // 防止无限循环
        while ($cursor > 0 && $maxUp > 0) {
            $found = Db::select(
                "SELECT id, workflow_name, status, duration_ms, error, created_at, parent_execution_id, retry_depth, step_count
                 FROM kode_lowcode_workflow_executions WHERE id = ?",
                [$cursor]
            );
            if (empty($found)) break;
            $chain[] = $found[0];
            $cursor = (int) $found[0]['parent_execution_id'];
            $maxUp--;
        }
        // 反转：从根到当前
        $chain = array_reverse($chain);
        $rootId = (int) ($chain[0]['id'] ?? $id);
        $currentIndex = count($chain) - 1;

        // 向下查找：当前执行的直接重试后代
        $children = Db::select(
            "SELECT id, workflow_name, status, duration_ms, error, created_at, parent_execution_id, retry_depth, step_count
             FROM kode_lowcode_workflow_executions WHERE parent_execution_id = ? ORDER BY id ASC",
            [$id]
        );

        return $this->ok([
            'current_execution_id' => \app\support\HashId::enc($id),
            'root_execution_id' => \app\support\HashId::enc($rootId),
            'current_index' => $currentIndex,
            'total_depth' => count($chain),
            // 链上/子节点行内 id 与 parent_execution_id 混淆（追溯过程内部保持 int）
            'chain' => array_map(static fn (array $row): array => self::hashOut($row, ['id', 'parent_execution_id']), $chain),
            'children' => array_map(static fn (array $row): array => self::hashOut($row, ['id', 'parent_execution_id']), $children),
        ]);
    }

    /**
     * GET /api/workflow/step-performance
     * 步骤级性能瓶颈分析：P50/P95/P99 + 热点步骤 + 慢步骤排行。
     *
     * Query: days (1-30), workflow (可选)
     *
     * 返回：
     * - overall_percentiles：P50/P95/P99
     * - by_step：每步骤的统计（count/success_rate/avg/p50/p95/p99/max）
     * - top_slow：最慢步骤 Top 10
     * - top_failed：失败率最高步骤 Top 10
     * - hotspots：耗时超过阈值的步骤（热点）
     */
    /**
     * 步骤成败的唯一判据（SQL 侧），返回 `[failed, succeeded]` 两个布尔表达式，
     * `$col` 换成该查询里的 jsonb 列名（`value` / `kv.value`）。与 stepOutcome() 逐条对应，
     * 两侧必须同一条规则，否则同一个步骤在「步骤健康」和「性能分布」给出两个成功率。
     *
     * 两条旧错法各挡一处：
     *  - `(value->>'success') = 'true'`（旧性能分布）：写侧只有 api_call 分支会落 `success` 键，
     *    log / condition / calc 这类步骤压根没有它 —— 于是一律算不成功，实测库里 `log_input`
     *    在性能分布显示 0% 成功率、在步骤健康显示 100%，两个数都印在同一页上；
     *  - 只看 `error` 键（旧步骤健康）：api_call 拿到 HTTP 5xx 时回的是
     *    `{http_code, data, success: false}` 且不带 error 键，于是失败的上游调用被记成成功。
     *
     * @return array{0: string, 1: string}
     */
    private static function stepOutcomeSql(string $col): array
    {
        // 必须写成 CASE 而不是两个条件直接 OR：`success` 键缺失时 `->>` 出 SQL NULL，
        // `false OR NULL` 结果是 NULL 而不是 false，于是 `NOT failed` 也是 NULL，
        // `SUM(CASE WHEN succeeded THEN 1 ...)` 把「没有该键」照旧计成 0 —— 换汤不换药。
        $failed = "(CASE WHEN ({ucol}->>'error') IS NOT NULL AND ({ucol}->>'error') <> '' THEN true"
            . " WHEN ({ucol}->>'success') = 'false' THEN true ELSE false END)";
        $failed = str_replace('{ucol}', $col, $failed);

        return [$failed, "(NOT {$failed})"];
    }

    /**
     * 步骤成败的唯一判据（PHP 侧）：非空 error、或显式 `success: false`，两者之一即算失败；
     * 其余算成功（所以成功与失败互补，两个端点对同一批行必然给出同一组数）。
     *
     * 空串 error 不算失败，与 SQL 侧的 `<> ''` 同口径 —— 旧 PHP 判据用 `isset` 判，
     * 把空串算成失败，于是同一行在两个端点一个判成功一个判失败。
     *
     * @param array<string, mixed> $stepResult
     * @return array{0: bool, 1: bool} [success, failed]
     */
    private static function stepOutcome(array $stepResult): array
    {
        $error = $stepResult['error'] ?? null;
        $failed = ($error !== null && $error !== '')
            || (($stepResult['success'] ?? true) === false);

        return [!$failed, $failed];
    }

    /**
     * 同一枚判据，问的是**上一轮落库的那一步**（`step_results_json` 里的一行）。
     *
     * `null` 意思是「这一轮压根没跑到，或记录形状不认识」——它既不是成功也不是失败，
     * 所以调用方只许写 `=== true` / `=== false`，不许折成任何一个方向：
     * 折成成功会把没跑到的步骤的输出当缓存种进上下文（于是重试答的是假数据），
     * 折成失败会把「上一轮没执行到」渲染成「上一轮这一步失败了」。
     *
     * 这一家存在的理由和 `stepOutcome()` 完全一样：重放/单步重试/批量重试/定时跑四个入口
     * 原先各自写了一遍 `isset($cached['error'])`。那一判据与这里的口径在两种行上分岔 ——
     * `error` 是空串（旧写法算失败，判据说成功），以及 `success: false` 而不带 `error` 键
     * （旧写法算成功）。后者在这一族里最贵：自动定位「第一个失败步骤」时它直接跳过那一格，
     * 于是从**失败步骤之前**重跑整条工作流，而用户点的是「从这里重试」。
     */
    private static function recordedOutcome(mixed $recorded): ?bool
    {
        if (!is_array($recorded)) {
            return null;
        }

        [$ok] = self::stepOutcome($recorded);

        return $ok;
    }

    /**
     * 「这一步为什么失败」的那一句话，和判成败住在同一家。
     *
     * 这不是装饰：执行记录的 `error` 列与工作流响应的 `error` 是这一屏唯一的失败原因出口，
     * 而 HTTP 4xx/5xx 的结果**压根没有 error 键**（只有 `http_code` + `success: false`），
     * 于是旧写法 `$result['error'] ?? 'step error'` 每次都落进那句占位文案 —— 页面上印着
     * 「step error」四个字母，排障的人得回头翻定义才知道是上游返了 500。
     *
     * 另一半更贵：重试循环把取自 `$result['error']` 的值直接喂给
     * `isNonRetryableError(string)`，null 进去是 `\TypeError`（`\Error` 不是 `\Exception`），
     * 方法体内那句 `catch (\Throwable)` 又转不成 400 —— 所以**原因文案必须由这一家给**，
     * 不能留给调用方各自兜一个可能为 null 的表达式。
     *
     * @param array<string, mixed> $stepResult 调用方须已用 stepOutcome() 判为失败
     */
    private static function stepFailureText(array $stepResult): string
    {
        $error = $stepResult['error'] ?? null;
        if (is_string($error) && $error !== '') {
            return $error;
        }

        if (array_key_exists('http_code', $stepResult)) {
            return '上游返回 HTTP ' . (int) $stepResult['http_code'];
        }

        return '步骤上报 success=false';
    }

    /**
     * 「这一步试了几次」的唯一读法。与 stepOutcome() 同住一处，因为**四处读记录的聚合**
     * （写侧成本、单步轨迹、重试分析、瓶颈/健康评分）原先各写一遍
     * `$sr['_attempt'] ?? $sr['attempt'] ?? 1` —— 后面那一支读的是 `attempt`，
     * 而**没有任何一个执行器写过这一枚键**（盖章处只写 `_attempt`），所以它是那种
     * 「看着像兜底、其实恒落默认值」的死码：一次真重试过三次的步骤在那里读回 1。
     *
     * @param array<string, mixed> $stepResult
     */
    private static function stepAttempts(array $stepResult): int
    {
        return max(1, (int) ($stepResult['_attempt'] ?? 1));
    }

    /**
     * 「这一步算不算被重试过」的唯一读法，且它是 stepAttempts() 的派生层。
     *
     * 旧写法有两份并且**在同一圈循环里各加一次**：`!empty($sr['_retries_exhausted'])`
     * 与 `$sr['_attempt'] > 1`。前一支在一枚**从未重试过**的失败步骤上也成立（executeStep()
     * 在重试循环结束后无条件补写它，实测执行 1220878 的 `{"error":"…","_attempt":1,
     * "_retries_exhausted":true}`），后一支让一枚 `_attempt: 3` 的步骤数两次（实测 id 649）。
     * 于是 `steps_retried` 与 healthScore 的「重试率」那一维（按 0.3 分/百分点扣分）都在说假话。
     *
     * 派生层一旦自己读键，判据就又分家了 —— 所以这一枚方法体里不许出现那两个字面量。
     *
     * @param array<string, mixed> $stepResult
     */
    private static function stepRetried(array $stepResult): bool
    {
        return self::stepAttempts($stepResult) > 1;
    }

    /**
     * 「这一步是什么类型」的唯一读法 —— 问的是**执行结果**上的那枚 `_type`，
     * 不是定义里的 `type`（定义树由 stepTree() 走，那是另一棵树、另一个键）。
     *
     * `_type` 之前落库的执行记录没有这一枚键，而那几屏（单步轨迹、依赖健康、瓶颈榜）
     * 把它当一列画，所以认不出时按**落键形状**推一次：直接把历史行统一画成「未知类型」
     * 等于用一次修复抹掉上线以来所有可读的信息。这一份推断只住在这里 ——
     * 消费侧再各自 `isset($x['http_code'])` 一遍，就是第四份「这一步是什么」。
     * 认不出仍回落空串：loop / switch 容器与合成的轮次超时行本来就不是一枚执行器结果，
     * 那不是「我懒得猜」，是「这一格没有类型」。
     *
     * @param array<string, mixed> $stepResult
     */
    private static function stepTypeOf(array $stepResult): string
    {
        $type = (string) ($stepResult['_type'] ?? '');
        if ($type !== '') {
            return $type;
        }

        if (array_key_exists('http_code', $stepResult)) {
            return 'api_call';
        }
        if (is_bool($stepResult['matched'] ?? null)) {
            return 'condition';
        }
        if (array_key_exists('message', $stepResult)) {
            return 'log';
        }
        if (is_array($stepResult['steps'] ?? null)) {
            return 'sub_workflow';
        }

        return '';
    }

    public function stepPerformance()
    {
        $days = min(30, max(1, (int) Request::input('days', 7)));
        $workflowFilter = trim((string) Request::input('workflow', ''));
        $hotspotThresholdMs = max(1, (int) Request::input('hotspot_ms', 100));

        // 展开 step_results_json 为行：使用 PostgreSQL json_each + unnest
        $whereWf = '';
        $params = [];
        if ($workflowFilter !== '') {
            $whereWf = ' AND workflow_name = ?';
            $params[] = $workflowFilter;
        }

        [$failedSql, $succeededSql] = self::stepOutcomeSql('value');

        $sql = "
            WITH exploded AS (
                SELECT
                    e.workflow_name,
                    e.id AS exec_id,
                    e.status AS exec_status,
                    key AS step_name,
                    (value->>'_duration_ms')::numeric AS duration_ms,
                    {$failedSql} AS failed,
                    {$succeededSql} AS succeeded
                FROM kode_lowcode_workflow_executions e,
                     LATERAL json_each(COALESCE(e.step_results_json, '{}')::json) AS kv(key, value)
                WHERE e.created_at > NOW() - INTERVAL '{$days} days'
                  AND e.step_results_json <> '{}'
                  {$whereWf}
            )
            SELECT
                step_name,
                COUNT(*) AS exec_count,
                SUM(CASE WHEN succeeded THEN 1 ELSE 0 END) AS success_count,
                SUM(CASE WHEN failed THEN 1 ELSE 0 END) AS failed_count,
                ROUND(AVG(duration_ms)::numeric, 1) AS avg_ms,
                ROUND((PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY duration_ms))::numeric, 1) AS p50_ms,
                ROUND((PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms))::numeric, 1) AS p95_ms,
                ROUND((PERCENTILE_CONT(0.99) WITHIN GROUP (ORDER BY duration_ms))::numeric, 1) AS p99_ms,
                MAX(duration_ms) AS max_ms
            FROM exploded
            GROUP BY step_name
            ORDER BY avg_ms DESC
        ";

        $rows = Db::select($sql, $params);

        $byStep = [];
        $allDurations = [];
        foreach ($rows as $r) {
            $cnt = (int) $r['exec_count'];
            $succ = (int) $r['success_count'];
            $fail = (int) $r['failed_count'];
            $entry = [
                'step_name' => (string) $r['step_name'],
                'exec_count' => $cnt,
                'success_count' => $succ,
                'failed_count' => $fail,
                'success_rate' => $cnt > 0 ? round($succ / $cnt * 100, 1) : 0,
                'avg_ms' => (float) $r['avg_ms'],
                'p50_ms' => (float) $r['p50_ms'],
                'p95_ms' => (float) $r['p95_ms'],
                'p99_ms' => (float) $r['p99_ms'],
                'max_ms' => (float) $r['max_ms'],
            ];
            $byStep[] = $entry;
            $allDurations[] = (float) $r['avg_ms'];
        }

        // 整体分位数
        sort($allDurations);
        $n = count($allDurations);
        $pct = function (float $p) use ($allDurations, $n): float {
            if ($n === 0) return 0;
            $idx = (int) round($p * ($n - 1));
            return (float) $allDurations[max(0, min($n - 1, $idx))];
        };

        // 最慢步骤 Top 10
        $topSlow = $byStep;
        usort($topSlow, fn($a, $b) => $b['avg_ms'] <=> $a['avg_ms']);
        $topSlow = array_slice($topSlow, 0, 10);

        // 失败率最高 Top 10
        $topFailed = array_filter($byStep, fn($s) => $s['failed_count'] > 0);
        usort($topFailed, function ($a, $b) {
            $ra = $a['failed_count'] / max(1, $a['exec_count']);
            $rb = $b['failed_count'] / max(1, $b['exec_count']);
            return $rb <=> $ra;
        });
        $topFailed = array_slice($topFailed, 0, 10);

        // 热点步骤（avg_ms 超过阈值）
        $hotspots = array_filter($byStep, fn($s) => $s['avg_ms'] > $hotspotThresholdMs);
        usort($hotspots, fn($a, $b) => $b['avg_ms'] <=> $a['avg_ms']);
        $hotspots = array_values($hotspots);

        return $this->ok([
            'days' => $days,
            'workflow' => $workflowFilter,
            'hotspot_threshold_ms' => $hotspotThresholdMs,
            'overall_percentiles' => [
                'p50' => $pct(0.5),
                'p95' => $pct(0.95),
                'p99' => $pct(0.99),
            ],
            'total_steps' => count($byStep),
            'by_step' => $byStep,
            'top_slow' => $topSlow,
            'top_failed' => $topFailed,
            'hotspots' => $hotspots,
        ]);
    }

    /**
     * POST /api/workflow/budgets
     * 保存工作流预算配置（upsert）。
     *
     * Body: { workflow_name, daily_exec_limit, hourly_exec_limit, avg_duration_limit_ms, daily_api_limit, alert_enabled }
     */
    public function budgetSave()
    {
        $body = Request::input();
        $name = trim((string) ($body['workflow_name'] ?? ''));
        if ($name === '' || !preg_match('/^[a-z0-9_]{1,64}$/', $name)) {
            return $this->error('workflow_name 必填且仅允许小写字母/数字/下划线', 422);
        }
        // 与 alertRuleSave 同族：格式合法不等于目标存在，允许孤儿预算等于允许"对着不存在
        // 的 workflow 设预算"——审计时会拿到孤儿，且主表删除后没有级联清理这些历史预算。
        $wfExists = Db::select(
            "SELECT id FROM kode_lowcode_workflows WHERE name = ?",
            [$name]
        );
        if (empty($wfExists)) {
            return $this->error('关联的工作流不存在', 404);
        }

        $dailyExec = max(0, (int) ($body['daily_exec_limit'] ?? 0));
        $hourlyExec = max(0, (int) ($body['hourly_exec_limit'] ?? 0));
        $avgDur = max(0, (int) ($body['avg_duration_limit_ms'] ?? 0));
        $dailyApi = max(0, (int) ($body['daily_api_limit'] ?? 0));
        $alertEnabled = isset($body['alert_enabled']) ? (int) (bool) $body['alert_enabled'] : 1;
        $alertUrl = trim((string) ($body['alert_webhook_url'] ?? ''));
        $alertSecretInput = trim((string) ($body['alert_webhook_secret'] ?? ''));
        $alertCooldown = max(0, (int) ($body['alert_cooldown_min'] ?? 30));
        $escalationThreshold = max(1, (int) ($body['escalation_threshold'] ?? 3));

        // 读-改-写：`alert_webhook_secret` 用 `***` 占位符表示「保留原值」，靠 SELECT 出来的
        // 旧 secret 做 fallback。裸 SELECT + 裸 UPDATE 在 READ COMMITTED 下会丢一边（lost update）
        // ——典型场景：管理员 A 改 daily_exec_limit（发 `***` 保留原 secret），管理员 B 同时改
        // alert_webhook_secret（发新明文），两个事务都读到同一份旧 secret、都写回，B 的新 secret
        // 被 A 的旧快照覆盖。用事务 + `SELECT ... FOR UPDATE` 行锁把「读旧 secret → 决策 → 写回」
        // 包成关键区。同 `app/admin/controllers/ConfigController::set()` 的加固
        // （见 tests/ReadWriteConcurrentUpdateGuardTest）。
        $exists = Db::select(
            "SELECT id FROM kode_lowcode_workflow_budgets WHERE workflow_name = ?",
            [$name]
        );

        // Secret 保留逻辑：
        // - '***' → 保留原密钥（编辑时前端不持有明文，用占位符表示"不变"）
        // - ''    → 显式清除
        // - 非空  → 设置新密钥
        $secretPreserved = false;
        $isPlaceholder = ($alertSecretInput === '***');
        // $alertSecret 必须在闭包**外面**先有一份：事务闭包里的局部变量出不来
        // （AGENTS.md §3「事务闭包的局部变量到不了闭包外面」），否则更新分支走到
        // 末尾那句 `($alertSecret !== '' ? ...)` 时读的是未定义变量。
        $alertSecret = '';
        if (!empty($exists)) {
            $id = (int) $exists[0]['id'];
            try {
                Db::transaction(function () use ($id, $dailyExec, $hourlyExec, $avgDur, $dailyApi, $alertEnabled, $alertUrl, $alertCooldown, $escalationThreshold, $alertSecretInput, $isPlaceholder, &$secretPreserved, &$alertSecret): void {
                    // 事务内 FOR UPDATE 读旧 secret——占位符时用它作为最终写回值；新 secret 场景
                    // 也走这一把锁，只是不使用 $existingSecret。
                    $row = Db::select(
                        "SELECT alert_webhook_secret FROM kode_lowcode_workflow_budgets WHERE id = ? LIMIT 1 FOR UPDATE",
                        [$id]
                    );
                    if ($row === []) {
                        throw new \RuntimeException("budget row {$id} not found");
                    }
                    $existingSecret = (string) ($row[0]['alert_webhook_secret'] ?? '');
                    $alertSecret = $isPlaceholder ? $existingSecret : $alertSecretInput;
                    $secretPreserved = $isPlaceholder && $existingSecret !== '';

                    if ($isPlaceholder) {
                        // 占位符：不写 alert_webhook_secret 列（保持 DB 里原值不动，等价于覆盖为
                        // 原值——因为锁已持有、原值即最新值）。
                        Db::update(
                            "UPDATE kode_lowcode_workflow_budgets SET daily_exec_limit=?, hourly_exec_limit=?, avg_duration_limit_ms=?, daily_api_limit=?, alert_enabled=?, alert_webhook_url=?, alert_cooldown_min=?, escalation_threshold=?, updated_at=NOW() WHERE id=?",
                            [$dailyExec, $hourlyExec, $avgDur, $dailyApi, $alertEnabled, $alertUrl, $alertCooldown, $escalationThreshold, $id]
                        );
                    } else {
                        Db::update(
                            "UPDATE kode_lowcode_workflow_budgets SET daily_exec_limit=?, hourly_exec_limit=?, avg_duration_limit_ms=?, daily_api_limit=?, alert_enabled=?, alert_webhook_url=?, alert_webhook_secret=?, alert_cooldown_min=?, escalation_threshold=?, updated_at=NOW() WHERE id=?",
                            [$dailyExec, $hourlyExec, $avgDur, $dailyApi, $alertEnabled, $alertUrl, $alertSecret, $alertCooldown, $escalationThreshold, $id]
                        );
                    }
                });
            } catch (\RuntimeException $e) {
                if (str_starts_with($e->getMessage(), 'budget row ')) {
                    return $this->error('预算配置不存在', 404);
                }
                throw $e;
            }
        } else {
            $alertSecret = $isPlaceholder ? '' : $alertSecretInput;
            $secretPreserved = false;
            $limits = [
                'daily_exec_limit' => $dailyExec,
                'hourly_exec_limit' => $hourlyExec,
                'avg_duration_limit_ms' => $avgDur,
                'daily_api_limit' => $dailyApi,
                'alert_enabled' => $alertEnabled,
                'alert_webhook_url' => $alertUrl,
                'alert_cooldown_min' => $alertCooldown,
                'escalation_threshold' => $escalationThreshold,
            ];
            // 新建分支。这里原先写着「目前没有 UNIQUE 约束，暂按后写覆盖处理」——
            // 那句话是错的：`kode_lowcode_workflow_budgets_workflow_name_key` 实测就在库里。
            // 两个人同时给同一条工作流存预算时，旧写法第二个人撞 23505 → 未捕获 → 500。
            // 占位符（'***'）场景下并发抢到的那一行带着别人的密钥，所以冲突时
            // **不覆盖 `alert_webhook_secret`**，其余限流列照收（后写覆盖 = 保存的语义）。
            AtomicUpsert::run(
                'kode_lowcode_workflow_budgets',
                ['workflow_name' => $name] + $limits + ['alert_webhook_secret' => $alertSecret],
                ['workflow_name'],
                $isPlaceholder ? array_keys($limits) : null,
                ['updated_at' => 'NOW()']
            );
        }

        return $this->ok([
            'workflow_name' => $name,
            'daily_exec_limit' => $dailyExec,
            'hourly_exec_limit' => $hourlyExec,
            'avg_duration_limit_ms' => $avgDur,
            'daily_api_limit' => $dailyApi,
            'alert_enabled' => $alertEnabled,
            'alert_webhook_url' => $alertUrl,
            'alert_webhook_secret' => ($alertSecret !== '' ? '***' : ''),
            'alert_webhook_secret_preserved' => $secretPreserved,
            'alert_cooldown_min' => $alertCooldown,
            'escalation_threshold' => $escalationThreshold,
        ]);
    }

    /**
     * GET /api/workflow/budgets
     * 列出所有工作流预算配置及其当前告警状态。
     *
     * Query: workflow (可选，按名称过滤)
     *
     * 每条返回：
     * - budget 配置字段
     * - current: daily_exec_count / hourly_exec_count / avg_duration_ms / daily_api_calls
     * - alerts: [{ metric, current, limit, ratio, exceeded }]
     */
    public function budgetList()
    {
        $workflowFilter = trim((string) Request::input('workflow', ''));

        $where = '';
        $params = [];
        if ($workflowFilter !== '') {
            $where = 'WHERE workflow_name = ?';
            $params[] = $workflowFilter;
        }

        $budgets = Db::select(
            "SELECT id, workflow_name, daily_exec_limit, hourly_exec_limit, avg_duration_limit_ms, daily_api_limit, alert_enabled, alert_webhook_url, alert_webhook_secret, alert_cooldown_min, last_alert_at, escalation_threshold, consecutive_exceeded, created_at, updated_at FROM kode_lowcode_workflow_budgets {$where} ORDER BY workflow_name",
            $params
        );

        $result = [];
        foreach ($budgets as $b) {
            $name = (string) $b['workflow_name'];

            // 当前统计（过去 24h + 过去 1h）
            $stats = Db::select(
                "SELECT
                    SUM(CASE WHEN created_at > NOW() - INTERVAL '24 hours' THEN 1 ELSE 0 END) AS daily_exec,
                    SUM(CASE WHEN created_at > NOW() - INTERVAL '1 hour' THEN 1 ELSE 0 END) AS hourly_exec,
                    AVG(CASE WHEN created_at > NOW() - INTERVAL '24 hours' THEN duration_ms END) AS avg_dur,
                    SUM(CASE WHEN created_at > NOW() - INTERVAL '24 hours' THEN COALESCE(api_calls, 0) END) AS daily_api
                 FROM kode_lowcode_workflow_executions WHERE workflow_name = ?",
                [$name]
            );
            $s = $stats[0] ?? [];
            $dailyExec = (int) ($s['daily_exec'] ?? 0);
            $hourlyExec = (int) ($s['hourly_exec'] ?? 0);
            $avgDur = (float) ($s['avg_dur'] ?? 0);
            $dailyApi = (int) ($s['daily_api'] ?? 0);

            // 构建告警列表
            $alerts = [];
            $addAlert = function (string $metric, int $current, int $limit) use (&$alerts): void {
                if ($limit <= 0) return;
                $ratio = round($current / $limit, 2);
                $exceeded = $current >= $limit;
                $alerts[] = [
                    'metric' => $metric,
                    'current' => $current,
                    'limit' => $limit,
                    'ratio' => $ratio,
                    'exceeded' => $exceeded,
                    'warning' => !$exceeded && $ratio >= 0.8,
                ];
            };
            $addAlert('daily_exec', $dailyExec, (int) $b['daily_exec_limit']);
            $addAlert('hourly_exec', $hourlyExec, (int) $b['hourly_exec_limit']);
            $addAlert('avg_duration', (int) round($avgDur), (int) $b['avg_duration_limit_ms']);
            $addAlert('daily_api', $dailyApi, (int) $b['daily_api_limit']);

            $secretMasked = (string) ($b['alert_webhook_secret'] ?? '') !== '' ? '***' : '';
            $lastAlertAt = $b['last_alert_at'] ?? null;
            $lastAlertAtIso = ($lastAlertAt === null || $lastAlertAt === '') ? null : $lastAlertAt;

            $result[] = [
                'id' => \app\support\HashId::enc((int) $b['id']),
                'workflow_name' => $name,
                'daily_exec_limit' => (int) $b['daily_exec_limit'],
                'hourly_exec_limit' => (int) $b['hourly_exec_limit'],
                'avg_duration_limit_ms' => (int) $b['avg_duration_limit_ms'],
                'daily_api_limit' => (int) $b['daily_api_limit'],
                'alert_enabled' => (int) $b['alert_enabled'],
                'alert_webhook_url' => (string) ($b['alert_webhook_url'] ?? ''),
                'alert_webhook_secret' => $secretMasked,
                'alert_webhook_configured' => (string) ($b['alert_webhook_url'] ?? '') !== '',
                'alert_cooldown_min' => (int) ($b['alert_cooldown_min'] ?? 30),
                'last_alert_at' => $lastAlertAtIso,
                'escalation_threshold' => (int) ($b['escalation_threshold'] ?? 3),
                'consecutive_exceeded' => (int) ($b['consecutive_exceeded'] ?? 0),
                'current' => [
                    'daily_exec' => $dailyExec,
                    'hourly_exec' => $hourlyExec,
                    'avg_duration_ms' => round($avgDur, 1),
                    'daily_api' => $dailyApi,
                ],
                'alerts' => $alerts,
                'exceeded_count' => count(array_filter($alerts, fn($a) => $a['exceeded'])),
            ];
        }

        return $this->ok([
            'total' => count($result),
            'exceeded_workflows' => count(array_filter($result, fn($r) => $r['exceeded_count'] > 0)),
            'budgets' => $result,
        ]);
    }

    /**
     * DELETE /api/workflow/budgets/{workflow}
     * 删除工作流预算配置。
     */
    public function budgetDelete()
    {
        $name = trim((string) $this->param('workflow', ''));
        if ($name === '') {
            return $this->error('workflow 必填', 422);
        }
        Db::update(
            "DELETE FROM kode_lowcode_workflow_budgets WHERE workflow_name = ?",
            [$name]
        );
        return $this->ok(['workflow_name' => $name, 'deleted' => true]);
    }

    /**
     * POST /api/workflow/budgets/alert-check
     * 预算告警检测：评估所有启用了告警的工作流预算，写入告警历史，
     * 并在满足冷却条件时触发 webhook 通知。
     *
     * Body:
     * - force (bool, 可选): true 时忽略冷却期强制发送
     * - workflow (string, 可选): 只检测指定工作流
     *
     * 返回：检测到的超限项、写入的告警条数、成功/失败投递条数。
     */
    public function budgetAlertCheck()
    {
        $force = (bool) Request::input('force', false);
        $workflowOnly = trim((string) Request::input('workflow', ''));

        $where = 'WHERE alert_enabled = 1';
        $params = [];
        if ($workflowOnly !== '') {
            $where .= ' AND workflow_name = ?';
            $params[] = $workflowOnly;
        }

        $budgets = Db::select(
            "SELECT id, workflow_name, daily_exec_limit, hourly_exec_limit, avg_duration_limit_ms, daily_api_limit, alert_webhook_url, alert_webhook_secret, alert_cooldown_min, last_alert_at, escalation_threshold, consecutive_exceeded FROM kode_lowcode_workflow_budgets {$where}",
            $params
        );

        $checked = 0;
        $exceededItems = [];
        $alertsInserted = 0;
        $webhookSent = 0;
        $webhookSkipped = 0;
        $webhookFailed = 0;
        $escalatedCount = 0;

        foreach ($budgets as $b) {
            $checked++;
            $name = (string) $b['workflow_name'];

            $stats = Db::select(
                "SELECT
                    SUM(CASE WHEN created_at > NOW() - INTERVAL '24 hours' THEN 1 ELSE 0 END) AS daily_exec,
                    SUM(CASE WHEN created_at > NOW() - INTERVAL '1 hour' THEN 1 ELSE 0 END) AS hourly_exec,
                    AVG(CASE WHEN created_at > NOW() - INTERVAL '24 hours' THEN duration_ms END) AS avg_dur,
                    SUM(CASE WHEN created_at > NOW() - INTERVAL '24 hours' THEN COALESCE(api_calls, 0) END) AS daily_api
                 FROM kode_lowcode_workflow_executions WHERE workflow_name = ?",
                [$name]
            );
            $s = $stats[0] ?? [];
            $dailyExec = (int) ($s['daily_exec'] ?? 0);
            $hourlyExec = (int) ($s['hourly_exec'] ?? 0);
            $avgDur = (int) round((float) ($s['avg_dur'] ?? 0));
            $dailyApi = (int) ($s['daily_api'] ?? 0);

            $metrics = [
                ['metric' => 'daily_exec', 'current' => $dailyExec, 'limit' => (int) $b['daily_exec_limit']],
                ['metric' => 'hourly_exec', 'current' => $hourlyExec, 'limit' => (int) $b['hourly_exec_limit']],
                ['metric' => 'avg_duration', 'current' => $avgDur, 'limit' => (int) $b['avg_duration_limit_ms']],
                ['metric' => 'daily_api', 'current' => $dailyApi, 'limit' => (int) $b['daily_api_limit']],
            ];

            $thisWorkflowExceeded = [];
            foreach ($metrics as $m) {
                if ($m['limit'] <= 0) {
                    continue;
                }
                $ratio = round($m['current'] / $m['limit'], 2);
                if ($ratio < 1) {
                    continue;
                }
                $level = $ratio >= 1.5 ? 'critical' : 'warning';
                $msg = sprintf(
                    "工作流「%s」%s 指标超限：当前 %d / 上限 %d (%.0f%%)",
                    $name,
                    $m['metric'],
                    $m['current'],
                    $m['limit'],
                    $ratio * 100
                );
                $thisWorkflowExceeded[] = [
                    'metric' => $m['metric'],
                    'current' => $m['current'],
                    'limit' => $m['limit'],
                    'ratio' => $ratio,
                    'level' => $level,
                    'message' => $msg,
                ];
            }

            if (empty($thisWorkflowExceeded)) {
                // 未超限：重置连续超限计数器
                if ((int) $b['consecutive_exceeded'] > 0) {
                    Db::update(
                        "UPDATE kode_lowcode_workflow_budgets SET consecutive_exceeded = 0 WHERE id = ?",
                        [(int) $b['id']]
                    );
                }
                continue;
            }

            // 连续超限升级：计数器 +1，达到阈值后 warning 升级为 critical
            // 读-改-写：从预加载快照读 $b['consecutive_exceeded'] → PHP +1 → 写回。
            // 并发 alert-check 下两个事务都读到同一 count、都写同一 count+1——一个增量丢失。
            // 用 SQL 原子自增替代 PHP 侧读-算-写，再读回新值做升级判定。
            // 同熔断器追踪、budgetSave、definitionUpdate、versionRollback 的加固。
            $escalationThreshold = (int) ($b['escalation_threshold'] ?? 3);

            // 原子自增
            Db::update(
                "UPDATE kode_lowcode_workflow_budgets SET consecutive_exceeded = consecutive_exceeded + 1 WHERE id = ?",
                [(int) $b['id']]
            );

            // 读回新值（原子写已完成，这里只为升级判定取最新值）
            $fresh = Db::select(
                "SELECT consecutive_exceeded FROM kode_lowcode_workflow_budgets WHERE id = ?",
                [(int) $b['id']]
            );
            $consecutive = !empty($fresh) ? (int) $fresh[0]['consecutive_exceeded'] : 0;

            if ($consecutive >= $escalationThreshold) {
                foreach ($thisWorkflowExceeded as &$ex) {
                    if ($ex['level'] === 'warning') {
                        $ex['level'] = 'critical';
                        $ex['message'] .= "（连续 {$consecutive} 次超限，已升级）";
                        $escalatedCount++;
                    }
                }
                unset($ex);
            }

            // 写入告警历史
            foreach ($thisWorkflowExceeded as $item) {
                Db::insert(
                    "INSERT INTO kode_lowcode_workflow_alerts (workflow_name, metric, level, current_value, limit_value, ratio, message, webhook_status, webhook_http_code) VALUES (?, ?, ?, ?, ?, ?, ?, '', 0)",
                    [
                        $name,
                        $item['metric'],
                        $item['level'],
                        $item['current'],
                        $item['limit'],
                        $item['ratio'],
                        $item['message'],
                    ]
                );
                $alertsInserted++;
                $exceededItems[] = $item;
            }

            // 冷却期判断
            $cooldownMin = (int) ($b['alert_cooldown_min'] ?? 30);
            $lastAlertAt = $b['last_alert_at'] ?? null;
            $inCooldown = false;
            if (!$force && $lastAlertAt !== null && $lastAlertAt !== '') {
                $lastTs = strtotime((string) $lastAlertAt);
                if ($lastTs !== false && (time() - $lastTs) < $cooldownMin * 60) {
                    $inCooldown = true;
                }
            }

            // Webhook 投递
            $webhookUrl = trim((string) ($b['alert_webhook_url'] ?? ''));
            $webhookSecret = trim((string) ($b['alert_webhook_secret'] ?? ''));

            if ($webhookUrl === '') {
                $webhookSkipped++;
            } elseif ($inCooldown) {
                $webhookSkipped++;
            } else {
                $topLevel = 'warning';
                foreach ($thisWorkflowExceeded as $ex) {
                    if ($ex['level'] === 'critical') {
                        $topLevel = 'critical';
                        break;
                    }
                }
                $payload = json_encode([
                    'event' => 'workflow_budget_alert',
                    'workflow' => $name,
                    'level' => $topLevel,
                    'items' => $thisWorkflowExceeded,
                    'checked_at' => date('Y-m-d H:i:s'),
                ], JSON_UNESCAPED_UNICODE);

                $delivery = $this->deliverWebhook($webhookUrl, $payload, $webhookSecret);
                $ws = $delivery['status'];
                $whCode = (int) $delivery['http_code'];
                if ($ws === 'delivered') {
                    $webhookSent++;
                } else {
                    $webhookFailed++;
                }

                // 回写告警记录的投递状态（取本次最新插入的 id）
                $lastInserted = Db::select(
                    "SELECT MAX(id) AS max_id FROM kode_lowcode_workflow_alerts WHERE workflow_name = ? AND created_at > NOW() - INTERVAL '2 minutes'",
                    [$name]
                );
                $lastId = $lastInserted[0]['max_id'] ?? 0;
                if ($lastId) {
                    Db::update(
                        "UPDATE kode_lowcode_workflow_alerts SET webhook_status = ?, webhook_http_code = ? WHERE id <= ? AND workflow_name = ? AND webhook_status = ''",
                        [$ws, $whCode, $lastId, $name]
                    );
                }

                // 更新 last_alert_at
                Db::update(
                    "UPDATE kode_lowcode_workflow_budgets SET last_alert_at = NOW() WHERE id = ?",
                    [(int) $b['id']]
                );
            }
        }

        return $this->ok([
            'checked' => $checked,
            'exceeded_count' => count($exceededItems),
            'alerts_inserted' => $alertsInserted,
            'webhook_sent' => $webhookSent,
            'webhook_skipped' => $webhookSkipped,
            'webhook_failed' => $webhookFailed,
            'escalated_count' => $escalatedCount,
            'items' => $exceededItems,
        ]);
    }

    /**
     * GET /api/workflow/alerts
     * 告警历史查询。
     *
     * Query:
     * - workflow (可选): 按工作流过滤
     * - level (可选): warning / critical
     * - days (可选，默认 7): 时间窗口（天）
     * - limit (可选，默认 100): 返回条数上限（最大 500）
     */
    public function alertHistory()
    {
        $workflow = trim((string) Request::input('workflow', ''));
        $level = trim((string) Request::input('level', ''));
        $days = (int) Request::input('days', 7);
        $limit = (int) Request::input('limit', 100);

        $days = max(1, min(90, $days));
        $limit = max(1, min(500, $limit));

        $where = "WHERE created_at > NOW() - INTERVAL '{$days} days'";
        $params = [];
        if ($workflow !== '') {
            $where .= ' AND workflow_name = ?';
            $params[] = $workflow;
        }
        if ($level !== '') {
            $where .= ' AND level = ?';
            $params[] = $level;
        }

        // 总数与等级分布算在**筛选后的整个时间窗**上。此前它们在下面那批
        // `LIMIT ?` 截断的行里数出来的：`limit=1` 时「告警总数」恒为 1，
        // 两张分布卡也跟着数这一行 —— 数字看着正常，含义已经换了（AGENTS.md §4）。
        $summary = Db::select(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE level = 'warning') AS warning,
                    COUNT(*) FILTER (WHERE level = 'critical') AS critical
               FROM kode_lowcode_workflow_alerts {$where}",
            $params
        )[0] ?? [];
        $total = (int) ($summary['total'] ?? 0);

        // 排行同样由 SQL 排序。旧写法是 krsort($workflowCounts, SORT_NUMERIC)：
        // 键是工作流名（字符串），按数值规则比等于两两相等，实测整趟排序没动过任何一项，
        // 「告警 TOP 工作流」其实是「最近 N 行里第一次出现的顺序」，告警最多的那个能排到榜尾。
        $ranking = Db::select(
            "SELECT workflow_name, COUNT(*) AS cnt FROM kode_lowcode_workflow_alerts {$where}
              GROUP BY workflow_name ORDER BY cnt DESC, workflow_name ASC LIMIT 10",
            $params
        );

        $rows = Db::select(
            "SELECT id, workflow_name, metric, level, current_value, limit_value, ratio, message, webhook_status, webhook_http_code, created_at FROM kode_lowcode_workflow_alerts {$where} ORDER BY created_at DESC, id DESC LIMIT ?",
            array_merge($params, [$limit])
        );

        $result = [];
        foreach ($rows as $r) {
            $lvl = (string) $r['level'];
            $wn = (string) $r['workflow_name'];
            $result[] = [
                'id' => \app\support\HashId::enc((int) $r['id']),
                'workflow_name' => $wn,
                'metric' => (string) $r['metric'],
                'level' => $lvl,
                'current_value' => (int) $r['current_value'],
                'limit_value' => (int) $r['limit_value'],
                'ratio' => (float) $r['ratio'],
                'message' => (string) $r['message'],
                'webhook_status' => (string) $r['webhook_status'],
                'webhook_http_code' => (int) $r['webhook_http_code'],
                'created_at' => (string) $r['created_at'],
            ];
        }

        $topWorkflows = [];
        foreach ($ranking as $r) {
            $topWorkflows[] = ['workflow_name' => (string) $r['workflow_name'], 'count' => (int) $r['cnt']];
        }

        return $this->ok([
            'total' => $total,
            'days' => $days,
            'level_counts' => [
                'warning' => (int) ($summary['warning'] ?? 0),
                'critical' => (int) ($summary['critical'] ?? 0),
            ],
            'top_workflows' => $topWorkflows,
            // 列表是被 limit 截断的最近若干条，得把这件事说出来：
            // 少了它，「窗口里 500 条只列了 200 条」和「窗口里就 200 条」在页面上长得一样。
            'truncated' => $total > count($result),
            'alerts' => $result,
        ]);
    }

    /**
     * GET /api/workflow/step-regression
     * 步骤性能回归检测：对比当前周期（近 N 天）与上一周期（N 天前 ~ 2N 天前），
     * 检测 avg_ms 和 p95_ms 的环比变化，标记超过阈值的回归。
     *
     * Query:
     * - days (可选，默认 7): 当前周期天数（上一周期同样长度）
     * - workflow (可选): 按工作流过滤
     * - threshold (可选，默认 50): 回归阈值百分比（avg_ms 增幅）
     * - min_count (可选，默认 3): 最小样本数（低于此值的步骤跳过）
     *
     * 返回：回归列表 + 汇总统计 + 写入检测记录
     */
    public function stepRegression()
    {
        $days = max(1, min(30, (int) Request::input('days', 7)));
        $workflowFilter = trim((string) Request::input('workflow', ''));
        $threshold = max(1, min(1000, (int) Request::input('threshold', 50)));
        $minCount = max(1, (int) Request::input('min_count', 3));
        $days2 = $days * 2;

        $whereWf = '';
        $params = [];
        if ($workflowFilter !== '') {
            $whereWf = ' AND workflow_name = ?';
            $params[] = $workflowFilter;
        }

        // 当前周期 vs 上一周期
        [$failedSql] = self::stepOutcomeSql('value');
        $sql = "
            WITH exploded AS (
                SELECT
                    e.workflow_name,
                    e.id AS exec_id,
                    e.created_at,
                    key AS step_name,
                    (value->>'_duration_ms')::numeric AS duration_ms,
                    {$failedSql} AS failed
                FROM kode_lowcode_workflow_executions e,
                     LATERAL json_each(COALESCE(e.step_results_json, '{}')::json) AS kv(key, value)
                WHERE e.step_results_json <> '{}'
                  AND e.created_at > NOW() - INTERVAL '{$days2} days'
                  {$whereWf}
            ),
            current_period AS (
                SELECT workflow_name, step_name,
                    COUNT(*) AS cnt,
                    ROUND(AVG(duration_ms)::numeric, 1) AS avg_ms,
                    ROUND((PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms))::numeric, 1) AS p95_ms
                FROM exploded
                WHERE created_at > NOW() - INTERVAL '{$days} days'
                GROUP BY workflow_name, step_name
                HAVING COUNT(*) >= {$minCount}
            ),
            previous_period AS (
                SELECT workflow_name, step_name,
                    COUNT(*) AS cnt,
                    ROUND(AVG(duration_ms)::numeric, 1) AS avg_ms,
                    ROUND((PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms))::numeric, 1) AS p95_ms
                FROM exploded
                WHERE created_at > NOW() - INTERVAL '{$days2} days'
                  AND created_at <= NOW() - INTERVAL '{$days} days'
                GROUP BY workflow_name, step_name
            ),
            compared AS (
                SELECT
                    c.workflow_name,
                    c.step_name,
                    c.avg_ms AS current_avg_ms,
                    p.avg_ms AS previous_avg_ms,
                    COALESCE((c.avg_ms - p.avg_ms) / NULLIF(p.avg_ms, 0) * 100, 0) AS change_pct,
                    c.p95_ms AS current_p95_ms,
                    COALESCE(p.p95_ms, 0) AS previous_p95_ms,
                    c.cnt AS current_count,
                    COALESCE(p.cnt, 0) AS previous_count
                FROM current_period c
                JOIN previous_period p ON c.workflow_name = p.workflow_name AND c.step_name = p.step_name
                WHERE p.avg_ms > 0
                  AND (c.avg_ms - p.avg_ms) / NULLIF(p.avg_ms, 0) * 100 >= {$threshold}
            )
            SELECT * FROM compared
            ORDER BY change_pct DESC
        ";

        $rows = Db::select($sql, $params);

        $regressions = [];
        foreach ($rows as $r) {
            $changePct = round((float) $r['change_pct'], 2);
            $level = $changePct >= 200 ? 'critical' : ($changePct >= 100 ? 'warning' : 'info');
            $regressions[] = [
                'workflow_name' => (string) $r['workflow_name'],
                'step_name' => (string) $r['step_name'],
                'current_avg_ms' => (float) $r['current_avg_ms'],
                'previous_avg_ms' => (float) $r['previous_avg_ms'],
                'change_pct' => $changePct,
                'current_p95_ms' => (float) $r['current_p95_ms'],
                'previous_p95_ms' => (float) $r['previous_p95_ms'],
                'current_count' => (int) $r['current_count'],
                'previous_count' => (int) $r['previous_count'],
                'level' => $level,
            ];
            Db::insert(
                "INSERT INTO kode_lowcode_workflow_step_regressions (workflow_name, step_name, current_avg_ms, previous_avg_ms, change_pct, current_p95_ms, previous_p95_ms, current_count, previous_count, level) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $regressions[count($regressions) - 1]['workflow_name'],
                    $regressions[count($regressions) - 1]['step_name'],
                    $regressions[count($regressions) - 1]['current_avg_ms'],
                    $regressions[count($regressions) - 1]['previous_avg_ms'],
                    $changePct,
                    $regressions[count($regressions) - 1]['current_p95_ms'],
                    $regressions[count($regressions) - 1]['previous_p95_ms'],
                    $regressions[count($regressions) - 1]['current_count'],
                    $regressions[count($regressions) - 1]['previous_count'],
                    $level,
                ]
            );
        }

        // 等级统计
        $levelCounts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($regressions as $r) {
            if (isset($levelCounts[$r['level']])) $levelCounts[$r['level']]++;
        }

        return $this->ok([
            'total' => count($regressions),
            'days' => $days,
            'threshold_pct' => $threshold,
            'min_count' => $minCount,
            'level_counts' => $levelCounts,
            'regressions' => $regressions,
        ]);
    }

    /**
     * POST /api/workflow/executions/chain-resolve
     * 重试链剪枝：遍历所有重试链，标记 chain_status。
     *
     * chain_status 取值：
     * - 'root': 无重试的独立执行
     * - 'resolved': 链中最后一次重试 succeeded
     * - 'exhausted': 链中所有重试均 failed
     * - 'pending': 链中有 queued/running 的重试
     *
     * 返回：各状态统计 + 已更新的执行数
     */
    public function chainResolve()
    {
        // 1. 标记有重试的子执行（depth > 0）为 chain_status='retry'
        // 2. 标记无重试的独立执行为 'root'
        $updated = 0;
        $result = ['root' => 0, 'resolved' => 0, 'exhausted' => 0, 'pending' => 0];

        // 找到所有有子执行（重试）的父执行
        $parents = Db::select(
            "SELECT DISTINCT parent_execution_id FROM kode_lowcode_workflow_executions WHERE parent_execution_id > 0"
        );

        // 先标记所有 depth=0 且没有子执行（重试）的为 'root'
        Db::update(
            "UPDATE kode_lowcode_workflow_executions SET chain_status = 'root' WHERE retry_depth = 0 AND id NOT IN (SELECT DISTINCT parent_execution_id FROM kode_lowcode_workflow_executions WHERE parent_execution_id > 0)"
        );
        $result['root'] = (int) Db::select("SELECT COUNT(*) AS cnt FROM kode_lowcode_workflow_executions WHERE chain_status = 'root'")[0]['cnt'];

        // 对每个父执行，根据子执行状态标记链的最终状态
        foreach ($parents as $p) {
            $parentId = (int) $p['parent_execution_id'];

            // 获取所有子执行（该父执行的所有重试）
            $children = Db::select(
                "SELECT id, status, created_at FROM kode_lowcode_workflow_executions WHERE parent_execution_id = ? ORDER BY created_at DESC",
                [$parentId]
            );

            if (empty($children)) continue;

            // 取最新的子执行状态决定链状态
            $latest = $children[0];
            $latestStatus = (string) $latest['status'];

            if (in_array($latestStatus, ['queued', 'running'], true)) {
                $chainStatus = 'pending';
            } elseif ($latestStatus === 'succeeded') {
                $chainStatus = 'resolved';
            } else {
                $chainStatus = 'exhausted';
            }

            // 更新父执行和所有子执行的 chain_status
            Db::update(
                "UPDATE kode_lowcode_workflow_executions SET chain_status = ? WHERE id = ? OR parent_execution_id = ?",
                [$chainStatus, $parentId, $parentId]
            );
            $result[$chainStatus]++;
            $updated++;
        }

        // 标记所有重试子执行（depth > 0）的 chain_status 同步为父执行的状态
        Db::update(
            "UPDATE kode_lowcode_workflow_executions e SET chain_status = (SELECT COALESCE(p.chain_status, 'retry') FROM kode_lowcode_workflow_executions p WHERE p.id = e.parent_execution_id) WHERE e.retry_depth > 0"
        );

        return $this->ok([
            'updated_chains' => $updated,
            'result' => $result,
        ]);
    }

    /**
     * GET /api/workflow/step-regressions
     * 回归检测历史查询。
     *
     * Query:
     * - workflow (可选): 按工作流过滤
     * - level (可选): critical/warning/info
     * - days (可选，默认 30): 时间窗口
     * - limit (可选，默认 100): 返回上限
     */
    public function stepRegressionHistory()
    {
        $workflow = trim((string) Request::input('workflow', ''));
        $level = trim((string) Request::input('level', ''));
        $days = max(1, min(90, (int) Request::input('days', 30)));
        $limit = max(1, min(500, (int) Request::input('limit', 100)));

        $where = "WHERE detected_at > NOW() - INTERVAL '{$days} days'";
        $params = [];
        if ($workflow !== '') {
            $where .= ' AND workflow_name = ?';
            $params[] = $workflow;
        }
        if ($level !== '') {
            $where .= ' AND level = ?';
            $params[] = $level;
        }

        // 总数与等级分布算在筛选后的整个窗口上（同 alertHistory：旧写法数的是下面被
        // `LIMIT ?` 截断的那几行，页面三张等级卡于是跟着 limit 一起缩）。
        $summary = Db::select(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE level = 'critical') AS critical,
                    COUNT(*) FILTER (WHERE level = 'warning') AS warning,
                    COUNT(*) FILTER (WHERE level = 'info') AS info
               FROM kode_lowcode_workflow_step_regressions {$where}",
            $params
        )[0] ?? [];
        $total = (int) ($summary['total'] ?? 0);

        $rows = Db::select(
            "SELECT id, workflow_name, step_name, current_avg_ms, previous_avg_ms, change_pct, current_p95_ms, previous_p95_ms, current_count, previous_count, level, detected_at FROM kode_lowcode_workflow_step_regressions {$where} ORDER BY detected_at DESC, id DESC LIMIT ?",
            array_merge($params, [$limit])
        );

        $result = [];
        foreach ($rows as $r) {
            $lvl = (string) $r['level'];
            $result[] = [
                'id' => \app\support\HashId::enc((int) $r['id']),
                'workflow_name' => (string) $r['workflow_name'],
                'step_name' => (string) $r['step_name'],
                'current_avg_ms' => (float) $r['current_avg_ms'],
                'previous_avg_ms' => (float) $r['previous_avg_ms'],
                'change_pct' => (float) $r['change_pct'],
                'current_p95_ms' => (float) $r['current_p95_ms'],
                'previous_p95_ms' => (float) $r['previous_p95_ms'],
                'current_count' => (int) $r['current_count'],
                'previous_count' => (int) $r['previous_count'],
                'level' => $lvl,
                'detected_at' => (string) $r['detected_at'],
            ];
        }

        return $this->ok([
            'total' => $total,
            'days' => $days,
            'level_counts' => [
                'critical' => (int) ($summary['critical'] ?? 0),
                'warning' => (int) ($summary['warning'] ?? 0),
                'info' => (int) ($summary['info'] ?? 0),
            ],
            'truncated' => $total > count($result),
            'regressions' => $result,
        ]);
    }

    /**
     * GET /api/workflow/dependency-graph
     * 跨工作流依赖图：解析所有工作流定义中的 sub_workflow 步骤，
     * 构建 parent → child 有向图，附带执行统计。
     *
     * 返回：nodes (工作流) + edges (调用关系) + 统计
     */
    public function dependencyGraph()
    {
        $workflows = Db::select("SELECT id, name, steps_json FROM kode_lowcode_workflows ORDER BY id");

        $nodes = [];
        $edges = [];
        $callSites = []; // workflow_name => [被调名…]（每处调用一个元素）

        foreach ($workflows as $wf) {
            $name = (string) $wf['name'];
            $steps = json_decode($wf['steps_json'] ?? '[]', true) ?? [];
            $nodeStepTypes = array_count_values(array_column($steps, 'type'));
            $nodeStepsCount = count($steps);

            $nodeExecs = Db::select(
                "SELECT COUNT(*) AS cnt, ROUND(AVG(duration_ms)::numeric, 1) AS avg_ms, ROUND((PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms))::numeric, 1) AS p95_ms,
                        SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succ, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS fail
                 FROM kode_lowcode_workflow_executions WHERE workflow_name = ? AND created_at > NOW() - INTERVAL '7 days'",
                [$name]
            );
            $execs = $nodeExecs[0] ?? [];
            $cnt = (int) ($execs['cnt'] ?? 0);
            $succ = (int) ($execs['succ'] ?? 0);
            $fail = (int) ($execs['fail'] ?? 0);

            $nodes[] = [
                'id' => \app\support\HashId::enc((int) $wf['id']),
                'name' => $name,
                'step_count' => $nodeStepsCount,
                'step_types' => $nodeStepTypes,
                'exec_count_7d' => $cnt,
                'avg_ms' => (float) ($execs['avg_ms'] ?? 0),
                'p95_ms' => (float) ($execs['p95_ms'] ?? 0),
                'success_rate' => $cnt > 0 ? round($succ / $cnt * 100, 1) : 0,
                'failed_count' => $fail,
            ];

            // 「这一枚工作流调了谁」由 extractSubWorkflowsFromSteps() 判一次（含子步骤，
            // 每处调用一个元素，所以它同时是下面的 call_sites 计数来源）。
            $calls = $this->extractSubWorkflowsFromSteps($steps, []);
            $callSites[$name] = $calls;
            foreach ($calls as $child) {
                $edgeKey = $name . '→' . $child;
                if (!in_array($edgeKey, $edges, true)) {
                    $edges[] = $edgeKey;
                }
            }
        }

        // 构建 edges 数组（带统计）
        $edgeList = [];
        foreach ($edges as $edgeKey) {
            [$from, $to] = explode('→', $edgeKey);
            // 该调用关系在定义里出现几处（含躲在 loop/switch 里的那些）。
            $callCount = count(array_filter($callSites[$from] ?? [], fn (string $t): bool => $t === $to));
            $edgeList[] = [
                'from' => $from,
                'to' => $to,
                'call_sites' => $callCount,
            ];
        }

        return $this->ok([
            'nodes' => $nodes,
            'edges' => $edgeList,
            'total_workflows' => count($nodes),
            'total_edges' => count($edgeList),
            'workflows_with_deps' => count($edgeList),
        ]);
    }

    /**
     * GET /api/workflow/sla-report
     * 工作流 SLA 报表：对比当前表现与目标，返回合规状态。
     *
     * Query:
     * - days (可选，默认 7): 统计窗口
     *
     * 返回：每个工作流的 SLA 目标、当前表现、合规状态
     */
    public function slaReport()
    {
        $days = max(1, min(90, (int) Request::input('days', 7)));

        $targets = Db::select(
            "SELECT workflow_name, target_success_rate, target_p95_ms, target_p50_ms, target_daily_min, enabled FROM kode_lowcode_workflow_sla_targets WHERE enabled = 1 ORDER BY workflow_name"
        );

        // 默认覆盖：取过去 days 内所有出现过的工作流作为默认目标，
        // 若已有配置则以配置为准（配置优先覆盖默认）。
        $execNames = Db::select(
            "SELECT DISTINCT workflow_name FROM kode_lowcode_workflow_executions WHERE created_at > NOW() - INTERVAL '{$days} days' ORDER BY workflow_name"
        );
        $byName = [];
        $customNames = [];
        foreach ($execNames as $en) {
            $byName[(string) $en['workflow_name']] = [
                'workflow_name' => (string) $en['workflow_name'],
                'target_success_rate' => 99.0,
                'target_p95_ms' => 500,
                'target_p50_ms' => 100,
                'target_daily_min' => 0,
                'enabled' => 1,
            ];
        }
        foreach ($targets as $t) {
            $byName[(string) $t['workflow_name']] = $t;
            $customNames[(string) $t['workflow_name']] = true;
        }
        $mergedTargets = array_values($byName);

        $result = [];
        $compliant = 0;
        $breached = 0;

        foreach ($mergedTargets as $t) {
            $name = (string) $t['workflow_name'];
            $targetSR = (float) $t['target_success_rate'];
            $targetP95 = (int) $t['target_p95_ms'];
            $targetP50 = (int) $t['target_p50_ms'];
            $targetDaily = (int) $t['target_daily_min'];

            $stats = Db::select(
                "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succ,
                    ROUND(AVG(duration_ms)::numeric, 1) AS avg_ms,
                    ROUND((PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY duration_ms))::numeric, 1) AS p50_ms,
                    ROUND((PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms))::numeric, 1) AS p95_ms,
                    SUM(CASE WHEN created_at > NOW() - INTERVAL '24 hours' THEN 1 ELSE 0 END) AS daily
                 FROM kode_lowcode_workflow_executions WHERE workflow_name = ? AND created_at > NOW() - INTERVAL '{$days} days'",
                [$name]
            );
            $s = $stats[0] ?? [];
            $total = (int) ($s['total'] ?? 0);
            $succ = (int) ($s['succ'] ?? 0);
            $actualSR = $total > 0 ? round($succ / $total * 100, 1) : 0;
            $actualP95 = (float) ($s['p95_ms'] ?? 0);
            $actualP50 = (float) ($s['p50_ms'] ?? 0);
            $actualDaily = (int) ($s['daily'] ?? 0);

            // SLA 合规判断
            $checks = [
                ['metric' => 'success_rate', 'target' => $targetSR, 'actual' => $actualSR, 'met' => $actualSR >= $targetSR],
                ['metric' => 'p95_ms', 'target' => $targetP95, 'actual' => $actualP95, 'met' => $actualP95 <= $targetP95],
                ['metric' => 'p50_ms', 'target' => $targetP50, 'actual' => $actualP50, 'met' => $actualP50 <= $targetP50],
                ['metric' => 'daily_min', 'target' => $targetDaily, 'actual' => $actualDaily, 'met' => $actualDaily >= $targetDaily],
            ];
            $allMet = !in_array(false, array_column($checks, 'met'), true);
            $metCount = count(array_filter($checks, fn($c) => $c['met']));

            if ($allMet) $compliant++; else $breached++;

            $result[] = [
                'workflow_name' => $name,
                'compliant' => $allMet,
                'checks_met' => $metCount,
                'checks_total' => count($checks),
                'checks' => $checks,
                'total' => $total,
                'target_source' => isset($customNames[$name]) ? 'custom' : 'default',
                'success_rate' => $actualSR,
                'avg_ms' => (float) ($s['avg_ms'] ?? 0),
                'p50_ms' => $actualP50,
                'p95_ms' => $actualP95,
                'daily_exec' => $actualDaily,
            ];
        }

        return $this->ok([
            'days' => $days,
            'total' => count($result),
            'compliant' => $compliant,
            'breached' => $breached,
            'compliance_rate' => count($result) > 0 ? round($compliant / count($result) * 100, 1) : 0,
            'workflows' => $result,
        ]);
    }

    /**
     * POST /api/workflow/sla-targets
     * 保存工作流 SLA 目标配置（upsert）。
     *
     * Body: { workflow_name, target_success_rate, target_p95_ms, target_p50_ms, target_daily_min, enabled }
     */
    public function slaTargetSave()
    {
        $body = Request::input();
        $name = trim((string) ($body['workflow_name'] ?? ''));
        if ($name === '' || !preg_match('/^[a-z0-9_]{1,64}$/', $name)) {
            return $this->error('workflow_name 必填且仅允许小写字母/数字/下划线', 422);
        }
        // 与 alertRuleSave / budgetSave 同族：sla_targets 没有独立 DELETE endpoint，全靠主表
        // 级联清理，因此创建侧必须把「目标 workflow 存在」这道关堵住，否则主表一删就是孤儿。
        $wfExists = Db::select(
            "SELECT id FROM kode_lowcode_workflows WHERE name = ?",
            [$name]
        );
        if (empty($wfExists)) {
            return $this->error('关联的工作流不存在', 404);
        }

        $targetSR = min(100, max(0, (float) ($body['target_success_rate'] ?? 99.0)));
        $targetP95 = max(1, (int) ($body['target_p95_ms'] ?? 500));
        $targetP50 = max(1, (int) ($body['target_p50_ms'] ?? 100));
        $targetDaily = max(0, (int) ($body['target_daily_min'] ?? 0));
        $enabled = isset($body['enabled']) ? (int) (bool) $body['enabled'] : 1;

        // 唯一键 `workflow_name` 真实存在（`kode_lowcode_workflow_sla_targets_workflow_name_key`）：
        // 两个人同时给同一条工作流存 SLA 目标时，旧写法「SELECT 说没有 → 裸 INSERT」的第二个人
        // 撞 23505 → 未捕获 → 500。一行 `ON CONFLICT` 同时表达「有就改、没有就建」。
        AtomicUpsert::run(
            'kode_lowcode_workflow_sla_targets',
            [
                'workflow_name' => $name,
                'target_success_rate' => $targetSR,
                'target_p95_ms' => $targetP95,
                'target_p50_ms' => $targetP50,
                'target_daily_min' => $targetDaily,
                'enabled' => $enabled,
            ],
            ['workflow_name'],
            null,
            ['updated_at' => 'NOW()']
        );

        return $this->ok([
            'workflow_name' => $name,
            'target_success_rate' => $targetSR,
            'target_p95_ms' => $targetP95,
            'target_p50_ms' => $targetP50,
            'target_daily_min' => $targetDaily,
            'enabled' => $enabled,
        ]);
    }

    /* ===================== 工作流导入导出 ===================== */

    /**
     * GET /api/workflow/definitions/{id}/export
     * 导出工作流为 JSON（用于备份/分享/跨环境迁移）。
     */
    public function definitionExport()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('工作流不存在', 404);
        }
        $rows = Db::select("SELECT * FROM kode_lowcode_workflows WHERE id = ?", [$id]);
        if (empty($rows)) {
            return $this->error('工作流不存在', 404);
        }
        $wf = $rows[0];
        $steps = json_decode($wf['steps_json'] ?? '[]', true) ?? [];
        $onFailSteps = json_decode($wf['on_fail_json'] ?? '[]', true) ?? [];

        $export = [
            'version' => '1.0',
            'exported_at' => date('Y-m-d H:i:s'),
            'workflow' => [
                'name' => $wf['name'],
                'description' => $wf['description'] ?? '',
                'steps' => $steps,
                'on_fail_steps' => $onFailSteps,
                'timeout_ms' => (int) ($wf['timeout_ms'] ?? 30000),
                'status' => (int) $wf['status'],
            ],
        ];

        return $this->ok($export);
    }

    /**
     * POST /api/workflow/definitions/import
     * 从 JSON 导入工作流定义（备份恢复/跨环境迁移）。
     *
     * 请求体：{ "name": "可选覆盖名", "description": "可选覆盖描述", "workflow": { ... } }
     * 或完整导出 JSON。
     */
    public function definitionImport()
    {
        $body = Request::input();
        $overrideName = trim((string) ($body['name'] ?? ''));
        $overrideDesc = (string) ($body['description'] ?? '');
        $wfData = $body['workflow'] ?? $body;

        if (!isset($wfData['steps']) || !is_array($wfData['steps'])) {
            return $this->error('JSON 格式无效：缺少 steps 数组', 422);
        }

        $name = $overrideName !== '' ? $overrideName : (string) ($wfData['name'] ?? '');
        if ($name === '') {
            return $this->error('工作流名称不能为空', 422);
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $name)) {
            return $this->error('name 白名单字符集 [a-zA-Z0-9_-]，长度 2-64', 422);
        }

        $steps = $wfData['steps'];
        $onFailSteps = $wfData['on_fail_steps'] ?? [];
        if (!is_array($onFailSteps)) {
            $onFailSteps = [];
        }
        $timeoutMs = (int) ($wfData['timeout_ms'] ?? 30000);
        $timeoutMs = max(1000, min(300000, $timeoutMs));
        $description = $overrideDesc !== '' ? $overrideDesc : (string) ($wfData['description'] ?? '');
        if (mb_strlen($description) > 1000) {
            return $this->error('description 超过 1000 字符限制', 422);
        }

        // 校验步骤
        $validationError = $this->validateSteps($steps);
        if ($validationError !== null) {
            return $this->error("步骤校验失败: {$validationError}", 422);
        }
        $validationError = $this->validateSteps($onFailSteps);
        if ($validationError !== null) {
            return $this->error("on_fail_steps 校验失败: {$validationError}", 422);
        }

        $owner = $this->owner();

        // 检查是否已存在
        $existing = Db::select("SELECT id FROM kode_lowcode_workflows WHERE name = ?", [$name]);
        if (!empty($existing)) {
            return $this->error("工作流名称已存在: {$name}", 409);
        }

        // 插入
        $lastId = Db::insert(
            "INSERT INTO kode_lowcode_workflows (name, description, steps_json, on_fail_json, step_count, status, timeout_ms, version, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $name,
                $description,
                json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($onFailSteps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                count($steps),
                (int) ($wfData['status'] ?? 1),
                $timeoutMs,
                '1.0.0',
                $owner,
            ]
        );

        return $this->ok([
            'id' => \app\support\HashId::enc((int) $lastId),
            'name' => $name,
            'description' => $description,
            'step_count' => count($steps),
            'on_fail_count' => count($onFailSteps),
            'timeout_ms' => $timeoutMs,
            'message' => '工作流导入成功',
        ], 201);
    }

    /* ===================== 定时调度 ===================== */

    /**
     * GET /api/workflow/scheduled
     * 列出所有设置了 Cron 调度的工作流。
     */
    public function scheduledList()
    {
        $items = Db::select(
            "SELECT id, name, description, schedule_cron, next_run_at, last_run_at, status, step_count, updated_at
             FROM kode_lowcode_workflows
             WHERE schedule_cron <> '' AND status = 1
             ORDER BY next_run_at ASC NULLS LAST"
        );
        // 出参 id 混淆
        $items = array_map(static fn (array $row): array => self::hashOut($row), $items);
        return $this->ok($items);
    }

    /**
     * POST /api/workflow/scheduled/run-due
     * 执行所有到期的定时工作流（可由外部 cron 定时调用此端点）。
     * 返回执行结果摘要。
     */
    public function scheduledRunDue()
    {
        $due = Db::select(
            "SELECT id, name, schedule_cron, steps_json, on_fail_json, timeout_ms
             FROM kode_lowcode_workflows
             WHERE schedule_cron <> '' AND status = 1
               AND (next_run_at IS NULL OR next_run_at <= NOW())"
        );

        $results = [];
        foreach ($due as $wf) {
            $workflowName = $wf['name'];
            $params = [];

            // 执行工作流
            $steps = json_decode($wf['steps_json'] ?? '[]', true) ?? [];
            $onFailSteps = json_decode($wf['on_fail_json'] ?? '[]', true) ?? [];
            $timeoutMs = (int) ($wf['timeout_ms'] ?? 30000);

            $start = microtime(true);
            $traceId = $this->traceId();
            $context = self::stepContext((string) $workflowName, $params, $traceId);
            $deadline = $start + $timeoutMs / 1000.0;
            $stepResults = [];
            $status = 'succeeded';
            $error = '';

            foreach ($steps as $i => $step) {
                if ($deadline > 0 && microtime(true) > $deadline) {
                    $status = 'failed';
                    $error = "工作流超时 ({$timeoutMs}ms)";
                    break;
                }
                $stepStart = microtime(true);
                $type = $step['type'] ?? '';
                $name = $step['name'] ?? "step_{$i}";
                $config = $step['config'] ?? [];
                $config['name'] = $name;
                $config['type'] = $type;

                $stepResult = $this->executeStep($type, $config, $context, 0, $deadline);
                $stepDuration = (int) round((microtime(true) - $stepStart) * 1000);
                $stepResult['duration_ms'] = $stepDuration;
                [$stepOk, $stepFailed] = self::stepOutcome($stepResult);
                $stepResult['success'] = $stepOk;

                // 同 `batchRetryExecutions`：步骤的输出就是它自己那份结果，
                // 旧写法读 `$stepResult['result']` 恒假，于是定时跑出来的每一步
                // 都拿不到前序上下文，而执行记录照旧落 succeeded。
                $context[$name] = ['result' => $stepResult];
                $stepResults[$name] = $stepResult;

                // 步骤级失败检查（与上面落库的 success 同一个判据）
                if ($stepFailed && ($step['on_error'] ?? 'stop') === 'stop') {
                    $status = 'failed';
                    $error = self::stepFailureText($stepResult);
                    $context['__error__'] = ['message' => $error];
                    if (!empty($onFailSteps)) {
                        $failResult = $this->executeFailSteps($onFailSteps, $context, 0, $deadline, 'workflow');
                        $stepResults['__on_fail__'] = $failResult;
                    }
                    break;
                }
            }

            $durationMs = (int) round((microtime(true) - $start) * 1000);

            // 记录执行日志
            $execId = $this->logExecution(
                $workflowName,
                (int) $wf['id'],
                $params,
                $stepResults,
                $status,
                $traceId,
                $durationMs,
                $error
            );

            // 计算下次执行时间并更新。cron 点位由进程算出（epoch 秒），
            // 落库那一段交给数据库解释：`next_run_at <= NOW()` 判到期，
            // 交一枚无时区串进去会让派发时刻随 δ 整体前移/后移。
            $nextRun = $this->nextCronTime($wf['schedule_cron'], time());
            $nextRunAt = null;
            if ($nextRun !== null) {
                $instant = DbClock::instant($nextRun);
                Db::update(
                    "UPDATE kode_lowcode_workflows SET last_run_at = NOW(), next_run_at = {$instant['sql']} WHERE id = ?",
                    [$instant['binding'], (int) $wf['id']]
                );
                $nextRunAt = $nextRun;
            } else {
                Db::update(
                    'UPDATE kode_lowcode_workflows SET last_run_at = NOW(), next_run_at = NULL WHERE id = ?',
                    [(int) $wf['id']]
                );
            }

            $results[] = [
                'id' => \app\support\HashId::enc((int) $wf['id']),
                'name' => $workflowName,
                'status' => $status,
                'duration_ms' => $durationMs,
                'execution_id' => \app\support\HashId::enc((int) $execId),
                'next_run_at' => $nextRunAt === null ? null : date('Y-m-d H:i:s', $nextRunAt),
                'error' => $error,
            ];
        }

        return $this->ok(['triggered' => count($results), 'results' => $results]);
    }

    /**
     * 解析 Cron 表达式，计算下次执行时间。
     * 支持格式：每分钟 "every/N * * *"、每小时 "0 every/N * * *"、每日 "M H * * *"、每周 "M H * * D"
     * 标准 5 字段：分 时 日 月 周
     *
     * 入参与返回都是 **epoch 秒（绝对瞬间）**，不是墙钟串。cron 表达式只能由进程算出点位，
     * 但算出的那点位的**落库**必须交给数据库解释（`DbClock::instant()`）：交回一枚无时区串
     * 会让 `next_run_at` 随进程时区与库会话时区的差 δ 整体漂移，定时工作流因此提前/延后 δ 派发。
     *
     * @param string $cron  Cron 表达式
     * @param int    $from  基准瞬间（epoch 秒）
     * @return int|null     下次执行的绝对瞬间（epoch 秒），无效返回 null
     */
    private function nextCronTime(string $cron, int $from): ?int
    {
        $parts = preg_split('/\s+/', trim($cron));
        if (count($parts) !== 5) {
            return null;
        }

        [$min, $hour, $day, $month, $dow] = $parts;
        $fromTs = $from;

        // 从基准时间+1分钟开始搜索
        $ts = $fromTs + 60;

        // 最多搜索 366 天（约一年）
        $maxTs = $fromTs + 366 * 86400;

        $minSet = $this->cronFieldValues($min, 0, 59);
        $hourSet = $this->cronFieldValues($hour, 0, 23);
        $daySet = $this->cronFieldValues($day, 1, 31);
        $monthSet = $this->cronFieldValues($month, 1, 12);
        $dowSet = $this->cronFieldValues($dow, 0, 6);

        if (empty($minSet) || empty($hourSet) || empty($daySet) || empty($monthSet) || empty($dowSet)) {
            return null;
        }

        while ($ts < $maxTs) {
            $curMin = (int) date('i', $ts);
            $curHour = (int) date('G', $ts);
            $curDay = (int) date('j', $ts);
            $curMonth = (int) date('n', $ts);
            $curDow = (int) date('w', $ts);

            if (
                in_array($curMonth, $monthSet, true)
                && in_array($curDay, $daySet, true)
                && in_array($curDow, $dowSet, true)
                && in_array($curHour, $hourSet, true)
                && in_array($curMin, $minSet, true)
            ) {
                return $ts;
            }

            $ts += 60; // 逐分钟步进
        }

        return null;
    }

    /**
     * 解析 Cron 字段为值集合。
     * 支持：数字、every/N、A-B、A,B,C、组合如 1,3-5,every/2
     */
    private function cronFieldValues(string $field, int $min, int $max): array
    {
        $values = [];
        $parts = explode(',', trim($field));
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;

            // * 匹配所有
            if ($part === '*') {
                for ($v = $min; $v <= $max; $v++) {
                    $values[] = $v;
                }
                continue;
            }

            // every/N 模式（即星号斜杠N）
            if (str_starts_with($part, '*/')) {
                $step = (int) substr($part, 2);
                if ($step <= 0) continue;
                for ($v = $min; $v <= $max; $v += $step) {
                    $values[] = $v;
                }
            }
            // A-B 范围
            elseif (str_contains($part, '-')) {
                [$a, $b] = array_map('intval', explode('-', $part));
                for ($v = min($a, $b); $v <= max($a, $b); $v++) {
                    $values[] = $v;
                }
            }
            // 单个数字
            else {
                $v = (int) $part;
                if ($v >= $min && $v <= $max) {
                    $values[] = $v;
                }
            }
        }
        return array_unique($values);
    }

    /* ===================== 执行历史导出 ===================== */

    /**
     * GET /api/workflow/executions/export
     * 导出执行历史为 JSON（含 input/step_results），支持筛选。
     */
    public function executionsExport()
    {
        $workflow = Request::input('workflow', null);
        $status = Request::input('status', null);
        $limit = min(1000, max(1, (int) Request::input('limit', 200)));
        $days = min(90, max(1, (int) Request::input('days', 7)));

        $where = [];
        $bindings = [];
        if ($workflow !== null && $workflow !== '') {
            $where[] = 'workflow_name = ?';
            $bindings[] = $workflow;
        }
        if ($status !== null && $status !== '') {
            $where[] = 'status = ?';
            $bindings[] = $status;
        }
        $since = DbClock::since('created_at', $days * 86400);
        $where[] = $since['sql'];
        $bindings[] = $since['binding'];
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        // 窗口全集与抽样分开数：产物里的 `total` 是「这个文件装了几条」，
        // 而 `window_rows` 才是「筛选后窗口里一共有几条」——只报前者的话，
        // 拿这份 JSON 做对账的人读到的是「系统里只有这么多执行」，且没有任何一处说漏了。
        $scan = $this->scanBounded(
            'kode_lowcode_workflow_executions',
            'SELECT id, workflow_name, workflow_id, status, step_count, duration_ms, error, trace_id,
                    created_at, input_json, step_results_json',
            $whereSql,
            $bindings,
            'ORDER BY id DESC',
            $limit
        );
        $items = $scan['rows'];

        $export = [
            'exported_at' => date('Y-m-d H:i:s'),
            // 顶层也带一份：`filters.days` 是「提交并被夹过的筛选条件」，而面板与对账脚本
            // 读的是这一族的统一口径（同级键 days），别让人去猜嵌套位置。
            'days' => $days,
            'filters' => compact('workflow', 'status', 'limit', 'days'),
            'total' => count($items),
            'executions' => [],
        ] + $this->scanDeclared($scan);

        foreach ($items as $item) {
            $export['executions'][] = [
                'id' => \app\support\HashId::enc((int) $item['id']),
                'workflow_name' => $item['workflow_name'],
                'status' => $item['status'],
                'step_count' => (int) $item['step_count'],
                'duration_ms' => (int) $item['duration_ms'],
                'error' => $item['error'],
                'trace_id' => $item['trace_id'],
                'created_at' => $item['created_at'],
                'input' => json_decode($item['input_json'] ?? '{}', true) ?? [],
                'step_results' => json_decode($item['step_results_json'] ?? '{}', true) ?? [],
            ];
        }

        return $this->ok($export);
    }

    /* ===================== Webhook 触发器（公开） ===================== */

    /**
     * POST /api/workflow/webhook/{name}
     * 外部系统通过 HTTP Webhook 触发工作流（无需 JWT 认证）。
     *
     * 安全机制（判定见 webhookAdmission()，至少得有一道）：
     *  1) HMAC-SHA256 签名验证：X-Workflow-Signature 头 = HMAC(secret, raw_body)
     *  2) IP 白名单：webhook_ips 逗号分隔
     *
     * Body: { params: {...} } 或纯参数对象
     */
    public function webhookTrigger()
    {
        $name = trim((string) $this->param('name', ''));
        if ($name === '') {
            return $this->error('workflow name 不能为空', 422);
        }

        // 查找工作流
        $rows = Db::select("SELECT * FROM kode_lowcode_workflows WHERE name = ? AND status = 1", [$name]);
        if (empty($rows)) {
            return $this->error("工作流 '{$name}' 不存在或未启用", 404);
        }
        $workflow = $rows[0];

        // 从 PSR-7 Request 取签名头与原始体（兼容 Webman 下 $_SERVER 可能不注入自定义头）
        $psrRequest = Request::getRequest();
        $signature = '';
        $rawBody = '';
        if ($psrRequest !== null) {
            $sigHeaders = $psrRequest->getHeader('X-Workflow-Signature');
            $signature = trim((string) ($sigHeaders[0] ?? ''));
            $stream = $psrRequest->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $rawBody = (string) $stream;
        }
        if ($signature === '') {
            $signature = trim((string) ($_SERVER['HTTP_X_WORKFLOW_SIGNATURE'] ?? ''));
        }
        if ($rawBody === '') {
            $rawBody = file_get_contents('php://input') ?: '';
        }

        $denied = $this->webhookAdmission(
            trim((string) ($workflow['webhook_secret'] ?? '')),
            trim((string) ($workflow['webhook_ips'] ?? '')),
            ClientIp::resolve('0.0.0.0'),
            $signature,
            $rawBody
        );
        if ($denied !== null) {
            return $this->error($denied['msg'], $denied['code']);
        }

        // 解析参数
        $body = Request::input();
        if (isset($body['params']) && is_array($body['params'])) {
            $params = $body['params'];
        } else {
            $params = (array) $body;
        }

        $traceId = $this->traceId();
        $result = $this->executeWorkflowInternal($name, $params, $traceId, 0, 0);
        // 出参执行 id 混淆（内部引擎保持 int，仅在响应边界编码）
        if (isset($result['id'])) {
            $result['id'] = \app\support\HashId::enc((int) $result['id']);
        }
        return $this->ok($result);
    }

    /**
     * Webhook 触发器的准入判定（纯函数，便于离线断言）。
     *
     * 返回 null 表示放行，否则返回 `{code, msg}`（调用方直接包成错误信封）。
     *
     * 治的是：这条路由**故意不挂 JWT**（外部系统调它），而签名校验写在
     * `if ($webhook_secret !== '')` 里 —— 于是「没配密钥」不是配置提醒，而是
     * 实打实的匿名执行入口：任何能连到后台的调用方都能拉起一条会写库、会出网、
     * 会跑 on_fail 分支的流程。IP 白名单同样可留空，两者都空就是完全敞开。
     *
     * 口径：secret 与 ip 至少要有一道。只配 IP 白名单是有意保留的用法
     * （固定出口的回调方），但「两道都配了」时必须都过；签名比较恒走 hash_equals，
     * 别换成 `==`（长度相同的前缀就能撞过去）。
     *
     * @return array{code:int, msg:string}|null
     */
    private function webhookAdmission(string $secret, string $ipList, string $clientIp, string $signature, string $rawBody): ?array
    {
        // 判定端自己 trim：空白串当成「没配」，否则一个空格看起来像开了防护，
        // 实际只要求 HMAC 用空格当密钥（等于没有），而「未配置」的提示也不会出现。
        $secret = trim($secret);
        $ipList = trim($ipList);

        if ($secret === '' && $ipList === '') {
            return [
                'code' => 403,
                'msg' => '该工作流未配置 webhook 凭证：请先设置签名密钥或 IP 白名单',
            ];
        }

        if ($ipList !== '') {
            // 空串一律丢掉：`"1.2.3.4, "` 尾部那个空项会匹配到 REMOTE_ADDR 为空的请求，
            // 变成「谁都能进」——白名单里有洞比没有白名单更难发现。
            $allowed = array_values(array_filter(
                array_map(static fn (string $ip): string => trim($ip), explode(',', $ipList)),
                static fn (string $ip): bool => $ip !== ''
            ));
            if ($allowed === [] || !in_array($clientIp, $allowed, true)) {
                return ['code' => 403, 'msg' => "IP 不在白名单: {$clientIp}"];
            }
        }

        if ($secret !== '') {
            $expected = hash_hmac('sha256', $rawBody, $secret);
            if (!hash_equals($expected, $signature)) {
                return ['code' => 401, 'msg' => '签名验证失败'];
            }
        }

        return null;
    }

    /* ===================== 工作流版本历史 ===================== */

    /**
     * GET /api/workflow/definitions/{id}/versions
     * 获取工作流版本历史列表。
     */
    public function versionList()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('工作流不存在', 404);
        }

        $win = $this->pageWindow($this->query('page'), $this->query('size'));
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];

        $total = (int) (Db::select(
            'SELECT COUNT(*) AS cnt FROM kode_lowcode_workflow_versions WHERE workflow_id = ?',
            [$id]
        )[0]['cnt'] ?? 0);

        // 原本写死 `LIMIT 50` 且没有任何总数：第 51 个版本连「被切过」这件事都没有痕迹。
        $items = Db::select(
            "SELECT id, version, description, timeout_ms, schedule_cron, created_by, created_at,
                    length(steps_json) as steps_size
             FROM kode_lowcode_workflow_versions
             WHERE workflow_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ? OFFSET ?",
            [$id, $size, $offset]
        );
        // 出参版本行 id 混淆
        $items = array_map(static fn (array $row): array => self::hashOut($row), $items);

        return $this->page($items, $total, $page, $size);
    }

    /**
     * POST /api/workflow/definitions/{id}/versions/{version}/rollback
     * 回滚到指定历史版本。
     */
    public function versionRollback()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为不存在；version 为语义化版本串，不混淆
        $id = $this->eid();
        if ($id === null) {
            return $this->error('工作流不存在', 404);
        }
        $version = trim((string) $this->param('version', ''));

        // 前置校验（快速失败，不加锁）
        $wfRows = Db::select("SELECT id FROM kode_lowcode_workflows WHERE id = ?", [$id]);
        if (empty($wfRows)) {
            return $this->error('工作流不存在', 404);
        }
        $verRows = Db::select(
            "SELECT id FROM kode_lowcode_workflow_versions WHERE workflow_id = ? AND version = ?",
            [$id, $version]
        );
        if (empty($verRows)) {
            return $this->error("版本 '{$version}' 不存在", 404);
        }

        // 读-改-写：版本号递增 `SELECT version → explode('.') → minor+1 → UPDATE version = ?`
        // 从 SELECT 出的旧 version 计算新值再写回。READ COMMITTED 下两个并发的 versionRollback
        // 都会读到同一 minor、都写同一 minor+1——一个的版本增量被另一个覆盖（lost update），
        // 且后到的事务的 recordVersion 快照带的是陈旧 minor。
        // 用事务 + `FOR UPDATE` 行锁把「读工作流 → 读版本 → 递增 → 写回 → 记录快照」整包成
        // 关键区；锁内所有 SELECT 都是 fresh committed 值。
        // 同 `definitionUpdate` / `budgetSave` 的加固、同 `app/admin/controllers/ConfigController::set()`
        // （见 tests/ReadWriteConcurrentUpdateGuardTest）。
        $newVersion = null;
        $ver = null;
        try {
            Db::transaction(function () use ($id, $version, &$newVersion, &$ver): void {
                // 先锁工作流行，锁内后续 SELECT 都是 fresh committed 值
                $locked = Db::select(
                    "SELECT id, version FROM kode_lowcode_workflows WHERE id = ? LIMIT 1 FOR UPDATE",
                    [$id]
                );
                if ($locked === []) {
                    throw new \RuntimeException("workflow {$id} not found");
                }

                // 锁内重新读取目标版本（防止 pre-check 和锁获取之间被删除）
                $verRows = Db::select(
                    "SELECT * FROM kode_lowcode_workflow_versions WHERE workflow_id = ? AND version = ?",
                    [$id, $version]
                );
                if (empty($verRows)) {
                    throw new \RuntimeException("version '{$version}' not found");
                }
                $ver = $verRows[0];

                // 版本号递增：解析锁内读出的当前版本 minor 并 +1
                $parts = explode('.', (string) $locked[0]['version']);
                $minor = isset($parts[1]) ? ((int) $parts[1]) : 0;
                $newVersion = '1.' . ($minor + 1) . '.0';

                // 回滚：将当前工作流的 steps/on_fail 替换为历史版本
                Db::update(
                    "UPDATE kode_lowcode_workflows SET steps_json = ?, on_fail_json = ?, description = ?, timeout_ms = ?, schedule_cron = ?, version = ?, updated_at = NOW() WHERE id = ?",
                    [
                        $ver['steps_json'],
                        $ver['on_fail_json'],
                        $ver['description'],
                        (int) $ver['timeout_ms'],
                        $ver['schedule_cron'],
                        $newVersion,
                        $id,
                    ]
                );

                // 记录本次回滚为新版本快照
                $this->recordVersion($id, $ver['workflow_name'], $newVersion, '回滚自 ' . $version, $ver['steps_json'], $ver['on_fail_json'], (int) $ver['timeout_ms'], $ver['schedule_cron']);
            });
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'workflow ')) {
                return $this->error('工作流不存在', 404);
            }
            if (str_starts_with($msg, "version '")) {
                return $this->error("版本 '{$version}' 不存在", 404);
            }
            throw $e;
        }

        return $this->ok(['id' => \app\support\HashId::enc($id), 'name' => $ver['workflow_name'], 'version' => $newVersion, 'message' => "已回滚到版本 {$version}"]);
    }

    /**
     * 记录工作流版本快照（每次更新时调用）。
     */
    private function recordVersion(int $workflowId, string $name, string $version, string $description, string $stepsJson, string $onFailJson, int $timeoutMs, string $scheduleCron): void
    {
        $owner = $this->owner();
        Db::insert(
            "INSERT INTO kode_lowcode_workflow_versions (workflow_id, workflow_name, version, description, steps_json, on_fail_json, timeout_ms, schedule_cron, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$workflowId, $name, $version, $description, $stepsJson, $onFailJson, $timeoutMs, $scheduleCron, $owner]
        );
    }

    /* ===================== Webhook 投递日志 ===================== */

    /**
     * GET /api/workflow/webhook-logs?workflow=&status=&page=&size=
     * Webhook 投递日志列表。
     */
    public function webhookLogs()
    {
        $win = $this->pageWindow($this->query('page'), $this->query('size'), 200);
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];

        $workflow = trim((string) Request::input('workflow', ''));
        $status = trim((string) Request::input('status', ''));

        $where = [];
        $bindings = [];
        if ($workflow !== '') {
            $where[] = 'workflow_name = ?';
            $bindings[] = $workflow;
        }
        if ($status !== '') {
            $where[] = 'status = ?';
            $bindings[] = $status;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $items = Db::select(
            "SELECT id, workflow_id, workflow_name, execution_id, target_url, status, http_code, duration_ms, error, created_at
             FROM kode_lowcode_workflow_webhook_logs
             {$whereSql}
             ORDER BY created_at DESC, id DESC
             LIMIT ? OFFSET ?",
            [...$bindings, $size, $offset]
        );

        $total = (int) (Db::select("SELECT COUNT(*) as cnt FROM kode_lowcode_workflow_webhook_logs {$whereSql}", $bindings)[0]['cnt'] ?? 0);

        $items = array_map(static fn (array $row): array => self::hashOut($row, ['id', 'workflow_id', 'execution_id']), $items);

        // `created_at` 同秒的投递原本没有第二排序键，翻页会在两页间重复或漏行。
        return $this->page($items, $total, $page, $size);
    }

    /**
     * GET /api/workflow/webhook-logs/{id}
     * Webhook 投递日志详情。
     */
    public function webhookLogShow()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为日志不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('日志不存在', 404);
        }
        $rows = Db::select(
            "SELECT * FROM kode_lowcode_workflow_webhook_logs WHERE id = ?",
            [$id]
        );
        if (empty($rows)) {
            return $this->error('日志不存在', 404);
        }
        return $this->ok(self::hashOut($rows[0], ['id', 'workflow_id', 'execution_id']));
    }

    /**
     * 发送执行完成通知（Webhook 回调）。
     * 在工作流执行完成后调用，非阻塞。
     */
    private function sendNotification(int $workflowId, array $result): void
    {
        $rows = Db::select("SELECT notify_url, notify_secret, notify_template FROM kode_lowcode_workflows WHERE id = ?", [$workflowId]);
        if (empty($rows)) return;
        $notifyUrl = trim((string) ($rows[0]['notify_url'] ?? ''));
        if ($notifyUrl === '') return;

        // 自引用检测：notify_url 指向自身 webhook trigger endpoint 会导致无限递归
        if (str_contains($notifyUrl, '/api/workflow/webhook/')) {
            \Kode\Framework\Facades\Log::warning("notify_url 自引用检测：workflow_id={$workflowId} notify_url={$notifyUrl}，跳过投递以防止无限递归");
            return;
        }

        $notifySecret = trim((string) ($rows[0]['notify_secret'] ?? ''));
        $notifyTemplate = trim((string) ($rows[0]['notify_template'] ?? ''));
        $workflowName = $result['workflow'] ?? '';
        $executionId = (int) ($result['id'] ?? 0);
        // 对外投递的 payload 用混淆 id（与 /api/workflow 出参一致），库内仍存 int
        $opaqueExecutionId = $executionId > 0 ? \app\support\HashId::enc($executionId) : '';

        $payload = [
            'workflow' => $workflowName,
            'execution_id' => $opaqueExecutionId,
            'status' => $result['status'] ?? '',
            'duration_ms' => $result['duration_ms'] ?? 0,
            'error' => $result['error'] ?? null,
            'trace_id' => $result['trace_id'] ?? '',
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        // 模板变量插值：notify_template 支持 {{workflow}}, {{execution_id}}, {{status}}, {{duration_ms}}, {{error}}, {{trace_id}}, {{timestamp}}
        if ($notifyTemplate !== '') {
            $templateVars = [
                '{{workflow}}' => $workflowName,
                '{{execution_id}}' => $opaqueExecutionId,
                '{{status}}' => $payload['status'],
                '{{duration_ms}}' => (string) $payload['duration_ms'],
                '{{error}}' => $payload['error'] ?? '',
                '{{trace_id}}' => $payload['trace_id'],
                '{{timestamp}}' => $payload['timestamp'],
            ];
            $rendered = str_replace(array_keys($templateVars), array_values($templateVars), $notifyTemplate);
            // 尝试解析为 JSON，失败则按字符串发送
            $decoded = json_decode($rendered, true);
            $body = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        $delivery = $this->deliverWebhook($notifyUrl, $body, $notifySecret);

        // 记录投递日志（含重试信息）
        Db::insert(
            "INSERT INTO kode_lowcode_workflow_webhook_logs (workflow_id, workflow_name, execution_id, target_url, status, http_code, duration_ms, error, request_body, response_body, retry_count, max_retries)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 3)",
            [$workflowId, $workflowName, $executionId, $notifyUrl, $delivery['status'], $delivery['http_code'], $delivery['duration_ms'], $delivery['error'], $body, mb_substr($delivery['response_body'], 0, 2048)]
        );
    }

    /**
     * 发送执行告警（失败时触发，与通知独立）。
     *
     * 与 notify_url 的区别：
     * - notify_url: 每次执行完成都发送（成功/失败）
     * - alert_url: 仅失败时发送，用于告警通知
     */
    private function sendAlert(int $workflowId, array $result): void
    {
        $rows = Db::select("SELECT alert_url, alert_secret, alert_template FROM kode_lowcode_workflows WHERE id = ?", [$workflowId]);
        if (empty($rows)) return;
        $alertUrl = trim((string) ($rows[0]['alert_url'] ?? ''));
        if ($alertUrl === '') return;

        // 自引用检测
        if (str_contains($alertUrl, '/api/workflow/webhook/')) {
            \Kode\Framework\Facades\Log::warning("alert_url 自引用检测：workflow_id={$workflowId}，跳过投递");
            return;
        }

        $alertSecret = trim((string) ($rows[0]['alert_secret'] ?? ''));
        $alertTemplate = trim((string) ($rows[0]['alert_template'] ?? ''));
        $workflowName = $result['workflow'] ?? '';
        $executionId = (int) ($result['id'] ?? 0);
        $opaqueExecutionId = $executionId > 0 ? \app\support\HashId::enc($executionId) : '';

        $payload = [
            'type' => 'alert',
            'workflow' => $workflowName,
            'execution_id' => $opaqueExecutionId,
            'status' => $result['status'] ?? 'failed',
            'error' => $result['error'] ?? null,
            'trace_id' => $result['trace_id'] ?? '',
            'duration_ms' => $result['duration_ms'] ?? 0,
            'timestamp' => date('Y-m-d H:i:s'),
            'severity' => 'error',
        ];

        // 模板变量插值：alert_template 支持 {{workflow}}, {{execution_id}}, {{status}}, {{error}}, {{trace_id}}, {{duration_ms}}, {{timestamp}}
        if ($alertTemplate !== '') {
            $templateVars = [
                '{{workflow}}' => $workflowName,
                '{{execution_id}}' => $opaqueExecutionId,
                '{{status}}' => $payload['status'],
                '{{error}}' => $payload['error'] ?? '',
                '{{trace_id}}' => $payload['trace_id'],
                '{{duration_ms}}' => (string) $payload['duration_ms'],
                '{{timestamp}}' => $payload['timestamp'],
            ];
            $rendered = str_replace(array_keys($templateVars), array_values($templateVars), $alertTemplate);
            $decoded = json_decode($rendered, true);
            $body = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        $delivery = $this->deliverWebhook($alertUrl, $body, $alertSecret);

        \Kode\Framework\Facades\Log::info("告警投递：workflow={$workflowName} status={$delivery['status']} http_code={$delivery['http_code']}");
    }

    /**
     * POST /api/workflow/webhook-logs/{id}/retry
     * 手动重试单条失败的投递日志。
     */
    public function webhookLogRetry()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为日志不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('日志不存在', 404);
        }
        $rows = Db::select(
            "SELECT * FROM kode_lowcode_workflow_webhook_logs WHERE id = ?",
            [$id]
        );
        if (empty($rows)) {
            return $this->error('日志不存在', 404);
        }
        $log = $rows[0];
        if ($log['status'] === 'delivered') {
            return $this->error('该日志已投递成功，无需重试');
        }

        $result = $this->deliverWebhook($log['target_url'], $log['request_body'], $log['notify_secret'] ?? '');
        $newStatus = $result['status'];
        $newHttpCode = $result['http_code'];
        $newDurationMs = $result['duration_ms'];
        $newErrStr = $result['error'];
        $newRespBody = mb_substr($result['response_body'], 0, 2048);

        // 读-改-写：原代码 PHP 侧 `$newRetryCount = $log['retry_count'] + 1` 再写回，
        // 并发重试下两个事务都读到同一 retry_count、都写同一 retry_count+1（SQL 增量虽原子，
        // 但 status 判定基于陈旧值）。用 SQL CASE 在增量后的新值上判定 status，完全消除
        // PHP 侧读-算-写。同 budgetAlertCheck / 熔断器追踪的加固。
        Db::update(
            "UPDATE kode_lowcode_workflow_webhook_logs SET
                retry_count = retry_count + 1,
                status = CASE
                    WHEN ? = 'delivered' THEN 'delivered'
                    WHEN retry_count + 1 >= max_retries THEN 'dead_letter'
                    ELSE 'failed'
                END,
                http_code = ?,
                duration_ms = ?,
                error = ?,
                response_body = ?
            WHERE id = ?",
            [$newStatus, $newHttpCode, $newDurationMs, $newErrStr, $newRespBody, $id]
        );

        // 读回最终 status 供响应使用
        $fresh = Db::select(
            "SELECT status FROM kode_lowcode_workflow_webhook_logs WHERE id = ?",
            [$id]
        );
        $finalStatus = !empty($fresh) ? $fresh[0]['status'] : 'failed';

        $msg = $finalStatus === 'delivered' ? '重试成功' : ($finalStatus === 'dead_letter' ? '重试已耗尽，移入死信队列' : "重试失败: {$newErrStr}");
        return $this->ok(['id' => \app\support\HashId::enc($id), 'status' => $finalStatus, 'http_code' => $newHttpCode, 'message' => $msg]);
    }

    /**
     * POST /api/workflow/webhook-logs/retry-now
     * 自动重试所有失败的投递日志（未超过最大重试次数）。
     */
    public function webhookLogRetryNow()
    {
        $rows = Db::select(
            "SELECT id, target_url, request_body, retry_count, max_retries FROM kode_lowcode_workflow_webhook_logs WHERE status = 'failed' AND retry_count < max_retries ORDER BY created_at ASC LIMIT 10"
        );

        $retryCount = 0;
        $successCount = 0;
        $failCount = 0;

        foreach ($rows as $log) {
            $notifySecret = '';
            $wfRows = Db::select("SELECT notify_secret FROM kode_lowcode_workflows WHERE id = ?", [(int) $log['workflow_id'] ?? 0]);
            if (!empty($wfRows)) {
                $notifySecret = trim((string) ($wfRows[0]['notify_secret'] ?? ''));
            }

            $result = $this->deliverWebhook($log['target_url'], $log['request_body'], $notifySecret);
            $retryCount++;

            // 读-改-写：用 SQL CASE 在增量后的新值上判定 status（同 webhookLogRetry）
            Db::update(
                "UPDATE kode_lowcode_workflow_webhook_logs SET
                    retry_count = retry_count + 1,
                    status = CASE
                        WHEN ? = 'delivered' THEN 'delivered'
                        WHEN retry_count + 1 >= max_retries THEN 'dead_letter'
                        ELSE 'failed'
                    END,
                    http_code = ?,
                    duration_ms = ?,
                    error = ?,
                    response_body = ?
                WHERE id = ?",
                [$result['status'], $result['http_code'], $result['duration_ms'], $result['error'], mb_substr($result['response_body'], 0, 2048), $log['id']]
            );

            // 读回最终 status 供统计使用
            $fresh = Db::select(
                "SELECT status FROM kode_lowcode_workflow_webhook_logs WHERE id = ?",
                [(int) $log['id']]
            );
            $finalStatus = !empty($fresh) ? $fresh[0]['status'] : 'failed';

            if ($finalStatus === 'delivered') {
                $successCount++;
            } else {
                $failCount++;
            }
        }

        return $this->ok(['retried' => $retryCount, 'success' => $successCount, 'failed' => $failCount, 'message' => "重试 {$retryCount} 条，成功 {$successCount}，失败 {$failCount}"]);
    }

    /**
     * 执行 Webhook 投递（核心方法，供 sendNotification 和 retry 复用）。
     *
     * 目标地址是用户在工作流里填的，所以出网前必须过 `RemoteFetch`：那条 `CURLOPT_NOPROXY`
     * 只管「别把这些主机交给代理」，对直连内网一点作用都没有；协议白名单缺失时
     * `file://` 会被 curl 自己解释（见 RemoteFetch::safeOpts 注释）。
     */
    private function deliverWebhook(string $url, string $body, string $notifySecret): array
    {
        $guarded = \app\support\RemoteFetch::safeOpts($url);
        if ($guarded['ok'] !== true) {
            return ['status' => 'failed', 'http_code' => 0, 'duration_ms' => 0, 'error' => (string) $guarded['msg'], 'response_body' => ''];
        }

        $headers = ['Content-Type: application/json'];
        if ($notifySecret !== '') {
            $headers[] = 'X-Workflow-Signature: ' . hash_hmac('sha256', $body, $notifySecret);
        }

        $status = 'pending';
        $httpCode = 0;
        $errStr = '';
        $respBody = '';
        $t0 = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, $guarded['opts'] + [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            // 只影响「要不要经代理」，不是出网闸门；内网拒绝由上面的 vet() 负责
            CURLOPT_NOPROXY => 'localhost,127.0.0.1,::1,10.*,172.16.*,192.168.*',
        ]);
        $respBody = (string) curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $durationMs = (int) round((microtime(true) - $t0) * 1000);

        if ($curlErr !== '') {
            $status = 'failed';
            $errStr = $curlErr;
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            $status = 'delivered';
        } else {
            $status = 'failed';
            $errStr = "HTTP {$httpCode}";
        }

        return ['status' => $status, 'http_code' => $httpCode, 'duration_ms' => $durationMs, 'error' => $errStr, 'response_body' => $respBody];
    }

    /* ===================== Webhook 签名工具 + 健康检查 ===================== */

    /**
     * POST /api/workflow/webhook-signature/{name}
     * Webhook 签名生成工具：传入 body，返回 HMAC-SHA256 签名。
     * 用于开发者在外部系统测试 webhook 集成时生成签名。
     */
    public function webhookSignature()
    {
        $name = trim((string) $this->param('name', ''));
        if ($name === '') {
            return $this->error('workflow name 不能为空');
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $name)) {
            return $this->error('workflow name 白名单字符集 [a-zA-Z0-9_-]，长度 2-64', 422);
        }

        $rows = Db::select("SELECT webhook_secret FROM kode_lowcode_workflows WHERE name = ?", [$name]);
        if (empty($rows)) {
            return $this->error("工作流 '{$name}' 不存在", 404);
        }
        $secret = trim((string) ($rows[0]['webhook_secret'] ?? ''));
        if ($secret === '') {
            return $this->error("工作流 '{$name}' 未配置 webhook_secret");
        }

        $body = (string) Request::input('body', '');
        if ($body === '') {
            return $this->error('body 不能为空');
        }
        if (strlen($body) > 65536) {
            return $this->error('body 超过 64KB 限制', 422);
        }

        $signature = hash_hmac('sha256', $body, $secret);

        return $this->ok([
            'name' => $name,
            'signature' => $signature,
            'algorithm' => 'HMAC-SHA256',
            'header' => 'X-Workflow-Signature: ' . $signature,
            'example' => [
                'curl' => "curl -X POST 'http://your-host/api/workflow/webhook/{$name}' -H 'Content-Type: application/json' -H 'X-Workflow-Signature: {$signature}' -d '{$body}'",
            ],
        ]);
    }

    /**
     * POST /api/workflow/webhook-signature/generate
     * Webhook 签名生成增强：不依赖工作流，直接传入 body + secret 生成签名。
     *
     * body: { body: '...', secret: '...', algorithm?: 'sha256' }
     */
    public function webhookSignatureGenerate()
    {
        $body = (string) Request::input('body', '');
        $secret = trim((string) Request::input('secret', ''));
        $algorithm = trim((string) Request::input('algorithm', 'sha256'));

        if ($body === '') {
            return $this->error('body 不能为空');
        }
        if ($secret === '') {
            return $this->error('secret 不能为空');
        }

        $supportedAlgos = ['sha256', 'sha1', 'sha512', 'sha384'];
        if (!in_array($algorithm, $supportedAlgos, true)) {
            return $this->error("不支持的算法: {$algorithm}，支持: " . implode(', ', $supportedAlgos));
        }

        $signature = hash_hmac($algorithm, $body, $secret);

        return $this->ok([
            'body' => $body,
            'secret' => str_repeat('*', min(8, max(1, strlen($secret)))),
            'algorithm' => 'HMAC-' . strtoupper($algorithm),
            'signature' => $signature,
            'header' => "X-Workflow-Signature: {$signature}",
            'example' => [
                'curl' => "curl -X POST 'http://your-endpoint' -H 'Content-Type: application/json' -H 'X-Workflow-Signature: {$signature}' -d '{$body}'",
                'php' => "hash_hmac('{$algorithm}', '{$body}', '{$secret}')",
                'nodejs' => "crypto.createHmac('{$algorithm}', '{$secret}').update(JSON.stringify(body)).digest('hex')",
                'python3' => "import hmac, hashlib; hmac.new(b'{$secret}', b'{$body}', '{$algorithm}').hexdigest()",
            ],
        ]);
    }

    /**
     * POST /api/workflow/webhook/test
     * Webhook 连通性测试：发送测试请求验证 URL 可达性。
     */
    public function webhookTest()
    {
        $url = trim((string) Request::input('url', ''));
        if ($url === '') {
            return $this->error('url 不能为空');
        }

        $secret = trim((string) Request::input('secret', ''));

        $payload = [
            'type' => 'test',
            'message' => 'This is a test webhook delivery from kode workflow engine.',
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $delivery = $this->deliverWebhook($url, $body, $secret);

        $result = [
            'status' => $delivery['status'],
            'http_code' => $delivery['http_code'],
            'duration_ms' => $delivery['duration_ms'],
            'error' => $delivery['error'] !== '' ? $delivery['error'] : null,
            'response_body' => mb_substr($delivery['response_body'], 0, 1024),
            'request_body' => $body,
        ];

        return $this->ok($result);
    }

    /**
     * GET /api/workflow/health
     * 工作流健康检查：检测卡死执行、高失败率、Webhook 投递失败等。
     */
    public function health()
    {
        // 1) 总览统计
        $total = (int) (Db::select("SELECT COUNT(*) as cnt FROM kode_lowcode_workflows")[0]['cnt'] ?? 0);
        $enabled = (int) (Db::select("SELECT COUNT(*) as cnt FROM kode_lowcode_workflows WHERE status = 1")[0]['cnt'] ?? 0);

        // 2) 最近 24 小时执行统计。窗口界线必须由库的时钟给（NOW() 与 timestamptz 同源），
        // 交一枚无时区串进去会让整段窗口随「进程时区 − 库会话时区」的差 δ 整体推移。
        $sinceExec = DbClock::since('created_at', 86400);
        $execStats = Db::select(
            "SELECT COUNT(*) as total,
                    SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) as succeeded,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    AVG(duration_ms) as avg_duration_ms
             FROM kode_lowcode_workflow_executions
             WHERE " . $sinceExec['sql'],
            [$sinceExec['binding']]
        );
        $execTotal = (int) ($execStats[0]['total'] ?? 0);
        $execSucceeded = (int) ($execStats[0]['succeeded'] ?? 0);
        $execFailed = (int) ($execStats[0]['failed'] ?? 0);
        $avgDuration = (float) ($execStats[0]['avg_duration_ms'] ?? 0);
        $successRate = $execTotal > 0 ? round($execSucceeded / $execTotal * 100, 2) : 100.0;

        // 3) 失败率最高的工作流（Top 5）
        $topFailures = Db::select(
            "SELECT workflow_name, COUNT(*) as total,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
             FROM kode_lowcode_workflow_executions
             WHERE " . $sinceExec['sql'] . "
             GROUP BY workflow_name
             HAVING COUNT(*) >= 5
             ORDER BY (SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) * 1.0 / COUNT(*)) DESC
             LIMIT 5",
            [$sinceExec['binding']]
        );
        foreach ($topFailures as &$tf) {
            $tf['failure_rate'] = round((float) $tf['failed'] / (float) $tf['total'] * 100, 2);
        }

        // 4) Webhook 投递失败
        $sinceHook = DbClock::since('created_at', 86400);
        $webhookStats = Db::select(
            "SELECT COUNT(*) as total,
                    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
             FROM kode_lowcode_workflow_webhook_logs
             WHERE " . $sinceHook['sql'],
            [$sinceHook['binding']]
        );
        $whTotal = (int) ($webhookStats[0]['total'] ?? 0);
        $whDelivered = (int) ($webhookStats[0]['delivered'] ?? 0);
        $whFailed = (int) ($webhookStats[0]['failed'] ?? 0);
        $whSuccessRate = $whTotal > 0 ? round($whDelivered / $whTotal * 100, 2) : 100.0;

        // 5) 待重试的 Webhook
        $pendingRetry = (int) (Db::select(
            "SELECT COUNT(*) as cnt FROM kode_lowcode_workflow_webhook_logs WHERE status = 'failed' AND retry_count < max_retries"
        )[0]['cnt'] ?? 0);

        // 6) 健康状态判定
        $healthStatus = 'healthy';
        $issues = [];
        if ($successRate < 80) {
            $healthStatus = 'warning';
            $issues[] = "执行成功率偏低 ({$successRate}%)";
        }
        if ($whSuccessRate < 90) {
            $healthStatus = $healthStatus === 'healthy' ? 'warning' : 'warning';
            $issues[] = "Webhook 投递成功率偏低 ({$whSuccessRate}%)";
        }
        if ($pendingRetry > 10) {
            $healthStatus = 'warning';
            $issues[] = "待重试 Webhook 较多 ({$pendingRetry} 条)";
        }
        if ($successRate < 50) {
            $healthStatus = 'critical';
            $issues[] = "执行成功率严重偏低 ({$successRate}%)";
        }

        return $this->ok([
            'status' => $healthStatus,
            'issues' => $issues,
            'workflows' => ['total' => $total, 'enabled' => $enabled],
            'executions_24h' => [
                'total' => $execTotal,
                'succeeded' => $execSucceeded,
                'failed' => $execFailed,
                'success_rate' => $successRate,
                'avg_duration_ms' => round($avgDuration, 1),
            ],
            'webhook_24h' => [
                'total' => $whTotal,
                'delivered' => $whDelivered,
                'failed' => $whFailed,
                'success_rate' => $whSuccessRate,
                'pending_retry' => $pendingRetry,
            ],
            'top_failures' => $topFailures,
            'checked_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /* ===================== Webhook 仪表板 + 自动重试 + 历史清理 ===================== */

    /**
     * GET /api/workflow/webhook-dashboard
     * Webhook 投递仪表板：24h 聚合指标、耗时分布、小时趋势、HTTP 状态分布、待重试统计、Top 失败工作流。
     */
    public function webhookDashboard()
    {
        $hours = min(168, max(1, (int) Request::input('hours', 24)));
        $sinceSecs = $hours * 3600;

        // 1) 聚合指标（窗口界线由库的时钟给，否则整屏随 δ 推移）
        $sinceAgg = DbClock::since('created_at', $sinceSecs);
        $agg = Db::select(
            "SELECT COUNT(*) as total,
                    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    AVG(duration_ms) as avg_ms,
                    MIN(duration_ms) as min_ms,
                    MAX(duration_ms) as max_ms
             FROM kode_lowcode_workflow_webhook_logs
             WHERE " . $sinceAgg['sql'],
            [$sinceAgg['binding']]
        );
        $row = $agg[0] ?? [];
        $total = (int) ($row['total'] ?? 0);
        $delivered = (int) ($row['delivered'] ?? 0);
        $failed = (int) ($row['failed'] ?? 0);
        $pending = (int) ($row['pending'] ?? 0);
        $successRate = $total > 0 ? round($delivered / $total * 100, 2) : 100.0;

        // 2) 耗时百分位 (p50/p90/p95/p99)
        $sinceDur = DbClock::since('created_at', $sinceSecs);
        $durRows = Db::select(
            "SELECT duration_ms FROM kode_lowcode_workflow_webhook_logs WHERE " . $sinceDur['sql'] . " AND duration_ms > 0 ORDER BY duration_ms ASC",
            [$sinceDur['binding']]
        );
        $durList = array_values(array_map('intval', array_column($durRows, 'duration_ms')));
        $durCount = count($durList);
        $percentiles = ['p50' => 0, 'p90' => 0, 'p95' => 0, 'p99' => 0];
        if ($durCount > 0) {
            $percentiles['p50'] = $durList[(int) min($durCount - 1, floor($durCount * 0.5))];
            $percentiles['p90'] = $durList[(int) min($durCount - 1, floor($durCount * 0.9))];
            $percentiles['p95'] = $durList[(int) min($durCount - 1, floor($durCount * 0.95))];
            $percentiles['p99'] = $durList[(int) min($durCount - 1, floor($durCount * 0.99))];
        }

        // 3) 小时趋势（按小时聚合，最多 24 桶；跨度 > 24h 时按 3h 聚合）
        $bucketMinutes = $hours <= 24 ? 60 : 180;
        $sinceBucket = DbClock::since('created_at', $sinceSecs);
        $hourly = Db::select(
            "SELECT DATE_TRUNC('hour', created_at) as bucket,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
             FROM kode_lowcode_workflow_webhook_logs
             WHERE " . $sinceBucket['sql'] . "
             GROUP BY bucket
             ORDER BY bucket ASC",
            [$sinceBucket['binding']]
        );
        $hourly = array_map(function ($r) {
            return [
                'bucket' => $r['bucket'],
                'total' => (int) $r['total'],
                'delivered' => (int) $r['delivered'],
                'failed' => (int) $r['failed'],
            ];
        }, $hourly);

        // 4) HTTP 状态码分布（Top 10）
        $sinceHttp = DbClock::since('created_at', $sinceSecs);
        $httpDist = Db::select(
            "SELECT http_code, status, COUNT(*) as cnt
             FROM kode_lowcode_workflow_webhook_logs
             WHERE " . $sinceHttp['sql'] . "
             GROUP BY http_code, status
             ORDER BY cnt DESC
             LIMIT 10",
            [$sinceHttp['binding']]
        );
        $httpDist = array_map(function ($r) {
            return ['http_code' => (int) $r['http_code'], 'status' => $r['status'], 'count' => (int) $r['cnt']];
        }, $httpDist);

        // 5) 待重试分布（by retry_count）
        $retryDist = Db::select(
            "SELECT retry_count, COUNT(*) as cnt
             FROM kode_lowcode_workflow_webhook_logs
             WHERE status = 'failed' AND retry_count < max_retries
             GROUP BY retry_count
             ORDER BY retry_count ASC"
        );
        $retryDist = array_map(function ($r) {
            return ['retry_count' => (int) $r['retry_count'], 'count' => (int) $r['cnt']];
        }, $retryDist);
        $pendingRetryTotal = array_sum(array_column($retryDist, 'count'));

        // 6) Top 失败工作流
        $sinceTop = DbClock::since('created_at', $sinceSecs);
        $topFail = Db::select(
            "SELECT workflow_name, COUNT(*) as failed, AVG(duration_ms) as avg_ms
             FROM kode_lowcode_workflow_webhook_logs
             WHERE " . $sinceTop['sql'] . " AND status = 'failed'
             GROUP BY workflow_name
             ORDER BY failed DESC
             LIMIT 5",
            [$sinceTop['binding']]
        );
        foreach ($topFail as &$tf) {
            $tf['failed'] = (int) $tf['failed'];
            $tf['avg_ms'] = round((float) $tf['avg_ms'], 1);
        }
        unset($tf);

        // 7) 累计总量（全表）
        $allTime = Db::select("SELECT COUNT(*) as cnt FROM kode_lowcode_workflow_webhook_logs")[0]['cnt'] ?? 0;

        return $this->ok([
            'window_hours' => $hours,
            'total' => $total,
            'delivered' => $delivered,
            'failed' => $failed,
            'pending' => $pending,
            'success_rate' => $successRate,
            'duration' => [
                'avg_ms' => round((float) ($row['avg_ms'] ?? 0), 1),
                'min_ms' => (int) ($row['min_ms'] ?? 0),
                'max_ms' => (int) ($row['max_ms'] ?? 0),
                'percentiles' => $percentiles,
            ],
            'hourly_trend' => $hourly,
            'http_status_distribution' => $httpDist,
            'retry_distribution' => $retryDist,
            'pending_retry_total' => (int) $pendingRetryTotal,
            'top_failed_workflows' => $topFail,
            'all_time_total' => (int) $allTime,
            'checked_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * POST /api/workflow/webhook-logs/auto-retry
     * 指数退避自动重试：base 60s * 2^retry_count（1min → 2min → 4min → ...）。
     * 仅重试已过退避窗口且 retry_count < max_retries 的失败日志。
     */
    public function autoRetry()
    {
        $batchSize = min(50, max(1, (int) Request::input('limit', 20)));

        $eligible = Db::select(
            "SELECT id, target_url, request_body, retry_count, max_retries, workflow_id
             FROM kode_lowcode_workflow_webhook_logs
             WHERE status = 'failed'
               AND retry_count < max_retries
               AND now() >= created_at + power(2, retry_count) * interval '1 minute'
             ORDER BY created_at ASC
             LIMIT ?",
            [$batchSize]
        );

        $retryCount = 0;
        $successCount = 0;
        $failCount = 0;
        $details = [];

        foreach ($eligible as $log) {
            $notifySecret = '';
            $wfRows = Db::select("SELECT notify_secret FROM kode_lowcode_workflows WHERE id = ?", [(int) $log['workflow_id'] ?? 0]);
            if (!empty($wfRows)) {
                $notifySecret = trim((string) ($wfRows[0]['notify_secret'] ?? ''));
            }

            $result = $this->deliverWebhook($log['target_url'], $log['request_body'], $notifySecret);
            $retryCount++;

            // 读-改-写：用 SQL CASE 在增量后的新值上判定 status（同 webhookLogRetry / webhookLogRetryNow）
            Db::update(
                "UPDATE kode_lowcode_workflow_webhook_logs SET
                    retry_count = retry_count + 1,
                    status = CASE
                        WHEN ? = 'delivered' THEN 'delivered'
                        WHEN retry_count + 1 >= max_retries THEN 'dead_letter'
                        ELSE 'failed'
                    END,
                    http_code = ?,
                    duration_ms = ?,
                    error = ?,
                    response_body = ?
                WHERE id = ?",
                [$result['status'], $result['http_code'], $result['duration_ms'], $result['error'], mb_substr($result['response_body'], 0, 2048), (int) $log['id']]
            );

            // 读回最终 status 供响应使用
            $fresh = Db::select(
                "SELECT status, retry_count FROM kode_lowcode_workflow_webhook_logs WHERE id = ?",
                [(int) $log['id']]
            );
            $finalStatus = $fresh[0]['status'] ?? 'failed';
            $newRetryCount = (int) ($fresh[0]['retry_count'] ?? 0);

            $details[] = [
                'id' => \app\support\HashId::enc((int) $log['id']),
                // kode_lowcode_workflows 的列名是 name（workflow_name 只存在于
                // executions/budgets/alerts 那几张下游表）—— 别名保住下面的取键。
                'workflow' => Db::select("SELECT name AS workflow_name FROM kode_lowcode_workflows WHERE id = ?", [(int) $log['workflow_id'] ?? 0])[0]['workflow_name'] ?? '',
                'status' => $finalStatus,
                'http_code' => $result['http_code'],
                'retry_count' => $newRetryCount,
            ];

            if ($finalStatus === 'delivered') {
                $successCount++;
            } else {
                $failCount++;
            }
        }

        return $this->ok([
            'retried' => $retryCount,
            'success' => $successCount,
            'failed' => $failCount,
            'details' => $details,
            'backoff' => '60s * 2^retry_count',
            'message' => $retryCount === 0
                ? '没有符合条件的待重试日志（可能尚未到达退避窗口）'
                : "重试 {$retryCount} 条，成功 {$successCount}，失败 {$failCount}",
        ]);
    }

    /**
     * POST /api/workflow/executions/prune
     * 清理历史数据：删除 N 天前的执行记录、Webhook 日志、旧版本历史（保留每个 workflow 最新版本）。
     * 支持 dry_run 预览模式。
     */
    public function pruneExecutions()
    {
        // 判定只有一份（RetentionGuard）：这里曾是第四份 `min(365, max(1, (int) input('days', 30)))`，
        // 漏传/0/负数统统夹成 1 天，而 dry_run 服务端默认 false —— 一次手误就是把昨天之前的
        // 执行记录、webhook 日志、旧版本历史全删了，且响应只写着「已清理 N 条」。
        $guard = $this->retentionDays(Request::input('days'));
        if ($guard['error'] !== null) {
            return $this->fail($guard['error'], 400);
        }
        $days = $guard['days'];
        $dryRun = (bool) Request::input('dry_run', false);

        // 右界必须是 `<`（DbClock::before 那一侧）：用 `<=` 会让界线那一瞬间的行
        // 同时属于两个窗口。界线本身由库的 NOW() 给，不拿进程墙钟去算。
        // `cutoffEpoch` 只是出参展示用的一次取时，不参与任何 SQL 比较。
        $cutoffEpoch = time() - $days * 86400;

        $results = [
            'cutoff' => date('Y-m-d H:i:s', $cutoffEpoch),
            'days' => $days,
            'dry_run' => $dryRun,
        ];

        // 1) 执行记录
        $before = DbClock::before('created_at', $days * 86400);
        $execCount = (int) (Db::select("SELECT COUNT(*) as cnt FROM kode_lowcode_workflow_executions WHERE " . $before['sql'], [$before['binding']])[0]['cnt'] ?? 0);
        $results['executions_count'] = $execCount;
        $results['executions_deleted'] = 0;
        if ($dryRun === false && $execCount > 0) {
            $results['executions_deleted'] = Db::delete("DELETE FROM kode_lowcode_workflow_executions WHERE " . $before['sql'], [$before['binding']]);
        }

        // 2) Webhook 日志
        $logCount = (int) (Db::select("SELECT COUNT(*) as cnt FROM kode_lowcode_workflow_webhook_logs WHERE " . $before['sql'], [$before['binding']])[0]['cnt'] ?? 0);
        $results['webhook_logs_count'] = $logCount;
        $results['webhook_logs_deleted'] = 0;
        if ($dryRun === false && $logCount > 0) {
            $results['webhook_logs_deleted'] = Db::delete("DELETE FROM kode_lowcode_workflow_webhook_logs WHERE " . $before['sql'], [$before['binding']]);
        }

        // 3) 版本历史（保留每个 workflow 的最新一条，其余旧的才删）
        //
        // 片段只拼一次：COUNT 与 DELETE 必须是同一个窗口，否则「预览说 N 条、真删了 M 条」
        // 这种话在页面上永远说不出来（同 §3「一个 WHERE 片段只有一个拼装点」）。
        //
        // 「这一行是旧版本」= 组里还存在 id 更大的一行，所以判定是 **EXISTS** 而不是 NOT EXISTS。
        // 旧写法是 `NOT EXISTS (… latest.created_at = (SELECT MAX(created_at) …))`，那个相关子查询
        // 没有把 v 自己排除掉，于是 MAX 取到的恒是「本组最新那一行的 created_at」，而任何一行
        // 去 EXISTS 它都成立（自己就是 MAX 时等号对自己成立）—— `NOT EXISTS` 对**任何一行**恒假，
        // 这一腿恒数 0，而 message 里那句「已清理 N 条旧版本」就一直报 0，版本表只增不减
        // （每存/改一次定义各落一行）。本机实测：窗口内 22 行里确有 1 行该删，旧谓词数出 0。
        // 但把 NOT 摘掉还不够 —— 谓词得**反着**写：`NOT EXISTS (newer.id > v.id)` 数的是
        // 「本组最新的那些行」，配上 `created_at < 界线`就成了「只删过期了仍是最新的那一行」，
        // 等于替每个工作流把最后一条记录删掉、反而把所有旧版本永久留下（方向完全相反的另一种恒错）。
        // 用 id 而不是 created_at 判「有没有更新的一行」顺带补上第二排序键（同 §4 那条）：
        // 同秒落的两行版本在 created_at 上无从分别，而 id 是 bigserial，与写入同序。
        $versionWhere = 'v.' . $before['sql'] . '
                   AND EXISTS (
                       SELECT 1 FROM kode_lowcode_workflow_versions newer
                       WHERE newer.workflow_id = v.workflow_id
                         AND newer.id > v.id
                   )';

        $versionCount = (int) (Db::select(
            "SELECT COUNT(*) as cnt FROM kode_lowcode_workflow_versions v WHERE {$versionWhere}",
            [$before['binding']]
        )[0]['cnt'] ?? 0);
        $results['versions_count'] = $versionCount;
        $results['versions_deleted'] = 0;
        if ($dryRun === false && $versionCount > 0) {
            $results['versions_deleted'] = Db::delete(
                "DELETE FROM kode_lowcode_workflow_versions v WHERE {$versionWhere}",
                [$before['binding']]
            );
        }

        $results['message'] = $dryRun
            ? "预览模式：将清理 {$execCount} 条执行、{$logCount} 条日志、{$versionCount} 条旧版本"
            : "已清理 {$execCount} 条执行、{$logCount} 条日志、{$versionCount} 条旧版本";

        return $this->ok($results);
    }

    /* ===================== 版本对比 + 定时补跑 ===================== */

    /**
     * GET /api/workflow/definitions/{id}/versions/compare?from=X&to=Y
     * 版本对比：任意两个版本的差异对比。
     */
    public function versionCompare()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为工作流不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('工作流不存在', 404);
        }
        $from = trim((string) Request::input('from', ''));
        $to = trim((string) Request::input('to', ''));

        if ($from === '' || $to === '') {
            return $this->error('from 和 to 版本参数不能为空');
        }

        // 获取当前工作流信息
        $wfRows = Db::select("SELECT * FROM kode_lowcode_workflows WHERE id = ?", [$id]);
        if (empty($wfRows)) {
            return $this->error('工作流不存在', 404);
        }
        $currentVersion = $wfRows[0]['version'];

        // 获取两个版本
        $fromRows = Db::select(
            "SELECT * FROM kode_lowcode_workflow_versions WHERE workflow_id = ? AND version = ?",
            [$id, $from]
        );
        if (empty($fromRows)) {
            return $this->error("版本 '{$from}' 不存在", 404);
        }
        $toRows = Db::select(
            "SELECT * FROM kode_lowcode_workflow_versions WHERE workflow_id = ? AND version = ?",
            [$id, $to]
        );
        if (empty($toRows)) {
            return $this->error("版本 '{$to}' 不存在", 404);
        }

        $fromVer = $fromRows[0];
        $toVer = $toRows[0];

        // 解析 steps_json
        $fromSteps = json_decode($fromVer['steps_json'] ?? '[]', true) ?? [];
        $toSteps = json_decode($toVer['steps_json'] ?? '[]', true) ?? [];

        // 步骤对比：按名称匹配
        $fromStepMap = [];
        foreach ($fromSteps as $s) {
            $fromStepMap[$s['name'] ?? ''] = $s;
        }
        $toStepMap = [];
        foreach ($toSteps as $s) {
            $toStepMap[$s['name'] ?? ''] = $s;
        }

        $addedSteps = [];
        $removedSteps = [];
        $modifiedSteps = [];

        foreach ($toStepMap as $name => $step) {
            if (!isset($fromStepMap[$name])) {
                $addedSteps[] = ['name' => $name, 'type' => $step['type'] ?? ''];
            }
        }
        foreach ($fromStepMap as $name => $step) {
            if (!isset($toStepMap[$name])) {
                $removedSteps[] = ['name' => $name, 'type' => $step['type'] ?? ''];
            }
        }
        foreach ($toStepMap as $name => $toStep) {
            if (isset($fromStepMap[$name])) {
                $fromStep = $fromStepMap[$name];
                $changes = [];
                if (($fromStep['type'] ?? '') !== ($toStep['type'] ?? '')) {
                    $changes[] = "type: {$fromStep['type']} → {$toStep['type']}";
                }
                $fromConfigJson = json_encode($fromStep['config'] ?? [], JSON_UNESCAPED_UNICODE);
                $toConfigJson = json_encode($toStep['config'] ?? [], JSON_UNESCAPED_UNICODE);
                if ($fromConfigJson !== $toConfigJson) {
                    $changes[] = 'config 变更';
                }
                if (!empty($changes)) {
                    $modifiedSteps[] = ['name' => $name, 'changes' => $changes];
                }
            }
        }

        // 配置对比
        $configChanges = [];
        if ((int) $fromVer['timeout_ms'] !== (int) $toVer['timeout_ms']) {
            $configChanges[] = "timeout_ms: {$fromVer['timeout_ms']} → {$toVer['timeout_ms']}";
        }
        if (($fromVer['schedule_cron'] ?? '') !== ($toVer['schedule_cron'] ?? '')) {
            $configChanges[] = "schedule_cron: '{$fromVer['schedule_cron']}' → '{$toVer['schedule_cron']}'";
        }
        $fromOnFail = json_decode($fromVer['on_fail_json'] ?? '[]', true) ?? [];
        $toOnFail = json_decode($toVer['on_fail_json'] ?? '[]', true) ?? [];
        if (json_encode($fromOnFail) !== json_encode($toOnFail)) {
            $configChanges[] = 'on_fail 变更';
        }
        if (($fromVer['description'] ?? '') !== ($toVer['description'] ?? '')) {
            $configChanges[] = "description: '{$fromVer['description']}' → '{$toVer['description']}'";
        }

        $hasChanges = !empty($addedSteps) || !empty($removedSteps) || !empty($modifiedSteps) || !empty($configChanges);

        return $this->ok([
            'workflow_id' => \app\support\HashId::enc($id),
            'current_version' => $currentVersion,
            'from' => ['version' => $from, 'description' => $fromVer['description'], 'created_at' => $fromVer['created_at'], 'created_by' => $fromVer['created_by']],
            'to' => ['version' => $to, 'description' => $toVer['description'], 'created_at' => $toVer['created_at'], 'created_by' => $toVer['created_by']],
            'steps_added' => $addedSteps,
            'steps_removed' => $removedSteps,
            'steps_modified' => $modifiedSteps,
            'config_changes' => $configChanges,
            'has_changes' => $hasChanges,
            'summary' => $hasChanges
                ? "差异: 新增 " . count($addedSteps) . " 步, 删除 " . count($removedSteps) . " 步, 修改 " . count($modifiedSteps) . " 步, 配置变更 " . count($configChanges) . " 项"
                : '两个版本完全相同',
        ]);
    }

    /**
     * GET /api/workflow/scheduled/missed
     * 检测遗漏的定时执行：next_run_at 已过但未执行的工作流。
     */
    public function scheduledMissed()
    {
        $items = Db::select(
            "SELECT id, name, description, schedule_cron, next_run_at, last_run_at,
                    EXTRACT(EPOCH FROM (NOW() - next_run_at)) as overdue_seconds
             FROM kode_lowcode_workflows
             WHERE schedule_cron <> '' AND status = 1
               AND next_run_at IS NOT NULL
               AND next_run_at < NOW()
             ORDER BY next_run_at ASC"
        );

        foreach ($items as &$item) {
            $item['overdue_seconds'] = (int) ($item['overdue_seconds'] ?? 0);
            $item['overdue_minutes'] = (int) ceil($item['overdue_seconds'] / 60);
            $item['overdue_display'] = match (true) {
                $item['overdue_minutes'] < 60 => "{$item['overdue_minutes']} 分钟前",
                $item['overdue_minutes'] < 1440 => (int) floor($item['overdue_minutes'] / 60) . " 小时前",
                default => (int) floor($item['overdue_minutes'] / 1440) . " 天前",
            };
        }
        unset($item);

        $items = array_map(static fn (array $row): array => self::hashOut($row), $items);

        return $this->ok(['items' => $items, 'count' => count($items)]);
    }

    /**
     * POST /api/workflow/scheduled/compensate
     * 补偿执行遗漏的定时工作流。
     */
    public function scheduledCompensate()
    {
        $items = Db::select(
            "SELECT id, name, schedule_cron, next_run_at
             FROM kode_lowcode_workflows
             WHERE schedule_cron <> '' AND status = 1
               AND next_run_at IS NOT NULL
               AND next_run_at < NOW()"
        );

        $results = [];
        $compensated = 0;
        foreach ($items as $wf) {
            $traceId = $this->traceId();
            $result = $this->executeWorkflowInternal($wf['name'], [], $traceId, 0, 0);
            $compensated++;
            $results[] = [
                'id' => \app\support\HashId::enc((int) $wf['id']),
                'name' => $wf['name'],
                'status' => $result['status'] ?? 'unknown',
                'error' => $result['error'] ?? null,
            ];

            // 更新 next_run_at 和 last_run_at：点位由进程算（epoch 秒），
            // 落库这一段交给数据库解释，口径同 scheduledRunDue
            $cron = $wf['schedule_cron'] ?? '';
            $nextRun = $this->nextCronTime($cron, time());
            if ($nextRun === null) {
                Db::update(
                    'UPDATE kode_lowcode_workflows SET last_run_at = NOW(), next_run_at = NULL WHERE id = ?',
                    [(int) $wf['id']]
                );
            } else {
                $instant = DbClock::instant($nextRun);
                Db::update(
                    "UPDATE kode_lowcode_workflows SET last_run_at = NOW(), next_run_at = {$instant['sql']} WHERE id = ?",
                    [$instant['binding'], (int) $wf['id']]
                );
            }
        }

        return $this->ok([
            'compensated' => $compensated,
            'results' => $results,
            'message' => $compensated === 0 ? '没有遗漏的定时任务' : "已补偿 {$compensated} 个定时任务",
        ]);
    }

    /* ===================== 沙箱模式 + 依赖图 ===================== */

    /**
     * POST /api/workflow/execute/sandbox
     * 沙箱执行：跳过 api_call/sub_workflow/delay 步骤，不发通知，验证流程逻辑。
     */
    public function executeSandbox()
    {
        $workflowName = trim((string) Request::input('workflow', ''));
        if ($workflowName === '') {
            return $this->error('workflow 不能为空', 422);
        }

        $params = (array) Request::input('params', []);
        $traceId = $this->traceId();

        $result = $this->executeWorkflowInternal($workflowName, $params, $traceId, 0, 0, true);

        $data = $result;
        // 出参执行 id 混淆（内部引擎保持 int，仅在响应边界编码）
        if (array_key_exists('id', $data)) {
            $data['id'] = \app\support\HashId::enc((int) $data['id']);
        }
        // 沙箱计数也走同一棵结果树：躲在一个 loop/switch 分支里的那一步，按顶层数的时候
        // 结构上数不到 ——「跳过了 1 步」与「跳过 3 步里已经执行了 2 步」是两种结论，
        // 而这一屏的全部作用就是告诉提需求的人「哪些格子没验证」。
        $skippedCount = 0;
        $executedCount = 0;
        foreach ($this->stepResultTree($data['steps'] ?? [], '', $workflowName) as $leaf) {
            if (($leaf['result']['status'] ?? '') === 'skipped_sandbox') {
                $skippedCount++;
            } else {
                $executedCount++;
            }
        }

        return $this->ok([
            ...$data,
            'sandbox' => true,
            'skipped_steps' => $skippedCount,
            'executed_steps' => $executedCount,
        ]);
    }

    /**
     * GET /api/workflow/dependencies
     * 工作流依赖图：遍历所有启用的工作流，递归解析 sub_workflow 步骤，构建调用关系树。
     */
    public function dependenciesGraph()
    {
        $workflows = Db::select(
            "SELECT id, name, description, steps_json FROM kode_lowcode_workflows WHERE status = 1 ORDER BY name"
        );

        // Pass 1: 收集 edges 和 allDependents
        $edges = [];
        $allDependents = []; // workflow name → list of names that call it
        $subWfMap = []; // workflow name → list of sub_workflows

        foreach ($workflows as $wf) {
            $name = (string) $wf['name'];
            $steps = json_decode($wf['steps_json'] ?? '[]', true) ?? [];

            $subWorkflows = [];
            // 走的是环检测同一份步骤树：躲进 loop/switch 分支里的调用以前在这张图上不存在，
            // 于是「依赖图说无环、环检测说有环」之外又多一种「两边都看不见那一圈」。
            foreach ($this->stepTree($steps) as $leaf) {
                if ($leaf['type'] !== 'sub_workflow') continue;
                $target = (string) ($leaf['config']['workflow'] ?? '');
                if ($target === '' || $target === $name) continue;

                $subWorkflows[] = $target;
                $edges[] = [
                    'from' => $name,
                    'to' => $target,
                    'step_name' => $leaf['name'],
                ];
                if (!isset($allDependents[$target])) {
                    $allDependents[$target] = [];
                }
                if (!in_array($name, $allDependents[$target], true)) {
                    $allDependents[$target][] = $name;
                }
            }
            $subWfMap[$name] = array_values(array_unique($subWorkflows));
        }

        // Pass 2: 构建 nodes（此时 allDependents 已完整）
        $nodes = [];
        foreach ($workflows as $wf) {
            $name = $wf['name'];
            $nodes[] = [
                'id' => \app\support\HashId::enc((int) $wf['id']),
                'name' => $name,
                'description' => $wf['description'] ?? '',
                'sub_workflows' => $subWfMap[$name] ?? [],
                'is_entry_point' => !isset($allDependents[$name]),
            ];
        }

        // 按名称排序 edges
        usort($edges, fn($a, $b) => strnatcasecmp($a['from'] . '>' . $a['to'], $b['from'] . '>' . $b['to']));

        // 检测循环依赖：回溯找环只有一枚实现（findCycles），本方法发的是裸路径清单。
        $adj = [];
        foreach ($edges as $edge) {
            $adj[$edge['from']][] = $edge['to'];
        }
        $known = [];
        foreach ($nodes as $node) {
            $known[$node['name']] = true;
        }
        $cycles = array_map(
            static fn (array $c): array => $c['path'],
            $this->findCycles($adj, $known)
        );

        return $this->ok([
            'nodes' => $nodes,
            'edges' => $edges,
            'cycles' => $cycles,
            'summary' => [
                'total_workflows' => count($nodes),
                'total_edges' => count($edges),
                'entry_points' => count(array_filter($nodes, fn($n) => $n['is_entry_point'])),
                'has_cycles' => !empty($cycles),
            ],
        ]);
    }

    /**
     * 依赖图里「回溯找环」的唯一实现。
     *
     * 邻接表 + 已知节点集进，**去重后的结构体**出。两个消费面都委派给它，各自在自己的边界上
     * 决定对外形状：`dependenciesGraph()` 取 `path`（发 `string[][]`，前端 `cycle.join(' → ')`），
     * `cycleDetection()` 直接交回结构体（前端读 `cycle.path` 与 `cycle.depth`）。
     *
     * 「未登记的目标闭合不了环」这条判定也只住在这里：$known 之外的邻接点一律不走进去 ——
     * 一枚不存在的工作流没有出边，DFS 到它只能原路返回，永远闭不上圈。
     *
     * @param array<string, list<string>> $adj   邻接表（from → 若干 to，to 可以不在 $known 里）
     * @param array<string, true>         $known 已知节点集（只有这些点会被当作根遍历、也才被走进去）
     * @return list<array{path: list<string>, workflows: list<string>, depth: int}>
     */
    private function findCycles(array $adj, array $known): array
    {
        $raw = [];
        $visited = [];
        $inStack = [];

        $dfs = function (string $node, array $path) use (&$dfs, $adj, $known, &$raw, &$visited, &$inStack): void {
            $visited[$node] = true;
            $inStack[$node] = true;
            $path[] = $node;

            foreach ($adj[$node] ?? [] as $neighbor) {
                if (!isset($known[$neighbor])) {
                    continue; // 未登记的目标没有出边，闭合不了环
                }
                if (!isset($visited[$neighbor])) {
                    $dfs($neighbor, $path);
                } elseif (isset($inStack[$neighbor])) {
                    $start = array_search($neighbor, $path, true);
                    if ($start !== false) {
                        $cycle = array_slice($path, (int) $start);
                        $cycle[] = $neighbor; // 闭合：首尾同名
                        $raw[] = [
                            'path' => $cycle,
                            'workflows' => array_values(array_unique(array_slice($cycle, 0, -1))),
                            'depth' => count($cycle) - 1,
                        ];
                    }
                }
            }

            array_pop($path);
            unset($inStack[$node]);
        };

        foreach (array_keys($known) as $node) {
            if (!isset($visited[$node])) {
                $dfs((string) $node, []);
            }
        }

        // 同一圈可能从不同起点各找到一次，按闭合路径去重。
        $seen = [];
        $out = [];
        foreach ($raw as $cycle) {
            $key = implode('->', $cycle['path']);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $cycle;
            }
        }

        return $out;
    }

    /* ===================== 步骤执行引擎 ===================== */

    /** 单次等待的硬天花板（毫秒）：拿不到 deadline 时也绝不睡过这个数。 */
    private const MAX_WAIT_MS = 10_000;

    /**
     * 请求线程里一次「等待」实际允许占用的毫秒数 = min(期望值, 天花板, 剩余预算)。
     * 返回 0 表示预算已尽，调用方应当放弃这次重试/请求，而不是照原计划睡。
     *
     * 治的是：deadline 过去只在工作流**步骤之间**查，步骤内部的三处阻塞（api_call 的
     * curl 超时、内层重试退避、外层重试退避）全都不查，于是各层预算相乘 ——
     * 单个 api_call 最坏 timeout(60s) × (1+3 次重试) + 退避，外层再乘 (1+5)，
     * 一个坏上游能把 worker 按住二十多分钟。11 worker 的常驻服务里五个人同时点
     * 「执行」就等于把整个后台锁死（AGENTS.md §5 热路径禁止 sleep；同步执行是
     * 既有形态，改不了，能改的是「等过界」）。
     *
     * 判据只写这一份：三处阻塞各写一遍，迟早只改对一个（同 RetentionGuard 的口径）。
     */
    private function clampWaitMs(float $deadline, int $wantMs): int
    {
        $capped = max(0, min($wantMs, self::MAX_WAIT_MS));
        if ($deadline <= 0) {
            return $capped;
        }

        return max(0, min($capped, (int) floor(($deadline - microtime(true)) * 1000)));
    }

    /** 剩余预算是否已经不够再发一次「至少 1 秒」的请求。 */
    private function budgetExhausted(float $deadline): bool
    {
        return $deadline > 0 && microtime(true) >= $deadline;
    }

    /**
     * 执行单个步骤。
     *
     * @param string $type     步骤类型
     * @param array  $config   步骤配置
     * @param array  $context  上下文（包含 params 和上一步的输出）
     * @param int    $depth    递归深度（0 = 顶层）
     * @param float  $deadline 截止时间（microtime，0 表示无限制）
     * @param bool   $sandbox  沙箱模式（跳过 api_call/sub_workflow/delay）
     * @return array 步骤结果
     */
    private function executeStep(string $type, array $config, array $context, int $depth = 0, float $deadline = 0, bool $sandbox = false): array
    {
        // 步骤级超时（优先于工作流级 deadline）
        $stepTimeoutMs = (int) ($config['timeout_ms'] ?? 0);
        $stepDeadline = $stepTimeoutMs > 0 ? microtime(true) + $stepTimeoutMs / 1000.0 : 0;
        $effectiveDeadline = $stepDeadline > 0 && $deadline > 0
            ? min($stepDeadline, $deadline)
            : ($stepDeadline > 0 ? $stepDeadline : $deadline);

        // 步骤级重试配置
        $maxRetries = min(5, max(0, (int) ($config['max_retries'] ?? 0)));
        $backoffMs = min(5000, max(100, (int) ($config['backoff_ms'] ?? 500)));

        $attempt = 0;
        $lastError = null;

        while ($attempt <= $maxRetries) {
            $t0 = microtime(true);
            $result = $this->executeStepInternal($type, $config, $context, $depth, $effectiveDeadline, $sandbox);
            $result['_duration_ms'] = (int) round((microtime(true) - $t0) * 1000);
            $result['_attempt'] = $attempt + 1;
            // 「这一格是谁跑的」只有这一个盖章点知道。类型不在执行器结果里（executeApiCall 出
            // http_code/data/success，executeLog 出 message），所以成本统计与依赖健康度原先读的
            // `$stepResult['type']` 是一枚结构上不存在的键 —— 实测库里 `step_results_json LIKE
            // '%"type":"%'` 数出 0 行，于是 `api_calls` 恒 0，而它流进 costTrends、预算面板的
            // daily_api、成本分摊与容量预测共七条聚合腿。消费侧只问 stepTypeOf()。
            $result['_type'] = $type;

            // 成功则返回：判据只有一份。原先这里写 `!isset($result['error'])`，
            // 而 HTTP 4xx/5xx 的结果不带 error 键（只有 http_code + success=false），
            // 于是 `max_retries` 对上游报错完全失效 —— 一次 503 抖动就把整条工作流打死。
            [$stepOk] = self::stepOutcome($result);
            if ($stepOk) {
                return $result;
            }

            $lastError = self::stepFailureText($result);

            // 不可重试的错误（参数校验类）
            if ($this->isNonRetryableError($lastError)) {
                return $result;
            }

            // 预算已尽时子步骤根本没发请求（executeApiCall 的 skipped_by_deadline），
            // 再退避重试只是把 worker 多按住几轮
            if (($result['skipped_by_deadline'] ?? false) === true) {
                return $result;
            }

            // 超时错误：步骤级和工作流级谁先到算谁，两者都不看就等于没有超时
            $now = microtime(true);
            if ($stepDeadline > 0 && $now >= $stepDeadline) {
                $result['error'] = "步骤超时 ({$stepTimeoutMs}ms)";
                return $result;
            }
            if ($deadline > 0 && $now >= $deadline) {
                $result['error'] = '工作流预算已用尽，未再重试';
                $result['skipped_by_deadline'] = true;
                return $result;
            }

            $attempt++;
            if ($attempt <= $maxRetries) {
                // 指数退避: backoffMs * 2^(attempt-1)，加上随机抖动
                $delayMs = $backoffMs * (2 ** ($attempt - 1));
                $delayMs += random_int(0, (int) ($delayMs * 0.2));
                $waitMs = $this->clampWaitMs($effectiveDeadline, (int) $delayMs);
                if ($waitMs <= 0) {
                    // 预算已尽：与其为了「再试一次」把 worker 按住，不如现在就把失败交回去
                    $result['_retries_cut_by_deadline'] = true;
                    break;
                }
                usleep($waitMs * 1000);
            }
        }

        // 重试循环结束：被 deadline 掐断的不算「试完都失败」，两者对排障是不同结论
        $result['error'] = $lastError;
        if (($result['_retries_cut_by_deadline'] ?? false) !== true) {
            $result['_retries_exhausted'] = true;
        }
        return $result;
    }

    /**
     * 判断错误是否不可重试（参数校验、配置错误等）。
     */
    private function isNonRetryableError(string $error): bool
    {
        $nonRetryablePatterns = [
            'url 必填',
            'url 无效',
            '步骤引用自身',
            '引用的步骤不存在',
            '前向引用',
            '未知步骤类型',
            '不支持的',
            '无效',
            '必填',
            '配置错误',
        ];
        $errorLower = strtolower($error);
        foreach ($nonRetryablePatterns as $pattern) {
            if (str_contains($errorLower, strtolower($pattern))) {
                return true;
            }
        }
        return false;
    }

    /**
     * 步骤执行核心逻辑（由 executeStep 包装，后者负责计时）。
     */
    private function executeStepInternal(string $type, array $config, array $context, int $depth = 0, float $deadline = 0, bool $sandbox = false): array
    {
        // 沙箱模式：跳过外部副作用步骤（api_call/sub_workflow/delay），其余正常执行
        if ($sandbox && in_array($type, ['api_call', 'sub_workflow', 'delay'], true)) {
            $sandboxResult = match ($type) {
                'api_call' => [
                    'status' => 'skipped_sandbox',
                    'reason' => '沙箱模式：跳过 API 调用',
                    'mock_response' => (array) ($config['mock_response'] ?? ['ok' => true, 'sandbox' => true]),
                    'url' => $config['url'] ?? '',
                    'method' => strtoupper((string) ($config['method'] ?? 'GET')),
                ],
                'sub_workflow' => [
                    'status' => 'skipped_sandbox',
                    'reason' => '沙箱模式：跳过子工作流',
                    'workflow' => $config['workflow'] ?? '',
                    'mock_response' => ['ok' => true, 'sandbox' => true, 'sub_workflow' => true],
                ],
                'delay' => [
                    'status' => 'skipped_sandbox',
                    'reason' => '沙箱模式：跳过延迟',
                    'delayed_ms' => 0,
                    'target_ms' => (int) (($config['seconds'] ?? 1) * 1000),
                ],
            };
            return $sandboxResult;
        }

        return match ($type) {
            'api_call' => $this->executeApiCall($config, $context, $deadline),
            'transform' => $this->executeTransform($config, $context),
            'condition' => $this->executeCondition($config, $context),
            'log' => $this->executeLog($config, $context),
            'loop' => $this->executeLoop($config, $context, $depth, $deadline, $sandbox),
            'delay' => $this->executeDelay($config, $context, $deadline),
            'sub_workflow' => $this->executeSubWorkflow($config, $context, $depth, $deadline),
            'switch' => $this->executeSwitch($config, $context, $depth, $deadline, $sandbox),
            default => ['error' => "未知步骤类型: {$type}"],
        };
    }

    /**
     * 执行 API 调用步骤。
     *
     * config: { url, method, payload?, headers?, timeout? }
     * payload 支持 {{step_name.field}} 变量替换。
     *
     * @param float $deadline 工作流/步骤的截止时间（microtime 秒，0 = 由上层天花板兜底）
     */
    private function executeApiCall(array $config, array $context, float $deadline = 0): array
    {
        $url = (string) ($config['url'] ?? '');
        if ($url === '') {
            return ['error' => 'url 必填'];
        }

        $method = strtoupper((string) ($config['method'] ?? 'GET'));
        $timeout = (int) ($config['timeout'] ?? 30);
        $maxRetries = min(3, max(0, (int) ($config['max_retries'] ?? 0)));
        $backoffMs = min(5000, max(100, (int) ($config['backoff_ms'] ?? 1000)));

        // 预算已尽就别再把 worker 交给一次注定超时的请求：curl 会自己占满 timeout 秒。
        if ($this->budgetExhausted($deadline)) {
            return ['error' => '工作流预算已用尽，未发起请求', 'success' => false, 'skipped_by_deadline' => true];
        }
        // 把 curl 超时压进剩余预算（curl 的超时粒度是秒，不足 1 秒按 1 秒给，宁可多等一秒
        // 也不把作者写的 timeout 当成事实上的无上限）。
        if ($deadline > 0) {
            $leftSec = (int) ceil($deadline - microtime(true));
            $timeout = max(1, min($timeout, $leftSec));
        }

        // 变量替换（只在首次做，避免重试时重复替换）
        $url = $this->replaceVariables($url, $context);
        // 闸门必须在变量替换**之后**：`http://a{{host}}` 里的 host 来自上下文，
        // 替换前查的是另一个地址，等于没查。
        $guarded = \app\support\RemoteFetch::safeOpts($url);
        if ($guarded['ok'] !== true) {
            return ['error' => '出网地址被拒：' . (string) $guarded['msg'], 'success' => false];
        }
        $payload = $config['payload'] ?? null;
        $headers = $config['headers'] ?? [];

        if ($payload !== null) {
            $payload = $this->replaceVariablesInString(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $context);
            $payload = json_decode($payload, true);
        }
        if ($headers !== null) {
            $headers = $this->replaceVariablesInArray($headers, $context);
        }

        // 构建 curl 头
        $curlHeaders = ['Accept: application/json', 'Content-Type: application/json'];
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                $curlHeaders[] = "{$k}: {$v}";
            }
        }

        // 闸门选项放左侧：同名项（TLS/协议/钉 IP）由 RemoteFetch 说了算，
        // 业务选项只能补它没有的键。
        $opts = $guarded['opts'] + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => (int) ceil($timeout / 2),
            // 本机请求绕过系统代理（curl 默认走环境变量代理，localhost 会被错误转发）；
            // 这条只管「要不要经代理」，内网拒绝由 RemoteFetch::vet() 负责
            CURLOPT_NOPROXY => 'localhost,127.0.0.1,::1,10.*,172.16.*,192.168.*',
        ];

        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            $opts[CURLOPT_POST] = $method === 'POST';
            if ($method !== 'POST') {
                $opts[CURLOPT_CUSTOMREQUEST] = $method;
            }
            $opts[CURLOPT_POSTFIELDS] = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';
        } elseif ($method === 'DELETE') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }

        // 重试循环：指数退避
        $lastResult = null;
        $attempts = 0;
        $cutByDeadline = false;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $attempts = $attempt + 1;

            if ($attempt > 0) {
                // 指数退避：backoffMs * 2^(attempt-1)，且绝不睡过剩余预算
                $sleepMs = (int) ($backoffMs * pow(2, $attempt - 1));
                $waitMs = $this->clampWaitMs($deadline, $sleepMs);
                if ($waitMs <= 0) {
                    $cutByDeadline = true;
                    break;
                }
                usleep($waitMs * 1000);
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, $opts);
            $responseBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($responseBody === false) {
                $lastResult = [
                    'error' => "请求失败: {$curlErr}",
                    'http_code' => $httpCode,
                    'success' => false,
                ];
                // 网络错误，继续重试
                continue;
            }

            $data = json_decode($responseBody, true);
            if (!is_array($data)) {
                $data = $responseBody;
            }

            $lastResult = [
                'http_code' => $httpCode,
                'data' => $data,
                'success' => $httpCode >= 200 && $httpCode < 300,
            ];

            if ($lastResult['success']) {
                break;
            }
        }

        $lastResult['attempts'] = $attempts;
        if ($cutByDeadline) {
            // 告诉上层「少试了几次」而不是「试完都失败」：两者对排障是不同结论
            $lastResult['retries_cut_by_deadline'] = true;
        }

        // 响应路径提取：config.response_path = "user.name" 从 data 中提取嵌套字段
        $responsePath = (string) ($config['response_path'] ?? '');
        if ($responsePath !== '' && isset($lastResult['data']) && is_array($lastResult['data'])) {
            $extracted = $this->resolveVariable($responsePath, $lastResult['data']);
            $lastResult['data'] = $extracted;
        }

        return $lastResult;
    }

    /**
     * 执行数据转换步骤。
     *
     * config: { mappings: [{ from: "{{step.field}}", to: "target_key" }] }
     */
    private function executeTransform(array $config, array $context): array
    {
        $mappings = (array) ($config['mappings'] ?? []);
        $result = [];

        foreach ($mappings as $mapping) {
            $from = (string) ($mapping['from'] ?? '');
            $to = (string) ($mapping['to'] ?? '');
            if ($from === '' || $to === '') {
                continue;
            }
            // 如果 from 包含 {{...}} 变量模式，走变量替换（支持字面量+变量混合）
            // 否则走路径解析（纯路径引用）
            if (str_contains($from, '{{')) {
                $value = $this->replaceVariablesInString($from, $context);
            } else {
                $value = $this->resolveVariable($from, $context);
            }
            $result[$to] = $value;
        }

        return $result;
    }

    /**
     * 执行条件分支步骤。
     *
     * config: { condition: "{{step.field}} == value", else_action?: string }
     */
    private function executeCondition(array $config, array $context): array
    {
        $expression = (string) ($config['condition'] ?? '');
        // 变量替换：字符串值自动加引号，防止 eval 将其解释为常量
        $expression = $this->replaceVariablesForCondition($expression, $context);

        if ($expression === '') {
            return ['matched' => false, 'expression' => '', 'error' => 'condition 不能为空'];
        }

        // 表达式求值：ConditionEvaluator 只认字面量 + 比较 + 逻辑运算，函数调用在词法阶段即被拒
        $result = ConditionEvaluator::evaluate($expression);

        return [
            'matched' => $result,
            'expression' => $expression,
        ];
    }

    /**
     * 条件表达式变量替换：字符串值加单引号包裹，数字/布尔值直接嵌入。
     * 例：{{params.action}} == "daily_checkin" → 'daily_checkin' == "daily_checkin"
     */
    private function replaceVariablesForCondition(string $input, array $context): string
    {
        return preg_replace_callback('/\{\{([^}]+)\}\}/', function ($matches) use ($context) {
            $value = $this->resolveVariable($matches[1], $context);
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if (is_numeric($value)) {
                return (string) $value;
            }
            // 字符串：包成单引号字面量，反斜杠与单引号都要转义（少转义反斜杠会把值尾巴折进转义序列）
            return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value) . "'";
        }, $input);
    }

    /**
     * 执行日志步骤（调试用）。
     *
     * config: { message: "{{...}}" }
     */
    private function executeLog(array $config, array $context): array
    {
        $message = (string) ($config['message'] ?? '');
        $message = $this->replaceVariables($message, $context);

        // 落 kode_system_logs：这一句话是工作流自己写的日志，不是「谁调了哪个接口」的流水，
        // 审计表既没有列装它、读侧也只扫 `_00.._NN`，写进去等于一个字都不留。
        $where = [];
        $workflowName = (string) ($context['__workflow_name'] ?? '');
        if ($workflowName !== '') {
            $where['workflow'] = $workflowName;
        }
        $traceId = (string) ($context['__trace_id'] ?? '');
        if ($traceId !== '') {
            $where['trace_id'] = $traceId;
        }
        SysLog::info('workflow', $message, $where);

        return ['message' => $message];
    }

    /**
     * 执行循环步骤：遍历数组，对每个元素执行子步骤。
     *
     * config: { source: "{{step.result.items}}", item: "item", steps: [...] }
     *
     * - source: 变量路径，必须解析为数组（支持 JSON 字符串自动解码）
     * - item:   循环变量名（默认 'item'），子步骤中通过 {{item.field}} 引用
     * - steps:  子步骤数组（支持 api_call/transform/condition/log/delay，禁止嵌套 loop）
     *
     * 安全限制：最多遍历 100 个元素，防止运行失控。
     */
    private function executeLoop(array $config, array $context, int $depth = 0, float $deadline = 0, bool $sandbox = false): array
    {
        $source = (string) ($config['source'] ?? '');
        $itemName = (string) ($config['item'] ?? 'item');
        $subSteps = (array) ($config['steps'] ?? []);

        if ($source === '') {
            return ['error' => 'source 必填'];
        }

        // 解析 source（可能是变量引用或字面量 JSON）
        $value = $this->resolveVariable($source, $context);
        if (is_string($value) && preg_match('/^[\[\{]/', $value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            return ['error' => "source 不是数组: {$source}", 'resolved' => $value];
        }

        // 安全上限：最多 100 项
        $items = array_slice($value, 0, 100);
        $results = [];

        foreach ($items as $idx => $item) {
            // 超时检查
            if ($deadline > 0 && microtime(true) >= $deadline) {
                $results[] = ['index' => $idx, 'error' => 'loop 超时'];
                break;
            }

            // 每次迭代创建新的上下文副本，避免互相污染
            $loopContext = $context;
            $loopContext[$itemName] = $item;
            $loopContext['index'] = $idx;
            $loopContext['count'] = count($items);

            $iterationResults = [];
            foreach ($subSteps as $subStep) {
                $subName = (string) ($subStep['name'] ?? '');
                $subType = (string) ($subStep['type'] ?? '');
                $subConfig = (array) ($subStep['config'] ?? []);
                try {
                    $subResult = $this->executeStep($subType, $subConfig, $loopContext, $depth, $deadline, $sandbox);
                    $iterationResults[$subName] = $subResult;
                    $loopContext[$subName] = ['result' => $subResult];
                } catch (\Throwable $e) {
                    $iterationResults[$subName] = ['error' => $e->getMessage()];
                }
            }
            $results[] = ['index' => $idx, 'item' => $item, 'results' => $iterationResults];
        }

        return [
            'count' => count($results),
            'source_resolved' => count($value),
            'iterations' => $results,
        ];
    }

    /**
     * 执行延迟步骤（用于 API 限流、等待上游响应等场景）。
     *
     * config: { seconds: 1 }  // 0-5 秒，防滥用
     *
     * @param float $deadline 剩余预算的截止时间（microtime 秒，0 = 只受 5 秒上限约束）
     */
    private function executeDelay(array $config, array $context, float $deadline = 0): array
    {
        $seconds = (float) ($config['seconds'] ?? 1);
        // 安全上限：最多 5 秒
        $seconds = min(5.0, max(0, $seconds));
        $targetMs = (int) ($seconds * 1000);
        $startMs = (int) (microtime(true) * 1000);
        // 预算已尽时 delay 步骤不再睡满：这一步本来就是「等一会儿再打下游」，
        // 上游都没预算了，等满只会让整条工作流越过 deadline 才回报。
        $waitMs = $this->clampWaitMs($deadline, $targetMs);
        if ($waitMs > 0) {
            usleep($waitMs * 1000);
        }
        $elapsedMs = (int) (microtime(true) * 1000) - $startMs;

        return [
            'delayed_ms' => $elapsedMs,
            'target_ms' => $targetMs,
            'cut_by_deadline' => $waitMs < $targetMs,
        ];
    }

    /**
     * 执行子工作流步骤：递归调用另一个工作流。
     *
     * config: { workflow: "name", params?: {...} }
     *
     * - workflow: 目标工作流名称（必填）
     * - params:   传入参数（可选，默认透传当前 params）
     *
     * 安全限制：递归深度最大 3 层（防止无限递归）。
     * 子工作流不记录独立执行日志（避免日志爆炸），结果通过本步骤返回。
     */
    private function executeSubWorkflow(array $config, array $context, int $depth = 0, float $deadline = 0): array
    {
        $workflowName = (string) ($config['workflow'] ?? '');
        if ($workflowName === '') {
            return ['error' => 'workflow 必填'];
        }

        // 防止自调用
        $currentWorkflow = $context['__workflow_name'] ?? '';
        if ($workflowName === $currentWorkflow) {
            return ['error' => "子工作流不能调用自身: {$workflowName}"];
        }

        // 解析参数
        $params = $config['params'] ?? $context['params'] ?? [];
        $params = $this->replaceVariablesInArray((array) $params, $context);

        $traceId = $context['__trace_id'] ?? bin2hex(random_bytes(4));
        $result = $this->executeWorkflowInternal($workflowName, $params, $traceId, $depth + 1, $deadline);

        $data = $result;
        return [
            'workflow' => $workflowName,
            'status' => $data['status'] ?? 'failed',
            'duration_ms' => $data['duration_ms'] ?? 0,
            'steps' => $data['steps'] ?? [],
            'error' => $data['error'] ?? null,
        ];
    }

    /**
     * 执行 switch 步骤：多分支条件路由（类似 switch/case）。
     *
     * config: {
     *   variable: "{{step.field}}",       // 要判断的变量
     *   cases: [                          // 分支列表
     *     { "value": "red", "steps": [...] },
     *     { "value": "blue", "steps": [...] },
     *   ],
     *   default_steps?: [...]              // 默认分支（所有 case 不匹配时执行）
     * }
     *
     * 只执行匹配的第一个 case 的子步骤（互斥），未匹配则执行 default_steps。
     */
    private function executeSwitch(array $config, array $context, int $depth = 0, float $deadline = 0, bool $sandbox = false): array
    {
        $variablePath = (string) ($config['variable'] ?? '');
        $cases = (array) ($config['cases'] ?? []);
        $defaultSteps = (array) ($config['default_steps'] ?? []);

        if ($variablePath === '') {
            return ['error' => 'variable 必填'];
        }

        // 解析变量值
        $value = $this->resolveVariable($variablePath, $context);
        $valueStr = (string) $value;

        // 查找匹配的 case
        $matchedCase = null;
        $matchedValue = null;
        foreach ($cases as $case) {
            $caseValue = (string) ($case['value'] ?? '');
            if ($caseValue === $valueStr) {
                $matchedCase = $case;
                $matchedValue = $caseValue;
                break;
            }
        }

        // 选择要执行的步骤
        $stepsToRun = [];
        $branchLabel = 'default';
        if ($matchedCase !== null) {
            $stepsToRun = (array) ($matchedCase['steps'] ?? []);
            $branchLabel = "case:{$matchedValue}";
        } elseif (!empty($defaultSteps)) {
            $stepsToRun = $defaultSteps;
            $branchLabel = 'default';
        }

        $results = [];
        foreach ($stepsToRun as $subStep) {
            // 超时检查
            if ($deadline > 0 && microtime(true) >= $deadline) {
                $results[] = ['error' => 'switch 超时'];
                break;
            }

            $subName = (string) ($subStep['name'] ?? '');
            $subType = (string) ($subStep['type'] ?? '');
            $subConfig = (array) ($subStep['config'] ?? []);
            try {
                $subResult = $this->executeStep($subType, $subConfig, $context, $depth, $deadline, $sandbox);
                $results[$subName] = $subResult;
                $context[$subName] = ['result' => $subResult];
            } catch (\Throwable $e) {
                $results[$subName] = ['error' => $e->getMessage()];
            }
        }

        return [
            'variable' => $valueStr,
            'branch' => $branchLabel,
            'matched' => $matchedCase !== null,
            'results' => $results,
        ];
    }

    /**
     * 执行失败恢复步骤（on_fail）。
     *
     * @param array $failSteps  恢复步骤数组
     * @param array $context    当前上下文
     * @param int   $depth      递归深度
     * @param float $deadline   截止时间
     * @param string $source    触发恢复的来源步骤名（用于日志）
     * @return array 恢复步骤执行结果
     */
    private function executeFailSteps(array $failSteps, array $context, int $depth, float $deadline, string $source): array
    {
        $results = [];
        foreach ($failSteps as $failStep) {
            // 超时检查
            if ($deadline > 0 && microtime(true) >= $deadline) {
                $results[] = ['error' => 'on_fail 超时'];
                break;
            }

            $failName = (string) ($failStep['name'] ?? 'on_fail_' . count($results));
            $failType = (string) ($failStep['type'] ?? '');
            $failConfig = (array) ($failStep['config'] ?? []);
            try {
                $failResult = $this->executeStep($failType, $failConfig, $context, $depth, $deadline);
                $results[$failName] = $failResult;
            } catch (\Throwable $e) {
                $results[$failName] = ['error' => $e->getMessage()];
            }
        }
        return $results;
    }

    /* ===================== 变量替换引擎 ===================== */

    /**
     * 替换字符串中的 {{variable}} 引用。
     *
     * 支持格式：
     *   {{params.field}}        — 请求参数
     *   {{step_name.result.x}} — 前序步骤输出
     *   {{step_name.field}}    — 前序步骤字段（简写）
     */
    private function replaceVariables(string $input, array $context): string
    {
        return $this->replaceVariablesInString($input, $context);
    }

    /**
     * 替换字符串中的变量引用。
     */
    private function replaceVariablesInString(string $input, array $context): string
    {
        return preg_replace_callback('/\{\{([^}]+)\}\}/', function ($matches) use ($context) {
            $path = $matches[1];
            return $this->resolveVariable($path, $context);
        }, $input);
    }

    /**
     * 替换数组中的变量引用（递归）。
     */
    private function replaceVariablesInArray(array $input, array $context): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $input[$key] = $this->replaceVariablesInString($value, $context);
            } elseif (is_array($value)) {
                $input[$key] = $this->replaceVariablesInArray($value, $context);
            }
        }
        return $input;
    }

    /**
     * 解析变量路径（支持点号嵌套 + 数组索引）。
     *
     * 例：params.cartId → context['params']['cartId']
     *     step1.result.items[0].price → context['step1']['result']['items'][0]['price']
     */
    private function resolveVariable(string $path, array $context): mixed
    {
        // Strip {{ }} if present (transform mappings may include braces)
        $path = trim($path, '{}');
        // 简单路径：点号分隔
        $parts = explode('.', $path);
        $current = $context;

        // 第一级：步骤名，直接匹配
        $first = array_shift($parts);
        if (!is_array($current) || !array_key_exists($first, $current)) {
            return '';
        }
        $current = $current[$first];

        // 剩余路径：逐层查找，若直接键不存在则尝试在 result 子键下查找
        foreach ($parts as $part) {
            if (preg_match('/^(\w+)\[(\d+)\]$/', $part, $m)) {
                $key = $m[1];
                $idx = (int) $m[2];
                if (is_array($current) && array_key_exists($key, $current) && is_array($current[$key])) {
                    $current = $current[$key][$idx] ?? null;
                } elseif (is_array($current) && array_key_exists('result', $current)
                    && is_array($current['result']) && array_key_exists($key, $current['result'])
                    && is_array($current['result'][$key])) {
                    $current = $current['result'][$key][$idx] ?? null;
                } else {
                    return '';
                }
                continue;
            }
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } elseif (is_array($current) && array_key_exists('result', $current)
                && is_array($current['result']) && array_key_exists($part, $current['result'])) {
                // 便捷路径：{{step.field}} 自动解析为 {{step.result.field}}
                $current = $current['result'][$part];
            } else {
                return '';
            }
        }

        if (is_array($current)) {
            return json_encode($current, JSON_UNESCAPED_UNICODE);
        }
        return (string) $current;
    }

    /* ===================== 验证 + 日志 ===================== */

    /**
     * 验证步骤定义。
     *
     * @return string|null 错误信息，null 表示通过
     */
    private function validateSteps(array $steps, bool $allowLoop = true): ?string
    {
        $allowedTypes = ['api_call', 'transform', 'condition', 'log', 'delay', 'sub_workflow', 'switch'];
        if ($allowLoop) {
            $allowedTypes[] = 'loop';
        }
        $seenNames = [];

        foreach ($steps as $i => $step) {
            $type = (string) ($step['type'] ?? '');
            if (!in_array($type, $allowedTypes, true)) {
                return "步骤 #{$i} 类型无效: {$type}（允许: " . implode(', ', $allowedTypes) . "）";
            }

            $name = (string) ($step['name'] ?? '');
            if ($name === '') {
                return "步骤 #{$i} 缺少 name";
            }
            if (in_array($name, $seenNames, true)) {
                return "步骤 name 重复: {$name}";
            }
            $seenNames[] = $name;

            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
                return "步骤 name '{$name}' 必须为合法标识符";
            }

            $config = (array) ($step['config'] ?? []);

            if ($type === 'api_call') {
                $url = (string) ($config['url'] ?? '');
                if ($url === '') {
                    return "步骤 '{$name}' 缺少 config.url";
                }
                $maxRetries = (int) ($config['max_retries'] ?? 0);
                if ($maxRetries < 0 || $maxRetries > 3) {
                    return "步骤 '{$name}' config.max_retries 必须在 0-3 之间";
                }
                $backoffMs = (int) ($config['backoff_ms'] ?? 1000);
                if ($backoffMs < 100 || $backoffMs > 5000) {
                    return "步骤 '{$name}' config.backoff_ms 必须在 100-5000 之间";
                }
                $timeout = (int) ($config['timeout'] ?? 30);
                if ($timeout < 1 || $timeout > 60) {
                    return "步骤 '{$name}' config.timeout 必须在 1-60 秒之间";
                }
            }
            if ($type === 'transform') {
                $mappings = (array) ($config['mappings'] ?? []);
                if (empty($mappings)) {
                    return "步骤 '{$name}' 缺少 config.mappings";
                }
            }
            if ($type === 'delay') {
                $seconds = (float) ($config['seconds'] ?? 1);
                if ($seconds < 0 || $seconds > 5) {
                    return "步骤 '{$name}' config.seconds 必须在 0-5 之间（防滥用）";
                }
            }
            if ($type === 'loop') {
                $source = (string) ($config['source'] ?? '');
                if ($source === '') {
                    return "步骤 '{$name}' 缺少 config.source";
                }
                $subSteps = (array) ($config['steps'] ?? []);
                if (empty($subSteps)) {
                    return "步骤 '{$name}' 缺少 config.steps（子步骤数组）";
                }
                // 禁止嵌套 loop（子步骤里不能再有 loop）
                $subError = $this->validateSteps($subSteps, false);
                if ($subError !== null) {
                    return "步骤 '{$name}' 子步骤校验失败: {$subError}";
                }
            }
            if ($type === 'sub_workflow') {
                $wfName = (string) ($config['workflow'] ?? '');
                if ($wfName === '' || !preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $wfName)) {
                    return "步骤 '{$name}' config.workflow 必填，白名单字符集 [a-zA-Z0-9_-]，长度 2-64";
                }
            }
            if ($type === 'switch') {
                $variable = (string) ($config['variable'] ?? '');
                if ($variable === '') {
                    return "步骤 '{$name}' 缺少 config.variable";
                }
                $cases = (array) ($config['cases'] ?? []);
                if (empty($cases)) {
                    return "步骤 '{$name}' 缺少 config.cases（至少一个分支）";
                }
                foreach ($cases as $ci => $case) {
                    $caseValue = (string) ($case['value'] ?? '');
                    if ($caseValue === '') {
                        return "步骤 '{$name}' cases[{$ci}] 缺少 value";
                    }
                    $caseSteps = (array) ($case['steps'] ?? []);
                    if (!empty($caseSteps)) {
                        $caseError = $this->validateSteps($caseSteps, false);
                        if ($caseError !== null) {
                            return "步骤 '{$name}' cases[{$ci}] 子步骤校验失败: {$caseError}";
                        }
                    }
                }
                // default_steps 也需校验
                $defaultSteps = (array) ($config['default_steps'] ?? []);
                if (!empty($defaultSteps)) {
                    $defaultError = $this->validateSteps($defaultSteps, false);
                    if ($defaultError !== null) {
                        return "步骤 '{$name}' default_steps 校验失败: {$defaultError}";
                    }
                }
            }
        }

        return null;
    }

    /* ===================== 可观测性增强（Round 15） ===================== */

    /**
     * GET /api/workflow/step-stats
     * 步骤级统计：按步骤名聚合执行次数、成功率、失败次数、最近失败。
     *
     * Query: days=7 (可选)
     *
     * 从 step_results_json 解析每个步骤的结果，按步骤名聚合。
     * 步骤成功/失败判定走 stepOutcome()（唯一判据，与性能分布、健康页同一口径）。
     * 分布来自 ScanBudget 有界抽样，窗口全集与抽样规模由出参三件套声明。
     */
    public function stepStats()
    {
        $days = max(1, min(365, (int) Request::input('days', 7)));
        $since = DbClock::since('created_at', $days * 86400);

        $scan = $this->scanExecutions(
            'SELECT id, workflow_name, status, step_results_json, created_at',
            'WHERE ' . $since['sql'] . ' AND step_count > 0',
            [$since['binding']]
        );
        $rows = $scan['rows'];

        $stats = [];
        foreach ($rows as $row) {
            $stepResults = json_decode($row['step_results_json'] ?? '{}', true) ?? [];
            foreach ($this->stepResultTree($stepResults) as $leaf) {
                $stepName = $leaf['path'];
                $stepResult = $leaf['result'];
                $outcome = self::stepOutcome($stepResult);
                $isFailed = $outcome[1];

                if (!isset($stats[$stepName])) {
                    $stats[$stepName] = [
                        'step_name' => $stepName,
                        'executions' => 0,
                        'succeeded' => 0,
                        'failed' => 0,
                        'success_rate' => 0,
                        'avg_duration_ms' => 0,
                        'min_duration_ms' => 0,
                        'max_duration_ms' => 0,
                        'last_failure_at' => null,
                        'last_error' => '',
                        'workflow_names' => [],
                        '_duration_sum' => 0,
                    ];
                }

                $stats[$stepName]['executions']++;
                $stats[$stepName]['workflow_names'][] = $row['workflow_name'];

                // 收集耗时
                $dur = (int) ($stepResult['_duration_ms'] ?? $stepResult['duration_ms'] ?? 0);
                if ($dur > 0) {
                    $stats[$stepName]['_duration_sum'] += $dur;
                    if ($dur > $stats[$stepName]['max_duration_ms']) $stats[$stepName]['max_duration_ms'] = $dur;
                    if ($stats[$stepName]['min_duration_ms'] === 0 || $dur < $stats[$stepName]['min_duration_ms']) $stats[$stepName]['min_duration_ms'] = $dur;
                }

                if ($isFailed) {
                    $stats[$stepName]['failed']++;
                    $stats[$stepName]['last_failure_at'] = $row['created_at'];
                    $errStr = (string) ($stepResult['error'] ?? '');
                    if ($errStr !== '' && strlen($errStr) < 500) {
                        $stats[$stepName]['last_error'] = $errStr;
                    }
                } else {
                    $stats[$stepName]['succeeded']++;
                }
            }
        }

        // 计算成功率 + 去重 workflow_names + 平均耗时
        $list = [];
        foreach ($stats as $s) {
            $s['success_rate'] = $s['executions'] > 0
                ? round($s['succeeded'] / $s['executions'] * 100, 1)
                : 0;
            $s['avg_duration_ms'] = $s['executions'] > 0
                ? round($s['_duration_sum'] / $s['executions'], 1)
                : 0;
            $s['workflow_names'] = array_values(array_unique($s['workflow_names']));
            unset($s['_duration_sum']);
            $list[] = $s;
        }

        // 按失败数降序、执行次数降序排序
        usort($list, function ($a, $b) {
            if ($b['failed'] !== $a['failed']) return $b['failed'] <=> $a['failed'];
            return $b['executions'] <=> $a['executions'];
        });

        return $this->ok(array_merge([
            'days' => $days,
            'total_steps' => count($list),
            'items' => $list,
        ], $this->scanDeclared($scan)));
    }

    /**
     * GET /api/workflow/duration-distribution
     * 执行耗时分布：P50/P95/P99 + 直方图分桶。
     *
     * Query: days=7 (可选)
     *
     * 按工作流分组计算百分位数，并返回全量直方图数据。
     */
    public function durationDistribution()
    {
        $days = max(1, min(365, (int) Request::input('days', 7)));
        $since = DbClock::since('created_at', $days * 86400);

        $rows = Db::select(
            "SELECT workflow_name, duration_ms
             FROM kode_lowcode_workflow_executions
             WHERE " . $since['sql'] . " AND duration_ms > 0
             ORDER BY workflow_name, duration_ms ASC",
            [$since['binding']]
        );

        // 按工作流分组
        $byWorkflow = [];
        $allDurations = [];
        foreach ($rows as $row) {
            $name = (string) $row['workflow_name'];
            $dur = (int) $row['duration_ms'];
            $byWorkflow[$name][] = $dur;
            $allDurations[] = $dur;
        }

        // 计算百分位数辅助函数
        $percentile = function (array $sorted, float $p): int {
            if (empty($sorted)) return 0;
            $n = count($sorted);
            $idx = (int) ceil($p * $n);
            $idx = max(0, min($n - 1, $idx - 1));
            return $sorted[$idx];
        };

        // 各工作流统计
        $workflowStats = [];
        foreach ($byWorkflow as $name => $durations) {
            sort($durations);
            $n = count($durations);
            $sum = array_sum($durations);
            $workflowStats[] = [
                'workflow_name' => $name,
                'count' => $n,
                'avg_ms' => round($sum / $n, 1),
                'min_ms' => $durations[0],
                'max_ms' => $durations[$n - 1],
                'p50_ms' => $percentile($durations, 0.5),
                'p95_ms' => $percentile($durations, 0.95),
                'p99_ms' => $percentile($durations, 0.99),
            ];
        }

        // 全量直方图（10 个桶）
        sort($allDurations);
        $histogram = [
            '0-50ms' => 0, '50-100ms' => 0, '100-250ms' => 0,
            '250-500ms' => 0, '500ms-1s' => 0, '1s-2s' => 0,
            '2s-5s' => 0, '5s-10s' => 0, '10s-30s' => 0, '30s+' => 0,
        ];
        foreach ($allDurations as $d) {
            if ($d < 50) $histogram['0-50ms']++;
            elseif ($d < 100) $histogram['50-100ms']++;
            elseif ($d < 250) $histogram['100-250ms']++;
            elseif ($d < 500) $histogram['250-500ms']++;
            elseif ($d < 1000) $histogram['500ms-1s']++;
            elseif ($d < 2000) $histogram['1s-2s']++;
            elseif ($d < 5000) $histogram['2s-5s']++;
            elseif ($d < 10000) $histogram['5s-10s']++;
            elseif ($d < 30000) $histogram['10s-30s']++;
            else $histogram['30s+']++;
        }

        $allSorted = $allDurations;
        sort($allSorted);
        $overallPercentiles = empty($allSorted)
            ? ['p50' => 0, 'p95' => 0, 'p99' => 0]
            : [
                'p50' => $percentile($allSorted, 0.5),
                'p95' => $percentile($allSorted, 0.95),
                'p99' => $percentile($allSorted, 0.99),
            ];

        return $this->ok([
            'days' => $days,
            'total_executions' => count($allDurations),
            'overall_percentiles' => $overallPercentiles,
            'histogram' => $histogram,
            'by_workflow' => $workflowStats,
        ]);
    }

    /**
     * GET /api/workflow/failure-analysis
     * 失败根因分析：错误类型分类 + Top N 失败原因。
     *
     * Query: days=7 (可选)
     *
     * 从失败执行的 step_results_json 中提取错误，按类型分类计数。
     * 错误类型: connection_error / http_error / timeout / condition_mismatch / unknown
     *
     * `total_failed` 是窗口全集（SQL COUNT），分类计数来自有界抽样 —— 所以三件套必须一起出，
     * 否则「占比」的分母会被读成全集（分类只数了抽样那些行）。
     */
    public function failureAnalysis()
    {
        $days = max(1, min(365, (int) Request::input('days', 7)));
        $since = DbClock::since('created_at', $days * 86400);

        $scan = $this->scanExecutions(
            'SELECT id, workflow_name, status, step_results_json, error, created_at',
            "WHERE " . $since['sql'] . " AND status IN ('failed', 'timeout', 'cancelled')",
            [$since['binding']]
        );
        $rows = $scan['rows'];

        $categories = [
            'connection_error' => ['label' => '连接失败', 'count' => 0, 'examples' => []],
            'http_error' => ['label' => 'HTTP 错误', 'count' => 0, 'examples' => []],
            'timeout' => ['label' => '超时', 'count' => 0, 'examples' => []],
            'condition_mismatch' => ['label' => '条件不匹配', 'count' => 0, 'examples' => []],
            'api_error' => ['label' => 'API 业务错误', 'count' => 0, 'examples' => []],
            'unknown' => ['label' => '其他', 'count' => 0, 'examples' => []],
        ];
        $topErrors = [];
        $workflowFailures = [];
        $totalFailed = $scan['window_rows'];

        foreach ($rows as $row) {
            $wfName = (string) $row['workflow_name'];
            if (!isset($workflowFailures[$wfName])) {
                $workflowFailures[$wfName] = ['workflow_name' => $wfName, 'failed' => 0, 'errors' => []];
            }
            $workflowFailures[$wfName]['failed']++;

            $stepResults = json_decode($row['step_results_json'] ?? '{}', true) ?? [];
            $execError = (string) ($row['error'] ?? '');

            // 收集所有步骤错误（含躲进 loop/switch/on_fail 里的那些，见 stepResultTree()）
            $errors = [];
            if ($execError !== '') $errors[] = $execError;
            foreach ($this->stepResultTree($stepResults) as $leaf) {
                $stepError = $leaf['result']['error'] ?? null;
                if (is_string($stepError) && $stepError !== '') {
                    $errors[] = "step '{$leaf['path']}': " . $stepError;
                }
            }

            foreach ($errors as $errStr) {
                $errStr = trim((string) $errStr);
                if ($errStr === '') continue;

                // 分类
                $category = 'unknown';
                $errLower = mb_strtolower($errStr);
                if (
                    str_contains($errLower, 'failed to connect')
                    || str_contains($errLower, 'could not connect')
                    || str_contains($errLower, 'connection refused')
                    || str_contains($errLower, 'connection timed out')
                    || str_contains($errLower, 'no route to host')
                    || str_contains($errLower, 'could not resolve host')
                ) {
                    $category = 'connection_error';
                } elseif (
                    str_contains($errLower, 'http')
                    && (preg_match('/http\s*\d{3}/i', $errStr, $m))
                ) {
                    $category = 'http_error';
                } elseif (
                    str_contains($errLower, 'timeout')
                    || str_contains($errLower, 'timed out')
                    || str_contains($errLower, 'deadline')
                ) {
                    $category = 'timeout';
                } elseif (
                    str_contains($errLower, 'condition')
                    || str_contains($errLower, 'matched: false')
                    || str_contains($errLower, '条件不匹配')
                ) {
                    $category = 'condition_mismatch';
                } elseif (
                    str_contains($errLower, 'code != 0')
                    || str_contains($errLower, 'business error')
                    || str_contains($errLower, 'invalid')
                    || str_contains($errLower, 'not found')
                    || str_contains($errLower, 'unauthorized')
                    || str_contains($errLower, 'forbidden')
                ) {
                    $category = 'api_error';
                }

                $categories[$category]['count']++;
                if (count($categories[$category]['examples']) < 3 && mb_strlen($errStr) <= 200) {
                    $categories[$category]['examples'][] = [
                        'execution_id' => \app\support\HashId::enc((int) $row['id']),
                        'workflow' => $wfName,
                        'error' => $errStr,
                        'created_at' => $row['created_at'],
                    ];
                }

                // Top errors 计数
                $shortErr = mb_substr($errStr, 0, 150);
                $topErrors[$shortErr] = ($topErrors[$shortErr] ?? 0) + 1;
            }
        }

        // 移除 count=0 的分类
        $catList = array_values(array_filter(
            $categories,
            fn($c) => $c['count'] > 0
        ));
        usort($catList, fn($a, $b) => $b['count'] <=> $a['count']);

        // Top N 错误（取前 10）
        arsort($topErrors);
        $topErrorsList = [];
        $rank = 0;
        foreach ($topErrors as $err => $cnt) {
            if ($rank >= 10) break;
            $topErrorsList[] = ['rank' => ++$rank, 'error' => $err, 'count' => $cnt];
        }

        // 按失败数降序
        $wfList = array_values($workflowFailures);
        usort($wfList, fn($a, $b) => $b['failed'] <=> $a['failed']);

        return $this->ok(array_merge([
            'days' => $days,
            'total_failed' => $totalFailed,
            'categories' => $catList,
            'top_errors' => $topErrorsList,
            'by_workflow' => $wfList,
        ], $this->scanDeclared($scan)));
    }

    /* ===================== 步骤引用验证 + 执行链路追踪（Round 17） ===================== */

    /**
     * POST /api/workflow/definitions/validate-refs
     * 验证步骤间的变量引用有效性。
     *
     * Body: { steps: [...], workflow_name?: "..." }
     *
     * 检查规则：
     * 1. {{params.field}} — 合法（params 在执行时注入）
     * 2. {{step_name.result.field}} — step_name 必须在步骤列表中且位于当前步骤之前
     * 3. 前向引用 — 当前步骤引用了后续步骤的输出（警告）
     * 4. 自引用 — 步骤引用自身输出（警告）
     */
    public function validateStepRefs()
    {
        $steps = (array) Request::input('steps', []);
        $workflowName = trim((string) Request::input('workflow_name', ''));

        if (empty($steps)) {
            return $this->error('steps 不能为空', 422);
        }

        $issues = [];
        $stepNames = [];
        $stepIndex = [];

        // 收集所有步骤名
        foreach ($steps as $idx => $step) {
            $name = (string) ($step['name'] ?? '');
            if ($name !== '') {
                $stepNames[] = $name;
                $stepIndex[$name] = $idx;
            }
        }

        // 递归提取字符串中的所有变量引用
        $extractRefs = function (string $input): array {
            $refs = [];
            if (preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', $input, $m)) {
                $refs = $m[1];
            }
            return $refs;
        };

        // 递归遍历 config 中所有字符串值
        $walkConfig = function (array $config) use ($extractRefs): array {
            $allRefs = [];
            foreach ($config as $key => $value) {
                if (is_string($value)) {
                    $allRefs = array_merge($allRefs, $extractRefs($value));
                } elseif (is_array($value)) {
                    $allRefs = array_merge($allRefs, $walkConfig($value));
                }
            }
            return $allRefs;
        };

        foreach ($steps as $idx => $step) {
            $stepName = (string) ($step['name'] ?? "step_{$idx}");
            $config = (array) ($step['config'] ?? []);
            $refs = $walkConfig($config);

            foreach ($refs as $ref) {
                // params.xxx — 合法，跳过
                if (str_starts_with($ref, 'params.')) continue;

                // step_name.result.field 或 step_name.field
                // 提取 step_name（第一段）
                $parts = explode('.', $ref);
                $refStepName = $parts[0];

                if (!isset($stepIndex[$refStepName])) {
                    $issues[] = [
                        'step' => $stepName,
                        'ref' => $ref,
                        'type' => 'error',
                        'message' => "引用的步骤 '{$refStepName}' 不存在",
                    ];
                    continue;
                }

                // 自引用
                if ($refStepName === $stepName) {
                    $issues[] = [
                        'step' => $stepName,
                        'ref' => $ref,
                        'type' => 'warning',
                        'message' => "步骤引用自身输出",
                    ];
                    continue;
                }

                // 前向引用（引用了后续步骤）
                if ($stepIndex[$refStepName] > $idx) {
                    $issues[] = [
                        'step' => $stepName,
                        'ref' => $ref,
                        'type' => 'warning',
                        'message' => "前向引用：'{$refStepName}' 在当前步骤之后定义",
                    ];
                }
            }
        }

        $hasErrors = !empty(array_filter($issues, fn($i) => $i['type'] === 'error'));

        return $this->ok([
            'valid' => !$hasErrors,
            'total_issues' => count($issues),
            'errors' => array_values(array_filter($issues, fn($i) => $i['type'] === 'error')),
            'warnings' => array_values(array_filter($issues, fn($i) => $i['type'] === 'warning')),
            'step_count' => count($steps),
            'step_names' => $stepNames,
            'workflow_name' => $workflowName,
        ]);
    }

    /**
     * GET /api/workflow/executions/{id}/trace
     * 执行链路追踪：返回完整的执行树（含 sub_workflow 嵌套）。
     *
     * 从 step_results_json 构建树形结构：
     * - 每个步骤是一个节点（name/type/status/duration/error）
     * - 容器步骤（sub_workflow / loop / switch / on_fail）里真跑过的子结果展开为子节点
     * - 树只由 `stepResultTree()` 走一遍，这里按 pre-order 把 `parent` 复原成 children
     */
    public function executionTrace()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为执行记录不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('执行记录不存在', 404);
        }
        $rows = Db::select(
            "SELECT id, workflow_name, workflow_id, status, step_results_json, duration_ms, error, trace_id, created_at, idempotency_key
             FROM kode_lowcode_workflow_executions WHERE id = ?",
            [$id]
        );
        if (empty($rows)) {
            return $this->error('执行记录不存在', 404);
        }

        $row = $rows[0];
        $stepResults = json_decode($row['step_results_json'] ?? '{}', true) ?? [];

        // 走树只有一份：`stepResultTree()` 交出「这一轮真跑过的每一格」（含藏在 loop 的某一轮、
        // switch 的某一分支、以及失败步骤的恢复步骤里的那些），这里把 `parent` 复原成 children。
        //
        // **pre-order ⇒ 挂到「最近一次出现」的那个同 path 节点**：loop 的每一轮各交回一枚叶子，
        // 于是同一枚 `path` 会出现两次（两轮都跑了同名的一步）；只按 path 建一张表的话，
        // 第二轮的子步骤会被挂到第一轮那个父节点下面（或直接找不到父亲），树看着是深的、
        // 轮次却是错的。所以 `$ordByPath[$leaf['path']]` 必须在**解析完 `$leaf['parent']` 之后**再写。
        $nodes = [];
        $ordByPath = [];
        $childrenOf = [];
        $roots = [];
        foreach ($this->stepResultTree($stepResults, '', (string) $row['workflow_name']) as $leaf) {
            $result = $leaf['result'];
            $parentOrd = $leaf['parent'] === '' ? null : ($ordByPath[$leaf['parent']] ?? null);
            $ord = count($nodes);
            if ($parentOrd === null) {
                // 顶层，或认不出父亲 —— 认不出也挂到根上，而不是丢掉那一格：
                // 它可能是「这一轮真的跑过（且超时了）」的唯一记录。
                $roots[] = $ord;
            } else {
                $childrenOf[$parentOrd][] = $ord;
            }

            $node = [
                'name' => self::stepDisplayName($leaf),
                'status' => self::stepOutcome($result)[0] ? 'succeeded' : 'failed',
                'duration_ms' => (int) ($result['_duration_ms'] ?? $result['duration_ms'] ?? 0),
                'error' => $result['error'] ?? null,
                'depth' => (int) $leaf['depth'],
                'children' => [],
                'workflow' => (string) $leaf['workflow'],
            ];

            // 如果是 sub_workflow，登记被调用的那枚工作流（结论仍由 `stepOutcome` 给，
            // 但 sub_workflow 容器自带 `status` 时以它为准 —— 那是内层整趟的结论）。
            if (isset($result['steps']) && is_array($result['steps'])) {
                $node['sub_workflow'] = (string) ($result['workflow'] ?? $node['name']);
                $node['status'] = (string) ($result['status'] ?? $node['status']);
                $node['duration_ms'] = (int) ($result['duration_ms'] ?? $node['duration_ms']);
            }

            $nodes[] = $node;
            $ordByPath[$leaf['path']] = $ord;
        }

        $renderNode = null;
        $renderNode = function (int $ord) use (&$renderNode, $nodes, $childrenOf): array {
            $node = $nodes[$ord];
            foreach ($childrenOf[$ord] ?? [] as $childOrd) {
                $node['children'][] = $renderNode($childOrd);
            }
            return $node;
        };

        $tree = [];
        foreach ($roots as $ord) {
            $tree[] = $renderNode($ord);
        }

        // 统计
        $countNode = function (array $nodes) use (&$countNode): array {
            $total = count($nodes);
            $failed = 0;
            $totalDur = 0;
            foreach ($nodes as $n) {
                if ($n['status'] === 'failed') $failed++;
                $totalDur += $n['duration_ms'];
                $childStats = $countNode($n['children']);
                $total += $childStats['total'];
                $failed += $childStats['failed'];
                $totalDur += $childStats['total_duration_ms'];
            }
            return ['total' => $total, 'failed' => $failed, 'total_duration_ms' => $totalDur];
        };
        $stats = $countNode($tree);

        return $this->ok([
            'id' => \app\support\HashId::enc((int) $row['id']),
            'workflow_name' => $row['workflow_name'],
            'status' => $row['status'],
            'duration_ms' => (int) $row['duration_ms'],
            'error' => $row['error'],
            'trace_id' => $row['trace_id'],
            'created_at' => $row['created_at'],
            'idempotency_key' => $row['idempotency_key'],
            'tree' => $tree,
            'stats' => $stats,
        ]);
    }

    /* ===================== Round 30: 步骤回放 + 失败模式 + SLA 历史 ===================== */

    /**
     * GET /api/workflow/executions/{id}/step-trace
     * 执行步骤回放：返回扁平化的步骤级执行轨迹，含每个步骤的完整输入输出、
     * 耗时、重试次数、缓存标记。支持递归展开 sub_workflow 嵌套步骤。
     */
    public function executionStepTrace()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为执行记录不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('执行记录不存在', 404);
        }
        $rows = Db::select(
            "SELECT id, workflow_name, status, step_results_json, input_json, duration_ms, error, trace_id, created_at
             FROM kode_lowcode_workflow_executions WHERE id = ?",
            [$id]
        );
        if (empty($rows)) {
            return $this->error('执行记录不存在', 404);
        }

        $row = $rows[0];
        $stepResults = json_decode($row['step_results_json'] ?? '{}', true) ?? [];
        $inputParams = json_decode($row['input_json'] ?? '{}', true) ?? [];

        // 走树只有一份：`stepResultTree()` 交出「这一轮真跑过的每一格」，这里按它的 pre-order
        // 摊成逐行清单（loop 的某一步跑过三轮，就在清单上占三行 —— 与时间线同一批）。
        $steps = [];
        $order = 0;
        foreach ($this->stepResultTree($stepResults, '', (string) $row['workflow_name']) as $leaf) {
            $stepResult = $leaf['result'];
            $order++;
            // 逐条时间线的成败也用同一个判据（旧写法自己写了一遍 error/success 两条 if）
            $status = self::stepOutcome($stepResult)[0] ? 'succeeded' : 'failed';
            // 特殊状态
            if (isset($stepResult['status'])) {
                $status = (string) $stepResult['status'];
            }
            // 类型只问 stepTypeOf()：这一处原先自己写了四分支推断（按 `http_code`/`matched`/
            // `message`/`steps` 猜），而同一句「这一步是什么」在依赖健康与瓶颈榜那里各还有
            // 第三、第四份读法。空串是那几枚「不是执行器结果」的格子（容器、合成的轮次超时行），
            // 页面上这一列仍然要有个字，所以在这里落「unknown」—— 落点只有这一处。
            $type = self::stepTypeOf($stepResult);

            $stepData = [
                'order' => $order,
                'name' => self::stepDisplayName($leaf),
                'workflow' => (string) $leaf['workflow'],
                'depth' => (int) $leaf['depth'],
                'status' => $status,
                'duration_ms' => (int) ($stepResult['_duration_ms'] ?? $stepResult['duration_ms'] ?? 0),
                'error' => $stepResult['error'] ?? null,
                'attempt' => self::stepAttempts($stepResult),
                'cached' => (bool) ($stepResult['_cached'] ?? false),
                'type' => $type === '' ? 'unknown' : $type,
            ];

            // 提取关键输出数据
            $outputKeys = ['http_code', 'data', 'message', 'matched', 'expression', 'result', 'workflow', 'status', 'success'];
            $output = [];
            foreach ($outputKeys as $k) {
                if (array_key_exists($k, $stepResult)) {
                    $output[$k] = $stepResult[$k];
                }
            }
            $stepData['output'] = $output;

            $steps[] = $stepData;
        }

        // 统计
        $totalSteps = count($steps);
        $failedSteps = count(array_filter($steps, fn($s) => $s['status'] === 'failed'));
        $totalDuration = array_sum(array_column($steps, 'duration_ms'));

        return $this->ok([
            'execution_id' => \app\support\HashId::enc((int) $row['id']),
            'workflow_name' => $row['workflow_name'],
            'status' => $row['status'],
            'duration_ms' => (int) $row['duration_ms'],
            'error' => $row['error'],
            'trace_id' => $row['trace_id'],
            'created_at' => $row['created_at'],
            'input' => $inputParams,
            'stats' => [
                'total_steps' => $totalSteps,
                'failed_steps' => $failedSteps,
                'total_duration_ms' => $totalDuration,
                'max_depth' => $totalSteps > 0 ? max(array_column($steps, 'depth')) : 0,
            ],
            'steps' => $steps,
        ]);
    }

    /**
     * GET /api/workflow/failure-patterns
     * 失败模式聚类：按规范化错误消息分组，统计各模式出现次数，展示 Top N。
     *
     * Query: days (默认 30), workflow (可选过滤), limit (默认 20)
     *
     * 两条腿口径不同（AGENTS.md §4「聚合不许按行抽样」的最后一处漏口）：
     *  - `total_failed` 走 SQL 的 `COUNT(*)`，算在**同一份 WHERE**（时间窗 + 工作流筛选）上；
     *  - `top_patterns` / `total_patterns` 来自最近 `PATTERN_SAMPLE_ROWS` 行的归一化聚类，
     *    归一化判据只在 PHP 里有一份，所以下沉不进 SQL，改用 `scanned_rows` + `truncated` 如实声明。
     * 旧写法两处都数这一批行，于是 `total_failed` **恒等于 500**（且 `limit` 参数改不动它），
     * 面板上的「总失败数」在窗口第 501 条失败起就再也不涨了 —— 比前三处更安静。
     */
    public function failurePatterns()
    {
        $days = max(1, min(90, (int) Request::input('days', 30)));
        $limit = max(1, min(100, (int) Request::input('limit', 20)));
        $workflow = trim((string) Request::input('workflow', ''));

        $where = "WHERE status = 'failed' AND created_at > NOW() - INTERVAL '{$days} days'";
        $params = [];
        if ($workflow !== '') {
            $where .= " AND workflow_name = ?";
            $params[] = $workflow;
        }

        $totalRow = (array) (Db::select(
            "SELECT COUNT(*) AS total FROM kode_lowcode_workflow_executions {$where}",
            $params
        )[0] ?? []);
        $totalFailed = (int) ($totalRow['total'] ?? 0);

        $sql = "SELECT id, workflow_name, status, step_results_json, error, created_at
                FROM kode_lowcode_workflow_executions {$where}
                ORDER BY created_at DESC LIMIT " . self::PATTERN_SAMPLE_ROWS;

        $rows = Db::select($sql, $params);

        // 提取并规范化错误消息
        $patternMap = [];
        foreach ($rows as $row) {
            $errors = [];
            // 工作流级错误
            if (!empty($row['error'])) {
                $errors[] = (string) $row['error'];
            }
            // 步骤级错误：容器键按执行器类型分岔，只有 stepResultTree() 看得见全部子结果
            $stepResults = json_decode($row['step_results_json'] ?? '{}', true) ?? [];
            foreach ($this->stepResultTree($stepResults) as $leaf) {
                $stepError = $leaf['result']['error'] ?? null;
                if (is_string($stepError) && $stepError !== '') {
                    $errors[] = '[' . $leaf['path'] . '] ' . $stepError;
                }
            }

            // 去重后聚合
            $seen = [];
            foreach ($errors as $err) {
                if (empty(trim($err))) continue;
                // 规范化：去除具体数值、ID 等动态部分
                $normalized = $this->normalizeErrorPattern($err);
                if (isset($seen[$normalized])) continue;
                $seen[$normalized] = true;

                if (!isset($patternMap[$normalized])) {
                    $patternMap[$normalized] = [
                        'pattern' => $normalized,
                        'count' => 0,
                        'workflows' => [],
                        'samples' => [],
                    ];
                }
                $patternMap[$normalized]['count']++;
                if (!in_array($row['workflow_name'], $patternMap[$normalized]['workflows'], true)) {
                    $patternMap[$normalized]['workflows'][] = $row['workflow_name'];
                }
                if (count($patternMap[$normalized]['samples']) < 3) {
                    $patternMap[$normalized]['samples'][] = [
                        'execution_id' => \app\support\HashId::enc((int) $row['id']),
                        'workflow' => $row['workflow_name'],
                        'error' => $err,
                        'created_at' => $row['created_at'],
                    ];
                }
            }
        }

        // 按出现次数排序
        $patterns = array_values($patternMap);
        usort($patterns, fn($a, $b) => $b['count'] <=> $a['count']);
        $patterns = array_slice($patterns, 0, $limit);

        $scanned = count($rows);

        return $this->ok([
            'days' => $days,
            'total_failed' => $totalFailed,
            'total_patterns' => count($patternMap),
            'top_patterns' => $patterns,
            'scanned_rows' => $scanned,
            'truncated' => $totalFailed > $scanned,
        ]);
    }

    /**
     * 规范化错误消息：将动态部分替换为占位符，用于模式聚类。
     */
    private function normalizeErrorPattern(string $error): string
    {
        $pattern = $error;
        // 替换数字（保留小数点后的部分用于区分如 "503" 这类状态码）
        $pattern = preg_replace('/\b\d{3,}\b/', '<N>', $pattern);
        // 替换 UUID
        $pattern = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '<UUID>', $pattern);
        // 替换时间戳
        $pattern = preg_replace('/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', '<DATETIME>', $pattern);
        // 替换 URL
        $pattern = preg_replace('/https?:\/\/[^\s]+/', '<URL>', $pattern);
        // 替换引号内的动态内容（仅保留引号结构）
        $pattern = preg_replace('/"([^"]{20,})"/', '"<TEXT>"', $pattern);
        return $pattern;
    }

    /**
     * GET /api/workflow/sla-history
     * SLA 合规历史趋势：按日统计 SLA 合规率变化。
     *
     * Query: days (默认 30), workflow (可选过滤)
     */
    public function slaHistory()
    {
        $days = max(1, min(90, (int) Request::input('days', 30)));
        $workflow = trim((string) Request::input('workflow', ''));

        // 获取 SLA 目标
        $targets = Db::select(
            "SELECT workflow_name, target_success_rate, target_p95_ms, target_p50_ms, target_daily_min
             FROM kode_lowcode_workflow_sla_targets WHERE enabled = 1"
        );
        $targetMap = [];
        foreach ($targets as $t) {
            $targetMap[(string) $t['workflow_name']] = $t;
        }

        // 按日统计执行数据
        $sql = "SELECT DATE_TRUNC('day', created_at) AS day, workflow_name,
                       COUNT(*) AS total,
                       SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succ,
                       ROUND(AVG(duration_ms)::numeric, 1) AS avg_ms,
                       ROUND((PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms))::numeric, 1) AS p95_ms
                FROM kode_lowcode_workflow_executions
                WHERE created_at > NOW() - INTERVAL '{$days} days'";
        $params = [];
        if ($workflow !== '') {
            $sql .= " AND workflow_name = ?";
            $params[] = $workflow;
        }
        $sql .= " GROUP BY day, workflow_name ORDER BY day DESC, workflow_name";

        $rows = Db::select($sql, $params);

        // 按日聚合
        $byDay = [];
        foreach ($rows as $row) {
            $dayKey = (string) $row['day'];
            if (!isset($byDay[$dayKey])) {
                $byDay[$dayKey] = [
                    'day' => $dayKey,
                    'total' => 0,
                    'succ' => 0,
                    'sum_p95' => 0.0,
                    'wf_count' => 0,
                    'compliant_count' => 0,
                    'breached_count' => 0,
                ];
            }
            $d = &$byDay[$dayKey];
            $total = (int) $row['total'];
            $succ = (int) $row['succ'];
            $p95 = (float) ($row['p95_ms'] ?? 0);
            $d['total'] += $total;
            $d['succ'] += $succ;
            $d['sum_p95'] += $p95 * $total; // weighted
            $d['wf_count']++;

            // 判断合规
            $name = (string) $row['workflow_name'];
            $sr = $total > 0 ? ($succ / $total * 100) : 0;
            $t = $targetMap[$name] ?? ['target_success_rate' => 99.0, 'target_p95_ms' => 500];
            $metSR = $sr >= (float) $t['target_success_rate'];
            $metP95 = $p95 <= (int) $t['target_p95_ms'];
            if ($metSR && $metP95) {
                $d['compliant_count']++;
            } else {
                $d['breached_count']++;
            }
            unset($d);
        }

        // 排序（按日升序）
        $history = array_values($byDay);
        usort($history, fn($a, $b) => strcmp($a['day'], $b['day']));

        // 计算每日合规率
        foreach ($history as &$h) {
            $h['success_rate'] = $h['total'] > 0 ? round($h['succ'] / $h['total'] * 100, 1) : 0;
            $h['avg_p95_ms'] = $h['total'] > 0 ? round($h['sum_p95'] / $h['total'], 1) : 0;
            $h['compliance_rate'] = $h['wf_count'] > 0 ? round($h['compliant_count'] / $h['wf_count'] * 100, 1) : 0;
            unset($h['sum_p95']);
        }
        unset($h);

        return $this->ok([
            'days' => $days,
            'total_days' => count($history),
            'avg_compliance_rate' => count($history) > 0 ? round(array_sum(array_column($history, 'compliance_rate')) / count($history), 1) : 0,
            'trend' => $history,
        ]);
    }

    /* ===================== Round 31: 健康评分 + 重试分析 + Webhook 统计 ===================== */

    /**
     * GET /api/workflow/health-scores
     * 工作流健康评分：综合成功率、延迟、重试率、失败趋势、执行量 5 项指标，
     * 计算 0-100 健康分数，返回排序列表。
     *
     * Query: days (默认 7)
     */
    public function workflowHealthScore()
    {
        $days = max(1, min(90, (int) Request::input('days', 7)));

        // 获取 SLA 目标（用于延迟评分）
        $targets = Db::select(
            "SELECT workflow_name, target_p95_ms FROM kode_lowcode_workflow_sla_targets WHERE enabled = 1"
        );
        $targetMap = [];
        foreach ($targets as $t) {
            $targetMap[(string) $t['workflow_name']] = (int) $t['target_p95_ms'];
        }

        // 收集所有执行过的工作流名称
        $execNames = Db::select(
            "SELECT DISTINCT workflow_name FROM kode_lowcode_workflow_executions WHERE created_at > NOW() - INTERVAL '{$days} days' ORDER BY workflow_name"
        );

        $results = [];
        $retryWindow = 0;
        $retryScanned = 0;
        foreach ($execNames as $en) {
            $name = (string) $en['workflow_name'];

            // 基础统计
            $stats = Db::select(
                "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succ,
                    ROUND(AVG(duration_ms)::numeric, 1) AS avg_ms,
                    ROUND((PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms))::numeric, 1) AS p95_ms
                 FROM kode_lowcode_workflow_executions
                 WHERE workflow_name = ? AND created_at > NOW() - INTERVAL '{$days} days'",
                [$name]
            );
            $s = $stats[0] ?? [];
            $total = (int) ($s['total'] ?? 0);
            $succ = (int) ($s['succ'] ?? 0);
            $avgMs = (float) ($s['avg_ms'] ?? 0);
            $p95Ms = (float) ($s['p95_ms'] ?? 0);

            // 成功率评分 (0-40)
            $successRate = $total > 0 ? ($succ / $total * 100) : 0;
            $scoreSuccess = min(40, round($successRate / 100 * 40));

            // 延迟评分 (0-25): P95 <= target → 满分, 超出越多分越低
            $targetP95 = $targetMap[$name] ?? 500;
            $ratio = $targetP95 > 0 ? $p95Ms / $targetP95 : 1;
            if ($ratio <= 1.0) {
                $scoreLatency = 25;
            } elseif ($ratio <= 1.5) {
                $scoreLatency = round(25 * (1 - ($ratio - 1.0) * 2));
            } elseif ($ratio <= 2.0) {
                $scoreLatency = round(12.5 * (1 - ($ratio - 1.5) * 2));
            } else {
                $scoreLatency = max(0, round(6.25 * (1 - ($ratio - 2.0) * 2)));
            }
            $scoreLatency = max(0, min(25, $scoreLatency));

            // 重试率评分 (0-15): 从 step_results_json 统计重试步骤
            $retryScan = $this->scanExecutions(
                'SELECT step_results_json',
                "WHERE workflow_name = ? AND created_at > NOW() - ?::interval AND step_results_json <> '{}' AND step_results_json IS NOT NULL",
                [$name, "{$days} days"],
                'ORDER BY id DESC',
                self::RETRY_ROWS_PER_WORKFLOW
            );
            $retryWindow += $retryScan['window_rows'];
            $retryScanned += $retryScan['scanned_rows'];
            $retryTotal = 0;
            $retryCount = 0;
            foreach ($retryScan['rows'] as $rs) {
                $steps = json_decode($rs['step_results_json'] ?? '{}', true) ?? [];
                // 分母与分子都数在**结果树**的叶子上：只数顶层的话，躲在 loop 那一格
                // 里的重试步骤压根不进分母，而「重试率」这一维的全部作用就是说这句话。
                foreach ($this->stepResultTree($steps, '', $name) as $leaf) {
                    $retryTotal++;
                    if (self::stepRetried($leaf['result'])) {
                        $retryCount++;
                    }
                }
            }
            $retryRate = $retryTotal > 0 ? ($retryCount / $retryTotal * 100) : 0;
            $scoreRetry = round(max(0, 15 - $retryRate * 0.3));

            // 失败趋势评分 (0-10): 对比前后半期失败率
            $halfDays = max(1, (int) ceil($days / 2));
            $early = Db::select(
                "SELECT COUNT(*) AS t, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS f
                 FROM kode_lowcode_workflow_executions
                 WHERE workflow_name = ? AND created_at > NOW() - INTERVAL '{$days} days' AND created_at <= NOW() - INTERVAL '{$halfDays} days'",
                [$name]
            );
            $late = Db::select(
                "SELECT COUNT(*) AS t, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS f
                 FROM kode_lowcode_workflow_executions
                 WHERE workflow_name = ? AND created_at > NOW() - INTERVAL '{$halfDays} days'",
                [$name]
            );
            $earlyF = (int) ($early[0]['f'] ?? 0);
            $earlyT = (int) ($early[0]['t'] ?? 0);
            $lateF = (int) ($late[0]['f'] ?? 0);
            $lateT = (int) ($late[0]['t'] ?? 0);
            $earlyFailRate = $earlyT > 0 ? ($earlyF / $earlyT * 100) : 0;
            $lateFailRate = $lateT > 0 ? ($lateF / $lateT * 100) : 0;
            $trendDelta = $lateFailRate - $earlyFailRate;
            if ($trendDelta <= 0) {
                $scoreTrend = 10;
            } elseif ($trendDelta < 5) {
                $scoreTrend = round(10 - $trendDelta * 1.5);
            } elseif ($trendDelta < 10) {
                $scoreTrend = round(2.5 - ($trendDelta - 5) * 0.5);
            } else {
                $scoreTrend = 0;
            }
            $scoreTrend = max(0, min(10, $scoreTrend));

            // 执行量评分 (0-10): 有足够执行数据得高分
            $scoreVolume = $total >= 100 ? 10 : ($total >= 50 ? 8 : ($total >= 20 ? 6 : ($total >= 5 ? 4 : 2)));

            $totalScore = $scoreSuccess + $scoreLatency + $scoreRetry + $scoreTrend + $scoreVolume;
            $healthLevel = $totalScore >= 85 ? 'excellent' : ($totalScore >= 70 ? 'good' : ($totalScore >= 50 ? 'fair' : 'poor'));

            $results[] = [
                'workflow_name' => $name,
                'score' => $totalScore,
                'level' => $healthLevel,
                'components' => [
                    'success_rate' => ['score' => $scoreSuccess, 'max' => 40, 'actual' => round($successRate, 1)],
                    'latency' => ['score' => $scoreLatency, 'max' => 25, 'actual_p95_ms' => round($p95Ms, 1), 'target_p95_ms' => $targetP95],
                    'retry_rate' => ['score' => $scoreRetry, 'max' => 15, 'actual_pct' => round($retryRate, 1)],
                    'failure_trend' => ['score' => $scoreTrend, 'max' => 10, 'early_fail_pct' => round($earlyFailRate, 1), 'late_fail_pct' => round($lateFailRate, 1)],
                    'volume' => ['score' => $scoreVolume, 'max' => 10, 'total' => $total],
                ],
                'stats' => [
                    'total' => $total,
                    'success_rate' => round($successRate, 1),
                    'avg_ms' => round($avgMs, 1),
                    'p95_ms' => round($p95Ms, 1),
                ],
            ];
        }

        // 按分数降序排列
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        $levelCounts = ['excellent' => 0, 'good' => 0, 'fair' => 0, 'poor' => 0];
        foreach ($results as $r) {
            $levelCounts[$r['level']]++;
        }

        return $this->ok(array_merge([
            'days' => $days,
            'total_workflows' => count($results),
            'level_counts' => $levelCounts,
            'avg_score' => count($results) > 0 ? round(array_sum(array_column($results, 'score')) / count($results), 1) : 0,
            'workflows' => $results,
        ], [
            // 跨工作流累加：重试率那一维的每个工作流各扫一次，所以「窗口有多少」与
            // 「进 PHP 有多少」只能是求和口径（见 RETRY_ROWS_PER_WORKFLOW）。
            'window_rows' => $retryWindow,
            'scanned_rows' => $retryScanned,
            'truncated' => $retryWindow > $retryScanned,
        ]));
    }

    /**
     * GET /api/workflow/step-retry-analysis
     * 步骤重试分析：从 step_results_json 提取 _attempt 数据，
     * 分析哪些步骤被重试最多、重试成功率、重试耗尽率。
     *
     * Query: days (默认 30)
     */
    public function stepRetryAnalysis()
    {
        $days = max(1, min(90, (int) Request::input('days', 30)));

        $scan = $this->scanExecutions(
            'SELECT workflow_name, step_results_json',
            "WHERE created_at > NOW() - INTERVAL '{$days} days' AND step_results_json <> '{}' AND step_results_json IS NOT NULL"
        );
        $rows = $scan['rows'];

        // 按步骤名聚合
        $stepData = [];
        $totalStepsSeen = 0;
        $totalRetried = 0;
        $totalRetrySaved = 0; // 重试后最终成功的
        $totalRetryExhausted = 0; // 重试后仍失败的

        $collectStepRetries = function (array $results, string $workflow) use (&$stepData, &$totalStepsSeen, &$totalRetried, &$totalRetrySaved, &$totalRetryExhausted): void {
            foreach ($this->stepResultTree($results, '', $workflow) as $leaf) {
                $stepName = $leaf['path'];
                $stepResult = $leaf['result'];
                $totalStepsSeen++;

                $attempt = self::stepAttempts($stepResult);
                [$success] = self::stepOutcome($stepResult);
                $durationMs = (int) ($stepResult['_duration_ms'] ?? 0);

                $key = "{$leaf['workflow']}::{$stepName}";
                if (!isset($stepData[$key])) {
                    $stepData[$key] = [
                        'workflow' => $leaf['workflow'],
                        'step_name' => $stepName,
                        'depth' => $leaf['depth'],
                        'total' => 0,
                        'retried' => 0,
                        'retry_exhausted' => 0,
                        'retry_success' => 0,
                        'attempts_sum' => 0,
                        'attempts_max' => 0,
                        'durations_sum' => 0,
                        'durations_max' => 0,
                    ];
                }

                $d = &$stepData[$key];
                $d['total']++;
                $d['attempts_sum'] += $attempt;
                $d['attempts_max'] = max($d['attempts_max'], $attempt);
                $d['durations_sum'] += $durationMs;
                $d['durations_max'] = max($d['durations_max'], $durationMs);

                if (self::stepRetried($stepResult)) {
                    $totalRetried++;
                    $d['retried']++;
                    if ($success) {
                        $totalRetrySaved++;
                        $d['retry_success']++;
                    } else {
                        $totalRetryExhausted++;
                        $d['retry_exhausted']++;
                    }
                }
                unset($d);
            }
        };

        foreach ($rows as $row) {
            $steps = json_decode($row['step_results_json'] ?? '{}', true) ?? [];
            $collectStepRetries($steps, (string) $row['workflow_name']);
        }

        // 构建结果
        $results = [];
        $excessiveDetails = [];
        foreach ($stepData as $step) {
            $retried = $step['retried'];
            $retryRate = $step['total'] > 0 ? round($retried / $step['total'] * 100, 1) : 0;
            $retrySuccessRate = $retried > 0 ? round($step['retry_success'] / $retried * 100, 1) : 0;
            $avgAttempts = $step['total'] > 0 ? round($step['attempts_sum'] / $step['total'], 2) : 1;
            $exhaustRate = $retried > 0 ? round($step['retry_exhausted'] / $retried * 100, 1) : 0;
            $avgDuration = $step['total'] > 0 ? round($step['durations_sum'] / $step['total'], 1) : 0;

            // 过度重试：重试率或耗尽率过半，且样本量足够
            $isExcessive = ($retryRate >= 50 || $exhaustRate >= 50) && $step['total'] >= 3;

            if ($isExcessive) {
                $excessiveDetails[] = [
                    'workflow' => $step['workflow'],
                    'step_name' => $step['step_name'],
                    'total' => $step['total'],
                    'retry_rate_pct' => $retryRate,
                    'exhaust_rate_pct' => $exhaustRate,
                    'avg_duration_ms' => $avgDuration,
                ];
            }

            $results[] = [
                'workflow' => $step['workflow'],
                'step_name' => $step['step_name'],
                'depth' => $step['depth'],
                'total' => $step['total'],
                'retried' => $retried,
                'retry_rate_pct' => $retryRate,
                'avg_attempts' => $avgAttempts,
                'max_attempts' => $step['attempts_max'],
                'retry_success_count' => $step['retry_success'],
                'retry_success_rate_pct' => $retrySuccessRate,
                'retry_exhausted_count' => $step['retry_exhausted'],
                'exhaust_rate_pct' => $exhaustRate,
                'avg_duration_ms' => $avgDuration,
                'max_duration_ms' => $step['durations_max'],
                'is_flaky' => $retryRate >= 50,
                'is_excessive' => $isExcessive,
            ];
        }

        // 按重试率降序
        usort($results, fn($a, $b) => [$b['retry_rate_pct'], $b['retried']] <=> [$a['retry_rate_pct'], $a['retried']]);

        $flakyCount = count(array_filter($results, fn($r) => $r['is_flaky']));
        $excessiveCount = count($excessiveDetails);

        return $this->ok(array_merge([
            'days' => $days,
            'total_steps_seen' => $totalStepsSeen,
            'total_retried' => $totalRetried,
            'total_retry_saved' => $totalRetrySaved,
            'total_retry_exhausted' => $totalRetryExhausted,
            'overall_retry_rate_pct' => $totalStepsSeen > 0 ? round($totalRetried / $totalStepsSeen * 100, 1) : 0,
            'overall_retry_success_rate_pct' => $totalRetried > 0 ? round($totalRetrySaved / $totalRetried * 100, 1) : 0,
            'overall_exhaust_rate_pct' => $totalRetried > 0 ? round($totalRetryExhausted / $totalRetried * 100, 1) : 0,
            'flaky_steps' => $flakyCount,
            'excessive_retry_steps' => $excessiveCount,
            'excessive_details' => $excessiveDetails,
            'steps' => $results,
        ], $this->scanDeclared($scan)));
    }

    /**
     * GET /api/workflow/webhook-delivery-stats
     * Webhook 投递统计：汇总投递成功/失败/DLQ 数量、平均延迟、
     * 按目标 URL 分组统计、重试分布。
     *
     * Query: days (默认 30)
     *
     * 头部四个数字（总数 / 各状态 / 投递率 / 平均延迟）算在**整个时间窗**上（一条 SQL 腿），
     * 不是下面那份 `LIMIT 2000` 的抽样行 —— 与 #131 修掉的 alertHistory / stepRegressionHistory /
     * webhookDlq 同一条线（AGENTS §4「聚合不许按行抽样」的剩下两处漏口）。
     * 分组明细（by_url / retry_distribution / http_status_codes）仍来自抽样：
     * URL 归一化只在 PHP 里有一份判据，搬进 SQL 等于写第二份，所以那三块靠
     * `scanned_rows` + `truncated` 如实声明它们只描述最近这些行。
     */
    public function webhookDeliveryStats()
    {
        $days = max(1, min(90, (int) Request::input('days', 30)));

        $head = Db::select(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM((status = 'delivered')::int), 0)    AS delivered,
                    COALESCE(SUM((status = 'failed')::int), 0)       AS failed,
                    COALESCE(SUM((status = 'dead_letter')::int), 0)  AS dead_letter,
                    COALESCE(SUM((status = 'retrying')::int), 0)     AS retrying,
                    COALESCE(SUM((status = 'pending')::int), 0)      AS pending,
                    COALESCE(SUM((status NOT IN ('delivered', 'failed', 'dead_letter', 'retrying', 'pending'))::int), 0) AS other,
                    COALESCE(AVG(duration_ms) FILTER (WHERE status IN ('delivered', 'failed')), 0) AS avg_latency_ms
             FROM kode_lowcode_workflow_webhook_logs
             WHERE created_at > NOW() - INTERVAL '{$days} days'"
        );
        $headRow = (array) ($head[0] ?? []);

        $total = (int) ($headRow['total'] ?? 0);
        $deliveredCount = (int) ($headRow['delivered'] ?? 0);
        $latencyRows = $deliveredCount + (int) ($headRow['failed'] ?? 0);

        $rows = Db::select(
            "SELECT id, workflow_name, execution_id, target_url, status, http_code, duration_ms, error, retry_count, max_retries, created_at
             FROM kode_lowcode_workflow_webhook_logs
             WHERE created_at > NOW() - INTERVAL '{$days} days'
             ORDER BY created_at DESC LIMIT " . self::WEBHOOK_SAMPLE_ROWS
        );

        $byStatus = [
            'delivered' => $deliveredCount,
            'failed' => (int) ($headRow['failed'] ?? 0),
            'dead_letter' => (int) ($headRow['dead_letter'] ?? 0),
            'retrying' => (int) ($headRow['retrying'] ?? 0),
            'pending' => (int) ($headRow['pending'] ?? 0),
            'other' => (int) ($headRow['other'] ?? 0),
        ];
        $byUrl = [];
        $retryDistribution = []; // retry_count => count
        $errorCodes = [];

        foreach ($rows as $row) {
            $status = (string) $row['status'];

            // 按 URL 分组（去除 query string 便于聚合）
            $urlBase = parse_url((string) $row['target_url'], PHP_URL_PATH) ?? (string) $row['target_url'];
            $host = parse_url((string) $row['target_url'], PHP_URL_HOST) ?? '';
            $urlKey = $host . $urlBase;
            if (!isset($byUrl[$urlKey])) {
                $byUrl[$urlKey] = ['url' => $urlKey, 'total' => 0, 'delivered' => 0, 'failed' => 0, 'dead_letter' => 0, 'avg_duration_ms' => 0.0, 'durations' => []];
            }
            $byUrl[$urlKey]['total']++;
            if ($status === 'delivered') $byUrl[$urlKey]['delivered']++;
            if ($status === 'failed') $byUrl[$urlKey]['failed']++;
            if ($status === 'dead_letter') $byUrl[$urlKey]['dead_letter']++;
            $byUrl[$urlKey]['durations'][] = (int) $row['duration_ms'];

            // 重试分布
            $retryCount = (int) $row['retry_count'];
            $retryDistribution[$retryCount] = ($retryDistribution[$retryCount] ?? 0) + 1;

            // HTTP 状态码
            if ((int) $row['http_code'] > 0) {
                $httpCode = (int) $row['http_code'];
                $errorCodes[$httpCode] = ($errorCodes[$httpCode] ?? 0) + 1;
            }
        }

        // 计算每个 URL 的平均延迟
        foreach ($byUrl as &$url) {
            $url['avg_duration_ms'] = count($url['durations']) > 0
                ? round(array_sum($url['durations']) / count($url['durations']), 1)
                : 0;
            unset($url['durations']);
        }
        unset($url);

        // 按投递量降序
        $urlList = array_values($byUrl);
        usort($urlList, fn($a, $b) => $b['total'] <=> $a['total']);
        $urlList = array_slice($urlList, 0, 20);

        // 重试分布排序
        ksort($retryDistribution);

        $deliveryRate = $total > 0 ? round($deliveredCount / $total * 100, 1) : 0;

        return $this->ok([
            'days' => $days,
            'total_deliveries' => $total,
            'delivery_rate_pct' => $deliveryRate,
            'by_status' => $byStatus,
            'avg_latency_ms' => $latencyRows > 0 ? round((float) $headRow['avg_latency_ms'], 1) : 0,
            'by_url' => $urlList,
            'retry_distribution' => $retryDistribution,
            'http_status_codes' => $errorCodes,
            'scanned_rows' => count($rows),
            'truncated' => $total > count($rows),
        ]);
    }

    /* ===================== Round 32: 定时分析 + API 聚合 + 超时热点 ===================== */

    /**
     * GET /api/workflow/schedule-analysis
     * 定时执行分析：解析 cron 表达式，预测未来 5 次执行时间，
     * 分析执行频率、实际间隔与计划间隔的偏差、遗漏次数。
     *
     * Query: limit (默认 20)
     */
    public function scheduleAnalysis()
    {
        $limit = max(1, min(50, (int) Request::input('limit', 20)));

        $workflows = Db::select(
            "SELECT id, name, schedule_cron, next_run_at, last_run_at, created_at, updated_at
             FROM kode_lowcode_workflows
             WHERE schedule_cron <> '' AND schedule_cron IS NOT NULL
             ORDER BY name"
        );

        $results = [];
        $now = time();

        foreach ($workflows as $wf) {
            $cron = (string) $wf['schedule_cron'];
            $parts = preg_split('/\s+/', trim($cron));
            $validCron = count($parts) === 5;

            // 预测未来 5 次执行（内部 epoch 秒，出参那一段再折成可读串）
            $nextRuns = [];
            if ($validCron) {
                $tempTime = $now;
                for ($i = 0; $i < 5; $i++) {
                    $next = $this->nextCronTime($cron, $tempTime);
                    if ($next === null) break;
                    $nextRuns[] = date('Y-m-d H:i:s', $next);
                    $tempTime = $next;
                }
            }

            // 计算 cron 频率描述
            $freqLabel = $this->cronFrequencyLabel($cron);

            // 计算理论间隔（秒）
            $expectedInterval = $this->cronExpectedIntervalSeconds($cron);

            // 从执行记录统计实际间隔
            $executions = Db::select(
                "SELECT created_at FROM kode_lowcode_workflow_executions
                 WHERE workflow_name = ? AND created_at > NOW() - INTERVAL '30 days'
                 ORDER BY created_at DESC LIMIT 30",
                [(string) $wf['name']]
            );
            $actualIntervals = [];
            $prevTime = null;
            foreach ($executions as $exec) {
                if ($prevTime !== null) {
                    $diff = strtotime($prevTime) - strtotime($exec['created_at']);
                    if ($diff > 0) $actualIntervals[] = $diff;
                }
                $prevTime = $exec['created_at'];
            }
            $avgActualInterval = count($actualIntervals) > 0 ? round(array_sum($actualIntervals) / count($actualIntervals)) : 0;
            $minActualInterval = count($actualIntervals) > 0 ? min($actualIntervals) : 0;
            $maxActualInterval = count($actualIntervals) > 0 ? max($actualIntervals) : 0;

            // 遗漏次数
            $missedCount = Db::select(
                "SELECT COUNT(*) AS cnt FROM kode_lowcode_workflow_executions
                 WHERE workflow_name = ? AND status = 'failed' AND error LIKE '%超时%'
                 AND created_at > NOW() - INTERVAL '30 days'",
                [(string) $wf['name']]
            );
            $timeoutCount = (int) ($missedCount[0]['cnt'] ?? 0);

            $results[] = [
                'id' => \app\support\HashId::enc((int) $wf['id']),
                'name' => (string) $wf['name'],
                'cron' => $cron,
                'valid_cron' => $validCron,
                'frequency_label' => $freqLabel,
                'expected_interval_seconds' => $expectedInterval,
                'last_run_at' => $wf['last_run_at'],
                'next_run_at' => $wf['next_run_at'],
                'next_runs' => $nextRuns,
                'avg_actual_interval_seconds' => $avgActualInterval,
                'min_actual_interval_seconds' => $minActualInterval,
                'max_actual_interval_seconds' => $maxActualInterval,
                'interval_drift_pct' => ($expectedInterval > 0 && $avgActualInterval > 0)
                    ? round(($avgActualInterval - $expectedInterval) / $expectedInterval * 100, 1)
                    : 0,
                'execution_count_30d' => count($executions),
                'timeout_failures_30d' => $timeoutCount,
                'status' => $validCron ? ($nextRuns[0] !== null ? 'scheduled' : 'invalid_next') : 'invalid_cron',
            ];
        }

        return $this->ok([
            'total_scheduled' => count($results),
            'valid_crons' => count(array_filter($results, fn($r) => $r['valid_cron'])),
            'invalid_crons' => count(array_filter($results, fn($r) => !$r['valid_cron'])),
            'workflows' => $results,
        ]);
    }

    /**
     * 将 cron 表达式转换为频率描述标签。
     */
    private function cronFrequencyLabel(string $cron): string
    {
        $parts = preg_split('/\s+/', trim($cron));
        if (count($parts) !== 5) return '无效 cron';

        [$min, $hour, $day, $month, $dow] = $parts;

        // 常见模式识别
        if ($min === '*' && $hour === '*' && $day === '*' && $month === '*' && $dow === '*') {
            return '每分钟';
        }
        if (preg_match('/^\*\/(\d+)$/', $min, $m)) {
            return "每 {$m[1]} 分钟";
        }
        if (preg_match('/^\*\/(\d+)$/', $hour, $m) && $min === '0') {
            return "每 {$m[1]} 小时";
        }
        if (preg_match('/^\*\/(\d+)$/', $day, $m) && $hour !== '*' && $min !== '*') {
            return "每 {$m[1]} 天";
        }
        if ($day !== '*' && $dow === '*' && $month === '*') {
            return "每月 {$day} 号";
        }
        if ($dow !== '*' && $day === '*' && $month === '*') {
            $dowMap = ['0' => '日', '1' => '一', '2' => '二', '3' => '三', '4' => '四', '5' => '五', '6' => '六'];
            $days = [];
            foreach (explode(',', $dow) as $d) {
                if (isset($dowMap[$d])) $days[] = $dowMap[$d];
            }
            return '每周' . implode('、', $days);
        }
        if ($month !== '*') {
            return "每年 {$month} 月";
        }
        return "自定义: {$cron}";
    }

    /**
     * 估算 cron 表达式的理论执行间隔（秒）。
     */
    private function cronExpectedIntervalSeconds(string $cron): int
    {
        $parts = preg_split('/\s+/', trim($cron));
        if (count($parts) !== 5) return 0;

        [$min, $hour, $day, $month, $dow] = $parts;

        // 每分钟
        if ($min === '*' && $hour === '*' && $day === '*' && $month === '*' && $dow === '*') {
            return 60;
        }
        // 每 N 分钟
        if (preg_match('/^\*\/(\d+)$/', $min, $m) && $hour === '*' && $day === '*' && $month === '*' && $dow === '*') {
            return (int) $m[1] * 60;
        }
        // 每小时
        if ($hour === '*' && $min !== '*' && $day === '*' && $month === '*' && $dow === '*') {
            return 3600;
        }
        // 每天
        if ($day === '*' && $hour !== '*' && $min !== '*' && $month === '*' && $dow === '*') {
            return 86400;
        }
        // 每周
        if ($dow !== '*' && $day === '*' && $month === '*') {
            return 7 * 86400;
        }
        // 每月
        if ($day !== '*' && $dow === '*' && $month === '*') {
            return 30 * 86400;
        }
        return 0;
    }

    /**
     * GET /api/workflow/api-call-aggregation
     * API 调用聚合分析：从 step_results_json 中提取 api_call 步骤的 URL，
     * 聚合统计调用次数、成功率、平均/P95 延迟、错误率。
     *
     * Query: days (默认 30)
     */
    public function apiCallAggregation()
    {
        $days = max(1, min(90, (int) Request::input('days', 30)));

        $scan = $this->scanExecutions(
            'SELECT workflow_name, step_results_json',
            "WHERE created_at > NOW() - INTERVAL '{$days} days' AND step_results_json <> '{}' AND step_results_json IS NOT NULL"
        );
        $rows = $scan['rows'];

        $apiStats = [];
        $totalApiCalls = 0;

        $collectApiCalls = function (array $results, string $workflow) use (&$apiStats, &$totalApiCalls): void {
            foreach ($this->stepResultTree($results, '', $workflow) as $leaf) {
                $stepName = $leaf['path'];
                $stepResult = $leaf['result'];

                // 检测 api_call 步骤（有 http_code 或 data 字段）
                if (isset($stepResult['http_code']) || isset($stepResult['data'])) {
                    $totalApiCalls++;
                    $url = (string) ($stepResult['url'] ?? $stepResult['target_url'] ?? $stepResult['endpoint'] ?? '');
                    // 从 data 中提取 URL（fallback）
                    if ($url === '' && isset($stepResult['data']['url'])) {
                        $url = (string) $stepResult['data']['url'];
                    }
                    if ($url === '') $url = "[{$stepName}]";

                    // 规范化 URL（去除 query string 便于聚合）
                    $urlBase = parse_url($url, PHP_URL_PATH) ?? $url;
                    $host = parse_url($url, PHP_URL_HOST) ?? '';
                    $urlKey = $host . $urlBase;

                    if (!isset($apiStats[$urlKey])) {
                        $apiStats[$urlKey] = [
                            'url' => $urlKey,
                            'total' => 0,
                            'success' => 0,
                            'failed' => 0,
                            'timeouts' => 0,
                            'durations' => [],
                            'http_codes' => [],
                            'workflows' => [],
                            'steps' => [],
                        ];
                    }

                    $a = &$apiStats[$urlKey];
                    $a['total']++;

                    $httpCode = (int) ($stepResult['http_code'] ?? 0);
                    $a['http_codes'][$httpCode] = ($a['http_codes'][$httpCode] ?? 0) + 1;

                    $duration = (int) ($stepResult['_duration_ms'] ?? $stepResult['duration_ms'] ?? 0);
                    $a['durations'][] = $duration;

                    [$success, $isFailed] = self::stepOutcome($stepResult);

                    if ($success) {
                        $a['success']++;
                    } else {
                        $a['failed']++;
                        $err = (string) ($stepResult['error'] ?? '');
                        if ($isFailed && ($err !== '')
                            && (str_contains($err, '超时') || str_contains($err, 'timeout'))) {
                            $a['timeouts']++;
                        }
                    }

                    if (!in_array($leaf['workflow'], $a['workflows'], true)) $a['workflows'][] = $leaf['workflow'];
                    if (!in_array($stepName, $a['steps'], true)) $a['steps'][] = $stepName;

                    unset($a);
                }
            }
        };

        foreach ($rows as $row) {
            $steps = json_decode($row['step_results_json'] ?? '{}', true) ?? [];
            $collectApiCalls($steps, (string) $row['workflow_name']);
        }

        // 构建结果
        $results = [];
        foreach ($apiStats as $stat) {
            $avgDur = count($stat['durations']) > 0 ? round(array_sum($stat['durations']) / count($stat['durations']), 1) : 0;
            sort($stat['durations']);
            $p95Idx = (int) ceil(count($stat['durations']) * 0.95) - 1;
            $p95Dur = count($stat['durations']) > 0 ? $stat['durations'][max(0, $p95Idx)] : 0;
            $successRate = $stat['total'] > 0 ? round($stat['success'] / $stat['total'] * 100, 1) : 0;
            $timeoutRate = $stat['total'] > 0 ? round($stat['timeouts'] / $stat['total'] * 100, 1) : 0;

            // 清理 http_codes
            $cleanCodes = [];
            foreach ($stat['http_codes'] as $code => $count) {
                if ((int) $code > 0) $cleanCodes[(int) $code] = $count;
            }

            $results[] = [
                'url' => $stat['url'],
                'total_calls' => $stat['total'],
                'success_count' => $stat['success'],
                'failed_count' => $stat['failed'],
                'timeout_count' => $stat['timeouts'],
                'success_rate_pct' => $successRate,
                'timeout_rate_pct' => $timeoutRate,
                'avg_duration_ms' => $avgDur,
                'p95_duration_ms' => $p95Dur,
                'http_status_codes' => $cleanCodes,
                'workflows' => $stat['workflows'],
                'steps' => $stat['steps'],
            ];
        }

        // 按调用次数降序
        usort($results, fn($a, $b) => $b['total_calls'] <=> $a['total_calls']);

        $totalSuccess = array_sum(array_column($results, 'success_count'));
        $overallSuccessRate = $totalApiCalls > 0 ? round($totalSuccess / $totalApiCalls * 100, 1) : 0;

        return $this->ok(array_merge([
            'days' => $days,
            'total_api_calls' => $totalApiCalls,
            'unique_endpoints' => count($results),
            'overall_success_rate_pct' => $overallSuccessRate,
            'endpoints' => $results,
        ], $this->scanDeclared($scan)));
    }

    /**
     * GET /api/workflow/timeout-hotspots
     * 超时热点分析：找出超时率最高的步骤、接近超时阈值的步骤。
     * 按步骤聚合超时次数、超时率、平均耗时 vs 超时阈值。
     *
     * Query: days (默认 30), threshold_pct (默认 80，接近超时阈值百分比)
     */
    public function timeoutHotspots()
    {
        $days = max(1, min(90, (int) Request::input('days', 30)));
        $thresholdPct = max(1, min(100, (int) Request::input('threshold_pct', 80)));

        // 获取工作流超时配置
        $wfTimeouts = Db::select(
            "SELECT name, timeout_ms FROM kode_lowcode_workflows WHERE timeout_ms > 0"
        );
        $timeoutMap = [];
        foreach ($wfTimeouts as $wf) {
            $timeoutMap[(string) $wf['name']] = (int) $wf['timeout_ms'];
        }

        $scan = $this->scanExecutions(
            'SELECT workflow_name, step_results_json, status',
            "WHERE created_at > NOW() - INTERVAL '{$days} days' AND step_results_json <> '{}' AND step_results_json IS NOT NULL"
        );
        $rows = $scan['rows'];

        $stepStats = [];

        $collectTimeoutData = function (array $results, string $workflow) use (&$stepStats): void {
            foreach ($this->stepResultTree($results, '', $workflow) as $leaf) {
                $stepName = $leaf['path'];
                $stepResult = $leaf['result'];

                $duration = (int) ($stepResult['_duration_ms'] ?? $stepResult['duration_ms'] ?? 0);
                $hasError = isset($stepResult['error']) && $stepResult['error'] !== null;
                $isTimeout = $hasError && (str_contains((string) $stepResult['error'], '超时') || str_contains((string) $stepResult['error'], 'timeout'));

                $key = "{$leaf['workflow']}::{$stepName}";
                if (!isset($stepStats[$key])) {
                    $stepStats[$key] = [
                        'workflow' => $leaf['workflow'],
                        'step_name' => $stepName,
                        'depth' => $leaf['depth'],
                        'total' => 0,
                        'timeouts' => 0,
                        'errors' => 0,
                        'durations' => [],
                        'error_messages' => [],
                    ];
                }

                $s = &$stepStats[$key];
                $s['total']++;
                $s['durations'][] = $duration;
                if ($hasError) {
                    $s['errors']++;
                    $s['error_messages'][] = (string) $stepResult['error'];
                }
                if ($isTimeout) {
                    $s['timeouts']++;
                }
                unset($s);
            }
        };

        foreach ($rows as $row) {
            $steps = json_decode($row['step_results_json'] ?? '{}', true) ?? [];
            $collectTimeoutData($steps, (string) $row['workflow_name']);
        }

        $results = [];
        $totalTimeouts = 0;

        foreach ($stepStats as $step) {
            $timeoutRate = $step['total'] > 0 ? round($step['timeouts'] / $step['total'] * 100, 1) : 0;
            $errorRate = $step['total'] > 0 ? round($step['errors'] / $step['total'] * 100, 1) : 0;
            $avgDur = count($step['durations']) > 0 ? round(array_sum($step['durations']) / count($step['durations']), 1) : 0;
            sort($step['durations']);
            $p95Idx = (int) ceil(count($step['durations']) * 0.95) - 1;
            $p95Dur = count($step['durations']) > 0 ? $step['durations'][max(0, $p95Idx)] : 0;

            // 检查是否接近超时阈值
            $wfTimeout = $timeoutMap[$step['workflow']] ?? 0;
            $nearThreshold = false;
            $thresholdPctUsed = 0.0;
            if ($wfTimeout > 0 && $p95Dur > 0) {
                $ratio = $p95Dur / $wfTimeout * 100;
                $thresholdPctUsed = round($ratio, 1);
                $nearThreshold = $ratio >= $thresholdPct;
            }

            $totalTimeouts += $step['timeouts'];

            // 错误消息频次
            $errorFreq = [];
            foreach ($step['error_messages'] as $msg) {
                $short = mb_substr($msg, 0, 80);
                $errorFreq[$short] = ($errorFreq[$short] ?? 0) + 1;
            }
            arsort($errorFreq);
            $topErrors = array_slice($errorFreq, 0, 3, true);

            $results[] = [
                'workflow' => $step['workflow'],
                'step_name' => $step['step_name'],
                'depth' => $step['depth'],
                'total' => $step['total'],
                'timeouts' => $step['timeouts'],
                'timeout_rate_pct' => $timeoutRate,
                'errors' => $step['errors'],
                'error_rate_pct' => $errorRate,
                'avg_duration_ms' => $avgDur,
                'p95_duration_ms' => $p95Dur,
                'workflow_timeout_ms' => $wfTimeout,
                'p95_vs_timeout_pct' => $thresholdPctUsed,
                'near_threshold' => $nearThreshold,
                'is_critical' => $timeoutRate >= 10 || $nearThreshold,
                'top_errors' => $topErrors,
            ];
        }

        // 按超时率降序，再按接近阈值降序
        usort($results, function ($a, $b) {
            if ($a['timeout_rate_pct'] !== $b['timeout_rate_pct']) {
                return $b['timeout_rate_pct'] <=> $a['timeout_rate_pct'];
            }
            return $b['p95_vs_timeout_pct'] <=> $a['p95_vs_timeout_pct'];
        });

        $criticalCount = count(array_filter($results, fn($r) => $r['is_critical']));
        $nearThresholdCount = count(array_filter($results, fn($r) => $r['near_threshold']));

        return $this->ok(array_merge([
            'days' => $days,
            'threshold_pct' => $thresholdPct,
            'total_steps' => count($results),
            'total_timeouts' => $totalTimeouts,
            'critical_steps' => $criticalCount,
            'near_threshold_steps' => $nearThresholdCount,
            'steps' => $results,
        ], $this->scanDeclared($scan)));
    }

    /* ===================== Webhook 死信队列 + 载荷预览（Round 18） ===================== */

    /**
     * GET /api/workflow/webhook-dlq
     * 死信队列列表：重试次数已耗尽的 Webhook 投递。
     */
    public function webhookDlq()
    {
        $limit = max(1, min(200, (int) Request::input('limit', 50)));
        $workflow = trim((string) Request::input('workflow', ''));

        $where = "WHERE status = 'dead_letter'";
        $params = [];
        if ($workflow !== '') {
            $where .= ' AND workflow_name = ?';
            $params[] = $workflow;
        }

        // 总数与排行算在同一份筛选条件上。此前排行那条 SQL 把 `workflow_name = ?` 漏了
        // （它自己另写了一遍 WHERE），于是筛了工作流 A、下面那行「按工作流分布」还列着 B；
        // 而 total 取的是 `count($items)` —— 也就是被 limit 截断的这一页，不是死信总数。
        $total = (int) (Db::select(
            "SELECT COUNT(*) AS cnt FROM kode_lowcode_workflow_webhook_logs {$where}",
            $params
        )[0]['cnt'] ?? 0);

        $rows = Db::select(
            "SELECT id, workflow_id, workflow_name, execution_id, target_url, status, http_code, duration_ms, error, retry_count, max_retries, created_at
               FROM kode_lowcode_workflow_webhook_logs {$where}
              ORDER BY created_at DESC, id DESC LIMIT ?",
            array_merge($params, [$limit])
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => \app\support\HashId::enc((int) $row['id']),
                'workflow_name' => $row['workflow_name'],
                'execution_id' => \app\support\HashId::enc((int) $row['execution_id']),
                'target_url' => $row['target_url'],
                'http_code' => (int) $row['http_code'],
                'duration_ms' => (int) $row['duration_ms'],
                'error' => $row['error'],
                'retry_count' => (int) $row['retry_count'],
                'max_retries' => (int) $row['max_retries'],
                'created_at' => $row['created_at'],
            ];
        }

        // 统计
        $statsRows = Db::select(
            "SELECT workflow_name, COUNT(*) as cnt FROM kode_lowcode_workflow_webhook_logs {$where}
              GROUP BY workflow_name ORDER BY cnt DESC, workflow_name ASC",
            $params
        );
        $byWorkflow = [];
        foreach ($statsRows as $r) {
            $byWorkflow[] = ['workflow_name' => $r['workflow_name'], 'count' => (int) $r['cnt']];
        }

        return $this->ok([
            'total' => $total,
            'truncated' => $total > count($items),
            'items' => $items,
            'by_workflow' => $byWorkflow,
        ]);
    }

    /**
     * POST /api/workflow/webhook-dlq/{id}/retry
     * 从死信队列手动重试。
     */
    public function webhookDlqRetry()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为日志不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('日志不存在', 404);
        }
        $rows = Db::select("SELECT * FROM kode_lowcode_workflow_webhook_logs WHERE id = ?", [$id]);
        if (empty($rows)) {
            return $this->error('日志不存在', 404);
        }
        $log = $rows[0];
        if ($log['status'] !== 'dead_letter') {
            return $this->error('该日志不在死信队列中');
        }

        // 重置重试计数
        Db::update(
            "UPDATE kode_lowcode_workflow_webhook_logs SET retry_count = 0, status = 'pending' WHERE id = ?",
            [$id]
        );

        // 执行投递
        $notifySecret = trim((string) ($log['notify_secret'] ?? ''));
        $result = $this->deliverWebhook($log['target_url'], $log['request_body'], $notifySecret);

        $finalStatus = ($result['status'] === 'delivered') ? 'delivered' : 'failed';

        Db::update(
            "UPDATE kode_lowcode_workflow_webhook_logs SET status = ?, http_code = ?, duration_ms = ?, error = ?, response_body = ? WHERE id = ?",
            [$finalStatus, $result['http_code'], $result['duration_ms'], $result['error'], mb_substr($result['response_body'], 0, 2048), $id]
        );

        return $this->ok([
            'id' => \app\support\HashId::enc($id),
            'status' => $finalStatus,
            'http_code' => $result['http_code'],
            'message' => $finalStatus === 'delivered' ? '重试成功' : "重试失败: {$result['error']}",
        ]);
    }

    /**
     * POST /api/workflow/webhook-dlq/{id}/drop
     * 从死信队列永久丢弃。
     */
    public function webhookDlqDrop()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为日志不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('日志不存在', 404);
        }
        $rows = Db::select("SELECT * FROM kode_lowcode_workflow_webhook_logs WHERE id = ?", [$id]);
        if (empty($rows)) {
            return $this->error('日志不存在', 404);
        }
        $log = $rows[0];
        if ($log['status'] !== 'dead_letter') {
            return $this->error('该日志不在死信队列中');
        }

        Db::update(
            "UPDATE kode_lowcode_workflow_webhook_logs SET status = 'dropped', error = CONCAT(COALESCE(error, ''), ' [手动丢弃]') WHERE id = ?",
            [$id]
        );

        return $this->ok(['id' => \app\support\HashId::enc($id), 'status' => 'dropped', 'message' => '已从死信队列丢弃']);
    }

    /**
     * POST /api/workflow/webhook-preview
     * Webhook 载荷预览：渲染模板变量，返回预览数据。
     *
     * Body: { workflow_name: "...", execution_id?: number }
     *
     * 支持变量: {{workflow}} {{execution_id}} {{status}} {{duration_ms}} {{error}} {{trace_id}} {{timestamp}}
     */
    public function webhookPreview()
    {
        $workflowName = trim((string) Request::input('workflow_name', ''));
        // definition_id 为对外 HashId 串（兼容历史数字入参），解码失败视为无效
        $definitionRaw = trim((string) Request::input('definition_id', ''));
        $definitionId = 0;
        if ($definitionRaw !== '') {
            $definitionId = ctype_digit($definitionRaw) ? (int) $definitionRaw : (\app\support\HashId::dec($definitionRaw) ?? 0);
            if ($definitionId <= 0) {
                return $this->error('definition_id 无效', 422);
            }
        }

        if ($workflowName === '' && $definitionId === 0) {
            return $this->error('请提供 workflow_name 或 definition_id', 422);
        }

        if ($definitionId > 0) {
            $wfRows = Db::select(
                "SELECT id, name, notify_template, alert_template, notify_url, alert_url FROM kode_lowcode_workflows WHERE id = ?",
                [$definitionId]
            );
            if (empty($wfRows)) {
                return $this->error("工作流 '{$definitionRaw}' 不存在", 404);
            }
            $wf = $wfRows[0];
            $workflowName = $wf['name'];
        } else {
            $wfRows = Db::select(
                "SELECT id, name, notify_template, alert_template, notify_url, alert_url FROM kode_lowcode_workflows WHERE name = ?",
                [$workflowName]
            );
            if (empty($wfRows)) {
                return $this->error("工作流 '{$workflowName}' 不存在", 404);
            }
            $wf = $wfRows[0];
        }

        // 获取最新执行记录作为数据来源；execution_id 为对外 HashId 串（兼容历史数字入参）
        $execRaw = trim((string) Request::input('execution_id', ''));
        $execId = 0;
        if ($execRaw !== '') {
            $execId = ctype_digit($execRaw) ? (int) $execRaw : (\app\support\HashId::dec($execRaw) ?? 0);
            if ($execId <= 0) {
                return $this->error('execution_id 无效', 422);
            }
        }
        if ($execId > 0) {
            $execRows = Db::select(
                "SELECT id, status, duration_ms, error, trace_id, created_at FROM kode_lowcode_workflow_executions WHERE id = ?",
                [$execId]
            );
        } else {
            $execRows = Db::select(
                "SELECT id, status, duration_ms, error, trace_id, created_at FROM kode_lowcode_workflow_executions WHERE workflow_name = ? ORDER BY id DESC LIMIT 1",
                [$workflowName]
            );
        }

        $preview = [];

        // 通知载荷
        $notifyTemplate = trim((string) ($wf['notify_template'] ?? ''));
        if ($notifyTemplate !== '') {
            $vars = $this->renderWebhookVars($notifyTemplate, $wf, $execRows[0] ?? null, $execId);
            $preview['notify'] = [
                'template' => $notifyTemplate,
                'rendered' => $vars,
                'target_url' => $wf['notify_url'],
            ];
        }

        // 告警载荷
        $alertTemplate = trim((string) ($wf['alert_template'] ?? ''));
        if ($alertTemplate !== '') {
            $vars = $this->renderWebhookVars($alertTemplate, $wf, $execRows[0] ?? null, $execId);
            $preview['alert'] = [
                'template' => $alertTemplate,
                'rendered' => $vars,
                'target_url' => $wf['alert_url'],
            ];
        }

        if (empty($preview)) {
            return $this->error('该工作流未配置 notify_template 或 alert_template', 422);
        }

        return $this->ok($preview);
    }

    /**
     * 渲染 Webhook 模板变量。
     */
    private function renderWebhookVars(string $template, array $wf, ?array $exec, int $execId): string
    {
        // 预览面向管理端展示：execution_id 以对外 HashId 串渲染
        $execIdDisplay = $execId > 0 ? \app\support\HashId::enc($execId) : '';
        if ($exec !== null) {
            $execIdDisplay = \app\support\HashId::enc((int) ($exec['id'] ?? 0));
        }
        $replacements = [
            '{{workflow}}' => $wf['name'] ?? '',
            '{{execution_id}}' => $execIdDisplay,
            '{{status}}' => $exec['status'] ?? '',
            '{{duration_ms}}' => (string) ($exec['duration_ms'] ?? ''),
            '{{error}}' => $exec['error'] ?? '',
            '{{trace_id}}' => $exec['trace_id'] ?? '',
            '{{timestamp}}' => date('Y-m-d H:i:s'),
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * 记录执行日志。
     */
    private function logExecution(
        string $workflowName,
        int $workflowId,
        array $input,
        ?array $stepResults,
        string $status,
        string $traceId,
        int $durationMs,
        string $error,
        int $parentExecutionId = 0,
        int $retryDepth = 0
    ): int {
        try {
            $id = Db::insert(
                "INSERT INTO kode_lowcode_workflow_executions (workflow_name, workflow_id, input_json, step_results_json, status, step_count, duration_ms, error, trace_id, parent_execution_id, retry_depth)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $workflowName,
                    $workflowId,
                    json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($stepResults ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $status,
                    count($stepResults ?? []),
                    $durationMs,
                    $error,
                    $traceId,
                    $parentExecutionId,
                    $retryDepth,
                ]
            );
            return is_numeric($id) ? (int) $id : 0;
        } catch (\Throwable) {
            // 静默：日志失败不影响执行
            return 0;
        }
    }

    /* ===================== 熔断器 ===================== */

    /**
     * GET /api/workflow/circuit-breaker
     * 列出所有设置了熔断器的工作流及其状态。
     */
    public function circuitBreakerList()
    {
        $rows = Db::select(
            "SELECT id, name, status, circuit_breaker_threshold, circuit_breaker_count,
                    circuit_breaker_open, circuit_breaker_opened_at, updated_at
             FROM kode_lowcode_workflows
             WHERE circuit_breaker_threshold > 0
             ORDER BY circuit_breaker_open DESC, circuit_breaker_count DESC"
        );
        $rows = array_map(static fn (array $row): array => self::hashOut($row), $rows);
        return $this->ok($rows);
    }

    /**
     * POST /api/workflow/circuit-breaker/{id}/reset
     * 重置熔断器状态（清零计数、关闭开关）。
     */
    public function circuitBreakerReset()
    {
        // 入参 id 为对外 HashId 串，解码失败即视为工作流不存在
        $id = $this->eid();
        if ($id === null) {
            return $this->error('工作流不存在', 404);
        }

        $rows = Db::select("SELECT id, name FROM kode_lowcode_workflows WHERE id = ?", [$id]);
        if (empty($rows)) {
            return $this->error('工作流不存在', 404);
        }

        Db::update(
            "UPDATE kode_lowcode_workflows SET circuit_breaker_count = 0, circuit_breaker_open = FALSE, circuit_breaker_opened_at = NULL, updated_at = NOW() WHERE id = ?",
            [$id]
        );

        return $this->ok(['id' => \app\support\HashId::enc($id), 'name' => $rows[0]['name'], 'reset' => true]);
    }

    /* ===================== 步骤健康仪表板 ===================== */

    /**
     * GET /api/workflow/step-health
     * 步骤级健康指标：超时率、重试率、平均耗时。
     */
    public function stepHealth()
    {
        $days = min(90, max(1, (int) Request::input('days', 7)));
        $workflowFilter = trim((string) Request::input('workflow', ''));

        $where = "WHERE created_at > NOW() - INTERVAL '{$days} days'";
        $params = [];
        if ($workflowFilter !== '') {
            $where .= " AND workflow_name = ?";
            $params[] = $workflowFilter;
        }

        $scan = $this->scanExecutions(
            'SELECT step_results_json, workflow_name, status',
            $where,
            $params
        );
        $rows = $scan['rows'];

        $stats = [];
        $totalExecutions = $scan['window_rows'];

        foreach ($rows as $row) {
            $steps = json_decode($row['step_results_json'] ?? '[]', true) ?? [];
            // 行按结果树的 path 建键（`main_loop.log_item`），不按裸步骤名：躲在循环/分支里的
            // 那一步原先**根本不出行**，而与顶层同名的那一枚又会和它共用一行 ——
            // 两个不同格子的失败率被加在一起平均，页面上看着像「这一步偶尔失败」。
            foreach ($this->stepResultTree($steps, '', (string) $row['workflow_name']) as $leaf) {
                $name = (string) $leaf['path'];
                $stepResult = $leaf['result'];
                if (!isset($stats[$name])) {
                    $stats[$name] = ['total' => 0, 'success' => 0, 'failed' => 0, 'timeout' => 0, 'retried' => 0, 'durations' => []];
                }
                // 这里绝不能用 `$s = &$stats[$name]`：引用一旦留下就不再解绑，
                // 下面那个 `foreach ($stats as $name => $s)` 会把每个元素逐个写进**最后一个键**，
                // 于是最后一个步骤的计数被前面的覆盖掉（实测整条 softfail 从 steps 里消失）。
                $stats[$name]['total']++;

                [$isOk, $isFailed] = self::stepOutcome($stepResult);
                if ($isOk) {
                    $stats[$name]['success']++;
                } elseif ($isFailed) {
                    $stats[$name]['failed']++;
                    $err = self::stepFailureText($stepResult);
                    if (str_contains($err, '超时') || str_contains($err, 'timeout')) {
                        $stats[$name]['timeout']++;
                    }
                }
                if (self::stepRetried($stepResult)) {
                    $stats[$name]['retried']++;
                }
                if (isset($stepResult['_duration_ms'])) {
                    $stats[$name]['durations'][] = (int) $stepResult['_duration_ms'];
                }
            }
        }

        // 聚合统计
        $result = [];
        foreach ($stats as $name => $s) {
            $durations = $s['durations'];
            sort($durations);
            $n = count($durations);
            $avg = $n > 0 ? round(array_sum($durations) / $n, 1) : 0;
            $p50 = $n > 0 ? $durations[(int) ($n * 0.5)] : 0;
            $p95 = $n > 0 ? $durations[(int) ($n * 0.95)] : 0;

            $result[] = [
                'step_name' => $name,
                'total' => $s['total'],
                'success' => $s['success'],
                'failed' => $s['failed'],
                'timeout' => $s['timeout'],
                'retried' => $s['retried'],
                'success_rate' => $s['total'] > 0 ? round($s['success'] / $s['total'] * 100, 1) : 0,
                'retry_rate' => $s['total'] > 0 ? round($s['retried'] / $s['total'] * 100, 1) : 0,
                'timeout_rate' => $s['total'] > 0 ? round($s['timeout'] / $s['total'] * 100, 1) : 0,
                'avg_ms' => $avg,
                'p50_ms' => $p50,
                'p95_ms' => $p95,
            ];
        }

        // 按失败数降序排列
        usort($result, fn($a, $b) => $b['failed'] <=> $a['failed']);

        return $this->ok(array_merge(
            ['days' => $days, 'workflow' => $workflowFilter, 'total_executions' => $totalExecutions, 'steps' => $result],
            $this->scanDeclared($scan)
        ));
    }

    /**
     * GET /api/workflow/cost-trends
     * 执行成本趋势（按小时聚合的内存/耗时/API调用/DB查询/步骤重试）。
     *
     * Query: days (1-30), workflow (可选，按名称过滤)
     */
    public function costTrends()
    {
        $days = min(30, max(1, (int) Request::input('days', 7)));
        $workflowFilter = trim((string) Request::input('workflow', ''));

        $sql = "SELECT date_trunc('hour', created_at) AS hour_bucket,
                       COUNT(*) AS executions,
                       SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succeeded,
                       SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                       AVG(duration_ms) AS avg_duration_ms,
                       MAX(duration_ms) AS max_duration_ms,
                       SUM(COALESCE(memory_peak_kb, 0)) AS total_memory_kb,
                       AVG(COALESCE(memory_peak_kb, 0)) AS avg_memory_kb,
                       SUM(COALESCE(api_calls, 0)) AS total_api_calls,
                       SUM(COALESCE(db_queries, 0)) AS total_db_queries,
                       SUM(COALESCE(steps_retried, 0)) AS total_steps_retried
                FROM kode_lowcode_workflow_executions
                WHERE created_at > NOW() - INTERVAL '{$days} days'";
        $params = [];
        if ($workflowFilter !== '') {
            $sql .= " AND workflow_name = ?";
            $params[] = $workflowFilter;
        }
        $sql .= " GROUP BY hour_bucket ORDER BY hour_bucket";

        $rows = Db::select($sql, $params);

        $buckets = [];
        $totals = [
            'executions' => 0, 'succeeded' => 0, 'failed' => 0,
            'total_duration_ms' => 0, 'total_memory_kb' => 0,
            'total_api_calls' => 0, 'total_db_queries' => 0,
            'total_steps_retried' => 0,
        ];
        $durations = [];

        foreach ($rows as $row) {
            $exec = (int) $row['executions'];
            $succ = (int) $row['succeeded'];
            $fail = (int) $row['failed'];
            $avgDur = (float) $row['avg_duration_ms'];
            $maxDur = (int) $row['max_duration_ms'];
            $totMem = (int) $row['total_memory_kb'];
            $avgMem = (float) $row['avg_memory_kb'];
            $totApi = (int) $row['total_api_calls'];
            $totDb = (int) $row['total_db_queries'];
            $totRetry = (int) $row['total_steps_retried'];

            $buckets[] = [
                'hour' => $row['hour_bucket'],
                'executions' => $exec,
                'succeeded' => $succ,
                'failed' => $fail,
                'success_rate' => $exec > 0 ? round($succ / $exec * 100, 1) : 0,
                'avg_duration_ms' => round($avgDur, 1),
                'max_duration_ms' => $maxDur,
                'total_memory_kb' => $totMem,
                'avg_memory_kb' => round($avgMem, 1),
                'total_api_calls' => $totApi,
                'total_db_queries' => $totDb,
                'total_steps_retried' => $totRetry,
            ];
            $totals['executions'] += $exec;
            $totals['succeeded'] += $succ;
            $totals['failed'] += $fail;
            $totals['total_duration_ms'] += (int) round($avgDur * $exec);
            $totals['total_memory_kb'] += $totMem;
            $totals['total_api_calls'] += $totApi;
            $totals['total_db_queries'] += $totDb;
            $totals['total_steps_retried'] += $totRetry;
            if ($exec > 0) {
                $durations[] = $avgDur;
            }
        }

        $overall = [
            'executions' => $totals['executions'],
            'success_rate' => $totals['executions'] > 0 ? round($totals['succeeded'] / $totals['executions'] * 100, 1) : 0,
            'failed' => $totals['failed'],
            'avg_duration_ms' => count($durations) > 0 ? round(array_sum($durations) / count($durations), 1) : 0,
            'total_memory_kb' => $totals['total_memory_kb'],
            'total_api_calls' => $totals['total_api_calls'],
            'total_db_queries' => $totals['total_db_queries'],
            'total_steps_retried' => $totals['total_steps_retried'],
        ];

        return $this->ok([
            'days' => $days,
            'workflow' => $workflowFilter,
            'overall' => $overall,
            'buckets' => $buckets,
        ]);
    }

    /* ===================== Round 33: 步骤性能趋势 ===================== */

    /**
     * GET /api/workflow/step-trend
     * 步骤性能趋势：按天聚合步骤级 avg_ms / p95_ms / success_rate，检测性能劣化。
     *
     * Query: days=14 (默认14天), step_name=xxx (可选过滤)
     */
    public function stepTrend()
    {
        $days = min(60, max(1, (int) Request::input('days', 14)));
        $stepFilter = trim((string) Request::input('step_name', ''));
        $workflowFilter = trim((string) Request::input('workflow', ''));

        $where = [];
        $params = [];

        if ($stepFilter !== '') {
            $where[] = "kv.key = ?";
            $params[] = $stepFilter;
        }
        if ($workflowFilter !== '') {
            $where[] = "e.workflow_name = ?";
            $params[] = $workflowFilter;
        }
        $whereStr = !empty($where) ? ' AND ' . implode(' AND ', $where) : '';

        [$failedSql] = self::stepOutcomeSql('kv.value');
        $sql = "
            WITH exploded AS (
                SELECT
                    date_trunc('day', e.created_at) AS day,
                    kv.key AS step_name,
                    (kv.value->>'_duration_ms')::numeric AS duration_ms,
                    {$failedSql} AS failed
                FROM kode_lowcode_workflow_executions e,
                     LATERAL json_each(COALESCE(e.step_results_json, '{}')::json) AS kv
                WHERE e.created_at > NOW() - INTERVAL '{$days} days'
                  AND e.step_results_json <> '{}'
                  {$whereStr}
            )
            SELECT
                step_name,
                day::text AS day_label,
                COUNT(*) AS exec_count,
                SUM(CASE WHEN failed THEN 1 ELSE 0 END) AS failed_count,
                ROUND(AVG(duration_ms)::numeric, 1) AS avg_ms,
                ROUND((PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY duration_ms))::numeric, 1) AS p50_ms,
                ROUND((PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms))::numeric, 1) AS p95_ms,
                MAX(duration_ms) AS max_ms
            FROM exploded
            WHERE duration_ms IS NOT NULL AND duration_ms > 0
            GROUP BY step_name, day
            ORDER BY step_name, day
        ";

        $rows = Db::select($sql, $params);

        // 按 step_name 分组
        $grouped = [];
        $summary = [];
        foreach ($rows as $r) {
            $stepName = (string) $r['step_name'];
            if (!isset($grouped[$stepName])) {
                $grouped[$stepName] = [];
                $summary[$stepName] = ['exec_total' => 0, 'fail_total' => 0, 'days_count' => 0];
            }
            $cnt = (int) $r['exec_count'];
            $fail = (int) $r['failed_count'];
            $entry = [
                'day' => (string) $r['day_label'],
                'exec_count' => $cnt,
                'failed_count' => $fail,
                'success_rate' => $cnt > 0 ? round(($cnt - $fail) / $cnt * 100, 1) : 100,
                'avg_ms' => (float) $r['avg_ms'],
                'p50_ms' => (float) $r['p50_ms'],
                'p95_ms' => (float) $r['p95_ms'],
                'max_ms' => (float) $r['max_ms'],
            ];
            $grouped[$stepName][] = $entry;
            $summary[$stepName]['exec_total'] += $cnt;
            $summary[$stepName]['fail_total'] += $fail;
            $summary[$stepName]['days_count']++;
        }

        // 构建结果列表（按总执行数降序）
        $steps = [];
        foreach ($grouped as $stepName => $trendData) {
            $s = $summary[$stepName];
            $totalExec = $s['exec_total'];
            $totalFail = $s['fail_total'];

            // 检测劣化：比较最后 3 天 vs 之前的趋势
            $sorted = $trendData;
            usort($sorted, fn($a, $b) => strcmp($a['day'], $b['day']));
            $recent = array_slice($sorted, -3);
            $earlier = array_slice($sorted, 0, max(0, count($sorted) - 3));
            $recentAvg = !empty($recent) ? array_sum(array_column($recent, 'avg_ms')) / count($recent) : 0;
            $earlierAvg = !empty($earlier) ? array_sum(array_column($earlier, 'avg_ms')) / count($earlier) : 0;
            $avgDegradation = $earlierAvg > 0 ? round(($recentAvg - $earlierAvg) / $earlierAvg * 100, 1) : 0;

            $steps[] = [
                'step_name' => $stepName,
                'exec_total' => $totalExec,
                'failed_total' => $totalFail,
                'overall_success_rate' => $totalExec > 0 ? round(($totalExec - $totalFail) / $totalExec * 100, 1) : 100,
                'days_active' => $s['days_count'],
                'avg_ms_degradation' => $avgDegradation,
                'degradation_level' => $avgDegradation > 50 ? 'critical' : ($avgDegradation > 20 ? 'warning' : ($avgDegradation > 0 ? 'notice' : 'stable')),
                'trend' => $trendData,
            ];
        }
        usort($steps, fn($a, $b) => $b['exec_total'] <=> $a['exec_total']);

        // 全局统计
        $degradedCount = count(array_filter($steps, fn($s) => $s['degradation_level'] !== 'stable'));
        $criticalCount = count(array_filter($steps, fn($s) => $s['degradation_level'] === 'critical'));

        return $this->ok([
            'days' => $days,
            'step_name' => $stepFilter,
            'workflow' => $workflowFilter,
            'total_steps' => count($steps),
            'degraded_steps' => $degradedCount,
            'critical_degradation' => $criticalCount,
            'steps' => array_slice($steps, 0, 30),
        ]);
    }

    /* ===================== Round 33: 执行频率热力图 ===================== */

    /**
     * GET /api/workflow/execution-heatmap
     * 执行频率热力图：小时 × 星期矩阵，可视化执行模式。
     *
     * Query: days=14 (默认14天)
     */
    public function executionHeatmap()
    {
        $days = min(90, max(1, (int) Request::input('days', 14)));

        $sql = "
            SELECT
                EXTRACT(DOW FROM created_at)::int AS dow,
                EXTRACT(HOUR FROM created_at)::int AS hour,
                COUNT(*) AS executions,
                SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succeeded,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                ROUND(AVG(duration_ms)::numeric, 1) AS avg_duration_ms
            FROM kode_lowcode_workflow_executions
            WHERE created_at > NOW() - INTERVAL '{$days} days'
            GROUP BY dow, hour
            ORDER BY dow, hour
        ";
        $rows = Db::select($sql);

        // 构建 7x24 矩阵
        $matrix = [];
        $totalExec = 0;
        $totalSucceeded = 0;
        $totalFailed = 0;
        $hourlyTotals = array_fill(0, 24, 0);
        $dowTotals = array_fill(0, 7, 0);

        foreach ($rows as $r) {
            $dow = (int) $r['dow'];
            $hour = (int) $r['hour'];
            $exec = (int) $r['executions'];
            $succ = (int) $r['succeeded'];
            $fail = (int) $r['failed'];

            $matrix[$dow][$hour] = [
                'executions' => $exec,
                'succeeded' => $succ,
                'failed' => $fail,
                'avg_duration_ms' => (float) $r['avg_duration_ms'],
            ];
            $totalExec += $exec;
            $totalSucceeded += $succ;
            $totalFailed += $fail;
            $hourlyTotals[$hour] += $exec;
            $dowTotals[$dow] += $exec;
        }

        // 补全空单元格
        for ($dow = 0; $dow < 7; $dow++) {
            for ($hour = 0; $hour < 24; $hour++) {
                if (!isset($matrix[$dow][$hour])) {
                    $matrix[$dow][$hour] = ['executions' => 0, 'succeeded' => 0, 'failed' => 0, 'avg_duration_ms' => 0];
                }
            }
        }

        // 找出峰值时段
        $maxHour = 0;
        $maxHourCount = 0;
        $maxDow = 0;
        $maxDowCount = 0;
        $maxCell = 0;
        $maxCellDow = 0;
        $maxCellHour = 0;

        for ($dow = 0; $dow < 7; $dow++) {
            if ($dowTotals[$dow] > $maxDowCount) {
                $maxDowCount = $dowTotals[$dow];
                $maxDow = $dow;
            }
            for ($hour = 0; $hour < 24; $hour++) {
                $c = $matrix[$dow][$hour]['executions'];
                if ($c > $maxCell) {
                    $maxCell = $c;
                    $maxCellDow = $dow;
                    $maxCellHour = $hour;
                }
            }
        }
        for ($hour = 0; $hour < 24; $hour++) {
            if ($hourlyTotals[$hour] > $maxHourCount) {
                $maxHourCount = $hourlyTotals[$hour];
                $maxHour = $hour;
            }
        }

        return $this->ok([
            'days' => $days,
            'total_executions' => $totalExec,
            'success_rate' => $totalExec > 0 ? round($totalSucceeded / $totalExec * 100, 1) : 0,
            'failed' => $totalFailed,
            'matrix' => $matrix,
            'hourly_totals' => $hourlyTotals,
            'dow_totals' => $dowTotals,
            'peak' => [
                'hour' => $maxHour,
                'hour_count' => $maxHourCount,
                'dow' => $maxDow,
                'dow_count' => $maxDowCount,
                'cell' => $maxCell,
                'cell_dow' => $maxCellDow,
                'cell_hour' => $maxCellHour,
            ],
        ]);
    }

    /* ===================== Round 33: 自定义告警规则 ===================== */

    /**
     * GET /api/workflow/alert-rules
     * 列出所有告警规则。
     */
    public function alertRuleList()
    {
        $rows = Db::select(
            "SELECT id, name, workflow_name, rule_type, metric, threshold, comparison, enabled, notify_webhook, cooldown_min, last_triggered_at, trigger_count, created_at, updated_at
             FROM kode_lowcode_workflow_alert_rules
             ORDER BY enabled DESC, id DESC"
        );

        $rules = [];
        $enabledCount = 0;
        $triggeredCount = 0;
        foreach ($rows as $r) {
            $enabled = (int) $r['enabled'] === 1;
            $rules[] = [
                'id' => \app\support\HashId::enc((int) $r['id']),
                'name' => (string) $r['name'],
                'workflow_name' => (string) $r['workflow_name'],
                'rule_type' => (string) $r['rule_type'],
                'metric' => (string) $r['metric'],
                'threshold' => (float) $r['threshold'],
                'comparison' => (string) $r['comparison'],
                'enabled' => $enabled,
                'notify_webhook' => (string) $r['notify_webhook'],
                'cooldown_min' => (int) $r['cooldown_min'],
                'last_triggered_at' => $r['last_triggered_at'] ? (string) $r['last_triggered_at'] : null,
                'trigger_count' => (int) $r['trigger_count'],
                'created_at' => (string) $r['created_at'],
                'updated_at' => (string) $r['updated_at'],
            ];
            if ($enabled) $enabledCount++;
            if ((int) $r['trigger_count'] > 0) $triggeredCount++;
        }

        return $this->ok([
            'total_rules' => count($rules),
            'enabled_rules' => $enabledCount,
            'triggered_rules' => $triggeredCount,
            'rules' => $rules,
        ]);
    }

    /**
     * POST /api/workflow/alert-rules
     * 创建/更新告警规则。
     */
    public function alertRuleSave()
    {
        $input = (array) Request::input();
        // 入参 id 为对外 HashId 串（兼容历史数字入参）；缺省视为新建，非空但解码失败视为无效
        $idRaw = trim((string) ($input['id'] ?? ''));
        $id = 0;
        if ($idRaw !== '') {
            $id = ctype_digit($idRaw) ? (int) $idRaw : (\app\support\HashId::dec($idRaw) ?? 0);
            if ($id <= 0) {
                return $this->error('无效的规则 ID', 422);
            }
        }
        $name = trim((string) ($input['name'] ?? ''));
        $workflowName = trim((string) ($input['workflow_name'] ?? ''));
        $ruleType = trim((string) ($input['rule_type'] ?? ''));
        $metric = trim((string) ($input['metric'] ?? ''));
        $threshold = (float) ($input['threshold'] ?? 0);
        $comparison = trim((string) ($input['comparison'] ?? '<'));
        $enabled = isset($input['enabled']) ? (int) $input['enabled'] : 1;
        $notifyWebhook = trim((string) ($input['notify_webhook'] ?? ''));
        $cooldownMin = max(0, (int) ($input['cooldown_min'] ?? 15));

        if ($name === '') {
            return $this->error('规则名称不能为空', 422);
        }

        // workflow_name 是这条规则绑定的目标工作流。历史上未加校验，前端可以传空串创建，
        // 从而在 alert_rules 里积累孤儿数据（既无 workflow 关联、也不会被主表删除级联清理）。
        // 这里同时校验非空 + 目标存在，两处不满足各自返回 422/404，避免"指向不存在 workflow"的静默成功。
        if ($workflowName === '') {
            return $this->error('关联的工作流不能为空', 422);
        }
        $wfExists = Db::select(
            "SELECT id FROM kode_lowcode_workflows WHERE name = ?",
            [$workflowName]
        );
        if (empty($wfExists)) {
            return $this->error('关联的工作流不存在', 404);
        }

        // 验证 comparison
        if (!in_array($comparison, ['<', '>', '<=', '>=', '=='], true)) {
            return $this->error('无效的比较操作符', 422);
        }

        // 验证 metric
        $validMetrics = ['success_rate', 'p95_ms', 'execution_count', 'error_count', 'avg_duration_ms'];
        if (!in_array($metric, $validMetrics, true)) {
            return $this->error('无效的指标类型', 422);
        }

        // 自动推断 rule_type
        if ($ruleType === '') {
            $ruleType = 'custom_metric';
        }

        // 「现在」由库给（to_timestamp）：交一枚无时区串会让 created_at / updated_at
        // 整列偏 δ，而告警冷却期正是拿 updated_at / last_triggered_at 判「上次触发」的。
        $nowEpoch = time();
        $now = DbClock::instant($nowEpoch)['sql'];
        if ($id > 0) {
            $existing = Db::select(
                "SELECT id FROM kode_lowcode_workflow_alert_rules WHERE id = ?",
                [$id]
            );
            if (empty($existing)) {
                return $this->error('规则不存在', 404);
            }
            Db::update(
                "UPDATE kode_lowcode_workflow_alert_rules SET
                    name = ?, workflow_name = ?, rule_type = ?, metric = ?, threshold = ?, comparison = ?,
                    enabled = ?, notify_webhook = ?, cooldown_min = ?, updated_at = {$now}
                 WHERE id = ?",
                [$name, $workflowName, $ruleType, $metric, $threshold, $comparison,
                 $enabled, $notifyWebhook, $cooldownMin, $nowEpoch, $id]
            );
            return $this->ok(['id' => \app\support\HashId::enc($id), 'updated' => true]);
        }

        $inserted = Db::select(
            "INSERT INTO kode_lowcode_workflow_alert_rules (name, workflow_name, rule_type, metric, threshold, comparison, enabled, notify_webhook, cooldown_min, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, {$now}, {$now}) RETURNING id",
            [$name, $workflowName, $ruleType, $metric, $threshold, $comparison,
             $enabled, $notifyWebhook, $cooldownMin, $nowEpoch, $nowEpoch]
        );
        $newId = (int) ($inserted[0]['id'] ?? 0);
        return $this->ok(['id' => \app\support\HashId::enc($newId), 'created' => true]);
    }

    /**
     * DELETE /api/workflow/alert-rules/{id}
     * 删除告警规则。
     */
    public function alertRuleDelete()
    {
        // 入参 id 为对外 HashId 串，解码失败视为无效
        $id = $this->eid();
        if ($id === null) {
            return $this->error('无效的规则 ID', 422);
        }
        $rows = Db::select("SELECT id FROM kode_lowcode_workflow_alert_rules WHERE id = ?", [$id]);
        if (empty($rows)) {
            return $this->error('规则不存在', 404);
        }
        Db::delete("DELETE FROM kode_lowcode_workflow_alert_rules WHERE id = ?", [$id]);
        return $this->ok(['id' => \app\support\HashId::enc($id), 'deleted' => true]);
    }

    /**
     * POST /api/workflow/alert-rules/check
     * 执行告警规则检查，触发满足条件的告警。
     */
    public function alertRuleCheck()
    {
        $force = (bool) Request::input('force', false);
        $days = min(30, max(1, (int) Request::input('days', 1)));

        $rules = Db::select(
            "SELECT id, name, workflow_name, metric, threshold, comparison, notify_webhook, cooldown_min, last_triggered_at, trigger_count
             FROM kode_lowcode_workflow_alert_rules WHERE enabled = 1"
        );

        $results = [];
        $triggeredCount = 0;
        $skippedCount = 0;

        foreach ($rules as $rule) {
            $ruleId = (int) $rule['id'];
            $ruleName = (string) $rule['name'];
            $wfName = trim((string) $rule['workflow_name']);
            $metric = (string) $rule['metric'];
            $threshold = (float) $rule['threshold'];
            $comparison = (string) $rule['comparison'];
            $notifyWebhook = trim((string) $rule['notify_webhook']);
            $cooldownMin = (int) $rule['cooldown_min'];
            $lastTriggered = $rule['last_triggered_at'];
            $currentCount = (int) $rule['trigger_count'];

            // 冷却期检查
            if ($lastTriggered && !$force) {
                $lastTime = strtotime($lastTriggered);
                $elapsedMin = (time() - $lastTime) / 60;
                if ($cooldownMin > 0 && $elapsedMin < $cooldownMin) {
                    $results[] = [
                        'rule_id' => \app\support\HashId::enc($ruleId),
                        'rule_name' => $ruleName,
                        'triggered' => false,
                        'reason' => "冷却中（已过 {$elapsedMin} 分钟，需 {$cooldownMin} 分钟）",
                    ];
                    $skippedCount++;
                    continue;
                }
            }

            // 获取当前指标值
            $currentValue = 0;
            $metricLabel = $metric;
            $wfFilter = '';
            $params = [];
            if ($wfName !== '') {
                $wfFilter = ' WHERE workflow_name = ?';
                $params[] = $wfName;
            }

            switch ($metric) {
                case 'success_rate':
                    $sql = "SELECT COUNT(*) AS total,
                                   SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succ
                            FROM kode_lowcode_workflow_executions
                            WHERE created_at > NOW() - INTERVAL '{$days} days'{$wfFilter}";
                    $s = Db::select($sql, $params);
                    $row = $s[0] ?? [];
                    $total = (int) ($row['total'] ?? 0);
                    $succ = (int) ($row['succ'] ?? 0);
                    $currentValue = $total > 0 ? round($succ / $total * 100, 2) : 100;
                    break;

                case 'p95_ms':
                    $sql = "SELECT ROUND((PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms))::numeric, 1) AS p95
                            FROM kode_lowcode_workflow_executions
                            WHERE created_at > NOW() - INTERVAL '{$days} days'{$wfFilter}";
                    $s = Db::select($sql, $params);
                    $row = $s[0] ?? [];
                    $currentValue = (float) ($row['p95'] ?? 0);
                    break;

                case 'execution_count':
                    $sql = "SELECT COUNT(*) AS cnt
                            FROM kode_lowcode_workflow_executions
                            WHERE created_at > NOW() - INTERVAL '{$days} days'{$wfFilter}";
                    $s = Db::select($sql, $params);
                    $row = $s[0] ?? [];
                    $currentValue = (int) ($row['cnt'] ?? 0);
                    break;

                case 'error_count':
                    $sql = "SELECT SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS err_cnt
                            FROM kode_lowcode_workflow_executions
                            WHERE created_at > NOW() - INTERVAL '{$days} days'{$wfFilter}";
                    $s = Db::select($sql, $params);
                    $row = $s[0] ?? [];
                    $currentValue = (int) ($row['err_cnt'] ?? 0);
                    break;

                case 'avg_duration_ms':
                    $sql = "SELECT ROUND(AVG(duration_ms)::numeric, 1) AS avg_ms
                            FROM kode_lowcode_workflow_executions
                            WHERE created_at > NOW() - INTERVAL '{$days} days'{$wfFilter}";
                    $s = Db::select($sql, $params);
                    $row = $s[0] ?? [];
                    $currentValue = (float) ($row['avg_ms'] ?? 0);
                    break;
            }

            // 比较判断
            $triggered = false;
            switch ($comparison) {
                case '<': $triggered = $currentValue < $threshold; break;
                case '>': $triggered = $currentValue > $threshold; break;
                case '<=': $triggered = $currentValue <= $threshold; break;
                case '>=': $triggered = $currentValue >= $threshold; break;
                case '==': $triggered = $currentValue == $threshold; break;
            }

            $newCount = $currentCount;

            if ($triggered) {
                $triggeredCount++;
                // 「触发时刻」用库的 to_timestamp 落库（冷却期按这一列判「上次触发」）；
                // `last_triggered_at` 读回时是库会话时区的串，交给 strtotime 会重新按进程时区解释。
                $nowEpoch = time();
                $nowSql = DbClock::instant($nowEpoch)['sql'];

                // 更新规则触发状态
                // 读-改-写：`trigger_count` 从预加载快照 +1 再写回。并发 alert-check 下
                // 两个事务都读到同一 count、都写同一 count+1——一个增量丢失。
                // 用 SQL 原子自增替代 PHP 侧读-算-写。同 budgetAlertCheck / 熔断器追踪的加固。
                Db::update(
                    "UPDATE kode_lowcode_workflow_alert_rules SET last_triggered_at = {$nowSql}, trigger_count = trigger_count + 1, updated_at = {$nowSql} WHERE id = ?",
                    [$ruleId]
                );

                // 写入告警历史
                $message = sprintf(
                    '规则「%s」触发：%s %s %s（当前值 %s）',
                    $ruleName,
                    $wfName !== '' ? $wfName . ' ' : '',
                    $metricLabel,
                    $comparison,
                    $threshold,
                    $currentValue
                );

                // Webhook 通知
                $webhookStatus = 'none';
                $webhookHttpCode = 0;
                if ($notifyWebhook !== '') {
                    $payload = json_encode([
                        'type' => 'custom_alert',
                        'rule_name' => $ruleName,
                        'workflow' => $wfName !== '' ? $wfName : '(全局)',
                        'metric' => $metricLabel,
                        'comparison' => $comparison,
                        'threshold' => $threshold,
                        'current_value' => $currentValue,
                        'message' => $message,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ], JSON_UNESCAPED_UNICODE);

                    $alertGuarded = \app\support\RemoteFetch::safeOpts($notifyWebhook);
                    if ($alertGuarded['ok'] !== true) {
                        // 通知失败绝不影响告警落库：只记状态。原先这条连 SSL_VERIFYPEER 都是
                        // false（等于允许中间人改告警内容），现在和 webhook 走同一份闸门选项。
                        $webhookStatus = 'failed';
                    } else {
                        $ch = curl_init($notifyWebhook);
                        curl_setopt_array($ch, $alertGuarded['opts'] + [
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => $payload,
                            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => 10,
                        ]);
                        $resp = curl_exec($ch);
                        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        $webhookStatus = ($resp !== false && $httpCode >= 200 && $httpCode < 300) ? 'delivered' : 'failed';
                        $webhookHttpCode = $httpCode;
                    }
                }

                // 写入告警历史表
                Db::insert(
                    "INSERT INTO kode_lowcode_workflow_alerts (workflow_name, metric, level, current_value, limit_value, ratio, message, webhook_status, webhook_http_code, created_at)
                     VALUES (?, ?, 'custom', ?, ?, ?, ?, ?, ?, {$nowSql})",
                    [
                        $wfName !== '' ? $wfName : '(全局)',
                        $metric,
                        (int) round($currentValue),
                        (int) round($threshold),
                        $threshold > 0 ? round($currentValue / $threshold, 4) : 0,
                        $message,
                        $webhookStatus,
                        $webhookHttpCode,
                        $nowEpoch,
                    ]
                );
            } else {
                $skippedCount++;
            }

            $results[] = [
                'rule_id' => \app\support\HashId::enc($ruleId),
                'rule_name' => $ruleName,
                'triggered' => $triggered,
                'current_value' => $currentValue,
                'threshold' => $threshold,
                'comparison' => $comparison,
                'metric' => $metricLabel,
                'workflow' => $wfName !== '' ? $wfName : '(全局)',
                'webhook_status' => $triggered ? $webhookStatus : 'none',
            ];
        }

        return $this->ok([
            'checked' => count($rules),
            'triggered' => $triggeredCount,
            'skipped' => $skippedCount,
            'results' => $results,
            'days' => $days,
        ]);
    }

    /* ===================== Round 34: 执行关联分析 ===================== */

    /**
     * GET /api/workflow/correlation-analysis
     * 执行关联分析：检测多个工作流在相近时间窗口内同时失败的关联模式。
     *
     * 不同于 failurePatterns（按错误消息聚类），本方法关注时间上的共现：
     * 在滑动窗口内，多个工作流同时失败 → 可能共享外部依赖或基础设施问题。
     *
     * Query: days=7 (默认7天), window_min=5 (关联窗口分钟数，默认5)
     */
    public function correlationAnalysis()
    {
        $days = min(30, max(1, (int) Request::input('days', 7)));
        $windowMin = min(60, max(1, (int) Request::input('window_min', 5)));

        // 获取失败执行。`error` 是 TEXT 载荷列，滑动窗口的共现判据只在 PHP 有一份，
        // 所以这一族允许抽样 —— 代价是三件套必须说出来（见 ScanBudget 类注释）。
        // 方向必须取**最近**那头：旧写法 `ORDER BY created_at ASC` 配上任何上限都会把最新的一批
        // 切掉，于是面板上「最近的关联故障」其实是上周的（同 `performanceBottleneck` 修过的那一条）。
        // DESC 取回来后再在 PHP 里翻成升序：窗口算法靠 `$jTime - $baseTime > $windowSec` 断开，
        // 喂它降序会让那次 break 永远不触发、每个 base 都聚到末尾。
        $scan = $this->scanExecutions(
            'SELECT id, workflow_name, error, created_at, duration_ms, steps_retried',
            "WHERE created_at > NOW() - ?::interval AND status = 'failed'",
            ["{$days} days"],
            'ORDER BY created_at DESC'
        );
        $failures = $scan['rows'];
        usort($failures, fn($a, $b) => strtotime((string) $a['created_at']) <=> strtotime((string) $b['created_at']));

        if (count($failures) < 2) {
            return $this->ok(array_merge([
                'days' => $days, 'window_min' => $windowMin,
                'total_failures' => $scan['window_rows'],
                'correlation_events' => [], 'anomaly_groups' => [],
                'summary' => ['events' => 0, 'unique_workflows' => 0, 'unique_workflow_names' => []],
            ], $this->scanDeclared($scan)));
        }

        // 滑动窗口关联检测
        $correlationEvents = [];
        $n = count($failures);
        $windowSec = $windowMin * 60;

        for ($i = 0; $i < $n; $i++) {
            $baseTime = strtotime($failures[$i]['created_at']);
            $cluster = [];

            for ($j = $i; $j < $n; $j++) {
                $jTime = strtotime($failures[$j]['created_at']);
                if ($jTime - $baseTime > $windowSec) break;
                $cluster[] = $failures[$j];
            }

            if (count($cluster) >= 2) {
                // 检查是否有多个不同工作流
                $wfNames = array_unique(array_column($cluster, 'workflow_name'));
                if (count($wfNames) >= 2) {
                    // 提取共同特征
                    $errorPatterns = [];
                    $stepNames = [];
                    foreach ($cluster as $c) {
                        if (!empty($c['error'])) {
                            $errorPatterns[] = $c['error'];
                        }
                    }

                    $correlationEvents[] = [
                        'window_start' => $failures[$i]['created_at'],
                        'window_end' => $cluster[count($cluster) - 1]['created_at'],
                        'window_minutes' => $windowMin,
                        'failure_count' => count($cluster),
                        'unique_workflows' => count($wfNames),
                        'workflows' => array_values($wfNames),
                        'execution_ids' => array_map(fn($c) => \app\support\HashId::enc((int) $c['id']), $cluster),
                        'avg_duration_ms' => round(array_sum(array_map(fn($c) => (int) $c['duration_ms'], $cluster)) / count($cluster), 1),
                        'total_retried' => array_sum(array_map(fn($c) => (int) ($c['steps_retried'] ?? 0), $cluster)),
                        'error_preview' => array_slice($errorPatterns, 0, 3),
                    ];
                }
            }

            // 跳过已关联的窗口避免重叠
            if (count($cluster) > 1) {
                $i += count($cluster) - 1;
            }
        }

        // 关联事件按失败数降序
        usort($correlationEvents, fn($a, $b) => $b['failure_count'] <=> $a['failure_count']);

        // 异常分组：按共同工作流组合分组
        $anomalyGroups = [];
        foreach ($correlationEvents as $event) {
            $key = implode('+', $event['workflows']);
            if (!isset($anomalyGroups[$key])) {
                $anomalyGroups[$key] = [
                    'workflows' => $event['workflows'],
                    'event_count' => 0,
                    'total_failures' => 0,
                    'first_seen' => $event['window_start'],
                    'last_seen' => $event['window_end'],
                    'avg_failure_count' => 0,
                    '_counts' => [],
                ];
            }
            $anomalyGroups[$key]['event_count']++;
            $anomalyGroups[$key]['total_failures'] += $event['failure_count'];
            $anomalyGroups[$key]['last_seen'] = $event['window_end'];
            $anomalyGroups[$key]['_counts'][] = $event['failure_count'];
        }
        foreach ($anomalyGroups as &$ag) {
            $ag['avg_failure_count'] = round(array_sum($ag['_counts']) / count($ag['_counts']), 1);
            unset($ag['_counts']);
        }
        $anomalyGroups = array_values($anomalyGroups);
        usort($anomalyGroups, fn($a, $b) => $b['event_count'] <=> $a['event_count']);

        // 统计
        $uniqueWfs = [];
        foreach ($correlationEvents as $e) {
            foreach ($e['workflows'] as $w) {
                $uniqueWfs[$w] = true;
            }
        }

        return $this->ok(array_merge([
            'days' => $days,
            'window_min' => $windowMin,
            'total_failures' => $scan['window_rows'],
            'correlation_events' => array_slice($correlationEvents, 0, 50),
            'anomaly_groups' => array_slice($anomalyGroups, 0, 20),
            'summary' => [
                'events' => count($correlationEvents),
                'unique_workflows' => count($uniqueWfs),
                'unique_workflow_names' => array_keys($uniqueWfs),
            ],
        ], $this->scanDeclared($scan)));
    }

    /* ===================== Round 34: 异常检测 ===================== */

    /**
     * GET /api/workflow/anomaly-detection
     * 执行指标异常检测：基于 Z-Score 检测执行量、延迟、失败率的统计异常。
     *
     * 每日统计执行数/成功率/P95耗时，计算均值和标准差，
     * Z-Score > 2 视为异常（spike/drop），标记异常类型。
     *
     * Query: days=30 (默认30天), workflow=xxx (可选过滤)
     */
    public function anomalyDetection()
    {
        $days = min(90, max(7, (int) Request::input('days', 30)));
        $workflowFilter = trim((string) Request::input('workflow', ''));

        $where = '';
        $params = [];
        if ($workflowFilter !== '') {
            $where = ' AND workflow_name = ?';
            $params[] = $workflowFilter;
        }

        // 按天聚合
        $sql = "
            SELECT
                date_trunc('day', created_at)::text AS day_label,
                COUNT(*) AS executions,
                SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succeeded,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                ROUND(AVG(duration_ms)::numeric, 1) AS avg_duration_ms,
                ROUND((PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms))::numeric, 1) AS p95_ms
            FROM kode_lowcode_workflow_executions
            WHERE created_at > NOW() - INTERVAL '{$days} days' {$where}
            GROUP BY date_trunc('day', created_at)
            ORDER BY day_label
        ";
        $rows = Db::select($sql, $params);

        if (count($rows) < 7) {
            return $this->ok([
                'days' => $days, 'workflow' => $workflowFilter,
                'insufficient_data' => true,
                'daily_count' => count($rows),
                'required_min' => 7,
                'daily_stats' => [], 'anomalies' => [],
                'summary' => ['total_anomalies' => 0, 'critical' => 0, 'warning' => 0, 'metrics_checked' => 0],
            ]);
        }

        // 提取指标序列
        $dailyData = [];
        $execCounts = [];
        $failCounts = [];
        $avgDurations = [];
        $p95Durations = [];

        foreach ($rows as $r) {
            $exec = (int) $r['executions'];
            $succ = (int) $r['succeeded'];
            $fail = (int) $r['failed'];
            $avgDur = (float) $r['avg_duration_ms'];
            $p95 = (float) $r['p95_ms'];

            $failRate = $exec > 0 ? round($fail / $exec * 100, 1) : 0;
            $successRate = $exec > 0 ? round($succ / $exec * 100, 2) : 100;

            $dailyData[] = [
                'day' => (string) $r['day_label'],
                'executions' => $exec,
                'succeeded' => $succ,
                'failed' => $fail,
                'failure_rate' => $failRate,
                'success_rate' => $successRate,
                'avg_duration_ms' => $avgDur,
                'p95_ms' => $p95,
            ];
            $execCounts[] = $exec;
            $failCounts[] = $fail;
            $avgDurations[] = $avgDur;
            $p95Durations[] = $p95;
        }

        // 计算统计异常检测辅助函数
        $calcStats = function (array $values): array {
            $n = count($values);
            if ($n === 0) return ['mean' => 0, 'std' => 0];
            $mean = array_sum($values) / $n;
            $variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values)) / max(1, $n - 1);
            return ['mean' => $mean, 'std' => sqrt($variance)];
        };

        $zThreshold = 2.0;
        $anomalies = [];

        // 对每个指标序列检测异常
        $metrics = [
            'executions' => ['label' => '执行量', 'values' => $execCounts, 'z' => null, 'stats' => null],
            'failure_rate' => ['label' => '失败率', 'values' => array_map(fn($d) => $d['failure_rate'], $dailyData), 'z' => null, 'stats' => null],
            'p95_ms' => ['label' => 'P95延迟', 'values' => $p95Durations, 'z' => null, 'stats' => null],
        ];

        foreach ($metrics as $metricKey => &$m) {
            $m['stats'] = $calcStats($m['values']);
            $mean = $m['stats']['mean'];
            $std = $m['stats']['std'];

            // 找出异常点
            if ($std > 0) {
                foreach ($m['values'] as $idx => $val) {
                    $z = ($val - $mean) / $std;
                    if (abs($z) > $zThreshold) {
                        $anomalies[] = [
                            'day' => $dailyData[$idx]['day'],
                            'metric' => $metricKey,
                            'metric_label' => $m['label'],
                            'value' => $val,
                            'mean' => round($mean, 2),
                            'std' => round($std, 2),
                            'z_score' => round($z, 2),
                            'type' => $z > 0 ? 'spike' : 'drop',
                            'severity' => abs($z) > 3 ? 'critical' : 'warning',
                        ];
                    }
                }
            }
        }
        unset($m);

        // 按严重度排序
        usort($anomalies, function ($a, $b) {
            $sevOrder = ['critical' => 0, 'warning' => 1];
            if ($sevOrder[$a['severity']] !== $sevOrder[$b['severity']]) {
                return $sevOrder[$a['severity']] <=> $sevOrder[$b['severity']];
            }
            return abs($b['z_score']) <=> abs($a['z_score']);
        });

        return $this->ok([
            'days' => $days,
            'workflow' => $workflowFilter,
            'insufficient_data' => false,
            'daily_count' => count($rows),
            'daily_stats' => $dailyData,
            'anomalies' => array_slice($anomalies, 0, 30),
            'summary' => [
                'total_anomalies' => count($anomalies),
                'critical' => count(array_filter($anomalies, fn($a) => $a['severity'] === 'critical')),
                'warning' => count(array_filter($anomalies, fn($a) => $a['severity'] === 'warning')),
                'metrics_checked' => count($metrics),
            ],
        ]);
    }

    /* ===================== Round 34: 执行步骤时间线 ===================== */

    /**
     * GET /api/workflow/executions/{id}/timeline
     * 执行步骤时间线：Gantt 风格可视化步骤执行时间线。
     *
     * 从 step_results_json 提取每步的开始时间和耗时，
     * 计算相对偏移（毫秒），检测并行步骤组，返回时间线数据。
     */
    public function executionTimeline()
    {
        // 入参 id 为对外 HashId 串，解码失败视为无效
        $id = $this->eid();
        if ($id === null) {
            return $this->error('无效的执行 ID', 422);
        }

        $rows = Db::select(
            "SELECT id, workflow_name, status, duration_ms, step_results_json, created_at
             FROM kode_lowcode_workflow_executions WHERE id = ?",
            [$id]
        );
        if (empty($rows)) {
            return $this->error('执行不存在', 404);
        }

        $exec = $rows[0];
        $stepResults = json_decode($exec['step_results_json'] ?? '{}', true) ?? [];

        if (empty($stepResults)) {
            return $this->ok([
                'id' => \app\support\HashId::enc($id), 'workflow' => $exec['workflow_name'],
                'status' => $exec['status'], 'duration_ms' => (int) $exec['duration_ms'],
                'steps' => [], 'total_steps' => 0, 'max_depth' => 0,
                'parallel_groups' => 0, 'sequential_steps' => 0,
            ]);
        }

        // 走树只有一份：`stepResultTree()` 交出「这一轮真跑过的每一格」，这里按它的 pre-order
        // 摊成 Gantt 时间线的逐行（旧写法只递归 `steps`，loop 的某一轮、switch 命中的那一支、
        // 以及失败步骤的恢复步骤里的那此子结果**结构上到不了时间线** —— 于是图上「这一条
        // 执行只有 3 步」，而执行记录里其实跑了 6 步。
        $steps = [];
        $maxDepth = 0;
        $stepIndex = 0;

        foreach ($this->stepResultTree($stepResults, '', (string) $exec['workflow_name']) as $leaf) {
            $result = $leaf['result'];
            $duration = (int) ($result['_duration_ms'] ?? $result['duration_ms'] ?? 0);
            $error = !empty($result['error']);
            $success = ($result['success'] ?? true) === true || !empty($result['success']);
            $hasError = $error;
            // 类型只问 stepTypeOf()：这一处原先在行数组之后再补四分支推断（`http_code`/`matched`/
            // `message`/`steps`），与执行轨迹、依赖健康、瓶颈榜各有一份同句话。空串是那几枚
            // 「不是执行器结果」的格子（容器、合成的轮次超时行），时间线这一列要有个字，
            // 所以落「unknown」的落点只有这一处。
            $type = self::stepTypeOf($result);

            // 计算开始时间偏移（假设步骤按顺序执行，累加前面的步骤耗时）
            $stepIndex++;
            $steps[] = [
                'order' => $stepIndex,
                'step_name' => self::stepDisplayName($leaf),
                'full_path' => (string) $leaf['path'],
                'depth' => (int) $leaf['depth'],
                'duration_ms' => $duration,
                'status' => $hasError ? 'failed' : ($success ? 'succeeded' : 'unknown'),
                'error' => (string) ($result['error'] ?? ''),
                'workflow' => (string) $leaf['parent'],
                'type' => $type === '' ? 'unknown' : $type,
                'is_sub_workflow' => isset($result['steps']) && is_array($result['steps']),
            ];

            $depth = (int) $leaf['depth'];
            if ($depth > $maxDepth) $maxDepth = $depth;
        }

        // 计算累积时间偏移（Gantt 图用）
        $totalDuration = (int) $exec['duration_ms'];
        $cumulativeMs = 0;
        $parallelGroups = 0;
        $sequentialSteps = 0;

        foreach ($steps as &$s) {
            $s['start_ms'] = $cumulativeMs;
            $s['end_ms'] = $cumulativeMs + $s['duration_ms'];
            $s['progress_pct'] = $totalDuration > 0 ? round($cumulativeMs / $totalDuration * 100, 2) : 0;
            $cumulativeMs += $s['duration_ms'];
            if ($s['duration_ms'] > 0) {
                $sequentialSteps++;
            }
        }
        unset($s);

        // 检测并行步骤组：depth 相同且 end_ms 重叠的步骤
        $byDepth = [];
        foreach ($steps as $s) {
            $byDepth[$s['depth']][] = $s;
        }

        $parallelDetected = [];
        foreach ($byDepth as $depth => $depthSteps) {
            if (count($depthSteps) < 2) continue;
            // 检查是否有重叠（同一深度但 start_ms 不同且时间重叠）
            $overlapping = [];
            for ($i = 0; $i < count($depthSteps) - 1; $i++) {
                $a = $depthSteps[$i];
                $b = $depthSteps[$i + 1];
                // 如果 a.end > b.start 且 a.start != b.start，说明有并行
                if ($a['end_ms'] > $b['start_ms'] && $a['start_ms'] != $b['start_ms']) {
                    if (!in_array($a['order'], $overlapping)) $overlapping[] = $a['order'];
                    if (!in_array($b['order'], $overlapping)) $overlapping[] = $b['order'];
                }
            }
            if (!empty($overlapping)) {
                $parallelDetected[] = [
                    'depth' => $depth,
                    'step_orders' => $overlapping,
                    'step_names' => array_map(fn($o) => $steps[$o - 1]['step_name'], $overlapping),
                ];
                $parallelGroups++;
            }
        }

        return $this->ok([
            'id' => \app\support\HashId::enc($id),
            'workflow' => $exec['workflow_name'],
            'status' => $exec['status'],
            'total_duration_ms' => $totalDuration,
            'created_at' => $exec['created_at'],
            'steps' => $steps,
            'total_steps' => count($steps),
            'max_depth' => $maxDepth,
            'sequential_steps' => $sequentialSteps,
            'parallel_groups' => $parallelGroups,
            'parallel_details' => $parallelDetected,
        ]);
    }

    /* ===================== Round 35: 执行路径挖掘 ===================== */

    /**
     * GET /api/workflow/path-mining
     * 跨执行的执行路径模式分析：识别主路径、异常分支、死代码步骤。
     *
     * 参数：days(1-30, 默认7), workflow(可选), limit(默认20)
     *
     * 路径签名 = 按执行顺序的步骤名序列（用 | 连接，含深度前缀）。
     * 死代码 = 工作流 steps_json 定义中存在但执行历史中从未到达的步骤。
     */
    public function pathMining()
    {
        $days = (int) Request::input('days', 7);
        $days = max(1, min(30, $days));
        $workflow = Request::input('workflow', null);
        $limit = (int) Request::input('limit', 20);
        $limit = max(1, min(100, $limit));

        $where = ['created_at >= NOW() - ?::interval'];
        $bindings = ["{$days} days"];
        if ($workflow !== null && $workflow !== '') {
            $where[] = 'workflow_name = ?';
            $bindings[] = (string) $workflow;
        }

        $scan = $this->scanExecutions(
            'SELECT id, workflow_name, status, duration_ms, step_results_json, created_at',
            'WHERE ' . implode(' AND ', $where),
            $bindings,
            'ORDER BY created_at DESC'
        );
        $rows = $scan['rows'];

        if ($scan['scanned_rows'] === 0) {
            return $this->ok(array_merge([
                'total_executions' => 0,
                'unique_paths' => 0,
                'main_path' => null,
                'branches' => [],
                'dead_steps' => [],
                'path_signatures' => [],
                'days' => $days,
            ], $this->scanDeclared($scan)));
        }

        // 收集每个执行的路径签名
        $pathStats = []; // signature => ['count', 'failed_count', 'total_duration_ms', 'workflows']
        $workflowsSeen = [];

        // 递归提取路径签名（扁平化）
        // 走法只有 stepResultTree() 一枚：旧写法只递归 `steps`，loop 的 `iterations[].results`
        // 与 switch 的 `results` 整片不在签名里，于是「走了分支」和「没走分支」两种执行
        // 压成同一个签名 —— 主路径的占比因此把两条业务算成一条，而异常分支榜（占比<5% 且失败率>0）
        // 结构上永远看不到只在分支里失败的那一趟。
        // array_unique 保留旧形状「每枚步骤在签名里只占一格」：循环跑 100 轮不该让签名长出 100 段。
        $extractPathSignature = function (array $stepResults): array {
            $paths = [];
            foreach ($this->stepResultTree($stepResults) as $leaf) {
                $paths[$leaf['path']] = true;
            }
            return array_keys($paths);
        };

        // 收集定义中所有步骤（用于死代码检测）
        $definedSteps = []; // workflow => set of step names
        $executedStepNames = []; // workflow => set，由下面同一份解码结果派生

        foreach ($rows as $row) {
            $wfName = (string) $row['workflow_name'];
            $workflowsSeen[$wfName] = true;
            $stepResults = json_decode($row['step_results_json'] ?? '{}', true) ?? [];
            $executedStepNames[$wfName] = $this->collectExecutedStepNames($stepResults)
                + ($executedStepNames[$wfName] ?? []);
            $sig = $extractPathSignature($stepResults);
            $sigStr = implode(' > ', $sig);
            if ($sigStr === '') $sigStr = '(empty)';

            if (!isset($pathStats[$sigStr])) {
                $pathStats[$sigStr] = [
                    'count' => 0,
                    'failed_count' => 0,
                    'total_duration_ms' => 0,
                    'workflows' => [],
                ];
            }
            $pathStats[$sigStr]['count']++;
            if ($row['status'] !== 'succeeded') {
                $pathStats[$sigStr]['failed_count']++;
            }
            $pathStats[$sigStr]['total_duration_ms'] += (int) $row['duration_ms'];
            if (!in_array($wfName, $pathStats[$sigStr]['workflows'])) {
                $pathStats[$sigStr]['workflows'] = array_merge(
                    $pathStats[$sigStr]['workflows'], [$wfName]
                );
            }
        }

        // 加载工作流定义，提取 steps_json 中所有步骤（递归）
        $workflowDefs = Db::select(
            "SELECT name, steps_json FROM kode_lowcode_workflows
             WHERE status = 1"
        );
        foreach ($workflowDefs as $def) {
            $wfName = (string) $def['name'];
            if (!empty($workflow) && $workflow !== '' && $wfName !== $workflow) continue;
            $stepsDef = json_decode($def['steps_json'] ?? '[]', true) ?? [];
            $definedSteps[$wfName] = $this->collectDefinedStepNames($stepsDef);
        }

        // 检测死代码：定义中存在但执行中从未出现
        $deadSteps = [];
        foreach ($definedSteps as $wfName => $defined) {
            if (!isset($executedStepNames[$wfName])) {
                // 工作流从未执行过，所有步骤都是"未执行"而非死代码
                continue;
            }
            $executed = $executedStepNames[$wfName];
            foreach ($defined as $stepName => $stepDef) {
                if (!isset($executed[$stepName])) {
                    $deadSteps[] = [
                        'workflow' => $wfName,
                        'step_name' => $stepName,
                        'type' => (string) ($stepDef['type'] ?? 'unknown'),
                    ];
                }
            }
        }

        // 占比的分母是**参与聚合的那些行**：分布数的是抽样，用窗口全集当分母会把
        // 「主路径占 80%」读成「主路径占 0.08%」。窗口口径由下面的三件套单独声明。
        $sampledExecutions = count($rows);

        // 计算占比、排序
        $sortedPaths = [];
        foreach ($pathStats as $sigStr => $stats) {
            $pct = round($stats['count'] / $sampledExecutions * 100, 2);
            $avgMs = $stats['count'] > 0
                ? (int) round($stats['total_duration_ms'] / $stats['count'])
                : 0;
            $sortedPaths[] = [
                'signature' => $sigStr,
                'count' => $stats['count'],
                'pct' => $pct,
                'failed_count' => $stats['failed_count'],
                'failure_rate' => $stats['count'] > 0
                    ? round($stats['failed_count'] / $stats['count'] * 100, 2)
                    : 0.0,
                'avg_duration_ms' => $avgMs,
                'workflows' => $stats['workflows'],
            ];
        }
        usort($sortedPaths, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        $mainPath = $sortedPaths[0] ?? null;

        // 识别异常分支：占比<5% 且失败率>0
        $branches = [];
        foreach ($sortedPaths as $p) {
            if ($p === ($mainPath ?? null)) continue;
            if ($p['pct'] < 5.0 && $p['failed_count'] > 0) {
                $branches[] = $p;
            }
        }

        return $this->ok(array_merge([
            'total_executions' => $scan['window_rows'],
            'unique_paths' => count($sortedPaths),
            'main_path' => $mainPath,
            'branches' => array_slice($branches, 0, $limit),
            'dead_steps' => array_slice($deadSteps, 0, $limit),
            'path_signatures' => array_slice($sortedPaths, 0, $limit),
            'workflows_analyzed' => array_keys($workflowsSeen),
            'days' => $days,
        ], $this->scanDeclared($scan)));
    }

    /**
     * 收集 steps_json 定义中的所有步骤名（含子步骤）。
     * 返回 [step_path => step_def] 映射。
     *
     * 键与下面的 `collectExecutedStepNames()` 同形（父.子 点号拼接），两边都由**同一份**走法给出
     * 路径才能对账；旧写法只递归顶层的 `steps` 键（真实定义里子步骤住在
     * `config.steps` / `config.cases[].steps` / `config.default_steps`），于是 loop/switch 里的
     * 子步骤**从不在定义清单里出现** —— 它们执行过也被数成「执行过」，两边都不出现在死代码榜上，
     * 这一屏因此说不出「分支里那一步从没跑到过」。
     *
     * @return array<string, array<string, mixed>>
     */
    private function collectDefinedStepNames(array $stepsDef): array
    {
        $result = [];

        foreach ($this->stepTree($stepsDef) as $leaf) {
            if ($leaf['name'] === '') continue;
            $result[$leaf['path']] = $leaf['step'];
        }

        return $result;
    }

    /**
     * 递归收集 step_results_json 中执行过的步骤，交回 `path => true` 的集合。
     *
     * 键必须与上面的 `collectDefinedStepNames()` 同形（父.子 点号拼接）—— 死代码榜是两边的差集，
     * 两边各走一份路就各差一批。走法只有 `stepResultTree()` 一枚：旧写法只递归 `steps` 那一格，
     * 于是 loop 每轮的 `iterations[].results` 与 switch 的 `results` 整片不在集合里，
     * #203 把子步骤收进定义清单之后，**每一枚躲在分支里的步骤都被列成「从未到达」**。
     *
     * 累加器与 `foreach` 的循环变量**不能同名**：这里曾两边都叫 `$result`，
     * 于是每轮迭代把累加结果覆盖成当前步骤自己的数组，返回值只剩最后一个步骤 ——
     * 除它之外的所有已执行步骤都被下面的死代码榜列成「从未到达」。
     */
    private function collectExecutedStepNames(array $stepResults): array
    {
        $set = [];
        foreach ($this->stepResultTree($stepResults) as $leaf) {
            $set[$leaf['path']] = true;
        }
        return $set;
    }

    /* ===================== Round 35: 输入参数画像 ===================== */

    /**
     * GET /api/workflow/input-profile
     * 按工作流聚合输入参数分布与异常值检测。
     *
     * 参数：days(1-30, 默认7), workflow(可选), limit(默认20)
     */
    public function inputProfile()
    {
        $days = (int) Request::input('days', 7);
        $days = max(1, min(30, $days));
        $workflow = Request::input('workflow', null);
        $limit = (int) Request::input('limit', 20);
        $limit = max(1, min(100, $limit));

        $where = ['created_at >= NOW() - ?::interval'];
        $bindings = ["{$days} days"];
        if ($workflow !== null && $workflow !== '') {
            $where[] = 'workflow_name = ?';
            $bindings[] = (string) $workflow;
        }

        // 参数类型分布的判据只在 PHP 有一份（initParamStats/getTypeLabel），所以按行抽样是允许的；
        // 但 `input_json` 与 `step_results_json` 同族（整列 TEXT 进 PHP 再 json_decode），
        // 无预算的扫描在 days=30 的窗口里就是把 worker 装满。见 ScanBudget 的类注释。
        $scan = $this->scanExecutions(
            'SELECT id, workflow_name, input_json',
            'WHERE ' . implode(' AND ', $where),
            $bindings,
            'ORDER BY created_at DESC'
        );
        $rows = $scan['rows'];

        if ($scan['scanned_rows'] === 0) {
            return $this->ok(array_merge([
                'total_executions' => 0,
                'workflows' => 0,
                'parameters' => [],
                'global_summary' => [],
                'days' => $days,
            ], $this->scanDeclared($scan)));
        }

        // 按工作流聚合参数统计
        $wfParams = []; // workflow => [param_key => stats]

        foreach ($rows as $row) {
            $wfName = (string) $row['workflow_name'];
            $params = json_decode($row['input_json'] ?? '{}', true) ?? [];
            if (!is_array($params)) continue;

            if (!isset($wfParams[$wfName])) {
                $wfParams[$wfName] = [];
            }

            foreach ($params as $key => $value) {
                if (!isset($wfParams[$wfName][$key])) {
                    $wfParams[$wfName][$key] = $this->initParamStats();
                }
                $this->updateParamStats($wfParams[$wfName][$key], $value);
            }
        }

        // 最终化每个参数的统计
        foreach ($wfParams as &$wfParamsRef) {
            foreach ($wfParamsRef as &$statsRef) {
                $this->finalizeParamStats($statsRef);
            }
        }
        unset($wfParamsRef, $statsRef);

        // 汇总
        $parameters = [];
        $globalSummary = [
            'total_parameters' => 0,
            'total_anomalies' => 0,
            'numeric_parameters' => 0,
            'string_parameters' => 0,
            'array_parameters' => 0,
            'boolean_parameters' => 0,
            'null_parameters' => 0,
        ];

        foreach ($wfParams as $wfName => $paramKeys) {
            foreach ($paramKeys as $key => $stats) {
                $stats['workflow'] = $wfName;
                $stats['param_key'] = $key;
                $parameters[] = $stats;
                $globalSummary['total_parameters']++;
                $globalSummary['total_anomalies'] += (int) $stats['anomaly_count'];

                $typeCounts = $stats['type_counts'];
                if (isset($typeCounts['null'])) {
                    $globalSummary['null_parameters'] += $typeCounts['null'];
                }
                if (isset($typeCounts['numeric'])) {
                    $globalSummary['numeric_parameters']++;
                }
                if (isset($typeCounts['string'])) {
                    $globalSummary['string_parameters']++;
                }
                if (isset($typeCounts['array'])) {
                    $globalSummary['array_parameters']++;
                }
                if (isset($typeCounts['boolean'])) {
                    $globalSummary['boolean_parameters']++;
                }
            }
        }

        // 按出现次数降序排序
        usort($parameters, function ($a, $b) {
            return $b['occurrence'] <=> $a['occurrence'];
        });

        return $this->ok(array_merge([
            'total_executions' => $scan['window_rows'],
            'workflows' => count($wfParams),
            'parameters' => array_slice($parameters, 0, $limit),
            'global_summary' => $globalSummary,
            'days' => $days,
        ], $this->scanDeclared($scan)));
    }

    /**
     * 初始化参数统计结构（含内部聚合字段）。
     */
    private function initParamStats(): array
    {
        return [
            'occurrence' => 0,
            'null_count' => 0,
            'zero_count' => 0,
            'type_counts' => ['numeric' => 0, 'string' => 0, 'array' => 0, 'object' => 0, 'boolean' => 0, 'null' => 0],
            'numeric_values' => [],
            'string_values' => [],
            'unique_count' => 0,
            'avg_string_length' => 0,
            'top_values' => [],
            'min' => null,
            'max' => null,
            'avg' => null,
            'median' => null,
            'stddev' => null,
            'anomaly_count' => 0,
            '__unique_map' => [],
        ];
    }

    /**
     * 更新参数统计（单值，累加到内部字段）。
     */
    private function updateParamStats(array &$stats, $value): void
    {
        $stats['occurrence']++;

        if ($value === null) {
            $stats['null_count']++;
            $stats['type_counts']['null']++;
            $stats['__unique_map']['__null__'] = ($stats['__unique_map']['__null__'] ?? 0) + 1;
            return;
        }

        if (is_bool($value)) {
            $stats['type_counts']['boolean']++;
            $valStr = $value ? 'true' : 'false';
            $stats['__unique_map'][$valStr] = ($stats['__unique_map'][$valStr] ?? 0) + 1;
            return;
        }

        if (is_array($value) || is_object($value)) {
            $typeKey = is_array($value) ? 'array' : 'object';
            $stats['type_counts'][$typeKey]++;
            $valStr = '[' . count($value) . ' items]';
            $stats['__unique_map'][$valStr] = ($stats['__unique_map'][$valStr] ?? 0) + 1;
            return;
        }

        if (is_string($value)) {
            $stats['type_counts']['string']++;
            $stats['string_values'][] = $value;
            $stats['__unique_map'][$value] = ($stats['__unique_map'][$value] ?? 0) + 1;
            return;
        }

        // 数值型（int/float）
        $stats['type_counts']['numeric']++;
        $num = (float) $value;
        if ($num == 0) $stats['zero_count']++;
        $stats['numeric_values'][] = $num;
        $valStr = (string) $value;
        $stats['__unique_map'][$valStr] = ($stats['__unique_map'][$valStr] ?? 0) + 1;
    }

    /**
     * 最终化参数统计：计算数值聚合 + 字符串统计 + 异常检测，清理内部字段。
     */
    private function finalizeParamStats(array &$stats): void
    {
        // 数值型聚合
        if (!empty($stats['numeric_values'])) {
            $vals = $stats['numeric_values'];
            sort($vals);
            $count = count($vals);
            $stats['min'] = (float) $vals[0];
            $stats['max'] = (float) $vals[$count - 1];
            $sum = array_sum($vals);
            $stats['avg'] = round($sum / $count, 4);
            $stats['median'] = $count % 2 === 0
                ? round(($vals[$count / 2 - 1] + $vals[$count / 2]) / 2, 4)
                : (float) $vals[(int) floor($count / 2)];

            if ($count > 1) {
                $sumSqDiff = 0.0;
                foreach ($vals as $v) {
                    $diff = $v - $stats['avg'];
                    $sumSqDiff += $diff * $diff;
                }
                $stats['stddev'] = round(sqrt($sumSqDiff / $count), 4);
            } else {
                $stats['stddev'] = 0.0;
            }

            if ($stats['stddev'] > 0) {
                $lower = $stats['avg'] - 3 * $stats['stddev'];
                $upper = $stats['avg'] + 3 * $stats['stddev'];
                $anomalies = 0;
                foreach ($vals as $v) {
                    if ($v < $lower || $v > $upper) $anomalies++;
                }
                $stats['anomaly_count'] = $anomalies;
            }
        }

        // 字符串长度
        if (!empty($stats['string_values'])) {
            $lengths = array_map('mb_strlen', $stats['string_values']);
            $stats['avg_string_length'] = (int) round(array_sum($lengths) / count($lengths));
        }

        // 唯一值统计
        $map = $stats['__unique_map'] ?? [];
        $stats['unique_count'] = count($map);
        arsort($map);
        $stats['top_values'] = array_slice($map, 0, 5, true);

        // 清理内部字段
        unset($stats['numeric_values'], $stats['string_values'], $stats['__unique_map']);
    }

    /* ===================== Round 35: 容量规划预测 ===================== */

    /**
     * GET /api/workflow/capacity-forecast
     * 基于历史负载的线性回归预测未来 horizon 天。
     *
     * 参数：days(7-90, 默认30), horizon(1-14, 默认7)
     *
     * 指标：execution_count, failure_count, avg_duration_ms, p95_duration_ms
     */
    public function capacityForecast()
    {
        $days = (int) Request::input('days', 30);
        $days = max(7, min(90, $days));
        $horizon = (int) Request::input('horizon', 7);
        $horizon = max(1, min(14, $horizon));

        // 按日聚合
        $daily = Db::select(
            "SELECT
                DATE_TRUNC('day', created_at)::date AS day,
                COUNT(*) AS execution_count,
                COUNT(*) FILTER (WHERE status != 'succeeded') AS failure_count,
                AVG(duration_ms) AS avg_duration_ms,
                PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms) AS p95_duration_ms
             FROM kode_lowcode_workflow_executions
             WHERE created_at >= NOW() - ?::interval
             GROUP BY DATE_TRUNC('day', created_at)::date
             ORDER BY day ASC",
            ["{$days} days"]
        );

        if (count($daily) < 7) {
            return $this->ok([
                'insufficient_data' => true,
                'days_available' => count($daily),
                'days_required' => 7,
                'metrics' => [],
                'capacity_warnings' => [],
                'days' => $days,
            ]);
        }

        $metricNames = ['execution_count', 'failure_count', 'avg_duration_ms', 'p95_duration_ms'];
        $metrics = [];

        foreach ($metricNames as $metric) {
            $historical = [];
            foreach ($daily as $d) {
                $historical[] = [
                    'day' => (string) $d['day'],
                    'value' => (float) ($d[$metric] ?? 0),
                ];
            }

            // 线性回归拟合
            $regression = $this->linearRegression($historical);

            // 预测未来
            $forecast = [];
            $lastDay = (string) end($historical)['day'];
            $lastTs = strtotime($lastDay);
            $forecastValues = [];
            for ($i = 1; $i <= $horizon; $i++) {
                $dayTs = $lastTs + $i * 86400;
                $dayStr = date('Y-m-d', $dayTs);
                $predictedValue = $regression['intercept'] + $regression['slope'] * $i;
                $predictedValue = max(0, round($predictedValue, 2));
                $forecast[] = [
                    'day' => $dayStr,
                    'predicted' => $predictedValue,
                ];
                $forecastValues[] = $predictedValue;
            }

            // 趋势方向
            $last7Avg = 0.0;
            $prev7Avg = 0.0;
            $last7count = min(7, count($historical));
            $prev7count = count($historical) - $last7count;
            $last7Sum = 0.0;
            $prev7Sum = 0.0;
            for ($i = count($historical) - $last7count; $i < count($historical); $i++) {
                $last7Sum += $historical[$i]['value'];
            }
            $last7Avg = $last7count > 0 ? $last7Sum / $last7count : 0.0;
            if ($prev7count > 0) {
                for ($i = 0; $i < $prev7count; $i++) {
                    $prev7Sum += $historical[$i]['value'];
                }
                $prev7Avg = $prev7Sum / $prev7count;
            }

            $direction = 'stable';
            if ($regression['slope'] > 0.001) {
                $direction = 'increasing';
            } elseif ($regression['slope'] < -0.001) {
                $direction = 'decreasing';
            }

            // 强度：|slope| * last_day / avg
            $avgAll = array_sum(array_column($historical, 'value')) / count($historical);
            $strength = $avgAll > 0
                ? round(abs($regression['slope']) * count($historical) / $avgAll * 100, 1)
                : 0.0;

            $metrics[] = [
                'metric' => $metric,
                'label' => $this->metricLabel($metric),
                'historical_values' => $historical,
                'forecast_values' => $forecast,
                'trend' => [
                    'direction' => $direction,
                    'slope' => round($regression['slope'], 4),
                    'intercept' => round($regression['intercept'], 4),
                    'r_squared' => round($regression['r_squared'], 4),
                    'strength_pct' => $strength,
                ],
                'last_7d_avg' => round($last7Avg, 2),
                'prev_period_avg' => round($prev7Avg, 2),
            ];
        }

        // 容量告警
        $warnings = [];
        foreach ($metrics as $m) {
            if ($m['metric'] === 'p95_duration_ms' && $m['trend']['direction'] === 'increasing' && $m['trend']['strength_pct'] > 10) {
                $warnings[] = [
                    'metric' => 'p95_duration_ms',
                    'level' => 'warning',
                    'message' => 'P95 延迟增长趋势显著（+{' . $m['trend']['strength_pct'] . '}%），可能存在性能退化',
                ];
            }
            if ($m['metric'] === 'execution_count' && $m['trend']['direction'] === 'increasing' && $m['trend']['strength_pct'] > 20) {
                $warnings[] = [
                    'metric' => 'execution_count',
                    'level' => 'notice',
                    'message' => '执行量增长趋势显著（+{' . $m['trend']['strength_pct'] . '}%），建议关注容量',
                ];
            }
            if ($m['metric'] === 'failure_count' && $m['trend']['direction'] === 'increasing' && $m['trend']['strength_pct'] > 15) {
                $warnings[] = [
                    'metric' => 'failure_count',
                    'level' => 'critical',
                    'message' => '失败数增长趋势显著（+{' . $m['trend']['strength_pct'] . '}%），建议排查根因',
                ];
            }
        }

        return $this->ok([
            'days_analyzed' => count($daily),
            'horizon_days' => $horizon,
            'metrics' => $metrics,
            'capacity_warnings' => $warnings,
            'days' => $days,
        ]);
    }

    /**
     * 线性回归最小二乘法拟合。
     * 返回 ['slope', 'intercept', 'r_squared']。
     */
    private function linearRegression(array $points): array
    {
        $n = count($points);
        if ($n < 2) {
            return ['slope' => 0.0, 'intercept' => ($points[0]['value'] ?? 0), 'r_squared' => 1.0];
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumX2 = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = (float) $i;
            $y = (float) $points[$i]['value'];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        $denom = $n * $sumX2 - $sumX * $sumX;
        $slope = $denom != 0.0 ? ($n * $sumXY - $sumX * $sumY) / $denom : 0.0;
        $intercept = ($sumY - $slope * $sumX) / $n;

        // R²
        $meanY = $sumY / $n;
        $ssTot = 0.0;
        $ssRes = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $y = (float) $points[$i]['value'];
            $yHat = $intercept + $slope * $i;
            $ssTot += ($y - $meanY) * ($y - $meanY);
            $ssRes += ($y - $yHat) * ($y - $yHat);
        }
        $rSquared = $ssTot != 0.0 ? max(0.0, min(1.0, 1.0 - $ssRes / $ssTot)) : 1.0;

        return ['slope' => $slope, 'intercept' => $intercept, 'r_squared' => $rSquared];
    }

    /**
     * 指标中文名映射。
     */
    private function metricLabel(string $metric): string
    {
        $map = [
            'execution_count' => '执行次数',
            'failure_count' => '失败次数',
            'avg_duration_ms' => '平均耗时',
            'p95_duration_ms' => 'P95 耗时',
        ];
        return $map[$metric] ?? $metric;
    }

    /* ===================== Round 36: 数据血缘追踪 ===================== */

    /**
     * GET /api/workflow/data-lineage
     * 跨工作流的数据流动追踪：分析数据从输入到输出的完整链路。
     *
     * 参数：days(1-30, 默认7), workflow(可选), limit(默认20)
     *
     * 核心逻辑：
     *  1. 从 steps_json 定义中解析变量引用（{{step_name.field}} / {{params.field}}）
     *  2. 从 step_results_json 中提取实际数据流
     *  3. 构建数据流图（节点=步骤，边=数据传递关系）
     *  4. 检测孤立步骤（无输入引用也无输出去向）
     */
    public function dataLineage()
    {
        $days = (int) Request::input('days', 7);
        $days = max(1, min(30, $days));
        $workflow = Request::input('workflow', null);
        $limit = (int) Request::input('limit', 20);
        $limit = max(1, min(100, $limit));

        $where = ['created_at >= NOW() - ?::interval'];
        $bindings = ["{$days} days"];
        if ($workflow !== null && $workflow !== '') {
            $where[] = 'workflow_name = ?';
            $bindings[] = (string) $workflow;
        }

        $scan = $this->scanExecutions(
            'SELECT id, workflow_name, status, duration_ms, input_json, step_results_json, created_at',
            'WHERE ' . implode(' AND ', $where),
            $bindings,
            'ORDER BY created_at DESC',
            $limit * 10
        );
        $rows = $scan['rows'];

        if ($scan['scanned_rows'] === 0) {
            return $this->ok(array_merge([
                'total_executions' => 0,
                'lineage_graph' => ['nodes' => [], 'edges' => []],
                'data_flows' => [],
                'workflow_summary' => [],
                'days' => $days,
            ], $this->scanDeclared($scan)));
        }

        // 加载工作流定义（steps_json）
        $wfDefs = Db::select(
            "SELECT name, steps_json FROM kode_lowcode_workflows WHERE status = 1"
        );
        $defsMap = [];
        foreach ($wfDefs as $def) {
            if (!empty($workflow) && $workflow !== '' && $def['name'] !== $workflow) continue;
            $defsMap[(string) $def['name']] = json_decode($def['steps_json'] ?? '[]', true) ?? [];
        }

        // 从定义中提取变量引用
        $references = []; // workflow => [step_name => ['inputs' => [], 'outputs' => []]]
        foreach ($defsMap as $wfName => $stepsDef) {
            $references[$wfName] = $this->extractLineageFromDefinition($stepsDef, $wfName, '');
        }

        // 从执行中提取实际数据流
        $dataFlows = [];
        $executedSteps = []; // workflow => set of step names

        foreach ($rows as $row) {
            $wfName = (string) $row['workflow_name'];
            $inputJson = json_decode($row['input_json'] ?? '{}', true) ?? [];
            $stepResults = json_decode($row['step_results_json'] ?? '{}', true) ?? [];

            if (!is_array($stepResults)) continue;

            // 标记执行过的步骤
            if (!isset($executedSteps[$wfName])) {
                $executedSteps[$wfName] = [];
            }
            $this->markExecutedSteps($stepResults, $executedSteps[$wfName]);

            // 提取输入参数流向
            if (!empty($inputJson)) {
                $inputKeys = array_keys($inputJson);
                // 输入参数是数据流的源点
                foreach ($inputKeys as $key) {
                    $dataFlows[] = [
                        'source' => 'params.' . $key,
                        'target' => '',
                        'field' => $key,
                        'value_type' => gettype($inputJson[$key]),
                        'exec_id' => \app\support\HashId::enc((int) $row['id']),
                        'workflow' => $wfName,
                    ];
                }
            }

            // 提取步骤间数据流
            $this->extractExecutionFlows($stepResults, $wfName, (int) $row['id'], $dataFlows);
        }

        // 构建节点
        $nodes = [];
        $allSteps = [];
        foreach ($references as $wfName => $stepRefs) {
            foreach ($stepRefs as $stepName => $refs) {
                $nodeKey = $wfName . '::' . $stepName;
                if (!in_array($nodeKey, $allSteps)) {
                    $allSteps[] = $nodeKey;
                    $nodes[] = [
                        'id' => $nodeKey,
                        'workflow' => $wfName,
                        'step_name' => $stepName,
                        'inputs_count' => count($refs['inputs']),
                        'outputs_count' => count($refs['outputs']),
                        'executed' => isset($executedSteps[$wfName][$stepName]),
                    ];
                } else {
                    $idx = array_search($nodeKey, $allSteps);
                    if (isset($executedSteps[$wfName][$stepName])) {
                        $nodes[$idx]['executed'] = true;
                    }
                }
            }
        }

        // 构建边（从定义的引用中）
        $edges = [];
        foreach ($references as $wfName => $stepRefs) {
            foreach ($stepRefs as $stepName => $refs) {
                foreach ($refs['inputs'] as $ref) {
                    $source = $ref;
                    $target = $wfName . '::' . $stepName;
                    $edges[] = [
                        'source' => $source,
                        'target' => $target,
                        'field' => $ref,
                    ];
                }
            }
        }

        // 去重边
        $uniqueEdges = [];
        $seenEdges = [];
        foreach ($edges as $edge) {
            $key = $edge['source'] . '->' . $edge['target'] . '.' . $edge['field'];
            if (!in_array($key, $seenEdges)) {
                $seenEdges[] = $key;
                $uniqueEdges[] = $edge;
            }
        }

        // 检测孤立步骤
        $orphanSteps = [];
        foreach ($nodes as $node) {
            if (!$node['executed'] && $node['inputs_count'] === 0 && $node['outputs_count'] === 0) {
                $orphanSteps[] = $node;
            }
        }

        // 汇总
        $workflowSummary = [];
        foreach ($references as $wfName => $stepRefs) {
            $totalInputs = 0;
            $totalOutputs = 0;
            foreach ($stepRefs as $stepName => $refs) {
                $totalInputs += count($refs['inputs']);
                $totalOutputs += count($refs['outputs']);
            }
            $workflowSummary[] = [
                'workflow' => $wfName,
                'steps_defined' => count($stepRefs),
                'steps_executed' => count($executedSteps[$wfName] ?? []),
                'input_refs' => $totalInputs,
                'output_refs' => $totalOutputs,
            ];
        }

        return $this->ok(array_merge([
            'total_executions' => $scan['window_rows'],
            'lineage_graph' => [
                'nodes' => array_slice($nodes, 0, $limit * 2),
                'edges' => array_slice($uniqueEdges, 0, $limit * 3),
            ],
            'data_flows' => array_slice($dataFlows, 0, $limit * 5),
            'workflow_summary' => $workflowSummary,
            'orphan_steps' => array_slice($orphanSteps, 0, $limit),
            'days' => $days,
        ], $this->scanDeclared($scan)));
    }

    /**
     * 从 steps_json 定义中提取数据血缘引用。
     *
     * 每个节点扫的是**自己那一份 config**（`stepTree()` 已把子步骤桶摘出去，所以父步骤不会把
     * 子步骤的引用又数一遍）。以前这一具数的是步骤顶层的七枚键（`url`/`payload`/`message` 那一串），
     * 而它们全都住在 `config` 里 ⇒ `inputs` 恒为空、整张血缘图只有节点没有边；
     * 再加上只递归顶层 `steps`，loop/switch 里的步骤在这张图上压根不存在。
     *
     * 节点键用步骤名原文（不带路径前缀）—— 调用方建节点、标「执行过」都按这一枚名字对账。
     *
     * @param array<int, mixed> $stepsDef
     * @return array<string, array{inputs: list<string>, outputs: list<string>}>
     */
    private function extractLineageFromDefinition(array $stepsDef, string $workflowName, string $prefix): array
    {
        $result = [];

        foreach ($this->stepTree($stepsDef, $prefix) as $leaf) {
            if ($leaf['name'] === '') continue;

            $inputs = [];
            $this->collectLineageRefs($leaf['config'], $inputs);

            $result[$leaf['name']] = [
                'inputs' => array_values(array_unique($inputs)),
                'outputs' => [$workflowName . '::' . $leaf['name']],
            ];
        }

        return $result;
    }

    /**
     * 把一坨配置里出现的 `{{params.x}}` / `{{step.x}}` 引用收进 `$inputs`。
     *
     * 只认这两类前缀（加上孤零零一枚 `params`）：它们是定义里唯二能指向**别人产出的数据**的写法，
     * 其余形如 `{{ ... }}` 的占位（格式化指令、字面量）当成边会把图画满假箭头。
     *
     * @param list<string> $inputs
     */
    private function collectLineageRefs(mixed $val, array &$inputs): void
    {
        $strings = [];
        $this->configStrings($val, $strings);

        foreach ($strings as $str) {
            if (!preg_match_all('/\{\{([^}]+)\}\}/', $str, $matches)) {
                continue;
            }

            foreach ($matches[1] as $ref) {
                $ref = trim($ref);
                if ($ref === 'params' || str_starts_with($ref, 'params.') || str_starts_with($ref, 'step.')) {
                    $inputs[] = $ref;
                }
            }
        }
    }

    /**
     * 把一枚步骤配置摊平成其中的字符串（数组/对象值递归到叶子）。
     *
     * 「配置里哪些位置可能藏着模板」这件事只在这里判一次：血缘与必填参数两处扫描读的是同一批
     * 落点，各写一遍的 flatten 迟早只改对一个（同上一条的 `stepTree()` 之于深度）。
     *
     * @param list<string> $out
     */
    private function configStrings(mixed $val, array &$out): void
    {
        if (is_array($val)) {
            foreach ($val as $v) {
                $this->configStrings($v, $out);
            }
            return;
        }

        if (is_string($val)) {
            $out[] = $val;
        }
    }

    /**
     * 标记执行过的步骤（按**裸步骤名**登记，调用方那侧 `extractLineageFromDefinition()` 的
     * 节点键也是裸名，两边必须同一形状）。
     *
     * 旧写法只递归顶层那一格 `steps`，于是 loop/switch/on_fail 里真的跑过的那一步说不出
     * 「它跑过」—— 这一处喂的是数据血缘图里 `executed` 那一格，画的是「这个节点的产出被人用过吗」。
     */
    private function markExecutedSteps(array $stepResults, array &$executed): void
    {
        foreach ($this->stepResultTree($stepResults) as $leaf) {
            if ($leaf['name'] === '') continue;
            $executed[$leaf['name']] = true;
        }
    }

    /**
     * 从执行结果中提取数据流。
     *
     * `source` 用**点号路径**（与 `pathMining`/死代码榜同一套键）；旧写法只递归顶层 `steps`，
     * loop/switch 里那一步的产出从来没进过这张流图。
     * 归属工作流恒是**这一行执行记录的** `$workflow`（与节点键 `wfName::step` 同一口径），
     * 不按 sub_workflow 结果里那一格切换 —— 这一列与定义侧的 `references` 对账，切换就接不上。
     */
    private function extractExecutionFlows(array $stepResults, string $workflow, int $execId, array &$flows): void
    {
        foreach ($this->stepResultTree($stepResults, '', $workflow) as $leaf) {
            $result = $leaf['result'];
            if (!isset($result['data']) || !is_array($result['data'])) continue;

            $source = $workflow . '::' . $leaf['path'];
            foreach (array_keys($result['data']) as $field) {
                $flows[] = [
                    'source' => $source,
                    'target' => '',
                    'field' => (string) $field,
                    'value_type' => gettype($result['data'][$field]),
                    'exec_id' => \app\support\HashId::enc($execId),
                    'workflow' => $workflow,
                ];
            }
        }
    }

    /* ===================== Round 36: 执行模式聚类 ===================== */

    /**
     * GET /api/workflow/execution-clustering
     * 按执行特征聚类相似执行。
     *
     * 参数：days(1-30, 默认7), workflow(可选), limit(默认10), cluster_by(path/input/duration, 默认path)
     */
    public function executionClustering()
    {
        $days = (int) Request::input('days', 7);
        $days = max(1, min(30, $days));
        $workflow = Request::input('workflow', null);
        $limit = (int) Request::input('limit', 10);
        $limit = max(1, min(50, $limit));
        $clusterBy = (string) Request::input('cluster_by', 'path');
        if (!in_array($clusterBy, ['path', 'input', 'duration'], true)) {
            $clusterBy = 'path';
        }

        $where = ['created_at >= NOW() - ?::interval'];
        $bindings = ["{$days} days"];
        if ($workflow !== null && $workflow !== '') {
            $where[] = 'workflow_name = ?';
            $bindings[] = (string) $workflow;
        }

        $scan = $this->scanExecutions(
            'SELECT id, workflow_name, status, duration_ms, input_json, step_results_json, created_at',
            'WHERE ' . implode(' AND ', $where),
            $bindings,
            'ORDER BY created_at DESC'
        );
        $rows = $scan['rows'];

        if ($scan['scanned_rows'] === 0) {
            return $this->ok(array_merge([
                'total_executions' => 0,
                'cluster_count' => 0,
                'clusters' => [],
                'cluster_summary' => [],
                'days' => $days,
            ], $this->scanDeclared($scan)));
        }

        // 计算每个执行的特征
        $execFeatures = [];
        // 聚类键 = 「这一步在这条路里出现过」。按 path 去重是刻意的：loop 跑 100 轮不该让
        // 两条本来就同形的执行聚出两个簇（旧写法只递归顶层 `steps`，看不见 loop/switch 里的子步骤，
        // 于是「循环里做了什么」不同的执行全被归进同一簇）。
        $extractPathSig = function (array $stepResults): string {
            $seen = [];
            foreach ($this->stepResultTree($stepResults) as $leaf) {
                $seen[$leaf['path']] = true;
            }
            return implode('|', array_keys($seen));
        };

        foreach ($rows as $row) {
            $stepResults = json_decode($row['step_results_json'] ?? '{}', true) ?? [];
            $inputJson = json_decode($row['input_json'] ?? '{}', true) ?? [];
            $duration = (int) $row['duration_ms'];

            $features = [];
            $features['id'] = \app\support\HashId::enc((int) $row['id']);
            $features['workflow'] = (string) $row['workflow_name'];
            $features['status'] = (string) $row['status'];
            $features['duration_ms'] = $duration;
            $features['created_at'] = (string) $row['created_at'];
            $features['path_signature'] = $extractPathSig(is_array($stepResults) ? $stepResults : []);

            // 输入哈希
            $inputHash = '';
            if (!empty($inputJson)) {
                $inputStr = json_encode($inputJson, JSON_UNESCAPED_UNICODE);
                $inputHash = md5($inputStr);
            }
            $features['input_hash'] = $inputHash;
            $features['input_keys'] = is_array($inputJson) ? count($inputJson) : 0;

            $execFeatures[] = $features;
        }

        // 聚类
        $clusters = [];
        $clusterCount = 0;

        if ($clusterBy === 'path') {
            // 按路径签名聚类
            $byPath = [];
            foreach ($execFeatures as $f) {
                $sig = $f['path_signature'] !== '' ? $f['path_signature'] : '(empty)';
                if (!isset($byPath[$sig])) {
                    $byPath[$sig] = [];
                }
                $byPath[$sig][] = $f;
            }
            foreach ($byPath as $sig => $execs) {
                $clusterCount++;
                $durations = array_column($execs, 'duration_ms');
                sort($durations);
                $clusters[] = [
                    'cluster_id' => $clusterCount,
                    'feature' => substr($sig, 0, 100),
                    'size' => count($execs),
                    'avg_duration_ms' => (int) round(array_sum($durations) / count($durations)),
                    'min_duration_ms' => (int) $durations[0],
                    'max_duration_ms' => (int) $durations[count($durations) - 1],
                    'success_rate' => round(count(array_filter($execs, fn($e) => $e['status'] === 'succeeded')) / count($execs) * 100, 1),
                    'executions' => array_slice($execs, 0, 5),
                    'representative' => $execs[0],
                ];
            }
        } elseif ($clusterBy === 'input') {
            // 按输入哈希聚类
            $byInput = [];
            foreach ($execFeatures as $f) {
                $hash = $f['input_hash'] !== '' ? $f['input_hash'] : '(no_input)';
                if (!isset($byInput[$hash])) {
                    $byInput[$hash] = [];
                }
                $byInput[$hash][] = $f;
            }
            foreach ($byInput as $hash => $execs) {
                $clusterCount++;
                $durations = array_column($execs, 'duration_ms');
                sort($durations);
                $clusters[] = [
                    'cluster_id' => $clusterCount,
                    'feature' => 'input_hash=' . substr($hash, 0, 16),
                    'size' => count($execs),
                    'avg_duration_ms' => (int) round(array_sum($durations) / count($durations)),
                    'min_duration_ms' => (int) $durations[0],
                    'max_duration_ms' => (int) $durations[count($durations) - 1],
                    'success_rate' => round(count(array_filter($execs, fn($e) => $e['status'] === 'succeeded')) / count($execs) * 100, 1),
                    'executions' => array_slice($execs, 0, 5),
                    'representative' => $execs[0],
                ];
            }
        } else {
            // 按耗时聚类（±20% 阈值）
            $sortedByDuration = $execFeatures;
            usort($sortedByDuration, function ($a, $b) {
                return $a['duration_ms'] <=> $b['duration_ms'];
            });

            $currentCluster = [];
            $currentBase = null;
            foreach ($sortedByDuration as $f) {
                if ($currentBase === null) {
                    $currentBase = $f['duration_ms'];
                }
                if ($currentBase > 0 && abs($f['duration_ms'] - $currentBase) / $currentBase <= 0.2) {
                    $currentCluster[] = $f;
                } else {
                    if (!empty($currentCluster)) {
                        $clusterCount++;
                        $durations = array_column($currentCluster, 'duration_ms');
                        $clusters[] = [
                            'cluster_id' => $clusterCount,
                            'feature' => 'duration≈' . $currentBase . 'ms',
                            'size' => count($currentCluster),
                            'avg_duration_ms' => (int) round(array_sum($durations) / count($durations)),
                            'min_duration_ms' => (int) min($durations),
                            'max_duration_ms' => (int) max($durations),
                            'success_rate' => round(count(array_filter($currentCluster, fn($e) => $e['status'] === 'succeeded')) / count($currentCluster) * 100, 1),
                            'executions' => array_slice($currentCluster, 0, 5),
                            'representative' => $currentCluster[0],
                        ];
                    }
                    $currentCluster = [$f];
                    $currentBase = $f['duration_ms'];
                }
            }
            if (!empty($currentCluster)) {
                $clusterCount++;
                $durations = array_column($currentCluster, 'duration_ms');
                $clusters[] = [
                    'cluster_id' => $clusterCount,
                    'feature' => 'duration≈' . $currentBase . 'ms',
                    'size' => count($currentCluster),
                    'avg_duration_ms' => (int) round(array_sum($durations) / count($durations)),
                    'min_duration_ms' => (int) min($durations),
                    'max_duration_ms' => (int) max($durations),
                    'success_rate' => round(count(array_filter($currentCluster, fn($e) => $e['status'] === 'succeeded')) / count($currentCluster) * 100, 1),
                    'executions' => array_slice($currentCluster, 0, 5),
                    'representative' => $currentCluster[0],
                ];
            }
        }

        // 按大小降序排序
        usort($clusters, function ($a, $b) {
            return $b['size'] <=> $a['size'];
        });

        $sizes = array_column($clusters, 'size');

        return $this->ok(array_merge([
            'total_executions' => $scan['window_rows'],
            'cluster_count' => $clusterCount,
            'cluster_by' => $clusterBy,
            'clusters' => array_slice($clusters, 0, $limit),
            'cluster_summary' => [
                'total_clusters' => $clusterCount,
                'largest_cluster' => max($sizes),
                'smallest_cluster' => min($sizes),
                'avg_cluster_size' => round(array_sum($sizes) / count($sizes), 1),
                'singleton_clusters' => count(array_filter($sizes, fn($s) => $s === 1)),
            ],
            'days' => $days,
        ], $this->scanDeclared($scan)));
    }

    /* ===================== Round 36: 工作流健康趋势 ===================== */

    /**
     * GET /api/workflow/health-trend
     * 工作流健康度时间序列分析。
     *
     * 参数：days(7-90, 默认30), workflow(可选), granularity(day/week/month, 默认day)
     */
    public function healthTrend()
    {
        $days = (int) Request::input('days', 30);
        $days = max(7, min(90, $days));
        $workflow = Request::input('workflow', null);
        $granularity = (string) Request::input('granularity', 'day');
        if (!in_array($granularity, ['day', 'week', 'month'], true)) {
            $granularity = 'day';
        }

        $where = ['created_at >= NOW() - ?::interval'];
        $bindings = ["{$days} days"];
        if ($workflow !== null && $workflow !== '') {
            $where[] = 'workflow_name = ?';
            $bindings[] = (string) $workflow;
        }

        $truncExpr = match ($granularity) {
            'week' => "DATE_TRUNC('week', created_at)",
            'month' => "DATE_TRUNC('month', created_at)",
            default => "DATE_TRUNC('day', created_at)",
        };

        $sql = "
            SELECT workflow_name, {$truncExpr}::date AS period,
                COUNT(*) AS executions,
                COUNT(*) FILTER (WHERE status = 'succeeded') AS succeeded,
                COUNT(*) FILTER (WHERE status != 'succeeded') AS failed,
                AVG(duration_ms) AS avg_duration_ms,
                PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms) AS p95_duration_ms,
                PERCENTILE_CONT(0.50) WITHIN GROUP (ORDER BY duration_ms) AS p50_duration_ms
            FROM kode_lowcode_workflow_executions
            WHERE " . implode(' AND ', $where) . "
            GROUP BY workflow_name, {$truncExpr}
            ORDER BY workflow_name, period ASC";

        $rows = Db::select($sql, $bindings);

        if (empty($rows)) {
            return $this->ok([
                'days_analyzed' => $days,
                'granularity' => $granularity,
                'workflows' => 0,
                'time_series' => [],
                'workflow_summary' => [],
                'overall_trend' => [],
            ]);
        }

        // 按工作流分组
        $byWorkflow = [];
        foreach ($rows as $row) {
            $wfName = (string) $row['workflow_name'];
            if (!isset($byWorkflow[$wfName])) {
                $byWorkflow[$wfName] = [];
            }
            $byWorkflow[$wfName][] = [
                'period' => (string) $row['period'],
                'executions' => (int) $row['executions'],
                'succeeded' => (int) $row['succeeded'],
                'failed' => (int) $row['failed'],
                'success_rate' => $row['executions'] > 0
                    ? round($row['succeeded'] / $row['executions'] * 100, 1)
                    : 0.0,
                'avg_duration_ms' => round((float) $row['avg_duration_ms'], 1),
                'p95_duration_ms' => round((float) $row['p95_duration_ms'], 1),
                'p50_duration_ms' => round((float) $row['p50_duration_ms'], 1),
            ];
        }

        // 计算每个工作流的健康分和时间序列
        $workflowSummary = [];
        $allPeriods = [];

        foreach ($byWorkflow as $wfName => $periods) {
            $periods[] = null; // sentinel for sorting
            usort($periods, function ($a, $b) {
                if ($a === null) return 1;
                if ($b === null) return -1;
                return strcmp($a['period'], $b['period']);
            });
            array_pop($periods);

            if (empty($periods)) continue;

            // 计算健康分（加权）
            $scores = [];
            foreach ($periods as $p) {
                // 成功率(40) + 延迟(25) + 失败率(20) + 执行量(15)
                $score = $this->calculateHealthScoreInline($p);
                $scores[] = $score;
            }

            $currentScore = end($scores);
            $prevScore = count($scores) > 1 ? end(array_slice($scores, 0, -1)) : $currentScore;
            $trendDirection = $currentScore > $prevScore + 2 ? 'improving' : ($currentScore < $prevScore - 2 ? 'degrading' : 'stable');

            $workflowSummary[] = [
                'workflow' => $wfName,
                'periods' => count($periods),
                'current_health' => (int) round($currentScore),
                'previous_health' => (int) round($prevScore),
                'trend' => $trendDirection,
                'change' => round($currentScore - $prevScore, 1),
                'avg_executions' => round(array_sum(array_column($periods, 'executions')) / count($periods), 1),
                'avg_success_rate' => round(array_sum(array_column($periods, 'success_rate')) / count($periods), 1),
                'avg_p95_ms' => round(array_sum(array_column($periods, 'p95_duration_ms')) / count($periods), 1),
                'total_failures' => array_sum(array_column($periods, 'failed')),
            ];
        }

        // 构建时间序列
        $timeSeries = [];
        foreach ($byWorkflow as $wfName => $periods) {
            $timeSeries[] = [
                'workflow' => $wfName,
                'periods' => $periods,
            ];
        }

        // 整体趋势
        $totalExecutions = 0;
        $totalFailed = 0;
        $healthScores = [];
        foreach ($byWorkflow as $wfName => $periods) {
            $totalExecutions += array_sum(array_column($periods, 'executions'));
            $totalFailed += array_sum(array_column($periods, 'failed'));
            foreach ($periods as $p) {
                $healthScores[] = $this->calculateHealthScoreInline($p);
            }
        }

        $avgHealth = $healthScores ? round(array_sum($healthScores) / count($healthScores), 1) : 0;
        $overallTrend = [
            'avg_health' => $avgHealth,
            'total_executions' => $totalExecutions,
            'total_failures' => $totalFailed,
            'overall_success_rate' => $totalExecutions > 0 ? round(($totalExecutions - $totalFailed) / $totalExecutions * 100, 1) : 0,
            'direction' => $avgHealth >= 80 ? 'healthy' : ($avgHealth >= 60 ? 'moderate' : 'critical'),
        ];

        return $this->ok([
            'days_analyzed' => $days,
            'granularity' => $granularity,
            'workflows' => count($byWorkflow),
            'time_series' => array_slice($timeSeries, 0, 20),
            'workflow_summary' => $workflowSummary,
            'overall_trend' => $overallTrend,
        ]);
    }

    /**
     * 内联计算健康分（0-100）。
     */
    private function calculateHealthScoreInline(array $period): float
    {
        // 成功率(40分)
        $successScore = $period['success_rate'] * 0.4;
        // 延迟(25分)：p95 < 100ms=满分, > 2000ms=0分
        $p95 = $period['p95_duration_ms'];
        $latencyScore = max(0, min(1.0, (2000 - $p95) / 1900)) * 25;
        // 失败率(20分)：0失败=满分, >10%=0分
        $failRate = $period['executions'] > 0 ? $period['failed'] / $period['executions'] * 100 : 0;
        $failureScore = max(0, 1.0 - $failRate / 10) * 20;
        // 执行量(15分)：>10=满分
        $volumeScore = min(1.0, $period['executions'] / 10) * 15;

        return $successScore + $latencyScore + $failureScore + $volumeScore;
    }

    /* ===================== Round 37: 输入参数验证 ===================== */

    /**
     * GET /api/workflow/input-validation
     * 从 steps_json 定义中提取参数约束，验证实际输入是否符合。
     *
     * 参数：days(1-30, 默认7), workflow(可选), limit(默认20)
     */
    public function inputValidation()
    {
        $days = (int) Request::input('days', 7);
        $days = max(1, min(30, $days));
        $workflow = Request::input('workflow', null);
        $limit = (int) Request::input('limit', 20);
        $limit = max(1, min(100, $limit));

        // 加载工作流定义
        $wfDefs = Db::select("SELECT name, steps_json FROM kode_lowcode_workflows WHERE status = 1");
        $defsMap = [];
        foreach ($wfDefs as $def) {
            if (!empty($workflow) && $workflow !== '' && $def['name'] !== $workflow) continue;
            $defsMap[(string) $def['name']] = json_decode($def['steps_json'] ?? '[]', true) ?? [];
        }

        // 从定义中提取必填参数
        $requiredParams = []; // workflow => [param_name => constraint]
        foreach ($defsMap as $wfName => $stepsDef) {
            $params = $this->extractRequiredParamsFromDef($stepsDef, []);
            $requiredParams[$wfName] = $params;
        }

        // 查询实际执行输入
        $where = ['created_at >= NOW() - ?::interval'];
        $bindings = ["{$days} days"];
        if ($workflow !== null && $workflow !== '') {
            $where[] = 'workflow_name = ?';
            $bindings[] = (string) $workflow;
        }

        $scan = $this->scanExecutions(
            'SELECT id, workflow_name, input_json, status',
            'WHERE ' . implode(' AND ', $where),
            $bindings,
            'ORDER BY created_at DESC'
        );
        $rows = $scan['rows'];

        if ($scan['scanned_rows'] === 0) {
            return $this->ok(array_merge([
                'total_executions' => 0,
                'workflows_checked' => 0,
                'violations' => [],
                'global_summary' => [],
                'days' => $days,
            ], $this->scanDeclared($scan)));
        }

        // 验证每个执行
        $violations = [];
        $wfStats = [];

        foreach ($rows as $row) {
            $wfName = (string) $row['workflow_name'];
            $inputJson = json_decode($row['input_json'] ?? '{}', true) ?? [];

            if (!isset($requiredParams[$wfName])) continue;
            if (!isset($wfStats[$wfName])) {
                $wfStats[$wfName] = ['executions' => 0, 'violations' => 0];
            }
            $wfStats[$wfName]['executions']++;

            $required = $requiredParams[$wfName];
            foreach ($required as $paramName => $constraint) {
                if (!array_key_exists($paramName, $inputJson)) {
                    $violations[] = [
                        'workflow' => $wfName,
                        'exec_id' => \app\support\HashId::enc((int) $row['id']),
                        'param' => $paramName,
                        'violation_type' => 'missing',
                        'message' => "缺少必填参数 '{$paramName}'",
                        'constraint' => $constraint,
                    ];
                    $wfStats[$wfName]['violations']++;
                } elseif ($inputJson[$paramName] === null || $inputJson[$paramName] === '') {
                    $violations[] = [
                        'workflow' => $wfName,
                        'exec_id' => \app\support\HashId::enc((int) $row['id']),
                        'param' => $paramName,
                        'violation_type' => 'empty',
                        'message' => "参数 '{$paramName}' 为空值",
                        'constraint' => $constraint,
                    ];
                    $wfStats[$wfName]['violations']++;
                } elseif (!empty($constraint['type']) && $constraint['type'] !== 'any') {
                    $actualType = $this->getTypeLabel($inputJson[$paramName]);
                    if ($actualType !== $constraint['type']) {
                        $violations[] = [
                            'workflow' => $wfName,
                            'exec_id' => \app\support\HashId::enc((int) $row['id']),
                            'param' => $paramName,
                            'violation_type' => 'type_mismatch',
                            'message' => "参数 '{$paramName}' 类型不匹配: 期望 {$constraint['type']}，实际 {$actualType}",
                            'constraint' => $constraint,
                        ];
                        $wfStats[$wfName]['violations']++;
                    }
                }
            }
        }

        // 汇总
        $globalSummary = [
            'total_executions' => count($rows),
            'workflows_checked' => count($wfStats),
            'total_violations' => count($violations),
            'missing_count' => count(array_filter($violations, fn($v) => $v['violation_type'] === 'missing')),
            'empty_count' => count(array_filter($violations, fn($v) => $v['violation_type'] === 'empty')),
            'type_mismatch_count' => count(array_filter($violations, fn($v) => $v['violation_type'] === 'type_mismatch')),
            'compliance_rate' => count($rows) > 0
                ? round((count($rows) - count(array_unique(array_column($violations, 'exec_id')))) / count($rows) * 100, 1)
                : 100.0,
        ];

        // 工作流统计
        $wfResults = [];
        foreach ($wfStats as $wfName => $stats) {
            $wfResults[] = [
                'workflow' => $wfName,
                'executions' => $stats['executions'],
                'violations' => $stats['violations'],
                'compliance_rate' => round(($stats['executions'] - $stats['violations']) / max(1, $stats['executions']) * 100, 1),
                'required_params' => array_keys($requiredParams[$wfName] ?? []),
            ];
        }

        // 标题栏的总数是窗口全集；`global_summary` 里的合规率分子分母都取自同一批抽样
        // （违规点只能对已解码的行判），两者相除不会跨口径 —— 见 ScanBudget 的类注释。
        return $this->ok(array_merge([
            'total_executions' => $scan['window_rows'],
            'workflows_checked' => count($wfStats),
            'violations' => array_slice($violations, 0, $limit * 3),
            'workflow_results' => $wfResults,
            'global_summary' => $globalSummary,
            'days' => $days,
        ], $this->scanDeclared($scan)));
    }

    /**
     * 从 steps_json 定义中提取必填参数。
     *
     * 引用的落点由 `config` 读、深度由 `stepTree()` 管：旧写法在步骤顶层找
     * `url`/`payload`/`message`/`expression` 那七枚键（它们全都住在 `config` 里），又只递归
     * 顶层的 `steps` —— 于是必填集**恒为空**，`input-validation` 那一屏对每一枚工作流都宣布
     * 「不需要任何入参」，而真实定义里躺着 `{{params.order_id}}` 这一类必填引用。
     */
    private function extractRequiredParamsFromDef(array $stepsDef, array $accumulated): array
    {
        foreach ($this->stepTree($stepsDef) as $leaf) {
            $strings = [];
            $this->configStrings($leaf['config'], $strings);

            foreach ($strings as $str) {
                if (!preg_match_all('/\{\{params\.([^}]+)\}\}/', $str, $matches)) {
                    continue;
                }

                foreach ($matches[1] as $paramRef) {
                    $paramRef = trim($paramRef);
                    $topKey = strpos($paramRef, '.') !== false
                        ? substr($paramRef, 0, strpos($paramRef, '.'))
                        : $paramRef;

                    if (!isset($accumulated[$topKey])) {
                        $accumulated[$topKey] = ['type' => 'any'];
                    }
                }
            }
        }

        return $accumulated;
    }

    /**
     * 获取 PHP 值的类型标签。
     */
    private function getTypeLabel($value): string
    {
        if ($value === null) return 'null';
        if (is_bool($value)) return 'boolean';
        if (is_int($value) || is_float($value)) return 'numeric';
        if (is_string($value)) return 'string';
        if (is_array($value)) return 'array';
        if (is_object($value)) return 'object';
        return 'unknown';
    }

    /* ===================== 定义审计的作用域（三条入口共用一个拼装口） ===================== */

    /**
     * 定义侧 SELECT 的 `WHERE` 只由这里拼一次。
     *
     * 此前 `configAudit` / `securityAudit` / `cycleDetection` 各抄一遍「`$where = ' WHERE name = ?'`
     * 再串到 SQL 后面」，而其中两条的字面量里**已经写死了一句 `WHERE status = 1`** —— 于是交出
     * `?workflow=` 时得到 `… WHERE status = 1 WHERE name = ?`（SQLSTATE 42601 → 500），
     * 而恰好没写前置 WHERE 的那条能跑，分岔因此看着像「三条里两条坏」而不是「一份拼装被抄了三遍」。
     * 现在条件进数组、`WHERE` 这个关键字只在这里出现一次。
     *
     * @param list<string> $conds 本入口的范围条件（作用域由本函数追加，不由调用方拼）
     * @param string|null  $scope 生效作用域（null = 全站）
     * @param list<mixed>  $bindings 绑定参数，按 SQL 里 `?` 的顺序追加
     * @return string 拼好的 `WHERE …` 片段；没有任何条件时是空串（调用方直接插进语句尾部）
     */
    private static function definitionWhere(array $conds, ?string $scope, array &$bindings): string
    {
        if ($scope !== null && $scope !== '') {
            $conds[] = 'name = ?';
            $bindings[] = $scope;
        }

        return $conds === [] ? '' : 'WHERE ' . implode(' AND ', $conds);
    }

    /**
     * 问出本次生效作用域：`[拒绝响应, 作用域]`，两者恒为一枚 null。
     *
     * 拒绝时必须一个字节都不去问库。非字符串不是「这个名字没登记」而是参数错误 —— 旧写法
     * `(string) $workflow` 会把数组折成 "Array"（附带一条折成字符串的警告）再当合法名字发出去。
     *
     * @return array{0: ?\Psr\Http\Message\ResponseInterface, 1: ?string}
     */
    private function definitionScope(): array
    {
        $raw = Request::input('workflow', null);

        if ($raw === null || $raw === '') {
            return [null, null];
        }

        if (!is_scalar($raw)) {
            return [$this->error('workflow 参数必须是工作流名称（字符串）', 422), null];
        }

        $scope = trim((string) $raw);

        return [null, $scope === '' ? null : $scope];
    }

    /**
     * 「指定了作用域却一行都没落进范围」一律 404，且必须**点名那个名字**、分说两种「没进范围」。
     *
     * 旧落点是 `total_workflows: 0` + 空清单：与「全站审计且恰好只有一条」同形，页面说不出
     * 「这一屏筛的是谁」；「名字拼错了」与「那一枚已暂停」两种处置项完全不同的原因也被压成同一句
     * （口径同 #186「这个关键词没有命中」的第四种来源：作用域压根没生效）。
     *
     * @param bool $enabledOnly 本入口的范围是否只含启用的行（`configAudit` 管配置完整度，与跑不跑无关）
     */
    private function definitionScopeFailure(string $scope, bool $enabledOnly): \Psr\Http\Message\ResponseInterface
    {
        $found = Db::select("SELECT status FROM kode_lowcode_workflows WHERE name = ?", [$scope]);

        if ($found === []) {
            return $this->error("工作流「{$scope}」没有登记过，请核对名称", 404);
        }

        if ($enabledOnly && (int) ($found[0]['status'] ?? 0) !== 1) {
            return $this->error("工作流「{$scope}」已暂停，不在本轮审计范围内", 404);
        }

        return $this->error("作用域「{$scope}」没有落进本轮审计范围", 404);
    }

    /* ===================== Round 37: 配置审计 ===================== */

    /**
     * GET /api/workflow/config-audit
     * 审计所有工作流配置完整性。
     *
     * 参数：workflow(可选，按名筛这一枚；出参同级 `scope` 回显生效作用域，null = 全站)
     * 范围含暂停行 —— 这一屏管的是配置完整度，与跑不跑无关。
     */
    public function configAudit()
    {
        [$bad, $scope] = $this->definitionScope();
        if ($bad !== null) return $bad;

        $bindings = [];
        $where = self::definitionWhere([], $scope, $bindings);

        $rows = Db::select(
            "SELECT id, name, description, status, version, timeout_ms, schedule_cron,
                    notify_url, notify_secret, webhook_secret, webhook_ips,
                    on_fail_json, alert_url, alert_secret, alert_template, notify_template,
                    circuit_breaker_threshold, max_concurrent, step_count, created_at, updated_at
             FROM kode_lowcode_workflows {$where}
             ORDER BY name",
            $bindings
        );

        if ($rows === [] && $scope !== null) {
            return $this->definitionScopeFailure($scope, false);
        }

        if (empty($rows)) {
            return $this->ok([
                'total_workflows' => 0,
                'audits' => [],
                'summary' => [],
                'scope' => $scope,
            ]);
        }

        $auditItems = [
            'timeout_ms' => ['label' => '超时配置', 'field' => 'timeout_ms', 'missing_val' => 0, 'required' => true],
            'on_fail_json' => ['label' => '失败处理', 'field' => 'on_fail_json', 'missing_val' => '', 'required' => false],
            'schedule_cron' => ['label' => '定时调度', 'field' => 'schedule_cron', 'missing_val' => '', 'required' => false],
            'notify_url' => ['label' => '执行通知', 'field' => 'notify_url', 'missing_val' => '', 'required' => false],
            'notify_secret' => ['label' => '通知签名', 'field' => 'notify_secret', 'missing_val' => '', 'required' => false],
            'webhook_secret' => ['label' => 'Webhook 签名', 'field' => 'webhook_secret', 'missing_val' => '', 'required' => false],
            'webhook_ips' => ['label' => 'IP 白名单', 'field' => 'webhook_ips', 'missing_val' => '', 'required' => false],
            'alert_url' => ['label' => '告警通知', 'field' => 'alert_url', 'missing_val' => '', 'required' => false],
            'alert_secret' => ['label' => '告警签名', 'field' => 'alert_secret', 'missing_val' => '', 'required' => false],
            'alert_template' => ['label' => '告警模板', 'field' => 'alert_template', 'missing_val' => '', 'required' => false],
            'notify_template' => ['label' => '通知模板', 'field' => 'notify_template', 'missing_val' => '', 'required' => false],
            'circuit_breaker_threshold' => ['label' => '熔断阈值', 'field' => 'circuit_breaker_threshold', 'missing_val' => 0, 'required' => false],
            'max_concurrent' => ['label' => '并发限制', 'field' => 'max_concurrent', 'missing_val' => 0, 'required' => false],
            'version' => ['label' => '版本控制', 'field' => 'version', 'missing_val' => '', 'required' => false],
        ];

        $audits = [];
        $summary = [
            'total_workflows' => count($rows),
            'compliant' => 0,
            'warnings' => 0,
            'missing' => 0,
            'compliance_rate' => 0.0,
            'checks_per_workflow' => [],
        ];

        foreach ($rows as $row) {
            $wfAudit = [
                'workflow' => (string) $row['name'],
                'status' => (int) $row['status'],
                'version' => (string) $row['version'],
                'step_count' => (int) $row['step_count'],
                'checks' => [],
                'score' => 100,
                'level' => 'compliant',
            ];

            $totalChecks = 0;
            $passedChecks = 0;

            foreach ($auditItems as $key => $item) {
                $totalChecks++;
                $val = $row[$item['field']] ?? $item['missing_val'];
                $isMissing = $val === $item['missing_val'] || $val === null;
                $isCompliant = !$isMissing;

                if ($isCompliant) {
                    $passedChecks++;
                }

                $wfAudit['checks'][] = [
                    'key' => $key,
                    'label' => $item['label'],
                    'status' => $isCompliant ? 'compliant' : ($item['required'] ? 'missing' : 'warning'),
                    'value' => $val,
                ];

                if (!$isCompliant && $item['required']) {
                    $wfAudit['score'] -= 20;
                    $summary['missing']++;
                } elseif (!$isCompliant && !$item['required']) {
                    $wfAudit['score'] -= 5;
                    $summary['warnings']++;
                }
            }

            $wfAudit['score'] = max(0, min(100, $wfAudit['score']));
            $wfAudit['level'] = $wfAudit['score'] >= 80 ? 'compliant' : ($wfAudit['score'] >= 50 ? 'warning' : 'missing');
            $wfAudit['compliance_rate'] = round($passedChecks / max(1, $totalChecks) * 100, 1);

            if ($wfAudit['level'] === 'compliant') $summary['compliant']++;
            elseif ($wfAudit['level'] === 'warning') $summary['warnings']++;
            else $summary['missing']++;

            $audits[] = $wfAudit;
        }

        $summary['compliance_rate'] = round($summary['compliant'] / max(1, $summary['total_workflows']) * 100, 1);

        // 按分数排序
        usort($audits, function ($a, $b) {
            return $a['score'] <=> $b['score'];
        });

        return $this->ok([
            'total_workflows' => count($rows),
            'audits' => $audits,
            'summary' => $summary,
            'scope' => $scope,
        ]);
    }

    /* ===================== Round 37: 执行成本分摊 ===================== */

    /**
     * GET /api/workflow/cost-allocation
     * 按工作流/步骤分摊资源成本。
     *
     * 参数：days(1-30, 默认7), workflow(可选), limit(默认20)
     */
    public function costAllocation()
    {
        $days = (int) Request::input('days', 7);
        $days = max(1, min(30, $days));
        $workflow = Request::input('workflow', null);
        $limit = (int) Request::input('limit', 20);
        $limit = max(1, min(100, $limit));

        $where = ['created_at >= NOW() - ?::interval'];
        $bindings = ["{$days} days"];
        if ($workflow !== null && $workflow !== '') {
            $where[] = 'workflow_name = ?';
            $bindings[] = (string) $workflow;
        }

        // 按步骤分摊的那一份扫描先跑（它是有界的），这样「窗口里一行都没有」时
        // 出参的三件套仍然是**扫出来的**而不是手填的 0。
        $scan = $this->scanExecutions(
            'SELECT workflow_name, id AS exec_id, step_results_json',
            'WHERE ' . implode(' AND ', $where),
            $bindings,
            'ORDER BY created_at DESC',
            $limit * 50
        );
        $stepRows = $scan['rows'];

        // 按工作流聚合
        $wfRows = Db::select(
            "SELECT workflow_name,
                COUNT(*) AS executions,
                SUM(COALESCE(api_calls, 0)) AS total_api_calls,
                SUM(COALESCE(db_queries, 0)) AS total_db_queries,
                SUM(COALESCE(memory_peak_kb, 0)) AS total_memory_kb,
                SUM(COALESCE(duration_ms, 0)) AS total_duration_ms,
                SUM(COALESCE(steps_retried, 0)) AS total_steps_retried,
                AVG(duration_ms) AS avg_duration_ms,
                AVG(COALESCE(api_calls, 0)) AS avg_api_calls
             FROM kode_lowcode_workflow_executions
             WHERE " . implode(' AND ', $where) . "
             GROUP BY workflow_name
             ORDER BY total_duration_ms DESC",
            $bindings
        );

        if (empty($wfRows)) {
            return $this->ok(array_merge([
                'total_executions' => 0,
                'workflows' => 0,
                'per_workflow' => [],
                'per_step' => [],
                'allocation_summary' => [],
                'days' => $days,
            ], $this->scanDeclared($scan)));
        }

        // 计算全局总量
        $totalDuration = 0;
        $totalApiCalls = 0;
        $totalDbQueries = 0;
        $totalMemory = 0;
        $totalRetried = 0;

        $perWorkflow = [];
        foreach ($wfRows as $row) {
            $duration = (int) $row['total_duration_ms'];
            $apiCalls = (int) $row['total_api_calls'];
            $dbQueries = (int) $row['total_db_queries'];
            $memory = (int) $row['total_memory_kb'];
            $retried = (int) $row['total_steps_retried'];

            $totalDuration += $duration;
            $totalApiCalls += $apiCalls;
            $totalDbQueries += $dbQueries;
            $totalMemory += $memory;
            $totalRetried += $retried;

            $perWorkflow[] = [
                'workflow' => (string) $row['workflow_name'],
                'executions' => (int) $row['executions'],
                'total_api_calls' => $apiCalls,
                'total_db_queries' => $dbQueries,
                'total_memory_kb' => $memory,
                'total_duration_ms' => $duration,
                'total_steps_retried' => $retried,
                'avg_duration_ms' => round((float) $row['avg_duration_ms'], 1),
                'avg_api_calls' => round((float) $row['avg_api_calls'], 1),
                'duration_pct' => $totalDuration > 0 ? round($duration / $totalDuration * 100, 2) : 0,
                'api_calls_pct' => $totalApiCalls > 0 ? round($apiCalls / $totalApiCalls * 100, 2) : 0,
            ];
        }

        // 按步骤分摊的成本从 step_results_json 展开（判据只有一份：行来自上面那次有界扫描）
        $stepCosts = []; // workflow::step => stats
        // 键用 walker 交回的 `path`：每轮各一枚叶子，所以「loop 里那一步调了 100 次」在
        // total_api_calls 里就是 100 —— 旧写法只递归顶层那一格 `steps`，分支里的成本整个没进过账，
        // 而这一格的分母 `$sampledDuration` 取的就是这里的和，于是「成本都花在哪一步」答的是主路径。
        $expandStepCosts = function (array $results, string $wfName) use (&$stepCosts): void {
            foreach ($this->stepResultTree($results, '', $wfName) as $leaf) {
                $result = $leaf['result'];
                $key = $wfName . '::' . $leaf['path'];

                $duration = (int) ($result['_duration_ms'] ?? $result['duration_ms'] ?? 0);
                $apiCalls = 0;
                if (isset($result['http_code']) || isset($result['data']) || isset($result['response'])) {
                    $apiCalls = 1;
                }

                if (!isset($stepCosts[$key])) {
                    $stepCosts[$key] = [
                        'workflow' => $wfName,
                        'step_name' => $leaf['path'],
                        'executions' => 0,
                        'total_duration_ms' => 0,
                        'total_api_calls' => 0,
                    ];
                }
                $stepCosts[$key]['executions']++;
                $stepCosts[$key]['total_duration_ms'] += $duration;
                $stepCosts[$key]['total_api_calls'] += $apiCalls;
            }
        };

        foreach ($stepRows as $row) {
            $stepResults = json_decode($row['step_results_json'] ?? '{}', true) ?? [];
            $expandStepCosts(is_array($stepResults) ? $stepResults : [], (string) $row['workflow_name']);
        }

        // 排序并计算占比。分母必须是**同一批行**的耗时之和：$totalDuration 来自全窗口的
        // 按工作流聚合，而分子只展开抽样行 —— 两者相除等于「窗口越长、每步看着越便宜」。
        $sampledDuration = array_sum(array_column($stepCosts, 'total_duration_ms'));
        $perStep = [];
        foreach ($stepCosts as $key => $stats) {
            $stats['avg_duration_ms'] = $stats['executions'] > 0
                ? round($stats['total_duration_ms'] / $stats['executions'], 1)
                : 0;
            $stats['duration_pct'] = $sampledDuration > 0
                ? round($stats['total_duration_ms'] / $sampledDuration * 100, 2)
                : 0;
            $perStep[] = $stats;
        }
        usort($perStep, function ($a, $b) {
            return $b['total_duration_ms'] <=> $a['total_duration_ms'];
        });

        $allocationSummary = [
            'total_duration_ms' => $totalDuration,
            'total_api_calls' => $totalApiCalls,
            'total_db_queries' => $totalDbQueries,
            'total_memory_kb' => $totalMemory,
            'total_steps_retried' => $totalRetried,
            'most_expensive_workflow' => $perWorkflow[0]['workflow'] ?? '',
            'most_expensive_step' => $perStep[0]['step_name'] ?? '',
            'workflows_count' => count($perWorkflow),
            'steps_count' => count($perStep),
        ];

        return $this->ok(array_merge([
            // 此前是 count($wfRows) —— 按工作流 GROUP BY 的行数，也就是「有几个工作流」，
            // 却被面板当「总执行」印在卡片上。
            'total_executions' => $scan['window_rows'],
            'workflows' => count($perWorkflow),
            'per_workflow' => array_slice($perWorkflow, 0, $limit),
            'per_step' => array_slice($perStep, 0, $limit),
            'allocation_summary' => $allocationSummary,
            'days' => $days,
        ], $this->scanDeclared($scan)));
    }

    /* ===================== Round 38: 安全审计 ===================== */

    /**
     * GET /api/workflow/security-audit
     * 扫描所有工作流 steps_json 中的安全风险。
     *
     * 参数：workflow(可选，按名筛这一枚；出参同级 `scope` 回显生效作用域，null = 全站)
     * 范围只含启用的行；筛到空范围一律 404 并点名（区分「没登记过」与「已暂停」）。
     */
    public function securityAudit()
    {
        [$bad, $scope] = $this->definitionScope();
        if ($bad !== null) return $bad;

        $bindings = [];
        $where = self::definitionWhere(['status = 1'], $scope, $bindings);

        $rows = Db::select(
            "SELECT id, name, steps_json, timeout_ms, webhook_secret, webhook_ips,
                    notify_secret, alert_secret, on_fail_json, circuit_breaker_threshold
             FROM kode_lowcode_workflows {$where}
             ORDER BY name",
            $bindings
        );

        if ($rows === [] && $scope !== null) {
            return $this->definitionScopeFailure($scope, true);
        }

        if (empty($rows)) {
            return $this->ok([
                'total_workflows' => 0,
                'audits' => [],
                'summary' => [],
                'scope' => $scope,
            ]);
        }

        $audits = [];
        $summary = [
            'total_workflows' => count($rows),
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
            'risk_level' => 'safe',
        ];

        foreach ($rows as $row) {
            $stepsJson = json_decode($row['steps_json'] ?? '[]', true) ?? [];
            $findings = [];

            // 1. 硬编码 URL 检测（api_call 步骤中 url 不含 {{ 变量）
            $hardcodedUrls = $this->findHardcodedUrls($stepsJson, []);
            if (!empty($hardcodedUrls)) {
                $findings[] = [
                    'check' => 'hardcoded_url',
                    'label' => '硬编码 URL',
                    'severity' => 'warning',
                    'count' => count($hardcodedUrls),
                    'examples' => array_slice($hardcodedUrls, 0, 3),
                    'message' => '存在硬编码 URL，建议改为配置变量',
                ];
            }

            // 2. 缺少 Webhook 签名
            if (empty($row['webhook_secret'])) {
                $hasWebhook = $this->hasWebhookStep($stepsJson);
                if ($hasWebhook) {
                    $findings[] = [
                        'check' => 'missing_webhook_secret',
                        'label' => '缺少 Webhook 签名',
                        'severity' => 'critical',
                        'count' => 1,
                        'examples' => [],
                        'message' => '工作流包含 Webhook 调用但未配置签名密钥',
                    ];
                }
            }

            // 3. 缺少 IP 白名单
            if (empty($row['webhook_ips']) && $this->hasWebhookStep($stepsJson)) {
                $findings[] = [
                    'check' => 'missing_ip_whitelist',
                    'label' => '缺少 IP 白名单',
                    'severity' => 'warning',
                    'count' => 1,
                    'examples' => [],
                    'message' => '工作流包含 Webhook 调用但未配置 IP 白名单',
                ];
            }

            // 4. Log 步骤输出（敏感数据泄露）
            //    （曾经这一位是 `expression_eval_risk`：#70 去掉 eval 之后 `expression` 这一字段
            //    在定义里没有任何落点，留着就是「一个永不触发的判据占着一次遍历」。已随本轮退役。）
            $logOutputs = $this->findLogSteps($stepsJson, []);
            if (!empty($logOutputs)) {
                $findings[] = [
                    'check' => 'log_data_exposure',
                    'label' => '日志数据暴露',
                    'severity' => 'info',
                    'count' => count($logOutputs),
                    'examples' => array_slice($logOutputs, 0, 3),
                    'message' => 'Log 步骤可能记录敏感数据',
                ];
            }

            // 5. 缺少超时配置
            if ((int) $row['timeout_ms'] === 0) {
                $findings[] = [
                    'check' => 'missing_timeout',
                    'label' => '缺少超时配置',
                    'severity' => 'warning',
                    'count' => 1,
                    'examples' => [],
                    'message' => '工作流未配置超时时间，可能导致无限执行',
                ];
            }

            // 6. 缺少失败处理
            if (empty($row['on_fail_json'])) {
                $findings[] = [
                    'check' => 'missing_fail_handler',
                    'label' => '缺少失败处理',
                    'severity' => 'warning',
                    'count' => 1,
                    'examples' => [],
                    'message' => '工作流未配置失败处理策略',
                ];
            }

            // 7. API 调用缺少超时
            $apiCallsWithoutTimeout = $this->findApiCallsWithoutTimeout($stepsJson, []);
            if (!empty($apiCallsWithoutTimeout)) {
                $findings[] = [
                    'check' => 'api_call_no_timeout',
                    'label' => 'API 调用缺少超时',
                    'severity' => 'warning',
                    'count' => count($apiCallsWithoutTimeout),
                    'examples' => array_slice($apiCallsWithoutTimeout, 0, 3),
                    'message' => '部分 API 调用未配置超时时间',
                ];
            }

            // 8. Webhook 触发器无凭证（入站方向，与 2/3 的出站 webhook 步骤是两回事）
            //    没配 secret 也没配 IP 白名单时，POST /api/workflow/webhook/{name} 是匿名的，
            //    而 webhookAdmission() 会直接拒（403）—— 这里把「为什么被拒」提前告诉作者。
            if (trim((string) ($row['webhook_secret'] ?? '')) === ''
                && trim((string) ($row['webhook_ips'] ?? '')) === '') {
                $findings[] = [
                    'check' => 'open_webhook_trigger',
                    'label' => 'Webhook 触发器无凭证',
                    'severity' => 'critical',
                    'count' => 1,
                    'examples' => [],
                    'message' => '未配置签名密钥与 IP 白名单，该工作流的 webhook 触发入口已拒绝一切调用（配一项即可放开）',
                ];
            }

            // 统计
            $criticalCount = count(array_filter($findings, fn($f) => $f['severity'] === 'critical'));
            $warningCount = count(array_filter($findings, fn($f) => $f['severity'] === 'warning'));
            $infoCount = count(array_filter($findings, fn($f) => $f['severity'] === 'info'));

            $summary['critical'] += $criticalCount;
            $summary['warning'] += $warningCount;
            $summary['info'] += $infoCount;

            $riskScore = $criticalCount * 30 + $warningCount * 10 + $infoCount * 2;
            $riskLevel = $riskScore >= 60 ? 'high' : ($riskScore >= 30 ? 'medium' : ($riskScore > 0 ? 'low' : 'safe'));

            $audits[] = [
                'workflow' => (string) $row['name'],
                'findings' => $findings,
                'critical' => $criticalCount,
                'warning' => $warningCount,
                'info' => $infoCount,
                'risk_score' => $riskScore,
                'risk_level' => $riskLevel,
            ];
        }

        // 按风险分数排序
        usort($audits, function ($a, $b) {
            return $b['risk_score'] <=> $a['risk_score'];
        });

        $summary['risk_level'] = $summary['critical'] > 0 ? 'high' : ($summary['warning'] > 5 ? 'medium' : 'low');

        return $this->ok([
            'total_workflows' => count($rows),
            'audits' => $audits,
            'summary' => $summary,
            'scope' => $scope,
        ]);
    }

    /**
     * 走一遍步骤树，交出**扁平**的叶子清单：`[{step, config, type, name, path}, …]`。
     *
     * 步骤的权威形状是 `{type, name, config:{…}}`（`validateSteps()` 与 `stepTypeCatalog()` 就是这一份），
     * 子步骤住在 `config.steps` / `config.cases[].steps` / `config.default_steps` ——
     * 「哪些键装子步骤」这份知识只写在这里。此前读定义的九处各抄一遍「读顶层键 + 递归顶层 `steps`」，
     * 于是判据要么恒不触发（`$step['url']` 从来不存在）、要么恒触发
     * （`empty($step['timeout_ms'])` 对任何步骤都成立），而 loop/switch 里的调用
     * 在审计、环检测、依赖图、血缘、必填参数五个屏上同时不存在。
     *
     * 交回的 `config` 是**去掉子步骤桶之后**的那一份：调用方扫 config 求变量引用时，
     * 不会把子步骤又数一遍（父步骤自己那份才是它自己的配置）。
     *
     * @param array<int, mixed> $steps
     * @return list<array{step: array<string, mixed>, config: array<string, mixed>, type: string, name: string, path: string}>
     */
    private function stepTree(array $steps, string $prefix = ''): array
    {
        $leaves = [];

        foreach ($steps as $raw) {
            if (!is_array($raw)) continue;

            $config = is_array($raw['config'] ?? null) ? $raw['config'] : [];
            $children = [];

            if (is_array($config['steps'] ?? null)) {
                $children[] = $config['steps'];
            }
            unset($config['steps']);

            if (is_array($config['cases'] ?? null)) {
                foreach ($config['cases'] as $case) {
                    if (is_array($case) && is_array($case['steps'] ?? null)) {
                        $children[] = $case['steps'];
                    }
                }
            }
            unset($config['cases']);

            if (is_array($config['default_steps'] ?? null)) {
                $children[] = $config['default_steps'];
            }
            unset($config['default_steps']);

            $name = (string) ($raw['name'] ?? '');
            $path = $prefix === '' ? $name : $prefix . '.' . $name;

            $leaves[] = [
                'step' => $raw,
                'config' => $config,
                'type' => (string) ($raw['type'] ?? ''),
                'name' => $name,
                'path' => $path,
            ];

            foreach ($children as $branch) {
                foreach ($this->stepTree($branch, $path) as $leaf) {
                    $leaves[] = $leaf;
                }
            }
        }

        return $leaves;
    }

    /**
     * 走一遍**执行结果**树，交出扁平清单：`[{name, path, workflow, depth, result}, …]`。
     *
     * 这与 `stepTree()` 是**两棵树**，容器键各不相同，别互相抄：定义树的子步骤住在
     * `config.steps` / `config.cases[].steps` / `config.default_steps`，而执行结果里的子结果
     * 按**执行器**分岔 —— `executeSubWorkflow()` 落在 `steps`、`executeLoop()` 落在
     * `iterations[].results`（超时那一格是不带 `results` 的裸 `{index, error}`）、
     * `executeSwitch()` 落在 `results`（超时那一格是**数字键**的裸 `{error}`）、
     * 步骤级恢复落在同一格的 `on_fail_results`、工作流级恢复落在顶层 `__on_fail__`
     * （五处写入的都是 `executeFailSteps()` 的返回，恒为「步骤名 => 结果」的映射）。
     * 「哪些键装子结果」这份知识只写在这里。此前十二处消费各自递归顶层那一格 `steps`，于是
     * loop/switch/on_fail 里真的跑过的那一步既不在死代码榜（被算成「从未到达」）、也不在
     * 失败榜/重试榜/调用聚合里点名，而 #203 把子步骤收进**定义**清单之后两边必然差一批 ——
     * 差的那一批恰好是「躲在分支里、每轮都在跑」的步骤，死代码榜那一栏的文案是「从未到达」，
     * 照着删掉的就是一次真业务。
     *
     * 三条口径：
     * ① **容器节点也交回**（调用方按 `http_code`/`data`/`success` 判「这一步是不是 api_call」，
     *    父节点自己可能就是那一次调用）；
     * ② `path` 的段就是**它来自的那枚 JSON 键**：`steps` / `results` / `iterations` 折叠掉
     *    （`main_loop.log_item`），`on_fail_results` 与 `__on_fail__` 保留（那一格与父步骤是
     *    两件事：「补偿也失败了」不能被并进主步骤那一行）；`iterations` 的下标不进 path，
     *    但每一轮各交回一枚叶子 —— 同一枚循环步骤跑 100 次不该在榜上占 100 格；
     * ③ `__on_fail__` **只下钻、不交回自己**：它不是一枚步骤，交回出去就等于往「跑过的步骤」
     *    清单里凭空加一枚定义里从来没有的名字（死代码榜的另一半是「定义里有、执行里没有」）。
     *
     * `workflow` 只在节点自带非空 `workflow` 键（sub_workflow）时切换，否则原样往下传。
     * 调用方原先那句 `?? $stepName` 会凭空造出一枚任何定义与超时清单里都不存在的名字。
     * 递归不设上限：`step_results_json` 由 `json_decode`（默认 512 层）交回，JSON 文档本身成树、
     * 不可能有环，所以这里不需要消费者各自那句 `depth < 3`（那一枚是照着执行器的
     * `if ($depth > 3)` 抄的，而超过 3 层的子结果**结构上落不进库**）。
     *
     * @param array<string|int, mixed> $nodes 一层「步骤名 => 结果」的映射
     * @return list<array{name: string, path: string, parent: string, workflow: string, depth: int, result: array<string, mixed>}>
     */
    private function stepResultTree(array $nodes, string $prefix = '', string $workflow = '', int $depth = 0, string $parent = ''): array
    {
        $leaves = [];

        foreach ($nodes as $key => $result) {
            if (!is_array($result)) continue;

            $isNamed = is_string($key);
            $seg = $isNamed ? $key : '#' . $key;
            $path = $prefix === '' ? $seg : $prefix . '.' . $seg;
            $name = $isNamed ? $key : '';

            // 工作流级恢复：它是一层映射而不是一个步骤的结果，只下钻。
            $childWf = $workflow;
            if ($isNamed && $key === '__on_fail__') {
                foreach ($this->stepResultTree($result, $path, $workflow, $depth + 1, $parent) as $leaf) {
                    $leaves[] = $leaf;
                }
                continue;
            }

            if (isset($result['workflow']) && is_string($result['workflow']) && $result['workflow'] !== '') {
                $childWf = $result['workflow'];
            }

            $leaves[] = [
                'name' => $name,
                'path' => $path,
                'parent' => $parent,
                'workflow' => $workflow,
                'depth' => $depth,
                'result' => $result,
            ];

            $buckets = [];
            if (is_array($result['steps'] ?? null)) {
                $buckets[] = [$result['steps'], $path];
            }
            if (is_array($result['results'] ?? null)) {
                $buckets[] = [$result['results'], $path];
            }
            if (is_array($result['on_fail_results'] ?? null)) {
                $buckets[] = [$result['on_fail_results'], $path . '.on_fail_results'];
            }
            if (is_array($result['iterations'] ?? null)) {
                foreach ($result['iterations'] as $idx => $iteration) {
                    if (!is_array($iteration)) continue;
                    if (is_array($iteration['results'] ?? null)) {
                        $buckets[] = [$iteration['results'], $path];
                        continue;
                    }
                    // loop 超时那一格没有 results：把它的 error 也交回，别让它只是「少一轮」。
                    $leaves[] = [
                        'name' => '',
                        'path' => $path . '.#' . ($iteration['index'] ?? $idx),
                        'parent' => $path,
                        'workflow' => $workflow,
                        'depth' => $depth + 1,
                        'result' => $iteration,
                    ];
                }
            }

            foreach ($buckets as [$bucket, $bucketPath]) {
                foreach ($this->stepResultTree($bucket, $bucketPath, $childWf, $depth + 1, $path) as $leaf) {
                    $leaves[] = $leaf;
                }
            }
        }

        return $leaves;
    }

    /**
     * 一棵结果树里**给人看的那一列名字**只有一个写法。
     *
     * `stepResultTree()` 对数字键与合成格（loop 超时那一轮 `main_loop.#2`）交回 `name = ''` ——
     * 那是语义（「这一格不是定义里的一枚步骤」），不是「这一格没内容」：loop 与 switch 容器自己
     * **不带** `error` 键，那一格带着整棵树里唯一的失败记录。渲染口若直接把 `name` 发出去，
     * 页面上就是一行空白，而那一次超时正是「这一条执行为什么失败」的全部答案。
     * 取 `path` 的最后一段（`#2`）既能对上轮次，也不会凭空造出一枚定义里不存在的步骤名。
     *
     * @param array{name: string, path: string} $leaf
     */
    private static function stepDisplayName(array $leaf): string
    {
        if ($leaf['name'] !== '') {
            return $leaf['name'];
        }
        $segments = explode('.', $leaf['path']);
        return (string) end($segments);
    }

    /**
     * 查找硬编码 URL（`config.url` 里不含 {{ 变量的那几枚）。
     */
    private function findHardcodedUrls(array $steps, array $accumulated): array
    {
        foreach ($this->stepTree($steps) as $leaf) {
            $url = $leaf['config']['url'] ?? null;
            if (!is_string($url) || $url === '') continue;
            if (strpos($url, '{{') === false && strpos($url, 'http') === 0) {
                $accumulated[] = $url;
            }
        }
        return $accumulated;
    }

    /**
     * 检查工作流是否包含出站调用（决定两项入站凭证判据要不要开口）。
     */
    private function hasWebhookStep(array $steps): bool
    {
        foreach ($this->stepTree($steps) as $leaf) {
            // `http` 不是真实步骤类型（`stepTypeCatalog()` 里没有这一枚）。
            if ($leaf['type'] === 'api_call') return true;
        }
        return false;
    }

    /**
     * 查找 log 步骤会被打印出来的内容。
     */
    private function findLogSteps(array $steps, array $accumulated): array
    {
        foreach ($this->stepTree($steps) as $leaf) {
            if ($leaf['type'] !== 'log') continue;
            $message = $leaf['config']['message'] ?? null;
            if (!is_string($message) || trim($message) === '') continue;
            // 念的必须是**会被打印的内容**，不是步骤名（步骤名等于什么都没说）。
            // 日志文案里常有中文字面量，按字节切会留半个汉字，而这个值要进响应 ——
            // kode/http 的 json_encode 带 JSON_THROW_ON_ERROR，半个汉字就是整份响应 500。
            $accumulated[] = Clip::chars($message, 50);
        }
        return $accumulated;
    }

    /**
     * 查找缺少超时的 API 调用。
     */
    private function findApiCallsWithoutTimeout(array $steps, array $accumulated): array
    {
        foreach ($this->stepTree($steps) as $leaf) {
            if ($leaf['type'] !== 'api_call') continue;
            // 真实字段是 `config.timeout`、**按秒计**（`validateSteps()` 的 1..60，默认 30）。
            // 旧判据读顶层 `timeout_ms` —— 那个键在步骤上从来不存在，于是这一条对每一枚
            // 工作流都触发，审计页因此对每行都印同一条假警告。
            if ((int) ($leaf['config']['timeout'] ?? 0) > 0) continue;
            $accumulated[] = $leaf['name'] !== '' ? $leaf['name'] : $leaf['path'];
        }
        return $accumulated;
    }

    /* ===================== Round 38: 依赖环检测 ===================== */

    /**
     * GET /api/workflow/cycle-detection
     * 检测 sub_workflow 调用中的循环依赖。
     *
     * 参数：workflow(可选，作用域)
     *
     * 图恒按**全量启用集**构建，作用域只筛**上报的那几圈**：一圈是由若干枚工作流共同构成的，
     * 在 SQL 里就把它们筛掉等于让「这一屏没有环」成为结构上的必然（只查 A 时 A→B→A 的 B
     * 压根不在节点集里，DFS 直接 `continue`），于是这一面板对每一个真环都回「无环」。
     * 作用域名没落进启用集时 404 点名（同 §4「这个关键词没有命中」的第四种来源）。
     */
    public function cycleDetection()
    {
        [$bad, $scope] = $this->definitionScope();
        if ($bad !== null) return $bad;

        $bindings = [];
        // 前置条件只有一份，且没有第二句 WHERE：作用域交给下面的筛圈。
        $where = self::definitionWhere(['status = 1'], null, $bindings);

        $rows = Db::select(
            "SELECT name, steps_json FROM kode_lowcode_workflows {$where}
             ORDER BY name",
            $bindings
        );

        // 提取 sub_workflow 调用关系
        $graph = []; // workflow => [sub_workflow1, sub_workflow2]
        $allNodes = [];

        foreach ($rows as $row) {
            $wfName = (string) $row['name'];
            $allNodes[$wfName] = true;
            $stepsJson = json_decode($row['steps_json'] ?? '[]', true) ?? [];
            $subWorkflows = $this->extractSubWorkflowsFromSteps($stepsJson, []);
            $graph[$wfName] = array_values(array_unique($subWorkflows));
        }

        if ($scope !== null && !in_array($scope, array_keys($allNodes), true)) {
            return $this->definitionScopeFailure($scope, true);
        }

        if ($allNodes === []) {
            return $this->ok([
                'total_workflows' => 0,
                'scope' => null,
                'cycles' => [],
                'graph' => ['nodes' => [], 'edges' => []],
                'summary' => $this->cycleSummary([]),
            ]);
        }

        // 构建边列表
        $edges = [];
        foreach ($graph as $wf => $subs) {
            foreach ($subs as $sub) {
                $edges[] = ['from' => $wf, 'to' => $sub];
            }
        }

        // 回溯找环只有一枚实现（findCycles）：本方法直接交回它的结构体，
        // 与 dependenciesGraph() 走的是同一份遍历、同一份去重，两张面板才对得上同一条环。
        $cycles = $this->findCycles($graph, $allNodes);

        // 作用域筛的是**上报的那几圈**（图与边保持全量，见方法上的注释）。
        $reported = $scope === null ? $cycles : array_values(array_filter(
            $cycles,
            fn(array $c): bool => in_array($scope, (array) $c['workflows'], true)
        ));

        $nodes = array_keys($allNodes);

        return $this->ok([
            'total_workflows' => count($nodes),
            'scope' => $scope,
            'cycles' => $reported,
            'graph' => [
                'nodes' => $nodes,
                'edges' => $edges,
            ],
            'summary' => $this->cycleSummary($reported, count($nodes)),
        ]);
    }

    /**
     * 环检测的结论摘要，只由这一处算。
     *
     * 「有环工作流数」以前是 `count(array_unique(array_merge(...array_map(...))))`：
     * 名单为空时 `array_map` 得 `[]`、`array_merge(...[])` 也是 `[]`，那一句全靠 `?: 0` 兜住。
     * 现在按上报清单算，`total_workflows` 却是**图**的口径（全量启用集）—— 两个数各说各的，
     * 页面那两格才不会被筛了作用域的那一屏混成同一件事。
     *
     * @param array<int, array{path: list<string>, workflows: list<string>, depth: int}> $cycles
     */
    private function cycleSummary(array $cycles, int $workflowTotal = 0): array
    {
        $members = [];
        foreach ($cycles as $cycle) {
            foreach ($cycle['workflows'] as $wf) {
                $members[$wf] = true;
            }
        }

        return [
            'total_workflows' => $workflowTotal,
            'workflows_with_cycles' => count($members),
            'cycle_count' => count($cycles),
            'max_cycle_depth' => $cycles === [] ? 0 : max(array_column($cycles, 'depth')),
            'has_cycles' => $cycles !== [],
        ];
    }

    /**
     * 从 steps_json 中提取 sub_workflow 调用。
     *
     * 子步骤树由 `stepTree()` 走（以前这一具自己 `foreach` 且只递归顶层 `steps`，所以躲在
     * loop/switch 分支里的调用在环检测眼里不存在），被调名只由 `config.workflow` 读
     * （`sub_workflow` 的权威形状见 `validateSteps()`）。以前退到 `$step['name']` 那一枚
     * 「步骤显示名」，它不在节点集里 ⇒ `cycles` 恒为 `[]`，而同一份文件里
     * `dependenciesGraph()` 一直读的是 `config.workflow` —— 分岔而非设计。
     *
     * @param array<int, mixed> $steps
     * @param list<string>      $accumulated
     * @return list<string>
     */
    private function extractSubWorkflowsFromSteps(array $steps, array $accumulated): array
    {
        foreach ($this->stepTree($steps) as $leaf) {
            if ($leaf['type'] !== 'sub_workflow') continue;
            $target = (string) ($leaf['config']['workflow'] ?? '');
            if ($target !== '') {
                $accumulated[] = $target;
            }
        }

        return $accumulated;
    }

    /* ===================== Round 38: 执行回放对比 ===================== */

    /**
     * GET /api/workflow/replay-comparison
     * 对比同一工作流不同执行的结果差异。
     *
     * 参数：workflow(必填), days(1-30, 默认7), limit(默认10)
     */
    public function replayComparison()
    {
        $workflow = (string) Request::input('workflow', '');
        if ($workflow === '') {
            return $this->error('workflow 参数必填', 422);
        }

        $days = (int) Request::input('days', 7);
        $days = max(1, min(30, $days));
        $limit = (int) Request::input('limit', 10);
        $limit = max(2, min(50, $limit));

        $rows = Db::select(
            "SELECT id, status, duration_ms, input_json, step_results_json, created_at
             FROM kode_lowcode_workflow_executions
             WHERE workflow_name = ? AND created_at >= NOW() - ?::interval
             ORDER BY created_at DESC
             LIMIT ?",
            [$workflow, "{$days} days", $limit]
        );

        if (count($rows) < 2) {
            return $this->ok([
                'workflow' => $workflow,
                'comparisons' => [],
                'summary' => ['total_comparisons' => 0, 'total_differences' => 0],
                'message' => '需要至少 2 次执行才能对比',
                'days' => $days,
            ]);
        }

        // 以最新执行为基准，对比其他执行
        $baseline = $rows[0];
        $baselineResults = json_decode($baseline['step_results_json'] ?? '{}', true) ?? [];

        $comparisons = [];
        foreach (array_slice($rows, 1) as $exec) {
            $execResults = json_decode($exec['step_results_json'] ?? '{}', true) ?? [];
            $diff = $this->compareExecutions($baseline, $exec, $baselineResults, $execResults);
            $comparisons[] = [
                'baseline_id' => \app\support\HashId::enc((int) $baseline['id']),
                'baseline_time' => (string) $baseline['created_at'],
                'compare_id' => \app\support\HashId::enc((int) $exec['id']),
                'compare_time' => (string) $exec['created_at'],
                'diff' => $diff,
            ];
        }

        $totalDiffs = 0;
        foreach ($comparisons as $c) {
            $totalDiffs += (int) ($c['diff']['diff_count'] ?? 0);
        }

        return $this->ok([
            'workflow' => $workflow,
            'baseline_id' => \app\support\HashId::enc((int) $baseline['id']),
            'baseline_time' => (string) $baseline['created_at'],
            'comparisons' => $comparisons,
            'summary' => [
                'total_comparisons' => count($comparisons),
                'total_differences' => $totalDiffs,
                'has_differences' => $totalDiffs > 0,
            ],
            'days' => $days,
        ]);
    }

    /**
     * 对比两个执行的结果差异。
     */
    private function compareExecutions(array $baseline, array $compare, array $baselineResults, array $compareResults): array
    {
        $diff = [
            'diff_count' => 0,
            'status_changed' => false,
            'duration_changed' => false,
            'steps_added' => [],
            'steps_removed' => [],
            'steps_modified' => [],
            'output_changed' => [],
        ];

        // 状态变化
        if ($baseline['status'] !== $compare['status']) {
            $diff['status_changed'] = true;
            $diff['diff_count']++;
        }

        // 耗时变化（>10% 视为变化）
        $bDur = (int) $baseline['duration_ms'];
        $cDur = (int) $compare['duration_ms'];
        if ($bDur > 0 && abs($cDur - $bDur) / $bDur > 0.1) {
            $diff['duration_changed'] = true;
            $diff['diff_count']++;
            $diff['duration_baseline'] = $bDur;
            $diff['duration_compare'] = $cDur;
        }

        // 步骤对比
        $baselineStepNames = array_keys($baselineResults);
        $compareStepNames = array_keys($compareResults);

        $added = array_diff($compareStepNames, $baselineStepNames);
        $removed = array_diff($baselineStepNames, $compareStepNames);
        $common = array_intersect($baselineStepNames, $compareStepNames);

        foreach ($added as $step) {
            $diff['steps_added'][] = (string) $step;
        }
        foreach ($removed as $step) {
            $diff['steps_removed'][] = (string) $step;
        }
        $diff['diff_count'] += count($added) + count($removed);

        // 输出变化
        foreach ($common as $step) {
            $bStep = $baselineResults[$step] ?? [];
            $cStep = $compareResults[$step] ?? [];
            if (!is_array($bStep) || !is_array($cStep)) continue;

            // 对比输出数据
            $bData = $bStep['data'] ?? $bStep['result'] ?? $bStep;
            $cData = $cStep['data'] ?? $cStep['result'] ?? $cStep;

            if (json_encode($bData) !== json_encode($cData)) {
                $diff['output_changed'][] = [
                    'step' => (string) $step,
                    'baseline' => $this->truncateJson($bData, 100),
                    'compare' => $this->truncateJson($cData, 100),
                ];
                $diff['diff_count']++;
            }
        }

        return $diff;
    }

    /**
     * 截断 JSON 输出用于显示。
     */
    private function truncateJson($data, int $maxLen): string
    {
        $str = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
        $cut = Clip::chars($str, $maxLen);
        // 省略号只在**真的截短了**时补：旧写法 `strlen($str) > $maxLen` 按字节判、
        // 再无条件拼 '...'，于是一个 101 字节 34 字的中文值没被截也带着省略号进了响应。
        return $cut === $str ? $str : $cut . '…';
    }

    /* ===================== Round 39: 变更影响分析 ===================== */

    /**
     * GET /api/workflow/change-impact
     * 评估修改工作流对下游的影响。
     *
     * 参数：workflow(必填)
     */
    public function changeImpact()
    {
        $workflow = (string) Request::input('workflow', '');
        if ($workflow === '') {
            return $this->error('workflow 参数必填', 422);
        }

        // 1. 查找所有工作流的 sub_workflow 调用关系
        $allDefs = Db::select("SELECT name, steps_json FROM kode_lowcode_workflows WHERE status = 1");
        $dependents = []; // 哪些工作流调用了目标工作流
        $dependencyMap = [];

        foreach ($allDefs as $def) {
            $defName = (string) $def['name'];
            if ($defName === $workflow) continue;
            $stepsJson = json_decode($def['steps_json'] ?? '[]', true) ?? [];
            $subWorkflows = $this->extractSubWorkflowsFromSteps($stepsJson, []);
            if (in_array($workflow, $subWorkflows)) {
                $dependents[] = $defName;
                $dependencyMap[$defName] = array_unique($subWorkflows);
            }
        }

        // 2. 查询每个依赖者的 SLA 目标和健康度
        $upstreamDeps = [];
        foreach ($dependents as $depName) {
            $slaRow = Db::select(
                "SELECT target_success_rate, target_p95_ms FROM kode_lowcode_workflow_sla_targets WHERE workflow_name = ?",
                [$depName]
            );
            $sla = $slaRow[0] ?? [];

            // 查询近期健康度
            $healthRow = Db::select(
                "SELECT COUNT(*) AS cnt,
                        COUNT(*) FILTER (WHERE status = 'succeeded') AS succ,
                        AVG(duration_ms) AS avg_ms,
                        PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms) AS p95_ms
                 FROM kode_lowcode_workflow_executions
                 WHERE workflow_name = ? AND created_at >= NOW() - INTERVAL '7 days'",
                [$depName]
            );
            $h = $healthRow[0] ?? [];
            $totalCnt = (int) ($h['cnt'] ?? 0);
            $succCnt = (int) ($h['succ'] ?? 0);
            $sr = $totalCnt > 0 ? round($succCnt / $totalCnt * 100, 1) : 0;

            $upstreamDeps[] = [
                'workflow' => $depName,
                'sla_target' => [
                    'success_rate' => $sla['target_success_rate'] ?? null,
                    'p95_ms' => $sla['target_p95_ms'] ?? null,
                ],
                'health' => [
                    'executions_7d' => $totalCnt,
                    'success_rate' => $sr,
                    'avg_ms' => round((float) ($h['avg_ms'] ?? 0), 1),
                    'p95_ms' => round((float) ($h['p95_ms'] ?? 0), 1),
                ],
                'depends_on_count' => count($dependencyMap[$depName] ?? []),
            ];
        }

        // 3. 查询目标工作流的近期执行统计
        $targetStats = Db::select(
            "SELECT COUNT(*) AS cnt,
                    COUNT(*) FILTER (WHERE status != 'succeeded') AS failed
             FROM kode_lowcode_workflow_executions
             WHERE workflow_name = ? AND created_at >= NOW() - INTERVAL '7 days'",
            [$workflow]
        );
        $targetStats = $targetStats[0] ?? [];
        $targetCnt = (int) ($targetStats['cnt'] ?? 0);
        $targetFailed = (int) ($targetStats['failed'] ?? 0);

        // 4. 计算影响评估
        $affectedWorkflows = count($upstreamDeps);
        $affectedExecutions = array_sum(array_column($upstreamDeps, 'health.executions_7d'));

        // 影响等级
        if ($affectedWorkflows === 0) {
            $impactLevel = 'none';
        } elseif ($affectedWorkflows <= 2 && $targetFailed === 0) {
            $impactLevel = 'low';
        } elseif ($affectedWorkflows <= 5 || $targetFailed <= 5) {
            $impactLevel = 'medium';
        } else {
            $impactLevel = 'high';
        }

        $recommendation = match ($impactLevel) {
            'none' => '无下游依赖，可安全修改',
            'low' => '影响较小，建议在低峰期修改并监控下游',
            'medium' => '影响中等，建议先灰度发布并配置回滚方案',
            'high' => '影响较大，建议分批发布并提前通知下游负责人',
            default => '评估影响后决定',
        };

        return $this->ok([
            'workflow' => $workflow,
            'target_stats' => [
                'executions_7d' => $targetCnt,
                'failed_7d' => $targetFailed,
                'success_rate_7d' => $targetCnt > 0 ? round(($targetCnt - $targetFailed) / $targetCnt * 100, 1) : 0,
            ],
            'upstream_dependencies' => $upstreamDeps,
            'impact_assessment' => [
                'level' => $impactLevel,
                'affected_workflows' => $affectedWorkflows,
                'affected_executions_7d' => $affectedExecutions,
                'recommendation' => $recommendation,
            ],
        ]);
    }

    /* ===================== Round 39: 配置变更历史 ===================== */

    /**
     * GET /api/workflow/config-history
     * 从 versions 表追踪工作流配置变更历史。
     *
     * 参数：workflow(必填), limit(默认20)
     */
    public function configHistory()
    {
        $workflow = (string) Request::input('workflow', '');
        if ($workflow === '') {
            return $this->error('workflow 参数必填', 422);
        }

        $limit = (int) Request::input('limit', 20);
        $limit = max(1, min(50, $limit));

        // 查询工作流 ID
        $wfRow = Db::select("SELECT id, version FROM kode_lowcode_workflows WHERE name = ?", [$workflow]);
        if (empty($wfRow)) {
            return $this->error("工作流 '{$workflow}' 不存在", 404);
        }
        $wfId = (int) $wfRow[0]['id'];

        // 「版本总数」的全集口径只能由 SQL 数：下面的列表与差异都是按 $limit 抽样算的
        $totalRow = Db::select(
            'SELECT COUNT(*) AS cnt FROM kode_lowcode_workflow_versions WHERE workflow_id = ?',
            [$wfId]
        );
        $totalVersions = (int) ($totalRow[0]['cnt'] ?? 0);

        // 查询版本历史
        $versions = Db::select(
            "SELECT id, version, steps_json, timeout_ms, schedule_cron, on_fail_json, created_at
             FROM kode_lowcode_workflow_versions
             WHERE workflow_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ?",
            [$wfId, $limit]
        );

        if (empty($versions)) {
            return $this->ok([
                'workflow' => $workflow,
                'current_version' => $wfRow[0]['version'] ?? '',
                'versions' => [],
                'diff_summary' => [
                    'total_versions' => $totalVersions,
                    'total_step_changes' => 0,
                    'total_config_changes' => 0,
                    'max_change_version' => '',
                    'max_change_count' => 0,
                ],
                'timeline' => [],
                'scanned_versions' => 0,
                'truncated' => false,
            ]);
        }

        // 对比相邻版本
        $versionList = [];
        $totalStepChanges = 0;
        $totalConfigChanges = 0;
        $maxChangeVersion = '';
        $maxChangeCount = 0;

        for ($i = 0; $i < count($versions); $i++) {
            $cur = $versions[$i];
            $versionList[] = [
                'version' => (string) $cur['version'],
                'created_at' => (string) $cur['created_at'],
                'id' => \app\support\HashId::enc((int) $cur['id']),
            ];

            if ($i > 0) {
                $prev = $versions[$i - 1];
                $diff = $this->compareWorkflowVersions($cur, $prev);
                $versionList[count($versionList) - 1]['diff'] = $diff;
                $versionList[count($versionList) - 1]['change_count'] = count($diff['steps_added']) + count($diff['steps_removed']) + count($diff['steps_modified']) + count($diff['config_changes']);

                $totalStepChanges += count($diff['steps_added']) + count($diff['steps_removed']) + count($diff['steps_modified']);
                $totalConfigChanges += count($diff['config_changes']);

                if ($versionList[count($versionList) - 1]['change_count'] > $maxChangeCount) {
                    $maxChangeCount = $versionList[count($versionList) - 1]['change_count'];
                    $maxChangeVersion = $diff['version'];
                }
            } else {
                $versionList[0]['diff'] = null;
                $versionList[0]['change_count'] = 0;
            }
        }

        // 构建时间线
        $timeline = [];
        foreach (array_reverse($versionList) as $v) {
            $timeline[] = [
                'version' => $v['version'],
                'time' => $v['created_at'],
                'change_count' => $v['change_count'] ?? 0,
            ];
        }

        return $this->ok([
            'workflow' => $workflow,
            'current_version' => $wfRow[0]['version'] ?? '',
            'versions' => $versionList,
            'diff_summary' => [
                'total_versions' => $totalVersions,
                'total_step_changes' => $totalStepChanges,
                'total_config_changes' => $totalConfigChanges,
                'max_change_version' => $maxChangeVersion,
                'max_change_count' => $maxChangeCount,
            ],
            'timeline' => $timeline,
            // 相邻版本的差异只能在 PHP 里算（`compareWorkflowVersions` 一份判据），所以抽样是允许的；
            // 但抽样规模必须说出来 —— 少了这两个键，「共 37 个版本只比了最近 20 个」
            // 与「一共 20 个版本」在页面上长得一样，而后者是可以被照着做决策的。
            'scanned_versions' => count($versionList),
            'truncated' => count($versionList) < $totalVersions,
        ]);
    }

    /**
     * 对比两个版本的差异。
     */
    private function compareWorkflowVersions(array $cur, array $prev): array
    {
        $diff = [
            'version' => (string) $cur['version'],
            'steps_added' => [],
            'steps_removed' => [],
            'steps_modified' => [],
            'config_changes' => [],
        ];

        // 步骤对比
        $curSteps = json_decode($cur['steps_json'] ?? '[]', true) ?? [];
        $prevSteps = json_decode($prev['steps_json'] ?? '[]', true) ?? [];

        $curStepNames = array_map(fn($s) => (string) ($s['name'] ?? $s['step'] ?? ''), $curSteps);
        $prevStepNames = array_map(fn($s) => (string) ($s['name'] ?? $s['step'] ?? ''), $prevSteps);

        $added = array_diff($curStepNames, $prevStepNames);
        $removed = array_diff($prevStepNames, $curStepNames);
        $common = array_intersect($curStepNames, $prevStepNames);

        foreach ($added as $name) {
            $diff['steps_added'][] = (string) $name;
        }
        foreach ($removed as $name) {
            $diff['steps_removed'][] = (string) $name;
        }

        // 修改检测
        foreach ($common as $name) {
            $curStep = null;
            $prevStep = null;
            foreach ($curSteps as $s) {
                if ((string) ($s['name'] ?? $s['step'] ?? '') === $name) { $curStep = $s; break; }
            }
            foreach ($prevSteps as $s) {
                if ((string) ($s['name'] ?? $s['step'] ?? '') === $name) { $prevStep = $s; break; }
            }
            if ($curStep && $prevStep && json_encode($curStep) !== json_encode($prevStep)) {
                $diff['steps_modified'][] = (string) $name;
            }
        }

        // 配置对比
        $configFields = ['timeout_ms', 'schedule_cron', 'on_fail_json'];
        foreach ($configFields as $field) {
            $curVal = $cur[$field] ?? '';
            $prevVal = $prev[$field] ?? '';
            if ($curVal !== $prevVal) {
                $diff['config_changes'][] = [
                    'field' => $field,
                    'from' => $prevVal,
                    'to' => $curVal,
                ];
            }
        }

        return $diff;
    }

    /* ===================== Round 39: 执行幂等性验证 ===================== */

    /**
     * GET /api/workflow/idempotency-check
     * 验证幂等键有效性和重复执行检测。
     *
     * 全部计数与分组都算在**窗口全集**上（SQL 侧 `COUNT(*) FILTER` + `GROUP BY ... HAVING COUNT(*) > 1`）：
     * 这一族其它入口按行抽样是允许的，因为判据只在 PHP 有一份；而「同一个键出现了几次」是纯 SQL 的
     * 分组计数，且**按行截断会把一个重复组分在边界上切成两半** —— 于是「两条执行共用一个幂等键」
     * 这个必须被发现的事会因为截断而报成「没有」。下沉进 SQL 同时消掉了那条 `input_json`
     * （这份判据从来没读过它，却按整列 TEXT 把它取了回来）。
     *
     * 参数：days(1-30, 默认7), workflow(可选), limit(默认20，只切三份**列表**，不切任何计数)
     */
    public function idempotencyCheck()
    {
        $days = (int) Request::input('days', 7);
        $days = max(1, min(30, $days));
        $workflow = Request::input('workflow', null);
        $limit = max(1, min(100, (int) Request::input('limit', 20)));

        $where = ['created_at >= NOW() - ?::interval'];
        $bindings = ["{$days} days"];
        if ($workflow !== null && $workflow !== '') {
            $where[] = 'workflow_name = ?';
            $bindings[] = (string) $workflow;
        }
        // 一份 WHERE 喂全部五条腿（各写一遍就会漂 —— webhookDlq 的「筛了 A 的列表、排行还数着 B」即此）。
        // `$wherePlain` 是给「前面已经有自己的 WHERE」的腿用的（成员明细那条按 IN 打头）。
        $wherePlain = implode(' AND ', $where);
        $whereSql = 'WHERE ' . $wherePlain;
        // 「带键的行」= 同一条窗口条件再加一格，只定义一次：分组腿与畸形键清单腿都读它。
        $keyedWhere = $whereSql . " AND idempotency_key <> ''";

        // 腿 1：窗口全集的五项计数。`!~ ?` 在文本上排在窗口绑定之前，所以参数表把正则放首位。
        $totals = (array) (Db::select(
            "SELECT COUNT(*) AS total_executions,
                    COUNT(*) FILTER (WHERE idempotency_key = '') AS missing_key,
                    COUNT(*) FILTER (WHERE idempotency_key <> '' AND idempotency_key !~ ?) AS format_invalid,
                    COUNT(*) FILTER (WHERE idempotency_key <> '') AS with_key,
                    COUNT(DISTINCT NULLIF(idempotency_key, '')) AS distinct_keys
             FROM kode_lowcode_workflow_executions {$whereSql}",
            array_merge([self::IDEMPOTENCY_KEY_FORMAT], $bindings)
        )[0] ?? []);
        $window = (int) ($totals['total_executions'] ?? 0);
        $distinctKeys = (int) ($totals['distinct_keys'] ?? 0);

        // 腿 2：重复组。窗口函数在**内层分组之后、外层 LIMIT 之前**求值，
        // 所以 `duplicate_groups` / `conflict_groups` 是整个窗口的组数，`LIMIT` 只切明细列表
        // （旧写法数的是进 PHP 的那一批，一枚 `limit=1` 就能把「有重复」压成「没有重复」）。
        $groups = Db::select(
            "SELECT key, executions, status_kinds,
                    COUNT(*) OVER () AS duplicate_groups,
                    SUM(CASE WHEN status_kinds > 1 THEN 1 ELSE 0 END) OVER () AS conflict_groups
             FROM (
                SELECT idempotency_key AS key, COUNT(*) AS executions,
                       COUNT(DISTINCT status) AS status_kinds
                FROM kode_lowcode_workflow_executions {$keyedWhere}
                GROUP BY idempotency_key
                HAVING COUNT(*) > 1
             ) g
             ORDER BY executions DESC, key
             LIMIT " . $limit,
            $bindings
        );
        $duplicateGroups = (int) ($groups[0]['duplicate_groups'] ?? 0);
        $conflictGroups = (int) ($groups[0]['conflict_groups'] ?? 0);

        // 腿 3：上榜那些组的成员明细（每组最多 IDEMPOTENCY_MEMBERS_PER_GROUP 条）。
        $membersByGroup = [];
        if ($groups !== []) {
            $keys = array_values(array_map(fn($g) => (string) $g['key'], $groups));
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $memberRows = Db::select(
                "SELECT key, id, workflow_name, status, created_at FROM (
                    SELECT e.idempotency_key AS key, e.id, e.workflow_name, e.status, e.created_at,
                           row_number() OVER (PARTITION BY e.idempotency_key ORDER BY e.id) AS rn
                    FROM kode_lowcode_workflow_executions e
                    WHERE e.idempotency_key IN ({$placeholders}) AND {$wherePlain}
                ) m WHERE m.rn <= " . self::IDEMPOTENCY_MEMBERS_PER_GROUP . ' ORDER BY m.key, m.rn',
                array_merge($keys, $bindings)
            );
            foreach ($memberRows as $m) {
                $membersByGroup[(string) $m['key']][] = $m;
            }
        }

        $duplicates = [];
        $conflicts = [];
        foreach ($groups as $group) {
            $key = (string) $group['key'];
            $members = $membersByGroup[$key] ?? [];
            $executions = (int) $group['executions'];
            // 一致性看 `status_kinds`（整个组的 SQL 计数），不看起来的这几条样本 ——
            // 组被截断时「样本里只有一种状态」不等于「全组状态一致」。
            $hasConflict = ((int) $group['status_kinds']) > 1;
            $dup = [
                'key' => $key,
                'executions' => $executions,
                'exec_ids' => array_map(fn($m) => \app\support\HashId::enc((int) $m['id']), $members),
                'members_truncated' => count($members) < $executions,
                'status_consistent' => !$hasConflict,
                'statuses' => array_values(array_unique(array_column($members, 'status'))),
                'workflows' => array_values(array_unique(array_column($members, 'workflow_name'))),
                'times' => array_values(array_map(fn($m) => (string) $m['created_at'], $members)),
            ];
            $duplicates[] = $dup;
            if ($hasConflict) {
                $conflicts[] = $dup;
            }
        }

        // 腿 4/5：两份清单。计数在腿 1，列表在这里，`limit` 只作用在这一层。
        $missingKey = array_map(fn($r) => [
            'exec_id' => \app\support\HashId::enc((int) $r['id']),
            'workflow' => (string) $r['workflow_name'],
            'time' => (string) $r['created_at'],
        ], Db::select(
            "SELECT id, workflow_name, created_at FROM kode_lowcode_workflow_executions
             {$whereSql} AND idempotency_key = ''
             ORDER BY id DESC LIMIT " . $limit,
            $bindings
        ));

        $formatInvalid = array_map(fn($r) => [
            'exec_id' => \app\support\HashId::enc((int) $r['id']),
            'workflow' => (string) $r['workflow_name'],
            'key' => (string) $r['idempotency_key'],
        ], Db::select(
            "SELECT id, workflow_name, idempotency_key FROM kode_lowcode_workflow_executions
             {$keyedWhere} AND idempotency_key !~ ?
             ORDER BY id DESC LIMIT " . $limit,
            array_merge($bindings, [self::IDEMPOTENCY_KEY_FORMAT])
        ));

        $summary = [
            'total_executions' => $window,
            'with_key' => (int) ($totals['with_key'] ?? 0),
            'missing_key' => (int) ($totals['missing_key'] ?? 0),
            'format_invalid' => (int) ($totals['format_invalid'] ?? 0),
            'duplicates' => $duplicateGroups,
            'conflicts' => $conflictGroups,
            // 与 `duplicates` 同一件事：「有几个键被重复使用」。旧写法这里回的是**去重后的键数**
            // （与 `with_key` 同值），于是顶部那张「重复键」卡片其实一直是「键的种类数」。
            'duplicate_keys' => $duplicateGroups,
            'duplicate_rate' => $distinctKeys > 0
                ? round($duplicateGroups / $distinctKeys * 100, 1)
                : 0,
        ];

        return $this->ok([
            'total_executions' => $window,
            'duplicates' => $duplicates,
            'missing_key' => $missingKey,
            'format_invalid' => $formatInvalid,
            'conflicts' => array_slice($conflicts, 0, 10),
            'summary' => $summary,
            'days' => $days,
            // 三份列表各自被 `limit` 切过，而上面的计数是整个窗口的口径 —— 不说这一格，
            // 「窗口里 137 组只列 20 组」和「一共 20 组」在页面上长得一样。
            'truncated' => $duplicateGroups > count($duplicates)
                || $summary['missing_key'] > count($missingKey)
                || $summary['format_invalid'] > count($formatInvalid),
        ]);
    }

    /* ===================== Round 40: 依赖健康度 ===================== */

    /**
     * GET /api/workflow/dependency-health
     * 监控子工作流和 API 调用的健康度。
     *
     * 参数：days(1-30, 默认7)
     */
    public function dependencyHealth()
    {
        $days = max(1, min(30, (int) Request::input('days', 7)));

        // 1. 收集所有工作流定义的依赖关系
        $allDefs = Db::select("SELECT name, steps_json FROM kode_lowcode_workflows WHERE status = 1");
        $dependencies = []; // key => ['type', 'target', 'callers' => []]

        foreach ($allDefs as $def) {
            $defName = (string) $def['name'];
            $steps = json_decode($def['steps_json'] ?? '[]', true) ?? [];
            $this->collectDependencies($steps, $dependencies, $defName);
        }

        // 2. 收集执行数据
        $scan = $this->scanExecutions(
            'SELECT workflow_name, step_results_json, status',
            "WHERE created_at >= NOW() - ?::interval AND step_results_json <> '{}' AND step_results_json IS NOT NULL",
            ["{$days} days"]
        );
        $rows = $scan['rows'];

        // 3. 统计每个依赖的健康指标
        $health = []; // key => metrics

        // 每个节点在**这一行执行记录**里占哪一格只由 `stepResultTree()` 判一次：旧写法只递归
        // 顶层那一格 `steps`（且带一枚 `$depth < 3` 的同款守卫），于是躲在 loop 轮次、
        // switch 分支与 on_fail 补偿里的那一枚子工作流调用 / 那一次 api_call **在这张健康榜上
        // 一个都不计** —— 页面说的是「这一枚依赖从没被调过」，而它每轮都在跑。
        $collectHealth = function (array $results) use (&$health): void {
            foreach ($this->stepResultTree($results) as $leaf) {
                $stepResult = $leaf['result'];
                // 「这一步是什么」只问 stepTypeOf()：这一处原先自己读 `_type`/`type` 两枚键再回落，
                // 而同一句话在执行轨迹与瓶颈榜那里各还有另一份读法。
                $stepType = self::stepTypeOf($stepResult);

                // 子工作流依赖。target 仍读节点自己那一格（`$leaf['workflow']` 是**外层**工作流：
                // 切换发生在下钻之前，用它会把调用方写成被调方）；name 为空时不记，
                // 因为那是一枚合成叶子（轮次超时行），压根不是一次调用。
                if ($stepType === 'sub_workflow' || $stepType === 'call_workflow' || (isset($stepResult['steps']) && is_array($stepResult['steps']))) {
                    $target = (string) ($stepResult['workflow'] ?? $stepResult['target'] ?? $leaf['name']);
                    if ($target !== '') {
                        $this->recordDepHealth($health, 'sub_workflow', $target, $stepResult);
                    }
                }

                // API 调用依赖
                if (isset($stepResult['http_code']) || isset($stepResult['data']) || isset($stepResult['url']) || isset($stepResult['endpoint'])) {
                    $url = (string) ($stepResult['url'] ?? $stepResult['target_url'] ?? $stepResult['endpoint'] ?? '');
                    if ($url !== '') {
                        $host = parse_url($url, PHP_URL_HOST) ?? 'local';
                        $path = parse_url($url, PHP_URL_PATH) ?? '';
                        $this->recordDepHealth($health, 'api_call', $host . $path, $stepResult);
                    } else {
                        // 无 URL 时这一格只是「哪一步在发请求」的标签，用点号路径而不是裸名：
                        // 两枚同名子步骤（不同分支里各一枚）此前会折进同一个桶、互摊成败与耗时。
                        $this->recordDepHealth($health, 'api_call', "[{$leaf['path']}]", $stepResult);
                    }
                }
            }
        };

        foreach ($rows as $row) {
            $steps = json_decode($row['step_results_json'] ?? '{}', true) ?? [];
            $collectHealth($steps);
        }

        // 4. 构建健康报告
        $reports = [];
        foreach ($health as $key => $h) {
            $total = $h['total'];
            $success = $h['success'];
            $failed = $h['failed'];
            $timeouts = $h['timeouts'];
            $successRate = $total > 0 ? round($success / $total * 100, 1) : 0;
            $avgMs = $total > 0 ? round($h['durations_sum'] / $total, 1) : 0;
            $timeoutRate = $total > 0 ? round($timeouts / $total * 100, 1) : 0;

            $speedScore = ($avgMs < 500) ? 30 : (($avgMs < 2000) ? 15 : (($avgMs < 5000) ? 5 : 0));
            $timeoutScore = ($timeoutRate < 5) ? 10 : (($timeoutRate < 20) ? 5 : 0);
            $healthScore = max(0, min(100, (int) (
                $successRate * 0.5
                + $speedScore
                + $timeoutScore
                + ($failed === 0 ? 10 : 0)
            )));

            $level = $healthScore >= 80 ? 'healthy' : ($healthScore >= 50 ? 'warning' : 'danger');

            $reports[] = [
                'type' => $h['type'],
                'target' => $h['target'],
                'total_calls' => $total,
                'success_calls' => $success,
                'failed_calls' => $failed,
                'timeout_calls' => $timeouts,
                'success_rate_pct' => $successRate,
                'avg_duration_ms' => $avgMs,
                'p95_duration_ms' => $h['p95'] ?? null,
                'max_duration_ms' => $h['durations_max'],
                'timeout_rate_pct' => $timeoutRate,
                'health_score' => $healthScore,
                'level' => $level,
                'callers' => array_values(array_unique($h['callers'])),
                'http_codes' => $h['http_codes'] ?? [],
            ];
        }

        usort($reports, fn($a, $b) => [$a['level'], $a['health_score']] <=> [$b['level'], $b['health_score']]);

        $healthyCount = count(array_filter($reports, fn($r) => $r['level'] === 'healthy'));
        $warningCount = count(array_filter($reports, fn($r) => $r['level'] === 'warning'));
        $dangerCount = count(array_filter($reports, fn($r) => $r['level'] === 'danger'));

        return $this->ok(array_merge([
            'days' => $days,
            'total_dependencies' => count($reports),
            'healthy' => $healthyCount,
            'warning' => $warningCount,
            'danger' => $dangerCount,
            'defined_dependencies' => count($dependencies),
            'dependencies' => $reports,
        ], $this->scanDeclared($scan)));
    }

    /**
     * 收集步骤定义中的依赖关系（深度由 `stepTree()` 管，配置只由 `config` 读）。
     *
     * 旧写法读的是步骤顶层的 `workflow`/`url`/`target`/`target_url`/`endpoint` —— 它们全都住在
     * `config` 里 ⇒ `$deps` 恒空，`dependency-health` 的「定义里的依赖」永远是 0，而同一份定义在
     * 环检测与依赖图里数得到调用（那是分岔的另一半）。`call_workflow` 也不是真实的步骤类型
     * （目录里只有 `sub_workflow`），留着就是一支恒不命中的分支。
     */
    private function collectDependencies(array $steps, array &$deps, string $caller): void
    {
        foreach ($this->stepTree($steps) as $leaf) {
            if ($leaf['type'] === 'sub_workflow') {
                $target = (string) ($leaf['config']['workflow'] ?? '');
                if ($target !== '') {
                    $key = "sub_workflow::{$target}";
                    if (!isset($deps[$key])) {
                        $deps[$key] = ['type' => 'sub_workflow', 'target' => $target, 'callers' => []];
                    }
                    $deps[$key]['callers'][] = $caller;
                }
                continue;
            }

            if ($leaf['type'] !== 'api_call') continue;

            $url = (string) ($leaf['config']['url'] ?? '');
            if ($url === '') continue;

            $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
            $key = "api_call::{$host}{$path}";
            if (!isset($deps[$key])) {
                $deps[$key] = ['type' => 'api_call', 'target' => $host . $path, 'callers' => []];
            }
            $deps[$key]['callers'][] = $caller;
        }
    }

    /**
     * 记录依赖的健康指标。
     */
    private function recordDepHealth(array &$health, string $type, string $target, array $stepResult): void
    {
        $key = "{$type}::{$target}";
        if (!isset($health[$key])) {
            $health[$key] = [
                'type' => $type,
                'target' => $target,
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'timeouts' => 0,
                'durations_sum' => 0,
                'durations_max' => 0,
                'durations_list' => [],
                'callers' => [],
                'http_codes' => [],
            ];
        }

        $h = &$health[$key];
        $h['total']++;

        [$success] = self::stepOutcome($stepResult);
        $durationMs = (int) ($stepResult['_duration_ms'] ?? 0);
        $httpCode = (int) ($stepResult['http_code'] ?? 0);

        $h['callers'][] = (string) ($stepResult['workflow'] ?? '');

        if ($httpCode > 0) {
            $h['http_codes'][$httpCode] = ($h['http_codes'][$httpCode] ?? 0) + 1;
        }

        if ($success) {
            $h['success']++;
        } else {
            $h['failed']++;
            if (stripos((string) ($stepResult['error'] ?? ''), 'timeout') !== false || $httpCode === 408) {
                $h['timeouts']++;
            }
        }

        if ($durationMs > 0) {
            $h['durations_sum'] += $durationMs;
            $h['durations_max'] = max($h['durations_max'], $durationMs);
            $h['durations_list'][] = $durationMs;
        }

        // 计算 P95（每 10 次刷新）
        if (count($h['durations_list']) > 0 && count($h['durations_list']) % 10 === 0) {
            sort($h['durations_list']);
            $p95Idx = (int) (count($h['durations_list']) * 0.95);
            $p95Idx = min($p95Idx, count($h['durations_list']) - 1);
            $h['p95'] = $h['durations_list'][$p95Idx];
        }

        unset($h);
    }

    /* ===================== Round 40: 性能瓶颈定位 ===================== */

    /**
     * GET /api/workflow/performance-bottleneck
     * 自动定位最慢的步骤，计算瓶颈分数。
     *
     * 参数：days(1-30, 默认7), workflow(可选), limit(默认20)
     */
    public function performanceBottleneck()
    {
        $days = max(1, min(30, (int) Request::input('days', 7)));
        $workflow = (string) Request::input('workflow', '');
        $limit = max(1, min(50, (int) Request::input('limit', 20)));

        $where = "WHERE created_at >= NOW() - ?::interval AND step_results_json <> '{}' AND step_results_json IS NOT NULL";
        $params = ["{$days} days"];
        if ($workflow !== '') {
            $where .= ' AND workflow_name = ?';
            $params[] = $workflow;
        }

        // 排序从 `ORDER BY id`（升序）改成默认的 DESC：截断时必须留下**最近**的那些执行，
        // 否则窗口一超预算，页面上印的就是「最古老的一千次」的性能结论。
        $scan = $this->scanExecutions(
            'SELECT workflow_name, status, step_results_json',
            $where,
            $params
        );
        $rows = $scan['rows'];

        // 聚合步骤耗时
        $stepData = [];
        $totalExecutions = 0;
        $totalDurationSum = 0;
        $failedExecutions = 0;

        // 步骤耗时按**点号路径**聚合，走法只由 `stepResultTree()` 判一次。旧写法只递归顶层那一格
        // `steps`（还带一枚 `$depth < 3` 的同款守卫），于是 loop 轮次、switch 分支与 on_fail 补偿里
        // 的那一步**从没进过这张瓶颈榜** —— 而这一榜的全部作用就是指出「哪一步最慢」；
        // loop / switch 的超时格也只有在这一刻第一次被数到（那两个容器的返回里压根没有 error 键）。
        // `$totalExecutions` / `$failedExecutions` / 执行级耗时恒是**每行执行记录一次**，
        // 所以它们跟着取行那一圈走，而不是留在递归的 `$depth === 0` 分支里。
        foreach ($rows as $row) {
            $steps = json_decode($row['step_results_json'] ?? '{}', true) ?? [];
            $wfName = (string) $row['workflow_name'];
            $execDuration = 0;

            foreach ($this->stepResultTree($steps, '', $wfName) as $leaf) {
                $stepResult = $leaf['result'];
                $durationMs = (int) ($stepResult['_duration_ms'] ?? 0);
                $attempt = self::stepAttempts($stepResult);
                [$success] = self::stepOutcome($stepResult);
                $stepType = self::stepTypeOf($stepResult);

                if ($leaf['depth'] === 0) {
                    $execDuration += $durationMs;
                }

                $key = "{$leaf['workflow']}::{$leaf['path']}";
                if (!isset($stepData[$key])) {
                    $stepData[$key] = [
                        'workflow' => $leaf['workflow'],
                        'step_name' => $leaf['path'],
                        'step_type' => $stepType,
                        'total' => 0,
                        'failed' => 0,
                        'retried' => 0,
                        'durations_sum' => 0,
                        'durations_max' => 0,
                        'durations_list' => [],
                        'attempts_sum' => 0,
                    ];
                }

                $s = &$stepData[$key];
                $s['total']++;
                $s['durations_sum'] += $durationMs;
                $s['durations_max'] = max($s['durations_max'], $durationMs);
                $s['durations_list'][] = $durationMs;
                $s['attempts_sum'] += $attempt;

                if (!$success) {
                    $s['failed']++;
                }
                // 「这一步重试过吗」只问 stepRetried()：旧写法在这里自己写 `$attempt > 1`，
                // 于是瓶颈榜的「重试率」与告警历史/重试分析那两屏用的是三个不同的数 ——
                // 而这三张卡在同一页并排画给运维。
                if (self::stepRetried($stepResult)) {
                    $s['retried']++;
                }
                unset($s);
            }

            $totalExecutions++;
            $totalDurationSum += $execDuration;
            if ((string) $row['status'] !== 'succeeded') {
                $failedExecutions++;
            }
        }

        // 构建瓶颈报告
        $avgExecDuration = $totalExecutions > 0 ? $totalDurationSum / $totalExecutions : 0;
        $results = [];

        foreach ($stepData as $step) {
            $avgMs = $step['total'] > 0 ? round($step['durations_sum'] / $step['total'], 1) : 0;
            $failRate = $step['total'] > 0 ? $step['failed'] / $step['total'] : 0;
            $retryRate = $step['total'] > 0 ? $step['retried'] / $step['total'] : 0;

            // 瓶颈分数 = 耗时占比 × 30 + 失败率 × 40 + 重试率 × 30
            $durationRatio = $avgExecDuration > 0 ? min(1.0, $avgMs / $avgExecDuration) : 0;
            $bottleneckScore = round(
                $durationRatio * 30 + $failRate * 40 + $retryRate * 30,
                2
            );

            // P95 计算
            $p95Ms = null;
            if (!empty($step['durations_list'])) {
                sort($step['durations_list']);
                $p95Idx = (int) (count($step['durations_list']) * 0.95);
                $p95Idx = min($p95Idx, count($step['durations_list']) - 1);
                $p95Ms = $step['durations_list'][$p95Idx];
            }

            $results[] = [
                'workflow' => $step['workflow'],
                'step_name' => $step['step_name'],
                'step_type' => $step['step_type'],
                'total_executions' => $step['total'],
                'failed_executions' => $step['failed'],
                'fail_rate_pct' => round($failRate * 100, 1),
                'retried' => $step['retried'],
                'retry_rate_pct' => round($retryRate * 100, 1),
                'avg_duration_ms' => $avgMs,
                'max_duration_ms' => $step['durations_max'],
                'p95_duration_ms' => $p95Ms,
                'duration_ratio' => round($durationRatio * 100, 1),
                'bottleneck_score' => $bottleneckScore,
            ];
        }

        // 按瓶颈分数降序
        usort($results, fn($a, $b) => $b['bottleneck_score'] <=> $a['bottleneck_score']);

        // 分类
        $criticalBottlenecks = array_filter($results, fn($r) => $r['bottleneck_score'] >= 60);
        $warningBottlenecks = array_filter($results, fn($r) => $r['bottleneck_score'] >= 30 && $r['bottleneck_score'] < 60);

        // 优化建议
        $suggestions = [];
        foreach ($results as $r) {
            if ($r['bottleneck_score'] >= 60 && $r['fail_rate_pct'] > 30) {
                $suggestions[] = "步骤 {$r['workflow']}::{$r['step_name']} 失败率高({$r['fail_rate_pct']}%)，建议优化错误处理或增加重试策略";
            }
            if ($r['bottleneck_score'] >= 60 && $r['retry_rate_pct'] > 30) {
                $suggestions[] = "步骤 {$r['workflow']}::{$r['step_name']} 重试率高({$r['retry_rate_pct']}%)，建议检查依赖服务稳定性";
            }
            if ($r['bottleneck_score'] >= 60 && $r['avg_duration_ms'] > 5000) {
                $suggestions[] = "步骤 {$r['workflow']}::{$r['step_name']} 平均耗时 {$r['avg_duration_ms']}ms，建议拆分或异步化";
            }
        }

        // `total_executions` 与其余面板同口径 = 窗口全集；`failed_executions` /
        // `avg_execution_duration_ms` 是抽样侧的数（判据只在 PHP 有一份），
        // 所以三件套必须一起出 —— 少了声明，两者相除就成了假占比。
        return $this->ok(array_merge([
            'days' => $days,
            'total_executions' => $scan['window_rows'],
            'failed_executions' => $failedExecutions,
            'avg_execution_duration_ms' => round($avgExecDuration, 1),
            'total_steps' => count($results),
            'critical_bottlenecks' => count($criticalBottlenecks),
            'warning_bottlenecks' => count($warningBottlenecks),
            'suggestions' => $suggestions,
            'bottlenecks' => array_slice($results, 0, $limit),
        ], $this->scanDeclared($scan)));
    }

    /* ===================== 成本告警检查 ===================== */

    /**
     * 执行完成后检查成本告警阈值，超阈值发送告警。
     */
    private function checkCostAlerts(array $workflow, int $executionId, int $durationMs, int $memoryPeakKb, int $stepsFailed): void
    {
        $alertMemory = (int) ($workflow['alert_memory_kb'] ?? 0);
        $alertDuration = (int) ($workflow['alert_duration_ms'] ?? 0);
        $alertSteps = (int) ($workflow['alert_step_failures'] ?? 0);
        $alerts = [];

        if ($alertMemory > 0 && $memoryPeakKb > $alertMemory) {
            $alerts[] = "内存峰值 {$memoryPeakKb}KB 超阈值 {$alertMemory}KB";
        }
        if ($alertDuration > 0 && $durationMs > $alertDuration) {
            $alerts[] = "执行耗时 {$durationMs}ms 超阈值 {$alertDuration}ms";
        }
        if ($alertSteps > 0 && $stepsFailed > $alertSteps) {
            $alerts[] = "步骤失败数 {$stepsFailed} 超阈值 {$alertSteps}";
        }

        if (!empty($alerts)) {
            $this->sendAlert((int) $workflow['id'], [
                'id' => $executionId,
                'workflow' => $workflow['name'] ?? '',
                'status' => 'cost_alert',
                'duration_ms' => $durationMs,
                'error' => implode('; ', $alerts),
            ]);
        }
    }
}
