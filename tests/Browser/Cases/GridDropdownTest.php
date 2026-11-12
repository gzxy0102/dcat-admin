<?php

namespace Tests\Browser\Cases;

use Laravel\Dusk\Browser;
use Tests\TestCase;

class GridDropdownTest extends TestCase
{
    public function testRowMenuEscapesSingleRowScrollingTable()
    {
        $menu = view('admin::grid.dropdown-actions', [
            'default' => [],
            'custom'  => ['<a href="#">View</a>', '<a href="#">Edit</a>', '<a href="#">Delete</a>'],
        ])->render();

        $fixture = json_encode('<div id="row-menu-test" style="position:fixed;top:100px;left:100px;width:400px;z-index:1000">'
            .'<div class="table-responsive" style="overflow:auto;position:relative">'
            .'<table class="table" style="width:800px"><thead><tr><th>User</th><th>Actions</th></tr></thead>'
            .'<tbody><tr><td style="width:700px">One user</td><td>'.$menu.'</td></tr></tbody></table>'
            .'</div></div>');

        $this->browse(function (Browser $browser) use ($fixture) {
            $browser->visit(admin_base_path('/'));
            $browser->script(<<<JS
$('body').append({$fixture});
var container = document.querySelector('#row-menu-test .table-responsive');
container.scrollLeft = container.scrollWidth;
JS
            );

            $toggle = '#row-menu-test [data-toggle="dropdown"]';
            $menu = '#row-menu-test .dropdown-menu';

            $browser->click($toggle)->waitFor($menu.'.show');
            $this->assertMenuClickable($browser);

            // Close and reopen, then place the single row near the viewport bottom.
            $browser->click($toggle)->assertMissing($menu.'.show');
            $browser->script("$('#row-menu-test').css({top: 'auto', bottom: '10px'});");
            $browser->click($toggle)->waitFor($menu.'.show');
            $this->assertMenuClickable($browser);

            // A fixed menu must close when its scrolling container moves.
            $browser->script("$('#row-menu-test .table-responsive').scrollLeft(0);");
            $browser->waitUntilMissing($menu.'.show');
            $browser->script("$('#row-menu-test').remove();");
        });
    }

    protected function assertMenuClickable(Browser $browser)
    {
        $browser->waitUsing(2, 50, function () use ($browser) {
            $result = $browser->script(<<<'JS'
return Array.prototype.every.call(document.querySelectorAll('#row-menu-test .dropdown-item a'), function (item) {
    var rect = item.getBoundingClientRect();
    var hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
    return rect.top >= 0 && rect.bottom <= window.innerHeight && item.contains(hit);
});
JS
            );

            return $result[0];
        }, 'Every row action should be visible and clickable outside the scrolling table.');
    }
}
