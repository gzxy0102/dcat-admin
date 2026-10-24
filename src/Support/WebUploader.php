<?php

namespace Dcat\Admin\Support;

use Illuminate\Http\Request;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * WebUploader文件上传处理.
 *
 * @property string $_id
 * @property int $chunk
 * @property int $chunks
 * @property string $upload_column
 * @property UploadedFile $file
 */
class WebUploader
{
    const FILE_NAME = '_file_';

    /**
     * 单次分块上传允许的最大分块数.
     */
    const MAX_CHUNKS = 10000;

    public $temporaryDirectory = 'tmp';

    protected $temporaryFilePath;

    protected $completeFile;

    public function __construct(Request $request = null)
    {
        $request = $this->prepareRequest($request ?: request());

        $this->_id = $request->get('_id');
        $this->chunk = $request->get('chunk');
        $this->chunks = $request->get('chunks');
        $this->upload_column = $request->get('upload_column');
        $this->file = $request->file(static::FILE_NAME);
    }

    protected function prepareRequest($request)
    {
        $relation = $request->get('_relation');
        if (! $relation || ! is_string($relation)) {
            return $request;
        }

        return $request->merge([
            '_relation' => trim($relation, ','),
        ]);
    }

    /**
     * 判断是否是分块上传.
     *
     * @return bool
     */
    public function hasChunkFile()
    {
        return $this->chunks > 1;
    }

    /**
     * 判断是否是文件上传请求.
     *
     * @return bool
     */
    public function isUploading()
    {
        $file = $this->file;

        if (
            ! $file
            || ! $this->upload_column
            || ! $file instanceof UploadedFile
        ) {
            return false;
        }

        return true;
    }

    /**
     * 获取完整的上传文件.
     *
     * @return UploadedFile|void
     */
    public function getUploadedFile()
    {
        $file = $this->file;

        if (! $file || ! $file instanceof UploadedFile) {
            return;
        }

        // 该中间件在登录等鉴权放行路由上、认证之前也会执行，
        // 必须在任何临时文件写入前完成参数校验，非法请求直接 422
        $this->validateChunkRequest();

        if (! $this->hasChunkFile()) {
            return $file;
        }

        if ($this->completeFile !== null) {
            return $this->completeFile;
        }

        return $this->completeFile = $this->mergeChunks($file);
    }

    /**
     * 移除临时文件以及文件夹.
     */
    public function deleteTemporaryFile()
    {
        if (! $this->temporaryFilePath) {
            return;
        }
        @unlink($this->temporaryFilePath);

        if (
            ! Finder::create()
                ->in($dir = dirname($this->temporaryFilePath))
                ->files()
                ->count()
        ) {
            @rmdir($dir);
        }
    }

    /**
     * 合并分块文件.
     *
     * @param  UploadedFile  $file
     * @return UploadedFile|false
     */
    protected function mergeChunks(UploadedFile $file)
    {
        $tmpDir = $this->getTemporaryPath($this->_id);
        $newFilename = $this->generateChunkFileName($file);

        // 移动当前分块到临时目录.
        $this->moveChunk($file, $tmpDir, $newFilename);

        // 判断所有分块是否上传完毕.
        if (! $this->isComplete($tmpDir, $newFilename)) {
            return false;
        }

        $this->temporaryFilePath = $tmpDir.'/'.$newFilename.'.tmp';

        $this->putTempFileContent($this->temporaryFilePath, $tmpDir, $newFilename);

        return new UploadedFile(
            $this->temporaryFilePath,
            $file->getClientOriginalName(),
            null,
            null,
            true
        );
    }

    /**
     * 判断所有分块是否上传完毕.
     *
     * @param  string  $tmpDir
     * @param  string  $newFilename
     * @return bool
     */
    protected function isComplete($tmpDir, $newFilename)
    {
        for ($index = 0; $index < $this->chunks; $index++) {
            if (! is_file("{$tmpDir}/{$newFilename}.{$index}.part")) {
                return false;
            }
        }

        return true;
    }

