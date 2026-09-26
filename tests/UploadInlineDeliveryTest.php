<?php

declare(strict_types=1);

namespace app\tests;

use app\admin\controllers\UploadController;
use Kode\Framework\Testing\TestCase;
use Kode\Http\Psr7\Message\ServerRequest;
use Kode\Http\Request as HttpRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * 上传内容嗅探 + 下载投递的收口（存储型 XSS）。
 *
 * 修复前两道门同时放行：
 *  1. `mimeOk()` 结尾挂了 `|| str_starts_with($mime, 'text/')` 兜底，
 *     于是「内容是 HTML 的 .txt」也算 MIME 校验通过（实测 finfo 直接嗅成 text/html、
 *     PHP 源码嗅成 text/x-php、SVG 嗅成 image/svg+xml）。
 *  2. `show()` 把嗅探结果原样写进 Content-Type，同一份内容以 text/html 落在应用自己的域下，
 *     点开 /api/files/xxx/yyy.txt 即执行脚本 —— 扩展名白名单被「改名投递」绕过。
 *
 * 收口口径：上传侧不再给 text/* 开兜底；投递侧只有不可执行的类型允许内联，
 * 其余一律 application/octet-stream + attachment + nosniff。
 * 投递侧才是真正的兜底：扩展名白名单本身可配（upload.allow_exts），
 * 且历史文件/别的入口（媒体库直接落盘）都能往 uploads 里放任意字节。
 *
 * 另钉住名字校验的字符集：`upload.naming = origin` 产出的文件名带连字符
 * （`my-report.pdf` 落成 `my-report_1a2b3c4d.pdf`），校验少放一个 `-` 就让上传成功的文件永远下载不到
 * （见 test_origin_named_file_with_a_hyphen_is_downloadable）。
 */
final class UploadInlineDeliveryTest extends TestCase
{
    protected bool $independentApp = true;

    /** 探针目录名满足 show() 的分段规则（[A-Za-z0-9_-]{1,64}） */
    private const DIR = 'zz_inline_probe';

    /** @var list<string> 本用例写入的文件绝对路径 */
    private array $files = [];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(dirname(__DIR__));

        $this->dir = base_path('storage/uploads/' . self::DIR);
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if ($this->files !== []) {
            @rmdir($this->dir);
        }

