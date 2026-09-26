# API 统一文档（由 `php kode route:list` 生成，手工补齐出入参）

> 基地址如 `http://127.0.0.1:9527`。无特别说明均需鉴权头
> `Authorization: Bearer <token>`；统一信封见 AGENTS.md §3
> （成功 `{code,msg,dur,data}`，分页 +`total/page/size`，失败 `{code,msg,dur,errors?}`）。
> 读跨分片数据的接口在**与 `total` 同级**处多一个 `unreadable_shards`：`[{shard,reason,code}]`，
> 恒在（全片可读时是 `[]`，不是缺键）。非空即本页数字只是「读到的那些片」的部分口径 ——
> 客户端不能只取 `data`（那样「少了三片」与「本来就这么点数据」在页面上长得一样），
> 管理端由 `KodeShardGaps` 告警条承接（审计列表/我的操作/趋势、公告列表、看板、分片总览）。
> 对外 ID 均为 HashId 字符串（`uid` 为 `u_` 前缀串），见 AGENTS.md §4。
>
> 本表不是写完就完：`tests/ApiDocRouteGateTest.php` 会把每一行的「方法 + 路径」拿去和真实
> 路由表对账，写了不存在的端点直接红。所以别再用 `GET/POST | A、B` 这种「方法集 × 路径集」
> 的合并写法——机器分不清哪条路径配哪个方法，人也会抄错（一行一端点，成对写清）。

**只读接口的门禁口径**：读接口按**渲染它那张菜单页的 `auth_code`** 挂 `RequirePerm`，与 `/api/menus`
的菜单可见性同源 —— 菜单看得见 == 接口调得动。写侧仍是 `RequireRole('admin')`。
不这么挂的话，「写全 admin、读只验登录」等于把用户名单、系统库存、聚合指标发给任何一枚有效 token。
下面各表里标「登录可看」即只验 `AuthMiddleware`；标具体码（如 `users:view`）即走该门禁。
口径回归见 `tests/CoreReadGateTest.php`（核心只读 GET 必须落在 admin 或某个已声明码里）
与 `tests/AdminReadScopeTest.php`（出参范围）。

**码 → 覆盖的只读接口**（`?` 表示「或」，任一即可）：