    /**
     * 移动分块文件到临时目录.
     *
     * @param  UploadedFile  $file
     * @param  string  $tmpDir
     * @param  string  $newFilename
     */
    protected function moveChunk(UploadedFile $file, $tmpDir, $newFilename)
    {
        $file->move($tmpDir, "{$newFilename}.{$this->chunk}.part");
    }

    /**
     * @param  string  $path
     * @param  string  $tmpDir
     * @param  string  $newFilename
     */
    protected function putTempFileContent($path, $tmpDir, $newFileame)
    {
        $out = fopen($path, 'wb');

        if (flock($out, LOCK_EX)) {
            for ($index = 0; $index < $this->chunks; $index++) {
                $partPath = "{$tmpDir}/{$newFileame}.{$index}.part";
                if (! $in = @fopen($partPath, 'rb')) {
                    break;
                }

                while ($buff = fread($in, 4096)) {
                    fwrite($out, $buff);
                }

                @fclose($in);
                @unlink($partPath);
            }

            flock($out, LOCK_UN);
        }

        fclose($out);
    }

    /**
     * 生成分块文件名称.
     *
     * @param  UploadedFile  $file
     * @return string
     */
    protected function generateChunkFileName(UploadedFile $file)
    {
        return md5($file->getClientOriginalName());
    }

    /**
     * 校验分块上传参数，保证临时文件只能写入 storage/tmp 内部目录.
     *
     * 分块请求必须携带合法的 _id（临时分块目录名）以及范围内的
     * chunk/chunks 整数，否则以 422 明确拒绝.
     *
     * @throws HttpException
     */
    protected function validateChunkRequest()
    {
        $id = $this->_id;

        // _id 由前端 WebUploader 组件生成（Str::random），作为临时分块
        // 目录名，必须严格限制字符集，防止路径穿越逃逸 storage/tmp
        if ($id !== null && $id !== '' && (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id))) {
            throw new HttpException(422, 'Invalid upload id');
        }

        $chunks = $this->normalizeIntegerParam('chunks');
        $chunk = $this->normalizeIntegerParam('chunk');

        if ($chunks === null) {
            // 非分块上传不允许携带 chunk 参数
            if ($chunk !== null) {
                throw new HttpException(422, 'Invalid chunk parameter');
            }

            return;
        }

        if ($chunks < 1 || $chunks > static::MAX_CHUNKS) {
            throw new HttpException(422, 'Invalid chunks');
        }

        $this->chunks = $chunks;

        if ($chunks <= 1) {
            $this->chunk = $chunk ?: 0;

            return;
        }

        // 分块上传必须携带合法的 _id 用于隔离临时分块目录
        if ($id === null || $id === '') {
            throw new HttpException(422, 'Missing upload id');
        }

        if ($chunk === null || $chunk < 0 || $chunk >= $chunks) {
            throw new HttpException(422, 'Invalid chunk index');
        }

        $this->chunk = $chunk;
    }

    /**
     * 解析非负整数请求参数，缺失返回 null，格式非法直接 422.
     *
     * @param  string  $property
     * @return int|null
     *
     * @throws HttpException
     */
    protected function normalizeIntegerParam(string $property)
    {
        $value = $this->{$property};

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            if ($value < 0) {
                throw new HttpException(422, 'Invalid '.$property);
            }

            return $value;
        }

        if (! is_string($value) || ! preg_match('/^\d+$/', $value)) {
            throw new HttpException(422, 'Invalid '.$property);
        }

        return (int) $value;
    }

    /**
     * 获取临时文件路径.
     *
     * @param  mixed  $path
     * @return string
     *
     * @throws HttpException
     */
    public function getTemporaryPath($path)
    {
        if (! is_string($path) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $path)) {
            throw new HttpException(422, 'Invalid temporary path');
        }

        return $this->getTemporaryDirectory().'/'.$path;
    }

    /**
     * 获取临时文件目录.
     *
     * @return string
     */
    public function getTemporaryDirectory()
    {
        $dir = storage_path($this->temporaryDirectory);

        if (! is_dir($dir)) {
            app('files')->makeDirectory($dir, 0755, true);
        }

        return rtrim($dir, '/');
    }
}
