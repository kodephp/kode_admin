<?php

declare(strict_types=1);

namespace app\admin\controllers;

use app\concerns\ApiResponse;
use app\support\DbBackup;
use app\support\DbRestore;
use app\support\SysLog;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;

/**
 * 备份管理：列表 + 触发 + 恢复 + 策略配置。
 *
 * 转储范围与命令在 `DbBackup`，恢复的子进程在 `DbRestore` —— 这一层只做收参、门禁与信封。
 */
final class BackupController extends Controller
{
    use ApiResponse;

    private const BACKUP_DIR = 'storage/backups';

    /**
     * 解析备份文件名 → 真实路径。
     * 校验：字符白名单 + 仅 .sql + 禁点开头 + realpath 必须落在备份目录内（防穿越纵深防御）。
     * 非法/不存在返回 null，调用方转 400/404。
     */
    private function resolveFile(string $file): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.sql$/', $file) || str_starts_with($file, '.')) {
            return null;
        }
        $dir = base_path(self::BACKUP_DIR);
        $realDir = realpath($dir);
        $real = realpath($dir . '/' . $file);
        if ($realDir === false || $real === false) {
            return null;
        }
        if (!str_starts_with($real, $realDir . DIRECTORY_SEPARATOR) || !is_file($real)) {
            return null;
        }

        return $real;
    }

    /**
     * GET /api/system/backups — 列出已有备份文件。
     */
    public function index()
    {
        $dir = base_path(self::BACKUP_DIR);
        if (!is_dir($dir)) {
            return $this->ok(['files' => [], 'dir' => self::BACKUP_DIR]);
        }

        $files = glob($dir . '/*.sql') ?: [];
        usort($files, static fn(string $a, string $b): int => strcmp(basename($a), basename($b)));

        $list = [];
        foreach ($files as $file) {
            $stat = @stat($file);
            $mtime = $stat ? (int) $stat['mtime'] : 0;
            $size = $stat ? (int) $stat['size'] : 0;
            $ageDays = $mtime > 0 ? (int) ((time() - $mtime) / 86400) : 0;
            $list[] = [
                'name' => basename($file),
                'path' => self::BACKUP_DIR . '/' . basename($file),
                'size' => $size,
                'size_human' => $this->humanBytes($size),
                'created_at' => $mtime > 0 ? date('Y-m-d H:i:s', $mtime) : '',
                'age_days' => $ageDays,
            ];
        }

        // 概览：按 7/30/90 天分档 + 最旧/最新
        $now = time();
        $within7 = 0;
        $within30 = 0;
        $within90 = 0;
        $olderThan90 = 0;
        $oldest = null;
        $newest = null;
        foreach ($list as $item) {
            if ($item['age_days'] <= 7) $within7++;
            elseif ($item['age_days'] <= 30) $within30++;
            elseif ($item['age_days'] <= 90) $within90++;
            else $olderThan90++;
            if ($oldest === null || strcmp($item['name'], $oldest) < 0) $oldest = $item['name'];
            if ($newest === null || strcmp($item['name'], $newest) > 0) $newest = $item['name'];
        }
        $newestAgeDays = null;
        $oldestAgeDays = null;
        foreach ($list as $item) {
            if ($item['name'] === $newest) $newestAgeDays = $item['age_days'];
            if ($item['name'] === $oldest) $oldestAgeDays = $item['age_days'];
        }

        return $this->ok([
            'files' => $list,
            'dir' => self::BACKUP_DIR,
            'total' => count($list),
            'total_size' => $list !== [] ? array_sum(array_column($list, 'size')) : 0,
            'total_size_human' => $this->humanBytes($list !== [] ? array_sum(array_column($list, 'size')) : 0),
            'within_7d' => $within7,
            'within_30d' => $within30,
            'within_90d' => $within90,
            'older_than_90d' => $olderThan90,
            'newest' => $newest,
            'newest_age_days' => $newestAgeDays,
            'oldest' => $oldest,
            'oldest_age_days' => $oldestAgeDays,
        ]);
    }

    /**
     * POST /api/system/backups/run — 按需触发备份。
     *
     * 请求体：{ tables?: string[] } — 可选表列表（**省略**该键才是全库）。
     * 带键但归一后为空（`[]`、`[""]`、`[" ", ""]`）或形状不对（写成字符串）一律 400：
     * 旧写法 `if (is_array($tables) && $tables !== [])` 把这几样静默落回 `null`，
     * 于是「一张表都没点到」的请求实际备了整座库 —— 范围被静默放大，比拒掉危险得多。
     */
    public function run()
    {
        $tables = $this->input('tables');
        $tableList = null;
        if ($tables !== null) {
            if (!is_array($tables)) {
                return $this->fail('tables 必须是表名数组：省略该键才是备份全库', 400);
            }
            $tableList = array_values(array_filter(array_map(
                static fn ($t): string => is_string($t) ? trim($t) : '',
                $tables
            )));
            if ($tableList === []) {
                return $this->fail('没有指定任何要备份的表：省略 tables 才是备份全库', 400);
            }
        }

        try {
            // 必须落进 storage/backups：只有那里的文件才归列表/下载/清理这套接口管辖
            $file = DbBackup::run($tableList, (string) base_path(self::BACKUP_DIR));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 400);
        } catch (\Throwable $e) {
            // 异常原文只进日志：里面是路径/驱动细节，响应给可执行的下一步就够了
            SysLog::error('backup', '手动备份失败：' . $e->getMessage());

            return $this->fail('备份失败（检查 pg_dump 可用性与数据库权限）', 500);
        }

        $size = (int) @filesize($file);
        // 覆盖面随产物一起回：操作者要能在页面上看到「这次备了哪几座库、多少张表」，
        // 否则「全库备份」到底覆盖了什么仍然只有打开 SQL 文件才知道。
        // 权威记录在产物头部（同一次 DbBackup::scope() 写进去的），这里只是回显。
        $coverage = DbBackup::coverage(DbBackup::scope());
        SysLog::info('backup', '手动备份完成', [
            'file' => basename($file),
            'size' => $size,
            'coverage' => $coverage,
        ]);

        return $this->ok([
            'file' => basename($file),
            'size' => $size,
            'size_human' => $this->humanBytes($size),
            'databases' => array_column($coverage, 'db'),
            'tables' => array_map(static fn(array $c): array => [
                'db' => $c['db'],
                'table_count' => $c['tables'],
            ], $coverage),
        ]);
    }

    /**
     * POST /api/system/backups/restore — 从备份文件恢复。
     *
     * 请求体：{ file: string, confirm: string }
     *
     * 安全门禁：confirm 必须为 "I_UNDERSTAND_THE_RISK"，否则拒绝。
     */
    public function restore()
    {
        $file = (string) $this->input('file', '');
        $confirm = (string) $this->input('confirm', '');

        if ($confirm !== 'I_UNDERSTAND_THE_RISK') {
            return $this->fail('请确认风险（confirm 须为 I_UNDERSTAND_THE_RISK）', 400);
        }

        $filePath = $this->resolveFile($file);
        if ($filePath === null) {
            return $this->fail('文件名非法或备份文件不存在', 404);
        }

        try {
            // 命令形状、凭证投递、退出码语义全在 DbRestore 那一处（恢复只此一个派发口）。
            // 为什么不能只看「有没有异常」：psql 不加 ON_ERROR_STOP 时中间语句报错照样
            // exit 0（实测），而旧产物又不带 --clean，恢复进已有库时每条 CREATE 都撞
            // 「已存在」—— 于是这个接口一直在「一行都没恢复」的情况下回「恢复完成」。
            // 现在的口径：产物自带 `\connect` 分段与逐节 BEGIN/COMMIT（坏一节回滚自己、
            // 不牵连别的节），第一条错误即中止并回非零码，错误原文进日志、尾巴进响应。
            $run = DbRestore::run($filePath);

            if ($run['code'] !== 0) {
                // 尾巴才是中止原因（ON_ERROR_STOP 让 psql 停在第一条错误上）；整段回显能有
                // 几十万字（每节几十张表的 COPY 计数），响应只留尾部、全量落日志
                SysLog::error('backup', "备份恢复失败（exit {$run['code']}）：{$file}\n" . mb_substr($run['out'], 0, 20000));

                return $this->fail('恢复失败（exit ' . $run['code'] . '）：' . $this->tail($run['out']), 500);
            }

            SysLog::info('backup', "备份恢复完成：{$file}");

            return $this->ok([
                'file' => $file,
                'output' => $this->tail($run['out']),
                'message' => '恢复完成',
            ]);
        } catch (\Throwable $e) {
            // 恢复走的是 psql -f，异常原文里可能带连接串/库名：只进日志
            SysLog::error('backup', "备份恢复异常（{$file}）：" . $e->getMessage());

            return $this->fail('恢复失败：执行环境异常，详见系统日志', 500);
        }
    }

    /**
     * DELETE /api/system/backups/{file} — 删除备份文件。
     */
    public function destroy()
    {
        $file = (string) $this->param('file');
        $filePath = $this->resolveFile($file);
        if ($filePath === null) {
            return $this->fail('文件名非法或备份文件不存在', 404);
        }

        if (@unlink($filePath)) {
            SysLog::info('backup', "备份文件已删除：{$file}");
            return $this->ok(['file' => $file, 'action' => 'deleted']);
        }

        return $this->fail('删除失败', 500);
    }

    /**
     * GET /api/system/backups/download?file=<name> — 下载单个备份 SQL 文件。
     * 通过附件头返回，方便前端「下载」按钮直接链接。
     */
    public function download()
    {
        $file = (string) $this->query('file', '');
        $filePath = $this->resolveFile($file);
        if ($filePath === null) {
            return $this->fail('文件名非法或备份文件不存在', 404);
        }

        $size = (int) @filesize($filePath);

        return \Kode\Framework\Http\Resp::make('', 200, [
            'Content-Type' => 'application/sql; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $file . '"',
            'Content-Length' => (string) $size,
        ])->withBody(\Kode\Http\Psr7\Stream::create((string) @file_get_contents($filePath)));
    }

    /**
     * POST /api/system/backups/clean — 清理指定天数之前的备份。
     *
     * 请求体：{ days: int, confirm?: string }
     * 安全门禁：days 必须在 1..3650（越界直接 400，不做静默夹取）；
     * 清理 90 天以内的备份还需 confirm=I_UNDERSTAND_THE_RISK。
     */
    public function clean()
    {
        $data = $this->validate(Request::all(), [
            'days' => 'required|integer',
            'confirm' => 'nullable|string',
        ]);
        // 早先这里把 days 夹进 1..3650：传 0（或漏传、传负数）变成「删掉 1 天前的全部备份」，
        // 一个手错的参数就是一次静默的大规模删除。越界就拒，别替用户决定。
        $days = (int) $data['days'];
        if ($days < 1 || $days > 3650) {
            return $this->fail('days 须在 1..3650 之间', 400);
        }
        $confirm = (string) ($data['confirm'] ?? '');

        if ($days < 90 && $confirm !== 'I_UNDERSTAND_THE_RISK') {
            return $this->fail('清理 90 天以内备份需 confirm=I_UNDERSTAND_THE_RISK', 400);
        }

        $dir = base_path(self::BACKUP_DIR);
        if (!is_dir($dir)) {
            return $this->ok(['deleted' => [], 'deleted_count' => 0, 'days' => $days]);
        }

        $cutoff = time() - $days * 86400;
        $deleted = [];
        $files = glob($dir . '/*.sql') ?: [];
        foreach ($files as $file) {
            $stat = @stat($file);
            if ($stat && $stat['mtime'] < $cutoff) {
                if (@unlink($file)) {
                    $deleted[] = basename($file);
                }
            }
        }

        SysLog::info('backup', "批量清理 {$days} 天前备份：删除 " . count($deleted) . " 个");

        return $this->ok([
            'deleted' => $deleted,
            'deleted_count' => count($deleted),
            'days' => $days,
        ]);
    }

    // ─── 私有辅助 ───────────────────────────────────────────

    /** psql 回显只留尾部：中止原因在最后一条输出上，整段可能几十万字。 */
    private function tail(string $text, int $limit = 4000): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return '…（已截断，完整输出见系统日志）' . mb_substr($text, -$limit);
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . 'GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . 'MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . 'KB';
        }
        return $bytes . 'B';
    }
}