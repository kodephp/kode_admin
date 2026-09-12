<?php

/*
 * 后台子应用路由（app/admin/routes.php 存在即被框架自动发现，标签 app:admin）。
 *
 * 约定：/api/auth/login 公开；其余 /api/* 经 JWT 鉴权，管理操作再套超管角色门禁。
 */

use Kode\Http\App;
use Kode\Http\Request;
use Kode\Framework\Http\Middleware\AuthMiddleware;
use app\admin\http\controllers\AuthController;
use app\admin\http\controllers\AuditController;
use app\admin\http\controllers\ConfigController;
use app\admin\http\controllers\DashboardController;
use app\admin\http\controllers\DeptController;
use app\admin\http\controllers\DictController;
use app\admin\http\controllers\DbController;
use app\admin\http\controllers\NotifyController;
use app\admin\http\controllers\ScheduleController;
use app\admin\http\controllers\MonitorController;
use app\admin\http\controllers\StorageController;
use app\admin\http\controllers\SyslogController;
use app\admin\http\controllers\FileController;
use app\admin\http\controllers\MenuButtonController;
use app\admin\http\controllers\MenuController;
use app\admin\http\controllers\PermsController;
use app\admin\http\controllers\PluginController;
use app\admin\http\controllers\RoleController;
use app\admin\http\controllers\UploadController;
use app\admin\http\controllers\UserController;
use app\admin\http\controllers\SystemController;
use app\admin\http\middleware\RequireRole;

