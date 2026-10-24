<?php

namespace Dcat\Admin\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EditorMDController
{
    use ValidatesEditorImageUpload;

    public function upload(Request $request)
    {
        list($file, $dir, $disk, $extension) = $this->validateImageUpload($request, 'editormd-image-file');

        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk($disk);

        $newName = $this->generateImageName($file, $extension);

        $storage->putFileAs($dir, $file, $newName);

        return ['success' => 1, 'url' => $storage->url("{$dir}/$newName")];
    }
}
