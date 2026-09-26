<?php

declare(strict_types=1);

namespace app\admin\controllers;

use app\support\PermCatalog;
use Kode\Framework\Http\Controller;
use app\concerns\ApiResponse;

/**
 * 权限目录：下发角色编辑器能勾到的码。
 *
 * 派生逻辑在 app\support\PermCatalog —— 那是「授予面」的唯一口，
 * 契约审计（PluginContract::auditPermGates）与这里读同一份集合。
 */
final class PermsController extends Controller
{
    use ApiResponse;

    public function catalog()
    {
        return $this->ok(PermCatalog::groups());
    }
}
