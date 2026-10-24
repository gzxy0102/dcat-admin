<?php

namespace Dcat\Admin\Support;

use Illuminate\Support\Str;

/**
 * 已上传文件删除签名.
 *
 * 删除接口位于 dcat-api 路由组，整组放行 Permission 中间件，
 * 因此前端删除文件时必须携带服务端生成的 HMAC 签名，
 * 签名绑定表单类、字段（含关联字段）以及文件路径，防止篡改 key 参数删除任意文件。
 *
 * @see \Dcat\Admin\Http\Controllers\HandleFormController::destroyFile()
 */
class FileDeleteSignature
{
    const INPUT_NAME = '_signature';

    /**
     * 生成删除签名.
     *
     * @param  string  $form  表单类名
     * @param  string  $column  字段名
     * @param  string|array|null  $relation  关联字段信息
     * @param  string  $path  文件路径
     * @return string
     */
    public static function make(string $form, string $column, $relation, string $path): string
    {
        return hash_hmac('sha256', static::payload($form, $column, $relation, $path), static::key());
    }

    /**
     * 校验删除签名.
     *
     * @param  mixed  $signature
     * @param  string  $form
     * @param  string  $column
     * @param  string|array|null  $relation
     * @param  string  $path
     * @return bool
     */
    public static function validate($signature, string $form, string $column, $relation, string $path): bool
    {
        if (! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals(static::make($form, $column, $relation, $path), $signature);
    }

    /**
     * @param  string  $form
     * @param  string  $column
     * @param  string|array|null  $relation
     * @param  string  $path
     * @return string
     */
    protected static function payload(string $form, string $column, $relation, string $path): string
    {
        return implode("\n", [$form, $column, static::normalizeRelation($relation), $path]);
    }

    /**
     * 关联信息在渲染、上传、删除三个阶段可能表现为
     * 数组、字符串、null 或包含空元素，统一归一化后参与签名.
     *
     * @param  string|array|null  $relation
     * @return string
     */
    protected static function normalizeRelation($relation): string
    {
        if (is_array($relation)) {
            $relation = implode(',', array_filter($relation, function ($value) {
                return $value !== null && $value !== '';
            }));
        }

        return trim((string) $relation, ',');
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
