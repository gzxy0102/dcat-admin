<?php

namespace Dcat\Admin\Http\Actions\Extensions;

use Dcat\Admin\Admin;
use Dcat\Admin\Grid\RowAction;
use Dcat\Admin\Http\Actions\Extensions\Concerns\HasExtensionAuthorization;

class Enable extends RowAction
{
    use HasExtensionAuthorization;

    public function title()
    {
        return sprintf('<b>%s</b>', trans('admin.enable'));
    }

    public function handle()
    {
        Admin::extension()->enable($this->getKey());

        return $this
            ->response()
            ->success(trans('admin.update_succeeded'))
            ->refresh();
    }
}
