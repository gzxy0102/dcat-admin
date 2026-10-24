<?php

namespace Tests\Feature;

use Dcat\Admin\Http\Controllers\EditorMDController;
use Dcat\Admin\Http\Controllers\TinymceController;
use Dcat\Admin\Support\EditorUploadSignature;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * 编辑器（tinymce/editormd）图片上传安全测试.
 *
 * 上传接口位于 Permission 中间件整组放行的 dcat-api 路由组内，
 * 请求必须携带服务端生成的 HMAC 签名（绑定 disk 与 dir），
 * 文件内容必须是真实图片（jpeg/png/gif/webp/bmp，拒绝 svg），
 * 存储扩展名由服务端检测的 MIME 映射生成，不信任客户端原始扩展名.
 *
 * @group editor-upload-security
 */
class EditorUploadSecurityTest extends TestCase
{
    protected $login = false;

    /**
     * @var string
     */
    protected $tmpDir;

    /**
     * 1x1 PNG.
     */
    const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * 1x1 GIF.
     */
    const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/dcat-admin-editor-upload-test-'.uniqid();
        mkdir($this->tmpDir, 0755, true);

        config([
            'filesystems.disks.dcat-editor-upload-test' => ['driver' => 'local', 'root' => $this->tmpDir],
            'admin.upload.disk'                         => 'dcat-editor-upload-test',
        ]);
    }

    public function tearDown(): void
    {
        app('files')->deleteDirectory($this->tmpDir);

        parent::tearDown();
    }

    /**
     * 构造真实可校验的上传文件.
     *
     * @param  string  $content
     * @param  string  $clientName
     * @return UploadedFile
     */
    protected function makeUploadedFile(string $content, string $clientName): UploadedFile
    {
        $path = $this->tmpDir.'/source-'.uniqid();
        file_put_contents($path, $content);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    /**
     * @param  string  $input
     * @param  array  $params
     * @param  UploadedFile|null  $file
     * @return \Illuminate\Http\Request
     */
    protected function makeRequest(string $input, array $params, ?UploadedFile $file = null)
    {
        $request = \Illuminate\Http\Request::create('/dcat-api/editor/upload', 'POST', $params);

        if ($file) {
            $request->files->set($input, $file);
        }

        return $request;
    }

    /**
     * @param  callable  $callable
     * @param  int  $status
     * @return HttpException
     */
    protected function assertThrowsHttpException(callable $callable, int $status = 422): HttpException
    {
        try {
            $callable();
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());

            return $e;
        }

        $this->fail("Expected HttpException {$status} was not thrown.");
    }

    public function testTinymceUploadsRealImageWithValidSignature()
    {
        // 攻击者把客户端文件名伪造成 evil.php，服务端必须按真实 MIME 存储为 .png
        $request = $this->makeRequest('file', [
            'disk' => 'dcat-editor-upload-test',
            'dir'  => 'tinymce/images',
            '_sign' => EditorUploadSignature::make('dcat-editor-upload-test', 'tinymce/images'),
        ], $this->makeUploadedFile(base64_decode(static::PNG), 'evil.php'));

        $response = (new TinymceController())->upload($request);

        $this->assertTrue((bool) preg_match('/\.png$/', $response['location']));
        $this->assertFileExists($this->tmpDir.'/tinymce/images/'.basename($response['location']));
    }

    public function testTinymceRejectsPhpFileEvenWithValidSignature()
    {
        $request = $this->makeRequest('file', [
            'disk'  => 'dcat-editor-upload-test',
            'dir'   => 'tinymce/images',
            '_sign' => EditorUploadSignature::make('dcat-editor-upload-test', 'tinymce/images'),
        ], $this->makeUploadedFile('<?php eval($_GET["x"]);', 'shell.php'));

        $this->assertThrowsHttpException(function () use ($request) {
            (new TinymceController())->upload($request);
        });
    }

    public function testTinymceRejectsSvgContent()
    {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>';

        $request = $this->makeRequest('file', [
            'disk'  => 'dcat-editor-upload-test',
            'dir'   => 'tinymce/images',
            '_sign' => EditorUploadSignature::make('dcat-editor-upload-test', 'tinymce/images'),
        ], $this->makeUploadedFile($svg, 'xss.svg'));

        $this->assertThrowsHttpException(function () use ($request) {
            (new TinymceController())->upload($request);
        });
    }

    public function testTinymceRejectsFakeImageWithJpgExtension()
    {
        $request = $this->makeRequest('file', [
            'disk'  => 'dcat-editor-upload-test',
            'dir'   => 'tinymce/images',
            '_sign' => EditorUploadSignature::make('dcat-editor-upload-test', 'tinymce/images'),
        ], $this->makeUploadedFile('plain text content', 'fake.jpg'));

        $this->assertThrowsHttpException(function () use ($request) {
            (new TinymceController())->upload($request);
        });
    }

    public function testRejectsMissingSignature()
    {
        $request = $this->makeRequest('file', [
            'disk' => 'dcat-editor-upload-test',
            'dir'  => 'tinymce/images',
        ], $this->makeUploadedFile(base64_decode(static::PNG), 'a.png'));

        $this->assertThrowsHttpException(function () use ($request) {
            (new TinymceController())->upload($request);
        });
    }

    public function testRejectsSignatureWithTamperedDir()
    {
        // 签名是为 tinymce/images 生成的，攻击者篡改成其它目录
        $request = $this->makeRequest('file', [
            'disk'  => 'dcat-editor-upload-test',
            'dir'   => 'other/dir',
            '_sign' => EditorUploadSignature::make('dcat-editor-upload-test', 'tinymce/images'),
        ], $this->makeUploadedFile(base64_decode(static::PNG), 'a.png'));

        $this->assertThrowsHttpException(function () use ($request) {
            (new TinymceController())->upload($request);
        });
    }

    public function testRejectsSignatureWithTamperedDisk()
    {
        // 签名是为默认磁盘生成的，攻击者指定未授权磁盘
        $request = $this->makeRequest('file', [
            'disk'  => 'public',
            'dir'   => 'tinymce/images',
            '_sign' => EditorUploadSignature::make('dcat-editor-upload-test', 'tinymce/images'),
        ], $this->makeUploadedFile(base64_decode(static::PNG), 'a.png'));

        $this->assertThrowsHttpException(function () use ($request) {
            (new TinymceController())->upload($request);
        });
    }

    public function testRejectsPathTraversalDirEvenWithValidSignature()
    {
        // 即使攻击者为穿越目录计算出合法签名，目录格式校验也必须拒绝
        $request = $this->makeRequest('file', [
            'disk'  => 'dcat-editor-upload-test',
            'dir'   => 'a/../secret',
            '_sign' => EditorUploadSignature::make('dcat-editor-upload-test', 'a/../secret'),
        ], $this->makeUploadedFile(base64_decode(static::PNG), 'a.png'));

        $this->assertThrowsHttpException(function () use ($request) {
            (new TinymceController())->upload($request);
        });

        $this->assertFalse(file_exists($this->tmpDir.'/secret'));
    }

    public function testRejectsUnknownDisk()
    {
        $request = $this->makeRequest('file', [
            'disk'  => 'disk-not-configured',
            'dir'   => 'tinymce/images',
            '_sign' => EditorUploadSignature::make('disk-not-configured', 'tinymce/images'),
        ], $this->makeUploadedFile(base64_decode(static::PNG), 'a.png'));

        $this->assertThrowsHttpException(function () use ($request) {
            (new TinymceController())->upload($request);
        });
    }

    public function testFallsBackToDefaultDiskWhenDiskOmitted()
    {
        // 字段未自定义 disk 时，urlWithQuery 不携带 disk 参数，
        // 签名为空磁盘名，控制器回退到 admin.upload.disk
        $request = $this->makeRequest('file', [
            'dir'   => 'tinymce/images',
            '_sign' => EditorUploadSignature::make(null, 'tinymce/images'),
        ], $this->makeUploadedFile(base64_decode(static::PNG), 'a.png'));

        $response = (new TinymceController())->upload($request);

        $this->assertTrue((bool) preg_match('/\.png$/', $response['location']));
    }

    public function testEditorMdUploadsGifWithClientPhpName()
    {
        $request = $this->makeRequest('editormd-image-file', [
            'disk'  => 'dcat-editor-upload-test',
            'dir'   => 'markdown/images',
            '_sign' => EditorUploadSignature::make('dcat-editor-upload-test', 'markdown/images'),
        ], $this->makeUploadedFile(base64_decode(static::GIF), 'shell.php'));

        $response = (new EditorMDController())->upload($request);

        $this->assertSame(1, $response['success']);
        $this->assertTrue((bool) preg_match('/\.gif$/', $response['url']));
    }

    public function testEditorMdRejectsNonImageWith422()
    {
        $request = $this->makeRequest('editormd-image-file', [
            'disk'  => 'dcat-editor-upload-test',
            'dir'   => 'markdown/images',
            '_sign' => EditorUploadSignature::make('dcat-editor-upload-test', 'markdown/images'),
        ], $this->makeUploadedFile('<?php phpinfo();', 'info.php'));

        $this->assertThrowsHttpException(function () use ($request) {
            (new EditorMDController())->upload($request);
        });
    }

    public function testSignatureSupportsBase64AppKey()
    {
        config(['app.key' => 'base64:'.base64_encode('binary-editor-key')]);

        $signature = EditorUploadSignature::make('dcat-editor-upload-test', 'tinymce/images');

        $this->assertTrue(
            EditorUploadSignature::validate($signature, 'dcat-editor-upload-test', 'tinymce/images')
        );
        $this->assertFalse(
            EditorUploadSignature::validate($signature, 'dcat-editor-upload-test', 'other/dir')
        );
    }
}
