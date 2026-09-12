-- ============================================================
-- Kode 通用后台数据库结构（PostgreSQL 14+ 通用，无版本特性依赖）
-- 用途：全新环境一键建表；与 database/migrations 等价（迁移用于增量变更）。
-- 执行：psql -h 127.0.0.1 -U root -d kode_app -f database/sql/schema.sql
-- 字符集：数据库 UTF8；时间统一 TIMESTAMPTZ（带时区，导入导出不漂移）。
-- ============================================================

-- ---------------- 用户表 ----------------
CREATE TABLE IF NOT EXISTS kode_users (
    id          BIGSERIAL PRIMARY KEY,          -- 用户主键
    name        VARCHAR(64)  NOT NULL,          -- 登录名（唯一业务标识）
    email       VARCHAR(191) NOT NULL UNIQUE,   -- 邮箱（登录/通知）
    password    VARCHAR(255) NOT NULL,          -- 密码哈希（password_hash）
    status      SMALLINT     NOT NULL DEFAULT 1,-- 状态：1 正常 / 0 禁用（禁用拒绝登录）
    dept_id     BIGINT       NOT NULL DEFAULT 0,-- 所属部门 ID（0 为未分配）
    avatar      VARCHAR(512) NOT NULL DEFAULT '', -- 头像（媒体库路径或外链）
    remark      VARCHAR(255) NOT NULL DEFAULT '', -- 备注
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(), -- 创建时间
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()  -- 更新时间
);
COMMENT ON TABLE kode_users IS '后台用户';
COMMENT ON COLUMN kode_users.name IS '登录名';
COMMENT ON COLUMN kode_users.email IS '邮箱';
COMMENT ON COLUMN kode_users.password IS '密码哈希';

-- ---------------- 角色表 ----------------
CREATE TABLE IF NOT EXISTS kode_roles (
    id          BIGSERIAL PRIMARY KEY,          -- 角色主键
    name        VARCHAR(64)  NOT NULL,          -- 角色显示名（如 超级管理员）
    slug        VARCHAR(64)  NOT NULL UNIQUE,   -- 角色标识（代码侧判断，如 admin）
    perms       TEXT         NOT NULL DEFAULT '[]', -- 权限点 JSON 数组（如 ["user:create"]）
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(), -- 创建时间
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()  -- 更新时间
);
COMMENT ON TABLE kode_roles IS '角色';
COMMENT ON COLUMN kode_roles.slug IS '角色标识（门禁判断依据）';

-- ---------------- 用户角色关联表 ----------------
CREATE TABLE IF NOT EXISTS kode_role_user (
    id          BIGSERIAL PRIMARY KEY,          -- 关联主键
    user_id     BIGINT       NOT NULL,           -- 用户 ID（同 users.id 类型）
    role_slug   VARCHAR(64)  NOT NULL            -- 角色标识
);
COMMENT ON TABLE kode_role_user IS '用户角色关联';
CREATE INDEX IF NOT EXISTS idx_kode_role_user_user ON kode_role_user (user_id);

-- ---------------- 菜单表（核心菜单；插件菜单由各插件 menus() 声明） ----------------
CREATE TABLE IF NOT EXISTS kode_menus (
    id          BIGSERIAL PRIMARY KEY,          -- 菜单主键
    title       VARCHAR(64)  NOT NULL,          -- 菜单标题（前端展示）
    path        VARCHAR(255) NOT NULL,          -- 前端路由路径（如 /users）
    icon        VARCHAR(64)  NOT NULL DEFAULT '', -- 前端图标名
    plugin      VARCHAR(64)  NOT NULL DEFAULT 'system', -- 所属插件（system=核心）
    sort        INTEGER      NOT NULL DEFAULT 0, -- 排序（越小越前）
    parent_id   BIGINT       NOT NULL DEFAULT 0, -- 上级菜单 ID（0 为顶级，无限树）
    name        VARCHAR(64)  NOT NULL DEFAULT '', -- 路由名（如 SystemUser）
    component   VARCHAR(255) NOT NULL DEFAULT '', -- 视图路径（如 /sys/user/list，空=前端映射）
    meta        TEXT         NOT NULL DEFAULT '{}', -- 路由元信息 JSON（徽标/显隐/缓存等）
    status      SMALLINT     NOT NULL DEFAULT 1, -- 状态：1 启用 / 0 禁用（禁用非超管不可见）
    auth_code   VARCHAR(128) NOT NULL DEFAULT '' -- 权限码（空=登录可见；非空=需角色持有）
);
COMMENT ON TABLE kode_menus IS '后台菜单（核心部分）';
COMMENT ON COLUMN kode_menus.plugin IS '所属插件标识';