        HttpRequest::clear();
        parent::tearDown();
    }

    /**
     * 核心：内容为 HTML 的 .txt 不得以页面形式投递。
     */
    public function test_html_disguised_as_txt_is_downloaded_not_rendered(): void
    {
        $payload = "<script>alert('xss')</script>";
        $path = $this->write('html_as_txt.txt', $payload);

        // 前提自证：finfo 确实把它嗅成 text/html，否则这条用例什么都没测到
        self::assertSame('text/html', mime_content_type($path), '探针嗅探结果已变，用例前提失效');

        [$response, $body] = $this->show('html_as_txt.txt');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString(
            'text/html',
            $response->getHeaderLine('Content-Type'),
            'HTML 内容被当页面投递：' . $response->getHeaderLine('Content-Type')
        );
        self::assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('attachment', $response->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('html_as_txt.txt', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame($payload, $body, '改投递方式不该改动字节内容');
    }

    /**
     * PHP 源码改名成 .txt 同样不能内联（嗅成 text/x-php）。
     */
    public function test_php_source_disguised_as_txt_is_not_inlined(): void
    {
        $path = $this->write('php_as_txt.txt', "<?php echo 'owned';\n");
        self::assertSame('text/x-php', mime_content_type($path), '探针嗅探结果已变，用例前提失效');

        [$response, $body] = $this->show('php_as_txt.txt');

        self::assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('attachment', $response->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('owned', $body);
    }

    /**
     * SVG 是脚本容器：即便扩展名白名单里没有 svg，投递侧也不能放行内联。
     */
    public function test_svg_payload_is_not_served_inline(): void
    {
        $path = $this->write('svg_as_txt.txt', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');
        self::assertSame('image/svg+xml', mime_content_type($path), '探针嗅探结果已变，用例前提失效');

        [$response,] = $this->show('svg_as_txt.txt');

        self::assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('attachment', $response->getHeaderLine('Content-Disposition'));
    }

    /**
     * 合法文件不能被收口误伤：头像/图片预览仍要能内联，纯文本也是。
     */
    public function test_legitimate_media_stay_inline(): void
    {
        $this->write('plain.txt', "line one\r\nline two\n");
        self::assertSame('text/plain', mime_content_type($this->dir . '/plain.txt'), '探针嗅探结果已变');
        [$text,] = $this->show('plain.txt');
        self::assertSame('text/plain', $text->getHeaderLine('Content-Type'));
        self::assertFalse($text->hasHeader('Content-Disposition'), '可内联类型不该被强推下载');
        self::assertSame('nosniff', $text->getHeaderLine('X-Content-Type-Options'));

        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iTRmAAAAAElFTkSuQmCC'
        );
        $this->write('avatar.png', $png);
        self::assertSame('image/png', mime_content_type($this->dir . '/avatar.png'), '探针嗅探结果已变');
        [$image, $imageBytes] = $this->show('avatar.png');
        self::assertSame('image/png', $image->getHeaderLine('Content-Type'));
        self::assertFalse($image->hasHeader('Content-Disposition'));
        self::assertSame($png, $imageBytes, '图片字节要原样送达');
    }

    /**
     * 上传侧：text/* 兜底去掉后，合法文本仍过、伪装文本被拒。
     * （txt/csv 内容 finfo 恒为 text/plain，含 BOM/CRLF 也是，所以逐扩展名映射够用。）
     */
    public function test_mime_check_no_longer_accepts_any_text_sniff(): void
    {
        self::assertTrue($this->mimeOk($this->write('ok.txt', "readme\r\nsecond line\n"), 'txt'));
        self::assertTrue($this->mimeOk($this->write('ok.csv', "a,b,c\n1,2,3\n"), 'csv'));
        self::assertFalse(
            $this->mimeOk($this->write('bad1.txt', '<script>alert(1)</script>'), 'txt'),
            'HTML 内容改名 .txt 曾被 text/* 兜底放行'
        );
        self::assertFalse($this->mimeOk($this->write('bad2.txt', "<?php system(\$_GET['c']);\n"), 'txt'));
        self::assertFalse(
            $this->mimeOk($this->write('bad3.txt', '<svg onload="alert(1)"></svg>'), 'txt'),
            'SVG 内容改名 .txt 同样不该过'
        );
    }

    /**
     * 配置侧兜底：管理员把 html 加进 upload.allow_exts 也只是拿到附件，不会变成页面。
     */
    public function test_even_a_configured_extension_cannot_become_a_page(): void
    {
        $payload = "<script>alert('via allow_exts')</script>";
        $this->write('config_probe.html', $payload);

        [$response, $bytes] = $this->show('config_probe.html');

        self::assertSame($payload, $bytes, '文件仍在（下载可用）');
        self::assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('attachment', $response->getHeaderLine('Content-Disposition'));
    }

    /**
     * `upload.naming = origin` 保留原文件名的连字符（`my-report.pdf` → `my-report_1a2b3c4d.pdf`），
     * 下载校验的字符集必须接得住它，否则「上传成功、URL 永远 404」。
     */
    public function test_origin_named_file_with_a_hyphen_is_downloadable(): void
    {
        $name = 'my-report_1a2b3c4d.txt';
        self::assertSame(
            1,
            preg_match(UploadController::NAME_PAT, $name),
            'NAME_PAT 接不住 origin 命名产出的名字（该形状 = 原文件名 sanitize 后 + "_" + 8 hex）'
        );

        $this->write($name, "hello\r\n");
        [$response, $body] = $this->show($name);

        self::assertSame(200, $response->getStatusCode(), '连字符文件名被判非法路径：' . $body);
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
    }

    // ─── 辅助 ───────────────────────────────────────────────

    /** 写入探针文件，返回绝对路径。 */
    private function write(string $name, string $content): string
    {
        $file = $this->dir . '/' . $name;
        file_put_contents($file, $content);
        if (!in_array($file, $this->files, true)) {
            $this->files[] = $file;
        }
        self::assertFileExists($file);

        return $file;
    }

    /**
     * 直接调 show()（路由 GET /api/files/{path:.+}，path 形如 目录/文件名）。
     *
     * @return array{0: ResponseInterface, 1: string}
     */
    private function show(string $name): array
    {
        HttpRequest::setRequest(
            (new ServerRequest('GET', '/api/files/' . self::DIR . '/' . $name))
                ->withAttribute('_route_params', ['path' => self::DIR . '/' . $name])
        );

        $response = (new UploadController())->show();
        self::assertInstanceOf(ResponseInterface::class, $response);

        return [$response, (string) $response->getBody()];
    }

    /** 用 stub 冒充上传文件，只为喂给私有的 mimeOk()。 */
    private function mimeOk(string $file, string $ext): bool
    {
        $stub = new class($file) {
            public function __construct(private string $uri)
            {
            }

            public function getStream(): object
            {
                return new class($this->uri) {
                    public function __construct(private string $uri)
                    {
                    }

                    public function getMetadata(?string $key = null): mixed
                    {
                        return $key === 'uri' ? $this->uri : null;
                    }
                };
            }
        };

        $ref = new \ReflectionMethod(UploadController::class, 'mimeOk');
        $ref->setAccessible(true);

        return (bool) $ref->invokeArgs(new UploadController(), [$stub, $ext]);
    }
}
