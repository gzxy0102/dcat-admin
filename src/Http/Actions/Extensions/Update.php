<?php

namespace Dcat\Admin\Http\Actions\Extensions;

use Dcat\Admin\Admin;
use Dcat\Admin\Grid\RowAction;
use Dcat\Admin\Http\Actions\Extensions\Concerns\HasExtensionAuthorization;

class Update extends RowAction
{
    use HasExtensionAuthorization;

    public function title()
    {
        $replace = ['version' => $this->row->extension->getLocalLatestVersion()];

        return sprintf('<b>%s</b>', trans('admin.upgrade_to_version', $replace));
    }

    public function handle()
    {
        $manager = Admin::extension()
            ->updateManager()
            ->update($this->getKey());

        return $this
            ->response()
            ->success(implode('<br>', $manager->notes))
            ->refresh();
    }
}
