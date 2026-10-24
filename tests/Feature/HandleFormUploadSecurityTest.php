<?php

namespace Tests\Feature;

use Dcat\Admin\Http\Controllers\HandleFormController;
use Dcat\Admin\Http\JsonResponse;
use Dcat\Admin\Support\FileDeleteSignature;
use Dcat\Admin\Widgets\Form as WidgetsForm;
use Illuminate\Http\Request;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * 文件删除签名测试表单.
 */
class HandleFormUploadSecurityForm extends WidgetsForm
{
    public function handle(array $input)
    {
    }

    public function form()
    {
        $this->file('avatar');
        $this->text('name');
    }
}

/**
 * dcat-api 文件删除接口安全测试.
 *
 * destroy-file 接口位于 Permission 中间件整组放行的 dcat-api 路由组内，
 * 删除请求必须携带绑定表单类、字段（含关联字段）以及文件路径的 HMAC 签名，
 * 否则任意 key 参数都可删除字段 disk 上的任意路径.
 *
 * @group form-upload-security
 */
class HandleFormUploadSecurityTest extends TestCase
{
    protected $login = false;

    /**
     * @var string
     */
    protected $tmpDir;

    public function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/dcat-admin-upload-test-'.uniqid();
        mkdir($this->tmpDir, 0755, true);

