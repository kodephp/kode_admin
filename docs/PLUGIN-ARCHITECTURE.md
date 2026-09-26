# 通用后台架构设计：核心 vs 插件体系

> 本文档定义 Kode 通用后台的**职责边界**与**插件分类体系**，指导开发者判断"该做在核心还是该做成插件"。

---

## 1. 核心后台职责（不拆插件）

通用后台只做**基础架构层**的工作，以下模块永久内嵌于核心，不拆为插件：

| 模块 | 说明 | 路径 |
| --- | --- | --- |
| 系统配置 | `.env` 不可变配置 + 运行时只读展示（`/api/system/configs`） | `app/admin/controllers/SystemController.php` |
| 配置中心 | 运行时可变 KV 配置（`/api/configs`，DB 存储，支持分组/类型/写入） | `app/admin/controllers/ConfigController.php` |
| 角色管理 | RBAC 角色 CRUD + 权限码授权（`/api/roles`） | `app/admin/controllers/RoleController.php` |
| 菜单管理 | 动态路由 + 按钮级权限码（`/api/menus`） | `app/admin/controllers/MenuController.php` |
| 用户管理 | 用户 CRUD + 角色分配 + 部门关联 | `app/admin/controllers/UserController.php` |
| 部门管理 | 树形组织结构 | `app/admin/controllers/DeptController.php` |
| 权限目录 | 授予面 = 菜单 auth_code ∪ 按钮码 ∪ 插件 `permissions()` 声明的操作码，唯一派生口 `app/support/PermCatalog`（`/api/perms/catalog` 与契约审计同源读它） | `app/support/PermCatalog.php` |
| 插件管理 | 安装/卸载/升级/暂停 + 市场（`/api/plugins`） | `app/admin/controllers/PluginController.php` |
| 操作审计 | 谁/方法/路径/状态/耗时/IP 元数据（禁止记请求体） | `app/admin/controllers/AuditController.php` |
| 系统日志 | 框架级日志查看/清理（`/api/syslogs`） | `app/admin/controllers/SyslogController.php` |
| 定时任务 | 任务调度 + 手动触发（`/api/schedules`） | `app/admin/controllers/ScheduleController.php` |
| 服务器监控 | 资源/分片拓扑（`/api/monitor`） | `app/admin/controllers/MonitorController.php` |
| 数据库管理 | 表/字段/行查看 + SQL 查询（`/api/db/*`） | `app/admin/controllers/DbController.php` |
| 媒体库 | 全局文件/图片/视频管理 + URL 解析（`/api/media/*`） | `app/admin/controllers/FileController.php` |
| 文件上传 | 鉴权上传 + 下载（`/api/upload`） | `app/admin/controllers/UploadController.php` |
| 通知中心 | 站内通知 + 广播（`/api/notifications/*`） | `app/admin/controllers/NotifyController.php` |
| 仪表盘 | 汇总数据（`/api/dashboard/summary`） | `app/admin/controllers/DashboardController.php` |

**判断标准**：如果"所有业务都需要它"或"它涉及框架级机制"（鉴权/路由/插件加载/审计），就做在核心。

---

## 2. 插件分类体系

```
┌─────────────────────────────────────────────────┐
│                  插件体系                         │
├──────────────────────┬──────────────────────────┤
│     组件类插件        │      业务类插件            │
│  (Component Plugin)  │  (Business Plugin)       │
├──────────────────────┼──────────────────────────┤
│ • 钱包组件            │ • CMS（企业官网）          │
│ • 对象存储            │ • 商城                    │
│ • 物流通信            │ • 博客                    │
│ • 打印                │ • 论坛                    │
│ • 全局配色            │ • SaaS 基础               │
│ • 短信/邮件通道       │ • PaaS / IaaS 管理        │
│ • 发票/票据           │ • 在线教育                 │
│ • 地图/定位           │ • 招聘/求职               │
│ • 支付网关            │ • 房产/二手车             │
│ • AI 对话/知识库      │                           │
└──────────────────────┴──────────────────────────┘
```

### 2.1 组件类插件（Component）

