<?php

declare(strict_types=1);

namespace app\admin\controllers;

use Kode\Framework\Http\Controller;
use Kode\Framework\Process\ProcessManager;
use Kode\Process\Signal;
use app\concerns\ApiResponse;
use app\support\SysLog;

/**
 * 进程控制：常驻 Worker 清单、逐槽位运行状态、启动派发 / 优雅停止 / 平滑重载。
 *
 * 「谁在跑、pid 是多少、信号发给谁」只问 {@see ProcessManager}
 * （framework v1.12.0 的 residentSlots() / slotStates() / signalSlots()，
 * v1.13.0 起 start() 自己也会拒绝重复派发）。
 *
 * 此前这里自己拼了一个 `/tmp/kode-daemon.pid` 当判据，而 `kode process:start`
 * 从不往那个名字写文件（它按槽位写 `sys_get_temp_dir()/kode-worker-<name>:<slot>.pid`），
 * 于是三条线朝同一个方向坏掉：状态恒「未运行」→「启动」的防重入永久放行
 * （每点一次真叠加一整套守护进程，且第二代会覆盖 pid 文件、把第一代变成查不到的孤儿）
 * → 停止/重载永远失败。另外 reload 发的 `posix_kill($pid, 1)` 是 SIGHUP，
 * 而 Daemon 没装 HUP 处理器 —— 那是按系统默认处置**直接终止**，不是重载。
 *
 * 「覆盖 pid 文件」这件事自 kode/process v5.5.0 起已经被堵住了（`Daemon` 只认领不存在/空/
 * 非数字/已死/是自己的文件，别人占着就抛异常拒绝启动；v5.5.1 起「判归属 + 落盘」还罩在同一把
 * 非阻塞 flock 里，所以**同时**敲两次 `process:start` 也只活一套），framework v1.13.0
 * 的 `start()` 也会在派发前拦一次。但那两道都拦在**被 detach 出去的子进程**里：本控制器
 * `exec()` 之后立刻返回，子进程的拒绝只落在 storage/logs/daemon.log，运维在页面上看不到。
 * 所以这里的预检不是重复实现，它是唯一能给出发回 400 的那一次判定
 * （它自己也挡不住并发：两个请求可以同时看到「全没跑」，那时兜底的是 process 侧那把锁）。
 *
 * 因此本控制器仍然不表达「重启」：一次请求内既不能等旧进程退出（AGENTS.md §5 禁止请求内
 * sleep / 文件轮询），也不能不等就派发 —— stop 发的是 SIGTERM，守护进程要停完 worker、
 * 回收子进程、删掉 pid 文件才退出，紧接着派发的第二代只会撞上「pid 文件被存活进程占用」。
 * 改代码请点「平滑重载」（USR1 = Daemon 重启该路全部 worker），
 * 换 worker 注册表请「停止 → 状态表归零 → 启动」两步。
 */
