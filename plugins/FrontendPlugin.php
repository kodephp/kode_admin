<?php

declare(strict_types=1);

namespace Kode\Plugins;

use Kode\Plugins\frontend\GiteeReleaseController;
use Kode\Plugins\frontend\WorkflowBridgeController;
use app\admin\middleware\RequirePerm;
use app\concerns\AttemptGuard;
use app\support\AuditTrail;
use app\support\Clip;
use app\support\ClientIp;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Middleware\AuthMiddleware;
use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;
use Kode\Http\Request;
use Kode\Plugins\frontend\BuilderController;

/**
 * 前端壳（uni3-lowcode）运行时 + Builder CRUD 插件
 *
 * 对接 uni-app x 蒸汽模式渲染壳（uni3 前端工程）；其产物 preset 目录位置见 config/uni3.php。
 *
 * 暴露两类端点：
 *
 * 一、Runtime（前端 shell 消费，全部公开，无登录态）：
 *   GET  /api/plugins/frontend/manifest          → AppManifest（tabBar/主题/路由/页面清单）
 *   GET  /api/plugins/frontend/schema/{pageId}   → PageSchema（从 preset JSON 读）
 *   GET  /api/plugins/frontend/presets           → preset 索引（pageId/title/blocks 计数）
 *   POST /api/plugins/frontend/diagnostics       → 护栏回传（聚合上报）
 *
 * 二、Builder CRUD（admin-web 消费，挂 JWT 鉴权 + 权限门禁）：
 *   GET/POST  /api/builder/projects
 *   GET/PUT/DELETE /api/builder/projects/{id}
 *   GET/POST  /api/builder/projects/{id}/pages
 *   GET/PUT/DELETE /api/builder/pages/{id}
 *   POST      /api/builder/projects/{id}/build
 *   GET       /api/builder/projects/{id}/builds
 *   GET/POST  /api/lowcode/licenses
 *   GET       /api/lowcode/licenses/{id}
 *
 * 数据来源：uni3/backend/presets/*.json（55 份 preset）。
 * 路径可在 config/uni3.php 覆盖，默认硬编码本机绝对路径。
 */
final class FrontendPlugin implements PluginInterface
{
    use AttemptGuard;

    /** 护栏回传上报的计数前缀（`AttemptGuard` 的键名，改它等于清空现有预算）。 */
    private const DIAG_PREFIX = 'lowcode_diag';

    public function name(): string
    {
        return 'frontend';
    }

    public static function version(): string
    {
        return '1.2.0';
    }

    /** @return list<string> 插件所属表（备份提醒 + purge 清理依据） */
    public function tables(): array
    {
        return [
            'kode_lowcode_projects',
            'kode_lowcode_pages',
            'kode_lowcode_components',
            'kode_lowcode_build_jobs',
            'kode_lowcode_licenses',
            'kode_lowcode_workflows',
            'kode_lowcode_workflow_executions',
            'kode_lowcode_workflow_versions',
            'kode_lowcode_workflow_webhook_logs',
            'kode_lowcode_workflow_budgets',
            'kode_lowcode_workflow_alerts',
            'kode_lowcode_workflow_alert_rules',
            'kode_lowcode_workflow_sla_targets',
            'kode_lowcode_workflow_step_regressions',
            'kode_gitee_releases',
            'kode_gitee_downloads',
        ];
    }

    /**
     * 安装：建 16 张表（幂等，可重入）。
     *
     * 覆盖 Builder（projects/pages/components/build_jobs/licenses）、
     * 工作编排流（workflows/executions/versions/webhook_logs/budgets/alerts/alert_rules/sla_targets/step_regressions）、
     * 以及并入本插件的 GiteeRelease（gitee_releases/gitee_downloads）。
     * 契约红线：install() 建的每张表、controller 查的每张表，都必须出现在 tables() 里
     * （否则全新环境缺表 / uninstall purge 漏删），由 tests/PluginSchemaCoverageTest 把守。
     * 风格与 NoticePlugin::install 一致（CREATE TABLE IF NOT EXISTS + 索引）。
     */
    public function install(): void
    {
        // 1) 项目表：一个低代码项目（appid + edition + license）
        Db::statement(
            "CREATE TABLE IF NOT EXISTS kode_lowcode_projects (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(128) NOT NULL,
                appid VARCHAR(64) NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT '',
                edition VARCHAR(24) NOT NULL DEFAULT 'standard',
                license_id VARCHAR(64) NOT NULL DEFAULT '',
                theme_key VARCHAR(64) NOT NULL DEFAULT 'light-clean',
                status SMALLINT NOT NULL DEFAULT 1,
                created_by VARCHAR(64) NOT NULL DEFAULT '',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );
        Db::statement("CREATE INDEX IF NOT EXISTS idx_kode_lowcode_projects_status ON kode_lowcode_projects (status)");
        Db::statement("CREATE INDEX IF NOT EXISTS idx_kode_lowcode_projects_edition ON kode_lowcode_projects (edition)");

        // 2) 页面表：项目下的页面 Schema
        Db::statement(
            "CREATE TABLE IF NOT EXISTS kode_lowcode_pages (
                id BIGSERIAL PRIMARY KEY,
                project_id BIGINT NOT NULL,
                page_id VARCHAR(64) NOT NULL,
                title VARCHAR(128) NOT NULL DEFAULT '',
                schema_json TEXT NOT NULL DEFAULT '{}',
                status SMALLINT NOT NULL DEFAULT 1,
                sort INT NOT NULL DEFAULT 0,
                created_by VARCHAR(64) NOT NULL DEFAULT '',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (project_id, page_id)
            )"
        );
        Db::statement("CREATE INDEX IF NOT EXISTS idx_kode_lowcode_pages_project ON kode_lowcode_pages (project_id, status)");