- **定位**：可复用的"小功能块"，多个业务可共享调用
- **特征**：无完整业务流程，提供 API + 可选 UI 组件 + 数据表
- **约束**：不得包含路由页面（除非是配置页），以 API + 事件总线对外
- **示例**：

| 组件 | 提供的能力 | 典型调用方 |
| --- | --- | --- |
| 钱包组件 | 充值/提现/冻结/流水查询/多钱包类型 | 商城、在线教育、招聘 |
| 对象存储 | S3 兼容多平台对接（阿里/腾讯/七牛/MinIO） | 所有需要文件的业务 |
| 物流通信 | 快递下单/轨迹查询/电子面单 | 商城、电商 |
| 支付网关 | 微信/支付宝/银联聚合（封装 kode/pays） | 商城、在线教育 |
| 短信/邮件 | 通道管理/模板/发送/状态回执 | 所有需要通知的业务 |
| 打印 | 标签/发票/物流单打印模板 | 商城、ERP |
| 地图/定位 | 高德/百度地图集成 + 轨迹 | 物流、房产 |
| AI 对话 | LLM 接入/知识库/RAG | CMS、客服、搜索 |

### 2.2 业务类插件（Business）

- **定位**：完整的业务闭环（数据模型 + 业务规则 + UI 页面 + 权限）
- **特征**：有独立的用户旅程，可组合多个组件插件
- **约束**：表名沿用全局 `kode_` 前缀并带插件自己的名字段（如 `kode_cms_articles`；跨插件、跨核心都不得撞名——`tables()` 同时是 `uninstall(purge)` 的 DROP 清单），路由前缀 `/api/<name>/`，菜单通过 `menus()` 声明
- **示例**：

| 业务 | 使用的组件 | 核心数据表 |
| --- | --- | --- |
| CMS | 媒体库、AI 对话 | 文章/分类/标签/评论 |
| 商城 | 钱包、支付网关、物流、对象存储 | 商品/订单/购物车/售后 |
| 博客 | 媒体库 | 文章/分类/标签/评论 |
| 论坛 | 媒体库 | 帖子/回复/板块/举报 |
| 招聘 | 钱包、短信 | 职位/简历/投递/面试 |

---

## 3. 媒体库设计（全局文件中枢）

### 3.1 核心原则

**所有文件（logo / 图片 / 头像 / 视频 / 附件）统一走媒体库，业务表只存媒体 ID（int），不存完整 URL。**

```
业务表                          媒体库
┌──────────────┐    media_id    ┌──────────────────────┐
│ kode_articles│───────────────→│ kode_media            │
│ id: 1        │   (int)        │ id: 42               │
│ cover_id: 42 │               │ path: 202609/xxx.jpg  │
│ banner_id: 7 │               │ size: 204800           │
└──────────────┘               │ mime: image/jpeg       │
                               │ uploaded_by: 1         │
                               └──────────────────────┘
                                          │
                                          ▼
                              前端通过 mediaUrl(id) 获取 URL
                              后端通过 MediaService::resolveUrl(id) 转换
```

### 3.2 存储结构

```
storage/media/
├── avatars/          # 头像（可选单独目录）
├── 2026/09/          # 按月分目录
│   ├── abc123.jpg
│   └── def456.mp4
└── tmp/              # 上传临时区（定时清理）
```

### 3.3 数据模型

```sql
CREATE TABLE kode_media (
    id          BIGSERIAL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,       -- 原始文件名
    path        VARCHAR(512) NOT NULL,       -- 相对路径（storage/media/202609/xxx）
    size        BIGINT NOT NULL DEFAULT 0,   -- 字节数
    mime        VARCHAR(128) NOT NULL DEFAULT '',
    ext         VARCHAR(16) NOT NULL DEFAULT '',
    disk_id     BIGINT DEFAULT NULL,         -- 关联对象存储（可为空=本地）
    disk_path   VARCHAR(512) DEFAULT NULL,   -- 对象存储 Key（可为空）
    folder      VARCHAR(255) DEFAULT '',     -- 逻辑文件夹（如 avatars/ 或空）
    created_by  BIGINT NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_media_folder ON kode_media(folder);
CREATE INDEX idx_media_created_by ON kode_media(created_by);
```

