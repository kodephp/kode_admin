# API 统一文档（由 `php kode route:list` 生成，手工补齐出入参）

> 基地址如 `http://127.0.0.1:9527`。无特别说明均需鉴权头
> `Authorization: Bearer <token>`；统一信封见 AGENTS.md §3
> （成功 `{code,msg,dur,data}`，分页 +`total/page/size`，失败 `{code,msg,dur,errors?}`）。
> 对外 ID 均为 HashId 字符串（`uid` 为 `u_` 前缀串），见 AGENTS.md §4。

## 探针（公开）

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | /ping、/health、/health/live、/health/ready | 探活/存活/就绪 |

## 鉴权

| 方法 | 路径 | 入参 | 出参 data |
| --- | --- | --- | --- |
| POST | /api/auth/login | `{username, password}` | `{token, user, roles}` |
| GET | /api/auth/me | — | `{uid, username, avatar, roles, perms}` |
| GET | /api/auth/login-logs | 我的登录日志 |
| GET | /api/auth/login-logs/all?username= | 全员登录日志（admin） |
| DELETE | /api/auth/login-logs/clean?days= | 清理我的登录日志（admin） |
| POST | /api/auth/password | `{old_password, new_password}`（长度走 `auth.password_min_length`） | `{updated}` |
| PUT | /api/auth/profile | `{name, email, avatar?}` | 用户对象 |
| POST | /api/auth/refresh | 旧 token（头） | `{token, exp}`（旧 token 作废） |

## 用户（列表/详情登录可看，其余 admin）

| 方法 | 路径 | 入参 | 出参 data |
| --- | --- | --- | --- |
| GET | /api/users | `page/size/search/dept_id/status/start_time/end_time/id/remark`（email 脱敏，附 `roles`/`dept_name`，id 为 HashId） | `{data,total,page,size}` |
| POST | /api/users | `{name, email, password, dept_id?, remark?}`（密码长度走配置） | `{id}` 201 |
| PUT | /api/users/{id} | `{name, dept_id?, remark?}` | `{id}` |
| DELETE | /api/users/{id} | —（超管除外） | `{deleted}` |
| POST | /api/users/{id}/roles | `{roles:[]}` | `{id, roles}` |
| POST | /api/users/{id}/toggle | —（超管除外） | `{id, status}` |
| POST | /api/users/{id}/password | `{password}`（长度走配置） | `{id}` |
| GET | /api/users/export | — | CSV 下载 |
| POST | /api/users/batch-delete | 批量删除 `{ids}`（跳过超管，admin） |
| POST | /api/users/batch-roles | 批量分配角色 `{ids, roles}`（admin） |
| POST | /api/users/import | CSV 导入（列 name/email/password/remark?，2000 行上限，逐行报告，admin） |

## 角色 / 菜单 / 部门 / 配置

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET/POST | /api/roles | 列表（含 perms/status/remark/created，`search` 模糊）/新增（admin） |
| PUT/DELETE | /api/roles/{slug} | 改名/权限/状态/备注/删除（admin 角色除外，admin） |
| GET | /api/menus | 嵌套树（含一级目录，`?buttons=1` 附按钮；按身份过滤 auth_code+status，非超管禁用的不可见） |
| POST/PUT | /api/menus、/api/menus/{id} | 新增/改 `{title,path,icon,plugin,sort,parent_id?,auth_code?,status?,name?,component?,meta?}`（admin，防环+深度保护，写清 redis 缓存） |
| DELETE | /api/menus/{id} | 删除（按钮联带删，子菜单上移顶级，admin） |
| GET/POST | /api/menus/{id}/buttons | 按钮列表/新增 `{code,title,sort}`（admin，code 全局唯一） |
| PUT/DELETE | /api/menu-buttons/{id} | 按钮改名/删除（admin，code 不可改） |
| GET | /api/perms/catalog | 权限目录（按插件分组：菜单码+按钮码，供角色勾选） |
| GET/POST/PUT/DELETE | /api/depts… | 部门树（含 status/remark/created）/新增/改名/删除（有子部门或有人时 409，admin 写） |
| GET/POST | /api/configs、/api/configs/{key} | 配置列表（含 ctype/ctitle/cgroup/sort）/读取（含敏感掩码）/写入（admin，写失效缓存） |
| POST | /api/configs/reset | 恢复出厂默认值（只补缺失键，admin） |
| DELETE | /api/configs/{key} | 删除配置（admin） |

