<?php

declare(strict_types=1);

namespace app\support;

/**
 * 把文本截到 N 个**字符** —— 全仓唯一的一份截断判据。
 *
 * 存在的理由是单位：pgsql 的 `VARCHAR(n)` 与 `json_encode` 都按字符/码点算，而 `substr()`
 * 按字节算。一个汉字 3 字节，`substr($v, 0, 64)` 落在第 22 个字中间，尾巴上留半个字符，
 * 于是两种坏法各占一半，且都不报「太长」：
 *
 *  - 绑进 SQL（筛选条件、落库字段）→ `SQLSTATE[22021] 无效的 "UTF8" 编码字节顺序`，
 *    一次正常保存或一枚查询参数就是 500；审计/登录日志那种外面包着
 *    `catch (\Throwable) {}` 的旁路写入更糟 —— 记录**静默消失**，接口照回 200。
 *  - 进响应（表数据浏览的长单元格）→ `kode/http` 的 `Response::json()` 带
 *    `JSON_THROW_ON_ERROR`，一列切坏的文本让**整份响应**编码失败，而不是那一格出问题。
 *
 * 切点恰好落在字缝上时（比如界是 3 的倍数、内容是纯三字样的文本）不报错，只是把用户
 * 写的后半截吃掉 —— 那是这一族里最难发现的一种，所以写侧**不该用截断当列宽守卫**：
 * 校验规则写 `length:N`（AGENTS.md §3），超了就是 422，别替用户决定留哪半句。
 * 这个类只用在「必须截」的两处：绑进 SQL 的**筛选**条件（限的是这次匹配的代价，
 * 语义就是取前缀）与**展示**截断（面板只画前若干个字）。
 */
final class Clip
{
    private function __construct()
    {
    }

    /**
     * @param int $chars 保留的字符数（列宽/展示宽度），不是字节数
     */
    public static function chars(string $value, int $chars): string
    {
        // 字节数已达标时字符数必然也达标，热路径（每次审计写入）因此不必走 mbstring。
        if (strlen($value) <= $chars) {
            return $value;
        }

        return mb_substr($value, 0, $chars);
    }

    /**
     * 保留末尾 N 个**字符**（脱敏展示「留首几末几」的那一半）。
     *
     * 与 `chars()` 同一个单位问题：`substr($phone, -4)` 对多字节值会把前半截切成
     * 非法 UTF-8，而掩码串是要进响应的。
     */
    public static function tail(string $value, int $chars): string
    {
        if (strlen($value) <= $chars) {
            return $value;
        }

        return mb_substr($value, -$chars);
    }
}
