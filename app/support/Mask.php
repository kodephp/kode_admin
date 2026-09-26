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
    /** 手机号：13800138000 → 138****8000（按字符计数，脏数据不能把响应打崩） */
    public static function phone(string $phone): string
    {
        return mb_strlen($phone) >= 7
            ? Clip::chars($phone, 3) . '****' . Clip::tail($phone, 4)
            : '****';
    }

    /** 邮箱：k***@kode.dev */
    public static function email(string $email): string
    {
        $pos = strpos($email, '@');
        if ($pos === false || $pos < 1) {
            return '****';
        }

        // 首字符取一个**字符**而不是一个字节：中文邮箱前缀是库里的脏数据也有的。
        return Clip::chars($email, 1) . '***' . substr($email, $pos);
    }

    /** 身份证：110101********1234（留首6末4） */
    public static function idCard(string $id): string
    {
        return mb_strlen($id) >= 10
            ? Clip::chars($id, 6) . '********' . Clip::tail($id, 4)
            : '****';
    }

    /** 姓名：保留首字，其余掩码 */
    public static function name(string $name): string
    {
        if ($name === '') {
            return '';
        }

        return Clip::chars($name, 1) . str_repeat('*', max(1, mb_strlen($name) - 1));
    }
}