## 插件市场（admin）

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | /api/plugins | 列表（版本/状态/菜单/所属表/能力清单/契约审计/**依赖图**） |
| GET | /api/plugins/{name}/contract | 单个插件完整能力契约 + 零漂移审计明细 + **依赖概览** |
| POST | /api/plugins/{name}/{install,pause,resume,upgrade} | 安装/暂停/恢复/升级；**install/resume/upgrade 依赖未就绪拒绝**；**pause 级联暂停下游** |
| POST | /api/plugins/{name}/uninstall | `{confirm:1, purge?}` 二次确认；purge 时删表 + **清配置** |
| GET | /api/plugins/{name}/package | 打包下载 zip（含 plugin.json 清单，随包携带 contract） |
| POST | /api/plugins/install-remote | `{url, sha256, name}` 远程安装（sha256 + 清单三道门禁 + 20MB 上限，装完 reload） |
| GET | /api/plugins/{name}/config | schema 驱动的插件配置表单数据源（secret 掩码回显） |
| PUT | /api/plugins/{name}/config | `{values}` 全量保存；校验失败返回 `400 + errors` |

**远程安装清单三道门禁**（`PluginPackager::verifyManifest()`，sha256 之后、落盘之前）：
① 包内必须有 `plugin.json` 且声明 `name`；② 清单 `name` 必须等于请求的 `name`
（防「请求 foo、落盘 BarPlugin.php」导致登记状态与实体错位）；③ 清单 `kode` 下限必须被
当前框架版本满足（用 `PluginDependency::satisfies()` 判定）。校验通过后**状态与版本同时登记**，
响应额外返回 `{version, kode}`；未声明 `kode` 的旧市场包视为不限框架，但 `name` 仍必须一致。

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

## 审计 / 系统 / 上传

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | /api/audits?page&size&username&path&method&status&start_time&end_time | 审计日志（跨分片归并；status 支持精确码或 2/4/5 百位类） |
| GET | /api/audits/export | 审计 CSV 导出（admin，最多 5000） |
| GET | /api/audits/stats | 近 7 天审计量（跨分片聚合） |
| DELETE | /api/audits/clean?days= | 保留期清理（7~3650，默认 90） |
| GET | /api/dashboard/summary | 用户/角色/公告/今日审计/插件/系统信息 |
| GET | /api/audits/mine?page&size | 我的操作记录（按 token 用户名过滤） |
| GET | /api/schedules | 定时任务注册表（#[Cron]，只读） |
| POST | /api/schedules/{name}/run | 手动触发一次（admin） |
| GET | /api/monitor | 服务器监控快照（负载/CPU/内存/磁盘/应用） |
| GET | /api/monitor/shards | 分片总览（拓扑+各分片表行数，admin） |
| GET | /api/system/configs | 框架配置只读总览（脱敏，admin） |
| GET | /api/db/tables | 表列表（kode_ 前缀，admin） |
| GET | /api/db/tables/{table} | 表结构（admin） |
| GET | /api/db/tables/{table}/rows | 表数据分页（admin） |
| POST | /api/db/query | 受限 SQL（仅 SELECT/WITH，禁多语句，admin） |
| POST | /api/upload | multipart `file` 字段（`?dir=` 子目录；大小/类型/MIME/命名规则走配置；返回含媒体注册 `id`） |
| GET | /api/files/{path} | 文件下载（鉴权，防穿越，支持子目录） |
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
| GET | /api/system/backup?tables= | 数据库备份下载（白名单表，>50MB 指引命令行） |
| GET | /api/notifications | 我的通知（`is_read` 筛选，全员广播 user_id=0 共享） |
| GET | /api/notifications/unread-count | 未读数 |
| POST | /api/notifications/{id}/read | 标为已读 |
| POST | /api/notifications/read-all | 全部已读 |
| DELETE | /api/notifications/clear | 清理已读 |
| POST | /api/notifications/broadcast | 超管广播（admin） |
| GET | /api/storage | 存储通道列表 |
| GET | /api/storage/drivers | 可用驱动目录 `[{driver, plugin}]`（只读，auth） |
| POST | /api/storage | 新增通道（admin）；驱动必须已在 `DriverRegistry` 注册 |
| PUT/DELETE | /api/storage/{id} | 改名改配置/删除（默认通道保护，admin） |
| POST | /api/storage/{id}/default | 设为默认（未注册驱动拒绝，admin） |
| GET | /api/syslogs | 系统日志（`level` 筛选，admin） |
| GET | /api/syslogs/{id} | 日志详情含 context（admin） |
| DELETE | /api/syslogs/clean?days= | 清理（admin） |
| GET/POST | /api/dicts | 字典类型列表/新增（admin） |
| PUT/DELETE | /api/dicts/{id} | 类型改名/删除（联带删项，admin） |
| GET | /api/dict-items?type= | 字典项（admin 取数，登录可见读） |
| POST/PUT/DELETE | /api/dict-items… | 字典项增改删（admin） |
| GET | /api/secure/ping | 签名演示（`X-Api-*` 四头，见 ApiSigner） |

## 公告插件 / C 端

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET/POST | /api/notices | 公告分页/新增（按创建者归属落分片库） |
| GET | /api/notices/export | 公告 CSV 导出（正文去标签截断） |
| GET/PUT/DELETE | /api/notices/{id} | 详情/改删（三元组 eid 定位，验归属） |
| GET | /c-api/ping | C 端探活 |
| POST | /c-api/auth/register | C 端自助注册（默认关闭，`C_API_REGISTER=true` 开启） |
