<?php

namespace Dcat\Admin\Http\Controllers;

use Dcat\Admin\Support\EditorUploadSignature;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 编辑器（tinymce/editormd）图片上传校验.
 *
 * 上传接口必须携带服务端生成的 HMAC 签名（绑定 disk 与 dir），
 * 文件内容必须是真实图片（jpeg/png/gif/webp/bmp，拒绝 svg），
 * 存储扩展名由服务端检测到的 MIME 映射生成，不信任客户端原始扩展名.
 */
trait ValidatesEditorImageUpload
{
    /**
     * 服务端 MIME 与存储扩展名映射（小写 MIME => 扩展名）.
     */
    protected static $imageExtensions = [
        'image/jpeg'     => 'jpg',
        'image/pjpeg'    => 'jpg',
        'image/png'      => 'png',
        'image/gif'      => 'gif',
        'image/webp'     => 'webp',
        'image/bmp'      => 'bmp',
        'image/x-ms-bmp' => 'bmp',
    ];

    /**
     * 校验上传请求，返回 [UploadedFile, 目录, 磁盘名, 扩展名].
     *
     * @param  Request  $request
     * @param  string  $input
     * @return array
     *
     * @throws HttpException
     */
    protected function validateImageUpload(Request $request, string $input): array
    {
        $file = $request->file($input);

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw new HttpException(422, trans('admin.uploader.upload_failed'));
        }

        $disk = $request->get('disk');
        $dir = $request->get('dir', '');

        if (($disk !== null && ! is_string($disk)) || ! is_string($dir)) {
            throw new HttpException(422, trans('admin.deny'));
        }

        $dir = trim($dir, '/');

        if (! EditorUploadSignature::validate($request->get(EditorUploadSignature::INPUT_NAME), $disk, $dir)) {
            throw new HttpException(422, trans('admin.deny'));
        }

        if (! $this->isValidUploadDirectory($dir)) {
            throw new HttpException(422, trans('admin.deny'));
        }

        $disk = is_string($disk) && $disk !== '' ? $disk : (string) config('admin.upload.disk');

        if ($disk === '' || ! config("filesystems.disks.{$disk}")) {
            throw new HttpException(422, trans('admin.deny'));
        }

        $extension = $this->detectImageExtension($file);

        if ($extension === null) {
            throw new HttpException(422, trans('admin.uploader.upload_failed'));
        }

        return [$file, $dir, $disk, $extension];
    }

    /**
     * 校验目录格式：允许多级相对目录（如 tinymce/images），
     * 拒绝绝对路径、.. 穿越、反斜杠与空片段.
     *
     * @param  string  $dir
     * @return bool
     */
    protected function isValidUploadDirectory(string $dir): bool
    {
        if ($dir === '') {
            return true;
        }

        if (strpos($dir, "\0") !== false || strpos($dir, '\\') !== false) {
            return false;
        }

        foreach (explode('/', $dir) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }

            if (preg_match('/[:<>"|?*]/', $segment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 检测真实图片 MIME 并映射为存储扩展名，非允许类型返回 null.
     *
     * @param  UploadedFile  $file
     * @return string|null
     */
    protected function detectImageExtension(UploadedFile $file)
    {
        $path = $file->getRealPath();

        if (! is_string($path)) {
            return null;
        }

        $info = @getimagesize($path);

        if (! is_array($info) || empty($info[0]) || empty($info[1]) || empty($info['mime'])) {
            return null;
        }

        $mime = strtolower($info['mime']);

        return static::$imageExtensions[$mime] ?? null;
    }

    /**
     * 生成存储文件名（扩展名由服务端检测的 MIME 决定）.
     *
     * @param  UploadedFile  $file
     * @param  string  $extension
     * @return string
     */
    protected function generateImageName(UploadedFile $file, string $extension): string
    {
        return uniqid(md5($file->getClientOriginalName())).'.'.$extension;
    }
}
