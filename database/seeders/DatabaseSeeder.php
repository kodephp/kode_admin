<?php

declare(strict_types=1);

use Kode\Framework\Database\Seeder;

/**
 * 默认填充器（由 make/脚手架预置，开箱即可 `php kode db:seed`）。
 *
 * 在此聚合各业务 seeder，例如：
 *   $this->call(UsersTableSeeder::class);
 *   $this->call(PostsTableSeeder::class);
 *
 * 业务 seeder 放在同目录（database/seeders/），继承 {@see Seeder} 并实现 run()。
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 默认空跑：按需取消注释并调用子 seeder。
        // $this->call(UsersTableSeeder::class);
    }
}
