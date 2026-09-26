# 整体架构（四层 + 插件化边界）

> 结论：采用**四层架构**为主，DDD 战术局部使用。本系统是通用后台（CRUD 密集），
> 完整 DDD（聚合根/值对象/资源库接口隔离）的仪式感大于收益；但借 DDD 三个纪律：
> 通用语言（AGENTS.md 术语）、聚合边界（插件即限界上下文）、跨聚合走事件。

## 1. 分层与依赖方向

```
Controller（app/<应用>/controllers/）
  收参/校验/调服务/包信封，零业务规则，零 SQL
    ↓
Service（app/services/，如 UserService）
  业务规则 + 编排仓储/Db/事件；抛 RuntimeException(msg, httpCode)
    ↓
Repository（app/repositories/）+ Db（存量）
  只做 persistence（find/save/remove/分页），无业务判断
    ↓
Model（app/models/，Doctrine 实体）
  只做映射 + toArray 脱敏，无逻辑
```

- 依赖只向下；跨模块调用走 Service（同应用）或事件（跨插件），禁止 Controller 直调他模块 Service。
- Service 方法是事务边界候选（单库事务直接包，跨片走 `Saga::run()`）。
- **落地进度要如实说**：上面这张四层图目前只有 `用户` 一个聚合走通（`app/models/User.php` +
  `app/repositories/UserRepository.php`）。45 张 `kode_*` 表里其余 44 张没有实体，
  47 个控制器文件里 38 个直接调 `Db::`。所以本图是**目标形状**，不是现状快照 ——
  写新代码按 AGENTS §1 的 ORM 口径办（业务规则进 Service，持久化在 Service 里用 `Db::` + 绑定参数，
  要迁 ORM 就整块迁，别在一个 Service 里一半实体一半裸 SQL）。

## 2. DDD 战术映射

| DDD 概念 | 本项目落地 |
| --- | --- |
| 限界上下文 | 插件（`plugins/<name>/` + 独立路由前缀/表前缀，见 AGENTS §2） |
| 聚合 | 核心实体及归属子表（如用户 + 角色关联），归属键 `created_by`/`user_id` 恒定 |
| 仓储 | `app/repositories/`（Doctrine），接口即类（暂不抽象接口，YAGNI） |
| 领域事件 | 框架事件总线（`Event::listen` 在 `app/bootstrap.php`） |
| 通用语言 | AGENTS.md 全文即语言表（信封/归属/分片/门禁术语统一） |

## 3. 请求完整路径（以 POST /api/users 为例）

`TimingMiddleware(_t0)` → `PluginGate` → `AuthMiddleware` → `RequireRole` →
`UserController::store`（validate）→ `UserService::create`（唯一性/哈希）→
`UserRepository::save`（persist+flush）→ `ApiEnvelope::ok`（code/dur/data）→
`DoctrineClearMiddleware`（clear）/ `AuditMiddleware`（记元数据）。

## 4. 什么时候升级

- 某插件业务规则超过 ~500 行 Service 代码 → 拆聚合 + 事件解耦；
- 跨库写成为常态 → Saga 模板化（见 `app/support/Saga.php`）；
- 读压力到单库极限 → 读写分离已就绪（`DB_READ_WRITE_SPLIT`），再到分片（`Shard`）。
