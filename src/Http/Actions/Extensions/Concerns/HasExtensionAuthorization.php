<?php

namespace Dcat\Admin\Http\Actions\Extensions\Concerns;

use Dcat\Admin\Admin;

trait HasExtensionAuthorization
{
    /**
     * 启用、禁用、更新、卸载、安装扩展均属于高危操作，仅允许超级管理员执行.
     *
     * @param  mixed  $user
     * @return bool
     */
    protected function authorize($user): bool
    {
        return $user && method_exists($user, 'isAdministrator') && $user->isAdministrator();
    }

    /**
     * 非超级管理员不渲染扩展操作入口.
     *
     * @return bool
     */
    public function allowed()
    {
        /** @var mixed $user */
        $user = Admin::user();

        return parent::allowed() && $user && method_exists($user, 'isAdministrator') && $user->isAdministrator();
    }
}
