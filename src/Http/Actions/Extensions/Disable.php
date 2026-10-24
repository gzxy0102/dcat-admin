<?php

namespace Dcat\Admin\Http\Actions\Extensions;

use Dcat\Admin\Admin;
use Dcat\Admin\Grid\RowAction;
use Dcat\Admin\Http\Actions\Extensions\Concerns\HasExtensionAuthorization;

class Disable extends RowAction
{
    use HasExtensionAuthorization;

    public function title()
    {
        return sprintf('<span class="text-80">%s</span>', trans('admin.disable'));
    }

    public function handle()
    {
        Admin::extension()->enable($this->getKey(), false);

        return $this
            ->response()
            ->success(trans('admin.update_succeeded'))
            ->refresh();
    }
}
