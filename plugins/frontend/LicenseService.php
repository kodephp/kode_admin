<?php

declare(strict_types=1);

namespace Kode\Plugins\frontend;

use Kode\Database\Db\Db;

/**
 * LicenseService - 低代码 License 签发与校验核心
 *
 * 8 维校验（对齐 uni3 COMMERCIALIZATION.md §4.3）：
 *   1. signature  RSA-2048 + SHA256 签名（防篡改）
 *   2. type/edition  合法授权类型
 *   3. appid      授权 appid 白名单（含 * 通配）
 *   4. domain     授权域名白名单（H5，支持 *.example.com 通配）
 *   5. bundle     授权包名白名单（App）
 *   6. validity   授权有效期（start_at <= now <= end_at；end_at 为空 = 永久）
 *   7. version    授权版本匹配（如 2.x 匹配 2.0 / 2.1.3）
 *   8. features   授权功能模块子集校验
 *
 * 密钥管理：RSA-2048 私钥存 storage/keys/lowcode-license/（0600），公钥同目录（0644）。
 * 通过 config('uni3.license_key_dir') 可覆盖目录。密钥自动惰性生成，也可手动 rotate。
 *
 * 依赖：PHP openssl 扩展（默认启用）
 */
final class LicenseService
{
    /** @var string[] 允许的 type */
    private const ALLOWED_TYPES = ['commercial', 'subscription', 'oem', 'trial'];

    /** @var string[] 允许的 edition */
    private const ALLOWED_EDITIONS = ['standard', 'professional', 'enterprise', 'oem', 'starter', 'growth'];

    /** @var string[] 允许的 status */
    private const ALLOWED_STATUS = ['active', 'revoked', 'expired', 'suspended'];

    /** @var string[] JSON 编码的数组字段 */
    private const JSON_ARRAY_FIELDS = ['appids', 'domains', 'bundles', 'features'];

    /**
     * license_id 号段的事务级 advisory 锁键（`pg_advisory_xact_lock`）。
     *
     * 值本身没有业务含义，唯一要求是**别再写第二份**：并发双方各拿一把号段锁，
     * 等价于没锁（两个请求读到同一个 MAX，铸出同一个 license_id 撞 UNIQUE）。
     */
    private const LICENSE_ID_LOCK_KEY = 8_092_415_301;

    /* ===================== 密钥管理 ===================== */

    /**
     * 密钥目录（默认 storage/keys/lowcode-license/）
     */
    private function keyDir(): string
    {
        $dir = (string) config('uni3.license_key_dir', '');
        if ($dir === '') {
            // __DIR__ = <项目根>/plugins/frontend → 上溯 2 级到项目根
            $dir = dirname(__DIR__, 2) . '/storage/keys/lowcode-license';
        }
        return rtrim($dir, '/');
    }

    public function privateKeyPath(): string
    {
        return $this->keyDir() . '/license.private.pem';
    }

    public function publicKeyPath(): string
    {
        return $this->keyDir() . '/license.public.pem';
    }

