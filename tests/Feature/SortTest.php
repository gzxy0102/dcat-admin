<?php

namespace Tests\Feature;

use Dcat\Admin\Grid;
use Dcat\Admin\Repositories\EloquentRepository;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\Models\User;
use Tests\TestCase;

/**
 * Grid排序参数安全测试.
 *
 * @group grid-sort
 */
class SortTest extends TestCase
{
    protected $login = false;

    /**
     * @param  array|string|null  $sort
     * @return Grid\Model
     */
    protected function makeModel($sort)
    {
        $request = Request::create('/admin/tests/users', 'GET', ['_sort' => $sort]);

        return new Grid\Model($request, new EloquentRepository(new User()));
    }

    /**
     * 调用仓库的 protected setSort 方法.
     *
     * @param  Grid\Model  $model
     * @return void
     */
    protected function applySort(Grid\Model $model)
    {
        $method = new ReflectionMethod($model->repository(), 'setSort');
        $method->setAccessible(true);
        $method->invoke($model->repository(), $model);
    }

    public function testGetSortWithValidInput()
    {
        [$column, $type, $cast] = $this->makeModel(['column' => 'username', 'type' => 'ASC'])->getSort();

        $this->assertSame('username', $column);
        $this->assertSame('asc', $type);
        $this->assertNull($cast);

        [$column, $type, $cast] = $this->makeModel(['column' => 'profile.postcode', 'type' => 'desc', 'cast' => 'SIGNED'])->getSort();

        $this->assertSame('profile.postcode', $column);
        $this->assertSame('desc', $type);
        $this->assertSame('SIGNED', $cast);
    }

    public function testGetSortIgnoresInvalidStructure()
    {
        // 非数组参数
        $this->assertSame([null, null, null], $this->makeModel('username,asc')->getSort());
        $this->assertSame([null, null, null], $this->makeModel(null)->getSort());

        // column 非法类型
        $this->assertSame([null, null, null], $this->makeModel(['column' => ['username'], 'type' => 'asc'])->getSort());
        $this->assertSame([null, null, null], $this->makeModel(['column' => '', 'type' => 'asc'])->getSort());

        // 非法排序方向
        $this->assertSame([null, null, null], $this->makeModel(['column' => 'username', 'type' => 'asc,updatexml(1,1,1)'])->getSort());
        $this->assertSame([null, null, null], $this->makeModel(['column' => 'username', 'type' => '1;DROP TABLE test_users'])->getSort());
        $this->assertSame([null, null, null], $this->makeModel(['column' => 'username', 'type' => 'asc desc'])->getSort());

        // cast 非法类型时安全忽略 cast
        [$column, $type, $cast] = $this->makeModel(['column' => 'username', 'type' => 'asc', 'cast' => ['SIGNED']])->getSort();

        $this->assertSame('username', $column);
        $this->assertSame('asc', $type);
        $this->assertNull($cast);
    }

    public function testSetSortWithValidColumn()
    {
        $model = $this->makeModel(['column' => 'username', 'type' => 'desc']);
        $this->applySort($model);

        $order = $model->findQueryByMethod('orderBy')->first();
        $this->assertSame(['username', 'desc'], $order['arguments']);
    }

    public function testSetSortWithJsonColumn()
    {
        $model = $this->makeModel(['column' => 'options->name', 'type' => 'desc']);
        $this->applySort($model);

        $raw = $model->findQueryByMethod('orderByRaw')->first();
        $this->assertSame(["JSON_UNQUOTE(JSON_EXTRACT(`options`, '$.name')) desc"], $raw['arguments']);
    }

    public function testSetSortWithRelationColumn()
    {
        $model = $this->makeModel(['column' => 'profile.postcode', 'type' => 'asc']);
        $this->applySort($model);

        $this->assertNotNull($model->findQueryByMethod('join')->first());

        $order = $model->findQueryByMethod('orderBy')->first();
        $this->assertSame(['test_user_profiles.postcode', 'asc'], $order['arguments']);
    }

