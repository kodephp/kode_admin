<?php

declare(strict_types=1);

namespace Kode\Plugins;

use app\admin\middleware\RequirePerm;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Middleware\AuthMiddleware;
use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;
use Kode\Plugins\frontend\GiteeReleaseController;

/**
 * GiteeRelease 插件：从 gitee 私有仓库下载编译后的前端资产。
 *
 * 用途：
 *  - 关联用户 gitee 私有仓库（repo + token + branch）
 *  - 下载 release assets（编译后的前端产物 zip/tar）
 *  - SHA256 完整性校验
 *  - 解压安装到 public/assets/lowcode-shell/v{version}/
 *  - 版本检查 + 更新管理
 *
 * 路由前缀：/api/gitee-release/*
 * 权限门禁：gitee:view（查看）/ gitee:edit（配置+安装）
 */
final class GiteeReleasePlugin implements PluginInterface
{
    public function name(): string
    {
        return 'gitee-release';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    /** @return list<string> */
    public function tables(): array
    {
        return ['kode_gitee_releases', 'kode_gitee_downloads'];
    }

    public function install(): void
    {
        // 1) 仓库配置表
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

        // 2) 下载记录表
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
    }

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

    public function upgrade(string $from, string $to): void
    {
        if (version_compare($from, '1.0.0', '<')) {
            $this->install();
        }
    }

    public function register(PluginManager $manager): void
    {
        $auth = new AuthMiddleware();
        $view = new RequirePerm('gitee:view');
        $edit = new RequirePerm('gitee:edit');

        // 仓库 CRUD
        $manager->addRoute('gitee.repos.index', 'GET', '/api/gitee-release/repos', [GiteeReleaseController::class, 'reposIndex'])
            ->middleware($auth, $view);
        $manager->addRoute('gitee.repos.store', 'POST', '/api/gitee-release/repos', [GiteeReleaseController::class, 'repoStore'])
            ->middleware($auth, $edit);
        $manager->addRoute('gitee.repos.update', 'PUT', '/api/gitee-release/repos/{id:[A-Za-z0-9]+}', [GiteeReleaseController::class, 'repoUpdate'])
            ->middleware($auth, $edit);
        $manager->addRoute('gitee.repos.destroy', 'DELETE', '/api/gitee-release/repos/{id:[A-Za-z0-9]+}', [GiteeReleaseController::class, 'repoDestroy'])
            ->middleware($auth, $edit);

        // Release 管理
        $manager->addRoute('gitee.releases.list', 'GET', '/api/gitee-release/repos/{id:[A-Za-z0-9]+}/releases', [GiteeReleaseController::class, 'releasesList'])
            ->middleware($auth, $view);
        $manager->addRoute('gitee.releases.check', 'GET', '/api/gitee-release/repos/{id:[A-Za-z0-9]+}/check-updates', [GiteeReleaseController::class, 'checkUpdates'])
            ->middleware($auth, $view);

        // 下载 + 安装
        $manager->addRoute('gitee.downloads.list', 'GET', '/api/gitee-release/repos/{id:[A-Za-z0-9]+}/downloads', [GiteeReleaseController::class, 'downloadsList'])
            ->middleware($auth, $view);
        $manager->addRoute('gitee.downloads.install', 'POST', '/api/gitee-release/repos/{id:[A-Za-z0-9]+}/install', [GiteeReleaseController::class, 'installRelease'])
            ->middleware($auth, $edit);
        $manager->addRoute('gitee.downloads.test', 'POST', '/api/gitee-release/repos/{id:[A-Za-z0-9]+}/test', [GiteeReleaseController::class, 'testConnection'])
            ->middleware($auth, $edit);

        // 本地已安装版本
        $manager->addRoute('gitee.versions.installed', 'GET', '/api/gitee-release/versions', [GiteeReleaseController::class, 'installedVersions'])
            ->middleware($auth, $view);
    }

    public function boot(PluginManager $manager): void
    {
        // 预热：检查是否有配置了自动更新的仓库
    }

    public function permissions(): array
    {
        return ['gitee:view', 'gitee:edit'];
    }

    public function menus(): array
    {
        return [
            [
                'title' => '前端资产仓库',
                'path' => '/gitee-release',
                'icon' => 'lucide:github',
                'auth_code' => 'gitee:view',
                'parent' => '/system',
            ],
        ];
    }
}
