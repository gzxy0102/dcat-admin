<?php

namespace Tests\Feature;

use Dcat\Admin\Form;
use Dcat\Admin\Support\FileDeleteSignature;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 创建页文件删除（HasFiles::deleteFileWhenCreating）安全测试.
 *
 * 新增页面通过表单提交地址删除已上传文件，同样必须携带
 * 绑定表单类、字段（含关联字段）与文件路径的删除签名.
 *
 * @group form-upload-security
 */
class CreatePageFileDeleteTest extends TestCase
{
    protected $login = false;

    /**
     * @var string
     */
    protected $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/dcat-admin-create-delete-test-'.uniqid();
        mkdir($this->tmpDir, 0755, true);

        config([
            'filesystems.disks.dcat-create-delete-test' => ['driver' => 'local', 'root' => $this->tmpDir],
            'admin.upload.disk'                         => 'dcat-create-delete-test',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);

        parent::tearDown();
    }

    /**
     * @return Form
     */
    protected function makeForm()
    {
        $form = new Form();
        $form->file('avatar');

        return $form;
    }

    /**
     * @param  Form  $form
     * @param  array  $input
     * @return \Illuminate\Http\JsonResponse|void
     */
    protected function invokeDelete(Form $form, array $input)
    {
        $method = new ReflectionMethod($form, 'deleteFileWhenCreating');
        $method->setAccessible(true);

        return $method->invoke($form, $input);
    }

    public function testRejectsMissingSignature()
    {
        $file = $this->tmpDir.'/files-a.jpg';
        file_put_contents($file, 'image');

        $response = $this->invokeDelete($this->makeForm(), [
            '_file_del_' => '',
            '_column'    => 'avatar',
            'key'        => 'files-a.jpg',
        ]);

        $this->assertFalse(json_decode($response->getContent(), true)['status']);
        $this->assertTrue(file_exists($file));
    }

    public function testRejectsTamperedKey()
    {
        $fileA = $this->tmpDir.'/files-a.jpg';
        $fileB = $this->tmpDir.'/files-b.jpg';
        file_put_contents($fileA, 'image');
        file_put_contents($fileB, 'image');

        $form = $this->makeForm();
        $signature = FileDeleteSignature::make(get_class($form), 'avatar', '', 'files-a.jpg');

        // 签名绑定 files-a.jpg，攻击者把 key 篡改成其它路径
        $response = $this->invokeDelete($form, [
            '_file_del_' => '',
            '_column'    => 'avatar',
            'key'        => 'files-b.jpg',
            '_signature' => $signature,
        ]);

        $this->assertFalse(json_decode($response->getContent(), true)['status']);
        $this->assertTrue(file_exists($fileA));
        $this->assertTrue(file_exists($fileB));
    }

    public function testRejectsSignatureForAnotherForm()
    {
        $file = $this->tmpDir.'/files-a.jpg';
        file_put_contents($file, 'image');

        $signature = FileDeleteSignature::make('App\Admin\Forms\Other', 'avatar', '', 'files-a.jpg');

        $response = $this->invokeDelete($this->makeForm(), [
            '_file_del_' => '',
            '_column'    => 'avatar',
            'key'        => 'files-a.jpg',
            '_signature' => $signature,
        ]);

        $this->assertFalse(json_decode($response->getContent(), true)['status']);
        $this->assertTrue(file_exists($file));
    }

    public function testDeletesWithValidSignature()
    {
        $file = $this->tmpDir.'/files-a.jpg';
        file_put_contents($file, 'image');

        $form = $this->makeForm();
        $signature = FileDeleteSignature::make(get_class($form), 'avatar', '', 'files-a.jpg');

        $response = $this->invokeDelete($form, [
            '_file_del_' => '',
            '_column'    => 'avatar',
            'key'        => 'files-a.jpg',
            '_signature' => $signature,
        ]);

        $this->assertTrue(json_decode($response->getContent(), true)['status']);
        $this->assertFalse(file_exists($file));
    }

    public function testRelationFieldDeletesWithValidSignature()
    {
        $file = $this->tmpDir.'/files-a.jpg';
        file_put_contents($file, 'image');

        $form = new Form();
        $form->hasMany('photos', function ($nested) {
            $nested->file('avatar');
        });

        // 渲染阶段签名为 [关联名, null]（新增行无主键），
        // jQuery 请求中 null 元素被丢弃，归一化后必须一致.
        $signature = FileDeleteSignature::make(get_class($form), 'avatar', ['photos', null], 'files-a.jpg');

        $response = $this->invokeDelete($form, [
            '_file_del_' => '',
            '_column'    => 'avatar',
            '_relation'  => ['photos'],
            'key'        => 'files-a.jpg',
            '_signature' => $signature,
        ]);

        $this->assertTrue(json_decode($response->getContent(), true)['status']);
        $this->assertFalse(file_exists($file));
    }

    public function testRejectsTamperedRelation()
    {
        $file = $this->tmpDir.'/files-a.jpg';
        file_put_contents($file, 'image');

        $form = new Form();
        $form->hasMany('photos', function ($nested) {
            $nested->file('avatar');
        });
        $form->hasMany('galleries', function ($nested) {
            $nested->file('avatar');
        });

        $signature = FileDeleteSignature::make(get_class($form), 'avatar', ['photos', null], 'files-a.jpg');

        // 篡改为另一个同样含 avatar 字段的关联，签名必须失效
        $response = $this->invokeDelete($form, [
            '_file_del_' => '',
            '_column'    => 'avatar',
            '_relation'  => ['galleries'],
            'key'        => 'files-a.jpg',
            '_signature' => $signature,
        ]);

        $this->assertFalse(json_decode($response->getContent(), true)['status']);
        $this->assertTrue(file_exists($file));
    }
}
