<?php

namespace Tests\Feature;

use Dcat\Admin\Exception\AdminException;
use Dcat\Admin\Http\Controllers\ScaffoldController;
use Dcat\Admin\Scaffold\MigrationCreator;
use Dcat\Admin\Support\Helper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 代码生成器（脚手架）安全测试.
 *
 * - 生成的控制器/模型/仓库类名必须是合法 PHP 类名，
 *   Helper::guessClassFileName 必须拒绝目录逃逸，文件只能生成在预期 PSR-4 目录；
 * - MigrationCreator 的字段名/主键/类型/索引必须白名单校验，
 *   默认值与注释使用 var_export 转义，避免生成可被注入的 PHP 代码.
 *
 * @group scaffold-security
 */
class ScaffoldSecurityTest extends TestCase
{
    protected $login = false;

    public function testIsValidClassNameAcceptsNormalClasses()
    {
        $this->assertTrue(Helper::isValidClassName('App\Admin\Controllers\PostController'));
        $this->assertTrue(Helper::isValidClassName('App\Admin\Models\Post'));
        $this->assertTrue(Helper::isValidClassName('App\Admin\Repositories\Post'));
        $this->assertTrue(Helper::isValidClassName('\App\Admin\Models\Post\\'));

        $this->assertFalse(Helper::isValidClassName(''));
        $this->assertFalse(Helper::isValidClassName('App\Admin\..\..\EvilController'));
        $this->assertFalse(Helper::isValidClassName('App\Admin\Evil Controller'));
        $this->assertFalse(Helper::isValidClassName('App\Admin\2Cool\Controller'));
        $this->assertFalse(Helper::isValidClassName('App\Admin\Foo-Bar'));
    }

    public function testGuessClassFileNameRejectsDirectoryTraversal()
    {
        try {
            Helper::guessClassFileName('App\Admin\..\..\EvilController');

            $this->fail('Directory traversal class name should be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }
    }

    public function testGuessClassFileNameKeepsNormalClasses()
    {
        $path = str_replace('\\', '/', Helper::guessClassFileName('App\Admin\Controllers\PostController'));

        $this->assertStringEndsWith('app/Admin/Controllers/PostController.php', $path);
    }

    public function testStoreRejectsInvalidClassNames()
    {
        // 失败路径会 flash 错误信息到 session，需要给 Redirector 设置 session
        app('redirect')->setSession($this->app['session.store']);

        $request = Request::create('admin/helpers/scaffold', 'POST', [
            'create'          => ['controller'],
            'table_name'      => 'posts',
            'controller_name' => 'App\Admin\Controllers\Foo\..\..\EvilController',
        ]);

        $response = (new ScaffoldController())->store($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);

        $error = $this->app['session.store']->get('error');
        $this->assertInstanceOf(MessageBag::class, $error);
        $this->assertStringContainsString('Invalid controller class name', $error->first());

        // 不能在任何预期目录之外生成文件
        $this->assertFalse(file_exists(base_path('app/Admin/EvilController.php')));
    }

    public function testMigrationBlueprintRejectsInjectedFieldName()
    {
        $this->assertBlueprintThrows([
            ['name' => "title');phpinfo();//", 'type' => 'string'],
        ]);
    }

    public function testMigrationBlueprintRejectsInvalidType()
    {
        $this->assertBlueprintThrows([
            ['name' => 'title', 'type' => "string')->raw('x');//"],
        ]);
    }

    public function testMigrationBlueprintRejectsInvalidKey()
    {
        $this->assertBlueprintThrows([
            ['name' => 'title', 'type' => 'string', 'key' => "index')->raw('x');//"],
        ]);
    }

    public function testMigrationBlueprintRejectsInjectedPrimaryKey()
    {
        $this->assertBlueprintThrows([
            ['name' => 'title', 'type' => 'string'],
        ], "id');phpinfo();//");
    }

    public function testMigrationBlueprintEscapesDefaultAndComment()
    {
        $payload = "');phpinfo();//";

        $blueprint = $this->buildBlueprint([
            ['name' => 'title', 'type' => 'string', 'default' => $payload, 'comment' => $payload],
        ]);

        // 注入内容被 var_export 转义，生成的迁移文件仍是合法 PHP 代码
        $this->assertStringContainsString('->default('.var_export($payload, true).')', $blueprint);
        $this->assertStringContainsString('->comment('.var_export($payload, true).')', $blueprint);
        $this->assertStringNotContainsString("->default('');", $blueprint);
    }

    public function testMigrationBlueprintKeepsNormalDefaults()
    {
        $blueprint = $this->buildBlueprint([
            ['name' => 'title', 'type' => 'string', 'default' => 'hello'],
        ], 'id', false, false);

        $expected = "\$table->bigIncrements('id');\n"
            ."            \$table->string('title')->default('hello');";

        $this->assertSame($expected, $blueprint);
    }

    public function testMigrationFileContainsEscapedTableName()
    {
        $dir = sys_get_temp_dir().'/dcat-admin-migration-test-'.uniqid();
        mkdir($dir, 0755, true);

        try {
            $path = (new MigrationCreator(app('files')))
                ->buildBluePrint([['name' => 'title', 'type' => 'string']])
                ->create('create_scaffold_security_test_table', $dir, 'scaffold_security_test');

            $content = file_get_contents($path);

            $this->assertStringContainsString("Schema::create('scaffold_security_test', function (Blueprint \$table) {", $content);
            $this->assertStringContainsString("\$table->bigIncrements('id');", $content);

            unlink($path);
        } finally {
            rmdir($dir);
        }
    }

    public function testLangArrayExportEscapesKeysAndValues()
    {
        $payload = "weird'key\\";

        $array = [
            'labels' => [$payload => 'x'],
            'fields' => ['title' => $payload],
        ];

        $path = sys_get_temp_dir().'/dcat-admin-lang-test-'.uniqid().'.php';
        file_put_contents($path, Helper::exportArrayPhp($array));

        try {
            $loaded = require $path;

            $this->assertSame('x', $loaded['labels'][$payload]);
            $this->assertSame($payload, $loaded['fields']['title']);
        } finally {
            unlink($path);
        }
    }

    /**
     * @param  array  $fields
     * @param  string  $keyName
     * @return string
     */
    protected function buildBlueprint(array $fields, $keyName = 'id', $timestamps = true, $softDeletes = false)
    {
        $creator = new MigrationCreator(app('files'));
        $creator->buildBluePrint($fields, $keyName, $timestamps, $softDeletes);

        $property = new ReflectionProperty(MigrationCreator::class, 'bluePrint');
        $property->setAccessible(true);

        return $property->getValue($creator);
    }

    /**
     * @param  array  $fields
     * @param  string  $keyName
     */
    protected function assertBlueprintThrows(array $fields, $keyName = 'id')
    {
        try {
            $this->buildBlueprint($fields, $keyName);

            $this->fail('Injected blueprint values should be rejected.');
        } catch (AdminException $e) {
            $this->assertTrue(true);
        }
    }
}