-- ---------------- 菜单按钮表（按钮级权限码，全局唯一） ----------------
CREATE TABLE IF NOT EXISTS kode_menu_buttons (
    id          BIGSERIAL PRIMARY KEY,          -- 按钮主键
    menu_id     BIGINT       NOT NULL,          -- 所属菜单 id（kode_menus.id，逻辑外键）
    code        VARCHAR(128) NOT NULL,          -- 权限码（全局唯一，如 users:create）
    title       VARCHAR(64)  NOT NULL,          -- 按钮标题（前端展示）
    sort        INTEGER      NOT NULL DEFAULT 0, -- 排序（越小越前）
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(), -- 创建时间
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(), -- 更新时间
    CONSTRAINT kode_menu_buttons_code_uniq UNIQUE (code) -- 权限码全局唯一
);
CREATE INDEX IF NOT EXISTS kode_menu_buttons_menu_idx ON kode_menu_buttons (menu_id);
COMMENT ON TABLE kode_menu_buttons IS '菜单按钮（按钮级权限）';

-- ---------------- 公告表（notice 示例插件） ----------------
CREATE TABLE IF NOT EXISTS kode_notices (
    id          BIGSERIAL PRIMARY KEY,          -- 公告主键
    title       VARCHAR(128) NOT NULL,          -- 公告标题
    body        TEXT NOT NULL,                      -- 公告正文（富文本 HTML）
    status      SMALLINT     NOT NULL DEFAULT 1, -- 状态：1 发布 / 0 草稿
    created_by  VARCHAR(64)  NOT NULL DEFAULT '', -- 创建者用户 ID（分片键 + 归属）
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(), -- 创建时间
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()  -- 更新时间
);
COMMENT ON TABLE kode_notices IS '公告（notice 插件示例）';

-- ---------------- 操作审计日志表（只记元数据，不记请求体） ----------------
CREATE TABLE IF NOT EXISTS kode_audit_logs (
    id          BIGSERIAL PRIMARY KEY,          -- 日志主键
    user_id     VARCHAR(64)  NOT NULL DEFAULT '', -- 操作用户 ID（未登录为 0/guest）
    username    VARCHAR(64)  NOT NULL DEFAULT '', -- 操作用户名
    method      VARCHAR(16)  NOT NULL,           -- HTTP 方法
    path        VARCHAR(255) NOT NULL,           -- 请求路径
    status      INTEGER      NOT NULL,           -- 响应状态码
    duration_ms INTEGER      NOT NULL DEFAULT 0, -- 耗时毫秒
    ip          VARCHAR(64)  NOT NULL DEFAULT '', -- 客户端 IP
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(), -- 创建时间
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()  -- 更新时间
);
COMMENT ON TABLE kode_audit_logs IS '操作审计日志（元数据）；探针路径跳过';

-- ---------------- 操作审计日志分片表（4 片，按 user_id 哈希，见 Shard） ----------------
-- 旧 audit_logs 单表保留历史数据，新写入只走分片表。
CREATE TABLE IF NOT EXISTS kode_audit_logs_00 (LIKE kode_audit_logs INCLUDING ALL);
CREATE TABLE IF NOT EXISTS kode_audit_logs_01 (LIKE kode_audit_logs INCLUDING ALL);
CREATE TABLE IF NOT EXISTS kode_audit_logs_02 (LIKE kode_audit_logs INCLUDING ALL);
CREATE TABLE IF NOT EXISTS kode_audit_logs_03 (LIKE kode_audit_logs INCLUDING ALL);

-- ---------------- 系统配置表 ----------------
CREATE TABLE IF NOT EXISTS kode_sys_configs (
    id          BIGSERIAL PRIMARY KEY,          -- 配置主键
    ckey        VARCHAR(128) NOT NULL UNIQUE,   -- 配置键（如 site.name）
    cvalue      VARCHAR(2000) NOT NULL DEFAULT '', -- 配置值
    remark      VARCHAR(255) NOT NULL DEFAULT '', -- 说明
    ctype       VARCHAR(16)  NOT NULL DEFAULT 'text', -- 类型：text/number/textarea/boolean/image/json
    ctitle      VARCHAR(64)  NOT NULL DEFAULT '', -- 展示标题
    cgroup      VARCHAR(32)  NOT NULL DEFAULT 'custom', -- 分组：site/auth/audit/upload/market/custom
    sort        INTEGER      NOT NULL DEFAULT 0, -- 组内排序
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(), -- 创建时间
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()  -- 更新时间
);
COMMENT ON TABLE kode_sys_configs IS '系统配置中心';

