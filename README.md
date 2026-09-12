# Kode 通用后台（后端）

插件式通用管理后台 API：鉴权 / 用户 / 角色 / 菜单 / 部门 / 配置中心 / 插件市场 /
操作审计 / 公告 / 上传 / 仪表盘 / 备份，统一信封 `{code,msg,dur,data}`。

> 框架 `kode/framework` · DB 以 pgsql 为主 · ORM 默认 Doctrine
> 详细后台说明见 [README-ADMIN.md](README-ADMIN.md)，开发规范见 [AGENTS.md](AGENTS.md)，
> 变更历史见 [CHANGELOG.md](CHANGELOG.md)。

## 安装（任意 pgsql 账号均可，无写死角色）

```bash
composer install
# 1. 建库（用你自己的账号；示例 FlyEnv root 空密码）
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

## 前端

- 管理 Web：`../admin-web`（vben-antd，Ant Design Vue + vxe-table；适配层 `src/api/kode.ts`）
- C 端多端：uni-app x，调 `/c-api/*`（默认关闭自助注册，`C_API_REGISTER=true` 开启）