return function (App $app): void {
    $app->post('/api/auth/login', fn($req) => resolve(AuthController::class)->login());

    $auth = [new AuthMiddleware()];
    $admin = [new AuthMiddleware(), new RequireRole('admin')];

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

    $app->get('/api/users', fn($req) => resolve(UserController::class)->index())->middleware($auth);
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

    $app->get('/api/roles', fn($req) => resolve(RoleController::class)->index())->middleware($auth);
    $app->post('/api/roles', fn($req) => resolve(RoleController::class)->store())->middleware($admin);
    $app->put('/api/roles/{slug}', fn($req) => resolve(RoleController::class)->update())->middleware($admin);

    $app->get('/api/roles', fn($req) => resolve(RoleController::class)->index())->middleware($auth);
    $app->post('/api/roles', fn($req) => resolve(RoleController::class)->store())->middleware($admin);
    $app->delete('/api/roles/{slug}', fn($req) => resolve(RoleController::class)->destroy())->middleware($admin);

    $app->get('/api/menus', fn($req) => resolve(MenuController::class)->tree())->middleware($auth);
    $app->post('/api/menus', fn($req) => resolve(MenuController::class)->store())->middleware($admin);
    $app->put('/api/menus/{id:[A-Za-z0-9]+}', fn($req) => resolve(MenuController::class)->update())->middleware($admin);
    $app->delete('/api/menus/{id:[A-Za-z0-9]+}', fn($req) => resolve(MenuController::class)->destroy())->middleware($admin);
    $app->get('/api/menus/{id:[A-Za-z0-9]+}/buttons', fn($req) => resolve(MenuButtonController::class)->index())->middleware($auth);
    $app->post('/api/menus/{id:[A-Za-z0-9]+}/buttons', fn($req) => resolve(MenuButtonController::class)->store())->middleware($admin);
    $app->put('/api/menu-buttons/{id:[A-Za-z0-9]+}', fn($req) => resolve(MenuButtonController::class)->update())->middleware($admin);
    $app->delete('/api/menu-buttons/{id:[A-Za-z0-9]+}', fn($req) => resolve(MenuButtonController::class)->destroy())->middleware($admin);

    $app->get('/api/configs', fn($req) => resolve(ConfigController::class)->index())->middleware($auth);
    $app->get('/api/configs/{key}', fn($req) => resolve(ConfigController::class)->get())->middleware($auth);
    $app->post('/api/configs', fn($req) => resolve(ConfigController::class)->set())->middleware($admin);
    $app->post('/api/configs/reset', fn($req) => resolve(ConfigController::class)->reset())->middleware($admin);
    $app->delete('/api/configs/{key}', fn($req) => resolve(ConfigController::class)->destroy())->middleware($admin);

    $app->get('/api/depts', fn($req) => resolve(DeptController::class)->tree())->middleware($auth);
    $app->get('/api/dicts', fn($req) => resolve(DictController::class)->types())->middleware($auth);
    $app->post('/api/dicts', fn($req) => resolve(DictController::class)->storeType())->middleware($admin);
    $app->put('/api/dicts/{id:[A-Za-z0-9]+}', fn($req) => resolve(DictController::class)->updateType())->middleware($admin);
    $app->delete('/api/dicts/{id:[A-Za-z0-9]+}', fn($req) => resolve(DictController::class)->destroyType())->middleware($admin);
    $app->get('/api/dict-items', fn($req) => resolve(DictController::class)->items())->middleware($auth);
    $app->post('/api/dict-items', fn($req) => resolve(DictController::class)->storeItem())->middleware($admin);
    $app->put('/api/dict-items/{id:[A-Za-z0-9]+}', fn($req) => resolve(DictController::class)->updateItem())->middleware($admin);
    $app->delete('/api/dict-items/{id:[A-Za-z0-9]+}', fn($req) => resolve(DictController::class)->destroyItem())->middleware($admin);
    $app->post('/api/depts', fn($req) => resolve(DeptController::class)->store())->middleware($admin);
    $app->put('/api/depts/{id:[A-Za-z0-9]+}', fn($req) => resolve(DeptController::class)->update())->middleware($admin);
    $app->delete('/api/depts/{id:[A-Za-z0-9]+}', fn($req) => resolve(DeptController::class)->destroy())->middleware($admin);
    $app->get('/api/plugins', fn($req) => resolve(PluginController::class)->index())->middleware($auth);
    $app->get('/api/plugins/{name}/contract', fn($req) => resolve(PluginController::class)->contract())->middleware($auth);
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
    $app->get('/api/plugins/{name}/files/read', fn($req) => resolve(PluginController::class)->fileRead())->middleware($admin);
    $app->get('/api/plugins/{name}/detail', fn($req) => resolve(PluginController::class)->runtimeDetail())->middleware($admin);
    $app->get('/api/plugins/marketplace/health', fn($req) => resolve(PluginController::class)->marketplaceHealth())->middleware($auth);
    $app->get('/api/plugins/marketplace/list', fn($req) => resolve(PluginController::class)->marketplaceList())->middleware($auth);
    $app->get('/api/plugins/marketplace/check-updates', fn($req) => resolve(PluginController::class)->checkUpdates())->middleware($auth);
    $app->post('/api/plugins/batch-pause', fn($req) => resolve(PluginController::class)->batchPause())->middleware($admin);
    $app->post('/api/plugins/batch-resume', fn($req) => resolve(PluginController::class)->batchResume())->middleware($admin);
    $app->post('/api/plugins/batch-uninstall', fn($req) => resolve(PluginController::class)->batchUninstall())->middleware($admin);
    $app->get('/api/perms/catalog', fn($req) => resolve(PermsController::class)->catalog())->middleware($auth);
    $app->get('/api/audits', fn($req) => resolve(AuditController::class)->index())->middleware($admin);
    $app->get('/api/audits/export', fn($req) => resolve(AuditController::class)->export())->middleware($admin);
    $app->get('/api/notifications', fn($req) => resolve(NotifyController::class)->index())->middleware($auth);
    $app->get('/api/notifications/unread-count', fn($req) => resolve(NotifyController::class)->unreadCount())->middleware($auth);
    $app->post('/api/notifications/{id:[A-Za-z0-9]+}/read', fn($req) => resolve(NotifyController::class)->read())->middleware($auth);
    $app->post('/api/notifications/read-all', fn($req) => resolve(NotifyController::class)->readAll())->middleware($auth);
    $app->delete('/api/notifications/clear', fn($req) => resolve(NotifyController::class)->clear())->middleware($auth);
    $app->post('/api/notifications/broadcast', fn($req) => resolve(NotifyController::class)->broadcast())->middleware($admin);
    $app->get('/api/storage', fn($req) => resolve(StorageController::class)->index())->middleware($auth);
    // 可用驱动目录（只读）：数据源是已启用插件的 drivers() 声明，随启停即时变化。
    $app->get('/api/storage/drivers', fn($req) => resolve(StorageController::class)->drivers())->middleware($auth);
    $app->post('/api/storage', fn($req) => resolve(StorageController::class)->store())->middleware($admin);
    $app->put('/api/storage/{id:[A-Za-z0-9]+}', fn($req) => resolve(StorageController::class)->update())->middleware($admin);
    $app->post('/api/storage/{id:[A-Za-z0-9]+}/default', fn($req) => resolve(StorageController::class)->setDefault())->middleware($admin);
    $app->delete('/api/storage/{id:[A-Za-z0-9]+}', fn($req) => resolve(StorageController::class)->destroy())->middleware($admin);
    $app->get('/api/syslogs', fn($req) => resolve(SyslogController::class)->index())->middleware($admin);
    $app->get('/api/syslogs/{id:\d+}', fn($req) => resolve(SyslogController::class)->show())->middleware($admin);
    $app->get('/api/schedules', fn($req) => resolve(ScheduleController::class)->index())->middleware($auth);
    $app->post('/api/schedules/{name}/run', fn($req) => resolve(ScheduleController::class)->run())->middleware($admin);
    $app->get('/api/monitor', fn($req) => resolve(MonitorController::class)->overview())->middleware($auth);
    $app->get('/api/monitor/shards', fn($req) => resolve(MonitorController::class)->shards())->middleware($admin);
    $app->get('/api/db/tables', fn($req) => resolve(DbController::class)->tables())->middleware($admin);
    $app->get('/api/db/tables/{table}', fn($req) => resolve(DbController::class)->columns())->middleware($admin);
    $app->get('/api/db/tables/{table}/rows', fn($req) => resolve(DbController::class)->rows())->middleware($admin);
    $app->post('/api/db/query', fn($req) => resolve(DbController::class)->sql())->middleware($admin);
    $app->delete('/api/syslogs/clean', fn($req) => resolve(SyslogController::class)->clean())->middleware($admin);
    $app->get('/api/audits/mine', fn($req) => resolve(AuditController::class)->mine())->middleware($auth);
    $app->get('/api/audits/stats', fn($req) => resolve(AuditController::class)->stats())->middleware($auth);
    $app->delete('/api/audits/clean', fn($req) => resolve(AuditController::class)->clean())->middleware($admin);

    $app->get('/api/system/backup', fn($req) => resolve(SystemController::class)->backup())->middleware($admin);
    $app->post('/api/system/cache-clear', fn($req) => resolve(SystemController::class)->cacheClear())->middleware($admin);
    $app->get('/api/system/configs', fn($req) => resolve(SystemController::class)->configs())->middleware($admin);
    $app->get('/api/system/pack-info', fn($req) => resolve(SystemController::class)->packInfo())->middleware($admin);

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
        ->middleware([new AuthMiddleware(), new \app\http\middleware\ApiSignMiddleware()]);
};