        config([
            'filesystems.disks.dcat-upload-test' => ['driver' => 'local', 'root' => $this->tmpDir],
            'admin.upload.disk'                  => 'dcat-upload-test',
        ]);
    }

    public function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);

        parent::tearDown();
    }

    public function testSignatureRoundTrip()
    {
        $signature = FileDeleteSignature::make('App\\Forms\\Demo', 'avatar', '', 'files/a.jpg');

        $this->assertTrue(
            FileDeleteSignature::validate($signature, 'App\\Forms\\Demo', 'avatar', '', 'files/a.jpg')
        );
    }

    public function testSignatureBindsFormColumnRelationAndPath()
    {
        $signature = FileDeleteSignature::make('App\\Forms\\Demo', 'avatar', 'photos', 'files/a.jpg');

        // 篡改文件路径
        $this->assertFalse(
            FileDeleteSignature::validate($signature, 'App\\Forms\\Demo', 'avatar', 'photos', 'files/b.jpg')
        );

        // 篡改字段
        $this->assertFalse(
            FileDeleteSignature::validate($signature, 'App\\Forms\\Demo', 'photo', 'photos', 'files/a.jpg')
        );

        // 篡改表单类
        $this->assertFalse(
            FileDeleteSignature::validate($signature, 'App\\Forms\\Other', 'avatar', 'photos', 'files/a.jpg')
        );

        // 篡改关联字段
        $this->assertFalse(
            FileDeleteSignature::validate($signature, 'App\\Forms\\Demo', 'avatar', 'files', 'files/a.jpg')
        );
    }

    public function testSignatureRejectsMissingValue()
    {
        $make = FileDeleteSignature::make('App\\Forms\\Demo', 'avatar', '', 'files/a.jpg');

        $this->assertFalse(FileDeleteSignature::validate(null, 'App\\Forms\\Demo', 'avatar', '', 'files/a.jpg'));
        $this->assertFalse(FileDeleteSignature::validate('', 'App\\Forms\\Demo', 'avatar', '', 'files/a.jpg'));
        $this->assertFalse(FileDeleteSignature::validate([], 'App\\Forms\\Demo', 'avatar', '', 'files/a.jpg'));
        $this->assertFalse(FileDeleteSignature::validate($make.'tampered', 'App\\Forms\\Demo', 'avatar', '', 'files/a.jpg'));
    }

    public function testSignatureNormalizesRelationRepresentations()
    {
        // 渲染阶段使用 [关联名, 主键]（主键可能为 null），
        // 删除请求中 jQuery 可能丢弃 null 值或序列化为空字符串，
        // 各种表现形式必须生成一致的签名.
        $signature = FileDeleteSignature::make('App\\Forms\\Demo', 'avatar', ['photos', null], 'files/a.jpg');

        $this->assertTrue(FileDeleteSignature::validate($signature, 'App\\Forms\\Demo', 'avatar', ['photos'], 'files/a.jpg'));
        $this->assertTrue(FileDeleteSignature::validate($signature, 'App\\Forms\\Demo', 'avatar', ['photos', ''], 'files/a.jpg'));
        $this->assertTrue(FileDeleteSignature::validate($signature, 'App\\Forms\\Demo', 'avatar', 'photos', 'files/a.jpg'));
        $this->assertTrue(FileDeleteSignature::validate($signature, 'App\\Forms\\Demo', 'avatar', 'photos,', 'files/a.jpg'));
    }

    public function testSignatureSupportsBase64AppKey()
    {
        config(['app.key' => 'base64:'.base64_encode('binary-app-key')]);

        $signature = FileDeleteSignature::make('App\\Forms\\Demo', 'avatar', '', 'files/a.jpg');

        $this->assertTrue(
            FileDeleteSignature::validate($signature, 'App\\Forms\\Demo', 'avatar', '', 'files/a.jpg')
        );
    }

    public function testGetFieldRejectsMissingColumnWith422()
    {
        $controller = new HandleFormController();
        $method = new ReflectionMethod($controller, 'getField');
        $method->setAccessible(true);

        $request = Request::create('/dcat-api/form/destroy-file', 'POST', ['_column' => 'not-exists']);

        try {
            $method->invoke($controller, $request, new HandleFormUploadSecurityForm());
            $this->fail('Missing column should throw 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testGetFieldRejectsNonUploadFieldWith422()
    {
        $controller = new HandleFormController();
        $method = new ReflectionMethod($controller, 'getField');
        $method->setAccessible(true);

        $request = Request::create('/dcat-api/form/destroy-file', 'POST', ['_column' => 'name']);

        try {
            $method->invoke($controller, $request, new HandleFormUploadSecurityForm());
            $this->fail('Non upload field should throw 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testDestroyFileRejectsRequestWithoutSignature()
    {
        $file = $this->tmpDir.'/files-a.jpg';
        file_put_contents($file, 'image');

        $request = Request::create('/dcat-api/form/destroy-file', 'POST', [
            '_form_'  => HandleFormUploadSecurityForm::class,
            '_column' => 'avatar',
            'key'     => 'files-a.jpg',
        ]);

        $response = (new HandleFormController())->destroyFile($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertFalse($response->toArray()['status']);
        $this->assertTrue(file_exists($file));
    }

    public function testDestroyFileRejectsTamperedKey()
    {
        $file = $this->tmpDir.'/files-a.jpg';
        file_put_contents($file, 'image');

        $signature = FileDeleteSignature::make(
            HandleFormUploadSecurityForm::class, 'avatar', '', 'files-a.jpg'
        );

        // 签名是绑定 files-a.jpg 的，攻击者把 key 篡改成其它路径
        $request = Request::create('/dcat-api/form/destroy-file', 'POST', [
            '_form_'     => HandleFormUploadSecurityForm::class,
            '_column'    => 'avatar',
            'key'        => 'files-b.jpg',
            '_signature' => $signature,
        ]);

        $response = (new HandleFormController())->destroyFile($request);

        $this->assertFalse($response->toArray()['status']);
        $this->assertTrue(file_exists($file));
    }

    public function testDestroyFileRejectsSignatureForAnotherColumn()
    {
        $file = $this->tmpDir.'/files-a.jpg';
        file_put_contents($file, 'image');

        $signature = FileDeleteSignature::make(
            HandleFormUploadSecurityForm::class, 'other', '', 'files-a.jpg'
        );

        $request = Request::create('/dcat-api/form/destroy-file', 'POST', [
            '_form_'     => HandleFormUploadSecurityForm::class,
            '_column'    => 'avatar',
            'key'        => 'files-a.jpg',
            '_signature' => $signature,
        ]);

        $response = (new HandleFormController())->destroyFile($request);

        $this->assertFalse($response->toArray()['status']);
        $this->assertTrue(file_exists($file));
    }

    public function testDestroyFileDeletesWithValidSignature()
    {
        $file = $this->tmpDir.'/files-a.jpg';
        file_put_contents($file, 'image');

        $signature = FileDeleteSignature::make(
            HandleFormUploadSecurityForm::class, 'avatar', '', 'files-a.jpg'
        );

        $request = Request::create('/dcat-api/form/destroy-file', 'POST', [
            '_form_'     => HandleFormUploadSecurityForm::class,
            '_column'    => 'avatar',
            'key'        => 'files-a.jpg',
            '_signature' => $signature,
        ]);

        $response = (new HandleFormController())->destroyFile($request);

        $this->assertTrue($response->toArray()['status']);
        $this->assertFalse(file_exists($file));
    }

    public function testDestroyFileRejectsMissingKeyWith422()
    {
        $signature = FileDeleteSignature::make(
            HandleFormUploadSecurityForm::class, 'avatar', '', 'files-a.jpg'
        );

        $request = Request::create('/dcat-api/form/destroy-file', 'POST', [
            '_form_'     => HandleFormUploadSecurityForm::class,
            '_column'    => 'avatar',
            '_signature' => $signature,
        ]);

        try {
            (new HandleFormController())->destroyFile($request);
            $this->fail('Missing key should throw 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function testDestroyFileRejectsUnauthorizedForm()
    {
        $request = Request::create('/dcat-api/form/destroy-file', 'POST', [
            '_form_'  => \Dcat\Admin\Http\Forms\InstallFromLocal::class,
            '_column' => 'extension',
            'key'     => 'files-a.zip',
        ]);

        try {
            (new HandleFormController())->destroyFile($request);
            $this->fail('Unauthorized form should throw 403.');
        } catch (HttpException $e) {
            // InstallFromLocal 仅允许超级管理员，未登录用户被拒绝
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
