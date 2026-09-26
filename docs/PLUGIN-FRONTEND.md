# 插件管理前端开发指南（admin-web · Ant Design Vue）

> 面向前端开发者。读完本文可从零开发插件管理页面，避免常见运行时错误。
> 本文所有 API 返回结构均以仓库内真实后端代码为基准，逐一核对。

---

## 0. 文件与构建

```
admin-web/
├── apps/web-antd/src/views/kode/plugins/index.vue   # 插件管理主页面
├── apps/web-antd/src/api/kode.ts                     # API 封装（kodeApi.*）
└── packages/...                                      # Vben 共享包

构建：npx turbo run build --filter=@vben/web-antd --force
验证：Tasks: 11 successful, 11 total / dist.zip ~1.89 MB
```

---

## 1. API 端点与返回结构（逐字段核对）

所有端点前缀 `/api/plugins`，信封格式 `{ code, msg, data }`（`data` 内结构见下）。

### 1.1 `GET /api/plugins` → 插件列表（`PluginController::index()`）

```ts
// 每个插件对象的完整字段（PluginController.php:41-71）
type PluginItem = {
  // ---- 基本信息 ----
  name: string;              // 插件名（= URL 前缀，小写 2-32 位）
  class: string;            // 完整类名
  version: string;          // 代码声明版本
  installed_version: string | null;  // 已安装版本（null = 未安装）
  status: 'enabled' | 'paused' | 'uninstalled';  // 状态

  // ---- 能力清单（PluginContract::describe） ----
  menus: Array<{ path: string; title: string; icon?: string; sort?: number }>;
  tables: string[];        // 表名列表
  commands: Array<{ class: string }>;  // 声明的命令类
  events: Record<string, string[]>;   // ⚠️ 对象，非数组！事件名 → 监听器列表
  crons: Array<{ name: string; expression: string; description: string }>;
  capabilities: string[];  // 声明的能力名列表
  groups: Record<string, string[]>;  // ⚠️⚠️ 对象，非数组！分组名 → 能力名列表
  permissions: string[];   // 权限码列表
  drivers: string[];       // 驱动名列表
  requires: string[];      // 依赖的插件名列表
  config_schema: any;      // 配置 schema（嵌套对象）
  meta: Record<string, any>;  // 元数据（任意键值）
  errors: string[];        // 能力方法执行失败的错误信息

  // ---- 契约审计 ----
  contract_ok: boolean;    // true = 零漂移
  contract_drift: string[];  // 漂移的能力名列表
  contract_pending: string[];  // 待确认的能力名列表

  // ---- 依赖图（PluginDependencyResolver::overview） ----
  dependencies: Array<{
    name: string;          // 依赖的插件名
    version: string | null;  // 版本约束
    installed: string;     // 已安装版本（'' = 未安装）
    status: string;        // 依赖插件的状态
    ok: boolean;           // ⚠️ 字段名是 'ok'，不是 'ready'
  }>;
  dependents: string[];    // 下游依赖者（谁依赖本插件）
  transitive: string[];    // 传递闭包（间接依赖本插件的插件）
  cycle: boolean;          // 是否存在循环依赖
};
```

### 1.2 `GET /api/plugins/:name/detail` → 运行时详情（`PluginRuntimeDetail::detail()`）

```ts
type RuntimeDetail = {
  ok: boolean;
  name: string;
  routes: Array<{
    path: string;
    methods: string[];     // HTTP 方法列表
    source: string;        // 'plugin:<name>'
  }>;
  events: Array<{
    event: string;
    listeners: string[];
    registered: boolean;
  }>;
  crons: Array<{
    name: string;
    expression: string;
    description: string;
    enabled: boolean;
  }>;
  commands: Array<{
    class: string;
    name: string;
    registered: boolean;
  }>;
  menus: Array<{ path: string; title: string; icon?: string; sort?: number }>;
};
```

### 1.3 `GET /api/plugins/:name/contract` → 能力契约详情

```ts
type ContractDetail = PluginItem & {
  schema: Record<string, [string, boolean, string]>;  // CAPABILITY_SCHEMA
  group_labels: Record<string, string>;                // GROUP_LABELS
  dependencies: {                                      // 完整依赖概览
    dependencies: Array<{ name: string; version: string | null; installed: string; status: string; ok: boolean }>;
    dependents: string[];
    transitive: string[];
    cycle: boolean;
  };
};
```

### 1.4 `GET /api/plugins/audit-report` → 审计报表

```ts
type AuditReport = {
  summary: {
    total: number;
    enabled: number;
    paused: number;
    uninstalled: number;
    healthy: number;
    degraded: number;
    unhealthy: number;
    drift_count: number;
    cycle_count: number;
    missing_deps: number;
  };
  plugins: Array<{
    name: string;
    status: string;
    contract_ok: boolean;
    contract_drift: string[];
    cycle: boolean;
    health_status: 'healthy' | 'degraded' | 'unhealthy';
    missing_deps: string[];
    // ... 同 PluginItem 部分字段
  }>;
  generated_at: string;  // ISO 时间
};
```