    /**
     * 加载或生成 RSA-2048 密钥对（幂等）
     *
     * @return array{private: \OpenSSLAsymmetricKey, public: \OpenSSLAsymmetricKey}
     * @throws \RuntimeException openssl 扩展不可用或密钥加载失败
     */
    public function keys(): array
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('openssl 扩展未启用，无法使用 RSA 签名');
        }

        $privPath = $this->privateKeyPath();
        $pubPath = $this->publicKeyPath();
        if (!is_file($privPath) || !is_file($pubPath)) {
            $this->generateKeyPair();
        }

        $privPem = @file_get_contents($privPath);
        $pubPem = @file_get_contents($pubPath);
        if ($privPem === false || $pubPem === false) {
            throw new \RuntimeException('License 密钥文件读取失败');
        }
        $priv = openssl_pkey_get_private((string) $privPem);
        $pub = openssl_pkey_get_public((string) $pubPem);
        if ($priv === false || $pub === false) {
            // 密钥损坏，尝试重建
            @unlink($privPath);
            @unlink($pubPath);
            $this->generateKeyPair();
            $priv = openssl_pkey_get_private((string) @file_get_contents($privPath));
            $pub = openssl_pkey_get_public((string) @file_get_contents($pubPath));
            if ($priv === false || $pub === false) {
                throw new \RuntimeException('License 密钥对加载失败，请删除 storage/keys/lowcode-license/ 后重试');
            }
        }
        return ['private' => $priv, 'public' => $pub];
    }

    /**
     * 生成 RSA-2048 密钥对
     *
     * @throws \RuntimeException 目录不可创建或密钥生成失败
     */
    private function generateKeyPair(): void
    {
        $dir = $this->keyDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new \RuntimeException("无法创建密钥目录：{$dir}");
        }

        $details = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $pair = openssl_pkey_new($details);
        if ($pair === false) {
            throw new \RuntimeException('RSA 密钥生成失败：' . implode(' | ', openssl_error_string()));
        }
        // PHP 8+ API：openssl_pkey_export() 导出私钥 PEM，openssl_pkey_get_details()['key'] 取公钥 PEM
        $privPem = '';
        $pubPem = '';
        $exportOk = openssl_pkey_export($pair, $privPem);
        if ($exportOk === false) {
            throw new \RuntimeException('RSA 密钥导出失败：' . implode(' | ', openssl_error_string()));
        }
        $pubDetails = openssl_pkey_get_details($pair);
        if ($pubDetails === false || !isset($pubDetails['key']) || !is_string($pubDetails['key'])) {
            throw new \RuntimeException('RSA 公钥导出失败');
        }
        $pubPem = $pubDetails['key'];
        $privPath = $this->privateKeyPath();
        $pubPath = $this->publicKeyPath();
        if (@file_put_contents($privPath, $privPem, LOCK_EX) === false || @file_put_contents($pubPath, $pubPem, LOCK_EX) === false) {
            throw new \RuntimeException('密钥文件写入失败，请检查目录权限');
        }
        @chmod($privPath, 0600);
        @chmod($pubPath, 0644);
    }

    /**
     * 手动轮转密钥对（谨慎使用，旧 License 签名将失效）
     *
     * @return array{rotated: bool, privateKeyPath: string, publicKeyPath: string, backupDir: string, warning: string}
     */
    public function rotateKeys(): array
    {
        $privPath = $this->privateKeyPath();
        $pubPath = $this->publicKeyPath();
        $backupDir = $this->keyDir() . '/backup';
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0700, true)) {
            throw new \RuntimeException('无法创建密钥备份目录');
        }
        $ts = date('Ymd-His');
        foreach ([$privPath, $pubPath] as $path) {
            if (is_file($path)) {
                $backup = $backupDir . '/' . basename($path) . ".{$ts}.bak";
                if (!@rename($path, $backup) && !@copy($path, $backup)) {
                    @unlink($path);
                }
            }
        }
        @unlink($privPath);
        @unlink($pubPath);
        $this->keys(); // 触发生成
        return [
            'rotated' => true,
            'privateKeyPath' => $privPath,
            'publicKeyPath' => $pubPath,
            'backupDir' => $backupDir,
            'warning' => '旧 License 签名将无法通过校验，请重新签发或保留旧密钥',
        ];
    }

    /**
     * 密钥指纹（用于前端展示与轮换确认）
     */
    public function fingerprints(): array
    {
        $keys = $this->keys();
        $details = openssl_pkey_get_details($keys['private']);
        return [
            'type' => 'RSA-2048',
            'bits' => (int) ($details['bits'] ?? 0),
            'sha256' => base64_encode(hash('sha256', (string) ($details['key'] ?? ''), true)),
            'sha1' => base64_encode(hash('sha1', (string) ($details['key'] ?? ''), true)),
            'generated_at' => date('c'),
        ];
    }

    /* ===================== 签名 / 校验 ===================== */

    /**
     * 规范化序列化 payload（sort keys，禁止重复 key 覆盖）
     *
     * @param array<string, mixed> $payload
     */
    public static function canonicalize(array $payload): string
    {
        ksort($payload, SORT_STRING);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? '{}' : $json;
    }

    /**
     * 构建 License 签名的 payload（所有可变字段必须进来，否则校验无法识别篡改）
     *
     * @param array<string, mixed> $license 完整 license row（JSON 字段已解码为数组）
     * @return array<string, mixed>
     */
    public static function buildPayload(array $license): array
    {
        $normalizeList = function (mixed $v): array {
            return array_values(array_map('strval', (array) ($v ?? [])));
        };
        return [
            'license_id' => (string) ($license['license_id'] ?? ''),
            'type' => (string) ($license['type'] ?? ''),
            'edition' => (string) ($license['edition'] ?? ''),
            'customer_name' => (string) ($license['customer_name'] ?? ''),
            'customer_email' => (string) ($license['customer_email'] ?? ''),
            'appids' => $normalizeList($license['appids'] ?? []),
            'domains' => $normalizeList($license['domains'] ?? []),
            'bundles' => $normalizeList($license['bundles'] ?? []),
            'features' => $normalizeList($license['features'] ?? []),
            'start_at' => $license['start_at'] ?? null,
            'end_at' => $license['end_at'] ?? null,
            'version' => (string) ($license['version'] ?? ''),
        ];
    }

    /**
     * 对 license row 签名（返回 base64 字符串）
     *
     * @param array<string, mixed> $license 完整 license row（JSON 字段已解码为数组）
     * @throws \RuntimeException 签名失败
     */
    public function sign(array $license): string
    {
        $keys = $this->keys();
        $canonical = self::canonicalize(self::buildPayload($license));
        $sig = '';
        if (!openssl_sign($canonical, $sig, $keys['private'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('License 签名失败：' . implode(' | ', openssl_error_string()));
        }
        return base64_encode($sig);
    }

    /**
     * 校验 license row 的 signature（仅签名维度）
     *
     * @param array<string, mixed> $license
     */
    public function verifySignature(array $license, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }
        $sig = base64_decode($signature, true);
        if ($sig === false) {
            return false;
        }
        $keys = $this->keys();
        $canonical = self::canonicalize(self::buildPayload($license));
        return openssl_verify($canonical, $sig, $keys['public'], OPENSSL_ALGO_SHA256) === 1;
    }

    /* ===================== 8 维校验 ===================== */

    /**
     * 完整 8 维校验（供前端 shell bootstrap 使用）
     *
     * @param array<string, mixed> $license 从 DB 取出的完整 license row（JSON 字段已解码）
     * @param array<string, mixed> $claims 客户端声明的 {appid, domain, bundle, version, features}
     * @return array{valid: bool, reasons: list<string>, details: array<string, bool>}
     */
    public function verify(array $license, array $claims): array
    {
        $reasons = [];
        $details = [];

        // 1) signature
        $sigOk = $this->verifySignature($license, (string) ($license['signature'] ?? ''));
        $details['signature'] = $sigOk;
        if (!$sigOk) {
            $reasons[] = 'signature invalid';
        }

        // 2) type/edition
        $type = (string) ($license['type'] ?? '');
        $edition = (string) ($license['edition'] ?? '');
        $typeEditionOk = in_array($type, self::ALLOWED_TYPES, true) && in_array($edition, self::ALLOWED_EDITIONS, true);
        $details['type_edition'] = $typeEditionOk;
        if (!$typeEditionOk) {
            $reasons[] = "type '{$type}' / edition '{$edition}' 非法";
        }

        // 3) appid
        $appid = (string) ($claims['appid'] ?? '');
        $appids = array_map('strval', (array) ($license['appids'] ?? []));
        $appidOk = $appid !== '' && (in_array('*', $appids, true) || in_array($appid, $appids, true));
        $details['appid'] = $appidOk;
        if (!$appidOk) {
            $reasons[] = "appid '{$appid}' 不在授权列表";
        }

        // 4) domain
        $domain = (string) ($claims['domain'] ?? '');
        $domains = array_map('strval', (array) ($license['domains'] ?? []));
        $domainOk = $domain === '' ? true : self::domainMatches($domain, $domains);
        $details['domain'] = $domainOk;
        if (!$domainOk) {
            $reasons[] = "domain '{$domain}' 不在授权列表";
        }

        // 5) bundle
        $bundle = (string) ($claims['bundle'] ?? '');
        $bundles = array_map('strval', (array) ($license['bundles'] ?? []));
        $bundleOk = $bundle === '' ? true : (in_array('*', $bundles, true) || in_array($bundle, $bundles, true));
        $details['bundle'] = $bundleOk;
        if (!$bundleOk) {
            $reasons[] = "bundle '{$bundle}' 不在授权列表";
        }

        // 6) validity
        $now = new \DateTimeImmutable('now');
        $startAt = $license['start_at'] ?? null;
        $endAt = $license['end_at'] ?? null;
        $startOk = $startAt === null || $now >= new \DateTimeImmutable((string) $startAt);
        $endOk = $endAt === null || $now <= new \DateTimeImmutable((string) $endAt);
        $validityOk = $startOk && $endOk;
        $details['validity'] = $validityOk;
        if (!$startOk) {
            $reasons[] = '授权尚未生效';
        }
        if (!$endOk) {
            $reasons[] = '授权已过期';
        }

        // 7) version
        $ver = (string) ($claims['version'] ?? '');
        $pattern = (string) ($license['version'] ?? '');
        $versionOk = $ver === '' ? true : self::versionMatches($ver, $pattern);
        $details['version'] = $versionOk;
        if (!$versionOk) {
            $reasons[] = "version '{$ver}' 不匹配授权版本 '{$pattern}'";
        }

        // 8) features
        $reqFeatures = array_map('strval', (array) ($claims['features'] ?? []));
        $allowedFeatures = array_map('strval', (array) ($license['features'] ?? []));
        $featuresOk = empty($reqFeatures) || count(array_diff($reqFeatures, $allowedFeatures)) === 0;
        $details['features'] = $featuresOk;
        if (!$featuresOk) {
            $reasons[] = '所需功能模块超出授权范围';
        }

        // status 附加校验（revoked / suspended 直接拒绝）
        $status = (string) ($license['status'] ?? 'active');
        $statusOk = $status === 'active';
        $details['status'] = $statusOk;
        if (!$statusOk) {
            $reasons[] = "license status '{$status}'";
        }

        return [
            'valid' => empty($reasons),
            'reasons' => $reasons,
            'details' => $details,
        ];
    }

    /**
     * 域名匹配：支持精确 + *.example.com 通配
     *
     * @param string $domain
     * @param list<string> $allowlist
     */
    private static function domainMatches(string $domain, array $allowlist): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return false;
        }
        foreach ($allowlist as $allowed) {
            $allowed = strtolower(trim($allowed));
            if ($allowed === '') {
                continue;
            }
            if ($allowed === '*') {
                return true;
            }
            if ($allowed === $domain) {
                return true;
            }
            if (str_starts_with($allowed, '*.')) {
                $suffix = substr($allowed, 1); // ".example.com"
                if (str_ends_with($domain, $suffix)) {
                    $sub = substr($domain, 0, -strlen($suffix));
                    // 只允许一层子域（防止 evil.example.com 匹配 *.example.com）
                    if ($sub !== '' && !str_contains($sub, '.')) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * 版本匹配：支持 "2.x"、"2.1.x"、"2.1.0" 等模式
     *
     * "2.x"     匹配 2.0 / 2.1 / 2.1.3
     * "2.1.x"   匹配 2.1.0 / 2.1.3
     * "2.1.0"   精确匹配
     * "*"       匹配任意
     */
    private static function versionMatches(string $version, string $pattern): bool
    {
        $version = trim($version);
        $pattern = trim($pattern);
        if ($version === '' || $pattern === '') {
            return false;
        }
        if ($pattern === '*') {
            return true;
        }
        if ($pattern === $version) {
            return true;
        }
        // x 通配
        if (str_ends_with($pattern, '.x')) {
            $prefix = rtrim(substr($pattern, 0, -2), '.'); // "2" 或 "2.1"
            $prefixParts = explode('.', $prefix);
            $verParts = explode('.', $version);
            if (count($verParts) < count($prefixParts)) {
                return false;
            }
            $head = implode('.', array_slice($verParts, 0, count($prefixParts)));
            return $head === $prefix;
        }
        return false;
    }

    /* ===================== 签发 / 吊销 / 刷新 ===================== */

    /**
     * 签发新 License（校验 + 生成 id + 签名 + 落库）
     *
     * @param array<string, mixed> $data 输入数据（appids/domains/bundles/features 数组）
     * @return array{id: int, license: array<string, mixed>}
     * @throws \InvalidArgumentException 参数非法
     * @throws \RuntimeException 落库失败
     */
    public function issue(array $data): array
    {
        $type = (string) ($data['type'] ?? 'commercial');
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("type '{$type}' 非法，允许值：" . implode(', ', self::ALLOWED_TYPES));
        }
        $edition = (string) ($data['edition'] ?? 'standard');
        if (!in_array($edition, self::ALLOWED_EDITIONS, true)) {
            throw new \InvalidArgumentException("edition '{$edition}' 非法，允许值：" . implode(', ', self::ALLOWED_EDITIONS));
        }
        $customerName = trim((string) ($data['customer_name'] ?? ''));
        if ($customerName === '') {
            throw new \InvalidArgumentException('customer_name 必填');
        }

        $startAt = !empty($data['start_at']) ? (string) $data['start_at'] : date('Y-m-d');
        $endAt = !empty($data['end_at']) ? (string) $data['end_at'] : null;
        $version = (string) ($data['version'] ?? '2.x');
        $year = date('Y');

        // 「取号 → 签名 → 落库」必须是一段原子区，所以整块放进一个事务里。
        //
        // 号源用**当年最大序号 + 1**，不是「当年行数 + 1」：`COUNT(*)` 只要库里少一行
        // （手工清理、按客户批量删除……）就永远撞在已存在的号上，撞完 `license_id` 的
        // UNIQUE 直接 500，而且此后每一次签发都撞同一个号 —— 不是偶发竞争，是永久卡死，
        // 只能靠人去看 SQL 才知道是号段断了。`MAX + 1` 恒大于现存集合，天然不回头。
        //
        // 抢号本身要靠事务级 advisory 锁串行化：两个请求各自读到同一个 `MAX` 就会铸出
        // 同一个号（11 个 worker、后台两人同时点「签发」即命中）。锁的粒度选「表级一把」
        // 而不是按行 —— 抢的是号段，不是某一行；`xact` 版本随提交自动释放，不需要 undo。
        // 事务期间 `Db::` 的读与写复用同一条连接（见 §3「事务内读强制走主库」），
        // 所以锁一定先于 INSERT 生效 —— 换成两条连接就不是同一把锁了。
        $dbId = (int) Db::transaction(function () use (
            $type, $edition, $customerName, $startAt, $endAt, $version, $year, $data
        ): int {
            Db::statement('SELECT pg_advisory_xact_lock(?)', [self::LICENSE_ID_LOCK_KEY]);

            // 只认 `<前缀><数字>` 的存量号：库里混进过别的形状（手工种的试用号）时，
            // 让 CAST 报错等于让签发接口 500，而跳过那行只多送一个空号段。
            // 起始位写成 `CAST(? AS INTEGER)` 而不是裸 `substring(x FROM ?)`：服务端预编译下
            // 那个占位符会被推断成**模式串**（PG15+ 无匹配时回 NULL 而不是报错），
            // 于是 MAX 恒为 0、每次签发都铸 `LIC-YYYY-0001` —— 实测拿到的是 0，不是异常。
            // 偏移恒由 `$prefix`（含结尾那个连字符）算，别写成字面量 10：
            // 少算一位就是把 `-0001` 当数字读成 **-1**，于是铸出 `LIC-YYYY-0000` 再逐号往回撞。
            $prefix = 'LIC-' . $year . '-';
            $serial = (int) Db::select(
                "SELECT COALESCE(MAX(CAST(substring(license_id FROM CAST(? AS INTEGER)) AS INTEGER)), 0) AS mx
                 FROM kode_lowcode_licenses
                 WHERE license_id ~ ?",
                [strlen($prefix) + 1, '^' . $prefix . '[0-9]+$']
            )[0]['mx'];
            $licenseId = $prefix . sprintf('%04d', $serial + 1);

            // 组装 payload + 签名（先签后落库，签名绑定所有字段）
            $licenseRow = [
                'license_id' => $licenseId,
                'type' => $type,
                'edition' => $edition,
                'customer_name' => $customerName,
                'customer_email' => (string) ($data['customer_email'] ?? ''),
                'customer_contact' => (string) ($data['customer_contact'] ?? ''),
                'appids' => (array) ($data['appids'] ?? []),
                'domains' => (array) ($data['domains'] ?? []),
                'bundles' => (array) ($data['bundles'] ?? []),
                'features' => (array) ($data['features'] ?? []),
                'start_at' => $startAt,
                'end_at' => $endAt,
                'version' => $version,
                'status' => 'active',
            ];
            $signature = $this->sign($licenseRow);

            $lastId = Db::insert(
                "INSERT INTO kode_lowcode_licenses
                    (license_id, type, edition, customer_name, customer_email, customer_contact,
                     appids, domains, bundles, features, start_at, end_at, version, status, signature)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)",
                [
                    $licenseId, $type, $edition,
                    $customerName, $licenseRow['customer_email'], $licenseRow['customer_contact'],
                    json_encode($licenseRow['appids'], JSON_UNESCAPED_SLASHES),
                    json_encode($licenseRow['domains'], JSON_UNESCAPED_SLASHES),
                    json_encode($licenseRow['bundles'], JSON_UNESCAPED_SLASHES),
                    json_encode($licenseRow['features'], JSON_UNESCAPED_SLASHES),
                    $startAt, $endAt, $version, $signature,
                ]
            );
            if (!is_numeric($lastId) || (int) $lastId <= 0) {
                throw new \RuntimeException('License 落库失败');
            }

            return (int) $lastId;
        });

        return ['id' => $dbId, 'license' => $this->get($dbId)];
    }

    /**
     * 从 DB 获取完整 license（JSON 字段已解码为数组）
     */
    public function get(int $dbId): ?array
    {
        $row = Db::select("SELECT * FROM kode_lowcode_licenses WHERE id = ?", [$dbId]);
        if (empty($row)) {
            return null;
        }
        return self::decodeRow($row[0]);
    }

    /**
     * 按 license_id 获取（用于前端 shell bootstrap 校验）
     */
    public function getByLicenseId(string $licenseId): ?array
    {
        $row = Db::select("SELECT * FROM kode_lowcode_licenses WHERE license_id = ?", [$licenseId]);
        if (empty($row)) {
            return null;
        }
        return self::decodeRow($row[0]);
    }

    /**
     * 把 DB row 的 JSON 字段解码为数组
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function decodeRow(array $row): array
    {
        foreach (self::JSON_ARRAY_FIELDS as $field) {
            $row[$field] = json_decode((string) ($row[$field] ?? '[]'), true) ?? [];
        }
        return $row;
    }

    /**
     * 吊销 License（仅更新 status，签名保持不变便于审计追溯）
     */
    public function revoke(int $dbId): bool
    {
        $cur = $this->get($dbId);
        if ($cur === null) {
            return false;
        }
        if ($cur['status'] === 'revoked') {
            return true;
        }
        return Db::update(
            "UPDATE kode_lowcode_licenses SET status = 'revoked', updated_at = NOW() WHERE id = ?",
            [$dbId]
        ) > 0;
    }

    /**
     * 激活 License（恢复 active 状态；revoked 需二次确认，本方法允许）
     */
    public function activate(int $dbId): bool
    {
        $cur = $this->get($dbId);
        if ($cur === null) {
            return false;
        }
        if ($cur['status'] === 'active') {
            return true;
        }
        return Db::update(
            "UPDATE kode_lowcode_licenses SET status = 'active', updated_at = NOW() WHERE id = ?",
            [$dbId]
        ) > 0;
    }

    /**
     * 刷新 License（覆盖可变字段 + 重签；不改变 license_id 与 status）
     *
     * 用于：延期（改 end_at）、扩容（加 appids/domains）、版本升级（改 version）等场景。
     *
     * @param array<string, mixed> $data 需要更新的字段
     * @return array<string, mixed> 更新后的完整 row
     * @throws \RuntimeException License 不存在
     * @throws \InvalidArgumentException type/edition 非法
     */
    public function refresh(int $dbId, array $data): array
    {
        // 整行读出来 → 只改其中几个字段 → **全列写回去**，所以读与写之间必须锁住这一行。
        // 不锁的话两条并发 PUT 是后写的那条把先写的那条**静默吃掉**：B 读到 A 之前的旧行，
        // 把自己那几个字段合并进去再全列覆盖，A 刚写的新值就消失了；而签名是按 B 手里那份
        // 合并结果重算的，所以 verify 一路签验相符 —— 库里少了一半授权范围，页面和壳都看不出来。
        // 对 license 来说丢的那半边就是「客户以为已经加进去的 appid」。
        //
        // `FOR UPDATE` 必须在**同一个事务里**先抢再读（读完再另开一条无锁 SELECT 等于白锁），
        // 所以合并逻辑整块搬进闭包；不存在时在事务外抛，免得把「不存在」渲染成一次回滚失败。
        return Db::transaction(function () use ($dbId, $data): array {
            $locked = Db::select("SELECT * FROM kode_lowcode_licenses WHERE id = ? FOR UPDATE", [$dbId]);
            if (empty($locked)) {
                throw new \RuntimeException("License {$dbId} 不存在");
            }
            $cur = self::decodeRow($locked[0]);

            // 标量字段
            if (array_key_exists('type', $data)) {
                $newType = (string) $data['type'];
                if (!in_array($newType, self::ALLOWED_TYPES, true)) {
                    throw new \InvalidArgumentException("type '{$newType}' 非法");
                }
                $cur['type'] = $newType;
            }
            if (array_key_exists('edition', $data)) {
                $newEdition = (string) $data['edition'];
                if (!in_array($newEdition, self::ALLOWED_EDITIONS, true)) {
                    throw new \InvalidArgumentException("edition '{$newEdition}' 非法");
                }
                $cur['edition'] = $newEdition;
            }
            foreach (['customer_name', 'customer_email', 'customer_contact', 'version'] as $field) {
                if (array_key_exists($field, $data)) {
                    $cur[$field] = (string) $data[$field];
                }
            }
            foreach (['start_at', 'end_at'] as $field) {
                if (array_key_exists($field, $data)) {
                    $cur[$field] = $data[$field] === '' ? null : (string) $data[$field];
                }
            }
            // 数组字段
            foreach (self::JSON_ARRAY_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $cur[$field] = (array) $data[$field];
                }
            }

            // 重签（绑定所有可变字段）
            $signature = $this->sign($cur);

            Db::update(
                "UPDATE kode_lowcode_licenses SET
                    type = ?, edition = ?, customer_name = ?, customer_email = ?, customer_contact = ?,
                    appids = ?, domains = ?, bundles = ?, features = ?,
                    start_at = ?, end_at = ?, version = ?, signature = ?, updated_at = NOW()
                 WHERE id = ?",
                [
                    $cur['type'], $cur['edition'], $cur['customer_name'], $cur['customer_email'], $cur['customer_contact'],
                    json_encode($cur['appids'], JSON_UNESCAPED_SLASHES),
                    json_encode($cur['domains'], JSON_UNESCAPED_SLASHES),
                    json_encode($cur['bundles'], JSON_UNESCAPED_SLASHES),
                    json_encode($cur['features'], JSON_UNESCAPED_SLASHES),
                    $cur['start_at'], $cur['end_at'], $cur['version'], $signature, $dbId,
                ]
            );

            // 在锁内读回（同一条连接、事务尚未提交，读到的就是刚写的那一行）
            return self::decodeRow(Db::select("SELECT * FROM kode_lowcode_licenses WHERE id = ?", [$dbId])[0]);
        });
    }
}
