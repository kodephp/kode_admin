<?php

/*
 * 压测路由（与裸内核 peer 同表同 SQL 横比用；压测口径见 kode 框架文档 benchmarks，不在本仓）。
 *
 * 由框架自动 glob 加载（app/routes/*.php），但**默认不注册任何端点**：
 * `BENCH_ROUTES=true` 才挂上。判据同 `C_API_REGISTER` —— 这几条是匿名可达的顶层路由
 * （排在 SPA 兜底之前，且 `/bench/db` 会真发 SQL），不该跟着生产包一起出门。
 * 压测完记得关掉；`tests/RootRouteGateTest.php` 按「默认关闭」口径锁这条。
 *
 * 用 sqlite 显式连接（库文件走 `DB_SQLITE_PATH` 配置的 sqlite 连接，见 config/database.php），
 * 避免默认 pgsql 连接影响压测口径。
 */

use Kode\Http\App;

return function (App $app): void {
    if ((bool) env('BENCH_ROUTES', false)) {
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
    }
};