### 1.5 `GET /api/plugins/:name/health` → 健康检查

`app/support/PluginHealthChecker::check()` 的原样出参（`views/kode/plugins/index.vue`
的健康弹窗按此渲染，`checks` 是**对象 map** 而不是数组，键名即检查项名）：

```ts
type Health = {
  status: 'healthy' | 'degraded' | 'unhealthy';
  // 是字符串，直接进 Alert 的 description；不是 { ok, failed } 计数对象
  summary: string;                       // "3 项通过 / 0 项异常 / 6 项不适用"
  checks: {
    [key in 'routes' | 'commands' | 'events' | 'crons' | 'menus'
              | 'config' | 'drivers' | 'dependencies' | 'version']: {
      applicable: boolean;               // false = 这个面对该插件不适用（灰点，不算异常）
      ok: boolean;
      detail: string;                    // 已插值的中文文案，前端原样显示
      count?: number;
      declared?: number;
      missing?: string[];
      routes?: Array<{ path: string; methods: string[] }>;
      tasks?: Array<{ name: string; expression: string; description: string; enabled: boolean }>;
      menus?: Array<{ path: string; title: string }>;
      drivers?: string[];
      version?: string | null;
      valid?: boolean;
      saved?: number;
      schema_count?: number;
    };
  };
};
```

**ok / applicable 的口径**（决定了弹窗会不会一片红，改检查项时必须守）：

- 没声明 = 不适用：`applicable: false`、`ok: true`。组件插件没有命令/菜单/驱动是正常形态，
  把它算成 `failed` 会让每只新装插件都显示 degraded，红色随即失去意义。
- 声明了 = 必须**全部**落地：`ok` 取「已注册数 === 声明数」。
  「声明 3 条命令只注册上 1 条」是注册链路真断了，回 `ok: true` 等于把缺失渲染成健康。
- 两个纯信息行：`routes`（契约里没有 `routes` 能力，没有对账基准，只报数不判健康）、
  `config`（schema 的 default 是产品支持的运行状态，「装完还没配」不等于坏）。
- `detail` **必须已插值**：弹窗是 `{{ check.detail }}` 原样渲染的，
  模板里留 `{count}` 就等于把占位符显示给管理员看。

---

## 2. ⚠️ 常见陷阱与正确写法

### 2.1 `groups` 是对象，不是数组（最高频错误）

```ts
// ❌ 错误：TypeError: (p.groups || []).slice is not a function
<Tag v-for="g in (p.groups || []).slice(0, 3)" :key="g">

// ✅ 正确：groups 是 Record<string, string[]>，用 Object.keys()
<Tag v-for="g in Object.keys(p.groups || {}).slice(0, 3)" :key="g">
```

**原因**：`PluginContract::describe()` 返回 `'groups' => array<group_name, capability_list>`，
PHP 关联数组 → JSON 对象，不是 JSON 数组。

### 2.2 `events` 是对象，不是数组

```ts
// ❌ 错误
p.events.length

// ✅ 正确
Object.keys(p.events || {}).length
```

### 2.3 依赖就绪字段是 `ok`，不是 `ready`

```ts
// ❌ 错误
deps.filter(d => d.ready).length

// ✅ 正确
deps.filter(d => d.ok).length
```

### 2.4 `dependencies` 每项的完整结构

```ts
// 每项是 { name, version, installed, status, ok }
// name      — 依赖的插件名
// version   — 版本约束（null = 无约束）
// installed — 已安装版本（'' = 未安装）
// status    — 依赖插件的状态（enabled/paused/uninstalled）
// ok        — 是否满足约束（boolean）
```

### 2.5 `menus` 每项的完整结构

```ts
// 每项是 { path, title, icon?, sort? }
// path — 路由路径
// title — 菜单标题
// icon — 图标名（可选）
// sort — 排序权重（可选）
```

---

## 3. 前端组件模式与约定

### 3.1 API 调用模式

```ts
// 使用 kodeApi 封装（见 src/api/kode.ts）
const data = await kodeApi.pluginList();         // GET /api/plugins
const detail = await kodeApi.pluginRuntime(name); // GET /api/plugins/:name/detail
const contract = await kodeApi.pluginContract(name); // GET /api/plugins/:name/contract
const health = await kodeApi.pluginHealth(name);     // GET /api/plugins/:name/health
const audit = await kodeApi.pluginAuditReport();     // GET /api/plugins/audit-report
```

### 3.2 信封解析

```ts
// 响应信封：{ code, msg, data }
// 成功：code === 0（或 200），data 为实际内容
// 失败：code !== 0，msg 为错误信息
// 前端统一在 kode.ts 层处理，业务代码直接拿 data
```

### 3.3 Ant Design Vue 组件约定

