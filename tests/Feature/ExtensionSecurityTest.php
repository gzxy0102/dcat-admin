<?php

namespace Tests\Feature;

use Dcat\Admin\Admin;
use Dcat\Admin\Exception\RuntimeException;
use Dcat\Admin\Http\Actions\Extensions\Disable;
use Dcat\Admin\Http\Actions\Extensions\Enable;
use Dcat\Admin\Http\Actions\Extensions\InstallFromLocal;
use Dcat\Admin\Http\Actions\Extensions\Marketplace;
use Dcat\Admin\Http\Actions\Extensions\Uninstall;
use Dcat\Admin\Http\Actions\Extensions\Update;
use Dcat\Admin\Http\Forms\InstallFromLocal as InstallFromLocalForm;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 扩展启用、禁用、更新、卸载、本地安装等高危操作的权限测试.
 *
 * 这些操作通过 dcat-api 路由组执行，Permission 中间件对整组放行，
 * 因此必须在 Action/Form 自身明确仅超级管理员可执行.
 *
 * @group extension-security
 */
class ExtensionSecurityTest extends TestCase
{
    protected $login = false;

    /**
     * 未登录用户（Admin::user() 为空）必须被拒绝.
     */
    public function testSensitiveExtensionActionsRejectGuest()
    {
        $actions = [
            Enable::class,
            Disable::class,
            Update::class,
            Uninstall::class,
        ];

        foreach ($actions as $class) {
            $action = new $class();

            $this->assertFalse($action->passesAuthorization(), $class);
            $this->assertFalse($action->allowed(), $class);
        }

        $this->assertFalse((new InstallFromLocal())->allowed());
        $this->assertFalse((new Marketplace())->allowed());

        $this->assertFalse((new InstallFromLocalForm())->passesAuthorization());
    }

    /**
     * 超级管理员可以执行.
     */
    public function testSensitiveExtensionActionsAllowAdministrator()
    {
        Admin::guard()->login($this->getUser());

        $actions = [
            Enable::class,
            Disable::class,
            Update::class,
            Uninstall::class,
        ];

        foreach ($actions as $class) {
            $action = new $class();

            $this->assertTrue($action->passesAuthorization(), $class);
            $this->assertTrue($action->allowed(), $class);
        }

        $this->assertTrue((new InstallFromLocal())->allowed());
        $this->assertTrue((new Marketplace())->allowed());

        $this->assertTrue((new InstallFromLocalForm())->passesAuthorization());
    }

    /**
     * InstallFromLocal::getFilePath 必须严格限制在 file 字段的上传目录
     * （config('admin.upload.directory.file')，默认 files）内且后缀为 .zip，
     * 拒绝目录穿越、绝对路径以及不存在的文件，
     * 确保 finally 清理与 Manager::extract 不会删除上传目录之外的文件.
     */
    public function testInstallFromLocalFilePathMustStayInsideUploadDirectory()
    {
        $root = sys_get_temp_dir().'/dcat-admin-install-test-'.uniqid();
        mkdir($root.'/files', 0755, true);
        file_put_contents($root.'/files/pkg.zip', 'zip-content');
        file_put_contents($root.'/files/not-a-zip.jpg', 'image-content');
        file_put_contents($root.'/secret.zip', 'zip-content');

        config([
            'filesystems.disks.local.root' => $root,
            'admin.upload.directory.file'  => 'files',
        ]);

        try {
            $form = new InstallFromLocalForm();
            $method = new ReflectionMethod($form, 'getFilePath');
            $method->setAccessible(true);

            // 合法的上传文件路径（files 目录内的 .zip）
            $this->assertSame(
                realpath($root.'/files/pkg.zip'),
                $method->invoke($form, 'files/pkg.zip')
            );

            // 同磁盘、files 目录外的已有 .zip 文件不允许被解析（防止被解压/删除）
            try {
                $method->invoke($form, 'secret.zip');
                $this->fail('File outside the upload directory should be rejected.');
            } catch (RuntimeException $e) {
                $this->assertTrue(true);
            }

            try {
                $method->invoke($form, '../secret.zip');
                $this->fail('Directory traversal should be rejected.');
            } catch (RuntimeException $e) {
                $this->assertTrue(true);
            }

            // files 目录内的非 .zip 文件不允许被解析
            try {
                $method->invoke($form, 'files/not-a-zip.jpg');
                $this->fail('Non-zip file should be rejected.');
            } catch (RuntimeException $e) {
                $this->assertTrue(true);
            }

            // 绝对路径
            try {
                $method->invoke($form, $root.'/secret.zip');
                $this->fail('Absolute path should be rejected.');
            } catch (RuntimeException $e) {
                $this->assertTrue(true);
            }

            // 不存在的文件
            try {
                $method->invoke($form, 'files/not-exist.zip');
                $this->fail('Nonexistent file should be rejected.');
            } catch (RuntimeException $e) {
                $this->assertTrue(true);
            }

            // 空值与 null 字节
            foreach (['', "files/\0pkg.zip"] as $invalid) {
                try {
                    $method->invoke($form, $invalid);
                    $this->fail('Invalid file should be rejected.');
                } catch (RuntimeException $e) {
                    $this->assertTrue(true);
                }
            }

            // 磁盘上所有已有文件均未被删除
            $this->assertTrue(file_exists($root.'/secret.zip'));
            $this->assertTrue(file_exists($root.'/files/pkg.zip'));
            $this->assertTrue(file_exists($root.'/files/not-a-zip.jpg'));
        } finally {
            unlink($root.'/files/pkg.zip');
            unlink($root.'/files/not-a-zip.jpg');
            unlink($root.'/secret.zip');
            rmdir($root.'/files');
            rmdir($root);
        }
    }
}
