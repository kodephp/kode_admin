<?php

declare(strict_types=1);

/*
 * uni3-lowcode 前端壳对接配置
 *
 * 消费方：
 *  - plugins/FrontendPlugin.php（manifest / schema / presets 三个 Runtime 端点读 preset 目录）；
 *  - plugins/frontend/LicenseService.php（license 密钥对落盘目录）。
 */

return [
    /*
     * preset 页面清单目录：内含 index.json 与 {pageId}.json。
     * 这些 JSON 由 uni3 侧构建产出，属于外部产物，不随本项目入库，因此部署时必须配成本机实际路径；
     * 留空回落到项目根 uni3-presets 目录（通常不存在 → presets 端点返回空列表，schema 端点 404，
     * 属可诊断的失败，不会抛异常）。配置了就不静默改道：路径不存在也照该路径读，避免配错被兜底伪装成有数据。
     */
    'preset_dir' => env('UNI3_PRESET_DIR', ''),

    /*
     * 低代码授权密钥目录（签发/校验用 keypair 落盘位置）。
     * 留空 = LicenseService 用 storage/keys/lowcode-license；密钥目录属敏感位置，
     * 生产应指向项目外并由部署方收紧权限，且不得进版本库。
     */
    'license_key_dir' => env('UNI3_LICENSE_KEY_DIR', ''),
];
