<?php

declare(strict_types=1);

namespace app\tests;

use Kode\Database\Db\Db;
use Kode\Framework\Testing\TestCase;
use Kode\Framework\Validation\ValidationException;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Kode\Plugins\frontend\BuilderController;
use Psr\Http\Message\ResponseInterface;

/**
 * 低代码 Builder/License 写侧的列宽闸门（AGENTS.md §4「越界一律 4xx 拒掉」）。
 *
 * 修的是实测出来的 500：`kode_lowcode_licenses.customer_name` 是 VARCHAR(128)，
 * 而 `issue()` 只判了「必填」、`refresh()` 一个字符都没判；`kode_lowcode_projects` 那边
 * 也只判了 `name`，`appid`/`license_id`/`theme_key` 三个 64 宽列与 `pages.title` 全裸。
 * 于是超长的值一路绑进 SQL，pgsql 回 `22001 对于可变字符类型来说，值太长了(128)`。
 * 实测抛出的是**原生 `PDOException`**（`kode/database` 的 `PdoConnection::retryOnce()` 只重连「断链」类，
 * 其余原样 rethrow，`DatabaseException` 只在连接/连接池失败那条路上），而 `PDOException extends \RuntimeException`
 * 正好落进控制器「落库失败 → 500」那条 catch（没有 catch 的入口则由框架兜成 500）
 * —— 客户端看到「服务器错误」，排障方向整个反过来：像是后端坏了，其实是表单写长了。
 *
 * 本机实测（同一事务里回滚，不留行）：
 *   INSERT ... customer_name = repeat('客', 129) → 错误: 值太长了(128)
 * 中文按**字符**计而不是字节，规则于是用 `length:`（Symfony Length 同样按字符）。
 * 不用 `max:` 的理由由 `test_a_digit_only_value_is_measured_in_characters_not_as_a_number`
 * 钉住：`max:` 在 framework < 1.11 上把纯数字串按数值比大小，`appid = '20260101'` 会被误拒。
 *
 * 两份规则不许合成一份：`version` 在项目/构建侧是 32 宽、license 表里只有 16，
 * 合并只能取宽的，于是 17 字符的版本号照样把 license 撞成 500。
 */
final class LowcodeColumnWidthTest extends TestCase
{
    protected bool $independentApp = true;

    private const PROBE = 'zz-lowcode-width';

    /** @var list<int> 本用例建出来的 project id（tearDown 精确按 id 删，连它的页面与构建任务） */
    private array $projectIds = [];

    /** @var list<int> 本用例签发的 license id */
    private array $licenseIds = [];