    public function testSetSortWithValidCasts()
    {
        $validCasts = ['SIGNED', 'UNSIGNED INTEGER', 'DECIMAL(10,2)', 'DATE', 'DATETIME', 'TIME', 'YEAR', 'JSON', 'BINARY(4)', 'CHAR(10)', 'NCHAR', 'REAL', 'DOUBLE', 'FLOAT'];

        foreach ($validCasts as $cast) {
            $model = $this->makeModel(['column' => 'username', 'type' => 'asc', 'cast' => $cast]);
            $this->applySort($model);

            $raw = $model->findQueryByMethod('orderByRaw')->first();
            $this->assertNotNull($raw, "cast [{$cast}] 应被允许");
            $this->assertSame(["CAST(`username` AS {$cast}) asc"], $raw['arguments']);
        }

        // 关联字段 + CAST（对应 UserController 中 sortable('SIGNED') 的用法）
        $model = $this->makeModel(['column' => 'profile.postcode', 'type' => 'desc', 'cast' => 'SIGNED']);
        $this->applySort($model);

        $raw = $model->findQueryByMethod('orderByRaw')->first();
        $this->assertSame(['CAST(`test_user_profiles`.`postcode` AS SIGNED) desc'], $raw['arguments']);
    }

    public function testSetSortIgnoresColumnInjection()
    {
        $payloads = [
            // 反引号、引号、注释、表达式注入
            'username` desc, (select sleep(1))-- ',
            "username' and '1'='1",
            'username asc;DROP TABLE test_users',
            'username/**/desc',
            'username desc --',
            'count(*)',
            'options->name\')) union select 1-- ',
            'options->name` where 1=1#',
        ];

        foreach ($payloads as $column) {
            $model = $this->makeModel(['column' => $column, 'type' => 'asc']);
            $this->applySort($model);

            $this->assertTrue(
                $model->findQueryByMethod('orderByRaw')->isEmpty(),
                "非法排序字段 [{$column}] 不应生成 orderByRaw"
            );
            $this->assertTrue(
                $model->findQueryByMethod('orderBy')->isEmpty(),
                "非法排序字段 [{$column}] 不应生成 orderBy"
            );
        }
    }

    public function testSetSortIgnoresDirectionInjection()
    {
        $payloads = ['asc,(select 1)', 'desc;DROP TABLE test_users', 'asc desc', ''];

        foreach ($payloads as $type) {
            $model = $this->makeModel(['column' => 'username', 'type' => $type]);
            $this->applySort($model);

            $sql = $model->getQueries()->map(function ($query) {
                $arguments = array_map(function ($argument) {
                    return is_scalar($argument) ? (string) $argument : '';
                }, $query['arguments']);

                return $query['method'].' '.implode(' ', $arguments);
            })->implode(' ');

            $this->assertStringNotContainsString('select', strtolower($sql));
            $this->assertStringNotContainsString('drop', strtolower($sql));
        }
    }

    public function testSetSortIgnoresCastInjection()
    {
        $payloads = [
            'SIGNED) AND sleep(1)-- ',
            'CHAR(1) desc, (select 1)-- ',
            'SIGNED;DROP TABLE test_users',
            'SIGNED`',
            'CONCAT(1,2)',
        ];

        foreach ($payloads as $cast) {
            $model = $this->makeModel(['column' => 'username', 'type' => 'asc', 'cast' => $cast]);
            $this->applySort($model);

            // 非法 cast 安全降级为普通排序，排序字段本身仍生效
            $this->assertTrue($model->findQueryByMethod('orderByRaw')->isEmpty(), "非法 cast [{$cast}] 不应生成 orderByRaw");

            $order = $model->findQueryByMethod('orderBy')->first();
            $this->assertNotNull($order, "非法 cast [{$cast}] 应降级为普通排序");
            $this->assertSame(['username', 'asc'], $order['arguments']);
        }
    }
}
