<?php

declare(strict_types=1);

namespace app\support;

/**
 * 敏感字段脱敏（展示层）：列表/日志只显示掩码，详情按权限显示原文。
 *
 * 淘宝式做法：敏感数据宁可掩码展示（138****8000）+ 虚拟号，也不整体加密 body
 * （加密破坏缓存/日志/WAF，且前端仍要解密展示，多此一举）。
 */
final class Mask
{
    /** 手机号：13800138000 → 138****8000 */
    public static function phone(string $phone): string
    {
        return strlen($phone) >= 7
            ? substr($phone, 0, 3) . '****' . substr($phone, -4)
            : '****';
    }

    /** 邮箱：k***@kode.dev */
    public static function email(string $email): string
    {
        $pos = strpos($email, '@');
        if ($pos === false || $pos < 1) {
            return '****';
        }

        return $email[0] . '***' . substr($email, $pos);
    }

    /** 身份证：110101********1234（留首6末4） */
    public static function idCard(string $id): string
    {
        return strlen($id) >= 10
            ? substr($id, 0, 6) . '********' . substr($id, -4)
            : '****';
    }

    /** 姓名：保留首字，其余掩码 */
    public static function name(string $name): string
    {
        if ($name === '') {
            return '';
        }
        $first = mb_substr($name, 0, 1, 'UTF-8');

        return $first . str_repeat('*', max(1, mb_strlen($name, 'UTF-8') - 1));
    }
}
