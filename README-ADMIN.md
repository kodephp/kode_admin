# Kode 通用后台（插件式）

基于 `kode/skeleton` + `kode/framework` 的开箱即用管理后台：核心只做鉴权/用户/角色/菜单/插件管理，业务功能全部以插件形式开发。

> 框架 v1.3.7 · DB 以 **pgsql** 为主 · ORM 默认 Doctrine（实体 `app/entities/`，仓储 `app/repositories/`）。

## 跑起来（任意 pgsql 账号均可，无写死角色）

```bash
composer install
# 1. 建库（用你自己的账号；FlyEnv 示例 root 空密码）
psql -h 127.0.0.1 -U root -d postgres -c "CREATE DATABASE kode_app;"
# 2. 配 .env（照 .env.example 填自己的 DB_USERNAME/DB_PASSWORD 等）
cp .env.example .env
php kode init
# 3. 建表 + 初始数据
php kode migrate
php kode db:seed --class=AdminSeeder
# 4. 启动
php kode start
```

默认超管 `admin / ChangeMe123!`（首次登录后立即修改）。

## 接口约定（统一信封，短字段）

成功 `{code:HTTP状态(默认200), msg:"ok", dur:"0.0003s", data?:…}`，
失败 `{code:HTTP状态, msg, dur, errors?}`，分页同级加 `total/page/size`（`data` 为当页数组）。
鉴权：`Authorization: Bearer <token>`（登录签发；业务声明经 AES 二次加密，见下）。

| 方法 | 路径 | 说明 | 权限 |
| --- | --- | --- | --- |
| POST | /api/auth/login | 登录（username+password，防爆破 10 次/10 分钟） | 公开 |
| GET | /api/auth/me | 当前用户 | 登录 |
| POST | /api/auth/password | 改自己密码（需原密码） | 登录 |
| POST | /api/auth/refresh | 滑动续期（旧 token 即时作废，需 JWT redis 共享黑名单） | 登录 |
| GET | /api/users?page&size | 用户分页 | 登录 |
| POST | /api/users | 新增用户 | admin |
| PUT | /api/users/{id} | 改名 | admin |
| DELETE | /api/users/{id} | 删除（超管除外） | admin |
| POST | /api/users/{id}/roles | 分配角色 | admin |
| POST | /api/users/{id}/toggle | 启用/禁用切换（超管除外，禁用后拒绝登录） | admin |
| GET/POST | /api/roles | 角色列表/新增 | 登录/admin |
| DELETE | /api/roles/{slug} | 删除（admin 除外） | admin |
| GET | /api/menus | 菜单聚合（核心 + 各插件 `menus()`） | 登录 |
| GET | /api/plugins | 已加载插件 | 登录 |
| GET | /api/audits?page&size | 操作审计日志 | admin |
| DELETE | /api/audits/clean?days= | 审计保留期清理（7~3650 天，默认 90；另有每周 cron） | admin |
| GET | /api/system/backup?tables= | 数据库备份下载（白名单表，pg_dump） | admin |

> 备份恢复验证：下载包可直接 `psql -f` 恢复（已实测空库恢复零错误，数据完整）；
> JWT 黑名单走 redis（多 worker 共享，吊销/续期跨进程一致，`config/jwt.php`）。
| GET | /api/dashboard/summary | 仪表盘汇总 | 登录 |
| POST | /api/upload | 文件上传 | 登录 |
| GET | /api/files/{path} | 文件下载 | 登录 |
| GET/POST/PUT/DELETE | /api/depts… | 部门树/新增/改名/删除 | 登录/admin |
| GET/POST | /api/configs… | 配置中心列表/读取/写入 | 登录/admin |
| PUT | /api/auth/profile | 改自己资料 | 登录 |
| GET | /api/dashboard/summary | 仪表盘汇总（用户/角色/公告/今日审计/插件/系统） | 登录 |
| POST | /api/upload | 文件上传（multipart file 字段，≤10MB 白名单类型） | 登录 |
| GET | /api/files/{path} | 文件下载（鉴权，防目录穿越） | 登录 |
| GET | /api/secure/ping | 签名演示（`X-Api-*` 四头，见下） | 登录+签名 |

