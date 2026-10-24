<?php

namespace Tests\Feature;

use Dcat\Admin\Http\Controllers\AuthController;
use Dcat\Admin\Models\Administrator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * 登录限流与注销路由安全测试.
 *
 * - 登录失败按 username+IP 计数，5 次/60 秒，超限返回 HTTP 429；
 * - 登录成功清零计数；失败响应对"用户不存在"与"密码错误"保持一致，
 *   避免用户枚举；
 * - auth/logout 仅允许 POST（CSRF 保护），getLogout 方法名保留兼容.
 *
 * @group auth-security
 */
class AuthRateLimitTest extends TestCase
{
    protected $login = false;

    public function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
    }

    public function testLoginIsRateLimitedAfterFiveFailures()
    {
        $controller = new AuthController();

        for ($i = 0; $i < AuthController::MAX_LOGIN_ATTEMPTS; $i++) {
            $response = $controller->postLogin($this->makeLoginRequest('missing-user', 'wrong-password'));

            $this->assertSame(422, $response->getStatusCode(), "Attempt #$i should be a validation error.");
        }

        $response = $controller->postLogin($this->makeLoginRequest('missing-user', 'wrong-password'));

        $this->assertSame(429, $response->getStatusCode());
        $this->assertStringContainsString('Too many login attempts', $response->getData(true)['data']['message'] ?? '');
    }

    public function testSuccessfulLoginClearsAttempts()
    {
        $user = Administrator::first();
        $user->password = bcrypt('secret123');
        $user->save();

        $controller = new AuthController();

        // 先失败 3 次
        for ($i = 0; $i < 3; $i++) {
            $controller->postLogin($this->makeLoginRequest($user->username, 'wrong-password'));
        }

        // 成功登录必须清零计数
        $response = $controller->postLogin($this->makeLoginRequest($user->username, 'secret123', true));

        $this->assertSame(200, $response->getStatusCode());

        // 清零后允许再次失败 5 次才会被限流
        for ($i = 0; $i < AuthController::MAX_LOGIN_ATTEMPTS; $i++) {
            $response = $controller->postLogin($this->makeLoginRequest($user->username, 'wrong-password'));

            $this->assertSame(422, $response->getStatusCode(), "Attempt #$i should be a validation error.");
        }

        $response = $controller->postLogin($this->makeLoginRequest($user->username, 'wrong-password'));

        $this->assertSame(429, $response->getStatusCode());
    }

    public function testRateLimitIsPerUsername()
    {
        $controller = new AuthController();

        for ($i = 0; $i < AuthController::MAX_LOGIN_ATTEMPTS + 1; $i++) {
            $controller->postLogin($this->makeLoginRequest('blocked-user', 'wrong-password'));
        }

        $this->assertSame(
            429,
            $controller->postLogin($this->makeLoginRequest('blocked-user', 'wrong-password'))->getStatusCode()
        );

        // 其它用户名不受限
        $this->assertSame(
            422,
            $controller->postLogin($this->makeLoginRequest('other-user', 'wrong-password'))->getStatusCode()
        );
    }

    public function testFailedLoginResponseDoesNotRevealUserExistence()
    {
        $user = Administrator::first();

        $existing = (new AuthController())->postLogin($this->makeLoginRequest($user->username, 'wrong-password'));
        $missing = (new AuthController())->postLogin($this->makeLoginRequest('no-such-user', 'wrong-password'));

        $this->assertSame($existing->getStatusCode(), $missing->getStatusCode());
        $this->assertSame($existing->getContent(), $missing->getContent());
    }

    public function testLogoutRouteOnlyAcceptsPost()
    {
        $uri = trim(admin_base_path('auth/logout'), '/');

        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(function (Route $route) use ($uri) {
                return $route->uri() === $uri;
            });

        $this->assertNotNull($route, 'auth/logout route should be registered.');
        $this->assertSame(['POST'], $route->methods());
    }

    public function testGetLogoutStillWorks()
    {
        $request = Request::create(admin_base_path('auth/logout'), 'POST');
        $request->setLaravelSession($this->app['session.store']);

        $response = (new AuthController())->getLogout($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('auth/login', $response->getTargetUrl());
    }

    /**
     * @param  string  $username
     * @param  string  $password
     * @param  bool  $withSession
     * @return Request
     */
    protected function makeLoginRequest($username, $password, $withSession = false)
    {
        $request = Request::create(admin_base_path('auth/login'), 'POST', [
            'username' => $username,
            'password' => $password,
        ]);

        if ($withSession) {
            $request->setLaravelSession($this->app['session.store']);
        }

        return $request;
    }
}
