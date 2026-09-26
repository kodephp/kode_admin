# AGENTS.md — Kode 通用后台开发约束（IDE / 模型必读）

> 本文件是本项目唯一开发规范入口。换 IDE 或换模型时，把本文件全文喂给它即可开工。

## 1. 项目速览

- 后端：PHP >= 8.3 常驻多进程（`php kode start`，默认 127.0.0.1:9527，11 worker）。
- DB：**pgsql 为主**（FlyEnv PostgreSQL 18，root 空密码，库 `kode_app`），sqlite 仅压测备用。
- ORM：**现状是混层，别照文档的理想态写**。实测口径（2026-09 重测，数字会漂，结论不会）：
  `app/models/` 只有 1 个实体（User）、`app/repositories/` 只有 1 个仓储，而 45 张 `kode_*` 表里
  只有它被映射；47 个控制器文件里 38 个直接走 `Db::`。所以「默认 Doctrine、新代码一律走仓储」
  这句话在仓库里从未落地 —— 照着写会给一张表配一套实体+仓储，与它所在文件的既有风格分叉，
  而 §3 的分层示例、分页口径与全部回归测试都建立在 `Db::` 上。
  现在的规矩：**新代码跟随所在层的既有意图** —— 控制器零业务、业务规则进 `app/services/`
  （没有对应 Service 的聚合就先建一个再写），持久化在 Service 里用 `Db::` + 绑定参数；
  要把某个聚合迁到实体+仓储时整块迁并补测试，不要在同一个 Service 里一半 ORM 一半裸 SQL。
  （`Db::` 查询构建器不是「仅存量可用」：它是当前唯一的持久化面，§3 的分层与门禁都按它写。）
- 前端：管理 Web 用 Vue3+Element Plus 系 SPA（见 §7）；C 端多端用 uni-app x。后端只出 JSON。

## 2. 目录铁律（违反直接返工）

> 位置约定：本项目 = `composer/kode_test`；kode 框架及其拆分包 = 同级目录 `composer/<pkg>`；管理前端 = `composer/admin-web`。
> 只改本项目的代码，包的问题一律去源码仓库改并发版（详见本节末 vendor 规则）。

- `app/` 下目录全小写，类文件首字母大写驼峰；命名空间全小写且与目录一一对应。
- 多应用布局（框架自动发现，无需配置）：
  `app/admin/` = 后台子应用（路由 `app/admin/routes.php`，标签 `app:admin`），
  `app/api/` = C 端子应用（路由 `app/api/routes.php`，前缀 `/c-api/*`，标签 `app:api`）；
  控制器在各应用 `controllers/` 下，命名空间 `app\<应用>\controllers\...`。
  全局共享（中间件/trait/实体/仓储/support）放 `app/middleware/`、`app/concerns/` 等顶层目录。
- 控制器方法无返回类型声明（返回 Response/数组均可，**不要写 `: array`**）。
- 用 `Request::` 门面必须 `use Kode\Http\Request;`（否则回退到当前命名空间 fatal）；收参优先控制器短方法。
- 插件：`plugins/<Name>Plugin.php` 实现 `PluginInterface`，路由前缀 `/api/<插件>/`，`config/plugins.php` 登记。
  插件名恒等于 URL 前缀（`name()` 返回值即 `/api/<name>` 的 name，供运行时门禁匹配）；
  插件控制器放 `plugins/<name>/`（命名空间 `Kode\Plugins\<name>`）；
  插件 `menus()` 声明支持 `auth_code`（权限码，空=登录可见）与 `parent`（挂到的目录 path，如 `/cms`）；插件类可选 `version()`（插件管理页展示）。
  插件表名沿用 §3 的全局 `kode_` 前缀，再带自己的名字段隔开（`kode_notices`、`kode_wallets`、`kode_lowcode_*`、`kode_gitee_*`）；
  历史文档里写过的 `plug_<name>_` 前缀**从未落地**，代码里一张 `plug_` 表都没有，别照抄。
  插件的「SQL 引用的表 = `install()` 建的表 = `tables()` 声明的表」必须三处对齐：建了没声明则 purge 删不掉、
  备份抓不到；查了没人建则全新环境装完即 `relation does not exist` 500（开发库手工建过永远复现不出）；
  声明了没人建则 purge 会 DROP 一张不属于本插件的表。加表时 `install()`+`tables()`+`upgrade()`（幂等回填）一起改，
  静态门禁见 `tests/PluginSchemaCoverageTest.php`。
  还有第四种坏法是三处对齐**完全看不见**的：名字只差 `kode_` 前缀。`tables()` 声明 `kode_notices`、
  install() 建的也是它，而 `locate()` 的未分片分支返回了裸 `'notices'` —— 分片开关同时决定写侧落点和读侧表名，
  两侧各写一遍就会漂，于是默认配置（`SHARD_ENABLED=false`）下列表读得出、详情/改/删全部 500，
  而本机 `.env` 是开着分片的，所以只有把开关真的拨到另一侧才看得见（行为断言 `tests/NoticeSingleTableTest.php`）。
  所以**插件的表名只写一份**（控制器里的 `private const TABLE`，SQL 与 `Shard::ensureTables()`/`onShard()` 全走它）；
  前缀缺失的静态口径见 `PluginSchemaCoverageTest::test_no_plugin_references_its_own_table_without_the_prefix`
  —— 它扫 `plugins/<name>/` 下的文件（主类里的 `'notices'` 是 `name()`/路由前缀，属正当约定）。
- 插件市场：打包 `GET /api/plugins/{name}/package`，远程安装 `POST /api/plugins/install-remote`
 （sha256 强制校验，20MB 上限，落盘不覆盖，装完 reload 生效）；协议见 `docs/MARKETPLACE-PROTOCOL.md`。
- 实体：`app/models/`，Attribute 注解映射；时间字段一律 `timestamptz_var` 类型 + `TIMESTAMPTZ` 列。
- 新增类无需 `composer dump-autoload`（PSR-4 即时加载），但改完必须重启服务（路由/插件启动期加载）。
- `vendor/` 是 composer 的领地，**禁止手改任何包**（包括 `vendor/composer/installed.json|php` 这类元数据）。
  kode 框架/包的问题一律改同级源码仓库 `/Users/Zhuanz/Desktop/website/composer/<pkg>`（目录名 = 包名尾段，
  如 `../framework`、`../router`、`../jwt`、`../cache`），在那里跑它自己的测试。
  目录名与包名不一致的三个例外（别照尾段猜，会走错仓库）：`../application` = kode/core、
  `../websocket` = kode/messaging、`../msg` = kode/tools；要确认归属就看各仓库 composer.json 的 name 字段。
