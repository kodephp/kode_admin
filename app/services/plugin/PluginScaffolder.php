<?php

declare(strict_types=1);

namespace app\support;

use Kode\Framework\Plugin\PluginDiscoverer;

/**
 * 插件脚手架：程序化生成插件骨架（类 + 清单 + 可选登记）。
 *
 * 与框架的 MakePluginCommand（v1.4.3）共享同一套命名规范化与生成逻辑，
 * 但剥离了控制台 IO 依赖，供 HTTP 控制器 / 测试 / 脚本复用。
 *
 * 命名规则（与 MakePluginCommand 一致）：
 *   - 输入 "Blog" → 文件名 "BlogPlugin.php"，类名 "Kode\Plugins\BlogPlugin"
 *   - 输入 "blog" → 文件名 "BlogPlugin.php"（自动转 StudlyCase）
 *   - 输入 "BlogPlugin" → 文件名 "BlogPlugin.php"（保留）
 *   - 输入 "blog-plugin" → 文件名 "BlogPlugin.php"（连字符转驼峰）
 *
 * 校验：插件名经 snake_case 规范化后须匹配 PluginDiscoverer::NAME_PATTERN
 * （小写字母/数字/下划线，2-32 位）。不合法抛 InvalidArgumentException。
 *
 * 只读原则：除指定的插件文件与 config/plugins.php 外不修改任何文件。
 */
final class PluginScaffolder
{
    private string $basePath;

    public function __construct(string $basePath = '')
    {
        $this->basePath = $basePath;
    }

    /**
     * 生成插件骨架。
     *
     * @param string $name     插件名（Blog / blog / BlogPlugin / blog-plugin）
     * @param bool   $register 是否写入 config/plugins.php
     * @param bool   $force    覆盖已存在文件
     * @return array{ok: bool, class: string, name: string, files: array, registered: bool, skipped: bool, message: string}
     */
    public function scaffold(string $name, bool $register = false, bool $force = false): array
    {
        $raw = trim($name);
        if ($raw === '') {
            throw new \InvalidArgumentException('插件名不能为空');
        }

        // 规范化：去掉可能的 Plugin 后缀，转 StudlyCase，再加 Plugin
        $base = $this->stripSuffix($raw, 'Plugin');
        $class = $this->studly($base);
        if (!str_ends_with($class, 'Plugin')) {
            $class .= 'Plugin';
        }

        // 校验插件名格式（与 PluginDiscoverer::NAME_PATTERN 一致）
        $pluginName = $this->snake($base);
        if (!preg_match(PluginDiscoverer::NAME_PATTERN, $pluginName)) {
            throw new \InvalidArgumentException(
                "插件名 '{$pluginName}' 不合法：须匹配 " . PluginDiscoverer::NAME_PATTERN
                . '（小写字母/数字/下划线，2-32 位）'
            );
        }

        $classPath = $this->path('plugins/' . $class . '.php');
        $manifestPath = $this->path('plugins/' . $class . '.plugin.json');

        // 检查是否已存在
        $skipped = false;
        if (is_file($classPath) && !$force) {
            $skipped = true;
        }

        // 生成插件类
        $classContent = $this->buildClassContent($class, $pluginName);
        $this->writeFile($classPath, $classContent, $force || $skipped === false);

        // 生成 plugin.json
        $manifestContent = $this->buildManifestContent($class, $pluginName);
        $this->writeFile($manifestPath, $manifestContent, $force);

        // 生成 CHANGELOG.md
        $changelogPath = $this->path('plugins/' . $class . '.CHANGELOG.md');
        $changelogContent = $this->buildChangelogContent($class, $pluginName);
        $this->writeFile($changelogPath, $changelogContent, $force);

        // 可选：写入 config/plugins.php
        $registered = false;
        if ($register && !$skipped) {
            $registered = $this->registerPlugin($class);
        }

        $result = [
            'ok' => true,
            'class' => "Kode\\Plugins\\{$class}",
            'name' => $pluginName,
            'files' => [
                'class' => $classPath,
                'manifest' => $manifestPath,
                'changelog' => $changelogPath,
            ],
            'registered' => $registered,
            'skipped' => $skipped,
            'message' => $skipped
                ? "已存在，跳过（用 force=true 覆盖）：{$classPath}"
                : "已生成插件：Kode\\Plugins\\{$class}",
        ];

        return $result;
    }

    /** 去掉末尾的 Plugin 后缀（大小写不敏感）。 */
    private function stripSuffix(string $value, string $suffix): string
    {
        if (str_ends_with($value, $suffix)) {
            return substr($value, 0, -strlen($suffix));
        }
        if (str_ends_with(strtolower($value), strtolower($suffix))) {
            return substr($value, 0, -strlen($suffix));
        }
        return $value;
    }

