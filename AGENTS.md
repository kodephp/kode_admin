# AGENTS.md — Kode 通用后台开发约束（IDE / 模型必读）

> 本文件是本项目唯一开发规范入口。换 IDE 或换模型时，把本文件全文喂给它即可开工。

## 1. 项目速览

- 后端：PHP >= 8.3 常驻多进程（`php kode start`，默认 127.0.0.1:9527，11 worker）。
- DB：**pgsql 为主**（FlyEnv PostgreSQL 18，root 空密码，库 `kode_app`），sqlite 仅压测备用。
- ORM：**默认 Doctrine ORM**（模型 `app/models/`，仓储 `app/repositories/`）；旧 `Db::` 查询构建器仅存量代码可用，新代码一律走仓储。
- 前端：管理 Web 用 Vue3+Element Plus 系 SPA（见 §7）；C 端多端用 uni-app x。后端只出 JSON。

## 2. 目录铁律（违反直接返工）

- `app/` 下目录全小写，类文件首字母大写驼峰；命名空间全小写且与目录一一对应。
- 多应用布局（框架自动发现，无需配置）：
  `app/admin/` = 后台子应用（路由 `app/admin/routes.php`，标签 `app:admin`），
  `app/api/` = C 端子应用（路由 `app/api/routes.php`，前缀 `/c-api/*`，标签 `app:api`）；
  控制器在各应用 `http/controllers/` 下，命名空间 `app\<应用>\http\controllers\...`。
  全局共享（中间件/trait/实体/仓储/support）放 `app/http/`、`app/entities/` 等顶层目录。
- 控制器方法无返回类型声明（返回 Response/数组均可，**不要写 `: array`**）。
- 用 `Request::` 门面必须 `use Kode\Http\Request;`（否则回退到当前命名空间 fatal）；收参优先控制器短方法。
- 插件：`plugins/<Name>Plugin.php` 实现 `PluginInterface`，路由前缀 `/api/<插件>/`，`config/plugins.php` 登记。
  插件名恒等于 URL 前缀（`name()` 返回值即 `/api/<name>` 的 name，供运行时门禁匹配）；
  插件控制器放 `plugins/<name>/`（命名空间 `Kode\Plugins\<name>`）；
  插件 `menus()` 声明支持 `auth_code`（权限码，空=登录可见）与 `parent`（挂到的目录 path，如 `/cms`）；  插件表名前缀 `plug_<name>_`（核心表不加前缀）；插件类可选 `version()`（插件管理页展示）。
- 插件市场：打包 `GET /api/plugins/{name}/package`，远程安装 `POST /api/plugins/install-remote`
 （sha256 强制校验，20MB 上限，落盘不覆盖，装完 reload 生效）；协议见 `docs/MARKETPLACE-PROTOCOL.md`。
- 实体：`app/models/`，Attribute 注解映射；时间字段一律 `timestamptz_var` 类型 + `TIMESTAMPTZ` 列。
- 新增类无需 `composer dump-autoload`（PSR-4 即时加载），但改完必须重启服务（路由/插件启动期加载）。

## 3. 后端开发规范（四层架构，详见 docs/ARCHITECTURE.md）

- 分层：Controller（收参/校验/调服务/包信封，零业务）→ Service（`app/services/`，业务规则+编排，
  抛 `RuntimeException(msg, httpCode)`）→ Repository（只做 persistence）→ Model（映射+脱敏无逻辑）。
  跨模块调 Service，跨插件走事件；Service 方法即事务边界候选。
- Service 拆分线：单个 Service 超 ~500 行按聚合拆（拆分方向 = 业务聚合边界，必要时升为插件）；
  禁止跨聚合直接调库，跨聚合读走对方 Service 读方法、写走事件。
- 协变逆变：fluent setter 返回 `static`（非 `self`，子类链式安全）；重写方法参数只放宽、返回只收窄；
  工厂/命名构造返回 `static`；接口一旦发布不再收窄。

- 收参用控制器短方法：`$this->input/query/post/params/only/param`；读 header/文件用 `$this->request()`。
- 校验用 `$this->validate($data, $rules)`（Symfony Validator 字符串管道），失败自动 422，**不要手写 try/catch 包格式**。
- 返回一律走统一信封（短字段，`app/support/ApiEnvelope.php`）：
  成功 `{code:HTTP状态(默认200), msg:"ok", dur:"0.0003s", data?:…}`，
  失败 `{code:HTTP状态, msg, dur, errors?}`，分页同级加 `total/page/size`（`data` 为当页数组）。
  控制器用 trait 方法 `$this->ok($data,$status)/$this->fail($msg,$status)/$this->page()`，**禁止自造其他格式**。
  校验失败由 `TimingMiddleware` 自动转 422 信封（含 `errors`）。
  框架层直返错误（鉴权 401、门禁 403、404 等 `{message}` 形态）由同一中间件出口归一化进信封。
- 常用 code/msg（code 恒等于 HTTP 状态，可按业务替换增改）：
  200 ok · 201 已创建 · 400 参数错误 · 401 未认证 · 403 权限不足 ·
  404 不存在 · 409 已存在 · 422 参数校验失败 · 429 过于频繁 · 500 服务器错误。
