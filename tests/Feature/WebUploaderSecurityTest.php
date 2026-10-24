<?php

namespace Tests\Feature;

use Dcat\Admin\Http\Middleware\WebUploader as WebUploaderMiddleware;
use Dcat\Admin\Support\WebUploader;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * WebUploader 分块上传安全测试.
 *
 * admin.upload 中间件位于 admin 中间件组末尾，auth/login 等
 * 鉴权放行路由在认证通过前也会触发分块合并处理，因此 _id、chunk、
 * chunks 参数必须在写入任何临时文件前完成校验，非法请求直接 422，
 * 临时文件不能逃逸 storage/tmp.
 *
 * @group web-uploader-security
 */
class WebUploaderSecurityTest extends TestCase
{
    protected $login = false;

    /**
     * @var string
     */
    protected $tmpDir;

    /**
     * 测试过程中创建的临时分块目录.
     *
     * @var array
     */
    protected $tmpIds = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/dcat-admin-web-uploader-test-'.uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    public function tearDown(): void
    {
        foreach ($this->tmpIds as $id) {
            app('files')->deleteDirectory(storage_path('tmp/'.$id));
        }

        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);

        parent::tearDown();
    }

    /**
     * @param  string  $content
     * @return UploadedFile
     */
    protected function makeUploadedFile(string $content): UploadedFile
    {
        $path = $this->tmpDir.'/chunk-'.uniqid();
        file_put_contents($path, $content);

        return new UploadedFile($path, 'chunk.bin', null, null, true);
    }

    /**
     * @param  array  $params
     * @param  UploadedFile|null  $file
     * @return Request
     */
    protected function makeRequest(array $params, UploadedFile $file = null)
    {
        $request = Request::create(admin_base_path('auth/login'), 'POST', $params);

        if ($file) {
            $request->files->set(WebUploader::FILE_NAME, $file);
        }

        return $request;
    }

    /**
     * @param  callable  $callable
     * @return HttpException
     */
    protected function assertThrows422(callable $callable): HttpException
    {
        try {
            $callable();
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());

            return $e;
        }

        $this->fail('Expected HttpException 422 was not thrown.');
    }

    /**
     * @param  array  $params
     * @param  UploadedFile|null  $file
     * @return WebUploader
     */
    protected function makeUploader(array $params, UploadedFile $file = null): WebUploader
    {
        if (isset($params['_id']) && is_string($params['_id']) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $params['_id'])) {
            $this->tmpIds[] = $params['_id'];
        }

        return new WebUploader($this->makeRequest($params, $file));
    }

    public function testSingleFileUploadKeepsWorking()
    {
        $uploader = $this->makeUploader(
            ['_id' => 'AbC123', 'upload_column' => 'avatar'],
            $this->makeUploadedFile('hello')
        );

        $file = $uploader->getUploadedFile();

        $this->assertInstanceOf(UploadedFile::class, $file);
        $this->assertFalse($uploader->hasChunkFile());
    }

    public function testSingleFileUploadWithoutIdKeepsWorking()
    {
        $uploader = $this->makeUploader(
            ['upload_column' => 'avatar'],
            $this->makeUploadedFile('hello')
        );

        $this->assertInstanceOf(UploadedFile::class, $uploader->getUploadedFile());
    }

    public function testRejectsTraversalUploadId()
    {
        foreach (['../../etc', 'a/b', 'a\\b', '..', str_repeat('a', 65)] as $id) {
            $uploader = $this->makeUploader(
                ['_id' => $id, 'upload_column' => 'avatar', 'chunks' => '2', 'chunk' => '0'],
                $this->makeUploadedFile('x')
            );

            $this->assertThrows422(function () use ($uploader) {
                $uploader->getUploadedFile();
            });
        }
    }

    public function testRejectsMalformedChunksAndChunk()
    {
        $cases = [
            // chunks 非纯数字
            ['_id' => 'id1', 'chunks' => '2foo', 'chunk' => '0'],
            ['_id' => 'id1', 'chunks' => '2.5', 'chunk' => '0'],
            ['_id' => 'id1', 'chunks' => ' 2', 'chunk' => '0'],
            // chunks 超出范围
            ['_id' => 'id1', 'chunks' => '0', 'chunk' => '0'],
            ['_id' => 'id1', 'chunks' => (string) (WebUploader::MAX_CHUNKS + 1), 'chunk' => '0'],
            // chunk 越界或非数字
            ['_id' => 'id1', 'chunks' => '2', 'chunk' => '2'],
            ['_id' => 'id1', 'chunks' => '2', 'chunk' => '-1'],
            ['_id' => 'id1', 'chunks' => '2', 'chunk' => 'x'],
        ];

        foreach ($cases as $params) {
            $params['upload_column'] = 'avatar';

            $uploader = $this->makeUploader($params, $this->makeUploadedFile('x'));

            $this->assertThrows422(function () use ($uploader) {
                $uploader->getUploadedFile();
            });
        }
    }

    public function testRejectsChunkedUploadWithoutIdOrChunk()
    {
        // 分块上传缺少 _id
        $uploader = $this->makeUploader(
            ['upload_column' => 'avatar', 'chunks' => '2', 'chunk' => '0'],
            $this->makeUploadedFile('x')
        );

        $this->assertThrows422(function () use ($uploader) {
            $uploader->getUploadedFile();
        });

        // 分块上传缺少 chunk
        $uploader = $this->makeUploader(
            ['_id' => 'id2', 'upload_column' => 'avatar', 'chunks' => '2'],
            $this->makeUploadedFile('x')
        );

        $this->assertThrows422(function () use ($uploader) {
            $uploader->getUploadedFile();
        });
    }

    public function testRejectsChunkParameterOnSingleFileUpload()
    {
        $uploader = $this->makeUploader(
            ['upload_column' => 'avatar', 'chunk' => '0'],
            $this->makeUploadedFile('x')
        );

        $this->assertThrows422(function () use ($uploader) {
            $uploader->getUploadedFile();
        });
    }

    public function testChunkedMergeStaysInsideStorageTmp()
    {
        $id = 'merge'.uniqid();
        $params = ['_id' => $id, 'upload_column' => 'avatar', 'chunks' => '2'];

        $first = $this->makeUploader($params + ['chunk' => '0'], $this->makeUploadedFile('hello'));
        $this->assertFalse($first->getUploadedFile());

        // 未合并完成时不得产生临时目录逃逸
        $part = storage_path("tmp/{$id}/".md5('chunk.bin').'.0.part');
        $this->assertFileExists($part);
        $this->assertSame(
            0,
            strpos(realpath(dirname($part)), realpath(storage_path('tmp')))
        );

        $second = $this->makeUploader($params + ['chunk' => '1'], $this->makeUploadedFile('world'));
        $merged = $second->getUploadedFile();

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\File\UploadedFile::class, $merged);
        $this->assertSame('helloworld', file_get_contents($merged->getRealPath()));
        $this->assertSame(
            0,
            strpos(realpath($merged->getRealPath()), realpath(storage_path('tmp')))
        );

        $second->deleteTemporaryFile();
        $this->assertFalse(file_exists(storage_path("tmp/{$id}")));
    }

    public function testGetTemporaryPathRejectsEscape()
    {
        $uploader = new WebUploader($this->makeRequest([]));

        foreach (['../evil', 'a/b', '..\\..\\evil', str_repeat('a', 65)] as $path) {
            $this->assertThrows422(function () use ($uploader, $path) {
                $uploader->getTemporaryPath($path);
            });
        }
    }

    public function testLoginRouteRejectsMaliciousChunkBeforeAuth()
    {
        // auth/login 在 admin.auth.except 中，未认证请求也会到达
        // admin.upload 中间件，恶意分块参数必须在认证前被 422 拒绝
        $request = $this->makeRequest([
            '_id'           => '../../evil',
            'upload_column' => 'avatar',
            'chunks'        => '2',
            'chunk'         => '0',
        ], $this->makeUploadedFile('x'));

        $middleware = new WebUploaderMiddleware();
        app()->instance('admin.web-uploader', new WebUploader($request));

        $this->assertThrows422(function () use ($middleware, $request) {
            $middleware->handle($request, function () {
                return 'next';
            });
        });

        // 校验发生在任何临时文件写入之前，不得留下分块目录
        $this->assertFalse(file_exists(storage_path('tmp').'/dcat-admin-evil'));
        $this->assertFalse(file_exists(storage_path('tmp').'/../../dcat-admin-evil'));
    }

    public function testLoginRoutePassesThroughNormalRequest()
    {
        $request = $this->makeRequest(['username' => 'admin', 'password' => 'admin']);

        $middleware = new WebUploaderMiddleware();
        app()->instance('admin.web-uploader', new WebUploader($request));

        $this->assertSame('next', $middleware->handle($request, function () {
            return 'next';
        }));
    }
}