### 3.4 后端 API

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/media?folder=&type=&page=&size=` | 分页列表（支持按文件夹/类型筛选） |
| POST | `/api/media/folders` | 新建文件夹 `{dir, name}` |
| POST | `/api/media/rename` | 重命名 `{from, name}` |
| POST | `/api/media/move` | 移动 `{from, to_dir}` |
| POST | `/api/media/replace` | 替换文件 `{path, file}` |
| POST | `/api/media/transfer` | 批量转移到对象存储 `{ids, disk_id}` |
| GET | `/api/media/resolve?ids=1,2,3` | 批量解析 ID→URL `{1:"...", 2:"..."}` |
| GET | `/api/media/dirs` | 文件夹树 |
| DELETE | `/api/media/{path}` | 删除文件 |

### 3.5 前端组件

提供统一的**媒体选择器**组件（`<KodeMediaPicker>`），所有表单的"图片/视频/文件"字段统一使用：

```vue
<KodeMediaPicker
  v-model:open="pickerVisible"
  accept="image"          <!-- image / video / file / all（默认 image） -->
  @select="(ref: string) => (form.coverId = ref)"  <!-- 回调 media:ID 字符串 -->
/>
```

组件内部：
1. 弹窗展示媒体库网格（缩略图 + 文件名 + 大小 + 类型标签）
2. 左侧 `DirectoryTree` 文件夹导航 + 搜索 + 直接上传到当前目录
3. emit `media:ID` 字符串（非 URL），业务侧存储该值
4. 仅已注册媒体（`media_id > 0`）可被选中

### 3.6 URL 转换（共用方法）

```php
// 后端：共享方法（StorageManager，无需注入）
$url = StorageManager::url($mediaId);    // → /api/files/202609/xxx.jpg 或远程 URL
$url = StorageManager::url('media:42');  // 同样支持 media:ID 格式
```

```ts
// 前端：共用工具（src/utils/media.ts，带缓存 + 并发去重）
import { mediaUrl, resolveMediaUrls } from '#/utils/media';

const url = await mediaUrl('media:42');           // → /api/files/202609/xxx.jpg
const urls = await resolveMediaUrls(['media:1', 'media:2']);  // 批量解析

// 兼容旧数据：直链原样返回
await mediaUrl('/api/files/202609/xxx.jpg');      // → 原样返回
await mediaUrl('https://cdn.example.com/a.jpg');  // → 原样返回
```

**存储约定**：业务表字段一律存 `media:ID` 字符串（非 int），展示时经上述方法解析。

---

## 4. 富文本编辑器选型

### 4.1 对比

| 编辑器 | 优点 | 缺点 | 适用场景 |
| --- | --- | --- | --- |
| **TinyMCE** | 功能最全、企业级、插件生态成熟、支持协同编辑 | 开源版功能受限（高级功能需商业授权）、体积大（~1MB） | 企业 CMS、合规要求高 |
| **TipTap** | 基于 ProseMirror、Vue3 原生支持、组合式 API、树 shaking 好 | 生态不如 TinyMCE、高级功能需付费扩展 | Vue3 项目、自定义需求多 |
| **wangEditor** | 国产、中文文档友好、轻量（~300KB）、免费 | 功能较基础、社区较小、TypeScript 支持弱 | 国内项目、功能需求简单 |
| **CKEditor 5** | 模块化、免费开源、Vue3 官方集成 | 性能一般、部分高级功能收费 | 通用场景 |

### 4.2 推荐方案

**默认使用 TipTap**，原因：
1. Vue 3 原生 Composition API 支持（`useEditor` composable）
2. Tree-shaking 优秀，按需引入插件，包体积可控
3. 插件生态丰富（@tiptap/extension-*）：表格、代码高亮、图片上传、链接、评论等
4. 与媒体库集成：自定义图片上传 handler → 调用 `/api/media/resolve`
5. 与 Tailwind/CSS 变量兼容性好

**保留 TinyMCE 作为备选**：当需要协同编辑、Word 兼容导出、或企业合规要求时切换。

### 4.3 集成要点

```ts
// @tiptap/extension-image 的自定义上传
import Image from '@tiptap/extension-image'