    /**
     * StudlyCase：create_users_table → CreateUsersTable，
     * SendNewsletter / UserController → 保持原有驼峰。
     */
    private function studly(string $value): string
    {
        $value = (string) preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', (string) $value);
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string) $value);
        $value = strtolower(trim($value));

        return str_replace(' ', '', ucwords($value));
    }

    /**
     * snake_case：CreateUsersTable → create_users_table。
     */
    private function snake(string $value): string
    {
        $value = (string) preg_replace('/(?<=\p{L})(?=\p{Lu})/u', '_', $value);
        $value = (string) preg_replace('/[^\pL\pN]+/u', '_', $value);

        return strtolower(trim($value, '_'));
    }

    /** 解析项目根。 */
    private function root(): string
    {
        return $this->basePath !== ''
            ? rtrim($this->basePath, '/')
            : (dirname(__DIR__, 2));
    }

    /** 在项目根下拼绝对路径。 */
    private function path(string $relative): string
    {
        return $this->root() . '/' . ltrim($relative, '/');
    }

    /** 递归建目录。 */
    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException("无法创建目录：{$dir}");
        }
    }

    /** 写入文件。 */
    private function writeFile(string $path, string $content, bool $force): bool
    {
        if (is_file($path) && !$force) {
            return false;
        }

        $this->ensureDir(dirname($path));
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("无法写入文件：{$path}");
        }

        return true;
    }

    /** 生成插件类内容。 */
    private function buildClassContent(string $class, string $pluginName): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace Kode\Plugins;

use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;

/**
 * {$class}（由 make:plugin 生成）
 *
 * 插件名：{$pluginName}
 * 路由前缀：/api/{$pluginName}/*（由 PluginGateMiddleware 按前缀门禁）
 *
 * 生命周期：
 *   - register()：绑定服务、注册路由/监听器/命令（框架启动期调用一次）
 *   - boot()：插件自身初始化（register 之后，路由已就绪）
 *
 * 能力声明（契约审计会校验声明⟺实现零漂移）：
 *   - requires()：依赖的插件名列表
 *   - configSchema()：配置项 schema
 *   - drivers()：提供的驱动名列表
 *   - commands()：控制台命令类列表
 *   - events()：监听的事件列表
 *   - crons()：定时任务声明
 *   - menus()：前端菜单项
 *   - permissions()：权限码声明
 */
final class {$class} implements PluginInterface
{
    public function name(): string
    {
        return '{$pluginName}';
    }

    public function register(PluginManager \$manager): void
    {
        // 注册路由（自动打 plugin:{$pluginName} 来源标签）
        \$manager->addRoute('{$pluginName}.hello', 'GET', '/api/{$pluginName}', static fn(): array => [
            'plugin' => '{$pluginName}',
            'hello' => 'world',
        ]);

        // 绑定服务到容器
        // \$manager->bind('{$pluginName}.service', static fn(): YourService => new YourService());
    }

    public function boot(PluginManager \$manager): void
    {
        // 插件启动逻辑（预热缓存、注册定时任务等）
    }

    // ── 元信息 ─────────────────────────────────────────────

    public function version(): string
    {
        return '1.0.0';
    }

    // 声明的许可证（可选，默认 'MIT'）
    // public function license(): string { return 'MIT'; }

    // 声明的作者（可选）
    // public function author(): string { return 'Your Name'; }

    // ── 生命周期 ────────────────────────────────────────────

    // 安装：建表 / 初始化（幂等，CREATE TABLE IF NOT EXISTS）
    // public function install(): void
    // {
    //     // \$db = \$this->db();
    //     // \$db->exec('CREATE TABLE IF NOT EXISTS ...');
    // }

    // 卸载：默认只停用保留数据；\$purge=true 才删表
    // public function uninstall(bool \$purge = false): void
    // {
    //     if (\$purge) {
    //         // \$this->db()->exec('DROP TABLE IF EXISTS ...');
    //     }
    // }

    // 升级：按版本差做增量变更（幂等）
    // public function upgrade(string \$from, string \$to): void
    // {
    //     // 示例：1.0.0 → 1.1.0 增加字段
    //     // if (version_compare(\$from, '1.1.0', '<')) { ... }
    // }

    // ── 数据 ────────────────────────────────────────────────

    // 插件所属表名列表（供 purge 与 pg_dump 提示）
    // public function tables(): array
    // {
    //     return [
    //         '{$pluginName}_items',
    //     ];
    // }

    // ── 配置与权限 ──────────────────────────────────────────

    // 配置项 schema（声明了就必须有非空实现，否则审计报 drift）
    // public function configSchema(): array
    // {
    //     return [
    //         'items_per_page' => ['type' => 'integer', 'default' => 20],
    //         'cache_ttl' => ['type' => 'integer', 'default' => 3600],
    //     ];
    // }

    // 权限码声明（前端菜单/按钮门禁靠此校验）
    // public function permissions(): array
    // {
    //     return [
    //         '{$pluginName}.view',
    //         '{$pluginName}.create',
    //         '{$pluginName}.update',
    //         '{$pluginName}.delete',
    //     ];
    // }

    // ── 驱动 ────────────────────────────────────────────────

