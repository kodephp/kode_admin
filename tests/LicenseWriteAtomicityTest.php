<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Plugins\frontend\LicenseService;

/**
 * License 写侧的原子性闸门（任务 #128）。
 *
 * 两条各自独立的坏法：
 *
 * 1. `issue()` 用 `COUNT(*) + 1` 取号。只要库里少一行（手工清理、将来接上批量删除），
 *    `COUNT+1` 就永远撞在已存在的号上 —— `kode_lowcode_licenses.license_id` 是
 *    `VARCHAR(64) NOT NULL UNIQUE`（见 `plugins/FrontendPlugin.php::install()`），
 *    撞完是 23505 → 500，而**此后每一次签发都撞同一个号**：不是偶发竞争，是永久卡死，
 *    且页面只回「签发失败」，不看 SQL 根本不知道是号段断了。
 * 2. `refresh()` 是「整行读出来 → 只改几个字段 → 全列写回去」，读与写之间没有锁。
 *    两条并发 PUT 会静默丢一边，而签名按手里那份合并结果重算，`verify()` 一路相符 ——
 *    丢掉的正是「客户以为已经加进去的 appid」。
 *
 * 三条用例各挡一件事，射程写清楚：
 *   - `test_issuance_numbering_follows_the_highest_serial_not_the_row_count` 是行为断言，
 *     把坏法 1 直接打死（旧写法在断言前就抛 23505）。
 *   - `test_issuance_waits_for_the_number_range_lock` 也是行为断言：用**另一条真实连接**
 *     占住同一把 advisory 锁，签发必须等锁并因 `lock_timeout` 失败、且一行都没落。
 *     旧写法里没有这把锁，会直接成功 → 红。
 *   - 坏法 2 只能用形状门禁挡（原因写在 `test_refresh_locks_the_row_for_the_whole_read_modify_write`
 *     的注释里，别把那条当成并发证明）。
 */
final class LicenseWriteAtomicityTest extends TestCase
{
    protected bool $independentApp = true;

    private const PROBE = 'zz-lowcode-atomic';

    /** @var list<int> */
    private array $licenseIds = [];

    /** @var \PDO|null 第二会话（占锁用），tearDown 兜底关闭 */
    private ?\PDO $other = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        if ($this->other !== null) {
            try {
                $this->other->rollBack();
            } catch (\Throwable) {
                // 事务可能已被超时/断链结束
            }
            $this->other = null;
        }
        foreach ($this->licenseIds as $id) {
            Db::delete('DELETE FROM kode_lowcode_licenses WHERE id = ?', [$id]);
        }
        // 断言失败路径（签发成功却判红、id 来不及登记）也不给库里留残渣：
        // 本用例的行一律带 PROBE 前缀的客户名，按这个精确前缀再扫一次。
        Db::delete('DELETE FROM kode_lowcode_licenses WHERE customer_name LIKE ?', [self::PROBE . '-%']);

