<?php

namespace Dcat\Admin\Support;

use Illuminate\Support\Str;

/**
 * 编辑器图片上传接口签名.
 *
 * tinymce/editormd 上传接口位于 dcat-api 路由组，disk 与 dir 参数
 * 允许合法自定义（开发者可更换存储磁盘与目录），但必须由服务端签名，
 * 防止攻击者篡改参数把文件写入未授权磁盘或目录穿越.
 *
 * 签名复用 FileDeleteSignature 的密钥规则（app.key，兼容 base64: 前缀）.
 *
 * @see \Dcat\Admin\Support\FileDeleteSignature
 * @see \Dcat\Admin\Http\Controllers\TinymceController
 * @see \Dcat\Admin\Http\Controllers\EditorMDController
 */
class EditorUploadSignature
{
    const INPUT_NAME = '_sign';

    /**
     * 生成上传签名.
     *
     * @param  string|null  $disk
     * @param  string  $dir
     * @return string
     */
    public static function make($disk, string $dir): string
    {
        return hash_hmac('sha256', static::payload($disk, $dir), static::key());
    }

    /**
     * 校验上传签名.
     *
     * @param  mixed  $signature
     * @param  string|null  $disk
     * @param  string  $dir
     * @return bool
     */
    public static function validate($signature, $disk, string $dir): bool
    {
        if (! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals(static::make($disk, $dir), $signature);
    }

    /**
     * @param  string|null  $disk
     * @param  string  $dir
     * @return string
     */
    protected static function payload($disk, string $dir): string
    {
        return trim((string) $disk)."\n".trim($dir, '/');
    }

    /**
     * 获取签名密钥.
     *
     * @return string
     */
    protected static function key(): string
    {
        $key = (string) config('app.key');

        if (Str::startsWith($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }
}
