<?php

namespace Dcat\Admin\Http\Actions\Extensions;

use Dcat\Admin\Grid\Tools\AbstractTool;
use Dcat\Admin\Http\Actions\Extensions\Concerns\HasExtensionAuthorization;

class Marketplace extends AbstractTool
{
    use HasExtensionAuthorization;

    protected $style = 'btn btn-primary';

    public function title()
    {
        return '<i class="feather icon-shopping-cart"></i> &nbsp;'.trans('admin.marketplace');
    }

    public function html()
    {
        return parent::html().'&nbsp;';
    }
}