    /** 用例开始时刻：`dbFail()` 会往 `kode_system_logs` 落一行，tearDown 只清这个点之后的探针行。 */
    private string $startedAt = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));
        $this->startedAt = date('Y-m-d H:i:s');
    }

    protected function tearDown(): void
    {
        foreach ($this->projectIds as $id) {
            Db::delete('DELETE FROM kode_lowcode_build_jobs WHERE project_id = ?', [$id]);
            Db::delete('DELETE FROM kode_lowcode_pages WHERE project_id = ?', [$id]);
            Db::delete('DELETE FROM kode_lowcode_projects WHERE id = ?', [$id]);
        }
        foreach ($this->licenseIds as $id) {
            Db::delete('DELETE FROM kode_lowcode_licenses WHERE id = ?', [$id]);
        }
        // 探针值本身当条件：只删得掉这一条用例写下的那行，不碰别人的错误日志
        Db::delete(
            "DELETE FROM kode_system_logs WHERE module = 'lowcode' AND message LIKE ? AND created_at >= ?",
            ['%not-a-date%', $this->startedAt]
        );

        HttpRequest::clear();

        parent::tearDown();
    }

    public function test_an_over_long_appid_is_a_validation_failure_not_a_500(): void
    {
        $before = $this->projectCount();

        try {
            $this->storeProject(['name' => self::PROBE . '-appid', 'appid' => str_repeat('a', 65)]);
            self::fail('65 字符的 appid 没被挡下：它会长成一枚 pgsql 22001 → 500');
        } catch (ValidationException $e) {
            self::assertSame(['appid'], array_column($e->errors, 'field'), '错误没指回 appid：' . json_encode($e->errors));
        }

        self::assertSame($before, $this->projectCount(), '校验失败之前已经写了库');
    }

    /** 边界那一格必须还能过：闸门收成「小于 64」会把正常项目一起挡死。 */
    public function test_exactly_64_characters_is_still_a_valid_appid(): void
    {
        $result = $this->storeProject(['name' => self::PROBE . '-edge', 'appid' => str_repeat('a', 64)]);

        self::assertSame(201, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $this->trackProject($result);
    }

    /** 纯数字串按字符数判，不按数值判（`max:` 在旧 framework 上比的是大小）。 */
    public function test_a_digit_only_value_is_measured_in_characters_not_as_a_number(): void
    {
        $result = $this->storeProject(['name' => self::PROBE . '-digits', 'appid' => '20260101']);

        self::assertSame(
            201,
            $result['status'],
            '8 位纯数字 appid 被当成数值拒掉了：' . json_encode($result['body'], JSON_UNESCAPED_UNICODE)
        );
        $this->trackProject($result);
    }

    /** License 侧同一族缺陷的四个落点：客户名、邮箱、联系方式、版本号。 */
    public function test_the_license_write_path_refuses_every_over_long_field(): void
    {
        $before = $this->licenseCount();

        $cases = [
            'customer_name' => str_repeat('客', 129),
            'customer_email' => str_repeat('e', 129) . '@example.test',
            'customer_contact' => str_repeat('c', 65),
            'version' => str_repeat('v', 17),
        ];

        foreach ($cases as $field => $value) {
            try {
                $this->storeLicense(['customer_name' => self::PROBE . '-lic', $field => $value]);
                self::fail("{$field} 超长却没被挡下");
            } catch (ValidationException $e) {
                self::assertSame(
                    [$field],
                    array_column($e->errors, 'field'),
                    "{$field} 的错误没指回自己：" . json_encode($e->errors)
                );
            }
        }

        self::assertSame($before, $this->licenseCount(), '拒掉的签发仍然落了库');
    }

    /** 改字段那条路（`refresh()`）此前一个字符都没判，超长值会直接进 UPDATE。 */
    public function test_the_update_path_refuses_an_over_long_value_without_touching_the_row(): void
    {
        $id = $this->seedProject();

        try {
            $this->updateProject($id, ['appid' => str_repeat('b', 65)]);
            self::fail('PUT 的超长 appid 没被挡下');
        } catch (ValidationException $e) {
            self::assertSame(['appid'], array_column($e->errors, 'field'));
        }

        $row = Db::select('SELECT appid FROM kode_lowcode_projects WHERE id = ?', [$id]);
        self::assertSame(
            'zz-initial-appid',
            (string) ($row[0]['appid'] ?? ''),
            '被拒的那次 PUT 仍然改了库里的值'
        );
    }

    /** PUT 的「缺键保留原值」不许顺手变成「缺键也拿来判」：没发的字段不参与校验。 */
    public function test_a_single_valid_field_passes_even_when_its_siblings_are_absent(): void
    {
        $id = $this->seedProject();

        $result = $this->updateProject($id, ['theme_key' => 'dark-clean']);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $row = Db::select('SELECT appid, theme_key FROM kode_lowcode_projects WHERE id = ?', [$id]);
        self::assertSame('zz-initial-appid', (string) ($row[0]['appid'] ?? ''), '没发的字段被抹成默认值了');
        self::assertSame('dark-clean', (string) ($row[0]['theme_key'] ?? ''));
    }

    /**
     * 同名的 `version` 在两张表里宽度不同，两份规则各自守住自己那一格。
     *
     * 17 字符在 license（16 宽）必须拒、在项目构建（32 宽）必须收 ——
     * 只有把两侧都跑一遍才挡得住「顺手合并成一份宽松规则」的改法。
     */
    public function test_the_two_tables_keep_their_own_version_ceiling(): void
    {
        try {
            $this->storeLicense(['customer_name' => self::PROBE . '-ver', 'version' => str_repeat('7', 17)]);
            self::fail('license 的 17 字符版本号没被挡下');
        } catch (ValidationException $e) {
            self::assertSame(['version'], array_column($e->errors, 'field'));
        }

        $projectId = $this->seedProject();
        $result = $this->triggerBuild($projectId, str_repeat('7', 17));
        self::assertSame(
            201,
            $result['status'],
            '32 宽的构建任务版本号拒了 17 字符：两份规则被合成了一份。' . json_encode($result['body'], JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 列宽守不住的那一类落库失败：对外只回通用文案，原文进系统日志。
     *
     * `start_at`/`end_at` 是 `date` 列、宽度无界（不是 VARCHAR），所以它们越不过任何 `length:` 规则，
     * 却会在 pgsql 侧撞 `22P02 无效的类型 date 输入语法`（本机实测）。探针值不能写成 `'tomorrow'`：
     * pgsql 把 today/yesterday/tomorrow/epoch 都当合法日期字面量，那样根本不会失败。
     * 修前这两个入口是 `catch (\RuntimeException $e) { fail($e->getMessage(), 500) }`，
     * 于是 `SQLSTATE[22007]: Invalid datetime format: 7 错误: 无效的类型 date 输入语法: "not-a-date"`
     * 外加 `unnamed portal parameter $11` 这种参数位次原样发给客户端 —— 对调用方没有一条可行动，
     * 却把落点结构抖了出去；运维要看的原文改由 `SysLog` 落到 `kode_system_logs`。
     */
    public function test_a_database_error_never_reaches_the_client_verbatim(): void
    {
        $before = $this->licenseCount();

        $store = $this->storeLicense([
            'customer_name' => self::PROBE . '-dberr',
            'start_at' => 'not-a-date',
        ]);

        self::assertSame(500, $store['status'], json_encode($store['body'], JSON_UNESCAPED_UNICODE));
        $blob = (string) json_encode($store['body'], JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('SQLSTATE', $blob, '500 响应里回带了数据库报错原文');
        self::assertStringNotContainsString('not-a-date', $blob, '500 响应把非法输入连原文回显了');
        self::assertStringNotContainsString('portal parameter', $blob, '500 响应回带了 SQL 参数位次这类内部细节');
        // 不外发的前提是「另有落点」：响应里只留「见系统日志」而日志没写，等于错误彻底失踪
        self::assertNotEmpty(
            Db::select(
                "SELECT message FROM kode_system_logs WHERE module = 'lowcode' AND message LIKE ? AND created_at >= ?",
                ['%not-a-date%', $this->startedAt]
            ),
            '原文没进系统日志：响应让运维去看的这一眼里其实什么都没有'
        );
        self::assertSame($before, $this->licenseCount(), '被数据库拒掉的签发仍然落了库');

        $id = $this->seedLicenseForUpdate();
        $update = $this->updateLicense($id, ['end_at' => 'not-a-date']);
        self::assertSame(500, $update['status'], json_encode($update['body'], JSON_UNESCAPED_UNICODE));
        self::assertStringNotContainsString(
            'SQLSTATE',
            (string) json_encode($update['body'], JSON_UNESCAPED_UNICODE),
            'PUT 那条落库口回带了数据库报错原文'
        );
        self::assertNull(
            Db::select('SELECT end_at FROM kode_lowcode_licenses WHERE id = ?', [$id])[0]['end_at'] ?? null,
            '被拒的那次 PUT 仍然改了库里的行'
        );
    }

    /* ==================== 工具 ==================== */

    /** @param array<string, mixed> $body */
    private function storeProject(array $body): array
    {
        return $this->call('POST', '/api/builder/projects', 'projectStore', $body);
    }

    /** @param array<string, mixed> $body */
    private function updateProject(int $id, array $body): array
    {
        return $this->call('PUT', '/api/builder/projects/' . \app\support\HashId::enc($id), 'projectUpdate', $body, $id);
    }

    /**
     * 走签发入口，并登记任何真落了库的行 —— 断言失败时也不给库里留残渣。
     *
     * @param array<string, mixed> $overrides
     * @return array{status: int, body: array<string, mixed>}
     */
    private function storeLicense(array $overrides): array
    {
        $result = $this->call('POST', '/api/lowcode/licenses', 'licenseStore', $overrides);

        $id = \app\support\HashId::dec((string) ($result['body']['data']['id'] ?? ''));
        if (is_int($id)) {
            $this->licenseIds[] = $id;
        }

        return $result;
    }

    /** 走真签发口种一行可改的 license（行由 `storeLicense()` 登记，tearDown 负责删）。 */
    private function seedLicenseForUpdate(): int
    {
        $result = $this->storeLicense(['customer_name' => self::PROBE . '-upd']);
        self::assertSame(201, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));

        $id = \app\support\HashId::dec((string) ($result['body']['data']['id'] ?? ''));
        self::assertIsInt($id, '签发出参没有可解码的 id：' . json_encode($result['body'], JSON_UNESCAPED_UNICODE));

        return (int) $id;
    }

    /** @param array<string, mixed> $body */
    private function updateLicense(int $id, array $body): array
    {
        return $this->call(
            'PUT',
            '/api/lowcode/licenses/' . \app\support\HashId::enc($id),
            'licenseUpdate',
            $body,
            $id
        );
    }

    private function triggerBuild(int $projectId, string $version): array
    {
        return $this->call(
            'POST',
            '/api/builder/projects/' . \app\support\HashId::enc($projectId) . '/build',
            'buildStore',
            ['version' => $version],
            $projectId
        );
    }

    /** @param array<string, mixed> $body @return array{status: int, body: array<string, mixed>} */
    private function call(string $method, string $path, string $handler, array $body, ?int $routeId = null): array
    {
        $request = (new ServerRequest($method, $path))
            ->withParsedBody($body)
            ->withAttribute('auth', (object) ['uid' => 1, 'username' => self::PROBE, 'roles' => ['admin']]);
        if ($routeId !== null) {
            $request = $request->withAttribute('_route_params', ['id' => \app\support\HashId::enc($routeId)]);
        }
        HttpRequest::setRequest($request);

        $response = (new BuilderController())->{$handler}();
        self::assertInstanceOf(ResponseInterface::class, $response);

        return [
            'status' => $response->getStatusCode(),
            'body' => (array) json_decode((string) $response->getBody(), true),
        ];
    }

    /** 建一个用例自用的小项目（绕开控制器，免得把「种数据」也算进被测路径），并登记给 tearDown。 */
    private function seedProject(): int
    {
        $id = (int) Db::insert(
            "INSERT INTO kode_lowcode_projects (name, appid, edition, theme_key, status, created_by)
             VALUES (?, ?, 'standard', 'light-clean', 1, ?)",
            [self::PROBE . '-seed', 'zz-initial-appid', self::PROBE]
        );
        $this->projectIds[] = $id;

        return $id;
    }

    /** @param array{status: int, body: array<string, mixed>} $result */
    private function trackProject(array $result): void
    {
        $id = \app\support\HashId::dec((string) ($result['body']['data']['id'] ?? ''));
        self::assertIsInt($id, '出参没有可解码的 id：' . json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        $this->projectIds[] = (int) $id;
    }

    private function projectCount(): int
    {
        return (int) (Db::select(
            "SELECT COUNT(*) AS c FROM kode_lowcode_projects WHERE created_by = ?",
            [self::PROBE]
        )[0]['c'] ?? 0);
    }

    private function licenseCount(): int
    {
        return (int) (Db::select("SELECT COUNT(*) AS c FROM kode_lowcode_licenses")[0]['c'] ?? 0);
    }
}
