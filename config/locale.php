<?php

/*
 * 国际化配置（symfony/translation）
 *
 * enabled   ：是否启用 Accept-Language 自动选语种中间件。
 * default   ：默认语种（未匹配到可用语种时使用）。
 * available ：允许的语种白名单（Accept-Language 仅命中列表内才切换）。
 *
 * 语言资源目录：应用 lang/ → 框架内置 resources/lang/（应用覆盖框架）。
 * 文案文件形如 lang/zh-CN/messages.php，返回 ['key' => '文案 %name%']。
 */

return [
    'enabled' => false,
    'default' => env('APP_LOCALE', 'zh-CN'),
    'available' => ['zh-CN', 'en'],
];