        parent::tearDown();
    }

    /* ===================== 坏法 1：号段来自行数，不是最大序号 ===================== */

    /**
     * 删掉中间一行之后，下一次签发必须**向前**取号。
     *
     * 探针是相对自身算的（不依赖库里现有号段是否连续）：连发两张、删掉前一张、
     * 再发第三张，断言第三张 = 第二张的序号 + 1。旧写法在第三张就撞 23505。
     */
    public function test_issuance_numbering_follows_the_highest_serial_not_the_row_count(): void
    {
        $first = $this->issue('first');
        $second = $this->issue('second');

        $serialFirst = $this->serialOf((string) $first['license']['license_id']);
        $serialSecond = $this->serialOf((string) $second['license']['license_id']);
        self::assertSame($serialFirst + 1, $serialSecond, '连发两张的序号就不连续，探针本身失效了');

        // 制造空洞：删掉小号那张（本用例自己的行，按 id 精确删）
        Db::delete('DELETE FROM kode_lowcode_licenses WHERE id = ?', [(int) $first['id']]);
        foreach ($this->licenseIds as $i => $id) {
            if ($id === (int) $first['id']) {
                unset($this->licenseIds[$i]);
            }
        }

        $third = $this->issue('third');
        self::assertSame(
            $serialSecond + 1,
            $this->serialOf((string) $third['license']['license_id']),
            '删过一行之后序号没有向前走 —— 取号读的是行数，下一次就会撞 UNIQUE 并永久卡死号段'
        );
    }

    /** 跨年号段各算各的：去年的最大号不能把今年的序号顶到 2027。 */
    public function test_the_number_range_is_scoped_to_the_current_year(): void
    {
        $prefix = 'LIC-' . date('Y');
        $otherYear = 'LIC-' . ((int) date('Y') + 11);

        // 种一张「未来年份」的号（同前缀形状、不同年份），用完即删
        $planted = Db::insert(
            "INSERT INTO kode_lowcode_licenses
                (license_id, type, edition, customer_name, start_at, version, status, signature)
             VALUES (?, 'trial', 'standard', ?, CURRENT_DATE, '1.x', 'active', '')",
            [$otherYear . '-9999', self::PROBE . '-future']
        );
        $this->licenseIds[] = (int) $planted;

        $issued = $this->issue('scoped');
        self::assertSame(
            $prefix,
            substr((string) $issued['license']['license_id'], 0, strlen($prefix)),
            '签发号串的前缀不是当年 —— 号段作用域漂了'
        );
        self::assertLessThan(
            9999,
            $this->serialOf((string) $issued['license']['license_id']),
            '当年序号被别的年份的行顶高了：MAX 没有按年前缀过滤'
        );
    }

    /* ===================== 坏法 1 的另一半：抢号没有串行化 ===================== */

    /**
     * 号段锁必须是**这一把** advisory 锁，且必须在事务里、落库之前拿到。
     *
     * 探针用第二条真实连接把同一把锁占住（键从类常量反射出来，不复制数字 ——
     * 代码里换成别的键就等于没锁，而反射取值正是为了让「换键」也判红），
     * 再给写连接设一条 `lock_timeout`，然后签发：
     *   - 必须失败在等锁上（SQLSTATE 55P03）；
     *   - 必须一行都没落（`Db::transaction` 回滚）。
     * 没有锁的旧写法：不等 → 直接签发成功 → 红。
     *
     * 前置条件先证再测：`SET lock_timeout` 走写连接，而本机 `DB_READ_WRITE_SPLIT=true`
     * 把普通 `Db::select` 派到了**另一条会话**上，所以确认必须由事务内的读来做
     * （顺带就是「事务内读强制走主库」的活证据）。确认不了就明确 skip 并写原因，
     * 别让「没跑」和「跑过且绿」在输出上长得一样。
     */
    public function test_issuance_waits_for_the_number_range_lock(): void
    {
        $key = (new \ReflectionClass(LicenseService::class))->getConstant('LICENSE_ID_LOCK_KEY');
        self::assertIsInt($key, 'LicenseService 里没有 LICENSE_ID_LOCK_KEY 这个号段锁键');

        $this->other = $this->secondConnection();
        $this->other->beginTransaction();
        // 整数常量拼进探针 SQL（不是用户输入，也不涉及本仓业务表）
        $this->other->query("SELECT pg_advisory_xact_lock({$key})")->fetchAll();

        Db::statement("SET lock_timeout = '400ms'");
        $observed = Db::transaction(fn (): string => (string) Db::select('SHOW lock_timeout')[0]['lock_timeout']);
        if ($observed !== '400ms') {
            self::markTestSkipped(
                "写会话上没看到 lock_timeout（读到 '{$observed}'）：SET 与业务写落在了不同连接，"
                . '这条探针要求 Db::statement 与事务内读同会话（见 Db::getReadConnection）'
            );
        }

        $before = $this->licenseCount();
        try {
            $result = $this->issueRaw('locked');
            self::fail('号段锁没挡住并发：签发直接成功了，明细=' . json_encode($result, JSON_UNESCAPED_UNICODE));
        } catch (\PDOException $e) {
            self::assertSame(
                '55P03',
                (string) $e->getCode(),
                '等锁失败的原因不是锁超时（55P03），而是：' . $e->getMessage()
            );
        } finally {
            Db::statement('SET lock_timeout = 0');
            $this->other->rollBack();
            $this->other = null;
        }

        self::assertSame($before, $this->licenseCount(), '等锁失败的那次签发仍然落了库（事务没回滚）');
    }

    /* ===================== 坏法 2：读-改-写没有锁（形状门禁） ===================== */

    /**
     * `refresh()` 的关键区必须「开事务 → FOR UPDATE 读 → 合并 → 全列写回」一气呵成。
     *
     * 为什么这里只能是形状门禁：`SELECT ... FOR UPDATE` 与 `UPDATE` 都要同一把
     * 行级排他锁，任何「让另一会话占住行锁、看签发/刷新会不会等」的探针都分不出
     * 二者（不加 FOR UPDATE 的旧写法照样会卡在 UPDATE 上，探针恒绿）。
     * 真并发要两条会话在同一进程里交错，phpunit 的单连接 + 同步执行做不到，
     * 所以这一条**只证明形状**，别当成「并发已验证」。
     *
     * 正对照在下一条用例里：把修复前的方法体喂同一个判据，必须判红。
     */
    public function test_refresh_locks_the_row_for_the_whole_read_modify_write(): void
    {
        $failure = $this->refreshShapeFailure($this->methodBody(LicenseService::class, 'refresh'));

        self::assertNull($failure, 'refresh() 的关键区形状不对：' . ($failure ?? ''));
    }

    /** 判据必须真的会判 —— 把修复前那份「get() 裸读 + 全列 UPDATE」喂进去。 */
    public function test_the_shape_check_rejects_the_unlocked_read_modify_write(): void
    {
        $beforeFix = <<<'PHP'
        $cur = $this->get($dbId);
        if ($cur === null) {
            throw new \RuntimeException("License {$dbId} 不存在");
        }
        foreach (self::JSON_ARRAY_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $cur[$field] = (array) $data[$field];
            }
        }
        $signature = $this->sign($cur);
        Db::update('UPDATE kode_lowcode_licenses SET appids = ?, signature = ? WHERE id = ?', [1, 2, $dbId]);
        return $this->get($dbId);
        PHP;

        self::assertSame(
            '没有开事务（Db::transaction）',
            $this->refreshShapeFailure($beforeFix),
            '形状判据对修复前的写法判绿了 —— 它没在检查任何东西'
        );
    }

    /**
     * 关键区判据：返回第一条不满足的原因，全满足返回 null。
     *
     * 只按**方法体内出现的先后**判，不看文件别处 —— 「文件里有 FOR UPDATE」不等于
     * refresh 读的那一行就是被锁住的那一行。
     */
    private function refreshShapeFailure(string $body): ?string
    {
        $begin = stripos($body, 'Db::transaction(');
        if ($begin === false) {
            return '没有开事务（Db::transaction）';
        }

        if (!preg_match(
            '/SELECT\s+\*\s+FROM\s+kode_lowcode_licenses\s+WHERE\s+id\s*=\s*\?\s+FOR\s+UPDATE/i',
            $body,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            return '没有带锁读这一行（SELECT * FROM kode_lowcode_licenses WHERE id = ? FOR UPDATE）';
        }
        $lock = (int) $m[0][1];
        if ($lock < $begin) {
            return '带锁读落在事务之外（FOR UPDATE 出现在 Db::transaction 之前）';
        }

        $update = stripos($body, 'UPDATE kode_lowcode_licenses SET');
        if ($update === false) {
            return '没有全列写回的那条 UPDATE';
        }
        if ($update < $lock) {
            return '写回在带锁读之前 —— 读-改-写的顺序反了，锁没护住任何东西';
        }

        return null;
    }

    /* ===================== 工具 ===================== */

    /** @return array{id: int, license: array<string, mixed>} */
    private function issue(string $tag): array
    {
        $result = $this->issueRaw($tag);
        $this->licenseIds[] = (int) $result['id'];

        return $result;
    }

    /** 不登记 id 的原始签发（用例自己负责清理路径）。@return array<string, mixed> */
    private function issueRaw(string $tag): array
    {
        return (new LicenseService())->issue([
            'customer_name' => self::PROBE . '-' . $tag,
            'type' => 'trial',
            'edition' => 'standard',
            'appids' => ['com.test.' . $tag],
            'version' => '2.x',
        ]);
    }

    private function serialOf(string $licenseId): int
    {
        // PHP 的 substr 起点是 0 基、PG 的 substring FROM 是 1 基：这里切的是 `LIC-YYYY-` 之后
        $serial = (int) substr($licenseId, strlen('LIC-' . date('Y') . '-'));
        self::assertGreaterThan(0, $serial, "号串 {$licenseId} 解析不出序号，探针写法与实现不同源");

        return $serial;
    }

    private function licenseCount(): int
    {
        return (int) Db::select('SELECT COUNT(*) AS c FROM kode_lowcode_licenses')[0]['c'];
    }

    /** 第二会话：同一座库、另一个连接（占锁用；凭证只从配置取，永不打印）。 */
    private function secondConnection(): \PDO
    {
        $cfg = (array) config('database.connections.pgsql');

        return new \PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $cfg['host'], (int) $cfg['port'], $cfg['database']),
            (string) $cfg['username'],
            (string) $cfg['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    /** 取方法体原文（按 ReflectionMethod 的行界切，不在整份文件上正则 —— 会串到别的方法）。 */
    private function methodBody(string $class, string $method): string
    {
        $ref = new \ReflectionMethod($class, $method);
        $file = (string) $ref->getFileName();
        self::assertNotFalse($file, "{$class} 没有源文件可读");
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice($lines, (int) $ref->getStartLine() - 1, (int) $ref->getEndLine() - (int) $ref->getStartLine() + 1));
    }
}