- 字段一律短命名：`code/msg/dur/data/total/page/size/errors`（`duration` 这类长词禁用）。
- 密码一律 `password_hash` / `password_verify`；对外数组永不含 password（实体 `toArray()` 已脱敏）。
- 守卫顺序：`AuthMiddleware` 在外，`RequireRole('slug')` 在内；超管专属操作控制器内二次确认（如初始账号不可删）。
- 登录防爆破：同 IP/账号 10 分钟失败 10 次即 429；测试时注意清缓存或换账号，勿把自己锁死。
  注意本机调试所有请求同属 127.0.0.1（共享 IP 预算），输错几次密码会连带锁住正确登录；
  被锁时清 `storage/cache/*` 或等 10 分钟；默认账号 `admin / ChangeMe123!`（首次登录必改）。
- 账号状态：`status` 0 禁用拒绝登录（403）；初始超管不可禁用/删除。
- 操作审计：`AuditMiddleware` 只记元数据（谁/方法/路径/状态/耗时/IP），**禁止记录请求体**；
  探针路径跳过；审计失败绝不影响主流程。
- 文件上传：`Request::file('file')` 取文件，大小 ≤10MB、白名单扩展名，存 `storage/uploads/{年月}/`，
  下载走鉴权接口（生产静态资源走 nginx/CDN，本接口用于需鉴权的中小文件）。
- 时间列读写 `\DateTimeImmutable`；展示格式化 `Y-m-d H:i:s`。
- DB 表结构变更两处同步：`database/migrations/`（增量）+ `database/sql/schema.sql`（全量中文注释版）。
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
- 分片表按需开通：`Shard::ensureTables($base)` 在写/读入口调用（worker 内每基名检查一次），
  无需预建；分片库表 DDL 以迁移 + `Shard::ddlFor` 为准，保持一致。
- 分布式事务：跨库/跨片写用 `Saga::run()`（do/undo 步骤，失败逆序补偿，补偿失败记 trail 人工介入）；
  do/undo 必须幂等可重入；强一致场景走单库事务，不用 Saga。
- 归属：分片表必备 `created_by`，写操作验归属（本人或 admin，否则 403）；
  跨片错误经 `Shard::onShard` 自动前缀 `[shard:库.表]`，结合 trace_id 定位故障片。
- 追踪错误：所有分片异常带片标识 + trace_id；审计/日志按 trace_id 串联。

## 4. 安全红线

- `.env` 不入库；`JWT_SECRET` 强随机；生产 `APP_DEBUG=false`。
- SQL 一律绑定参数，**禁止字符串拼接**；表/字段名走白名单（框架 Schema 已做）。
- 用户可控数据进响应头由框架剥离 CR/LF；CORS origins 收敛具体域名（禁止 `*` + 凭证）。
  本地联调前端（:8848）需开 `CORS_ENABLED`，origins 只写实际访问源（单值回显，多值浏览器拒收）。
- `/metrics`、`/docs` 禁止 `protect=none` 对公网；`/health` 失败明细仅 debug 可见。
- 会话固定：登录成功后轮换会话/CSRF 令牌；JWT `exp` 不超过 `iat + ttl`。
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

## 5. 性能红线

- 热路径（控制器/中间件）禁止同步阻塞外部调用（curl/OTLP）、禁止文件 I/O、禁止 `sleep`。
- 常驻进程禁止在请求内累积静态状态；用完的重量资源（EM 由中间件自动 `clear()`）及时释放。
- 追踪/指标保持 opt-in + 低采样；压测口径见 kode 框架文档 benchmarks（同窗比值）。

## 6. 测试与验证

- 改完必跑：`php -l`（语法）→ 相关 curl（登录→业务→越权 401/403）→ `composer test`。
- 批量改完必须 `php -l` 全扫 + curl 复测（正则批量替换易吃括号，如 `$this->ok([` 收尾 `]);`）。
- 注释里禁止出现 `*/` 序列（如 `*secret*/` 会提前终结 docblock 导致整文件语法错误）；用中文括号改写。
- 新文件必须 `php -l` 单验（write 工具不校验语法，未 lint 的文件会炸启动）。
- 框架升级后检查 `config/` 是否缺新 key（缺 key 回退框架默认，旧项目如裸开关曾因此失效）；
  `composer` 操作后核对 `installed.json` 实际版本与预期一致。
- 错误排查顺序：`curl -v` 看状态码 → `.env` 确认 `APP_DEBUG=true` 看 location/chain → `storage/logs/`。
- 端口被占：`php kode status --port 9527` → `php kode stop --port 9527`，禁止直接 kill -9（除非僵死）。
- 重启语义：`restart` = 停同端口旧实例（含残留 worker，仅认 `kode-http:` 进程名）+  fresh 拉起
  （默认前台显示启动界面，`-d` 守护）；`--rolling` 保留零停机滚动；`start` 端口被占绝不自动杀进程。
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