## JWT 二次加密

令牌业务声明（uid/username/roles/perms/custom）经 AES-256-GCM 二次加密后才装入
`enc` 字段，token 明文段仅留 exp/iat/jti/platform 等验证字段——base64 解开也看不到业务数据，
篡改密文解密失败直接 401。密钥由 `JWT_SECRET` 经 HKDF 派生，`app/auth/EncryptedJwtGuard` 实现，
已替换 `AuthGuard`/`jwt` 绑定，对业务透明。

## API 签名（淘宝式防篡改 + 防重放）

重要对外接口（如支付回调、开放 API）挂 `ApiSignMiddleware`（见 `/api/secure/ping` 演示）：
客户端用 `ApiSigner::sign($secret, $method, $path, $query, $body, $ts, $nonce)` 算 HMAC，
带 `X-Api-Key/Timestamp/Nonce/Sign` 四头；服务端校验时间窗 ±300s + nonce 防重放
（file/redis 缓存跨 worker，单机内存退化）。密钥放 `API_SIGN_SECRET`。

## 脱敏展示

列表页 PII 用 `Mask::phone/email/idCard/name` 掩码（如 `138****8000`），
详情接口按权限返回原文。不要为展示而加密整个 body。

## 插件开发（以公告插件为例）

> 完整可运行示例、API 参考与自检清单见 `docs/PLUGIN-DEV.md`。

1. 新建 `plugins/<Name>Plugin.php` 实现 `PluginInterface`（文件名=类名，`*Plugin.php` 结尾）；
   控制器/服务放插件私有目录 `plugins/<name>/`（namespace `Kode\Plugins\<name>`）；
2. `register()` 里 `$manager->addRoute(...)->middleware(new AuthMiddleware())`，
   路由前缀恒为 `/api/<name>/`（`name()` 返回值）；建表在 `install()` 内（`CREATE TABLE IF NOT EXISTS`）；
   可选实现 `menus(): array` 返回 `[title, path, icon, auth_code?, parent?]`，`/api/menus` 自动收录；
3. `config/plugins.php` 登记类名，`kode reload` 生效；`route:list --source=plugin:<name>` 查看。

示例见 `plugins/NoticePlugin.php`（公告 CRUD：`GET/POST /api/notices`、`PUT/DELETE /api/notices/{id}`）。

## 插件市场（安装/卸载/升级/暂停）

- `GET /api/plugins` 列表（含代码版本/已装版本/状态/菜单/所属表）；
  `POST /api/plugins/{name}/{install,uninstall,pause,resume,upgrade}`（admin）。
- 启停经运行时门禁**即时生效**（暂停/卸载后路由直接 404），无需重启；
  卸载默认保留数据，`purge=1` 删表需 `confirm=1`，响应自带 pg_dump 备份命令。
- 插件约定：`name()` 恒等于 URL 前缀；表名前缀 `plug_<name>_`；
  可选 `version()/menus()/tables()/install()/uninstall()/upgrade()`。

## 亿级扩展（分库分表 + 分布式事务）

- 开关：`SHARD_ENABLED=true`（默认关闭零回归）+ `php kode shard:init` 建分片库表；
  公告按创建者归属落片（`kode_app_00/01` × `notices_00/01`），对外 eid 为三元组，`locate()` 解码直达；
  读写分离 `DB_READ_WRITE_SPLIT=true`；热数据走 redis `HotCache`。
- 跨片写用 `Saga::run()`（do/undo，失败逆序补偿，补偿失败记 trail 人工介入），do/undo 须幂等；
  跨片错误自动带 `[shard:库.表]` 前缀，结合 trace_id 定位。
- 详见 AGENTS.md §3 亿级扩展段。

## 前端对接

- **管理后台 Web**：`../admin-web`（vben-antd 基座，`apps/web-antd`，
  `accessMode=backend` + Kode 信封/登录/菜单映射，已接登录/动态菜单/CRUD，直连本后台）。
- **C 端多端**（App/小程序/H5）：uni-app x。
- 后端只暴露 JSON，不耦合任何前端构建。
