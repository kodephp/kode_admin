<?php

declare(strict_types=1);

namespace app\concerns;

/**
 * PUT 局部更新的取值判据（AGENTS.md §4「缺键保留原值」）。
 *
 * 为什么只留一份：这条契约原本在核心里躺着**两份语义不同的实现**。
 * `MenuController::update()` 用的是局部闭包 `$taken($k, $new, $old)`，判的是「键在不在」；
 * `RoleController::pick()` 判的是「键在且不为 null」。于是一份请求里
 * `{"auth_code": null}` 在菜单那条线上是**清空权限码**（菜单当场变成「登录可见」，
 * 一次越权可见），在角色那条线上才是「没改」。同一个 JSON 值在两个接口里含义相反，
 * 而两边的单测都是绿的 —— 这正是「判据只写一份」要挡的分岔，所以第三份实现由
 * `tests/PartialUpdateTest::test_the_judge_has_exactly_one_implementation` 静态挡住。
 *
 * 统一后的口径：**null 与缺键同等对待（都是「没改」）**。理由是显式清空在这个项目里
 * 一直有自己的写法（`''` / `[]`，见 AGENTS.md §4 括号里那句），而 null 多数来自
 * 「表单里那个框没选」或序列化顺手带出的空值 —— 把后者当成前者，代价是抹掉一次授权。
 * 需要「显式清空」的列由调用方的 `$cast` 决定落库形状（`''`、`[]`、`0`）。
 */
trait PartialUpdate
{
    /**
     * 这次请求有没有带着这一个键要改。
     *
     * 与 `pick()` 同源：判据只能写一次，否则「has」与「pick」迟早各自漂移成两种答案。
     *
     * @param array<string, mixed> $data
     */
    protected static function supplied(array $data, string $key): bool
    {
        return array_key_exists($key, $data) && $data[$key] !== null;
    }

    /**
     * 取一个可改列的新值：请求没带这个键就回落到库里的原值。
     *
     * @param array<string, mixed> $data
     * @param callable(mixed):mixed|null $cast 只在「带了键」时执行，所以库里原值不会被二次归一
     */
    protected static function pick(array $data, string $key, mixed $stored, ?callable $cast = null): mixed
    {
        if (!self::supplied($data, $key)) {
            return $stored;
        }

        return $cast === null ? $data[$key] : $cast($data[$key]);
    }
}
