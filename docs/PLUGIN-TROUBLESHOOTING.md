# 插件问题排查指南

> 面向开发者与运维。按症状索引，每个问题给出根因、诊断命令、修复步骤。

---

## 目录

1. [路由 404](#1-路由-404)
2. [契约漂移（drift）](#2-契约漂移drift)
3. [依赖未就绪](#3-依赖未就绪)
4. [安装/升级失败](#4-安装升级失败)
5. [卸载后残留](#5-卸载后残留)
6. [前端页面白屏 / 控制台报错](#6-前端页面白屏--控制台报错)
7. [定时任务不执行](#7-定时任务不执行)
8. [配置保存失败](#8-配置保存失败)

---

## 1. 路由 404

**症状**：`GET /api/<plugin-name>/*` 返回 404 或权限拒绝。

### 1.1 插件未声明到 `config/plugins.php`

```bash
# 检查发现但未声明
php kode plugin:list --undeclared
# 检查声明但发现不到
php kode plugin:list --missing
```

**修复**：在 `config/plugins.php` 的 `plugins` 数组中添加插件类全限定名。

### 1.2 插件文件未 reload

插件类是启动期加载，修改后必须 reload：

```bash
php kode reload
```

### 1.3 插件名不合规

插件名必须匹配 `^[a-z0-9_]{2,32}$`，路由前缀恒等于插件名。

```bash
# 查看插件名
php kode plugin:list
```

---

## 2. 契约漂移（drift）

**症状**：`/api/plugins` 列表中 `contract_ok: false`，`contract_drift` 非空。

### 2.1 诊断

```bash
# 全量审计
php kode plugin:audit

# 单插件审计
curl http://localhost:8780/api/plugins/<name>/contract | jq '.contract_drift'
```

### 2.2 常见原因

| drift 项 | 原因 | 修复 |
|---|---|---|
| `tables` | 表名不合规（非小写下划线）或重复 | 检查 `tables()` 返回值 |
| `version` | `version()` 返回非法版本格式 | 检查 SemVer 格式 |
| `requires` | 依赖版本约束语法无法解析 | 检查 `requires()` 格式 |
| `menus` | 菜单形态非法 | 检查 `menus()` 返回数组 |
| `permissions` | 路由门禁码未被 `permissions()` 声明 | 在 `permissions()` 中补充 |
| `crons` | 声明了但调度器中未注册 | `register()` 中调 `addCron()` |
| `commands` | 声明了但内核中未注册 | `register()` 中调 `addCommand()` |
| `events` | 声明了但派发器中未注册 | `register()` 中调 `addListener()` |

### 2.3 零漂移是发版红线

```bash
# 发版前必须通过
./vendor/bin/phpunit tests/PluginContractTest.php
```

---

## 3. 依赖未就绪

**症状**：`install`/`resume`/`upgrade` 返回 `400`，响应含 `dependencies` 对象。

### 3.1 诊断

```bash
# 查看依赖概览
curl http://localhost:8780/api/plugins/<name>/contract | jq '.dependencies'

# 每项依赖的 ok 字段
curl http://localhost:8780/api/plugins/<name>/contract | jq '.dependencies.dependencies[] | {name, ok, version, installed, status}'
```

### 3.2 常见原因

| 原因 | 诊断 | 修复 |
|---|---|---|
| 依赖未安装 | `installed: ''` | 先安装依赖插件 |
| 依赖已暂停 | `status: 'paused'` | 启用依赖插件 |
| 版本不匹配 | `ok: false` + `version` 非空 | 升级依赖插件到满足约束的版本 |

---

## 4. 安装/升级失败

### 4.1 市场安装

```bash
# 检查市场连通性
curl http://localhost:8780/api/plugins/marketplace/health

# 远程安装（三道门禁）
curl -X POST http://localhost:8780/api/plugins/install-remote \
  -H 'Content-Type: application/json' \
  -d '{"url":"...", "sha256":"...", "name":"..."}'
```

**三道门禁**：
1. 包内必须有 `plugin.json` 且声明 `name`
2. 清单 `name` 必须等于请求的 `name`
3. 清单 `kode` 下限必须被当前框架版本满足

### 4.2 升级

```bash
curl -X POST http://localhost:8780/api/plugins/<name>/upgrade
```

**注意**：`upgrade()` 必须幂等，`$from` → `$to` 之间做增量变更。

---

## 5. 卸载后残留

**症状**：卸载后数据库表仍在、配置仍在、依赖者未级联暂停。

### 5.1 诊断

```bash
# 检查是否真卸载
curl http://localhost:8780/api/plugins | jq '.[] | select(.name=="<name>") | .status'

# 检查依赖者
curl http://localhost:8780/api/plugins/<name>/contract | jq '.dependencies.dependents'
```

### 5.2 修复

```bash
# 强制卸载（purge 删表 + 清配置）
curl -X POST http://localhost:8780/api/plugins/<name>/uninstall \
  -H 'Content-Type: application/json' \
  -d '{"confirm":1, "purge":1}'
```

**注意**：`purge` 会删除插件声明的所有表，不可恢复。

---

## 6. 前端页面白屏 / 控制台报错

### 6.1 `p.groups` 相关 TypeError

```
TypeError: (p.groups || []).slice is not a function
```

**根因**：`groups` 是对象（`{ group: [cap, ...] }`），不是数组。

**修复**：
```ts
// ❌ 错误
p.groups.slice(0, 3)
// ✅ 正确
Object.keys(p.groups || {}).slice(0, 3)
```

### 6.2 `p.events` 相关 TypeError

```
TypeError: p.events.map is not a function
```

**根因**：`events` 是对象（`{ event: [listener, ...] }`），不是数组。

**修复**：
```ts
// ❌ 错误
p.events.map(e => e.name)
// ✅ 正确
Object.entries(p.events || {}).map(([event, listeners]) => ({ event, listeners }))
```

### 6.3 依赖就绪字段名错误

```
// ❌ 错误（字段不存在，返回 undefined）
deps.filter(d => d.ready)
// ✅ 正确
deps.filter(d => d.ok)
```

### 6.4 其他字段类型陷阱

| 字段 | 类型 | 正确用法 |
|---|---|---|
| `groups` | 对象 | `Object.keys(p.groups)` |
| `events` | 对象 | `Object.keys(p.events)` / `Object.entries(p.events)` |
| `config_schema` | 对象 | 按 schema 结构遍历 |
| `meta` | 对象 | 按 key 取值 |
| `dependencies[].ok` | boolean | `d.ok`（非 `d.ready`） |

**完整前端字段参考**：见 `docs/PLUGIN-FRONTEND.md` §1-§2。

---

## 7. 定时任务不执行

**症状**：`crons()` 声明了任务，但调度器中未执行。

### 7.1 诊断

```bash
# 查看调度器中的任务
php kode schedule:list --source=plugin:<name>

# 查看插件详情中的定时任务
curl http://localhost:8780/api/plugins/<name>/detail | jq '.crons'
```

### 7.2 修复

**必须在 `register()` 或 `boot()` 中调用 `addCron()`**：

```php
public function register(PluginManager $m): void
{
    $m->addCron('0 2 * * *', 'App\Jobs\ReportJob::run', 'report.nightly', '每天2点');
}
```

> ⚠️ `crons()` 只是声明清单，真正的注册发生在 `register()`/`boot()` 中。

---

## 8. 配置保存失败

**症状**：`PUT /api/plugins/:name/config` 返回 `400 + errors`。

### 8.1 诊断

```bash
# 查看配置 schema
curl http://localhost:8780/api/plugins/<name>/config | jq '.schema'

# 保存配置
curl -X PUT http://localhost:8780/api/plugins/<name>/config \
  -H 'Content-Type: application/json' \
  -d '{"values": {...}}'
```

### 8.2 常见原因

| 原因 | 诊断 | 修复 |
|---|---|---|
| schema 未声明 | `configSchema()` 返回空 | 在插件中实现 `configSchema()` |
| 类型不匹配 | `errors` 含字段名 | 按 schema 类型修正值 |
| secret 字段 | 掩码值原样回传 | 留空或原样回传掩码即视为「保持不变」 |

---

## 附录：关键命令速查

```bash
# 插件列表
php kode plugin:list

# 契约审计
php kode plugin:audit

# 路由列表（按来源过滤）
php kode route:list --source=plugin:<name>

# 定时任务列表
php kode schedule:list --source=plugin:<name>

# 重载
php kode reload

# 单元测试
./vendor/bin/phpunit tests/PluginContractTest.php
```

## 附录：API 速查

```
GET  /api/plugins                              列表
GET  /api/plugins/:name/contract               契约详情
GET  /api/plugins/:name/detail                 运行时详情
GET  /api/plugins/:name/health                 健康检查
GET  /api/plugins/:name/logs                   运行日志
GET  /api/plugins/:name/config                 配置数据
PUT  /api/plugins/:name/config                 保存配置
POST /api/plugins/:name/install                安装
POST /api/plugins/:name/pause                  暂停
POST /api/plugins/:name/resume                 恢复
POST /api/plugins/:name/upgrade                升级
POST /api/plugins/:name/uninstall              卸载
GET  /api/plugins/:name/package                打包下载
POST /api/plugins/install-remote               远程安装
GET  /api/plugins/audit-report                 审计报表
GET  /api/plugins/marketplace/health           市场连通性
POST /api/plugins/batch-pause                  批量暂停
POST /api/plugins/batch-resume                 批量启用
POST /api/plugins/batch-uninstall              批量卸载
```