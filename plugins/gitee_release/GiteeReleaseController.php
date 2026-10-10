<?php

declare(strict_types=1);

namespace Kode\Plugins\gitee_release;

use app\concerns\ApiResponse;
use app\concerns\PageWindow;
use Kode\Database\Db\Db;
use Kode\Framework\Http\Controller;
use Kode\Http\Request;

/**
 * GiteeRelease 控制器：gitee 私有仓库前端资产管理。
 *
 * 路由前缀：/api/gitee-release/*
 *
 * 核心能力：
 *  - 仓库 CRUD（repo + token + branch + install_path）
 *  - 从 gitee API 查询 releases
 *  - 下载 release assets 并 SHA256 校验
 *  - 解压安装到 public/{install_path}/v{version}/
 *  - 版本检查 + 已安装版本列表
 */
final class GiteeReleaseController extends Controller
{
    use ApiResponse;
    use PageWindow;

    /** 默认安装目录（相对 public/）：仓库未配置 install_path 时回落于此。 */
    private const DEFAULT_INSTALL_PATH = 'assets/lowcode-shell';

    /** 单个资产下载上限：整包边下边算，超出立刻断线（对齐 RemoteFetch 口径）。 */
    private const MAX_ASSET_BYTES = 100 * 1024 * 1024;

    /** 归档扩展名：只有这三种交给 SafeArchive，别的（裸 .exe/.bin）不再当「单文件」装。 */
    private const ARCHIVE_EXT = ['.zip', '.tar', '.tar.gz', '.tgz'];

    /**
     * 落进 public/ 的静态扩展名白名单。
     *
     * 解包目标在 web 根，黑名单（挡 .php）永远少一条：.phtml/.phar/.php5/.shtml/
     * .cgi/.pl/.py/.so 各自的后端开关按部署而变。白名单反过来——列出的才落盘。
     */
    private const STATIC_EXT = [
        'html', 'htm', 'js', 'mjs', 'css', 'json', 'map', 'txt', 'xml', 'ico',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif',
        'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'wasm',
    ];

    /**
     * 归档/单文件条目过滤器：返回 false 表示「本插件不收」（SafeArchive 跳过），
     * 抛错表示「这个包有恶意/不合规条目，整包中止」。
     *
     * 必须 public：`SafeArchive::extract()` 的第 3 参声明为 `?callable`，把本类的
     * private 静态方法作为数组回调传出去会直接 TypeError（可见性按被调函数处判定），
     * 装一次就炸一次。以下四个策略函数同理，且都是纯函数——测试据此直接判，不碰网络。
     */
    public static function assertStaticEntry(string $rel): bool
    {
        if (preg_match('#(^|/)\.#', $rel) === 1) {
            throw new \RuntimeException('包内含隐藏文件条目：' . $rel);
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, self::STATIC_EXT, true)) {
            throw new \RuntimeException('包内含允许静态资产之外的条目：' . $rel);
        }

