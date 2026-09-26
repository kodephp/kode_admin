<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\PluginController;
use app\support\DbRestore;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * 数据库连接凭证的外泄面回归：卸载提示与 psql 恢复命令。
 *
 * 两处各以一种方式把凭证送出了进程边界：
 *  - `POST /api/plugins/{name}/uninstall` 的 `backup_hint` 拼的是
 *    `pg_dump -h <DB_HOST> -U <DB_USERNAME> -d <DB_DATABASE>`，等于向客户端交代
 *    内网地址、账号名和库名 —— 响应会进前端日志、浏览器历史、代理日志和截图；
 *  - 恢复备份的命令行写成 `PGPASSWORD=xxx psql ...`，经 `/bin/sh -c` 拉起时密码落在
 *    sh 进程的 argv 上，本机任何用户 `ps` 一眼可读（同仓 DbBackup 早就走 proc_open 的 env，
 *    只有这条路径漏了）。
 *
 * 修完之后两件事都成了契约：提示里只有表名（可公开），密码只能走子进程环境。
 */
final class DbCredentialExposureTest extends TestCase
{
    /** 反射调用私有静态工具，不为测试把它们抬成公共 API */
    private static function hint(string $name, array $tables, bool $purge): string
    {
        $ref = new ReflectionMethod(PluginController::class, 'backupHint');
        $ref->setAccessible(true);

        return (string) $ref->invokeArgs(null, [$name, $tables, $purge]);
    }

    public function test_hint_names_tables_without_any_connection_argument(): void
    {
        // 用真实插件与真实表名：假表名（plug_*）会让这份回归读起来像在背书一条不存在的约定
        $hint = self::hint('wallet', ['kode_wallets', 'kode_wallet_ledgers'], false);

        self::assertStringContainsString('pg_dump', $hint);
        self::assertStringContainsString("-t 'kode_wallets'", $hint);
        self::assertStringContainsString("-t 'kode_wallet_ledgers'", $hint, '每张表都要给出，漏一张等于备份不全');
        self::assertStringContainsString('plugin_wallet_backup.sql', $hint);

        foreach ([' -h ', ' -U ', ' -d '] as $flag) {
            self::assertStringNotContainsString($flag, $hint, "提示里出现了连接参数{$flag}：主机/账号/库名不该出服务端");
        }
    }

    public function test_hint_for_a_tableless_plugin_does_not_emit_a_full_database_dump(): void
    {
        // 无表时若照拼 `pg_dump  > x.sql`，那是一条不带 -t 的整库导出提示
        self::assertStringNotContainsString('pg_dump', self::hint('demo', [], false));
        self::assertStringContainsString('无需备份', self::hint('demo', [], false));
    }

    public function test_purge_hint_reports_dropped_tables_and_stays_credential_free(): void
    {
        $hint = self::hint('notices', ['kode_notices'], true);

        self::assertStringContainsString('kode_notices', $hint);
        self::assertStringNotContainsString('pg_dump', $hint, '表已删除，再给备份命令是误导');
    }

    /**
     * 密码只能走子进程环境，命令串里一个字都不留。
     *
     * 判据从「扫控制器有没有 proc_open」改成「拿真命令函数验形状」：恢复命令搬进
     * `DbRestore` 之后，源码扫描跟着文件搬家只会把断言变成背书 —— 而这一条真正要挡的是
     * `PGPASSWORD=xxx psql …` 经 `/bin/sh -c` 拉起时密码落在 sh 的 argv 上（本机任何用户
     * `ps` 一眼可读）。所以直接喂一个诱饵密码进去，看它有没有出现在命令里。
     */
    public function test_restore_passes_the_password_through_the_child_environment(): void
    {
        $canary = 'zz-canary-' . bin2hex(random_bytes(6));
        $previous = $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? getenv('DB_PASSWORD');
        $_ENV['DB_PASSWORD'] = $canary;
        $_SERVER['DB_PASSWORD'] = $canary;
        putenv('DB_PASSWORD=' . $canary);

        try {
            $cmd = DbRestore::command('/tmp/kode_restore_sample.sql', 'kode_app_sample');

            self::assertStringContainsString('psql ', $cmd, '命令形状变了：这条回归的前提不再成立');
            self::assertStringNotContainsString(
                $canary,
                $cmd,
                '环境里的密码出现在了命令行里：它会落进 sh 进程的 argv，本机 ps 直接可读'
            );
            self::assertStringNotContainsString(
                'PGPASSWORD',
                $cmd,
                'PGPASSWORD 一旦进命令串，就等于把凭证交给了 argv'
            );
            self::assertStringContainsString(
                '-v ON_ERROR_STOP=1',
                $cmd,
                '默认命令必须带 ON_ERROR_STOP：psql 不加它时中间语句报错照样 exit 0'
            );

            // 环境投递仍在唯一派发口里；控制器再抄一份 psql 命令 = 第二个「密码怎么送」的说法。
            // 只扫去注释后的源码：控制器里那段解释「为什么必须 ON_ERROR_STOP」的注释
            // 就写着 psql，拿原文扫会把说明自己的话判成违规。
            $restore = self::codeOnly((string) file_get_contents(dirname(__DIR__) . '/app/support/DbRestore.php'));
            self::assertMatchesRegularExpression(
                '/proc_open\((?:[^()]|\([^()]*\))*?\'PGPASSWORD\'\s*=>/s',
                $restore,
                '恢复的 proc_open 必须把 PGPASSWORD 放进子进程环境，否则密码无处可放'
            );

            $controllerFile = dirname(__DIR__) . '/app/admin/controllers/BackupController.php';
            $raw = (string) file_get_contents($controllerFile);
            $controller = self::codeOnly($raw);
            self::assertStringContainsString(
                'psql',
                $raw,
                '对照：控制器注释里确实写着 psql（不出现说明下面这轮去注释扫描在扫空气）'
            );
            self::assertStringNotContainsString(
                'psql',
                $controller,
                '去注释后仍留着 psql：恢复命令又回到控制器里自己拼了一份'
            );
            foreach (['PGPASSWORD', 'proc_open(', 'env(\'DB_PASSWORD\''] as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $controller,
                    "恢复命令只此一个派发口（app\\support\\DbRestore）：控制器源码里出现了 {$needle}"
                );
            }
        } finally {
            if ($previous === false || $previous === null) {
                unset($_ENV['DB_PASSWORD'], $_SERVER['DB_PASSWORD']);
                putenv('DB_PASSWORD');
            } else {
                $_ENV['DB_PASSWORD'] = (string) $previous;
                $_SERVER['DB_PASSWORD'] = (string) $previous;
                putenv('DB_PASSWORD=' . (string) $previous);
            }
        }
    }

    public function test_plugin_controller_never_reads_connection_env(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/app/admin/controllers/PluginController.php');

        // 插件控制器只做状态翻转与提示，没有任何理由碰数据库连接参数；
        // 出现一次就是又把它拼进了对外响应（backup_hint 当年就是这么写的）。
        foreach (['DB_HOST', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE', 'DB_PORT'] as $key) {
            self::assertStringNotContainsString("env('{$key}'", $src, "PluginController 读了 {$key}");
        }
    }

    /**
     * 去掉注释后的源码。
     *
     * 「为什么不能这么写」的解释必然要提到那个写法（`psql`、`ON_ERROR_STOP`），
     * 拿原文扫等于把文档判成违规，下一步就是有人把注释删掉 —— 门禁吃掉解释自己的那段话。
     */
    private static function codeOnly(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }
}