        // 3) 组件表：项目下的自定义 Block 组件
        Db::statement(
            "CREATE TABLE IF NOT EXISTS kode_lowcode_components (
                id BIGSERIAL PRIMARY KEY,
                project_id BIGINT NOT NULL,
                type VARCHAR(64) NOT NULL,
                name VARCHAR(128) NOT NULL DEFAULT '',
                schema_json TEXT NOT NULL DEFAULT '{}',
                category VARCHAR(32) NOT NULL DEFAULT 'layout',
                status SMALLINT NOT NULL DEFAULT 1,
                created_by VARCHAR(64) NOT NULL DEFAULT '',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE (project_id, type)
            )"
        );
        Db::statement("CREATE INDEX IF NOT EXISTS idx_kode_lowcode_components_project ON kode_lowcode_components (project_id, type)");

        // 4) 构建任务表
        Db::statement(
            "CREATE TABLE IF NOT EXISTS kode_lowcode_build_jobs (
                id BIGSERIAL PRIMARY KEY,
                project_id BIGINT NOT NULL,
                version VARCHAR(32) NOT NULL DEFAULT '2.0.0',
                status VARCHAR(24) NOT NULL DEFAULT 'queued',
                started_at TIMESTAMPTZ,
                finished_at TIMESTAMPTZ,
                artifact_url VARCHAR(512) NOT NULL DEFAULT '',
                error TEXT NOT NULL DEFAULT '',
                created_by VARCHAR(64) NOT NULL DEFAULT '',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );
        Db::statement("CREATE INDEX IF NOT EXISTS idx_kode_lowcode_build_jobs_project ON kode_lowcode_build_jobs (project_id, status)");

        // 5) License 表：授权（对应 COMMERCIALIZATION.md §4.2）
        Db::statement(
            "CREATE TABLE IF NOT EXISTS kode_lowcode_licenses (
                id BIGSERIAL PRIMARY KEY,
                license_id VARCHAR(64) NOT NULL UNIQUE,
                type VARCHAR(24) NOT NULL DEFAULT 'commercial',
                edition VARCHAR(24) NOT NULL DEFAULT 'standard',
                customer_name VARCHAR(128) NOT NULL DEFAULT '',
                customer_email VARCHAR(128) NOT NULL DEFAULT '',
                customer_contact VARCHAR(64) NOT NULL DEFAULT '',
                appids TEXT NOT NULL DEFAULT '[]',
                domains TEXT NOT NULL DEFAULT '[]',
                bundles TEXT NOT NULL DEFAULT '[]',
                features TEXT NOT NULL DEFAULT '[]',
                start_at DATE,
                end_at DATE,
                version VARCHAR(16) NOT NULL DEFAULT '2.x',
                status VARCHAR(24) NOT NULL DEFAULT 'active',
                signature TEXT NOT NULL DEFAULT '',
                issued_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );
        Db::statement("CREATE INDEX IF NOT EXISTS idx_kode_lowcode_licenses_status ON kode_lowcode_licenses (status)");
        Db::statement("CREATE INDEX IF NOT EXISTS idx_kode_lowcode_licenses_type ON kode_lowcode_licenses (type, status)");

        // 6) 工作流定义表（WorkflowBridge — Schema events → 后端业务流）
        Db::statement(
            "CREATE TABLE IF NOT EXISTS kode_lowcode_workflows (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(64) NOT NULL UNIQUE,
                description TEXT NOT NULL DEFAULT '',
                steps_json TEXT NOT NULL DEFAULT '[]',
                on_fail_json TEXT NOT NULL DEFAULT '[]',
                step_count INT NOT NULL DEFAULT 0,
                timeout_ms INT NOT NULL DEFAULT 30000,
                version VARCHAR(16) NOT NULL DEFAULT '1.0.0',
                status SMALLINT NOT NULL DEFAULT 1,
                created_by VARCHAR(64) NOT NULL DEFAULT '',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );
        Db::statement("CREATE INDEX IF NOT EXISTS idx_kode_lowcode_workflows_status ON kode_lowcode_workflows (status)");
        // 兼容旧表：添加 timeout_ms 列
        Db::statement("ALTER TABLE kode_lowcode_workflows ADD COLUMN IF NOT EXISTS timeout_ms INT NOT NULL DEFAULT 30000");
        // 兼容旧表：添加 on_fail_json 列（工作流级失败恢复步骤）
        Db::statement("ALTER TABLE kode_lowcode_workflows ADD COLUMN IF NOT EXISTS on_fail_json TEXT NOT NULL DEFAULT '[]'");
        // 兼容旧表：添加 schedule_cron 列（Cron 调度表达式，如 "0 9 * * *" = 每天 9 点）
        Db::statement("ALTER TABLE kode_lowcode_workflows ADD COLUMN IF NOT EXISTS schedule_cron VARCHAR(64) NOT NULL DEFAULT ''");
        // 兼容旧表：添加 next_run_at 列（下次执行时间）
        Db::statement("ALTER TABLE kode_lowcode_workflows ADD COLUMN IF NOT EXISTS next_run_at TIMESTAMPTZ NULL");
        // 兼容旧表：添加 last_run_at 列（上次执行时间）
        Db::statement("ALTER TABLE kode_lowcode_workflows ADD COLUMN IF NOT EXISTS last_run_at TIMESTAMPTZ NULL");
        // 兼容旧表：添加 notify_url 列（执行完成回调地址）
        Db::statement("ALTER TABLE kode_lowcode_workflows ADD COLUMN IF NOT EXISTS notify_url VARCHAR(512) NOT NULL DEFAULT ''");
        // 兼容旧表：添加 notify_secret 列（回调 HMAC 签名密钥）
        Db::statement("ALTER TABLE kode_lowcode_workflows ADD COLUMN IF NOT EXISTS notify_secret VARCHAR(128) NOT NULL DEFAULT ''");
        // 兼容旧表：添加 webhook_secret 列（Webhook 触发签名密钥）
        Db::statement("ALTER TABLE kode_lowcode_workflows ADD COLUMN IF NOT EXISTS webhook_secret VARCHAR(128) NOT NULL DEFAULT ''");
        // 兼容旧表：添加 webhook_ips 列（Webhook IP 白名单，逗号分隔）
        Db::statement("ALTER TABLE kode_lowcode_workflows ADD COLUMN IF NOT EXISTS webhook_ips VARCHAR(512) NOT NULL DEFAULT ''");
        // 兼容旧表：添加 max_concurrent 列（最大并发执行数，0 = 不限制）
        Db::statement("ALTER TABLE kode_lowcode_workflows ADD COLUMN IF NOT EXISTS max_concurrent INT NOT NULL DEFAULT 5");

        // 7) 工作流执行日志表
        Db::statement(
            "CREATE TABLE IF NOT EXISTS kode_lowcode_workflow_executions (
                id BIGSERIAL PRIMARY KEY,
                workflow_name VARCHAR(64) NOT NULL,
                workflow_id BIGINT NOT NULL DEFAULT 0,
                input_json TEXT NOT NULL DEFAULT '{}',
                step_results_json TEXT NOT NULL DEFAULT '{}',
                status VARCHAR(24) NOT NULL DEFAULT 'queued',
                step_count INT NOT NULL DEFAULT 0,
                duration_ms INT NOT NULL DEFAULT 0,
                error TEXT NOT NULL DEFAULT '',
                trace_id VARCHAR(64) NOT NULL DEFAULT '',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );
        Db::statement("CREATE INDEX IF NOT EXISTS idx_workflow_executions_wf ON kode_lowcode_workflow_executions (workflow_name, status)");
        Db::statement("CREATE INDEX IF NOT EXISTS idx_workflow_executions_created ON kode_lowcode_workflow_executions (created_at)");

        // 8) 工作流版本历史表
        Db::statement(
            "CREATE TABLE IF NOT EXISTS kode_lowcode_workflow_versions (
                id BIGSERIAL PRIMARY KEY,
                workflow_id BIGINT NOT NULL,
                workflow_name VARCHAR(64) NOT NULL,
                version VARCHAR(16) NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                steps_json TEXT NOT NULL,
                on_fail_json TEXT NOT NULL DEFAULT '[]',
                timeout_ms INT NOT NULL DEFAULT 30000,
                schedule_cron VARCHAR(64) NOT NULL DEFAULT '',
                created_by VARCHAR(64) NOT NULL DEFAULT '',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );
        Db::statement("CREATE INDEX IF NOT EXISTS idx_workflow_versions_wf ON kode_lowcode_workflow_versions (workflow_id, created_at DESC)");

        // 9) Webhook 投递日志表
        Db::statement(
            "CREATE TABLE IF NOT EXISTS kode_lowcode_workflow_webhook_logs (
                id BIGSERIAL PRIMARY KEY,
                workflow_id BIGINT NOT NULL,
                workflow_name VARCHAR(64) NOT NULL,
                execution_id BIGINT NOT NULL DEFAULT 0,
                target_url VARCHAR(512) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                http_code INT NOT NULL DEFAULT 0,
                duration_ms INT NOT NULL DEFAULT 0,
                error TEXT NOT NULL DEFAULT '',
                request_body TEXT NOT NULL DEFAULT '',
                response_body TEXT NOT NULL DEFAULT '',
                retry_count INT NOT NULL DEFAULT 0,
                max_retries INT NOT NULL DEFAULT 3,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );
        Db::statement("CREATE INDEX IF NOT EXISTS idx_webhook_logs_wf ON kode_lowcode_workflow_webhook_logs (workflow_id, created_at DESC)");
        Db::statement("CREATE INDEX IF NOT EXISTS idx_webhook_logs_exec ON kode_lowcode_workflow_webhook_logs (execution_id)");

        // 10) 工作流预算表（budgetList/budgetSave/budgetDelete + alert-check 读写）
        Db::statement(
            "CREATE TABLE IF NOT EXISTS kode_lowcode_workflow_budgets (
                id BIGSERIAL PRIMARY KEY,
                workflow_name VARCHAR(64) NOT NULL UNIQUE,
                daily_exec_limit INT NOT NULL DEFAULT 0,
                hourly_exec_limit INT NOT NULL DEFAULT 0,
                avg_duration_limit_ms INT NOT NULL DEFAULT 0,
                daily_api_limit INT NOT NULL DEFAULT 0,
                alert_enabled SMALLINT NOT NULL DEFAULT 1,
                alert_webhook_url TEXT NOT NULL DEFAULT '',
                alert_webhook_secret TEXT NOT NULL DEFAULT '',
                alert_cooldown_min INT NOT NULL DEFAULT 30,
                last_alert_at TIMESTAMPTZ NULL,
                escalation_threshold INT NOT NULL DEFAULT 3,
                consecutive_exceeded INT NOT NULL DEFAULT 0,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );
        Db::statement("CREATE INDEX IF NOT EXISTS idx_budgets_wf ON kode_lowcode_workflow_budgets (workflow_name)");

        // 11) 预算告警历史表（alertHistory 读、budgetAlertCheck/webhook 规则命中时写）
        Db::statement(
            "CREATE TABLE IF NOT EXISTS kode_lowcode_workflow_alerts (
                id BIGSERIAL PRIMARY KEY,
                workflow_name VARCHAR(64) NOT NULL,
                metric VARCHAR(48) NOT NULL,
                level VARCHAR(12) NOT NULL DEFAULT 'warning',
                current_value INT NOT NULL DEFAULT 0,
                limit_value INT NOT NULL DEFAULT 0,
                ratio NUMERIC NOT NULL DEFAULT 0,
                message TEXT NOT NULL DEFAULT '',
                webhook_status VARCHAR(24) NOT NULL DEFAULT '',
                webhook_http_code INT NOT NULL DEFAULT 0,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );
        Db::statement("CREATE INDEX IF NOT EXISTS idx_alerts_wf ON kode_lowcode_workflow_alerts (workflow_name, created_at)");
        Db::statement("CREATE INDEX IF NOT EXISTS idx_alerts_time ON kode_lowcode_workflow_alerts (created_at)");

        // 12) 告警规则表（alertRuleList/Save/Delete/Check 读写）
        Db::statement(
            "CREATE TABLE IF NOT EXISTS kode_lowcode_workflow_alert_rules (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(128) NOT NULL,
                workflow_name VARCHAR(64) DEFAULT '',
                rule_type VARCHAR(32) NOT NULL DEFAULT 'custom_metric',
                metric VARCHAR(32) NOT NULL,
                threshold NUMERIC NOT NULL DEFAULT 0,
                comparison VARCHAR(4) NOT NULL DEFAULT '<',
                enabled SMALLINT NOT NULL DEFAULT 1,
                notify_webhook TEXT DEFAULT '',
                cooldown_min INT NOT NULL DEFAULT 15,
                last_triggered_at TIMESTAMPTZ NULL,
                trigger_count INT NOT NULL DEFAULT 0,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );
        Db::statement("CREATE INDEX IF NOT EXISTS idx_alert_rules_enabled ON kode_lowcode_workflow_alert_rules (enabled)");
        Db::statement("CREATE INDEX IF NOT EXISTS idx_alert_rules_wf ON kode_lowcode_workflow_alert_rules (workflow_name)");

        // 13) SLA 目标表（slaTargetSave 写、slaReport/slaHistory 读）
        Db::statement(
            "CREATE TABLE IF NOT EXISTS kode_lowcode_workflow_sla_targets (
                id BIGSERIAL PRIMARY KEY,
                workflow_name VARCHAR(64) NOT NULL UNIQUE,
                target_success_rate NUMERIC NOT NULL DEFAULT 99.0,
                target_p95_ms INT NOT NULL DEFAULT 500,
                target_p50_ms INT NOT NULL DEFAULT 100,
                target_daily_min INT NOT NULL DEFAULT 0,
                enabled SMALLINT NOT NULL DEFAULT 1,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );
        Db::statement("CREATE INDEX IF NOT EXISTS idx_sla_wf ON kode_lowcode_workflow_sla_targets (workflow_name)");

        // 14) 步骤性能回归表（stepRegression 写、stepRegressionHistory 读）
        Db::statement(
            "CREATE TABLE IF NOT EXISTS kode_lowcode_workflow_step_regressions (
                id BIGSERIAL PRIMARY KEY,
                workflow_name VARCHAR(64) NOT NULL,
                step_name VARCHAR(128) NOT NULL,
                current_avg_ms NUMERIC NOT NULL DEFAULT 0,
                previous_avg_ms NUMERIC NOT NULL DEFAULT 0,
                change_pct NUMERIC NOT NULL DEFAULT 0,
                current_p95_ms NUMERIC NOT NULL DEFAULT 0,
                previous_p95_ms NUMERIC NOT NULL DEFAULT 0,
                current_count INT NOT NULL DEFAULT 0,
                previous_count INT NOT NULL DEFAULT 0,
                level VARCHAR(12) NOT NULL DEFAULT 'warning',
                detected_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );
        Db::statement("CREATE INDEX IF NOT EXISTS idx_regressions_wf ON kode_lowcode_workflow_step_regressions (workflow_name, detected_at DESC)");
        Db::statement("CREATE INDEX IF NOT EXISTS idx_regressions_time ON kode_lowcode_workflow_step_regressions (detected_at DESC)");

        // 15) Gitee 仓库配置表（本插件的 GiteeReleaseController 读写；归属已从 gitee-release
        //     并入 frontend，创建也必须在这里兜住，否则全新环境装完 frontend 仍缺这两张表）
        Db::statement(
            "CREATE TABLE IF NOT EXISTS kode_gitee_releases (
                id BIGSERIAL PRIMARY KEY,
                repo VARCHAR(128) NOT NULL UNIQUE,
                token VARCHAR(256) NOT NULL DEFAULT '',
                branch VARCHAR(64) NOT NULL DEFAULT 'master',
                name VARCHAR(128) NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT '',
                install_path VARCHAR(256) NOT NULL DEFAULT 'assets/lowcode-shell',
                status SMALLINT NOT NULL DEFAULT 1,
                last_version VARCHAR(32) NOT NULL DEFAULT '',
                last_checked_at TIMESTAMPTZ,
                created_by VARCHAR(64) NOT NULL DEFAULT '',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );
        Db::statement("CREATE INDEX IF NOT EXISTS idx_gitee_releases_status ON kode_gitee_releases (status)");

        // 16) Gitee 下载记录表
        Db::statement(
            "CREATE TABLE IF NOT EXISTS kode_gitee_downloads (
                id BIGSERIAL PRIMARY KEY,
                repo_id BIGINT NOT NULL,
                version VARCHAR(32) NOT NULL,
                asset_name VARCHAR(256) NOT NULL DEFAULT '',
                download_url VARCHAR(512) NOT NULL DEFAULT '',
                sha256 VARCHAR(64) NOT NULL DEFAULT '',
                expected_sha256 VARCHAR(64) NOT NULL DEFAULT '',
                status VARCHAR(24) NOT NULL DEFAULT 'queued',
                error TEXT NOT NULL DEFAULT '',
                installed_path VARCHAR(256) NOT NULL DEFAULT '',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                finished_at TIMESTAMPTZ
            )"
        );
        Db::statement("CREATE INDEX IF NOT EXISTS idx_gitee_downloads_repo ON kode_gitee_downloads (repo_id, status)");
        Db::statement("CREATE INDEX IF NOT EXISTS idx_gitee_downloads_version ON kode_gitee_downloads (repo_id, version)");

        // 幂等：给 projects 加 edition 索引（已有则跳过）
        Db::statement(
            "CREATE INDEX IF NOT EXISTS idx_kode_lowcode_projects_name ON kode_lowcode_projects (name)"
        );
    }

    /**
     * 卸载：默认只停用（数据保留）；$purge=true 时删表（调用方必须二次确认 + 备份）。
     */
    public function uninstall(bool $purge = false): void
    {
        if (!$purge) {
            return;
        }
        foreach ($this->tables() as $table) {
            if (!preg_match('/^[a-z0-9_]{2,64}$/', $table)) {
                continue;
            }
            Db::statement("DROP TABLE IF EXISTS {$table}");
        }
    }

    /**
     * 升级钩子（按版本差执行增量变更，幂等）。
     *
     * - v1.0.0 → v1.1.0：新增 5 张 Builder 表（projects/pages/components/build_jobs/licenses）
     * - v1.1.0 → v1.2.0：补齐工作流运维表（versions/webhook_logs/budgets/alerts/alert_rules/
     *   sla_targets/step_regressions）与并入本插件的 gitee 两张表——install() 现覆盖全部
     *   tables()，历史环境靠这一版把缺表建回来（CREATE IF NOT EXISTS，可重入）。
     */
    public function upgrade(string $from, string $to): void
    {
        // 任一旧版本升级都跑一次全量建表（幂等），把 install 补齐的新表建回来
        if (version_compare($from, '1.2.0', '<')) {
            $this->install();
        }
    }

    public function register(PluginManager $manager): void
    {
        // 一、Runtime 端点（前端 shell 消费，全部公开）
        $manager->addRoute('frontend.manifest', 'GET', '/api/plugins/frontend/manifest', [self::class, 'manifest']);
        $manager->addRoute('frontend.schema', 'GET', '/api/plugins/frontend/schema/{pageId}', [self::class, 'schema']);
        $manager->addRoute('frontend.presets', 'GET', '/api/plugins/frontend/presets', [self::class, 'presets']);
        $manager->addRoute('frontend.diagnostics', 'POST', '/api/plugins/frontend/diagnostics', [self::class, 'diagnostics']);

        // 二、Builder CRUD 端点（admin-web 消费，挂 JWT 鉴权 + 权限门禁）
        $auth = new AuthMiddleware();
        $view = new RequirePerm('lowcode:view');
        $edit = new RequirePerm('lowcode:edit');
        $publish = new RequirePerm('lowcode:publish');
        $license = new RequirePerm('lowcode:license');

        // 项目 CRUD
        $manager->addRoute('builder.projects.index', 'GET', '/api/builder/projects', [BuilderController::class, 'projectsIndex'])
            ->middleware($auth, $view);
        $manager->addRoute('builder.projects.show', 'GET', '/api/builder/projects/{id}', [BuilderController::class, 'projectShow'])
            ->middleware($auth, $view);
        $manager->addRoute('builder.projects.store', 'POST', '/api/builder/projects', [BuilderController::class, 'projectStore'])
            ->middleware($auth, $edit);
        $manager->addRoute('builder.projects.update', 'PUT', '/api/builder/projects/{id}', [BuilderController::class, 'projectUpdate'])
            ->middleware($auth, $edit);
        $manager->addRoute('builder.projects.destroy', 'DELETE', '/api/builder/projects/{id}', [BuilderController::class, 'projectDestroy'])
            ->middleware($auth, $edit);

        // 页面 CRUD
        $manager->addRoute('builder.pages.index', 'GET', '/api/builder/projects/{id}/pages', [BuilderController::class, 'pagesIndex'])
            ->middleware($auth, $view);
        $manager->addRoute('builder.pages.show', 'GET', '/api/builder/pages/{pageDbId}', [BuilderController::class, 'pageShow'])
            ->middleware($auth, $view);
        $manager->addRoute('builder.pages.store', 'POST', '/api/builder/projects/{id}/pages', [BuilderController::class, 'pageStore'])
            ->middleware($auth, $edit);
        $manager->addRoute('builder.pages.update', 'PUT', '/api/builder/pages/{pageDbId}', [BuilderController::class, 'pageUpdate'])
            ->middleware($auth, $edit);
        $manager->addRoute('builder.pages.destroy', 'DELETE', '/api/builder/pages/{pageDbId}', [BuilderController::class, 'pageDestroy'])
            ->middleware($auth, $edit);

        // 构建任务
        $manager->addRoute('builder.builds.index', 'GET', '/api/builder/projects/{id}/builds', [BuilderController::class, 'buildsIndex'])
            ->middleware($auth, $view);
        $manager->addRoute('builder.builds.store', 'POST', '/api/builder/projects/{id}/build', [BuilderController::class, 'buildStore'])
            ->middleware($auth, $publish);

        // License CRUD + 校验 + 密钥管理
        // 注意：verify 是前端 shell bootstrap 校验入口，用 view 权限（普通登录用户即可校验）
        $manager->addRoute('license.index', 'GET', '/api/lowcode/licenses', [BuilderController::class, 'licensesIndex'])
            ->middleware($auth, $license);
        $manager->addRoute('license.show', 'GET', '/api/lowcode/licenses/{id}', [BuilderController::class, 'licenseShow'])
            ->middleware($auth, $license);
        $manager->addRoute('license.store', 'POST', '/api/lowcode/licenses', [BuilderController::class, 'licenseStore'])
            ->middleware($auth, $license);
        $manager->addRoute('license.verify', 'POST', '/api/lowcode/licenses/verify', [BuilderController::class, 'licenseVerify'])
            ->middleware($auth, $view);
        $manager->addRoute('license.update', 'PUT', '/api/lowcode/licenses/{id}', [BuilderController::class, 'licenseUpdate'])
            ->middleware($auth, $license);
        $manager->addRoute('license.revoke', 'POST', '/api/lowcode/licenses/{id}/revoke', [BuilderController::class, 'licenseRevoke'])
            ->middleware($auth, $license);
        $manager->addRoute('license.activate', 'POST', '/api/lowcode/licenses/{id}/activate', [BuilderController::class, 'licenseActivate'])
            ->middleware($auth, $license);
        $manager->addRoute('license.keys.fingerprints', 'GET', '/api/lowcode/licenses/_keys/fingerprints', [BuilderController::class, 'licenseKeysFingerprints'])
            ->middleware($auth, $license);
        $manager->addRoute('license.keys.rotate', 'POST', '/api/lowcode/licenses/_keys/rotate', [BuilderController::class, 'licenseKeysRotate'])
            ->middleware($auth, $license);

        // Gitee Release：前端资产仓库（必须在 SPA fallback 之前）
        $giteeView = new RequirePerm('gitee:view');
        $giteeEdit = new RequirePerm('gitee:edit');
        $manager->addRoute('gitee.repos.index', 'GET', '/api/gitee-release/repos', [GiteeReleaseController::class, 'reposIndex'])
            ->middleware($auth, $giteeView);
        $manager->addRoute('gitee.repos.store', 'POST', '/api/gitee-release/repos', [GiteeReleaseController::class, 'repoStore'])
            ->middleware($auth, $giteeEdit);
        $manager->addRoute('gitee.repos.update', 'PUT', '/api/gitee-release/repos/{id}', [GiteeReleaseController::class, 'repoUpdate'])
            ->middleware($auth, $giteeEdit);
        $manager->addRoute('gitee.repos.destroy', 'DELETE', '/api/gitee-release/repos/{id}', [GiteeReleaseController::class, 'repoDestroy'])
            ->middleware($auth, $giteeEdit);
        $manager->addRoute('gitee.releases.list', 'GET', '/api/gitee-release/repos/{id}/releases', [GiteeReleaseController::class, 'releasesList'])
            ->middleware($auth, $giteeView);
        $manager->addRoute('gitee.releases.check', 'GET', '/api/gitee-release/repos/{id}/check-updates', [GiteeReleaseController::class, 'checkUpdates'])
            ->middleware($auth, $giteeView);
        $manager->addRoute('gitee.downloads.list', 'GET', '/api/gitee-release/repos/{id}/downloads', [GiteeReleaseController::class, 'downloadsList'])
            ->middleware($auth, $giteeView);
        $manager->addRoute('gitee.downloads.install', 'POST', '/api/gitee-release/repos/{id}/install', [GiteeReleaseController::class, 'installRelease'])
            ->middleware($auth, $giteeEdit);
        $manager->addRoute('gitee.downloads.test', 'POST', '/api/gitee-release/repos/{id}/test', [GiteeReleaseController::class, 'testConnection'])
            ->middleware($auth, $giteeEdit);
        $manager->addRoute('gitee.versions.installed', 'GET', '/api/gitee-release/versions', [GiteeReleaseController::class, 'installedVersions'])
            ->middleware($auth, $giteeView);

        // WorkflowBridge：工作编排流（Schema events → 后端业务流）
        // Admin CRUD（挂 JWT 鉴权 + 权限门禁）
        $workflowView = new RequirePerm('workflow:view');
        $workflowEdit = new RequirePerm('workflow:edit');

        // 执行入口可落库/调外部 API，挂 JWT + workflow:edit（admin-web 试运行/事件链均带 token）
        $manager->addRoute('workflow.execute', 'POST', '/api/workflow/execute', [WorkflowBridgeController::class, 'execute'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.definitions.index', 'GET', '/api/workflow/definitions', [WorkflowBridgeController::class, 'definitionsIndex'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.definitions.show', 'GET', '/api/workflow/definitions/{id}', [WorkflowBridgeController::class, 'definitionShow'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.definitions.store', 'POST', '/api/workflow/definitions', [WorkflowBridgeController::class, 'definitionStore'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.definitions.update', 'PUT', '/api/workflow/definitions/{id}', [WorkflowBridgeController::class, 'definitionUpdate'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.definitions.destroy', 'DELETE', '/api/workflow/definitions/{id}', [WorkflowBridgeController::class, 'definitionDestroy'])
            ->middleware($auth, $workflowEdit);
        // 工作流模板库
        $manager->addRoute('workflow.templates.index', 'GET', '/api/workflow/templates', [WorkflowBridgeController::class, 'templatesList'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.templates.show', 'GET', '/api/workflow/templates/{name}', [WorkflowBridgeController::class, 'templateShow'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.templates.instantiate', 'POST', '/api/workflow/templates/{name}/instantiate', [WorkflowBridgeController::class, 'templateInstantiate'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.executions.list', 'GET', '/api/workflow/executions', [WorkflowBridgeController::class, 'executionsList'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.executions.show', 'GET', '/api/workflow/executions/{id}', [WorkflowBridgeController::class, 'executionShow'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.executions.replay', 'POST', '/api/workflow/executions/{id}/replay', [WorkflowBridgeController::class, 'executionReplay'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.analytics', 'GET', '/api/workflow/analytics', [WorkflowBridgeController::class, 'analytics'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.definitions.export', 'GET', '/api/workflow/definitions/{id}/export', [WorkflowBridgeController::class, 'definitionExport'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.definitions.import', 'POST', '/api/workflow/definitions/import', [WorkflowBridgeController::class, 'definitionImport'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.scheduled.list', 'GET', '/api/workflow/scheduled', [WorkflowBridgeController::class, 'scheduledList'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.scheduled.runDue', 'POST', '/api/workflow/scheduled/run-due', [WorkflowBridgeController::class, 'scheduledRunDue'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.executions.export', 'GET', '/api/workflow/executions/export', [WorkflowBridgeController::class, 'executionsExport'])
            ->middleware($auth, $workflowView);
        // Webhook 触发器（公开，外部系统调用，HMAC 签名验证）
        $manager->addRoute('workflow.webhook.trigger', 'POST', '/api/workflow/webhook/{name}', [WorkflowBridgeController::class, 'webhookTrigger']);
        // 工作流版本历史
        $manager->addRoute('workflow.versions.list', 'GET', '/api/workflow/definitions/{id}/versions', [WorkflowBridgeController::class, 'versionList'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.versions.rollback', 'POST', '/api/workflow/definitions/{id}/versions/{version}/rollback', [WorkflowBridgeController::class, 'versionRollback'])
            ->middleware($auth, $workflowEdit);
        // 版本对比（任意两版本差异）
        $manager->addRoute('workflow.versions.compare', 'GET', '/api/workflow/definitions/{id}/versions/compare', [WorkflowBridgeController::class, 'versionCompare'])
            ->middleware($auth, $workflowView);
        // 定时补跑检测 + 补偿
        $manager->addRoute('workflow.scheduled.missed', 'GET', '/api/workflow/scheduled/missed', [WorkflowBridgeController::class, 'scheduledMissed'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.scheduled.compensate', 'POST', '/api/workflow/scheduled/compensate', [WorkflowBridgeController::class, 'scheduledCompensate'])
            ->middleware($auth, $workflowEdit);
        // Webhook 投递日志
        $manager->addRoute('workflow.webhook.logs', 'GET', '/api/workflow/webhook-logs', [WorkflowBridgeController::class, 'webhookLogs'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.webhook.logShow', 'GET', '/api/workflow/webhook-logs/{id}', [WorkflowBridgeController::class, 'webhookLogShow'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.webhook.logRetry', 'POST', '/api/workflow/webhook-logs/{id}/retry', [WorkflowBridgeController::class, 'webhookLogRetry'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.webhook.retryNow', 'POST', '/api/workflow/webhook-logs/retry-now', [WorkflowBridgeController::class, 'webhookLogRetryNow'])
            ->middleware($auth, $workflowEdit);
        // Webhook 签名生成工具 + 工作流健康检查
        $manager->addRoute('workflow.webhook.signature', 'POST', '/api/workflow/webhook-signature/{name}', [WorkflowBridgeController::class, 'webhookSignature'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.health', 'GET', '/api/workflow/health', [WorkflowBridgeController::class, 'health'])
            ->middleware($auth, $workflowView);

        // Webhook 仪表板 + 指数退避自动重试 + 历史清理
        $manager->addRoute('workflow.webhook.dashboard', 'GET', '/api/workflow/webhook-dashboard', [WorkflowBridgeController::class, 'webhookDashboard'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.webhook.autoRetry', 'POST', '/api/workflow/webhook-logs/auto-retry', [WorkflowBridgeController::class, 'autoRetry'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.executions.prune', 'POST', '/api/workflow/executions/prune', [WorkflowBridgeController::class, 'pruneExecutions'])
            ->middleware($auth, $workflowEdit);

        // 沙箱执行 + 依赖图
        $manager->addRoute('workflow.execute.sandbox', 'POST', '/api/workflow/execute/sandbox', [WorkflowBridgeController::class, 'executeSandbox'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.dependencies', 'GET', '/api/workflow/dependencies', [WorkflowBridgeController::class, 'dependenciesGraph'])
            ->middleware($auth, $workflowView);

        // 工作流克隆 + Webhook 连通性测试
        $manager->addRoute('workflow.definitions.clone', 'POST', '/api/workflow/definitions/{id}/clone', [WorkflowBridgeController::class, 'definitionClone'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.webhook.test', 'POST', '/api/workflow/webhook/test', [WorkflowBridgeController::class, 'webhookTest'])
            ->middleware($auth, $workflowEdit);

        // 批量操作 + 执行取消 + 签名生成增强
        $manager->addRoute('workflow.definitions.batch', 'POST', '/api/workflow/definitions/batch', [WorkflowBridgeController::class, 'definitionBatch'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.executions.cancel', 'POST', '/api/workflow/executions/{id}/cancel', [WorkflowBridgeController::class, 'executionCancel'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.webhook.signature.generate', 'POST', '/api/workflow/webhook-signature/generate', [WorkflowBridgeController::class, 'webhookSignatureGenerate'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.executions.retry-params', 'POST', '/api/workflow/executions/{id}/retry-params', [WorkflowBridgeController::class, 'executionRetryParams'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.executions.retry-from-step', 'POST', '/api/workflow/executions/{id}/retry-from-step', [WorkflowBridgeController::class, 'executionRetryFromStep'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.executions.retry-preview', 'GET', '/api/workflow/executions/{id}/retry-preview', [WorkflowBridgeController::class, 'executionRetryPreview'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.executions.batch-retry', 'POST', '/api/workflow/executions/batch-retry', [WorkflowBridgeController::class, 'batchRetryExecutions'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.step-stats', 'GET', '/api/workflow/step-stats', [WorkflowBridgeController::class, 'stepStats'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.duration-distribution', 'GET', '/api/workflow/duration-distribution', [WorkflowBridgeController::class, 'durationDistribution'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.failure-analysis', 'GET', '/api/workflow/failure-analysis', [WorkflowBridgeController::class, 'failureAnalysis'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.definitions.validate-refs', 'POST', '/api/workflow/definitions/validate-refs', [WorkflowBridgeController::class, 'validateStepRefs'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.executions.trace', 'GET', '/api/workflow/executions/{id}/trace', [WorkflowBridgeController::class, 'executionTrace'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.webhook-dlq', 'GET', '/api/workflow/webhook-dlq', [WorkflowBridgeController::class, 'webhookDlq'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.webhook-dlq.retry', 'POST', '/api/workflow/webhook-dlq/{id}/retry', [WorkflowBridgeController::class, 'webhookDlqRetry'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.webhook-dlq.drop', 'POST', '/api/workflow/webhook-dlq/{id}/drop', [WorkflowBridgeController::class, 'webhookDlqDrop'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.webhook-preview', 'POST', '/api/workflow/webhook-preview', [WorkflowBridgeController::class, 'webhookPreview'])
            ->middleware($auth, $workflowView);

        // Round 19: 熔断器 + 步骤健康
        $manager->addRoute('workflow.circuit-breaker', 'GET', '/api/workflow/circuit-breaker', [WorkflowBridgeController::class, 'circuitBreakerList'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.circuit-breaker.reset', 'POST', '/api/workflow/circuit-breaker/{id}/reset', [WorkflowBridgeController::class, 'circuitBreakerReset'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.step-health', 'GET', '/api/workflow/step-health', [WorkflowBridgeController::class, 'stepHealth'])
            ->middleware($auth, $workflowView);

        // Round 20: 依赖图（模板库路由已在上面的 `workflow.templates.index` 注册，
        // 这里曾重复挂过一条同 pattern 同处理器的 `workflow.templates` —— 静态表后注册者覆盖前者，
        // 前面那条当场变死路由，`kode route:list` 列两条、实际只活一条。见 tests/RouteReachabilityTest.php）
        $manager->addRoute('workflow.dependency-graph', 'GET', '/api/workflow/dependency-graph', [WorkflowBridgeController::class, 'dependencyGraph'])
            ->middleware($auth, $workflowView);

        // Round 21: 步骤类型元数据（前端可视化编辑器）
        $manager->addRoute('workflow.step-types', 'GET', '/api/workflow/step-types', [WorkflowBridgeController::class, 'stepTypes'])
            ->middleware($auth, $workflowView);

        // Round 23: 执行成本趋势
        $manager->addRoute('workflow.cost-trends', 'GET', '/api/workflow/cost-trends', [WorkflowBridgeController::class, 'costTrends'])
            ->middleware($auth, $workflowView);
        // Round 25: 按工作流分组的成本趋势
        $manager->addRoute('workflow.cost-trends-by-workflow', 'GET', '/api/workflow/cost-trends-by-workflow', [WorkflowBridgeController::class, 'costTrendsByWorkflow'])
            ->middleware($auth, $workflowView);
        // Round 26: 重试链追溯 + 步骤性能 + 预算
        $manager->addRoute('workflow.executions.retry-chain', 'GET', '/api/workflow/executions/{id}/retry-chain', [WorkflowBridgeController::class, 'executionRetryChain'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.step-performance', 'GET', '/api/workflow/step-performance', [WorkflowBridgeController::class, 'stepPerformance'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.budgets', 'GET', '/api/workflow/budgets', [WorkflowBridgeController::class, 'budgetList'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.budgets.alert-check', 'POST', '/api/workflow/budgets/alert-check', [WorkflowBridgeController::class, 'budgetAlertCheck'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.budgets.save', 'POST', '/api/workflow/budgets', [WorkflowBridgeController::class, 'budgetSave'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.budgets.delete', 'DELETE', '/api/workflow/budgets/{workflow}', [WorkflowBridgeController::class, 'budgetDelete'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.alerts', 'GET', '/api/workflow/alerts', [WorkflowBridgeController::class, 'alertHistory'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.step-regression', 'GET', '/api/workflow/step-regression', [WorkflowBridgeController::class, 'stepRegression'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.step-regressions', 'GET', '/api/workflow/step-regressions', [WorkflowBridgeController::class, 'stepRegressionHistory'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.executions.chain-resolve', 'POST', '/api/workflow/executions/chain-resolve', [WorkflowBridgeController::class, 'chainResolve'])
            ->middleware($auth, $workflowEdit);

        // Round 29: SLA 报表 + 目标配置
        $manager->addRoute('workflow.sla-report', 'GET', '/api/workflow/sla-report', [WorkflowBridgeController::class, 'slaReport'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.sla-targets', 'POST', '/api/workflow/sla-targets', [WorkflowBridgeController::class, 'slaTargetSave'])
            ->middleware($auth, $workflowEdit);

        // Round 30: 步骤回放 + 失败模式 + SLA 历史
        $manager->addRoute('workflow.executions.step-trace', 'GET', '/api/workflow/executions/{id}/step-trace', [WorkflowBridgeController::class, 'executionStepTrace'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.failure-patterns', 'GET', '/api/workflow/failure-patterns', [WorkflowBridgeController::class, 'failurePatterns'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.sla-history', 'GET', '/api/workflow/sla-history', [WorkflowBridgeController::class, 'slaHistory'])
            ->middleware($auth, $workflowView);

        // Round 31: 健康评分 + 重试分析 + Webhook 统计
        $manager->addRoute('workflow.health-scores', 'GET', '/api/workflow/health-scores', [WorkflowBridgeController::class, 'workflowHealthScore'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.step-retry-analysis', 'GET', '/api/workflow/step-retry-analysis', [WorkflowBridgeController::class, 'stepRetryAnalysis'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.webhook-delivery-stats', 'GET', '/api/workflow/webhook-delivery-stats', [WorkflowBridgeController::class, 'webhookDeliveryStats'])
            ->middleware($auth, $workflowView);

        // Round 32: 定时分析 + API 聚合 + 超时热点
        $manager->addRoute('workflow.schedule-analysis', 'GET', '/api/workflow/schedule-analysis', [WorkflowBridgeController::class, 'scheduleAnalysis'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.api-call-aggregation', 'GET', '/api/workflow/api-call-aggregation', [WorkflowBridgeController::class, 'apiCallAggregation'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.timeout-hotspots', 'GET', '/api/workflow/timeout-hotspots', [WorkflowBridgeController::class, 'timeoutHotspots'])
            ->middleware($auth, $workflowView);

        // Round 33: 步骤趋势 + 热力图 + 告警规则
        $manager->addRoute('workflow.step-trend', 'GET', '/api/workflow/step-trend', [WorkflowBridgeController::class, 'stepTrend'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.execution-heatmap', 'GET', '/api/workflow/execution-heatmap', [WorkflowBridgeController::class, 'executionHeatmap'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.alert-rules', 'GET', '/api/workflow/alert-rules', [WorkflowBridgeController::class, 'alertRuleList'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.alert-rules.save', 'POST', '/api/workflow/alert-rules', [WorkflowBridgeController::class, 'alertRuleSave'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.alert-rules.check', 'POST', '/api/workflow/alert-rules/check', [WorkflowBridgeController::class, 'alertRuleCheck'])
            ->middleware($auth, $workflowEdit);
        $manager->addRoute('workflow.alert-rules.delete', 'DELETE', '/api/workflow/alert-rules/{id}', [WorkflowBridgeController::class, 'alertRuleDelete'])
            ->middleware($auth, $workflowEdit);

        // Round 34: 关联分析 + 异常检测 + 执行时间线
        $manager->addRoute('workflow.correlation-analysis', 'GET', '/api/workflow/correlation-analysis', [WorkflowBridgeController::class, 'correlationAnalysis'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.anomaly-detection', 'GET', '/api/workflow/anomaly-detection', [WorkflowBridgeController::class, 'anomalyDetection'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.executions.timeline', 'GET', '/api/workflow/executions/{id}/timeline', [WorkflowBridgeController::class, 'executionTimeline'])
            ->middleware($auth, $workflowView);

        // Round 35: 执行路径挖掘 + 输入参数画像 + 容量规划预测
        $manager->addRoute('workflow.path-mining', 'GET', '/api/workflow/path-mining', [WorkflowBridgeController::class, 'pathMining'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.input-profile', 'GET', '/api/workflow/input-profile', [WorkflowBridgeController::class, 'inputProfile'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.capacity-forecast', 'GET', '/api/workflow/capacity-forecast', [WorkflowBridgeController::class, 'capacityForecast'])
            ->middleware($auth, $workflowView);

        // Round 36: 数据血缘追踪 + 执行模式聚类 + 工作流健康趋势
        $manager->addRoute('workflow.data-lineage', 'GET', '/api/workflow/data-lineage', [WorkflowBridgeController::class, 'dataLineage'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.execution-clustering', 'GET', '/api/workflow/execution-clustering', [WorkflowBridgeController::class, 'executionClustering'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.health-trend', 'GET', '/api/workflow/health-trend', [WorkflowBridgeController::class, 'healthTrend'])
            ->middleware($auth, $workflowView);

        // Round 37: 输入参数验证 + 配置审计 + 执行成本分摊
        $manager->addRoute('workflow.input-validation', 'GET', '/api/workflow/input-validation', [WorkflowBridgeController::class, 'inputValidation'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.config-audit', 'GET', '/api/workflow/config-audit', [WorkflowBridgeController::class, 'configAudit'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.cost-allocation', 'GET', '/api/workflow/cost-allocation', [WorkflowBridgeController::class, 'costAllocation'])
            ->middleware($auth, $workflowView);

        // Round 38: 安全审计 + 依赖环检测 + 执行回放对比
        $manager->addRoute('workflow.security-audit', 'GET', '/api/workflow/security-audit', [WorkflowBridgeController::class, 'securityAudit'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.cycle-detection', 'GET', '/api/workflow/cycle-detection', [WorkflowBridgeController::class, 'cycleDetection'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.replay-comparison', 'GET', '/api/workflow/replay-comparison', [WorkflowBridgeController::class, 'replayComparison'])
            ->middleware($auth, $workflowView);

        // Round 39: 变更影响分析 + 配置变更历史 + 执行幂等性验证
        $manager->addRoute('workflow.change-impact', 'GET', '/api/workflow/change-impact', [WorkflowBridgeController::class, 'changeImpact'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.config-history', 'GET', '/api/workflow/config-history', [WorkflowBridgeController::class, 'configHistory'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.idempotency-check', 'GET', '/api/workflow/idempotency-check', [WorkflowBridgeController::class, 'idempotencyCheck'])
            ->middleware($auth, $workflowView);

        // Round 40: 依赖健康度 + 性能瓶颈定位
        $manager->addRoute('workflow.dependency-health', 'GET', '/api/workflow/dependency-health', [WorkflowBridgeController::class, 'dependencyHealth'])
            ->middleware($auth, $workflowView);
        $manager->addRoute('workflow.performance-bottleneck', 'GET', '/api/workflow/performance-bottleneck', [WorkflowBridgeController::class, 'performanceBottleneck'])
            ->middleware($auth, $workflowView);

        // 三、静态资源服务（uni3-lowcode H5 构建产物）
        $manager->addRoute('frontend.static', 'GET', '/uni3-preview/{path}', [self::class, 'serveStatic']);

        // 四、Admin Web 静态资源（具体路径；兜底见 boot()）
        $manager->addRoute('admin.index', 'GET', '/', [self::class, 'serveStaticAdmin']);
        $manager->addRoute('admin.js', 'GET', '/js/{file}', [self::class, 'serveStaticAdmin']);
        $manager->addRoute('admin.css', 'GET', '/css/{file}', [self::class, 'serveStaticAdmin']);
        $manager->addRoute('admin.jse', 'GET', '/jse/{file}', [self::class, 'serveStaticAdmin']);
        $manager->addRoute('admin.static', 'GET', '/_app-config-{file}', [self::class, 'serveStaticAdmin']);
        // 兜底路由（API 404 + SPA fallback）不在这里注册，见 boot()：它们必须**最后**进路由表，
        // 而注册顺序由 `PluginManager::load()` 的两段式决定（全部 register() → 全部 boot()）。
    }

    /**
     * 兜底路由挂在 boot() 而不是 register()：这是结构性的，不是习惯问题。
     *
     * `Router::doMatch()` 对动态路由是**先到先得**（`vendor/kode/http` 的 `Router::doMatch()`
     * 先查静态表，再按注册顺序取第一个 `match()` 成功的），所以 `{path:.+}` 这种贪婪兜底
     * 一旦注册在任何人前面，后面所有参数化路由都会静默变成它的响应 —— 不报错、不告警，
     * `kode route:list` 照常列出。而 register() 里的顺序取决于 `config/plugins.php` 的数组位置，
     * `PluginScaffolder::registerPlugin()` 又是往数组末尾追加的：装一个新插件就可能把兜底挤到中间。
     * `load()` 保证「所有插件 register() 完才轮到 boot()」，兜底因此恒在插件路由之末
     * （应用侧 app/routes*.php 更早加载，静态表优先 + 动态先到先得，都不受影响）。
     * 回归见 tests/RouteReachabilityTest.php（逐条真实 dispatch）。
     */
    public function boot(PluginManager $manager): void
    {
        // 插件启动逻辑（预热 preset 缓存、可选：写 kode_audit_logs 记录 shell 启动）

        // API 未匹配路径 → 404 JSON（必须早于 SPA fallback，避免误回 index.html）
        // 前缀清单必须覆盖所有后端 API 前缀：漏掉 /c-api 时，C 端打错路径会拿到
        // 200 + index.html，客户端 JSON 解析才失败，故障现场极难定位。
        foreach (['/api', '/c-api'] as $apiPrefix) {
            foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $apiMethod) {
                $manager->addRoute(
                    'api.notfound.' . strtolower($apiMethod) . '.' . trim($apiPrefix, '/'),
                    $apiMethod,
                    $apiPrefix . '/{path:.+}',
                    [self::class, 'apiNotFound']
                );
            }
        }
        // SPA fallback: 匹配所有未匹配的前端路由（本方法末尾 = 整张表末尾）
        $manager->addRoute('admin.spa', 'GET', '/{path:.+}', [self::class, 'serveStaticAdmin']);
    }

    /**
     * 权限码声明（供权限目录按插件分组，角色勾选用）。
     * 契约红线：menus() 里出现的 auth_code 必须在这里声明。
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return ['lowcode:view', 'lowcode:edit', 'lowcode:publish', 'lowcode:license', 'gitee:view', 'gitee:edit', 'workflow:view', 'workflow:edit'];
    }

    /**
     * 菜单声明（供 MenuController 聚合，前端动态路由自动收录）。
     *
     * @return list<array{title: string, path: string, icon: string, auth_code?: string, parent?: string}>
     */
    public function menus(): array
    {
        return [
            [
                'title' => '低代码预览',
                'path' => '/lowcode-preview',
                'icon' => 'lucide:layout-template',
                'auth_code' => 'lowcode:view',
                'parent' => '/system',
            ],
            [
                'title' => '低代码 Builder',
                'path' => '/lowcode-builder',
                'icon' => 'lucide:hammer',
                'auth_code' => 'lowcode:view',
                'parent' => '/system',
            ],
            [
                'title' => '授权管理',
                'path' => '/lowcode-licenses',
                'icon' => 'lucide:key-round',
                'auth_code' => 'lowcode:license',
                'parent' => '/system',
            ],
            [
                'title' => '前端资产仓库',
                'path' => '/gitee-release',
                'icon' => 'lucide:github',
                'auth_code' => 'gitee:view',
                'parent' => '/system',
            ],
            [
                'title' => '工作编排流',
                'path' => '/workflow-bridge',
                'icon' => 'lucide:workflow',
                'auth_code' => 'workflow:view',
                'parent' => '/system',
            ],
        ];
    }

    /* ===================== 端点实现 ===================== */

    /**
     * GET /api/plugins/frontend/manifest
     * 返回 AppManifest（供 uni3 App.uvue bootstrap 消费）。
     */
    public static function manifest(): array
    {
        $presets = self::loadPresetIndex();
        $pages = self::buildPages($presets);

        // tabBar：从 preset 里挑 6 个高频页面做底部导航
        $tabBar = self::buildTabBar();

        return [
            'appId' => 'kode-lowcode',
            'name' => 'uni3-lowcode',
            'description' => 'WorkBuddy 低代码可视化前端壳 · 由 kode_test 后端下发',
            'logo' => '/favicon.ico',
            'theme' => self::defaultTheme(),
            'themes' => [
                self::defaultTheme(),
                self::darkTheme(),
            ],
            'version' => self::version(),
            'schemaVersion' => '2.0.0',
            'plugins' => [
                ['key' => 'theme', 'enabled' => true, 'category' => 'common', 'version' => '1.0.0'],
            ],
            'routes' => [
                ['path' => '/pages/index/index', 'pageId' => '__index__', 'title' => '通用页', 'tabBar' => false, 'requiresAuth' => false, 'keepAlive' => false],
            ],
            'pages' => $pages,
            'tabBar' => $tabBar,
            'sideMenu' => ['enabled' => false, 'items' => []],
            'interceptors' => [],
            'telemetry' => [
                'endpoint' => '/api/plugins/frontend/diagnostics',
                'sampleRate' => 0.2,
                'autoTrack' => ['pv', 'error'],
            ],
            'i18n' => [
                'default' => 'zh-CN',
                'locales' => ['zh-CN', 'en-US'],
                'resources' => [],
            ],
            'flags' => [
                ['key' => 'visualEditor', 'enabled' => true, 'description' => 'dev 期可视化编辑器'],
                ['key' => 'diagnostics', 'enabled' => true, 'description' => '护栏回传上报'],
            ],
            'permissions' => ['native' => [], 'business' => []],
            'splash' => ['image' => '', 'duration' => 0, 'skipOn' => 'coldStart'],
            'channel' => ['id' => 'stable', 'allowUsers' => [], 'minVersion' => '2.0.0'],
        ];
    }

    /**
     * GET /api/plugins/frontend/schema/{pageId}
     * 返回 PageSchema（JSON，从 uni3/backend/presets/{pageId}.json 读）。
     *
     * @param string $pageId 页面 ID（白名单字符集）
     */
    public static function schema(): array
    {
        $pageId = (string) Request::param('pageId', '');
        if (!preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $pageId)) {
            return ['code' => 400, 'msg' => 'invalid pageId', 'data' => null];
        }

        $path = self::presetDir() . DIRECTORY_SEPARATOR . $pageId . '.json';
        if (!is_file($path)) {
            return ['code' => 404, 'msg' => "schema not found: {$pageId}", 'data' => null];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return ['code' => 500, 'msg' => 'read failed', 'data' => null];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['code' => 500, 'msg' => 'invalid preset json', 'data' => null];
        }

        return ['code' => 0, 'msg' => 'ok', 'data' => $data];
    }

    /**
     * GET /api/plugins/frontend/presets
     * 返回 preset 索引（供前端做页面切换器）。
     */
    public static function presets(): array
    {
        $index = self::loadPresetIndex();
        $pages = $index['pages'] ?? [];

        // 排序：按 blocks 降序（高频页面优先）
        usort($pages, static fn($a, $b) => (($b['blocks'] ?? 0)) <=> (($a['blocks'] ?? 0)));

        return [
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'generatedAt' => $index['generatedAt'] ?? '',
                'total' => count($pages),
                'pages' => $pages,
            ],
        ];
    }

    /**
     * POST /api/plugins/frontend/diagnostics
     * 护栏回传上报（接收 schema/manifest/插件健康态报告）。
     *
     * 落库只走 `AuditTrail::record()`（§3：审计落库的唯一派发口），记的是**元数据**：
     * 审计表根本没有放请求体的列，而 §4 也禁止把请求体写进审计。上报的三项内容
     * 进应用日志（无 PII，且下面有按 IP 的预算兜着），这样「谁在报、报了几次」与
     * 「报的是什么」两条都留了痕。
     *
     * 这是一个「不验身份就能写库」的公开入口，所以按 §4 复用 `AttemptGuard` 并起独立前缀。
     * 身份键这里**故意与 IP 键同值**：匿名入口没有身份，若第二把键写成固定串，
     * 就成了全站共享的一个桶 —— 任何一枚客户端都能替所有人把这个端点锁死
     * （`AttemptGuard` 的双键设计是为登录入口挡 NAT，套到匿名入口上方向正好反过来）。
     */
    public static function diagnostics(): array
    {
        $started = microtime(true);

        try {
            $body = self::requestBody();
            // 公开端点，字段钳长度防匿名刷爆（按**字符**钳：这三个值马上要绑进 SQL，
            // 半个汉字就是 22021，匿名调用方一枚 body 就能打出 500）
            $source = Clip::chars((string) (is_array($body) ? ($body['source'] ?? 'shell') : 'shell'), 32);
            $schemaId = Clip::chars((string) (is_array($body) ? ($body['schemaId'] ?? '') : ''), 64);
            $manifestId = Clip::chars((string) (is_array($body) ? ($body['manifestId'] ?? '') : ''), 64);

            $receivedAt = date('Y-m-d H:i:s');
            $ip = ClientIp::resolve();

            // 静态方法里用不了 $this，而 AttemptGuard 的方法是 protected —— 同类内部对实例受保护
            // 成员有访问权，所以就地 new 一个，不为一个端点把 trait 的方法全改成 static。
            $guard = new self();
            if ($guard->tooManyAttempts(self::DIAG_PREFIX, $ip, $ip)) {
                return ['code' => 429, 'msg' => '过于频繁', 'data' => ['ok' => false]];
            }
            $guard->hitAttempt(self::DIAG_PREFIX, $ip, $ip);

            \Kode\Framework\Facades\Log::info(
                "低代码护栏回传：source={$source} schemaId={$schemaId} manifestId={$manifestId} trace_id=" . self::traceId()
            );

            $persisted = false;
            try {
                AuditTrail::record(
                    0,
                    'frontend-shell',
                    'POST',
                    '/api/plugins/frontend/diagnostics',
                    200,
                    (int) round((microtime(true) - $started) * 1000),
                    $ip
                );
                $persisted = true;
            } catch (\Throwable) {
                // 审计失败绝不影响主流程（§3），但这次不许再对着没落库的结果回「已收到」
            }

            return [
                'code' => 0,
                'msg' => 'ok',
                'data' => ['ok' => true, 'received_at' => $receivedAt, 'persisted' => $persisted],
            ];
        } catch (\Throwable) {
            // best-effort：恒 HTTP 200 + code:0 是已发布契约（§7），但不回任何异常原文
            return ['code' => 0, 'msg' => 'ok', 'data' => ['ok' => false, 'received_at' => date('Y-m-d H:i:s')]];
        }
    }

    /**
     * 静态资源服务（uni3-lowcode H5 构建产物）
     *
     * 从 public/uni3-preview/ 目录读取文件，按 MIME 类型返回。
     * 路径遍历防护：拒绝包含 .. 或绝对路径的请求。
     */
    public static function serveStatic(): \Psr\Http\Message\ResponseInterface
    {
        $path = (string) Request::param('path', '');
        // 路径遍历防护
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            return new \Kode\Http\Response(403, ['Content-Type' => 'text/plain'], 'Forbidden');
        }

        $baseDir = dirname(__DIR__) . '/public/uni3-preview';
        $realBase = realpath($baseDir);
        if ($realBase === false) {
            return new \Kode\Http\Response(404, ['Content-Type' => 'text/plain'], 'Not Found');
        }

        $fullPath = $baseDir . '/' . $path;
        $realPath = realpath($fullPath);

        // 文件存在且在 baseDir 内
        if ($realPath !== false && is_file($realPath) && str_starts_with($realPath, $realBase)) {
            $content = file_get_contents($realPath);
            if ($content === false) {
                return new \Kode\Http\Response(500, ['Content-Type' => 'text/plain'], 'Internal Server Error');
            }
            $mime = self::guessMimeType($realPath);
            return new \Kode\Http\Response(200, ['Content-Type' => $mime], $content);
        }

        // SPA fallback: 非文件请求返回 index.html
        $indexPath = $realBase . '/index.html';
        if (is_file($indexPath)) {
            $content = file_get_contents($indexPath);
            if ($content === false) {
                return new \Kode\Http\Response(500, ['Content-Type' => 'text/plain'], 'Internal Server Error');
            }
            return new \Kode\Http\Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $content);
        }

        return new \Kode\Http\Response(404, ['Content-Type' => 'text/plain'], 'Not Found');
    }

    /**
     * 根据文件扩展名猜测 MIME 类型。
     */
    private static function guessMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'html' => 'text/html; charset=utf-8',
            'js' => 'application/javascript',
            'css' => 'text/css; charset=utf-8',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'map' => 'application/json',
            default => 'application/octet-stream',
        };
    }

    /**
     * API 未匹配路径统一返回 404 JSON 信封。
     *
     * 必须注册在 SPA fallback（`/{path:.+}`）之前：否则 `/api/**`、`/c-api/**` 的未知路径
     * 会被兜底路由命中并返回 index.html（HTTP 200 + text/html），
     * 导致 API 客户端拿到 HTML 而非结构化错误，难以排障。
     */
    public static function apiNotFound(): \Psr\Http\Message\ResponseInterface
    {
        $path = (string) (Request::path() ?? '');
        $body = json_encode([
            'code' => 404,
            'msg' => "接口不存在: {$path}",
            'dur' => '0.0000s',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"code":404,"msg":"接口不存在"}';

        return new \Kode\Http\Response(404, ['Content-Type' => 'application/json; charset=utf-8'], $body);
    }

    /**
     * Admin Web 静态资源服务（js/css/jse 等构建产物）
     *
     * 从 public/ 目录读取文件。非文件请求返回 index.html（SPA fallback）。
     * 路径遍历防护：拒绝包含 .. 或绝对路径的请求。
     */
    public static function serveStaticAdmin(): \Psr\Http\Message\ResponseInterface
    {
        // 获取完整请求路径（去掉开头的 /）
        $path = ltrim(Request::path() ?? '', '/');
        // 路径遍历防护
        if ($path !== '' && str_contains($path, '..')) {
            return new \Kode\Http\Response(403, ['Content-Type' => 'text/plain'], 'Forbidden');
        }

        $baseDir = dirname(__DIR__) . '/public';
        $realBase = realpath($baseDir);
        if ($realBase === false) {
            return new \Kode\Http\Response(404, ['Content-Type' => 'text/plain'], 'Not Found');
        }

        // 空路径（根路径 /）：直接返回 index.html
        if ($path === '' || $path === 'index.html') {
            $indexPath = $realBase . '/index.html';
            if (is_file($indexPath)) {
                $content = file_get_contents($indexPath);
                if ($content === false) {
                    return new \Kode\Http\Response(500, ['Content-Type' => 'text/plain'], 'Internal Server Error');
                }
                return new \Kode\Http\Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $content);
            }
            return new \Kode\Http\Response(404, ['Content-Type' => 'text/plain'], 'Not Found');
        }

        $fullPath = $baseDir . '/' . $path;
        $realPath = realpath($fullPath);

        // 文件存在且在 baseDir 内
        if ($realPath !== false && is_file($realPath) && str_starts_with($realPath, $realBase)) {
            $content = file_get_contents($realPath);
            if ($content === false) {
                return new \Kode\Http\Response(500, ['Content-Type' => 'text/plain'], 'Internal Server Error');
            }
            $mime = self::guessMimeType($realPath);
            return new \Kode\Http\Response(200, ['Content-Type' => $mime], $content);
        }

        // SPA fallback: 非文件请求返回 index.html
        $indexPath = $realBase . '/index.html';
        if (is_file($indexPath)) {
            $content = file_get_contents($indexPath);
            if ($content === false) {
                return new \Kode\Http\Response(500, ['Content-Type' => 'text/plain'], 'Internal Server Error');
            }
            return new \Kode\Http\Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $content);
        }

        return new \Kode\Http\Response(404, ['Content-Type' => 'text/plain'], 'Not Found');
    }

    /* ===================== 数据加载 ===================== */

    /**
     * 读取 preset 索引（backend/presets/index.json）。
     * 缓存到静态属性，避免每次请求重复解析。
     *
     * @return array{generatedAt: string, pages: list<array{pageId: string, title: string, blocks: int}>}
     */
    private static function loadPresetIndex(): array
    {
        // 常驻进程缓存带 60s TTL：preset 索引经部署/后台写入后可自愈，避免永久陈旧（AGENTS.md §5）
        static $cache = null;
        static $expiresAt = 0;
        if ($cache !== null && time() < $expiresAt) {
            return $cache;
        }

        $dir = self::presetDir();
        $path = $dir . DIRECTORY_SEPARATOR . 'index.json';
        $data = [];
        if (is_file($path)) {
            $raw = file_get_contents($path);
            $decoded = $raw === false ? [] : (json_decode($raw, true) ?? []);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        // 兼容两种索引键名：uni3/backend/presets/index.json 顶层是 `presets`，
        // 运行时统一按 `pages` 消费。键名不匹配曾导致 presets 端点恒返回空列表 → 预览页空白。
        $pages = $data['pages'] ?? $data['presets'] ?? null;
        if (!is_array($pages) || $pages === []) {
            // 索引缺失/为空时兜底：直接扫描 preset 目录，避免前端页面选择列表空白。
            $pages = self::scanPresetPages($dir);
        }

        $generatedAt = (string) ($data['generatedAt'] ?? '');
        if ($generatedAt === '') {
            $generatedAt = is_file($path) ? date('c', (int) filemtime($path)) : date('c');
        }

        $cache = ['generatedAt' => $generatedAt, 'pages' => array_values($pages)];
        $expiresAt = time() + 60;

        return $cache;
    }

    /**
     * 扫描 preset 目录生成页面索引（index.json 缺失或为空时的兜底路径）。
     *
     * @return list<array{pageId: string, title: string, blocks: int}>
     */
    private static function scanPresetPages(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $pages = [];
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        foreach ($files as $file) {
            $pageId = basename($file, '.json');
            if ($pageId === 'index' || !preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $pageId)) {
                continue;
            }

            $raw = file_get_contents($file);
            $json = $raw === false ? [] : (json_decode($raw, true) ?? []);
            $blocks = is_array($json) ? ($json['blocks'] ?? []) : [];

            $pages[] = [
                'pageId' => $pageId,
                'title' => (string) (is_array($json) ? ($json['title'] ?? $pageId) : $pageId),
                'blocks' => is_array($blocks) ? count($blocks) : 0,
            ];
        }

        return $pages;
    }

    /**
     * 构建 AppManifest.pages 列表（每个 preset 一个 AppPageEntry）。
     *
     * @param array{generatedAt: string, pages: list<array<string, mixed>>} $index
     * @return list<array<string, mixed>>
     */
    private static function buildPages(array $index): array
    {
        $pages = [];
        foreach ($index['pages'] as $entry) {
            $pageId = (string) ($entry['pageId'] ?? '');
            if ($pageId === '' || !preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $pageId)) {
                continue;
            }
            $pages[] = [
                'pageId' => $pageId,
                'path' => '/pages/index/index?pageId=' . $pageId,
                'source' => 'remote',
                'remoteUrl' => '/api/plugins/frontend/schema/' . $pageId,
                'meta' => [
                    'title' => (string) ($entry['title'] ?? $pageId),
                    'keepAlive' => false,
                    'requiresAuth' => false,
                ],
            ];
        }

        return $pages;
    }

    /**
     * 构建底部 tabBar（6 个高频 preset 页面）。
     */
    private static function buildTabBar(): array
    {
        return [
            'enabled' => true,
            'position' => 'bottom',
            'items' => [
                ['key' => 'home', 'text' => '首页', 'icon' => 'home', 'pageId' => 'home', 'path' => '/pages/index/index?pageId=home'],
                ['key' => 'cart', 'text' => '购物车', 'icon' => 'cart', 'pageId' => 'cart', 'path' => '/pages/index/index?pageId=cart'],
                ['key' => 'forum', 'text' => '论坛', 'icon' => 'chat', 'pageId' => 'forum', 'path' => '/pages/index/index?pageId=forum'],
                ['key' => 'blog', 'text' => '资讯', 'icon' => 'file-text', 'pageId' => 'tech-blog', 'path' => '/pages/index/index?pageId=tech-blog'],
                ['key' => 'community', 'text' => '社区', 'icon' => 'team', 'pageId' => 'community', 'path' => '/pages/index/index?pageId=community'],
                ['key' => 'mine', 'text' => '我的', 'icon' => 'user', 'pageId' => 'mine', 'path' => '/pages/index/index?pageId=mine'],
            ],
            'background' => 'var(--bg-card-solid)',
            'activeColor' => 'var(--primary)',
            'inactiveColor' => 'var(--text-3)',
            'floating' => false,
            'raisedCenter' => ['pageId' => 'home', 'icon' => 'home', 'bgColor' => 'var(--grad-brand)'],
        ];
    }

    /**
     * 默认主题（light-clean）。
     */
    private static function defaultTheme(): array
    {
        return [
            'key' => 'light-clean',
            'name' => '浅色清爽',
            'dark' => false,
            'cssVars' => [
                '--primary' => '#1677ff',
                '--accent' => '#722ed1',
                '--success' => '#52c41a',
                '--warning' => '#faad14',
                '--danger' => '#ff4d4f',
                '--text-1' => '#1f1f1f',
                '--text-2' => '#595959',
                '--text-3' => '#8c8c8c',
                '--bg-page' => '#f5f5f5',
                '--bg-card' => '#ffffff',
                '--bg-card-solid' => '#ffffff',
                '--border' => '#f0f0f0',
            ],
            'tokens' => [
                'radius' => ['sm' => '8rpx', 'md' => '12rpx', 'lg' => '20rpx', 'pill' => '9999rpx'],
                'spacing' => ['xs' => '4rpx', 'sm' => '8rpx', 'md' => '16rpx', 'lg' => '24rpx', 'xl' => '32rpx'],
                'fontSize' => ['xs' => '20rpx', 'sm' => '24rpx', 'md' => '28rpx', 'lg' => '32rpx', 'xl' => '40rpx'],
            ],
        ];
    }

    /**
     * 深色主题。
     */
    private static function darkTheme(): array
    {
        return [
            'key' => 'cyber-blue',
            'name' => '赛博蓝紫',
            'dark' => true,
            'cssVars' => [
                '--primary' => '#00E0FF',
                '--accent' => '#7B61FF',
                '--success' => '#00F0A8',
                '--warning' => '#FFB800',
                '--danger' => '#FF4757',
                '--text-1' => '#F0F4FF',
                '--text-2' => '#B8C4D8',
                '--text-3' => '#8A93A4',
                '--bg-page' => '#050810',
                '--bg-card' => 'rgba(15, 23, 41, 0.7)',
                '--bg-card-solid' => 'rgba(15, 23, 41, 0.92)',
                '--border' => 'rgba(255, 255, 255, 0.06)',
            ],
            'tokens' => [
                'radius' => ['sm' => '12rpx', 'md' => '20rpx', 'lg' => '32rpx', 'pill' => '9999rpx'],
                'spacing' => ['xs' => '4rpx', 'sm' => '8rpx', 'md' => '16rpx', 'lg' => '24rpx', 'xl' => '32rpx'],
                'fontSize' => ['xs' => '20rpx', 'sm' => '24rpx', 'md' => '28rpx', 'lg' => '32rpx', 'xl' => '40rpx'],
            ],
        ];
    }

    /* ===================== 辅助方法 ===================== */

    /**
     * preset 目录：config/uni3.php 的 preset_dir（见该文件注释），未配置回落项目根 uni3-presets。
     * 配置了就认它——目录不存在也照实返回，由上层读出空列表/404，不静默改道去别的目录。
     */
    private static function presetDir(): string
    {
        $dir = trim((string) config('uni3.preset_dir', ''));
        if ($dir === '') {
            $dir = dirname(__DIR__) . '/uni3-presets';
        }

        return rtrim($dir, DIRECTORY_SEPARATOR);
    }

    /**
     * 读取请求体（尽力而为）。
     * 优先从 $_POST / php://input 读；异常时返回空数组。
     *
     * @return array<string, mixed>
     */
    private static function requestBody(): array
    {
        try {
            $raw = file_get_contents('php://input');
            if ($raw !== false && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    return $data;
                }
            }
            return $_POST ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 当前请求 trace_id（从 header 读，缺失则生成）。
     */
    private static function traceId(): string
    {
        $headers = function_exists('getallheaders') ? (getallheaders() ?? []) : [];
        foreach (['X-Trace-Id', 'X-Trace-ID', 'x-trace-id'] as $key) {
            $val = $headers[$key] ?? ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] ?? '');
            if (is_string($val) && $val !== '') {
                // 按字符钳：header 是外部可控串，半个汉字会让下游的日志写入撞 22021。
                return Clip::chars($val, 64);
            }
        }
        return bin2hex(random_bytes(8));
    }
}
