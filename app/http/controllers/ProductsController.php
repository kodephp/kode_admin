<?php

declare(strict_types=1);

namespace app\http\controllers;

use Kode\Framework\ApiDoc\Attributes\OpenApi;
use Kode\Framework\ApiDoc\Attributes\OpenApiParameter;
use Kode\Framework\ApiDoc\Attributes\OpenApiResponse;
use Kode\Framework\Http\Attributes\Controller as RouteController;
use Kode\Framework\Http\Attributes\Delete;
use Kode\Framework\Http\Attributes\Get;
use Kode\Framework\Http\Attributes\Post;
use Kode\Framework\Http\Controller;
use Kode\Limiting\Attribute\RateLimit;

/**
 * 属性路由示例控制器。
 *
 * 本类演示「自动路由匹配」：用 #[Controller] / #[Get] 等属性声明意图，
 * 框架启动时自动发现并注册，无需在 routes.php 里逐条手写。
 * 方法上的 name 仍支持命名路由（route('product.show', ['id' => 1])）。
 *
 * 同时演示声明式限流 #[RateLimit]：类级规则对所有方法生效，方法级规则叠加。
 * 把 config/limiting.php 的 driver 改为 redis 即让这些限额变为分布式。
 *
 * 可用 `php kode route:list` 查看自动登记的路由。
 */
#[RouteController(prefix: '/products')]
#[RateLimit(capacity: 100, rate: 5.0, key: 'products:{ip}')]
final class ProductsController extends Controller
{
    /**
     * GET /products
     */
    #[Get('')]
    #[RateLimit(capacity: 20, rate: 1.0, key: 'products:list:{ip}')]
    public function index()
    {
        return $this->json([
            ['id' => 1, 'name' => 'Kode 键盘'],
            ['id' => 2, 'name' => 'Kode 鼠标'],
        ]);
    }

    /**
     * GET /products/{id:\d+}  （命名路由 product.show）
     */
    #[Get('/{id:\d+}', name: 'product.show')]
    #[OpenApi(
        summary: '获取商品详情',
        description: '按商品 ID 返回商品信息',
        tags: ['product'],
        parameters: [
            new OpenApiParameter('fields', 'query', 'string', description: '逗号分隔的返回字段，如 id,name'),
        ],
        responses: [
            200 => new OpenApiResponse(
                200,
                'OK',
                properties: [
                    'id'   => ['type' => 'integer', 'description' => '商品 ID'],
                    'name' => ['type' => 'string', 'description' => '商品名称'],
                ],
                example: ['id' => 1, 'name' => 'Kode 键盘'],
            ),
            404 => new OpenApiResponse(404, '商品不存在'),
        ],
    )]
    public function show()
    {
        $id = (int) $this->param('id');

        return $this->json(['id' => $id, 'name' => '商品 #' . $id]);
    }

    /**
     * POST /products
     */
    #[Post('')]
    public function store()
    {
        $data = $this->validate($this->input(), [
            'name'  => 'required|min:2|max:50',
            'price' => 'required|numeric|min:0',
        ]);

        return $this->json($data);
    }

    /**
     * DELETE /products/{id:\d+}
     */
    #[Delete('/{id:\d+}')]
    public function destroy()
    {
        $id = (int) $this->param('id');

        return $this->json(['id' => $id]);
    }
}
