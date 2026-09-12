<?php

declare(strict_types=1);

namespace Kode\Plugins;

use app\admin\http\middleware\RequirePerm;
use Kode\Framework\Http\Middleware\AuthMiddleware;
use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;
use Kode\Plugins\notices\NoticeController;

/**
 * 示例业务插件：公告管理。
 *
 * 插件开发约定（本后台采用）：
 *  - 路由前缀统一 `/api/<plugin>/...`，读操作挂鉴权，写操作再套超管门禁；
 *  - 可选实现 `menus(): array`（[title, path, icon]），菜单聚合接口自动收录供前端动态路由；
 *  - 开关：在 config/plugins.php 增删本类 + `kode reload`（或重启）生效。
 */
final class NoticePlugin implements PluginInterface
{
    public function name(): string
    {
        // 约定：插件名恒等于其 URL 前缀（/api/<name>/*），供运行时门禁匹配。
        return 'notices';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    /** @return list<string> 插件所属表（备份提醒 + purge 清理依据） */
    public function tables(): array
    {
        return ['kode_notices'];
    }

    /** 安装：建表（幂等，可重入）。 */
    public function install(): void
    {
        \Kode\Database\Db\Db::statement(
            'CREATE TABLE IF NOT EXISTS kode_notices (
                id BIGSERIAL PRIMARY KEY,
                title VARCHAR(128) NOT NULL,
                body TEXT NOT NULL,
                status SMALLINT NOT NULL DEFAULT 1,
                created_by VARCHAR(64) NOT NULL DEFAULT \'\',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )'
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
            \Kode\Database\Db\Db::statement("DROP TABLE IF EXISTS {$table}");
        }
    }

    /** 升级钩子（按版本差执行增量变更，幂等）。 */
    public function upgrade(string $from, string $to): void
    {
    }

    public function register(PluginManager $manager): void
    {
        $auth = new AuthMiddleware();
        // 权限码门禁：声明了 permissions() 就必须真在校验（否则权限目录勾了也白勾）
        $view   = new RequirePerm('notices:view');
        $create = new RequirePerm('notices:create');
        $update = new RequirePerm('notices:update');
        $delete = new RequirePerm('notices:delete');

        $manager->addRoute('notice.index', 'GET', '/api/notices', [NoticeController::class, 'index'])
            ->middleware($auth, $view);
        $manager->addRoute('notice.export', 'GET', '/api/notices/export', [NoticeController::class, 'export'])
            ->middleware($auth, $view);
        $manager->addRoute('notice.show', 'GET', '/api/notices/{id:[A-Za-z0-9]+}', [NoticeController::class, 'show'])
            ->middleware($auth, $view);
        $manager->addRoute('notice.store', 'POST', '/api/notices', [NoticeController::class, 'store'])
            ->middleware($auth, $create);
        $manager->addRoute('notice.update', 'PUT', '/api/notices/{id:[A-Za-z0-9]+}', [NoticeController::class, 'update'])
            ->middleware($auth, $update);
        $manager->addRoute('notice.destroy', 'DELETE', '/api/notices/{id:[A-Za-z0-9]+}', [NoticeController::class, 'destroy'])
            ->middleware($auth, $delete);
    }

    public function boot(PluginManager $manager): void
    {
    }

    /**
     * 权限码声明（供权限目录按插件分组，角色勾选用）。
     * 契约红线：menus() 里出现的 auth_code 必须在这里声明。
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return ['notices:view', 'notices:create', 'notices:update', 'notices:delete'];
    }

    /**
     * @return list<array{title: string, path: string, icon: string}>
     */
    public function menus(): array
    {
        return [
            ['title' => '公告管理', 'path' => '/notices', 'icon' => 'notification', 'auth_code' => 'notices:view', 'parent' => '/cms'],
        ];
    }
}