    // 提供的驱动名列表（声明了就必须有非空实现，否则审计报 drift）
    // public function drivers(): array
    // {
    //     return [
    //         '{$pluginName}.driver_a',
    //         '{$pluginName}.driver_b',
    //     ];
    // }

    // ── 集成 ────────────────────────────────────────────────

    public function requires(): array
    {
        return [];
    }

    // ── 暴露：菜单 / 命令 / 事件 / 定时任务 ─────────────────

    // 前端菜单项（路径由前端路由表拼接，无需含完整 URL）
    // public function menus(): array
    // {
    //     return [
    //         [
    //             'path' => '/kode/{$pluginName}',
    //             'title' => '{$pluginName}',
    //             'icon' => 'dashboard',
    //             'children' => [
    //                 ['path' => '/kode/{$pluginName}/items', 'title' => '管理'],
    //             ],
    //         ],
    //     ];
    // }

    // 控制台命令类列表（须在 register() 里调 addCommand 才真正生效）
    // public function commands(): array
    // {
    //     return [
    //         '{$pluginName}\\Commands\\ListCommand::class',
    //     ];
    // }

    // 监听的事件列表（须在 register() 里调 addListener 才真正生效）
    // public function events(): array
    // {
    //     return [
    //         'app.ready' => 'App\\Listeners\\OnReady::class',
    //     ];
    // }

    // 定时任务声明（须在 register() 或 boot() 里调 addCron 才真正生效）
    // public function crons(): array
    // {
    //     return [
    //         [
    //             'name' => '{$pluginName}.nightly',
    //             'expression' => '0 2 * * *',
    //             'description' => '每天 2 点执行',
    //         ],
    //     ];
    // }
}

PHP;
    }

    /** 生成 plugin.json 清单内容。 */
    private function buildManifestContent(string $class, string $pluginName): string
    {
        return <<<JSON
{
    "name": "{$pluginName}",
    "version": "1.0.0",
    "class": "Kode\\\\Plugins\\\\{$class}",
    "description": "{$pluginName} 插件（由 make:plugin 生成）",
    "author": "",
    "license": "MIT",
    "changelog": "{$class}.CHANGELOG.md"
}
JSON . PHP_EOL;
    }

    /** 生成 CHANGELOG.md 内容（与版本历史功能配套）。 */
    private function buildChangelogContent(string $class, string $pluginName): string
    {
        return <<<MD
# {$class} Changelog

> 遵循 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.0.0/) 格式。

## [1.0.0] - 初始版本

### Added
- 初始插件骨架生成

MD . PHP_EOL;
    }

    /**
     * 将插件类写入 config/plugins.php 的 plugins 数组。
     * 用「括号配对」定位闭合 ]，支持单/双引号键、空数组、多行数组。
     *
     * @return bool 是否成功写入
     */
    private function registerPlugin(string $class): bool
    {
        $configPath = $this->path('config/plugins.php');
        if (!is_file($configPath)) {
            return false;
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            return false;
        }

        // 检查是否已声明
        $classRef = "\\Kode\\Plugins\\{$class}::class";
        if (str_contains($content, $classRef)) {
            return true;
        }

        // 定位 'plugins' 或 "plugins" 键及其后的开括号
        $keyPattern = '/([\'"]plugins[\'"]\s*=>\s*\[)/';
        if (!preg_match($keyPattern, $content, $m, \PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $openBracket = $m[0][1] + strlen($m[0][0]) - 1;
        $closePos = $this->findMatchingBracket($content, $openBracket);
        if ($closePos === -1) {
            return false;
        }

        $inner = substr($content, $openBracket + 1, $closePos - $openBracket - 1);
        $indent = '        ';
        if (trim($inner) === '') {
            $newInner = "\n{$indent}{$classRef},\n    ";
        } else {
            $newInner = rtrim($inner, " \t") . "\n{$indent}{$classRef},\n    ";
        }

        $newContent = substr($content, 0, $openBracket + 1)
            . $newInner
            . substr($content, $closePos);

        if (file_put_contents($configPath, $newContent) === false) {
            return false;
        }

        return true;
    }

    /** 从开括号位置向前扫描，返回匹配的闭括号位置。 */
    private function findMatchingBracket(string $content, int $openPos): int
    {
        $len = strlen($content);
        $depth = 0;
        $inSingle = $inDouble = false;
        $escape = false;
        for ($i = $openPos; $i < $len; $i++) {
            $ch = $content[$i];
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($ch === '\\' && ($inSingle || $inDouble)) {
                $escape = true;
                continue;
            }
            if ($inSingle) {
                if ($ch === "'") { $inSingle = false; }
                continue;
            }
            if ($inDouble) {
                if ($ch === '"') { $inDouble = false; }
                continue;
            }
            if ($ch === "'") { $inSingle = true; continue; }
            if ($ch === '"') { $inDouble = true; continue; }
            if ($ch === '[') { $depth++; continue; }
            if ($ch === ']') {
                $depth--;
                if ($depth === 0) { return $i; }
            }
        }
        return -1;
    }
}
