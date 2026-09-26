<?php

/*
 * 后台子应用路由（app/admin/routes.php 存在即被框架自动发现，标签 app:admin）。
 *
 * 约定：/api/auth/login 公开；其余 /api/* 经 JWT 鉴权，管理操作再套超管角色门禁；
 * 只读接口按渲染它的菜单页的 auth_code 挂权限码门禁（见下面的 $can）。
 */

use Kode\Http\App;
use Kode\Http\Request;
use Kode\Framework\Http\Middleware\AuthMiddleware;
use app\admin\controllers\AuthController;
use app\admin\controllers\AuditController;
use app\admin\controllers\ConfigController;
use app\admin\controllers\DashboardController;
use app\admin\controllers\DeptController;
use app\admin\controllers\DictController;
use app\admin\controllers\DbController;
use app\admin\controllers\NotifyController;
use app\admin\controllers\ScheduleController;
use app\admin\controllers\MonitorController;
use app\admin\controllers\StorageController;
use app\admin\controllers\SyslogController;
use app\admin\controllers\FileController;
use app\admin\controllers\MenuButtonController;
use app\admin\controllers\MenuController;
use app\admin\controllers\PermsController;
use app\admin\controllers\PluginController;
use app\admin\controllers\RoleController;
use app\admin\controllers\UploadController;
use app\admin\controllers\UserController;
use app\admin\controllers\SystemController;
use app\admin\controllers\ShardController;
use app\admin\controllers\ProcessController;
use app\admin\controllers\TenantController;
use app\admin\controllers\MigrationController;
use app\admin\controllers\FeatureFlagController;
use app\admin\controllers\SessionController;
use app\admin\controllers\BackupController;
use app\admin\middleware\RequirePerm;
use app\admin\middleware\RequireRole;

