<?php

declare(strict_types=1);

namespace app\support;

use Kode\Framework\Packaging\Packager;

/**
 * 本站打包器：继承框架 {@see Packager}，只做「应用声明」，不复制引擎逻辑。
 *
 * 打包引擎全部在框架层 `Kode\Framework\Packaging\Packager`：
 *   - PHAR 构建（暂存目录 + buildFromDirectory，绕开 OOM）
 *   - composer autoload 三件套重写（autoload_static / autoload_files / autoload_classmap）
 *   - micro.sfx 下载与二进制拼接（含 custom-ini 头）
 *   - 产物校验（签名 / 桩 / mapPhar 别名 / 必备入口链）
 *
 * 三档分发形态（能力递增，代价递增）：
 *   A. PHAR   build/kode.phar   需目标机有 PHP        任意平台
 *   B. 二进制 build/kode.bin    无需 PHP（内嵌 micro.sfx）仅 x86_64 Linux
 *   C. 镜像   Dockerfile        PHP 在镜像内         任意 Linux
 *
 * 继承生效路径（声明⟺实现，改一处即全局生效）：
 *   config/packaging.php 的 'packager' 键 → Packager::driver() 解析到本类 →
 *   框架内置命令 pack:phar / pack:bin / pack:verify 经本类驱动。
 *
 * 定制方式（按侵入性从低到高）：
 *   1. 改 config/packaging.php——文件名、白/黑名单、内存、签名算法，零代码；
 *   2. 在本类覆盖 projectRoot() / config() 两个静态钩子——换产物根或配置源；
 *   3. 覆盖 engine 方法（collect / pack / toBin / verify）——改行为本身。
 * 不要在框架引擎里改本站逻辑；框架引擎是共享层。
 *
 * 与插件级打包（PluginPackager，单插件 zip）职责分离：本类只负责「整个系统」。
 */
final class AppPackager extends Packager
{
}