-- ---------------- 部门表 ----------------
CREATE TABLE IF NOT EXISTS kode_depts (
    id          BIGSERIAL PRIMARY KEY,          -- 部门主键
    name        VARCHAR(64)  NOT NULL,          -- 部门名称
    parent_id   BIGINT       NOT NULL DEFAULT 0,-- 上级部门 ID（0 为顶级）
    sort        INTEGER      NOT NULL DEFAULT 0,-- 排序（越小越前）
    status      SMALLINT     NOT NULL DEFAULT 1, -- 状态：1 启用 / 0 禁用
    remark      VARCHAR(255) NOT NULL DEFAULT '', -- 备注
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(), -- 创建时间
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()  -- 更新时间
);
COMMENT ON TABLE kode_depts IS '部门（树形，用户经 users.dept_id 归属）';

-- ---------------- 插件市场表 ----------------
CREATE TABLE IF NOT EXISTS kode_plugins (
    id          BIGSERIAL PRIMARY KEY,          -- 主键
    name        VARCHAR(64)  NOT NULL UNIQUE,   -- 插件名（恒等于 URL 前缀）
    version     VARCHAR(32)  NOT NULL,          -- 已安装版本
    status      VARCHAR(16)  NOT NULL,          -- installed/enabled/paused/uninstalled
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(), -- 创建时间
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()  -- 更新时间
);
COMMENT ON TABLE kode_plugins IS '插件市场安装状态';

-- ---------------- 后台登录日志表 ----------------
CREATE TABLE IF NOT EXISTS kode_login_logs (
    id          BIGSERIAL PRIMARY KEY,          -- 主键
    user_id     BIGINT       NOT NULL DEFAULT 0, -- 用户 ID（不存在为 0）
    username    VARCHAR(64)  NOT NULL DEFAULT '', -- 登录名
    ip          VARCHAR(64)  NOT NULL DEFAULT '', -- 登录 IP
    user_agent  VARCHAR(512) NOT NULL DEFAULT '', -- UA
    device      VARCHAR(128) NOT NULL DEFAULT '', -- 设备（浏览器/OS 粗判）
    status      SMALLINT     NOT NULL DEFAULT 0, -- 1 成功 / 0 失败
    fail_reason VARCHAR(255) NOT NULL DEFAULT '', -- 失败原因
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW() -- 登录时间
);
CREATE INDEX IF NOT EXISTS kode_login_logs_user_idx ON kode_login_logs (user_id);
COMMENT ON TABLE kode_login_logs IS '后台登录日志';

-- ---------------- 字典表（类型 + 字典项） ----------------
CREATE TABLE IF NOT EXISTS kode_dict_types (
    id          BIGSERIAL PRIMARY KEY,          -- 主键
    name        VARCHAR(64)  NOT NULL,          -- 类型名称（如 性别）
    code        VARCHAR(64)  NOT NULL UNIQUE,   -- 类型编码（全局唯一，如 sys_gender）
    remark      VARCHAR(255) NOT NULL DEFAULT '', -- 说明
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(), -- 创建时间
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()  -- 更新时间
);
CREATE TABLE IF NOT EXISTS kode_dict_data (
    id          BIGSERIAL PRIMARY KEY,          -- 主键
    type_code   VARCHAR(64)  NOT NULL,          -- 所属类型编码
    label       VARCHAR(64)  NOT NULL,          -- 展示标签
    value       VARCHAR(128) NOT NULL,          -- 实际值
    sort        INTEGER      NOT NULL DEFAULT 0, -- 排序
    remark      VARCHAR(255) NOT NULL DEFAULT '', -- 说明
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(), -- 创建时间
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()  -- 更新时间
);
CREATE INDEX IF NOT EXISTS kode_dict_data_type_idx ON kode_dict_data (type_code);
COMMENT ON TABLE kode_dict_types IS '字典类型';
COMMENT ON TABLE kode_dict_data IS '字典项';
