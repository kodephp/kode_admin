<?php

declare(strict_types=1);

namespace app\api\http\controllers;

use app\http\concerns\ApiResponse;
use Kode\Framework\Http\Controller;

/**
 * C 端预留控制器（示例）。
 */
final class PingController extends Controller
{
    use ApiResponse;

    public function ping()
    {
        return $this->ok(['pong' => true, 'app' => 'api']);
    }
}
