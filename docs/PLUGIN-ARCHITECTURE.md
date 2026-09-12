# 通用后台架构设计：核心 vs 插件体系

> 本文档定义 Kode 通用后台的**职责边界**与**插件分类体系**，指导开发者判断"该做在核心还是该做成插件"。

---

## 1. 核心后台职责（不拆插件）

通用后台只做**基础架构层**的工作，以下模块永久内嵌于核心，不拆为插件：

| 模块 | 说明 | 路径 |
| --- | --- | --- |
| 系统配置 | `.env` 不可变配置 + 运行时只读展示（`/api/system/configs`） | `app/admin/http/controllers/SystemController.php` |
| 配置中心 | 运行时可变 KV 配置（`/api/configs`，DB 存储，支持分组/类型/写入） | `app/admin/http/controllers/ConfigController.php` |
| 角色管理 | RBAC 角色 CRUD + 权限码授权（`/api/roles`） | `app/admin/http/controllers/RoleController.php` |
| 菜单管理 | 动态路由 + 按钮级权限码（`/api/menus`） | `app/admin/http/controllers/MenuController.php` |
| 用户管理 | 用户 CRUD + 角色分配 + 部门关联 | `app/admin/http/controllers/UserController.php` |
| 部门管理 | 树形组织结构 | `app/admin/http/controllers/DeptController.php` |
| 权限目录 | 按插件聚合权限码 + 按钮码（`/api/perms/catalog`） | `app/admin/http/controllers/PermsController.php` |
| 插件管理 | 安装/卸载/升级/暂停 + 市场（`/api/plugins`） | `app/admin/http/controllers/PluginController.php` |
| 操作审计 | 谁/方法/路径/状态/耗时/IP 元数据（禁止记请求体） | `app/admin/http/controllers/AuditController.php` |
| 系统日志 | 框架级日志查看/清理（`/api/syslogs`） | `app/admin/http/controllers/SyslogController.php` |
| 定时任务 | 任务调度 + 手动触发（`/api/schedules`） | `app/admin/http/controllers/ScheduleController.php` |
| 服务器监控 | 资源/分片拓扑（`/api/monitor`） | `app/admin/http/controllers/MonitorController.php` |
| 数据库管理 | 表/字段/行查看 + SQL 查询（`/api/db/*`） | `app/admin/http/controllers/DbController.php` |
| 媒体库 | 全局文件/图片/视频管理 + URL 解析（`/api/media/*`） | `app/admin/http/controllers/FileController.php` |
| 文件上传 | 鉴权上传 + 下载（`/api/upload`） | `app/admin/http/controllers/UploadController.php` |
| 通知中心 | 站内通知 + 广播（`/api/notifications/*`） | `app/admin/http/controllers/NotifyController.php` |
| 仪表盘 | 汇总数据（`/api/dashboard/summary`） | `app/admin/http/controllers/DashboardController.php` |

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
- **约束**：表名前缀 `plug_<name>_`，路由前缀 `/api/<name>/`，菜单通过 `menus()` 声明
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

后端 `StorageController` 已实现本地/USB/NAS/LAN-NAS 四类存储管理。

### 7.3 对象存储插件设计

```
plugins/StoragePlugin.php
├── config/
│   └── storage.php        # 各平台配置（ak/sk/bucket/region/endpoint）
├── services/
│   ├── StorageService.php # 统一接口：upload/download/delete/list
│   └── adapters/
│       ├── AliyunOssAdapter.php
│       ├── TencentCosAdapter.php
│       ├── QiniuAdapter.php
│       ├── MinioAdapter.php
│       └── LocalAdapter.php   # 本地存储（降级）
├── migrations/
│   └── 001_create_storage_drivers.php
└── tables
    └── plug_storage_driver  # 存储驱动表（ak/sk/bucket/region/status）
```

**接口约定**：
```php
interface StorageInterface {
    public function upload(string $localPath, string $remoteKey): array;
    public function download(string $remoteKey, string $localPath): void;
    public function delete(string $remoteKey): void;
    public function getUrl(string $remoteKey, ?int $expires = null): string;
    public function exists(string $remoteKey): bool;
    public function list(string $prefix = ''): array;
}
```

**推荐 Composer 包**：
- `league/flysystem`（统一文件系统抽象层）
- `league/flysystem-aws-s3-v3`（阿里/MinIO S3 兼容）
- `league/flysystem-qiniu-cloud-storage`（七牛）
- `league/flysystem-azure-blob-storage`（Azure）

---

## 8. 钱包组件设计（组件类插件示例）

### 8.1 为什么是组件而非核心

- 不是所有业务都需要钱包（CMS 不需要，商城需要）
- 钱包有多种形态（余额/积分/权益/虚拟币），不同业务用不同钱包类型
- 钱包需要独立的财务流水、对账、审计

### 8.2 数据模型

```sql
-- 钱包账户（每个用户可有多类钱包）
CREATE TABLE plug_wallet_account (
    id          BIGSERIAL PRIMARY KEY,
    user_id     BIGINT NOT NULL,
    type        VARCHAR(32) NOT NULL DEFAULT 'balance',  -- balance/integral/point/virtual
    balance     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    frozen      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_in    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_out   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(user_id, type)
);

-- 钱包流水（只追加，不可修改）
CREATE TABLE plug_wallet_ledger (
    id          BIGSERIAL PRIMARY KEY,
    user_id     BIGINT NOT NULL,
    account_id  BIGINT NOT NULL,
    type        VARCHAR(32) NOT NULL,       -- balance/integral
    direction   VARCHAR(4) NOT NULL,        -- in/out
    amount      DECIMAL(12,2) NOT NULL,     -- 正数
    balance     DECIMAL(12,2) NOT NULL,     -- 变动后余额
    biz_type    VARCHAR(32) NOT NULL,       -- recharge/consume/transfer/refund
    biz_id      VARCHAR(64) NOT NULL,       -- 业务单据号
    remark      VARCHAR(255) DEFAULT '',
    operator_id BIGINT DEFAULT NULL,        -- 操作人（admin 操作时填）
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_ledger_user ON plug_wallet_ledger(user_id, type, direction);
CREATE INDEX idx_ledger_biz ON plug_wallet_ledger(biz_type, biz_id);
```

### 8.3 对外接口

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/wallet/accounts/{userId}` | 获取用户所有钱包账户 |
| GET | `/api/wallet/ledgers` | 分页流水（`?user_id=&type=&direction=&page=`） |
| POST | `/api/wallet/recharge` | 充值 `{user_id, type, amount, biz_id}` |
| POST | `/api/wallet/consume` | 消费 `{user_id, type, amount, biz_type, biz_id, remark}` |
| POST | `/api/wallet/transfer` | 转账 `{from_user, to_user, type, amount}` |
| POST | `/api/wallet/freeze` | 冻结 `{user_id, type, amount}` |
| POST | `/api/wallet/unfreeze` | 解冻 `{user_id, type, amount}` |

### 8.4 业务集成方式

```php
// 商城插件下单成功后调用钱包组件
$wallet = resolve(PluginManager::class)->get('wallet');
$wallet->consume([
    'user_id'  => $orderId->userId,
    'type'     => 'balance',
    'amount'   => $orderTotal,
    'biz_type' => 'order_pay',
    'biz_id'   => $orderId->hashId,
    'remark'   => "订单 {$orderId->hashId} 支付",
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
- 表名建议带插件前缀避免撞名（如 `plug_wallet_*`；示例公告插件用 `kode_notices`）

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