final class ProcessController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/process/list — 已注册 Worker 及其**生效**声明。
     *
     * 清单只从注册表读，不在控制器里把 config('process.workers') 再解析一遍：
     * 那样类上覆写的 interval()/instances() 全是空的（配置没写声明键就报 null），
     * 面板显示的那份和真正 fork 出来的那份根本不是一回事。
     */
    public function list()
    {
        $manager = $this->manager();

        $running = [];
        foreach ($manager->slotStates() as $row) {
            if ($row['alive']) {
                $running[$row['name']][] = $row['slot'];
            }
        }

        $workers = [];
        foreach ($manager->workers() as $worker) {
            $workers[] = [
                'name' => $worker->name(),
                'instances' => $worker->instances(),
                'interval' => $worker->interval(),
                'slots' => array_values($worker->slots()),
                'once' => $worker->once(),
                'running_slots' => $running[$worker->name()] ?? [],
            ];
        }

        return $this->ok([
            'workers' => $workers,
            'total' => count($workers),
        ]);
    }

    /**
     * GET /api/process/status — 逐槽位的运行状态。
     *
     * 每行对应一路真正会被启动的守护进程（一次性 worker 不在列：它们启动即退出、
     * 从不写 pid 文件）。`start_time`/`uptime` 取 pid 文件的 mtime —— 这是不依赖
     * `/proc` 的跨平台口径；旧的 `/proc/{pid}/stat` 第 22 字段算法在 darwin 上
     * 什么都读不到（面板显示 —），在 Linux 上算出来的还是**宿主机**开机时长。
     */
    public function status()
    {
        $slots = [];
        $aliveCount = 0;

        foreach ($this->manager()->slotStates() as $row) {
            if ($row['alive']) {
                $aliveCount++;
            }

            $slots[] = [
                'name' => $row['name'],
                'slot' => $row['slot'],
                'label' => $row['name'] . ':' . $row['slot'],
                'pid' => $row['pid'],
                'alive' => $row['alive'],
                'pid_file' => $row['pid_file'],
                'start_time' => $row['started_at'] === null ? null : date('Y-m-d H:i:s', $row['started_at']),
                'uptime' => $row['started_at'] === null ? 0 : max(0, time() - $row['started_at']),
            ];
        }

        return $this->ok([
            'running' => $aliveCount > 0,
            'alive_count' => $aliveCount,
            'slot_count' => count($slots),
            'slots' => $slots,
        ]);
    }

    /**
     * POST /api/process/start — 后台拉起常驻进程（只派发，不等它证明自己活着）。
     *
     * 有槽位在跑时必须拒绝：`kode process:start` 现在自己也会拒（kode/process v5.5.1
     * 的 pid 文件 flock 互斥 + framework v1.13.0 的 start() 预检），但那是**被 detach 出去的子进程**
     * 里的拒绝，原因只落进 storage/logs/daemon.log。这一条预检是唯一能让运维在当前请求里
     * 看到 400 的判定，缺了它就是「点了没反应，第二代根本没起来」。
     */
    public function start()
    {
        $manager = $this->manager();

        $running = [];
        foreach ($manager->slotStates() as $row) {
            if ($row['alive']) {
                $running[] = $row['name'] . ':' . $row['slot'];
            }
        }
        if ($running !== []) {
            return $this->fail(
                '常驻进程已在运行：' . implode('、', $running)
                . '。重复派发会叠加出查不到的守护进程，请先「停止」并等状态表归零。',
                400
            );
        }

        [$command, $logFile] = $this->startCommand();
        exec($command);

        SysLog::info('process', '已派发常驻进程启动', [
            'command' => $command,
            'slots' => array_column($manager->residentSlots(), 'pid_file'),
        ]);

        return $this->ok([
            'dispatched' => true,
            'slots' => array_map(
                static fn (array $row): string => $row['name'] . ':' . $row['slot'],
                $manager->residentSlots()
            ),
            'log_file' => $logFile,
        ]);
    }

    /**
     * POST /api/process/stop — 优雅停止（SIGTERM，Daemon 清理子进程后退出）。
     */
    public function stop()
    {
        return $this->signalAll(Signal::TERM, 'SIGTERM', '停止');
    }

    /**
     * POST /api/process/reload — 平滑重载（SIGUSR1 = Daemon 重启该路全部 worker）。
     *
     * 绝不是 SIGHUP：那个信号没有安装处理器，默认处置是终止整个守护进程。
     */
    public function reload()
    {
        return $this->signalAll(Signal::USR1, 'SIGUSR1', '平滑重载');
    }

    // ---- 内部方法 ----

    /**
     * 向全部存活的常驻槽位发信号，并如实回报发给了谁、跳过了谁、谁失败。
     *
     * 三份清单的标识与状态表同源（`name:slot`），运维能把「发给了哪几路」和
     * 页面上那几行逐条对上。一路都没发出去就不回成功 —— 理由是旧的「未运行」
     * 判据（对着不存在的文件）会说假话，现在点名到槽位。
     *
     * @param int    $signo  Kode\Process\Signal 常量（跨平台，别写数字）
     * @param string $name   出参里给运维看的信号名
     * @param string $verb   动作的中文，用于失败文案
     */
    private function signalAll(int $signo, string $name, string $verb)
    {
        $result = $this->manager()->signalSlots($signo);

        if ($result['signalled'] === []) {
            $reasons = array_column($result['skipped'], 'label');
            foreach ($result['failed'] as $row) {
                $reasons[] = $row['label'] . '（' . $row['reason'] . '）';
            }

            return $this->fail('没有' . $verb . '成功的常驻槽位：' . implode('、', $reasons), 400);
        }

        SysLog::info('process', '常驻进程已发送 ' . $name, $result);

        return $this->ok(['signal' => $name] + $result);
    }

    /**
     * 拉起常驻进程的命令行。
     *
     * 必须 detach（`kode process:start` 是监督循环，永不返回），输出追加进
     * storage/logs/ —— 请求里既不读它也不等它。
     *
     * @return array{0: string, 1: string} [命令, 日志文件]
     */
    private function startCommand(): array
    {
        $logFile = storage_path('logs/daemon.log');

        $command = sprintf(
            '%s %s process:start >> %s 2>&1 &',
            escapeshellarg((string) env('PHP_BINARY', PHP_BINARY)),
            escapeshellarg(base_path('kode')),
            escapeshellarg($logFile)
        );

        return [$command, $logFile];
    }

    private function manager(): ProcessManager
    {
        return resolve(ProcessManager::class);
    }
}