        return true;
    }

    /**
     * tag/版本名 → 可入路径的形态。
     *
     * `$version` 会拼进 `public/{install_path}/v{version}`，不校验就等于把落点
     * 交给远端 tag：`..%2f` 之类能翻出目标目录。白名单 + 禁 `..`。
     */
    public static function safeVersion(string $version): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]{0,63}$/', $version) === 1
            && !str_contains($version, '..');
    }

    /**
     * `owner/repo` → 可拼进 gitee API 路径的形态。
     *
     * 三条 gitee 请求的 URL 都是 `https://gitee.com/api/v5/repos/{repo}...`，主机恒定，
     * 唯一由用户给的就是这一段路径。老写法只挡形状不挡 `..`：`owner/..` 能过正则，
     * 拼出 `/api/v5/repos/owner/../releases`，服务器按 RFC 归一后打到别的 API 路径上，
     * 而这一发是带着用户 PRIVATE-TOKEN 出去的。禁 `..` 之后残留最多是一个 404。
     */
    public static function safeRepo(string $repo): bool
    {
        return preg_match('#^[a-zA-Z0-9_-]+/[a-zA-Z0-9_.-]+$#', $repo) === 1
            && !str_contains($repo, '..');
    }

    /**
     * 安装目录（相对 public/）→ 归一后的安全路径，非法返回 null。
     *
     * 写侧（新建/改仓库）和读侧（安装时）都要过一遍：读侧不挡的话，一条历史脏数据
     * （绝对路径或 `../`）就让后续每次安装都往项目外写文件。
     */
    public static function safeInstallPath(string $path): ?string
    {
        $rel = trim(trim($path), '/');
        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) {
            return null;
        }
        // 大小写保留：install_path 直接拼进访问 URL，抹平大小写会把已分发的前端链接指没。
        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._\-]*(/[A-Za-z0-9][A-Za-z0-9._\-]*)*$#', $rel) !== 1) {
            return null;
        }

        return $rel;
    }

    /**
     * 出参 id 混淆：内部自增 id → 对外 HashId 不透明串（AGENTS.md §4 防枚举）。
     * 入参一律用 eid()/HashId::dec 解码，repoToken() 等内部调用保持 int；
     * 值为 null 的字段保持 null。
     */
    private static function hashOut(array $row, array $fields = ['id']): array
    {
        foreach ($fields as $f) {
            if (array_key_exists($f, $row) && $row[$f] !== null) {
                $row[$f] = \app\support\HashId::enc((int) $row[$f]);
            }
        }

        return $row;
    }

    /* ===================== 用户上下文 ===================== */

    private function owner(): string
    {
        $payload = Request::attr('auth');
        return (string) ($payload->username ?? ($payload->uid ?? ''));
    }

    private function projectRoot(): string
    {
        return defined('KODE_PROJECT_ROOT') ? KODE_PROJECT_ROOT : dirname(__DIR__, 2);
    }

    /* ===================== 仓库 CRUD ===================== */

    /**
     * GET /api/gitee-release/repos
     * 仓库配置列表（分页）。
     *
     * 此前是「整张表 SELECT 进内存 + `total = count($items)`」，而且**逐行**再发一枚
     * `SELECT token FROM kode_gitee_releases WHERE id = ?` 只为判「有没有配令牌」：
     * N 行 = 1 + N 条查询，而这一列本可以跟着主查询一起回来、在响应边界摘掉
     * （口径同 §4：`pageWindow()` + 同一份条件的 `COUNT(*)` + `$this->page()`）。
     */
    public function reposIndex()
    {
        $win = $this->pageWindow($this->query('page'), $this->query('size'));
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];

        $total = (int) (Db::select('SELECT COUNT(*) AS cnt FROM kode_gitee_releases')[0]['cnt'] ?? 0);
        $items = Db::select(
            "SELECT id, repo, branch, name, description, install_path, status, last_version, last_checked_at, created_at, updated_at, token
             FROM kode_gitee_releases ORDER BY id DESC LIMIT ? OFFSET ?",
            [$size, $offset]
        );
        // 掩码 token（只出「有没有配」，永不出原文）：跟着主查询一次带回，
        // 在对外边界（hashOut 混淆 id）之前先把明文那一列摘掉。
        // 判据用 `!== ''` 而不是 `(bool)`：与 repoStore 自己回显的那一条同源，
        // 也让一枚字面量 `"0"` 的令牌不被报成「没配」。
        foreach ($items as &$item) {
            $item['has_token'] = $item['token'] !== '';
            unset($item['token']);
            $item['last_checked_at'] = $item['last_checked_at'] ?? null;
            $item = self::hashOut($item, ['id']);
        }
        unset($item);

        return $this->page($items, $total, $page, $size);
    }

    /**
     * POST /api/gitee-release/repos
     * 创建仓库配置。
     * Body: { repo, token?, branch?, name?, description?, install_path? }
     */
    public function repoStore()
    {
        $repo = trim((string) Request::input('repo', ''));
        if ($repo === '') {
            return $this->error('repo 不能为空');
        }
        if (!self::safeRepo($repo)) {
            return $this->error('repo 格式错误，应为 owner/repo（每段仅限字母数字与 _ . -，禁 `..`）');
        }

        $token = trim((string) Request::input('token', ''));
        $branch = trim((string) Request::input('branch', 'master'));
        $name = trim((string) Request::input('name', ''));
        $description = trim((string) Request::input('description', ''));
        $installPath = self::safeInstallPath(
            trim((string) Request::input('install_path', 'assets/lowcode-shell'))
        );
        if ($installPath === null) {
            return $this->error('install_path 只能是相对 public/ 的普通路径（禁绝对路径、`..`、特殊字符）');
        }

        // 检查是否已存在
        $exists = Db::select("SELECT id FROM kode_gitee_releases WHERE repo = ?", [$repo]);
        if (!empty($exists)) {
            return $this->error('仓库已存在');
        }

        $id = Db::insert(
            "INSERT INTO kode_gitee_releases (repo, token, branch, name, description, install_path, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$repo, $token, $branch, $name, $description, $installPath, $this->owner()]
        );

        $item = Db::select("SELECT id, repo, branch, name, description, install_path, status, last_version, created_at FROM kode_gitee_releases WHERE id = ?", [(int) $id])[0];
        $item['has_token'] = $token !== '';

        return $this->ok(self::hashOut($item, ['id']), 201);
    }

    /**
     * PUT /api/gitee-release/repos/{id}
     * 更新仓库配置。
     */
    public function repoUpdate()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('仓库不存在', 404);
        }
        $existing = Db::select("SELECT * FROM kode_gitee_releases WHERE id = ?", [$id]);
        if (empty($existing)) {
            return $this->error('仓库不存在', 404);
        }
        $existing = $existing[0];

        $updates = [];
        $bindings = [];

        $fields = ['branch', 'name', 'description', 'install_path'];
        foreach ($fields as $f) {
            $val = Request::input($f, null);
            if ($val === null) {
                continue;
            }
            if ($f === 'install_path') {
                // 与 repoStore 同口径：安装路径决定文件落点，脏值一旦入库，
                // 之后每次 install 都往那里写。空串按「改回默认」处理。
                $val = self::safeInstallPath((string) $val) ?? self::DEFAULT_INSTALL_PATH;
            }
            $updates[] = "{$f} = ?";
            $bindings[] = trim((string) $val);
        }

        // token：空字符串表示不变，非空表示更新
        $token = Request::input('token', null);
        if ($token !== null && $token !== '') {
            $updates[] = "token = ?";
            $bindings[] = trim((string) $token);
        }

        // status
        $status = Request::input('status', null);
        if ($status !== null) {
            $updates[] = "status = ?";
            $bindings[] = (int) $status;
        }

        if (empty($updates)) {
            return $this->error('无更新字段');
        }

        $updates[] = "updated_at = NOW()";
        $bindings[] = $id;

        Db::update(
            "UPDATE kode_gitee_releases SET " . implode(', ', $updates) . " WHERE id = ?",
            $bindings
        );

        $item = Db::select("SELECT id, repo, branch, name, description, install_path, status, last_version, created_at, updated_at FROM kode_gitee_releases WHERE id = ?", [$id])[0];
        // 「配没配凭证」的判据全插件只有一份口径：空串 = 未配置（`(bool) '0'` 是 false，
        // 会把一枚字面量为 "0" 的令牌报成没配，与列表/写入两处自相矛盾）。
        $item['has_token'] = $this->repoToken($id) !== '';

        return $this->ok(self::hashOut($item, ['id']));
    }

    /**
     * DELETE /api/gitee-release/repos/{id}
     * 删除仓库配置。
     */
    public function repoDestroy()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('仓库不存在', 404);
        }
        // 两步删（主表 + 下载记录）包在同一事务：只跑第一步就失败会留下「repo 已删但下载记录
        // 还挂着旧 repo_id」的孤儿行——`kode_gitee_downloads` 没有 FK（见 GiteeReleasePlugin::install），
        // 从业务侧无从定位。同 app/admin 的 role/menu/dict 与 builder/projects 的修复。
        Db::transaction(function () use ($id): void {
            Db::delete("DELETE FROM kode_gitee_releases WHERE id = ?", [$id]);
            // 级联删除下载记录
            Db::delete("DELETE FROM kode_gitee_downloads WHERE repo_id = ?", [$id]);
        });
        // 回显传入的对外 hashid 串，不回内部 int
        return $this->ok(['deleted' => $this->param('id')]);
    }

    /* ===================== Release 管理 ===================== */

    /**
     * GET /api/gitee-release/repos/{id}/releases
     * 从 gitee API 查询 release 列表。
     */
    public function releasesList()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('仓库不存在', 404);
        }
        $repo = $this->repoRow($id);
        if (!$repo) {
            return $this->error('仓库不存在', 404);
        }

        $token = $this->repoToken($id);
        $read = $this->fetchGiteeReleases($repo['repo'], $token, (int) Request::input('limit', 10));

        // available/reason 与清单同级，清单落在业务键 `releases` 上（读不到时为空数组）：
        // 出参形状不随成败变，但「没读到」必须能被念出来（AGENTS §6/§7）。
        // 键名不叫 `data` —— 那会在信封的 data 里再套一层同名包裹。
        return $this->ok([
            'releases' => $read['data'],
            'repo' => $repo['repo'],
            'available' => $read['ok'],
            'reason' => $read['reason'],
        ]);
    }

    /**
     * GET /api/gitee-release/repos/{id}/check-updates
     * 检查最新版本（对比本地 last_version）。
     */
    public function checkUpdates()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('仓库不存在', 404);
        }
        $repo = $this->repoRow($id);
        if (!$repo) {
            return $this->error('仓库不存在', 404);
        }

        $token = $this->repoToken($id);
        $read = $this->fetchGiteeReleases($repo['repo'], $token, 1);

        // 「没读到」不能落成 has_update: false —— 那会被念成「已是最新版本」，
        // 而这一句恰好在令牌失效时最误导人。读不到时相关键一律 null（同 §3 的聚合缺键口径）。
        $latest = $read['ok'] ? ($read['data'][0] ?? null) : null;
        $latestVersion = $latest === null ? '' : (string) ($latest['tag_name'] ?? '');
        $hasUpdate = $latestVersion !== '' && $latestVersion !== $repo['last_version'];

        // 更新 last_checked_at（试过就叫 checked，与有没有读到无关）
        Db::update("UPDATE kode_gitee_releases SET last_checked_at = NOW() WHERE id = ?", [$id]);

        return $this->ok([
            'available' => $read['ok'],
            'reason' => $read['reason'],
            'latest_version' => $read['ok'] ? $latestVersion : null,
            'current_version' => $repo['last_version'],
            'has_update' => $read['ok'] ? $hasUpdate : null,
            'latest_release' => $latest,
        ]);
    }

    /* ===================== 下载 + 安装 ===================== */

    /**
     * POST /api/gitee-release/repos/{id}/install
     * 下载并安装指定版本的 release asset。
     * Body: { version, asset_name?, expected_sha256? }
     */
    public function installRelease()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('仓库不存在', 404);
        }
        $repo = $this->repoRow($id);
        if (!$repo) {
            return $this->error('仓库不存在', 404);
        }

        $token = $this->repoToken($id);
        $version = trim((string) Request::input('version', ''));
        if ($version === '') {
            return $this->error('version 不能为空');
        }
        if (!self::safeVersion($version)) {
            return $this->error('version 只能由字母数字与 . _ - 组成（不能含路径分隔或 `..`）');
        }

        // 落点在下载之前定死：脏 install_path（绝对路径或 `..`）会把包解到项目外，
        // 而 public/ 是 web 根，落出去就是任意文件写。空值才回落默认，非法值拒绝。
        $rawPath = trim((string) ($repo['install_path'] ?? ''));
        $installPath = $rawPath === '' ? self::DEFAULT_INSTALL_PATH : self::safeInstallPath($rawPath);
        if ($installPath === null) {
            return $this->error('该仓库的 install_path 非法（只允许相对 public/ 的普通路径），请先修正仓库配置');
        }

        $expectedSha256 = trim((string) Request::input('expected_sha256', ''));
        $assetName = trim((string) Request::input('asset_name', ''));

        // 1) 从 gitee API 获取 release 信息
        $read = $this->fetchGiteeReleaseByTag($repo['repo'], $version, $token);
        if (!$read['ok']) {
            // 旧写法把任何失败都念成「未找到 tag=…」，于是令牌失效被报成版本不存在
            return $this->error($read['reason']);
        }
        $release = $read['data'];

        // 2) 选择 asset
        $assets = $release['assets'] ?? [];
        $targetAsset = null;
        if ($assetName !== '') {
            foreach ($assets as $a) {
                if (($a['name'] ?? '') === $assetName) {
                    $targetAsset = $a;
                    break;
                }
            }
        }
        if (!$targetAsset && !empty($assets)) {
            // 默认选第一个归档包
            foreach ($assets as $a) {
                if (self::archiveExtOf((string) ($a['name'] ?? '')) !== null) {
                    $targetAsset = $a;
                    break;
                }
            }
        }
        if (!$targetAsset) {
            return $this->error('该 release 无可用下载资产');
        }

        $downloadUrl = trim((string) ($targetAsset['browser_download_url'] ?? ''));
        $assetLabel = (string) ($targetAsset['name'] ?? 'unknown');
        // 只认归档包：以前「拿第一个资产、不是归档就整文件搬进 web 根」会把 .exe/.bin
        // 之类的东西原样落到 public/ 下，成为可被直接访问的意外产物。
        $ext = self::archiveExtOf($assetLabel);
        if ($ext === null) {
            return $this->error('资产不是 zip/tar/tar.gz 归档，无法安全安装');
        }

        // 3) 创建下载记录（先落 running：失败也要有迹可循）
        $stmt = Db::prepare(
            "INSERT INTO kode_gitee_downloads (repo_id, version, asset_name, download_url, status)
             VALUES (?, ?, ?, ?, 'running')"
        );
        $stmt->execute([$id, $version, mb_substr($assetLabel, 0, 128), $downloadUrl]);
        $downloadId = (int) Db::lastInsertId();

        // 临时名不给远端字符串留位置：资产名里塞 `../` 就能把下载写到别处，
        // 而 SafeArchive 按扩展名分派，后缀必须由本函数决定。
        $tmpFile = sys_get_temp_dir() . "/gitee_dl_{$downloadId}_" . bin2hex(random_bytes(4)) . $ext;
        $markFailed = static function (string $reason) use ($downloadId, $tmpFile): void {
            Db::update(
                "UPDATE kode_gitee_downloads SET status='failed', error=?, finished_at=NOW() WHERE id=?",
                [mb_substr($reason, 0, 500), $downloadId]
            );
            @unlink($tmpFile);
        };

        // 4) 下载（SSRF 逐跳过闸 + 钉 IP + 边下边算体积，见 RemoteFetch）
        $fetched = \app\support\RemoteFetch::toFile($downloadUrl, $tmpFile, self::MAX_ASSET_BYTES, 120);
        if ($fetched['ok'] !== true) {
            $reason = (string) ($fetched['msg'] ?? '下载失败');
            $markFailed($reason);

            return $this->error('下载失败：' . $reason);
        }

        // 5) SHA256 校验
        $actualSha256 = (string) hash_file('sha256', $tmpFile);
        if ($expectedSha256 !== '' && !hash_equals(strtolower($expectedSha256), $actualSha256)) {
            $markFailed('SHA256 不匹配');

            return $this->error("SHA256 校验失败（期望 {$expectedSha256}，实际 {$actualSha256}）");
        }

        // 6) 解压安装
        $projectRoot = $this->projectRoot();
        $targetDir = $projectRoot . '/public/' . $installPath . '/v' . $version;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $markFailed('目标目录创建失败：' . $installPath);

            return $this->error('安装失败：目标目录不可用');
        }
        @chmod($targetDir, 0775);

        try {
            // 同版本重装是幂等修复动作（目录本就归这个版本），故允许覆盖；
            // 但每个条目都要过静态白名单——落点在整个包的顶端，
            // `../` 条目和符号链接在这里同样致命。
            $files = \app\support\SafeArchive::extract(
                $tmpFile,
                $targetDir,
                [self::class, 'assertStaticEntry'],
                false
            );
        } catch (\Throwable $e) {
            $markFailed('解压失败：' . $e->getMessage());
            @unlink($tmpFile);

            return $this->error('解压失败：' . $e->getMessage());
        }
        @unlink($tmpFile);
        // 零条目 = 「安装成功」的空壳：PharData 对真实 tar 恒迭代 0 条，
        // 过去就是这么把「什么都没装」报成成功的。空包必须算失败。
        if ($files === 0) {
            $markFailed('包内无可安装条目');

            return $this->error('安装失败：包内没有允许的静态资产条目');
        }

        // 7) 更新下载记录 + 仓库版本（两步写同一个事务：只落一步就是
        //    「下载说成功了，仓库页面上的版本还是旧的」这种没人能从出参看出来的半状态）
        Db::transaction(function () use ($downloadId, $actualSha256, $expectedSha256, $installPath, $version, $id): void {
            Db::update(
                "UPDATE kode_gitee_downloads SET status='succeeded', sha256=?, expected_sha256=?, installed_path=?, finished_at=NOW() WHERE id=?",
                [$actualSha256, $expectedSha256, $installPath . '/v' . $version, $downloadId]
            );
            Db::update(
                "UPDATE kode_gitee_releases SET last_version=?, last_checked_at=NOW(), updated_at=NOW() WHERE id=?",
                [$version, $id]
            );
        });

        return $this->ok([
            // 内部自增下载记录 id 对外混淆
            'download_id' => \app\support\HashId::enc($downloadId),
            'version' => $version,
            'asset' => $assetLabel,
            'files' => $files,
            'sha256' => $actualSha256,
            'installed_path' => $installPath . '/v' . $version,
        ]);
    }

    /**
     * POST /api/gitee-release/repos/{id}/test
     * 测试 gitee 连接（验证 token + repo 可访问）。
     */
    public function testConnection()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('仓库不存在', 404);
        }
        $repo = $this->repoRow($id);
        if (!$repo) {
            return $this->error('仓库不存在', 404);
        }

        // 读侧兜底：写侧已按 safeRepo 拦了，这里挡的是库里的历史脏数据。诊断接口要给出
        // 人能看懂的原因，不能只回一句「连不上」。
        if (!self::safeRepo((string) $repo['repo'])) {
            return $this->error('该仓库的 repo 非法（应为 owner/repo 且不含 `..`），请先修正仓库配置');
        }

        $token = $this->repoToken($id);
        $url = "https://gitee.com/api/v5/repos/{$repo['repo']}";
        $headers = [];
        if ($token !== '') {
            $headers[] = "PRIVATE-TOKEN: {$token}";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return $this->error("请求失败: {$curlErr}");
        }

        $data = json_decode($body, true);
        $ok = ($httpCode === 200) && isset($data['id']);

        if ($ok) {
            return $this->ok([
                'ok' => true,
                'repo_id' => $data['id'],
                'full_name' => $data['full_name'] ?? $repo['repo'],
                'description' => $data['description'] ?? '',
                'default_branch' => $data['default_branch'] ?? '',
                'message' => '连接成功',
            ]);
        }

        return $this->error("连接失败 (HTTP {$httpCode}): " . ($data['message'] ?? $curlErr ?? '未知错误'));
    }

    /* ===================== 下载记录 ===================== */

    /**
     * GET /api/gitee-release/repos/{id}/downloads
     * 下载记录列表（分页）。
     *
     * `kode_gitee_downloads` 是只增不减的流水（每次安装落一行）：此前一句 `LIMIT` 都没有，
     * 响应体随安装次数线性长大，而 `total` 回的是「这一批有几条」。
     */
    public function downloadsList()
    {
        $id = $this->eid();
        if ($id === null) {
            return $this->fail('仓库不存在', 404);
        }
        $win = $this->pageWindow($this->query('page'), $this->query('size'));
        if ($win['error'] !== null) {
            return $this->fail($win['error'], 400);
        }
        [$page, $size, $offset] = [$win['page'], $win['size'], $win['offset']];

        $total = (int) (Db::select(
            'SELECT COUNT(*) AS cnt FROM kode_gitee_downloads WHERE repo_id = ?',
            [$id]
        )[0]['cnt'] ?? 0);
        $items = Db::select(
            "SELECT d.*, r.repo
             FROM kode_gitee_downloads d
             JOIN kode_gitee_releases r ON d.repo_id = r.id
             WHERE d.repo_id = ?
             ORDER BY d.id DESC LIMIT ? OFFSET ?",
            [$id, $size, $offset]
        );
        $items = array_map(static fn (array $row): array => self::hashOut($row, ['id', 'repo_id']), $items);

        return $this->page($items, $total, $page, $size);
    }

    /* ===================== 已安装版本 ===================== */

    /**
     * GET /api/gitee-release/versions
     * 扫描 public/ 下已安装的前端资产版本。
     */
    public function installedVersions()
    {
        $projectRoot = $this->projectRoot();
        $versions = [];

        // 扫描所有已配置的仓库的 install_path
        $repos = Db::select("SELECT id, repo, install_path FROM kode_gitee_releases WHERE status = 1");
        foreach ($repos as $repo) {
            $raw = trim((string) ($repo['install_path'] ?? ''));
            $installPath = $raw === '' ? self::DEFAULT_INSTALL_PATH : self::safeInstallPath($raw);
            if ($installPath === null) {
                continue; // 脏配置不参与扫描：这页只读，跳过比跟着拼路径稳妥
            }
            $baseDir = $projectRoot . '/public/' . $installPath;
            if (!is_dir($baseDir)) {
                continue;
            }
            $subDirs = glob($baseDir . '/v*', GLOB_ONLYDIR);
            if ($subDirs) {
                foreach ($subDirs as $dir) {
                    // 目录名 = 'v' + tag（安装侧口径），所以只剥掉我们自己加的那一个 v。
                    // 早先 basename($dir,'') 压根不去前缀，回给前端的 version 带 v、
                    // path 又拼成 `.../vv1.2.3`，点进去是 404。
                    $dirName = basename($dir);
                    $version = str_starts_with($dirName, 'v') ? substr($dirName, 1) : $dirName;
                    if (!self::safeVersion($version)) {
                        continue;
                    }
                    $indexHtml = $dir . '/index.html';
                    $hasIndex = is_file($indexHtml);
                    $versions[] = [
                        // 内部自增 repo id 对外混淆
                        'repo_id' => \app\support\HashId::enc((int) $repo['id']),
                        'repo' => $repo['repo'],
                        'version' => $version,
                        'path' => $installPath . '/v' . $version,
                        'has_index' => $hasIndex,
                        'modified_at' => date('Y-m-d H:i:s', filemtime($dir)),
                    ];
                }
            }
        }

        return $this->ok($versions);
    }

    /* ===================== 内部方法 ===================== */

    private function repoRow(int $id): ?array
    {
        $rows = Db::select("SELECT * FROM kode_gitee_releases WHERE id = ?", [$id]);
        return $rows[0] ?? null;
    }

    private function repoToken(int $id): string
    {
        $rows = Db::select("SELECT token FROM kode_gitee_releases WHERE id = ?", [$id]);
        return $rows[0]['token'] ?? '';
    }

    /**
     * 从 gitee API 获取 releases 列表。回 verdict 形状（ok / reason / data）。
     */
    private function fetchGiteeReleases(string $repo, string $token, int $limit): array
    {
        $subject = "仓库 {$repo} 的 release 列表";
        // 这一段拼进 URL 路径，脏值（`owner/..`）会把 PRIVATE-TOKEN 带到别的 API 路径上
        if (!self::safeRepo($repo)) {
            return self::giteeUnreadable($subject . '：仓库地址非法（owner/repo 形状不对），未发起请求');
        }

        $url = "https://gitee.com/api/v5/repos/{$repo}/releases?per_page={$limit}";
        $got = $this->giteeGet($url, $token);

        return self::giteeListVerdict($got['status'], $got['body'], $subject);
    }

    /**
     * 从 gitee API 获取指定 tag 的 release。回 verdict 形状（ok / reason / data）。
     */
    private function fetchGiteeReleaseByTag(string $repo, string $tag, string $token): array
    {
        $subject = "tag={$tag} 的 release";
        // repo 与 tag 都是拼进路径的片段：`?`/`#`/`/` 一旦混进来就换了一段 API 路径，
        // 而这一发带着用户的 PRIVATE-TOKEN
        if (!self::safeRepo($repo) || !self::safeVersion($tag)) {
            return self::giteeUnreadable($subject . '：仓库或 tag 形状非法，未发起请求');
        }

        $url = "https://gitee.com/api/v5/repos/{$repo}/releases/tags/{$tag}";
        $got = $this->giteeGet($url, $token);

        return self::giteeReleaseVerdict($got['status'], $got['body'], $subject);
    }

    /**
     * 一发 gitee GET：只负责「拿到状态码 + 正文」，结论由 verdict 下。
     *
     * 状态码 0 = 传输层失败（连不上/超时），与 HTTP 0 不是一回事；curl 报错时
     * `CURLINFO_HTTP_CODE` 本来就是 0，这里显式按 `curl_errno` 归一次，
     * 免得「一个字节都没收到」被下游读成「对方回了个空列表」。
     *
     * @return array{status: int, body: string}
     */
    private function giteeGet(string $url, string $token): array
    {
        $headers = [];
        if ($token !== '') {
            $headers[] = "PRIVATE-TOKEN: {$token}";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $failed = curl_errno($ch) !== 0;
        curl_close($ch);

        return [
            'status' => ($failed || $body === false) ? 0 : $status,
            'body' => is_string($body) ? $body : '',
        ];
    }

    /* ===================== gitee 读侧判定（纯函数，可离线断言） ===================== */

    /**
     * 「一个 release 都没有」与「没读到」的唯一分岔口（列表形状）。
     *
     * @return array{ok: bool, reason: string, data: array}
     */
    public static function giteeListVerdict(int $status, string $body, string $subject): array
    {
        $failure = self::giteeReadFailure($status, $subject);
        if ($failure !== null) {
            return self::giteeUnreadable($failure);
        }
        if ($status === 404) {
            return self::giteeUnreadable("没读到{$subject}：gitee 上说这个仓库不存在，或对这枚令牌不可见（HTTP 404）");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            // 挡住最坏的一支：gitee 的错误回包是 JSON 对象，解码后同样是「数组」，
            // 旧写法 `is_array($data) ? $data : []` 会把 {"message":…} 当列表交出去。
            return self::giteeUnreadable("没读到{$subject}：gitee 的回包不是列表（HTTP {$status}）");
        }

        return ['ok' => true, 'reason' => '', 'data' => $decoded];
    }

    /**
     * 单个 release 形状的判定。
     *
     * @return array{ok: bool, reason: string, data: array}
     */
    public static function giteeReleaseVerdict(int $status, string $body, string $subject): array
    {
        $failure = self::giteeReadFailure($status, $subject);
        if ($failure !== null) {
            return self::giteeUnreadable($failure);
        }
        if ($status === 404) {
            // 404 在 gitee 上同时是「仓库不存在/令牌看不见它」，只报「这个 tag 没发布」是半个假因
            return self::giteeUnreadable(
                "gitee 上没有{$subject}（HTTP 404：该 tag 未发布，或仓库本身不存在/对这枚令牌不可见）"
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            return self::giteeUnreadable("没读到{$subject}：gitee 的回包不是 release 对象（HTTP {$status}）");
        }

        return ['ok' => true, 'reason' => '', 'data' => $decoded];
    }

    /**
     * 与形状无关的那半判定（连接层 + 鉴权 + 服务端错误），两个 verdict 共用一份。
     * 回 null = 这一发可以按正文形状继续判。
     */
    private static function giteeReadFailure(int $status, string $subject): ?string
    {
        if ($status === 0) {
            return "没读到{$subject}：无法连接 gitee（连接失败或超时）";
        }
        if ($status === 401) {
            return "没读到{$subject}：私有令牌无效或已被收回（HTTP 401）";
        }
        if ($status === 403) {
            return "没读到{$subject}：gitee 拒绝访问（HTTP 403，令牌权限不足或被限流）";
        }
        if ($status >= 400 && $status !== 404) {
            return "没读到{$subject}：gitee 返回了 HTTP {$status}";
        }

        return null;
    }

    /**
     * @return array{ok: bool, reason: string, data: array}
     */
    private static function giteeUnreadable(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'data' => []];
    }

    /**
     * 资产名 → 归档后缀（交给 SafeArchive 分派用），非归档返回 null。
     *
     * 只看名字结尾、不嗅探内容：名字来自远端，但「以 .zip 结尾」只决定用哪个
     * 解包器，真伪由 ZipArchive/流式 tar 头自己判——打不开就抛错，不会静默装空。
     */
    public static function archiveExtOf(string $name): ?string
    {
        $lower = strtolower(trim($name));
        foreach (self::ARCHIVE_EXT as $ext) {
            if (str_ends_with($lower, $ext)) {
                return $ext;
            }
        }

        return null;
    }
}