Image.configure({
  allowBase64: false,
  HTMLAttributes: { class: 'rich-editor-img' },
})

// 自定义上传图片：上传后解析 media:ID → URL 再插入
import { mediaUrl } from '#/utils/media'

function createUploadHandler() {
  return async ({ file }) => {
    const [r] = await kodeApi.upload([file], '/uploads/editor');
    const url = await mediaUrl(`media:${r.id}`);
    return { url, mediaId: r.id };
  };
}
```

> 富文本 HTML 中 `<img src>` 存已解析的 URL（HTML 需真实地址才能渲染）；
> 若后续需迁移磁盘，可通过 `media:ID` 关联关系批量重新解析。

---

## 5. 系统配置 vs 配置中心

### 5.1 职责区分

| | 系统配置（System Configs） | 配置中心（Config Center） |
|---|---|---|
| **数据源** | `.env` 文件（框架级） | 数据库 `kode_configs` 表 |
| **可否修改** | ❌ 只读（改 .env 需重启） | ✅ 运行时读写 |
| **接口** | `GET /api/system/configs` | `GET/POST/DELETE /api/configs` |
| **用途** | 展示框架运行参数（调试/排查） | 业务级运行时配置（管理员可改） |
| **示例** | APP_DEBUG、DB_HOST、JWT_SECRET | 站点名称、页脚版权、注册开关、SEO |
| **缓存** | 无（每次读 .env） | Redis `HotCache`（写必失效） |

### 5.2 当前问题

**问题**：系统配置页和配置中心页数据"看起来互通"，容易混淆。

**修正**：
- 系统配置页 = **只读展示** `.env` 中的框架参数（标注"修改需改 .env 并重启"）
- 配置中心页 = **读写** 业务级 KV 配置（管理员可直接在后台改）
- 前端明确区分两个页面的标题和说明，不再混用数据

### 5.3 配置中心数据模型

```sql
CREATE TABLE kode_configs (
    id          BIGSERIAL PRIMARY KEY,
    group       VARCHAR(64) NOT NULL DEFAULT 'general',  -- 分组
    key         VARCHAR(128) NOT NULL UNIQUE,            -- 配置键
    type        VARCHAR(32) NOT NULL DEFAULT 'string',   -- string/number/boolean/json
    value       TEXT NOT NULL DEFAULT '',                 -- 配置值
    title       VARCHAR(128) NOT NULL DEFAULT '',        -- 标题
    remark      VARCHAR(255) DEFAULT '',                 -- 备注
    sort        INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

---

## 6. 插件市场（Plugin Marketplace）

### 6.1 定位

**插件市场是框架内建功能，不是后台配置项。** 框架启动时自动加载已安装的插件，市场页提供"浏览/安装/升级" UI。

### 6.2 当前实现

| 功能 | 接口 | 说明 |
| --- | --- | --- |
| 已安装列表 | `GET /api/plugins` | 含代码版本/已装版本/状态/菜单/表 |
| 远程安装 | `POST /api/plugins/install-remote` | `{name, url, sha256}`，强制校验 |
| 暂停/恢复 | `POST /api/plugins/{name}/pause` | 运行时门禁即时生效 |
| 卸载 | `POST /api/plugins/{name}/uninstall` | 默认保留数据，`purge=1` 删表 |
| 升级 | `POST /api/plugins/{name}/upgrade` | 替换包 + reload |
| 打包下载 | `GET /api/plugins/{name}/package` | 供其他实例远程安装 |

### 6.3 协议

详见 `docs/MARKETPLACE-PROTOCOL.md`。核心：
- 包格式：zip（含 `composer.json` + `PluginInterface` 实现类）
- 校验：sha256 强制比对，20MB 上限
- 安全：落盘不覆盖，装完 reload

---

## 7. 存储配置（对象存储）

### 7.1 定位

**对象存储应作为"组件类插件"，而非后台核心配置。** 核心只提供本地存储，对象存储通过插件扩展。

### 7.2 当前实现

盘的登记与 CRUD 是**核心职责**，表是 `kode_storage_configs`（`database/sql/schema.sql`，
迁移 `2026_09_08_000028` 建表并 seed 一条 `local` 默认盘），管理页为菜单「存储配置」`/storage`，
接口为 `StorageController`（`/api/storage*`）。`store()` 只接受 `DriverRegistry::allowed()`
里的驱动键——即**已启用插件 `drivers()` 声明的键**，核心自己不认驱动名。

历史文档在这里写过「本地/USB/NAS/LAN-NAS 四类」：代码里一张 USB/NAS 表、一个驱动键都没有，
别照抄（§3.6 的 URL 转换也只分「local 盘」与「远程盘」两类）。

### 7.3 对象存储插件分工

已落地形态是**两个插件分工**（不存在单个 `StoragePlugin.php`，插件也不带 `config/` 与
`migrations/` 目录——配置走 `configSchema()`，建表走 `install()`）：

```
plugins/StorageDriverPlugin.php        # 契约示范 + local 兜底监听者
└── 监听 storage_driver_resolve，driver=local 直接回执 pushed=true（本地盘无需上传）

plugins/FlysystemStoragePlugin.php     # 纯组件：远程驱动实现（无自己的表）
└── 同一事件的另一个监听者：用 league/flysystem-aws-s3-v3 统一实现
    s3 / oss / cos / minio（endpoint 指向各家服务；未实现的驱动如实回 pushed=false）
```

两个插件都**不建配置表**：`drivers()` 只申报键名（`DriverRegistry` 据此放行 `store()`），
真正的桶/密钥存在核心的 `kode_storage_configs.config`（JSON 一列，`ak`/`sk` 标了 secret，
出参恒脱敏，见 `tests/StorageConfigMaskTest.php`）。

> `StorageDriverPlugin::tables()` 里那张 `kode_storage_drivers`（driver/endpoint/bucket/prefix/ak/sk/enabled）
> 目前**全仓无一处读写**——它是契约示范留下的骨架表，不是运行时配置源。要么接上用它，
> 要么删掉声明；挂着一张带 ak/sk 列却没人管的表，只多一个凭证落点。

**驱动契约不是 PHP 接口，而是一个事件**（历史上这里画过一个 `StorageInterface`，代码里从未存在）：

```php
// 核心 StorageManager::pushRemote() 派发（常量 StorageManager::REMOTE_RESOLVE_EVENT）
Event::dispatch('storage_driver_resolve', [
    'driver' => 's3',            // kode_storage_configs.driver
    'path'   => '/abs/local/file.jpg',
    'key'    => 'media/2026/09/file.jpg',
    'disk'   => ['id' => 7, 'driver' => 's3', 'endpoint' => …, 'bucket' => …, 'ak' => …, 'sk' => …, 'prefix' => …],
    'id'     => 42,              // 媒体 id（与 disk.id 不同义）
]);
// 监听者用 $event->set() 回报：pushed(bool) / url(string) / msg(string)
// 读入参必须用 $event->get()：框架 Kode\Event\Event 没有 __get，写 $event->driver 恒得 null。
```

**四个入参的口径由核心单方面保证，驱动一律不许自己算**：

| 键 | 形状 | 由谁保证 |
| --- | --- | --- |
| `path` | **绝对**本地路径，可直接 `is_file`/`read` | `StorageManager::localPath()`——守护进程 CWD 实测是 `/`，传 `uploads/…` 相对串等于让驱动按 CWD 猜，永远「源文件不存在」 |
| `key` | **完整**远程对象键，`prefix` 已含且只出现一次 | `StorageManager::remoteObjectKey()`——写侧与展示侧同源，否则「transfer 成功、前端点开的 URL 指向从不存在过的对象」 |
| `disk` | **平铺**形态（`flattenDisk()` 摊掉嵌套 `config`） | 唯一派发点进门先摊平，调用方（含 `StorageController::test()`）一律传 `disk()` 原样 |
| `id` | 媒体 id | 与 `disk.id`（盘 id）不同名不同义 |

驱动侧只剩一句「把这个文件写到这个键」，回报 url 用 `StorageManager::remoteObjectUrl($disk, $key)`。
形状与同源性的回归见 `tests/StorageRemotePushTest.php`（+ `tests/StorageDriverPayloadTest.php` 守平铺形态）。

> 事件契约里**没有删除动词**：`StorageController::test()` 的连通性探针会往桶里落一个
> `kode-probe/{临时文件名}` 且不清理。要么在契约里补 `storage_driver_delete`，要么由桶生命周期规则兜掉
> 这个前缀——别指望应用侧能删（它没有驱动句柄）。

**已用的 Composer 包**：`league/flysystem` + `league/flysystem-aws-s3-v3`（S3 协议通吃
AWS / OSS / COS / MinIO / R2，靠 endpoint 分流）。七牛、Azure 这类非 S3 协议的
需另装对应适配器（`league/flysystem-qiniu-cloud-storage` 等），**装之前先在插件 `drivers()` 里申报键名**，
否则核心不会允许建那种盘的配置行。

---

## 8. 钱包组件设计（组件类插件示例）

### 8.1 为什么是组件而非核心

- 不是所有业务都需要钱包（CMS 不需要，商城需要）
- 钱包有多种形态（余额/积分/权益/虚拟币），不同业务用不同钱包类型
  —— **尚未落地**：当前实现是「每 (member, tenant) 一个账户」，多种形态只体现在流水的 `type` 上
- 钱包需要独立的财务流水、对账、审计

### 8.2 数据模型（对照 `plugins/WalletPlugin.php` 的 `install()`；省略 `IF NOT EXISTS` 与其余两个索引）

```sql
-- 钱包账户：一个会员在一个租户下一个账户（惰性开户 / 批量开户）
CREATE TABLE kode_wallets (
    id          BIGSERIAL PRIMARY KEY,
    member_id   BIGINT NOT NULL,
    tenant_id   BIGINT NOT NULL DEFAULT 0,
    balance     NUMERIC(18,4) NOT NULL DEFAULT 0,
    frozen      NUMERIC(18,4) NOT NULL DEFAULT 0,
    currency    VARCHAR(8)  NOT NULL DEFAULT 'CNY',
    status      SMALLINT NOT NULL DEFAULT 1,
    note        TEXT NOT NULL DEFAULT '',
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX uk_wallets_member_tenant ON kode_wallets (member_id, tenant_id);

-- 钱包流水（只追加，不可修改；账本先行，余额变化必留 before/after）
CREATE TABLE kode_wallet_ledgers (
    id                     BIGSERIAL PRIMARY KEY,
    wallet_id              BIGINT NOT NULL,
    member_id              BIGINT NOT NULL,
    tenant_id              BIGINT NOT NULL DEFAULT 0,
    type                   VARCHAR(24) NOT NULL,      -- created/credit/debit/freeze/unfreeze/adjust/transfer_in/transfer_out/refund
    amount                 NUMERIC(18,4) NOT NULL,
    before_balance         NUMERIC(18,4) NOT NULL DEFAULT 0,
    after_balance          NUMERIC(18,4) NOT NULL DEFAULT 0,
    reason                 VARCHAR(255) NOT NULL DEFAULT '',
    operator               VARCHAR(64) NOT NULL DEFAULT '',
    counterparty_wallet_id BIGINT NULL,               -- 转账对手方
    meta                   JSONB NULL,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_ledgers_wallet ON kode_wallet_ledgers (wallet_id, created_at DESC);
CREATE INDEX idx_ledgers_member ON kode_wallet_ledgers (member_id, created_at DESC);
```

### 8.3 对外接口

| 方法 | 路径 | 权限码 | 说明 |
| --- | --- | --- | --- |
| GET | `/api/wallet/members` | `wallet:view` | 分页账户列表 |
| GET | `/api/wallet/members/{member_id}` | `wallet:view` | 单会员账户（`member_id` 为 HashId 不透明串） |
| GET | `/api/wallet/members/{member_id}/ledger` | `wallet:view` | 分页流水 |
| GET | `/api/wallet/stats` | `wallet:view` | 余额/冻结汇总 |
| GET | `/api/wallet/recent` | `wallet:view` | 最近流水 |
| GET | `/api/wallet/export` | `wallet:export` | 导出（单独权限码，导出含 PII） |
| POST | `/api/wallet/batch-open` | `wallet:create` | 批量开户 |
| POST | `/api/wallet/adjust` | `wallet:adjust` | 调整 `{member_id, amount, reason}`（amount 可负） |
| POST | `/api/wallet/transfer` | `wallet:transfer` | 转账 `{from_member_id, to_member_id, amount, reason}` |

### 8.4 业务集成方式

```php
// 跨插件调服务：走对方 register() 里 bind 的容器 id（没有 PluginManager::get()）
// 写入一律走账本方法，余额与流水在同一事务里落，不要在调用方拼 SQL。
$wallet = resolve('wallet.service');
$wallet->debit($memberId, $orderTotal, "订单 {$orderHashId} 支付", 'shop', [
    'biz_type' => 'order_pay',
    'biz_id'   => $orderHashId,
]);
```

---

## 9. 插件开发规范（组件 vs 业务）

> **可运行示例、`PluginInterface`/`PluginManager` 完整 API、本地开发流程与自检
> 清单见 `docs/PLUGIN-DEV.md`**。本节只讲「该放哪」的边界取舍。
>
> 代码事实（勿以记忆为准）：插件**不**用 `routes.php` / `tables.php` /
> `migrations/` 等文件声明路由、表与菜单——一律在主类里用方法表达
> （`register()` 内 `$manager->addRoute(...)`、`tables()`、`menus()`、
> `install()/uninstall()/upgrade()`）。也没有 `PluginManager::get()`；
> 跨插件取服务统一走容器 `resolve('<bind-id>')`。

### 9.1 组件插件骨架

```
plugins/<Name>Plugin.php          # 实现 PluginInterface
plugins/<name>/                   # 插件私有目录（namespace Kode\Plugins\<name>）
├── <Name>Service.php                 # 核心逻辑
├── events/                           # 可选：事件监听处理器
└── controllers/                      # 可选：仅供内部调用的控制器
```

**组件插件约定**：
- 不声明 `menus()`（除非有配置页）、不注册前端路由
- 通过 `bind()` 暴露 Service + 事件总线对外
- 表名一律用全局 `kode_` 前缀 + 插件自己的名字段避免撞名（`kode_wallets`、`kode_notices`、`kode_lowcode_*`）；
  `tables()` 同时是 `uninstall(purge)` 的删表清单，撞名等于替别人删表（静态门禁见 `tests/PluginSchemaCoverageTest.php`）

### 9.2 业务插件骨架

```
plugins/<Name>Plugin.php          # 主类：路由/菜单/表生命周期全在这里
plugins/<name>/
├── <Name>Controller.php              # 业务控制器（/api/<name>/*）
├── <Name>Service.php                 # 业务逻辑
├── entities/                         # 可选：Doctrine 实体
└── repositories/                     # 可选：仓储
```

**业务插件约定**：
- 必须有 `menus()`（声明菜单 + 权限码）
- 路由前缀恒为 `/api/<name>/`（`name()` 返回值），运行时门禁按此前缀匹配
- 建表在 `install()` 内用 `CREATE TABLE IF NOT EXISTS`（幂等）；卸载默认保留数据

### 9.3 插件间调用

```php
// 业务插件调用组件插件：按组件 bind 的 id 从容器取（无 PluginManager::get）。
/** @var \Kode\Plugins\WalletService $wallet */
$wallet = resolve('wallet.service');
$wallet->consume([...]);

// 跨插件事件：任何插件可 fire / 监听（register 内 addListener）。
Event::fire('order.paid', $orderData);
```

---

## 10. 总结：该做在核心还是插件？

| 判断条件 | 核心 | 插件 |
| --- | --- | --- |
| 所有业务都需要 | ✅ | |
| 涉及框架机制（鉴权/路由/插件加载） | ✅ | |
| 不可移除（移除后系统崩溃） | ✅ | |
| 只有特定业务需要 | | ✅ |
| 可独立安装/卸载 | | ✅ |
| 需要对接第三方（支付/短信/存储） | | ✅ 组件 |
| 有完整业务流程（用户旅程） | | ✅ 业务 |
| 可被多个业务复用 | | ✅ 组件 |
