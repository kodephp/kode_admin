<?php

declare(strict_types=1);

/**
 * 系统级打包配置（整站 → 单文件分发行）。
 *
 * 被 app\support\AppPackager（继承框架 Kode\Framework\Packaging\Packager）消费，
 * 是唯一 SoT：改打包行为只改这里，不要在命令或打包引擎里散落硬编码。
 *
 * 与插件级打包（PluginPackager）职责分离——本配置只管「整个系统」。
 *
 * 安全红线（务必理解后再改）：
 *   - .env 与 storage/ 永远不进包：前者含密钥，后者是运行时数据。
 *     部署约定与 webman 二进制打包一致——放在产物同目录。
 *   - 用户上传文件不得进包：phar:// 协议操作上传文件有反序列化风险。
 *   - 排除 dev 依赖目录后，autoload_files.php 必须按实际文件集重写，
 *     否则 bootstrap 硬 require 缺失文件直接致命（见 Packager 注释 5）。
 */
return [

    /* ── 打包器实现 ── */

    /**
     * 打包器实现类（须为 Kode\Framework\Packaging\Packager 或其子类）。
     * 留空即使用框架内置实现；本站走继承子类，便于后续按本站需要定制。
     * 该键只用于 Packager::driver() 解析，不进入其余配置项。
     */
    'packager' => 'app\support\AppPackager',

    /* ── 产物 ── */

    /** 产物输出目录（相对项目根）。 */
    'build_dir' => 'build',

    /** PHAR 产物名。注意：Phar::mapPhar() 的别名必须等于此 basename。 */
    'phar_filename' => 'kode.phar',

    /** 二进制产物名（micro.sfx + phar 拼接）。 */
    'bin_filename' => 'kode.bin',

    /**
     * PHAR 签名算法。SHA256 是默认值，与 webman/console 一致；
     * 若需 openssl 签名，此处传 Phar::OPENSSL 并提供 'openssl_private_key'。
     */
    'signature_algorithm' => \Phar::SHA256,

    /** openssl 签名私钥路径（signature_algorithm 为 OPENSSL 时必填）。 */
    'openssl_private_key' => null,

    /* ── 运行环境 ── */

    /**
     * 打包进程内存下限（MB）。buildFromDirectory() 仍需缓冲文件映射，
     * 默认 128M 在 44MB/6000+ 文件的 vendor 上会被 OOM 杀掉；
     * Packager 会据此 ini_set() 抬高（memory_limit 是 PHP_INI_ALL，可运行期提升）。
     */
    'memory_limit_mb' => 512,

    /** 二进制内嵌的 PHP 版本（下载 micro.sfx 用），默认取当前 PHP 主.次。 */
    'php_version' => null,

    /** micro.sfx 下载地址前缀（workerman 官方分发）。 */
    'micro_sfx_base' => 'https://download.workerman.net/php8.microsfx',

    /* ── 文件选择 ── */

    /**
     * 参与打包的顶层目录（白名单）。项目根其它内容一律不进包。
     * storage/ 刻意不在其中——它是运行时数据目录，须留在包外。
     */
    'include_dirs' => [
        'app',
        'config',
        'database',
        'lang',
        'plugins',
        'public',
        'vendor',
    ],

    /**
     * 参与打包的顶层文件（白名单）。
     * 注意 kode 是 CLI 入口薄壳，必须进包；public/index.php 在 include_dirs 内。
     */
    'include_files' => [
        'kode',
        'composer.json',
        'composer.lock',
        'LICENSE',
        'README.md',
        '.env.example',
    ],

    /**
     * 子树黑名单：命中前缀的整个目录树丢弃。
     * 前四项是 dev/元数据；后四项是纯 phpunit 依赖树。
     * 排除 phpunit 依赖树前必须先重写 autoload_files.php（Packager 自动做）。
     */
    'exclude_dirs' => [
        '.git',
        '.phpunit.cache',
        '.workbuddy',
        '.idea',
        '.vscode',
        'tests',
        'docs',
        'deploy',
        'build',
        'node_modules',
        'storage',
        'vendor/phpunit',
        'vendor/sebastian',
        'vendor/myclabs',
        'vendor/theseer',
    ],

    /** 单个文件黑名单（按 basename 匹配，任意层级）。 */
    'exclude_files' => [
        '.DS_Store',
        '.gitkeep',
        'Thumbs.db',
    ],

    /** 顶层文件黑名单（按相对路径精确匹配；这些是开发/部署工具，运行期不需要）。 */
    'exclude_top_files' => [
        '.env',
        '.gitattributes',
        '.gitignore',
        '.env.local',
        'phpunit.xml',
        'phpunit.xml.dist',
        'CHANGELOG.md',
        'AGENTS.md',
        'README-ADMIN.md',
        'Dockerfile',
        'docker-compose.yml',
        'docker-compose.yaml',
    ],

    /**
     * 扩展名黑名单（按扩展名匹配，运行期不需要的二进制/日志/大文件）。
     * 注意：不要放 .md —— include_files 里有 README.md，白名单优先于本表。
     */
    'exclude_ext' => [
        'png', 'jpg', 'jpeg', 'gif', 'ico', 'svg',
        'pdf', 'zip', 'tar', 'gz', 'rar', '7z',
        'sqlite', 'sqlite3', 'db',
        'log', 'tmp', 'swp', 'swo',
    ],

    /* ── 校验 ── */

    /**
     * 打包完成后必须存在的包内路径（缺任一项 → 构建失败）。
     * 这是「声明⟺实现」在打包上的落地：声明要能跑，就必须真的能把入口链拉通。
     */
    'must_include' => [
        'kode',
        'vendor/autoload.php',
        'vendor/kode/framework/kode',
        'public/index.php',
        'config/app.php',
    ],

    /**
     * 运行期需要为空目录存在的路径（打包不建，init/首次启动时创建）。
     * 列在这里仅用于部署文档与 verify 报告，不参与进包判断。
     */
    'runtime_dirs' => [
        'storage/logs',
        'storage/cache',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/uploads',
        'storage/doctrine/proxies',
        'storage/sessions',
        'storage/runtime',
    ],

    /**
     * 二进制头部内嵌的 php.ini 片段（webman 同款：\xfd\xf6\x69\xe6 + 长度 + 正文）。
     * 二进制不读外部 php.ini，需要自定义就写这里（换行分隔）。
     */
    'custom_ini' => null,
];
