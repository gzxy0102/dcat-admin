<?php

namespace Dcat\Admin\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TinymceController
{
    use ValidatesEditorImageUpload;

    public function upload(Request $request)
    {
        list($file, $dir, $disk, $extension) = $this->validateImageUpload($request, 'file');

        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk($disk);

        $newName = $this->generateImageName($file, $extension);

        $storage->putFileAs($dir, $file, $newName);

        return ['location' => $storage->url("{$dir}/$newName")];
    }
}