- 依赖恒取最新：kode/* 一律跟到各包**已发布的最新 tag**，排查任何包侧问题前先确认不是版本落后。
  核对命令：`composer outdated --no-audit | grep ^kode/`（受约束过滤，只比同主版本）+
  逐包比对上游 tag（权威）：`composer show | grep -oE "^kode/[a-z-]+ +[0-9.]+" | while read pkg ver; do
  tail=${pkg#kode/}; latest=$(git ls-remote --tags --refs "https://github.com/kodephp/${tail}.git"
  | sed 's|.*refs/tags/||' | grep -E '^v?[0-9]+\.[0-9]+' | sed 's/^v//' | sort -V | tail -1);
  [ "$latest" != "$ver" ] && echo "$pkg $ver -> $latest"; done`（无输出即全最新）。
  落后就先 `composer update kode/<pkg>` 再复现；升级后按 §6 跑回归。
- 包/框架改动的发布闭环（缺一不可）：源码 + 测试 → **更新文档**（包内 README 用法段 + 更新日志条目，
  涉及对外行为的同步 `../kode框架文档/docs/` 对应篇目）
  → **版本号**（有 `composer.json` `version` 字段的包，须与类里的 `VERSION` 常量一致；
  纯 tag 驱动的包（如 router）打 tag 即可，缺一份就漂移）
  → `git commit` + `git tag vX.Y.Z` → **推送分支与 tag 到该包仓库**。
  只改不推等于没改：应用侧 `composer update kode/<pkg>` 只能取到远端已发布的 dist。
  应用侧升级命令：`composer update --no-audit kode/<pkg>:<版本>`（GitHub 认证用
  `COMPOSER_AUTH='{"github-oauth":{"github.com":"<token>"}}'`）；装完必须 `php kode restart --port 9527 -d` + `composer test` 回归。
  自查 vendor 是否被污染：`git -C ../<pkg> archive <tag> | tar -x -C <tmp>` 再 `diff -rq <tmp> vendor/kode/<pkg>`，
  应无差异（`.gitattributes` 的 export-ignore 使两者天然同构）；有差异即说明有人手改过，用 `composer reinstall kode/<pkg>` 归位。

## 3. 后端开发规范（四层架构，详见 docs/ARCHITECTURE.md）

- 分层：Controller（收参/校验/调服务/包信封，零业务）→ Service（`app/services/`，业务规则+编排，
  抛 `RuntimeException(msg, httpCode)`）→ Repository（只做 persistence）→ Model（映射+脱敏无逻辑）。
  跨模块调 Service，跨插件走事件；Service 方法即事务边界候选。
- Service 拆分线：单个 Service 超 ~500 行按聚合拆（拆分方向 = 业务聚合边界，必要时升为插件）；
  禁止跨聚合直接调库，跨聚合读走对方 Service 读方法、写走事件。
- 协变逆变：fluent setter 返回 `static`（非 `self`，子类链式安全）；重写方法参数只放宽、返回只收窄；
  工厂/命名构造返回 `static`；接口一旦发布不再收窄。

- **`Db::table()` 交回的是关联数组，不是实体**（`QueryBuilder::get(): array`、`first(): ?array` 就是签名）。
  按对象读它有两种坏法，而且**都不报错**：`$row->task_name` 在数组上取属性只是 PHP 8 的一条 warning +
  值变 null，于是「列表渲染成 N 行全空白、一行数据都不报」；一旦把它交给带 `object` 声明的参数
  （`formatFlag(object $row)`、`logChange(..., ?object $before)`）就是 `TypeError`，
  而 `\TypeError` 是 `\Error` 不是 `\Exception` —— 外围 `catch (\Throwable)` 若在**方法体外**才兜得住，
  写在方法体内的 `try/catch` 反而常把它吞成半套状态（**实测：库里已经改了，客户端看到 500**）。
  最隐蔽的一支是 `(bool) $row->enabled` 拿到 null：`check()` 对任何开关恒回 `disabled`、
  `stats()` 把启用中的数成 disabled、`toggle()` 的 `!null` 恒 true 即**只有关没有开**。
  本仓 `app/` 下真正调用它的只有两个控制器（`FeatureFlagController`、`ScheduleController::runs()`），
  两个都曾被当成对象读，而这两张表在开发库恰好是空的 —— 所以**每个入口一条行为断言**
  （塞一行真数据进去，要求读出来的是那行数据），而不是正则门禁：回归 `tests/QueryBuilderRowShapeTest.php`。
  断言要**逐字段**：`stats()` 的「最近变更」当时只有 `key` 被改对了，`updated_at` 那一行还留着
  `$row->updated_at ?? ''`（`??` 连 warning 都不给，恒回空串），而用例只查 `key` 就整条绿了 ——
  页面上那一列永远是空白。
- **布尔列的写入要靠 `kode/database >= 1.26.0`**：此前五条语句路径都走 `PDOStatement::execute($bindings)`，
  那条 API 把每个值都按字符串绑，PHP 的 `false` 到 pgsql 是空串 ——
  写 boolean 列与 `WHERE flag = ?` 一律 `SQLSTATE[22P02]`，而 `true` 侥幸能过（`'1'` 是合法的 boolean 字面量），
  所以症状是「**新建正常、一关就 500**」，看起来像业务 bug（实测过的现场就是上一条的 `toggle()`）。
  sqlite 侧不报错但存成文本 `''`，同一份代码在两种方言下落两种值。
  结论不是「自己去绕」而是升级依赖：包侧现在只有一个绑定派发口（`bool → PARAM_BOOL`、
  `null → PARAM_NULL`、其余 `PARAM_STR`）。排查同类问题时先用 `typeof(col)` / `pg_typeof` 问库里**实际**存了什么。

- 收参用控制器短方法：`$this->input/query/post/params/only/param`；读 header/文件用 `$this->request()`。
- 校验用 `$this->validate($data, $rules)`（Symfony Validator 字符串管道），失败自动 422，**不要手写 try/catch 包格式**。
  `min:`/`max:` 的含义由**声明类型**决定（framework >= 1.11.0）：规则里带 `integer`/`numeric`、或值本身是 int/float，
  才按数值比大小；其余（含 `'20260101'` 这类**纯数字串**）按字符长度。旧版本反过来 —— 纯数字串一律按数值比，
  于是 `appid => 'max:64'` 会把 8 位数字的应用 ID 误拒成「大于 64」。所以**挡列宽一律写 `length:N`**：
  它任何版本都按字符数计、且天然跳过缺键（PUT 的「缺键保留原值」不必再写一遍判空）。
  注意校验调用不能落在 `catch (\RuntimeException)` 里面 —— `ValidationException` 继承它，422 会被重写成 500。
  列宽数字与迁移/`schema.sql` 同源（pgsql `VARCHAR(n)` 计字符不计字节）；同名列在两表宽度不同时
  必须分两份规则，合成一份只能取宽的，于是窄的那张表照样撞 22001。
- **「剪到某个宽度」只有一个实现：`app\support\Clip`**（`chars()` 留头、`tail()` 留尾，都按字符）。
  写侧**不剪**：列宽交给上一条的 `length:N`，越界回 422。此前全仓三十多处 `substr($v, 0, 64)` 把
  `max:64`（当时按字符放行 64 个字）之后再来一次**按字节**截，一个汉字 3 字节，切点落在第 21 个字中间，
  绑进 SQL 是 `SQLSTATE[22021]`（非法 UTF-8）而不是「太长」，于是校验放行、落库却炸。
  三种后果分别对应三处现场：读侧筛选（一枚 `?keyword=` 把列表打成 500）、
  旁路日志（`AuditTrail`/`logLogin`/`SysLog` 的 `catch (\Throwable) {}` 把 INSERT 失败吃成「什么都没发生」，
  爆破尝试因此**从日志里消失**且接口照回 200）、出参展示（`DbController` 那一列 `substr($v,0,2000)` 切坏之后
  `kode/http` 的 `Response::json()` 带 `JSON_THROW_ON_ERROR`，**一个格子让整份响应 500**）。
  两条容易漏的口径：① 展示截断必须**按字符判长**且只在真的截短时补省略号 ——
  `strlen($v) > 2000` 时截 2000 字节，667 个汉字的值既没被截也带着省略号（那是「看着像全量」的假数据）；
  ② 剪出来的尾巴还要进响应的一律走 `Clip`，`Mask` 的「留首 3 末 4」曾是 `substr($phone, -4)`，
  多字节值同样会切成非法 UTF-8。批量导入不许静默截断：切掉的部分没人会发现，跳过的行要带行号和原因。
  白名单是**解析**而非截断的字节切片（tar 头、密文偏移、md5/hex 前缀、`u_`/`media:` 剥前缀、`strpos` 定位），
  逐条登记在 `tests/ColumnClipTest.php` 的 `CLIP_PARSE_ALLOWLIST`（只能缩短，新增要先回答「为什么不能按字符」）。
  门禁与四种形状的行为断言见 `tests/ColumnClipTest.php`：静态那条把注释先 `token_get_all()` 剥掉、
  按括号配对切参数（`substr($a, 0, strpos($b, ','))` 的逗号不能算分参），
  并且两头都验 —— 越界的新写法必须点名、白名单里已消失的条目也必须点名（留着就会掩盖新的同名违规）。
- 返回一律走统一信封（短字段，`app/support/ApiEnvelope.php`）：
  成功 `{code:HTTP状态(默认200), msg:"ok", dur:"0.0003s", data?:…}`，
  失败 `{code:HTTP状态, msg, dur, errors?}`，分页同级加 `total/page/size`（`data` 为当页数组）。
  控制器用 trait 方法 `$this->ok($data,$status)/$this->fail($msg,$status)/$this->page()`，**禁止自造其他格式**。
  校验失败由 `TimingMiddleware` 自动转 422 信封（含 `errors`）。
  框架层直返错误（鉴权 401、门禁 403、404 等 `{message}` 形态）由同一中间件出口归一化进信封。
  归一化只换壳、**不许吃载荷**：`message`/`errors` 之外的键必须搬进 `data` 一起出，
  「4xx + 附带详情」（带冲突清单的 409、批量结果）到客户端只剩「请求失败」四个字等于没说。
  反例是这条线的由来：`/health` 不健康时回 503，被归一化剥成空壳后，报告**恰好在它唯一被读的那次**失踪。
  所以健康/就绪这类**自带响应形状的端点**（`TimingMiddleware::SHAPE_STABLE`，现为 `/health`、`/health/ready`）
  恒透传：判定和状态码照常是 503，明细永不外发由探针侧的脱敏负责（§4）。
  回归见 `tests/ErrorResponseEnvelopeTest.php`（含「只有 message 时结果逐字节不变」的反向对照）
  与 `tests/HealthDegradedReportTest.php`（换成真失败探针验判定，别只信恒绿的 200）。
- **失败分支不能从 `ok()` 发出去**。`ApiEnvelope::ok()` 里是 `['code'=>…,'msg'=>'ok',…] + $extra`，
  PHP 的 `+` **保留左边**，所以 `$extra['msg']` 被整份丢掉 —— `ok(['data'=>null], 400, ['msg'=>'workflow 参数必填'])`
  到客户端是 `HTTP 400 {"code":400,"msg":"ok","data":{"data":null}}`：状态码对、**原因没了**，
  vben 的请求层按 `code∉[200,300)` 弹提示，于是弹的是一句「ok」，而 `data.data` 那层重复包裹
  又让视图只能写 `(res as any).data?.data ?? res` 去猜形状。`plugins/frontend/WorkflowBridgeController.php`
  里曾有 13 处这么写（`alertRuleSave` / `alertRuleDelete` / `executionTimeline` / `replayComparison`
  / `changeImpact` / `configHistory`），而同一文件里已有 109 处走 `$this->error($msg, $status)`
  （框架层 `{message}`，由上一条的中间件出口归一化进信封，状态码与原因都保住）—— 分岔才是根因，
  **一个文件里的错误派发口只能有一个**。写失败响应只有两条路：控制器层 `$this->error(原因, 状态码)`，
  或 `$this->fail(原因, 状态码, $extra)`（要带附带详情走 `$extra`，别塞 `msg`）。
  门禁见 `tests/ErrorEnvelopeDispatchTest.php`：静态那条扫 `app/` + `plugins/` 全量非注释代码
  （按括号配对切参数、只认**字面量 4xx/5xx 状态位**，`ok($x, $status)` 这种运行时状态不算命中），
  正对照是一段 heredoc 探针（两个违规必须被点名、一个 `ok(..., 200, ['msg'=>…])` 必须放行），
  行为断言拿五个只读 handler 真发请求，钉「`msg` 不得等于 `ok`」而不是钉具体文案
  （钉原文会让下次改提示语变成假红）。测试**故意绕过** `TimingMiddleware` 直接调 handler ——
  否则两个派发口在出参上就分不出来了。
- 常用 code/msg（code 恒等于 HTTP 状态，可按业务替换增改）：
  200 ok · 201 已创建 · 400 参数错误 · 401 未认证 · 403 权限不足 ·
  404 不存在 · 409 已存在 · 422 参数校验失败 · 429 过于频繁 · 500 服务器错误。
- 字段一律短命名：`code/msg/dur/data/total/page/size/errors`（`duration` 这类长词禁用）。
- 密码一律 `password_hash` / `password_verify`；对外数组永不含 password（实体 `toArray()` 已脱敏）。
- 守卫顺序：`AuthMiddleware` 在外，`RequireRole('slug')` 在内；超管专属操作控制器内二次确认（如初始账号不可删）。
- **顶层路由只允许 `GET /health` 一条**，门禁见 `tests/RootRouteGateTest.php`。
  这条线曾经不在任何门禁的射程内（`CoreWriteGate`/`CoreReadGate` 扫 `app:admin`、`ApiWriteGate` 读 `app:api`、
  `PluginAnonymousRouteGate` 扫 `plugin:*`），骨架就在这个空档里长期挂着免密签发 `uid=1` 的 `POST /auth/login`
  ——`RequirePerm` 认「uid=1 即超管」，一枚空 POST 就能按超管口径读全站。新路由一律挂 `app/admin`、`app/api` 或插件；
  `config/routes.php` 的 `attributes.controllers` 保持留空（顶层属性扫描 = 写个示例类就自动公开一个根路径端点，
  还抢在 SPA 兜底前面）。顶层确实需要路由时，把「为什么必须在根上」与它的门禁写进 `ROOT_ROUTES`。
  分类器是**排除式**的（未打标 = 框架自带探针，`app:admin`/`app:api`/`plugin:*` = 另有门禁，其余全算顶层），
  不要改回按 `app` 精确匹配：`app/routes/*.php` 每个文件各打一个 `routes:<文件名>` 标签，
  `app/routes/bench.php` 的三条匿名压测口就是这么躲过白名单的（现由 `BENCH_ROUTES=false` 默认关闭，
  口径同 `C_API_REGISTER`）。正对照（开开关后必须枚举到 `/bench/*`）才是挡住「分类器看不见这一圈」的那条腿
  ——只测「默认没挂」时，旧分类器照样全绿。
- **铸访问令牌的地方必须先判定身份**：`jwt()->issue(` / 载荷含 `'uid'` 的 `->issue([...])` 所在文件，
  必须真的含该入口的判据符号（`password_verify`、`EncryptedJwtGuard::authenticate`、`AttemptGuard` 计数），
  新签发点登记进 `tests/RootRouteGateTest.php` 的 `MINT_SITES`。真实登录只有 `/api/auth/login` 一条；
  「第二枚发令牌的口」要自己回答「凭什么给这个人发」。`LicenseService::issue()` 铸授权码不算签发点。
- 兜底路由（`/api/{path:.+}`、`/c-api/{path:.+}`、SPA 的 `/{path:.+}`）**只在 `boot()` 注册，不放 `register()`**：
  动态匹配是先到先得（`kode/http` 的 `Router::doMatch()`），兜底排在前就把后注册的一切参数化路由悄悄吞成兜底响应，
  而 `register()` 的先后由 `config/plugins.php` 的数组位置决定（`PluginScaffolder` 往末尾追加）。
  **先到先得只管动态这一层**：无参字面量路由存在 `$static[$method][$path]` 哈希表里、先于任何 `{param}` 命中，
  与注册顺序无关 —— 别在路由文件里写「字面量必须注册在 `{key}` 之前否则被吞」（本仓曾有三人三处这么写，
  错在引导人做没有必要的挪动）。顺带一句反直觉的：同 pattern 重复注册时**静态是后写覆盖前者、动态是先写胜出**，
  方向相反且都不报错。完整口径与判据见 `kode/http` README《匹配优先级》+ 两侧测试。
  结构口径与逐条真实 dispatch 的回归见 `plugins/FrontendPlugin.php::boot()` 与 `tests/RouteReachabilityTest.php`。
- 只读接口的门禁**按渲染它那张菜单页的 `auth_code` 挂** `RequirePerm`（`app/admin/routes.php` 的 `$can(...)` 工厂），
  与 `/api/menus` 的菜单可见性同源：菜单看得见 == 接口调得动。写侧仍走 `$admin`。
  「写全 admin、读只验登录」等于把用户名单/系统库存/聚合指标发给任何一枚有效 token
  （一张只读的部门页也能读全站手机号）。码只能取**授予面**里真实存在的值
  （配了个勾不到的码 = 谁都没权限，永久锁死，回归见 `tests/PermGateAuditTest.php`）。
  授予面的唯一派生口是 `app\support\PermCatalog`（= `kode_menus.auth_code` ∪ `kode_menu_buttons.code`
  ∪ 各插件 `permissions()` 声明的操作码）；`/api/perms/catalog` 与 `PluginContract::auditPermGates()`
  都读它，不许再各写一份集合。此前正是「审计比声明面、目录出菜单面」两张面从不对齐，
  插件路由 17 个码里 11 个写侧码（`workflow:edit`、`notices:create`、`wallet:adjust` …）
  谁都没勾得到，非超管调写接口恒 403 而审计全绿 —— 失败是 fail-closed 的，所以没有任何报错指向真因。
  对账口径（消费面 = 注册路由上的 RequirePerm 码 ⊂ 授予面，含「扫描面非空」的防空转断言
  与「目录里必须真有仅由 permissions() 补进来的码」的正对照）见 `tests/PermCatalogGrantabilityTest.php`。
  静态口径见 `tests/CoreReadGateTest.php`（含「至少 N 条路由真挂了门禁」的防空转断言 + 白名单条目必须仍存在且仍验登录）。
  插件菜单的枚举口同样只有一份 = `app\support\PluginMenus`（`tree()` 的合并面、上面的声明码面、
  以及**写侧**的 path 撞车判定都读它）：插件菜单不入库、只在读取时和库内菜单合并，所以「这个 path
  是不是已经被某个插件占了」在写侧原本完全看不见，建一条同 path 的记录就能让侧边栏出两个同名条目，
  而库里那条和插件那条互相改不动（本机 `kode_menus` id=49 那条 `plugin IS NULL` 的化石行即此来源，
  路径正是 FrontendPlugin 声明的 `/workflow-bridge`）。`MenuController::store()/update()` 撞车回 409，
  判定只盯「这次写入占了新 path」——路径没动的编辑放行，否则那条历史行连改标题都改不动、
  而它唯一的出口是删除。回归见 `tests/MenuWriteContractTest.php`（含「例外只放行路径不变」的反向对照）。
- 全局聚合出参（`/api/dashboard/summary`、`/api/audits/stats`）对非超管**收口径而不是 403**：
  要么响应带 `scope` 字段并**缺键**（不给 0 —— 0 会被读成「这台系统没有用户」，那是另一种泄漏），
  要么同一条 SQL 加归属条件只出本人的行；返回形状保持稳定，前端两张图都能画。见 `tests/AdminReadScopeTest.php`。
- **非 GET 的核心路由必须挂 `RequireRole`/`RequirePerm`**，例外逐条写在 `tests/CoreWriteGateTest.php` 的
  `SELF_SERVICE` 里，判据只有一个：这条改动落在**谁的行**上（自助改密/自己的头像/本人收件箱才算）。
  读侧白名单不看写路由、控制器单测又是自己注入 `auth` 属性绕开中间件的，所以一条只挂 `$auth` 的 `POST`
  不会让任何既有测试变红 —— 只有这条门禁能发现。往全站共享状态写的（改别人、改配置、删库存）一律不算例外。
- 那条门禁只枚举 `app:admin` 标签，**`/c-api/*` 的写接口对它不存在**。C 端非 GET 路由由
  `tests/ApiWriteGateTest.php` 管，判据读 `app/api/routes.php` 的**源码**而不是注册后的路由表
  （注册常包在 `if (env('C_API_REGISTER'))` 里，默认关闭时「扫到 0 条」和「全部合规」输出一模一样）。
  C 端**禁止复用后台控制器方法**：那条线收哪些字段跟着后台需求长，复用等于把 `dept_id`/`roles` 一类的口子
  发给匿名请求（历史上 `POST /c-api/auth/register` 就是这么写的，并配 `dept_id` 让外部把人塞进指定部门）。
  给 C 端单独建控制器、入参写死，且白名单条目必须登记校验字段全集与 handler 里的守卫符号 ——
  **注释里写「有节流」不算过**，符号得在 handler 里真出现（口径同 `PluginAnonymousRouteGateTest`）。
- 媒体库的口径是「**人人可读的共享资源库 + 只有超管可改**」：`KodeMediaPicker` 内嵌在个人中心/富文本/站点设置，
  读接口收不进任何菜单码；正因为读不设防，写侧（建目录/改名/移动/替换/删除/转储）必须钉死超管。
- 共享行（如 `kode_notifications.user_id = 0` 的全员广播）任何情况下都不进批量删除条件：
  清空已读写成 `user_id IN (0, ?)` 等于任何账号一次操作替全站删公告。取 uid 一律走
  `AccountScope::uidOf(AccountScope::payload())` 并先挡 `<= 0`（uid 为 0 会让「我的收件箱」落进广播行）。
- 登录防爆破：同 IP/账号 10 分钟失败 10 次即 429；测试时注意清缓存或换账号，勿把自己锁死。
  注意本机调试所有请求同属 127.0.0.1（共享 IP 预算），输错几次密码会连带锁住正确登录；
  被锁时清 `storage/cache/*` 或等 10 分钟；默认账号 `admin / ChangeMe123!`（首次登录必改）。
  判据只写一份：`app\concerns\AttemptGuard`（键固定成对 `前缀_ip:IP` + `前缀_user:身份`，
  只按 IP 会被 NAT 绕过、只按身份等于攻击者可替全员锁死账号；缓存不可用一律**放行**）。
  登录/改密计**失败**，C 端匿名注册计**尝试**（成功建号也 +1，否则预算等于没有）；
  新增「不验身份就能写库」的入口就复用这个 trait 并起一个新前缀，不要再手写第四份计数器。
- 账号状态：`status` 0 禁用拒绝登录（403）；初始超管不可禁用/删除。
- 操作审计：`AuditMiddleware` 只记元数据（谁/方法/路径/状态/耗时/IP），**禁止记录请求体**；
  探针路径跳过；审计失败绝不影响主流程。
- 审计落库只有一个派发口：`app\support\AuditTrail::record()`。落哪张表由同类的 `tableFor($uid, $ip)`
  判一次（有 uid 按 uid 路由、否则按 IP），SQL 里**禁止写死** `kode_audit_logs` 及其分片表名：
  读侧（列表/统计/history）一律枚举 `Shard::tables('kode_audit_logs')` 的 `_00.._NN`，
  写进基表等于永久不可见（基表那 167 条老数据就是这么失踪的，写侧看起来 INSERT 成功、日志页查不到）。
  列宽按**字符**截（pgsql `VARCHAR(n)` 计字符不计字节：64/64/16/255/64）——按字节 `substr` 会把
  中文用户名切成非法 UTF-8，一条审计失败会连累同请求的其它写入。
  来路 IP 只由 `app\support\ClientIp::resolve()` 判（内含 `security.trusted_proxies`）：
  自己 `Request::ip()` 不传白名单 = 把反代机当成来路记进审计，`X-Forwarded-For` 还可被伪造。
  直连地址由运行时在交付请求时盖戳（`kode/process` >= 5.4.0 的 `Request::setRemoteAddress()`；
  此前三个运行时都没写过这个属性，`ip()` 恒为空串）——所以**历史审计行的 ip 列是空的**，
  按 IP 查老数据永远查不到，别当成「这台系统没有外部访问」。同理修复前所有「按来路 IP」的
  预算（限流 / 登录防爆破 / 访问日志）是把全站客户端折进同一个桶跑的。
  门禁见 `tests/AuditTrailWriteGateTest.php`（含「旧写法必须变红」的正对照），
  行为断言见 `tests/AuditTrailWriteTest.php`（改一次 flag → 列表查得到 + 表名口径与读侧一致）。
- 文件上传：`Request::file('file')` 取文件，大小 ≤10MB、白名单扩展名，存 `storage/uploads/{年月}/`，
  下载走鉴权接口（生产静态资源走 nginx/CDN，本接口用于需鉴权的中小文件）。
- 时间列读写 `\DateTimeImmutable`；展示格式化 `Y-m-d H:i:s`。
- DB 表结构变更两处同步：`database/migrations/`（增量）+ `database/sql/schema.sql`（全量中文注释版）。
  同步要落到**列**：只加迁移、schema.sql 的表定义不动，新环境装完就缺列（`SELECT ... status` 直接报错），
  列级门禁见 `tests/SchemaCoverageTest.php`。
  同步还要落到**列的形状**（NOT NULL / DEFAULT）：`kode/database` 直到 v1.25.0 才有 `->notNull()`
  （此前建表器**无法表达非空**：`nullable` 选项被 `toSql()` 忽略、`setNullable(false)` 是空操作），
  所以凡走 `Schema::create()` 建出的列在存量库里一律「可空 + 无默认」，与 schema.sql / 插件 install()
  写的手写 DDL 分叉 —— 实测 44 列。后果不是「少个约束」这么抽象：`kode_menus.plugin` 可空且无默认，
  于是任何一条不写这列的 INSERT 都静默落 NULL（库里真有一条），而 `kode_notices` 的分片表
  （走 install()）反倒是严格的那一份 —— 同一张表在同一个库里躺着两种形状。
  现在三条腿齐了：新迁移建列必须把 `->notNull()` / `->default()` 写出来（函数默认值如 `NOW()`
  建表器仍表达不了，见包 README《列约束》段，那条 ALTER 走原生 SQL）；
  `app\support\SchemaShape` 是对账与收敛的唯一判据（只收紧不放宽，有 NULL 行的列只报告不动手，
  回填成什么是业务决定）；存量库跑 `php kode migrate` 落 `ConvergeColumnShapes`；
  回归见 `tests/SchemaShapeTest.php`（含「派生表 `(LIKE …)` 继承基表形状」与
  「漂移必须全部落在 `SchemaShape::LEGACY_NULLABLE` 欠账清单里」—— 清单只能缩短，
  新列漏写 `notNull()` 会在这里变红）。
- 表名统一 `kode_` 前缀（`migrations` 表除外，框架写死）；业务表追加后缀；pgsql 通用语法（PG14+ 可装）。
- DB 账号：禁用 root 直连业务（各人账号不同），用各环境自建专用账号 + 按库授权；
  属主即连接账号（建库建表用谁的账号，业务就连谁的），避免 root 建表业务读不到；
  新环境照 `.env.example` 填自己的账号密码即可安装，无任何写死的角色名依赖。
- pgsql 默认启用 `ltree` 扩展（树形查询备用）；时间列一律 TIMESTAMPTZ。
- 亿级扩展：
  读写分离 `DB_READ_WRITE_SPLIT=true`（读走 `pgsql_read`，事务内读自动走主库；从库故障不自动回切，靠探针告警）；
  海量表按 `Shard::table($base,$key)` 分片（4 片起，2 的幂次扩；跨片聚合只做管理后台量级查询）；
  分库（`SHARD_ENABLED=true` + `shard:init` 建库建表）：分片键恒用归属键（owner/user_id），
  同一归属落同一库表；对外 eid 为三元组（库序号/表序号/id），`locate()` 解码直达；
  热数据（菜单树等）走 `HotCache::remember/forget`（redis，写必失效），登录等安全路径禁止缓存。
  新增前缀必须登记进 `HotCache::NAMESPACES`：`/api/system/cache-clear` 与「一键修复」只清那一份清单
  （此前只写死 `menus:`/`config:`，插件的 `plugin:config:*`/`plugin:status:*` 漏在清理面外，
  超管点了清理仍读到 5 分钟内的旧值，而接口回的是成功）。对账口径见 `tests/HotCacheNamespaceTest.php`
  （扫非注释代码取前缀 + 「注册过就必须真被 clearAll() 删掉」的行为断言）。
  **清理结果还必须先说「清没清成」**：`HotCache::clear()` 在连不上服务时返回 `null` 而不是 0 ——
  一条已死的 `pconnect` 上 `scan()` 回的是 `false`（实测，不抛），旧写法 `return 0` 把
  「一个键都没删」写成「清完了，只是没东西可清」，运维看到「热缓存已清理（共 0 个键）」
  与真相完全相反。出参是逐命名空间的 `{前缀: int|null}` + 同级 `complete`（false 时 `total`
  只是「清成功的那些行」之和），「哪些行没清成」只由 `HotCache::incomplete()` 判一次，
  「一键修复」也按它决定进 `executed` 还是 `skipped`（失败列进「已修复」是同一份假象）。
  前端不写死命名空间表：`api/kode.ts` 的类型与 `views/kode/monitor/index.vue` 都读 `namespaces`
  的键原文，新增前缀才不会「清理面有它、页面上看不见」（此前那份类型写着 `{configs, menus}`，
  而响应里从来只有 `{namespaces, total, complete}`，提示条渲染成「菜单 ，配置 ）」）。
  回归见 `tests/CacheClearOutcomeTest.php`（活连接逐行真数 + 死连接逐行 null + repair 不得记成功）。
- 分片表按需开通：`Shard::ensureTables($base)` 在写/读入口调用（worker 内每基名检查一次），
  无需预建；分片基表清单只写一份 = `Shard::SHARDED_BASES`（`shard:init`、建表、漂移探测都读它，
  各写一遍数组就会有人漏）。
  **片表的形状只由主库基表的真实目录派生**：`Shard::ddlFor($base, $table)` 读 `pg_attribute` +
  `format_type` + `pg_index`，返回**语句清单**（一条 CREATE TABLE + 若干 CREATE INDEX；
  `Db::statement()` 走 prepare，pgsql 不接受分号拼多语句，所以不能返回一条）。
  代码里**永不再出现第二份表定义**：这里曾写死过一份 `kode_notices` 的 schema，基表两年改过三处
  （body→TEXT、加 status、加 type）副本一次没跟，且与基表的偏离是双向的（基表 title/body/两个时间列
  实测可空、时间列还没默认值，副本是 `NOT NULL DEFAULT NOW()`）—— 于是「基表合法的写入」到片上被拒、
  同一条不带时间的 INSERT 因落点不同结果不同。另一支 `LIKE {$base} INCLUDING ALL` 则是**必然失败**
  （分片库里没有基表，实测 42P01），被 catch 吞掉后表现为「除 notices 之外没有任何基表能分片」。
  两条硬口径：主键序列**不跨库** → 片上写成 `GENERATED BY DEFAULT AS IDENTITY`（BY DEFAULT 才能
  让迁移/回填显式带 id）；唯一索引/表达式索引/部分索引**不照抄**（片表的唯一约束只管单片，
  抄过去只会拒掉基表合法的写；表达式与部分索引不在重建范围）。基表读不到或列形状不认识一律
  **抛**（宁可失败也不建一张错形状的片），`shard:init` 因此会红，而请求路径由 catch 保住。
  回归见 `tests/ShardDdlFromBaseTest.php`（在**分片库里**执行生成的 DDL，那里恰恰没有基表；
  含「把 ddlFor 换回 LIKE/换回直抄 nextval 两条变异都必须红」的对照）。
- 片表的列缺口由 `Shard::ensureTables()` 回填：表已存在时按**主库基表的真实列集合**
  （`pg_attribute` + `format_type`，不走代码里的第二份定义）逐列 `ADD COLUMN IF NOT EXISTS`，
  **只加不删**；默认值只在字面量过字符集校验时才带，且永不写「NOT NULL 而无默认」
  （既有片有行时那会直接锁死写入）。理由：建片走 `ddlFor`、改列走迁移，而迁移只跑主库 ——
  于是「基表有 type、片表没有」在四条路径（迁移/install/upgrade/按需建表）里**没有人负责回填**，
  实测后果是列表少一片（`catch (\Throwable) continue` 吞掉 42703）与写入 500。
  **补列在补索引之前**（索引可能建在刚补出来的那列上，反了会 42703 打断这一轮）；索引语句恒带
  `IF NOT EXISTS`，所以既有片表也能照跑 —— 本机四张 `kode_notices_*` 缺的那条 `(type, status)`
  就是这么补回来的（缺它的症状不是报错而是「每片一次顺序扫」，不测就永远看不见）。
  `php kode shard:init` 会顺带跑一次并汇报「补了什么 / 还缺什么」（缺列多因当前账号无 ALTER 权限）；
  只读口径见 `Shard::drift()` 与 `GET /api/shard/config` 的 `column_drift`。回归见 `tests/ShardColumnAlignTest.php`
  （含「临时基表 + 缺列片表 → 补列 → 数据在位 → 再跑幂等」与「基表不可读时绝不碰片表」；
  收尾删临时表要**按目录现查**，`ensureTables` 是库×表双维，写死名字清单就会漏下 `_01` 那几张 ——
  残留会让下一轮的「漂移」前提建立在自己留下的片表上）。
- **凡是枚举片清单去读/去删，只走 `Shard::scan(Shard::readUnits(...), $fn)` 这一个派发口**，
  不许再写 `foreach (Shard::tables(...))` + 自己 catch。它管两件事：
  ① 连接名先过 `Db::hasConnection()` —— `Db::connection('不存在的名字')` **不报错**，而是静默回落
  主库配置，于是「分片库没注册」被读成「主库上那张同名片表有多少行」（本机主库里真有
  `kode_audit_logs_00..03` 的残留行，就这么被算进了全站总数）；
  ② 失败的那一条腿带原因回来（`unreadable`：片标签 + 可读中文原因 + SQLSTATE），
  而不是 `catch (\Throwable) { continue; }` 把读失败吃成「这一片没有数据」。
  出参口径：**`unreadable_shards` 恒在**（正常时是 `[]`，不是缺键 —— 缺键与老后端无法区分），
  与 `total`/`page`/`size` 同级；`data` 的形状不许随拓扑变（图表按固定长度画）。
  只回 `data` 会把「少了两片的列表」渲染成「这台系统就这么多请求」。
  `scan` 的 `results` **只含成功的腿**，所以片数≠腿数时不许按下标回查 `readUnits` 的清单来贴表名
  —— 标签必须由回调自己随结果返回，否则少一条腿就让后面每条整体错一格（`tests/MonitorShardTotalsTest.php` 逐片独立重数就是钉这一条）。
  两种「缺」分开点名：派发口径的读失败走 `unreadable_shards`，建表口径（配置里有、库里没有）走
  `data.missing_tables`（`UNION ALL` 少一条腿会让整条 SQL 报错，必须先按 `pg_class` 核对）。
  CSV/备份这类**头已发出、状态码改不了**的产物，把缺片写进正文尾巴（`#` 注释行），别只留信封字段。
  静态门禁见 `tests/ShardReadDispatchGateTest.php`（含「旧写法必须变红」的三条 nowdoc 对照），
  行为断言见 `tests/AuditShardReadTest.php`（每个读入口一条独立用例：一条红只对应一个入口）
  与 `tests/ShardReadDegradedTest.php`（notices 侧的两个面：未注册连接落到主库 + catch-continue）；
  前端对应面 = `KodeShardGaps` 告警条（审计列表/我的操作/趋势、公告列表、看板、分片总览面板）。
  `GET /api/monitor/shards` 的每节还带 `complete`：false 时 `total` 只是「读到的那些片」之和，不是全站口径。
- 多租户当前**只有脚手架，没有隔离**，别把开关当成分租户能力的实现：
  实测 `kode_tenants` 是空表、全库只有 `kode_schedule_runs` / `kode_tenant_schedule_runs` 两张表带 `tenant_id`
  （45 张业务表里其余 43 张没有归属列）；`config/tenant.php` 的 `resolver` 只管「请求头/子域 → 租户串」，
  **不校验这个租户是否存在**；`TENANT_STORAGE_STRATEGY=database` + `TENANT_STORAGE_ON_MISSING=fallback`
  的组合是「解析不到映射就沿用默认连接」—— 于是打开开关之后，所有租户都静默落回同一个库，
  页面看着是按租户操作的，数据其实全在一起（phpunit.xml 用的正是这组配置，别拿它当反证）。
  要真做隔离：先给业务表补归属列并让读路径带上它，再把 `on_missing` 改 `abort`，
  并让 resolver 认 `kode_tenants` 里的登记（三者缺一个，「隔离」就只是字面意思）。
- 分布式事务：跨库/跨片写用 `Saga::run()`（do/undo 步骤，失败逆序补偿，补偿失败记 trail 人工介入）；
  do/undo 必须幂等可重入；强一致场景走单库事务，不用 Saga。
- 归属：分片表必备 `created_by`，写操作验归属（本人或 admin，否则 403）；
  跨片错误经 `Shard::onShard` 自动前缀 `[shard:库.表]`，结合 trace_id 定位故障片。
- 追踪错误：所有分片异常带片标识 + trace_id；审计/日志按 trace_id 串联。
- 一份数据有两种形状时，归一只放在**唯一派发点**。`StorageManager::pushRemote()` 派发给驱动插件的
  四样入参各由核心算一次：`disk` 恒为平铺形态（`disk()` 返回的嵌套 `config` 进门先 `flattenDisk()` 摊平）、
  `path` 恒为**绝对**本地路径（`localPath()`；守护进程 CWD 实测是 `/`，传 `uploads/…` 相对串等于让驱动猜落点）、
  `key` 恒为**完整**对象键且与展示侧同源（`remoteObjectKey()`/`remoteObjectUrl()`）。
  此前是核心传嵌套、调用方 `StorageController::test()` 自己平铺、驱动再自带一份带日期与媒体 id 的键公式，
  结果就是「连通性测试通过、批量转移必失败」+ 推成功后前端点开的 URL 指向从不存在过的对象，
  而报错还指向「配置不完整」这个假因。新增同类派发口照此办：不要在两处各自抹平一次、
  不要让驱动自己算第二份键。回归见 `tests/StorageRemotePushTest.php` + `tests/StorageDriverPayloadTest.php`。
- 一个**判定**（这一步算成功还是失败）也只许有一份实现，且 SQL 侧与 PHP 侧必须**互补**。
  `WorkflowBridgeController` 里同一句话曾有五份各自实现的判据：`stepPerformance()` 认
  `(value->>'success') = 'true'`（写侧只有 api_call 分支落 `success` 键，log/condition/calc **根本没这个键**，
  于是被一律判成失败），`stepHealth()`/`stepStats()` 认 `!isset(error)`（反向漏掉 5xx：`executeApiCall()`
  对 HTTP 5xx 回 `{http_code, data, success:false}` **不带 error 键**，健康页把它记成成功），
  `apiCallAggregation()` 又自己 AND 了一个 `http_code < 400`，`executionStepTrace()` 再写一遍两条 if。
  实测同一批 73 条执行：`log_input` 在步骤健康页 100% 成功、在同页的性能分布表 **0%**，`calc_bonus` 93% vs 50% ——
  两个数都印在「工作流桥」上，谁都不报错。现在只留 `stepOutcome()`（PHP）与 `stepOutcomeSql()`（SQL）一份判据，
  三处执行器落库前的 `success` 也走它（旧写法 `= !isset(error)` 会在入库那一刻把 5xx 翻成成功）。
  SQL 侧**必须写成 `CASE … THEN true … ELSE false END`**，不能图省事写成两个条件直接 OR：
  键缺失时 `->>` 出 SQL NULL，`false OR NULL` 是 NULL 而不是 false，`NOT failed` 也就成 NULL，
  `SUM(CASE WHEN succeeded THEN 1 ELSE 0 END)` 照旧把「没有该键」计成 0 —— 换汤不换药。
  同类形态：`stepHealth()` 的聚合循环曾用 `$s = &$stats[$name]` 且从不解绑，
  下面那个 `foreach ($stats as $name => $s)` 就把每个元素逐个写进**最后一个键**，实测整条步骤从 steps 里消失
  （其余用引用累加的点如 `apiCallAggregation()`、`timeoutHotspots()` 本来就有 `unset($s)`，只有这里漏了）。
  门禁与行为断言见 `tests/WorkflowStepOutcomeTest.php`（五种步骤形状各挡一条错法、五个面板各自验判据并对账，
  另有「旧写法不得复活」的静态扫描 —— 它先 `token_get_all()` 剥掉注释再扫，
  否则文档里那句「旧写法长什么样」会被当成实现命中，逼人删掉解释来让测试变绿）。
- 同一件事有两半（改状态 + 留轨迹）时，派发口也只留**一个**。插件版本就属这一类：
  `kode_plugins.version` 回答「现在是什么版本」，账本 `kode_plugin_versions` 回答「是怎么变成这个版本号的」，
  两半必须由 `PluginVersionHistory::changeVersion()` 一起写。此前四处调用只有 `upgrade()` 记得两头都写，
  install / 远程安装 / 备份恢复三处只改列，于是账本看着是完整历史、实际只记了四分之一的账，
  而 `rollback()` 全建在这张表上（没记 install 的插件算不出回滚候选，
  「被恢复带进来的版本号」还会被记成一次升级）。四条硬口径：
  ① 版本列只由 `changeVersion()` 改，`kode_plugins.version` 的 UPDATE 也只此一处（静态门禁
  `tests/PluginVersionWriteGateTest.php`，含「needle 必须在允许处真的命中」的正对照）；
  ② 「变更前是什么」由调用方显式传 `$from`：install 的 `setStatus()` 会建行并送 `1.0.0` 默认值，
  之后再去读列就把「从没装过」读成「上一版是默认值」，账本里会多一条假前置版本；
  ③ 权威版本是 `XxxPlugin::version()`，`kode_plugins.version` 只是登记镜像；
  ④ `rollback()` 只认账本里出现过的目标，且文案必须承认「只改登记、代码未动，换代码请走备份恢复」。
  行为断言见 `tests/PluginVersionHistoryTest.php`（该文件的 DB 用例此前因缺 `Application::make()`
  而**整类静默 skip**，文件头的「覆盖 rollback」是假的 —— 探库失败要把原因写进 skip 文案，
  否则「没跑」和「跑过且绿」在输出上长得一样）。

## 4. 安全红线

- `.env` 不入库；`JWT_SECRET` 强随机；生产 `APP_DEBUG=false`。
  **运行时生成到磁盘的密钥，落盘的同时就要进忽略面**：`LicenseService` 把低代码 license 的
  RSA-2048 私钥生成在 `storage/keys/lowcode-license/`（0600），而此前没有任何一条规则盖住它 ——
  它既不在 `.gitignore` 里、也从未被跟踪，所以一次「顺手 `git add -A`」就能把签发授权码的私钥推进仓库，
  且推到公开远端后**换密钥等于作废全部已发放 license**（清历史也不追回已读过的副本）。
  同一条线在这一圈是无声的：文件不报错、不告警，只是躺在 untracked 列表里。
  判据问 git 自己（`git check-ignore`）而不是正则扫 `.gitignore` —— 后者在「规则写错但看着像对的」时全绿；
  三条一起看：机制通不通（`.env` 必须被判忽略）、密钥目录在不在忽略面、**不能把整仓忽略掉**
  （`AGENTS.md` 必须判「未忽略」，否则加一条 `/` 也算过）。另有一条反向断言：
  `git ls-files -- storage/keys` 必须为空 —— 已经跟踪上了就不是「补条规则」能解决的，要换密钥并清历史。
  回归见 `tests/SecretPathIgnoreTest.php`。新增同类密钥/凭证目录时照此办（不要靠「反正没人 add -A」）。
- SQL 一律绑定参数，**禁止字符串拼接**；表/字段名走白名单（框架 Schema 已做）。
- 用户可控数据进响应头由框架剥离 CR/LF；CORS origins 收敛具体域名（禁止 `*` + 凭证）。
  本地联调前端（:8848）需开 `CORS_ENABLED`，origins 只写实际访问源（单值回显，多值浏览器拒收）。
- `/metrics`、`/docs` 禁止 `protect=none` 对公网；`/health` 失败明细仅 debug 可见。
- 插件路由**默认不带任何鉴权**（`Manager::addRoute()` 的中间件由插件自己声明，框架不补）。
  不挂 `AuthMiddleware` 的端点必须自带准入判定，并登记进 `tests/PluginAnonymousRouteGateTest.php` 的白名单：
  非 GET 的那几条还要写出「守卫符号」——判定得在 handler 里真出现（如 webhook 触发器的 `webhookAdmission(`），
  光在白名单注释里讲一句不算过。新增匿名端点先问「它凭什么对匿名调用开放」，答不上就补鉴权。
  判据别写成「配了才生效」的可选项：`webhook_secret` 为空即跳过验签，等于给外部留一个匿名执行入口
  （被触发的工作流会写库、会出网、会跑 on_fail）——凭证至少要有一道，两项都没配就 403。
  口径与 IP 优先/签名走 `hash_equals` 的细节见 `docs/API.md` 的「工作流入站触发」段；
  回归见 `tests/WorkflowWebhookGateTest.php`。
- 会话固定：登录成功后轮换会话/CSRF 令牌；JWT `exp` 不超过 `iat + ttl`。
- 账号处置即时生效：鉴权只验签、`RequireRole` 读登录时铸好的载荷，所以**禁用/收回角色/重置密码/删除账号**
  必须在写侧调用 `EncryptedJwtGuard::revokeUserTokens($uid)` 撤销在发令牌（写库前撤销，失败可重试），
  否则处置要等到令牌自然过期才生效；读侧不加每请求查库（热路径红线）。新增同类处置入口照此办。
  角色侧同理：**禁用/删除角色**要按角色撤销成员会话（`UserService::invalidateSessionsByRole`），
  且读取侧（`RolePerms`）必须认 `kode_roles.status` —— 两边都过滤，开关才真的落地。
- PUT 局部更新语义：可改字段一律「缺键保留原值」（显式传空数组/空串才是清空），
  否则列表页那种只发 `{status}` 的状态开关要么 422（字段 required），要么把兄弟字段抹成默认值。
  **这个判定只有一份** = `app\concerns\PartialUpdate`（`supplied()` 问「带没带」，`pick()` 出值），
  且 **null 与缺键同义**。反面是「null 算清空」：`auth_code: null` 会把菜单的权限码抹掉，
  那条菜单当场从「按码门禁」落到「登录可见」—— 那是提权，不是清空；要清空请发 `''`。
  各处自己写一遍时，两份实现的语义必然分岔：`MenuController` 的局部闭包认「键在不在」、
  `RoleController` 的 `private static pick()` 认「键在且不为 null」，同一个请求在两条线上
  得到相反的结果，而两条线都「测试通过」。漏一列更直接：`DeptController::update()` 曾经
  无条件写 `sort = (int) ($data['sort'] ?? 0)`，而编辑框根本不发这一列 ——
  **每改一次部门名，全站部门排序归零一次**（列表按 `ORDER BY sort, id` 排，页面上又没有任何地方
  显示过 sort，所以没人知道自己抹掉了什么）；`DictController`/`MenuButtonController` 同形。
  新增 update 处理器就 `use PartialUpdate;` 并让每个可改列走 `self::pick(...)`，
  门禁（含「旧写法必须变红」的正对照、豁免清单不得留僵尸登记）见 `tests/PartialUpdateTest.php`。
- 树形的「上级」也只有一份判定 = `app\support\TreeParent`（`kode_menus` 与 `kode_depts` 共用）：
  顶级 = `null|''|0|'0'`，环与不存在的上级在同一处判、状态码也在同一处映射（环 400、上级不存在 404）。
  两侧各写一遍的实际代价是：部门侧的 `update()` 从来没读 `parent_id`，而前端 `api.ts` 写着
  `void pid`（把值丢掉以免白传）—— 那个「上级部门」选择框**两侧都没人接**，改了不落地还回 200；
  同时 `tree()` 不把上级以对外形状出参（ApiTreeSelect 拿 `valueField='id'` 回填），
  于是打开编辑框时上级恒空，用户不动它、提交，等于把一个有上级的部门挪成顶级。
  对外出参必须与 `id` 同形状（HashId 串），这是 §4 HashId 那条在树上的落点。
  回归见 `tests/PartialUpdateTest.php` 的部门挪动/环/顶级四条例，前端对应 `views/sys/api.ts`
  的 `toDept()`（`created_at`→`createTime`、`parent_id`）与 `dept/modules/form.vue` 的 `toFormValues()`。
- 授权类枚举写入前先查表：角色 slug 必须存在于 `kode_roles`（`UserService::assertRolesExist`），
  否则拼错的名字会作为授权记录落库并回显成「已授予」，而 `RequireRole` 永远不认；
  批量入口整批先校验（放在循环里会被逐个 catch 吞掉，`updated:0` 照样回 200）。
- 目录权限不依赖 umask：`mkdir`/`chmod` 写死 `0775`，禁止 `0777`（umask=0 的容器/守护进程里
  上传目录与插件解包目录会全局可写，别的账号塞个 .php 就是任意文件落地）。
  静态门禁见 `tests/DynamicCodeGateTest.php`。
- 破坏性入参禁止静默夹取：`min/max` 兜底等于替用户做决定 —— `days=0`（或漏传/负数）被夹成 1，
  就是一次「删掉昨天之前全部备份」的无告警批量删除。越界一律 400 拒掉，让用户改参数
  （口径见 `BackupController::clean`；配套断言见 `tests/BackupArtifactTest.php`）。
  保留期入口（审计/系统/登录三处 `clean` + 工作流 `executions/prune` + 定时任务 `schedules/prune`）
  共用 `app\concerns\RetentionGuard::retentionDays()`：days 必填、必须整数、必须在 7..3650，
  漏传不再回落成「删 90 天前」。判据只写一份是故意的 —— 五个入口各写一遍，迟早只改对一个：
  后两个入口就是这么躲过旧门禁的（它们写的分别是 `min(365, max(1, input('days', 30)))` 与
  `min(365, max(1, query('days', 30)))`，常量与取参器都跟前三处不同，而 prune 的 `dry_run` 在服务端
  默认 false —— 漏传 days 就是立刻删掉昨天之前的全部执行/日志/旧版本；`schedules/prune` 更绕，
  DELETE 语句在框架的 `ScheduleDispatcher::pruneRunHistory()` 里，方法体里连 `DELETE FROM` 都没有）。
  门禁因此改判**不变式**而不是旧写法的字面量：「方法体读了 days 且自己删行、或把 days 交给
  prune/clean/purge callee」的，必须调 `retentionDays()` 并把它的 `error` 回成 400。
  这两个新入口只有静态门禁、没有 400 行为断言：它们没有预览模式，闸门哪天被摘掉，
  用例跑起来就是先删真数据再报红 —— 用例本身会变成事故。见 `tests/RetentionGuardTest.php`。
  未认证仍先 401，别拿参数校验给匿名请求探接口。
  同一条线在 `POST /api/migrations/rollback` 上更贵：`step` 曾经直接进 `Migrator::rollback($steps)`，
  而框架的批次算式是 `range($maxBatch - $steps + 1, $maxBatch)` 再过滤 `>= 1` —— 于是
  **越界的 step 不是被拒绝而是被放大**（`step=999` 把 41 个批次全算进去 = 一次静默的 reset），
  `step=0` 因 range 反向计数而等价于 `step=1`，超大整数溢出成 float 后 `range()` 抛 `ValueError`
  进 500 响应。现在入参先过 `MigrationController::stepsFor($raw, $maxBatch)`（纯函数、可离线断言），
  算不出「1..当前最大批次」内的整数就 400 并把「回滚全部」指向 `reset` 那条明确的口；
  bool 必须在 numeric 之前挡掉（`(int) true === 1` 会被当成合法的 step）。
  这条用例只验判定本身：真回滚会动 `migrations` 表，所以派发面前置两道线（见下），跑完核对
  `SELECT COUNT(*), MAX(batch)` 不变。回归见 `tests/MigrationRollbackStepsTest.php`。
  同一条线在 `POST /api/migrations/run` 上坏法不同：`Migrator::run($steps)` 走的是
  `array_slice($pending, 0, $steps)`，越界**无害**（slice 自己夹），但
  `step=0` 是「一个都不跑」而 handler 回「没有需要执行的迁移」—— 把「参数写错了」渲染成「系统是新的」；
  `step=-1` 是**砍掉最后一个的部分执行**，已跑的那几个照写批次号、响应里没有任何提示；
  `(int) '2.9'` 得 2、`(int) true` 得 1，都是静默改步长。
  现在两边的**策略**分开设（回滚必须有上界、执行的缺省是 `null` = 全部待执行），
  **形状规则**只写一份（`MigrationController::positiveStep()`），执行侧入口是 `runStepFor()`，
  畸形值 400、缺省照旧全跑（管理页那个不带 step 的按钮不能被守卫误伤）。
  同一条线在特性开关的灰度比例上**后果是放量而不是删除**：`max(0, min(100, (int) $this->input('rollout')))`
  把这一列直接喂给分桶比较（`bucket < rollout`），所以每一次静默夹取都是一次真实的灰度动作 ——
  实测 `rollout: 1000` → 100 = **对全量用户放开**（打错一个 0 就把 10% 变全量）、`-1` → 0 = 对全量关闭、
  `"50人"` → 50（尾部垃圾被吃掉）、`"abc"`/`true` → 0/1，而四者都回 `{code:200, action:"updated"}`，
  页面与审计账本都不会说「你给的数我没看懂」。口径与 `stepsFor()`/`retentionDays()` 同族：
  要么是 0..100 的整数，要么 400，**判据只写一份**（`FeatureFlagController::percentOf()`，
  两个写入口共用）、**先拒再写**。断言形状照上一条：派发前先核对守卫与写入口径，
  否则未修复版会先把畸形值真落库再让断言追认。
  另一半是**读侧**：`check()` 在 `user_id` 缺失时 `bucket` 恒为 0，于是任何 `0<rollout<100` 的开关
  对每一个不带主体的调用都回 `enabled: true, reason:"in_rollout"` —— 而管理端的「判定测试」面板
  就是这么发的（输入框留空即 `undefined`），灰度 10% 的开关在页面上显示「命中」，
  这句话的主语其实是「没有这个人」。分桶的前提是有一个可以分桶的主体，缺主体只能**拒答**（400），
  不能替所有人回答「在」；全量/零灰度与主体无关，不能被这条守卫一起拒掉（否则试算框常驻红）。
  回归见 `tests/FeatureFlagRolloutTest.php`。
- 破坏性 handler 的回归测试**自己不能成为事故**：发请求之前必须有两道线。
  ① 源码级：判据调用排在引擎调用之前，**且交给引擎的是判据的结论而不是原始入参** ——
  只查「调了判据」的话，`$migrator->run($this->input('step'))`（算完就扔）照样全绿；
  ② 值级：拿真实入参逐个问判据「拒不拒」，分母用 handler 自己那个数（回滚侧 = 本机 `MAX(batch)`，
  **不是** `COUNT(DISTINCT batch)` —— 批次号不连号的库（回滚过再跑就是）两者立刻分叉，
  预检就会对着一个 handler 没用过的数放行）。
  只有第 ① 道不够：判据哪天被改松时，红的是下面那条「行数没变」，而那时真回滚已经跑完。
  执行侧再加一条 —— **问引擎自己**（反射拿 handler 那个 `Migrator`，读 `getPendingFiles()`），
  非空就 `markTestSkipped` 并写明原因（本文件第一稿就在这条上真跑掉了一次 `ConvergeColumnShapes`：
  它的行数断言是在**已经落库之后**才变红的）。判据也不许在测试里 glob 一遍目录重写，
  那是第二份「什么算待执行」。同一条对账顺带比「已执行历史」（引擎 `getRan()` vs 管理页 `batches`）：
  待执行恒为 0 的机器上，只比 pending 那份清单等于每次都在比两个空数组，这一侧才有内容。
  回归见 `tests/MigrationRunStepsTest.php`。
- 分页窗口只判一次：`app\concerns\PageWindow::pageWindow()` 出 `{page,size,offset,error}`，`offset` 只由它算。
  旧写法 `max(1, (int) query('page', 1))` 只挡下限，`page=9223372036854775807` 经 `(int)` 饱和成 `PHP_INT_MAX`、
  乘上 size 溢出成 float，绑进 `LIMIT ? OFFSET ?` 就是 `SQLSTATE[22P02]`（Doctrine 侧是 `setFirstResult()`
  收到 float 的 TypeError）—— 一个查询参数就能把列表接口打成 500。两条口径分工明确：`size` 夹到上限是**保护**
  （限的是本次返回行数，沿用旧行为；确需更大显式传 `$maxSize`，如 DB 表格浏览与钱包流水的 200），
  `page` 越过行深上限（`MAX_ROW_DEPTH` = 10 万行）一律 400，绝不静默夹成一个「看着像数据」的页码 ——
  pgsql 的 `OFFSET n` 是真扫弃 n 行，深度无界等于单次请求的 DB 开销无界。
  服务层/仓储的入参恒为 `offset` 而非 `page`（`UserService::list`、`UserRepository::page`、`WalletService::list`），
  因为第二处乘法就是第二个 500 现场。门禁见 `tests/PageWindowGateTest.php`（「旧写法不得复活」的静态扫描
  + 正/负对照），行为断言见 `tests/PageWindowTest.php`（pgsql 与 Doctrine 两条分支各一条）。
  **归并型分页另有一份预算**：跨分片「每片各取 `page*size` 行进 PHP 再 usort 切片」的入口
  （`AuditController::index/mine`、`NoticeController::index`）没有 OFFSET，`MAX_ROW_DEPTH` 限的是 DB 扫弃的行数，
  不是 PHP 存下的行数 —— 沿用等于放行 `100000 × 4 片`（真实审计表实测每行净占 ~885 B，
  worker `memory_limit` 128M，即 ~354 MB），一枚有效 token 一个查询参数就能把 worker 撞成内存 fatal。
  这类入口一律走 `mergedWindow(page, size, shards)`（预算 `MAX_MERGED_ROWS` = 2 万行按片数摊薄；
  片数取 `count(Shard::tables(...))`，跨库分片的入口用 `Shard::dbCount() * Shard::tableShards()`，
  不要自己再乘一遍配置常量）。`shards <= 1` 自动退回 `pageWindow()` —— 单表分页的代价在 SQL 侧的
  OFFSET，不该被内存预算误伤。口径 4 的门禁是「方法体里出现 `$page * $size` 就必须调 `mergedWindow(`」。
  片数本身只从 `Shard::config()` 读（DB 热配置优先）：读侧若改读 `config/sharding.php`，
  扩过片之后循环就少扫几片（`DashboardController`、`NoticeController` 曾各读一份）。
  批量导出的游标不在这条线上，但有同款坑：拿 `count($rows) === $size` 当「还有下一页」时，
  夹取方与判据方必须是同一个 —— 服务层曾把 500 夹成 200，于是 `WalletController::export()` 第一页就退出，
  静默少给数据还回 200 OK；撞到行数天花板要把截断写进产物正文（流式导出已发头，状态码改不了）。
  **插件的列表入口也在这条线上**，此前 `plugins/frontend/BuilderController.php` 三处各写一套：
  `licensesIndex` 写死 `LIMIT 200` 且**完全不看** `page`/`size`、`pagesIndex` 一个 `LIMIT` 都没有、
  `buildsIndex` 写死 `LIMIT 100`。而管理端一直在发那两个参数 —— 于是第 2 页返回的就是第 1 页的内容、
  `total` 缺键被前端回落成「本页条数」，第 201 条之后的授权永久看不见。现在的口径：
  **列表入口 = `pageWindow()` + 一条 `COUNT(*)` + `$this->page()`，没有第二种形状**。
  随列表一起出的**分布/统计**（license 页顶部那三张卡片）必须由同一条 SQL 的
  `COUNT(*) FILTER (WHERE …)` 算在**筛选后的全集**上、经 `page()` 的第 5 参 `$extra` 回 `stats`，
  绝不让前端数当页行：列表一旦真分页，那是把「全站分布」静默换成「本页分布」（同上一条「聚合不许按行抽样」）。
  回归见 `tests/LowcodeListWindowTest.php`。
  同一条线在插件里还有两个后续现场，各自多一种坏法：
  `WorkflowBridgeController::definitionsIndex()`（`tests/WorkflowDefinitionListWindowTest.php`）和
  `GiteeReleaseController` 的 `reposIndex`/`downloadsList`（`tests/GiteeListWindowTest.php`）。
  后者还带一份**逐行查凭证**：列表原本每行再发一枚 `SELECT token … WHERE id = ?`（N 行 = 1 + N 条查询），
  而那一列本可以跟着主查询一起回来、在响应边界摘掉 —— 门禁是「`reposIndex` 的方法体不许出现 `repoToken(`」。
  「有没有配凭证」的判据恒用 `!== ''` 而不是 `(bool)`：一枚字面量 `"0"` 的令牌是配了凭证，
  `(bool) '0'` 会把它报成没配（写侧/回显/列表三处必须同一个判据）。
  另外别把「摘掉 `pageWindow()` 的 error 分支」猜成 500 —— 实测是 **200 + 空列表**
  （`PageWindow` 出错时回 `offset = 0`，`LIMIT ? OFFSET ?` 照跑）：把「翻页翻过头了」渲染成
  「这台系统再没有别的仓库了」。断言因此是状态码 400，而不是「不炸」。
  工作流桥的四个只读口是同一族的最后一片（`tests/WorkflowListWindowTest.php`）：
  `executionsList`/`webhookLogs`/`versionList` 三处都算好了 `offset` 却把 `OFFSET` 写死成 0
  （`versionList` 连 `total` 都没有），而 `webhookLogs` 收的是 `limit` 而不是 `page`/`size` —— 于是
  管理端一直在发的翻页参数结构性地到不了 SQL。两条只在这里出现的口径：
  ① **按时间排序的列表必须有第二排序键**（`ORDER BY created_at DESC, id DESC`）：同秒投递在 pgsql 里
  顺序不固定，翻页会在两页之间重复或漏行，而「两页不许有交集」这条断言在只有 `created_at` 时**随机通过**；
  ② 差异只能算在 PHP 里的那些口（`configHistory` 的版本间 diff）**允许抽样但必须如实声明**：
  全集计数走同一条 `COUNT(*)`、恒出 `diff_summary.total_versions`，抽样规模出 `scanned_versions` +
  `truncated`（口径同 `ScanBudget`，但键名按本入口的语义）。前端那张「版本总数」卡读
  `total_versions`，**不许** 数 `versions.length` —— 那是把「共 37 个版本只比了最近 20 个」印成
  「一共 20 个版本」，而后端已经真分页了（`limit` 是抽样天花板不是页大小）。
  前端三个列表客户端因此从裸 `requestClient.get` 换成 `getPage()`（`configHistory` 仍是裸 get ——
  它的 handler 回 `ok($payload)` 而不是 `page()`，`check-page-contract.php` 按方法体里的
  `$this->page(` 分这一类，两边不能互相「顺手统一」）。
- 跨分片**聚合**不许按行抽样：`AuditController::overview()` 曾在每片各取 `ORDER BY id DESC LIMIT 200000`
  行进 PHP 手搓 status/method/path/ip/user 的计数。行预算正比于历史流水（4 片 × 20 万行的关联数组，
  worker 128M 必成内存 fatal），而 LIMIT 截掉的那部分不会让走 `COUNT(*)` 的 `total_7d` 变小 ——
  于是每张分布表、`unique_ips`/`unique_users`、`avg_duration_ms` 全部**静默偏小**，页面看不出这是抽样
  （`avg` 更是分子取自抽样、分母取自全量）。现在恒在 SQL 侧：每片一条带窗口的腿 `UNION ALL` 成派生表，
  `GROUP BY` / `COUNT(DISTINCT)` / `MAX` 全算在跨片全集上，返回行数正比于**分组基数**。
  同类入口照此办，不要在 PHP 里数行。
  缺片口径是这条线的另一半：`UNION ALL` 少一条腿会让整条 SQL 报错（比静默偏小好），但要先按 `pg_class`
  核对片表、把「配置里有、库里没有」的如实回进 `missing_tables` —— 逐片 `try/catch continue` 会把缺片
  吃成一个看起来正常的数字。`yesterday` 恒为自然日半开区间 `[昨天00:00, 今天00:00)`（旧写法
  `<= '23:59:59'` 会让最后一秒内落库的行既不算今天也不算昨天）。
  门禁与不变式（`sum(status) == sum(methods) == total_7d`；「把修复前的写法喂同一个判据必须判红」
  的正对照）见 `tests/AuditOverviewTest.php`。
- 这条线在**插件的 limit 型汇总**上同样成立，而且不靠分片就能中招：`WorkflowBridgeController` 的
  `alertHistory()` / `stepRegressionHistory()` / `webhookDlq()` 曾经先 `ORDER BY ... LIMIT ?`（默认 100/100/50，
  管理端固定发 200/100）取一批行，再在 PHP 里 `count($result)` 当 `total`、foreach 数等级当 `level_counts` ——
  于是页面上「告警总数」「Warning/Critical 两张卡」数的是**这一批**，一个查询参数 `limit=1` 就能把它们
  统一压成 1（前端只读 `data`，无从分辨）。`webhookDlq()` 更绕：它的排行本来就是 SQL 侧 `GROUP BY` 算的，
  却自己另写了一遍 `WHERE status='dead_letter'` 而**丢掉 `workflow_name = ?`** —— 筛了工作流 A、
  排行还列着 B，与上一条「stats 不跟筛选走」是同一个假因。现在的口径：一份 `$where` + `$params` 同时喂
  三条腿（汇总 `COUNT(*) FILTER`、排行 `GROUP BY ... ORDER BY COUNT(*) DESC`、列表 `LIMIT ?`），
  `total`/分布恒算在筛选后的窗口全集上，截断由布尔 `truncated` 明说（少了它，「窗口 500 条只列 200 条」
  和「一共 200 条」在页面上长得一样，而后者是可以被照着做决策的）。
  排行必须**由 SQL 排序**：旧写法 `krsort($workflowCounts, SORT_NUMERIC)` 拿数值规则比字符串键
  （实测两两相等、整趟排序没动过任何一项），所谓「告警 TOP 工作流」其实是插入顺序，告警最多的那个排到榜尾。
  断言的造行形状有要求：三种等级各一条时「数全集」与「数这一页」会巧合相等，必须配成不对称的
  1/2/0（且让 limit 抽到的那一行落在被少数的那一档上），否则分布断言是自证的。回归见 `tests/WorkflowSummaryScopeTest.php`。
- **判据不许读它自己正在追加的那个数组**：`WorkflowBridgeController::budgetList()` 的 `$addAlert` 闭包
  （`use (&$alerts)`）曾写 `'warning' => !$alerts && $ratio >= 0.8`，而 `$alerts` 正是它下一行要
  `$alerts[] =` 进去的那一个 —— 于是 `!$alerts` 只对**第一条**告警成立，四条指标里
  `hourly_exec`/`avg_duration`/`daily_api` 的 `warning` 恒为 false，而 `daily_exec` 越界时
  `exceeded` 与 `warning` 同时为 true。后果落在页面上那一列三段标签
  （`exceeded_count > 0` / `alerts.some(a => a.warning)` / 否则绿色）：「API 调用量已用到上限的 90%」
  渲染成 **✅ 正常**，而这一列就是那个数在管理端唯一的出口（同 §7「读不到与没有这些数据必须是两件事」：
  这里连读都是读错的）。现在的口径：`$exceeded` 先算一次，`'exceeded' => $exceeded` +
  `'warning' => !$exceeded && $ratio >= 0.8` —— 两者互斥，且**每一条指标都能拿到 warning**。
  回归见 `tests/WorkflowBudgetAlertTest.php`（夹具按上一条配成不对称的 1% 与 90%，让被抽到的那行落在
  后一条上；另有「越界不得同时算接近」一条）。
- **出参里的一列不许在「数组」与「对象」两种 JSON 类型之间来回跳**：`array_filter()` **保留原键**，
  所以从混排列表里筛出来的那一列一旦有键空洞，`json_encode` 就把它写成 `{"1":{…}}` 而不是 `[ {…} ]`。
  `validateStepRefs()` 的 `errors`/`warnings` 就是这么坏的 —— `$issues` 按步骤顺序混装两类问题，
  「某步前向引用（warning）排在前、某步引用不存在的步骤（error）排在后」时 `errors` 的键是 `[1]`，
  而管理端那一列**只数 `.length`**（`views/kode/workflow-bridge/index.vue` 的
  `${errors.length} 个错误，${warnings.length} 个警告`），对象没有 length，提示条就印出
  「引用验证失败：**undefined** 个错误，**undefined** 个警告」—— 那句「验证失败」还在，计数全丢了。
  口径：给管理端按列表读的一列一律 `array_values(array_filter(...))`；只有**本来就按字符串键分组**的
  关联结构（`app/support/PluginContract.php` 的 `groups`、`PluginMetrics.php` 的 `method_distribution`）
  才允许保留键，所以那条门禁只扫 `plugins/`。`usort` 会重排下标，所以「filter 完再 usort」的
  `top_failed`/`hotspots` 是安全的 —— 判据看的是**有没有重排**，不是有没有用 filter。
  断言打在**响应正文**上（`"errors":\[`）而不是 PHP 里的数组：`?? []` 之类的兜底能骗过后者，骗不过线上形状。
  回归见 `tests/WorkflowValidateRefsShapeTest.php`（三条：混排形状、恒绿的 warning-only 正向对照、
  以及「旧写法必须点名 `WorkflowBridgeController.php → 'errors'`」的静态门禁 + 扫描面非空/被修文件在面上）。
- 上一条线的另一半是**行预算**：`WorkflowBridgeController` 里那族展开载荷列
  （`step_results_json` / `input_json` / `error`，三者都是 TEXT）的诊断入口
  （`failureAnalysis` / `pathMining` / `inputProfile` / `costAllocation` / `dependencyHealth` /
  `performanceBottleneck` / `workflowHealthScore` / `correlationAnalysis` / `inputValidation` … 共 14 个
  + 健康评分按工作流循环里的那一处）
  曾经把整窗口的行 `Db::select()` 进 PHP 再 foreach —— 实测每行平均 1958 B、最大 37801 B，
  而 `json_decode(..., true)` 再放大 8~9 倍，worker `memory_limit` 128M：一枚 `days=365` 就能把
  诊断面板打成内存 fatal。归一化/递归步骤树/URL 收敛这些判据**只在 PHP 里有一份**，
  所以按行抽样是允许的，代价是必须如实报出来：扫描一律走 `app\concerns\ScanBudget::scanBounded()`
  /`scanExecutions()`（`STEP_ROWS = 2000` 是天花板，入口只能调小），出参恒带
  `window_rows`（同一份 WHERE 的 `COUNT(*)`）+ `scanned_rows` + `truncated` 三键，
  由 `scanDeclared()` 一次搬进 `data` —— 缺键与「老版本后端」在客户端无法区分，面板按固定形状画图。
  分子分母不能混着取：分布与占比的分母恒是**同一批抽样**（`scanned_rows`），
  标题栏的总数才是窗口全集，反过来写每行占比都静默偏小且加起来不等于 100%。
  两处顺带修掉的真缺陷：`performanceBottleneck` 的 `ORDER BY id`（升序）让截断留下的是**最老**那些行；
  `workflowHealthScore` 的「重试率」那一维在每个工作流的循环里各拉一次全量（跨工作流累加后才声明）。
  导出产物同理：`executionsExport` 的 `total` 数的是「文件里装了几条」，窗口口径由
  `window_rows` + `truncated` 补上（前端在 truncated 时点名「共 N 条，只写了 M 条」）。
  **标题栏那份总数必须是 `window_rows`**，写成 `count($rows)` 时三件套照样全绿、卡片却静默变成抽样数 ——
  契约用例因此按 handler 对账 `total_executions === 窗口全集`（嵌套的 `global_summary` 不在射程内：
  那一处**应当**是抽样口径，分子分母同批）。
  **能下沉进 SQL 的聚合不属于这一族**：`idempotencyCheck` 的「同一个幂等键出现了几次」是纯
  `GROUP BY ... HAVING COUNT(*) > 1`，按行截断会把一个重复组**在边界上切成两半**，于是「两条执行共用一个键」
  这个必须被发现的事报成「没有重复」。现在五条腿全在 SQL 侧（窗口计数 `COUNT(*) FILTER`、分组 +
  `COUNT(*) OVER ()` 数整个窗口的组数、每组明细 `row_number() <= N`、两份清单），`limit` 只切三份**列表**、
  不切任何计数。判据是「这份判据在 PHP 里有几份」，不是「这一族别人都这么写」。
  回归见 `tests/WorkflowSummaryScopeTest.php`（含「新加的 handler 必须带声明」的契约用例
  + 「手写扫描漏 LIMIT 不得复活」的静态扫描，另有旧写法必红/合法形状必绿的双向对照）。
  静态扫描的**列名清单必须是一组**（`step_results_json` / `input_json` / `error`）而不是
  「造出上一轮缺陷的那个列名」：它最初只认 `step_results_json`，于是同一张表上把 `input_json`
  整列拉走的三个入口在它眼皮底下全绿了一整轮（名字是上一轮的，代价是这一轮的）。
  幂等那一条的种子有硬要求：**重复组数必须多于被测的 `limit`**，只种一组时
  「整个窗口的组数」与「榜上那几条」巧合相等，`duplicates = count(榜)` 这份回退写法照样绿。
- 夹过的时间窗必须**回显生效值**，且**每个成功返回分支都带**。`WorkflowBridgeController` 这一族诊断入口
  都收 `days` 并把它静默夹到本面板的上下界（读侧夹取是保护，口径同 §4 分页里「size 夹到上限」），
  缺陷不在夹取而在**没说夹成几天**：`?days=999` 时面板画的是最近 30 天的数，而响应里没有任何窗口声明，
  于是「近 999 天」和「近 30 天」在页面上长得一模一样 —— 而 30 天那一档恰恰是最贵的那档。
  口径：键名 `days`（与 `window_rows`/`truncated` 同级，**不放 `filters` 嵌套里**，导出产物两处都出），
  值恒为**夹过之后**的变量（`'days' => $days` 或 `compact(…, 'days')`），早退分支（空窗口/数据不足）同样要带。
  判据按**分支**算而不是按方法体算：「正常分支带、早退分支不带」是真实缺陷形状，方法级扫描看不见，
  而空窗口正是被夹取保护的那类请求最常走的路径。载荷是变量的形状
  （`return $this->ok(['data' => $export])`，导出/清理这类先拼装再返回）看**拼装那几行**
  （上一条成功返回到这一条之间的那段直线代码），不许放宽到整方法体。
  `pruneExecutions` 是破坏性入口，只由静态门禁覆盖 —— 它的行为用例真会删数据，别补。
  回归见 `tests/WorkflowWindowDeclarationTest.php`（静态门禁扫全部 handler 并拿已知名单当正对照，
  另有「剥掉一个分支的回显必须点名」「剥掉变量载荷拼装行的声明必须点名」两条反对照，
  行为断言覆盖上限/下限/漏传三种夹取方向）。
- 产物要落在有人管辖的目录：写文件先确认「谁会来列它、谁会来清它」——备份写进系统临时目录
  而列表/下载/清理只认 `storage/backups`，等于备份从未发生。含敏感数据的转储恒先以 0600
  建好文件再让子进程覆盖（shell 的 `>` 只截断不改模式），子进程 stderr 单独收管道
  （`2>&1` 会把报错混进产物正文，失败时又被 unlink 连同原因一起吞掉）。
- 数据库备份/恢复只有**两个派发口**，两侧口径不同别互相抄：
  - 范围只有一个来源 = `DbBackup::scope()`，各库**现读** `pg_tables`、库名取连接自己报的
    `current_database()`。写死表清单（旧实现那份含 `kode_audit_logs_00.._03` 的常量）会跟着
    `table_shards` 取值漂移，而「备了什么」只有打开 SQL 文件才知道；照 `db_name` 模板拼库名
    会在名字写错时 dump 到另一座库（甚至主库）。两座连接报同一个名字即判重失败——
    那正是分片连接回落主库配置的现场。空分片库整节跳过并在产物头部点名，不许悄悄少一节。
  - **空表清单不是「全库」**：全库的口径是 `null`（省略 `tables` 键），`DbBackup::run([])` 必须在
    **建文件之前**抛 `InvalidArgumentException`。`narrowTo()` 与空清单相交每节都是空，而
    `partition()` 恒保留主库那节（它是这套系统的家），所以旧写法照常建文件、照常写那 5 行注释头、
    循环里一次 dump 都不跑，最后回「备份完成」——一份实测 **150 字节、除注释头什么都不含**的 .sql，
    体积和真备份只差几十字节，恢复侧却什么都恢复不出。调用方也不许把空清单静默归一成 `null`：
    旧 `if (is_array($tables) && $tables !== [])` 让 `[]`、`[""]`、`[" ", ""]` 和
    `tables:"kode_users"`（写成字符串）全部落回**整座库** —— 那是把范围静默**放大**，比回假产物更危险。
    两条派发口（`POST /api/system/backups/run` 的 JSON、`GET /api/system/backup?tables=` 的逗号串）
    归一后为空一律 400，判定只在 `DbBackup::run()` 写一份。回归见 `tests/BackupScopeTest.php`
    （库级：不建文件）+ `tests/BackupArtifactTest.php`（两个入口各一条，且核对 `storage/backups`
    在本次请求前后无新增）。
  - 子进程只有一个 = `app\support\DbRestore`。恢复侧的输出**必须并成一条流**（命令以 `exec 2>&1;` 开头、
    fd2 走 `/dev/null`），与上一条相反：恢复没有产物正文要保护，分两管读会死锁
    （一份带几十条 `DROP … IF EXISTS` 的产物恢复进空库就是几十条 NOTICE，攒满管道缓冲即卡死）。
    `proc_open` 的 `['stream', …]` 第二元必须是**已打开的资源**，写 `['stream','php://stdout']`
    会让 proc_open 直接返回 false → 接口对任何人固定 500「恢复进程启动失败」，
    而退出码判据全在下游、没有一条测试看得见（上一轮真实发生过）。
  - 恢复必须 `-v ON_ERROR_STOP=1`：psql 不加它时**中间语句报错照样 exit 0**（实测），产物又不带
    `--clean` 时每条 CREATE 都撞「已存在」，于是接口一直在「一行都没恢复」的情况下回「恢复完成」。
    产物是逐节 `\connect` + BEGIN/COMMIT（坏一节只回滚自己），第一条错误即中止、原文进日志、
    尾巴进 500 响应——「进程没起来」与「SQL 报错」在响应上必须分得开。
    门禁与端到端断言见 `tests/BackupScopeGateTest.php` + `tests/BackupRestoreE2ETest.php`
    （后者只在 scratch 库里跑真 pg_dump → 真 psql，含「不加 ON_ERROR_STOP 时退出码照样 0」的反向对照）。

- 外部包的解包只能走 `app\support\SafeArchive::extract()`（zip/tar/tar.gz）：**违规条目一律抛错并
  回滚本次已落盘的文件与新建目录**，逐条目 `continue` 只留给调用方的业务过滤（`$accept` 返回 false）。
  静默跳过会把「半装」当成功——调用方拿到 `count>0`、状态照常翻成启用，缺文件要到运行时才炸；
  零条目空包同样算失败（`PharData` 对真实 tar 恒迭代 0 条，别拿它当解包器）。
  市场包条目白名单见 `PluginPackager::packageEntry()`，落点约定「根 `XxxPlugin.php` + `<name>/*.php`」，
  非 `.php`/点开头条目/落点越界全部中止且不动 `plugins/` 一个字节（断言见 `tests/PluginSupplyChainTest.php`）。
- 往 `plugins/` 写文件的只有两处：代码编辑器（`PUT /api/plugins/{name}/files`）与备份恢复
  （`POST /api/plugins/backup/restore`），两者共用 `PluginFileExplorer::check()/write()`，**归属必须在
  拼路径之前判**（`realpath()` 会把 `../` 折掉，折完仍在 `plugins/` 里就查不出越界；允许的形状只有
  「这个插件自己的根级主类」+「`plugins/<name>/` 之下」，主类身份由 `discover()` 反查，不靠命名约定猜）。
  凭空建文件的能力只属于恢复（`allowCreate=true`），符号链接一律不写；恢复还**先 `check()` 判完所有条目
  再落盘**——有一条写不进就整个插件算失败，不翻 `kode_plugins` 的状态与版本（半套文件 + 已启用 = 下次启动 fatal），
  失败原因走 `{code:400, msg, errors:{restore:{插件:[原因]}}}`。断言见 `tests/PluginBackupRestoreTest.php`。
  **枚举面 = 「`plugins/<name>/` 整棵树 + 该插件的主类」**，这一条同时是文件浏览器、备份导出、代码统计与市场
  打包认的「这个插件有哪些文件」：把兄弟类放在 `plugins/` 根级、或另起一个和 `name()` 不同名的目录（历史上
  `plugins/Builder`、`plugins/GiteeRelease`、`plugins/WorkflowBridge` 都由 name=frontend 的插件装载），
  备份就会少抓文件而恢复侧完全看不出来。读侧走同一条归属线（`read()` 与 `check()/write()` 共用
  `ownershipFailure()`，真实落点再判一次，符号链接指向别家插件同样拒），单文件上限读写共用
  `PluginFileExplorer::MAX_FILE_BYTES`（两个数各写一套时，「导得出去、恢复不回来」就是下一份半套备份）；
  自述 `complete:false` 的备份整插件判失败。形状与口径的门禁见 `tests/PluginLayoutGateTest.php`。
- 服务端出网只能走 `app\support\RemoteFetch`（`vet()` 判规则 / `toFile()` 抓产物 / `safeOpts()` 给
  「自己还要拿 curl 发请求」的调用方出选项包）：只允许 http(s)、URL 不带凭证、
  **重定向自己跟且每一跳重新过闸**、过闸后用 `CURLOPT_RESOLVE` 钉住**已校验的那个 IP** 再连
  （否则「查一次、连时再查一次」就是 DNS 重绑定）、体积**边下边算**超预算立刻断线、失败不留半截文件。
  禁止再出现第二份 `curl_init($用户URL)`：`CURLOPT_NOPROXY` 只管「要不要经代理」，不是内网闸门；
  scheme 白名单缺失时 curl 会自己解释 `file://`（实测能把 `.env` 原样读进响应体）。
  合并时闸门选项放**左侧**（`$guarded['opts'] + $业务opts`），地址含运行时变量的必须在**替换之后**过闸。
  唯一例外是主机写死的内置 API（gitee.com）：那种 URL 里用户可控的只有路径段，交给 `safeRepo()`/`safeVersion()`
  挡形状与 `..`，不走 `RemoteFetch`（它治的是「主机也能选」）。除此之外一律禁止裸 `curl_init()`。
  内网放行开关 `MARKET_ALLOW_PRIVATE` 仅供本地联调，生产恒关；规则表见 `docs/MARKETPLACE-PROTOCOL.md` §3.1。
- 远端可控的字符串禁止直接拼路径：`version`/`install_path`/资产名先过 `safeVersion()`/`safeInstallPath()`/
  `archiveExtOf()` 校验再用，临时文件后缀由**本地**决定而不是抄远端资产名（`../` 塞进临时路径就能写到别处）。
  校验函数写成 public 纯函数以便离线断言——`SafeArchive::extract()` 第 3 参是 `?callable`，
  private 静态方法当数组回调传出去会直接 TypeError（可见性按被调函数处判定）。
- JWT 续期（`refresh`）重签完整身份并作废旧 token（旧 token 立即 401，需重登逻辑处理）。
- 加解密约束（实测 AES-GCM 82B 往返约 1.5µs）：
  JWT 业务声明已由 `EncryptedJwtGuard` 加密（`uid` 明文保留：KodeJwt 强制要求且 URL/审计依赖）；
  PII 落库用 `Crypto::enc/dec` 字段级加密；**URL/查询串/普通 body 禁止整体加密**
  （破坏缓存/日志/WAF，且 TLS 已保护传输）；库内主键保持自增整数（不折腾 UUID），
  对外出由下条 HashId 规则覆盖，安全靠鉴权不靠混淆。
- 对外 ID 防枚举：业务资源 id 对外一律 `HashId` 混淆（出用 `enc`，入用 trait 的 `eid()`，
  非法进 404），路由约束写 `{id:[A-Za-z0-9]+}`（`\d+` 会拦掉 hashid）；
  认证身份 `uid` 对外亦为不透明串（`u_` + HashId，token 内无顺序数字；鉴权时解码比对，
  不一致/旧数字格式直接 401，需重登）；内部审计/仓储一律用解码后整数；盐放 `HASHID_SALT`。
- 防篡改/防重放：对外重要接口挂 `ApiSignMiddleware`（淘宝式 HMAC 签名 + 时间窗 ±300s + nonce），
  客户端用 `ApiSigner::sign()` 生成 `X-Api-Key/Timestamp/Nonce/Sign` 四头；密钥放 `API_SIGN_SECRET`。
- 脱敏展示：列表/日志用 `Mask::phone/email/idCard/name` 掩码，详情按权限返回原文；
  不要为展示而加密整个 body（前端仍要解密，多此一举）。
- 掩码不只管展示，它划出一条**写侧边界**：读侧恒出 `******` 的那些行客户端拿不到原值，
  于是「表单原样存回去」= 用掩码字面量（或被列表页映射成的空串）覆盖真凭证，
  而下一轮读还是掩码，页面上完全看不出凭证已被清空（配置中心一次「保存配置」抹掉全部对象存储密钥
  就是这个形状：那一行的输入框在页面上看着和别的行没区别）。所以：掩码字面量一律 400
  （「掩码不是真值」）、整行 upsert 必须「缺键保留原值」、有托管方的键（`plugin.*` 归 PluginConfig：
  按 schema 校验、认掩码、失效它自己的 `plugin:config:*` 缓存键）一律拒写拒删并把错误指回托管入口。
  缓存键命名空间是同一条线的另一半：写侧只失效 `config:{key}` 时插件仍读 5 分钟内的旧值，
  「写成功了但行为没变」最难查。新增掩码面必须同时新增它对应的写侧闸门，
  回归见 `tests/ConfigSecretMaskTest.php`（读侧）+ `tests/ConfigWriteOwnershipTest.php`（写侧）。

## 5. 性能红线

- 热路径（控制器/中间件）禁止同步阻塞外部调用（curl/OTLP）、禁止文件 I/O、禁止 `sleep`。
  例外只有工作流的同步执行（`plugins/WorkflowBridge`，产品形态就是「点了就等结果」），
  红线改判为**等待必须有界**：三处阻塞（`api_call` 的 curl 超时、内层重试退避、外层重试退避）
  一律只问 `WorkflowBridgeController::clampWaitMs()` 一个判据（= min(期望值, 10s 天花板, 剩余预算)），
  且 `deadline` 必须从 `executeStep` 一路传到每个阻塞点。
  各层自己算一遍就会相乘：单个 api_call 最坏 timeout(60s)×(1+3)+退避，外层再 ×(1+5)，
  一个坏上游能按住 worker 二十多分钟（11 worker 的后台，五个人同时点「执行」即锁死）。
  别指望兜底：native 运行时无按请求看门狗，`SERVER_MAX_REQUEST` 是**处理完**才回收，
  对正卡在 curl 上的 worker 完全无效。预算耗尽时要报「未发起请求/未再重试」而不是
  `_retries_exhausted`（「一次没发」和「试完都失败」是两种排障结论）。
  门禁见 `tests/WorkflowBlockingBudgetTest.php`（含「每处 usleep 都问过预算」「step-types 的
  区间必须与 validateSteps 的口径一致」两条静态对齐；`stepTypeCatalog()` 单拆成方法就是为了让测试能对齐）。
- 常驻进程禁止在请求内累积静态状态；用完的重量资源（EM 由中间件自动 `clear()`）及时释放。
- **常驻进程的「谁在跑 / 信号发给谁 / 能不能再启动」只有一个判据面** = `ProcessManager` 的
  `residentSlots()` / `slotStates()` / `signalSlots()`（framework >= 1.12.0；
  `Facades\Process` 只是它的门面，同一个底层服务）。控制器、面板、
  `health()` 都不许再自己拼 pid 路径：这里曾写死 `/tmp/kode-daemon.pid`，而 `kode process:start`
  按槽位写 `sys_get_temp_dir()/kode-worker-<name>:<slot>.pid`，于是那个文件永远不存在 ——
  三条线朝同一个方向坏掉：状态恒「未运行」→「启动」的防重入永久放行（每点一次真叠加一整套守护进程，
  且第二代覆盖 pid 文件、把第一代变成查不到的孤儿）→ 停止/重载永远失败。
  pid 文件只由守护进程进入运行循环时创建、退出时删除，所以**读侧绝不 unlink 失效文件**
  （读者删它等于把一次「正在启动、尚未落 pid」的正常拉起抹成「没发生过」；而 `kode/process` 的
  Daemon **默认**文件名恰好就是 `kode-daemon.pid`，这一刀可能落在别人的进程上）。
  信号只认两个：`Signal::TERM` 优雅停止、`Signal::USR1` 平滑重载该路全部 worker。
  **SIGHUP 没有装处理器** —— 旧 reload 发的 `posix_kill($pid, 1)` 是按系统默认处置**直接终止**，
  不是重载。面板因此不表达「重启」：一次请求内既不能等旧进程停完（上一条红线禁止请求内 sleep /
  文件轮询），也不能不等就派发 —— SIGTERM 之后紧接着的第二代只会撞上「pid 文件被存活进程占用」。
  改代码点「平滑重载」，换 worker 注册表走「停止 → 状态表归零 → 启动」两步。
  重复启动是**两道**判定且**不互为后备**：framework v1.13.0 的 `start()` 预检是唯一能给运维
  回 400 的那一次（`kode process:start` 是被 detach 出去的子进程，它的拒绝只落
  storage/logs/daemon.log，页面上看不到），而它挡不住并发 —— 两个请求可以同时看到「全没跑」；
  兜并发的是 kode/process v5.5.1 的锁（「判归属 + 落盘」跑在同一把非阻塞 `flock` 里，
  实测同时敲两次只活一套、败者退码 1 且理由点名「并发启动」）。
  回归见 `tests/ProcessSingleSourceTest.php`（静态：全仓不许出现第二份 pid 路径 / `posix_kill(` /
  `/proc/{pid}` 算式；行为：health 与 repair 同源且不碰别人的文件）
  + `tests/ProcessControlPanelTest.php`（三行状态口径与信号派发）。
- 追踪/指标保持 opt-in + 低采样；压测口径见 kode 框架文档 benchmarks（同窗比值）。

## 6. 测试与验证

- 改完必跑：`php -l`（语法）→ 相关 curl（登录→业务→越权 401/403）→ `composer test`。
- 批量改完必须 `php -l` 全扫 + curl 复测（正则批量替换易吃括号，如 `$this->ok([` 收尾 `]);`）。
- `docs/API.md` 与 `README-ADMIN.md` 的端点表里写出的每个「方法 + 路径」必须真实注册
  （改路由/删路由记得同步文档，门禁见 `tests/ApiDocRouteGateTest.php`）。
  表格里**一行一端点**：`GET/POST | A、B` 那种「方法集 × 路径集」的合并写法会把配对关系抹平
  （`POST /api/configs/{key}` 看着合法，真实写入其实是 `POST /api/configs`）。
- `README-ADMIN.md` 首屏的「框架 vX.Y.Z · http vX.Y.Z」是**全仓唯一一处人写的依赖版本自述**，
  `composer update kode/*` 之后不会有任何东西提醒你它变了。它必须与 `vendor/composer/installed.json`
  对上（门禁 `tests/DocVersionClaimTest.php`：解析不到、包名写错、版本对不上三种都算红）。
  报旧版本的代价是排障方向：按 v1.10.1 读框架源码会得出「探针坏了 `/health` 也回 ok」的相反结论，
  而 v1.10.2 起 `status` 是随探针结果的。同一条线也适用于各包自身（`Kode::VERSION` 与 composer.json
  的 version 由包内 VersionTest 守）。
- 注释里禁止出现 `*/` 序列（如 `*secret*/` 会提前终结 docblock 导致整文件语法错误）；用中文括号改写。
- 诊断面禁止把「框架 API 用错」渲染成「这个插件没有这项数据」。`catch (\Throwable)` 包住对框架类的调用时，
  方法名打错抛的是 `\Error`（`Call to undefined method`），同样被吞，于是面板显示 0 条 —— 与「真的一条没有」
  长得一模一样，且**只有配置齐全能走到那一行的路径**才会暴露。实测：健康检查与运行时详情都调
  `ScheduleDispatcher::tasks()`（那是 `Scheduler` 的方法），插件页「定时任务」tab 长期恒空。
  `FrameworkReferenceGateTest` 只核对类名存在，方法名打错它全绿，所以新增/改动这类调用点必须配
  `method_exists` 断言 + 一条「注册真数据→查得到→注销→查不到」的正负对照（只测正向的话，
  「把声明数当注册数返回」也能过）。同类形态：文案里的 `{count}` 占位符无人插值，
  判据用 `/\{[a-z_]+\}/` 全量扫而不是逐个词表。回归见 `tests/PluginHealthReportTest.php`。
- 运行时诊断的 ok 口径：没声明 = `applicable: false` + `ok: true`（组件插件没有命令/菜单是正常形态，
  算成异常会让每只新装插件都翻黄，红色随即失去意义）；声明了 = 必须**全部**落地
  （`ok` 取「已注册数 === 声明数」，「3 条只注册上 1 条」是真断链）。
  纯信息行（`routes`、`config`）恒 `ok: true`，因为契约里没有可对账的声明。见 §7 与 `docs/PLUGIN-FRONTEND.md`。
- 「读不到」和「没有这些数据」在页面上必须是两件事。`catch (\Throwable) { return $this->ok([]); }`
  这一族恒 200 的空载荷，再配上前端 `.catch(() => [])`，等于把「这座库没读到」渲染成「这台系统没有慢查询」，
  而诊断屏唯一被点开的时刻恰恰是有人来查这一项的时候（本机 `kode_app` 没装 `pg_stat_statements`，
  慢查询那一 tab 因此长期空白且说不出原因）。口径（`MonitorController` 四条数据库腿 + `ScheduleController`
  六个任务调度读口）：**恒 200 + `available`/`reason` 同级键、出参形状不随成败变**，`reason` 由
  `app\support\DbReadFace::reason()` 按 SQLSTATE 归因（08/HYT 前缀=数据库不可达、42501=无权读统计视图、
  42P01 带扩展名=扩展未安装、55000 带扩展名=扩展未装载、42P01=视图或表不存在、其余=读取失败），
  **判定只写这一份** —— `MonitorController::dbDegradeReason()` 与两个控制器都只调它，新增读口一律用
  `DbReadFace::readable($payload)` / `unreadable($e, $face, $payload)`，不要在控制器里再 match 一遍
  （`Shard::failureReason()` 那份「分片库不可达 / 片表缺列」是**逐片**词汇、处置项是 `shard:init`，
  与这份有意分开，不要合并）；
  驱动消息与 host/port 永不出参（那是内网拓扑，且 reason 的全部作用就是被念给人听）。
  数字类出参读不到时回 **null 而不是 0**（同 §3 的聚合缺键规则：`活跃连接 0 / 0` 会被读成「一个都没有」）。
  破坏性读口不在此列：`POST /api/schedules/prune` 失败恒 **500 + 结论**且响应里不带 `deleted`
  （`{deleted:0}` 会被念成「清理完成，没有过期数据」）。
  前端按 `available` 出告警条，并用 `Promise.allSettled` 逐腿独立 —— 一条腿挂了不许抹掉另外三条已到的结果。
  **测试里伪造「库不可达」时先记住本机开着 `DB_READ_WRITE_SPLIT=true`**：`Db::select()` 走 `getReadConnection()`，
  只把**默认连接**换成拒连配置时四条腿照样读得到（本机实测），一整类降级断言会全绿而什么都没测到。
  夹具只有一份 = `app\tests\Support\DeadDatabase`（覆盖所有 driver=pgsql 的已注册连接，或另注一条拒连连接并
  `setDefaultConnection()`；`addConnection()` 会把 `PoolManager::$driver` 改成最后注册的那个名字，所以
  **恢复时必须把默认连接放回最后一条**；`removeConnection()` 对当前默认连接直接返回 false）。
  它自带前提自查（`Db::select('SELECT 1')` 必须抛、且异常里必须真有 SQLSTATE），前提不成立直接红而不是假绿；
  每个使用方给 `deadConnectionName()` 取独占名字，否则两个类共用夹具会互相摘错连接。
  断言这类「恒带键」的出参时禁用 `?? '缺键'` 兜底 —— `??` 对 null 同样触发，正好把「回 null」判成「缺键」；
  用 `assertArrayHasKey` + `assertNull` 分开。回归见 `tests/MonitorDbDiagnosticsTest.php`
  与 `tests/ScheduleReadFaceTest.php`（后者还钉「判定只有一处实现」的静态门禁 + §6 要求的
  「真数据→查得到→删掉→查不到」正负对照）。
- 同一条口径也管**外部主机的读腿**（§4 那条 gitee.com 例外只免掉 SSRF 闸门，不免掉这条）：
  `GiteeReleaseController` 的两条 gitee 读只走 `giteeGet()` 一个传输口，结论由 `giteeListVerdict()`/
  `giteeReleaseVerdict()` 下 —— **HTTP 200 也可能是「一发都没读到」**（401 令牌失效、404 仓库对这枚令牌
  不可见、回包是 `{"message":…}` 这类对象而不是列表），所以出参恒带 `available`/`reason`，
  且 `has_update` 在读不到时回 **null**：落成 `false` 会被页面念成「已是最新版本」，而这句话主语的真相是
  「一次都没读」，恰好在凭证过期时最误导人。「空列表」与「读不到」必须是两张图（前者是数据、后者是故障）。
  回归见 `tests/GiteeReadOutcomeTest.php`（离线纯判定 + 「两个 fetch 必须走 giteeGet/verdict」
  「两个读口出参必须带 available/reason」的静态门禁）。
- 新文件必须 `php -l` 单验（write 工具不校验语法，未 lint 的文件会炸启动）。
- 框架升级后检查 `config/` 是否缺新 key（缺 key 回退框架默认，旧项目如裸开关曾因此失效）；
- `.env.example` 是唯一的开关注册表：代码里新增的 `env('KEY'` 读取点必须在此登记（回归见 `tests/EnvDeclarationTest.php`）。
  可选覆盖项写成**注释行给示例值**（`#PHP_BINARY=/usr/local/bin/php`），不要写 `KEY=` 空值：
  框架 `env()` 只在变量缺失时才回落默认，`KEY=` 会被 EnvLoader 真写进环境，空串把代码默认值顶掉
  （`PHP_BINARY=` 让子进程命令变空串，`DB_READ_HOST=` 让读写分离连空主机）。仅消费端显式判空的键可留空（见该测试 EMPTY_OK）。
  `composer` 操作后核对 `installed.json` 实际版本与预期一致。
  再核 vendor 字节未被污染：`git -C ../<pkg> archive <tag> | tar -x -C <tmp>` → `diff -rq <tmp> vendor/kode/<pkg>` 无差异，
  有差异先 `composer reinstall kode/<pkg>` 归位，再谈排查业务问题（见 §2）。
- 错误排查顺序：`curl -v` 看状态码 → `.env` 确认 `APP_DEBUG=true` 看 location/chain → `storage/logs/`。
- 端口被占：`php kode status --port 9527` → `php kode stop --port 9527`，禁止直接 kill -9（除非僵死）。
- 重启语义：`restart` = 停同端口旧实例 + fresh 拉起（默认前台显示启动界面，`-d` 守护）；`--rolling` 保留零停机滚动；`start` 端口被占绝不自动杀进程。
  收尸孤儿 worker 按进程标题 `{server.name}:` 匹配（框架 ≥ 1.7.3，默认 `kode-http`），即作用域是**同名进程**而非同端口：
  单机跑多套 kode 必须给每套配不同 `SERVER_NAME`，否则 A 的 restart 会误杀 B 的 worker（反之残留 worker 占端口导致重启中止）。
- 本地调试用 `start -d`（守护，无 watch 干扰）；`nohup php kode start &` 会堆叠多代 master，
  一律用 `stop/status/restart` 管理，勿直接起裸进程。

## 7. 前后端契约（前端同学只看这节）

- 基地址如 `http://127.0.0.1:9527`；鉴权头 `Authorization: Bearer <token>`（登录接口返回）。
- 管理前端 = `admin-web`（vben-antd 基座，`apps/web-antd`，dev 端口 8848，同源 `/api` 反代到 9527）。
- 动态路由/侧边栏拉 `GET /api/menus`（嵌套树：一级目录+子菜单，核心 + 插件 `menus()` 已聚合，vben `accessMode=backend`）；
  当前用户 `GET /api/auth/me`（→`{uid,username,roles}`），权限码即角色 slug（`GET /api/auth/codes` 由前端映射，无需后端实现）。
- vben token 存加密 localStorage（命名空间 `kode-admin`，accessStore 托管），请求层 `apps/web-antd/src/api/request.ts` 统一注入；
  信封适配：成功 `code∈[200,300)`（Kode 用 HTTP 状态，不用 vben 的 0），错误字段取 `msg`。
- 分页统一 `{code,msg,dur,data,total,page,size}`；错误统一 `{code,msg,dur,errors?}` + HTTP 状态（422 带 `errors`）。
- 站点品牌（登录页标题/页脚 ICP）走 `GET /api/configs/site`（只验登录，恒返回 `site.title/logo/icp/description` 四键），
  **不要改成 `/api/configs`** —— 整表读取已收进 `configs:view`，登录时还没拿到权限码，非超管会 403。
- 数字看板 `/api/dashboard/summary` 响应带 `scope`（`all` / `self`）：`self` 时**缺键**而不是回 0，
  前端按 `scope` 隐藏全站卡片（`views/dashboard/analytics/index.vue` 的 `isAll`），否则会把「没权限」渲染成「这台系统没有用户」。
  `/api/audits/stats` 同理恒返回 7 天列表，`self` 时只是本人的行。
- 列表页的「读不到」与「这台系统没有数据」必须是两件事，分页器不许跑在数据前面。两条口径：
  ① 一屏多张卡时用**逐腿** `Promise.allSettled`（不许 `Promise.all` —— 一条 403 会把另外三条也打成空），
  失败的那条渲染 `—` + 一行告警，**不许** `res?.x ?? 0` / `?? []`：概览屏把「请求失败」画成「这台系统没打过日志」
  比画成 0 更糟，因为 0 会被照着做决策（`views/kode/audits`、`views/kode/syslogs` 的概览，
  `views/kode/monitor` 的四张 DB 面板读的是后端 `available/reason`，前端只按它渲染告警条）。
  **「HTTP 200」不等于「读到了」**：`views/kode/gitee-release` 的 releases 腿要看响应体里的 `available:false`
  才出 `—` + 原因，只 `catch` 请求层错误的话，令牌失效会渲染成「暂无 Release」（数据结论，不是故障）；
  而「检查更新」那句话的四种真相（有新版 / 已最新 / 上游没发过版 / 一次都没读到）只在
  `utils/update-check.ts` 判一次 —— `has_update:null` 掉进 `else` 就是「已是最新版本」那句假话的来路。
  ② 翻页/改每页条数时，**页码跟数据一起前进**：`load(p, ps)` 把窗口参数直接发给接口，成功后才
  `page.value = p; size.value = ps`。旧写法是先 `page.value = p` 再 `await`（或在模板里 `page = p` /
  `v-model:current="page"` —— 双向绑定在请求发出**之前**就替我们挪好了），于是请求失败时
  `rows/total` 还停在上一页，而分页器指着用户刚点的那一页 —— 把上一页的行渲染成「第 3 页就这些」。
  带 `showSizeChanger` 的表必须接住 `ps`，否则那个下拉是个死控件（改了不落地、还回 200）。
  换主体时（打开某个插件的日志、选中某个项目）要**先把行清空再取**：失败保留原行是对的，
  但保留着「上一个插件的日志」挂在当前标题下比空表更难发现（`views/kode/plugins`、`views/kode/lowcode-builder`）。
  一个顺带的手感：`@change="load"` / `@click="load"` 把事件参数（Select 的值串、MouseEvent）喂给了
  带默认值的 `load(p, ps)` —— 改签名时这类调用点必须写成 `load(1)`，`vue-tsc --noEmit` 会抓到它。
- 分页列表附带的分布（license 页顶部三张卡）只读响应里的 `stats` 键，**不再在前端数当页行**；
  `stats` 缺键时卡片出 `—` 而不是 0。
  **「谁的 `total/page/size/stats` 到得了视图」这件事只由客户端选择决定**：vben 的
  `defaultResponseInterceptor` 在 `responseReturn:'data'`（`src/api/request.ts` 的默认值）下返回的是
  `responseData['data']`，而 `ApiEnvelope::page()` 把那三键（以及 `page()` 第 5 参 `$extra` 里的
  `stats`/`unreadable_shards`）放在 `data` 的**同级** —— 于是裸 `requestClient.get()` 调一个回 `page()`
  的口，结构性地只剩裸数组，视图 `res.total ?? 本页行数` 就退化成「共 20 条」而真实总数是 137。
  #13 统一修过一次，但只对账了核心路由，插件的四个列表口（builder 项目/页面/构建、lowcode licenses）
  漏在外面直到 #151。现在的对账 = `admin-web/apps/web-antd/scripts/check-page-contract.php`
  （`pnpm check:page-contract`）：它按**后端 handler 方法体里有没有 `$this->page(`** 判形状（不按路径名猜），
  路由从三种注册形状解析（`->get(path, fn)`、`->get(path, [C::class, m])`、插件 `addRoute(name,'GET',path,[C::class,m])`，
  含插件里 `[self::class, …]` 归位到宿主类），前端**两张扫面** = `kode.ts` 的方法表 + `kode.ts` 之外直接发的
  裸 `requestClient.get`（`src/api/core/*.ts` 是这一圈；`responseReturn:'body'` 与 `/api/…` 已带前缀的写法都认），
  两端剥完注释归一路径后对账；另有两条正对照
  （把 `/users` 与插件的 `/builder/projects` 各改回裸 get，必须被点名）——
  没有那两条，「全部对齐」可能只是「分类器看不见这一圈」。
  新增回 `page()` 的列表口 = api 层用 `getPage()`，视图按 `{data,total,page,size,stats?}` 读，
  **不许**再写「res 可能是裸数组」的兼容分支（那条分支的存在本身就是客户端选错的证据）。
  到期口径两侧必须同粒度：后端 SQL 用 `end_at < CURRENT_DATE`
  （`date` 列、只比自然日），前端 `isExpired()` 也因此按 `YYYY-MM-DD` 字符串比 ——
  旧写法 `new Date(end_at) < new Date()` 把无时区的 date 当 UTC 零点，东八区到期当天 08:00 起
  就把还在有效期内的行标成「已过期」，而同一行的卡片计数仍说它生效，两处自相矛盾。
  同一条线在 workflow 面板上的另一半：`/api/workflow/alerts`、`/api/workflow/step-regressions` 的
  `total` 现在是整个时间窗的条数，而 `alerts` / `regressions` 只是 `limit` 截断的最近若干条，
  所以响应带 `truncated`，两个面板（`views/kode/workflow-bridge/index.vue` 的告警历史摘要行、
  回归历史弹窗）在 `truncated` 为真时点名「列表仅展示最近 N 条」。**不要**为了「看起来一致」
  把 `total` 改回 `count(列表长度)` —— 那正是这轮修掉的缺陷。
  同一张面板上还有三份契约要跟：
  ① 失败分析弹窗（`failure-analysis`）的分母**不是** `total_failed` 也**不是** `scanned_rows` ——
  一条执行可能有多个步骤报错，占比只能除以「榜上各档 `count` 之和」（`failureSampledErrors()`），
  窗口被 `ScanBudget::STEP_ROWS` 截断时另出一行 Alert 点名「分类与占比仅统计最近 N 条」。
  ② JSON 导出的 `total` 是**这个文件装了几条**，窗口全集在 `window_rows`；`truncated` 为真时
  提示「已导出 M 条，筛选窗口共 N 条」，别说「已导出全部」。
  ③ 清理弹窗的「保留天数」输入框 `:min="7"`，与后端 `RetentionGuard` 同界 ——
  后端不再替你夹小值（漏传/0/负数一律 400），前端把下限放开就等于让人对着 400 反复点确认。
  ④ 诊断类响应（`path-mining`/`input-profile`/`capacity-forecast`/`data-lineage`/`execution-clustering`/
  `health-trend`/`input-validation`/`cost-allocation`/`replay-comparison`/`idempotency-check`/
  `alert-rules`/`anomaly-detection`/`executions/export`…）都带同级键 `days` = **夹过之后**的生效窗口。
  面板上的天数下拉**选项集合必须落在后端区间内**（2026-09 逐档核对过：1/3/7/14/30 对 1..30、
  7..90 对 7..90，全部在界内，所以现在没有「选 1 天看 7 天」的活缺陷）。加档位时先核区间，
  越界那一档必须改成渲染 `res.days`（「实际统计最近 N 天」）而不是下拉里那个数 ——
  用户发 `?days=999` 而卡片写着「近 999 天」，等于替他把「只看最近 30 天」这件事瞒下来。
  `days` 缺键就当后端版本不对（别默认成提交值去渲染）。
  ⑤ 面板上的三个列表（执行日志、Webhook 投递日志、版本历史）现在是**服务端分页**：
  api 层走 `getPage()`、视图按 `{data,total,page,size}` 读、分页器接 `onChange(p, ps)` 并把两个参数
  都发回接口。`/api/workflow/webhook-logs` 的 `limit` 参数**后端已不再读**（改收 `page`/`size`），
  前端那个「条数」输入框因此删掉了，改成由分页器决定 —— 留着会是一个改了不落地、还回 200 的控件。
  配置变更历史弹窗（`config-history`）的「版本总数」卡读 `diff_summary.total_versions`，
  **不是** `versions.length`：后者在截断时把「共 37 个版本只比对了最近 20 个」写成「一共 20 个版本」，
  而 `truncated` 为真时下面那条 Alert 要同时点名两个数。
  ⑥ 预算面板那一列三段标签（`exceeded_count > 0` → `alerts.some(a => a.warning)` → 否则绿色）
  只有在后端**每条指标都能独立拿到 `warning`** 时才成立（口径见 §4）：否则「用到 90%」那一行会画成
  ✅ 正常，而标签本身没坏 —— 排障时先看接口里那条告警的 `warning`，别看模板。
- 特性开关页（`views/kode/feature-flags/index.vue`）跟三条后端契约：
  ① `rollout` 越界/非整数现在回 **400**（后端不再替你夹），页面上的两个输入框与后端同界（`:min="0" :max="100"`）；
  ② `GET /api/feature-flags/check` 在 `0<rollout<100` 时**必须带 `user_id`**，缺主体回 400
  （「灰度 10%」对着没有主体的调用回「命中」是假答案），所以输入框的 placeholder 写的是
  「部分灰度必填」而不是「可选」；全量/零灰度仍与主体无关，试算框不会常驻红；
  ③ 结果区的**错误分支不许写 `v-else`** —— 它上面连着三个 `v-if`，`v-else` 只绑最近那一个
  （`bucket !== undefined`），于是 `full_rollout` 这种**没有 `bucket` 键的成功响应**会渲染出一个
  内容为 `undefined` 的红 Tag，看着像报错、其实判定是成功的。错误一律显式 `v-if="checkResult.error"`。
  同一条形状线也适用于 `toggle`：库里那一行的 `enabled` 现在真的会被改掉（此前只有关没有开）。
- 定时任务页（`views/kode/schedules/index.vue`）消费 §6 那六个调度读入口的 `{tasks|rows, available, reason}`，
  三条硬口径：① **每条腿单独 `Promise.allSettled` + 各自的归一函数**（`listOf/tasksOf/locksOf/statOf`），
  归一时先铺 `EMPTY_*` 再 spread 响应、最后把 `rows/tasks/locked` 补成空集 —— 所以**未知形状（含 `available` 缺键）
  一律落进「读不到」方向**，旧后端不会渲染成「这台系统没跑过任务」。② 「执行成功率」「锁定中」两张卡
  读不到时出 `—` **而不是 100%/0**，卡片下方一行红字带 `reason`：旧写法 `vals.length === 0 ? 100 : …`
  把「一座库没读到」和「一次都没跑过」都画成绿色 100%（`Object.values(health.value)` 在形状改成
  `{tasks,…}` 之后更是恒为空 —— 于是**永远 100%**，而页面看着一切正常）。
  「真没跑过」与「读不到」的分工：前者由「任务健康指标」卡那台 `暂无执行数据` 说明，后者出告警条。
  ③ 换主体（打开某个任务的历史/租户弹窗）**先把行清空再取**，失败时保留的是上一个任务的记录。
  `lock-status` 是内存注册表、没有 `available` 键，所以「传输失败」出 `—` + 原因、「真的没锁」出 0。
  类型面在 `api/kode.ts` 只写一份（`ReadFace`/`ReadList`/`ScheduleHealth`/`ScheduleTenantStats`），
  视图不许各自内联一份 `available: boolean` 的形状。
- 例外：uni3 前端壳的 Runtime 端点（`/api/plugins/frontend/{manifest|schema/*|presets|diagnostics}`）是**已发布的外部契约**，
  恒 HTTP 200 + `code:0`（失败 `code:404/500` 仍在 200 里），壳的 `request.uts` 只判 `payload.code === 0` 后取 `data`。
  §3 的统一信封只管本项目自有客户端，别把这几个端点"顺手改规范"——会打断已分发的 App。