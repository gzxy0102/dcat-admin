<?php

namespace Dcat\Admin\Http\Forms;

use Dcat\Admin\Admin;
use Dcat\Admin\Contracts\LazyRenderable;
use Dcat\Admin\Exception\RuntimeException;
use Dcat\Admin\Traits\LazyWidget;
use Dcat\Admin\Widgets\Form;

class InstallFromLocal extends Form implements LazyRenderable
{
    use LazyWidget;

    public function handle(array $input)
    {
        $file = $input['extension'];

        if (! $file) {
            return $this->response()->error('Invalid arguments.');
        }

        try {
            $path = $this->getFilePath($file);

            $manager = Admin::extension();

            $extensionName = $manager->extract($path, true);

            if (! $extensionName) {
                return $this->response()->error(trans('admin.invalid_extension_package'));
            }

            $manager
                ->load()
                ->updateManager()
                ->update($extensionName);

            return $this->response()
                ->success(implode('<br>', $manager->updateManager()->notes))
                ->refresh();
        } catch (\Throwable $e) {
            Admin::reportException($e);

            return $this->response()->error($e->getMessage());
        } finally {
            if (! empty($path)) {
                @unlink($path);
            }
        }
    }

    public function form()
    {
        $this->file('extension')
            ->required()
            ->disk($this->disk())
            ->accept('zip', 'application/zip')
            ->autoUpload();
    }

    /**
     * 安装扩展属于高危操作，仅允许超级管理员执行.
     *
     * @param  mixed  $user
     * @return bool
     */
    protected function authorize($user): bool
    {
        return $user && method_exists($user, 'isAdministrator') && $user->isAdministrator();
    }

    /**
     * 获取上传的扩展包路径.
     *
     * 路径必须严格位于本表单 file 字段的实际上传目录
     * （即 config('admin.upload.directory.file')，默认 files）内，
     * 且后缀必须为 .zip，避免磁盘上任意已有文件被解压或清理流程删除.
     *
     * @param  string  $file
     * @return string
     *
     * @throws \Dcat\Admin\Exception\RuntimeException
     */
    protected function getFilePath($file)
    {
        $disk = $this->disk();

        $root = config("filesystems.disks.{$disk}.root");

        if (! $root) {
            throw new RuntimeException(sprintf('Missing \'root\' for disk [%s].', $disk));
        }

        if (! is_string($file) || $file === '' || strpos($file, "\0") !== false) {
            throw new RuntimeException('Invalid extension file.');
        }

        // file 字段未设置 dir() 时上传目录即为 config('admin.upload.directory.file').
        $directory = trim((string) config('admin.upload.directory.file'), '/\\');

        // realpath 同时解析 root 自身的符号链接.
        $root = realpath($root);

        if ($root === false) {
            throw new RuntimeException(sprintf('Invalid \'root\' for disk [%s].', $disk));
        }

        $root = rtrim($root, DIRECTORY_SEPARATOR);

        // file 字段的上传值相对于磁盘根目录（如 files/pkg.zip），
        // 而允许范围是上传目录（如 <root>/files）之内.
        $base = $directory === '' ? $root : $root.DIRECTORY_SEPARATOR.$directory;

        // 上传目录必须真实存在，其内的文件才允许被解压.
        $expected = realpath($base);

        // 拒绝绝对路径与目录穿越，文件必须真实存在于上传目录内.
        $path = realpath($root.DIRECTORY_SEPARATOR.ltrim($file, '/\\'));

        if (
            $path === false
            || $expected === false
            || ! is_file($path)
            || strpos($path, rtrim($expected, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) !== 0
            || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'zip'
        ) {
            throw new RuntimeException('Invalid extension file.');
        }

        return $path;
    }

    protected function disk()
    {
        return config('admin.extension.disk') ?: 'local';
    }
}
