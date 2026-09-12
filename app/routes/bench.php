<?php

/*
 * 压测路由（与 workman 裸内核 peer 同表同 SQL 横比，见 docs/benchmarks.md §八）。
 *
 * 由框架自动 glob 加载（app/routes/*.php），新增文件即生效，无需登记。
 * 用 sqlite 显式连接，避免默认 pgsql 连接影响压测。
 */

use Kode\Http\App;

return function (App $app): void {
    // 真实查询：主键随机点查 1000 行表，对端 peer /bench-db 同 SQL 同 JSON 形态。
    $app->get('/bench/db', function () {
        $id = random_int(1, 1000);
        $rows = \Kode\Database\Db\Db::connection('sqlite')
            ->select('SELECT * FROM bench_users WHERE id = ?', [$id]);

        return $rows[0] ?? ['id' => 0, 'name' => '', 'age' => 0];
    });

    // 同尺寸静态包（隔离变量：与 /bench/db 同等 JSON 体积，不查库）。
    $app->get('/bench/static', fn() => ['id' => 645, 'name' => 'user-645', 'age' => 33]);

    // JSON 数据处理：50 条记录动态构造 + 编码（对端 peer /bench-json 同语义）。
    $app->get('/bench/json', function () {
        $list = [];
        for ($i = 0; $i < 50; $i++) {
            $list[] = ['id' => $i, 'name' => 'user-' . $i, 'active' => ($i % 2 === 0)];
        }

        return ['data' => $list];
    });
};