return function (App $app): void {
    $app->post('/api/auth/login', fn($req) => resolve(AuthController::class)->login());

    $auth = [new AuthMiddleware()];
    $admin = [new AuthMiddleware(), new RequireRole('admin')];

    // 只读接口的权限码门禁：码取「已经用同一个 auth_code 渲染这张菜单页」的那个码，
    // 与 /api/menus 的菜单可见性同源 —— 菜单看得见 == 接口调得动，反之亦然。
    // 不这么挂的话，写侧全是 $admin、读侧只验登录，等于把用户名单/系统库存/聚合指标
    // 发给任何一枚有效 token（一张只读的部门页也能读全站手机号）。
    // 口径见 tests/CoreReadGateTest.php：核心只读路由必须落在 $admin 或 $can(...) 里。
    $can = static fn(string ...$codes): array => [new AuthMiddleware(), new RequirePerm(...$codes)];

    $app->get('/api/auth/me', fn($req) => resolve(AuthController::class)->me())
        ->middleware($auth);
    $app->post('/api/auth/password', fn($req) => resolve(AuthController::class)->password())
        ->middleware($auth);
    $app->put('/api/auth/profile', fn($req) => resolve(AuthController::class)->profile())
        ->middleware($auth);
    $app->post('/api/auth/refresh', fn($req) => resolve(AuthController::class)->refresh())
        ->middleware($auth);
    $app->get('/api/auth/login-logs', fn($req) => resolve(AuthController::class)->loginLogs())
        ->middleware($auth);
    $app->delete('/api/auth/login-logs/clean', fn($req) => resolve(AuthController::class)->loginLogsClean())
        ->middleware($auth);
    $app->get('/api/auth/login-logs/all', fn($req) => resolve(AuthController::class)->loginLogsAll())
        ->middleware($admin);

    $app->get('/api/users', fn($req) => resolve(UserController::class)->index())->middleware($can('users:view'));
    $app->post('/api/users', fn($req) => resolve(UserController::class)->store())->middleware($admin);
    $app->post('/api/users/batch-delete', fn($req) => resolve(UserController::class)->batchDestroy())->middleware($admin);
    $app->post('/api/users/batch-roles', fn($req) => resolve(UserController::class)->batchAssignRoles())->middleware($admin);
    $app->post('/api/users/import', fn($req) => resolve(UserController::class)->import())->middleware($admin);
    $app->put('/api/users/{id:[A-Za-z0-9]+}', fn($req) => resolve(UserController::class)->update())->middleware($admin);
    $app->delete('/api/users/{id:[A-Za-z0-9]+}', fn($req) => resolve(UserController::class)->destroy())->middleware($admin);
    $app->post('/api/users/{id:[A-Za-z0-9]+}/roles', fn($req) => resolve(UserController::class)->assignRoles())->middleware($admin);
    $app->post('/api/users/{id:[A-Za-z0-9]+}/toggle', fn($req) => resolve(UserController::class)->toggleStatus())->middleware($admin);
    $app->post('/api/users/{id:[A-Za-z0-9]+}/password', fn($req) => resolve(UserController::class)->resetPassword())->middleware($admin);
    $app->get('/api/users/export', fn($req) => resolve(UserController::class)->export())->middleware($admin);

    // 角色名单同时被用户页的「分配角色」下拉消费，故 users:view 亦可见（只读名字，不含 perms）。
    $app->get('/api/roles', fn($req) => resolve(RoleController::class)->index())->middleware($can('roles:view', 'users:view'));
    $app->post('/api/roles', fn($req) => resolve(RoleController::class)->store())->middleware($admin);
    $app->put('/api/roles/{slug}', fn($req) => resolve(RoleController::class)->update())->middleware($admin);
    $app->delete('/api/roles/{slug}', fn($req) => resolve(RoleController::class)->destroy())->middleware($admin);

    // 菜单树本身保持仅登录：它是侧边栏的数据源，门禁在树内按 auth_code 过滤，套权限码会把所有人的菜单清空。
    $app->get('/api/menus', fn($req) => resolve(MenuController::class)->tree())->middleware($auth);
    $app->post('/api/menus', fn($req) => resolve(MenuController::class)->store())->middleware($admin);
    $app->put('/api/menus/{id:[A-Za-z0-9]+}', fn($req) => resolve(MenuController::class)->update())->middleware($admin);
    $app->delete('/api/menus/{id:[A-Za-z0-9]+}', fn($req) => resolve(MenuController::class)->destroy())->middleware($admin);
    $app->get('/api/menus/{id:[A-Za-z0-9]+}/buttons', fn($req) => resolve(MenuButtonController::class)->index())->middleware($can('menus:view'));
    $app->post('/api/menus/{id:[A-Za-z0-9]+}/buttons', fn($req) => resolve(MenuButtonController::class)->store())->middleware($admin);
    $app->put('/api/menu-buttons/{id:[A-Za-z0-9]+}', fn($req) => resolve(MenuButtonController::class)->update())->middleware($admin);
    $app->delete('/api/menu-buttons/{id:[A-Za-z0-9]+}', fn($req) => resolve(MenuButtonController::class)->destroy())->middleware($admin);

    // 站点品牌项（标题/Logo/备案号/描述）：登录页就要显示，故单独出白名单子集，
    // 让整张配置表 /api/configs 能收到 configs:view 后面包（见 ConfigController::site）。
    // 这条是无参字面量路由，kode/http 把它放进 static 查表、先于任何 {param} 路由命中，
    // 所以「注册在 /api/configs/{key} 之前」只是可读性顺序，不是正确性前提
    // （真正靠注册顺序的是两条动态路由之间，如插件的 /api/{path:.+} 兜底，见 §3 与
    //  tests/RouteReachabilityTest.php）。顺序反了也不会被吞，反例断言在同一文件。
    $app->get('/api/configs/site', fn($req) => resolve(ConfigController::class)->site())->middleware($auth);
    $app->get('/api/configs', fn($req) => resolve(ConfigController::class)->index())->middleware($can('configs:view'));
    $app->get('/api/configs/{key}', fn($req) => resolve(ConfigController::class)->get())->middleware($can('configs:view'));
    $app->post('/api/configs', fn($req) => resolve(ConfigController::class)->set())->middleware($admin);
    $app->post('/api/configs/reset', fn($req) => resolve(ConfigController::class)->reset())->middleware($admin);
    $app->delete('/api/configs/{key}', fn($req) => resolve(ConfigController::class)->destroy())->middleware($admin);

    // 部门树同时是用户页的归属选择器数据源，故 users:view 可见。
    $app->get('/api/depts', fn($req) => resolve(DeptController::class)->tree())->middleware($can('depts:view', 'users:view'));
    $app->get('/api/dicts', fn($req) => resolve(DictController::class)->types())->middleware($can('notices:view'));
    $app->post('/api/dicts', fn($req) => resolve(DictController::class)->storeType())->middleware($admin);
    $app->put('/api/dicts/{id:[A-Za-z0-9]+}', fn($req) => resolve(DictController::class)->updateType())->middleware($admin);
    $app->delete('/api/dicts/{id:[A-Za-z0-9]+}', fn($req) => resolve(DictController::class)->destroyType())->middleware($admin);
    $app->get('/api/dict-items', fn($req) => resolve(DictController::class)->items())->middleware($can('notices:view'));
    $app->post('/api/dict-items', fn($req) => resolve(DictController::class)->storeItem())->middleware($admin);
    $app->put('/api/dict-items/{id:[A-Za-z0-9]+}', fn($req) => resolve(DictController::class)->updateItem())->middleware($admin);
    $app->delete('/api/dict-items/{id:[A-Za-z0-9]+}', fn($req) => resolve(DictController::class)->destroyItem())->middleware($admin);
    $app->post('/api/depts', fn($req) => resolve(DeptController::class)->store())->middleware($admin);
    $app->put('/api/depts/{id:[A-Za-z0-9]+}', fn($req) => resolve(DeptController::class)->update())->middleware($admin);
    $app->delete('/api/depts/{id:[A-Za-z0-9]+}', fn($req) => resolve(DeptController::class)->destroy())->middleware($admin);
    $app->get('/api/plugins', fn($req) => resolve(PluginController::class)->index())->middleware($can('plugins:view'));
    $app->get('/api/plugins/{name}/contract', fn($req) => resolve(PluginController::class)->contract())->middleware($can('plugins:view'));
    $app->get('/api/plugins/{name}/config', fn($req) => resolve(PluginController::class)->config())->middleware($admin);
    $app->put('/api/plugins/{name}/config', fn($req) => resolve(PluginController::class)->saveConfig())->middleware($admin);
    $app->post('/api/plugins/{name}/install', fn($req) => resolve(PluginController::class)->install())->middleware($admin);
    $app->post('/api/plugins/{name}/uninstall', fn($req) => resolve(PluginController::class)->uninstall())->middleware($admin);
    $app->post('/api/plugins/{name}/pause', fn($req) => resolve(PluginController::class)->pause())->middleware($admin);
    $app->post('/api/plugins/{name}/resume', fn($req) => resolve(PluginController::class)->resume())->middleware($admin);
    $app->post('/api/plugins/{name}/upgrade', fn($req) => resolve(PluginController::class)->upgrade())->middleware($admin);
    $app->get('/api/plugins/{name}/package', fn($req) => resolve(PluginController::class)->package())->middleware($admin);
    $app->post('/api/plugins/install-remote', fn($req) => resolve(PluginController::class)->installRemote())->middleware($admin);
    $app->post('/api/plugins/scaffold', fn($req) => resolve(PluginController::class)->scaffold())->middleware($admin);
    $app->get('/api/plugins/{name}/version-history', fn($req) => resolve(PluginController::class)->versionHistory())->middleware($admin);
    $app->get('/api/plugins/{name}/upgrade-preview', fn($req) => resolve(PluginController::class)->upgradePreview())->middleware($admin);
    $app->post('/api/plugins/{name}/rollback', fn($req) => resolve(PluginController::class)->rollback())->middleware($admin);
    $app->post('/api/plugins/{name}/config/reset', fn($req) => resolve(PluginController::class)->resetConfig())->middleware($admin);
    $app->get('/api/plugins/{name}/health', fn($req) => resolve(PluginController::class)->health())->middleware($admin);
    $app->get('/api/plugins/audit-report', fn($req) => resolve(PluginController::class)->auditReport())->middleware($admin);
    $app->get('/api/plugins/audit-report/export', fn($req) => resolve(PluginController::class)->auditReportExport())->middleware($admin);
    $app->get('/api/plugins/audit-report/export/csv', fn($req) => resolve(PluginController::class)->auditReportExportCsv())->middleware($admin);
    $app->get('/api/plugins/{name}/logs', fn($req) => resolve(PluginController::class)->logs())->middleware($admin);
    $app->post('/api/plugins/{name}/version-compare', fn($req) => resolve(PluginController::class)->versionCompare())->middleware($admin);
    $app->get('/api/plugins/{name}/files', fn($req) => resolve(PluginController::class)->files())->middleware($admin);
    $app->put('/api/plugins/{name}/files', fn($req) => resolve(PluginController::class)->fileWrite())->middleware($admin);
    $app->get('/api/plugins/{name}/files/read', fn($req) => resolve(PluginController::class)->fileRead())->middleware($admin);
    $app->get('/api/plugins/{name}/detail', fn($req) => resolve(PluginController::class)->runtimeDetail())->middleware($admin);
    $app->get('/api/plugins/{name}/backup', fn($req) => resolve(PluginController::class)->backup())->middleware($admin);
    $app->get('/api/plugins/{name}/code-search', fn($req) => resolve(PluginController::class)->codeSearch())->middleware($admin);
    $app->get('/api/plugins/code-search', fn($req) => resolve(PluginController::class)->globalCodeSearch())->middleware($admin);
    $app->get('/api/plugins/{name}/dependency-tree', fn($req) => resolve(PluginController::class)->dependencyTree())->middleware($admin);
    $app->get('/api/plugins/{name}/api-doc', fn($req) => resolve(PluginController::class)->apiDoc())->middleware($admin);
    $app->get('/api/plugins/{name}/api-doc/markdown', fn($req) => resolve(PluginController::class)->apiDocMarkdown())->middleware($admin);
    $app->get('/api/plugins/{name}/schedule-tasks', fn($req) => resolve(PluginController::class)->scheduleTasks())->middleware($admin);
    $app->get('/api/plugins/{name}/prerequisites', fn($req) => resolve(PluginController::class)->prerequisites())->middleware($admin);
    $app->get('/api/plugins/metrics', fn($req) => resolve(PluginController::class)->metrics())->middleware($admin);
    $app->get('/api/plugins/dependency-resolve', fn($req) => resolve(PluginController::class)->dependencyResolve())->middleware($admin);
    $app->get('/api/plugins/dependency-tree', fn($req) => resolve(PluginController::class)->dependencyTreeAll())->middleware($admin);
    $app->get('/api/plugins/marketplace/health', fn($req) => resolve(PluginController::class)->marketplaceHealth())->middleware($can('plugins:view'));
    $app->get('/api/plugins/marketplace/list', fn($req) => resolve(PluginController::class)->marketplaceList())->middleware($can('plugins:view'));
    $app->get('/api/plugins/marketplace/check-updates', fn($req) => resolve(PluginController::class)->checkUpdates())->middleware($can('plugins:view'));
    $app->post('/api/plugins/batch-pause', fn($req) => resolve(PluginController::class)->batchPause())->middleware($admin);
    $app->post('/api/plugins/batch-resume', fn($req) => resolve(PluginController::class)->batchResume())->middleware($admin);
    $app->post('/api/plugins/batch-uninstall', fn($req) => resolve(PluginController::class)->batchUninstall())->middleware($admin);
    $app->post('/api/plugins/backup/restore', fn($req) => resolve(PluginController::class)->restore())->middleware($admin);
    $app->get('/api/perms/catalog', fn($req) => resolve(PermsController::class)->catalog())->middleware($can('roles:view'));
    $app->get('/api/audits', fn($req) => resolve(AuditController::class)->index())->middleware($admin);
    $app->get('/api/audits/export', fn($req) => resolve(AuditController::class)->export())->middleware($admin);
    $app->get('/api/notifications', fn($req) => resolve(NotifyController::class)->index())->middleware($auth);
    $app->get('/api/notifications/unread-count', fn($req) => resolve(NotifyController::class)->unreadCount())->middleware($auth);
    $app->post('/api/notifications/{id:[A-Za-z0-9]+}/read', fn($req) => resolve(NotifyController::class)->read())->middleware($auth);
    $app->post('/api/notifications/read-all', fn($req) => resolve(NotifyController::class)->readAll())->middleware($auth);
    $app->delete('/api/notifications/clear', fn($req) => resolve(NotifyController::class)->clear())->middleware($auth);
    $app->post('/api/notifications/broadcast', fn($req) => resolve(NotifyController::class)->broadcast())->middleware($admin);
    // 存储通道属「存储配置」页（auth_code=configs:view）；出参含目标桶与容量，不再对全体登录用户开放。
    $app->get('/api/storage', fn($req) => resolve(StorageController::class)->index())->middleware($can('configs:view'));
    // 可用驱动目录（只读）：数据源是已启用插件的 drivers() 声明，随启停即时变化。
    $app->get('/api/storage/drivers', fn($req) => resolve(StorageController::class)->drivers())->middleware($can('configs:view'));
    // 通道配置查看（密钥字段脱敏）+ 容量统计
    $app->get('/api/storage/{id:[A-Za-z0-9]+}/config', fn($req) => resolve(StorageController::class)->config())->middleware($can('configs:view'));
    $app->get('/api/storage/{id:[A-Za-z0-9]+}/stats', fn($req) => resolve(StorageController::class)->stats())->middleware($can('configs:view'));
    $app->post('/api/storage', fn($req) => resolve(StorageController::class)->store())->middleware($admin);
    $app->put('/api/storage/{id:[A-Za-z0-9]+}', fn($req) => resolve(StorageController::class)->update())->middleware($admin);
    $app->post('/api/storage/{id:[A-Za-z0-9]+}/default', fn($req) => resolve(StorageController::class)->setDefault())->middleware($admin);
    $app->delete('/api/storage/{id:[A-Za-z0-9]+}', fn($req) => resolve(StorageController::class)->destroy())->middleware($admin);
    // 连通性测试：local 走本地探针；远程驱动经 storage_driver_resolve 事件走 FlysystemStoragePlugin 真实上传。
    $app->post('/api/storage/test', fn($req) => resolve(StorageController::class)->test())->middleware($admin);
    $app->get('/api/syslogs', fn($req) => resolve(SyslogController::class)->index())->middleware($admin);
    // 定时任务属「运维监控」组（菜单 /schedules、/monitor 的 auth_code=audits:view）。
    $app->get('/api/schedules', fn($req) => resolve(ScheduleController::class)->index())->middleware($can('audits:view'));
    $app->post('/api/schedules/{name}/run', fn($req) => resolve(ScheduleController::class)->run())->middleware($admin);
    $app->get('/api/schedules/runs', fn($req) => resolve(ScheduleController::class)->runs())->middleware($can('audits:view'));
    $app->put('/api/schedules/{name}/toggle', fn($req) => resolve(ScheduleController::class)->toggle())->middleware($admin);
    $app->get('/api/schedules/next-runs', fn($req) => resolve(ScheduleController::class)->nextRuns())->middleware($can('audits:view'));
    $app->get('/api/schedules/health', fn($req) => resolve(ScheduleController::class)->health())->middleware($can('audits:view'));
    $app->get('/api/schedules/{name}/history', fn($req) => resolve(ScheduleController::class)->history())->middleware($can('audits:view'));
    $app->get('/api/schedules/lock-status', fn($req) => resolve(ScheduleController::class)->lockStatus())->middleware($admin);
    $app->post('/api/schedules/create', fn($req) => resolve(ScheduleController::class)->create())->middleware($admin);
    $app->get('/api/schedules/by-tenant/{tenantId}', fn($req) => resolve(ScheduleController::class)->byTenant())->middleware($can('audits:view'));
    $app->post('/api/schedules/prune', fn($req) => resolve(ScheduleController::class)->prune())->middleware($admin);

    // 迁移管理
    $app->get('/api/migrations', fn($req) => resolve(MigrationController::class)->status())->middleware($admin);
    $app->post('/api/migrations/run', fn($req) => resolve(MigrationController::class)->run())->middleware($admin);
    $app->post('/api/migrations/rollback', fn($req) => resolve(MigrationController::class)->rollback())->middleware($admin);
    $app->post('/api/migrations/reset', fn($req) => resolve(MigrationController::class)->reset())->middleware($admin);

    // 特性开关
    // 特性开关（菜单页 auth_code=menu.feature_flags）；history 的数据源是审计日志，与 /api/audits 同口径走超管。
    $app->get('/api/feature-flags', fn($req) => resolve(FeatureFlagController::class)->index())->middleware($can('menu.feature_flags'));
    $app->get('/api/feature-flags/stats', fn($req) => resolve(FeatureFlagController::class)->stats())->middleware($can('menu.feature_flags'));
    $app->get('/api/feature-flags/history', fn($req) => resolve(FeatureFlagController::class)->history())->middleware($admin);
    $app->post('/api/feature-flags', fn($req) => resolve(FeatureFlagController::class)->store())->middleware($admin);
    $app->put('/api/feature-flags/{key}', fn($req) => resolve(FeatureFlagController::class)->update())->middleware($admin);
    $app->delete('/api/feature-flags/{key}', fn($req) => resolve(FeatureFlagController::class)->destroy())->middleware($admin);
    $app->post('/api/feature-flags/{key}/toggle', fn($req) => resolve(FeatureFlagController::class)->toggle())->middleware($admin);
    $app->get('/api/feature-flags/check', fn($req) => resolve(FeatureFlagController::class)->check())->middleware($can('menu.feature_flags'));

    // 多租户调度
    $app->get('/api/schedule/tenants', fn($req) => resolve(ScheduleController::class)->tenants())->middleware($can('audits:view'));
    $app->post('/api/schedule/tenants/run', fn($req) => resolve(ScheduleController::class)->runForTenants())->middleware($admin);
    $app->get('/api/schedule/tenants/runs', fn($req) => resolve(ScheduleController::class)->tenantRuns())->middleware($can('audits:view'));
    $app->get('/api/schedule/tenants/stats/{task}', fn($req) => resolve(ScheduleController::class)->tenantStats())->middleware($can('audits:view'));
    $app->get('/api/schedule/tenant-tasks', fn($req) => resolve(ScheduleController::class)->tenantTasks())->middleware($can('audits:view'));
    $app->get('/api/monitor', fn($req) => resolve(MonitorController::class)->overview())->middleware($can('audits:view'));
    $app->get('/api/monitor/shards', fn($req) => resolve(MonitorController::class)->shards())->middleware($admin);
    $app->get('/api/db/tables', fn($req) => resolve(DbController::class)->tables())->middleware($admin);
    $app->get('/api/databases', fn($req) => resolve(DbController::class)->connections())->middleware($admin);
    $app->get('/api/db/stats', fn($req) => resolve(DbController::class)->stats())->middleware($admin);
    $app->get('/api/db/top-tables', fn($req) => resolve(DbController::class)->topTables())->middleware($admin);
    $app->get('/api/db/tables/{table}', fn($req) => resolve(DbController::class)->columns())->middleware($admin);
    $app->get('/api/db/tables/{table}/rows', fn($req) => resolve(DbController::class)->rows())->middleware($admin);
    $app->get('/api/db/tables/{table}/indexes', fn($req) => resolve(DbController::class)->indexes())->middleware($admin);
    $app->get('/api/db/tables/{table}/constraints', fn($req) => resolve(DbController::class)->constraints())->middleware($admin);
    $app->get('/api/db/activity', fn($req) => resolve(DbController::class)->activity())->middleware($admin);
    $app->get('/api/db/locks', fn($req) => resolve(DbController::class)->locks())->middleware($admin);
    $app->post('/api/db/query', fn($req) => resolve(DbController::class)->sql())->middleware($admin);

    // 分库分表配置管理
    $app->get('/api/shard/config', fn($req) => resolve(ShardController::class)->config())->middleware($admin);
    $app->put('/api/shard/config', fn($req) => resolve(ShardController::class)->update())->middleware($admin);
    $app->get('/api/shard/status', fn($req) => resolve(ShardController::class)->status())->middleware($admin);

    // 进程控制
    // 读侧按「渲染它的菜单页」的 auth_code 挂（/monitor 服务器监控 = audits:view），
    // 菜单看得见 == 接口调得动；写侧（发信号、派发启动）仍只超管。
    // restart 已删除：stop 是 SIGTERM，守护进程要停完 worker、删掉 pid 文件才退出，
    // 而一次请求内既不能等它退出（§5 禁止请求内 sleep/轮询）也不能不等就派发
    //（第二代只会撞上 kode/process v5.5.1 的 pid 文件 flock 互斥而被拒）。
    // 改代码走 reload（USR1 平滑重载），换 worker 注册表走「停止 → 状态归零 → 启动」两步。
    $app->get('/api/process/list', fn($req) => resolve(ProcessController::class)->list())->middleware($can('audits:view'));
    $app->get('/api/process/status', fn($req) => resolve(ProcessController::class)->status())->middleware($can('audits:view'));
    $app->post('/api/process/stop', fn($req) => resolve(ProcessController::class)->stop())->middleware($admin);
    $app->post('/api/process/reload', fn($req) => resolve(ProcessController::class)->reload())->middleware($admin);
    $app->post('/api/process/start', fn($req) => resolve(ProcessController::class)->start())->middleware($admin);

    // 租户管理
    $app->get('/api/tenants', fn($req) => resolve(TenantController::class)->index())->middleware($admin);
    $app->get('/api/tenants/stats', fn($req) => resolve(TenantController::class)->stats())->middleware($admin);
    $app->post('/api/tenants', fn($req) => resolve(TenantController::class)->store())->middleware($admin);
    $app->put('/api/tenants/{id:[A-Za-z0-9]+}', fn($req) => resolve(TenantController::class)->update())->middleware($admin);
    $app->delete('/api/tenants/{id:[A-Za-z0-9]+}', fn($req) => resolve(TenantController::class)->destroy())->middleware($admin);
    $app->get('/api/tenants/export', fn($req) => resolve(TenantController::class)->export())->middleware($admin);
    $app->delete('/api/syslogs/clean', fn($req) => resolve(SyslogController::class)->clean())->middleware($admin);
    // mine/stats 保持仅登录：mine 按 token 用户名自过滤，stats 在控制器内按身份收敛口径
    // （超管看全站、其他人只看自己），二者都是仪表盘/首页要渲染的量；
    // overview 输出全站唯一 IP、用户名数与路径 Top，属超管数据（与 /api/audits 列表同口径）。
    $app->get('/api/audits/mine', fn($req) => resolve(AuditController::class)->mine())->middleware($auth);
    $app->get('/api/audits/stats', fn($req) => resolve(AuditController::class)->stats())->middleware($auth);
    $app->get('/api/audits/overview', fn($req) => resolve(AuditController::class)->overview())->middleware($admin);
    $app->delete('/api/audits/clean', fn($req) => resolve(AuditController::class)->clean())->middleware($admin);

    $app->get('/api/system/backup', fn($req) => resolve(SystemController::class)->backup())->middleware($admin);
    // 健康探针出参含 PHP 版本、扩展、磁盘与连接池 —— 只有服务器监控页消费它（auth_code=audits:view）。
    $app->get('/api/system/health', fn($req) => resolve(SystemController::class)->health())->middleware($can('audits:view'));
    $app->post('/api/system/health/repair', fn($req) => resolve(SystemController::class)->repair())->middleware($admin);
    $app->post('/api/system/cache-clear', fn($req) => resolve(SystemController::class)->cacheClear())->middleware($admin);
    $app->get('/api/system/configs', fn($req) => resolve(SystemController::class)->configs())->middleware($admin);
    $app->get('/api/system/pack-info', fn($req) => resolve(SystemController::class)->packInfo())->middleware($admin);

    // 会话管理
    $app->get('/api/sessions', fn($req) => resolve(SessionController::class)->index())->middleware($admin);
    $app->get('/api/sessions/online-count', fn($req) => resolve(SessionController::class)->onlineCount())->middleware($admin);
    $app->delete('/api/sessions/{id:[A-Za-z0-9]+}', fn($req) => resolve(SessionController::class)->revoke())->middleware($admin);
    $app->post('/api/sessions/clear-all', fn($req) => resolve(SessionController::class)->clearAll())->middleware($admin);

    // 备份管理
    $app->get('/api/system/backups', fn($req) => resolve(BackupController::class)->index())->middleware($admin);
    $app->get('/api/system/backups/download', fn($req) => resolve(BackupController::class)->download())->middleware($admin);
    $app->post('/api/system/backups/run', fn($req) => resolve(BackupController::class)->run())->middleware($admin);
    $app->post('/api/system/backups/restore', fn($req) => resolve(BackupController::class)->restore())->middleware($admin);
    $app->post('/api/system/backups/clean', fn($req) => resolve(BackupController::class)->clean())->middleware($admin);
    $app->delete('/api/system/backups/{file}', fn($req) => resolve(BackupController::class)->destroy())->middleware($admin);

    // 日志分析
    $app->get('/api/syslogs/stats', fn($req) => resolve(SyslogController::class)->stats())->middleware($admin);
    $app->get('/api/syslogs/trend', fn($req) => resolve(SyslogController::class)->trend())->middleware($admin);
    $app->get('/api/syslogs/top-errors', fn($req) => resolve(SyslogController::class)->topErrors())->middleware($admin);
    // 详情路由放在字面量之后只是可读性顺序：`{id:[A-Za-z0-9]+}` 是动态路由，
    // kode/http 先查无参字面量的静态表，stats/trend/top-errors 无论注册先后都不会被它吞
    // （断言见 tests/RouteReachabilityTest.php）。真正靠顺序的是两条动态路由之间，如 /api/files/{path:.+}。
    $app->get('/api/syslogs/{id:[A-Za-z0-9]+}', fn($req) => resolve(SyslogController::class)->show())->middleware($admin);

    // 数据库监控
    $app->get('/api/monitor/db-activity', fn($req) => resolve(MonitorController::class)->dbActivity())->middleware($admin);
    $app->get('/api/monitor/db-statements', fn($req) => resolve(MonitorController::class)->dbStatements())->middleware($admin);
    $app->get('/api/monitor/connections', fn($req) => resolve(MonitorController::class)->connections())->middleware($admin);
    $app->get('/api/monitor/db-locks', fn($req) => resolve(MonitorController::class)->dbLocks())->middleware($admin);

    // 首页数字看板：菜单 /dashboard 只挂 dashboard:view（每个角色都可能有），
    // 故保持仅登录，由控制器按身份收敛全站库存（scope=all|self）。
    $app->get('/api/dashboard/summary', fn($req) => resolve(DashboardController::class)->summary())->middleware($auth);

    $app->post('/api/upload', fn($req) => resolve(UploadController::class)->store())->middleware($auth);
    $app->get('/api/files/{path:.+}', fn($req) => resolve(UploadController::class)->show())->middleware($auth);
    $app->get('/api/media', fn($req) => resolve(FileController::class)->index())->middleware($auth);
    $app->get('/api/media/resolve', fn($req) => resolve(FileController::class)->resolveUrls())->middleware($auth);
    $app->post('/api/media/transfer', fn($req) => resolve(FileController::class)->transfer())->middleware($admin);
    $app->get('/api/media/months', fn($req) => resolve(FileController::class)->index())->middleware($auth);
    $app->get('/api/media/dirs', fn($req) => resolve(FileController::class)->dirs())->middleware($auth);
    $app->post('/api/media/folders', fn($req) => resolve(FileController::class)->mkdir())->middleware($admin);
    $app->post('/api/media/rename', fn($req) => resolve(FileController::class)->rename())->middleware($admin);
    $app->post('/api/media/replace', fn($req) => resolve(FileController::class)->replace())->middleware($admin);
    $app->post('/api/media/move', fn($req) => resolve(FileController::class)->move())->middleware($admin);
    $app->delete('/api/media/{path:.+}', fn($req) => resolve(FileController::class)->destroy())->middleware($admin);

    // 签名演示（淘宝式防篡改+防重放，按需挂载；客户端见 app\support\ApiSigner）。
    $app->get('/api/secure/ping', fn() => \app\support\ApiEnvelope::ok(['pong' => true, 'signed' => true]))
        ->middleware([new AuthMiddleware(), new \app\middleware\ApiSignMiddleware()]);
};