| 场景 | 组件 | 注意事项 |
|---|---|---|
| 卡片列表 | `<Card>` + `<Row :gutter="16">` + `<Col :span="8">` | 状态色左边框：`:style="{ borderLeft: '4px solid #52c41a' }"` |
| 信息展示 | `<VbenDescriptions :column="2" bordered :items="...">` | 来自 `@vben/common-ui` |
| 标签 | `<Tag size="small" color="...">` | 颜色：blue/purple/cyan/geekblue/green/success/warning/error/default |
| 悬浮提示 | `<Tooltip :title="...">` | 长列表用 `\n` 换行，逗号列表用 `, ` 拼接 |
| 下拉菜单 | `<Dropdown>` + `<Menu>` + `<MenuItem>` + `<Divider class="!my-1">` | 分组用 Divider 分隔 |
| 表格 | `<Table :data-source="..." :columns="..." :pagination="false" size="small">` | 滚动：`:scroll="{ x: 1200 }"` |
| 弹窗 | `<Modal v-model:open="..." width="860" :footer="null">` | 详情用 Tabs 而非多个 Card |
| 空状态 | `<Empty description="...">` | 每个 Tab 内独立判断 |

### 3.4 状态颜色约定

```ts
// 插件状态
enabled     → success（绿）
paused      → warning（橙）
uninstalled → default（灰）

// 契约状态
contract_ok=true       → success（'契约零漂移'）
contract_drift.length>0 → error（'契约漂移 N'）
其他                   → warning（'契约待确认'）

// 健康状态
healthy  → success
degraded → warning
unhealthy → error

// 卡片左边框色
enabled     → #52c41a
paused      → #faad14
uninstalled → #d9d9d9
```

---

## 4. 详情页架构（`openDetail` 主流程）

```
用户点击「详情」
  → openDetail(name)
    → detailName = name
    → detailVisible = true
    → detailData = await kodeApi.pluginRuntime(name)
    → 自动定位第一个非空 Tab
      ['routes', 'events', 'crons', 'commands', 'menus']

详情 Modal 布局（自上而下）：
  1. 顶部：「插件运行时概览」标题 + 复制 Dropdown（Markdown/JSON）
  2. VbenDescriptions（11 项）：版本/状态/契约/依赖环/表/驱动/能力·权限/依赖/依赖就绪/下游依赖者/传递依赖
  3. Divider
  4. Tabs（5 个 Tab，自动定位）
  5. 底部操作区：升级/启用/暂停/备份/卸载 + 更多 Dropdown（9 项，3 组）
```

---

## 5. 筛选维度

```ts
// 4 维筛选（搜索栏）
searchKeyword    // 插件名模糊搜索
filterStatus     // enabled / paused / uninstalled
filterContract   // ok / drift / pending
filterCycle      // cycle / nocycle

// 审计报表筛选
auditFilterHealth  // healthy / degraded / unhealthy

// 统计卡/审计卡点击筛选
toggleFilterDrift()   // 切换契约漂移筛选
toggleFilterCycle()   // 切换依赖环筛选
clearFilters()        // 清除所有筛选
```

---

## 6. 开发工作流

```
1. 修改 index.vue
2. npx turbo run build --filter=@vben/web-antd --force
3. 验证 Tasks: 11 successful, 11 total
4. 检查 dist.zip 产出
5. 追加 memory（.workbuddy/memory/YYYY-MM-DD.md）
```

### 6.1 后端字段变更检查清单

修改后端 `PluginController` / `PluginContract` / `PluginRuntimeDetail` 时，必须同步检查前端：

- [ ] `describe()` 返回字段变更 → 更新 `PluginItem` 类型定义
- [ ] `overview()` 返回字段变更 → 更新 `dependencies` / `dependents` / `transitive` 结构
- [ ] `runtimeDetail()` 返回字段变更 → 更新 `RuntimeDetail` 类型定义
- [ ] 新增/改检查项（`PluginHealthChecker`）→ 同步 §1.5 的口径表，并确认 `detail` 已插值
      （弹窗是 `{{ check.detail }}` 原样渲染）+ 弹窗标题映射里有这一项的中文名
- [ ] 字段从数组变为对象（或反之）→ 全局搜索该字段名，检查 `.slice()` / `.length` / `.join()` / `.map()` 等数组方法调用
- [ ] 字段名变更（如 `ready` → `ok`）→ 全局搜索旧字段名

---

## 7. 关键源码位置索引

| 功能 | 后端文件 | 前端文件 |
|---|---|---|
| 插件列表 | `app/admin/controllers/PluginController.php:32` (`index()`) | `views/kode/plugins/index.vue` |
| 运行时详情 | `app/support/PluginRuntimeDetail.php` | 同上（detail Modal） |
| 能力契约 | `app/support/PluginContract.php` (`describe()`, `audit()`) | 同上（contract Modal） |
| 依赖概览 | `vendor/kode/framework/src/Plugin/PluginDependencyResolver.php:472` (`overview()`) | 同上（detailInfoItems） |
| 审计报表 | `app/support/PluginAuditReport.php` (`generate()`) | 同上（audit Tab） |
| API 封装 | — | `src/api/kode.ts` |