| 权限码 | 覆盖 |
| --- | --- |
| `users:view` | /api/users |
| `roles:view` `? users:view` | /api/roles、/api/perms/catalog |
| `menus:view` | /api/menus/{id}/buttons |
| `depts:view` `? users:view` | /api/depts |
| `configs:view` | /api/configs、/api/configs/{key}、/api/storage* |
| `plugins:view` | /api/plugins、/api/plugins/{name}/contract、/api/plugins/marketplace/* |
| `notices:view` | /api/dicts、/api/dict-items |
| `audits:view` | /api/schedules*、/api/schedule/*、/api/monitor、/api/system/health |
| `menu.feature_flags` | /api/feature-flags、/api/feature-flags/stats、/api/feature-flags/check |

恒为超管（不随角色勾选放开）：`/api/audits`（全站操作流水与列表）、`/api/audits/export`、
`/api/audits/overview`、`/api/feature-flags/history`、`/api/monitor/shards`、`/api/schedules/lock-status`。
只验登录（出参自带身份范围，或是被内嵌组件消费的共享资源）：`/api/menus`、`/api/audits/mine`、`/api/audits/stats`、
`/api/dashboard/summary`、`/api/configs/site`、`/api/notifications*`、`/api/secure/ping`、
`/api/media{,/dirs,/months,/resolve}` 与 `/api/files/{path}`。

媒体库是这一组里唯一「读不设码」的业务数据源，理由是 `KodeMediaPicker` 内嵌在**个人中心换头像**、
富文本插图和站点设置里 —— 它不属于某一张菜单页，收不进任何 `auth_code`（收了就是别人的头像选择器 403）。
成立的前提写在写侧：**媒体库的一切变更（建目录/改名/移动/替换/删除/转储）恒为超管**，
`POST /api/upload` 只能往库里加自己的文件。回归见 `tests/CoreWriteGateTest.php`。

## 探针（公开）

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | /ping、/health、/health/live、/health/ready | 探活/存活/就绪 |

`/health` 由 `app/routes.php` 实现（**覆盖**框架内置的同名端点），回一份自述报告：
`{framework, version(框架内核), app_version(骨架), http(HTTP 内核), time, status, components}`。
`components` 逐项是 `ok` / `not_configured` / `error`（能力未接线算 `not_configured`，**不计入失败**，
免得每个新环境都起红灯）。判定与状态码同源：任一探针 `error` → `status:"degraded"` + **HTTP 503**，
readiness 靠这个摘流。失败明细里的连接串/SQLSTATE 仅 `APP_DEBUG=true` 时可见，生产只出 `error` 二字。

这几条路径**不进统一信封**（见 `app/middleware/TimingMiddleware.php` 的 `SHAPE_STABLE`）：
监控只有一套解析代码，若 503 时被改写成 `{code,msg}`，报告就恰好在它唯一被读的那次失踪。
回归见 `tests/HealthDegradedReportTest.php`（用真失败探针验判定与脱敏）
+ `tests/ErrorResponseEnvelopeTest.php`（验透传边界与「其余载荷搬进 data」）。

## 鉴权

| 方法 | 路径 | 入参 | 出参 data |
| --- | --- | --- | --- |
| POST | /api/auth/login | `{username, password}` | `{token, user, roles}` |
| GET | /api/auth/me | — | `{uid, username, avatar, roles, perms}` |
| GET | /api/auth/login-logs | 我的登录日志 |
| GET | /api/auth/login-logs/all?username= | 全员登录日志（admin） |
| DELETE | /api/auth/login-logs/clean?days= | 清理我的登录日志；days 必填且须在 7..3650，越界 400（未认证先 401） |
| POST | /api/auth/password | `{old_password, new_password}`（长度走 `auth.password_min_length`） | `{updated, revoked_tokens, relogin}`（错到上限 429） |
| PUT | /api/auth/profile | `{name, email, avatar?}` | 用户对象 |
| POST | /api/auth/refresh | 旧 token（头） | `{token, exp}`（旧 token 作废） |

**账号处置即时生效**：禁用（`/users/{id}/toggle`）、收回/改分配角色（`/users/{id}/roles`、`batch-roles`）、
重置密码（`/users/{id}/password`）、删除账号，都会**撤销该用户全部在发令牌** ——
鉴权只验签、`RequireRole` 读的是登录时铸好的载荷，不撤销的话处置要等到令牌自然过期（默认 1 小时）才生效。
客户端表现为下一次请求 401，按重登处理。自助改密（`/api/auth/password`）同理，响应带
`{updated, revoked_tokens, relogin:true}`，且原密码连续错到登录同一阈值即 429（独立计数键，不会连带锁死登录）。

## 用户（列表/详情需 `users:view`，其余 admin）

| 方法 | 路径 | 入参 | 出参 data |
| --- | --- | --- | --- |
| GET | /api/users | `page/size/search/dept_id/status/start_time/end_time/id/remark`（email 脱敏，附 `roles`/`dept_name`，id 为 HashId）（门禁 `users:view`） | `{data,total,page,size}` |
| POST | /api/users | `{name, email, password, dept_id?, remark?}`（密码长度走配置） | `{id}` 201 |
| PUT | /api/users/{id} | `{name, dept_id?, remark?}` | `{id}` |
| DELETE | /api/users/{id} | —（超管除外） | `{deleted}` |
| POST | /api/users/{id}/roles | `{roles:[]}`（slug 须在 `/api/roles` 内，否则 400 并列出不存在的名字；空数组=清空） | `{id, roles}` |
| POST | /api/users/{id}/toggle | —（超管除外） | `{id, status}` |
| POST | /api/users/{id}/password | `{password}`（长度走配置） | `{id}` |
| GET | /api/users/export | — | CSV 下载 |
| POST | /api/users/batch-delete | 批量删除 `{ids}`（跳过超管，admin） |
| POST | /api/users/batch-roles | 批量分配角色 `{ids, roles}`（admin；slug 同样白名单校验，整批先校验，不做半批） |
| POST | /api/users/import | CSV 导入（列 name/email/password/remark?，2000 行上限，逐行报告，admin） |

## 角色 / 菜单 / 部门 / 配置

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET/POST | /api/roles | 列表（含 perms/status/remark/created + `member_count`，`search` 模糊；读需 `roles:view` 或 `users:view`）/新增（admin） |
| PUT/DELETE | /api/roles/{slug} | 改名/权限/状态/备注/删除（admin 角色除外，admin）。PUT 为局部更新：可改字段一律「缺键保留原值」，列表页状态开关只发 `{status}` 即可；显式 `perms:[]` 才是清空。**禁用或删除角色会撤销其成员的全部在发令牌**，响应带 `revoked_sessions` |
| GET | /api/menus | 嵌套树（含一级目录，`?buttons=1` 附按钮；按身份过滤 auth_code+status，非超管禁用的不可见；禁用中的角色不算身份）。登录可看：侧边栏本身，出参已按身份裁剪 |
| POST | /api/menus | 新增 `{title,path,icon,plugin,sort,parent_id?,auth_code?,status?,name?,component?,meta?}`（admin，防环+深度保护，写清 redis 缓存） |
| PUT | /api/menus/{id} | 改（字段同上，`title`/`path` 仍为必填）（admin，防环+深度保护，写清 redis 缓存） |
| DELETE | /api/menus/{id} | 删除（按钮联带删，子菜单上移顶级，admin） |
| GET/POST | /api/menus/{id}/buttons | 按钮列表（读需 `menus:view`）/新增 `{code,title,sort}`（admin，code 全局唯一） |
| PUT/DELETE | /api/menu-buttons/{id} | 按钮改名/删除（admin，code 不可改） |
| GET | /api/perms/catalog | 权限目录（按插件分组，供角色勾选）。授予面 = 菜单 auth_code ∪ 按钮码 ∪ 各插件 `permissions()` 声明的操作码，唯一派生口是 `app\support\PermCatalog`（审计 `PluginContract::auditPermGates()` 读同一份集合）；读需 `roles:view` |
| GET | /api/depts | 部门树（含 status/remark/created；读需 `depts:view` 或 `users:view`） |
| POST | /api/depts | 新增部门（admin） |
| PUT | /api/depts/{id} | 改名/调整（admin） |
| DELETE | /api/depts/{id} | 删除（有子部门或有人时 409，admin 写） |
| GET | /api/configs/site | **登录即可读**的品牌键子集：固定返回 `{site.title, site.logo, site.icp, site.description}` 四键（缺值为空串，敏感键一律掩码）。登录页/页脚要在拿到权限码之前显示站点名，而整表 `/api/configs` 已收进 `configs:view` —— 按前缀放行会把 `site.*` 里的其它键一起漏出去，所以逐键列死。它与 `/api/configs/{key}` 的先后不是正确性前提：无参字面量走 kode/http 的静态查表、先于任何 `{param}` 路由命中（断言见 `tests/RouteReachabilityTest.php`；靠顺序的是两条动态路由之间，如插件的 `/api/{path:.+}` 兜底）。门禁见 `tests/CoreReadGateTest.php`。 |
| GET | /api/configs | 配置列表（含 ctype/ctitle/cgroup/sort），**读需 `configs:view`** |
| GET | /api/configs/{key} | 单键读取（含敏感掩码），**读需 `configs:view`** |
| POST | /api/configs | 写入：`{key,value?,remark?,type?,title?,group?,sort?}` 键名在 body 里（admin，写失效缓存；`DELETE /api/configs/{key}` 才是删除）。**局部更新口径**：缺键=保留库值（读侧对 secret 键恒出掩码，把「没发」当「清空」会让列表页一次「保存配置」抹掉全部凭证），显式空串仍是清空；`value` 等于掩码字面量 `******` → 400（掩码不是真值）；`plugin.*` 键 → 400，那些行归 `PUT /api/plugins/{name}/config` 托管（按 schema 校验、认掩码、失效插件自己的 `plugin:config:*` 缓存键）。回归见 `tests/ConfigWriteOwnershipTest.php` |
| POST | /api/configs/reset | 恢复出厂默认值（只补缺失键，admin） |
| DELETE | /api/configs/{key} | 删除配置（admin；`plugin.*` 键同样拒绝，去插件详情页配置区） |

## 插件市场（写操作 admin；库存类只读需 `plugins:view`）

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | /api/plugins | 列表（版本/状态/菜单/所属表/能力清单/契约审计/**依赖图**）（读需 `plugins:view`：全站装了什么本身就是库存） |
| GET | /api/plugins/{name}/contract | 单个插件完整能力契约 + 零漂移审计明细 + **依赖概览**（读需 `plugins:view`） |
| POST | /api/plugins/{name}/{install,pause,resume,upgrade} | 安装/暂停/恢复/升级；**install/resume/upgrade 依赖未就绪拒绝**；**pause 级联暂停下游** |
| POST | /api/plugins/{name}/uninstall | `{confirm:1, purge?}` 二次确认；purge 时删表 + **清配置**；`backup_hint` 只给表名，**不含主机/账号/库名**（连接参数由执行者本机的 PG* 环境变量提供） |
| GET | /api/plugins/{name}/package | 打包下载 zip（含 plugin.json 清单，随包携带 contract） |
| POST | /api/plugins/install-remote | `{url, sha256, name}` 远程安装（闸门顺序：入参格式 → 出网闸 → sha256 → 清单 → 解包，任一失败不落盘不登记，装完 reload） |
| GET | /api/plugins/{name}/config | schema 驱动的插件配置表单数据源（secret 掩码回显） |
| PUT | /api/plugins/{name}/config | `{values}` 全量保存；校验失败返回 `400 + errors` |
| GET | /api/plugins/{name}/detail | **运行时详情**：路由/事件/定时/命令/菜单完整条目（`PluginRuntimeDetail::detail()`） |
| GET | /api/plugins/{name}/health | **健康检查**：各检查项 ok/failed + 汇总 |
| GET | /api/plugins/{name}/logs | **运行日志**：按插件名过滤系统日志（分页） |
| GET | /api/plugins/audit-report | **审计报表**：全量插件契约+健康+依赖聚合 |
| GET | /api/plugins/audit-report/export | **审计报表导出 JSON**（附件下载） |
| GET | /api/plugins/audit-report/export/csv | **审计报表导出 CSV**（附件下载） |
| GET | /api/plugins/marketplace/{health,list,check-updates} | **市场连通性/可安装列表/可升级检查**（读需 `plugins:view`） |
| POST | /api/plugins/batch-pause | **批量暂停**：`{names:[...]}` 级联暂停下游 |
| POST | /api/plugins/batch-resume | **批量启用**：`{names:[...]}` |
| POST | /api/plugins/batch-uninstall | **批量卸载**：`{names:[...]}`，**只翻状态不删表**；传 `purge=1` 直接 400（要清表请逐个走单插件接口） |
| GET | /api/plugins/{name}/files | 插件目录文件清单（主类优先，含 size/mtime/is_main） |
| GET | /api/plugins/{name}/files/read?path= | 读单个文件源码（≤1MB，且只能是本插件的文件） |
| PUT | /api/plugins/{name}/files | `{path, content}` 编辑器保存：**只改已存在的文件**，扩展名白名单 `php/json/md/txt/yaml/yml`，≤1MB |
| GET | /api/plugins/{name}/backup | 导出插件备份 JSON `{ok, name, json, missing: string[], message}`；`missing` 点名「列进了清单但读不动」的条目，备份本体自带 `complete`/`missing` 两个键自述完整性 |
| POST | /api/plugins/backup/restore | `{json}` 恢复；响应 `{ok, restored, skipped, failed: {插件名:[原因]}, message}`，**任一条目未落盘即 400** + `errors.restore` 带原因；`complete:false`（或缺文件）的备份整插件直接判失败、一个字节都不写，不去翻配置与状态 |

**远程安装清单三道门禁**（`PluginPackager::verifyManifest()`，sha256 之后、落盘之前）：
① 包内必须有 `plugin.json` 且声明 `name`；② 清单 `name` 必须等于请求的 `name`
（防「请求 foo、落盘 BarPlugin.php」导致登记状态与实体错位）；③ 清单 `kode` 下限必须被
当前框架版本满足（用 `PluginDependency::satisfies()` 判定）。校验通过后**状态与版本同时登记**，
响应额外返回 `{version, kode}`；未声明 `kode` 的旧市场包视为不限框架，但 `name` 仍必须一致。

**备份恢复与远程安装共用一条落盘纪律**（`PluginFileExplorer::check()/write()`、
`PluginBackupService::import()`，断言见 `tests/PluginBackupRestoreTest.php`）：

- 恢复的 JSON 是用户递进来的，所以**归属在拼路径之前就判**：`..`/`.`/空段/绝对路径/盘符直接拒，
  不等 `realpath()` 兜底（`realpath` 会把 `../` 折掉，折完仍在 `plugins/` 里就查不出来了）。
  允许的形状只有两种——这个插件**自己的**根级主类文件（由 `discover()` 反查，不是按命名约定猜），
  以及 `plugins/<name>/` 之下。
- 凭空建文件的能力只属于恢复链路（`write(..., allowCreate: true)`）；编辑器那条路
  （`PUT /api/plugins/{name}/files`）要求目标已存在。符号链接（无论是中间目录还是目标文件）一律不写。
- **先判后写**：`import()` 先用 `check()` 把该插件的所有条目判完，有一条不落盘就整个插件算失败，
  既不动它的配置也不翻它的状态（`kode_plugins` 里不会凭空出现 `enabled`）。半套文件 + 已启用
  就是下一次启动的 fatal。
- 补建的目录恒 `0775`、新文件恒 `0644`（不跟 umask 赌）；`export()` 把读不动的条目放进
  `missing` 并写进消息，不再静默少文件。
- 边界：插件主类文件被整个删掉时（`discover()` 已认不出这个插件），根级那一条判不出归属，
  恢复照实报失败——那种情况走市场重装，而不是让一份 JSON 凭空铸一个新主类文件。

**存储驱动的可用集合**不是写死的：`GET /api/storage/drivers` 由 `DriverRegistry` 产出，
即「已启用插件在 `drivers()` 中声明的驱动键」全集。`POST /api/storage` 与
`POST /api/storage/{id}/default` 都按注册表校验——插件暂停/卸载后其驱动立即下线，
存储页返回 `400 + 「驱动未注册」`。

插件列表 / 契约响应中的能力字段（由 `app\support\PluginContract` 产出）：
`capabilities`（已声明能力键，按 schema 顺序稳定输出）、`groups`
（按 `meta/lifecycle/data/expose/integration/config` 分组）、`tables`、`menus`、
`commands`、`events`、`crons`（未声明均为空数组；列表与契约详情字段一致）、
`permissions`、`drivers`、`requires`、`config_schema`、`meta`、
`errors`（方法声明了但执行失败的能力，区别于「未声明」）、
`contract_ok`、`contract_drift`（声明漂移，含 crons/commands/events 声明了却未注册进调度器/内核/派发器，
表名非法或重复、`version()` 返回值非法或为空、`requires()` 依赖版本约束语法无法解析、
菜单形态非法、路由 `RequirePerm` 门禁码未被任何插件 `permissions()` 声明）、
`contract_pending`（待复查：环境未就绪 / 依赖未装 / 调度器里有本插件任务但 crons() 未声明 /
权限码既无菜单引用也无路由门禁引用）。
契约详情额外返回 `schema`（能力 schema 定义）与 `group_labels`（分组中文名）。

> ⚠️ **字段类型警告**（前端消费必读）：
> - `groups` 是**对象**（`{ group_name: [capability, ...] }`），**不是数组**。前端用 `Object.keys(p.groups)` 取值。
> - `events` 是**对象**（`{ event_name: [listener, ...] }`），**不是数组**。
> - `config_schema`、`meta` 均为**对象**（任意嵌套），不是数组。
> - `dependencies` 每项的就绪字段是 `ok`（boolean），**不是** `ready`。
> - 其余字段（`tables`, `menus`, `commands`, `crons`, `capabilities`, `permissions`, `drivers`, `requires`）均为**数组**。
> - 📖 完整前端字段结构见 `docs/PLUGIN-FRONTEND.md` §1-§2。

依赖字段（由 `app\support\PluginDependency` 产出，列表与契约响应均含）：
`dependencies`（直接依赖列表，每项 `{name, version, installed, status, ok}`）、
`dependents`（下游依赖者名列表）、`transitive`（传递依赖闭包）、`cycle`（是否成环）。
`install`/`resume`/`upgrade` 依赖不满足时返回 `400 + {dependencies: {ok, missing, notEnabled, versionMismatch, checked}}`；
`pause` 响应含 `{cascaded: [name...], hint}`。

审计是只读的：生命周期方法 `install/uninstall/upgrade` 带建表/删表副作用，
审计只校验存在与签名形态、绝不执行；能力方法执行失败归 `contract_pending`，
不等于 `contract_drift`。`contract_drift` 非空即声明⟺实现不一致，发版前必须清零。

打包清单 `plugin.json` 的 `kode` 字段是**框架版本下限**，由
`app\support\PluginPackager::frameworkConstraint()` 从
`vendor/composer/installed.json` 读真实 `kode/framework` 版本取主.次导出（如 `>=1.3`），
读不到才回退 `>=1.0`。市场侧据此判断兼容性，不要再按写死值判断。

**安装失败的出参口径**（前端消费必读）：出网闸/sha256/清单/解包任一不过，控制器返回
**`400 + {code:400, msg, dur}`**（`PluginController::installRemote()` 走统一 `$this->fail`，
不是 `200 + {ok:false}`），并记一条 `plugin` 系统日志；且**不改动 `plugins/` 一个字节、不写状态行**
（判定依据是全树快照对比，见 `tests/PluginSupplyChainTest.php::assertRefused()`）。
条目白名单与出网闸的完整规则表见 `docs/MARKETPLACE-PROTOCOL.md` §2.1、§3.1；
这里只列前端会看到的 msg 关键词：`插件名非法` / `sha256 非法` / `只允许 http(s) 地址` /
`地址不允许携带凭证` / `拒绝内网/保留地址` / `重定向` / `体积上限` / `为空` /
`校验失败：sha256 不匹配` / `清单声明插件名…不一致（疑似偷名）` / `不兼容` /
`包内含非 .php 条目` / `包内含隐藏文件条目` / `包内条目位置非法` /
`包内条目与已有文件同名` / `包内条目越界` / `包解压超出体积预算` / `包内无有效文件`。
`200` 时 `data` 含 `{name, files, version, kode, reload}`，`files` 为实际落盘文件数
（`0` 已在失败侧，不会出现「空壳包被翻成启用」）。

## 前端资产仓库插件（gitee-release，门禁 `gitee:view` / `gitee:edit`）

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET/POST | /api/gitee-release/repos | 仓库列表（token 掩码回显）/ 新增（`repo`+`token`+`branch`+`install_path`） |
| PUT/DELETE | /api/gitee-release/repos/{id} | 改配置 / 删除 |
| GET | /api/gitee-release/repos/{id}/releases | 拉远端 release 列表（只读，带 token 的出站请求） |
| GET | /api/gitee-release/repos/{id}/check-updates | 比对 `last_version` 与最新 tag |
| GET | /api/gitee-release/repos/{id}/downloads | 下载记录（`status` queued/running/succeeded/failed + `error`） |
| POST | /api/gitee-release/repos/{id}/install | `{version, sha256?, asset?}` 下载并安装到 `public/<install_path>/v<version>/` |
| POST | /api/gitee-release/repos/{id}/test | 测连（验 token 与仓库可达） |
| GET | /api/gitee-release/versions | 本地已安装版本（扫 `public/<install_path>/v*` 目录） |

`{id}` 对外是 HashId 不透明串（路由约束 `{id:[A-Za-z0-9]+}`，非法进 `404 仓库不存在`）；
失败一律走信封：业务不符 `400`、仓库不存在 `404`、鉴权/权限 `401/403`。

安装侧的五道闸门（与插件市场同一套实现，**不另起炉灶**）：
① `version` 必须是纯点分数字（`safeVersion()`），否则拼 `targetDir` 时就是路径注入；
② `install_path` 走 `safeInstallPath()`，绝对路径/`..`/空段一律拒，前导 `/` 归一为相对；
③ **只认归档资产**（`archiveExtOf()` 命中 zip/tar/tar.gz/tgz）——早先「拿第一个资产、
不是归档就整文件搬进 web 根」会把 `.exe`/`.bin` 原样落到 `public/` 下成为可直接访问的意外产物；
④ 下载走 `RemoteFetch::toFile()`（逐跳过闸 + 钉 IP + 100MB 边下边算，见
`docs/MARKETPLACE-PROTOCOL.md` §3.1）；
⑤ 解包走 `SafeArchive::extract(..., [self::class,'assertStaticEntry'], noOverwrite: false)`：
每个条目必须落在**静态资产白名单**内（html/js/css/json/map/字体/图/视频/wasm 等），
`.php`/`.phar`/`.sh`/`.so` 等一律抛错整包中止，零条目空包算失败而非「安装成功」。
同版本重装允许覆盖（目录本就归这个版本），但白名单不因此放宽。

**`repo` 段也是入参**：`/repos`、`/releases`、`/test` 三条请求的 URL 形如
`https://gitee.com/api/v5/repos/{repo}[/releases[/tags/{version}]]?…`，主机恒定、由用户控制的只有这一段
路径（所以它们不走 `RemoteFetch`——那里治的是「主机也能选」）。`safeRepo()` 管形状**并禁 `..`**：
老写法只挡 `owner/name` 形状，`owner/..` 能过，拼出的 `/api/v5/repos/owner/../releases` 经服务端归一
会打到别的 API 路径上，而这一发带着用户的 `PRIVATE-TOKEN`。写侧（`POST /repos`）与读侧（三条出站请求）
都过一遍，库里躺着的历史脏数据也出不去。

> 这五道校验全是 **public 纯函数**（`assertStaticEntry()` / `safeVersion()` / `safeInstallPath()` /
> `archiveExtOf()` / `safeRepo()`），可以离线逐条断言，见 `tests/GiteeAssetPolicyTest.php`。
> 其中 `assertStaticEntry()` 尤其不能写成 private：`SafeArchive::extract()` 第 3 参声明为 `?callable`，
> 把 private 静态方法当数组回调传出去会直接 TypeError（可见性按被调函数处判定），装一次炸一次。
> 该文件还含「把过滤器真的接进 extract」的接线回归。

## 审计 / 系统 / 上传

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | /api/audits?page&size&username&path&method&status&start_time&end_time | 审计日志（跨分片归并；status 支持精确码或 2/4/5 百位类）（admin：全站操作流水）。信封同级带 `unreadable_shards`：读不动的片不影响其余片的行与 `total`，但那份 `total` 就此只是部分口径 |
| GET | /api/audits/export | 审计 CSV 导出（admin，最多 5000）。头已发出、状态码改不了，所以缺片写进正文尾巴的 `#` 注释行（哪一片、为什么），而不是只留在信封字段里 |
| GET | /api/audits/stats | 近 7 天审计量（跨分片聚合）。**登录可看，但出参按身份分岔**：超管拿全站逐日量，其他人只拿**自己**触发的行（同一条 SQL 加 `username = 本人`），返回形状恒为 7 天列表，图表两端都能画；缺片走信封同级的 `unreadable_shards`，**不动 `data` 形状** |
| DELETE | /api/audits/clean?days= | 保留期清理；days 必填且须在 7..3650，越界 400（不静默夹取）。**有任何一张片表没删动就回 500 并点名是哪一张**，且失败响应里不带 `deleted`（带了就等于让读的人把半套删除当成全站口径） |
| GET | /api/dashboard/summary | 数字看板。**登录可看，出参按身份分岔，带 `scope` 字段**：超管 `scope:"all"` + `{users,roles,notices,audits_today,plugins,system}`；其他人只回 `{scope:"self", audits_today}`（本人今日请求数）。全站库存（用户/角色/插件计数、版本与分片开关）不对普通账号出库。缺键而不是给 0 —— 0 会被读成「这台系统没有用户」，那是另一种泄漏；前端按 `scope` 隐藏对应卡片。`notices`/`audits_today` 是跨片聚合，读不动的片走信封同级 `unreadable_shards`，非空时那两个数只是部分口径 |
| GET | /api/audits/overview | 审计总览聚合（admin）：近 7 天口径，聚合恒在 SQL 侧（每片一条带窗口的腿 `UNION ALL` 成一个派生表，`COUNT(DISTINCT)`/`GROUP BY` 都在跨片全集上算）。出参 `{total_7d,today_24h,yesterday,status{2xx..other},methods[],top_paths[],slow_paths[],unique_ips,unique_users,avg_duration_ms,missing_tables}`；`yesterday` 是自然日半开区间 `[昨天00:00, 今天00:00)`；`missing_tables` = 配置里有、库里没有的片表，**非空即上面所有数字只是部分口径**（缺片不再被 try/catch 吃成一个看起来正常的数）。不变式：`sum(status) == sum(methods) == total_7d`，回归见 `tests/AuditOverviewTest.php` |
| GET | /api/audits/mine?page&size | 我的操作记录（按 token 用户名过滤；token 无用户名直接 401，不回落成「查全员」） |
| GET | /api/schedules | 定时任务注册表（#[Cron]，只读；门禁 `audits:view`，与运维监控菜单页同源） |
| POST | /api/schedules/{name}/run | 手动触发一次（admin） |
| PUT | /api/schedules/{name}/toggle | 运行时启停（admin，体 `{enabled}`） |
| POST | /api/schedules/create | 动态注册任务（admin，体 `{name,expression,handler,description?,cluster?,tenant_id?,metadata?}`）；`handler` 只接受 `app\tasks` 下已有任务方法引用（`CleanupTask` / `app\tasks\AuditCleanTask` / `MonitorTask::ping`），不接受代码字符串 |
| GET | /api/schedules/health | 全部任务健康指标（门禁 `audits:view`）。出参 `{tasks,available,reason}` —— framework v1.14.0 起 `ScheduleDispatcher::stats()` 读不到时**抛**（旧写法在包里吞成一份 `total:0 / success_rate:100.0` 的合成行），管理端接成**恒 HTTP 200 + `{available,reason}` 同级键**：`tasks` 恒是对象（失败时是 `{}`），`reason` 只给结论与修法、驱动原文与 host:port 永不出参。判定只在 `app\support\DbReadFace::reason()` 写一份。这一屏下面六个读口同一口径，回归见 `tests/ScheduleReadFaceTest.php` |
| GET | /api/schedules/{name}/history | 单任务执行历史（`?limit=` 夹到 1..200）。出参 `{rows,available,reason}`；「历史表还没建」算没有历史（`rows:[]` + `available:true`），其余读失败一律点名 —— 抽屉只在有人查故障时点开，空表必须说得出是哪种空 |
| GET | /api/schedules/runs | 非租户任务执行历史（旧表 `kode_cron_runs`，`?task=&limit=`，`limit` 夹到 1..100）。出参 `{rows,available,reason}` |
| POST | /api/schedules/prune?days= | 清理过期执行历史（admin）。days 必填且须在 7..3650，越界 400；`pruneRunHistory()` 抛时回 **500 + 结论**（`清理执行历史失败：数据库不可达`），**失败响应不带 `deleted`** —— 带了就等于把「一条都没删成」念成「清理完成，没有过期数据」 |
| GET | /api/schedule/tenants | 活跃租户清单（门禁 `audits:view`）。出参 `{rows,available,reason}` |
| GET | /api/schedule/tenants/runs | 租户任务执行记录（`?task=`，SQL 侧封顶 200 行）。出参 `{rows,available,reason}` |
| GET | /api/schedule/tenants/stats/{task} | 单任务租户维度统计。出参 `{task,total,success,failed,last_run,last_duration_ms,available,reason}`；**读不到时四个数字回 `null` 而不是 0**（`total:0` 会被念成「这台系统一次都没跑过」），`task` 恒回显问的是哪条任务 |
| GET | /api/monitor | 服务器监控快照（负载/CPU/内存/磁盘/应用）（门禁 `audits:view`） |
| GET | /api/monitor/shards | 分片总览（拓扑+各分片表行数，admin）。拓扑只从 `Shard::config()` 读（DB 热配置优先，不是 `config/sharding.php`，否则扩过片的面板会少扫几片）；每个基表一节 `{base,total,complete,tables[],unreadable_shards[]}`，`tables[]` 逐片带 `conn/db/table/rows`。**`complete:false` 时 `total` 只是「读到的那些片」之和**，缺片在 `unreadable_shards` 里点名（片表不存在/缺列/无权/不可达 + SQLSTATE）；表名标签与行数出自同一次逐片查询，不按结果下标回查清单。回归见 `tests/MonitorShardTotalsTest.php` |
| GET | /api/monitor/db-activity | `pg_stat_activity` 活跃查询（admin）。四条数据库诊断腿共用一份契约：**恒 HTTP 200 + `{available,reason}` 同级键**（形状不随成败变），`reason` 是中文归因（数据库不可达 / 账号无权读统计视图 / 扩展未安装或未装载 / 统计视图不存在），驱动消息与 host:port 永不出参。「表空着」有两种原因（真没有这些数据 / 这座库没读到），前者不用排障、后者必须去排障，所以空表必须说得出是哪一种。判定只在后端 `app\support\DbReadFace::reason()` 写一份（`MonitorController::dbDegradeReason()` 与上面那一屏调度读口都只是它的转发/调用方），前端只按 `available` 渲染告警条。回归见 `tests/MonitorDbDiagnosticsTest.php` |
| GET | /api/monitor/db-statements | `pg_stat_statements` 慢查询 Top-N（admin，`?limit=` 夹到 1..50）。出参 `{rows,available,reason}`，本机默认未装该扩展 —— 以前这一屏恒空白且没有原因，与「这台系统没有慢查询」在页面上长得一样 |
| GET | /api/monitor/connections | 连接数与状态分布（admin）。出参 `{total,max,usage,by_state,available,reason}`；**读不到时三个数字回 `null` 而不是 0**（`活跃连接 0 / 0` 会被读成「这台系统一个连接都没有」） |
| GET | /api/monitor/db-locks | 锁等待对（admin）。出参 `{rows,available,reason}` |
| GET | /api/shard/config | 分片拓扑配置（admin）：出参含 `registered_connections`（按 `Db::hasConnection` 判定，不是拿惰性 `Db::connection()` 探的）、`allowed_hosts`（本进程可用于分片的主机白名单）、`convergence_seconds`、`column_drift`（开片时逐基表实测的片表缺列，来自 `Shard::drift()`，只读不回填；常态为空数组，非空说明当前账号补不了列，需 `php kode shard:init` 或提权） |
| PUT | /api/shard/config | 更新分片拓扑（admin）：体 `{enabled,db_count,table_shards,db_name,db_hosts?}`。**`db_hosts` 只能填运维在 `.env` 的 `SHARD_DB_HOSTS` 里声明过的主机（`DB_HOST` 恒可用）**，越界或含空格/等号/斜杠等连接串分隔符直接 400 —— 这个字段会让服务端带着应用的库账号密码去连对方并执行 `CREATE DATABASE`。建库的一次性维护连接用完即摘。回 `{connections_registered,connections_reachable,databases_created,tables_ready,restart_required,reload_required,message}`：可达数是逐片 `SELECT 1` 实测的，未收敛到全可达时 `restart_required=true`（路由在 `convergence_seconds` 内全 worker 收敛，新连接需 `php kode reload`） |
| GET | /api/shard/status | 分片状态（admin）：逐连接给 `{connection,registered,ok,tables[]}`，未注册的连接不再被「探通」（旧实现探测未知名字会回落主库配置，报告恒为可用） |
| GET | /api/system/configs | 框架配置只读总览（脱敏，admin） |
| GET | /api/db/tables | 表列表（kode_ 前缀，admin） |
| GET | /api/db/tables/{table} | 表结构（admin） |
| GET | /api/db/tables/{table}/rows | 表数据分页（admin） |
| POST | /api/db/query | 受限 SQL（admin）：单条 SELECT/WITH；文件/大对象/出网/`pg_sleep`/`set_config` 等能力函数在执行前按名拦截（`"pg_read_file"()` 这种引用标识符写法同样算），事务设为 READ ONLY + `statement_timeout` 5s，结果外层封顶 200 行，无论成败都回滚 |
| GET | /api/db/stats | `?connection=` 的总口径（同样适用于 /api/db 的其余只读端点：activity/locks/tables/tables/{table}/constraints/indexes/rows/top-tables）：连接名必须已在 `config/database.php` 注册，未知名直接 400 且绝不建连（未注册名会回落默认配置并把 key 永久留在连接缓存里） |
| GET | /api/sessions | 会话列表（admin）：24h 内**成功登录**记录（`kode_login_logs.status = 1`），用户名取日志行自带的 `username`；出参 `{id,user_id,username,user_status,ip,user_agent,login_at,active,is_current}`，`id`/`user_id` 均为 HashId |
| GET | /api/sessions/online-count | 在线统计（admin）：`total_24h`/`active_1h` 与列表同口径（只数成功登录、排除 `user_id = 0`） |
| DELETE | /api/sessions/{id} | 强制下线（admin）：撤销该登录用户的**全部**令牌（登录日志不携带 jti，多端会一起下线），回 `{user_id,revoked}`；`revoked` 是实际撤销条数，认不出的 uid 直接 500 而不是空转回 0 |
| POST | /api/sessions/clear-all | 清理超过 24h 的登录用户令牌（admin），回 `{users,revoked,failed}`：分别为用户数、令牌撤销条数、失败用户数（明细进系统日志，不回显异常消息） |
| POST | /api/upload | multipart `file` 字段（`?dir=` 子目录；大小/类型/MIME/命名规则走配置；返回含媒体注册 `id`）。MIME 校验按扩展名逐一对表，不给 `text/*` 开兜底（内容为 HTML/PHP/SVG 的 `.txt` 会被拒） |
| GET | /api/files/{path} | 文件下载（鉴权，防穿越，支持子目录）。只有不可执行类型（图片/PDF/`text/plain`）允许内联，其余一律 `application/octet-stream` + `Content-Disposition: attachment` + `nosniff` —— 上传内容可控，嗅探结果不能决定浏览器怎么渲染（存储型 XSS 收口） |
| GET | /api/media?dir=&page&size | 媒体库浏览（文件夹+文件混合，文件附 `media_id`/mime/尺寸） |
| GET | /api/media/dirs | 文件夹树 |
| GET | /api/media/resolve?ids= | 批量解析媒体 ID → URL（远程失败回退本地） |
| POST | /api/media/replace | 原地替换文件内容（同扩展名，admin） |
| POST | /api/media/transfer | 批量转移磁盘（local 即时；远程需 SDK，admin） |
| GET | /api/media/resolve?ids= | 批量解析媒体 ID → URL（远程失败回退本地） |
| POST | /api/media/folders | 新建文件夹（admin） |
| POST | /api/media/rename | 文件/文件夹重命名（admin） |
| POST | /api/media/move | 文件移入指定文件夹（admin） |
| POST | /api/media/replace | 原地替换文件内容（同扩展名，admin） |
| POST | /api/media/transfer | 批量转移磁盘 `{ids, disk_id}`（local 即时；远程需 SDK，admin） |
| DELETE | /api/media/{path} | 删除文件/文件夹（admin，递归） |
| GET | /api/system/backup?tables= | 数据库备份下载（白名单表，>50MB 指引命令行；即下即删，不落备份目录）。`tables` **省略**才是全库；给了却归一为空（如 `,,`）回 400，不出只含注释头的假产物 |
| GET | /api/system/backups | 备份文件列表 + 分档概览（7/30/90 天，只认 `storage/backups`，admin） |
| GET | /api/system/backups/download?file= | 下载单个备份（文件名白名单 + realpath 归属校验，admin） |
| POST | /api/system/backups/run | 按需备份 `{tables?}`（**省略该键才是全库**；带键但为空数组/全空白/写成字符串一律 400，不静默把范围放大成整库），产物写进 `storage/backups`（0600，同秒不互相顶掉）；出参含 `databases` 与 `tables:[{db,table_count}]`，即本次**实际覆盖**的库与表数；失败只回通用文案，原因进日志（admin） |
| POST | /api/system/backups/restore | 从备份恢复 `{file, confirm:"I_UNDERSTAND_THE_RISK"}`（admin）。见下「备份产物与恢复口径」 |
| POST | /api/system/backups/clean | 清理 `{days, confirm?}`；days 越界（非 1..3650）直接 400，不静默夹取；<90 天需 confirm（admin） |
| DELETE | /api/system/backups/{file} | 删除单个备份（admin） |

| GET | /api/notifications | 我的通知（`is_read` 筛选，全员广播 user_id=0 共享） |
| GET | /api/notifications/unread-count | 未读数 |
| POST | /api/notifications/{id}/read | 标为已读 |
| POST | /api/notifications/read-all | 全部已读 |
| DELETE | /api/notifications/clear | 清理**本人**已读（`user_id = 本人 AND is_read = 1`）。广播行 `user_id=0` 全站共享一条记录，早期写法 `IN (0, ?)` 会让任何一次清空已读替全站删掉公告（不可逆、跨账号） |
| POST | /api/notifications/broadcast | 超管广播（admin） |
| GET | /api/storage、/api/storage/drivers、/api/storage/{id}/config、/api/storage/{id}/stats | 存储通道列表 / 可用驱动目录 `[{driver, plugin}]` / 通道配置查看（密钥字段脱敏）/ 容量统计 —— 出参含目标桶与容量，属「存储配置」页数据源，**读需 `configs:view`** |
| POST | /api/storage | 新增通道（admin）；驱动必须已在 `DriverRegistry` 注册 |
| PUT/DELETE | /api/storage/{id} | 改名改配置/删除（默认通道保护，admin） |
| POST | /api/storage/{id}/default | 设为默认（未注册驱动拒绝，admin） |
| GET | /api/syslogs | 系统日志（`level` 筛选，admin） |
| GET | /api/syslogs/{id} | 日志详情含 context（admin） |
| DELETE | /api/syslogs/clean?days= | 清理；days 必填且须在 7..3650，越界 400（admin） |
| GET/POST | /api/dicts | 字典类型列表（读需 `notices:view`，与公告/字典共用的读侧口径）/新增（admin） |
| PUT/DELETE | /api/dicts/{id} | 类型改名/删除（联带删项，admin） |
| GET | /api/dict-items?type= | 字典项（读需 `notices:view`；增改删 admin） |
| POST | /api/dict-items | 新增字典项（admin） |
| PUT | /api/dict-items/{id} | 改字典项（admin） |
| DELETE | /api/dict-items/{id} | 删除字典项（admin） |
| GET | /api/secure/ping | 签名演示（`X-Api-*` 四头，见 ApiSigner） |

### 备份产物与恢复口径（v2）

- 备份范围只有一个来源：**各库现读的 `pg_tables`**（`kode_%` + `migrations`）。库名取连接自己报的
  `current_database()`，不抄配置模板 —— 照模板拼名字时「名字写错也能看起来对」，实际 dump 的却是另一座库。
  两座连接报同一个名字即判重失败（那正是分片连接回落主库配置的现场）。扩片、新插件建表自动进备份。
- 分库时每座分片库各成一节，产物用 psql 的 `\connect` 分段、**每节一个 BEGIN/COMMIT**：
  坏一节只回滚那一节，前面已提交的数据不受牵连。0 张表的分片库整节跳过并在产物头部点名
  （空库会让 `\connect` 在没跑过 `shard:init` 的目标机上当场断掉，不如不写）。
- 恢复走 `app\support\DbRestore`（唯一子进程派发口）+ `-v ON_ERROR_STOP=1`：psql 不加它时
  **中间语句报错照样 exit 0**（实测），旧产物又不带 `--clean`，恢复进已有库时每条 CREATE 都撞「已存在」——
  于是这个接口一直在「一行都没恢复」的情况下回「恢复完成」。现在的口径：第一条错误即中止并回非零码，
  报错原文进日志、尾巴进 500 响应（`恢复失败（exit N）：…`），「进程没起来」与「SQL 报错」在响应上分得开。
- 转储带 `--clean --if-exists --no-owner --no-privileges`：产物不绑定属主，恢复侧用什么账号都行。
- **旧产物（无 v2 头部）不要拿去恢复**：缺 `\connect` 分段与逐节事务，第一条错误就会被
  `ON_ERROR_STOP` 中止并如实回失败 —— 不会再静默「成功」。
- 产物含 PG18 `pg_dump` 的 `\restrict <token>` 行，因此**需要 psql 18+** 执行（低版本 psql 把它当未知命令）。
- 配套断言：`tests/BackupScopeTest.php`（范围/库名/覆盖面自述）、`tests/BackupArtifactTest.php`（产物形状）、
  `tests/BackupScopeGateTest.php`（静态门禁：片名不得写死、恢复命令必须带 `ON_ERROR_STOP`、
  `proc_open` 描述符不得写成路径字符串）、`tests/BackupRestoreE2ETest.php`（真 pg_dump → 真 psql 恢复，
  只在 scratch 库里跑）。

## 公告插件 / C 端

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET/POST | /api/notices | 公告分页/新增（按创建者归属落分片库）。分页读走 `Shard::scan`，信封同级带 `unreadable_shards`（非空时 `total` 只是读到的那些片之和） |
| GET | /api/notices/export | 公告 CSV 导出（正文去标签截断）；缺片写进正文尾巴的 `#` 注释行，理由同 `/api/audits/export` |
| GET/PUT/DELETE | /api/notices/{id} | 详情/改删（三元组 eid 定位，验归属） |
| GET | /c-api/ping | C 端探活 |
| POST | /c-api/auth/register | C 端自助注册（默认关闭，`C_API_REGISTER=true` 开启）：只收 name/email/password，落库固定无角色/无部门，按 IP+邮箱 10 分钟 10 次尝试计 429 |

## 工作流入站触发（WorkflowBridge 插件，唯一「不挂 JWT」的业务入口）

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| POST | /api/workflow/webhook/{name} | 外部系统触发一条已启用的工作流；`{params:{…}}` 或纯参数对象 |

身份**不由 token 承担**，由这条工作流自己的两项凭证承担，且**至少要配一项**
（配置入口：`PUT /api/workflow/definitions/{id}` 的 `webhook_secret` / `webhook_ips`）：

- `webhook_secret`：请求头 `X-Workflow-Signature` 必须等于 `HMAC-SHA256(raw_body, secret)`（十六进制，比较走 `hash_equals`）。
  签名算的是**原始请求体字节**，所以中间任何改 body 的代理都会让它失效；重放同一签名换 body 也过不了。
- `webhook_ips`：逗号分隔的精确 IP 列表（不做网段/前缀匹配）。只配这一项是有意支持的用法——对方是固定出口的回调服务。
- 两项都配了则**都要过**：先判 IP（不在名单直接 403，不再算签名），再验签。

| 情形 | 返回 |
| --- | --- |
| 两项凭证都没配 | 403 `该工作流未配置 webhook 凭证：请先设置签名密钥或 IP 白名单` |
| IP 不在白名单 | 403 `IP 不在白名单: <来源>` |
| 签名缺失/不符 | 401 `签名验证失败` |
| 工作流不存在或未启用 | 404 |

以前"没配密钥"是**敞开**的（签名校验写在 `if ($secret !== '')` 里，留空即跳过），等于给外部留了一个
匿名执行入口——被触发的工作流会写库、会出网、会跑 on_fail 分支。改成默认拒绝之后，
`GET /api/workflow/security-audit` 会同步报出 `open_webhook_trigger`（critical），
作者在被拒之前就能看到原因。判据只写在 `WorkflowBridgeController::webhookAdmission()` 一份，
回归见 `tests/WorkflowWebhookGateTest.php`；匿名路由整体白名单见 `tests/PluginAnonymousRouteGateTest.php`。

### 凭证字段的读写口径（`notify_secret` / `webhook_secret` / `alert_secret`）

`GET /api/workflow/definitions/{id}` 对这三段密钥**只出掩码** `******`（未配置时出空串，两者可区分）；
`PUT` 侧认这个掩码：提交值等于掩码 = 「这条没改」，保留库中原值，**显式留空才是清空**。
成对是必须的——管理端表单是「读详情 → 回填 → 整对象 PUT 回去」，只掩不认的话
用户改一次描述就把三条凭证静默抹掉，而下一轮读仍是掩码，页面上完全看不出凭证已空。
`POST`（新建）没有原值可保留，提交掩码一律 400。掩码字面量与 `PluginConfig::MASK` 同源，不再写第二份星号。
这条此前是 `SELECT *`，等于把签名密钥发给任何持 `workflow:view` 的账号（拿到即可伪造入站触发）。
列名清单在 `WorkflowBridgeController::DEFINITION_COLUMNS`，新增凭证列必须同时进 `SECRET_FIELDS`，
漏了由 `tests/WorkflowSecretMaskTest.php` 从 `information_schema` 现推集合那条挡住（读写两侧各有独立断言）。
