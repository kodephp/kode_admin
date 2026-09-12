<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Database\Db\Db;

/**
 * 全库表/列中文备注补齐（pg_description；迁移建表从未写备注，schema.sql 仅覆盖建库）。
 * 幂等：COMMENT ON 重复执行即覆盖。
 */
final class TableComments extends Migration
{
    public function up(): void
    {
        $tables = [
            'kode_users' => ['后台用户', [
                'id' => '用户主键', 'name' => '登录名', 'email' => '邮箱',
                'password' => '密码哈希', 'status' => '状态：1 正常 / 0 禁用',
                'dept_id' => '所属部门 ID（0 为未分配）', 'avatar' => '头像（媒体库路径或外链）',
                'remark' => '备注', 'created_at' => '创建时间', 'updated_at' => '更新时间',
            ]],
            'kode_roles' => ['角色', [
                'id' => '主键', 'name' => '角色显示名', 'slug' => '角色标识（门禁判断依据）',
                'perms' => '权限点 JSON 数组', 'status' => '状态：1 启用 / 0 禁用',
                'remark' => '备注', 'created_at' => '创建时间', 'updated_at' => '更新时间',
            ]],
            'kode_role_user' => ['用户角色关联', [
                'id' => '关联主键', 'user_id' => '用户 ID', 'role_slug' => '角色标识',
            ]],
            'kode_menus' => ['后台菜单（核心部分）', [
                'id' => '菜单主键', 'title' => '菜单标题（前端展示）',
                'path' => '前端路由路径', 'icon' => '前端图标名',
                'plugin' => '所属插件标识', 'sort' => '排序（越小越前）',
                'parent_id' => '上级菜单 ID（0 为顶级）', 'auth_code' => '权限码（空=登录可见）',
                'status' => '状态：1 启用 / 0 禁用', 'name' => '路由名',
                'component' => '视图路径', 'meta' => '路由元信息 JSON',
            ]],
            'kode_menu_buttons' => ['菜单按钮（按钮级权限）', [
                'id' => '按钮主键', 'menu_id' => '所属菜单 ID', 'code' => '权限码（全局唯一）',
                'title' => '按钮标题', 'sort' => '排序',
                'created_at' => '创建时间', 'updated_at' => '更新时间',
            ]],
            'kode_depts' => ['部门（树形）', [
                'id' => '主键', 'name' => '部门名称', 'parent_id' => '上级部门 ID（0 为顶级）',
                'sort' => '排序', 'status' => '状态', 'remark' => '备注',
                'created_at' => '创建时间', 'updated_at' => '更新时间',
            ]],
            'kode_sys_configs' => ['系统配置中心', [
                'id' => '主键', 'ckey' => '配置键', 'cvalue' => '配置值', 'remark' => '说明',
                'ctype' => '类型：text/number/textarea/boolean/image/json',
                'ctitle' => '展示标题', 'cgroup' => '分组', 'sort' => '组内排序',
                'created_at' => '创建时间', 'updated_at' => '更新时间',
            ]],
            'kode_dict_types' => ['字典类型', [
                'id' => '主键', 'name' => '类型名称', 'code' => '类型编码（全局唯一）',
                'remark' => '说明', 'created_at' => '创建时间', 'updated_at' => '更新时间',
            ]],
            'kode_dict_data' => ['字典项', [
                'id' => '主键', 'type_code' => '所属类型编码', 'label' => '展示标签',
                'value' => '实际值', 'sort' => '排序', 'remark' => '说明',
                'created_at' => '创建时间', 'updated_at' => '更新时间',
            ]],
            'kode_media' => ['媒体注册表（ID 关联）', [
                'id' => '媒体 ID', 'name' => '文件名', 'path' => '相对路径（uploads/…）',
                'mime' => 'MIME', 'size' => '字节数', 'width' => '宽', 'height' => '高',
                'duration' => '时长（秒）', 'disk_id' => '所属磁盘', 'created_by' => '上传者',
                'created_at' => '创建时间',
            ]],
            'kode_notifications' => ['站内通知', [
                'id' => '主键', 'user_id' => '接收用户（0=全员广播）', 'type' => '类型',
                'title' => '标题', 'content' => '内容', 'level' => '级别',
                'url' => '跳转链接', 'is_read' => '是否已读', 'read_at' => '已读时间',
                'created_at' => '创建时间',
            ]],
            'kode_storage_configs' => ['存储通道', [
                'id' => '主键', 'name' => '名称', 'driver' => '驱动：local/oss/s3',
                'config' => '驱动配置 JSON', 'is_default' => '是否默认',
                'created_at' => '创建时间', 'updated_at' => '更新时间',
            ]],
            'kode_system_logs' => ['系统日志', [
                'id' => '主键', 'level' => '级别', 'module' => '模块', 'message' => '消息',
                'context' => '上下文 JSON', 'ip' => 'IP', 'created_at' => '创建时间',
            ]],
            'kode_audit_logs' => ['操作审计日志（只记元数据）', [
                'id' => '主键', 'user_id' => '用户标识', 'username' => '用户名',
                'method' => 'HTTP 方法', 'path' => '请求路径', 'status' => 'HTTP 状态',
                'duration_ms' => '耗时毫秒', 'ip' => 'IP', 'created_at' => '创建时间',
                'updated_at' => '更新时间',
            ]],
            'kode_login_logs' => ['后台登录日志', [
                'id' => '主键', 'user_id' => '用户 ID', 'username' => '登录名', 'ip' => '登录 IP',
                'user_agent' => 'UA', 'device' => '设备', 'status' => '1 成功 / 0 失败',
                'fail_reason' => '失败原因', 'created_at' => '登录时间',
            ]],
            'kode_notices' => ['公告（notice 插件示例）', [
                'id' => '主键', 'title' => '标题', 'body' => '正文（富文本 HTML）',
                'created_by' => '创建者（分片键）', 'created_at' => '创建时间', 'updated_at' => '更新时间',
            ]],
            'kode_plugins' => ['插件安装登记', [
                'id' => '主键', 'name' => '插件名', 'version' => '已安装版本',
                'status' => '状态', 'created_at' => '创建时间',
            ]],
        ];
        $exists = [];
        foreach (Db::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'") as $r) {
            $exists[(string) $r['tablename']] = true;
        }
        foreach ($tables as $table => [$tableComment, $columns]) {
            if (!isset($exists[$table])) {
                continue;
            }
            // 表名前缀匹配分片表（如 kode_audit_logs_00 一并备注）。
            $targets = [$table];
            foreach (array_keys($exists) as $t) {
                if ($t !== $table && str_starts_with($t, $table . '_')) {
                    $targets[] = $t;
                }
            }
            foreach ($targets as $t) {
                Db::statement("COMMENT ON TABLE {$t} IS '" . str_replace("'", "''", $tableComment) . "'");
                foreach ($columns as $col => $comment) {
                    try {
                        Db::statement("COMMENT ON COLUMN {$t}.{$col} IS '" . str_replace("'", "''", $comment) . "'");
                    } catch (\Throwable) {
                    }
                }
            }
        }
    }

    public function down(): void
    {
    }
}
