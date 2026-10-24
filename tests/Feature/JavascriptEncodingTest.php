<?php

namespace Tests\Feature;

use Dcat\Admin\Support\JavaScript;
use Tests\TestCase;

/**
 * JS 上下文编码安全测试.
 *
 * - JavaScript::format 对数组/对象统一使用 JSON_HEX_TAG|AMP|APOS|QUOT 编码，
 *   防止 </script> 逃逸与属性注入；
 * - JavaScript::make 占位符替换逻辑必须保留；
 * - 树组件视图节点数据安全编码并默认启用 jsTree core.force_text，
 *   开发者显式设置的 force_text 不被覆盖；
 * - toastr 视图类型白名单校验，消息以 JSON 字符串输出.
 *
 * @group javascript-encoding
 */
class JavascriptEncodingTest extends TestCase
{
    protected $login = false;

    public function testFormatEscapesScriptBreakoutAndQuotes()
    {
        $payload = '</script><img src=x onerror=alert(1)>"\'&';

        $json = JavaScript::format(['text' => $payload]);

        $this->assertStringNotContainsString('</script>', $json);
        $this->assertStringNotContainsString('<img', $json);
        $this->assertStringContainsString('\u003C', $json);
        $this->assertStringContainsString('\u003E', $json);
        $this->assertStringContainsString('\u0022', $json);
        $this->assertStringContainsString('\u0027', $json);
        $this->assertStringContainsString('\u0026', $json);

        // 编码可逆，JS 侧拿到的仍是原始内容
        $decoded = json_decode($json, true);

        $this->assertSame($payload, $decoded['text']);
    }

    public function testFormatKeepsScalarValuesUntouched()
    {
        $this->assertSame('plain', JavaScript::format('plain'));
    }

    public function testFormatPreservesJavaScriptPlaceholder()
    {
        $script = 'function () { return "ok"; }';

        $placeholder = JavaScript::make($script);

        $json = JavaScript::format(['callback' => $placeholder]);

        // 占位符被替换为原始 JS 代码，且不再包含占位符本身
        $this->assertStringContainsString('"callback":'.$script, $json);
        $this->assertStringNotContainsString($placeholder, $json);
    }

    public function testWidgetTreeViewEncodesNodesAndEnablesForceText()
    {
        $nodes = [
            ['id' => 1, 'text' => "<img src=x onerror=alert(1)>'\"", 'parent' => '#'],
        ];

        $html = view('admin::widgets.tree', [
            'id'         => 'tree-encoding-test',
            'attributes' => '',
            'options'    => ['plugins' => ['checkbox']],
            'nodes'      => $nodes,
        ])->render();

        $this->assertStringContainsString('force_text = true', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('\u003Cimg', $html);
    }

    public function testWidgetTreeViewKeepsExplicitForceText()
    {
        $html = view('admin::widgets.tree', [
            'id'         => 'tree-encoding-test',
            'attributes' => '',
            'options'    => ['core' => ['force_text' => false]],
            'nodes'      => [],
        ])->render();

        // 显式配置保留在 options 中，仅存在受保护的默认赋值
        $this->assertStringContainsString('"force_text":false', $html);
        $this->assertSame(1, substr_count($html, 'force_text = true'));
    }

    public function testFormTreeViewEncodesNodesAndForcesText()
    {
        $nodes = [
            ['id' => 1, 'text' => '</script><script>alert(1)</script>', 'parent' => '#'],
        ];

        $html = view('admin::form.tree', [
            'viewClass'  => ['form-group' => '', 'label' => '', 'field' => ''],
            'label'      => 'tree',
            'class'      => '',
            'name'       => 'tree',
            'disabled'   => '',
            'checkboxes' => '',
            'help'       => [],
            'selector'   => '#form-tree-encoding-test',
            'options'    => [],
            'parents'    => [1],
            'nodes'      => $nodes,
            'expand'     => false,
        ])->render();

        $this->assertStringContainsString('force_text = true', $html);
        $this->assertStringContainsString('\u003C\/script\u003E', $html);
        $this->assertStringNotContainsString('</script><script>alert', $html);
        $this->assertStringContainsString('parents = [1]', $html);
    }

    public function testDialogTreeViewEncodesNodesColumnsAndForcesText()
    {
        $html = view('admin::grid.displayer.dialogtree', [
            'value'        => '1',
            'url'          => 'http://localhost/admin/tree',
            'title'        => 'tree',
            'checkAll'     => false,
            'nodes'        => [['id' => 1, 'text' => '<script>alert(1)</script>', 'name' => 'a']],
            'options'      => ['plugins' => ['checkbox']],
            'area'         => ['580px', '600px'],
            'columnNames'  => ['id' => 'id', 'text' => 'name', 'parent' => 'parent_id'],
            'rootParentId' => "0');alert(1);//",
        ])->render();

        $this->assertStringContainsString('force_text = true', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString("0');alert(1);//", $html);
        $this->assertStringContainsString('columns: {"id":"id","text":"name","parent":"parent_id"}', $html);
    }

    public function testToastrViewUsesWhitelistTypeAndJsonMessage()
    {
        admin_toastr("x'</script><script>alert(1)</script>", 'error', ['timeOut' => 100]);

        $html = view('admin::partials.toastr')->render();

        $this->assertStringContainsString('toastr.error(', $html);
        $this->assertStringContainsString('\u003C\/script\u003E', $html);
        $this->assertStringNotContainsString('</script><script>alert', $html);
        $this->assertStringContainsString('\u0027', $html);
        $this->assertTrue((bool) preg_match('/"timeOut":(?:"100"|100)/', $html));
    }

    public function testToastrViewFallsBackToInfoForInvalidType()
    {
        admin_toastr('ok', "error');alert(1);//");

        $html = view('admin::partials.toastr')->render();

        $this->assertStringContainsString('toastr.info(', $html);
        $this->assertStringNotContainsString('alert(1);//', $html);
    }
}
