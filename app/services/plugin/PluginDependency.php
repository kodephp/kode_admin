<?php

declare(strict_types=1);

namespace app\support;

use Kode\Framework\Plugin\PluginDependencyResolver;
use Kode\Framework\Plugin\PluginDiscoverer;

/**
 * 插件依赖门禁（本站后台的继承层：图算法零改动，只注入本站的事实）。
 *
 * 依赖图解析 / 版本约束判定 / 安装门禁 / 环检测 / 下游级联闭包全部由
 * {@see PluginDependencyResolver} 提供（框架级），本类只回答三个
 * 只有本站知道的问题：
 *   - statusOf()          → 本站 kode_plugins.status（经 HotCache）
 *   - installedVersion()  → 本站 kode_plugins.version
 *   - collection()        → 继承默认，即 {@see PluginDiscoverer::discover()}
 *                           （与本站 PluginMarket::discover() 同源，无需覆盖）
 *
 * 为什么还要留这个类：既有 17+ 处调用点写的是 PluginDependency::，
 * 保留同名子类让这些调用点零改动；同时把「本站事实」收口在这一个文件里，
 * 日后想换状态表（如改成多租户注册中心）只需改此类的两个钩子，
 * 框架引擎与全部调用点不动。
 *
 * 常量说明：NAME_PATTERN / VERSION_PATTERN 继承自框架，
 * PluginContract 里仍写 PluginDependency::NAME_PATTERN——PHP 常量可经子类名访问，
 * 语义不变、真值源仍是框架那一处，不产生第二份副本。
 */
final class PluginDependency extends PluginDependencyResolver
{
    /**
     * 取插件启用状态；null 表示「无状态记录，视为已启用」。
     *
     * 接本站 kode_plugins.status 表（经 HotCache 加速，未安装行返回 null）。
     */
    protected static function statusOf(string $name): ?string
    {
        return PluginMarket::statusOf($name);
    }

    /**
     * 取插件已安装版本；'' 表示「未登记版本」。
     *
     * 空串在框架 validate() 中视为「无法验证版本约束」而拒绝安装——
     * 比旧实现的 '0.0.0' 兜底更严谨：旧写法会让 ==0.0.0 / >=0.0.0
     * 这类空约束静默通过，而真实语义是「版本未知，不能放行」。
     */
    protected static function installedVersion(string $name): string
    {
        return (string) (PluginMarket::states()[$name]['version'] ?? '');
    }
}
