<?php

namespace Dcat\Admin\Http\Controllers;

use Dcat\Admin\Admin;
use Dcat\Admin\Form;
use Dcat\Admin\Http\Repositories\Administrator;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Traits\HasFormResponse;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    use HasFormResponse;

    /**
     * 登录失败最大尝试次数.
     */
    const MAX_LOGIN_ATTEMPTS = 5;

    /**
     * 登录限流时间窗口（秒）.
     */
    const LOGIN_DECAY_SECONDS = 60;

    /**
     * @var string
     */
    protected $view = 'admin::pages.login';

    /**
     * @var string
     */
    protected $redirectTo;

    /**
     * Show the login page.
     *
     * @return Content|\Illuminate\Http\RedirectResponse
     */
    public function getLogin(Content $content)
    {
        if ($this->guard()->check()) {
            return redirect($this->getRedirectPath());
        }

        return $content->full()->body(view($this->view));
    }

    /**
     * Handle a login request.
     *
     * @param  Request  $request
     * @return mixed
     */
    public function postLogin(Request $request)
    {
        $credentials = $request->only([$this->username(), 'password']);
        $remember = (bool) $request->input('remember', false);

        /** @var \Illuminate\Validation\Validator $validator */
        $validator = Validator::make($credentials, [
            $this->username()   => 'required',
            'password'          => 'required',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorsResponse($validator);
        }

        if ($this->hasTooManyLoginAttempts($request)) {
            return $this->sendLockoutResponse($request);
        }

        if ($this->guard()->attempt($credentials, $remember)) {
            $this->clearLoginAttempts($request);

            return $this->sendLoginResponse($request);
        }

        $this->incrementLoginAttempts($request);

        return $this->validationErrorsResponse([
            $this->username() => $this->getFailedLoginMessage(),
        ]);
    }

    /**
     * User logout.
     *
     * @return Redirect|string
     */
    public function getLogout(Request $request)
    {
        $this->guard()->logout();

        $request->session()->invalidate();

        $path = admin_url('auth/login');
        if ($request->pjax()) {
            return "<script>location.href = '$path';</script>";
        }

        return redirect($path);
    }

    /**
     * User setting page.
     *
     * @param  Content  $content
     * @return Content
     */
    public function getSetting(Content $content)
    {
        $form = $this->settingForm();
        $form->tools(
            function (Form\Tools $tools) {
                $tools->disableList();
            }
        );

        return $content
            ->title(trans('admin.user_setting'))
            ->body($form->edit(Admin::user()->getKey()));
    }

    /**
     * Update user setting.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function putSetting()
    {
        $form = $this->settingForm();

        if (! $this->validateCredentialsWhenUpdatingPassword()) {
            $form->responseValidationMessages('old_password', trans('admin.old_password_error'));
        }

        return $form->update(Admin::user()->getKey());
    }

    protected function validateCredentialsWhenUpdatingPassword()
    {
        $user = Admin::user();

        $oldPassword = \request('old_password');
        $newPassword = \request('password');

        if (
            (! $newPassword)
            || ($newPassword === $user->getAuthPassword())
        ) {
            return true;
        }

        if (! $oldPassword) {
            return false;
        }

        return $this->guard()
            ->getProvider()
            ->validateCredentials($user, ['password' => $oldPassword]);
    }

    /**
     * Model-form for user setting.
     *
     * @return Form
     */
    protected function settingForm()
    {
        return new Form(new Administrator(), function (Form $form) {
            $form->action(admin_url('auth/setting'));

            $form->disableCreatingCheck();
            $form->disableEditingCheck();
            $form->disableViewCheck();

            $form->tools(function (Form\Tools $tools) {
                $tools->disableView();
                $tools->disableDelete();
            });

            $form->display('username', trans('admin.username'));
            $form->text('name', trans('admin.name'))->required();
            $form->image('avatar', trans('admin.avatar'))->autoUpload();

            $form->password('old_password', trans('admin.old_password'));

            $form->password('password', trans('admin.password'))
                ->minLength(5)
                ->maxLength(20)
                ->customFormat(function ($v) {
                    if ($v == $this->password) {
                        return;
                    }

                    return $v;
                });
            $form->password('password_confirmation', trans('admin.password_confirmation'))->same('password');

            $form->ignore(['password_confirmation', 'old_password']);

            $form->saving(function (Form $form) {
                if ($form->password && $form->model()->password != $form->password) {
                    $form->password = bcrypt($form->password);
                }

                if (! $form->password) {
                    $form->deleteInput('password');
                }
            });

            $form->saved(function (Form $form) {
                return $form
                    ->response()
                    ->success(trans('admin.update_succeeded'))
                    ->redirect('auth/setting');
            });
        });
    }

    /**
     * @return string|\Symfony\Component\Translation\TranslatorInterface
     */
    protected function getFailedLoginMessage()
    {
        return Lang::has('admin.auth_failed')
            ? trans('admin.auth_failed')
            : 'These credentials do not match our records.';
    }

    /**
     * 是否已超出登录尝试次数限制.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function hasTooManyLoginAttempts(Request $request)
    {
        if ((int) Cache::get($this->loginAttemptsCacheKey($request), 0) < static::MAX_LOGIN_ATTEMPTS) {
            return false;
        }

        if (Cache::has($this->loginDecayCacheKey($request))) {
            return true;
        }

        // 时间窗口已过，重置计数
        $this->clearLoginAttempts($request);

        return false;
    }

    /**
     * 记录一次失败登录尝试.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return int  当前失败次数
     */
    protected function incrementLoginAttempts(Request $request)
    {
        $key = $this->loginDecayCacheKey($request);
        $attemptsKey = $this->loginAttemptsCacheKey($request);

        if (! Cache::has($key)) {
            Cache::put($key, time() + static::LOGIN_DECAY_SECONDS, static::LOGIN_DECAY_SECONDS);

            Cache::put($attemptsKey, 0, static::LOGIN_DECAY_SECONDS);
        }

        $attempts = (int) Cache::get($attemptsKey, 0) + 1;

        Cache::put($attemptsKey, $attempts, static::LOGIN_DECAY_SECONDS);

        return $attempts;
    }

    /**
     * 登录成功后清零失败次数.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    protected function clearLoginAttempts(Request $request)
    {
        Cache::forget($this->loginAttemptsCacheKey($request));

        Cache::forget($this->loginDecayCacheKey($request));
    }

    /**
     * 登录失败计数缓存键（按用户名+IP）.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function loginAttemptsCacheKey(Request $request)
    {
        return $this->loginCacheKey($request, 'attempts');
    }

    /**
     * 登录限流时间窗口缓存键.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function loginDecayCacheKey(Request $request)
    {
        return $this->loginCacheKey($request, 'decay');
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $suffix
     * @return string
     */
    protected function loginCacheKey(Request $request, $suffix)
    {
        $payload = $this->username().'|'.$request->input($this->username()).'|'.$request->ip();

        return 'dcat-admin:login:'.$suffix.':'.sha1($payload);
    }

    /**
     * 超出登录尝试次数限制的响应（HTTP 429）.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    protected function sendLockoutResponse(Request $request)
    {
        return $this->response()
            ->error($this->getTooManyLoginMessage())
            ->statusCode(429)
            ->send();
    }

    /**
     * @return string|\Symfony\Component\Translation\TranslatorInterface
     */
    protected function getTooManyLoginMessage()
    {
        return Lang::has('admin.too_many_login_attempts')
            ? trans('admin.too_many_login_attempts')
            : 'Too many login attempts. Please try again later.';
    }

    /**
     * Get the post login redirect path.
     *
     * @return string
     */
    protected function getRedirectPath()
    {
        return $this->redirectTo ?: admin_url('/');
    }

    /**
     * Send the response after the user was authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function sendLoginResponse(Request $request)
    {
        $request->session()->regenerate();

        $path = $this->getRedirectPath();

        return $this->response()
            ->success(trans('admin.login_successful'))
            ->locationToIntended($path)
            ->locationIf(Admin::app()->getEnabledApps(), $path)
            ->send();
    }

    /**
     * Get the login username to be used by the controller.
     *
     * @return string
     */
    protected function username()
    {
        return 'username';
    }

    /**
     * Get the guard to be used during authentication.
     *
     * @return \Illuminate\Contracts\Auth\StatefulGuard|GuardHelpers
     */
    protected function guard()
    {
        return Admin::guard();
    }
}
